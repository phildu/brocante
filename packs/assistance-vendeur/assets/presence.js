// Widget visiteur — assistance vendeur : disponibilité, question rapide, présence et intentions.
(function () {
  'use strict';

  var root = document.getElementById('av-widget');
  var initEl = document.getElementById('av-init');
  if (!root || !initEl) return;

  var init;
  try { init = JSON.parse(initEl.textContent || '{}'); } catch (e) { return; }
  var urls = init.urls || {};
  var token = init.token || root.dataset.token || '';
  var page = init.page || root.dataset.page || 'other';
  var ref = init.ref || root.dataset.ref || '';
  var cart = Array.isArray(init.cart) ? init.cart : [];
  if (!token) return;

  var bubble = document.getElementById('av-bubble');
  var bubbleText = document.getElementById('av-bubble-text');
  var panel = document.getElementById('av-panel');
  var closeBtn = document.getElementById('av-close');
  var statusEl = document.getElementById('av-status');
  var welcomeEl = document.getElementById('av-welcome');
  var holdEl = document.getElementById('av-hold');
  var waEl = document.getElementById('av-wa');
  var form = document.getElementById('av-form');
  var msgEl = document.getElementById('av-msg');
  var submitBtn = document.getElementById('av-submit');
  var emailField = document.getElementById('av-email-field');
  var phoneField = document.getElementById('av-phone-field');
  var consentField = document.getElementById('av-consent-field');

  var state = init.state || {};
  var startedAt = Date.now();
  var opened = false;
  var sending = false;
  var sent = false;
  var sentTimer = 0;

  function hhmm(ts) {
    var d = new Date((ts || 0) * 1000);
    return ('0' + d.getHours()).slice(-2) + 'h' + ('0' + d.getMinutes()).slice(-2);
  }

  function setOpen(open) {
    opened = open;
    panel.hidden = !open;
    bubble.setAttribute('aria-expanded', open ? 'true' : 'false');
    bubble.classList.toggle('is-open', open);
    if (open) {
      var first = form.querySelector('input[name="name"]');
      if (first) setTimeout(function () { first.focus({ preventScroll: true }); }, 60);
    }
  }

  function render(s) {
    if (!s || s.ok === false) return;
    state = s;
    root.classList.toggle('is-available', !!s.available);
    if (bubbleText) {
      bubbleText.textContent = s.available
        ? ((s.by ? s.by : 'Un vendeur') + ' est disponible')
        : 'Une question ?';
    }
    if (statusEl) {
      statusEl.classList.toggle('is-on', !!s.available);
      if (s.available) {
        var text = (s.by ? s.by : 'Un vendeur') + ' peut vous répondre';
        if (s.until) text += ' jusqu’à ' + hhmm(s.until);
        text += '.';
        if (s.note) text += ' ' + s.note;
        statusEl.textContent = text;
      } else if (s.shop && s.shop.open) {
        statusEl.textContent = 'La boutique est ouverte, mais aucun vendeur ne s’est encore déclaré disponible. ' + (s.away || '');
      } else {
        statusEl.textContent = (s.shop && s.shop.label ? s.shop.label + '. ' : '') + (s.away || 'Laissez-nous votre question : nous répondons dès que possible.');
      }
    }
    if (welcomeEl) welcomeEl.textContent = s.welcome || '';
    if (waEl) {
      if (s.whatsapp) {
        waEl.hidden = false;
        waEl.href = 'https://wa.me/' + s.whatsapp + '?text=' + encodeURIComponent(
          'Bonjour, je vous contacte depuis le site' + (ref ? ' à propos de cette pièce' : '') + '.'
        );
      } else {
        waEl.hidden = true;
      }
    }
    if (holdEl) {
      var hold = s.hold;
      if (hold && hold.until) {
        var minutes = Math.max(1, Math.round((hold.until * 1000 - Date.now()) / 60000));
        holdEl.hidden = false;
        holdEl.textContent = hold.mine
          ? 'Cette pièce vous est réservée encore ' + minutes + ' min. Prenez votre temps : nous restons joignables.'
          : 'Cette pièce est retenue quelques minutes par la boutique (une question est en cours de traitement).';
      } else {
        holdEl.hidden = true;
      }
    }
    if (s.asked && !sent) {
      setMessage('Vous nous avez déjà écrit il y a peu : nous vous répondons au plus vite.', '');
    }
  }

  function setMessage(text, kind) {
    if (!msgEl) return;
    msgEl.textContent = text || '';
    msgEl.className = 'av-msg' + (kind ? ' is-' + kind : '');
  }

  function ping(event) {
    if (!urls.ping) return Promise.resolve(null);
    var payload = {
      token: token,
      page: page,
      ref: ref,
      dwell: Math.round((Date.now() - startedAt) / 1000),
      cart: cart,
      event: event || ''
    };
    return fetch(urls.ping, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); }).then(function (s) {
      if (s && s.ok) {
        var wasAvailable = !!state.available;
        render(s);
        if (s.available && !wasAvailable && !opened && bubbleText) {
          bubble.classList.add('av-attract');
          setTimeout(function () { bubble.classList.remove('av-attract'); }, 1200);
        }
      }
      return s;
    }).catch(function () { return null; });
  }

  // ── Ouverture / fermeture ─────────────────────────────────────────────────
  if (bubble) bubble.addEventListener('click', function () { setOpen(!opened); });
  if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); bubble.focus({ preventScroll: true }); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && opened) { setOpen(false); if (bubble) bubble.focus({ preventScroll: true }); }
  });

  // ── Question ──────────────────────────────────────────────────────────────
  function syncChannel() {
    var channel = (form.querySelector('input[name="channel"]:checked') || {}).value || 'site';
    var wa = channel === 'whatsapp_call';
    emailField.hidden = wa;
    phoneField.hidden = !wa;
    consentField.hidden = !wa;
    form.querySelector('input[name="email"]').required = !wa;
    form.querySelector('input[name="phone"]').required = wa;
  }
  if (form) {
    form.addEventListener('change', function (e) {
      if (e.target && e.target.name === 'channel') { syncChannel(); setMessage('', ''); }
    });
    syncChannel();
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (sending) return;
      var channel = (form.querySelector('input[name="channel"]:checked') || {}).value || 'site';
      var body = (form.querySelector('textarea[name="body"]').value || '').trim();
      if (body.length < 5) { setMessage('Écrivez votre question (5 caractères minimum).', 'error'); return; }
      sending = true;
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Envoi…'; }
      setMessage('', '');
      var payload = {
        token: token,
        channel: channel,
        name: form.querySelector('input[name="name"]').value.trim(),
        email: form.querySelector('input[name="email"]').value.trim(),
        phone: form.querySelector('input[name="phone"]').value.trim(),
        consent: form.querySelector('input[name="consent"]').checked ? 1 : 0,
        body: body,
        ref: ref,
        website: form.querySelector('input[name="website"]').value
      };
      fetch(urls.question, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function (r) { return r.json(); }).then(function (r) {
        if (!r || !r.ok) {
          setMessage((r && r.error) || 'L’envoi a échoué : réessayez dans un instant.', 'error');
          return;
        }
        sent = true;
        form.querySelector('textarea[name="body"]').value = '';
        setMessage(r.message || 'Merci ! Votre question est bien partie.', 'ok');
        if (r.whatsapp_url && waEl) {
          waEl.hidden = false;
          waEl.href = r.whatsapp_url;
        }
        clearTimeout(sentTimer);
        sentTimer = setTimeout(function () { sent = false; }, 120000);
      }).catch(function () {
        setMessage('L’envoi a échoué : vérifiez votre connexion et réessayez.', 'error');
      }).finally(function () {
        sending = false;
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Envoyer ma question'; }
      });
    });
  }

  // ── Exécution : état initial, présence, intentions ───────────────────────
  render(state);

  if (urls.state) {
    fetch(urls.state + '?token=' + encodeURIComponent(token) + '&ref=' + encodeURIComponent(ref), {
      credentials: 'same-origin', headers: { Accept: 'application/json' }
    }).then(function (r) { return r.json(); }).then(render).catch(function () {});
  }

  ping('');

  var pingTimer = setInterval(function () {
    if (!document.hidden) ping('');
  }, 20000);

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) ping('');
  });
  window.addEventListener('pagehide', function () { clearInterval(pingTimer); });

  if (page === 'cart') {
    setTimeout(function () { ping('cart_view'); }, 1500);
  }
  if (page === 'checkout') {
    var focusSent = false;
    var main = document.querySelector('form[method="post"]') || document.querySelector('main');
    if (main) {
      main.addEventListener('focusin', function () {
        if (focusSent) return;
        focusSent = true;
        ping('checkout_focus');
      }, { once: false });
      main.addEventListener('submit', function () {
        if (!urls.ping || !navigator.sendBeacon) return;
        var payload = JSON.stringify({ token: token, page: page, ref: ref, cart: cart, dwell: Math.round((Date.now() - startedAt) / 1000), event: 'checkout_submit' });
        try { navigator.sendBeacon(urls.ping, new Blob([payload], { type: 'application/json' })); } catch (e) {}
      }, true);
    }
  }
})();