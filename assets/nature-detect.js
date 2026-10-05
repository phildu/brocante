// Détection de la nature (vêtements, déco…) et de la sous-catégorie par l'IA, en série, depuis le catalogue : pour les
// pièces cochées, ou pour toutes celles qui n'en ont pas encore. Une requête par pièce (admin/product-ai.php, save=1) :
// chacune est enregistrée tout de suite, on peut donc arrêter à tout moment sans rien perdre.
(function () {
  'use strict';
  var btnSel = document.getElementById('nature-detect');
  var btnMissing = document.getElementById('nature-detect-missing');
  var status = document.getElementById('nature-detect-status');
  if (!btnSel || !status) return;
  var URL_AI = (window.APP_BASE || '') + '/admin/product-ai.php';
  var missing = [];
  try { missing = JSON.parse(btnMissing ? btnMissing.dataset.refs : '[]'); } catch (e) {}
  var running = false, stop = false;

  function checked() {
    return Array.prototype.map.call(document.querySelectorAll('.bulk-select:checked'), function (b) { return b.dataset.ref; });
  }
  function refreshButtons() {
    if (running) return;
    var n = checked().length;
    btnSel.disabled = n === 0;
    btnSel.textContent = n ? 'Détecter la nature (' + n + ')' : 'Détecter la nature (IA)';
  }
  document.addEventListener('change', function (e) {
    if (e.target.classList && (e.target.classList.contains('bulk-select') || e.target.id === 'bulk-select-all')) refreshButtons();
  });

  function detectOne(ref) {
    var fd = new FormData();
    fd.append('fields', 'nature'); fd.append('ref', ref); fd.append('save', '1');
    return fetch(URL_AI, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue.' }; }); })
      .catch(function () { return { ok: false, error: 'Connexion perdue.' }; });
  }

  function run(refs, label) {
    if (running || !refs.length) return;
    running = true; stop = false;
    [btnSel, btnMissing].forEach(function (b) { if (b) b.disabled = true; });
    var stopBtn = document.createElement('button');
    stopBtn.type = 'button'; stopBtn.className = 'btn-small'; stopBtn.textContent = 'Arrêter';
    stopBtn.addEventListener('click', function () { stop = true; stopBtn.disabled = true; });
    status.parentNode.insertBefore(stopBtn, status.nextSibling);
    var done = 0, found = 0, failed = 0;
    (function next(i) {
      if (stop || i >= refs.length) {
        stopBtn.remove(); running = false;
        status.textContent = done + ' pièce(s) traitée(s), ' + found + ' nature(s) détectée(s)' + (failed ? ', ' + failed + ' échec(s)' : '') + (stop && done < refs.length ? ' — arrêté.' : '.') + ' Actualisation…';
        setTimeout(function () { location.reload(); }, 1200);
        return;
      }
      status.textContent = label + ' : ' + (i + 1) + ' / ' + refs.length + ' (Réf. ' + refs[i] + ')… une dizaine de secondes par pièce.';
      detectOne(refs[i]).then(function (res) {
        done++;
        if (res.ok && res.values && res.values.nature) found++; else failed++;
        next(i + 1);
      });
    })(0);
  }

  btnSel.addEventListener('click', function () { run(checked(), 'Détection'); });
  if (btnMissing) btnMissing.addEventListener('click', function () {
    if (confirm('Détecter la nature de ' + missing.length + ' pièce(s) ? Chacune demande une dizaine de secondes ; vous pouvez arrêter à tout moment.')) run(missing, 'Détection');
  });
  refreshButtons();
})();
