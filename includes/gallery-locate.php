<?php

// Localisation d'une galerie : les repères du plan (commerces, emplacements, zones) et l'adresse de l'événement, mis en forme pour les
// applications visiteur et exposant. La carte elle-même reste côté navigateur (Leaflet + fonds IGN), chargée seulement à la demande.
// Aucune donnée personnelle : noms publics des commerces, numéros d'emplacement, adresses saisies par l'organisateur.

require_once __DIR__ . '/galleries.php';
require_once __DIR__ . '/gallery-types.php';

/** Adresse de l'installation, pour construire les liens vers les boutiques de ce serveur. */
function gallery_locate_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Nom et adresse publique de chaque membre, sans lire les catalogues distants (le nom saisi suffit). */
function gallery_locate_shops(array $gallery, string $origin = ''): array
{
    $origin = $origin !== '' ? $origin : gallery_locate_origin();
    $out = [];
    foreach ($gallery['members'] as $m) {
        $key = gallery_member_key($m);
        if (($m['type'] ?? '') === 'local') {
            $name = ucfirst(str_replace('-', ' ', (string) ($m['slug'] ?? '')));
            $url = '';
            try {
                $config = tenant_load((string) $m['slug']);
                $name = trim((string) ($config['name'] ?? '')) ?: $name;
                $url = gallery_local_shop_url((string) $m['slug'], $config, $origin);
            } catch (Throwable $e) {
            }
            $out[$key] = ['name' => $name, 'url' => $url];
        } else {
            $name = trim((string) ($m['name'] ?? '')) ?: (string) parse_url((string) ($m['url'] ?? ''), PHP_URL_HOST);
            $out[$key] = ['name' => $name ?: 'Boutique', 'url' => rtrim((string) ($m['url'] ?? ''), '/')];
        }
    }
    return $out;
}

/** Adresse postale d'un lieu de commerce du plan : « 12 rue des Arts, 45000 Orléans ». */
function gallery_locate_place_address(array $place): string
{
    $town = trim((string) ($place['postcode'] ?? '') . ' ' . (string) ($place['city'] ?? ''));
    return trim(implode(', ', array_filter([(string) ($place['street'] ?? ''), $town], static fn ($v) => $v !== '')));
}

/** Point d'un élément du plan : le repère lui-même, ou le centre d'une zone / d'un emplacement. */
function gallery_locate_point(?array $item): ?array
{
    if (!$item || empty($item['pts'])) return null;
    if (($item['kind'] ?? '') === 'marker' || count($item['pts']) === 1) {
        $p = $item['pts'][0];
        return [(float) $p[0], (float) $p[1]];
    }
    return gallery_map_centroid($item['pts']);
}

/** Événement public de la galerie (module organisateur), ou null. */
function gallery_locate_event(array $gallery): ?array
{
    if (function_exists('gm_enabled') && !gm_enabled($gallery, 'organisateur')) return null;
    $e = gallery_event($gallery);
    if (!$e || empty($e['published'])) return null;
    $address = ge_place_text($e);
    $date = ge_date_text($e);
    if ($address === '' && $date === '') return null;
    return [
        'title' => trim((string) ($e['title'] ?? '')) ?: "L'événement",
        'date' => $date,
        'date_raw' => (string) ($e['date'] ?? ''),
        'end_raw' => (string) ($e['end_date'] ?? ''),
        'hours' => ge_hours_text($e),
        'address' => $address,
        'notes' => (string) ($e['notes'] ?? ''),
    ];
}

/** Lien d'itinéraire (Google Maps) vers un point ou une adresse. */
function gallery_locate_directions(?array $point, string $address = ''): string
{
    if ($point && isset($point[0], $point[1])) {
        return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode(number_format((float) $point[0], 6, '.', '') . ',' . number_format((float) $point[1], 6, '.', ''));
    }
    $address = trim($address);
    return $address !== '' ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($address) : '';
}

