/* « Nous trouver » : carte IGN Géoplateforme, géocodage et itinéraire (bdtopo-valhalla), export vCard.
   Aucune clé d'API, aucun traceur publicitaire ; Leaflet n'est chargé qu'à l'approche de la section. */
(function () {
  'use strict';

  var LEAFLET_CSS = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css';
  var LEAFLET_JS = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js';
  var leafletPromise = null;

  function data(el, name) {
    return el.dataset[name] || '';
  }

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

  function formatDistance(meters) {
    var m = Math.max(0, Number(meters) || 0);
    if (m < 1000) return Math.round(m / 10) * 10 + ' m';
    return (m / 1000).toFixed(m < 10000 ? 1 : 0).replace('.', ',') + ' km';
  }

  function formatDuration(seconds) {
    var s = Math.max(0, Math.round(Number(seconds) || 0));
    var min = Math.max(1, Math.round(s / 60));
    if (min < 60) return min + ' min';
    return Math.floor(min / 60) + ' h ' + ('0' + (min % 60)).slice(-2);
  }

  function zoomFor(type) {
    if (type === 'exact') return 18;
    if (type === 'housenumber' || type === 'street') return 17;
    if (type === 'locality' || type === 'municipality') return 14;
    return 16;
  }

  function readCache(key) {
    try {
      var raw = localStorage.getItem(key);
      if (!raw) return null;
      var hit = JSON.parse(raw);
      if (!hit || typeof hit.lat !== 'number' || typeof hit.lng !== 'number') return null;
      if (hit.at && Date.now() - hit.at > 1000 * 60 * 60 * 24 * 90) return null;
      return hit;
    } catch (e) { return null; }
  }

  function writeCache(key, lat, lng, label, type) {
    try { localStorage.setItem(key, JSON.stringify({ lat: lat, lng: lng, label: label, type: type, at: Date.now() })); } catch (e) {}
  }

  function geocode(url, query, limit) {
    var u = new URL(url);
    u.searchParams.set('q', query);
    u.searchParams.set('limit', String(limit || 1));
    return fetch(u.toString(), { headers: { Accept: 'application/json' } }).then(function (r) {
      if (!r.ok) throw new Error('geocode ' + r.status);
      return r.json();
    }).then(function (json) {
      return (json.features || []).map(function (f) {
        var c = f.geometry && f.geometry.coordinates;
        var p = f.properties || {};
        return {
          lng: c ? Number(c[0]) : NaN,
          lat: c ? Number(c[1]) : NaN,
          label: p.label || query,
          city: p.city || '',
          postcode: p.postcode || '',
          type: p.type || ''
        };
      }).filter(function (f) { return isFinite(f.lat) && isFinite(f.lng); });
    });
  }

  function reverseGeocode(url, lat, lng) {
    var u = new URL(url);
    u.pathname = u.pathname.replace(/\/search\/?$/, '/reverse');
    u.search = '';
    u.searchParams.set('lon', String(lng));
    u.searchParams.set('lat', String(lat));
    u.searchParams.set('limit', '1');
    return fetch(u.toString(), { headers: { Accept: 'application/json' } }).then(function (r) {
      return r.ok ? r.json() : null;
    }).then(function (json) {
      var f = json && json.features && json.features[0];
      return f && f.properties ? f.properties.label : '';
    }).catch(function () { return ''; });
  }

  function init(root) {
    var address = data(root, 'address');
    var name = data(root, 'name');
    var mapEl = root.querySelector('[data-locate-map]');
    var mapWrap = mapEl ? mapEl.closest('.shop-locate-mapwrap') : null;
    var layersEl = root.querySelector('[data-locate-layers]');
    var noteEl = root.querySelector('.shop-locate-note');
    var geocodeUrl = data(root, 'geocodeUrl');
    var routeUrl = data(root, 'routeUrl');
    var vcardUrl = data(root, 'vcard');
    var directionsUrl = data(root, 'directionsGoogle');
    var L = window.L;
    if (!mapEl || !L) return;

    var map = L.map(mapEl, { scrollWheelZoom: false, zoomControl: true, attributionControl: true });
    map.setView([46.6, 2.4], 5);

    var plan = L.tileLayer(data(root, 'tilesPlan'), {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.geoportail.gouv.fr/" target="_blank" rel="noopener">IGN Géoplateforme</a>'
    });
    var sat = L.tileLayer(data(root, 'tilesSat'), {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.geoportail.gouv.fr/" target="_blank" rel="noopener">IGN Géoplateforme</a>'
    });
    plan.addTo(map);

    var accent = (getComputedStyle(root).getPropertyValue('--accent') || '').trim() || '#b5502e';
    var shopMarker = null;
    var startMarker = null;
    var routeLine = null;
    var destination = null;
    var origin = null;
    var profile = 'car';
    var lastRoute = null;
    var searchSeq = 0;

    function pin(color) {
      return L.divIcon({
        className: 'shop-locate-pin' + (color === 'start' ? ' shop-locate-pin-start' : ''),
        iconSize: [26, 26],
        iconAnchor: [13, 13],
        popupAnchor: [0, -14]
      });
    }

    function showDestination(dest) {
      destination = dest;
      if (shopMarker) map.removeLayer(shopMarker);
      shopMarker = L.marker([dest.lat, dest.lng], { icon: pin('shop'), title: name, keyboard: false }).addTo(map);
      var popup = '<strong>' + escapeHtml(name) + '</strong>';
      if (dest.label && dest.label !== address) popup += '<br>' + escapeHtml(dest.label);
      shopMarker.bindPopup(popup);
      map.setView([dest.lat, dest.lng], zoomFor(dest.type));
      if (layersEl) layersEl.hidden = false;
      updateVcard(dest);
    }

    function updateVcard(dest) {
      var links = root.querySelectorAll('[data-locate-vcard]');
      if (!links.length) return;
      var base = links[0].getAttribute('href').split('?')[0] || vcardUrl;
      var href = base + '?lat=' + encodeURIComponent(dest.lat.toFixed(6)) + '&lng=' + encodeURIComponent(dest.lng.toFixed(6));
      Array.prototype.forEach.call(links, function (a) { a.setAttribute('href', href); });
    }

    function escapeHtml(s) {
      return String(s || '').replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function locateDestination() {
      if (!address) return Promise.resolve(null);
      // Point exact choisi dans l'administration (Pages légales → Identité) : pas de géocodage.
      var savedLat = parseFloat(data(root, 'lat'));
      var savedLng = parseFloat(data(root, 'lng'));
      if (isFinite(savedLat) && isFinite(savedLng)) {
        var saved = { lat: savedLat, lng: savedLng, label: '', type: 'exact' };
        showDestination(saved);
        return Promise.resolve(saved);
      }
      var key = 'shop-locate:' + address.toLowerCase();
      var cached = readCache(key);
      if (cached) {
        showDestination(cached);
        return Promise.resolve(cached);
      }
      return geocode(geocodeUrl, address, 1).then(function (found) {
        if (!found.length) throw new Error('empty');
        var d = found[0];
        writeCache(key, d.lat, d.lng, d.label, d.type);
        showDestination(d);
        return d;
      }).catch(function () {
        if (noteEl) noteEl.textContent = 'La carte n\'a pas pu situer l\'adresse automatiquement. Le lien Google Maps ci-contre reste disponible.';
        if (mapWrap) mapWrap.classList.remove('is-loading');
        throw new Error('destination');
      });
    }

    /* Fond de carte */

    if (layersEl) {
      layersEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-locate-layer]');
        if (!b) return;
        var which = b.getAttribute('data-locate-layer');
        Array.prototype.forEach.call(layersEl.querySelectorAll('button'), function (x) {
          x.setAttribute('aria-pressed', String(x === b));
        });
        if (which === 'sat') { map.removeLayer(plan); sat.addTo(map); }
        else { map.removeLayer(sat); plan.addTo(map); }
      });
    }

    /* Formulaire d'itinéraire */

    var toggle = root.querySelector('[data-locate-toggle]');
    var form = root.querySelector('[data-locate-routeform]');
    var input = root.querySelector('[data-locate-origin]');
    var suggestions = root.querySelector('[data-locate-suggestions]');
    var statusEl = root.querySelector('[data-locate-status]');
    var resultEl = root.querySelector('[data-locate-result]');
    var distanceEl = root.querySelector('[data-locate-distance]');
    var durationEl = root.querySelector('[data-locate-duration]');
    var gmapsEl = root.querySelector('[data-locate-gmaps]');
    var submitEl = form ? form.querySelector('[type="submit"]') : null;
    var debounce = null;

    if (toggle && form) {
      toggle.addEventListener('click', function () {
        var open = form.hidden;
        form.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (open) {
          setTimeout(function () { input && input.focus(); }, 30);
          locateDestination().catch(function () {});
        }
      });
    }

    function closeSuggestions() {
      if (!suggestions) return;
      suggestions.hidden = true;
      suggestions.innerHTML = '';
      if (input) input.setAttribute('aria-expanded', 'false');
    }

    function pickSuggestion(item, button) {
      origin = { lat: item.lat, lng: item.lng, label: item.label };
      if (input) input.value = item.label;
      closeSuggestions();
      setStatus(statusEl, 'Départ : ' + item.label, 'ok');
      if (button) button.blur();
    }

    function renderSuggestions(list) {
      if (!suggestions || !input) return;
      suggestions.innerHTML = '';
      if (!list.length) {
        closeSuggestions();
        return;
      }
      list.forEach(function (item) {
        var li = document.createElement('li');
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('role', 'option');
        b.textContent = item.label;
        var extra = [item.postcode, item.city].filter(Boolean).join(' ');
        if (extra && item.label.indexOf(extra) === -1) {
          var small = document.createElement('small');
          small.textContent = extra;
          b.appendChild(small);
        }
        b.addEventListener('click', function () { pickSuggestion(item, b); });
        b.addEventListener('keydown', function (e) {
          var prev = b.parentNode.previousElementSibling;
          var next = b.parentNode.nextElementSibling;
          if (e.key === 'ArrowDown' && next) { e.preventDefault(); next.querySelector('button').focus(); }
          else if (e.key === 'ArrowUp' && prev) { e.preventDefault(); prev.querySelector('button').focus(); }
          else if (e.key === 'Escape') { e.preventDefault(); closeSuggestions(); input.focus(); }
        });
        li.appendChild(b);
        suggestions.appendChild(li);
      });
      suggestions.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    if (input) {
      input.addEventListener('input', function () {
        origin = null;
        clearTimeout(debounce);
        var q = input.value.trim();
        if (q.length < 3) { closeSuggestions(); return; }
        var seq = ++searchSeq;
        debounce = setTimeout(function () {
          geocode(geocodeUrl, q, 6).then(function (list) {
            if (seq === searchSeq) renderSuggestions(list.slice(0, 6));
          }).catch(function () { if (seq === searchSeq) closeSuggestions(); });
        }, 320);
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSuggestions();
        if (e.key === 'ArrowDown' && suggestions && !suggestions.hidden) {
          var first = suggestions.querySelector('button');
          if (first) { e.preventDefault(); first.focus(); }
        }
      });
      input.addEventListener('change', function () {
        if (origin && input.value.trim() !== origin.label) origin = null;
      });
    }

    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) closeSuggestions();
      else if (suggestions && !suggestions.contains(e.target) && e.target !== input) closeSuggestions();
    });

    Array.prototype.forEach.call(root.querySelectorAll('[data-locate-profile]'), function (b) {
      b.addEventListener('click', function () {
        profile = b.getAttribute('data-locate-profile') === 'pedestrian' ? 'pedestrian' : 'car';
        Array.prototype.forEach.call(root.querySelectorAll('[data-locate-profile]'), function (x) {
          x.setAttribute('aria-pressed', String(x === b));
        });
        if (lastRoute) route(origin, destination).catch(function () {});
      });
    });

    var geoBtn = root.querySelector('[data-locate-geoloc]');
    if (geoBtn) {
      geoBtn.addEventListener('click', function () {
        if (!navigator.geolocation) {
          setStatus(statusEl, 'Votre navigateur ne donne pas accès à la position.', 'error');
          return;
        }
        setStatus(statusEl, 'Recherche de votre position…');
        navigator.geolocation.getCurrentPosition(function (pos) {
          var lat = pos.coords.latitude;
          var lng = pos.coords.longitude;
          origin = { lat: lat, lng: lng, label: 'Ma position' };
          if (input) input.value = 'Ma position';
          closeSuggestions();
          setStatus(statusEl, 'Position trouvée. Vous pouvez calculer l\'itinéraire.', 'ok');
          reverseGeocode(geocodeUrl, lat, lng).then(function (label) {
            if (label && origin && origin.label === 'Ma position') {
              origin.label = label + ' (ma position)';
              if (input) input.value = label;
            }
          });
        }, function () {
          setStatus(statusEl, 'Position refusée ou indisponible. Saisissez une adresse de départ.', 'error');
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
      });
    }

    function resolveOrigin() {
      if (origin) return Promise.resolve(origin);
      var q = input ? input.value.trim() : '';
      if (q.length < 3) return Promise.reject(new Error('origin'));
      return geocode(geocodeUrl, q, 1).then(function (found) {
        if (!found.length) throw new Error('origin');
        origin = { lat: found[0].lat, lng: found[0].lng, label: found[0].label };
        if (input) input.value = origin.label;
        return origin;
      });
    }

    function route(from, to) {
      if (!from || !to) return Promise.reject(new Error('route'));
      var u = new URL(routeUrl);
      u.searchParams.set('resource', 'bdtopo-valhalla');
      u.searchParams.set('profile', profile);
      u.searchParams.set('optimization', profile === 'pedestrian' ? 'shortest' : 'fastest');
      u.searchParams.set('start', from.lng + ',' + from.lat);
      u.searchParams.set('end', to.lng + ',' + to.lat);
      u.searchParams.set('getSteps', 'false');
      u.searchParams.set('getBbox', 'true');
      return fetch(u.toString(), { headers: { Accept: 'application/json' } }).then(function (r) {
        if (!r.ok) throw new Error('route ' + r.status);
        return r.json();
      }).then(function (json) {
        if (!json || !json.geometry) throw new Error('route');
        lastRoute = { origin: from, destination: to };
        if (routeLine) map.removeLayer(routeLine);
        if (startMarker) map.removeLayer(startMarker);
        routeLine = L.geoJSON(json.geometry, {
          style: { color: accent, weight: 5, opacity: .9, lineCap: 'round', lineJoin: 'round' }
        }).addTo(map);
        startMarker = L.marker([from.lat, from.lng], { icon: pin('start'), title: from.label, keyboard: false }).addTo(map);
        try { map.fitBounds(routeLine.getBounds(), { padding: [30, 30], maxZoom: 16 }); } catch (e) {}
        if (distanceEl) distanceEl.textContent = formatDistance(json.distance);
        if (durationEl) durationEl.textContent = (profile === 'pedestrian' ? 'à pied · ' : 'en voiture · ') + formatDuration(json.duration);
        if (gmapsEl && directionsUrl) {
          gmapsEl.href = directionsUrl + '&origin=' + encodeURIComponent(from.lat.toFixed(6) + ',' + from.lng.toFixed(6));
        }
        if (resultEl) resultEl.hidden = false;
        setStatus(statusEl, 'Itinéraire calculé.', 'ok');
        return json;
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        closeSuggestions();
        var destReady = destination ? Promise.resolve(destination) : locateDestination();
        setStatus(statusEl, 'Calcul de l\'itinéraire…');
        if (resultEl) resultEl.hidden = true;
        if (submitEl) submitEl.disabled = true;
        destReady.then(function (to) {
          return resolveOrigin().then(function (from) { return route(from, to); });
        }).catch(function (err) {
          if (err && err.message === 'origin') {
            setStatus(statusEl, 'Adresse de départ introuvable. Précisez la ville ou le code postal.', 'error');
          } else {
            setStatus(statusEl, 'Itinéraire indisponible pour le moment. Réessayez ou ouvrez Google Maps ci-dessous.', 'error');
          }
        }).then(function () {
          if (submitEl) submitEl.disabled = false;
        });
      });
    }

    /* Apple Plans : le lien n'apparaît que sur les appareils Apple. */
    var apple = root.querySelector('[data-locate-apple]');
    if (apple && /iPhone|iPad|iPod|Macintosh|Mac OS X/.test(navigator.userAgent)) apple.hidden = false;

    locateDestination().catch(function () {});
  }

  function boot(root) {
    if (root.dataset.locateReady === '1') return;
    root.dataset.locateReady = '1';
    loadLeaflet().then(function () { init(root); }).catch(function () {
      var mapWrap = root.querySelector('.shop-locate-mapwrap');
      if (mapWrap) mapWrap.classList.remove('is-loading');
      var note = root.querySelector('.shop-locate-note');
      if (note) note.textContent = 'La carte n\'a pas pu être chargée (connexion au fond IGN impossible). L\'adresse et le lien Google Maps restent disponibles.';
    });
  }

  function start() {
    var roots = document.querySelectorAll('[data-shop-locate]');
    if (!roots.length) return;
    if (!('IntersectionObserver' in window)) {
      roots.forEach(boot);
      return;
    }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        boot(entry.target);
      });
    }, { rootMargin: '350px 0px' });
    roots.forEach(function (root) { observer.observe(root); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
