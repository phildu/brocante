<?php

require_once __DIR__ . '/templates.php';
require_once __DIR__ . '/share.php';

/**
 * Slides exploitables par les heros autres que la pile de polaroïds : promos,
 * coups de cœur et photos d'ambiance, avec leur lien et leur légende.
 * $fallback : image de secours (photo d'histoire) quand aucune slide n'existe.
 */
function shop_hero_slides(array $items, ?string $fallback = null): array
{
    $slides = [];
    foreach ($items as $item) {
        $type = (string) ($item['type'] ?? '');
        $data = $item['data'] ?? null;
        if ($type === 'promo' && is_array($data)) {
            $img = shop_cover($data);
            if (!$img) continue;
            $tag = has_promo_price($data) && shop_sales_enabled()
                ? trim($data['price'] . ' → ' . $data['promo_price'])
                : 'Promo';
            $slides[] = [
                'img' => $img,
                'href' => '/produit.php?ref=' . urlencode((string) $data['ref']),
                'tag' => $tag . ' · ' . (string) $data['name'],
                'caption' => (string) $data['name'],
                'type' => 'promo',
            ];
        } elseif ($type === 'featured' && is_array($data)) {
            $img = shop_cover($data);
            if (!$img) continue;
            $slides[] = [
                'img' => $img,
                'href' => '/produit.php?ref=' . urlencode((string) $data['ref']),
                'tag' => 'Coup de cœur · ' . (string) $data['name'],
                'caption' => (string) $data['name'],
                'type' => 'featured',
            ];
        } elseif ($type === 'ambiance' && is_array($data) && !empty($data['path'])) {
            $slides[] = [
                'img' => (string) $data['path'],
                'href' => '',
                'tag' => '',
                'caption' => (string) ($data['caption'] ?? ''),
                'type' => 'ambiance',
            ];
        }
    }
    if (!$slides && $fallback) {
        $slides[] = ['img' => $fallback, 'href' => '', 'tag' => '', 'caption' => '', 'type' => 'ambiance'];
    }
    return $slides;
}

/** Calque du bandeau de page (photo/vidéo + voile), capturé pour être inséré dans le hero. */
function shop_hero_banner_html(): string
{
    ob_start();
    render_page_banner('accueil');
    return (string) ob_get_clean();
}

/** Boutons d'appel à l'action communs aux heros. $light : posés sur une photo sombre. */
function shop_hero_ctas(array $content, bool $light = false): string
{
    $share = share_page_button((string) ($content['site_name'] ?: tenant('name')), (string) ($content['hero_subtitle'] ?? ''));
    return '<div class="hero-ctas shop-hero-ctas">'
        . '<a class="btn btn-primary" href="/boutique.php">Voir la boutique</a>'
        . '<a class="btn btn-ghost" href="#histoire">Notre histoire</a>'
        . '<span class="shop-hero-share' . ($light ? ' is-light' : '') . '">' . $share . '</span>'
        . '</div>';
}

/** Accroche texte du hero (eyebrow, titre, sous-titre, boutons). */
function shop_hero_copy(array $content, bool $light = false): string
{
    return '<div class="shop-hero-copy' . ($light ? ' is-light' : '') . '">'
        . '<p class="eyebrow">' . h($content['hero_eyebrow'] ?? '') . '</p>'
        . '<h1>' . h($content['hero_title'] ?? '') . '</h1>'
        . '<p class="lede">' . h($content['hero_subtitle'] ?? '') . '</p>'
        . shop_hero_ctas($content, $light)
        . '</div>';
}

