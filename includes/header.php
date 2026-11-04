<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/oauth.php';
require_once __DIR__ . '/share.php';
require_once __DIR__ . '/apps.php';
require_once __DIR__ . '/plugins.php';
plugin_fire('front_view');

// Statut commercial réglé dans le portail : une boutique en test ou en pause ne s'ouvre au public que par son lien d'aperçu.
// Les retours de paiement restent accessibles pour ne pas perdre une commande déjà réglée.
$shopState = shop_state_load(tenant_slug());
$shopPreview = shop_preview_allowed(tenant_slug(), $_GET['apercu'] ?? null);
$shopStateScript = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
if (!shop_state_is_public($shopState) && !$shopPreview && !in_array($shopStateScript, ['checkout-success.php', 'suivi.php'], true)) {
    $shopName = (string) tenant('name', '');
    $paused = ($shopState['status'] ?? '') === 'paused';
    http_response_code(503);
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Retry-After: 3600');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($shopName) ?> — <?= $paused ? 'boutique en pause' : 'boutique en test' ?></title>
<style>
  :root { color-scheme: light dark; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; font: 16px/1.6 system-ui, sans-serif; background: #f6f1e8; color: #26221c; }
  main { max-width: 520px; text-align: center; }
  h1 { font-size: clamp(1.4rem, 4vw, 2rem); margin: 0 0 10px; }
  p { margin: 0 0 8px; opacity: .78; }
</style>
</head>
<body>
<main>
  <h1><?= h($shopName) ?></h1>
  <?php if ($paused): ?>
    <p>Cette boutique est momentanément en pause.</p>
    <p>Elle reviendra bientôt.</p>
  <?php else: ?>
    <p>Cette boutique est en cours de préparation (mode test).</p>
    <p>Elle n'est pas encore ouverte au public.</p>
  <?php endif; ?>
</main>
</body>
</html><?php
    exit;
}
$activeNav = $activeNav ?? '';
$cartCount = cart_count();
$siteContent = get_content();
$siteName = $siteContent['site_name'] ?: tenant('name');
$siteTagline = $siteContent['site_tagline'] ?: tenant('tagline');
?><!DOCTYPE html>
<html lang="fr" data-layout="<?= h(shop_layout()) ?>"<?= ($themeMode = appearance_theme()) !== 'auto' ? ' data-theme="' . h($themeMode) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? h($pageTitle) . ' — ' . h($siteName) : h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php
// Prévisualisation d'un lien partagé (réseaux sociaux, messageries) : les pages peuvent poser $ogDescription, $ogImage (chemin ou adresse), $ogType, $ogUrl avant d'inclure l'en-tête.
$ogTitle = isset($pageTitle) ? $pageTitle . ' — ' . $siteName : $siteName;
$ogImageUrl = share_media_url((string) ($ogImage ?? logo_url('square')));
echo share_og_html($ogTitle, (string) ($ogDescription ?? ($siteTagline ?: $siteName)), (string) ($ogUrl ?? shop_abs_url(strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/')), $ogImageUrl, (string) ($ogType ?? 'website'), $siteName);
?>
<?= tenant_head_html() ?>
<?php if (shop_setting('pwa_prompt', '1') !== '0'):   // web app : manifeste, icône et incitation à l'installer (Administration → Réglages du site)
    require_once __DIR__ . '/studio-theme.php'; $pwaTheme = studio_theme_colors();
    $pwaBase = tenant_base_path();
    $shopAppName = shop_app_name('shop'); ?>
<link rel="manifest" href="<?= h($pwaBase) ?>/pwa-manifest.php">
<meta name="theme-color" content="<?= h($pwaTheme['bg']) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h($shopAppName) ?>">
<link rel="apple-touch-icon" href="<?= h($pwaBase) ?>/pwa-icon.php?s=180">
<script src="/assets/pwa-save.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/pwa-save.js') ?>" data-auto="0" data-no-prompt="1" data-name="<?= h($shopAppName) ?>" data-icon="<?= h($pwaBase) ?>/pwa-icon.php?s=192" data-done-key="pwa_done" data-snooze-key="pwa_snooze"></script>
<script src="/assets/pwa-install.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/pwa-install.js') ?>" defer data-sw="<?= h($pwaBase) ?>/pwa-sw.php" data-scope="<?= h($pwaBase . '/') ?>" data-name="<?= h($shopAppName) ?>" data-icon="<?= h($pwaBase) ?>/pwa-icon.php?s=192"></script>
<?php endif; ?>
<link rel="stylesheet" href="/assets/share.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.css') ?>">
<script src="/assets/share.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.js') ?>" defer></script>
<link rel="stylesheet" href="/assets/shop-locate.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/shop-locate.css') ?>">
<script src="/assets/shop-locate.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/shop-locate.js') ?>" defer></script>
<link rel="stylesheet" href="/assets/style.css">
<?php foreach ((array) ($pageStyles ?? []) as $pageStyle): ?><link rel="stylesheet" href="<?= h((string) $pageStyle) ?>">
<?php endforeach; ?>
<?php foreach ((array) ($pageScripts ?? []) as $pageScript): ?><script src="<?= h((string) $pageScript) ?>" defer></script>
<?php endforeach; ?>
</head>
<body>
<?php include __DIR__ . '/icons.php'; ?>
<?php if ($tplPreviewKey = shop_template_preview()): ?>
<div class="template-preview-bar" role="status">Aperçu du modèle <strong><?= h(SHOP_TEMPLATES[$tplPreviewKey]['label']) ?></strong> — rien n'est enregistré.
  <a href="?modele=0">Quitter l'aperçu</a></div>
<?php endif; ?>
<?php if (!shop_state_is_public($shopState) && $shopPreview): ?>
<div class="template-preview-bar" role="status">Aperçu privé — boutique <strong><?= h(mb_strtolower(shop_state_label($shopState))) ?></strong>, visible seulement avec ce lien.</div>
<?php endif; ?>
<?php if ($__viewHeader = shop_view('header')): include $__viewHeader; else: ?>
<header class="site">
  <div class="wrap site-bar">
    <a class="wordmark" href="/index.php">
      <img src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($siteName) ?>" class="site-logo">
      <?php if ($siteTagline !== ''): ?><span class="tag"><?= h($siteTagline) ?></span><?php endif; ?>
    </a>
    <nav class="primary" aria-label="Navigation principale" id="primary-nav">
      <a href="/index.php"<?= $activeNav === 'accueil' ? ' aria-current="page"' : '' ?>>Accueil</a>
      <a href="/boutique.php"<?= $activeNav === 'boutique' ? ' aria-current="page"' : '' ?>>La boutique</a>
      <a href="/visite.php"<?= $activeNav === 'visite' ? ' aria-current="page"' : '' ?>>Visite virtuelle</a>
      <a href="/notre-histoire.php"<?= $activeNav === 'histoire' ? ' aria-current="page"' : '' ?>>Notre histoire</a>
      <a href="/nous-trouver.php"<?= $activeNav === 'nous-trouver' ? ' aria-current="page"' : '' ?>>Nous trouver</a>
      <?php if (shop_sales_enabled()): ?><?= cart_nav_link((int) $cartCount) ?><?php endif; ?>
      <?php $customerOn = oauth_enabled_providers('customer') || customer_session(); ?>
      <?php if (is_admin_logged_in()): ?>
        <?php if ($customerOn): ?><a href="/compte.php"<?= $activeNav === 'compte' ? ' aria-current="page"' : '' ?>>Mon compte</a><?php endif; ?>
      <?php elseif (customer_session()): ?>
        <a href="/compte.php"<?= $activeNav === 'compte' ? ' aria-current="page"' : '' ?>>Mon compte</a>
      <?php else: ?>
        <?php // Une seule entrée : /compte.php propose aussi l'accès à l'administration. ?>
        <a class="nav-cta" href="<?= $customerOn ? '/compte.php' : '/admin/login.php' ?>">Connexion</a>
      <?php endif; ?>
    </nav>
    <button type="button" class="burger-btn" id="burger-btn" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="primary-nav">
      <span></span><span></span><span></span>
    </button>
  </div>
  <div class="wrap site-sub">
    <nav class="secondary" aria-label="Navigation secondaire">
      <?php if (!function_exists('shop_member_galleries')) require_once __DIR__ . '/galleries.php'; foreach (shop_member_galleries() as $g): ?><a href="<?= h($g['url']) ?>">Galerie</a><?php break; endforeach; ?>
      <?= share_button_html(share_page_url(), (string) ($ogTitle ?? $siteName), (string) $siteTagline, '', 'sub-share', false, true) ?>
    </nav>
    <?php if (is_admin_logged_in()): ?><a class="sub-admin" href="/admin/dashboard.php">Administration</a><?php endif; ?>
  </div>
</header>
<?php endif; ?>
<?= shop_hours_notice_html() ?>
<div class="nav-overlay" id="nav-overlay"></div>
<main>
<?php $flash = flash_get(); if ($flash): ?>
  <div class="wrap" style="padding-top:24px;">
    <p class="publish-status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?><?php if (!empty($flash['link'])): ?> <a class="flash-cta" href="<?= h($flash['link']['url']) ?>"><?= h($flash['link']['label']) ?> →</a><?php endif; ?></p>
  </div>
<?php endif; ?>
