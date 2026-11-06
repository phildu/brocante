// Modale d'édition d'une pièce (admin/catalog.php) : les formulaires (#pm-sources > form.pm-form) sont déplacés dans la fenêtre à l'ouverture et
// remis en place à la fermeture, donc tous les scripts de la fiche (IA, prix, promo, code-barres…) continuent de fonctionner tels quels.
(function () {
  var src = document.getElementById('pm-sources');
  if (!src) return;
  var overlay = document.createElement('div'), dlg = document.createElement('div');
  overlay.className = 'pm-overlay'; overlay.hidden = true;
  dlg.className = 'pm-dialog'; dlg.setAttribute('role', 'dialog'); dlg.setAttribute('aria-modal', 'true'); dlg.setAttribute('aria-label', 'Édition de la pièce');
  overlay.appendChild(dlg); document.body.appendChild(overlay);
  var current = null, opener = null, dirty = false, TAB_KEY = 'pm_tab';

  function refs() { return [].slice.call(document.querySelectorAll('.catalog-table tbody tr[data-ref]')).filter(function (r) { return r.offsetParent !== null || r.getClientRects().length; }).map(function (r) { return r.dataset.ref; }); }
  function formOf(ref) { return document.getElementById('pmf-' + ref); }
  function tabOf(form) { try { return sessionStorage.getItem(TAB_KEY) || 'essentiel'; } catch (e) { return 'essentiel'; } }
  function showTab(form, name) {
    var found = false;
    form.querySelectorAll('[data-pm-panel]').forEach(function (p) { var on = p.dataset.pmPanel === name; p.hidden = !on; if (on) found = true; });
    if (!found) return showTab(form, 'essentiel');
    form.dataset.pmActive = name;
    form.querySelectorAll('[data-pm-tab]').forEach(function (t) { t.setAttribute('aria-selected', t.dataset.pmTab === name ? 'true' : 'false'); });
    try { sessionStorage.setItem(TAB_KEY, name); } catch (e) {}
    var panels = form.querySelector('.pm-panels'); if (panels) panels.scrollTop = 0;
  }
  function setDirty(form, v) { dirty = v; var d = form.querySelector('[data-pm-dirty]'); if (d) d.hidden = !v; }

  function open(ref, fromHash) {
    var form = formOf(ref); if (!form) return;
    if (current && current !== form && !close(false)) return;
    if (current === form) return;
    current = form; dirty = false;
    dlg.appendChild(form); overlay.hidden = false; document.body.classList.add('pm-open');
    showTab(form, tabOf(form)); setDirty(form, false);
    var list = refs(), i = list.indexOf(ref);
    form.querySelector('[data-pm-prev]').disabled = i <= 0; form.querySelector('[data-pm-next]').disabled = i < 0 || i >= list.length - 1;
    var fl = form.querySelector('[data-pm-flash]'), page = document.querySelector('main .publish-status');
    if (fl) { if (fromHash && page) { fl.textContent = page.textContent; fl.dataset.kind = page.dataset.kind || 'ok'; fl.hidden = false; page.hidden = true; } else fl.hidden = true; }
    try { history.replaceState(null, '', location.pathname + location.search + '#produit-' + ref); } catch (e) {}
    var first = form.querySelector('.pm-panel:not([hidden]) input[type="text"]'); if (first && !('ontouchstart' in window)) first.focus(); else dlg.focus && form.querySelector('.pm-ico').focus();
  }
  function close(force) {
    if (!current) return true;
    if (!force && dirty && !confirm('Des modifications ne sont pas enregistrées. Les abandonner ?')) return false;
    var form = current; current = null; dirty = false;
    src.appendChild(form); overlay.hidden = true; document.body.classList.remove('pm-open');
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
    if (opener && document.contains(opener)) { opener.focus({ preventScroll: true }); var row = opener.closest('tr'); if (row) row.scrollIntoView({ block: 'nearest' }); }
    return true;
  }
  function step(dir) {
    if (!current) return;
    var list = refs(), i = list.indexOf(current.dataset.ref), next = list[i + dir];
    if (next && close(false)) open(next, false);
  }

  document.querySelectorAll('[data-toggle-ref]').forEach(function (link) {
    link.addEventListener('click', function (e) { e.preventDefault(); opener = link; open(link.dataset.toggleRef, false); });
  });
  overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(false); });
  dlg.addEventListener('click', function (e) {
    var t = e.target.closest('[data-pm-tab]'); if (t && current) return showTab(current, t.dataset.pmTab);
    if (e.target.closest('[data-pm-close]')) return close(false);
    if (e.target.closest('[data-pm-prev]')) return step(-1);
    if (e.target.closest('[data-pm-next]')) return step(1);
  });
  // Un champ invalide dans une section masquée : on affiche la section (sinon le navigateur bloque l'envoi sans rien montrer).
  dlg.addEventListener('invalid', function (e) { var p = e.target.closest('[data-pm-panel]'); if (p && current) showTab(current, p.dataset.pmPanel); }, true);
  dlg.addEventListener('input', function (e) {
    if (!current) return; setDirty(current, true);
    if (e.target.name === 'name') { var t = current.querySelector('[data-pm-title]'); if (t) t.textContent = e.target.value || '(sans nom)'; }
  });
  dlg.addEventListener('change', function () { if (current) setDirty(current, true); });
  dlg.addEventListener('submit', function () { dirty = false; if (current) setDirty(current, false); });
  document.addEventListener('keydown', function (e) {
    if (!current) return;
    if (e.key === 'Escape') { if (document.querySelector('dialog[open], .scan-overlay') || [].some.call(document.querySelectorAll('.lb-box'), function (n) { return getComputedStyle(n).display !== 'none'; })) return; e.preventDefault(); close(false); return; }
    if (e.key !== 'Tab') return;
    var f = [].filter.call(dlg.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'), function (n) { return !n.disabled && !n.closest('[hidden]') && n.offsetParent !== null && !n.classList.contains('pm-default'); });
    if (!f.length) return;
    if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
    else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
  });
  window.addEventListener('beforeunload', function (e) { if (current && dirty) { e.preventDefault(); e.returnValue = ''; } });

  window.addEventListener('hashchange', function () {
    if (location.hash.indexOf('#produit-') !== 0) return;
    var ref = decodeURIComponent(location.hash.slice('#produit-'.length));
    if (!current || current.dataset.ref !== ref) open(ref, false);
  });
  if (location.hash.indexOf('#produit-') === 0) { var r = decodeURIComponent(location.hash.slice('#produit-'.length)); opener = document.querySelector('[data-toggle-ref="' + (window.CSS && CSS.escape ? CSS.escape(r) : r) + '"]'); open(r, true); }
})();
