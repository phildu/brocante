</main>
<?php if ($__viewFooter = shop_view('footer')): include $__viewFooter; else: ?>
<footer class="site">
  <div class="wrap footer-grid">
    <div>
      <p class="mark" style="font-family:var(--font-display);font-size:1.2rem;font-weight:600;"><?= h($siteName ?? tenant('name')) ?></p>
      <p class="lede" style="margin-top:10px;font-size:0.9rem;"><?= h($siteTagline ?? '') ?></p>
    </div>
    <div>
      <h4>Boutique</h4>
      <ul>
        <li><a href="/boutique.php">Toute la boutique</a></li>
        <?php foreach (category_list() as $c): ?>
          <li><a href="/boutique.php?cat=<?= h($c['key']) ?>"><?= h($c['label']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div>
      <h4>Maison</h4>
      <ul>
        <li><a href="/notre-histoire.php">Notre histoire</a></li>
        <li><a href="/visite.php">Visite virtuelle</a></li>
        <?php if (shop_has_public_donations()): ?><li><a href="/dons.php">Dons &amp; invendus du vide-grenier</a></li><?php endif; ?>
        <li><a href="/index.php#contact">Contact</a></li>
        <li><a href="/nous-trouver.php">Carte et itinéraire</a></li>
        <?php if (!function_exists('shop_member_galleries')) require_once __DIR__ . '/galleries.php'; foreach (shop_member_galleries() as $g): ?>
          <li><a href="<?= h($g['url']) ?>">Galerie « <?= h($g['name']) ?> »</a></li>
        <?php endforeach; ?>
        <li><a href="/admin/" class="footer-admin-link">Administration</a></li>
      </ul>
    </div>
  </div>
  <?php if (($footerNote = shop_footer_note()) !== ''): ?>
    <div class="wrap footer-note"><p><?= h($footerNote) ?></p></div>
  <?php endif; ?>
  <div class="wrap footer-bottom">
    <span>© 2026 <?= h($siteName ?? tenant('name')) ?></span>
    <?= share_button_html(shop_abs_url('/'), (string) ($siteName ?? tenant('name')), (string) ($siteTagline ?? ''), '', 'share-link') ?>
    <span>Prototype de boutique — déploiement local</span>
  </div>
</footer>
<?php endif; ?>
<?php require_once __DIR__ . '/payment-badges.php'; ?>
<?php if (($__paymentBadges = payment_badges_html()) !== ''): ?>
  <div class="wrap payment-badges-bar" style="display:flex;justify-content:center;padding:18px 0 4px;"><?= $__paymentBadges ?></div>
<?php endif; ?>
<?php require_once __DIR__ . '/legal.php'; ?>
<nav class="legal-bar" aria-label="Informations légales"><div class="wrap"><?= legal_links_html() ?></div></nav>
<div class="quick-preview-overlay" id="quick-preview-overlay" aria-hidden="true">
  <div class="quick-preview-media" id="quick-preview-media"></div>
  <span id="quick-preview-name"></span>
</div>
<?= plugin_render('site_footer') ?>
<?php require_once __DIR__ . '/slideshow-idle.php'; echo slideshow_idle_html(); ?>
<?php require_once __DIR__ . '/feedback.php'; echo feedback_widget_html(); ?>
<script>
(function () {
  // Écran de bienvenue (accueil, mobile) — une seule fois par session, pour
  // ne pas ré-imposer l'animation à chaque retour sur la page d'accueil.
  var splash = document.getElementById('welcome-splash');
  if (!splash) return;
  if (!window.matchMedia('(max-width: 879px)').matches) return;

  var alreadySeen = false;
  try { alreadySeen = sessionStorage.getItem('splashSeen') === '1'; } catch (e) {}
  if (alreadySeen) {
    splash.style.display = 'none';
    return;
  }
  try { sessionStorage.setItem('splashSeen', '1'); } catch (e) {}

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.body.style.overflow = 'hidden';
  setTimeout(function () {
    splash.classList.add('is-hidden');
    document.body.style.overflow = '';
    setTimeout(function () { splash.style.display = 'none'; }, 650);
  }, reduceMotion ? 500 : 1800);
})();

(function () {
  var btn = document.getElementById('burger-btn');
  var nav = document.getElementById('primary-nav');
  var overlay = document.getElementById('nav-overlay');
  if (!btn || !nav || !overlay) return;

  function closeMenu() {
    btn.classList.remove('is-open');
    nav.classList.remove('is-open');
    overlay.classList.remove('is-open');
    btn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }
  function openMenu() {
    btn.classList.add('is-open');
    nav.classList.add('is-open');
    overlay.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  btn.addEventListener('click', function () {
    if (nav.classList.contains('is-open')) closeMenu(); else openMenu();
  });
  overlay.addEventListener('click', closeMenu);
  nav.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', closeMenu); });
  window.addEventListener('resize', function () {
    if (window.innerWidth > 780) closeMenu();
  });
})();

(function () {
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion) return;
  var groups = document.querySelectorAll('.thumb-autoplay');
  groups.forEach(function (group, gi) {
    var slides = group.querySelectorAll('.thumb-slide');
    if (slides.length < 2) return;
    var current = 0;
    setInterval(function () {
      slides[current].classList.remove('is-active');
      current = (current + 1) % slides.length;
      slides[current].classList.add('is-active');
    }, 2600 + (gi % 5) * 150);
  });
})();

(function () {
  // Aperçu quasi plein écran au survol d'une vignette — uniquement sur les
  // appareils avec un vrai pointeur/hover (souris), pas au toucher, où le
  // survol simulé par le navigateur laisserait l'aperçu bloqué ouvert.
  // Fait défiler automatiquement toutes les photos du produit (pas une seule),
  // avec le même principe de fondu que le carrousel des vignettes.
  if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

  var overlay = document.getElementById('quick-preview-overlay');
  var media = document.getElementById('quick-preview-media');
  var nameEl = document.getElementById('quick-preview-name');
  var showTimer = null;
  var cycleTimer = null;

  function stopCycle() {
    clearInterval(cycleTimer);
    cycleTimer = null;
  }

  function startCycle(slides) {
    if (slides.length < 2) return;
    var current = 0;
    cycleTimer = setInterval(function () {
      slides[current].classList.remove('is-active');
      current = (current + 1) % slides.length;
      slides[current].classList.add('is-active');
    }, 1800);
  }

  document.querySelectorAll('[data-quick-gallery]').forEach(function (el) {
    el.addEventListener('mouseenter', function () {
      clearTimeout(showTimer);
      showTimer = setTimeout(function () {
        var items;
        try { items = JSON.parse(el.dataset.quickGallery); } catch (e) { items = []; }
        if (!items.length) return;

        media.textContent = '';
        items.forEach(function (it, i) {
          var slide;
          if (it.type === 'video') {
            slide = document.createElement('video');
            slide.src = '/' + it.src;
            slide.autoplay = true;
            slide.muted = true;
            slide.loop = true;
            slide.playsInline = true;
          } else {
            slide = document.createElement('img');
            slide.src = '/' + it.src;
            slide.alt = '';
          }
          slide.className = 'quick-preview-slide' + (i === 0 ? ' is-active' : '');
          media.appendChild(slide);
        });

        nameEl.textContent = el.dataset.quickName || '';
        overlay.classList.add('is-visible');
        startCycle(media.querySelectorAll('.quick-preview-slide'));
      }, 180);
    });
    el.addEventListener('mouseleave', function () {
      clearTimeout(showTimer);
      stopCycle();
      overlay.classList.remove('is-visible');
    });
  });
})();

(function () {
  // Fait passer les polaroïds du hero les uns devant les autres à tour de
  // rôle : on fait tourner les classes de position (p1 = devant) entre les
  // cadres, avec une transition CSS sur top/left/transform/z-index déjà
  // définie dans style.css — donc ici on ne fait que réassigner les classes.
  // Chaque carte porte son propre délai (data-dwell, en ms) : la promo reste
  // plus longtemps devant que les autres. Cliquer une carte qui n'est pas
  // devant l'y ramène immédiatement (et empêche la navigation du lien
  // qu'elle contient, le clic sert d'abord à la mettre en avant).
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var slots = ['p1', 'p2', 'p3'];

  document.querySelectorAll('.hero-polaroid-scene').forEach(function (scene) {
    var frames = Array.prototype.slice.call(scene.querySelectorAll('.polaroid-frame'));
    if (frames.length < 2) return;
    var order = frames.map(function (_, i) { return i; });
    var timer = null;

    function applySlots() {
      // Seules les 3 premières cartes de l'ordre courant occupent une
      // position visible (p1/p2/p3) ; les suivantes patientent hors-champ
      // (is-waiting) jusqu'à ce que la rotation les fasse remonter.
      order.forEach(function (frameIndex, slotIndex) {
        var frame = frames[frameIndex];
        slots.forEach(function (s) { frame.classList.remove(s); });
        frame.classList.remove('is-waiting');
        if (slotIndex < slots.length) {
          frame.classList.add(slots[slotIndex]);
        } else {
          frame.classList.add('is-waiting');
        }
      });
    }

    function frontDwell() {
      var dwell = parseInt(frames[order[0]].dataset.dwell, 10);
      return dwell > 0 ? dwell : 4200;
    }

    function scheduleNext() {
      if (reduceMotion) return;
      clearTimeout(timer);
      timer = setTimeout(function () {
        order.unshift(order.pop());
        applySlots();
        scheduleNext();
      }, frontDwell());
    }

    frames.forEach(function (frame, idx) {
      frame.addEventListener('click', function (e) {
        if (frame.classList.contains('p1')) return;
        e.preventDefault();
        order.splice(order.indexOf(idx), 1);
        order.unshift(idx);
        applySlots();
        scheduleNext();
      });
    });

    scheduleNext();
  });
})();
</script>
<script>
(function () {
  // Hero carrousel : la piste défile horizontalement (scroll-snap), les
  // flèches et les repères la font avancer d'une slide. Le repère actif suit
  // la slide la plus proche du centre de la piste.
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.querySelectorAll('[data-hero-carousel]').forEach(function (root) {
    var track = root.querySelector('.shop-hero-track');
    if (!track) return;
    var slides = Array.prototype.slice.call(track.children);
    if (slides.length === 0) return;
    var prev = root.querySelector('[data-hero-prev]');
    var next = root.querySelector('[data-hero-next]');
    var dots = Array.prototype.slice.call(root.querySelectorAll('[data-hero-dot]'));
    var scheduled = false;

    function activeIndex() {
      var box = track.getBoundingClientRect();
      var mid = box.left + track.clientWidth / 2;
      var best = 0, bestDistance = Infinity;
      slides.forEach(function (slide, i) {
        var r = slide.getBoundingClientRect();
        var d = Math.abs(r.left + r.width / 2 - mid);
        if (d < bestDistance) { bestDistance = d; best = i; }
      });
      return best;
    }

    function goTo(i) {
      var slide = slides[Math.max(0, Math.min(slides.length - 1, i))];
      track.scrollTo({ left: slide.offsetLeft - slides[0].offsetLeft, behavior: reduceMotion ? 'auto' : 'smooth' });
    }

    function sync() {
      scheduled = false;
      var i = activeIndex();
      dots.forEach(function (dot, j) { dot.setAttribute('aria-current', String(i === j)); });
      if (prev) prev.disabled = track.scrollLeft <= 2;
      if (next) next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
    }

    function queueSync() {
      if (scheduled) return;
      scheduled = true;
      window.requestAnimationFrame(sync);
    }

    if (prev) prev.addEventListener('click', function () { goTo(activeIndex() - 1); });
    if (next) next.addEventListener('click', function () { goTo(activeIndex() + 1); });
    dots.forEach(function (dot) {
      dot.addEventListener('click', function () { goTo(parseInt(dot.dataset.heroDot, 10) || 0); });
    });
    track.addEventListener('scroll', queueSync, { passive: true });
    window.addEventListener('resize', queueSync);
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); goTo(activeIndex() - 1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); goTo(activeIndex() + 1); }
    });
    sync();
  });
})();
</script>
<script src="/assets/lightbox.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/lightbox.js') ?>" defer></script>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
