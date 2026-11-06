<?php

// Promos d'une galerie commerciale : les articles retenus (à la main ou envoyés par une application de prise de vue / un connecteur)
// restent affichés même sans visuel promo « généré ». Le visuel promo d'une boutique Brocs garde la priorité ; sinon la carte
// reprend la photo, le nom, le prix et l'appel à l'action de l'article.
//
// Les jetons de l'application de prise de vue sont propres à une galerie (data/galeries/<identifiant>.json, bloc « shooting »)
// et n'ouvrent que l'endpoint public /connecteur-shooting.php.

const GALLERY_PROMOS_MAX = 6;
const GALLERY_SHOOTING_TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

/** Clé « commerce|référence » d'une promo. */
function gallery_promo_key(string $shop, string $ref): string
{
    return $shop . '|' . $ref;
}

/** Adresse de photo acceptée : fichier de la galerie (uploads/galeries/) ou adresse http(s). */
function gallery_promo_photo(string $photo): string
{
    $photo = trim($photo);
    if (preg_match('#^uploads/galeries/[a-z0-9][a-z0-9._-]*\.(?:jpg|png|webp)$#i', $photo)) return '/' . $photo;
    return preg_match('#^https?://[^\s"<>]+$#i', $photo) ? $photo : '';
}

/** Promo normalisée ; chaîne vide si inutilisable (pas de nom). */
function gallery_promo_normalize($p): ?array
{
    if (!is_array($p)) return null;
    $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 120);
    if ($name === '') return null;
    $shop = strtolower(trim((string) ($p['shop'] ?? 'shoot')));
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $shop)) $shop = 'shoot';
    $ref = mb_substr(trim((string) ($p['ref'] ?? '')), 0, 80);
    if ($ref === '') $ref = 'shoot-' . substr(sha1($name . '|' . (string) ($p['photo'] ?? '')), 0, 12);
    $url = trim((string) ($p['url'] ?? ''));
    $url = preg_match('#^https?://[^\s"<>]+$#i', $url) ? $url : '';
    return [
        'shop' => $shop,
        'shop_name' => mb_substr(trim((string) ($p['shop_name'] ?? '')), 0, 80),
        'ref' => $ref,
        'name' => $name,
        'price' => mb_substr(trim((string) ($p['price'] ?? '')), 0, 40),
        'promo_price' => mb_substr(trim((string) ($p['promo_price'] ?? '')), 0, 40),
        'photo' => gallery_promo_photo((string) ($p['photo'] ?? '')),
        'photo_mobile' => gallery_promo_photo((string) ($p['photo_mobile'] ?? '')),
        'url' => $url,
        'cta' => mb_substr(trim((string) ($p['cta'] ?? 'Voir l’offre')), 0, 40),
        'source' => in_array((string) ($p['source'] ?? ''), ['manual', 'shooting', 'connector'], true) ? (string) $p['source'] : 'manual',
        'added' => (int) ($p['added'] ?? time()),
    ];
}

/** Liste enregistrée dans la galerie : 6 au plus, sans doublon commerce+référence. */
function gallery_promos_normalize($list): array
{
    $out = [];
    foreach ((array) $list as $p) {
        $promo = gallery_promo_normalize($p);
        if (!$promo || isset($out[gallery_promo_key($promo['shop'], $promo['ref'])])) continue;
        $out[gallery_promo_key($promo['shop'], $promo['ref'])] = $promo;
        if (count($out) >= GALLERY_PROMOS_MAX) break;
    }
    return array_values($out);
}

/** Jeton de l'application de prise de vue (aucun jeton tant qu'il n'est pas créé). */
function gallery_shooting_normalize($s): array
{
    $s = is_array($s) ? $s : [];
    $token = strtolower(trim((string) ($s['token'] ?? '')));
    return [
        'token' => preg_match(GALLERY_SHOOTING_TOKEN_PATTERN, $token) ? $token : '',
        'enabled' => !empty($s['enabled']) && preg_match(GALLERY_SHOOTING_TOKEN_PATTERN, $token),
        'label' => mb_substr(trim((string) ($s['label'] ?? 'Application de prise de vue')), 0, 80),
        'created' => (int) ($s['created'] ?? 0),
        'last_used' => (int) ($s['last_used'] ?? 0),
    ];
}

function gallery_shooting_authorized(array $gallery, string $token): bool
{
    $shooting = gallery_shooting_normalize($gallery['shooting'] ?? []);
    return $shooting['enabled'] && $shooting['token'] !== '' && $token !== '' && hash_equals($shooting['token'], strtolower($token));
}

