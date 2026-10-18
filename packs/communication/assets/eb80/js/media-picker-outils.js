/**
 * ELECTROBOY 80 — Outils du sélecteur de médias (#poster-media-picker, admin.html)
 *
 * Ajoute au-dessus de la grille : recherche, tri (date, taille, ratio, nom) et
 * « tout déplier / tout replier ». Les dossiers (regroupements par préfixe faits par
 * renderPickerGrid) sont repliés par défaut ; une recherche les déplie.
 *
 * Fonctionne en enveloppant renderPickerGrid() : la liste chargée par loadPickerGrid()
 * est filtrée et triée avant l'affichage, puis les séparateurs deviennent des en-têtes
 * cliquables. _pickerCache est une variable globale (let) d'admin.html.
 */
(() => {
  /* global renderPickerGrid, _pickerCache, _pickerSlot, _renderPickerProjectsSection, _renderExtractAudioSection */
  if (typeof renderPickerGrid !== 'function') return;

  const CLE = 'eb80_picker_outils';
  let reglages = { tri: 'recent' };
  try { reglages = { ...reglages, ...JSON.parse(localStorage.getItem(CLE) || '{}') }; } catch (e) { /* stockage indisponible */ }
  let recherche = '';
  const ouverts = new Set(); // dossiers dépliés par l'utilisateur (le temps de la session)
  let source = null;         // liste complète venue de loadPickerGrid (sans séparateurs de dossier)
  let dernierRendu = null;   // tableau laissé par le dernier rendu, pour reconnaître un nouveau chargement
  const ratios = new Map();  // url → largeur / hauteur

  const TRIS = {
    recent: 'Plus récents',
    ancien: 'Plus anciens',
    lourd: 'Taille : plus lourds',
    leger: 'Taille : plus légers',
    portrait: 'Ratio : portrait → paysage',
    paysage: 'Ratio : paysage → portrait',
    nom: 'Nom (A → Z)',
  };
  const sansAccents = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const estImage = (f) => !f.isLink && /\.(jpe?g|png|webp|gif|avif|bmp|tiff?)$/i.test(f.filename || '');
  const estVideo = (f) => !f.isLink && /\.(mp4|mov|webm|m4v)$/i.test(f.filename || '');
  const sauver = () => { try { localStorage.setItem(CLE, JSON.stringify(reglages)); } catch (e) { /* rien */ } };

  // Ratio d'une image ou d'une vidéo (chargé une seule fois par URL).
  const mesurer = (f) => {
    if (ratios.has(f.url) || !(estImage(f) || estVideo(f))) return Promise.resolve();
    if (f._probedRatio) { ratios.set(f.url, f._probedRatio); return Promise.resolve(); }
    return new Promise((ok) => {
      const fin = (r) => { if (r) ratios.set(f.url, r); ok(); };
      const t = setTimeout(() => fin(null), 4000);
      if (estImage(f)) {
        const im = new Image();
        im.onload = () => { clearTimeout(t); fin(im.naturalWidth / im.naturalHeight); };
        im.onerror = () => { clearTimeout(t); fin(null); };
        im.src = f.url;
      } else {
        const v = document.createElement('video');
        v.preload = 'metadata';
        v.muted = true;
        v.onloadedmetadata = () => { clearTimeout(t); fin(v.videoWidth / v.videoHeight); };
        v.onerror = () => { clearTimeout(t); fin(null); };
        v.src = f.url;
      }
    });
  };

  const comparer = (a, b) => {
    const date = (f) => Date.parse(String(f.date || '').replace(' ', 'T')) || 0;
    switch (reglages.tri) {
      case 'ancien': return date(a) - date(b);
      case 'lourd': return (b.size || 0) - (a.size || 0);
      case 'leger': return (a.size || Infinity) - (b.size || Infinity);
      case 'portrait': return (ratios.get(a.url) ?? Infinity) - (ratios.get(b.url) ?? Infinity);
      case 'paysage': return (ratios.get(b.url) ?? -Infinity) - (ratios.get(a.url) ?? -Infinity);
      case 'nom': return sansAccents(a.filename).localeCompare(sansAccents(b.filename), 'fr');
      default: return date(b) - date(a);
    }
  };

  // Réaffichage depuis nos outils : renderPickerGrid vide la grille, on remet donc aussi
  // les sections que loadPickerGrid ajoute après elle (projets, extraction audio).
  const reafficher = () => {
    renderPickerGrid();
    if (typeof _renderPickerProjectsSection === 'function') _renderPickerProjectsSection();
    if (_pickerSlot === 'video-audio' && typeof _renderExtractAudioSection === 'function') _renderExtractAudioSection();
  };

  // ── Barre d'outils ─────────────────────────────────────────────────────────────
  const barre = () => {
    const grille = document.getElementById('picker-grid');
    let b = document.getElementById('picker-outils');
    if (b || !grille) return b;
    const style = document.createElement('style');
    style.textContent = `
#picker-outils{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 18px;border-bottom:2px solid var(--border);flex-shrink:0}
#picker-outils input,#picker-outils select{background:var(--mid,#111);border:1px solid var(--border,#2a2a2a);color:var(--white,#f0f0f0);font-family:'IBM Plex Mono',monospace;font-size:11px;padding:6px 8px}
#picker-outils input{flex:1;min-width:160px}
#picker-outils input:focus,#picker-outils select:focus{outline:none;border-color:var(--yellow)}
#picker-outils button{background:transparent;border:1px solid var(--border,#2a2a2a);color:var(--muted,#aaa);font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:1px;padding:6px 8px;cursor:pointer}
#picker-outils button:hover{border-color:var(--yellow);color:var(--yellow)}
#picker-outils .pk-info{font-size:10px;color:var(--muted,#aaa);letter-spacing:1px}
#picker-grid .pk-entete{cursor:pointer;user-select:none}
#picker-grid .pk-entete:hover{color:var(--yellow)!important}
#picker-grid .pk-replie{display:none!important}`;
    document.head.appendChild(style);
    b = document.createElement('div');
    b.id = 'picker-outils';
    b.innerHTML = `
      <input type="search" id="picker-recherche" placeholder="🔍 Rechercher un fichier…" autocomplete="off">
      <select id="picker-tri" title="Trier">${Object.entries(TRIS).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select>
      <button type="button" data-pk="deplier" title="Déplier tous les dossiers">▾ Tout déplier</button>
      <button type="button" data-pk="replier" title="Replier tous les dossiers">▸ Tout replier</button>
      <span class="pk-info" id="picker-outils-info"></span>`;
    grille.parentElement.insertBefore(b, grille);
    const champ = b.querySelector('#picker-recherche');
    let attente = null;
    champ.addEventListener('input', () => {
      clearTimeout(attente);
      attente = setTimeout(() => { recherche = champ.value.trim(); reafficher(); }, 180);
    });
    b.querySelector('#picker-tri').addEventListener('change', (e) => {
      reglages.tri = e.target.value;
      sauver();
      reafficher();
    });
    b.addEventListener('click', (e) => {
      const action = e.target.closest('[data-pk]')?.dataset.pk;
      if (!action) return;
      document.querySelectorAll('#picker-grid .pk-entete').forEach((h) => basculer(h, action === 'deplier'));
    });
    return b;
  };

  // ── Dossiers repliables ────────────────────────────────────────────────────────
  const cle = (h) => h.dataset.cle;
  const basculer = (entete, ouvrir) => {
    if (ouvrir) ouverts.add(cle(entete)); else ouverts.delete(cle(entete));
    entete.querySelector('.pk-fleche').textContent = ouvrir ? '▾' : '▸';
    document.querySelectorAll(`#picker-grid [data-pk-groupe="${CSS.escape(entete.dataset.groupe)}"]`)
      .forEach((n) => n.classList.toggle('pk-replie', !ouvrir));
  };
  const dossiers = () => {
    const grille = document.getElementById('picker-grid');
    // Séparateurs posés par renderPickerGrid : div pleine largeur sans vignette.
    const enfants = [...grille.children].filter((d) => d.id !== 'picker-projects-section');
    const entetes = enfants.filter((d) => d.style.gridColumn === '1 / -1' && !d.querySelector('img,video,audio'));
    // Chaque vignette appartient au dernier en-tête qui la précède.
    let groupe = null;
    enfants.forEach((d, i) => {
      if (entetes.includes(d)) { groupe = String(i); d.dataset.groupe = groupe; }
      else if (groupe !== null) d.dataset.pkGroupe = groupe;
    });
    entetes.forEach((h) => {
      const libelle = h.textContent.trim();
      // Le « bon format » d'un emplacement à recadrer reste toujours ouvert.
      if (/BON FORMAT/i.test(libelle)) return;
      h.classList.add('pk-entete');
      h.dataset.cle = libelle.replace(/\s*\(\d+\)\s*$/, '');
      h.innerHTML = `<span class="pk-fleche" style="display:inline-block;width:14px">▸</span>${libelle}`;
      h.addEventListener('click', () => basculer(h, !ouverts.has(cle(h))));
      basculer(h, recherche !== '' || ouverts.has(cle(h)));
    });
  };

  // ── Enveloppe de renderPickerGrid ──────────────────────────────────────────────
  const original = renderPickerGrid;
  const enveloppe = function () {
    barre();
    // Nouveau chargement (loadPickerGrid a remplacé la liste) : on garde la version complète.
    if (_pickerCache !== dernierRendu) source = _pickerCache.filter((f) => f._sep !== 'folder');
    const tri = document.getElementById('picker-tri');
    if (tri) tri.value = reglages.tri;
    const q = sansAccents(recherche);
    const garde = (f) => !q || sansAccents(`${f.filename} ${f.name || ''} ${f.platform || ''}`).includes(q);
    let liste = source.filter((f) => !f._sep && garde(f));

    const info = document.getElementById('picker-outils-info');
    if (!liste.length && source.length) {
      _pickerCache = liste;
      dernierRendu = _pickerCache;
      document.getElementById('picker-grid').innerHTML =
        `<div style="color:var(--muted);font-size:11px;letter-spacing:2px;grid-column:1/-1;padding:30px 0;text-align:center">Aucun fichier ne correspond à « ${recherche.replace(/[<>&"]/g, '')} »</div>`;
      if (info) info.textContent = `0 / ${source.filter((f) => !f._sep).length}`;
      return;
    }
    const parRatio = reglages.tri === 'portrait' || reglages.tri === 'paysage';
    const aMesurer = parRatio ? liste.filter((f) => !ratios.has(f.url) && (estImage(f) || estVideo(f))) : [];
    if (aMesurer.length) {
      // Tri par ratio : on mesure d'abord les médias, puis on réaffiche.
      if (info) info.textContent = `Mesure des formats… (${aMesurer.length})`;
      Promise.all(aMesurer.map(mesurer)).then(reafficher);
    }
    // Tri à l'intérieur de chaque segment (« bon format » / « autres » d'un emplacement à recadrer).
    const segments = [{ sep: null, fichiers: [] }];
    source.forEach((f) => {
      if (f._sep) segments.push({ sep: f, fichiers: [] });
      else if (garde(f)) segments[segments.length - 1].fichiers.push(f);
    });
    liste = segments.flatMap((sg) => (sg.fichiers.length
      ? [...(sg.sep ? [sg.sep] : []), ...sg.fichiers.slice().sort(comparer)] : []));
    _pickerCache = liste;
    original.apply(this, arguments);
    dernierRendu = _pickerCache;
    const total = source.filter((f) => !f._sep).length;
    const trouves = liste.filter((f) => !f._sep).length;
    if (info && !aMesurer.length) info.textContent = q ? `${trouves} / ${total}` : `${total} fichier(s)`;
    dossiers();
  };
  window.renderPickerGrid = enveloppe;
  // Chaque ouverture du sélecteur repart sans recherche.
  const panneau = document.getElementById('poster-media-picker');
  if (panneau) {
    new MutationObserver(() => {
      if (panneau.style.display === 'none' && recherche) {
        recherche = '';
        const champ = document.getElementById('picker-recherche');
        if (champ) champ.value = '';
      }
    }).observe(panneau, { attributes: true, attributeFilter: ['style'] });
  }
})();
