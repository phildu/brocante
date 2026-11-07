<?php

// Plan d'implantation d'une galerie : des repères, zones (polygones) et tracés dessinés sur un fond de carte (plan ou orthophoto IGN), ou
// sans fond (plan seul), avec un catalogue de surfaces, des emplacements numérotés et la localisation de la marchandise de chaque commerce.
// Stocké dans la clé `map` de data/galeries/<id>.json ; tout ce qui vient du navigateur passe par gallery_map_normalize().
//
// Fonds de carte : WMTS de la Géoplateforme IGN, libre d'accès et sans clé. Les tuiles tile.openstreetmap.org sont exclues : leur politique
// d'usage réserve le service aux sites à faible trafic et bloque l'IP au-delà (chaque visiteur de la carte multipliait les requêtes).
const GALLERY_MAP_TILE_PLAN = 'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}&FORMAT=image/png';
const GALLERY_MAP_TILE_SAT = 'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=ORTHOIMAGERY.ORTHOPHOTOS&STYLE=normal&TILEMATRIXSET=PM&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}&FORMAT=image/jpeg';

const GALLERY_MAP_MAX_ITEMS = 600;
const GALLERY_MAP_MAX_CATALOG = 40;
const GALLERY_MAP_MAX_POINTS = 60;

/** Natures de zone (rue, hall…) proposées pour regrouper les emplacements d'un plan. */
const GALLERY_ZONE_TYPES = ['rue' => 'Rue', 'hall' => 'Hall', 'allee' => 'Allée', 'cour' => 'Cour', 'parking' => 'Parking', 'quartier' => 'Quartier', 'autre' => 'Autre zone'];
/** Unités de tarification d'une zone : par emplacement, par mètre linéaire de façade, par m². */
const GALLERY_PRICE_UNITS = ['slot' => 'par emplacement', 'ml' => 'par mètre linéaire', 'm2' => 'par m²'];
/** Affectation d'un élément du plan : déballage, circulation, service, parking, zone exclue, entrée / sortie. */
const GALLERY_USAGES = ['vente' => 'Déballage', 'circulation' => 'Circulation', 'service' => 'Service', 'parking' => 'Parking',
    'exclu' => 'Exclu du déballage', 'acces' => 'Entrée / sortie'];
/** Sens de circulation d'une voie (direct = ordre de dessin des points). */
const GALLERY_FLOWS = ['direct' => 'Sens direct', 'inverse' => 'Sens inverse', 'double' => 'Double sens'];

/** Clé d'un commerce membre, la même que dans gallery_catalogue() : l'identifiant local, ou « r » + empreinte de l'adresse. */
function gallery_member_key(array $m): string
{
    return ($m['type'] ?? '') === 'local' ? (string) $m['slug'] : 'r' . substr(md5((string) ($m['url'] ?? '')), 0, 8);
}

/**
 * Plan nettoyé, ou null s'il est vide. $allowedShops : clés de commerce acceptées (celles des membres) ; une autre clé est effacée.
 * $locations : lieux de vente du type de galerie (clés acceptées pour « ztype ») ; les anciens types de zone restent toujours acceptés.
 * Éléments : repère (1 point), zone (3 points ou plus, rectangle compris) ou tracé (2 points ou plus).
 */