/** Jetons et boutons de l'application de prise de vue, créés dans la galerie. */
function gallery_shooting_ensure(array &$gallery, bool $rotate = false): string
{
    $shooting = gallery_shooting_normalize($gallery['shooting'] ?? []);
    if ($rotate || !preg_match(GALLERY_SHOOTING_TOKEN_PATTERN, $shooting['token'])) {
        $shooting['token'] = bin2hex(random_bytes(16));
        $shooting['created'] = time();
        $shooting['enabled'] = true;
    }
    $gallery['shooting'] = $shooting;
    return $shooting['token'];
}

/**
 * Promos affichées : les articles retenus d'abord (fusionnés avec le catalogue pour le prix, la photo et le visuel),
 * puis, pour ne rien perdre, les articles du catalogue qui portent un visuel promo généré. 6 au plus.
 */
function gallery_promos_merge(array $products, array $promos): array
{
    $catalogue = [];
    foreach ($products as $p) {
        if (is_array($p)) $catalogue[gallery_promo_key((string) ($p['shop'] ?? ''), (string) ($p['ref'] ?? ''))] = $p;
    }
    $items = [];
    $seen = [];
    foreach (gallery_promos_normalize($promos) as $promo) {
        $key = gallery_promo_key($promo['shop'], $promo['ref']);
        $p = $catalogue[$key] ?? null;
        $item = $promo;
        if ($p) {
            $item['name'] = trim((string) ($p['name'] ?? '')) !== '' ? (string) $p['name'] : $item['name'];
            $item['price'] = (string) ($p['price'] ?? '') !== '' ? (string) $p['price'] : $item['price'];
            $item['promo_price'] = (string) ($p['promo_price'] ?? '') !== '' ? (string) $p['promo_price'] : $item['promo_price'];
            $item['url'] = (string) ($p['url'] ?? '') !== '' ? (string) $p['url'] : $item['url'];
            $item['photo'] = $item['photo'] !== '' ? $item['photo'] : ((string) preg_match('#^https?://#i', (string) ($p['photo'] ?? '')) ? (string) $p['photo'] : '');
            $item['promo_visual'] = (string) ($p['promo_visual'] ?? '');
            $item['promo_visual_mobile'] = (string) ($p['promo_visual_mobile'] ?? '');
            $item['promo_cta'] = (string) ($p['promo_cta'] ?? '');
        }
        $item['promo_visual'] = $item['promo_visual'] ?? '';
        $item['promo_visual_mobile'] = $item['promo_visual_mobile'] ?? '';
        $item['fallback'] = $item['promo_visual'] === '';
        $item['tracked'] = $p !== null;
        $items[$key] = $item;
        $seen[$key] = true;
        if (count($items) >= GALLERY_PROMOS_MAX) break;
    }
    foreach ($products as $p) {
        if (count($items) >= GALLERY_PROMOS_MAX) break;
        if (!is_array($p) || (string) ($p['promo_visual'] ?? '') === '') continue;
        $key = gallery_promo_key((string) ($p['shop'] ?? ''), (string) ($p['ref'] ?? ''));
        if (isset($seen[$key])) continue;
        $items[$key] = [
            'shop' => (string) ($p['shop'] ?? ''), 'shop_name' => '', 'ref' => (string) ($p['ref'] ?? ''),
            'name' => (string) ($p['name'] ?? ''), 'price' => (string) ($p['price'] ?? ''), 'promo_price' => (string) ($p['promo_price'] ?? ''),
            'photo' => (string) ($p['photo'] ?? ''), 'photo_mobile' => '', 'url' => (string) ($p['url'] ?? ''),
            'cta' => (string) ($p['promo_cta'] ?? '') ?: 'Voir l’offre',
            'source' => 'connector', 'added' => (int) ($p['created_at'] ?? 0),
            'promo_visual' => (string) $p['promo_visual'], 'promo_visual_mobile' => (string) ($p['promo_visual_mobile'] ?? ''),
            'fallback' => false, 'tracked' => true,
        ];
        $seen[$key] = true;
    }
    return array_values($items);
}

/** Vrai si l'article (commerce+référence) figure déjà parmi les promos retenues. */
function gallery_promo_exists(array $promos, string $shop, string $ref): bool
{
    foreach ($promos as $p) {
        if ((string) ($p['shop'] ?? '') === $shop && (string) ($p['ref'] ?? '') === $ref) return true;
    }
    return false;
}