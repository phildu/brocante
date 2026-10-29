<?php
// Mise en page commune des pages d'administration d'une galerie (tableau de bord, prospects, compte) : exploitant (?g=) ou administrateur de la galerie.
require_once __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';
require_once PORTAIL_ROOT . '/includes/gallery-admin.php';
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
function ga_head(string $title): void
{
    global $gaGallery, $limited;
    $flash = portail_flash();
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — <?= e($gaGallery['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .gwrap { display: grid; gap: 16px; max-width: 980px; margin: 0 auto; padding: 0 16px 48px; }
  .gcard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .gcard h2 { margin: 0; font-size: 1.05rem; } .gcard h3 { margin: 6px 0 0; font-size: .95rem; }
  .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .gcard input[type=text], .gcard input[type=email], .gcard input[type=password], .gcard textarea, .gcard select { flex: 1; min-width: 180px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; }
  .gcard textarea { width: 100%; min-height: 120px; }
  .gcard label.f { display: grid; gap: 4px; font-size: .8rem; font-weight: 600; flex: 1; min-width: 180px; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
  .kpi { border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; background: var(--panel); } .kpi b { display: block; font-size: 1.5rem; } .kpi span { font-size: .78rem; opacity: .75; }
  table.t { width: 100%; border-collapse: collapse; font-size: .9rem; } table.t th, table.t td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: top; } table.t th { font-size: .75rem; opacity: .7; }
  .num { text-align: right !important; } .chart svg { width: 100%; height: auto; display: block; color: var(--accent, #b5502e); }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand"><h1><?= e($title) ?></h1><p><?= e($gaGallery['name']) ?></p></div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;"><?php include __DIR__ . '/_galerie-nav.php'; ?></span>
  </header>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <div class="gwrap">
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
