/**
 * ELECTROBOY 80 — Validation de médias par des tiers (admin)
 *
 * - Médiathèque : bouton ✅ sur chaque fichier (« Faire valider ») et pastille de statut
 *   quand une demande existe ; bouton « ✅ Validations » dans l'en-tête pour le suivi.
 * - window.EB80Validation.creer(url, nom, titre) : ouvre l'envoi pour un média
 *   (utilisé aussi par la fenêtre Vidéo IA pour les MP4 produits).
 * - window.EB80Validation.suivi(id?) : liste des demandes / détail d'une demande.
 * Les relecteurs répondent sur valider.html (lien personnel, sans compte). API : api/reviews.php.
 */
(() => {
  const CLE_CONTACTS = 'eb80_validation_contacts';
  const $ = (sel, el = document) => el.querySelector(sel);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const minsec = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
  const STATUTS = { pending: ['🕓', 'En attente', '#aaa'], approved: ['✅', 'Validé', '#3f6'], changes: ['✏️', 'Modifs demandées', '#FF006E'] };
  const DEC = { pending: '🕓', approved: '✅', changes: '✏️' };

  const api = async (chemin, options = {}) => {
    const res = await fetch('/api' + chemin, {
      ...options,
      headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('eb80_token') || ''), 'Content-Type': 'application/json', ...(options.headers || {}) },
    });
    const d = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(d.error || 'HTTP ' + res.status);
    return d;
  };

  const CSS = `
.mv{border:2px solid #ff0;background:#0a0a0a;color:#f0f0f0;padding:0;width:min(980px,96vw);max-height:92vh;font-family:'IBM Plex Mono',monospace;font-size:12px;}
.mv::backdrop{background:rgba(0,0,0,.8);}
.mv *{box-sizing:border-box;}
.mv-top{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:2px solid #222;background:#000;position:sticky;top:0;z-index:1;}
.mv-top h2{font-family:'Anton',sans-serif;font-weight:400;font-size:19px;margin:0;}
.mv-top h2 span{color:#3f6;}
.mv-corps{padding:16px;overflow:auto;max-height:calc(92vh - 54px);}
.mv label{display:block;font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#aaa;margin:10px 0 3px;}
.mv input[type=text],.mv input[type=email],.mv textarea,.mv select{width:100%;background:#111;border:1px solid #2a2a2a;color:#f0f0f0;font-family:inherit;font-size:12px;padding:7px 9px;}
.mv textarea{resize:vertical;min-height:60px;}
.mv-btn{background:transparent;border:2px solid #333;color:#f0f0f0;font-family:'VT323',monospace;font-size:16px;letter-spacing:2px;padding:4px 12px;cursor:pointer;text-transform:uppercase;}
.mv-btn:hover{border-color:#ff0;color:#ff0;}
.mv-btn.go{background:#3f6;border-color:#3f6;color:#000;}
.mv-btn.sm{font-size:13px;padding:1px 7px;}
.mv-grille{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:16px;}
@media (max-width:760px){.mv-grille{grid-template-columns:1fr;}}
.mv-media{background:#000;border:1px solid #222;display:flex;justify-content:center;}
.mv-media img,.mv-media video{max-width:100%;max-height:52vh;display:block;}
.mv-ligne{display:grid;grid-template-columns:1fr 1.4fr auto;gap:6px;margin-top:6px;}
.mv-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;}
.mv-chip{border:1px solid #333;padding:1px 7px;cursor:pointer;font-size:11px;}
.mv-chip:hover{border-color:#3f6;color:#3f6;}
.mv-liste{list-style:none;margin:0;padding:0;}
.mv-liste li{border-top:1px solid #222;padding:8px 0;display:flex;gap:10px;align-items:center;}
.mv-liste li.clic{cursor:pointer;}
.mv-liste li.clic:hover{background:#111;}
.mv-hint{font-size:10px;color:#aaa;line-height:1.5;}
.mv-fil li{display:block;}
.mv-instant{border:1px solid #ff0;color:#ff0;background:transparent;font-family:inherit;font-size:10px;padding:0 4px;cursor:pointer;margin-right:4px;}
.mv-pastille{position:absolute;top:4px;right:4px;z-index:3;background:rgba(0,0,0,.85);border:1px solid currentColor;font-size:11px;padding:0 4px;font-family:monospace;pointer-events:none;}
`;

  let dlg = null;
  const fenetre = (titre, html) => {
    if (!dlg) {
      const st = document.createElement('style');
      st.textContent = CSS;
      document.head.appendChild(st);
      dlg = document.createElement('dialog');
      dlg.className = 'mv';
      document.body.appendChild(dlg);
      dlg.addEventListener('click', (e) => { if (e.target.closest('[data-mv=fermer]')) dlg.close(); });
      dlg.addEventListener('close', () => rafraichirPastilles(true));
    }
    dlg.innerHTML = `<div class="mv-top"><h2>${titre}</h2><button class="mv-btn sm" data-mv="fermer">Fermer ✕</button></div><div class="mv-corps">${html}</div>`;
    if (!dlg.open) dlg.showModal();
    return dlg;
  };

  const apercuMedia = (url) => /\.(mp4|mov|webm|m4v)$/i.test(url)
    ? `<video src="${esc(url)}" controls playsinline preload="metadata"></video>`
    : /\.(mp3|m4a|wav|ogg|aac|flac)$/i.test(url) ? `<audio src="${esc(url)}" controls style="width:100%;margin:20px"></audio>`
    : `<img src="${esc(url)}" alt="">`;

  const contacts = () => { try { return JSON.parse(localStorage.getItem(CLE_CONTACTS) || '[]'); } catch (e) { return []; } };
  const memoriserContacts = (liste) => {
    try {
      const tous = contacts();
      liste.forEach((p) => {
        const i = tous.findIndex((c) => c.name === p.name && c.email === p.email);
        if (i >= 0) tous.splice(i, 1);
        tous.unshift(p);
      });
      localStorage.setItem(CLE_CONTACTS, JSON.stringify(tous.slice(0, 20)));
    } catch (e) { /* stockage indisponible */ }
  };

  const ligneLien = (p) => `
    <li><span title="${esc(p.decision)}">${DEC[p.decision] || '🕓'}</span>
      <div style="flex:1;min-width:0"><b>${esc(p.name)}</b> <span class="mv-hint">${esc(p.email || '')}</span>
        ${p.envoi ? `<div class="mv-hint">${esc(p.envoi)}</div>` : ''}
        ${p.last_seen ? `<div class="mv-hint">Vu le ${esc(p.last_seen)}</div>` : ''}</div>
      <button class="mv-btn sm" data-copier="${esc(p.lien)}">Copier le lien</button>
      ${navigator.share ? `<button class="mv-btn sm" data-partager="${esc(p.lien)}" data-nom="${esc(p.name)}">Partager</button>` : ''}
      ${p.email ? `<button class="mv-btn sm" data-relancer="${p.id}">Relancer</button>` : ''}
    </li>`;

  // ── Créer une demande ────────────────────────────────────────────────────────
  const creer = (url, nom, titre) => {
    const d = fenetre('FAIRE <span>VALIDER</span>', `
      <div class="mv-grille">
        <div><div class="mv-media">${apercuMedia(url)}</div><p class="mv-hint">${esc(nom || url.split('/').pop())}</p></div>
        <div>
          <label>Titre</label><input type="text" id="mv-titre" value="${esc(titre || nom || '')}">
          <label>Message pour les relecteurs</label>
          <textarea id="mv-message" placeholder="Ex. Voici la promo du 16 octobre, merci de valider avant jeudi."></textarea>
          <label>Règle</label>
          <select id="mv-regle"><option value="all">Validé quand tout le monde a validé</option><option value="one">Une seule validation suffit</option></select>
          <label>Personnes (nom, e-mail facultatif)</label>
          <div id="mv-personnes"></div>
          <button class="mv-btn sm" id="mv-plus" style="margin-top:6px">+ Personne</button>
          ${contacts().length ? `<div class="mv-hint" style="margin-top:8px">Déjà sollicités :</div><div class="mv-chips">${contacts().map((c, i) => `<span class="mv-chip" data-contact="${i}">+ ${esc(c.name)}</span>`).join('')}</div>` : ''}
          <p class="mv-hint" style="margin-top:8px">Chaque personne reçoit un lien personnel (par e-mail si l'adresse est renseignée et l'envoi d'e-mails configuré dans les réglages, sinon à copier dans WhatsApp, Messenger…). Pas de compte nécessaire.</p>
          <div style="margin-top:10px"><button class="mv-btn go" id="mv-envoyer">Envoyer pour validation</button></div>
          <p class="mv-hint" id="mv-info"></p>
        </div>
      </div>`);
    const ajouterLigne = (p = { name: '', email: '' }) => {
      const div = document.createElement('div');
      div.className = 'mv-ligne';
      div.innerHTML = `<input type="text" placeholder="Nom" value="${esc(p.name)}"><input type="email" placeholder="e-mail (facultatif)" value="${esc(p.email)}"><button class="mv-btn sm" title="Retirer">✕</button>`;
      div.querySelector('button').onclick = () => div.remove();
      $('#mv-personnes', d).appendChild(div);
    };
    ajouterLigne();
    $('#mv-plus', d).onclick = () => ajouterLigne();
    d.querySelectorAll('[data-contact]').forEach((c) => {
      c.onclick = () => {
        const p = contacts()[Number(c.dataset.contact)];
        const vide = [...d.querySelectorAll('#mv-personnes .mv-ligne')].find((l) => !l.querySelector('input').value.trim());
        if (vide) { vide.querySelectorAll('input')[0].value = p.name; vide.querySelectorAll('input')[1].value = p.email; } else ajouterLigne(p);
      };
    });
    $('#mv-envoyer', d).onclick = async (e) => {
      const people = [...d.querySelectorAll('#mv-personnes .mv-ligne')].map((l) => {
        const [n, m] = l.querySelectorAll('input');
        return { name: n.value.trim(), email: m.value.trim() };
      }).filter((p) => p.name);
      if (!people.length) { $('#mv-info', d).textContent = 'Ajoutez au moins une personne.'; return; }
      e.target.disabled = true;
      try {
        const r = await api('/reviews', { method: 'POST', body: JSON.stringify({
          media_url: url, media_name: nom || url.split('/').pop(),
          title: $('#mv-titre', d).value.trim(), message: $('#mv-message', d).value.trim(),
          rule: $('#mv-regle', d).value, people,
        }) });
        memoriserContacts(people);
        detail(r.id, r);
      } catch (err) {
        $('#mv-info', d).textContent = 'Erreur : ' + err.message;
        e.target.disabled = false;
      }
    };
  };

  // ── Suivi ────────────────────────────────────────────────────────────────────
  const suivi = async (id) => {
    if (id) return detail(id);
    const d = fenetre('<span>✅</span> VALIDATIONS', '<p class="mv-hint">Chargement…</p>');
    try {
      const liste = await api('/reviews');
      $('.mv-corps', d).innerHTML = liste.length ? `<ul class="mv-liste">${liste.map((r) => {
        const [ic, lib, coul] = STATUTS[r.status] || STATUTS.pending;
        return `<li class="clic" data-ouvrir="${r.id}">
          <span style="color:${coul};min-width:150px">${ic} ${lib}</span>
          <div style="flex:1;min-width:0"><b>${esc(r.title || r.media_name)}</b>
            <div class="mv-hint">${r.nb_valide}/${r.nb_personnes} validé · ${r.nb_commentaires} commentaire${r.nb_commentaires > 1 ? 's' : ''} · ${esc(r.updated_at)}</div></div>
        </li>`;
      }).join('')}</ul>`
        : '<p class="mv-hint">Aucune demande pour l\'instant. Dans la médiathèque, le bouton ✅ d\'un média lance une demande de validation.</p>';
      d.querySelectorAll('[data-ouvrir]').forEach((li) => { li.onclick = () => detail(Number(li.dataset.ouvrir)); });
    } catch (e) { $('.mv-corps', d).innerHTML = `<p class="mv-hint">${esc(e.message)}</p>`; }
  };

  const detail = async (id, deja) => {
    const d = fenetre('<span>✅</span> VALIDATION', '<p class="mv-hint">Chargement…</p>');
    let r;
    try { r = deja || await api('/reviews/' + id); } catch (e) { $('.mv-corps', d).innerHTML = esc(e.message); return; }
    const [ic, lib, coul] = STATUTS[r.status] || STATUTS.pending;
    $('.mv-corps', d).innerHTML = `
      <p style="margin:0 0 10px"><button class="mv-btn sm" data-retour>← Toutes les validations</button></p>
      <div class="mv-grille">
        <div><div class="mv-media">${apercuMedia(r.media_url)}</div>
          <p class="mv-hint">${esc(r.media_name)} · ${r.rule === 'one' ? 'une validation suffit' : 'validation de chacun requise'}</p>
          ${r.message ? `<p style="white-space:pre-wrap">${esc(r.message)}</p>` : ''}</div>
        <div>
          <div style="font-family:'Anton',sans-serif;font-size:20px">${esc(r.title || r.media_name)}</div>
          <p style="color:${coul};font-family:'VT323',monospace;font-size:20px;letter-spacing:2px;margin:4px 0">${ic} ${lib}</p>
          <label>Personnes</label>
          <ul class="mv-liste">${r.people.map(ligneLien).join('')}</ul>
          <div class="mv-ligne"><input type="text" id="mv-np-nom" placeholder="Nom"><input type="email" id="mv-np-mail" placeholder="e-mail (facultatif)"><button class="mv-btn sm" id="mv-np">+ Ajouter</button></div>
          <label>Commentaires</label>
          <ul class="mv-liste mv-fil" id="mv-fil">${r.comments.length ? r.comments.map((c) => `
            <li><b style="color:${c.equipe ? '#00FFDD' : '#f0f0f0'}">${esc(c.author)}</b> <span class="mv-hint">${esc(c.created_at)}</span>
              <div style="white-space:pre-wrap">${c.time_sec !== null ? `<button class="mv-instant" data-t="${c.time_sec}">${minsec(c.time_sec)}</button>` : ''}${esc(c.body)}</div></li>`).join('')
            : '<li class="mv-hint">Pas encore de commentaire.</li>'}</ul>
          <textarea id="mv-reponse" placeholder="Répondre aux relecteurs…" style="margin-top:8px"></textarea>
          <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
            <button class="mv-btn" id="mv-repondre">Répondre</button>
            <button class="mv-btn sm" id="mv-suppr" style="margin-left:auto">Supprimer la demande</button>
          </div>
          <p class="mv-hint" id="mv-info"></p>
        </div>
      </div>`;
    const video = $('.mv-media video', d);
    const info = (t) => { $('#mv-info', d).textContent = t; };
    d.querySelector('[data-retour]').onclick = () => suivi();
    d.querySelectorAll('[data-t]').forEach((b) => { b.onclick = () => { if (video) { video.currentTime = Number(b.dataset.t); video.play(); } }; });
    d.querySelectorAll('[data-copier]').forEach((b) => {
      b.onclick = async () => { try { await navigator.clipboard.writeText(b.dataset.copier); b.textContent = 'Copié ✓'; } catch (e) { prompt('Lien à copier :', b.dataset.copier); } };
    });
    d.querySelectorAll('[data-partager]').forEach((b) => {
      b.onclick = () => navigator.share({ title: 'Validation Electroboy 80', text: `Bonjour ${b.dataset.nom}, ton avis sur ce média avant publication :`, url: b.dataset.partager }).catch(() => {});
    });
    d.querySelectorAll('[data-relancer]').forEach((b) => {
      b.onclick = async () => { try { const x = await api(`/reviews/${r.id}/remind/${b.dataset.relancer}`, { method: 'POST' }); info(x.envoi); } catch (e) { info('Erreur : ' + e.message); } };
    });
    $('#mv-np', d).onclick = async () => {
      const name = $('#mv-np-nom', d).value.trim();
      if (!name) { info('Nom requis.'); return; }
      try { const x = await api(`/reviews/${r.id}/people`, { method: 'POST', body: JSON.stringify({ name, email: $('#mv-np-mail', d).value.trim() }) }); detail(r.id, x); } catch (e) { info('Erreur : ' + e.message); }
    };
    $('#mv-repondre', d).onclick = async () => {
      const body = $('#mv-reponse', d).value.trim();
      if (!body) return;
      const corps = { body };
      if (video && video.currentTime > 0 && video.paused) corps.time_sec = video.currentTime;
      try { detail(r.id, await api(`/reviews/${r.id}/comment`, { method: 'POST', body: JSON.stringify(corps) })); } catch (e) { info('Erreur : ' + e.message); }
    };
    $('#mv-suppr', d).onclick = async () => {
      if (!confirm('Supprimer cette demande et ses commentaires ? Les liens envoyés ne fonctionneront plus. Le média reste dans la médiathèque.')) return;
      await api('/reviews/' + r.id, { method: 'DELETE' });
      suivi();
    };
  };

  // ── Médiathèque : bouton ✅ et pastilles ──────────────────────────────────────
  let statuts = new Map();
  let chargeLe = 0;
  const rafraichirPastilles = async (forcer) => {
    if (forcer || Date.now() - chargeLe > 20000) {
      try {
        const liste = await api('/reviews');
        statuts = new Map();
        liste.forEach((r) => { if (!statuts.has(r.media_url)) statuts.set(r.media_url, r); }); // la plus récente
        chargeLe = Date.now();
      } catch (e) { return; }
    }
    document.querySelectorAll('#media-grid .media-card[data-fn]').forEach((card) => {
      const r = statuts.get((window.EB80_UPLOADS || '/uploads/') + card.dataset.fn);
      let p = card.querySelector('.mv-pastille');
      if (!r) { if (p) p.remove(); return; }
      const [ic, lib, coul] = STATUTS[r.status] || STATUTS.pending;
      if (!p) { p = document.createElement('div'); p.className = 'mv-pastille'; card.appendChild(p); }
      p.style.color = coul;
      p.textContent = `${ic} ${r.nb_valide}/${r.nb_personnes}`;
      p.title = `Validation : ${lib}`;
    });
  };
  const enrichirGrille = () => {
    // Entrée « Faire valider » dans le menu déroulant d'actions de chaque fichier.
    document.querySelectorAll('#media-grid .media-card[data-fn] .mc-dropdown').forEach((menu) => {
      if (menu.querySelector('.mv-item')) return;
      const fn = menu.closest('.media-card').dataset.fn;
      const item = document.createElement('button');
      item.className = 'mc-dropdown-item mv-item';
      item.innerHTML = '<span style="font-size:13px;width:16px;text-align:center">✅</span><span>Faire valider</span>';
      item.onclick = (e) => {
        e.stopPropagation();
        if (typeof window.closeMcMenu === 'function') window.closeMcMenu();
        const r = statuts.get((window.EB80_UPLOADS || '/uploads/') + fn);
        if (r && confirm('Une demande de validation existe déjà pour ce média. L\'ouvrir ? (Annuler pour en créer une nouvelle)')) detail(r.id);
        else creer((window.EB80_UPLOADS || '/uploads/') + fn, fn);
      };
      const apres = menu.querySelector('.mc-dropdown-item');
      if (apres) apres.after(item); else menu.prepend(item);
    });
    rafraichirPastilles(false);
  };

  const brancher = () => {
    const grille = document.getElementById('media-grid');
    if (grille) {
      let attente = null;
      new MutationObserver(() => { clearTimeout(attente); attente = setTimeout(enrichirGrille, 150); })
        .observe(grille, { childList: true, subtree: true });
    }
    const entete = document.querySelector('#page-media .section-header > div:last-child');
    if (entete && !entete.querySelector('.mv-entete')) {
      const b = document.createElement('button');
      b.className = 'btn btn-ghost mv-entete';
      b.textContent = '✅ Validations';
      b.onclick = () => suivi();
      entete.prepend(b);
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', brancher);
  else brancher();

  window.EB80Validation = { creer, suivi };
})();
