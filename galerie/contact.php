<?php
// Pages publiques liées aux abonnés et aux prospects d'une galerie : inscription aux nouveautés (POST, JSON), confirmation et désinscription
// (liens signés envoyés par e-mail). Voir includes/gallery-admin.php.
require_once __DIR__ . '/../includes/gallery-offers.php';

$a = (string) ($_GET['a'] ?? $_POST['a'] ?? '');
$slug = strtolower((string) ($_GET['g'] ?? $_POST['g'] ?? ''));

function ga_page(string $title, string $text): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $h = static fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($title) . '</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:48px auto;padding:0 16px;color:#222}</style></head><body><h1>' . $h($title) . '</h1><p>' . $h($text) . '</p></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'subscribe') {
    header('Content-Type: application/json; charset=utf-8');
    if (!empty($_POST['website'])) { echo json_encode(['ok' => true, 'message' => 'Presque fini : un e-mail de confirmation vient de vous être envoyé.']); exit; }   // champ piège
    [$msg, $err] = gallery_slug_valid($slug) ? ga_sub_subscribe($slug, (string) ($_POST['email'] ?? '')) : [null, 'Galerie introuvable.'];
    http_response_code($err ? 422 : 200);
    echo json_encode($err ? ['ok' => false, 'error' => $err] : ['ok' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!gallery_slug_valid($slug)) ga_page('Lien invalide', "Ce lien n'est pas valide.");
$id = (string) ($_GET['i'] ?? '');
$token = (string) ($_GET['t'] ?? '');
if ($a === 'confirm') {
    ga_sub_confirm($slug, $id, $token) ? ga_page('Inscription confirmée', "Merci ! Vous recevrez les nouveautés de la galerie. Chaque message contient un lien pour vous désinscrire.") : ga_page('Lien invalide', "Ce lien de confirmation n'est plus valide.");
}
if ($a === 'stop') {
    ga_stop($slug, (string) ($_GET['k'] ?? ''), $id, $token) ? ga_page('Désinscription prise en compte', 'Vous ne recevrez plus de message de cette galerie.') : ga_page('Lien invalide', "Ce lien n'est plus valide : vous êtes peut-être déjà désinscrit(e).");
}
ga_page('Lien invalide', "Ce lien n'est pas valide.");
