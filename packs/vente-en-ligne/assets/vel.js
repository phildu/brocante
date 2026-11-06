/* Pack Vente en ligne — formulaires envoyés à l'API du module (fetch + CSRF), puis rechargement. */
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var root = document.getElementById('vel-page');
    if (!root) return;
    var api = root.getAttribute('data-api');
    var csrf = root.getAttribute('data-csrf');
    var message = document.getElementById('vel-msg');

    function show(text, ok) {
      if (!message) return;
      message.textContent = text;
      message.setAttribute('data-kind', ok ? 'ok' : 'error');
    }

    root.addEventListener('submit', function (event) {
      var form = event.target.closest('form[data-vel-form]');
      if (!form) return;
      event.preventDefault();
      if (form.getAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) return;

      var button = form.querySelector('[type="submit"]');
      var body = new FormData(form);
      body.set('action', form.getAttribute('data-vel-form'));
      if (button) button.disabled = true;
      show('Enregistrement…', true);

      fetch(api, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf },
        body: body
      }).then(function (response) {
        return response.json().catch(function () {
          return { error: 'Réponse illisible (HTTP ' + response.status + ').' };
        });
      }).then(function (data) {
        if (data.error) {
          show(data.error, false);
          if (button) button.disabled = false;
          return;
        }
        if (form.hasAttribute('data-vel-stay')) {
          show(data.message || 'Enregistré.', true);
          if (button) button.disabled = false;
          return;
        }
        window.location.reload();
      }).catch(function () {
        show('La connexion a échoué, réessayez.', false);
        if (button) button.disabled = false;
      });
    });
  });
})();
