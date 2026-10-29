<?php
// Pages légales de la boutique : /legal.php?page=cgv | cgu | confidentialite | mentions (texte : Administration → Pages légales).
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/legal.php';

db();   // charge les réglages (shop_setting)
$page = (string) ($_GET['page'] ?? '');
if (!isset(LEGAL_PAGES[$page])) {
    http_response_code(404);
    $pageTitle = 'Page introuvable';
    $activeNav = '';
    include __DIR__ . '/includes/header.php';
    echo '<section class="tight"><div class="wrap"><h1>Page introuvable</h1><p><a href="/index.php">Retour à l\'accueil</a></p></div></section>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$pageTitle = LEGAL_PAGES[$page]['title'];
$activeNav = '';
include __DIR__ . '/includes/header.php';
?>
<section class="tight">
  <div class="wrap legal-page" style="max-width:780px;">
    <p class="eyebrow">Informations légales</p>
    <h1 style="font-size:2rem;margin:12px 0 22px;"><?= h($pageTitle) ?></h1>
    <div class="legal-body"><?= legal_render(legal_text($page)) ?></div>
    <p class="legal-nav" style="margin-top:34px;padding-top:16px;border-top:1px solid var(--line);font-size:.9rem;"><?= legal_links_html() ?></p>
  </div>
</section>
<style>
  .legal-body h2 { font-size: 1.15rem; margin: 26px 0 8px; } .legal-body p, .legal-body li { line-height: 1.65; } .legal-body ul { padding-left: 1.3rem; }
  .legal-nav a { display: inline-block; padding: 10px 4px; }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
