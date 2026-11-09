// Caisse du Studio : lire un QR code (billet de retrait d'un achat en ligne, reçu, étiquette d'une pièce) et encaisser une vente en boutique
// (espèces, carte via le QR de la page de paiement Stripe, ou terminal SumUp Solo). Appels : admin/pos-action.php.
(function () {
  'use strict';
  var root = document.getElementById('pos');
  if (!root) return;
  var ACTION = document.currentScript.dataset.action;
  var TERMINAL = document.currentScript.dataset.terminal === '1';   // terminal SumUp Solo appairé (réglages de paiement)
  var KEY = 'pos-panier';

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
  function call(data) {
    return fetch(ACTION, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(data) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue (' + r.status + ').' }; }); })
      .catch(function () { return { ok: false, error: 'Connexion perdue : vérifiez le réseau.' }; });
  }

  var basket = [];
  try { basket = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) {}
  function save() { try { sessionStorage.setItem(KEY, JSON.stringify(basket)); } catch (e) {} }
  function total() { return basket.reduce(function (s, l) { return s + (+l.price_cents || 0); }, 0); }

  // ── Structure ──
  var scanCard = el('section', 'pos-card');
  scanCard.appendChild(el('h1', null, 'Caisse'));
  scanCard.appendChild(el('p', 'qa-note', 'Scannez le QR code d’un billet de retrait (achat en ligne), d’un reçu ou d’une étiquette de pièce.'));
  var row = el('div', 'pos-row');
  var camBtn = el('button', 'btn btn-primary', '📷 Scanner un QR code'); camBtn.type = 'button';
  var input = el('input'); input.type = 'text'; input.placeholder = 'ou code du billet / référence'; input.setAttribute('aria-label', 'Code ou référence'); input.autocapitalize = 'characters'; input.autocomplete = 'off';
  var okBtn = el('button', 'btn btn-ghost', 'Chercher'); okBtn.type = 'button';
  row.appendChild(camBtn); row.appendChild(input); row.appendChild(okBtn);
  var msg = el('p', 'pos-msg'); msg.setAttribute('role', 'status');
  scanCard.appendChild(row); scanCard.appendChild(msg);
  var result = el('div', 'pos-result'); result.hidden = true;
  var basketCard = el('section', 'pos-card');
  var overlay = el('div', 'pos-overlay'); overlay.hidden = true;
  root.appendChild(scanCard); root.appendChild(result); root.appendChild(basketCard); root.appendChild(overlay);

  function say(text, bad) { msg.textContent = text || ''; msg.className = 'pos-msg' + (bad ? ' is-bad' : ''); }

  // ── Lecture d'un code ──
  function lookup(code) {
    code = String(code || '').trim();
    if (!code) return;
    say('Recherche…');
    call({ action: 'lookup', code: code }).then(function (r) {
      if (!r.ok) { say(r.error || 'Erreur.', true); return; }
      say('');
      showResult(r);
    });
  }
  camBtn.addEventListener('click', function () {
    if (!window.barcodeScanCamera) { say('Le lecteur de codes n’est pas chargé : saisissez le code.', true); return; }
    window.barcodeScanCamera(function (code) { input.value = ''; lookup(code); }, function () { say('Caméra inaccessible : saisissez le code du billet.', true); });
  });
  okBtn.addEventListener('click', function () { lookup(input.value); input.value = ''; });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); okBtn.click(); } });

  function showResult(r) {
    result.textContent = ''; result.hidden = false;
    var close = el('button', 'pos-close', '✕'); close.type = 'button'; close.setAttribute('aria-label', 'Fermer');
    close.addEventListener('click', function () { result.hidden = true; });
    result.appendChild(close);
    if (r.type === 'none') { result.appendChild(el('p', null, 'Code non reconnu.')); return; }
    if (r.type === 'product') {
      result.appendChild(el('h2', null, r.name));
      result.appendChild(el('p', 'pos-line', 'Réf. ' + r.ref + ' · ' + r.price + ' · stock ' + r.stock));
      var inBasket = basket.some(function (l) { return l.ref === r.ref; });
      if (r.stock < 1) result.appendChild(el('p', 'pos-bad', 'Cette pièce n’est plus en stock.'));
      else if (!r.free) result.appendChild(el('p', 'pos-bad', 'Réservée ou en cours de paiement par un client : scannez son billet.'));
      else if (inBasket) result.appendChild(el('p', 'pos-ok', 'Déjà dans le panier.'));
      else {
        var add = el('button', 'btn btn-primary', 'Ajouter au panier' + (r.price_cents != null ? ' — ' + r.price : '')); add.type = 'button';
        add.addEventListener('click', function () { basket.push({ ref: r.ref, name: r.name, price_cents: r.price_cents != null && r.fixed ? r.price_cents : 0 }); save(); result.hidden = true; renderBasket(); say('« ' + r.name + ' » ajoutée.'); });
        result.appendChild(add);
        if (!r.fixed) result.appendChild(el('p', 'qa-note', 'Prix non fixe : saisissez le prix négocié dans le panier.'));
      }
      return;
    }
    if (r.type === 'receipt') {
      result.appendChild(el('h2', null, 'Reçu ' + r.number + ' — déjà payé'));
      result.appendChild(el('p', 'pos-ok', 'Acheté en ligne : ' + eur(r.total_cents) + (r.paid_at ? ' le ' + r.paid_at.slice(0, 10) : '')));
      (r.lines || []).forEach(function (l) { result.appendChild(el('p', 'pos-line', l.qty + ' × ' + l.name)); });
      result.appendChild(el('p', 'qa-note', 'Rien à encaisser : remettez la pièce.'));
      return;
    }
    if (r.type === 'pickup') {
      result.appendChild(el('h2', null, 'Billet ' + r.code));
      result.appendChild(el('p', 'pos-line', r.product + ' (réf. ' + r.ref + ')'));
      result.appendChild(el('p', 'pos-line', r.name + (r.phone ? ' · ' + r.phone : '') + ' · ' + r.email));
      result.appendChild(el('p', 'pos-big', eur(r.amount_cents)));
      if (!r.valid) {
        var labels = { collected: 'Déjà retiré et payé.', released: 'Billet annulé : aucune somme prélevée.', expired: 'Billet expiré : aucune somme prélevée.', collecting: 'Encaissement en cours…', authorized: 'Billet périmé.' };
        result.appendChild(el('p', 'pos-bad', labels[r.status] || 'Billet indisponible.'));
        return;
      }
      result.appendChild(el('p', 'pos-ok', 'Billet valide jusqu’au ' + new Date(r.expires_at * 1000).toLocaleDateString('fr-FR')));
      var take = el('button', 'btn btn-primary', 'Encaisser ' + eur(r.amount_cents) + ' et remettre la pièce'); take.type = 'button';
      take.addEventListener('click', function () {
        take.disabled = true; say('Encaissement…');
        call({ action: 'pickup_collect', id: r.id }).then(function (x) {
          if (!x.ok) { take.disabled = false; say(x.error || 'Erreur.', true); return; }
          done('Billet encaissé : ' + eur(r.amount_cents), x.receipt_url);
        });
      });
      var rel = el('button', 'btn btn-ghost', 'Annuler le billet (lever le blocage)'); rel.type = 'button';
      rel.addEventListener('click', function () {
        if (!confirm('Lever le blocage sur la carte du client et libérer la pièce ?')) return;
        call({ action: 'pickup_release', id: r.id }).then(function (x) { say(x.ok ? 'Billet annulé : la pièce est libérée.' : (x.error || 'Erreur.'), !x.ok); if (x.ok) result.hidden = true; });
      });
      result.appendChild(take); result.appendChild(rel);
    }
  }

  // ── Panier ──
  function renderBasket() {
    basketCard.textContent = '';
    basketCard.hidden = !basket.length;
    if (!basket.length) return;
    basketCard.appendChild(el('h2', null, 'Panier (' + basket.length + ')'));
    basket.forEach(function (l, i) {
      var r = el('div', 'pos-item');
      r.appendChild(el('span', 'pos-item-name', l.ref + ' — ' + l.name));
      var p = el('input'); p.type = 'number'; p.min = '0'; p.step = '0.5'; p.inputMode = 'decimal'; p.value = (l.price_cents / 100).toFixed(2); p.setAttribute('aria-label', 'Prix de ' + l.name + ' en euros');
      p.addEventListener('change', function () { var v = Math.round(parseFloat(String(p.value).replace(',', '.')) * 100); l.price_cents = isFinite(v) && v >= 0 ? v : 0; save(); renderBasket(); });
      var rm = el('button', 'pos-rm', '✕'); rm.type = 'button'; rm.setAttribute('aria-label', 'Retirer');
      rm.addEventListener('click', function () { basket.splice(i, 1); save(); renderBasket(); });
      r.appendChild(p); r.appendChild(el('span', null, '€')); r.appendChild(rm);
      basketCard.appendChild(r);
    });
    basketCard.appendChild(el('p', 'pos-big', 'Total ' + eur(total())));
    var mail = el('input'); mail.type = 'email'; mail.placeholder = 'E-mail du client (pour le reçu, facultatif)'; mail.autocomplete = 'off'; mail.setAttribute('aria-label', 'E-mail du client');
    basketCard.appendChild(mail);
    var pay = el('div', 'pos-row');
    var cash = el('button', 'btn btn-primary', '💶 Payé en espèces / en main'); cash.type = 'button';
    cash.addEventListener('click', function () {
      if (!confirm('Confirmer le paiement de ' + eur(total()) + ' reçu en main propre ?')) return;
      cash.disabled = true;
      call({ action: 'sale_cash', items: basket, email: mail.value }).then(function (r) {
        if (!r.ok) { cash.disabled = false; say(r.error || 'Erreur.', true); return; }
        done('Vente enregistrée : ' + eur(r.total_cents), r.receipt_url); basket = []; save(); renderBasket();
      });
    });
    var card = el('button', 'btn btn-ghost', '💳 Carte bancaire'); card.type = 'button';
    card.addEventListener('click', function () {
      card.disabled = true; say('Création de la page de paiement…');
      call({ action: 'sale_card', items: basket }).then(function (r) {
        card.disabled = false;
        if (!r.ok) { say(r.error || 'Erreur.', true); return; }
        say(''); cardScreen(r);
      });
    });
    pay.appendChild(cash); pay.appendChild(card);
    if (TERMINAL) {
      var term = el('button', 'btn btn-ghost', '📟 Terminal SumUp'); term.type = 'button';
      term.addEventListener('click', function () {
        term.disabled = true; say('Envoi du montant au terminal…');
        call({ action: 'sale_terminal', items: basket }).then(function (r) {
          term.disabled = false;
          if (!r.ok) { say(r.error || 'Erreur.', true); return; }
          say(''); terminalScreen(r);
        });
      });
      pay.appendChild(term);
    }
    basketCard.appendChild(pay);
    var clear = el('button', 'btn btn-ghost pos-clear', 'Vider le panier'); clear.type = 'button';
    clear.addEventListener('click', function () { basket = []; save(); renderBasket(); });
    basketCard.appendChild(clear);
  }

  // ── Paiement par carte : le client scanne le QR (ou on lui tend le téléphone) ──
  var poll = null;
  function stopPoll() { if (poll) clearInterval(poll); poll = null; }
  function cardScreen(r) {
    overlay.textContent = ''; overlay.hidden = false;
    overlay.appendChild(el('h2', null, 'Paiement par carte — ' + eur(r.total_cents)));
    overlay.appendChild(el('p', 'qa-note', 'Le client scanne ce QR code avec son téléphone et paie sur la page sécurisée Stripe (carte, Apple Pay, Google Pay).'));
    var qrBox = el('div', 'pos-qr');
    try { var q = qrcode(0, 'M'); q.addData(r.url); q.make(); qrBox.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true }); } catch (e) { qrBox.textContent = 'QR indisponible : utilisez le bouton ci-dessous.'; }
    overlay.appendChild(qrBox);
    var open = el('a', 'btn btn-ghost', 'Ouvrir la page de paiement sur ce téléphone'); open.href = r.url; open.target = '_blank'; open.rel = 'noopener';
    overlay.appendChild(open);
    var st = el('p', 'pos-msg', 'En attente du paiement…'); st.setAttribute('role', 'status'); overlay.appendChild(st);
    var cancel = el('button', 'btn btn-ghost', 'Annuler ce paiement'); cancel.type = 'button';
    overlay.appendChild(cancel);
    function finish(status, receipt) {
      stopPoll(); overlay.hidden = true;
      if (status === 'paid') { basket = []; save(); renderBasket(); done('Paiement reçu : ' + eur(r.total_cents), receipt); }
      else say(status === 'expired' ? 'La page de paiement a expiré.' : 'Paiement annulé.', true);
    }
    cancel.addEventListener('click', function () { call({ action: 'sale_cancel', order_id: r.order_id }).then(function (x) { finish(x.status || 'canceled', x.receipt_url); }); });
    stopPoll();
    poll = setInterval(function () {
      call({ action: 'sale_status', order_id: r.order_id }).then(function (x) {
        if (!x.ok) { st.textContent = x.error || 'Vérification impossible, nouvel essai…'; return; }
        if (x.status !== 'pending') finish(x.status, x.receipt_url);
      });
    }, 3000);
  }

  // ── Paiement au terminal SumUp Solo : le client présente sa carte sur le lecteur ──
  function terminalScreen(r) {
    overlay.textContent = ''; overlay.hidden = false;
    overlay.appendChild(el('h2', null, 'Terminal SumUp — ' + eur(r.total_cents)));
    overlay.appendChild(el('p', 'qa-note', 'Le montant s’affiche sur le terminal. Tendez-le au client : carte, sans contact, Apple Pay ou Google Pay. La vente s’enregistre dès que le paiement est accepté.'));
    var st = el('p', 'pos-msg', 'En attente du paiement sur le terminal…'); st.setAttribute('role', 'status'); overlay.appendChild(st);
    var cancel = el('button', 'btn btn-ghost', 'Annuler sur le terminal'); cancel.type = 'button';
    overlay.appendChild(cancel);
    function finish(status, receipt) {
      stopPoll(); overlay.hidden = true;
      if (status === 'paid') { basket = []; save(); renderBasket(); done('Paiement reçu : ' + eur(r.total_cents), receipt); }
      else say(status === 'failed' ? 'Paiement refusé ou abandonné sur le terminal.' : 'Paiement annulé.', true);
    }
    cancel.addEventListener('click', function () {
      cancel.disabled = true; st.textContent = 'Annulation en cours…';
      call({ action: 'sale_cancel', order_id: r.order_id }).then(function (x) { cancel.disabled = false; finish(x.status || 'canceled', x.receipt_url); });
    });
    stopPoll();
    poll = setInterval(function () {
      call({ action: 'sale_status', order_id: r.order_id }).then(function (x) {
        if (!x.ok) { st.textContent = x.error || 'Vérification impossible, nouvel essai…'; return; }
        if (x.status !== 'pending') finish(x.status, x.receipt_url);
      });
    }, 2500);
  }

  function done(text, receiptUrl) {
    overlay.textContent = ''; overlay.hidden = false; result.hidden = true; stopPoll();
    overlay.appendChild(el('p', 'pos-check', '✓'));
    overlay.appendChild(el('h2', null, text));
    if (receiptUrl) { var a = el('a', 'btn btn-ghost', 'Voir le reçu'); a.href = receiptUrl; a.target = '_blank'; a.rel = 'noopener'; overlay.appendChild(a); }
    var again = el('button', 'btn btn-primary', 'Nouvelle vente'); again.type = 'button';
    again.addEventListener('click', function () { overlay.hidden = true; say(''); });
    overlay.appendChild(again);
  }

  renderBasket();
})();
