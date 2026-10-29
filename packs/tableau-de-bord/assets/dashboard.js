(function () {
  var root = document.getElementById('td-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, msg = document.getElementById('td-msg'), cfg = {};
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
  function table(id, head, rows, empty) {
    var t = document.getElementById(id); t.textContent = '';
    var tr = el('tr'); head.forEach(function (h, i) { tr.appendChild(el('th', h, { align: i ? 'right' : 'left' })); }); t.appendChild(tr);
    if (!rows.length) { var r0 = el('tr'); r0.appendChild(el('td', empty, { colspan: head.length })); t.appendChild(r0); return; }
    rows.forEach(function (r) { var x = el('tr'); r.forEach(function (c, i) { x.appendChild(el('td', String(c), i ? { align: 'right' } : {})); }); t.appendChild(x); });
  }
  function kpi(label, value) {
    var d = el('div', null, { style: 'border:1px solid var(--line);padding:10px 12px;' });
    d.appendChild(el('div', label, { style: 'font-size:.78rem;color:var(--ink-soft);' })); d.appendChild(el('div', value, { style: 'font-size:1.5rem;font-weight:700;' })); return d;
  }
  function chart(series) {
    var box = document.getElementById('td-chart'); box.textContent = '';
    var max = Math.max.apply(null, series.map(function (s) { return s.views; }).concat([1])), W = 900, H = 160, bw = W / series.length;
    var ns = 'http://www.w3.org/2000/svg', svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + (H + 20)); svg.style.width = '100%'; svg.style.height = 'auto';
    series.forEach(function (s, i) {
      var h = Math.round(s.views / max * H), r = document.createElementNS(ns, 'rect');
      r.setAttribute('x', i * bw + 1); r.setAttribute('y', H - h); r.setAttribute('width', Math.max(1, bw - 2)); r.setAttribute('height', h); r.setAttribute('fill', 'currentColor'); r.setAttribute('opacity', '.55');
      var t = document.createElementNS(ns, 'title'); t.textContent = s.day + ' : ' + s.views + ' pages vues, ' + s.visitors + ' visiteurs'; r.appendChild(t); svg.appendChild(r);
    });
    box.appendChild(svg);
  }
  function load() {
    var days = document.getElementById('td-days').value;
    fetch(api + '&days=' + days, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { msg.textContent = d.error; return; }
      var s = d.stats; cfg = d;
      var k = document.getElementById('td-kpis'); k.textContent = '';
      [['Visiteurs', s.visitors], ['Pages vues', s.views], ['Pièces consultées', s.product_views], ['Ajouts au panier', s.carts], ['Commandes', s.orders], ['Chiffre d’affaires', eur(s.revenue_cents)]].forEach(function (x) { k.appendChild(kpi(x[0], x[1])); });
      chart(s.series);
      table('td-products', ['Pièce', 'Vues', 'Visiteurs', 'Au panier'], s.top_products.map(function (p) { return [p.ref + ' — ' + p.name, p.views, p.visitors, p.carts]; }), 'Pas encore de visite sur une fiche pièce.');
      table('td-sources', ['Origine', 'Type', 'Visiteurs', 'Pièces vues', 'Au panier'], s.sources.map(function (x) { return [x.source, x.medium || '—', x.visitors, x.product_views, x.carts]; }), 'Pas encore de visite.');
      table('td-campaigns', ['Campagne', 'Réseau', 'Visiteurs', 'Pages vues'], s.campaigns.map(function (c) { return [c.campaign, c.source, c.visitors, c.views]; }), 'Aucune campagne : créez un lien ci-dessous.');
      var sel = document.getElementById('td-link-page');
      if (sel.options.length === 1) d.products.forEach(function (p) { sel.appendChild(el('option', p.ref + ' — ' + p.name, { value: p.ref })); });
    });
  }
  document.getElementById('td-days').addEventListener('change', load);
  document.getElementById('td-link').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target, base = f.page.value ? cfg.product_url + '?ref=' + encodeURIComponent(f.page.value) : cfg.shop_url, sep = base.indexOf('?') > -1 ? '&' : '?';
    var clean = function (v) { return String(v).toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, ''); };
    var url = base + sep + 'utm_source=' + clean(f.source.value) + '&utm_medium=social&utm_campaign=' + (clean(f.campaign.value) || 'campagne');
    var out = document.getElementById('td-link-out'); out.value = url; out.select();
    if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { msg.textContent = 'Lien copié.'; }, function () {});
  });
  load();
})();
