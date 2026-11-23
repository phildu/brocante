// Export CSV du rapport de prix (généré dans le navigateur, rien n'est envoyé).
(function () {
  const btn = document.getElementById('am-price-csv');
  const table = document.getElementById('am-price-table');
  if (!btn || !table) return;
  btn.addEventListener('click', function () {
    const esc = (v) => '"' + String(v).replace(/"/g, '""').replace(/\s+/g, ' ').trim() + '"';
    const lines = Array.from(table.querySelectorAll('tr')).map((tr) =>
      Array.from(tr.children).map((c) => esc(c.textContent)).join(';'));
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'prix-suggeres-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  });
})();
