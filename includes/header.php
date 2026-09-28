<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/functions.php';
$activeNav = $activeNav ?? '';
$cartCount = cart_count();
$siteContent = get_content();
$siteName = $siteContent['site_name'] ?: tenant('name');
$siteTagline = $siteContent['site_tagline'] ?: tenant('tagline');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? h($pageTitle) . ' — ' . h($siteName) : h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php include __DIR__ . '/icons.php'; ?>
<header class="site">
  <div class="wrap site-bar">
    <a class="wordmark" href="/index.php">
      <img src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($siteName) ?>" class="site-logo">
      <span class="tag"><?= h($siteTagline) ?></span>
    </a>
    <nav class="primary" aria-label="Navigation principale" id="primary-nav">
      <a href="/index.php"<?= $activeNav === 'accueil' ? ' aria-current="page"' : '' ?>>Accueil</a>
      <a href="/boutique.php"<?= $activeNav === 'boutique' ? ' aria-current="page"' : '' ?>>La boutique</a>
      <a href="/index.php#histoire">Notre histoire</a>
      <a href="/index.php#contact">Contact</a>
      <a href="/cart.php">Panier<?= $cartCount ? ' (' . $cartCount . ')' : '' ?></a>
      <?php if (is_admin_logged_in()): ?>
        <a class="nav-cta" href="/admin/catalog.php">Administration</a>
      <?php else: ?>
        <a class="nav-cta" href="/admin/login.php">Connexion</a>
      <?php endif; ?>
    </nav>
    <button type="button" class="burger-btn" id="burger-btn" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="primary-nav">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>
<div class="nav-overlay" id="nav-overlay"></div>
<main>
<?php $flash = flash_get(); if ($flash): ?>
  <div class="wrap" style="padding-top:24px;">
    <p class="publish-status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p>
  </div>
<?php endif; ?>
