<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/functions.php';
$activeNav = $activeNav ?? '';
$cartCount = cart_count();
$siteContent = get_content();
$siteName = $siteContent['site_name'] ?: 'La Brocante du Petit Chalet';
$siteTagline = $siteContent['site_tagline'] ?: '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? h($pageTitle) . ' — ' . h($siteName) : h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php include __DIR__ . '/icons.php'; ?>
<header class="site">
  <div class="wrap site-bar">
    <a class="wordmark" href="/index.php">
      <img src="/assets/logo.png" alt="<?= h($siteName) ?>" class="site-logo">
      <span class="tag"><?= h($siteTagline) ?></span>
    </a>
    <nav class="primary" aria-label="Navigation principale" id="primary-nav">
      <a href="/index.php"<?= $activeNav === 'accueil' ? ' aria-current="page"' : '' ?>>Accueil</a>
      <a href="/boutique.php"<?= $activeNav === 'boutique' ? ' aria-current="page"' : '' ?>>La boutique</a>
      <a href="/index.php#histoire">Notre histoire</a>
      <a href="/index.php#contact">Contact</a>
      <a href="/cart.php">Panier<?= $cartCount ? ' (' . $cartCount . ')' : '' ?></a>
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
