// Plugin de démonstration : liste, ajout et suppression de notes par l'API du plugin (adresses et jeton CSRF fournis par la page).
(function () {
  var box = document.getElementById('hello');
  if (!box) return;
  var list = document.getElementById('hello-notes');
  var status = document.getElementById('hello-status');
  var form = document.getElementById('hello-form');

  function call(url, body) {
    var opts = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (body) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = box.dataset.csrf;
      opts.body = JSON.stringify(body);
    }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return { error: 'Réponse illisible (' + r.status + ')' }; });
    });
  }

  function render(data) {
    if (data.error) { status.textContent = data.error; return; }
    status.textContent = data.notes.length ? '' : 'Aucune note pour le moment.';
    list.innerHTML = '';
    data.notes.forEach(function (n) {
      var li = document.createElement('li');
      var text = document.createElement('span');
      text.textContent = n.text;
      var meta = document.createElement('small');
      meta.textContent = n.author + ' · ' + n.created_at;
      li.appendChild(text);
      li.appendChild(meta);
      if (data.can_delete) {
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn-small';
        del.textContent = 'Supprimer';
        del.addEventListener('click', function () {
          call(box.dataset.apiDelete, { id: n.id }).then(load);
        });
        li.appendChild(del);
      }
      list.appendChild(li);
    });
  }

  function load() { call(box.dataset.apiNotes).then(render); }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var input = form.elements.text;
    call(box.dataset.apiNotes, { text: input.value }).then(function (data) {
      if (!data.error) input.value = '';
      render(data);
    });
  });
  load();
})();
