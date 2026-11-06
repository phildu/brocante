<?php

// Bandeau d'en-tête avec une ou plusieurs images : les images sont empilées dans .hero-media et un fondu
// enchaîné les fait défiler. Partagé par l'accueil du portail (accueil/index.php) et les pages de galerie
// (galerie/index.php). Le CSS (.hero-media > i) est fourni par chaque page ; le script est inséré une fois.

/** Chemins d'images valides, sans doublon, six au plus. */
function banner_slide_images(array $images): array
{
    $out = [];
    foreach ($images as $image) {
        $image = (string) $image;
        if ($image === '' || in_array($image, $out, true)) continue;
        $out[] = $image;
        if (count($out) >= 6) break;
    }
    return $out;
}

/** Couches <div class="hero-media"> + voile d'assombrissement ; '' sans image. */
function banner_slides_html(array $images, int $overlay, string $pos = 'center', bool $animate = true): string
{
    $images = banner_slide_images($images);
    if (!$images) return '';
    $pos = in_array($pos, ['top', 'center', 'bottom'], true) ? $pos : 'center';
    $html = '<div class="hero-media banner-slides"' . ($animate ? '' : ' data-static="1"') . ' aria-hidden="true">';
    foreach ($images as $i => $image) {
        $html .= '<i' . ($i === 0 ? ' class="is-on"' : '') . ' style="background-image:url(\''
            . htmlspecialchars('/' . $image, ENT_QUOTES, 'UTF-8') . '\');background-position:center ' . $pos . '"></i>';
    }
    return $html . '</div><div class="hero-shade" style="background:rgba(0,0,0,' . round(max(0, min(80, $overlay)) / 100, 2) . ')"></div>';
}

/** Script du diaporama (une seule fois par page) ; respecte prefers-reduced-motion. */
function banner_slides_script(): string
{
    static $done = false;
    if ($done) return '';
    $done = true;
    return <<<'HTML'
<script>
(function () {
  if (!window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  document.querySelectorAll('.banner-slides').forEach(function (box) {
    if (box.dataset.static) return;
    var slides = box.querySelectorAll('i');
    if (slides.length < 2) return;
    var at = 0, timer = null;
    function next() {
      slides[at].classList.remove('is-on');
      at = (at + 1) % slides.length;
      slides[at].classList.add('is-on');
    }
    function start() { if (!timer) timer = setInterval(next, 6000); }
    function stop() { clearInterval(timer); timer = null; }
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
    start();
  });
})();
</script>
HTML;
}
