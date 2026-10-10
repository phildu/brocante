<?php
// Page publique d'une galerie commerciale : /galerie/<identifiant>/ (réécrit vers ce fichier, voir .htaccess et router.php).
// Sans identifiant : la liste des galeries publiées. Les données viennent de includes/galleries.php (catalogue regroupé, mis en cache).
require_once __DIR__ . '/../includes/galleries.php';

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

// ── Liste des galeries ──
if ($slug === '') {
    $galleries = array_filter(gallery_list(), static fn (array $g): bool => $g['published']);
    $pageTitle = 'Galeries commerciales';
    $accent = '#b5502e';
    $gallery = null;
} else {
    $gallery = gallery_load($slug);
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
    }
}
$ink = gal_ink_on($accent);
// Habillage choisi dans le portail (palette, polices, bannière, logo, présentation) ; sans galerie (liste), l'habillage d'origine.
$theme = $gallery ? gallery_theme($gallery) : null;
$st = $gallery['style'] ?? gallery_style_normalize([]);

// ── Filtres (galerie) ──
$data = ['shops' => [], 'products' => [], 'skipped' => []];
$products = [];
$total = 0;
$q = $shopFilter = $catFilter = $sort = '';
$min = $max = null;
$page = 1;
$perPage = $st['per_page'];
$universes = [];
if ($gallery) {
    $data = gallery_catalogue($gallery, $origin);
    $shops = $data['shops'];
    uasort($shops, static fn (array $a, array $b): int => [$b['featured'], $a['order']] <=> [$a['featured'], $b['order']]);
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
$metaDescription = $gallery ? ($gallery['tagline'] ?: 'Les pièces de ' . count($data['shops']) . ' commerces réunies au même endroit.') : 'Des commerçants réunis au même endroit.';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?><?= $gallery ? ' — galerie commerciale' : '' ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($metaDescription) ?>">
<?php if ($gallery && !empty($st['banner'])): ?><meta property="og:image" content="<?= e($origin . '/' . $st['banner']) ?>"><?php endif; ?>
<?php if (!empty($notFound) || ($gallery && !$gallery['published'])): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
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
  .hero-media { position: absolute; inset: 0; background-size: cover; z-index: 0; } .hero-shade { position: absolute; inset: 0; z-index: 1; }
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
    <?php if ($canSignup): ?>
      <p class="hero-cta"><a class="btn-cta" href="/inscription/">Créer ma boutique</a>
        <span class="from"><?= e(saas_grid_from_text(saas_grid(''))) ?> · sans engagement</span><a class="alt" href="/#formules">Voir les tarifs</a></p>
    <?php endif; ?>
  </div></header>
  <main id="contenu" class="wrap">
    <section>
      <?php if ($galleries): ?>
        <div class="galleries">
          <?php foreach ($galleries as $g): ?>
            <a class="gcard" href="/galerie/<?= e($g['slug']) ?>/"><div class="band" style="background:<?= e($g['accent']) ?>"></div>
              <div class="in"><h3><?= e($g['name']) ?></h3><p><?= e($g['tagline'] ?: count($g['members']) . ' commerce' . (count($g['members']) > 1 ? 's' : '')) ?></p></div></a>
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
  <header class="hero<?= $st['banner'] !== '' ? ' has-banner' : '' ?> h-<?= e($st['hero_height']) ?> a-<?= e($st['hero_align']) ?>">
    <?php if ($st['banner'] !== ''): ?><div class="hero-media" style="background-image:url('/<?= e($st['banner']) ?>');background-position:center <?= e($st['banner_pos']) ?>"></div><div class="hero-shade" style="background:rgba(0,0,0,<?= round($st['banner_overlay'] / 100, 2) ?>)"></div><?php endif; ?>
    <div class="wrap">
    <?php if ($st['logo'] !== ''): ?><img class="hero-logo" src="/<?= e($st['logo']) ?>" alt="<?= e($gallery['name']) ?>"><?php endif; ?>
    <p class="eyebrow">Galerie commerciale<?= $gallery['published'] ? '' : ' · aperçu non publié' ?></p>
    <h1><?= e($gallery['name']) ?></h1>
    <?php if ($gallery['tagline'] !== ''): ?><p class="tagline"><?= e($gallery['tagline']) ?></p><?php endif; ?>
    <?php if ($gallery['description'] !== ''): ?><p class="intro"><?= nl2br(e($gallery['description'])) ?></p><?php endif; ?>
    <?php if ($st['show_stats']): ?><ul class="stats">
      <li><strong><?= count($shops) ?></strong>commerce<?= count($shops) > 1 ? 's' : '' ?></li>
      <li><strong><?= count($data['products']) ?></strong>pièce<?= count($data['products']) > 1 ? 's' : '' ?> en ligne</li>
      <li><strong><?= count($universes) ?></strong>univers</li>
    </ul><?php endif; ?>
    <?php if ($canSignup && $gallery['published'] && $st['show_cta']): ?>
      <p class="hero-cta"><a class="btn-cta" href="/inscription/?galerie=<?= e($gallery['slug']) ?>"><?= e($st['cta_label'] !== '' ? $st['cta_label'] : 'Créer ma boutique dans cette galerie') ?></a>
        <span class="from"><?= e(saas_grid_from_text(saas_grid($gallery['slug']))) ?> · sans engagement</span><a class="alt" href="#cta-boutique">En savoir plus</a></p>
    <?php endif; ?>
  </div></header>

  <main id="contenu" class="wrap">
    <?php if ($data['skipped']): ?>
      <p class="note">Certains commerces ne répondent pas pour le moment (<?= e(implode(', ', $data['skipped'])) ?>) : leurs pièces reviendront dès que possible.</p>
    <?php endif; ?>

    <?php if ($shops): ?>
    <section aria-labelledby="h-commerces">
      <h2 id="h-commerces"><?= e($st['shops_title'] !== '' ? $st['shops_title'] : 'Les commerces') ?></h2>
      <p class="lede"><?= e($st['shops_lede'] !== '' ? $st['shops_lede'] : 'Chaque commerce a sa boutique, son panier et son paiement : une pièce se règle chez son commerçant.') ?></p>
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
              <a href="<?= e(gal_url(['commerce' => $shopFilter === $key ? null : $key])) ?>#pieces"><?= $shopFilter === $key ? 'Toutes les pièces' : 'Ses pièces' ?></a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($canSignup && $gallery['published'] && $st['show_cta']): ?>
          <a class="shop join" href="/inscription/?galerie=<?= e($gallery['slug']) ?>"><span>＋ Votre boutique ici<br><small style="font-weight:400;color:var(--ink-soft)">Rejoindre <?= e($gallery['name']) ?></small></span></a>
        <?php endif; ?>
      </div>
    </section>

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
    <?php else: ?>
      <section><p class="empty">Cette galerie n'a pas encore de commerce.</p></section>
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
  <div class="act"><a class="btn-cta" href="<?= e($ctaUrl) ?>">Créer ma boutique</a>
    <span class="from"><?= e(saas_grid_from_text(saas_grid($slug))) ?> · mensuel ou annuel · sans engagement</span></div>
</div></section>
<a class="cta-float" href="<?= e($ctaUrl) ?>">Créer ma boutique</a>
<?php endif; ?>

<footer><div class="wrap">
  <?php if ($slug !== ''): ?><a href="/galerie/">← Toutes les galeries</a> · <?php endif; ?><?= e($st['footer_text'] !== '' && $slug !== '' ? $st['footer_text'] : 'Les pièces sont vendues et expédiées par les commerces eux-mêmes.') ?>
</div></footer>
</body>
</html>
