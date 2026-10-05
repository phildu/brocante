// Prompts enregistrés : sous un champ « Mots-clés / précisions » ou « Indications pour l'IA », un bouton
// pour garder le texte saisi, et la liste des prompts déjà enregistrés (un clic les remet dans le champ).
// S'active tout seul sur les champs portant data-saved-prompts :
//   data-saved-prompts="notes"        type fixe (ambiance, angle, complete, notes)
//   data-saved-prompts="@gen-kind"    type = valeur du <select id="gen-kind"> (la liste suit le choix)
// Serveur : admin/prompts-action.php (prompts propres à chaque commerce, partagés par l'équipe).
(function () {
  'use strict';
  var URL_ACTION = (window.APP_BASE || '') + '/admin/prompts-action.php';
  var KINDS = { ambiance: 'mise en situation', angle: 'autre angle', complete: 'compléter l\'objet', notes: 'fiche' };
  var all = [];           // prompts de tous les types
  var widgets = [];       // {kind(), render()}
  var loaded = false;

  function call(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(URL_ACTION, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue du serveur.' }; }); })
      .catch(function () { return { ok: false, error: 'Connexion perdue : réessayez.' }; });
  }

  function refreshAll(res) {
    if (res && res.prompts) all = res.prompts;
    widgets.forEach(function (w) { w.render(); });
  }

  function attach(input) {
    var spec = input.dataset.savedPrompts;
    var select = spec.charAt(0) === '@' ? document.getElementById(spec.slice(1)) : null;
    var kind = function () { return select ? select.value : spec; };

    var box = document.createElement('div');
    box.className = 'sp';
    var bar = document.createElement('div');
    bar.className = 'sp-bar';
    var save = document.createElement('button');
    save.type = 'button'; save.className = 'sp-save'; save.textContent = 'Enregistrer ce prompt';
    var msg = document.createElement('span');
    msg.className = 'sp-msg'; msg.setAttribute('role', 'status');
    bar.appendChild(save); bar.appendChild(msg);
    var list = document.createElement('ul');
    list.className = 'sp-list';
    box.appendChild(bar); box.appendChild(list);
    var anchor = input.closest('label') || input;
    anchor.parentNode.insertBefore(box, anchor.nextSibling);

    function say(text, error) { msg.textContent = text; msg.dataset.kind = error ? 'error' : 'ok'; }

    function render() {
      var k = kind();
      var items = all.filter(function (p) { return p.kind === k; });
      list.textContent = '';
      items.forEach(function (p) {
        var li = document.createElement('li');
        var use = document.createElement('button');
        use.type = 'button'; use.className = 'sp-use'; use.textContent = p.text; use.title = p.text;
        use.addEventListener('click', function () { input.value = p.text; input.dispatchEvent(new Event('input', { bubbles: true })); input.focus(); say(''); });
        var del = document.createElement('button');
        del.type = 'button'; del.className = 'sp-del'; del.textContent = '×';
        del.setAttribute('aria-label', 'Supprimer le prompt « ' + p.text + ' »');
        del.addEventListener('click', function () {
          if (!confirm('Supprimer ce prompt enregistré ?')) return;
          call({ action: 'delete', id: p.id }).then(function (res) { if (res.ok) { refreshAll(res); say('Prompt supprimé.'); } else say(res.error, true); });
        });
        li.appendChild(use); li.appendChild(del); list.appendChild(li);
      });
      box.dataset.empty = items.length ? '' : '1';
      list.hidden = !items.length;
      if (!loaded) return;
      save.title = 'Garde ce texte pour le réutiliser (prompts « ' + KINDS[k] + ' »)';
    }

    save.addEventListener('click', function () {
      if (!input.value.trim()) { say('Saisissez d\'abord un prompt à enregistrer.', true); input.focus(); return; }
      save.disabled = true;
      call({ action: 'save', kind: kind(), text: input.value }).then(function (res) {
        save.disabled = false;
        if (!res.ok) { say(res.error || 'Enregistrement impossible.', true); return; }
        refreshAll(res);
        say(res.created ? 'Prompt enregistré.' : 'Ce prompt est déjà enregistré.');
      });
    });
    if (select) select.addEventListener('change', function () { render(); say(''); });
    widgets.push({ render: render });
    render();
  }

  function start() {
    var inputs = document.querySelectorAll('[data-saved-prompts]');
    if (!inputs.length) return;
    inputs.forEach(attach);
    call({ action: 'list' }).then(function (res) {
      loaded = true;
      if (res.ok) refreshAll(res);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
