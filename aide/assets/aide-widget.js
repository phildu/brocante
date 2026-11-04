/* Panneau d'aide contextuel. Intégration : <link rel="stylesheet" href="/aide/assets/aide.css">
   puis <script src="/aide/assets/aide-widget.js" defer></script>. API : BrocsAide.open('slug'). */
(function () {
  'use strict';

  if (window.BrocsAide) return;

  var INDEX_URL = '/aide/aide-index.json.php';
  var panel = null;
  var backdrop = null;
  var body = null;
  var titleEl = null;
  var closeBtn = null;
  var trigger = null;
  var cache = null;
  var lastSlug = '';
  var opened = false;

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function build() {
    if (panel) return;

    backdrop = el('div', 'aide-widget-backdrop');
    backdrop.hidden = true;
    backdrop.addEventListener('click', close);

    panel = el('aside', 'aide-widget');
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-labelledby', 'aide-widget-title');

    var head = el('div', 'aide-widget-head');
    titleEl = el('strong', null, 'Aide');
    titleEl.id = 'aide-widget-title';
    closeBtn = el('button', 'aide-widget-close', '×');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Fermer l\'aide');
    closeBtn.addEventListener('click', close);
    head.appendChild(titleEl);
    head.appendChild(closeBtn);

    body = el('div', 'aide-widget-body');

    var foot = el('div', 'aide-widget-foot');
    var all = el('a', 'aide-btn aide-btn-ghost', 'Tout le centre d\'aide');
    all.href = '/aide/';
    foot.appendChild(all);

    panel.appendChild(head);
    panel.appendChild(body);
    panel.appendChild(foot);
    document.body.appendChild(backdrop);
    document.body.appendChild(panel);
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isOpen()) close();
  });

  document.addEventListener('click', function (event) {
    var opener = event.target.closest ? event.target.closest('[data-aide-open]') : null;
    if (!opener) return;
    event.preventDefault();
    open(opener.getAttribute('data-aide-open'));
  });

  function isOpen() {
    return opened;
  }

  function show() {
    opened = true;
    backdrop.hidden = false;
    panel.hidden = false;
    window.requestAnimationFrame(function () {
      backdrop.classList.add('is-open');
      panel.classList.add('is-open');
    });
  }

  function close() {
    if (!panel) return;
    opened = false;
    panel.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    window.setTimeout(function () {
      if (!opened) {
        panel.hidden = true;
        backdrop.hidden = true;
      }
    }, 220);
    if (trigger && document.contains(trigger)) {
      trigger.focus();
    } else if (closeBtn) {
      closeBtn.blur();
    }
    trigger = null;
  }

  function loadIndex() {
    if (cache) return cache;
    cache = window.fetch(INDEX_URL, { headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .catch(function (error) {
        cache = null;
        throw error;
      });
    return cache;
  }

  function renderLoading(slug) {
    body.textContent = '';
    var status = el('p', null, 'Chargement de la fiche…');
    status.setAttribute('role', 'status');
    body.appendChild(status);
    titleEl.textContent = 'Aide';
    if (slug) body.setAttribute('data-aide-slug', slug);
  }

  function renderError() {
    body.textContent = '';
    body.appendChild(el('p', null, 'La fiche n\'a pas pu être chargée. Le centre d\'aide reste disponible.'));
    var p = el('p');
    var link = el('a', 'aide-btn', 'Ouvrir le centre d\'aide');
    link.href = '/aide/';
    p.appendChild(link);
    body.appendChild(p);
  }

  function renderNotFound(slug) {
    body.textContent = '';
    body.appendChild(el('p', null, 'Cette fiche d\'aide n\'existe pas ou a été renommée.'));
    var p = el('p');
    var link = el('a', 'aide-btn', 'Chercher dans le centre d\'aide');
    link.href = '/aide/?q=' + encodeURIComponent(slug.replace(/-/g, ' '));
    p.appendChild(link);
    body.appendChild(p);
  }

  function typeLabel(type) {
    return type === 'tutoriel' ? 'Tutoriel' : (type === 'glossaire' ? 'Glossaire' : 'Question');
  }

  function renderArticle(article) {
    body.textContent = '';
    titleEl.textContent = 'Aide';

    body.appendChild(el('p', 'aide-kicker', typeLabel(article.type) + ' · ' + article.role_label));
    body.appendChild(el('h2', null, article.titre));
    body.appendChild(el('p', null, article.resume));

    if (Array.isArray(article.etapes) && article.etapes.length) {
      var steps = el('ol');
      article.etapes.forEach(function (step) {
        steps.appendChild(el('li', null, step));
      });
      body.appendChild(steps);
    }

    if (Array.isArray(article.erreurs) && article.erreurs.length) {
      var errors = el('div', 'aide-errors');
      errors.appendChild(el('h3', null, 'Erreurs fréquentes'));
      var list = el('ul');
      article.erreurs.forEach(function (error) {
        list.appendChild(el('li', null, error));
      });
      errors.appendChild(list);
      body.appendChild(errors);
    }

    var links = el('p');
    var full = el('a', 'aide-btn', 'Ouvrir la fiche complète');
    full.href = article.url;
    links.appendChild(full);
    body.appendChild(links);

    if (Array.isArray(article.liens) && article.liens.length) {
      var pageLinks = el('p');
      article.liens.forEach(function (lien, index) {
        if (index > 0) pageLinks.appendChild(document.createTextNode(' · '));
        var page = el('a', null, lien.libelle);
        page.href = lien.url;
        pageLinks.appendChild(page);
      });
      body.appendChild(pageLinks);
    }

    body.appendChild(el('p', 'aide-hint', 'Fiche issue du centre d\'aide. Aucune donnée n\'est envoyée ni conservée.'));
  }

  function open(slug) {
    build();
    trigger = document.activeElement;
    lastSlug = String(slug || '');
    renderLoading(lastSlug);
    show();
    closeBtn.focus();

    if (!lastSlug) {
      body.textContent = '';
      body.appendChild(el('p', null, 'Choisissez une fiche dans le centre d\'aide.'));
      return;
    }

    loadIndex().then(function (data) {
      var articles = Array.isArray(data.articles) ? data.articles : [];
      var found = null;
      for (var i = 0; i < articles.length; i++) {
        if (articles[i].slug === lastSlug) { found = articles[i]; break; }
      }
      if (!isOpen() || body.getAttribute('data-aide-slug') !== lastSlug) return;
      if (found) renderArticle(found);
      else renderNotFound(lastSlug);
    }).catch(function () {
      if (!isOpen()) return;
      renderError();
    });
  }

  window.BrocsAide = {
    open: open,
    close: close
  };
})();
