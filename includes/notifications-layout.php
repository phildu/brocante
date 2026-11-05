<?php
/**
 * Gabarits HTML du module Notifications : administration (responsable) et page
 * publique de gestion des préférences (accès par lien secret personnel).
 */

require_once __DIR__ . '/notifications.php';

/* ---------- administration ---------- */

/** Session + droits admin + schéma : à appeler en tête de chaque page admin/notifications/. */
function nt_admin_init(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_admin();
    nt_boot();
}

function nt_admin_sections(): array
{
    return [
        'dashboard' => ['/admin/notifications/index.php', 'Tableau de bord'],
        'utilisateurs' => ['/admin/notifications/utilisateurs.php', 'Utilisateurs'],
        'envoyer' => ['/admin/notifications/envoyer.php', 'Envoyer'],
        'historique' => ['/admin/notifications/historique.php', 'Historique'],
        'rubriques' => ['/admin/notifications/rubriques.php', 'Rubriques'],
        'canaux' => ['/admin/notifications/canaux.php', 'Canaux & réglages'],
    ];
}

function nt_asset_url(string $path): string
{
    $file = dirname(__DIR__) . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function nt_head(string $title, string $bodyClass): void
{
    $siteName = (get_content()['site_name'] ?? '');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — <?= h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="<?= h(nt_asset_url('/assets/notifications.css')) ?>">
</head>
<body class="<?= h($bodyClass) ?>">
<?php
}

function nt_admin_header(string $title, string $section): void
{
    $content = get_content();
    $adminNav = 'notifications';
    $flash = flash_get();
    nt_head($title . ' — Notifications', 'nt-admin');
    include __DIR__ . '/admin-nav.php';
    ?>
<main>
<section class="tight">
  <div class="wrap">
    <nav class="nt-subnav" aria-label="Sections des notifications">
      <?php foreach (nt_admin_sections() as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <h1 class="nt-title"><?= h($title) ?></h1>
    <?php if ($flash): ?>
      <p class="publish-status nt-flash" role="status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p>
    <?php endif; ?>
<?php
}

function nt_admin_footer(): void
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
function nt_redirect(string $url, ?string $message = null, string $kind = 'ok'): void
{
    if ($message !== null) flash_set($message, $kind);
    header('Location: ' . $url);
    exit;
}

/** Ouvre un formulaire POST du module : jeton CSRF + action. */
function nt_form_open(string $action, array $hidden = [], string $confirm = '', string $class = ''): string
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
function nt_post_action(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';
    require_csrf();
    return (string) ($_POST['action'] ?? '');
}

function nt_badge(string $text, string $kind = ''): string
{
    return '<span class="nt-badge' . ($kind !== '' ? ' ' . h($kind) : '') . '">' . h($text) . '</span>';
}

function nt_status_badge(string $status): string
{
    $map = [
        'sent' => ['Envoyé', 'ok'], 'failed' => ['Échec', 'bad'], 'queued' => ['En attente', 'warn'],
        'sending' => ['En cours', 'warn'], 'skipped' => ['Ignoré', ''],
    ];
    [$label, $kind] = $map[$status] ?? [$status, ''];
    return nt_badge($label, $kind);
}

/** Texte « il y a 3 min » / date, pour l'historique. */
function nt_fr_datetime(string $iso): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $iso, nt_tz());
    return $dt ? $dt->format('d/m/Y H:i') : $iso;
}

/* ---------- page publique : préférences d'un utilisateur ---------- */

function nt_public_header(string $title): void
{
    $flash = flash_get();
    nt_head($title, 'nt-public');
    ?>
<header class="ntp-top"><div class="ntp-top-inner"><span class="ntp-brand"><?= h(nt_site_name()) ?></span></div></header>
<main class="ntp-main">
  <h1 class="ntp-title"><?= h($title) ?></h1>
  <?php if ($flash): ?>
    <p class="publish-status nt-flash" role="status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p>
  <?php endif; ?>
<?php
}

function nt_public_footer(): void
{
    ?>
</main>
</body>
</html>
<?php
}
