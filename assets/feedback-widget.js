/* Widget « Un retour ? » (programme testeurs). Aucune dépendance.
   L'API /admin/feedback-api.php vérifie la session de la boutique et le jeton
   CSRF ; ici on ne fait que composer la demande et afficher le suivi. */
(function () {
  var root = document.querySelector('[data-fbw]');
  if (!root) return;
  var config;
  try { config = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { return; }
  if (!config.api) return;

  var launch = root.querySelector('[data-fbw-open]');
  var panel = root.querySelector('.fbw-panel');
  var closeBtn = root.querySelector('[data-fbw-close]');
  var form = root.querySelector('[data-fbw-form]');
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-fbw-tab]'));
  var formView = form;
  var history = root.querySelector('[data-fbw-history]');
  var list = root.querySelector('[data-fbw-list]');
  var empty = root.querySelector('[data-fbw-empty]');
  var msg = root.querySelector('[data-fbw-msg]');
  var submit = root.querySelector('.fbw-submit');
  var dot = root.querySelector('[data-fbw-dot]');
  var count = root.querySelector('[data-fbw-count]');
  var severity = root.querySelector('[data-fbw-severity]');
  var textarea = form ? form.querySelector('textarea[name="message"]') : null;
  var historyLoaded = false;

  function setMessage(text, kind) {
    if (!msg) return;
    msg.textContent = text || '';
    if (kind) msg.setAttribute('data-kind', kind); else msg.removeAttribute('data-kind');
  }

  function openPanel() {
    if (!panel) return;
    panel.hidden = false;
    launch.setAttribute('aria-expanded', 'true');
    peek();
    if (textarea && !form.hidden) textarea.focus();
  }

  function closePanel() {
    if (!panel) return;
    panel.hidden = true;
    launch.setAttribute('aria-expanded', 'false');
    launch.focus();
  }

  function showTab(name) {
    tabs.forEach(function (tab) {
      var active = tab.getAttribute('data-fbw-tab') === name;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    formView.hidden = name !== 'send';
    history.hidden = name !== 'mine';
    if (name === 'mine') loadHistory(true);
  }

  function syncSeverity() {
    var checked = form.querySelector('input[name="type"]:checked');
    if (severity) severity.hidden = !checked || checked.value !== 'bug';
  }

  function formatDate(iso) {
    var date = new Date(iso);
    if (isNaN(date.getTime())) return '';
    try {
      return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) + ' · ' +
        date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
      return date.toLocaleDateString('fr-FR');
    }
  }

  function renderHistory(items) {
    if (!list) return;
    list.textContent = '';
    var total = 0;
    (items || []).forEach(function (item) {
      total += (item.replies || []).length;
      var card = document.createElement('article');
      card.className = 'fbw-history-item';

      var top = document.createElement('div');
      top.className = 'fbw-history-top';
      var type = document.createElement('span');
      type.className = 'fbw-history-type';
      type.textContent = item.type_label || '';
      var badge = document.createElement('span');
      badge.className = 'fbw-badge';
      badge.setAttribute('data-tone', item.status_tone || 'new');
      badge.textContent = item.status_label || '';
      top.appendChild(type);
      top.appendChild(badge);

      var date = document.createElement('p');
      date.className = 'fbw-history-date';
      date.textContent = formatDate(item.at);

      var body = document.createElement('p');
      body.className = 'fbw-history-msg';
      body.textContent = item.message || '';

      card.appendChild(top);
      card.appendChild(date);
      card.appendChild(body);

      (item.replies || []).forEach(function (reply) {
        var box = document.createElement('div');
        box.className = 'fbw-history-reply';
        var who = document.createElement('b');
        who.textContent = 'Réponse de l’équipe' + (reply.at ? ' · ' + formatDate(reply.at) : '');
        box.appendChild(who);
        box.appendChild(document.createTextNode(reply.text || ''));
        card.appendChild(box);
      });

      list.appendChild(card);
    });
    if (empty) empty.hidden = total > 0 || (items || []).length > 0;
    if (count) {
      count.hidden = (items || []).length === 0;
      count.textContent = String((items || []).length);
    }
  }

  function peek() {
    return fetch(config.api + '?action=peek', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (dot && data && data.ok) dot.hidden = !(data.unread > 0);
      })
      .catch(function () {});
  }

  function loadHistory(force) {
    if (historyLoaded && !force) return Promise.resolve();
    return fetch(config.api + '?action=mine', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.ok) return;
        historyLoaded = true;
        if (dot) dot.hidden = true;
        renderHistory(data.items);
      })
      .catch(function () { /* le widget reste utilisable même si l'historique ne se charge pas */ });
  }

  function submitForm(event) {
    event.preventDefault();
    var data = new FormData(form);
    var message = String(data.get('message') || '').trim();
    if (message.length < 10) {
      setMessage('Décrivez votre retour en quelques mots (10 caractères minimum).', 'error');
      if (textarea) textarea.focus();
      return;
    }
    data.append('action', 'submit');
    data.append('page', location.pathname + location.search);
    data.append('page_title', document.title);
    data.append('module', config.module || '');
    data.append('viewport', window.innerWidth + '×' + window.innerHeight);
    if (submit) { submit.disabled = true; submit.textContent = 'Envoi…'; }
    setMessage('', '');
    fetch(config.api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': config.csrf || '' },
      body: data
    })
      .then(function (response) { return response.json().catch(function () { return { ok: false, error: 'Réponse illisible.' }; }); })
      .then(function (result) {
        if (!result || !result.ok) {
          setMessage((result && result.error) || 'Le retour n’a pas pu être envoyé.', 'error');
          return;
        }
        setMessage(result.message || 'Merci ! Votre retour est enregistré.', 'ok');
        form.reset();
        syncSeverity();
        loadHistory(true);
      })
      .catch(function () { setMessage('Erreur réseau : réessayez dans un instant.', 'error'); })
      .finally(function () {
        if (submit) { submit.disabled = false; submit.textContent = 'Envoyer mon retour'; }
      });
  }

  if (launch) launch.addEventListener('click', openPanel);
  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  if (form) form.addEventListener('submit', submitForm);
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { showTab(tab.getAttribute('data-fbw-tab')); });
  });
  if (form) form.querySelectorAll('input[name="type"]').forEach(function (input) {
    input.addEventListener('change', syncSeverity);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && panel && !panel.hidden) closePanel();
  });

  syncSeverity();
  peek();
})();
