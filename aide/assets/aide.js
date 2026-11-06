/* Recherche instantanée du centre d'aide. Sans requête réseau : le corpus est déjà dans la page. */
(function () {
  'use strict';

  var corpusEl = document.getElementById('aide-corpus');
  if (!corpusEl) return;

  var corpus;
  try {
    corpus = JSON.parse(corpusEl.textContent);
  } catch (error) {
    return;
  }
  var articles = Array.isArray(corpus.articles) ? corpus.articles : [];

  var stopwords = [
    'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'l', 'et', 'ou', 'mais', 'donc', 'or', 'ni', 'car',
    'a', 'au', 'aux', 'en', 'dans', 'par', 'pour', 'sur', 'sous', 'avec', 'sans', 'chez', 'vers', 'entre',
    'est', 'sont', 'etre', 'avoir', 'ai', 'as', 'ont', 'avait', 'ete', 'cette', 'ces', 'cet', 'ce', 'celui',
    'celle', 'ceux', 'celles', 'qui', 'que', 'quoi', 'dont', 'ou', 'il', 'elle', 'ils', 'elles', 'on',
    'nous', 'vous', 'je', 'tu', 'me', 'te', 'se', 'sa', 'son', 'ses', 'leur', 'leurs', 'mon', 'ma', 'mes',
    'ton', 'ta', 'tes', 'notre', 'votre', 'nos', 'vos', 'y', 'plus', 'moins', 'tres', 'trop', 'peu', 'bien',
    'mal', 'fait', 'faire', 'peut', 'pouvez', 'dois', 'doit', 'comment', 'pourquoi', 'quand', 'quel',
    'quelle', 'quels', 'quelles', 'svp', 'merci', 'bonjour', 'salut', 'stp'
  ];

  function fold(text) {
    var value = String(text == null ? '' : text).toLowerCase();
    if (value.normalize) value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    value = value.replace(/œ/g, 'oe').replace(/æ/g, 'ae');
    return value.replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function stem(word) {
    if (word.length > 4 && /[sx]$/.test(word)) return word.slice(0, -1);
    return word;
  }

  function tokens(text) {
    var words = fold(text).split(' ');
    var seen = [];
    for (var i = 0; i < words.length; i++) {
      var word = words[i];
      if (!word || word.length < 2 || stopwords.indexOf(word) !== -1) continue;
      word = stem(word);
      if (seen.indexOf(word) === -1) seen.push(word);
    }
    return seen;
  }

  function containsToken(haystack, token) {
    if (!haystack) return false;
    return new RegExp('(^| )' + token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[sx]?( |$)').test(haystack);
  }

  function fields(article) {
    if (article._fields) return article._fields;
    var titre = fold(article.titre);
    var mots = fold((article.mots_cles || []).join(' '));
    var resume = fold(article.resume);
    var etapes = fold((article.etapes || []).join(' '));
    var erreurs = fold((article.erreurs || []).join(' '));
    var prerequis = fold((article.prerequis || []).join(' '));
    var ecart = fold(article.ecart || '');
    var liens = fold((article.liens || []).map(function (lien) { return lien.libelle; }).join(' '));
    article._fields = {
      titre: titre,
      mots: mots,
      resume: resume,
      etapes: etapes,
      erreurs: erreurs,
      prerequis: prerequis,
      ecart: ecart,
      liens: liens
    };
    return article._fields;
  }

  function search(query, role, limit) {
    var words = tokens(query);
    if (!words.length) return [];
    var phrase = fold(query);
    var out = [];
    for (var i = 0; i < articles.length; i++) {
      var article = articles[i];
      if (role && article.role !== role && !(role !== 'tous' && article.role === 'tous')) continue;
      var f = fields(article);
      var score = 0;
      var matched = 0;
      for (var j = 0; j < words.length; j++) {
        var token = words[j];
        var hit = false;
        if (containsToken(f.titre, token)) { score += 9; hit = true; }
        if (containsToken(f.mots, token)) { score += 6; hit = true; }
        if (containsToken(f.resume, token)) { score += 3; hit = true; }
        if (containsToken(f.etapes, token)) { score += 2; hit = true; }
        if (containsToken(f.liens, token)) { score += 2; hit = true; }
        if (containsToken(f.erreurs, token) || containsToken(f.prerequis, token) || containsToken(f.ecart, token)) { score += 1; hit = true; }
        if (hit) matched++;
      }
      if (score <= 0) continue;
      if (phrase && f.titre.indexOf(phrase) !== -1) score += 8;
      out.push({
        article: article,
        score: score,
        coverage: matched / Math.max(1, words.length),
        matched: matched
      });
    }
    out.sort(function (a, b) {
      if (a.score !== b.score) return b.score - a.score;
      if (a.coverage !== b.coverage) return b.coverage - a.coverage;
      var ta = a.article.type === 'tutoriel' ? 0 : 1;
      var tb = b.article.type === 'tutoriel' ? 0 : 1;
      if (ta !== tb) return ta - tb;
      return a.article.titre.length - b.article.titre.length;
    });
    return out.slice(0, limit || 20);
  }

  var input = document.getElementById('aide-q');
  var roleSelect = document.querySelector('.aide-search select[name="role"]');
  var form = document.querySelector('.aide-search');
  var instant = document.getElementById('aide-instant');
  var browse = document.getElementById('aide-browse');
  if (!input || !instant || !browse || !form) return;

  var role = '';
  try {
    role = new URLSearchParams(window.location.search).get('role') || '';
  } catch (error) {
    role = '';
  }

  function typeLabel(type) {
    return type === 'tutoriel' ? 'Tutoriel' : (type === 'glossaire' ? 'Glossaire' : 'Question');
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function renderResults(query, results) {
    instant.textContent = '';
    if (!query) {
      instant.hidden = true;
      browse.hidden = false;
      return;
    }
    browse.hidden = true;
    instant.hidden = false;

    var heading = el('h2', null, 'Résultats pour « ' + query + ' »');
    instant.appendChild(heading);

    var count = el('p', 'aide-count', results.length + ' résultat' + (results.length > 1 ? 's' : '') + (role ? ' pour ce rôle' : '') + '.');
    count.setAttribute('role', 'status');
    instant.appendChild(count);

    if (!results.length) {
      var empty = el('div', 'aide-empty');
      empty.appendChild(el('p', null, 'Aucune fiche ne correspond à cette recherche.'));
      empty.appendChild(el('p', null, 'Essayez un mot plus court (« plan », « paiement », « registre ») ou posez la question à l\'assistant.'));
      var links = el('p');
      var chat = el('a', null, 'Poser la question à l\'assistant');
      chat.href = '#assistant';
      links.appendChild(chat);
      if (corpus.contact) {
        links.appendChild(document.createTextNode(' · '));
        var mail = el('a', null, 'Écrire à ' + corpus.contact);
        mail.href = corpus.mailto || ('mailto:' + corpus.contact);
        links.appendChild(mail);
      }
      empty.appendChild(links);
      instant.appendChild(empty);
      return;
    }

    var list = el('div', 'aide-results');
    results.forEach(function (result) {
      var article = result.article;
      var card = el('article', 'aide-result');
      card.appendChild(el('p', 'aide-kicker', typeLabel(article.type) + ' · ' + article.role_label));
      var title = el('h3');
      var link = el('a', null, article.titre);
      link.href = article.url;
      title.appendChild(link);
      card.appendChild(title);
      card.appendChild(el('p', null, article.resume));
      var more = el('p', 'aide-more');
      var read = el('a', null, 'Lire la fiche');
      read.href = article.url;
      more.appendChild(read);
      card.appendChild(more);
      list.appendChild(card);
    });
    instant.appendChild(list);
  }

  function applyRoleFilter(nextRole) {
    role = nextRole || '';
    if (roleSelect) roleSelect.value = role;
    document.querySelectorAll('[data-aide-role-link]').forEach(function (chip) {
      if (chip.getAttribute('data-aide-role-link') === (role || 'all')) {
        chip.setAttribute('aria-current', 'page');
      } else {
        chip.removeAttribute('aria-current');
      }
    });
    document.querySelectorAll('#aide-browse [data-aide-role]').forEach(function (section) {
      var sectionRole = section.getAttribute('data-aide-role');
      section.hidden = !!(role && sectionRole !== 'all' && sectionRole !== role);
    });
    var visibleTutos = 0;
    document.querySelectorAll('#aide-browse [data-aide-tutoriels] .aide-card[data-aide-role]').forEach(function (card) {
      var hide = !!(role && card.getAttribute('data-aide-role') !== 'all' && card.getAttribute('data-aide-role') !== role);
      card.hidden = hide;
      if (!hide) visibleTutos++;
    });
    var tutoSection = document.querySelector('#aide-browse [data-aide-tutoriels]');
    if (tutoSection) tutoSection.hidden = !!role && visibleTutos === 0;
    var glossaire = document.querySelector('#aide-browse [data-aide-glossaire]');
    if (glossaire) glossaire.hidden = !!role && role !== 'tous';
    if (input.value.trim()) renderResults(input.value.trim(), search(input.value.trim(), role, 20));
  }

  function syncUrl(query) {
    if (!window.history || !window.history.replaceState) return;
    var params = new URLSearchParams();
    if (query) params.set('q', query);
    if (role) params.set('role', role);
    var suffix = params.toString();
    try {
      window.history.replaceState(null, '', window.location.pathname + (suffix ? '?' + suffix : '') + window.location.hash);
    } catch (error) {
      /* file:// ou historique indisponible : la page reste utilisable. */
    }
  }

  var timer = 0;
  input.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      var query = input.value.trim();
      renderResults(query, query ? search(query, role, 20) : []);
      syncUrl(query);
    }, 120);
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var query = input.value.trim();
    renderResults(query, query ? search(query, role, 20) : []);
    syncUrl(query);
  });

  if (roleSelect) {
    roleSelect.addEventListener('change', function () {
      applyRoleFilter(roleSelect.value);
      syncUrl(input.value.trim());
    });
  }

  document.querySelectorAll('[data-aide-role-link]').forEach(function (chip) {
    chip.addEventListener('click', function (event) {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      var value = chip.getAttribute('data-aide-role-link');
      applyRoleFilter(value === 'all' ? '' : value);
      syncUrl(input.value.trim());
      if (input.value.trim()) instant.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  function openTarget(id) {
    if (!id) return;
    var node = document.getElementById(id);
    if (node && node.tagName === 'DETAILS') {
      node.open = true;
      node.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('a[href^="#"]') : null;
    if (!link) return;
    var id = link.getAttribute('href').slice(1);
    if (id && document.getElementById(id)) openTarget(id);
  });

  window.addEventListener('hashchange', function () {
    openTarget(window.location.hash.replace(/^#/, ''));
  });

  var initialOpen = new URLSearchParams(window.location.search).get('open');
  var hashTarget = window.location.hash.replace(/^#/, '');
  if (initialOpen) openTarget(initialOpen);
  else if (hashTarget) openTarget(hashTarget);

  if (role) applyRoleFilter(role);
})();
