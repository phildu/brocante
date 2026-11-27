/*
 * Animations des pages publiques (accueil, boutique, modèles) avec Motion (assets/lib/motion, licence MIT).
 * - Les blocs situés plus bas que l'écran apparaissent quand on y arrive ; dans une grille, les cartes arrivent l'une après l'autre.
 * - Survol (souris seulement) : la carte se soulève légèrement.
 * - Photo de « Notre histoire » : léger effet de profondeur au défilement.
 * Rien n'est caché tant que Motion n'est pas chargé, et rien ne bouge si le visiteur a demandé de réduire les animations.
 */
(function () {
  var M = window.Motion;
  if (!M || !M.animate || !M.inView) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // Courbe en S : départ doux, arrivée douce.
  var EASE = [0.45, 0, 0.15, 1];
  var viewH = window.innerHeight || document.documentElement.clientHeight;

  function belowFold(el) {
    return el.getBoundingClientRect().top > viewH * 0.92;
  }

  var pending = [];

  function showNow(el) {
    el.style.opacity = '';
    el.style.transform = '';
  }

  function reveal(el, delay) {
    if (el.dataset.motionDone || !belowFold(el)) return;
    el.dataset.motionDone = '1';
    el.style.opacity = '0';
    el.style.transform = 'translateY(28px)';
    pending.push(el);
    var stop = M.inView(el, function () {
      stop();
      pending.splice(pending.indexOf(el), 1);
      M.animate(el, { opacity: [0, 1], transform: ['translateY(28px)', 'translateY(0px)'] }, { duration: 0.7, delay: delay || 0, ease: EASE });
    }, { amount: 0.15 });
  }

  // Saut rapide (lien vers #contact, touche Fin) : un bloc déjà dépassé s'affiche aussitôt, il ne reste jamais invisible.
  var ticking = false;
  window.addEventListener('scroll', function () {
    if (ticking || !pending.length) return;
    ticking = true;
    requestAnimationFrame(function () {
      ticking = false;
      pending = pending.filter(function (el) {
        if (el.getBoundingClientRect().bottom > 0) return true;
        showNow(el);
        return false;
      });
    });
  }, { passive: true });
  // Impression : tout est visible.
  window.addEventListener('beforeprint', function () { pending.forEach(showNow); pending = []; });

  // Grilles : les éléments d'une même rangée arrivent en décalé, de gauche à droite.
  var GRIDS = '.grid-products, .cat-grid, .principles, .follow-grid';
  document.querySelectorAll(GRIDS).forEach(function (grid) {
    var rowTop = null, col = 0;
    Array.prototype.forEach.call(grid.children, function (child) {
      var top = child.offsetTop;
      col = (top === rowTop) ? col + 1 : 0;
      rowTop = top;
      reveal(child, Math.min(col, 5) * 0.08);
    });
  });

  // Blocs isolés : titres de section, textes, contact.
  var BLOCKS = '.section-head, .story-grid > div:first-child, #impact .wrap, .follow > .wrap > .eyebrow, .follow > .wrap > h2, .contact-grid > div, .featured-foot';
  document.querySelectorAll(BLOCKS).forEach(function (el, i) {
    reveal(el, el.matches('.contact-grid > div') ? (i % 2) * 0.12 : 0);
  });

  // Survol : la carte se soulève, avec un ressort (ordinateur seulement).
  if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    document.querySelectorAll('.card, .cat-tile').forEach(function (el) {
      var spring = { type: 'spring', stiffness: 320, damping: 22 };
      el.addEventListener('pointerenter', function () {
        M.animate(el, { transform: 'translateY(-4px)', boxShadow: '0 12px 28px rgba(20,15,8,0.12)' }, spring);
      });
      el.addEventListener('pointerleave', function () {
        M.animate(el, { transform: 'translateY(0px)', boxShadow: '0 0 0 rgba(20,15,8,0)' }, spring);
      });
    });
  }

  // Profondeur : la photo de l'histoire défile un peu moins vite que la page.
  if (M.scroll) {
    document.querySelectorAll('.story-photo img').forEach(function (img) {
      M.scroll(M.animate(img, { transform: ['translateY(-14px)', 'translateY(14px)'] }, { ease: 'linear' }), {
        target: img,
        offset: ['start end', 'end start']
      });
    });
  }
})();
