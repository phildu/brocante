/* Espace forces de vente — interactions légères, sans dépendance. */
(function () {
  'use strict';

  // Menu mobile
  var burger = document.querySelector('[data-fv-burger]');
  var side = document.getElementById('fv-side');
  if (burger && side) {
    burger.addEventListener('click', function () {
      var open = side.classList.toggle('is-open');
      burger.setAttribute('aria-expanded', String(open));
    });
    side.addEventListener('click', function (event) {
      if (event.target.closest('a')) side.classList.remove('is-open');
    });
  }

  // Copie dans le presse-papiers
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy], [data-copy-target]');
    if (!button) return;
    event.preventDefault();
    var value = button.getAttribute('data-copy');
    if (value === '' || value === null) {
      var target = document.querySelector(button.getAttribute('data-copy-target') || '');
      value = target ? (target.value || target.innerText || target.textContent || '') : '';
    }
    var done = function () {
      var old = button.textContent;
      button.textContent = 'Copié ✓';
      button.classList.add('is-copied');
      window.setTimeout(function () { button.textContent = old; button.classList.remove('is-copied'); }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(value).then(done).catch(function () { fallbackCopy(value, done); });
    } else {
      fallbackCopy(value, done);
    }
  });

  function fallbackCopy(value, done) {
    var area = document.createElement('textarea');
    area.value = value;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); done(); } catch (error) { /* silencieux */ }
    document.body.removeChild(area);
  }

  // Impression ciblée d'un kit
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-print]');
    if (!button) return;
    var kind = button.getAttribute('data-print');
    document.body.classList.add('fv-printing', 'print-' + kind);
    window.setTimeout(function () { window.print(); }, 60);
    var clean = function () {
      document.body.classList.remove('fv-printing', 'print-' + kind);
      window.removeEventListener('afterprint', clean);
    };
    window.addEventListener('afterprint', clean);
  });

  // Confirmations
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
    });
  });

  // Compteur de sélection (tournées)
  var checks = document.querySelectorAll('[data-select-item]');
  var counter = document.querySelector('[data-select-count]');
  if (checks.length && counter) {
    var refresh = function () {
      var n = document.querySelectorAll('[data-select-item]:checked').length;
      counter.textContent = n + ' sélection' + (n > 1 ? 's' : '');
      counter.hidden = n === 0;
    };
    checks.forEach(function (check) { check.addEventListener('change', refresh); });
    refresh();
  }
  var toggleAll = document.querySelector('[data-select-all]');
  if (toggleAll) {
    toggleAll.addEventListener('click', function () {
      var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-select-item]'));
      var allOn = boxes.length > 0 && boxes.every(function (box) { return box.checked; });
      boxes.forEach(function (box) { box.checked = !allOn; });
      boxes[0] && boxes[0].dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  // Personnalisation des kits (conservée sur l'appareil)
  var kitFields = document.querySelectorAll('[data-kit-field]');
  if (kitFields.length) {
    var stored = {}, touched = {};
    try { stored = JSON.parse(window.localStorage.getItem('fv-kit-fields') || '{}') || {}; } catch (error) { stored = {}; }
    try { (JSON.parse(window.localStorage.getItem('fv-kit-touched') || '[]') || []).forEach(function (key) { touched[key] = true; }); } catch (error) { touched = {}; }
    var render = function () {
      var values = {};
      kitFields.forEach(function (field) { values[field.getAttribute('data-kit-field')] = field.value; });
      document.querySelectorAll('[data-kit-var]').forEach(function (node) {
        var key = node.getAttribute('data-kit-var');
        if (values[key] !== undefined) node.textContent = values[key] || node.getAttribute('data-kit-empty') || '—';
      });
      try { window.localStorage.setItem('fv-kit-fields', JSON.stringify(values)); } catch (error) { /* silencieux */ }
    };
    kitFields.forEach(function (field) {
      field.addEventListener('input', function () {
        touched[field.getAttribute('data-kit-field')] = true;
        try { window.localStorage.setItem('fv-kit-touched', JSON.stringify(Object.keys(touched))); } catch (error) { /* silencieux */ }
        render();
      });
    });
    kitFields.forEach(function (field) {
      var key = field.getAttribute('data-kit-field');
      if (touched[key] && stored[key] !== undefined) field.value = stored[key];
    });
    render();
  }

  // Constructeur de questions de sondage
  var builder = document.querySelector('[data-question-builder]');
  var addQuestion = document.querySelector('[data-add-question]');
  if (builder && addQuestion) {
    addQuestion.addEventListener('click', function () {
      var rows = builder.querySelectorAll('[data-question-row]');
      if (rows.length >= 12) return;
      var row = rows.length ? rows[rows.length - 1].cloneNode(true) : null;
      if (!row) return;
      row.querySelectorAll('input, select, textarea').forEach(function (field) {
        if (field.type === 'checkbox') field.checked = false;
        else if (field.tagName === 'SELECT') field.selectedIndex = 0;
        else field.value = '';
      });
      builder.appendChild(row);
      var first = row.querySelector('input');
      if (first) first.focus();
    });
    builder.addEventListener('click', function (event) {
      var remove = event.target.closest('[data-remove-question]');
      if (!remove) return;
      var rows = builder.querySelectorAll('[data-question-row]');
      if (rows.length > 1) remove.closest('[data-question-row]').remove();
    });
  }

  // Diaporama du pitch
  document.querySelectorAll('[data-deck]').forEach(function (deck) {
    var slides = Array.prototype.slice.call(deck.querySelectorAll('.slide'));
    if (!slides.length) return;
    var index = 0;
    var dots = deck.querySelector('[data-deck-dots]');
    var notes = deck.querySelector('[data-deck-notes]');
    if (dots) {
      slides.forEach(function (slide, i) {
        var dot = document.createElement('button');
        dot.type = 'button';
        dot.setAttribute('aria-label', 'Diapositive ' + (i + 1));
        dot.addEventListener('click', function () { show(i); });
        dots.appendChild(dot);
      });
    }
    function show(next) {
      index = Math.max(0, Math.min(slides.length - 1, next));
      slides.forEach(function (slide, i) {
        slide.classList.toggle('is-active', i === index);
        slide.setAttribute('aria-hidden', String(i !== index));
      });
      if (dots) Array.prototype.forEach.call(dots.children, function (dot, i) { dot.setAttribute('aria-current', String(i === index)); });
      if (notes) {
        notes.textContent = '';
        var title = document.createElement('b');
        title.textContent = 'Notes du présentateur · ' + (index + 1) + '/' + slides.length;
        notes.appendChild(title);
        notes.appendChild(document.createElement('br'));
        notes.appendChild(document.createTextNode(slides[index].getAttribute('data-notes') || ''));
      }
    }
    deck.querySelectorAll('[data-deck-prev]').forEach(function (button) { button.addEventListener('click', function () { show(index - 1); }); });
    deck.querySelectorAll('[data-deck-next]').forEach(function (button) { button.addEventListener('click', function () { show(index + 1); }); });
    document.addEventListener('keydown', function (event) {
      if (event.target.closest('input, textarea, select')) return;
      if (event.key === 'ArrowRight' || event.key === 'PageDown') { show(index + 1); }
      if (event.key === 'ArrowLeft' || event.key === 'PageUp') { show(index - 1); }
    });
    show(0);
  });

  // Chronomètre d'appel
  var timer = document.querySelector('[data-call-timer]');
  if (timer) {
    var started = null;
    var tick = null;
    var output = timer.querySelector('output');
    var start = function () {
      if (started) return;
      started = Date.now();
      tick = window.setInterval(function () {
        var s = Math.floor((Date.now() - started) / 1000);
        output.textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
      }, 500);
    };
    var stop = function (record) {
      if (tick) window.clearInterval(tick);
      tick = null;
      if (record && started) {
        var input = timer.querySelector('[data-timer-input]') || document.querySelector('[data-timer-input]');
        var minutes = Math.max(1, Math.round((Date.now() - started) / 60000));
        if (input && input.value === '') input.value = String(minutes);
      }
      started = null;
      output.textContent = '00:00';
    };
    timer.querySelector('[data-timer-start]').addEventListener('click', start);
    timer.querySelector('[data-timer-stop]').addEventListener('click', function () { stop(false); });
    document.querySelectorAll('form').forEach(function (form) {
      form.addEventListener('submit', function () { stop(true); });
    });
  }

  // Impression immédiate si demandée
  if (new URLSearchParams(window.location.search).get('imprimer') === '1') {
    window.setTimeout(function () { window.print(); }, 300);
  }
})();
