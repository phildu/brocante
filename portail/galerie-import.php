<?php
// L'import de contacts d'une galerie vit dans l'extension « Force de vente » (portail/vente/prospects-import.php, servi à /galerie/<id>/vente/).
// Cette adresse reste valable pour les anciens liens (assistant, e-mails).
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/vente-scope.php';

header('Location: ' . vs_gallery_url($gaSlug, 'prospects-import.php'));
exit;
