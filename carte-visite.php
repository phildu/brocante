<?php

// Export de la boutique au format vCard 3.0 : le fichier .vcf s'importe dans
// le carnet d'adresses d'iPhone, Android, Outlook ou Google Contacts.
require_once __DIR__ . '/includes/functions.php';

$info = shop_locate_info();
if ($info['address'] === '' && $info['phone'] === '' && $info['email'] === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "La fiche de contact de cette boutique n'est pas encore renseignée.";
    exit;
}

$lat = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float) $_GET['lng'] : null;
$card = shop_locate_vcard($info, $lat, $lng);

$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $info['name']);
$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii !== false ? $ascii : (string) $info['name'])), '-');
if ($slug === '') $slug = 'boutique';

header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: attachment; filename="carte-visite-' . $slug . '.vcf"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $card;
