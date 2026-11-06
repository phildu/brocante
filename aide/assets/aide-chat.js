/* Assistant documentaire local : lit le corpus de la page, ne fait aucune requête et ne stocke rien. */
(function () {
  'use strict';

  var log = document.getElementById('aide-chat-log');
  var form = document.getElementById('aide-chat-form');
  var input = document.getElementById('aide-chat-question');
  var data = document.getElementById('aide-chat-data');
  var corpusEl = document.getElementById('aide-corpus');
  if (!log || !form || !input || !data || !corpusEl) return;

  var corpus;
  try {
    corpus = JSON.parse(corpusEl.textContent);
  } catch (error) {
    return;
  }
  var articles = Array.isArray(corpus.articles) ? corpus.articles : [];
  var contact = data.getAttribute('data-contact') || corpus.contact || '';
  var mailto = data.getAttribute('data-mailto') || corpus.mailto || '';
  var minScore = parseInt(data.getAttribute('data-threshold'), 10) || 10;
  var minCoverage = parseFloat(data.getAttribute('data-coverage')) || 0.5;

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
    article._fields = {
      titre: fold(article.titre),
      mots: fold((article.mots_cles || []).join(' ')),
      resume: fold(article.resume),
      etapes: fold((article.etapes || []).join(' ')),
      erreurs: fold((article.erreurs || []).join(' ')),
      prerequis: fold((article.prerequis || []).join(' ')),
      ecart: fold(article.ecart || ''),
      liens: fold((article.liens || []).map(function (lien) { return lien.libelle; }).join(' '))
    };
    return article._fields;
  }

  function search(query) {
    var words = tokens(query);
    if (!words.length) return [];
    var phrase = fold(query);
    var out = [];
    for (var i = 0; i < articles.length; i++) {
      var article = articles[i];
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
      out.push({ article: article, score: score, coverage: matched / Math.max(1, words.length) });
    }
    out.sort(function (a, b) {
      if (a.score !== b.score) return b.score - a.score;
      if (a.coverage !== b.coverage) return b.coverage - a.coverage;
      var ta = a.article.type === 'tutoriel' ? 0 : 1;
      var tb = b.article.type === 'tutoriel' ? 0 : 1;
      if (ta !== tb) return ta - tb;
      return a.article.titre.length - b.article.titre.length;
    });
    return out.slice(0, 6);
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function appendUser(text) {
    var box = el('div', 'aide-msg aide-msg-user');
    box.appendChild(el('p', 'aide-msg-who', 'Vous'));
    box.appendChild(el('p', null, text));
    log.appendChild(box);
  }

  function appendBot(question) {
    var box = el('div', 'aide-msg aide-msg-bot');
    box.appendChild(el('p', 'aide-msg-who', 'Assistant'));
    var trimmed = question.trim();
    if (!trimmed) {
      box.appendChild(el('p', null, 'Posez une question sur Brocs : plan, emplacement, paiement, Studio, registre…'));
      log.appendChild(box);
      return;
    }

    var results = search(trimmed);
    var best = results[0];
    if (!best || best.score < minScore || best.coverage < minCoverage) {
      var refusal = contact && mailto
        ? 'Je ne trouve pas cette réponse dans le centre d\'aide. Écrivez-nous à l\'adresse de contact ci-dessous, ou utilisez le formulaire de contact d\'une fiche produit quand la boutique l\'a activé.'
        : 'Je ne trouve pas cette réponse dans le centre d\'aide. Utilisez le formulaire de contact d\'une fiche produit quand la boutique l\'a activé, ou consultez les conditions d\'utilisation pour joindre la plateforme.';
      box.appendChild(el('p', null, refusal));
      if (contact && mailto) {
        var p = el('p');
        var link = el('a', 'aide-btn', 'Écrire à ' + contact);
        link.href = mailto;
        p.appendChild(link);
        box.appendChild(p);
      }
      box.appendChild(el('p', 'aide-hint', 'Le formulaire de contact d\'une boutique se trouve sur la fiche d\'une pièce, quand le module Messagerie est activé.'));
      log.appendChild(box);
      return;
    }

    var article = best.article;
    var tuto = null;
    for (var i = 0; i < results.length; i++) {
      if (results[i].article.type === 'tutoriel') { tuto = results[i].article; break; }
    }

    var title = el('p');
    title.appendChild(el('strong', null, article.titre));
    box.appendChild(title);
    box.appendChild(el('p', null, article.resume));

    var links = el('p');
    var read = el('a', null, 'Ouvrir la fiche');
    read.href = article.url;
    links.appendChild(read);
    if (tuto && tuto.url !== article.url) {
      links.appendChild(document.createTextNode(' · '));
      var tutorialLink = el('a', null, 'Voir le tutoriel');
      tutorialLink.href = tuto.url;
      links.appendChild(tutorialLink);
    }
    box.appendChild(links);
    log.appendChild(box);
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var question = input.value.trim();
    appendUser(question);
    appendBot(question);
    input.value = '';
    input.focus();
    while (log.children.length > 40) log.removeChild(log.firstElementChild);
  });
})();
