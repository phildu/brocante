/* Carte de la tournée : arrêts numérotés dans l'ordre de passage, et carte de sélection des commerces à visiter.
   Fond IGN (Géoplateforme) ; les adresses sont situées une à une par tournee-geo.php puis mémorisées sur la fiche. */
(function () {
  'use strict';
  var dataEl = document.getElementById('fv-tmap-data');
  if (!dataEl || !window.L) return;
  var data;
  try { data = JSON.parse(dataEl.textContent); } catch (error) { return; }

  function esc(text) {
    return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function km(a, b) {
    var r = 6371.0088, rad = Math.PI / 180;
    var dp = (b.lat - a.lat) * rad, dl = (b.lng - a.lng) * rad;
    var h = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dl / 2) * Math.sin(dl / 2);
    return 2 * r * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  function makeMap(id) {
    var el = document.getElementById(id);
    if (!el) return null;
    var map = L.map(el, { zoomControl: true, maxZoom: 20 }).setView([46.6, 2.4], 6);
    L.tileLayer(data.tiles, { maxZoom: 20, maxNativeZoom: 19, attribution: 'Plan © <a href="https://www.ign.fr" target="_blank" rel="noopener">IGN</a>' }).addTo(map);
    return map;
  }
  function has(item) { return typeof item.lat === 'number' && typeof item.lng === 'number'; }

  /* Situe les commerces sans position, un par un (le serveur géocode et mémorise). */
  function locate(items, onEach, onDone) {
    var todo = items.filter(function (item) { return !has(item); });
    var done = 0;
    (function next() {
      var item = todo.shift();
      if (!item) { onDone(done); return; }
      var body = new FormData();
      body.append('csrf', data.csrf);
      body.append('id', item.id);
      fetch(data.geoUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok) { item.lat = res.lat; item.lng = res.lng; done++; }
          onEach(item, res && res.ok);
        })
        .catch(function () { onEach(item, false); })
        .then(next);
    })();
    return todo.length;
  }

  var onPoolLocated = function () {};   // renseigné par la carte de sélection : reprend les positions trouvées ailleurs

  /* ── Carte de la tournée ── */
  var routeMap = makeMap('fv-route-map');
  if (routeMap && data.stops.length) {
    var routeLayer = L.layerGroup().addTo(routeMap);
    var status = document.getElementById('fv-route-status');
    var drawRoute = function (fit) {
      routeLayer.clearLayers();
      var pts = [], missing = 0, dist = 0, prev = null;
      data.stops.forEach(function (s) {
        if (!has(s)) { missing++; return; }
        var ll = [s.lat, s.lng];
        pts.push(ll);
        if (prev) dist += km(prev, s);
        prev = s;
        var popup = '<b>' + s.n + '. ' + esc(s.label) + '</b><br>' + esc(s.addr) + '<br><a href="' + esc(s.url) + '" target="_blank" rel="noopener">Y aller</a>';
        // Toute la base : des points simples (des centaines de pastilles numérotées ne se liraient plus)
        var marker = data.stops.length > 150
          ? L.circleMarker(ll, { radius: 5, weight: 1.5, color: '#17384f', fillColor: '#cf5b34', fillOpacity: .9 })
          : L.marker(ll, { icon: L.divIcon({ className: 'fv-pin', html: '<span>' + s.n + '</span>', iconSize: [28, 28], iconAnchor: [14, 14] }) });
        marker.bindPopup(popup).addTo(routeLayer);
      });
      if (pts.length > 1) L.polyline(pts, { color: '#cf5b34', weight: 3, opacity: .8, dashArray: '6 6' }).addTo(routeLayer);
      if (pts.length && fit) routeMap.fitBounds(L.latLngBounds(pts), { padding: [30, 30], maxZoom: 17 });
      if (status) {
        status.textContent = pts.length + ' arrêt' + (pts.length > 1 ? 's' : '') + ' sur ' + data.stops.length + ' situé' + (pts.length > 1 ? 's' : '') +
          (pts.length > 1 ? ' · environ ' + dist.toFixed(1).replace('.', ',') + ' km à vol d’oiseau' : '') +
          (missing ? ' · ' + missing + ' sans position' : '');
      }
    };
    drawRoute(true);
    var pending = data.stops.filter(function (s) { return !has(s); }).length;
    if (pending && status) status.textContent = 'Situation de ' + pending + ' adresse' + (pending > 1 ? 's' : '') + '…';
    var located = 0;
    locate(data.stops, function (item, ok) {
      if (ok) onPoolLocated(item);
      located++;
      if (status) status.textContent = 'Situation des adresses : ' + located + ' / ' + pending + '…';
      if (located % 25 === 0) drawRoute(located === 25);   // le tracé se complète par paquets, la carte se cadre une fois les premiers points connus
    }, function () { drawRoute(true); });
  }

  /* ── Carte de sélection ── */
  var poolMap = makeMap('fv-pool-map');
  if (poolMap) {
    var poolStatus = document.getElementById('fv-pool-status');
    var layer = L.layerGroup().addTo(poolMap);
    var markers = {};
    var box = function (id) { return document.querySelector('[data-select-item][value="' + id + '"]'); };
    var paint = function (item) {
      var m = markers[item.id], b = box(item.id);
      if (m) m.setStyle(b && b.checked ? { color: '#17384f', fillColor: '#cf5b34', fillOpacity: .95, radius: 9 } : { color: '#17384f', fillColor: '#ffffff', fillOpacity: .9, radius: 6 });
    };
    var summary = function () {
      var located = data.pool.filter(has).length;
      if (poolStatus) poolStatus.textContent = located + ' situé' + (located > 1 ? 's' : '') + ' sur ' + data.pool.length + ' commerce' + (data.pool.length > 1 ? 's' : '') + ' · cliquez un point pour l’ajouter ou le retirer.';
    };
    var fitPool = function () {
      var pts = data.pool.filter(has).map(function (i) { return [i.lat, i.lng]; });
      if (pts.length) poolMap.fitBounds(L.latLngBounds(pts), { padding: [30, 30], maxZoom: 16 });
    };
    var addMarker = function (item) {
      if (!has(item) || markers[item.id]) return;
      var m = L.circleMarker([item.lat, item.lng], { weight: 2 }).addTo(layer);
      m.bindTooltip(esc(item.label) + '<br><small>' + esc(item.addr) + '</small>');
      m.on('click', function () {
        var b = box(item.id);
        if (!b) return;
        b.checked = !b.checked;
        b.dispatchEvent(new Event('change', { bubbles: true }));
      });
      markers[item.id] = m;
      paint(item);
    };
    data.pool.forEach(addMarker);
    fitPool();
    summary();
    onPoolLocated = function (stop) {
      data.pool.forEach(function (item) {
        if (item.id !== stop.id || has(item)) return;
        var first = !Object.keys(markers).length;
        item.lat = stop.lat;
        item.lng = stop.lng;
        addMarker(item);
        if (first) fitPool();
      });
      summary();
    };
    data.pool.forEach(function (item) {
      var b = box(item.id);
      if (b) b.addEventListener('change', function () { paint(item); });
    });
    var all = document.querySelector('[data-select-all]');
    if (all) all.addEventListener('click', function () { data.pool.forEach(paint); });

    var locateBtn = document.getElementById('fv-pool-locate');
    if (locateBtn) {
      var missing = data.pool.filter(function (i) { return !has(i); }).length;
      if (!missing) locateBtn.hidden = true;
      locateBtn.addEventListener('click', function () {
        locateBtn.disabled = true;
        var total = missing, count = 0;
        locate(data.pool, function (item) {
          count++;
          addMarker(item);
          if (poolStatus) poolStatus.textContent = 'Situation des adresses : ' + count + ' / ' + total + '…';
        }, function () {
          fitPool();
          summary();
          locateBtn.disabled = false;
          locateBtn.hidden = data.pool.every(has);
        });
      });
    }

    var inView = document.getElementById('fv-pool-inview');
    if (inView) inView.addEventListener('click', function () {
      var bounds = poolMap.getBounds(), first = null, n = 0;
      data.pool.forEach(function (item) {
        var b = box(item.id);
        if (!b || !has(item) || !bounds.contains([item.lat, item.lng])) return;
        b.checked = true;
        first = first || b;
        n++;
        paint(item);
      });
      if (first) first.dispatchEvent(new Event('change', { bubbles: true }));
      if (poolStatus) poolStatus.textContent = n + ' commerce' + (n > 1 ? 's' : '') + ' coché' + (n > 1 ? 's' : '') + ' dans la vue.';
    });
  }
})();
