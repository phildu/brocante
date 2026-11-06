<?php
/**
 * Gabarits HTML du module Comptage : un pour l'administration (responsable),
 * un pour la page de connexion bénévole. Le compteur lui-même
 * (comptage/index.php) est une page autonome plein écran.
 */

require_once __DIR__ . '/comptage.php';
require_once __DIR__ . '/shop-modules.php';

const CP_FONTS = 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap';

/** Session + droits admin + schéma : à appeler en tête de chaque page admin/comptage/. */
function cp_admin_init(): void
{
    require_once __DIR__ . '/session.php';
    require_admin();
    shop_module_require('comptage-foule');   // plugin activé + accès payé, en essai, offert ou inclus (includes/shop-modules.php)
    cp_boot();
}

function cp_admin_sections(): array
{
    return [
        'resultats' => ['/admin/comptage/index.php', 'Résultats'],
        'evenements' => ['/admin/comptage/evenements.php', 'Événements & postes'],
        'acces' => ['/admin/comptage/acces.php', 'Accès bénévoles'],
    ];
}

function cp_asset_url(string $path): string
{
    $file = dirname(__DIR__) . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function cp_admin_header(string $title, string $section): void
{
    $content = get_content();
    $flash = flash_get();
    $adminNav = 'plugin:comptage-foule:index';
    $siteName = $content['site_name'] ?? '';
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — Comptage — <?= h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="<?= h(CP_FONTS) ?>" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="<?= h(cp_asset_url('/assets/buvette.css')) ?>">
<link rel="stylesheet" href="<?= h(cp_asset_url('/assets/dashboard.css')) ?>">
<link rel="stylesheet" href="<?= h(cp_asset_url('/assets/comptage.css')) ?>">
</head>
<body class="bv-admin cp-admin">
<?php include __DIR__ . '/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <nav class="bv-subnav bv-noprint" aria-label="Sections du comptage">
      <?php foreach (cp_admin_sections() as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <h1 class="bv-title"><?= h($title) ?></h1>

    <?php if ($flash): ?>
      <p class="publish-status bv-flash" role="status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p>
    <?php endif; ?>
<?php
}

function cp_admin_footer(): void
{
    ?>
  </div>
</section>
</main>
<script>
// Confirmation avant les actions destructrices : <form data-confirm="…">
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});
</script>
</body>
</html>
<?php
}

/** Page de connexion bénévole (même esprit que celle de la buvette). */
function cp_login_page(?string $error): void
{
    $content = get_content();
    $siteName = $content['site_name'] ?? 'La Brocante';
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#b5502e">
<title>Comptage — <?= h($siteName) ?></title>
<link href="<?= h(CP_FONTS) ?>" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/comptage.css">
</head>
<body class="cp-login-page">
<main class="cp-login">
  <p class="eyebrow">Comptage de foule</p>
  <h1><?= h($siteName) ?></h1>
  <p>Entrez le code que vous a donné le responsable pour ouvrir le compteur.</p>
  <?php if ($error): ?><p class="publish-status" data-kind="error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?= cp_form_open('login') ?>
    <input type="text" name="code" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="Code" required autofocus aria-label="Code d'accès">
    <button class="btn btn-primary" type="submit">Ouvrir le compteur</button>
  </form>
</main>
</body>
</html>
<?php
}
