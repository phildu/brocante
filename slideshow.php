<?php
require_once __DIR__ . '/includes/functions.php';

$content = get_content();
$orientation = ($_GET['orientation'] ?? $content['slideshow_orientation']) === 'vertical' ? 'vertical' : 'horizontal';

$slides = [];
foreach (slideshow_slides_list() as $s) {
    $data = slideshow_render_data($s);
    if ($data) $slides[] = $data;
}
?><!DOCTYPE html>
<html lang="fr" data-orientation="<?= h($orientation) ?>">
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
      <?php else: /* product */ ?>
        <div class="slide-product">
          <div class="slide-product-media">
            <?php if (!empty($p['photo'])): ?><img src="/<?= h($p['photo']) ?>" alt="<?= h($p['name']) ?>"><?php endif; ?>
          </div>
          <div class="slide-product-info">
            <?php if (!empty($p['badge'])): ?><span class="slide-product-badge"><?= h($p['badge']) ?></span><?php endif; ?>
            <h2 class="slide-product-name"><?= h($p['name']) ?></h2>
            <?php if (!empty($p['description'])): ?><p class="slide-product-desc"><?= h($p['description']) ?></p><?php endif; ?>
            <p class="slide-product-price">
              <?php if (has_promo_price($p)): ?>
                <span class="old"><?= h($p['price']) ?></span><?= h($p['promo_price']) ?>
              <?php else: ?>
                <?= h($p['price']) ?>
              <?php endif; ?>
            </p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<button type="button" class="fullscreen-btn" id="fullscreen-btn">Plein écran</button>

<script>
(function () {
  var slides = Array.prototype.slice.call(document.querySelectorAll('.slide'));
  var fsBtn = document.getElementById('fullscreen-btn');

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