/** Pile de polaroïds d'origine : cartes en quinconce, rotation et clic gérés par includes/footer.php. */
function shop_hero_polaroid(array $items, array $content, bool $hasBanner): string
{
    $html = '<section class="hero shop-hero shop-hero--polaroid' . ($hasBanner ? ' has-page-banner' : '') . '">';
    if ($hasBanner) $html .= shop_hero_banner_html();
    $html .= '<div class="wrap hero-grid">' . shop_hero_copy($content) . '<div class="hero-scene hero-polaroid-scene">';
    foreach ($items as $fi => $item) {
        $posClass = ['p1', 'p2', 'p3'][$fi] ?? 'is-waiting';
        $extraClass = ($item['type'] ?? '') === 'promo' ? ' polaroid-promo' : '';
        $dwell = ($item['type'] ?? '') === 'promo' ? 7000 : 4200;
        $html .= '<div class="polaroid-frame' . $extraClass . ' ' . $posClass . '" data-dwell="' . $dwell . '">';
        if ($item['type'] === 'welcome') {
            $html .= '<div class="polaroid-welcome-inner"><span class="welcome-macaron"><img src="/' . h(logo_url('square')) . '" alt="' . h($content['site_name'] ?? '') . '"></span><p class="welcome-caption">Bienvenue</p></div>';
        } elseif ($item['type'] === 'promo') {
            $pr = $item['data'];
            $html .= '<a href="/produit.php?ref=' . urlencode((string) $pr['ref']) . '" class="hero-slide hero-slide-promo is-active">'
                . (!empty($pr['photo']) ? '<img src="/' . h($pr['photo']) . '" alt="' . h($pr['name']) . '">' : '')
                . '<span class="hero-promo-tag">'
                . (shop_sales_enabled() && has_promo_price($pr)
                    ? '<span class="price-old">' . h($pr['price']) . '</span> ' . h($pr['promo_price'])
                    : 'Promo')
                . ' · ' . h($pr['name']) . '</span></a>';
        } elseif ($item['type'] === 'featured') {
            $fp = $item['data'];
            $html .= '<a href="/produit.php?ref=' . urlencode((string) $fp['ref']) . '" class="hero-slide is-active">'
                . (!empty($fp['photo']) ? '<img src="/' . h($fp['photo']) . '" alt="' . h($fp['name']) . '">' : '')
                . '<span class="hero-featured-tag">Coup de cœur · ' . h($fp['name']) . '</span></a>';
        } else {
            $s = $item['data'];
            $html .= '<div class="hero-slide is-active"><img src="/' . h($s['path']) . '" alt="' . h($s['caption']) . '">'
                . ($s['caption'] !== '' ? '<span class="hero-slide-caption">' . h($s['caption']) . '</span>' : '')
                . '</div>';
        }
        $html .= '</div>';
    }
    return $html . '</div></div></section>';
}

/** Image plein cadre, texte posé dessus sur un voile dégradé. */
function shop_hero_plein(array $slides, array $content, bool $hasBanner): string
{
    $slide = $slides[0] ?? null;
    $class = 'hero shop-hero shop-hero--plein' . ($slide ? '' : ' is-empty') . ($hasBanner ? ' has-page-banner' : '');
    $html = '<section class="' . $class . '">';
    if ($hasBanner) {
        $html .= shop_hero_banner_html();
    } elseif ($slide) {
        $html .= '<img class="shop-hero-bg" src="/' . h($slide['img']) . '" alt="">';
        $html .= '<span class="shop-hero-veil"></span>';
    }
    $html .= '<div class="wrap shop-hero-plein-in">' . shop_hero_copy($content, (bool) ($slide || $hasBanner));
    if ($slide && !empty($slide['href'])) {
        $html .= '<a class="shop-hero-caption-link" href="' . h($slide['href']) . '">' . h($slide['tag'] ?: $slide['caption']) . ' ↗</a>';
    } elseif ($slide && $slide['caption'] !== '') {
        $html .= '<p class="shop-hero-caption">' . h($slide['caption']) . '</p>';
    }
    return $html . '</div></section>';
}

/** Texte d'un côté, grande image de l'autre (avec pastille de légende). */
function shop_hero_scinde(array $slides, array $content): string
{
    $slide = $slides[0] ?? null;
    if (!$slide) {
        return '<section class="hero shop-hero shop-hero--scinde is-empty"><div class="wrap">' . shop_hero_copy($content) . '</div></section>';
    }
    $img = '<img src="/' . h($slide['img']) . '" alt="">';
    $html = '<section class="hero shop-hero shop-hero--scinde"><div class="wrap shop-hero-grid">'
        . shop_hero_copy($content) . '<div class="shop-hero-figure">'
        . (!empty($slide['href']) ? '<a href="' . h($slide['href']) . '">' . $img . '</a>' : $img);
    if ($slide['tag'] !== '' || $slide['caption'] !== '') {
        $html .= '<span class="shop-hero-figure-tag">' . h($slide['tag'] ?: $slide['caption']) . '</span>';
    }
    return $html . '</div></div></section>';
}

