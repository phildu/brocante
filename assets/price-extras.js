// « Faire une offre » (prix négociable) : ouvre la fenêtre de saisie et envoie l'offre à offre.php sans quitter la fiche.
(function () {
  'use strict';
  var dlg = document.querySelector('[data-offer-dialog]');
  if (!dlg) return;
  var form = dlg.querySelector('[data-offer-form]'), status = dlg.querySelector('[data-offer-status]');
  document.querySelectorAll('[data-offer-open]').forEach(function (b) { b.addEventListener('click', function () { status.textContent = ''; status.removeAttribute('data-kind'); dlg.showModal(); var a = form.querySelector('[name="amount"]'); if (a) a.focus(); }); });
  dlg.querySelectorAll('[data-offer-close]').forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); });
  dlg.addEventListener('click', function (e) { if (e.target !== dlg) return; var r = dlg.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) dlg.close(); });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = form.querySelector('[type="submit"]'); btn.disabled = true; status.textContent = 'Envoi…'; status.removeAttribute('data-kind');
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Réponse inattendue du serveur.' }; }); })
      .catch(function () { return { ok: false, message: 'Connexion perdue : réessayez.' }; })
      .then(function (res) {
        btn.disabled = false; status.textContent = res.message || ''; status.setAttribute('data-kind', res.ok ? 'ok' : 'error');
        if (res.ok) { form.reset(); setTimeout(function () { dlg.close(); }, 2200); }
      });
  });
  if (/[?&]offre=1/.test(location.search)) { /* repli sans JavaScript : le message est affiché par la fiche */ }
})();
