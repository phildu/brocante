<?php
// Page publique d'une galerie commerciale : /galerie/<identifiant>/ (réécrit vers ce fichier, voir .htaccess et router.php).
// Sans identifiant : la liste des galeries publiées. Les données viennent de includes/galleries.php (catalogue regroupé, mis en cache).
require_once __DIR__ . '/../includes/galleries.php';
require_once __DIR__ . '/../includes/gallery-admin.php';
require_once __DIR__ . '/../includes/gallery-offers.php';
require_once __DIR__ . '/../includes/gallery-loyalty.php';
require_once __DIR__ . '/../includes/gallery-booking.php';
require_once __DIR__ . '/../includes/gallery-bourse.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/promo.php';
require_once __DIR__ . '/../includes/banner-slides.php';
require_once __DIR__ . '/../includes/gallery-types.php';
require_once __DIR__ . '/../includes/cta.php';

function e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Couleur de texte lisible (blanc ou presque noir) sur un fond $hex. */
function gal_ink_on(string $hex): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#1c1a17' : '#ffffff';
}

// « Créer ma boutique » : seulement sur un déploiement qui porte l'inscription (portail). Les tarifs viennent de includes/saas-pricing.php.
$canSignup = is_file(__DIR__ . '/../inscription/index.php');
if ($canSignup) require_once __DIR__ . '/../includes/saas.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$slug = strtolower((string) ($_GET['g'] ?? ''));
$testRequest = null;

// Nom de domaine propre : une galerie qui a réservé l'adresse du visiteur est servie à la racine.
$hostGallery = $slug === '' ? gallery_from_host((string) ($_SERVER['HTTP_HOST'] ?? '')) : null;
if ($hostGallery) { $slug = (string) $hostGallery['slug']; $_GET['g'] = $slug; }

// ── Liste des galeries ──
if ($slug === '') {
    $galleries = array_filter(gallery_list(), static fn (array $g): bool => go_is_live($g));
    $pageTitle = 'Galeries commerciales';
    $accent = '#b5502e';
    $gallery = null;
} else {
    $gallery = gallery_load($slug);
    if ($gallery) $gallery['published'] = go_is_live($gallery);   // essai terminé ou abonnement résilié : comme non publiée (l'aperçu de l'administrateur reste possible)
    $testRequest = null;
    $testShop = (string) ($_GET['test_shop'] ?? '');
    if ($gallery && $canSignup && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $testShop)) {
        $candidate = saas_request_by_link((string) ($_GET['r'] ?? ''), (string) ($_GET['k'] ?? ''));
        if ($candidate && ($candidate['status'] ?? '') === 'approved'
            && (string) ($candidate['slug'] ?? '') === $testShop
            && (string) ($candidate['gallery'] ?? '') === $slug
            && is_dir(dirname(__DIR__) . '/tenants/' . $testShop)) {
            $testRequest = $candidate;
            $isMember = false;
            foreach ($gallery['members'] as $member) {
                if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === $testShop) {
                    $isMember = true;
                    break;
                }
            }
            if (!$isMember) $gallery['members'][] = ['type' => 'local', 'slug' => $testShop, 'featured' => false];
            header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
    }
    $preview = $gallery && hash_equals(gallery_preview_token($gallery), (string) ($_GET['apercu'] ?? ''));
    if (!$gallery || (!$gallery['published'] && !$preview)) {
        http_response_code(404);
        $pageTitle = 'Galerie introuvable';
        $accent = '#b5502e';
        $gallery = null;
        $notFound = true;
    } else {
        $pageTitle = $gallery['name'];
        $accent = $gallery['accent'];
        $vocab = gt_of($gallery)['vocab'];            // dénominations du type de galerie (commerces, marchands, exposants…)
        if ($gallery['published'] && !$preview && !$testRequest) ga_track_view($slug);   // fréquentation de la page (sans cookie), vue par l'administrateur de la galerie
    }
}
$ink = gal_ink_on($accent);
// Habillage choisi dans le portail (palette, polices, bannière, logo, présentation) ; sans galerie (liste), l'habillage d'origine.
$theme = $gallery ? gallery_theme($gallery) : null;
$st = $gallery['style'] ?? gallery_style_normalize([]);
$bannerImages = $gallery ? gallery_banner_images($st) : [];

// Campagne pionniers de la galerie : le palier du prochain inscrit remise le tarif « à partir de » et alimente le bandeau public.
$ebCampaign = $gallery && function_exists('eb_campaign_for_signup') ? eb_campaign_for_signup($gallery['slug']) : null;
$ebTier = $ebCampaign && $ebCampaign['progress']['open'] ? eb_tier_at($ebCampaign, $ebCampaign['progress']['next_rank']) : null;

