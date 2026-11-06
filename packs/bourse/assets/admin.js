// Bourse d'échanges — actions de la boîte de réception (l'API et le jeton CSRF sont fournis par la page du plugin).
(function () {
  var box = document.getElementById('bourse-admin');
  if (!box) return;
  var api = box.getAttribute('data-api');
  var csrf = box.getAttribute('data-csrf');
  var status = document.getElementById('bo-status');

  function send(payload) {
    return fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().catch(function () { return { error: 'Réponse illisible (' + r.status + ')' }; });
    });
  }

  function show(message, kind) {
    if (!status) return;
    status.hidden = false;
    status.textContent = message;
    status.setAttribute('data-kind', kind || 'error');
  }

  box.querySelectorAll('form[data-bourse]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (form.getAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) return;
      var payload = { action: form.getAttribute('data-bourse') };
      new FormData(form).forEach(function (value, key) { payload[key] = value; });
      var button = form.querySelector('button[type=submit]');
      if (button) button.disabled = true;
      send(payload).then(function (data) {
        if (data.error) {
          show(data.error, 'error');
          if (button) button.disabled = false;
          return;
        }
        window.location.reload();
      }).catch(function () {
        show('Enregistrement impossible : vérifiez la connexion.', 'error');
        if (button) button.disabled = false;
      });
    });
  });
})();
