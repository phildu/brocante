<?php
/**
 * Gabarits HTML du module Buvette : un pour l'administration (responsable),
 * un pour l'espace bénévoles (mobile d'abord, accès par code partagé).
 */

require_once __DIR__ . '/buvette.php';

/* ---------- administration ---------- */

/**
 * Droit exigé par une action POST de l'administration de la buvette, déduit de
 * son nom : delete_* -> supprimer ; add_*, create_*, load_*, duplicate_* -> créer ;
 * le reste (update_*, set_*, assign, toggle, reset, save_*, activate_*…) -> modifier.
 */
function bv_action_verb(string $action): string
{
    if (str_starts_with($action, 'delete')) return 'supprimer';
    foreach (['add', 'create', 'load', 'duplicate'] as $prefix) {
        if (str_starts_with($action, $prefix)) return 'creer';
    }
    return 'modifier';
}

/** Session + droits (consulter, et selon l'action : créer / modifier / supprimer) + schéma : à appeler en tête de chaque page admin/buvette/. */
function bv_admin_init(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_permission('portail.buvette.lire');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        require_permission(auth_perm('portail.buvette', bv_action_verb((string) ($_POST['action'] ?? ''))));
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

function bv_admin_header(string $title, string $section): void
{
    $content = get_content();
    $event = bv_active_event();
    $flash = flash_get();
    $adminNav = 'buvette';
    $siteName = $content['site_name'] ?? '';
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — Buvette — <?= h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="<?= h(bv_asset_url('/assets/buvette.css')) ?>">
</head>
<body class="bv-admin">
<?php include __DIR__ . '/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
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

    <h1 class="bv-title"><?= h($title) ?></h1>

    <?php if ($flash): ?>
      <p class="publish-status bv-flash" role="status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p>
    <?php endif; ?>
<?php
}

function bv_admin_footer(): void
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

/** Redirection après POST (PRG) avec message flash. */
function bv_redirect(string $url, ?string $message = null, string $kind = 'ok'): void
{
    if ($message !== null) flash_set($message, $kind);
    header('Location: ' . $url);
    exit;
}

/** Champ <input type="hidden"> csrf + action, pour les formulaires du module. */
function bv_form_open(string $action, array $hidden = [], string $confirm = '', string $class = ''): string
{
    $html = '<form method="post"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . csrf_input()
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
    require_csrf();
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
    if (session_status() === PHP_SESSION_NONE) session_start();
    bv_boot();
    bv_require_volunteer();
}

function bv_vol_header(string $title, string $section, bool $withNav = true): void
{
    $content = get_content();
    $siteName = $content['site_name'] ?? 'La Brocante';
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
