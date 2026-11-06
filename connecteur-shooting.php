<?php

// Endpoint public d'une application de prise de vue (studio photo, scanner de stand, caisse) :
// dépose un article dans les promos d'une galerie commerciale, avec sa photo.
//
//   GET  /connecteur-shooting.php?g=<galerie>&t=<jeton>   → mode d'emploi et état (JSON)
//   POST /connecteur-shooting.php?g=<galerie>&t=<jeton>
//        JSON : {"name":"…","ref":"…","shop":"…","shop_name":"…","price":"…","promo_price":"…",
//                "photo":"https://…" ou "photo_data":"data:image/jpeg;base64,…","url":"https://…","cta":"…"}
//        ou formulaire multipart : champs ci-dessus + fichier « photo ».
//
// Le jeton est propre à la galerie (portail → Galeries → Promos) et ne donne accès qu'à cet ajout.
// Aucune lecture du catalogue d'un commerce n'est faite ici : c'est la page de la galerie qui la fait.

require_once __DIR__ . '/includes/galleries.php';

const SHOOTING_MAX_PHOTO_BYTES = 6291456;
const SHOOTING_MAX_PER_HOUR = 30;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Shooting-Token');
header('Vary: Origin');

function shooting_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$slug = strtolower((string) ($_GET['g'] ?? ''));
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
if (!$gallery) shooting_reply(['ok' => false, 'error' => 'Galerie inconnue.'], 404);

$token = strtolower(trim((string) ($_GET['t'] ?? '')));
$bearer = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+([a-f0-9]{32})$/i', $bearer, $m)) $token = strtolower($m[1]);
if (isset($_SERVER['HTTP_X_SHOOTING_TOKEN'])) $token = strtolower(trim((string) $_SERVER['HTTP_X_SHOOTING_TOKEN']));
if (!gallery_shooting_authorized($gallery, $token)) {
    shooting_reply(['ok' => false, 'error' => 'Jeton absent, révoqué ou invalide. Générez-en un nouveau dans le portail (Galeries → Promos).'], 401);
}

$origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$endpoint = rtrim($origin, '/') . '/connecteur-shooting.php?g=' . rawurlencode($slug) . '&t=' . $token;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    shooting_reply([
        'ok' => true,
        'gallery' => ['slug' => $slug, 'name' => $gallery['name']],
        'endpoint' => $endpoint,
        'promos' => count($gallery['promos']),
        'max' => GALLERY_PROMOS_MAX,
        'example' => [
            'method' => 'POST',
            'content_type' => 'application/json',
            'body' => [
                'name' => 'Commode années 50', 'ref' => 'piece-42', 'shop' => 'shoot', 'shop_name' => 'Mon stand',
                'price' => '85 €', 'promo_price' => '60 €', 'photo' => 'https://exemple.fr/photo.jpg',
                'url' => 'https://exemple.fr/produit/42', 'cta' => 'Réserver cette pièce',
            ],
        ],
    ]);
}

/** Frein par adresse IP : 30 dépôts par heure et par galerie. */
function shooting_rate_ok(string $slug): bool
{
    $file = galleries_dir() . '/.shoot-rate.json';
    $now = time();
    $fh = @fopen($file, 'c+');
    if (!$fh) return true;
    try {
        if (!flock($fh, LOCK_EX)) return true;
        $all = (array) (json_decode((string) stream_get_contents($fh), true) ?: []);
        foreach ($all as $key => $times) {
            $all[$key] = array_values(array_filter((array) $times, static fn ($t) => is_numeric($t) && $t > $now - 3600));
            if (!$all[$key]) unset($all[$key]);
        }
        $key = $slug . '|' . substr(sha1((string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . date('Y-m-d', $now)), 0, 20);
        $allowed = count((array) ($all[$key] ?? [])) < SHOOTING_MAX_PER_HOUR;
        if ($allowed) $all[$key][] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($all, JSON_UNESCAPED_SLASHES));
        fflush($fh);
        return $allowed;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

if (!shooting_rate_ok($slug)) shooting_reply(['ok' => false, 'error' => 'Trop de dépôts en une heure. Réessayez plus tard.'], 429);

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$data = $_POST;
if (str_contains($contentType, 'application/json')) {
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > SHOOTING_MAX_PHOTO_BYTES + 1048576) shooting_reply(['ok' => false, 'error' => 'Envoi trop volumineux (6 Mo de photo au plus).'], 413);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) shooting_reply(['ok' => false, 'error' => 'Corps JSON illisible.'], 400);
    $data = $decoded;
}

$name = mb_substr(trim((string) ($data['name'] ?? $data['title'] ?? '')), 0, 120);
if ($name === '') shooting_reply(['ok' => false, 'error' => 'Le nom de l’article est obligatoire.'], 422);