/** Carrousel horizontal de slides, avec flèches et repères (JS dans footer.php). */
function shop_hero_carrousel(array $slides, array $content): string
{
    if (!$slides) return '<section class="hero shop-hero shop-hero--plein is-empty"><div class="wrap shop-hero-plein-in">' . shop_hero_copy($content) . '</div></section>';

    $html = '<section class="hero shop-hero shop-hero--carrousel"><div class="wrap shop-hero-carrousel-head">' . shop_hero_copy($content) . '</div>'
        . '<div class="shop-hero-carousel" data-hero-carousel>'
        . '<div class="shop-hero-track" tabindex="0" aria-label="Diaporama de la page d\'accueil">';
    foreach ($slides as $i => $s) {
        $inner = '<img src="/' . h($s['img']) . '" alt="' . h($s['caption']) . '"' . ($i === 0 ? '' : ' loading="lazy"') . '>'
            . ($s['tag'] !== '' ? '<span class="shop-hero-slide-tag">' . h($s['tag']) . '</span>' : '');
        $html .= '<div class="shop-hero-slide">' . ($s['href'] !== '' ? '<a href="' . h($s['href']) . '">' . $inner . '</a>' : $inner) . '</div>';
    }
    $html .= '</div><div class="shop-hero-carousel-bar">'
        . '<button type="button" class="shop-hero-arrow" data-hero-prev aria-label="Slide précédente">←</button>'
        . '<span class="shop-hero-dots">';
    foreach ($slides as $i => $_) {
        $html .= '<button type="button" data-hero-dot="' . $i . '" aria-label="Aller à la slide ' . ($i + 1) . '"></button>';
    }
    $html .= '</span><button type="button" class="shop-hero-arrow" data-hero-next aria-label="Slide suivante">→</button>'
        . '</div></div></section>';
    return $html;
}

/** Texte et trois images en composition asymétrique. */
function shop_hero_mosaique(array $slides, array $content): string
{
    $picks = array_slice($slides, 0, 3);
    if (!$picks) {
        return '<section class="hero shop-hero shop-hero--mosaique is-empty"><div class="wrap">' . shop_hero_copy($content) . '</div></section>';
    }
    $html = '<section class="hero shop-hero shop-hero--mosaique"><div class="wrap shop-hero-grid">' . shop_hero_copy($content) . '<div class="shop-hero-mosaic">';
    foreach ($picks as $i => $s) {
        $inner = '<img src="/' . h($s['img']) . '" alt="' . h($s['caption']) . '"' . ($i === 0 ? '' : ' loading="lazy"') . '>'
            . ($s['tag'] !== '' ? '<span class="shop-hero-slide-tag">' . h($s['tag']) . '</span>' : '');
        $html .= '<div class="shop-hero-mosaic-item m' . ($i + 1) . '">'
            . ($s['href'] !== '' ? '<a href="' . h($s['href']) . '">' . $inner . '</a>' : $inner) . '</div>';
    }
    return $html . '</div></div></section>';
}

/**
 * Rendu du hero de l'accueil selon le type actif. $items : rôles de la pile de
 * polaroïds (welcome, promo, featured, ambiance) ; $hasBanner : un bandeau de
 * page est configuré pour l'accueil (son contenu est imprimé par l'appelant).
 */
function shop_hero_html(string $type, array $items, array $content, bool $hasBanner = false): string
{
    if (!shop_hero_valid($type)) $type = 'polaroid';
    if ($type === 'polaroid') return shop_hero_polaroid($items, $content, $hasBanner);

    $fallback = !empty($content['story_photo']) ? (string) $content['story_photo'] : null;
    $slides = shop_hero_slides($items, $fallback);
    return match ($type) {
        'plein' => shop_hero_plein($slides, $content, $hasBanner),
        'scinde' => shop_hero_scinde($slides, $content),
        'carrousel' => shop_hero_carrousel($slides, $content),
        'mosaique' => shop_hero_mosaique($slides, $content),
        default => shop_hero_polaroid($items, $content, $hasBanner),
    };
}
