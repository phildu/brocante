<?php
require_once __DIR__ . '/includes/functions.php';

$content = get_content();
$orientation = ($_GET['orientation'] ?? $content['slideshow_orientation']) === 'vertical' ? 'vertical' : 'horizontal';
$embed = !empty($_GET['embed']);

$slides = slideshow_public_slides();
?><!DOCTYPE html>
<html lang="fr" data-orientation="<?= h($orientation) ?>" data-embed="<?= $embed ? '1' : '0' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diaporama — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; width: 100%; height: 100%; background: #000; overflow: hidden; font-family: "Archivo", ui-sans-serif, system-ui, sans-serif; }

  .slide { position: fixed; inset: 0; opacity: 0; visibility: hidden; transition: opacity 0.9s ease; }
  .slide.is-active { opacity: 1; visibility: visible; }
  .slide-media { width: 100%; height: 100%; object-fit: cover; display: block; background: #000; }

  .slide-caption {
    position: absolute; left: 0; right: 0; bottom: 0;
    padding: 3vw 4vw; background: linear-gradient(transparent, rgba(0,0,0,0.75));
    color: #fff; font-family: "Special Elite", monospace; font-size: 1.6vw; letter-spacing: 0.02em;
  }

  .slide-product { display: flex; width: 100%; height: 100%; background: #f4eee1; }
  html[data-orientation="vertical"] .slide-product { flex-direction: column; }
  html[data-orientation="horizontal"] .slide-product { flex-direction: row; }
  .slide-product-media { flex: 1.2; position: relative; overflow: hidden; background: #e2d5b4; }
  .slide-product-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .slide-product-info { flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 1.6vw; padding: 5vw; color: #2e2418; }
  .slide-product-badge {
    align-self: flex-start; font-family: "Special Elite", monospace; text-transform: uppercase;
    letter-spacing: 0.08em; font-size: 1.1vw; padding: 0.5vw 1.2vw; border: 2px solid #b5502e; color: #b5502e;
  }
  .slide-product-name { font-family: "Fraunces", serif; font-size: 3.4vw; line-height: 1.1; margin: 0; }
  .slide-product-desc { font-size: 1.3vw; color: #6b5c48; line-height: 1.5; max-width: 42ch; margin: 0; }
  .slide-product-price { font-family: "Special Elite", monospace; font-size: 2.6vw; margin: 0; }
  .slide-product-price .old { text-decoration: line-through; color: #6b5c48; font-size: 0.6em; margin-right: 0.6vw; }

  .slide-product.is-promo { background: #fbeee2; }
  .slide-product.is-promo .slide-product-badge { background: #b5502e; border-color: #b5502e; color: #fff; }
  .slide-product-kicker {
    align-self: flex-start; margin: 0 0 -0.8vw; font-family: "Special Elite", monospace; text-transform: uppercase;
    letter-spacing: 0.1em; font-size: 1.05vw; color: #b5502e;
  }
  .slide-product-price .now { color: #b5502e; }
  .slide-promo-ribbon {
    position: absolute; top: 2vw; left: 2vw; z-index: 2; background: #b5502e; color: #fff;
    font-family: "Special Elite", monospace; font-size: 2vw; line-height: 1; padding: 1vw 1.4vw;
    box-shadow: 0 4px 18px rgba(0,0,0,0.25);
  }

  .embed-hint {
    position: fixed; left: 50%; bottom: 2.4vh; transform: translateX(-50%); z-index: 9;
    padding: 0.7rem 1.2rem; background: rgba(0,0,0,0.62); color: rgba(255,255,255,0.9);
    border: 1px solid rgba(255,255,255,0.28); border-radius: 999px; pointer-events: none;
    font-family: "Archivo", sans-serif; font-size: 0.95rem; white-space: nowrap;
    animation: embed-hint-fade 1.6s ease 7s forwards;
  }
  @keyframes embed-hint-fade { to { opacity: 0; } }

  .empty-msg {
    display: flex; align-items: center; justify-content: center; height: 100%;
    color: #fff; font-family: "Archivo", sans-serif; text-align: center; padding: 40px; line-height: 1.6;
  }

  .fullscreen-btn {
    position: fixed; top: 24px; right: 24px; z-index: 10;
    background: rgba(0,0,0,0.55); color: #fff; border: 1px solid rgba(255,255,255,0.4);
    font-family: "Archivo", sans-serif; font-size: 0.85rem; padding: 10px 16px; cursor: pointer;
  }
  .fullscreen-btn.is-hidden { display: none; }
</style>
</head>
<body>

<?php if (!$slides): ?>
  <div class="empty-msg">Aucune diapositive configurée pour l'instant.<br>Administration → Diaporama boutique.</div>
<?php else: ?>
  <?php foreach ($slides as $i => $d): $s = $d['slide']; $p = $d['product']; ?>
    <div class="slide<?= $i === 0 ? ' is-active' : '' ?>" data-duration="<?= (int) $s['duration_seconds'] ?>" data-kind="<?= h($s['kind']) ?>">
      <?php if ($s['kind'] === 'video'): ?>
        <video class="slide-media" src="/<?= h($s['path']) ?>" muted playsinline<?= $i === 0 ? ' autoplay' : '' ?>></video>
        <?php if ($s['caption'] !== ''): ?><div class="slide-caption"><?= h($s['caption']) ?></div><?php endif; ?>
      <?php elseif ($s['kind'] === 'ambiance'): ?>
        <img class="slide-media" src="/<?= h($s['path']) ?>" alt="">
        <?php if ($s['caption'] !== ''): ?><div class="slide-caption"><?= h($s['caption']) ?></div><?php endif; ?>
      <?php else: /* product */ $isPromo = !empty($d['promo']); ?>
        <div class="slide-product<?= $isPromo ? ' is-promo' : '' ?>">
          <div class="slide-product-media">
            <?php if (!empty($p['photo'])): ?><img src="/<?= h($p['photo']) ?>" alt="<?= h($p['name']) ?>"><?php endif; ?>
            <?php if ($isPromo): ?><span class="slide-promo-ribbon"><?= (int) ($d['discount'] ?? 0) > 0 ? '−' . (int) $d['discount'] . ' %' : 'Promo' ?></span><?php endif; ?>
          </div>
          <div class="slide-product-info">
            <?php if ($isPromo): ?><p class="slide-product-kicker">Offre en cours</p><?php endif; ?>
            <?php if (!$isPromo && !empty($p['badge']) && (shop_sales_enabled() || !has_promo_price($p))): ?><span class="slide-product-badge"><?= h($p['badge']) ?></span><?php endif; ?>
            <h2 class="slide-product-name"><?= h($p['name']) ?></h2>
            <?php if (!empty($p['description'])): ?><p class="slide-product-desc"><?= h($p['description']) ?></p><?php endif; ?>
            <?php if (shop_sales_enabled()): ?><p class="slide-product-price">
              <?php if (has_promo_price($p)): ?><span class="old"><?= h($p['price']) ?></span><span class="now"><?= h($p['promo_price']) ?></span>
              <?php else: ?><?= h($p['price']) ?><?php endif; ?>
            </p><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($embed): ?><div class="embed-hint">Touchez l'écran pour revenir à la boutique</div><?php endif; ?>
<button type="button" class="fullscreen-btn" id="fullscreen-btn">Plein écran</button>

<script>
(function () {
  var slides = Array.prototype.slice.call(document.querySelectorAll('.slide'));
  var fsBtn = document.getElementById('fullscreen-btn');
  var embed = document.documentElement.dataset.embed === '1';

  function leaveEmbed() {
    if (!embed || window.parent === window) return;
    try { window.parent.postMessage({ type: 'brocs-kiosk:close' }, window.location.origin); } catch (err) {}
  }
  if (embed) {
    document.addEventListener('click', function (e) {
      if (e.target.closest('.fullscreen-btn')) return;
      leaveEmbed();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') leaveEmbed();
    });
  }

  function goFullscreen() {
    var el = document.documentElement;
    if (el.requestFullscreen) el.requestFullscreen().catch(function () {});
    fsBtn.classList.add('is-hidden');
  }
  fsBtn.addEventListener('click', goFullscreen);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'f' || e.key === 'F') goFullscreen();
    if (slides.length > 1 && e.key === 'ArrowRight') activate(current + 1);
    if (slides.length > 1 && e.key === 'ArrowLeft') activate(current - 1 + slides.length);
  });

  if (slides.length < 1) return;

  var current = 0;
  var timer = null;

  function activate(index) {
    var prevSlide = slides[current];
    var prevVideo = prevSlide.querySelector('video');
    if (prevVideo) { prevVideo.pause(); prevVideo.currentTime = 0; }
    prevSlide.classList.remove('is-active');

    current = index % slides.length;
    var slide = slides[current];
    slide.classList.add('is-active');

    var video = slide.querySelector('video');
    if (video) {
      video.currentTime = 0;
      video.play().catch(function () {});
    }
    scheduleNext(slide);
  }

  function scheduleNext(slide) {
    clearTimeout(timer);
    var duration = parseInt(slide.dataset.duration, 10) || 0;
    var video = slide.querySelector('video');

    // Durée 0 sur une vidéo : on attend la fin de la lecture plutôt qu'un
    // minuteur fixe. Sinon (image, fiche produit, ou vidéo à durée forcée),
    // minuteur classique.
    if (video && duration <= 0) {
      video.addEventListener('ended', function onEnded() {
        video.removeEventListener('ended', onEnded);
        activate(current + 1);
      });
      return;
    }
    var ms = (duration > 0 ? duration : 8) * 1000;
    timer = setTimeout(function () { activate(current + 1); }, ms);
  }

  if (slides.length > 1) {
    scheduleNext(slides[0]);
  } else {
    var onlyVideo = slides[0].querySelector('video');
    if (onlyVideo) {
      onlyVideo.loop = true;
      onlyVideo.play().catch(function () {});
    }
  }
})();
</script>
</body>
</html>
