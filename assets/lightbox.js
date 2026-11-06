// Survol d'une image (ordinateur) : deux boutons apparaissent dans son coin — « Agrandir » (fenêtre plein écran) et « Télécharger ».
// Dans la galerie d'une fiche, les flèches ◀ ▶ (ou du clavier) passent d'une image à l'autre. Échap, la croix ou un clic à côté referment.
// Aucune modification des pages : le script repère lui-même les images assez grandes (≥ 110 × 80 px). Exclusions : data-no-lightbox sur
// l'image ou un de ses parents, l'en-tête, le pied de page, les boutons.
(function () {
  if (window.__lightbox) return;
  window.__lightbox = true;

  var me = document.currentScript;
  if (me && me.src) {
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = me.src.replace(/lightbox\.js(\?.*)?$/, 'lightbox.css$1');
    document.head.appendChild(css);
  }

  var hoverOK = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var EXCLUDE = '[data-no-lightbox], header, footer, nav, button, .lb-ui, .quick-preview-overlay, .logo, .wordmark, .brand';
  var tools = null, hideTimer = null, current = null, box = null, group = [], index = 0, lastFocus = null;

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function srcOf(img) { return img.getAttribute('data-full') || img.currentSrc || img.src || ''; }
  function eligible(img) {
    if (!img || img.tagName !== 'IMG' || !srcOf(img) || /^data:/.test(srcOf(img)) || /\.svg(\?|$)/i.test(srcOf(img))) return false;
    if (img.closest(EXCLUDE)) return false;
    var r = img.getBoundingClientRect();
    return r.width >= 110 && r.height >= 80;
  }
  function visible(img) {
    var r = img.getBoundingClientRect(), cs = getComputedStyle(img);
    if (r.width < 2 || cs.visibility === 'hidden' || cs.display === 'none') return false;
    // diaporama de fiche : une seule image est affichée à la fois (les autres sont transparentes ou hors format)
    var slide = img.closest('.pg-slide');
    return !slide || (getComputedStyle(slide).visibility !== 'hidden' && getComputedStyle(slide).display !== 'none');
  }
  function filename(src) {
    try { var p = new URL(src, location.href).pathname.split('/').pop(); return decodeURIComponent(p) || 'image'; } catch (e) { return 'image'; }
  }

  /* ---- boutons au survol ---- */
  function ensureTools() {
    if (tools) return tools;
    tools = el('div', 'lb-ui lb-tools');
    var zoom = el('button', 'lb-btn', '⤢'); zoom.type = 'button'; zoom.title = 'Agrandir'; zoom.setAttribute('aria-label', 'Agrandir l\'image');
    var dl = el('a', 'lb-btn', '⬇'); dl.title = 'Télécharger'; dl.setAttribute('aria-label', 'Télécharger l\'image'); dl.setAttribute('download', '');
    zoom.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (current) open(current); });
    dl.addEventListener('click', function (e) { e.stopPropagation(); });
    tools.appendChild(zoom); tools.appendChild(dl);
    tools.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
    tools.addEventListener('mouseleave', scheduleHide);
    document.body.appendChild(tools);
    return tools;
  }
  function show(img) {
    clearTimeout(hideTimer);
    current = img;
    var t = ensureTools(), r = img.getBoundingClientRect();
    var top = Math.max(r.top, 0) + 8, right = Math.min(r.right, window.innerWidth);
    t.style.top = top + 'px';
    t.style.left = Math.max(right - 8 - 72, 4) + 'px';
    var dl = t.querySelector('a');
    dl.href = srcOf(img); dl.setAttribute('download', filename(srcOf(img)));
    t.classList.add('is-on');
  }
  function hide() { if (tools) tools.classList.remove('is-on'); current = null; }
  function scheduleHide() { clearTimeout(hideTimer); hideTimer = setTimeout(hide, 160); }

  if (hoverOK) {
    document.addEventListener('mouseover', function (e) {
      var img = e.target;
      if (img && img.tagName === 'IMG' && img !== current && eligible(img) && visible(img)) show(img);
    });
    document.addEventListener('mouseout', function (e) {
      if (e.target === current) scheduleHide();
    });
    window.addEventListener('scroll', hide, { passive: true });
  }

  /* ---- fenêtre plein écran ---- */
  function collect(img) {
    var scope = img.closest('.pg-main, .gm-list, .dp-grid, .md-gallery, .lb-gallery');
    var list = scope ? Array.prototype.filter.call(scope.querySelectorAll('img'), function (i) { return !i.closest(EXCLUDE) && srcOf(i) && !/\.svg(\?|$)/i.test(srcOf(i)) && (scope.matches('.pg-main') ? visibleSlideImg(i) : true); }) : [img];
    var seen = {}; return list.filter(function (i) { var s = srcOf(i); if (seen[s]) return false; seen[s] = 1; return true; });
  }
  function visibleSlideImg(i) {
    var slide = i.closest('.pg-slide');
    if (!slide) return true;
    // images réservées à un format d'écran : seulement celles qui s'affichent ici
    return getComputedStyle(slide).display !== 'none';
  }
  function build() {
    box = el('div', 'lb-ui lb-box'); box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', 'Image en grand');
    var imgWrap = el('div', 'lb-stage');
    var big = el('img', 'lb-img'); big.alt = '';
    imgWrap.appendChild(big);
    var close = el('button', 'lb-btn lb-close', '✕'); close.type = 'button'; close.title = 'Fermer'; close.setAttribute('aria-label', 'Fermer');
    var dl = el('a', 'lb-btn lb-dl', '⬇ Télécharger'); dl.setAttribute('download', '');
    var prev = el('button', 'lb-btn lb-nav lb-prev', '‹'); prev.type = 'button'; prev.setAttribute('aria-label', 'Image précédente');
    var next = el('button', 'lb-btn lb-nav lb-next', '›'); next.type = 'button'; next.setAttribute('aria-label', 'Image suivante');
    var cap = el('div', 'lb-cap');
    box.appendChild(imgWrap); box.appendChild(close); box.appendChild(dl); box.appendChild(prev); box.appendChild(next); box.appendChild(cap);
    close.addEventListener('click', closeBox);
    prev.addEventListener('click', function (e) { e.stopPropagation(); go(-1); });
    next.addEventListener('click', function (e) { e.stopPropagation(); go(1); });
    big.addEventListener('click', function (e) { e.stopPropagation(); });
    box.addEventListener('click', function (e) { if (e.target === box || e.target === imgWrap) closeBox(); });
    document.body.appendChild(box);
  }
  function render() {
    var img = group[index], big = box.querySelector('.lb-img'), src = srcOf(img);
    big.src = src; big.alt = img.alt || '';
    var dl = box.querySelector('.lb-dl'); dl.href = src; dl.setAttribute('download', filename(src));
    box.querySelector('.lb-cap').textContent = (img.alt || '') + (group.length > 1 ? '  ·  ' + (index + 1) + ' / ' + group.length : '');
    box.classList.toggle('is-multi', group.length > 1);
  }
  function go(d) { index = (index + d + group.length) % group.length; render(); }
  function open(img) {
    hide();
    lastFocus = document.activeElement;
    if (!box) build();
    group = collect(img); index = Math.max(group.indexOf(img), 0);
    if (!group.length) { group = [img]; index = 0; }
    render();
    box.classList.add('is-open');
    document.documentElement.classList.add('lb-lock');
    box.querySelector('.lb-close').focus();
  }
  function closeBox() {
    if (!box) return;
    box.classList.remove('is-open');
    document.documentElement.classList.remove('lb-lock');
    box.querySelector('.lb-img').removeAttribute('src');
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
  }
  document.addEventListener('keydown', function (e) {
    if (!box || !box.classList.contains('is-open')) return;
    if (e.key === 'Escape') { e.preventDefault(); closeBox(); }
    else if (e.key === 'ArrowLeft' && group.length > 1) { e.preventDefault(); go(-1); }
    else if (e.key === 'ArrowRight' && group.length > 1) { e.preventDefault(); go(1); }
    else if (e.key === 'Tab') {
      var f = box.querySelectorAll('button, a[href]'); f = Array.prototype.filter.call(f, function (n) { return n.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
})();