$shop = strtolower(trim((string) ($data['shop'] ?? 'shoot')));
if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $shop)) $shop = 'shoot';
$shopName = mb_substr(trim((string) ($data['shop_name'] ?? '')), 0, 80);
$ref = mb_substr(trim((string) ($data['ref'] ?? $data['sku'] ?? '')), 0, 80);
if ($ref === '') $ref = 'shoot-' . substr(sha1($name . '|' . $shop), 0, 12);

$photo = '';
$photoMobile = '';
try {
    if (!empty($_FILES['photo']) && is_array($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $photo = gallery_image_store($_FILES['photo'], $slug, 'promo', 1600);
    } elseif (!empty($_FILES['photo_mobile']) && is_array($_FILES['photo_mobile']) && ($_FILES['photo_mobile']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $photoMobile = gallery_image_store($_FILES['photo_mobile'], $slug, 'promo-mobile', 900);
    }
} catch (InvalidArgumentException $e) {
    shooting_reply(['ok' => false, 'error' => $e->getMessage()], 422);
}

$photoData = (string) ($data['photo_data'] ?? $data['photoData'] ?? '');
if ($photo === '' && $photoData !== '') {
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,([A-Za-z0-9+/=\s]+)$#', $photoData, $m)) {
        shooting_reply(['ok' => false, 'error' => 'Photo intégrée illisible (JPEG, PNG ou WebP en base64 attendu).'], 422);
    }
    $binary = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($binary === false || $binary === '' || strlen($binary) > SHOOTING_MAX_PHOTO_BYTES) {
        shooting_reply(['ok' => false, 'error' => 'Photo intégrée invalide ou trop lourde (6 Mo au plus).'], 413);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'shoot');
    if ($tmp === false || @file_put_contents($tmp, $binary) === false) shooting_reply(['ok' => false, 'error' => 'Photo non enregistrable.'], 500);
    try {
        $photo = gallery_image_store(['name' => 'photo', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($binary)], $slug, 'promo', 1600);
    } catch (InvalidArgumentException $e) {
        shooting_reply(['ok' => false, 'error' => $e->getMessage()], 422);
    } finally {
        @unlink($tmp);
    }
}

if ($photo === '') {
    $remote = trim((string) ($data['photo'] ?? $data['photo_url'] ?? ''));
    if ($remote !== '') {
        if (!preg_match('#^https?://[^\s"<>]+$#i', $remote)) shooting_reply(['ok' => false, 'error' => 'Adresse de photo invalide (http(s) attendu).'], 422);
        $photo = $remote;
    }
}

$url = trim((string) ($data['url'] ?? $data['link'] ?? ''));
if ($url !== '' && !preg_match('#^https?://[^\s"<>]+$#i', $url)) shooting_reply(['ok' => false, 'error' => 'Adresse de la fiche article invalide (http(s) attendu).'], 422);

$promo = gallery_promo_normalize([
    'shop' => $shop,
    'shop_name' => $shopName,
    'ref' => $ref,
    'name' => $name,
    'price' => mb_substr(trim((string) ($data['price'] ?? '')), 0, 40),
    'promo_price' => mb_substr(trim((string) ($data['promo_price'] ?? $data['sale_price'] ?? '')), 0, 40),
    'photo' => $photo,
    'photo_mobile' => $photoMobile,
    'url' => $url !== '' ? $url : rtrim($origin, '/') . '/galerie/' . rawurlencode($slug) . '/',
    'cta' => mb_substr(trim((string) ($data['cta'] ?? 'Voir l’offre')), 0, 40),
    'source' => 'shooting',
    'added' => time(),
]);
if (!$promo) shooting_reply(['ok' => false, 'error' => 'Article inutilisable.'], 422);

$promos = gallery_promos_normalize($gallery['promos'] ?? []);
$replaced = false;
foreach ($promos as $i => $existing) {
    if ($existing['shop'] === $promo['shop'] && $existing['ref'] === $promo['ref']) {
        $promo['added'] = $existing['added'];
        $promos[$i] = $promo;
        $replaced = true;
        break;
    }
}
if (!$replaced) {
    if (count($promos) >= GALLERY_PROMOS_MAX) {
        shooting_reply(['ok' => false, 'error' => 'Les ' . GALLERY_PROMOS_MAX . ' promos sont déjà prises. Retirez-en une dans le portail (Galeries → Promos).'], 409);
    }
    $promos[] = $promo;
}
$gallery['promos'] = $promos;
$gallery['shooting'] = array_merge(gallery_shooting_normalize($gallery['shooting'] ?? []), ['last_used' => time()]);
if (!gallery_save($gallery)) shooting_reply(['ok' => false, 'error' => 'Enregistrement impossible (droits du dossier data/galeries).'], 500);

shooting_reply(['ok' => true, 'replaced' => $replaced, 'promo' => $promo, 'count' => count($promos), 'max' => GALLERY_PROMOS_MAX]);