function gallery_map_normalize(array $m, ?array $allowedShops = null, ?array $locations = null): ?array
{
    $locationKeys = [];
    foreach ((array) $locations as $loc) {
        $lk = is_array($loc) ? (string) ($loc['key'] ?? '') : '';
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,23}$/', $lk)) $locationKeys[$lk] = true;
    }
    $num = static fn ($v, float $min, float $max): ?float => is_numeric($v) && (float) $v >= $min && (float) $v <= $max ? round((float) $v, 6) : null;
    $items = [];
    foreach ((array) ($m['items'] ?? []) as $it) {
        if (!is_array($it) || count($items) >= GALLERY_MAP_MAX_ITEMS) continue;
        $kind = (string) ($it['kind'] ?? '');
        $min = ['marker' => 1, 'polygon' => 3, 'line' => 2][$kind] ?? 0;
        if ($min === 0) continue;
        $pts = [];
        foreach (array_slice((array) ($it['pts'] ?? []), 0, $kind === 'marker' ? 1 : GALLERY_MAP_MAX_POINTS) as $p) {
            $lat = is_array($p) ? $num($p[0] ?? null, -90, 90) : null;
            $lng = is_array($p) ? $num($p[1] ?? null, -180, 180) : null;
            if ($lat !== null && $lng !== null) $pts[] = [$lat, $lng];
        }
        if (count($pts) < $min) continue;
        $id = preg_match('/^[a-z0-9]{4,16}$/', (string) ($it['id'] ?? '')) ? (string) $it['id'] : substr(bin2hex(random_bytes(6)), 0, 8);
        $shop = preg_match('/^[a-z0-9_-]{1,40}$/', (string) ($it['shop'] ?? '')) ? (string) $it['shop'] : '';
        if ($shop !== '' && $allowedShops !== null && !in_array($shop, $allowedShops, true)) $shop = '';
        $color = (string) ($it['color'] ?? '');
        $numero = mb_substr(trim((string) ($it['num'] ?? '')), 0, 12);
        $items[] = ['id' => $id, 'kind' => $kind, 'pts' => $pts, 'shop' => $shop,
            'num' => preg_match('/^[\p{L}\p{N} ._\/#-]*$/u', $numero) ? $numero : '',
            'surface' => preg_match('/^[a-z0-9]{3,16}$/', (string) ($it['surface'] ?? '')) ? (string) $it['surface'] : '',
            'role' => $kind === 'polygon' && in_array((string) ($it['role'] ?? ''), ['zone', 'territoire', 'secteur'], true) ? (string) $it['role'] : ($kind === 'polygon' ? 'slot' : ''),
            'ztype' => isset($locationKeys[(string) ($it['ztype'] ?? '')]) || isset(GALLERY_ZONE_TYPES[(string) ($it['ztype'] ?? '')]) ? (string) $it['ztype'] : 'autre',
            'unit' => isset(GALLERY_PRICE_UNITS[(string) ($it['unit'] ?? '')]) ? (string) $it['unit'] : 'slot',
            'price_cents' => max(0, min(1000000, (int) ($it['price_cents'] ?? 0))),
            'price_pro_cents' => max(0, min(1000000, (int) ($it['price_pro_cents'] ?? 0))),
            'zone' => '',
            'label' => mb_substr(trim((string) ($it['label'] ?? '')), 0, 60),
            'note' => mb_substr(trim((string) ($it['note'] ?? '')), 0, 200),
            'usage' => isset(GALLERY_USAGES[(string) ($it['usage'] ?? '')]) ? (string) $it['usage'] : '',
            'flow' => isset(GALLERY_FLOWS[(string) ($it['flow'] ?? '')]) ? (string) $it['flow'] : '',
            'indoor' => !empty($it['indoor']),
            'service' => (string) ($it['usage'] ?? '') === 'service' ? mb_substr(trim((string) ($it['service'] ?? '')), 0, 40) : '',
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#b5502e'];
    }
    // Un emplacement appartient à la plus petite zone tarifaire qui contient son centre (calculé ici : on ne fait pas confiance au navigateur) ;
    // une zone de circulation, de service, de parking ou exclue ne capte pas les emplacements : un emplacement dont le centre tombe dedans reste sans zone.
    $zones = [];
    $blocked = [];
    foreach ($items as $i => $it) {
        if ($it['role'] !== 'zone') continue;
        if (in_array($it['usage'], ['', 'vente'], true)) $zones[$i] = gallery_map_area_m2($it['pts']);
        else $blocked[] = $i;
    }
    if ($zones) {
        foreach ($items as $i => &$it) {
            if ($it['role'] !== 'slot') continue;
            [$cLat, $cLng] = gallery_map_centroid($it['pts']);
            foreach ($blocked as $bi) if (gallery_map_contains($items[$bi]['pts'], $cLat, $cLng)) continue 2;
            $best = null;
            foreach ($zones as $zi => $area) if (gallery_map_contains($items[$zi]['pts'], $cLat, $cLng) && ($best === null || $area < $zones[$best])) $best = $zi;
            if ($best !== null) $it['zone'] = $items[$best]['id'];
        }
        unset($it);
    }
    $booking = (array) ($m['booking'] ?? []);
    $booking = ['enabled' => !empty($booking['enabled']), 'hold_days' => max(1, min(60, (int) ($booking['hold_days'] ?? 7))),
        'intro' => mb_substr(trim((string) ($booking['intro'] ?? '')), 0, 300)];
    // Hypothèses de simulation du chiffre d'affaires (aide au découpage) : coûts fixes de l'événement, coût par emplacement occupé, frais en % du CA,
    // remplissage et écart de prix des scénarios bas / central / haut.
    $co = (array) ($m['costs'] ?? []);
    $trio = static function ($v, array $def, float $min, float $max): array {
        $v = array_values((array) $v);
        return array_map(static fn ($i) => is_numeric($v[$i] ?? null) ? round(max($min, min($max, (float) $v[$i])), 1) : $def[$i], [0, 1, 2]);
    };
    $costs = ['fixed_cents' => max(0, min(100000000, (int) ($co['fixed_cents'] ?? 0))), 'var_cents' => max(0, min(10000000, (int) ($co['var_cents'] ?? 0))),
        'pct' => is_numeric($co['pct'] ?? null) ? round(max(0, min(100, (float) $co['pct'])), 2) : 0.0,
        'occ' => $trio($co['occ'] ?? null, [40, 65, 90], 0, 100), 'adj' => $trio($co['adj'] ?? null, [-10, 0, 10], -90, 500)];
    $catalog = [];
    foreach (array_slice((array) ($m['catalog'] ?? []), 0, GALLERY_MAP_MAX_CATALOG) as $c0) {
        if (!is_array($c0)) continue;
        $w = $num($c0['w'] ?? null, 0.5, 500);
        $d = $num($c0['d'] ?? null, 0.5, 500);
        $name = mb_substr(trim((string) ($c0['name'] ?? '')), 0, 40);
        if ($w === null || $d === null || $name === '') continue;
        $cc = (string) ($c0['color'] ?? '');
        $catalog[] = ['id' => preg_match('/^[a-z0-9]{3,16}$/', (string) ($c0['id'] ?? '')) ? (string) $c0['id'] : substr(bin2hex(random_bytes(6)), 0, 8),
            'name' => $name, 'w' => round($w, 2), 'd' => round($d, 2), 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $cc) ? strtolower($cc) : '#b5502e'];
    }
    $places = [];
    foreach ((array) ($m['places'] ?? []) as $key => $pl) {
        $key = (string) $key;
        if (!is_array($pl) || !preg_match('/^[a-z0-9_-]{1,40}$/', $key) || ($allowedShops !== null && !in_array($key, $allowedShops, true))) continue;
        $clean = static fn (string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($pl[$k] ?? ''))), 0, $max);
        $entry = ['street' => $clean('street', 120), 'postcode' => $clean('postcode', 10), 'city' => $clean('city', 60), 'stand' => $clean('stand', 12), 'note' => $clean('note', 200)];
        if (array_filter($entry)) $places[$key] = $entry;
    }
    $c = (array) ($m['center'] ?? []);
    $lat = $num($c[0] ?? null, -90, 90);
    $lng = $num($c[1] ?? null, -180, 180);
    // Fond importé (plan masse, cadastre…) : image stockée sous uploads/galeries/ par le portail, calée sur deux coins [N,O]/[S,E].
    $bg = (array) ($m['background'] ?? []);
    $bgPath = (string) ($bg['path'] ?? '');
    if (!preg_match('#^uploads/galeries/[a-z0-9][a-z0-9-]*-fond-[a-z0-9]+\.(?:jpe?g|png|webp)$#', $bgPath)) $bgPath = '';
    $bgBounds = [];
    foreach (array_slice((array) ($bg['bounds'] ?? []), 0, 2) as $pt) {
        $blat = is_array($pt) ? $num($pt[0] ?? null, -90, 90) : null;
        $blng = is_array($pt) ? $num($pt[1] ?? null, -180, 180) : null;
        if ($blat !== null && $blng !== null) $bgBounds[] = [$blat, $blng];
    }
    $background = $bgPath !== '' && count($bgBounds) === 2 ? ['path' => $bgPath, 'bounds' => $bgBounds, 'opacity' => round(max(0.1, min(1, (float) ($bg['opacity'] ?? 0.7))), 2)] : null;
    if (!$items && !$catalog && !$places && $lat === null) return null;
    return ['center' => $lat !== null && $lng !== null ? [$lat, $lng] : null,
        'zoom' => max(1, min(20, (int) ($m['zoom'] ?? 17))),
        'bearing' => max(-180, min(180, (int) round((float) ($m['bearing'] ?? 0)))),
        // Réglages de la stylisation IA du fond (style, niveau de simplification 1..5, consigne perso) ; revalidés à chaque génération.
        'ai' => [
            'style' => preg_match('/^[a-z]{1,20}$/', (string) ($m['ai']['style'] ?? '')) ? (string) $m['ai']['style'] : 'aquarelle',
            'level' => max(1, min(5, (int) ($m['ai']['level'] ?? 3) ?: 3)),
            'custom' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string) ($m['ai']['custom'] ?? ''))), 0, 400),
        ],
        'base' => in_array($m['base'] ?? '', ['sat', 'none'], true) ? (string) $m['base'] : 'osm',
        'catalog' => $catalog, 'places' => $places, 'booking' => $booking, 'costs' => $costs, 'background' => $background,
        'items' => $items];
}

