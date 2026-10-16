<?php
// Sélecteur du diaporama d'une fiche (galerie) : quelles images sont montrées, et dans quel ordre. Réponse JSON.
//   POST ref, csrf
//   POST order   : identifiants des photos / vidéos montrées dans le diaporama, dans l'ordre (« 12,7,9 »)
// Toutes les autres images de la pièce sont masquées (is_hidden = 1) et rangées après, dans leur ordre actuel.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dp_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) dp_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') dp_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) dp_reply(['ok' => false, 'error' => 'Page expirée : rechargez-la et recommencez.'], 400);

$ref = (string) ($_POST['ref'] ?? '');
if (!get_product($ref)) dp_reply(['ok' => false, 'error' => 'Pièce introuvable.'], 404);

$rows = product_photos_list($ref);
$known = [];
foreach ($rows as $r) $known[(int) $r['id']] = $r;

$shown = [];
foreach (explode(',', (string) ($_POST['order'] ?? '')) as $id) {
    $id = (int) $id;
    if ($id > 0 && isset($known[$id]) && !in_array($id, $shown, true)) $shown[] = $id;
}
if (!$shown && $known) dp_reply(['ok' => false, 'error' => 'Gardez au moins une image dans le diaporama.']);

$hiddenIds = array_values(array_filter(array_keys($known), fn($id) => !in_array($id, $shown, true)));
$pdo = db();
$pdo->beginTransaction();
$upd = $pdo->prepare('UPDATE product_photos SET is_hidden = ?, sort_order = ? WHERE id = ? AND product_ref = ?');
$n = 0;
foreach ($shown as $id) $upd->execute([0, ++$n, $id, $ref]);
foreach ($hiddenIds as $id) $upd->execute([1, ++$n, $id, $ref]);
$pdo->commit();
sync_cover_photo($ref);

dp_reply(['ok' => true, 'shown' => count($shown), 'hidden' => count($hiddenIds)]);
