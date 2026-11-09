<?php
// Téléchargement du pack d'annonce d'une pièce du Studio (studio.php → relecture → « Pack d'annonce ») :
// un .zip avec les vraies photos du shooting (numérotées, photo principale d'abord) et le texte Vinted / Leboncoin.
// Appelé en POST depuis la relecture : les champs envoyés (non enregistrés encore) priment sur la fiche.

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

if (!admin_access_ok()) {
    http_response_code(401);
    exit('Session expirée : reconnectez-vous.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée.');
}

$product = get_product((string) ($_POST['ref'] ?? ''));
if (!$product) {
    http_response_code(404);
    exit('Fiche introuvable.');
}
$product = resale_pack_product($product, $_POST);

$tmp = tempnam(sys_get_temp_dir(), 'pack');
if ($tmp === false || !resale_pack_build_zip($product, $tmp)) {
    if ($tmp) @unlink($tmp);
    http_response_code(409);
    exit("Pack impossible : cette pièce n'a pas de photo, ou l'extension zip du serveur manque.");
}

$filename = 'pack-' . slugify((string) $product['name'], 40) . '-' . $product['ref'] . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
