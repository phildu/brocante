<?php
// Entrée d'installation de l'application « Vente » (caisse) : page d'enregistrement sur l'écran d'accueil,
// puis ouverture du Studio sur l'onglet Caisse (studio.php?tab=pos).
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio-theme.php';
require_once __DIR__ . '/includes/apps.php';

header('Cache-Control: no-store');
$theme = studio_theme_colors();
$base = tenant_base_path();
$name = shop_app_name('vente');
$site = trim((string) get_content()['site_name']) ?: (string) tenant('name');
$logo = logo_url('vertical');
$icon = $base . '/vente-icon.php?s=192';
$caisse = $base . '/studio.php?tab=pos';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h($name) ?></title>
<meta name="theme-color" content="<?= h($theme['bg']) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h($name) ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="<?= h($base) ?>/vente-manifest.php">
<link rel="apple-touch-icon" href="<?= h($base) ?>/vente-icon.php?s=180">
<script src="/assets/pwa-save.js?v=<?= (int) @filemtime(__DIR__ . '/assets/pwa-save.js') ?>" data-auto data-delay="900" data-key="vente" data-name="<?= h($name) ?>" data-icon="<?= h($icon) ?>" data-manifest="<?= h($base) ?>/vente-manifest.php" data-sw="<?= h($base) ?>/pwa-sw.php" data-scope="<?= h($base) ?>/"></script>
<style>
  :root { --bg: <?= h($theme['bg']) ?>; --ink: #1f1f1f; --accent: <?= h($theme['accent']) ?>; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { width: min(440px, 100%); display: grid; gap: 16px; text-align: center; }
  .logo { width: min(220px, 70%); height: auto; margin: 0 auto; }
  .icon { width: 88px; height: 88px; border-radius: 22px; margin: 0 auto; box-shadow: 0 12px 32px rgba(0,0,0,.18); }
  h1 { margin: 0; font-size: 1.55rem; line-height: 1.2; }
  p { margin: 0; color: #555; }
  .card { background: #fff; border: 1px solid #e2ddd2; border-radius: 16px; padding: 20px; display: grid; gap: 14px; }
  .go { display: inline-flex; align-items: center; justify-content: center; min-height: 50px; padding: 12px 20px; border-radius: 12px; background: var(--accent); color: #fff; font-weight: 700; text-decoration: none; }
  .hint { font-size: .86rem; color: #666; }
</style>
</head>
<body>
<main>
  <?php if ($logo !== ''): ?><img class="logo" src="/<?= h($logo) ?>" alt="<?= h($site) ?>"><?php else: ?><img class="icon" src="<?= h($icon) ?>" alt=""><?php endif; ?>
  <h1><?= h($name) ?></h1>
  <div class="card">
    <p>L'application de caisse : scanner un billet de retrait, encaisser une vente en boutique et valider une commande en ligne.</p>
    <a class="go" href="<?= h($caisse) ?>">Ouvrir la caisse</a>
    <p class="hint">Ajoutez-la à l'écran d'accueil avec le bouton d'installation de votre navigateur : elle s'ouvrira ensuite en plein écran, directement sur la caisse.</p>
  </div>
</main>
</body>
</html>
