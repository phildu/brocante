<?php

// Découverte des zones d'un site d'exposition à partir des données IGN (BD TOPO, Géoplateforme, sans clé) : bâtiments
// (zones intérieures), voirie avec sens de circulation, parkings, équipements et espaces publics, points d'accès.
// Le territoire dessiné par l'organisateur est la limite : seules les zones qui le concernent sont retenues, avec leur
// surface, leur caractère intérieur/extérieur et une proposition d'usage (déballage, circulation, service, parking).
// Utilisé par l'éditeur de plan (portail/galerie-plan.php, action « discover ») ; aucune donnée n'est écrite ici.

const GD_WFS_URL = 'https://data.geopf.fr/wfs/ows';
const GD_CACHE_TTL = 2592000;        // 30 jours : la BD TOPO évolue lentement
const GD_MAX_AREA_M2 = 4000000.0;    // 4 km² : au-delà, l'analyse n'a plus de sens pour un événement
const GD_MAX_ZONES = 120;

/** Demande WFS parallèle (une par couche), avec cache fichier court. -> couche => features GeoJSON */
function gd_wfs_fetch(array $spec, array $bbox): array
{
    $dir = dirname(__DIR__) . '/data/galeries/cache';
    $key = md5(json_encode([$spec, array_map(static fn ($v) => round($v, 5), $bbox)]));
    $file = $dir . '/discovery-' . $key . '.json';
    if (is_file($file) && (time() - (int) filemtime($file)) < GD_CACHE_TTL) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw)) return $raw;
    }
    if (!function_exists('curl_multi_init')) return array_fill_keys(array_keys($spec), []);
    $mh = curl_multi_init();
    $handles = [];
    $box = implode(',', array_map(static fn ($v) => round($v, 7), $bbox));
    foreach ($spec as $layer => $cfg) {
        $query = http_build_query([
            'SERVICE' => 'WFS', 'VERSION' => '2.0.0', 'REQUEST' => 'GetFeature',
            'TYPENAMES' => 'BDTOPO_V3:' . $layer, 'OUTPUTFORMAT' => 'application/json', 'SRSNAME' => 'EPSG:4326',
            'COUNT' => (int) ($cfg['count'] ?? 600), 'BBOX' => $box, 'PROPERTYNAME' => implode(',', $cfg['properties']),
        ]);
        $ch = curl_init(GD_WFS_URL . '?' . $query);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Bricbrok-galerie/1.0 (+https://bricbrok.com)', CURLOPT_FOLLOWLOCATION => true]);
        curl_multi_add_handle($mh, $ch);
        $handles[$layer] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running);
    $out = [];
    foreach ($handles as $layer => $ch) {
        $body = (string) curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $json = $code === 200 ? json_decode($body, true) : null;
        $out[$layer] = is_array($json) && isset($json['features']) && is_array($json['features']) ? $json['features'] : [];
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    if (is_dir($dir) && is_writable($dir)) {
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $file); else @unlink($tmp);
    }
    return $out;
}

/** Anneaux extérieurs d'une géométrie GeoJSON, en [lat, lng] (les coordonnées IGN sont [lng, lat, altitude]). */
function gd_geom_rings(array $geom): array
{
    $rings = [];
    $type = (string) ($geom['type'] ?? '');
    $coords = $geom['coordinates'] ?? null;
    if (!is_array($coords)) return $rings;
    $parts = $type === 'Polygon' ? [$coords] : ($type === 'MultiPolygon' ? $coords : []);
    foreach ($parts as $part) {
        $ring = [];
        foreach ((array) ($part[0] ?? []) as $c) {
            if (!is_array($c) || !isset($c[0], $c[1]) || !is_numeric($c[0]) || !is_numeric($c[1])) continue;
            $ring[] = [(float) $c[1], (float) $c[0]];
        }
        if (count($ring) >= 3) {
            if (end($ring) === reset($ring)) array_pop($ring);
            $rings[] = $ring;
        }
    }
    return $rings;
}

