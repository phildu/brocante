// Rendez-vous — actions de l'administration et générateur de créneaux.
// L'API, le jeton CSRF et la page courante viennent des attributs data-* posés par les pages du plugin.
(function () {
  var box = document.getElementById('rdv-admin');
  if (!box) return;
  var api = box.getAttribute('data-api');
  var csrf = box.getAttribute('data-csrf');
  var page = box.getAttribute('data-page');
  var pollId = parseInt(box.getAttribute('data-poll') || '0', 10) || 0;
  var listUrl = box.getAttribute('data-list') || '';
  var status = document.getElementById('rdv-msg');
  var base = window.APP_BASE || '';

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
    status.hidden = !message;
    status.textContent = message || '';
    status.setAttribute('data-kind', kind || 'error');
    status.className = (kind === 'error' || kind === 'warn') ? 'rdv-flash' : 'rdv-note';
    if (message && kind === 'error') status.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function go(url) {
    if (!url) return;
    if (base && url.indexOf(base + '/') === 0) {
      window.location.href = url;
      return;
    }
    window.location.href = url.charAt(0) === '/' ? base + url : url;
  }

  function busy(button, text) {
    if (!button) return function () {};
    var original = button.textContent;
    button.disabled = true;
    button.textContent = text || 'Enregistrement…';
    return function () {
      button.disabled = false;
      button.textContent = original;
    };
  }

  function run(button, payload, options) {
    options = options || {};
    var release = busy(button, options.busy);
    return send(payload).then(function (data) {
      if (data && data.error) {
        show(data.error, 'error');
        release();
        return null;
      }
      if (options.redirect) return go(options.redirect);
      if (options.reload === false) {
        release();
        show(options.message || 'Enregistré.', 'ok');
        return data;
      }
      window.location.reload();
      return data;
    }).catch(function () {
      show('Enregistrement impossible : vérifiez la connexion.', 'error');
      release();
      return null;
    });
  }

  function formPayload(form) {
    var payload = {};
    new FormData(form).forEach(function (value, key) { payload[key] = value; });
    return payload;
  }

  // Formulaires de création et d'édition
  var createForm = document.getElementById('rdv-create-form');
  if (createForm) {
    createForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = formPayload(createForm);
      payload.action = 'create';
      var button = createForm.querySelector('button[type=submit]');
      var release = busy(button, 'Création…');
      send(payload).then(function (data) {
        if (data && data.error) {
          show(data.error, 'error');
          release();
          return;
        }
        if (data && data.manage) go(data.manage);
        else release();
      }).catch(function () {
        show('Création impossible : vérifiez la connexion.', 'error');
        release();
      });
    });
  }

  var editForm = document.getElementById('rdv-edit-form');
  if (editForm) {
    editForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = formPayload(editForm);
      payload.action = 'update';
      payload.poll_id = pollId;
      run(editForm.querySelector('button[type=submit]'), payload, { busy: 'Enregistrement…' });
    });
  }

  var addSlots = document.getElementById('rdv-add-slots');
  if (addSlots) {
    addSlots.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = formPayload(addSlots);
      payload.action = 'add_slots';
      payload.poll_id = pollId;
      run(addSlots.querySelector('button[type=submit]'), payload, { busy: 'Ajout…' });
    });
  }

  // Boutons d'action (supprimer, confirmer, changer l'état, prévenir)
  box.addEventListener('click', function (e) {
    var button = e.target.closest('[data-rdv-action]');
    if (!button || button.disabled) return;
    var action = button.getAttribute('data-rdv-action');
    var confirmText = button.getAttribute('data-confirm');
    if (confirmText && !window.confirm(confirmText)) return;
    var payload = { poll_id: pollId };

    if (action === 'delete-slot') {
      payload.action = 'delete_slot';
      payload.slot_id = parseInt(button.getAttribute('data-slot-id') || '0', 10);
      run(button, payload, { busy: 'Suppression…' });
    } else if (action === 'delete-answer') {
      payload.action = 'delete_answer';
      payload.answer_id = parseInt(button.getAttribute('data-answer-id') || '0', 10);
      run(button, payload, { busy: 'Suppression…' });
    } else if (action === 'delete-poll') {
      payload.action = 'delete_poll';
      run(button, payload, { redirect: listUrl, busy: 'Suppression…' });
    } else if (action === 'status') {
      payload.action = 'status';
      payload.status = button.getAttribute('data-status') || '';
      run(button, payload, { busy: 'Mise à jour…' });
    } else if (action === 'notify') {
      payload.action = 'notify';
      run(button, payload, { busy: 'Envoi des e-mails…' });
    } else if (action === 'confirm') {
      var select = document.getElementById('rdv-confirm-slot');
      var check = document.getElementById('rdv-confirm-notify-check');
      payload.action = 'confirm';
      payload.slot_id = parseInt(select ? select.value : '0', 10);
      payload.notify = check && check.checked ? 1 : 0;
      run(button, payload, { busy: check && check.checked ? 'Confirmation et envoi…' : 'Confirmation…' });
    }
  });

  // Copie des liens publics
  box.addEventListener('click', function (e) {
    var button = e.target.closest('[data-copy]');
    if (!button) return;
    var value = button.getAttribute('data-copy');
    var done = function () {
      var original = button.textContent;
      button.textContent = 'Copié';
      window.setTimeout(function () { button.textContent = original; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done, function () { legacyCopy(value); done(); });
    } else {
      legacyCopy(value);
      done();
    }
  });

  function legacyCopy(value) {
    var input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    try { document.execCommand('copy'); } catch (err) {}
    document.body.removeChild(input);
  }

  // Générateur de créneaux
  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function isoDate(date) {
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
  }

  function toMinutes(value) {
    if (!value) return null;
    var parts = value.split(':');
    if (parts.length < 2) return null;
    var h = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    if (isNaN(h) || isNaN(m)) return null;
    return h * 60 + m;
  }

  function toTime(minutes) {
    if (minutes >= 24 * 60) return '23:59';
    return pad(Math.floor(minutes / 60)) + ':' + pad(minutes % 60);
  }

  box.querySelectorAll('[data-rdv-generator]').forEach(function (gen) {
    var targetSel = gen.getAttribute('data-target');
    var target = targetSel ? document.querySelector(targetSel) : null;
    var out = gen.querySelector('[data-gen-status]');
    var dayBoxes = Array.prototype.slice.call(gen.querySelectorAll('.rdv-gen-day'));
    var runButton = gen.querySelector('[data-gen-run]');
    if (!target || !runButton) return;

    function field(name) {
      var el = gen.querySelector('[data-gen="' + name + '"]');
      return el ? el.value : '';
    }

    function note(message, kind) {
      if (!out) return;
      out.textContent = message;
      out.setAttribute('data-kind', kind || '');
    }

    runButton.addEventListener('click', function () {
      var from = field('from');
      var to = field('to');
      if (!from || !to) { note('Indiquez la première et la dernière date.', 'error'); return; }
      var startDate = new Date(from + 'T00:00:00');
      var endDate = new Date(to + 'T00:00:00');
      if (isNaN(startDate.getTime()) || isNaN(endDate.getTime()) || startDate > endDate) {
        note('La période est invalide : la fin doit suivre le début.', 'error');
        return;
      }
      var days = dayBoxes.filter(function (box) { return box.checked; }).map(function (box) { return parseInt(box.value, 10); });
      if (!days.length) { note('Cochez au moins un jour de la semaine.', 'error'); return; }

      var start = toMinutes(field('start'));
      var end = toMinutes(field('end'));
      var duration = parseInt(field('duration') || '0', 10);
      if (isNaN(duration) || duration < 0) duration = 0;
      if (start !== null && end !== null && end <= start) {
        note('Le dernier horaire doit suivre le premier.', 'error');
        return;
      }

      var existing = {};
      target.value.split(/\r?\n/).forEach(function (line) { existing[line.trim()] = true; });
      var lines = [];
      var cursor = new Date(startDate.getTime());
      var guard = 0;
      while (cursor <= endDate && guard < 750) {
        guard++;
        if (days.indexOf(cursor.getDay()) !== -1) {
          var day = isoDate(cursor);
          if (start === null) {
            lines.push(day);
          } else if (end === null) {
            lines.push(duration > 0 ? day + ' ' + toTime(start) + '-' + toTime(start + duration) : day + ' ' + toTime(start));
          } else if (duration > 0) {
            for (var slot = start; slot + duration <= end; slot += duration) {
              lines.push(day + ' ' + toTime(slot) + '-' + toTime(slot + duration));
            }
            if (start + duration > end) lines.push(day + ' ' + toTime(start) + '-' + toTime(end));
          } else {
            lines.push(day + ' ' + toTime(start) + '-' + toTime(end));
          }
        }
        cursor.setDate(cursor.getDate() + 1);
      }

      var added = [];
      lines.forEach(function (line) {
        if (!existing[line]) { existing[line] = true; added.push(line); }
      });
      if (!added.length) {
        note('Ces créneaux sont déjà dans la liste.', 'warn');
        return;
      }
      target.value = (target.value.trim() ? target.value.replace(/\s+$/, '') + '\n' : '') + added.join('\n');
      target.dispatchEvent(new Event('input', { bubbles: true }));
      note(added.length + ' créneau' + (added.length > 1 ? 'x' : '') + ' ajouté' + (added.length > 1 ? 's' : '') + '.', 'ok');
    });
  });

  if (page === 'manage') {
    var summary = box.querySelector('.rdv-lede');
    if (summary) summary.setAttribute('data-page', 'manage');
  }
})();
