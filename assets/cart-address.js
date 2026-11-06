// Panier : saisie assistée de l'adresse de l'acheteur et localisation.
//  - auto-complétion (Base Adresse Nationale, La Poste) pour la France et l'outre-mer : clavier (↑ ↓ Entrée Échap) et tactile ;
//  - « Me localiser » : position du téléphone → adresse (BAN en France, sinon OpenStreetMap/Nominatim) → pays, code postal et adresse renseignés.
// Seuls l'adresse choisie et son code postal sont gardés (session) : la position elle-même n'est ni envoyée à la boutique ni enregistrée.
(function () {
  var section = document.getElementById('buyer-address');
  if (!section) return;
  var $ = function (id) { return document.getElementById(id); };
  var country = $('buyer-country'), box = $('address-suggestions'), postal = $('postal-code-value'), manual = $('postal-code-manual'),
      label = $('address-label-value'), status = $('geo-status'), locBtn = $('geo-locate'), line1 = $('address-line1'), city = $('address-city'),
      refreshBtn = $('address-refresh'), input = line1;   // l'adresse elle-même sert de champ de recherche BAN
  if (!country || !postal || !label) return;
  var ban = (section.dataset.ban || '').split(',');
  if (!country || !postal) return;
  var DOM = { '971': 'GP', '972': 'MQ', '973': 'GF', '974': 'RE', '976': 'YT' };
  var timer = null, items = [], active = -1, seq = 0;

  function say(t, kind) { if (status) { status.textContent = t || ''; status.dataset.kind = kind || ''; } }
  function hasCountry(code) { return [].some.call(country.options, function (o) { return o.value === code; }); }
  function closeList() { if (!box) return; box.hidden = true; box.textContent = ''; items = []; active = -1; if (input) input.setAttribute('aria-expanded', 'false'); }
  function apply(code, text, postcode, street, town) {   // renseigne pays, rue, code postal et ville, puis recalcule la zone tarifaire
    text = tidy(text); country.value = code; label.value = text || ''; postal.value = (postcode || '').replace(/[^0-9A-Za-z -]/g, '');
    if (line1) line1.value = street || (text || '');
    if (city) city.value = town || '';
    closeList(); say('Adresse retenue : ' + (text || postcode) + '. Calcul des frais de livraison…', 'ok');
    if (refreshBtn) refreshBtn.click();   // le bouton porte le retour vers cart.php (le formulaire de paiement n'est pas soumis)
  }
  function tidy(t) { var p = String(t || '').split(','); return /\d{5}/.test(p[0]) ? p[0].trim() : String(t || '').trim(); }   // « Place Bellecour 69002 Lyon,Lyon 2e Arrondissement » → sans le complément
  function countryOf(postcode) { return DOM[(postcode || '').slice(0, 3)] || 'FR'; }

  // --- Auto-complétion ---
  function setActive(i) {
    items.forEach(function (b, k) { b.setAttribute('aria-selected', k === i ? 'true' : 'false'); b.classList.toggle('is-active', k === i); });
    active = i; if (i >= 0 && items[i]) { items[i].scrollIntoView({ block: 'nearest' }); input.setAttribute('aria-activedescendant', items[i].id); } else input.removeAttribute('aria-activedescendant');
  }
  function render(features) {
    box.textContent = ''; items = [];
    features.forEach(function (f, k) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'address-suggestion-item'; b.id = 'addr-opt-' + k; b.setAttribute('role', 'option'); b.setAttribute('aria-selected', 'false');
      var p = f.properties || {}, main = document.createElement('strong'), sub = document.createElement('span');
      main.textContent = p.name || p.label; sub.textContent = ' ' + [p.postcode, p.city].filter(Boolean).join(' '); sub.style.opacity = '.7';
      b.appendChild(main); b.appendChild(sub);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () { apply(countryOf(p.postcode), p.label, p.postcode || '', p.type === 'municipality' ? '' : (p.name || ''), p.city || ''); });
      box.appendChild(b); items.push(b);
    });
    box.hidden = !items.length; input.setAttribute('aria-expanded', items.length ? 'true' : 'false'); active = -1;
  }
  if (input && box) {
    input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false'); input.setAttribute('aria-controls', 'address-suggestions'); box.setAttribute('role', 'listbox');
    input.addEventListener('input', function () {
      clearTimeout(timer);
      if (ban.indexOf(country.value) === -1) { closeList(); return; }   // pas de BAN hors France et outre-mer
      var q = input.value.trim(); if (q.length < 3) { closeList(); return; }
      var mine = ++seq;
      timer = setTimeout(function () {
        fetch('https://api-adresse.data.gouv.fr/search/?autocomplete=1&limit=6&q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (d) { if (mine === seq) render((d && d.features) || []); })
          .catch(function () { if (mine === seq) closeList(); });
      }, 250);
    });
    input.addEventListener('keydown', function (e) {
      if (!items.length) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive((active + 1) % items.length); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((active - 1 + items.length) % items.length); }
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); items[active].click(); }
      else if (e.key === 'Escape') { closeList(); }
    });
    document.addEventListener('click', function (e) { if (e.target !== input && !box.contains(e.target)) closeList(); });
  }

  // --- Me localiser ---
  function reverseBan(lat, lon) {
    return fetch('https://api-adresse.data.gouv.fr/reverse/?lat=' + lat + '&lon=' + lon).then(function (r) { return r.json(); }).then(function (d) {
      var f = d && d.features && d.features[0]; if (!f || !f.properties || !f.properties.postcode) return null;
      return { code: countryOf(f.properties.postcode), text: f.properties.label, postcode: f.properties.postcode, street: f.properties.type === 'municipality' ? '' : (f.properties.name || ''), town: f.properties.city || '' };
    });
  }
  function reverseOsm(lat, lon) {
    return fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&accept-language=fr&lat=' + lat + '&lon=' + lon).then(function (r) { return r.json(); }).then(function (d) {
      var a = d && d.address; if (!a || !a.country_code) return null;
      var street = [a.house_number, a.road || a.pedestrian || a.footway].filter(Boolean).join(' '), town = a.city || a.town || a.village || a.municipality || '';
      return { code: String(a.country_code).toUpperCase(), text: [street, [a.postcode, town].filter(Boolean).join(' ')].filter(Boolean).join(', '), postcode: a.postcode || '', street: street, town: town, countryName: a.country || '' };
    });
  }
  function locate() {
    if (!navigator.geolocation) { say("La localisation n'est pas disponible sur cet appareil : saisissez votre adresse.", 'error'); return; }
    locBtn.disabled = true; say('Localisation en cours… (acceptez la demande de votre navigateur)', '');
    navigator.geolocation.getCurrentPosition(function (pos) {
      var lat = pos.coords.latitude.toFixed(6), lon = pos.coords.longitude.toFixed(6);
      reverseBan(lat, lon).then(function (r) { return r || reverseOsm(lat, lon); }).then(function (r) {
        locBtn.disabled = false;
        if (!r) { say("Adresse introuvable à cet endroit : saisissez-la à la main.", 'error'); return; }
        if (!hasCountry(r.code)) { say('La boutique ne livre pas vers ' + (r.countryName || r.code) + '.', 'error'); return; }
        apply(r.code, r.text, r.postcode, r.street, r.town);
      }).catch(function () { locBtn.disabled = false; say('Service d\'adresses indisponible : saisissez votre adresse.', 'error'); });
    }, function (err) {
      locBtn.disabled = false;
      say(err && err.code === 1 ? 'Localisation refusée : autorisez-la dans votre navigateur, ou saisissez votre adresse.' : 'Position introuvable : saisissez votre adresse.', 'error');
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
  }
  if (locBtn) locBtn.addEventListener('click', locate);
  // Changer de pays ou de code postal à la main efface l'adresse mémorisée (elle ne correspondrait plus).
  country.addEventListener('change', function () { label.value = ''; }, true);        // phase de capture : avant l'envoi automatique du formulaire
  postal.addEventListener('input', function () { label.value = ''; });
})();