/** Ligne(s) d'une géométrie GeoJSON, en [lat, lng]. */
function gd_geom_lines(array $geom): array
{
    $type = (string) ($geom['type'] ?? '');
    $coords = $geom['coordinates'] ?? null;
    if (!is_array($coords)) return [];
    $parts = $type === 'LineString' ? [$coords] : ($type === 'MultiLineString' ? $coords : []);
    $lines = [];
    foreach ($parts as $part) {
        $line = [];
        foreach ((array) $part as $c) {
            if (!is_array($c) || !isset($c[0], $c[1]) || !is_numeric($c[0]) || !is_numeric($c[1])) continue;
            $line[] = [(float) $c[1], (float) $c[0]];
        }
        if (count($line) >= 2) $lines[] = $line;
    }
    return $lines;
}

// --- Géométrie plane locale (mètres) : assez précise à l'échelle d'un site ---

function gd_xy(array $pts, float $lat0): array
{
    $kLat = 111320.0;
    $kLng = 111320.0 * cos(deg2rad($lat0));
    $out = [];
    foreach ($pts as $p) $out[] = [$p[1] * $kLng, $p[0] * $kLat];
    return $out;
}

function gd_ll(array $xy, float $lat0): array
{
    $kLat = 111320.0;
    $kLng = 111320.0 * cos(deg2rad($lat0));
    $out = [];
    foreach ($xy as $p) $out[] = [$p[1] / $kLat, $p[0] / $kLng];
    return $out;
}

function gd_area(array $xy): float
{
    $n = count($xy);
    if ($n < 3) return 0.0;
    $s = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $a = $xy[$i];
        $b = $xy[($i + 1) % $n];
        $s += $a[0] * $b[1] - $b[0] * $a[1];
    }
    return abs($s) / 2;
}

function gd_centroid(array $xy): array
{
    $x = 0.0;
    $y = 0.0;
    foreach ($xy as $p) {
        $x += $p[0];
        $y += $p[1];
    }
    $n = max(1, count($xy));
    return [$x / $n, $y / $n];
}

function gd_point_in(array $poly, float $x, float $y): bool
{
    $in = false;
    for ($i = 0, $j = count($poly) - 1; $i < count($poly); $j = $i++) {
        [$xi, $yi] = $poly[$i];
        [$xj, $yj] = $poly[$j];
        if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $in = !$in;
    }
    return $in;
}

/** Part des sommets du contour situés dans le territoire (0..1) ; null si le contour est trop petit. */
function gd_inside_ratio(array $ringLl, array $terrLl): float
{
    if (count($ringLl) < 3) return 0.0;
    $lat0 = $ringLl[0][0];
    $ring = gd_xy($ringLl, $lat0);
    $terr = gd_xy($terrLl, $lat0);
    $inside = 0;
    foreach ($ring as $p) if (gd_point_in($terr, $p[0], $p[1])) $inside++;
    return $inside / count($ring);
}

/** Intersection de deux segments (coordonnées planes) ; null si parallèles ou hors segments. */
function gd_seg_cross(array $a, array $b, array $c, array $d): ?array
{
    $rx = $b[0] - $a[0];
    $ry = $b[1] - $a[1];
    $sx = $d[0] - $c[0];
    $sy = $d[1] - $c[1];
    $den = $rx * $sy - $ry * $sx;
    if (abs($den) < 1e-12) return null;
    $t = (($c[0] - $a[0]) * $sy - ($c[1] - $a[1]) * $sx) / $den;
    $u = (($c[0] - $a[0]) * $ry - ($c[1] - $a[1]) * $rx) / $den;
    if ($t < -1e-9 || $t > 1 + 1e-9 || $u < -1e-9 || $u > 1 + 1e-9) return null;
    return [$a[0] + $t * $rx, $a[1] + $t * $ry];
}

/** Points où une ligne coupe le contour du territoire (coordonnées planes). */
function gd_crossings(array $terr, array $line): array
{
    $pts = [];
    $n = count($terr);
    for ($i = 0; $i < count($line) - 1; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $p = gd_seg_cross($line[$i], $line[$i + 1], $terr[$j], $terr[($j + 1) % $n]);
            if ($p !== null) $pts[] = $p;
        }
    }
    return $pts;
}