/** Fond de carte importé (plan masse), prêt pour L.imageOverlay, ou null. */
function gallery_locate_background(?array $map): ?array
{
    $bg = is_array($map['background'] ?? null) ? $map['background'] : null;
    if (!$bg || empty($bg['path']) || count((array) ($bg['bounds'] ?? [])) !== 2) return null;
    return ['url' => '/' . ltrim((string) $bg['path'], '/'), 'bounds' => $bg['bounds'], 'opacity' => (float) ($bg['opacity'] ?? 0.7)];
}

/**
 * Données de localisation pour une application : plan à dessiner, commerces localisés, emplacements numérotés et événement.
 * -> ['center','zoom','base','tiles','background','items','spots','event'] ou null si la galerie n'a rien à localiser.
 */
function gallery_locate_data(array $gallery, ?array $shops = null, string $origin = ''): ?array
{
    $origin = $origin !== '' ? $origin : gallery_locate_origin();
    $shops ??= gallery_locate_shops($gallery, $origin);
    $map = is_array($gallery['map'] ?? null) ? $gallery['map'] : null;
    $carteOn = !function_exists('gm_enabled') || gm_enabled($gallery, 'carte');
    $event = gallery_locate_event($gallery);
    $locations = function_exists('gt_locations') ? gt_locations($gallery) : null;
    $placeWord = function_exists('gt_word') ? (ucfirst(gt_word($gallery, 'place')) ?: 'Emplacement') : 'Emplacement';

    $items = [];
    $zoneNames = [];
    if ($carteOn && $map) {
        foreach (($map['items'] ?? []) as $it) {
            if (($it['role'] ?? '') === 'zone') $zoneNames[(string) $it['id']] = gallery_zone_name($it, $locations);
        }
        foreach (($map['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $role = (string) ($it['role'] ?? '');
            $shopKey = (string) ($it['shop'] ?? '');
            $label = trim((string) ($it['label'] ?? ''));
            $items[] = [
                'id' => (string) ($it['id'] ?? ''), 'kind' => (string) ($it['kind'] ?? ''), 'role' => $role, 'pts' => $it['pts'] ?? [],
                'color' => (string) ($it['color'] ?? '#b5502e'), 'label' => $label, 'num' => (string) ($it['num'] ?? ''),
                'zone' => $role === 'slot' ? (string) ($zoneNames[(string) ($it['zone'] ?? '')] ?? '') : '',
                'ztype' => $role === 'zone' ? (function_exists('gt_location_label') ? gt_location_label($gallery, (string) ($it['ztype'] ?? 'autre')) : (string) (GALLERY_ZONE_TYPES[(string) ($it['ztype'] ?? 'autre')] ?? '')) : '',
                'note' => (string) ($it['note'] ?? ''),
                'shopKey' => $shopKey,
                'shop' => $shopKey !== '' ? (string) ($shops[$shopKey]['name'] ?? '') : '',
            ];
        }
    }

    $spots = [];
    foreach ($gallery['members'] as $m) {
        $key = gallery_member_key($m);
        $shop = $shops[$key] ?? null;
        if (!$shop) continue;
        $place = is_array($map['places'][$key] ?? null) ? $map['places'][$key] : null;
        $mine = array_values(array_filter($items, static fn (array $it): bool => $it['shopKey'] === $key));
        $rank = static fn (array $it): int => ($it['kind'] ?? '') === 'marker' ? 0 : (['slot' => 1, 'zone' => 2, 'territoire' => 2, 'line' => 3][$it['role'] ?? ''] ?? 4);
        usort($mine, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));
        $item = $mine[0] ?? null;
        $slot = null;
        foreach ($mine as $candidate) if ($candidate['role'] === 'slot') { $slot = $candidate; break; }
        $point = gallery_locate_point($item);
        $stand = trim((string) ($place['stand'] ?? ''));
        if ($slot && $stand === '') $stand = trim($placeWord . ' ' . $slot['num'] . ($slot['label'] !== '' ? ' (' . $slot['label'] . ')' : ''));
        $address = $place ? gallery_locate_place_address($place) : '';
        $note = trim((string) ($place['note'] ?? '')) ?: trim((string) ($item['note'] ?? ''));
        if (!$point && $address === '' && $stand === '' && $note === '') continue;
        $spots[] = [
            'id' => 's:' . $key, 'type' => 'boutique', 'name' => (string) $shop['name'],
            'stand' => $stand, 'zone' => (string) ($slot['zone'] ?? ($item['ztype'] ?? '')),
            'address' => $address, 'note' => $note, 'point' => $point,
            'url' => (string) ($shop['url'] ?? ''),
        ];
    }
    foreach ($items as $it) {
        if ($it['role'] !== 'slot' || $it['shopKey'] !== '' || $it['num'] === '') continue;
        $point = gallery_locate_point($it);
        if (!$point) continue;
        $spots[] = [
            'id' => 'p:' . $it['id'], 'type' => 'emplacement', 'name' => $placeWord . ' ' . $it['num'],
            'stand' => $it['num'], 'zone' => $it['zone'], 'address' => '', 'note' => $it['note'], 'point' => $point, 'url' => '',
        ];
    }
    $center = is_array($map['center'] ?? null) ? $map['center'] : null;
    if ($event) {
        $eventPoint = $center;
        $spots[] = [
            'id' => 'evenement', 'type' => 'evenement', 'name' => $event['title'],
            'stand' => '', 'zone' => '', 'address' => $event['address'],
            'note' => trim($event['date'] . ($event['hours'] !== '' ? ' · ' . $event['hours'] : '')),
            'point' => $eventPoint, 'url' => '',
        ];
    }
    if (!$items && !$spots) return null;
    if (!$center) {
        $points = array_values(array_filter(array_map(static fn (array $s) => $s['point'], $spots)));
        if ($points) $center = gallery_map_centroid($points);
    }
    return [
        'center' => $center,
        'zoom' => $center ? max(1, min(20, (int) ($map['zoom'] ?? 17))) : 16,
        'base' => $carteOn && $map ? (string) ($map['base'] ?? 'osm') : 'none',
        'tiles' => ['osm' => GALLERY_MAP_TILE_PLAN, 'sat' => GALLERY_MAP_TILE_SAT],
        'background' => $carteOn ? gallery_locate_background($map) : null,
        'items' => $items,
        'spots' => $spots,
        'event' => $event,
    ];
}

/** Localisation d'un emplacement d'exposant (plan dessiné, ou adresse de l'événement), pour son application personnelle. */
function gallery_locate_slot(array $gallery, array $slot): array
{
    $map = is_array($gallery['map'] ?? null) ? $gallery['map'] : null;
    $carteOn = !function_exists('gm_enabled') || gm_enabled($gallery, 'carte');
    $event = gallery_locate_event($gallery);
    $point = $carteOn && ($slot['kind'] ?? '') !== 'event' ? gallery_locate_point($slot) : null;
    $zone = '';
    if ($carteOn && ($slot['zone'] ?? '') !== '') {
        foreach (($map['items'] ?? []) as $it) {
            if (($it['role'] ?? '') === 'zone' && (string) ($it['id'] ?? '') === (string) $slot['zone']) $zone = gallery_zone_name($it);
        }
    }
    $address = (string) ($event['address'] ?? '');
    return [
        'point' => $point, 'zone' => $zone, 'address' => $address, 'event' => $event,
        'zoom' => $carteOn && $map ? max(1, min(20, (int) ($map['zoom'] ?? 17))) : 16,
        'base' => $carteOn && $map ? (string) ($map['base'] ?? 'osm') : 'none',
        'tiles' => ['osm' => GALLERY_MAP_TILE_PLAN, 'sat' => GALLERY_MAP_TILE_SAT],
        'background' => $carteOn ? gallery_locate_background($map) : null,
        'directions' => gallery_locate_directions($point, $address),
    ];
}