/**
 * Localisation de la marchandise d'un commerce de ce serveur (saisie dans l'espace de la galerie ou celui de la boutique) : celle qui nourrit le champ
 * « adresse du bien » des plateformes. Première galerie qui en renseigne une. -> ['street','postcode','city','stand','note','address'] ou null.
 */
function gallery_member_place(string $shopSlug): ?array
{
    if (!function_exists('gallery_list')) return null;
    foreach (gallery_list() as $g) {
        if (!in_array($shopSlug, array_map('gallery_member_key', array_filter($g['members'], static fn ($m) => $m['type'] === 'local')), true)) continue;
        $pl = $g['map']['places'][$shopSlug] ?? null;
        if (!$pl || ($pl['street'] === '' && $pl['city'] === '')) continue;
        $town = trim($pl['postcode'] . ' ' . $pl['city']);
        return $pl + ['address' => trim(implode(', ', array_filter([$pl['street'], $town])))];
    }
    return null;
}

/** Surface (m²) d'une zone dessinée, projection locale approchée (assez précise pour un emplacement ou une halle). */
function gallery_map_area_m2(array $pts): float
{
    $n = count($pts);
    if ($n < 3) return 0.0;
    $kLat = 111320.0;
    $kLng = 111320.0 * cos(deg2rad((float) $pts[0][0]));
    $sum = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $a = $pts[$i];
        $b = $pts[($i + 1) % $n];
        $sum += ($a[1] * $kLng) * ($b[0] * $kLat) - ($b[1] * $kLng) * ($a[0] * $kLat);
    }
    return abs($sum) / 2;
}

