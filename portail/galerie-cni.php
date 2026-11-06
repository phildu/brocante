<?php
// Copie d'une pièce d'identité déposée par un exposant : flux JPEG privé, réservé à l'organisateur connecté (exploitant ou administrateur
// de la galerie). Aucune adresse directe ne mène au fichier (data/ est refusé en HTTP) ; chaque consultation est journalisée.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/galleries.php';
require_once PORTAIL_ROOT . '/includes/gallery-booking.php';

/** Réponse d'erreur minimale : jamais d'information sur l'existence d'une copie. */
function gbcni_deny(int $code = 404): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $code === 403 ? 'Accès réservé.' : 'Introuvable.';
    exit;
}

$slug = strtolower((string) ($_GET['g'] ?? ''));
$slotId = (string) ($_GET['slot'] ?? '');
$side = (string) ($_GET['side'] ?? '');

$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
if (!$gallery || $slotId === '' || !isset(GB_CNI_SIDES[$side])) gbcni_deny();

$allowed = !empty($_SESSION['portail_user']) || $portailLocal;
if (!$allowed) {
    $admin = ga_current();
    $allowed = $admin && in_array($slug, $admin['slugs'], true);
}
if (!$allowed) gbcni_deny(403);

gb_cni_purge($slug);
$row = gb_row($gallery, $slotId);
$path = $row ? gb_cni_path($slug, $row, $side) : null;
if (!$path) gbcni_deny();

$actor = (string) (($_SESSION['portail_user'] ?? '') ?: ($_SESSION['gallery_admin']['email'] ?? '') ?: 'exploitant');
if (empty($_GET['thumb'])) gb_audit($slug, 'cni_view', ['slot' => $slotId, 'side' => $side, 'by' => $actor]);

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="piece-' . $side . '.jpg"');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Referrer-Policy: no-referrer');
readfile($path);
