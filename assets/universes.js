// Page Univers : les univers sont les grands domaines de la boutique (Mode, Sport, Déco…). Les pastilles les ajoutent ou les
// retirent de la liste ; la liste s'édite (nom, pictogramme, ordre, univers personnalisés) ; l'IA détecte ce que vend la boutique
// d'après ses photos ; le reclassement des pièces se fait en série (une requête par pièce vers admin/product-ai.php, save=1 :
// chaque résultat est enregistré aussitôt).
(function () {
  'use strict';
  var base = window.APP_BASE || '';
  var list = document.getElementById('un-list');
  if (!list) return;
  var tpl = document.getElementById('un-row-tpl');
  var status = document.getElementById('un-status');
  var profileEl = document.getElementById('un-profile');
  var sectorBox = document.getElementById('un-sectors');
  function say(el, text) { el.textContent = text; }

  // ── Liste et pastilles ──
  function rows() { return Array.prototype.slice.call(list.children); }
  function keyOf(li) { return li.querySelector('[name="key[]"]').value; }
  function chips() { return Array.prototype.slice.call(sectorBox.querySelectorAll('.un-sector')); }
  function chipFor(key) { return sectorBox.querySelector('.un-sector[data-key="' + key + '"]'); }
  // Une pastille est « enfoncée » quand la liste contient l'univers de ce domaine.
  function syncChips() {
    var keys = rows().map(keyOf);
    chips().forEach(function (c) { c.setAttribute('aria-pressed', keys.indexOf(c.dataset.key) > -1 ? 'true' : 'false'); });
  }
  function addRow(label, icon, key) {
    var li = tpl.content.firstElementChild.cloneNode(true);
    li.querySelector('[name="key[]"]').value = key || '';
    li.querySelector('[name="label[]"]').value = label || '';
    if (icon) li.querySelector('[name="icon[]"]').value = icon;
    list.appendChild(li);
    return li;
  }
  function removeRow(li, confirmed) {
    var n = parseInt(li.querySelector('[data-del]').dataset.count, 10) || 0;
    if (rows().length < 2) { say(status, 'Gardez au moins un univers.'); return false; }
    if (!confirmed && n > 0 && !confirm('Cet univers contient ' + n + ' pièce' + (n > 1 ? 's' : '') + ' : elles seront à reclasser. Le retirer ?')) return false;
    li.remove();
    return true;
  }
  // Phrase de la boutique tirée des univers cochés, tant que le vendeur ne l'a pas écrite lui-même.
  profileEl.addEventListener('input', function () { profileEl.dataset.auto = ''; });
  function syncProfile() {
    var sel = chips().filter(function (c) { return c.getAttribute('aria-pressed') === 'true'; });
    if (sel.length && (!profileEl.value.trim() || profileEl.dataset.auto)) {
      profileEl.value = sel.map(function (c) { return c.dataset.profile; }).join(' ; ').slice(0, 120);
      profileEl.dataset.auto = '1';
    }
  }
  sectorBox.addEventListener('click', function (e) {
    var c = e.target.closest('.un-sector');
    if (!c) return;
    var existing = rows().filter(function (li) { return keyOf(li) === c.dataset.key; })[0];
    if (existing) { if (!removeRow(existing)) return; }
    else {
      if (rows().length >= 12) { say(status, 'Douze univers au plus.'); return; }
      addRow(c.dataset.label, c.dataset.icon, c.dataset.key);
    }
    syncChips(); syncProfile();
  });
  list.addEventListener('click', function (e) {
    var li = e.target.closest('.un-row');
    if (!li) return;
    if (e.target.closest('[data-up]') && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
    else if (e.target.closest('[data-down]') && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
    else if (e.target.closest('[data-del]')) { if (removeRow(li)) syncChips(); }
  });
  document.getElementById('un-add').addEventListener('click', function () {
    if (rows().length >= 12) { say(status, 'Douze univers au plus.'); return; }
    addRow('').querySelector('[name="label[]"]').focus();
  });

  // ── Détection par l'IA ──
  var ideas = [];
  var detectBtn = document.getElementById('un-detect');
  var ideasBox = document.getElementById('un-ideas');
  function detect() {
    detectBtn.disabled = true; say(status, "L'IA regarde vos photos et vos pièces… (10 à 25 secondes)");
    // Ce que le vendeur a écrit guide l'IA ; sinon elle décide d'après les photos.
    var fd = new FormData(); fd.append('action', 'suggest'); fd.append('hint', profileEl.dataset.auto ? '' : profileEl.value);
    fetch(base + '/admin/universes-action.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Connexion perdue : réessayez.' }; })
      .then(function (res) {
        detectBtn.disabled = false;
        if (!res.ok) { say(status, res.error || 'Détection indisponible.'); return; }
        ideas = res.universes || [];
        // Ce que l'IA a compris est écrit dans le champ : on le relit et on peut le corriger.
        if (res.profile && !profileEl.value.trim()) { profileEl.value = res.profile; profileEl.dataset.auto = ''; }
        var how = { ia: "d'après vos photos", natures: "d'après la nature de vos pièces", texte: "d'après le nom de la boutique" }[res.source] || '';
        var seen = res.seen || {};
        say(status, 'Univers détectés : ' + ideas.map(function (u) { return u.label; }).join(', ') + (how ? ' (' + how + ')' : '') + '.'
          + (seen.photos ? ' ' + seen.photos + ' photo' + (seen.photos > 1 ? 's' : '') + ' analysée' + (seen.photos > 1 ? 's' : '') + (seen.names && seen.names.length ? ' : ' + seen.names.join(', ') : '') + '.' : " Aucune photo de pièce à analyser : l'IA s'est fiée au nom de la boutique."));
        var ul = document.getElementById('un-ideas-list'); ul.textContent = '';
        ideas.forEach(function (u) { var li = document.createElement('li'); li.textContent = u.label; ul.appendChild(li); });
        ideasBox.hidden = !ideas.length;
      });
  }
  detectBtn.addEventListener('click', detect);
  // Premier passage, boutique encore inconnue de l'IA : elle regarde les photos sans attendre un clic.
  if (document.getElementById('un-form').dataset.autoDetect) detect();

  function applyIdeas(replace) {
    var byKey = {};
    rows().forEach(function (li) { byKey[keyOf(li)] = li; });
    var keep = [];
    ideas.forEach(function (u) {
      var li = byKey[u.key];
      if (li) li.querySelector('[name="icon[]"]').value = u.icon;
      else if (rows().length < 12) li = addRow(u.label, u.icon, u.key);
      if (li) keep.push(li);
    });
    // Remplacer : on retire les autres univers ; ajouter : ils restent.
    if (replace) rows().forEach(function (li) { if (keep.indexOf(li) < 0) li.remove(); });
    ideasBox.hidden = true;
    syncChips(); syncProfile();
    say(status, (replace ? 'Liste remplacée' : 'Univers ajoutés') + ' : relisez, puis « Enregistrer les univers ».');
  }
  document.getElementById('un-apply-replace').addEventListener('click', function () { applyIdeas(true); });
  document.getElementById('un-apply-add').addEventListener('click', function () { applyIdeas(false); });

  // ── Reclassement en série ──
  var running = false, stop = false;
  var reStatus = document.getElementById('un-reclass-status');
  function classify(ref) {
    var fd = new FormData();
    fd.append('fields', 'category'); fd.append('ref', ref); fd.append('save', '1');
    return fetch(base + '/admin/product-ai.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .catch(function () { return { ok: false }; });
  }
  function reclass(refs) {
    if (running || !refs.length) return;
    running = true; stop = false;
    document.querySelectorAll('#un-reclass-orphans, #un-reclass-all').forEach(function (b) { b.disabled = true; });
    var stopBtn = document.createElement('button');
    stopBtn.type = 'button'; stopBtn.className = 'btn-small'; stopBtn.textContent = 'Arrêter';
    stopBtn.addEventListener('click', function () { stop = true; stopBtn.disabled = true; });
    reStatus.parentNode.insertBefore(stopBtn, reStatus.nextSibling);
    var done = 0, ok = 0;
    (function next(i) {
      if (stop || i >= refs.length) {
        stopBtn.remove(); running = false;
        say(reStatus, done + ' pièce(s) traitée(s), ' + ok + ' reclassée(s)' + (ok < done ? ', ' + (done - ok) + ' sans univers adapté ou en échec' : '') + '. Actualisation…');
        setTimeout(function () { location.reload(); }, 1200);
        return;
      }
      say(reStatus, 'Reclassement : ' + (i + 1) + ' / ' + refs.length + ' (Réf. ' + refs[i] + ')…');
      classify(refs[i]).then(function (res) { done++; if (res.ok && res.values && res.values.category) ok++; next(i + 1); });
    })(0);
  }
  ['un-reclass-orphans', 'un-reclass-all'].forEach(function (id) {
    var b = document.getElementById(id);
    if (!b) return;
    b.addEventListener('click', function () {
      var refs = JSON.parse(b.dataset.refs || '[]');
      if (confirm('Reclasser ' + refs.length + ' pièce(s) par l\'IA ? Environ ' + Math.max(1, Math.round(refs.length * 10 / 60)) + ' minute(s) ; vous pouvez arrêter à tout moment.')) reclass(refs);
    });
  });
})();