/** Centre (moyenne des sommets) d'un ensemble de points [lat, lng]. -> [lat, lng] */
function gallery_map_centroid(array $pts): array
{
    $la = 0.0;
    $ln = 0.0;
    foreach ($pts as $p) {
        $la += $p[0];
        $ln += $p[1];
    }
    $n = max(1, count($pts));
    return [$la / $n, $ln / $n];
}

/** Le point est-il dans le polygone ? (lancer de rayon) */
function gallery_map_contains(array $poly, float $lat, float $lng): bool
{
    $in = false;
    for ($i = 0, $j = count($poly) - 1; $i < count($poly); $j = $i++) {
        [$yi, $xi] = $poly[$i];
        [$yj, $xj] = $poly[$j];
        if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $in = !$in;
    }
    return $in;
}

/** Longueur (m) du premier côté d'un polygone : la façade d'un emplacement dessiné par 4 coins. */
function gallery_map_front_m(array $pts): float
{
    if (count($pts) < 2) return 0.0;
    $kLat = 111320.0;
    $kLng = 111320.0 * cos(deg2rad((float) $pts[0][0]));
    return hypot(($pts[1][1] - $pts[0][1]) * $kLng, ($pts[1][0] - $pts[0][0]) * $kLat);
}

/**
 * Tarif d'un emplacement (centimes) d'après sa zone : particulier ou « entreprise » (0 = même tarif). null si la zone n'a pas de tarif.
 * @param array $map plan normalisé ; @param array $slot élément « emplacement » du plan
 */
