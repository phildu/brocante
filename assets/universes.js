// Page Univers : édition de la liste (ajout, ordre, retrait), propositions de l'IA, et reclassement des pièces en
// série (une requête par pièce vers admin/product-ai.php, save=1 : chaque résultat est enregistré aussitôt).
(function () {
  'use strict';
  var base = window.APP_BASE || '';
  var list = document.getElementById('un-list');
  if (!list) return;
  var tpl = document.getElementById('un-row-tpl');
  var status = document.getElementById('un-status');
  function say(el, text) { el.textContent = text; }

  // ── Liste ──
  function row(n) { return n.closest('.un-row'); }
  list.addEventListener('click', function (e) {
    var li = e.target.closest('.un-row');
    if (!li) return;
    if (e.target.closest('[data-up]') && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
    else if (e.target.closest('[data-down]') && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
    else if (e.target.closest('[data-del]')) {
      var n = parseInt(e.target.closest('[data-del]').dataset.count, 10) || 0;
      if (list.children.length < 2) { say(status, 'Gardez au moins un univers.'); return; }
      if (n > 0 && !confirm('Cet univers contient ' + n + ' pièce' + (n > 1 ? 's' : '') + ' : elles seront à reclasser. Le retirer ?')) return;
      li.remove();
    }
  });
  function addRow(label, icon) {
    var li = tpl.content.firstElementChild.cloneNode(true);
    li.querySelector('[name="label[]"]').value = label || '';
    if (icon) li.querySelector('[name="icon[]"]').value = icon;
    list.appendChild(li);
    return li;
  }
  document.getElementById('un-add').addEventListener('click', function () {
    if (list.children.length >= 12) { say(status, 'Douze univers au plus.'); return; }
    addRow('').querySelector('[name="label[]"]').focus();
  });

  // ── Propositions de l'IA ──
  var ideas = [];
  var found = '';
  var suggestBtn = document.getElementById('un-suggest');
  var detectBtn = document.getElementById('un-detect');
  var profileInput = document.getElementById('un-profile');
  var ideasBox = document.getElementById('un-ideas');
  function suggest(btn) {
    suggestBtn.disabled = detectBtn.disabled = true; say(status, "L'IA regarde vos photos et vos pièces… (10 à 25 secondes)");
    // Ce que le vendeur a écrit guide l'IA ; sinon elle décide d'après les photos.
    fetch(base + '/admin/universes-action.php', { method: 'POST', body: (function () { var f = new FormData(); f.append('action', 'suggest'); f.append('hint', profileInput.value); return f; })(), credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Connexion perdue : réessayez.' }; })
      .then(function (res) {
        suggestBtn.disabled = detectBtn.disabled = false;
        if (!res.ok) { say(status, res.error || 'Propositions indisponibles.'); return; }
        ideas = res.universes; found = res.profile || '';
        // La boutique détectée est écrite dans le champ : on voit ce que l'IA a compris, et on peut le corriger.
        if (found && !profileInput.value.trim()) profileInput.value = found;
        var seen = res.seen || {};
        say(status, (found ? 'Boutique détectée : ' + found + '.' : '') + (seen.photos ? ' (' + seen.photos + ' photo' + (seen.photos > 1 ? 's' : '') + ' analysée' + (seen.photos > 1 ? 's' : '') + (seen.names && seen.names.length ? ' : ' + seen.names.join(', ') : '') + ')' : ' Aucune photo de pièce à analyser : l\'IA s\'est fiée au nom de la boutique ; décrivez-la dans le champ pour la guider.'));
        var ul = document.getElementById('un-ideas-list'); ul.textContent = '';
        ideas.forEach(function (u) { var li = document.createElement('li'); li.textContent = u.label; ul.appendChild(li); });
        ideasBox.hidden = false;
      });
  }
  suggestBtn.addEventListener('click', function () { suggest(); });
  // Premier passage, boutique encore inconnue de l'IA : elle regarde les photos sans attendre un clic.
  if (document.getElementById('un-form').dataset.autoDetect) suggest();
  detectBtn.addEventListener('click', function () { profileInput.value = ''; suggest(); });
  document.getElementById('un-apply').addEventListener('click', function () {
    // Les univers repris gardent leur clé (leurs pièces restent rattachées) ; les autres sont retirés, les nouveaux ajoutés.
    var byLabel = {};
    Array.prototype.forEach.call(list.children, function (li) { byLabel[li.querySelector('[name="label[]"]').value.trim().toLowerCase()] = li; });
    var keep = [];
    ideas.forEach(function (u) {
      var existing = byLabel[u.label.trim().toLowerCase()];
      if (existing) { existing.querySelector('[name="icon[]"]').value = u.icon; keep.push(existing); }
      else { keep.push(addRow(u.label, u.icon)); }
    });
    Array.prototype.slice.call(list.children).forEach(function (li) { if (keep.indexOf(li) < 0) li.remove(); });
    keep.forEach(function (li) { list.appendChild(li); });
    ideasBox.hidden = true;
    say(status, 'Liste remplacée : relisez, puis « Enregistrer les univers ».');
  });

  // ── Reclassement en série ──
  var running = false, stop = false;
  var reStatus = document.getElementById('un-reclass-status');
  function detect(ref) {
    var fd = new FormData();
    fd.append('fields', 'category'); fd.append('ref', ref); fd.append('save', '1');
    return fetch(base + '/admin/product-ai.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .catch(function () { return { ok: false }; });
  }
  function reclass(refs) {
    if (running || !refs.length) return;
    running = true; stop = false;
    var btns = document.querySelectorAll('#un-reclass-orphans, #un-reclass-all');
    btns.forEach(function (b) { b.disabled = true; });
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
      detect(refs[i]).then(function (res) { done++; if (res.ok && res.values && res.values.category) ok++; next(i + 1); });
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
