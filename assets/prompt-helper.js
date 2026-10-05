// Aide à la rédaction sous un champ « Mots-clés / précisions » ou « Indications pour l'IA » :
//  - des idées de décors, de situations et de lumières (un clic les ajoute au champ) et, pour une pièce
//    existante, des idées proposées par l'IA d'après sa photo ;
//  - le prompt réellement envoyé à l'IA, mis à jour à la frappe, avec la consigne surlignée.
// S'active sur les champs portant data-prompt-helper (même syntaxe que data-saved-prompts) :
//   data-prompt-helper="notes"      type fixe (ambiance, angle, complete, notes)
//   data-prompt-helper="@gen-kind"  type = valeur du <select id="gen-kind">
// Serveur : admin/prompts-action.php (actions preview et suggest).
(function () {
  'use strict';
  var URL_ACTION = (window.APP_BASE || '') + '/admin/prompts-action.php';

  var SCENES = [
    ['Décors', ['salon cosy avec cheminée', 'cuisine de campagne en bois', 'entrée avec console ancienne', 'chambre lumineuse et lin', 'bureau d\'artiste', 'terrasse en pierre au soleil', 'jardin fleuri', 'marché en plein air', 'rue pavée d\'une vieille ville', 'atelier de brocanteur']],
    ['Situations', ['portée par une femme en mouvement', 'portée par un homme, debout', 'posée sur une table dressée', 'accrochée à un cintre en bois', 'rangée sur une étagère', 'tenue à la main', 'sur un buffet, mise en valeur']],
    ['Cadrages', ['plan large, décor visible', 'plan moyen', 'gros plan sur le détail', 'portrait en pied', 'vue de dessus, à plat', 'légère plongée', 'contre-plongée', 'objet décentré, règle des tiers', 'arrière-plan flou, faible profondeur de champ']],
    ['Lumière et style', ['lumière dorée de fin de journée', 'lumière douce du matin', 'ambiance hivernale', 'ambiance estivale', 'style scandinave épuré', 'style bohème', 'fond neutre minimaliste', 'photo de magazine']]
  ];
  var IDEAS = {
    ambiance: SCENES,
    notes: SCENES.concat([['Précisions pour la fiche', ['années 70', 'excellent état', 'petite usure visible', 'léger éclat', 'pièce rare', 'fait main', 'pièce unique']]]),
    angle: [['Cadrages', ['gros plan sur le détail', 'plan moyen', 'plan large', 'vue de dessus, à plat', 'légère plongée', 'contre-plongée', 'objet décentré']], ['Prise de vue', ['fond gris clair uni', 'sans les accessoires', 'éclairage doux de studio', 'objet légèrement incliné', 'on voit bien la signature']]],
    complete: [['Précisions', ['garder exactement les mêmes motifs', 'compléter la base symétriquement', 'même matière et même couleur', 'sans rien ajouter d\'autre']]]
  };

  function call(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(URL_ACTION, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue du serveur.' }; }); })
      .catch(function () { return { ok: false, error: 'Connexion perdue : réessayez.' }; });
  }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

  function attach(input) {
    var spec = input.dataset.promptHelper;
    var select = spec.charAt(0) === '@' ? document.getElementById(spec.slice(1)) : null;
    var kind = function () { return select ? select.value : spec; };
    var form = input.form;
    var refInput = form && form.querySelector('[name="ref"]');
    var photoSelect = form && form.querySelector('[name="source_photo_id"]');
    var angleSelect = form && form.querySelector('[name="angle_preset"]');
    var maxLength = parseInt(input.getAttribute('maxlength'), 10) || 300;
    var aiIdeas = [];

    var anchor = input.closest('label') || input;

    // ── Idées ──
    var sug = el('div', 'ph-sug');
    var head = el('div', 'ph-head');
    head.appendChild(el('span', 'ph-title', 'Idées pour rédiger'));
    var msg = el('span', 'ph-msg'); msg.setAttribute('role', 'status');
    var ai = null;
    if (refInput && refInput.value) {
      ai = el('button', 'ph-ai', 'Idées pour cette pièce (IA)'); ai.type = 'button';
      head.appendChild(ai);
    }
    head.appendChild(msg);
    var groups = el('div', 'ph-groups');
    sug.appendChild(head); sug.appendChild(groups);
    anchor.parentNode.insertBefore(sug, anchor.nextSibling);

    function say(text, error) { msg.textContent = text; msg.dataset.kind = error ? 'error' : ''; }

    function add(idea) {
      var current = input.value.trim();
      if (current.toLowerCase().indexOf(idea.toLowerCase()) !== -1) { say('Déjà dans le texte.'); return; }
      var next = current ? current.replace(/[\s,;.]+$/, '') + ', ' + idea : idea;
      if (next.length > maxLength) { say('Trop long (' + maxLength + ' caractères au plus) : raccourcissez le texte.', true); return; }
      input.value = next;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      say('');
    }

    function renderGroups() {
      groups.textContent = '';
      var list = (IDEAS[kind()] || []).slice();
      if (aiIdeas.length) list.unshift(['Idées de l\'IA pour cette pièce', aiIdeas]);
      list.forEach(function (g) {
        var box = el('div', 'ph-group' + (g[0].indexOf('IA') !== -1 ? ' is-ai' : ''));
        box.appendChild(el('p', 'ph-gtitle', g[0]));
        var chips = el('div', 'ph-chips');
        g[1].forEach(function (idea) {
          var b = el('button', 'ph-chip', idea); b.type = 'button'; b.title = 'Ajouter « ' + idea + ' » au texte';
          b.addEventListener('click', function () { add(idea); input.focus(); });
          chips.appendChild(b);
        });
        box.appendChild(chips);
        groups.appendChild(box);
      });
      sug.hidden = !list.length;
    }

    if (ai) ai.addEventListener('click', function () {
      ai.disabled = true; say('L\'IA regarde la photo… (10 à 20 secondes)');
      call({ action: 'suggest', kind: kind(), ref: refInput.value, photo_id: photoSelect ? photoSelect.value : '' }).then(function (res) {
        ai.disabled = false;
        if (!res.ok) { say(res.error || 'Idées indisponibles.', true); return; }
        aiIdeas = res.ideas; say(''); renderGroups();
      });
    });

    // ── Prompt envoyé ──
    var prev = el('details', 'ph-prev');
    prev.open = window.innerWidth >= 700;
    var sum = el('summary', '', 'Prompt envoyé à l\'IA (en français)');
    var body = el('div', 'ph-body');
    prev.appendChild(sum); prev.appendChild(body);
    var english = false;   // false : traduction française ; true : texte exact envoyé, en anglais
    var last = null;       // dernière réponse du serveur, pour basculer sans la redemander
    var saved = anchor.parentNode.querySelector('.sp');
    (saved || sug).parentNode.insertBefore(prev, (saved || sug).nextSibling);

    function highlight(pre, text, needle) {
      if (!needle) { pre.textContent = text; return; }
      var parts = text.split(needle);
      parts.forEach(function (part, i) {
        if (i) pre.appendChild(el('mark', '', needle));
        pre.appendChild(document.createTextNode(part));
      });
    }

    function draw() {
      body.textContent = '';
      if (!last) return;
      if (!last.ok) { body.appendChild(el('p', 'ph-note', last.error || 'Aperçu indisponible.')); return; }
      var tog = el('label', 'ph-toggle');
      var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = english;
      cb.addEventListener('change', function () { english = cb.checked; sum.textContent = english ? 'Prompt envoyé à l\'IA (texte exact, en anglais)' : 'Prompt envoyé à l\'IA (en français)'; draw(); });
      tog.appendChild(cb); tog.appendChild(document.createTextNode(' Voir le texte exact envoyé (en anglais)'));
      body.appendChild(tog);
      var kw = input.value.trim();
      body.appendChild(el('p', 'ph-note', english
        ? 'Texte exact reçu par l\'IA, en anglais (langue que le modèle comprend le mieux)' + (kw ? ' ; votre consigne y est surlignée.' : '.')
        : 'Traduction française fidèle du texte envoyé : l\'IA en reçoit l\'équivalent anglais, phrase pour phrase' + (kw ? ' ; votre consigne y est surlignée.' : '.')));
      last.parts.forEach(function (part) {
        body.appendChild(el('p', 'ph-label', part.label));
        var pre = el('pre', 'ph-text');
        highlight(pre, english ? part.text : part.text_fr, kw);
        body.appendChild(pre);
      });
    }

    var seq = 0, timer = null;
    function refresh() {
      var my = ++seq;
      call({ action: 'preview', kind: kind(), text: input.value, angle: angleSelect ? angleSelect.value : 'auto' }).then(function (res) {
        if (my !== seq) return;
        last = res;
        draw();
      });
    }
    function later() { clearTimeout(timer); timer = setTimeout(refresh, 350); }

    input.addEventListener('input', later);
    if (select) select.addEventListener('change', function () { renderGroups(); say(''); refresh(); });
    if (angleSelect) angleSelect.addEventListener('change', refresh);
    renderGroups();
    refresh();
  }

  function start() { document.querySelectorAll('[data-prompt-helper]').forEach(attach); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
