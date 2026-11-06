/* Sélecteur du point exact du commerce (Administration → Pages légales → Identité).
   Carte IGN Géoplateforme sans clé, Leaflet chargé à l'approche du bloc ; le clic
   place l'épingle, « Centrer sur l'adresse » saute à l'adresse postale. */
(function () {
  'use strict';

  var LEAFLET_CSS = '/assets/lib/leaflet/leaflet.min.css';
  var LEAFLET_JS = '/assets/lib/leaflet/leaflet.min.js';
  var leafletPromise = null;

  function loadLeaflet() {
    if (window.L) return Promise.resolve(window.L);
    if (leafletPromise) return leafletPromise;
    leafletPromise = new Promise(function (resolve, reject) {
      var css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = LEAFLET_CSS;
      document.head.appendChild(css);
      var js = document.createElement('script');
      js.src = LEAFLET_JS;
      js.async = true;
      js.onload = function () { window.L ? resolve(window.L) : reject(new Error('leaflet')); };
      js.onerror = function () { leafletPromise = null; reject(new Error('leaflet')); };
      document.head.appendChild(js);
    });
    return leafletPromise;
  }

  function setStatus(el, message, kind) {
    if (!el) return;
    el.textContent = message || '';
    if (kind) el.dataset.kind = kind; else el.removeAttribute('data-kind');
  }

  function geocode(url, query, limit) {
    var u = new URL(url);
    u.searchParams.set('q', query);
    u.searchParams.set('limit', String(limit || 1));
    return fetch(u.toString(), { headers: { Accept: 'application/json' } }).then(function (r) {
      if (!r.ok) throw new Error('geocode ' + r.status);
      return r.json();
    }).then(function (json) {
      var f = json.features && json.features[0];
      if (!f || !f.geometry) return null;
      var c = f.geometry.coordinates;
      return { lat: Number(c[1]), lng: Number(c[0]), label: (f.properties || {}).label || '' };
    });
  }

  function init(root) {
    var mapEl = root.querySelector('[data-picker-map]');
    var latInput = root.querySelector('[data-picker-lat]');
    var lngInput = root.querySelector('[data-picker-lng]');
    var statusEl = root.querySelector('[data-picker-status]');
    var centerBtn = root.querySelector('[data-picker-center]');
    var clearBtn = root.querySelector('[data-picker-clear]');
    var addressInput = document.querySelector('input[name="address"]');
    if (!mapEl || !window.L) return;

    var L = window.L;
    var map = L.map(mapEl, { scrollWheelZoom: false, zoomControl: true, attributionControl: true });
    L.tileLayer(root.dataset.tiles || '', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.geoportail.gouv.fr/" target="_blank" rel="noopener">IGN Géoplateforme</a>'
    }).addTo(map);

    var accent = (getComputedStyle(root).getPropertyValue('--accent') || '').trim() || '#b5502e';
    var marker = null;

    function pin() {
      return L.divIcon({ className: 'shop-locate-pin', iconSize: [26, 26], iconAnchor: [13, 13], popupAnchor: [0, -14] });
    }

    function setPoint(lat, lng, label) {
      if (marker) map.removeLayer(marker);
      marker = L.marker([lat, lng], { icon: pin(), title: 'Commerce', keyboard: false }).addTo(map);
      if (label) marker.bindPopup(label);
      map.setView([lat, lng], Math.max(map.getZoom(), 18));
      if (latInput) latInput.value = lat.toFixed(6);
      if (lngInput) lngInput.value = lng.toFixed(6);
      setStatus(statusEl, 'Point sélectionné : ' + lat.toFixed(6) + ', ' + lng.toFixed(6) + ' — Enregistrer l\'identité pour le conserver.', 'ok');
    }

    function clearPoint() {
      if (marker) { map.removeLayer(marker); marker = null; }
      if (latInput) latInput.value = '';
      if (lngInput) lngInput.value = '';
      setStatus(statusEl, 'Aucun point précis : la carte et l\'itinéraire utiliseront l\'adresse du site.');
    }

    map.on('click', function (e) { setPoint(e.latlng.lat, e.latlng.lng); });

    var savedLat = parseFloat(latInput && latInput.value);
    var savedLng = parseFloat(lngInput && lngInput.value);
    if (isFinite(savedLat) && isFinite(savedLng)) {
      marker = L.marker([savedLat, savedLng], { icon: pin(), title: 'Commerce', keyboard: false }).addTo(map);
      map.setView([savedLat, savedLng], 18);
    } else {
      map.setView([46.6, 2.4], 5);
    }

    if (centerBtn) {
      centerBtn.addEventListener('click', function () {
        var q = addressInput ? addressInput.value.trim() : '';
        if (q.length < 4) {
          setStatus(statusEl, 'Renseignez d\'abord l\'adresse postale ci-dessus, puis recentrez.', 'error');
          return;
        }
        setStatus(statusEl, 'Recherche de l\'adresse…');
        geocode(root.dataset.geocodeUrl || '', q, 1).then(function (found) {
          if (!found) throw new Error('empty');
          map.setView([found.lat, found.lng], 18);
          setStatus(statusEl, 'Adresse située : cliquez sur la carte à l\'endroit exact du commerce.', 'ok');
        }).catch(function () {
          setStatus(statusEl, 'Adresse introuvable : déplacez la carte manuellement puis cliquez.', 'error');
        });
      });
    }

    if (clearBtn) clearBtn.addEventListener('click', clearPoint);
  }

  function boot(root) {
    if (root.dataset.pickerReady === '1') return;
    root.dataset.pickerReady = '1';
    loadLeaflet().then(function () { init(root); }).catch(function () {
      var statusEl = root.querySelector('[data-picker-status]');
      setStatus(statusEl, 'La carte n\'a pas pu être chargée. Vous pouvez continuer sans point précis.', 'error');
    });
  }

  function start() {
    var roots = document.querySelectorAll('[data-locate-picker]');
    if (!roots.length) return;
    if (!('IntersectionObserver' in window)) { roots.forEach(boot); return; }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        boot(entry.target);
      });
    }, { rootMargin: '250px 0px' });
    roots.forEach(function (root) { observer.observe(root); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