/** Parties d'une ligne situées dans le polygone : coupure aux intersections, conservation des tronçons intérieurs. */
function gd_clip_line(array $terr, array $line): array
{
    $cuts = [0.0, (float) (count($line) - 1)];
    $n = count($terr);
    for ($i = 0; $i < count($line) - 1; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $p = gd_seg_cross($line[$i], $line[$i + 1], $terr[$j], $terr[($j + 1) % $n]);
            if ($p === null) continue;
            $t = $line[$i + 1][0] != $line[$i][0] ? ($p[0] - $line[$i][0]) / ($line[$i + 1][0] - $line[$i][0]) : ($p[1] - $line[$i][1]) / (($line[$i + 1][1] - $line[$i][1]) ?: 1e-12);
            $cuts[] = max(0.0, min(1.0, $i + $t));
        }
    }
    $cuts = array_values(array_unique(array_map(static fn ($v) => round($v, 6), $cuts)));
    sort($cuts);
    $out = [];
    $at = static function (float $t) use ($line): array {
        $i = min(count($line) - 2, (int) floor($t));
        $f = $t - $i;
        return [$line[$i][0] + ($line[$i + 1][0] - $line[$i][0]) * $f, $line[$i][1] + ($line[$i + 1][1] - $line[$i][1]) * $f];
    };
    for ($k = 0; $k < count($cuts) - 1; $k++) {
        $mid = $at(($cuts[$k] + $cuts[$k + 1]) / 2);
        if (!gd_point_in($terr, $mid[0], $mid[1])) continue;
        $out[] = [$at($cuts[$k]), $at($cuts[$k + 1])];
    }
    return $out;
}

/** Contour d'une voie (largeur en mètres) autour d'une ligne, extrémités droites. */
function gd_buffer(array $line, float $width): array
{
    $h = max(0.8, $width / 2);
    $left = [];
    $right = [];
    for ($i = 0; $i < count($line) - 1; $i++) {
        $dx = $line[$i + 1][0] - $line[$i][0];
        $dy = $line[$i + 1][1] - $line[$i][1];
        $len = hypot($dx, $dy);
        if ($len < 1e-9) continue;
        $nx = -$dy / $len * $h;
        $ny = $dx / $len * $h;
        $left[] = [$line[$i][0] + $nx, $line[$i][1] + $ny];
        $right[] = [$line[$i][0] - $nx, $line[$i][1] - $ny];
        if ($i === count($line) - 2) {
            $left[] = [$line[$i + 1][0] + $nx, $line[$i + 1][1] + $ny];
            $right[] = [$line[$i + 1][0] - $nx, $line[$i + 1][1] - $ny];
        }
    }
    return array_merge($left, array_reverse($right));
}

