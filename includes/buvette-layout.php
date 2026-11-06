<?php
/**
 * Gabarits HTML du module Buvette : un pour l'administration (responsable),
 * un pour l'espace bénévoles (mobile d'abord, accès par code partagé).
 */

require_once __DIR__ . '/buvette.php';

/* ---------- administration ---------- */

/**
 * Droits + schéma : à appeler en tête de chaque page admin/buvette/. Ces pages ne sont jamais servies seules : c'est
 * portail/galerie-buvette.php (espace d'une galerie) qui les ouvre, après avoir vérifié l'accès et l'extension, et qui place la buvette
 * dans cette galerie (bv_enter_gallery()). Appelée directement (/admin/buvette/…), la page n'existe pas.
 */
function bv_admin_init(): void
{
    if (bv_gallery_context() === null) {
        http_response_code(404);
        exit('Page introuvable.');
    }
    bv_boot();
}

function bv_admin_sections(): array
{
    return [
        'dashboard' => ['/admin/buvette/index.php', 'Tableau de bord'],
        'carte' => ['/admin/buvette/carte.php', 'Carte & prix'],
        'recettes' => ['/admin/buvette/recettes.php', 'Recettes'],
        'fournitures' => ['/admin/buvette/fournitures.php', 'Fournitures'],
        'previsionnel' => ['/admin/buvette/previsionnel.php', 'Prévisionnel & bilan'],
        'courses' => ['/admin/buvette/courses.php', 'Courses'],
        'benevoles' => ['/admin/buvette/benevoles.php', 'Bénévoles & planning'],
        'checklist' => ['/admin/buvette/checklist.php', 'Check-list'],
    ];
}

function bv_asset_url(string $path): string
{
    $file = dirname(__DIR__) . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Feuille de style d'appoint : le module est écrit pour le thème de la boutique, il s'affiche ici dans celui du portail. */
function bv_gallery_css(): string
{
    return ':root { --ink-soft: var(--muted); --surface: var(--panel); --surface-2: var(--panel-2); --sage: var(--ok); --font-display: var(--font-ui); --font-body: var(--font-ui); }'
        . '.bv-admin .bv-title { display: none; }'
        . '@media print { .pnav, .pbar, .phead, .pnav-veil, .ga-actions { display: none !important; } body.has-admin-shell { padding: 0 !important; } .app, .gwrap, .ga-page { margin: 0 !important; padding: 0 !important; max-width: none !important; } }';
}

/** En-tête d'une page de la buvette : le cadre de l'espace de la galerie (portail/_galerie-layout.php), puis les sections du module. */
function bv_admin_header(string $title, string $section): void
{
    $event = bv_active_event();
    $flash = flash_get();
    ga_head($title . ' · Buvette', '', '<link rel="stylesheet" href="' . h(bv_asset_url('/assets/buvette.css')) . '"><style>' . bv_gallery_css() . '</style>');
    ?>
    <div class="bv-admin">
    <nav class="bv-subnav bv-noprint" aria-label="Sections de la buvette">
      <?php foreach (bv_admin_sections() as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($event && $section !== 'dashboard'): ?>
      <p class="bv-event-line bv-noprint">
        <span class="eyebrow">Événement en cours</span>
        <strong><?= h($event['name']) ?></strong>
        <?php if ($event['event_date']): ?>· <?= h(bv_fr_date($event['event_date'])) ?> <span class="bv-muted">(<?= h(bv_countdown_label($event['event_date'])) ?>)</span><?php endif; ?>
        · <a href="/admin/buvette/index.php#evenements">changer</a>
      </p>
    <?php elseif (!$event && $section !== 'dashboard'): ?>
      <p class="bv-callout bv-noprint">Aucun événement pour l'instant — <a href="/admin/buvette/index.php#nouvel-evenement">créez-en un</a> pour utiliser le prévisionnel, le planning et la check-list.</p>
    <?php endif; ?>

    <?php if ($flash): ?>
      <p class="publish-status bv-flash" role="status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p>
    <?php endif; ?>
<?php
}

function bv_admin_footer(): void
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

/** Redirection après POST (PRG) avec message flash. */
function bv_redirect(string $url, ?string $message = null, string $kind = 'ok'): void
{
    if ($message !== null) flash_set($message, $kind);
    header('Location: ' . bv_url_map($url));
    exit;
}

/** Champ <input type="hidden"> csrf + action, pour les formulaires du module. */
function bv_form_open(string $action, array $hidden = [], string $confirm = '', string $class = ''): string
{
    $html = '<form method="post"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . shop_csrf_input()
        . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    return $html;
}

/** Début de traitement d'un POST : CSRF + action demandée ('' si GET). */
function bv_post_action(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';
    shop_require_csrf();
    return (string) ($_POST['action'] ?? '');
}

/** Pastille d'allergène (affichage compact). */
function bv_allergen_chips(array $item): string
{
    $labels = bv_allergen_labels($item);
    if (!$labels) return '<span class="bv-muted">aucun allergène déclaré</span>';
    return implode('', array_map(fn($l) => '<span class="bv-chip">' . h($l) . '</span>', $labels));
}

/* ---------- espace bénévoles ---------- */

function bv_vol_sections(): array
{
    return [
        'accueil' => ['/buvette/index.php', 'Accueil', '🏠'],
        'carte' => ['/buvette/carte.php', 'Carte', '☕'],
        'recettes' => ['/buvette/recettes.php', 'Recettes', '🍳'],
        'planning' => ['/buvette/planning.php', 'Planning', '🗓️'],
        'checklist' => ['/buvette/checklist.php', 'Check-list', '✅'],
    ];
}

function bv_vol_init(): void
{
    require_once __DIR__ . '/session.php';
    bv_public_enter();   // galerie d'après l'adresse, extension active
    bv_boot();
    bv_require_volunteer();
}

function bv_vol_header(string $title, string $section, bool $withNav = true): void
{
    $siteName = bv_site_name() ?: 'La Buvette';
    $flash = flash_get();
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#b5502e">
<title><?= h($title) ?> — Buvette — <?= h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="<?= h(bv_asset_url('/assets/buvette.css')) ?>">
</head>
<body class="bv-vol<?= $withNav ? ' has-tabbar' : '' ?>">
<header class="bvv-top">
  <div class="bvv-top-inner">
    <span class="bvv-brand"><?= h($siteName) ?> · <strong>Buvette</strong></span>
    <?php if ($withNav): ?><a class="bvv-quit" href="/buvette/logout.php">Quitter</a><?php endif; ?>
  </div>
</header>
<main class="bvv-main">
  <h1 class="bvv-title"><?= h($title) ?></h1>
  <?php if ($flash): ?>
    <p class="publish-status bv-flash" role="status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p>
  <?php endif; ?>
<?php
    // Le menu du bas est émis par bv_vol_footer() pour rester après le contenu.
    $GLOBALS['bv_vol_section'] = $withNav ? $section : null;
}

function bv_vol_footer(): void
{
    $section = $GLOBALS['bv_vol_section'] ?? null;
    ?>
</main>
<?php if ($section !== null): ?>
<nav class="bvv-tabbar" aria-label="Navigation bénévoles">
  <?php foreach (bv_vol_sections() as $key => [$href, $label, $icon]): ?>
    <a href="<?= h($href) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><span aria-hidden="true"><?= $icon ?></span><span><?= h($label) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
</body>
</html>
<?php
}
