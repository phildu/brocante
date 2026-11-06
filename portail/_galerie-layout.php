<?php
// Mise en page commune des pages d'administration d'une galerie (tableau de bord, prospects, compte) : exploitant (?g=) ou administrateur de la galerie.
require_once __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/apps.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';
require_once PORTAIL_ROOT . '/includes/gallery-admin.php';
require_once PORTAIL_ROOT . '/includes/gallery-messages.php';
require_once PORTAIL_ROOT . '/includes/gallery-types.php';

$limited = defined('GA_LIMITED') ? GA_LIMITED : null;
$gaSlug = $limited ?: strtolower((string) ($_GET['g'] ?? $_POST['g'] ?? ''));
$gaGallery = $gaSlug !== '' ? gallery_load($gaSlug) : null;
if (!$gaGallery) {
    portail_flash('Choisissez une galerie.', 'error');
    header('Location: /portail/galeries.php');
    exit;
}
$gaOrigin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

/** Début de page (jusqu'à la barre de navigation). */
function ga_head(string $title, string $actions = '', string $head = ''): void
{
    global $gaGallery, $gaSlug, $gaOrigin, $limited;
    $flash = portail_flash();
    ob_start();
    include __DIR__ . '/_galerie-nav.php';
    $gaNav = (string) ob_get_clean();
    $publicUrl = gallery_public_url($gaGallery, $gaOrigin);
    $gaActions = '';
    if (!$limited) $gaActions .= '<a class="btn" href="/portail/galeries.php">← Galeries</a>';
    $gaActions .= '<a class="btn" href="' . e($publicUrl) . '" target="_blank" rel="noopener">Voir la galerie ↗</a>';
    $gaActions .= $actions;
    $gaAppName = gallery_app_name($gaGallery, 'gallery_admin');
    $gaAccent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gaGallery['accent'] ?? '')) ? $gaGallery['accent'] : '#b5502e';
    $gaIcon = '/galerie/icon.php?g=' . rawurlencode($gaSlug);
    $gaManifest = '/galerie/admin-manifest.php?g=' . rawurlencode($gaSlug);
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= e($gaAccent) ?>">
<link rel="manifest" href="<?= e($gaManifest) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(app_short_name($gaAppName, 14)) ?>">
<link rel="apple-touch-icon" href="<?= e($gaIcon) ?>&amp;s=180">
<script src="/assets/pwa-save.js?v=<?= (int) @filemtime(PORTAIL_ROOT . '/assets/pwa-save.js') ?>" data-auto="0" data-no-prompt="1" data-key="gadmin-<?= e($gaSlug) ?>" data-name="<?= e($gaAppName) ?>" data-icon="<?= e($gaIcon) ?>" data-accent="<?= e($gaAccent) ?>" data-sw="/pwa-sw.php" data-scope="/portail/"></script>
<title><?= e($title) ?> — <?= e($gaAppName) ?></title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<?= $head ?>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => $title,
    'eyebrow' => $gaGallery['name'],
    'section' => $limited ? 'Galerie' : 'Boutiques',
    'desc' => $limited ? 'Espace d’administration de votre galerie.' : 'Administration de la galerie et de ses exposants.',
    'actions' => $gaActions,
    'nav_html' => $limited ? $gaNav : '',
    'tabs' => $limited ? null : $gaNav,
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <div class="gwrap ga-page">
<?php
}

function ga_foot(): void
{
    echo "  </div>\n</div>\n<script src=\"/assets/pwd-toggle.js\" defer></script>\n</body>\n</html>\n";
}

function ga_eur(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}