/** Simplification Douglas-Peucker, puis réduction jusqu'à $max sommets (le plan en accepte 60). */
function gd_simplify(array $ring, int $max = 60): array
{
    if (count($ring) <= $max) return $ring;
    $dp = static function (array $pts, float $tol) use (&$dp): array {
        if (count($pts) < 3) return $pts;
        $a = $pts[0];
        $b = $pts[count($pts) - 1];
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $den = hypot($dx, $dy) ?: 1e-12;
        $idx = -1;
        $best = 0.0;
        for ($i = 1; $i < count($pts) - 1; $i++) {
            $d = abs($dy * ($pts[$i][0] - $a[0]) - $dx * ($pts[$i][1] - $a[1])) / $den;
            if ($d > $best) { $best = $d; $idx = $i; }
        }
        if ($best <= $tol || $idx < 0) return [$a, $b];
        $l = $dp(array_slice($pts, 0, $idx + 1), $tol);
        $r = $dp(array_slice($pts, $idx), $tol);
        array_pop($l);
        return array_merge($l, $r);
    };
    $closed = $ring;
    $closed[] = $ring[0];
    $tol = 0.25;
    $out = $ring;
    while ($tol < 8.0) {
        $out = $dp($closed, $tol);
        array_pop($out);
        if (count($out) <= $max) break;
        $tol *= 1.6;
    }
    if (count($out) > $max) {   // dernier recours : échantillonnage régulier
        $step = ceil(count($out) / $max);
        $out = array_values(array_filter($out, static fn ($v, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
    }
    return count($out) >= 3 ? $out : $ring;
}

/** Libellé de voie : nom collaboratif ou toponyme, sinon nature. */
function gd_road_name(array $p, string $fallback): string
{
    foreach (['nom_collaboratif_gauche', 'nom_collaboratif_droite', 'cpx_toponyme_route_nommee', 'nom_voie_ban_gauche'] as $k) {
        $v = trim((string) ($p[$k] ?? ''));
        if ($v !== '') return mb_convert_case(mb_strtolower($v), MB_CASE_TITLE, 'UTF-8');
    }
    return $fallback;
}

/** Sens de circulation BD TOPO -> direct / inverse / double (direct = ordre des points). */
function gd_flow(array $p): string
{
    $s = mb_strtolower((string) ($p['sens_de_circulation'] ?? ''));
    if (str_contains($s, 'double')) return 'double';
    if (str_contains($s, 'inverse')) return 'inverse';
    if (str_contains($s, 'direct')) return 'direct';
    return '';
}

/** Type de service déduit de la nature IGN, quand elle est explicite. */
function gd_service_kind(string $nature): string
{
    $n = mb_strtolower($nature);
    if (preg_match('/restaur|café|cafe|brasserie/', $n)) return 'Restauration';
    if (preg_match('/sanitaire|toilette|wc/', $n)) return 'Sanitaires';
    if (preg_match('/accueil|mairie/', $n)) return 'Accueil';
    if (preg_match('/salle|musée|médiathèque|centre culturel/', $n)) return 'Salle';
    return '';
}

/**
 * Zones pertinentes du territoire (validé) d'après la BD TOPO : bâtiments intérieurs, voirie (sens de circulation),
 * parkings, équipements et espaces publics, plus les points d'entrée / sortie à la limite du territoire.
 * $opts : min_area (m²), roads, parking, services (bool).
 */
function gallery_discover_site(array $territory, array $opts = []): array
{
    $pts = [];
    foreach ($territory as $p) {
        if (!is_array($p) || !isset($p[0], $p[1]) || !is_numeric($p[0]) || !is_numeric($p[1])) continue;
        $lat = (float) $p[0];
        $lng = (float) $p[1];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $pts[] = [$lat, $lng];
    }
    if (count($pts) < 3) return ['ok' => false, 'error' => 'Dessinez d\'abord un territoire (nature « Territoire ») sur le plan.'];
    if (count($pts) > 60) $pts = array_slice($pts, 0, 60);
    $lat0 = $pts[0][0];
    $terr = gd_xy($pts, $lat0);
    $terrArea = gd_area($terr);
    if ($terrArea < 50) return ['ok' => false, 'error' => 'Le territoire est trop petit pour être analysé (moins de 50 m²).'];
    if ($terrArea > GD_MAX_AREA_M2) return ['ok' => false, 'error' => 'Le territoire dépasse 4 km² : découpez-le en plusieurs territoires pour une analyse utile.'];

    $minArea = max(1.0, min(5000.0, (float) ($opts['min_area'] ?? 25)));
    $useRoads = !empty($opts['roads']);
    $useParking = !empty($opts['parking']);
    $useServices = !empty($opts['services']);

    $latMin = min(array_column($pts, 0));
    $latMax = max(array_column($pts, 0));
    $lngMin = min(array_column($pts, 1));
    $lngMax = max(array_column($pts, 1));
    $d = 0.00012;   // ~13 m de marge : une voie qui borde le territoire doit être vue
    $bbox = [$latMin - $d, $lngMin - $d, $latMax + $d, $lngMax + $d];

    $data = gd_wfs_fetch([
        'batiment' => ['count' => 800, 'properties' => ['cleabs', 'nature', 'usage_1', 'hauteur', 'geometrie']],
        'troncon_de_route' => ['count' => 900, 'properties' => ['cleabs', 'nature', 'nom_collaboratif_gauche', 'nom_collaboratif_droite', 'sens_de_circulation', 'largeur_de_chaussee', 'importance', 'position_par_rapport_au_sol', 'prive', 'geometrie']],
        'equipement_de_transport' => ['count' => 250, 'properties' => ['cleabs', 'nature', 'nature_detaillee', 'toponyme', 'geometrie']],
        'zone_d_activite_ou_d_interet' => ['count' => 250, 'properties' => ['cleabs', 'categorie', 'nature', 'nature_detaillee', 'toponyme', 'geometrie']],
    ], $bbox);

    $zones = [];
    $entries = [];
    $seenEntry = [];
    $counts = ['batiment' => 0, 'circulation' => 0, 'parking' => 0, 'service' => 0, 'espace' => 0];
    $add = static function (array $zone) use (&$zones, &$counts, $minArea, $lat0): void {
        if ($zone['area'] < $minArea) return;
        $zone['pts'] = gd_ll(gd_simplify(gd_xy($zone['pts'], $lat0), 60), $lat0);   // 60 sommets au plus, comme le plan
        $counts[$zone['type']] = ($counts[$zone['type']] ?? 0) + 1;
        $zones[] = $zone;
    };

    // Bâtiments : intérieur, usage déballage proposé (l'organisateur peut les exclure ensuite).
    foreach ((array) ($data['batiment'] ?? []) as $f) {
        foreach (gd_geom_rings((array) ($f['geometry'] ?? [])) as $ring) {
            $ratio = gd_inside_ratio($ring, $pts);
            if ($ratio < 0.5) continue;
            $ringXY = gd_xy($ring, $lat0);
            $area = gd_area($ringXY);
            if ($area <= 0 || $area > $terrArea * 0.92) continue;
            $p = (array) ($f['properties'] ?? []);
            $nat = (string) ($p['nature'] ?? 'Bâtiment');
            $use = trim((string) ($p['usage_1'] ?? ''));
            $label = 'Bâtiment — ' . $nat . ($use !== '' && mb_strtolower($use) !== mb_strtolower($nat) ? ' (' . $use . ')' : '');
            $add(['type' => 'batiment', 'usage' => 'vente', 'flow' => '', 'indoor' => true, 'label' => mb_substr($label, 0, 60),
                'ztype' => 'autre', 'area' => round($area * $ratio, 1), 'pts' => $ring, 'service' => '', 'source' => 'BD TOPO · bâtiment']);
        }
    }

    // Voirie : tronçons intérieurs au territoire, élargis à leur largeur ; les points de franchissement deviennent
    // des entrées / sorties. Le sens de circulation IGN est conservé.
    if ($useRoads) {
        foreach ((array) ($data['troncon_de_route'] ?? []) as $f) {
            $p = (array) ($f['properties'] ?? []);
            if (!empty($p['prive']) || (string) ($p['position_par_rapport_au_sol'] ?? '0') !== '0') continue;   // pas les tunnels ni les voies privées
            foreach (gd_geom_lines((array) ($f['geometry'] ?? [])) as $line) {
                $lineXY = gd_xy($line, $lat0);
                $cross = gd_crossings($terr, $lineXY);
                if (!$cross) continue;
                $name = gd_road_name($p, (string) ($p['nature'] ?? 'Voirie'));
                foreach ($cross as $c) {   // entrées / sorties potentielles
                    $k = round($c[0] / 9) . ':' . round($c[1] / 9);
                    if (isset($seenEntry[$k])) continue;
                    $seenEntry[$k] = true;
                    $entries[] = ['ll' => gd_ll([$c], $lat0)[0], 'label' => 'Entrée / sortie — ' . $name,
                        'flow' => gd_flow($p), 'importance' => (int) ($p['importance'] ?? 5)];
                }
                foreach (gd_clip_line($terr, $lineXY) as $part) {
                    $w = (float) str_replace(',', '.', (string) ($p['largeur_de_chaussee'] ?? ''));
                    $w = $w >= 1 ? max(2.0, min(16.0, $w)) : 4.0;
                    $ringXY = gd_buffer($part, $w);
                    $area = gd_area($ringXY);
                    if ($area < max(6.0, $minArea / 2)) continue;
                    $add(['type' => 'circulation', 'usage' => 'circulation', 'flow' => gd_flow($p), 'indoor' => false,
                        'label' => mb_substr($name, 0, 60), 'ztype' => 'rue', 'area' => round($area, 1),
                        'pts' => gd_ll($ringXY, $lat0), 'service' => '', 'source' => 'BD TOPO · voirie']);
                }
            }
        }
    }

    // Parkings et équipements.
    foreach ((array) ($data['equipement_de_transport'] ?? []) as $f) {
        $p = (array) ($f['properties'] ?? []);
        $nat = (string) ($p['nature'] ?? '');
        $isPark = mb_stripos($nat, 'parking') !== false;
        if ($isPark ? !$useParking : !$useServices) continue;
        foreach (gd_geom_rings((array) ($f['geometry'] ?? [])) as $ring) {
            $ratio = gd_inside_ratio($ring, $pts);
            if ($ratio < 0.4) continue;
            $area = gd_area(gd_xy($ring, $lat0)) * $ratio;
            $topo = trim((string) ($p['toponyme'] ?? ''));
            $label = ($isPark ? 'Parking' : $nat) . ($topo !== '' ? ' — ' . $topo : '');
            $add(['type' => $isPark ? 'parking' : 'service', 'usage' => $isPark ? 'parking' : 'service', 'flow' => '', 'indoor' => false,
                'label' => mb_substr($label, 0, 60), 'ztype' => 'autre', 'area' => round($area, 1), 'pts' => $ring,
                'service' => $isPark ? '' : gd_service_kind($nat), 'source' => 'BD TOPO · équipement']);
        }
    }

    // Espaces publics et équipements de proximité (mairie, musée, salle, piscine…).
    if ($useServices) {
        foreach ((array) ($data['zone_d_activite_ou_d_interet'] ?? []) as $f) {
            $p = (array) ($f['properties'] ?? []);
            $nat = (string) ($p['nature'] ?? '');
            $topo = trim((string) ($p['toponyme'] ?? ''));
            foreach (gd_geom_rings((array) ($f['geometry'] ?? [])) as $ring) {
                $ratio = gd_inside_ratio($ring, $pts);
                if ($ratio < 0.55) continue;
                $area = gd_area(gd_xy($ring, $lat0)) * $ratio;
                if ($area > $terrArea * 0.6) {   // c'est le site lui-même (place, halle…) : espace de déballage extérieur
                    $add(['type' => 'espace', 'usage' => 'vente', 'flow' => '', 'indoor' => false, 'label' => mb_substr($topo !== '' ? $topo : $nat, 0, 60),
                        'ztype' => 'autre', 'area' => round($area, 1), 'pts' => $ring, 'service' => '', 'source' => 'BD TOPO · espace']);
                } else {
                    $add(['type' => 'service', 'usage' => 'service', 'flow' => '', 'indoor' => false, 'label' => mb_substr(($topo !== '' ? $topo : $nat), 0, 60),
                        'ztype' => 'autre', 'area' => round($area, 1), 'pts' => $ring, 'service' => gd_service_kind($nat), 'source' => 'BD TOPO · ' . ($p['categorie'] ?? 'équipement')]);
                }
            }
        }
    }

    // Pertinence : plus grande surface d'abord, plafond de zones ; les entrées par importance de voie.
    usort($zones, static fn ($a, $b) => $b['area'] <=> $a['area']);
    $zones = array_slice($zones, 0, GD_MAX_ZONES);
    usort($entries, static fn ($a, $b) => ($a['importance'] <=> $b['importance']) ?: strcmp($a['label'], $b['label']));
    $entries = array_slice($entries, 0, 10);
    foreach ($entries as $i => &$e) { $e['pts'] = [$e['ll']]; unset($e['ll']); $e['indoor'] = false; if ($i === 0) $e['main'] = true; }
    unset($e);

    // Part du territoire couverte par des bâtiments : événement intérieur ou extérieur.
    $built = 0.0;
    foreach ($zones as $z) if ($z['type'] === 'batiment') $built += $z['area'];
    $coverage = $terrArea > 0 ? min(1.0, $built / $terrArea) : 0.0;
    $excluded = 0.0;
    foreach ($zones as $z) if (in_array($z['usage'], ['circulation', 'parking', 'service'], true)) $excluded += $z['area'];
    $warnings = [];
    if (!$data['batiment'] && !$data['troncon_de_route'] && !$data['equipement_de_transport'] && !$data['zone_d_activite_ou_d_interet']) {
        $warnings[] = 'Aucune donnée IGN reçue pour cet endroit (hors France, réseau indisponible ou territoire trop petit).';
    }
    if (count($zones) >= GD_MAX_ZONES) $warnings[] = 'Analyse limitée aux ' . GD_MAX_ZONES . ' plus grandes zones.';
    return ['ok' => true, 'territory' => ['area' => round($terrArea, 1), 'pts' => count($pts)],
        'event' => ['indoor' => $coverage >= 0.35, 'coverage' => round($coverage * 100, 1)],
        'zones' => $zones, 'entries' => $entries,
        'stats' => ['counts' => $counts, 'excluded_area' => round(min($terrArea, $excluded), 1),
            'useful_area' => round(max(0, $terrArea - $excluded), 1), 'min_area' => $minArea],
        'warnings' => $warnings];
}
