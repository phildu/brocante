<?php
// Worker CLI — détourage (rembg/Python) en arrière-plan : la seule
// génération qui a encore besoin d'une tâche de fond, car rembg est un
// process local (jusqu'à ~2 minutes) et non un appel HTTP à Gemini. Les
// générations ambiance/angle/complete/detail_enhance sont désormais
// synchrones directement dans admin/gallery-action.php (voir
// downscale_for_ai() dans includes/functions.php) — plus besoin de tâche de
// fond pour elles, ce qui les rend compatibles avec un hébergement
// mutualisé qui désactive exec()/shell_exec() pour tout le reste.
// Usage : php generate.php <ref> detoure [source_photo_id]

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

require_once __DIR__ . '/../../includes/functions.php';

[$script, $ref, $kind, $sourcePhotoId] = array_pad($argv, 4, null);

if (!$ref || $kind !== 'detoure') {
    fwrite(STDERR, "usage: php generate.php <ref> detoure [source_photo_id]\n");
    exit(1);
}

$product = get_product($ref);
if (!$product) {
    fwrite(STDERR, "[$ref] produit introuvable\n");
    exit(1);
}

$sourcePath = $product['photo'] ?? null;
if ($sourcePhotoId) {
    $stmt = db()->prepare('SELECT path FROM product_photos WHERE id = ? AND product_ref = ?');
    $stmt->execute([(int) $sourcePhotoId, $ref]);
    $found = $stmt->fetchColumn();
    if ($found) $sourcePath = $found;
}

if (!$sourcePath) {
    fwrite(STDERR, "[$ref] aucune photo source disponible\n");
    exit(1);
}

$srcAbs = __DIR__ . '/../../' . $sourcePath;

$bytes = run_cutout($srcAbs);
if (!$bytes) {
    fwrite(STDERR, "[$ref] détourage échoué\n");
    exit(1);
}
$path = save_binary_photo($bytes, 'product-' . $ref . '-detoure', 'png');
add_product_photo($ref, $path, 'Détourée', false);
echo "[$ref] détourage OK -> $path\n";
