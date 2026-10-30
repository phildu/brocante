<?php
// Demandes d'emplacement des exposants : POST (JSON ou formulaire) depuis le plan public de la galerie, et page d'annulation par lien e-mail.
// Voir includes/gallery-booking.php.
require_once __DIR__ . '/../includes/gallery-offers.php';
require_once __DIR__ . '/../includes/gallery-booking.php';
require_once __DIR__ . '/../includes/gallery-waitlist.php';

function gx_page(string $title, string $html): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:48px auto;padding:0 16px;color:#222}button{font:inherit;padding:10px 16px;cursor:pointer}</style></head><body><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $html . '</body></html>';
    exit;
}

$slug = strtolower((string) ($_GET['g'] ?? $_POST['g'] ?? ''));
$in = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $j = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($j)) { $in = $j; $slug = strtolower((string) ($j['g'] ?? $slug)); }
}
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($in['action'] ?? '') === 'cancel') {          // confirmation de l'annulation
    $cancelSlot = '';
    $ok = $gallery && gb_cancel($slug, (string) ($in['t'] ?? ''), $cancelSlot);
    if ($ok && $gallery && $cancelSlot !== '') gwl_on_release($gallery, $cancelSlot, 'exposant');
    gx_page($ok ? 'Demande annulée' : 'Lien invalide', '<p>' . ($ok ? "Votre demande est annulée : l'emplacement est de nouveau libre." : "Ce lien n'est plus valide (demande déjà traitée ou annulée).") . '</p>');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!empty($in['website'])) { echo json_encode(['ok' => true, 'message' => 'Votre demande est enregistrée.']); exit; }   // champ piège
    if (!$gallery) { http_response_code(404); echo json_encode(['error' => 'Galerie introuvable.']); exit; }
    [$e, $err] = gb_request($gallery, (string) ($in['slot'] ?? ''), $in, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $_FILES);
    http_response_code($err ? 422 : 200);
    $message = 'Votre demande est enregistrée : un e-mail de confirmation vient de vous être envoyé. L\'organisateur vous répond ensuite.';
    if (!$err && is_array($e) && !empty($e['cni']['uploaded'])) $message .= ' Votre pièce d\'identité a été reçue pour vérification ; sa copie sera supprimée automatiquement le ' . date('d/m/Y', (int) $e['cni']['purge_after']) . '.';
    echo json_encode($err ? ['error' => $err] : ['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
// GET : lien d'annulation reçu par e-mail (une confirmation est demandée : un robot de messagerie qui ouvre le lien n'annule rien)
$t = (string) ($_GET['t'] ?? '');
if (!$gallery || !preg_match('/^[a-f0-9]{32}$/', $t)) gx_page('Lien invalide', "<p>Ce lien n'est pas valide.</p>");
gx_page('Annuler ma demande', '<p>Voulez-vous annuler votre demande d\'emplacement pour « ' . htmlspecialchars($gallery['name'], ENT_QUOTES, 'UTF-8') . ' » ?</p><form method="post"><input type="hidden" name="g" value="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="t" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="action" value="cancel"><button type="submit">Oui, annuler ma demande</button></form>');
