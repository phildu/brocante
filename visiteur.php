<?php
// Espace visiteur : la web app des visiteurs de la boutique (fidélité, alertes, réservations, scan de QR codes). Fournie par le plugin
// « Espace visiteur & fidélité » ; sans lui (ou sans licence), la page n'existe pas.
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plugins.php';

$app = plugin_render('visiteur_app');
header('X-Robots-Tag: noindex');
if (trim($app) === '') {
    http_response_code(404);
    $pageTitle = 'Page introuvable';
    include __DIR__ . '/includes/header.php';
    echo '<main class="wrap" style="padding:48px 0;"><h1>Page introuvable</h1><p><a href="/index.php">Retour à l\'accueil</a></p></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$pageTitle = 'Mon espace';
include __DIR__ . '/includes/header.php';
echo $app;
include __DIR__ . '/includes/footer.php';