// ── Filtres (galerie) ──
$data = ['shops' => [], 'products' => [], 'skipped' => []];
$products = [];
$total = 0;
$q = $shopFilter = $catFilter = $sort = '';
$min = $max = null;
$page = 1;
$perPage = $st['per_page'];
$universes = [];
$sectors = [];
$sector = null;
if ($gallery) {
    $data = gallery_catalogue($gallery, $origin, $testRequest !== null, $testRequest === null);
    $shops = $data['shops'];
    uasort($shops, static fn (array $a, array $b): int => [$b['featured'], $a['order']] <=> [$a['featured'], $b['order']]);
    // Sous-zones du plan (bâtiments, halls…) : ?secteur=<identifiant> ne montre que les commerces posés dans ce secteur.
    $sectors = gallery_map_sectors($gallery['map'] ?? null);
    $sector = ($_GET['secteur'] ?? '') !== '' ? gallery_map_sector($gallery['map'] ?? null, strtolower((string) $_GET['secteur'])) : null;
    if ($sector) {
        $shops = array_intersect_key($shops, array_flip($sector['shops']));
        $pageTitle = $sector['name'] . ' — ' . $gallery['name'];
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $shopFilter = (string) ($_GET['commerce'] ?? '');
    $catFilter = (string) ($_GET['cat'] ?? '');
    $sort = in_array($_GET['tri'] ?? '', ['prix-asc', 'prix-desc', 'nom', 'recent'], true) ? (string) $_GET['tri'] : $st['default_sort'];
    $min = ($_GET['min'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $_GET['min']) : null;
    $max = ($_GET['max'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $_GET['max']) : null;
    $page = max(1, (int) ($_GET['page'] ?? 1));

    foreach ($data['products'] as $p) {
        if (isset($shops[$p['shop']])) $universes[$p['cat_label']] = ($universes[$p['cat_label']] ?? 0) + 1;
    }
    ksort($universes, SORT_NATURAL | SORT_FLAG_CASE);

    $products = array_values(array_filter($data['products'], static function (array $p) use ($shops, $q, $shopFilter, $catFilter, $min, $max): bool {
        if (!isset($shops[$p['shop']])) return false;
        if ($shopFilter !== '' && $p['shop'] !== $shopFilter) return false;
        if ($catFilter !== '' && $p['cat_label'] !== $catFilter) return false;
        if ($q !== '' && mb_stripos($p['name'] . ' ' . $p['description'] . ' ' . $p['cat_label'] . ' ' . $shops[$p['shop']]['name'], $q) === false) return false;
        if ($min !== null && ($p['price_cents'] === null || $p['price_cents'] < $min * 100)) return false;
        if ($max !== null && ($p['price_cents'] === null || $p['price_cents'] > $max * 100)) return false;
        return true;
    }));
    usort($products, static function (array $a, array $b) use ($sort): int {
        return match ($sort) {
            'prix-asc' => [$a['price_cents'] ?? PHP_INT_MAX, $a['name']] <=> [$b['price_cents'] ?? PHP_INT_MAX, $b['name']],
            'prix-desc' => [$b['price_cents'] ?? -1, $a['name']] <=> [$a['price_cents'] ?? -1, $b['name']],
            'nom' => strcasecmp($a['name'], $b['name']),
            default => $b['created_at'] <=> $a['created_at'],
        };
    });
    $total = count($products);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $visible = array_slice($products, ($page - 1) * $perPage, $perPage);
}

/** Adresse de la page avec les filtres actuels, modifiés par $changes (null retire un filtre). */
function gal_url(array $changes = []): string
{
    $base = array_filter(array_diff_key($_GET, ['g' => 1, 'page' => 1]), static fn ($v) => $v !== '' && $v !== null);
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '') unset($base[$k]); else $base[$k] = $v;
    }
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $path . ($base ? '?' . http_build_query($base) : '');
}

$activeFilters = [];
if ($gallery) {
    if ($q !== '') $activeFilters['q'] = 'Recherche : « ' . $q . ' »';
    if ($shopFilter !== '' && isset($shops[$shopFilter])) $activeFilters['commerce'] = $shops[$shopFilter]['name'];
    if ($catFilter !== '') $activeFilters['cat'] = $catFilter;
    if ($min !== null) $activeFilters['min'] = 'À partir de ' . rtrim(rtrim(number_format($min, 2, ',', ''), '0'), ',') . ' €';
    if ($max !== null) $activeFilters['max'] = 'Jusqu\'à ' . rtrim(rtrim(number_format($max, 2, ',', ''), '0'), ',') . ' €';
}
$metaDescription = $sector ? ($sector['note'] !== '' ? $sector['note'] : $sector['name'] . ' : ' . count($shops) . ' ' . ($vocab['members'] ?? 'commerces') . ' dans ' . $gallery['name'] . '.')
    : ($gallery ? ($gallery['tagline'] ?: 'Les pièces de ' . count($data['shops']) . ' commerces réunies au même endroit.') : 'Des commerçants réunis au même endroit.');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?><?= $gallery ? ' — ' . e($vocab['gallery'] ?? 'galerie commerciale') : '' ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($metaDescription) ?>">
<?php if ($gallery): echo share_og_html($pageTitle, $metaDescription, gallery_public_url($gallery, $origin), !empty($st['banner']) ? $origin . '/' . $st['banner'] : '', 'website', $pageTitle); endif; ?>
<link rel="stylesheet" href="/assets/share.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.css') ?>">
<script src="/assets/share.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.js') ?>" defer></script>
<?php if (!empty($notFound) || ($gallery && !$gallery['published'])): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<?php if ($testRequest): ?><meta name="robots" content="noindex, nofollow, noarchive"><meta name="referrer" content="no-referrer"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php if ($theme && $theme['fonts'] !== ''): ?><link href="<?= e($theme['fonts']) ?>" rel="stylesheet"><?php endif; ?>
<style>
  :root {
    --accent: <?= e($accent) ?>; --accent-ink: <?= e($ink) ?>;
    --bg: #f7f3ec; --surface: #ffffff; --surface-2: #efe9de; --ink: #221f1a; --ink-soft: #6a6459; --line: #ddd4c5;
    --radius: 14px; --shadow: 0 1px 2px rgba(34,31,26,.06), 0 8px 24px -12px rgba(34,31,26,.18);
    --display: 'Fraunces', Georgia, serif; --body: 'Archivo', system-ui, sans-serif;
  }
  <?php if (!$theme || $theme['allow_dark']): ?>@media (prefers-color-scheme: dark) {
    :root { --bg: #17140f; --surface: #221e18; --surface-2: #2c2720; --ink: #f1ebe0; --ink-soft: #a89f90; --line: #3a342b; --shadow: 0 1px 2px rgba(0,0,0,.4), 0 10px 28px -12px rgba(0,0,0,.6); }
  }<?php endif; ?>
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); font-family: var(--body); line-height: 1.5; -webkit-font-smoothing: antialiased; }
  a { color: inherit; }
  .wrap { width: min(1200px, 100% - 32px); margin-inline: auto; }
  .skip { position: absolute; left: -999px; } .skip:focus { left: 8px; top: 8px; background: var(--surface); padding: 8px 12px; z-index: 10; }
  :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }

  .hero { background: var(--hero-bg, var(--accent)); color: var(--hero-ink, var(--accent-ink)); padding: clamp(32px, 6vw, 72px) 0 clamp(28px, 5vw, 56px); position: relative; overflow: hidden; }
  .hero > .wrap { position: relative; z-index: 2; }
  .hero-media { position: absolute; inset: 0; z-index: 0; }
  .hero-media > i { position: absolute; inset: 0; background-size: cover; opacity: 0; transition: opacity 1.2s ease; }
  .hero-media > i.is-on { opacity: 1; }
  @media (prefers-reduced-motion: reduce) { .hero-media > i { transition: none; } }
  .hero-shade { position: absolute; inset: 0; z-index: 1; }
  .hero.has-banner::after { display: none; }
  .hero.h-compact { padding: clamp(20px, 3vw, 36px) 0 clamp(18px, 3vw, 30px); } .hero.h-tall { padding: clamp(64px, 12vw, 150px) 0 clamp(40px, 7vw, 90px); min-height: min(68vh, 560px); display: flex; align-items: flex-end; } .hero.h-tall > .wrap { width: min(1200px, 100% - 32px); }
  .hero.a-center { text-align: center; } .hero.a-center h1, .hero.a-center .tagline, .hero.a-center .intro { margin-inline: auto; } .hero.a-center .stats, .hero.a-center .hero-cta { justify-content: center; }
  .hero-logo { display: block; max-height: 68px; max-width: 220px; width: auto; margin: 0 0 16px; object-fit: contain; } .hero.a-center .hero-logo { margin-inline: auto; }
  .hero::after { content: ""; position: absolute; inset: auto -10% -60% auto; width: 520px; height: 520px; border-radius: 50%; background: currentColor; opacity: .07; }
  .hero .eyebrow { font-size: .78rem; letter-spacing: .14em; text-transform: uppercase; opacity: .8; margin: 0 0 10px; font-weight: 600; }
  .hero h1 { font-family: var(--display); font-weight: 600; font-size: clamp(2rem, 5.2vw, 3.6rem); line-height: 1.04; margin: 0; max-width: 18ch; text-wrap: balance; }
  .hero .tagline { font-size: clamp(1.02rem, 2vw, 1.3rem); margin: 14px 0 0; max-width: 56ch; opacity: .94; }
  .hero .intro { margin: 12px 0 0; max-width: 62ch; opacity: .85; }
  .stats { display: flex; flex-wrap: wrap; gap: 8px 22px; margin: 22px 0 0; padding: 0; list-style: none; font-weight: 600; }
  .stats strong { font-family: var(--display); font-size: 1.5rem; margin-right: 6px; }

  section { padding: 34px 0 6px; }
  h2 { font-family: var(--display); font-weight: 600; font-size: 1.5rem; margin: 0 0 4px; }
  .lede { color: var(--ink-soft); margin: 0 0 18px; }

  .shops { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 14px; }
  .shop { display: flex; flex-direction: column; gap: 10px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 14px; text-decoration: none; transition: transform .15s, box-shadow .15s, border-color .15s; }
  .shop-main { display: flex; gap: 14px; align-items: center; text-decoration: none; }
  .shop-links { display: flex; flex-wrap: wrap; gap: 6px 14px; padding-top: 8px; border-top: 1px solid var(--line); font-size: .86rem; font-weight: 600; }
  .shop-links a { color: var(--ink-soft); text-decoration: none; } .shop-links a:hover { color: var(--accent); text-decoration: underline; } .shop-links a.visit { color: var(--accent); }
  .shop.join { flex-direction: row; align-items: center; }
  .shop:hover { transform: translateY(-2px); box-shadow: var(--shadow); border-color: var(--accent); }
  .shop.is-on { border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); }
  .avatar { flex: none; width: 54px; height: 54px; border-radius: 12px; display: grid; place-items: center; font-family: var(--display); font-weight: 700; font-size: 1.4rem; overflow: hidden; background: var(--surface-2); }
  .avatar { position: relative; }
  .avatar img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; background: #fff; }
  .shop h3 { margin: 0; font-size: 1rem; line-height: 1.25; }
  .shop p { margin: 2px 0 0; font-size: .84rem; color: var(--ink-soft); }
  .shop .star { color: var(--accent); font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }

  form.filters { display: grid; grid-template-columns: 2fr 1.2fr 1.2fr .8fr .8fr 1fr auto; gap: 10px; align-items: end; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 14px; }
  .filters label { display: grid; gap: 4px; font-size: .74rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-soft); }
  .filters input, .filters select { font: inherit; font-size: .95rem; padding: 10px 11px; border: 1px solid var(--line); border-radius: 10px; background: var(--bg); color: var(--ink); min-width: 0; width: 100%; text-transform: none; letter-spacing: 0; font-weight: 400; }
  .btn { font: inherit; font-weight: 600; border: 0; border-radius: 10px; padding: 11px 18px; background: var(--accent); color: var(--accent-ink); cursor: pointer; text-decoration: none; display: inline-block; text-align: center; }
  .btn.ghost { background: transparent; color: var(--ink); border: 1px solid var(--line); }
  .chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 14px 0 0; align-items: center; }
  .chip { display: inline-flex; gap: 8px; align-items: center; background: var(--surface-2); border-radius: 999px; padding: 5px 6px 5px 12px; font-size: .86rem; text-decoration: none; }
  .chip b { display: grid; place-items: center; width: 20px; height: 20px; border-radius: 50%; background: var(--surface); font-size: .8rem; }
  .count { margin: 18px 0 12px; color: var(--ink-soft); }

  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(var(--card-min, 210px), 1fr)); gap: 18px; }
  .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; display: flex; flex-direction: column; transition: transform .15s, box-shadow .15s; }
  .card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
  .card .ph { position: relative; aspect-ratio: var(--ratio, 4 / 5); background: var(--surface-2); display: block; }
  .card .ph img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }
  .card .ph .none { position: absolute; inset: 0; display: grid; place-items: center; font-family: var(--display); font-size: 2.6rem; color: var(--ink-soft); }
  .badge { position: absolute; top: 10px; left: 10px; background: var(--accent); color: var(--accent-ink); font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; padding: 4px 9px; border-radius: 999px; }
  .card .body { padding: 12px 14px 14px; display: flex; flex-direction: column; gap: 3px; flex: 1; }
  .card h3 { margin: 0; font-size: 1rem; line-height: 1.3; }
  .card h3 a { text-decoration: none; } .card h3 a::after { content: ""; position: absolute; inset: 0; }
  .card { position: relative; }
  .card .by { font-size: .82rem; color: var(--ink-soft); position: relative; z-index: 1; width: fit-content; }
  .card .by:hover { color: var(--accent); }
  .price { margin-top: auto; padding-top: 8px; font-weight: 700; font-size: 1.05rem; }
  .price s { font-weight: 400; color: var(--ink-soft); margin-left: 6px; font-size: .9rem; }

  .pager { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin: 30px 0 10px; }
  .pager a, .pager span { min-width: 40px; padding: 9px 13px; border: 1px solid var(--line); border-radius: 10px; text-decoration: none; text-align: center; background: var(--surface); }
  .pager .cur { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); font-weight: 700; }
  .empty { text-align: center; padding: 56px 16px; background: var(--surface); border: 1px dashed var(--line); border-radius: var(--radius); color: var(--ink-soft); }
  .note { background: var(--surface-2); border-radius: var(--radius); padding: 12px 16px; font-size: .9rem; margin: 18px 0 0; }
  footer { margin-top: 56px; padding: 26px 0 40px; border-top: 1px solid var(--line); color: var(--ink-soft); font-size: .88rem; }

  .galleries { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
  .gcard { display: block; text-decoration: none; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
  .gcard:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
  .gcard .band { height: 10px; }
  .gcard div.in { padding: 18px; } .gcard h3 { margin: 0 0 6px; font-family: var(--display); font-size: 1.25rem; } .gcard p { margin: 0; color: var(--ink-soft); }

  .gwrap { position: relative; display: flex; }
  .gwrap > .gcard { flex: 1; }

  .hero-share { margin: 18px 0 0; } .hero .share-btn { color: inherit; border-color: color-mix(in srgb, currentColor 55%, transparent); } .hero .share-btn:hover { background: color-mix(in srgb, currentColor 14%, transparent); border-color: currentColor; }
  .hero.a-center .hero-share { display: flex; justify-content: center; }
  .share-dialog { --bg: var(--surface, #fff); }
  .hero-app { margin: 14px 0 0; } .hero.a-center .hero-app { display: flex; justify-content: center; }
  .app-btn { display: inline-block; cursor: pointer; list-style: none; color: inherit; font-weight: 600; text-decoration: none; padding: 10px 16px; border: 1px solid color-mix(in srgb, currentColor 55%, transparent); border-radius: 10px; } .app-btn:hover { background: color-mix(in srgb, currentColor 14%, transparent); }
  .app-pick { display: inline-block; text-align: left; } .app-pick summary::-webkit-details-marker { display: none; }
  .app-pick ul { list-style: none; margin: 8px 0 0; padding: 6px; background: var(--bg, #fff); color: var(--ink, #222); border-radius: 10px; box-shadow: 0 6px 24px rgba(0,0,0,.25); } .app-pick li a { display: block; padding: 8px 12px; color: inherit; text-decoration: none; border-radius: 6px; font-weight: 600; } .app-pick li a:hover { background: rgba(0,0,0,.07); }
  .hero-cta { display: flex; flex-wrap: wrap; gap: 12px 16px; align-items: center; margin: 24px 0 0; }
  .btn-cta { font: inherit; font-weight: 700; font-size: 1.02rem; border-radius: 12px; padding: 14px 24px; background: var(--hero-ink, var(--accent-ink)); color: var(--hero-bg, var(--accent)); text-decoration: none; display: inline-block; box-shadow: 0 8px 22px -10px rgba(0,0,0,.45); }
  .btn-cta:hover { transform: translateY(-1px); box-shadow: 0 12px 26px -10px rgba(0,0,0,.5); }
  .hero-cta .from { font-size: .92rem; opacity: .9; } .hero-cta .alt { color: inherit; font-weight: 600; opacity: .9; }
  .cta-band { margin-top: 52px; padding: clamp(28px, 5vw, 48px) 0; background: var(--surface-2); border-block: 1px solid var(--line); }
  .cta-band .in { display: grid; grid-template-columns: 1.3fr 1fr; gap: 28px; align-items: center; }
  .cta-band h2 { font-size: clamp(1.4rem, 3vw, 2rem); margin: 0 0 8px; }
  .cta-band ul { list-style: none; margin: 14px 0 0; padding: 0; display: grid; gap: 6px; color: var(--ink-soft); } .cta-band li::before { content: "✓ "; color: var(--accent); font-weight: 700; }
  .cta-band .act { display: grid; gap: 10px; justify-items: start; } .cta-band .btn-cta { background: var(--accent); color: var(--accent-ink); }
  .cta-band .from { color: var(--ink-soft); font-size: .92rem; }
  .shop.join { border-style: dashed; justify-content: center; color: var(--accent); font-weight: 700; text-align: center; }
  .cta-band ~ footer { margin-top: 0; }
  .cta-float { display: none; }
  @media (max-width: 720px) {
    .cta-band .in { grid-template-columns: 1fr; }
    .cta-float { display: block; position: fixed; right: 14px; bottom: 14px; z-index: 20; background: var(--accent); color: var(--accent-ink); font-weight: 700; padding: 12px 18px; border-radius: 999px; text-decoration: none; box-shadow: 0 10px 26px -8px rgba(0,0,0,.5); }
    body { padding-bottom: 70px; }
  }
  @media (max-width: 980px) { form.filters { grid-template-columns: 1fr 1fr; } form.filters .wide { grid-column: 1 / -1; } }
  @media (max-width: 520px) { .grid { grid-template-columns: repeat(2, 1fr); gap: 12px; } .card .body { padding: 10px; } form.filters { grid-template-columns: 1fr 1fr; } }
  .gl-login { position: absolute; top: 12px; right: 16px; z-index: 20; margin: 0; }
  .gl-login a { display: inline-block; padding: 7px 14px; border-radius: 999px; background: rgba(255,255,255,.92); color: #222; font-weight: 600; font-size: .88rem; text-decoration: none; box-shadow: 0 1px 6px rgba(0,0,0,.2); }
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
  <?= $theme ? $theme['css'] : '' ?>
  <?php if ($theme): ?>body { font-family: var(--body); } h1, h2, .gcard h3, .avatar, .stats strong, .card .none { font-family: var(--display); }<?php endif; ?>
</style>
</head>
<body>
<a class="skip" href="#contenu">Aller au contenu</a>

<?php if ($slug === ''): ?>
  <header class="hero"><div class="wrap">
    <p class="eyebrow">Des commerçants réunis</p>
    <h1>Galeries commerciales</h1>
    <p class="tagline">Une rue, un village, un groupe d'amis : leurs boutiques réunies au même endroit.</p>
    <p class="hero-share"><?= share_button_html($origin . '/galerie/', 'Galeries commerciales', "Des commerçants réunis au même endroit.") ?>
      <a class="alt" href="/galerie/admin.php" rel="nofollow" style="margin-left:14px;">Se connecter</a></p>
    <?php if ($canSignup): ?>
      <p class="hero-cta"><a class="btn-cta" href="/inscription/"<?= cta_attrs('galerie_hero_cta') ?>><?= e(cta_label('galerie_hero_cta')) ?></a>
        <span class="from"><?= e(saas_grid_from_text(saas_grid(''))) ?> · sans engagement</span><a class="alt" href="/#formules">Voir les tarifs</a></p>
    <?php endif; ?>
  </div></header>
  <main id="contenu" class="wrap">
    <section>
      <?php if ($galleries): ?>
        <div class="galleries">
          <?php foreach ($galleries as $g):
              $gType = gt_of($g);
              $gCount = count($g['members'] ?? []);
              $gDesc = trim((string) ($g['tagline'] ?? ''));
              if ($gDesc === '') $gDesc = $gCount . ' ' . ($gCount > 1 ? $gType['vocab']['members'] : $gType['vocab']['member']);
          ?>
            <div class="gwrap lp-hover">
              <a class="gcard" href="/galerie/<?= e($g['slug']) ?>/"><div class="band" style="background:<?= e($g['accent']) ?>"></div>
                <div class="in"><h3><?= e($g['name']) ?></h3><p><?= e($g['tagline'] ?: $gCount . ' ' . ($gCount > 1 ? $gType['vocab']['members'] : $gType['vocab']['member'])) ?></p></div></a>
              <?= gt_landing_preview_html((string) $gType['key'], $gDesc, 'Aperçu ' . $gType['name']) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="empty">Aucune galerie publiée pour le moment.</p>
      <?php endif; ?>
    </section>
  </main>

<?php elseif (!$gallery): ?>
  <main id="contenu" class="wrap"><section>
    <h1 style="font-family:var(--display)">Galerie introuvable</h1>
    <p class="lede">Cette galerie n'existe pas ou n'est pas encore publiée.</p>
    <p><a class="btn" href="/galerie/">Voir les galeries</a></p>
  </section></main>

<?php else: ?>
  <p class="gl-login"><a href="<?= e(ga_login_url($slug)) ?>" rel="nofollow">Se connecter</a></p>
  <header class="hero<?= $bannerImages ? ' has-banner' : '' ?> h-<?= e($st['hero_height']) ?> a-<?= e($st['hero_align']) ?>">
    <?= banner_slides_html($bannerImages, (int) $st['banner_overlay'], (string) $st['banner_pos'], (bool) $st['banner_slideshow']) ?>
    <div class="wrap">
    <?php if ($st['logo'] !== ''): ?><img class="hero-logo" src="/<?= e($st['logo']) ?>" alt="<?= e($gallery['name']) ?>"><?php endif; ?>
    <p class="eyebrow"><?= e(ucfirst($vocab['gallery'] ?? 'galerie commerciale')) ?> · <?= e(business_presence_label($gallery['presence_mode'])) ?><?= $gallery['published'] ? '' : ' · aperçu non publié' ?></p>
    <h1><?= e($gallery['name']) ?></h1>
    <?php if ($gallery['tagline'] !== ''): ?><p class="tagline"><?= e($gallery['tagline']) ?></p><?php endif; ?>
    <?php if ($gallery['description'] !== ''): ?><p class="intro"><?= nl2br(e($gallery['description'])) ?></p><?php endif; ?>
    <?php if ($st['show_stats']): ?><ul class="stats">
      <li><strong><?= count($shops) ?></strong><?= e(count($shops) > 1 ? ($vocab['members'] ?? 'commerces') : ($vocab['member'] ?? 'commerce')) ?></li>
      <li><strong><?= count($data['products']) ?></strong>pièce<?= count($data['products']) > 1 ? 's' : '' ?> en ligne</li>
      <li><strong><?= count($universes) ?></strong>univers</li>
    </ul><?php endif; ?>
    <?php if ($gallery['published']): ?><p class="hero-share"><?= share_button_html(gallery_public_url($gallery, $origin), $gallery['name'], $gallery['tagline'] ?: 'Les pièces de ' . count($shops) . ' commerces réunies au même endroit.', $st['banner'] !== '' ? $origin . '/' . $st['banner'] : '') ?></p><?php endif; ?>
    <?php if (gl_open($gallery)): ?>
      <div class="hero-app"><a class="app-btn" href="/galerie/visiteur.php?g=<?= e($gallery['slug']) ?>">📱 Application visiteurs · <?= e(gl_settings($gallery)['program_name']) ?>, points et récompenses</a></div>
    <?php elseif ($vApps = array_values(array_filter($shops, static fn ($s) => ($s['visitor_url'] ?? '') !== ''))): ?>
      <div class="hero-app">
        <?php if (count($vApps) === 1): ?><a class="app-btn" href="<?= e($vApps[0]['visitor_url']) ?>" target="_blank" rel="noopener">📱 Application visiteurs · fidélité, alertes, billets</a>
        <?php else: ?><details class="app-pick"><summary class="app-btn">📱 Application visiteurs · fidélité, alertes, billets</summary>
          <ul><?php foreach ($vApps as $vs): ?><li><a href="<?= e($vs['visitor_url']) ?>" target="_blank" rel="noopener"><?= e($vs['name']) ?> ↗</a></li><?php endforeach; ?></ul></details><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($canSignup && $gallery['published'] && $st['show_cta']): ?>
      <p class="hero-cta"><a class="btn-cta" href="/inscription/?galerie=<?= e($gallery['slug']) ?>"><?= e($st['cta_label'] !== '' ? $st['cta_label'] : 'Je veux vendre en ligne') ?></a>
        <span class="from"><?= e(saas_grid_from_text($ebTier ? eb_discounted_grid(saas_grid($gallery['slug']), $ebTier) : saas_grid($gallery['slug']))) ?> · sans engagement</span><a class="alt" href="#cta-boutique">En savoir plus</a></p>
    <?php endif; ?>
  </div></header>

  <main id="contenu" class="wrap">
    <?php if ($testRequest): ?><p class="note" role="status">Aperçu privé : la boutique « <?= e($testRequest['shop_name']) ?> » est affichée temporairement. Cet aperçu ne l’ajoute pas à la galerie.</p><?php endif; ?>
    <?php if ($data['skipped']): ?>
      <p class="note">Certains <?= e($vocab['members'] ?? 'commerces') ?> ne répondent pas pour le moment (<?= e(implode(', ', $data['skipped'])) ?>) : leurs pièces reviendront dès que possible.</p>
    <?php endif; ?>
    <?php if ($ebCampaign && $ebCampaign['enabled'] && $gallery['published'] && !$preview): ?><?= eb_widget_html($gallery['slug']) ?><?php endif; ?>

    <?php if ($sectors): ?>
    <nav class="chips" aria-label="Secteurs du site" style="margin:18px 0 0;">
      <a class="chip" href="<?= e(gal_url(['secteur' => null, 'commerce' => null])) ?>"<?= !$sector ? ' aria-current="page" style="font-weight:700;"' : '' ?>>Tout le site</a>
      <?php foreach ($sectors as $sec): ?>
        <a class="chip" href="<?= e(gal_url(['secteur' => $sec['slug'], 'commerce' => null])) ?>"<?= $sector && $sector['slug'] === $sec['slug'] ? ' aria-current="page" style="font-weight:700;"' : '' ?>><span aria-hidden="true" style="width:10px;height:10px;border-radius:50%;background:<?= e($sec['color']) ?>;display:inline-block;"></span><?= e($sec['name']) ?> <b><?= count($sec['shops']) ?></b></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php if ($sector && $sector['note'] !== ''): ?><p class="note"><?= e($sector['note']) ?></p><?php endif; ?>

    <?php if ($shops): ?>
    <section aria-labelledby="h-commerces">
      <h2 id="h-commerces"><?= e($st['shops_title'] !== '' ? $st['shops_title'] : ($vocab['members_title'] ?? 'Les commerces')) ?></h2>
      <p class="lede"><?= e($st['shops_lede'] !== '' ? $st['shops_lede'] : ($vocab['members_lede'] ?? 'Chaque commerce a sa boutique, son panier et son paiement : une pièce se règle chez son commerçant.')) ?></p>
      <div class="shops">
        <?php foreach ($shops as $key => $s): $initial = mb_strtoupper(mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $s['name']), 0, 1)); ?>
          <div class="shop<?= $shopFilter === $key ? ' is-on' : '' ?>">
            <a class="shop-main" href="<?= e($s['url'] !== '' ? $s['url'] : gal_url(['commerce' => $key]) . '#pieces') ?>"<?= $s['url'] !== '' ? ' target="_blank" rel="noopener"' : '' ?> title="<?= $s['url'] !== '' ? 'Ouvrir sa boutique' : 'Voir ses pièces' ?>">
              <span class="avatar" style="<?= $s['accent'] !== '' ? 'color:' . e(gal_ink_on($s['accent'])) . ';background:' . e($s['accent']) : '' ?>">
                <?= e($initial) ?><?php if ($s['logo'] !== ''): ?><img src="<?= e($s['logo']) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
              </span>
              <span>
                <?php if ($s['featured']): ?><span class="star">★ À la une</span><br><?php endif; ?>
                <h3><?= e($s['name']) ?></h3>
                <p><?= e($s['tagline'] !== '' ? $s['tagline'] : '') ?><?= $s['tagline'] !== '' ? ' · ' : '' ?><?= (int) $s['count'] ?> pièce<?= $s['count'] > 1 ? 's' : '' ?></p>
              </span>
            </a>
            <div class="shop-links">
              <?php if ($s['url'] !== ''): ?><a class="visit" href="<?= e($s['url']) ?>" target="_blank" rel="noopener">Visiter la boutique ↗</a><?php endif; ?>
              <?php if (($s['visitor_url'] ?? '') !== ''): ?><a href="<?= e($s['visitor_url']) ?>" target="_blank" rel="noopener" title="Points de fidélité, alertes, réservations et billets de ce commerce">Espace visiteur ↗</a><?php endif; ?>
              <a href="<?= e(gal_url(['commerce' => $shopFilter === $key ? null : $key])) ?>#pieces"><?= $shopFilter === $key ? 'Toutes les pièces' : 'Ses pièces' ?></a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($canSignup && $gallery['published'] && $st['show_cta']): ?>
          <a class="shop join" href="/inscription/?galerie=<?= e($gallery['slug']) ?>"><span>＋ <?= e($vocab['join_cta'] ?? 'Je veux vendre en ligne') ?><br><small style="font-weight:400;color:var(--ink-soft)">Rejoindre <?= e($gallery['name']) ?></small></span></a>
        <?php endif; ?>
      </div>
    </section>

    <?php endif; /* fin de la liste des commerces : le plan s'affiche aussi sans commerce (vide-grenier, marché) */ ?>

    <?php
      // Événement (module organisateur) : les places numérotées ev1…evN sont calculées, jamais stockées une à une.
      $orgOn = function_exists('gm_enabled') && gm_enabled($gallery, 'organisateur');
      $galleryEvent = gallery_event($gallery);
      $evCancelled = function_exists('ge_cancelled') && ge_cancelled($galleryEvent);
      $galleryBooking = gb_booking($gallery);
      $evPlaces = [];
      $evStates = [];
      if ($orgOn && $galleryEvent && !empty($galleryEvent['published']) && !$evCancelled) $evPlaces = ge_places($galleryEvent);
      if ($evPlaces && $galleryBooking['source'] === 'event') $evStates = gb_states($gallery);
      $hasEventSection = $orgOn && $galleryEvent && !empty($galleryEvent['published']);
      $waitlistLink = function_exists('gm_enabled') && gm_enabled($gallery, 'attente') && gt_feature($gallery, 'exposants') && !empty($galleryBooking['enabled'])
          ? '/galerie/attente.php?g=' . rawurlencode($gallery['slug']) : '';
    ?>
    <?php if ($hasEventSection): ?>
    <section id="evenement" aria-labelledby="h-evenement">
      <h2 id="h-evenement"><?= e($galleryEvent['title'] !== '' ? $galleryEvent['title'] : 'L\'événement') ?></h2>
      <p class="lede">
        <?php if ($d = ge_date_text($galleryEvent)): ?><strong><?= e(ucfirst($d)) ?></strong><?php endif; ?>
        <?php if ($p = ge_place_text($galleryEvent)): ?><?= $d ? ' · ' : '' ?><?= e($p) ?><?php endif; ?>
        <?php if ($h = ge_hours_text($galleryEvent)): ?><?= ($d || $p) ? ' · ' : '' ?><?= e($h) ?><?php endif; ?>
      </p>
      <?php if ($evCancelled): $cn = (array) $galleryEvent['cancelled']; ?>
        <div role="status" style="margin:12px 0;padding:12px 14px;border:1px solid #d9a3a0;border-radius:10px;background:#fdf0ef;color:#6d1f1b;">
          <strong>Événement annulé.</strong> <?= e(ge_cancel_reason($galleryEvent)) ?>.
          <?php if ((int) $cn['refund_pct'] > 0): ?>Les exposants inscrits sont remboursés à <?= (int) $cn['refund_pct'] ?> % du tarif sous <?= (int) $cn['refund_delay_days'] ?> jours, par <?= e($cn['refund_method']) ?>, et prévenus par e-mail.<?php else: ?>Les exposants inscrits sont prévenus par e-mail ; aucun remboursement n'est prévu selon les conditions de vente.<?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($galleryEvent['notes'] !== ''): ?><p class="lede"><?= nl2br(e($galleryEvent['notes'])) ?></p><?php endif; ?>
      <?php
        $evPrice = ge_place_price($galleryEvent);
        $evPricePro = ge_place_price($galleryEvent, true);
      ?>
      <?php if ($evPrice !== null && !$evCancelled): ?>
        <p class="lede">Tarif : <strong><?= e(gb_eur($evPrice)) ?></strong> la place<?= $evPricePro !== null && $evPricePro !== $evPrice ? ' · <strong>' . e(gb_eur($evPricePro)) . '</strong> pour les entreprises' : '' ?>.</p>
      <?php endif; ?>
      <?php if ($evPlaces): ?>
        <?php if ($galleryBooking['source'] === 'event'): ?>
          <?php if ($galleryBooking['intro'] !== ''): ?><p class="lede"><?= nl2br(e($galleryBooking['intro'])) ?></p><?php endif; ?>
          <p class="lede" style="margin:6px 0 0;">Choisissez une place libre : votre demande la réserve <?= (int) $galleryBooking['hold_days'] ?> jour(s), le temps que l'organisateur la valide.</p>
        <?php else: ?>
          <p class="lede" style="margin:6px 0 0;">Les réservations en ligne ne sont pas ouvertes pour le moment : les places ci-dessous montrent l'occupation de la manifestation.</p>
        <?php endif; ?>
        <style>
          #ev-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(54px,1fr));gap:6px;margin:12px 0 0;max-width:760px;}
          .ev-place{font:600 .9rem/1 system-ui,sans-serif;padding:13px 4px;border:1px solid var(--line);border-radius:8px;background:var(--surface-2,#f3efe7);color:var(--ink);cursor:default;}
          .ev-place.is-free{cursor:pointer;border-color:#2f6b4f;background:#e6f2e9;color:#1c4a33;}
          .ev-place.is-free:hover{border-color:#1c4a33;}
          .ev-place.is-pending{border-color:#b7791f;background:#fdf0d7;color:#7a4c07;}
          .ev-place.is-taken{border-color:#c9c0b2;background:#e9e3d9;color:#6b6257;}
          .ev-place:disabled{opacity:.85;}
          .ev-legend{display:flex;gap:14px;flex-wrap:wrap;margin:8px 0 0;font-size:.85rem;color:var(--ink-soft);}
        </style>
        <div id="ev-grid" role="list" aria-label="<?= e(ucfirst((string) ($vocab['places'] ?? 'places'))) ?> de la manifestation">
          <?php foreach ($evPlaces as $place):
              $state = $evStates[$place['id']] ?? '';
              $free = $state === '' && $galleryBooking['source'] === 'event';
              $cls = $state === 'taken' ? 'is-taken' : ($state === 'pending' ? 'is-pending' : ($free ? 'is-free' : ''));
              $label = ucfirst((string) ($vocab['place'] ?? 'place')) . ' ' . (int) $place['num'] . ' — ' . ($state === 'taken' ? 'indisponible' : ($state === 'pending' ? 'demande en cours' : ($free ? 'libre' : 'réservations fermées')));
          ?>
            <button type="button" role="listitem" class="ev-place <?= $cls ?>"<?= $free ? ' data-slot="' . e($place['id']) . '" data-num="' . (int) $place['num'] . '" data-price="' . (int) ($place['price_cents'] ?? '') . '" data-pricepro="' . (int) ($place['price_pro_cents'] ?? '') . '"' : ' disabled' ?> title="<?= e($label) ?>" aria-label="<?= e($label) ?>"><?= (int) $place['num'] ?></button>
          <?php endforeach; ?>
        </div>
        <?php if ($galleryBooking['source'] === 'event'): ?>
          <p class="ev-legend"><span>🟩 libre</span><span>🟧 demande en cours</span><span>⬜ attribuée</span></p>
          <?php $evSlug = $gallery['slug']; require __DIR__ . '/_event-booking-form.php'; ?>
          <script>
          (function(){
            var grid=document.getElementById('ev-grid');if(!grid)return;
            var pword=<?= json_encode(ucfirst((string) ($vocab['place'] ?? 'place')), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            grid.addEventListener('click',function(e){
              var b=e.target.closest?e.target.closest('button[data-slot]'):null;
              if(!b||b.disabled||!window.GXEventBooking)return;
              var p=b.getAttribute('data-price'),pp=b.getAttribute('data-pricepro');
              window.GXEventBooking.open(b.getAttribute('data-slot'),pword+' '+(b.getAttribute('data-num')||''),p===''?null:+p,pp===''?null:+pp);
            });
            window.addEventListener('gx:booking',function(e){
              var b=grid.querySelector('button[data-slot="'+e.detail.slot+'"]');if(!b)return;
              b.disabled=true;b.classList.remove('is-free');b.classList.add('is-pending');
              b.title=pword+' '+(b.getAttribute('data-num')||'')+' — demande en cours';
              b.setAttribute('aria-label',b.title);
            });
          })();
          </script>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($waitlistLink !== ''): ?><p class="lede">Une place vous intéresse mais elle est déjà prise ? <a href="<?= e($waitlistLink) ?>">Inscrivez-vous à la file d'attente</a> : vous serez prévenu par e-mail dès qu'une place se libère.</p><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php
      // Plan d'implantation dessiné par la galerie : la carte (et ses fonds IGN) ne se charge qu'au clic du visiteur.
      $planItems = [];
      $planMap = $gallery['map'] ?? null;
      $bk = $planMap['booking'] ?? ['enabled' => false];
      $carteOn = !function_exists('gm_enabled') || gm_enabled($gallery, 'carte');
      if ($gallery && !gt_feature($gallery, 'exposants')) $bk['enabled'] = false;      // fonctionnalité absente de ce type de galerie
      if ($galleryBooking['source'] === 'event') $bk['enabled'] = false;               // l'événement remplace le choix sur le plan
      $withPrices = $gallery && gt_feature($gallery, 'tarifs');
      $slotStates = !empty($bk['enabled']) ? gb_states($gallery) : [];
      $zoneNames = [];
      foreach (($planMap['items'] ?? []) as $pi) if ($pi['role'] === 'zone') $zoneNames[$pi['id']] = gallery_zone_name($pi, gt_locations($gallery));
      $sectorUrls = [];
      foreach ($sectors as $sec) $sectorUrls[$sec['id']] = gal_url(['secteur' => $sec['slug'], 'commerce' => null, 'page' => null]) . '#contenu';
      foreach (($planMap['items'] ?? []) as $pi) {
          $ps = $shops[$pi['shop']] ?? null;
          $isSlot = $pi['role'] === 'slot';
          $free = $isSlot && !empty($bk['enabled']) && $pi['shop'] === '' && !isset($slotStates[$pi['id']]);
          $planItems[] = ['id' => $pi['id'], 'kind' => $pi['kind'], 'role' => $pi['role'], 'pts' => $pi['pts'], 'color' => $pi['color'],
              'label' => $pi['label'] !== '' ? $pi['label'] : ($ps['name'] ?? ''), 'num' => $pi['num'], 'note' => $pi['note'],
              'm2' => $pi['kind'] === 'polygon' ? round(gallery_map_area_m2($pi['pts']), 1) : 0,
              'ztype' => $pi['role'] === 'zone' ? gt_location_label($gallery, (string) $pi['ztype']) : '', 'zone' => $isSlot ? ($zoneNames[$pi['zone']] ?? '') : '',
              'price' => $isSlot && $withPrices ? gallery_map_slot_price($planMap, $pi, false) : null, 'price_pro' => $isSlot && $withPrices ? gallery_map_slot_price($planMap, $pi, true) : null,
              'state' => $isSlot && !empty($bk['enabled']) ? ($pi['shop'] !== '' ? 'taken' : ($slotStates[$pi['id']] ?? 'free')) : '', 'free' => $free,
              'sector' => $sectorUrls[$pi['id']] ?? '', 'shop' => $ps ? $ps['name'] : '', 'url' => $ps ? ($ps['url'] !== '' ? $ps['url'] : gal_url(['commerce' => $pi['shop']]) . '#pieces') : ''];
      }
      $planData = $carteOn && $planItems ? ['center' => $planMap['center'], 'zoom' => $planMap['zoom'], 'base' => $planMap['base'], 'items' => $planItems,
          'tiles' => ['osm' => GALLERY_MAP_TILE_PLAN, 'sat' => GALLERY_MAP_TILE_SAT],
          'background' => $planMap['background'] ?? null, 'focus' => $sector['id'] ?? '',
          'booking' => !empty($bk['enabled']) ? ['intro' => $bk['intro'], 'endpoint' => '/galerie/exposant.php', 'slug' => $gallery['slug']] : null] : null;
    ?>
    <?php if ($planData): ?>
    <section id="plan-commerces" aria-labelledby="h-plan">
      <h2 id="h-plan">Plan <?= e($vocab['of_plan'] ?? 'des commerces') ?></h2>
      <p class="lede">Où trouver chaque <?= e($vocab['member'] ?? 'commerce') ?>. Touchez un repère ou une zone pour voir son nom<?= !empty($planData['booking']) ? ', son tarif' : '' ?> et ouvrir sa boutique.</p>
      <?php if (!empty($planData['booking'])): ?>
      <p style="margin:0 0 10px;"><button type="button" class="btn-cta" id="plan-book" style="font-size:.95rem;padding:10px 18px;">Je suis exposant : choisir mon emplacement</button>
        <?php if ($planData['booking']['intro'] !== ''): ?><span class="lede" style="display:block;margin:6px 0 0;"><?= e($planData['booking']['intro']) ?></span><?php endif; ?></p>
      <?php if ($waitlistLink !== ''): ?><p style="margin:0 0 10px;"><a href="<?= e($waitlistLink) ?>">File d'attente des exposants</a> : soyez prévenu dès qu'un emplacement se libère.</p><?php endif; ?>
      <?php endif; ?>
      <div id="plan-box" style="position:relative;height:min(70vh,520px);min-height:300px;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--surface-2);">
        <button type="button" id="plan-show" style="position:absolute;inset:0;width:100%;border:0;background:transparent;color:var(--ink);font:inherit;font-weight:600;cursor:pointer;">Afficher le plan<br><small style="font-weight:400;color:var(--ink-soft)">La carte est fournie par l'IGN : elle ne se charge qu'à votre demande.</small></button>
      </div>
      <div id="plan-legend" hidden style="display:none;gap:14px;flex-wrap:wrap;margin:8px 0 0;font-size:.85rem;color:var(--ink-soft);"><span>🟩 libre</span><span>🟧 demandé</span><span>⬜ attribué</span></div>
      <style>
      #plan-form .pf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;}
      #plan-form .pf-grid label{display:grid;gap:3px;font-size:.85rem;}
      #plan-form .pf-grid input,#plan-form .pf-grid select{width:100%;box-sizing:border-box;}
      #plan-form .pf-box{border:1px solid var(--line);border-radius:8px;padding:10px;display:grid;gap:8px;}
      #plan-form .pf-box>strong{font-size:.85rem;}
      #plan-form .pf-consent{display:flex;gap:8px;align-items:flex-start;font-size:.85rem;}
      #plan-form .pf-req{color:var(--accent-2,var(--accent));font-weight:700;}
      </style>
      <form id="plan-form" hidden enctype="multipart/form-data" style="margin-top:12px;border:1px solid var(--line);border-radius:12px;padding:14px;display:none;gap:10px;max-width:760px;" autocomplete="on">
        <strong id="plan-form-title">Votre demande</strong>
        <p id="plan-form-price" style="margin:0;color:var(--ink-soft);"></p>
        <input type="hidden" name="slot"><input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
        <div class="pf-grid">
          <label>Nom affiché (facultatif)<input type="text" name="name" maxlength="80" autocomplete="name"></label>
          <label>E-mail <span class="pf-req">*</span><input type="email" name="email" required maxlength="160" autocomplete="email"></label>
          <label>Téléphone (facultatif)<input type="text" name="phone" maxlength="30" autocomplete="tel"></label>
          <label>Je suis <span class="pf-req">*</span><select name="kind" id="plan-kind">
            <option value="particulier">Particulier</option><option value="association">Association</option><option value="entreprise">Entreprise</option>
          </select></label>
        </div>
        <p class="hint" style="margin:0;font-size:.8rem;">Le registre des vendeurs, obligatoire pour l'organisateur, demande l'identité, le domicile et les références de la pièce d'identité. <span class="pf-req">*</span> champs obligatoires.</p>

        <div class="pf-box" data-ct="particulier">
          <strong>Particulier</strong>
          <div class="pf-grid">
            <label>Prénom <span class="pf-req">*</span><input type="text" name="firstname" maxlength="60" autocomplete="given-name"></label>
            <label>Nom <span class="pf-req">*</span><input type="text" name="lastname" maxlength="60" autocomplete="family-name"></label>
            <label>Date de naissance (facultatif)<input type="date" name="birth_date" data-opt="1"></label>
            <label>Lieu de naissance (facultatif)<input type="text" name="birth_place" maxlength="120" data-opt="1"></label>
          </div>
          <div class="pf-grid">
            <label>Adresse du domicile <span class="pf-req">*</span><input type="text" name="address" maxlength="160" autocomplete="street-address"></label>
            <label>Code postal <span class="pf-req">*</span><input type="text" name="postal_code" maxlength="12" autocomplete="postal-code" inputmode="numeric"></label>
            <label>Ville <span class="pf-req">*</span><input type="text" name="city" maxlength="80" autocomplete="address-level2"></label>
          </div>
          <label class="pf-consent"><input type="checkbox" name="attestation" value="1"> <span>J'atteste sur l'honneur ne pas participer à plus de deux autres manifestations de ce type dans l'année et ne vendre que des objets personnels usagés.</span></label>
        </div>

        <div class="pf-box" data-ct="association entreprise">
          <strong>Association ou entreprise</strong>
          <div class="pf-grid">
            <label>Dénomination <span class="pf-req">*</span><input type="text" name="org_name" maxlength="120" autocomplete="organization"></label>
            <label data-ct="entreprise">Forme juridique <span class="pf-req">*</span><select name="legal_form"><option value="">Choisir…</option><?php foreach (CT_LEGAL_FORMS as $lk => $lv): ?><option value="<?= e($lk) ?>"><?= e($lv) ?></option><?php endforeach; ?></select></label>
            <label data-ct="association">Numéro RNA (facultatif si SIRET)<input type="text" name="rna" maxlength="20" placeholder="W123456789" data-opt="1"></label>
            <label>SIRET (ou SIREN)<input type="text" name="siret" maxlength="20" inputmode="numeric" placeholder="14 chiffres" data-opt="1"></label>
            <label data-ct="entreprise">SIREN (si pas de SIRET)<input type="text" name="siren" maxlength="20" inputmode="numeric" data-opt="1"></label>
            <label data-ct="entreprise">TVA intracommunautaire (facultatif)<input type="text" name="vat" maxlength="20" data-opt="1"></label>
          </div>
          <div class="pf-grid">
            <label>Adresse du siège <span class="pf-req">*</span><input type="text" name="org_address" maxlength="160"></label>
            <label>Code postal du siège <span class="pf-req">*</span><input type="text" name="org_postal_code" maxlength="12" inputmode="numeric"></label>
            <label>Ville du siège <span class="pf-req">*</span><input type="text" name="org_city" maxlength="80"></label>
          </div>
          <div class="pf-grid">
            <label>Prénom du représentant <span class="pf-req">*</span><input type="text" name="rep_firstname" maxlength="60"></label>
            <label>Nom du représentant <span class="pf-req">*</span><input type="text" name="rep_lastname" maxlength="60"></label>
            <label>Fonction du représentant <span class="pf-req">*</span><input type="text" name="rep_function" maxlength="80" placeholder="Président, gérant…"></label>
          </div>
          <label class="pf-consent" data-ct="association"><input type="checkbox" name="association_declaration" value="1" data-req="1"> <span>Je déclare que l'association ne vend que des objets usagés issus de dons de particuliers.</span></label>
        </div>

        <div class="pf-box">
          <strong>Pièce d'identité (références obligatoires au registre)</strong>
          <div class="pf-grid">
            <label>Nature <span class="pf-req">*</span><select name="id_doc_type" required><option value="">Choisir…</option><?php foreach (CT_ID_DOC_TYPES as $dk => $dv): ?><option value="<?= e($dk) ?>"><?= e($dv) ?></option><?php endforeach; ?></select></label>
            <label>Numéro <span class="pf-req">*</span><input type="text" name="id_doc_number" maxlength="40" required></label>
            <label>Délivrée le <span class="pf-req">*</span><input type="date" name="id_doc_date" required></label>
            <label>Autorité émettrice <span class="pf-req">*</span><input type="text" name="id_doc_issuer" maxlength="100" placeholder="Préfecture de…, mairie de…" required></label>
          </div>
          <p class="hint" style="margin:0;font-size:.8rem;">Ces références sont recopiées au registre des vendeurs, conservé cinq ans après la manifestation.</p>
        </div>

        <details class="pf-box">
          <summary style="cursor:pointer;"><strong>Vérification d'identité : copie de la pièce (facultatif)</strong></summary>
          <p class="hint" style="margin:6px 0 0;font-size:.8rem;">La copie n'est pas exigée par le registre. Si vous l'ajoutez, elle sert uniquement à l'organisateur pour vérifier la pièce, reste privée et est supprimée automatiquement au bout de <?= (int) GB_CNI_RETENTION_DAYS ?> jours. Vous pouvez la refuser et ne saisir que les références ci-dessus.</p>
          <div class="pf-grid">
            <label>Recto (photo)<input type="file" name="cni_recto" accept="image/*" capture="environment"></label>
            <label>Verso (photo, si la pièce en a un)<input type="file" name="cni_verso" accept="image/*" capture="environment"></label>
          </div>
          <label class="pf-consent"><input type="checkbox" name="cni_consent" value="1"> <span>J'accepte le dépôt et la consultation de la copie de ma pièce d'identité par l'organisateur, pour vérification, avec suppression au terme du délai indiqué.</span></label>
        </details>

        <label>Message (facultatif)<input type="text" name="message" maxlength="400" style="width:100%;" placeholder="Matériel, type d'objets, véhicule…"></label>
        <label class="pf-consent"><input type="checkbox" name="consent" value="1" required> <span>J'accepte que mes coordonnées et les informations de ce formulaire soient transmises à l'organisateur pour traiter ma demande et tenir le registre des vendeurs.</span></label>
        <div><button type="submit" class="btn-cta" style="font-size:.95rem;padding:10px 18px;">Envoyer ma demande</button> <button type="button" id="plan-form-cancel" class="btn">Annuler</button></div>
        <p id="plan-form-msg" role="status" style="margin:0;"></p>
      </form>
      <script type="application/json" id="plan-data"><?= json_encode($planData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
      <script>
      (function(){var b=document.getElementById('plan-show');if(!b)return;
      var d=JSON.parse(document.getElementById('plan-data').textContent),map=null,layers=[],bookMode=false,box=document.getElementById('plan-box'),placeWord=<?= json_encode(ucfirst((string) ($vocab['place'] ?? 'emplacement')), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      function load(u,t){return new Promise(function(ok,ko){var e=document.createElement(t==='css'?'link':'script');if(t==='css'){e.rel='stylesheet';e.href=u}else e.src=u;e.onload=ok;e.onerror=ko;document.head.appendChild(e)})}
      function eur(c){return c==null?null:(c/100).toFixed(2).replace('.',',')+' €'}
      function style(it,l){
        var c=it.color,o={color:c,weight:2,fillColor:c,fillOpacity:.3};
        if(it.role==='zone')o={color:c,weight:2,dashArray:'8 6',fillColor:c,fillOpacity:.07};
        if(it.role==='territoire')o={color:c,weight:2.5,dashArray:'2 6',fillColor:c,fillOpacity:.04};
        if(it.role==='secteur')o={color:c,weight:it.id===d.focus?4:3,dashArray:'10 4',fillColor:c,fillOpacity:it.id===d.focus?.12:.06};
        if(bookMode&&it.role==='slot'&&it.state){var st={free:'#2e8b57',pending:'#e08a1e',taken:'#9a9a9a'}[it.state];o={color:st,weight:2,fillColor:st,fillOpacity:it.state==='free'?.45:.35}}
        l.setStyle(o)}
      function popup(it){
        var p=document.createElement('div'),h=document.createElement('strong'),t=[];
        if(it.role==='zone')t.push((it.ztype&&(it.label||'').toLowerCase().indexOf(it.ztype.toLowerCase())!==0?it.ztype+' ':'')+(it.label||''));else{if(it.num)t.push(placeWord+' '+it.num);if(it.label)t.push(it.label);if(!t.length)t.push(it.shop||'Commerce')}
        h.textContent=t.join(' — ');p.appendChild(h);
        var add=function(x){var n=document.createElement('div');n.textContent=x;p.appendChild(n)};
        if(it.zone)add(it.zone);if(it.m2>0)add((it.m2>=100?Math.round(it.m2):it.m2)+' m²');if(it.note)add(it.note);
        if(it.role==='slot'&&it.price!=null)add('Tarif : '+eur(it.price)+(it.price_pro!=null&&it.price_pro!==it.price?' (entreprise : '+eur(it.price_pro)+')':''));
        if(it.role==='slot'&&it.state)add({free:placeWord+' libre',pending:'Demande en cours',taken:placeWord+' indisponible'}[it.state]);
        if(it.sector){var sa=document.createElement('a');sa.href=it.sector;sa.textContent='Voir ce secteur →';sa.style.display='block';p.appendChild(sa)}
        if(it.url){var a=document.createElement('a');a.href=it.url;a.target='_blank';a.rel='noopener';a.textContent=it.shop?'Boutique '+it.shop+' ↗':'Boutique ↗';a.style.display='block';p.appendChild(a)}
        if(bookMode&&it.role==='slot'&&it.free){var bt=document.createElement('button');bt.type='button';bt.textContent='Demander cet emplacement';bt.style.cssText='margin-top:6px;padding:6px 10px;cursor:pointer;';bt.addEventListener('click',function(){openForm(it);map.closePopup()});p.appendChild(bt)}
        return p}
      function openForm(it){var f=document.getElementById('plan-form');f.style.display='grid';f.hidden=false;f.slot.value=it.id;
        var nm=[it.num?placeWord+' '+it.num:placeWord,it.zone].filter(Boolean).join(' — ');document.getElementById('plan-form-title').textContent='Demande pour : '+nm;
        function price(){var pr=f.kind.value==='entreprise'&&it.price_pro!=null?it.price_pro:it.price;document.getElementById('plan-form-price').textContent=pr!=null?'Tarif : '+eur(pr)+' ('+(f.kind.value==='entreprise'?'entreprise':'particulier')+')':'Tarif communiqué par l\'organisateur'}
        function syncKind(){
          var t=f.kind.value;
          [].forEach.call(f.querySelectorAll('[data-ct]'),function(g){
            var on=g.getAttribute('data-ct').split(' ').indexOf(t)>=0,need=on&&g.querySelector('.pf-req')!==null;
            g.hidden=!on;
            [].forEach.call(g.querySelectorAll('input,select,textarea'),function(i){
              i.disabled=!on;
              if(i.type!=='file')i.required=on&&(need?i.dataset.opt!=='1':i.dataset.req==='1');
              if(!on&&i.type==='checkbox')i.checked=false;
            });
          });
        }
        f.kind.onchange=function(){syncKind();price()};syncKind();price();document.getElementById('plan-form-msg').textContent='';f.scrollIntoView({behavior:'smooth',block:'center'})}
      function render(){layers.forEach(function(x){x.l.remove()});layers=[];var all=[];
        d.items.forEach(function(it){
          var l;
          if(it.kind==='marker')l=L.marker(it.pts[0],{icon:L.divIcon({className:'',html:'<span style="display:block;width:18px;height:18px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.45),0 2px 5px rgba(0,0,0,.35);background:'+it.color+'"></span>',iconSize:[18,18],iconAnchor:[9,9]})});
          else{l=it.kind==='polygon'?L.polygon(it.pts):L.polyline(it.pts,{color:it.color,weight:3});if(it.kind==='polygon')style(it,l)}
          if(it.role==='zone'||it.role==='territoire'||it.role==='secteur'){var bd=l.getBounds(),sp=document.createElement('span');sp.style.cssText='display:inline-block;transform:translate(-50%,-100%);white-space:nowrap;font:700 11px/1.3 system-ui,sans-serif;letter-spacing:.04em;text-transform:uppercase;color:#222;background:rgba(255,255,255,.88);border:1px solid rgba(0,0,0,.3);border-radius:4px;padding:2px 7px;pointer-events:none';sp.textContent=(it.role==='territoire'?'⬡ ':'')+(it.ztype&&(it.label||'').toLowerCase().indexOf(it.ztype.toLowerCase())!==0?it.ztype+' · ':'')+(it.label||'');
            var zl=L.marker([bd.getNorth(),bd.getCenter().lng],{icon:L.divIcon({className:'',html:sp,iconSize:[0,0]}),interactive:false,keyboard:false});layers.push({l:zl});zl.addTo(map)}
          else if(it.role!=='territoire'&&it.role!=='secteur'){var tt=[it.num,it.label].filter(Boolean).join(' · ');if(tt){var t=document.createElement('span');t.textContent=tt;l.bindTooltip(t,it.kind==='marker'?{permanent:true,direction:'top',offset:[0,-10]}:{permanent:true,direction:'center'})}}
          l.bindPopup(function(){return popup(it)});l.addTo(map);if((it.role==='zone'||it.role==='territoire'||it.role==='secteur')&&l.bringToBack)l.bringToBack();layers.push({l:l});if(it.role!=='territoire'&&it.role!=='secteur')all.push(l)});
        return all}
      b.addEventListener('click',function(){b.textContent='Chargement…';
        Promise.all([load('https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css','css'),load('https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js','js')]).then(function(){
          box.textContent='';
          map=L.map(box,{scrollWheelZoom:false,maxZoom:22}).setView(d.center||[46.6,2.4],d.center?d.zoom:6);
          if(d.base==='none'){box.style.background='linear-gradient(rgba(0,0,0,.06) 1px,transparent 1px) 0 0/40px 40px, linear-gradient(90deg,rgba(0,0,0,.06) 1px,transparent 1px) 0 0/40px 40px, #f6f3ec'}
          else{var sat=d.base==='sat',tl=L.tileLayer(d.tiles[sat?'sat':'osm'],{maxZoom:22,maxNativeZoom:19,attribution:sat?'Imagerie © <a href="https://www.ign.fr" target="_blank" rel="noopener">IGN</a>':'Plan © <a href="https://www.ign.fr" target="_blank" rel="noopener">IGN</a>'});
            tl.on('tileerror',function(){if(!map.hasLayer(tl))return;map.removeLayer(tl);
              tl=sat?L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:22,maxNativeZoom:19,attribution:'Imagerie © Esri, Maxar, Earthstar Geographics'})
                :L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png',{subdomains:'abcd',maxZoom:22,maxNativeZoom:20,attribution:'© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> © <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>'});
              tl.addTo(map)});
            tl.addTo(map)}
          L.control.scale({metric:true,imperial:false}).addTo(map);
          if(d.background){map.createPane('planBgPane').style.zIndex=350;L.imageOverlay('/'+d.background.path,d.background.bounds,{pane:'planBgPane',opacity:d.background.opacity||.7}).addTo(map)}
          var all=render();
          var fo=d.focus&&d.items.filter(function(it){return it.id===d.focus})[0];
          if(fo)map.fitBounds(L.polygon(fo.pts).getBounds().pad(.15),{animate:false});
          else if(!d.center&&all.length)map.fitBounds(L.featureGroup(all).getBounds().pad(.2));
          var bk=document.getElementById('plan-book');
          if(bk)bk.addEventListener('click',function(){bookMode=!bookMode;bk.textContent=bookMode?'Quitter le choix d\'emplacement':'Je suis exposant : choisir mon emplacement';document.getElementById('plan-legend').style.display=bookMode?'flex':'none';document.getElementById('plan-legend').hidden=!bookMode;
            d.items.forEach(function(it,i){});render();if(bookMode)document.getElementById('plan-box').scrollIntoView({behavior:'smooth',block:'center'})});
          var f=document.getElementById('plan-form');
          if(f){f.addEventListener('submit',function(e){e.preventDefault();var m=document.getElementById('plan-form-msg'),fd=new FormData(f);fd.set('g',d.booking.slug);
            m.textContent='Un instant…';fetch(d.booking.endpoint,{method:'POST',headers:{Accept:'application/json'},body:fd}).then(function(r){return r.json()})
              .then(function(r){m.textContent=r.error||r.message;if(!r.error){d.items.forEach(function(it){if(it.id===fd.get('slot')){it.state='pending';it.free=false}});render();f.reset();if(f.kind.onchange)f.kind.onchange()}}).catch(function(){m.textContent='Envoi impossible, réessayez.'})});
            document.getElementById('plan-form-cancel').addEventListener('click',function(){f.style.display='none';f.hidden=true})}
        }).catch(function(){b.textContent='Le plan est indisponible pour le moment.'})});})();
      </script>
    </section>
    <?php endif; ?>

    <?php if ($shops): ?>
    <?php
      // Promotions : articles retenus par la galerie (à la main, application de prise de vue ou connecteur) et visuels promo des commerces membres.
      // Chaque affichage et chaque clic est compté (monétisation de la mise en avant).
      $promoItems = gallery_promos_merge($data['products'], $gallery['promos'] ?? []);
      $promoTrack = $gallery['published'];   // l'aperçu d'une galerie non publiée n'est pas compté
    ?>
    <?php if ($promoItems): ?>
    <section id="promos" class="promo-section" aria-labelledby="h-promos">
      <h2 id="h-promos">Les promos du moment</h2>
      <div class="promo-grid">
        <?php foreach ($promoItems as $p):
          $placement = 'galerie:' . $gallery['slug'];
          $tok = promo_token((string) $p['shop'], (string) $p['ref'], $placement);
          $trackable = $promoTrack && !empty($p['tracked']);
          $href = $trackable ? '/galerie/clic.php?g=' . rawurlencode($gallery['slug']) . '&s=' . rawurlencode((string) $p['shop']) . '&r=' . rawurlencode((string) $p['ref']) . '&t=' . $tok : $p['url'];
          $shopName = ($p['shop_name'] ?? '') !== '' ? (string) $p['shop_name'] : ($shops[$p['shop']]['name'] ?? '');
          $alt = $p['name'] . ' — ' . ($p['promo_price'] !== '' ? $p['promo_price'] . ' au lieu de ' . $p['price'] : $p['price']) . ($shopName !== '' ? ', chez ' . $shopName : '') . '. ' . ($p['cta'] ?: 'Voir l\'offre');
          $track = $trackable ? ' data-promo-track data-promo-shop="' . e($p['shop']) . '" data-promo-ref="' . e($p['ref']) . '" data-promo-placement="' . e($placement) . '" data-promo-token="' . e($tok) . '"' : '';
        ?>
          <?php if (!empty($p['fallback'])): ?>
            <a class="promo-card" href="<?= e($href) ?>"<?= $track ?>>
              <span class="promo-card-photo"><?php if ($p['photo'] !== ''): ?><img src="<?= e($p['photo']) ?>" alt="" loading="lazy" onerror="this.remove()"><?php else: ?><b aria-hidden="true"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></b><?php endif; ?></span>
              <span class="promo-card-body">
                <?php if ($shopName !== ''): ?><span class="promo-card-shop"><?= e($shopName) ?></span><?php endif; ?>
                <span class="promo-card-name"><?= e($p['name']) ?></span>
                <span class="promo-card-price"><?php if ($p['promo_price'] !== ''): ?><strong><?= e($p['promo_price']) ?></strong> <s><?= e($p['price']) ?></s><?php else: ?><strong><?= e($p['price'] ?: 'Voir le prix') ?></strong><?php endif; ?></span>
                <span class="promo-card-cta"><?= e($p['cta'] ?: 'Voir l’offre') ?> →</span>
              </span>
            </a>
          <?php else: ?>
            <a class="promo-visual" href="<?= e($href) ?>"<?= $track ?>>
              <picture><?php if ($p['promo_visual_mobile'] !== ''): ?><source media="(max-width: 700px)" srcset="<?= e($p['promo_visual_mobile']) ?>"><?php endif; ?><img src="<?= e($p['promo_visual']) ?>" alt="<?= e($alt) ?>" loading="lazy"></picture>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>
    <link rel="stylesheet" href="/assets/promo.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/promo.css') ?>">
    <script src="/assets/promo-track.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/promo-track.js') ?>" defer></script>
    <?php endif; ?>

    <section id="pieces" aria-labelledby="h-pieces">
      <h2 id="h-pieces"><?= e($st['pieces_title'] !== '' ? $st['pieces_title'] : 'Toutes les pièces') ?></h2>
      <form class="filters" method="get" action="<?= e(strtok($_SERVER['REQUEST_URI'] ?? '/', '?')) ?>">
        <?php if (isset($_GET['apercu'])): ?><input type="hidden" name="apercu" value="<?= e($_GET['apercu']) ?>"><?php endif; ?>
        <label class="wide">Recherche<input type="search" name="q" value="<?= e($q) ?>" placeholder="Un objet, une matière, un commerce…"></label>
        <label>Commerce<select name="commerce"><option value="">Tous</option>
          <?php foreach ($shops as $key => $s): ?><option value="<?= e($key) ?>"<?= $shopFilter === $key ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
        <label>Univers<select name="cat"><option value="">Tous</option>
          <?php foreach ($universes as $label => $n): ?><option value="<?= e($label) ?>"<?= $catFilter === $label ? ' selected' : '' ?>><?= e($label) ?> (<?= (int) $n ?>)</option><?php endforeach; ?></select></label>
        <label>Prix min (€)<input type="number" name="min" min="0" step="1" inputmode="numeric" value="<?= $min !== null ? e($min) : '' ?>"></label>
        <label>Prix max (€)<input type="number" name="max" min="0" step="1" inputmode="numeric" value="<?= $max !== null ? e($max) : '' ?>"></label>
        <label>Trier par<select name="tri">
          <?php foreach (['recent' => 'Nouveautés', 'prix-asc' => 'Prix croissant', 'prix-desc' => 'Prix décroissant', 'nom' => 'Nom'] as $k => $l): ?><option value="<?= $k ?>"<?= $sort === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
        <button class="btn" type="submit">Filtrer</button>
      </form>
      <?php if ($activeFilters): ?>
        <div class="chips" aria-label="Filtres actifs">
          <?php foreach ($activeFilters as $k => $label): ?><a class="chip" href="<?= e(gal_url([$k => null])) ?>#pieces"><?= e($label) ?> <b aria-hidden="true">×</b><span class="skip">Retirer ce filtre</span></a><?php endforeach; ?>
          <a class="chip" href="<?= e(gal_url(['q' => null, 'commerce' => null, 'cat' => null, 'min' => null, 'max' => null])) ?>#pieces">Tout effacer</a>
        </div>
      <?php endif; ?>

      <p class="count" aria-live="polite"><?= $total ?> pièce<?= $total > 1 ? 's' : '' ?><?= $total > $perPage ? ' · page ' . $page . ' sur ' . $pages : '' ?></p>

      <?php if ($visible): ?>
        <div class="grid">
          <?php foreach ($visible as $p): $s = $shops[$p['shop']]; ?>
            <article class="card">
              <span class="ph">
                <span class="none" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
                <?php if ($p['photo'] !== ''): ?><img src="<?= e($p['photo']) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async" onerror="this.remove()"><?php endif; ?>
                <?php if ($p['badge'] !== ''): ?><span class="badge"><?= e($p['badge']) ?></span><?php endif; ?>
              </span>
              <div class="body">
                <h3><a href="<?= e($p['url']) ?>"><?= e($p['name']) ?></a></h3>
                <a class="by" href="<?= e(gal_url(['commerce' => $p['shop']])) ?>#pieces"><?= e($s['name']) ?></a>
                <?php if ($p['price'] !== '' || $p['promo_price'] !== ''): ?>
                  <div class="price"><?php if ($p['promo_price'] !== ''): ?><?= e($p['promo_price']) ?><s><?= e($p['price']) ?></s><?php else: ?><?= e($p['price']) ?><?php endif; ?></div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if ($pages > 1): ?>
          <nav class="pager" aria-label="Pagination">
            <?php if ($page > 1): ?><a href="<?= e(gal_url(['page' => $page - 1])) ?>#pieces" rel="prev">← Précédent</a><?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
              <?php if ($i === $page): ?><span class="cur" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= e(gal_url(['page' => $i])) ?>#pieces"><?= $i ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $pages): ?><a href="<?= e(gal_url(['page' => $page + 1])) ?>#pieces" rel="next">Suivant →</a><?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php else: ?>
        <p class="empty"><?= $activeFilters ? 'Aucune pièce ne correspond à ces filtres.' : 'Aucune pièce en ligne pour le moment.' ?>
          <?php if ($activeFilters): ?><br><a href="<?= e(gal_url(['q' => null, 'commerce' => null, 'cat' => null, 'min' => null, 'max' => null])) ?>#pieces">Tout effacer</a><?php endif; ?></p>
      <?php endif; ?>
    </section>
    <?php elseif (!$planData && !$hasEventSection): ?>
      <section><p class="empty"><?= e($vocab['empty'] ?? "Cette galerie n'a pas encore de commerce.") ?></p></section>
    <?php endif; ?>

    <?php if ($gallery['published'] && function_exists('gt_feature') && gt_feature($gallery, 'bourse')):
      $gbSearches = array_slice(gbo_public_searches($gallery['slug']), 0, 3);
      $gbCount = count(gbo_public_searches($gallery['slug']));
      $gbUrl = '/galerie/bourse.php?g=' . rawurlencode($gallery['slug']); ?>
      <section id="bourse" aria-labelledby="h-bourse">
        <h2 id="h-bourse">Bourse d’échanges</h2>
        <p class="lede">Une pièce à vendre — un meuble, un service de table, un tableau ? Proposez-la à la galerie. Vous pouvez aussi retrouver
          les pièces que nous recherchons en ce moment.</p>
        <?php if ($gbSearches): ?>
          <div style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin:4px 0 16px;">
            <?php foreach ($gbSearches as $gs): ?>
              <article style="background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:13px 15px;">
                <h3 style="margin:0 0 4px;font-size:1rem;"><?= e($gs['title']) ?></h3>
                <p style="margin:0 0 8px;color:var(--ink-soft);font-size:.88rem;overflow-wrap:anywhere;"><?= e(mb_strimwidth($gs['description'], 0, 160, '…')) ?></p>
                <p style="margin:0;color:var(--ink-soft);font-size:.78rem;">
                  <?php if ($gs['category'] !== ''): ?><?= e($gs['category']) ?><?php endif; ?>
                  <?php if ($gs['price_cents']): ?><?= $gs['category'] !== '' ? ' · ' : '' ?>budget jusqu’à <?= e(gbo_money((int) $gs['price_cents'])) ?><?php endif; ?>
                </p>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <p><a class="btn" href="<?= e($gbUrl) ?>">Proposer une pièce<?= $gbCount ? ' · voir les ' . $gbCount . ' recherche' . ($gbCount > 1 ? 's' : '') : '' ?></a></p>
      </section>
    <?php endif; ?>
  </main>
<?php endif; ?>

<?php if ($canSignup && ($slug === '' || ($gallery && $gallery['published'] && $st['show_cta']))):
    $ctaUrl = '/inscription/' . ($slug !== '' ? '?galerie=' . rawurlencode($slug) : ''); ?>
<section class="cta-band" id="cta-boutique"><div class="wrap in">
  <div>
    <h2><?= $slug !== '' ? 'Vous êtes commerçant ? Rejoignez ' . e($gallery['name']) : 'Vous êtes commerçant ? Créez votre boutique' ?></h2>
    <p class="lede" style="margin:0">Votre boutique en ligne, prête en quelques minutes, avec son panier et son paiement<?= $slug !== '' ? ' — et vos pièces visibles ici, avec celles des autres commerces' : ', seule ou réunie avec d\'autres commerçants dans une galerie' ?>.</p>
    <ul><li>Vous choisissez votre formule, vous créez votre compte : aucune validation à attendre</li>
      <li>Vous arrivez directement sur votre boutique, à votre image (modèle au choix)</li>
      <li>Photos depuis le téléphone, fiches préparées par l'IA</li></ul>
  </div>
  <div class="act"><a class="btn-cta" href="<?= e($ctaUrl) ?>"<?= cta_attrs('galerie_band_cta') ?>><?= e(cta_label('galerie_band_cta')) ?></a>
    <span class="from"><?= e(saas_grid_from_text(saas_grid($slug))) ?> · mensuel ou annuel · sans engagement</span></div>
</div></section>
<a class="cta-float" href="<?= e($ctaUrl) ?>"<?= cta_attrs('galerie_float_cta') ?>><?= e(cta_label('galerie_float_cta')) ?></a>
<?php endif; ?>

<?php if ($slug !== '' && $gallery && $gallery['published']): ?>
<section class="gal-news" style="max-width:640px;margin:40px auto 0;padding:0 16px;">
  <h2 style="margin:0 0 6px;font-size:1.15rem;">Les nouveautés de la galerie</h2>
  <p style="margin:0 0 10px;color:var(--ink-soft);font-size:.92rem;">Recevez un message de temps en temps quand de nouvelles pièces arrivent. Désinscription en un clic.</p>
  <form id="gal-news" style="display:flex;gap:8px;flex-wrap:wrap;" autocomplete="off">
    <input type="email" name="email" required maxlength="160" placeholder="Votre e-mail" aria-label="Votre e-mail" style="flex:1;min-width:200px;padding:10px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
    <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
    <button type="submit" class="btn-cta" style="border:0;cursor:pointer;">Je m'abonne</button>
  </form>
  <p id="gal-news-msg" role="status" style="margin:8px 0 0;font-size:.9rem;"></p>
</section>
<script>
(function(){var f=document.getElementById('gal-news');if(!f)return;f.addEventListener('submit',function(e){e.preventDefault();var m=document.getElementById('gal-news-msg'),d=new URLSearchParams(new FormData(f));d.set('a','subscribe');d.set('g',<?= json_encode($slug) ?>);
fetch('/galerie/contact.php',{method:'POST',headers:{Accept:'application/json'},body:d}).then(function(r){return r.json()}).then(function(r){m.textContent=r.error||r.message;if(!r.error)f.reset()}).catch(function(){m.textContent='Envoi impossible, réessayez.'})});})();
</script>
<?php endif; ?>

<?php if ($slug === ''): ?>
<section style="max-width:640px;margin:36px auto 0;padding:0 16px;text-align:center;">
  <h2 style="margin:0 0 6px;font-size:1.2rem;">Vous voulez créer votre propre galerie ?</h2>
  <p style="margin:0 0 12px;color:var(--ink-soft);">Test gratuit, puis abonnement selon le nombre de commerces.</p>
  <a class="btn-cta" href="/galerie/creer.php"<?= cta_attrs('galerie_create_cta') ?>><?= e(cta_label('galerie_create_cta')) ?></a>
</section>
<?php endif; ?>

<footer><div class="wrap">
  <?php if ($slug !== ''): ?><a href="/galerie/">← Toutes les galeries</a> · <?php endif; ?><?= e($st['footer_text'] !== '' && $slug !== '' ? $st['footer_text'] : 'Les pièces sont vendues et expédiées par les commerces eux-mêmes.') ?>
  · <a href="<?= e(ga_login_url($slug)) ?>" rel="nofollow">Gérer ma galerie</a>
  <?php if ($slug !== '' && $gallery && $gallery['published'] && function_exists('gt_feature') && gt_feature($gallery, 'bourse')): ?>
    · <a href="/galerie/bourse.php?g=<?= e(rawurlencode($slug)) ?>">Bourse d’échanges</a>
  <?php endif; ?>
</div></footer>
<?= banner_slides_script() ?>
<?= gt_landing_preview_assets() ?>
<script src="/assets/cta-track.js?v=1" defer></script>
</body>
</html>
