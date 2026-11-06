<?php
// Réception des pièces envoyées par un autre site (ex. le Mac → la préprod),
// voir includes/sync.php. Pas de session admin ici : l'appel est authentifié
// par la clé de réception (en-tête X-Sync-Key), générée dans
// Administration → Envoyer en préprod.
//   status  → nom du site et pièces présentes (numéro → nom)
//   file    → sans fichier joint : « l'avez-vous déjà ? » ; avec : enregistrement
//   product → création ou mise à jour de la fiche et de sa galerie

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sync.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sync_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sync_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}
if (!sync_receive_enabled()) {
    sync_reply(['ok' => false, 'error' => 'Réception désactivée sur ce site : générez une clé dans Administration → Envoyer en préprod.'], 403);
}
// Clé en en-tête, ou dans le formulaire si l'hébergeur filtre les en-têtes personnalisés.
if (!sync_receive_key_matches((string) ($_SERVER['HTTP_X_SYNC_KEY'] ?? $_POST['sync_key'] ?? ''))) {
    usleep(300000);
    sync_reply(['ok' => false, 'error' => 'Clé de réception incorrecte.'], 403);
}
@set_time_limit(120);

switch ((string) ($_POST['action'] ?? '')) {
    case 'status':
        $products = [];
        foreach (db()->query('SELECT ref, name FROM products')->fetchAll() as $p) {
            $products[$p['ref']] = $p['name'];
        }
        sync_reply([
            'ok' => true,
            'site' => (string) (get_content()['site_name'] ?? '') ?: (string) tenant('name'),
            'tenant' => tenant_slug(),
            'products' => $products,
            'upload_max' => ini_get('upload_max_filesize'),
        ]);

    case 'file':
        $upload = isset($_FILES['file']) ? $_FILES['file'] : null;
        sync_reply(sync_receive_file((string) ($_POST['path'] ?? ''), strtolower((string) ($_POST['sha1'] ?? '')), $upload));

    case 'product':
        $product = json_decode((string) ($_POST['product'] ?? ''), true);
        $photos = json_decode((string) ($_POST['photos'] ?? '[]'), true);
        if (!is_array($product) || !is_array($photos)) sync_reply(['ok' => false, 'error' => 'Fiche illisible.'], 400);
        sync_reply(sync_receive_product($product, $photos, (string) ($_POST['mode'] ?? 'update')));
}

sync_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
