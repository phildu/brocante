// Sélecteur du diaporama d'une fiche (galerie admin) : voir admin/_diaporama-picker.php.
(function () {
  var box = document.getElementById('dp-block');
  var grid = document.getElementById('dp-grid');
  if (!box || !grid) return;
  var items;
  try { items = JSON.parse(grid.getAttribute('data-items') || '[]'); } catch (e) { items = []; }
  var count = document.getElementById('dp-count'), saveEl = document.getElementById('dp-save');
  var timer = null, dragId = null, seq = 0;

  function say(msg, kind) { saveEl.textContent = msg || ''; if (kind) saveEl.setAttribute('data-kind', kind); else saveEl.removeAttribute('data-kind'); }
  function byId(id) { for (var i = 0; i < items.length; i++) if (items[i].id === id) return items[i]; return null; }
  function shownIds() { return items.filter(function (it) { return it.on; }).map(function (it) { return it.id; }); }

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

  function render() {
    grid.textContent = '';
    var n = 0;
    items.forEach(function (it) {
      var tile = el('div', 'dp-tile' + (it.on ? ' is-on' : ''));
      tile.setAttribute('role', 'button'); tile.tabIndex = 0;
      tile.setAttribute('aria-pressed', it.on ? 'true' : 'false');
      tile.title = it.label + (it.on ? ' — dans le diaporama (cliquer pour retirer)' : ' — masquée (cliquer pour afficher)');
      tile.draggable = it.on;
      tile.dataset.id = it.id;
      var media = it.video ? el('video') : el('img');
      media.src = '/' + it.src;
      if (it.video) media.muted = true; else { media.alt = ''; media.loading = 'lazy'; }
      tile.appendChild(media);
      if (it.on) tile.appendChild(el('span', 'dp-num', String(++n)));
      var tag = it.video ? 'vidéo' : (it.ai ? 'IA' : '');
      if (it.screen) tag = (tag ? tag + ' · ' : '') + it.screen;
      if (tag) tile.appendChild(el('span', 'dp-tag', tag));
      if (it.on) {
        var mv = el('div', 'dp-move');
        var l = el('span', null, '◀'), r = el('span', null, '▶');
        l.title = 'Avancer'; r.title = 'Reculer';
        l.addEventListener('click', function (e) { e.stopPropagation(); move(it.id, -1); });
        r.addEventListener('click', function (e) { e.stopPropagation(); move(it.id, 1); });
        mv.appendChild(l); mv.appendChild(r); tile.appendChild(mv);
      }
      tile.appendChild(el('span', 'dp-label', it.label));
      tile.addEventListener('click', function () { toggle(it.id); });
      tile.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(it.id); }
        else if (it.on && e.key === 'ArrowLeft') { e.preventDefault(); move(it.id, -1, true); }
        else if (it.on && e.key === 'ArrowRight') { e.preventDefault(); move(it.id, 1, true); }
      });
      tile.addEventListener('dragstart', function (e) { dragId = it.id; tile.classList.add('is-drag'); try { e.dataTransfer.setData('text/plain', String(it.id)); e.dataTransfer.effectAllowed = 'move'; } catch (x) {} });
      tile.addEventListener('dragend', function () { dragId = null; render(); });
      tile.addEventListener('dragover', function (e) { if (dragId !== null && it.on && dragId !== it.id) { e.preventDefault(); tile.classList.add('is-over'); } });
      tile.addEventListener('dragleave', function () { tile.classList.remove('is-over'); });
      tile.addEventListener('drop', function (e) {
        e.preventDefault();
        var from = byId(dragId), to = it;
        if (!from || from === to) return;
        items.splice(items.indexOf(from), 1);
        items.splice(items.indexOf(to), 0, from);
        dragId = null; changed();
      });
      grid.appendChild(tile);
    });
    count.textContent = n + ' image' + (n > 1 ? 's' : '') + ' sur ' + items.length + ' dans le diaporama';
  }

  function sortOn() {
    // Les images montrées restent avant les masquées (ordre relatif conservé).
    var on = items.filter(function (i) { return i.on; }), off = items.filter(function (i) { return !i.on; });
    items = on.concat(off);
  }

  function toggle(id) {
    var it = byId(id);
    if (it.on && shownIds().length <= 1) { say('Gardez au moins une image dans le diaporama.', 'error'); return; }
    it.on = !it.on;
    if (it.on) { items.splice(items.indexOf(it), 1); var last = shownIds().length; items.splice(last, 0, it); }
    changed();
  }

  function move(id, dir, keepFocus) {
    var it = byId(id), shown = items.filter(function (i) { return i.on; });
    var pos = shown.indexOf(it), target = shown[pos + dir];
    if (!target) return;
    items.splice(items.indexOf(it), 1);
    items.splice(items.indexOf(target) + (dir > 0 ? 1 : 0), 0, it);
    changed();
    if (keepFocus) { var t = grid.querySelector('[data-id="' + id + '"]'); if (t) t.focus(); }
  }

  function setAll(fn) {
    var ok = 0;
    items.forEach(function (it) { it.on = !!fn(it); if (it.on) ok++; });
    if (!ok) { items[0].on = true; }
    changed();
  }

  function changed() { sortOn(); render(); syncList(); say('Enregistrement…'); clearTimeout(timer); timer = setTimeout(save, 500); }

  // Garde la liste détaillée de la page (case « Masquer cette photo ») en phase avec le sélecteur.
  function syncList() {
    items.forEach(function (it) {
      var row = document.getElementById('photo-' + it.id);
      if (!row) return;
      row.classList.toggle('is-hidden-photo', !it.on);
      var cb = row.querySelector('input[name="hidden"]');
      if (cb) cb.checked = !it.on;
    });
  }

  function save() {
    var my = ++seq, fd = new FormData();
    fd.append('csrf', box.dataset.csrf); fd.append('ref', box.dataset.ref); fd.append('order', shownIds().join(','));
    fetch('/admin/diaporama-action.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (my !== seq) return; if (res.ok) say('Enregistré ✓'); else say(res.error || 'Échec de l\'enregistrement.', 'error'); })
      .catch(function () { if (my === seq) say('Connexion perdue : modification non enregistrée.', 'error'); });
  }

  document.getElementById('dp-all').addEventListener('click', function () { setAll(function (it) { return !/^mesure/i.test(it.label); }); });
  document.getElementById('dp-generated').addEventListener('click', function () {
    if (!items.some(function (i) { return i.ai; })) { say('Aucune image générée à montrer pour l\'instant.', 'error'); return; }
    // Images générées + gros plans (étiquette, texture, détail) comme à la création par le Studio.
    setAll(function (it) { return it.ai || it.video || /^(étiquette|etiquette|texture|détail|detail)/i.test(it.label); });
  });
  render();
})();
