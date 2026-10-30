<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/oauth.php';
require_once __DIR__ . '/share.php';
require_once __DIR__ . '/plugins.php';
plugin_fire('front_view');
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
    require_once __DIR__ . '/studio-theme.php'; $pwaTheme = studio_theme_colors(); ?>
<link rel="manifest" href="/pwa-manifest.php">
<meta name="theme-color" content="<?= h($pwaTheme['bg']) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(mb_substr($siteName, 0, 14)) ?>">
<link rel="apple-touch-icon" href="/pwa-icon.php?s=180">
<script src="/assets/pwa-install.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/pwa-install.js') ?>" defer data-sw="/pwa-sw.php" data-scope="<?= h(tenant_base_path() . '/') ?>" data-name="<?= h($siteName) ?>" data-icon="/pwa-icon.php?s=192"></script>
<?php endif; ?>
<link rel="stylesheet" href="/assets/share.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.css') ?>">
<script src="/assets/share.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.js') ?>" defer></script>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php include __DIR__ . '/icons.php'; ?>
<?php if ($tplPreviewKey = shop_template_preview()): ?>
<div class="template-preview-bar" role="status">Aperçu du modèle <strong><?= h(SHOP_TEMPLATES[$tplPreviewKey]['label']) ?></strong> — rien n'est enregistré.
  <a href="?modele=0">Quitter l'aperçu</a></div>
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
      <a href="/index.php#histoire">Notre histoire</a>
      <a href="/index.php#contact">Contact</a>
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
