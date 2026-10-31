// Assistance vendeur — page d'administration : alertes en direct, voix, visiteurs, retenues, réglages.
(function () {
  'use strict';

  var root = document.getElementById('av-page');
  if (!root) return;

  var api = {
    state: root.dataset.apiState,
    save: root.dataset.apiSave,
    action: root.dataset.apiAction,
    test: root.dataset.apiTest
  };
  var csrf = root.dataset.csrf || '';

  var state = null;
  var knownMax = null;
  var settingsFilled = false;
  var voiceOn = false;
  try {
    var stored = localStorage.getItem('av-voice');
    voiceOn = stored === null ? root.dataset.voice === '1' : stored === '1';
  } catch (e) {
    voiceOn = root.dataset.voice === '1';
  }
  var audioCtx = null;

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function hhmm(ts) {
    var d = new Date(ts * 1000);
    return ('0' + d.getHours()).slice(-2) + 'h' + ('0' + d.getMinutes()).slice(-2);
  }

  function timeAgo(ts) {
    var diff = Math.max(0, Math.round(Date.now() / 1000) - ts);
    if (diff < 45) return 'à l’instant';
    if (diff < 3600) return 'il y a ' + Math.round(diff / 60) + ' min';
    if (diff < 86400) return 'il y a ' + Math.round(diff / 3600) + ' h';
    var d = new Date(ts * 1000);
    return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + ' ' + hhmm(ts);
  }

  function request(url, method, body) {
    var opts = {
      method: method || 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    };
    if (method && method !== 'GET') {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = csrf;
      opts.body = JSON.stringify(body || {});
    }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) {
          var err = new Error((data && data.error) || 'Erreur ' + r.status);
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  var flashTimer = 0;
  function flash(message, kind) {
    if (!message) return;
    var p = document.getElementById('av-flash');
    if (!p) return;
    p.className = 'av-msg' + (kind ? ' is-' + kind : ' is-ok');
    p.textContent = message;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(function () {
      p.className = 'av-msg';
      p.textContent = '';
    }, 6000);
  }

  // ── Voix et son ─────────────────────────────────────────────────────────────
  function primeAudio() {
    try {
      if (!audioCtx) {
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (Ctx) audioCtx = new Ctx();
      }
      if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
    } catch (e) {}
  }

  function beep() {
    if (!audioCtx) return;
    try {
      var osc = audioCtx.createOscillator();
      var gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, audioCtx.currentTime);
      gain.gain.setValueAtTime(0.0001, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.16, audioCtx.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.45);
      osc.connect(gain).connect(audioCtx.destination);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.5);
    } catch (e) {}
  }

  function say(text) {
    if (!('speechSynthesis' in window)) return;
    try {
      window.speechSynthesis.cancel();
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'fr-FR';
      u.rate = 1;
      u.pitch = 1;
      window.speechSynthesis.speak(u);
    } catch (e) {}
  }

  function phrase(alert) {
    var label = alert.label || 'Alerte';
    if (alert.kind === 'checkout') return 'Attention : un visiteur est sur le point d’acheter ' + (alert.product_name ? '« ' + alert.product_name + ' ». ' : '') + 'Ouvrez l’assistance.';
    if (alert.kind === 'question') return 'Nouvelle question d’un visiteur' + (alert.product_name ? ' sur « ' + alert.product_name + ' »' : '') + '.';
    if (alert.kind === 'dwell') return 'Un visiteur hésite sur ' + (alert.product_name ? '« ' + alert.product_name + ' »' : 'une pièce') + '.';
    if (alert.kind === 'cart') return 'Un visiteur prépare son panier.';
    if (alert.kind === 'cart_add') return 'Pièce ajoutée au panier' + (alert.product_name ? ' : « ' + alert.product_name + ' »' : '') + '.';
    if (alert.kind === 'checkout_start') return 'Un visiteur remplit ses coordonnées de paiement.';
    if (alert.kind === 'visit') return 'Un visiteur est sur le site. Êtes-vous disponible pour répondre ?';
    return label;
  }

  function announce(alerts) {
    if (!voiceOn || !alerts.length) return;
    var a = alerts[0];
    primeAudio();
    beep();
    say(alerts.length > 1 ? alerts.length + ' nouvelles alertes. ' + phrase(a) : phrase(a));
  }

  function updateSoundButton() {
    var b = document.getElementById('av-sound');
    if (!b) return;
    b.textContent = voiceOn ? 'Voix activée' : 'Activer la voix';
    b.classList.toggle('av-ghost', !voiceOn);
  }

  // ── Rendu de l'état ─────────────────────────────────────────────────────────
  function renderLive() {
    if (!state) return;
    var live = document.getElementById('av-live-state');
    var avail = document.getElementById('av-avail-state');
    var note = document.getElementById('av-channels-note');
    var ready = document.getElementById('av-channels-ready');
    var s = state.settings || {};

    if (avail) {
      avail.classList.toggle('is-on', !!state.availability.on);
      if (state.availability.on) {
        avail.textContent = (state.availability.by ? state.availability.by + ' — ' : '') + 'disponible jusqu’à ' + hhmm(state.availability.until)
          + (state.availability.note ? ' · ' + state.availability.note : '');
      } else {
        avail.textContent = 'Pas de disponibilité déclarée : le site invite le visiteur à laisser sa question.';
      }
      var noteInput = document.getElementById('av-avail-note');
      if (noteInput && document.activeElement !== noteInput) noteInput.value = state.availability.note || '';
    }

    if (live) {
      var active = s.active === '1';
      live.classList.toggle('is-on', active);
      live.textContent = active
        ? 'En écoute · widget ' + (s.widget === '1' ? 'visible' : 'masqué') + ' · ' + state.counts.online + ' visiteur(s) en ligne · '
          + (state.shop.open ? 'boutique ouverte' : 'boutique fermée' + (state.shop.label ? ' (' + state.shop.label + ')' : ''))
        : 'Module en pause : aucune alerte, aucun widget.';
    }

    var channels = [];
    channels.push(state.channels.mail ? 'e-mail prêt' : 'e-mail indisponible');
    channels.push(state.channels.sms ? 'SMS prêt' : 'SMS à configurer');
    channels.push(state.channels.whatsapp ? 'WhatsApp prêt' : 'WhatsApp à configurer');
    if (note) note.textContent = 'Canaux : ' + channels.join(' · ') + '.';
    if (ready) ready.textContent = 'Prêts maintenant : ' + channels.join(' · ') + (state.channels.shop_whatsapp ? ' · WhatsApp boutique renseigné' : ' · WhatsApp boutique non renseigné') + '.';

    var badge = document.getElementById('av-unseen');
    if (badge) {
      var unseen = (state.counts && state.counts.unseen) || 0;
      badge.hidden = unseen <= 0;
      badge.textContent = unseen > 99 ? '99+' : String(unseen);
    }
  }

  function renderAlerts() {
    var box = document.getElementById('av-alerts');
    if (!box) return;
    box.textContent = '';
    var alerts = state.alerts || [];
    if (!alerts.length) {
      box.appendChild(el('p', 'hint', 'Aucune alerte pour l’instant. Le site prévient ici dès qu’un visiteur pose une question ou s’intéresse à une pièce.'));
      return;
    }
    var heldRefs = {};
    (state.holds || []).forEach(function (h) { heldRefs[h.ref] = true; });
    alerts.forEach(function (a) {
      var card = el('article', 'av-alert level-' + (a.level || 'low') + (a.seen_at ? '' : ' is-unseen') + (a.handled_at ? ' is-handled' : ''));
      card.dataset.id = a.id;

      var head = el('div', 'av-alert-head');
      head.appendChild(el('span', 'av-alert-kind', a.label || a.kind));
      head.appendChild(el('span', 'av-alert-time', timeAgo(a.created_at) + (a.created_at ? ' · ' + hhmm(a.created_at) : '')));
      card.appendChild(head);

      if (a.product_name) {
        var prod = el('p', 'av-alert-product');
        if (a.url) {
          var link = el('a', '', '« ' + a.product_name + ' »');
          link.href = a.url;
          link.target = '_blank';
          link.rel = 'noopener';
          prod.appendChild(link);
        } else {
          prod.textContent = '« ' + a.product_name + ' »';
        }
        if (a.ref) prod.appendChild(document.createTextNode(' · réf. ' + a.ref));
        card.appendChild(prod);
      }
      if (a.detail) card.appendChild(el('p', 'av-alert-detail', a.detail));

      if (a.email || a.wa_visitor) {
        var contact = el('div', 'av-alert-contact');
        if (a.email) {
          var mail = el('a', '', 'Répondre par e-mail');
          mail.href = 'mailto:' + a.email;
          contact.appendChild(mail);
        }
        if (a.wa_visitor) {
          var wa = el('a', '', 'WhatsApp');
          wa.href = 'https://wa.me/' + a.wa_visitor + '?text=' + encodeURIComponent('Bonjour, je vous réponds au sujet de votre question sur le site.');
          wa.target = '_blank';
          wa.rel = 'noopener';
          contact.appendChild(wa);
        }
        card.appendChild(contact);
      }

      var actions = el('div', 'av-alert-actions');
      if (!a.handled_at) {
        var ack = el('button', 'btn-small av-ghost', 'Traiter');
        ack.type = 'button';
        ack.addEventListener('click', function () { act({ action: 'ack', id: a.id }, 'Alerte traitée.'); });
        actions.appendChild(ack);
      } else if (a.action) {
        actions.appendChild(el('span', 'av-alert-action', a.action + (a.handled_at ? ' · ' + hhmm(a.handled_at) : '')));
      }

      if (a.ref) {
        var minutes = el('select', '');
        [5, 10, 20, 30, 60].forEach(function (m) {
          var o = el('option', '', m + ' min');
          o.value = String(m);
          if (String(m) === String((state.settings || {}).hold_minutes || '20')) o.selected = true;
          minutes.appendChild(o);
        });
        actions.appendChild(minutes);

        var holdVisitor = el('button', 'btn-small', 'Retenir pour ce visiteur');
        holdVisitor.type = 'button';
        holdVisitor.disabled = !a.visitor;
        holdVisitor.title = a.visitor ? 'La pièce reste achetable par ce visiteur, pas par les autres' : 'Visiteur inconnu';
        holdVisitor.addEventListener('click', function () {
          act({ action: 'hold', id: a.id, minutes: parseInt(minutes.value, 10), scope: 'visitor' }, 'Pièce retenue pour ce visiteur.');
        });
        actions.appendChild(holdVisitor);

        var holdAll = el('button', 'btn-small av-ghost', 'Suspendre la vente en ligne');
        holdAll.type = 'button';
        holdAll.addEventListener('click', function () {
          if (!window.confirm('Suspendre la vente en ligne de cette pièce pour tous les visiteurs ?')) return;
          act({ action: 'hold', id: a.id, minutes: parseInt(minutes.value, 10), scope: 'all' }, 'Vente en ligne suspendue pour cette pièce.');
        });
        actions.appendChild(holdAll);

        if (heldRefs[a.ref]) {
          var release = el('button', 'btn-small av-ghost', 'Lever la retenue');
          release.type = 'button';
          release.addEventListener('click', function () { act({ action: 'release', id: a.id, ref: a.ref }, 'Pièce de nouveau en vente.'); });
          actions.appendChild(release);
        }
      }
      card.appendChild(actions);
      box.appendChild(card);
    });
  }

  function pageLabel(page) {
    return ({ home: 'Accueil', shop: 'Boutique', product: 'Fiche d’une pièce', cart: 'Panier', checkout: 'Paiement', other: 'Autre page' })[page] || page || 'Page';
  }

  function renderVisitors() {
    var box = document.getElementById('av-visitors');
    if (!box) return;
    box.textContent = '';
    var visitors = state.visitors || [];
    if (!visitors.length) {
      box.appendChild(el('p', 'hint', 'Personne sur le site en ce moment.'));
      return;
    }
    visitors.forEach(function (v) {
      var row = el('div', 'av-visitor');
      row.appendChild(el('span', 'av-live', '●')); // pastille
      row.appendChild(el('span', '', pageLabel(v.page)));
      if (v.ref) {
        var p = el('a', '', v.ref);
        p.href = '/produit.php?ref=' + encodeURIComponent(v.ref);
        p.target = '_blank';
        p.rel = 'noopener';
        row.appendChild(p);
      }
      if (v.dwell > 10) row.appendChild(el('span', 'av-muted', v.dwell + ' s sur la page'));
      if (v.cart && v.cart.length) row.appendChild(el('span', 'av-muted', v.cart.length + ' pièce(s) au panier'));
      row.appendChild(el('span', 'av-token', 'visiteur ' + v.token));
      row.appendChild(el('span', 'av-muted', 'vu ' + timeAgo(v.last_at)));
      box.appendChild(row);
    });
  }

  function renderHolds() {
    var box = document.getElementById('av-holds');
    if (!box) return;
    box.textContent = '';
    var holds = state.holds || [];
    if (!holds.length) {
      box.appendChild(el('p', 'hint', 'Aucune pièce retenue.'));
      return;
    }
    holds.forEach(function (h) {
      var row = el('div', 'av-visitor');
      var label = h.product_name || h.ref;
      var a = el('a', '', label);
      a.href = '/produit.php?ref=' + encodeURIComponent(h.ref);
      a.target = '_blank';
      a.rel = 'noopener';
      row.appendChild(a);
      row.appendChild(el('span', 'av-muted', 'jusqu’à ' + hhmm(h.until)));
      row.appendChild(el('span', 'av-muted', h.scope === 'all' ? 'vente suspendue pour tous' : 'réservée à un visiteur'));
      var b = el('button', 'btn-small av-ghost', 'Lever');
      b.type = 'button';
      b.addEventListener('click', function () { act({ action: 'release', id: h.id, ref: h.ref }, 'Pièce de nouveau en vente.'); });
      row.appendChild(b);
      box.appendChild(row);
    });
  }

  function renderSellers() {
    var box = document.getElementById('av-sellers');
    if (!box) return;
    box.textContent = '';
    var sellers = state.sellers || [];
    if (!sellers.length) {
      box.appendChild(el('p', 'hint', 'Aucun vendeur prévenu. Ajoutez un compte ci-dessous ou reprenez les comptes de l’équipe.'));
      return;
    }
    sellers.forEach(function (s) {
      var form = el('form', 'av-seller');
      form.dataset.email = s.email;

      var head = el('div', 'av-alert-head');
      var who = el('span', 'av-alert-kind', (s.name || s.email) + (s.active ? '' : ' (inactif)'));
      head.appendChild(who);
      var del = el('button', 'btn-small av-ghost', 'Retirer');
      del.type = 'button';
      del.addEventListener('click', function () {
        if (!window.confirm('Retirer ' + s.email + ' des vendeurs prévenus ?')) return;
        act({ action: 'seller-delete', email: s.email }, 'Vendeur retiré.').then(function () { settingsFilled = false; });
      });
      head.appendChild(del);
      form.appendChild(head);

      var grid = el('div', 'av-form-grid');
      grid.appendChild(field('Nom', 'text', 'name', s.name, 80));
      grid.appendChild(field('Portable', 'tel', 'phone', s.phone, 24));
      form.appendChild(grid);
      form.appendChild(el('p', 'hint', s.email));

      var checks = el('div', 'av-row');
      checks.appendChild(check('consent_mail', 'E-mail', s.consent_mail === 1));
      checks.appendChild(check('consent_sms', 'SMS', s.consent_sms === 1));
      checks.appendChild(check('consent_wa', 'WhatsApp', s.consent_wa === 1));
      checks.appendChild(check('active', 'Actif', s.active === 1));
      form.appendChild(checks);

      var row = el('div', 'av-row');
      var save = el('button', 'btn-small', 'Enregistrer');
      save.type = 'submit';
      row.appendChild(save);
      var msg = el('span', 'av-msg');
      msg.setAttribute('role', 'status');
      row.appendChild(msg);
      form.appendChild(row);

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var data = {
          action: 'seller',
          email: s.email,
          name: form.querySelector('[name="name"]').value,
          phone: form.querySelector('[name="phone"]').value,
          consent_mail: form.querySelector('[name="consent_mail"]').checked ? 1 : 0,
          consent_sms: form.querySelector('[name="consent_sms"]').checked ? 1 : 0,
          consent_wa: form.querySelector('[name="consent_wa"]').checked ? 1 : 0,
          active: form.querySelector('[name="active"]').checked ? 1 : 0
        };
        save.disabled = true;
        request(api.save, 'POST', data).then(function (d) {
          msg.className = 'av-msg is-ok';
          msg.textContent = d.message || 'Enregistré.';
          applyState(d, false);
        }).catch(function (err) {
          msg.className = 'av-msg is-error';
          msg.textContent = err.message;
        }).finally(function () { save.disabled = false; });
      });
      box.appendChild(form);
    });
  }

  function field(label, type, name, value, max) {
    var l = el('label', 'av-field', label);
    var input = document.createElement('input');
    input.type = type;
    input.name = name;
    input.value = value || '';
    if (max) input.maxLength = max;
    l.appendChild(input);
    return l;
  }

  function check(name, label, checked) {
    var l = el('label', 'av-check');
    var input = document.createElement('input');
    input.type = 'checkbox';
    input.name = name;
    input.checked = !!checked;
    l.appendChild(input);
    l.appendChild(document.createTextNode(' ' + label));
    return l;
  }

  function fillSettings() {
    if (settingsFilled || !state) return;
    var form = document.getElementById('av-settings');
    if (!form) return;
    var s = state.settings || {};
    ['active', 'widget', 'alert_cart', 'alert_cart_add', 'alert_checkout', 'notify_visit', 'notify_intent', 'voice'].forEach(function (k) {
      var input = form.elements.namedItem(k);
      if (input) input.checked = s[k] === '1';
    });
    ['dwell_seconds', 'cooldown_minutes', 'hold_minutes', 'retention_days', 'welcome', 'away'].forEach(function (k) {
      var input = form.elements.namedItem(k);
      if (input) input.value = s[k] || '';
    });
    var wa = form.elements.namedItem('whatsapp');
    if (wa) wa.value = s.whatsapp ? '+' + s.whatsapp : '';
    var channels = (s.channels || '').split(',');
    form.querySelectorAll('[name="channels[]"]').forEach(function (input) {
      input.checked = channels.indexOf(input.value) !== -1;
    });
    settingsFilled = true;
  }

  function applyState(data, announceNew) {
    if (!data || data.ok === false) return;
    var previousMax = knownMax;
    state = data;
    var alerts = data.alerts || [];
    var maxId = alerts.length ? alerts[0].id : 0;
    if (knownMax === null) knownMax = maxId;
    if (announceNew && previousMax !== null && maxId > previousMax) {
      var fresh = alerts.filter(function (a) { return a.id > previousMax; });
      announce(fresh);
    }
    knownMax = Math.max(knownMax || 0, maxId);

    renderLive();
    renderAlerts();
    renderVisitors();
    renderHolds();
    renderSellers();
    fillSettings();
    updateSoundButton();
  }

  function load(announceNew) {
    return request(api.state, 'GET').then(function (d) {
      applyState(d, announceNew !== false);
      return d;
    }).catch(function () {});
  }

  function act(body, okMessage) {
    return request(api.action, 'POST', body).then(function (d) {
      applyState(d, false);
      flash(okMessage || d.message || 'Fait.');
      return d;
    }).catch(function (err) {
      flash(err.message, 'error');
      throw err;
    });
  }

  // ── Actions de la page ──────────────────────────────────────────────────────
  document.querySelectorAll('[data-avail]').forEach(function (b) {
    b.addEventListener('click', function () {
      primeAudio();
      var minutes = parseInt(b.dataset.avail, 10) || 0;
      var note = (document.getElementById('av-avail-note') || {}).value || '';
      b.disabled = true;
      request(api.save, 'POST', { action: 'availability', minutes: minutes, note: note }).then(function (d) {
        applyState(d, false);
        flash(d.message || 'Disponibilité enregistrée.');
      }).catch(function (err) { flash(err.message, 'error'); }).finally(function () { b.disabled = false; });
    });
  });

  var soundBtn = document.getElementById('av-sound');
  if (soundBtn) {
    updateSoundButton();
    soundBtn.addEventListener('click', function () {
      voiceOn = !voiceOn;
      try { localStorage.setItem('av-voice', voiceOn ? '1' : '0'); } catch (e) {}
      updateSoundButton();
      if (voiceOn) {
        primeAudio();
        beep();
        say('Voix des alertes activée. Vous serez prévenu à chaque nouvelle alerte.');
        flash('Voix activée pour cet onglet.');
      } else {
        try { window.speechSynthesis.cancel(); } catch (e) {}
        flash('Voix coupée pour cet onglet.');
      }
    });
  }

  var testBtn = document.getElementById('av-test');
  if (testBtn) {
    testBtn.addEventListener('click', function () {
      primeAudio();
      testBtn.disabled = true;
      request(api.test, 'POST', {}).then(function (d) {
        applyState(d, true);
        flash(d.message || 'Alerte de démonstration envoyée.');
      }).catch(function (err) { flash(err.message, 'error'); }).finally(function () { testBtn.disabled = false; });
    });
  }

  var seenBtn = document.getElementById('av-seen');
  if (seenBtn) {
    seenBtn.addEventListener('click', function () {
      request(api.action, 'POST', { action: 'seen', up_to: knownMax || 0 }).then(function () {
        flash('Alertes marquées comme vues.');
        load(false);
      }).catch(function (err) { flash(err.message, 'error'); });
    });
  }

  var syncBtn = document.getElementById('av-sync');
  if (syncBtn) {
    syncBtn.addEventListener('click', function () {
      syncBtn.disabled = true;
      request(api.save, 'POST', { action: 'sync' }).then(function (d) {
        applyState(d, false);
        settingsFilled = false;
        fillSettings();
        flash(d.message || 'Comptes de l’équipe repris.');
      }).catch(function (err) { flash(err.message, 'error'); }).finally(function () { syncBtn.disabled = false; });
    });
  }

  var sellerForm = document.getElementById('av-seller-form');
  if (sellerForm) {
    sellerForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = sellerForm.querySelector('[data-role="msg"]');
      var button = sellerForm.querySelector('button[type="submit"]');
      var data = {
        action: 'seller',
        name: sellerForm.elements.namedItem('name').value,
        email: sellerForm.elements.namedItem('email').value,
        phone: sellerForm.elements.namedItem('phone').value,
        consent_mail: sellerForm.elements.namedItem('consent_mail').checked ? 1 : 0,
        consent_sms: sellerForm.elements.namedItem('consent_sms').checked ? 1 : 0,
        consent_wa: sellerForm.elements.namedItem('consent_wa').checked ? 1 : 0,
        active: sellerForm.elements.namedItem('active').checked ? 1 : 0
      };
      button.disabled = true;
      request(api.save, 'POST', data).then(function (d) {
        applyState(d, false);
        sellerForm.reset();
        sellerForm.elements.namedItem('consent_mail').checked = true;
        sellerForm.elements.namedItem('active').checked = true;
        msg.className = 'av-msg is-ok';
        msg.textContent = d.message || 'Vendeur ajouté.';
      }).catch(function (err) {
        msg.className = 'av-msg is-error';
        msg.textContent = err.message;
      }).finally(function () { button.disabled = false; });
    });
  }

  var settingsForm = document.getElementById('av-settings');
  if (settingsForm) {
    settingsForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = settingsForm.querySelector('[data-role="msg"]');
      var button = settingsForm.querySelector('button[type="submit"]');
      var data = { action: 'settings' };
      ['active', 'widget', 'alert_cart', 'alert_cart_add', 'alert_checkout', 'notify_visit', 'notify_intent', 'voice'].forEach(function (k) {
        data[k] = settingsForm.elements.namedItem(k).checked ? 1 : 0;
      });
      ['dwell_seconds', 'cooldown_minutes', 'hold_minutes', 'retention_days'].forEach(function (k) {
        data[k] = parseInt(settingsForm.elements.namedItem(k).value, 10) || 0;
      });
      data.welcome = settingsForm.elements.namedItem('welcome').value;
      data.away = settingsForm.elements.namedItem('away').value;
      data.whatsapp = settingsForm.elements.namedItem('whatsapp').value;
      data.channels = [];
      settingsForm.querySelectorAll('[name="channels[]"]:checked').forEach(function (input) { data.channels.push(input.value); });
      button.disabled = true;
      request(api.save, 'POST', data).then(function (d) {
        applyState(d, false);
        settingsFilled = false;
        fillSettings();
        msg.className = 'av-msg is-ok';
        msg.textContent = d.message || 'Réglages enregistrés.';
      }).catch(function (err) {
        msg.className = 'av-msg is-error';
        msg.textContent = err.message;
      }).finally(function () { button.disabled = false; });
    });
  }

  // Premier chargement puis veille régulière : toute nouvelle alerte est lue et signalée.
  load(false).then(function () {
    setTimeout(function () { load(true); }, 1200);
  });
  setInterval(function () {
    if (document.hidden) return;
    load(true);
  }, 12000);

  // La voix peut rester bloquée tant qu'aucune interaction n'a eu lieu : le premier clic libère le son.
  document.addEventListener('pointerdown', function once() {
    primeAudio();
    document.removeEventListener('pointerdown', once);
  });
})();