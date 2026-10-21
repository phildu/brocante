(function () {
  var page = document.getElementById('am-page');
  if (!page) return;

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': page.dataset.csrf
      },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().catch(function () {
        return { error: 'Réponse illisible (' + r.status + ')' };
      });
    });
  }

  var billingMsg = document.getElementById('am-billing-msg');
  var trialBtn = document.getElementById('am-trial');
  var subBtn = document.getElementById('am-subscribe');
  var tokenBtn = document.getElementById('am-token');
  var tokenOut = document.getElementById('am-token-out');

  if (trialBtn) {
    trialBtn.addEventListener('click', function () {
      trialBtn.disabled = true;
      billingMsg.textContent = 'Activation de l’essai…';
      post(page.dataset.apiTrial).then(function (data) {
        if (data.error) {
          billingMsg.textContent = data.error;
          trialBtn.disabled = false;
          return;
        }
        billingMsg.textContent = data.message || 'Essai activé.';
        location.reload();
      });
    });
  }

  if (subBtn) {
    subBtn.addEventListener('click', function () {
      subBtn.disabled = true;
      billingMsg.textContent = 'Ouverture du paiement…';
      post(page.dataset.apiSubscribe).then(function (data) {
        if (data.url) {
          location.href = data.url;
          return;
        }
        billingMsg.textContent = data.error || 'Paiement indisponible.';
        subBtn.disabled = false;
      });
    });
  }

  if (tokenBtn) {
    tokenBtn.addEventListener('click', function () {
      if (page.dataset.entitled !== '1') return;
      if (tokenOut.textContent && !confirm('Régénérer le jeton ? L’ancien ne fonctionnera plus.')) return;
      tokenBtn.disabled = true;
      post(page.dataset.apiToken).then(function (data) {
        tokenBtn.disabled = false;
        if (data.error) {
          tokenOut.hidden = false;
          tokenOut.textContent = data.error;
          return;
        }
        tokenOut.hidden = false;
        tokenOut.textContent = data.token + '\n\n' + (data.hint || '');
      });
    });
  }
})();