function gallery_map_slot_price(array $map, array $slot, bool $pro = false): ?int
{
    $zone = null;
    foreach ($map['items'] as $it) if ($it['role'] === 'zone' && $it['id'] === $slot['zone']) $zone = $it;
    if (!$zone) return null;
    $rate = $pro && $zone['price_pro_cents'] > 0 ? $zone['price_pro_cents'] : $zone['price_cents'];
    if ($rate <= 0) return null;
    return match ($zone['unit']) {
        'ml' => (int) round($rate * gallery_map_front_m($slot['pts'])),
        'm2' => (int) round($rate * gallery_map_area_m2($slot['pts'])),
        default => $rate,
    };
}

/** Nom d'une zone : « Rue des Fleurs », « Hall A » ; le type n'est ajouté que si le nom ne le dit pas déjà (« Fleurs » → « Rue Fleurs »). */
function gallery_zone_name(array $zone, ?array $locations = null): string
{
    $labels = GALLERY_ZONE_TYPES;
    foreach ((array) $locations as $loc) {
        if (is_array($loc) && isset($loc['key'])) $labels[(string) $loc['key']] = (string) ($loc['label'] ?? $loc['key']);
    }
    $t = (string) ($labels[$zone['ztype'] ?? 'autre'] ?? '');
    $l = trim((string) ($zone['label'] ?? ''));
    if ($l === '') return $t;
    return ($t !== '' && ($zone['ztype'] ?? 'autre') !== 'autre' && mb_stripos($l, $t) !== 0) ? $t . ' ' . $l : $l;
}

/**
 * Secteurs (sous-zones) du plan : un bâtiment, un hall, une cour du territoire qui a sa propre page publique (?secteur=<identifiant>).
 * Un commerce appartient au plus petit secteur qui contient l'un de ses éléments du plan (emplacement, repère) ; un commerce posé hors de tout
 * secteur n'apparaît que sur la page d'ensemble. L'identifiant vient du nom (« Halle A » → halle-a) et reste unique dans le plan.
 * -> liste de ['id','slug','name','note','color','pts','area','shops' => [clés de commerce],'slots' => nombre d'emplacements]
 */
function gallery_map_sectors(?array $map): array
{
    $items = (array) ($map['items'] ?? []);
    $sectors = [];
    $used = [];
    foreach ($items as $it) {
        if (($it['role'] ?? '') !== 'secteur') continue;
        $name = trim((string) ($it['label'] ?? '')) ?: 'Secteur ' . (count($sectors) + 1);
        $base = function_exists('gallery_slugify') ? gallery_slugify($name) : trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = substr($base !== '' ? $base : 'secteur', 0, 34);
        $slug = $base;
        for ($n = 2; isset($used[$slug]); $n++) $slug = $base . '-' . $n;
        $used[$slug] = true;
        $sectors[] = ['id' => (string) $it['id'], 'slug' => $slug, 'name' => $name, 'note' => (string) ($it['note'] ?? ''), 'color' => (string) ($it['color'] ?? '#b5502e'),
            'pts' => $it['pts'], 'area' => gallery_map_area_m2($it['pts']), 'shops' => [], 'slots' => 0];
    }
    if (!$sectors) return [];
    foreach ($items as $it) {
        if (in_array($it['role'] ?? '', ['zone', 'territoire', 'secteur'], true)) continue;
        [$la, $ln] = gallery_map_centroid($it['pts']);
        $best = null;
        foreach ($sectors as $i => $sec) if (gallery_map_contains($sec['pts'], $la, $ln) && ($best === null || $sec['area'] < $sectors[$best]['area'])) $best = $i;
        if ($best === null) continue;
        if (($it['role'] ?? '') === 'slot') $sectors[$best]['slots']++;
        $shop = (string) ($it['shop'] ?? '');
        if ($shop !== '' && !in_array($shop, $sectors[$best]['shops'], true)) $sectors[$best]['shops'][] = $shop;
    }
    return $sectors;
}

/** Le secteur d'identifiant $slug dans le plan, ou null. */
function gallery_map_sector(?array $map, string $slug): ?array
{
    foreach (gallery_map_sectors($map) as $sec) if ($sec['slug'] === $slug) return $sec;
    return null;
}
