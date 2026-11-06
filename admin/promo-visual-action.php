<?php
// Visuel promo d'un article (photo + encart CTA, fabriqué dans le navigateur par assets/promo-visual.js). Réponses JSON.
//   save   → ref, headline, cta + fichiers « desktop » (3:2) et « mobile » (9:16), au format JPEG
//   delete → ref
// Le visuel garde une copie des prix qu'il affiche : si l'article change de prix, il n'est plus montré tant qu'il n'est pas refait (includes/promo.php).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function promo_visual_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) promo_visual_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') promo_visual_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) promo_visual_reply(['ok' => false, 'error' => 'Page expirée : rechargez-la et recommencez.'], 400);

$ref = (string) ($_POST['ref'] ?? '');
$product = get_product($ref);
if (!$product) promo_visual_reply(['ok' => false, 'error' => 'Pièce introuvable.'], 404);
$old = promo_visual_get($ref);

switch ((string) ($_POST['action'] ?? '')) {
    case 'delete': {
        promo_visual_files_delete($old);
        db()->prepare('DELETE FROM promo_visuals WHERE ref = ?')->execute([$ref]);
        promo_visual_reply(['ok' => true]);
    }
    case 'save': {
        if (!has_promo_price($product)) promo_visual_reply(['ok' => false, 'error' => 'Cette pièce n\'est pas en promo : mettez-la en promo (mention « Promo » et prix promo), enregistrez, puis créez le visuel.']);
        // Chaque image est relue et réencodée (taille et format bornés) avant d'être rangée.
        $save = static function (string $field, int $w, int $h, string $suffix) use ($ref): ?string {
            $f = $_FILES[$field] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $f['size'] > 6 * 1048576) return null;
            $img = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
            if (!$img) return null;
            if (imagesx($img) !== $w || imagesy($img) !== $h) {
                $c = imagecreatetruecolor($w, $h);
                imagecopyresampled($c, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
                $img = $c;
            }
            $dir = dirname(__DIR__) . '/uploads';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $name = 'promo-' . preg_replace('/[^A-Za-z0-9_-]/', '', $ref) . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . $suffix . '.jpg';
            return imagejpeg($img, $dir . '/' . $name, 88) ? 'uploads/' . $name : null;
        };
        $desktop = $save('desktop', 1500, 1000, '');
        $mobile = $save('mobile', 1080, 1920, '-mobile');
        if (!$desktop) { promo_visual_files_delete(['path' => $mobile]); promo_visual_reply(['ok' => false, 'error' => 'Image non reçue ou illisible : réessayez.'], 400); }
        promo_visual_files_delete($old);
        db()->prepare('INSERT INTO promo_visuals (ref, path, path_mobile, headline, cta, price, promo_price, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(ref) DO UPDATE SET path = excluded.path, path_mobile = excluded.path_mobile, headline = excluded.headline, cta = excluded.cta, price = excluded.price, promo_price = excluded.promo_price, created_at = CURRENT_TIMESTAMP')
            ->execute([$ref, $desktop, $mobile, mb_substr(trim((string) ($_POST['headline'] ?? '')), 0, 40), mb_substr(trim((string) ($_POST['cta'] ?? '')), 0, 40), trim((string) $product['price']), trim((string) $product['promo_price'])]);
        promo_visual_reply(['ok' => true, 'path' => $desktop, 'mobile' => $mobile]);
    }
}
promo_visual_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
