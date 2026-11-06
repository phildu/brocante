<?php
// Bourse d'échanges : la page publique est fournie par le plugin « bourse » (packs/bourse/front/bourse_app.php), activé boutique par
// boutique dans le portail → Plugins. Sans plugin actif et à jour, la page n'existe pas.
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plugins.php';

$activeNav = 'bourse';
$pageTitle = 'Bourse d’échanges';
$app = plugin_render('bourse_app');
if (trim($app) === '') {
    http_response_code(404);
    $pageTitle = 'Page introuvable';
    include __DIR__ . '/includes/header.php';
    echo '<main class="wrap" style="padding:48px 0;"><h1>Page introuvable</h1><p><a href="/index.php">Retour à l\'accueil</a></p></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
include __DIR__ . '/includes/header.php';
echo $app;
include __DIR__ . '/includes/footer.php';
