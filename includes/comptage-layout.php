<?php
/**
 * Gabarits HTML du module Comptage : un pour l'administration (le cadre de l'espace de la galerie),
 * un pour la page de connexion bénévole. Le compteur lui-même
 * (comptage/index.php) est une page autonome plein écran.
 */

require_once __DIR__ . '/comptage.php';

const CP_FONTS = '/assets/fonts.css';

/**
 * Droits + schéma : à appeler en tête de chaque page admin/comptage/. Ces pages ne sont jamais servies seules : c'est
 * portail/galerie-comptage.php (espace d'une galerie) qui les ouvre, après avoir vérifié l'accès et l'extension, et qui place le comptage
 * dans cette galerie (cp_enter_gallery()). Appelée directement (/admin/comptage/…), la page n'existe pas.
 */
function cp_admin_init(): void
{
    if (cp_gallery_context() === null) {
        http_response_code(404);
        exit('Page introuvable.');
    }
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

/** Feuille de style d'appoint : le module est écrit pour le thème de la boutique, il s'affiche ici dans celui du portail. */
function cp_gallery_css(): string
{
    return ':root { --ink-soft: var(--muted); --surface: var(--panel); --surface-2: var(--panel-2); --sage: var(--ok); --font-display: var(--font-ui); --font-body: var(--font-ui); --font-mono: "IBM Plex Mono", monospace; }'
        . '.bv-admin .bv-title { display: none; }'
        . '.cp-admin a:not(.btn):not(.btn-small):not(.bv-subnav a) { color: var(--accent); }'
        . '.cp-admin .btn-small { display: inline-block; padding: 7px 13px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); color: var(--ink); font: inherit; font-size: .84rem; font-weight: 600; text-decoration: none; cursor: pointer; }'
        . '.cp-admin .btn-small:hover { border-color: var(--accent); color: var(--accent); }'
        . '@media print { .pnav, .pbar, .phead, .pnav-veil, .ga-actions { display: none !important; } body.has-admin-shell { padding: 0 !important; } .app, .gwrap, .ga-page { margin: 0 !important; padding: 0 !important; max-width: none !important; } }';
}

/** En-tête d'une page du comptage : le cadre de l'espace de la galerie (portail/_galerie-layout.php), puis les sections du module. */
function cp_admin_header(string $title, string $section): void
{
    $flash = flash_get();
    $css = '';
    foreach (['/assets/buvette.css', '/assets/dashboard.css', '/assets/comptage.css'] as $f) $css .= '<link rel="stylesheet" href="' . h(cp_asset_url($f)) . '">';
    $name = ext_t('Comptage de foule');
    ga_head($title === $name ? $title : $title . ' · ' . $name, '', $css . '<style>' . cp_gallery_css() . '</style>');
    ?>
<div class="bv-admin cp-admin">
    <nav class="bv-subnav bv-noprint" aria-label="Sections du comptage">
      <?php foreach (cp_admin_sections() as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($flash): ?>
      <p class="publish-status bv-flash" role="status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p>
    <?php endif; ?>
<?php
}

function cp_admin_footer(): void
{
    ?>
</div>
<script>
// Confirmation avant les actions destructrices : <form data-confirm="…">
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});
</script>
<?php
    ga_foot();
}

/** Page de connexion bénévole (même esprit que celle de la buvette). */
function cp_login_page(?string $error): void
{
    $siteName = cp_site_name() ?: 'La Brocante';
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
