<?php

// Outils géographiques de la tournée : distance, ordre le plus court et géocodage d'une adresse (Géoplateforme IGN,
// sans clé). Fonctions pures, sans base ni session : testées par tests/VenteGeoTest.php.

/** Distance à vol d'oiseau entre deux points, en kilomètres (formule de haversine). */
function fv_geo_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0088;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = $p2 - $p1;
    $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/** Le prospect a-t-il des coordonnées exploitables ? */
function fv_geo_has(array $p): bool
{
    return isset($p['lat'], $p['lng']) && $p['lat'] !== '' && $p['lng'] !== '' && ((float) $p['lat'] !== 0.0 || (float) $p['lng'] !== 0.0);
}

/**
 * Ordre de passage le plus court (plus proche voisin, puis améliorations 2-opt) à partir du premier arrêt.
 * Les arrêts sans coordonnées gardent leur ordre d'origine et sont placés à la fin.
 *
 * @param array<int,array> $stops arrêts (clés lat, lng)
 * @return array<int,array>
 */
function fv_route_order(array $stops): array
{
    $stops = array_values($stops);
    $geo = [];
    $rest = [];
    foreach ($stops as $s) {
        if (fv_geo_has($s)) $geo[] = $s; else $rest[] = $s;
    }
    $n = count($geo);
    if ($n < 3) return array_merge($geo, $rest);

    $d = static fn (array $a, array $b): float => fv_geo_distance_km((float) $a['lat'], (float) $a['lng'], (float) $b['lat'], (float) $b['lng']);
    $order = [0];
    $left = range(1, $n - 1);
    while ($left) {
        $last = $geo[end($order)];
        $best = null;
        $bestD = INF;
        foreach ($left as $k => $idx) {
            $dist = $d($last, $geo[$idx]);
            if ($dist < $bestD) { $bestD = $dist; $best = $k; }
        }
        $order[] = $left[$best];
        array_splice($left, $best, 1);
    }

    // 2-opt, premier arrêt fixe (le point de départ ne bouge pas), parcours ouvert.
    $improved = true;
    $guard = 0;
    while ($improved && $guard++ < 50) {
        $improved = false;
        for ($i = 1; $i < $n - 1; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $before = $d($geo[$order[$i - 1]], $geo[$order[$i]]) + ($j + 1 < $n ? $d($geo[$order[$j]], $geo[$order[$j + 1]]) : 0.0);
                $after = $d($geo[$order[$i - 1]], $geo[$order[$j]]) + ($j + 1 < $n ? $d($geo[$order[$i]], $geo[$order[$j + 1]]) : 0.0);
                if ($after + 1e-9 < $before) {
                    $order = array_merge(array_slice($order, 0, $i), array_reverse(array_slice($order, $i, $j - $i + 1)), array_slice($order, $j + 1));
                    $improved = true;
                }
            }
        }
    }
    return array_merge(array_map(static fn (int $i): array => $geo[$i], $order), $rest);
}

/** Longueur totale d'une tournée (km à vol d'oiseau), arrêts sans coordonnées ignorés. */
function fv_route_length_km(array $stops): float
{
    $total = 0.0;
    $prev = null;
    foreach ($stops as $s) {
        if (!fv_geo_has($s)) continue;
        if ($prev) $total += fv_geo_distance_km((float) $prev['lat'], (float) $prev['lng'], (float) $s['lat'], (float) $s['lng']);
        $prev = $s;
    }
    return $total;
}

/** Adresse complète « rue, code postal ville » d'un prospect. */
function fv_prospect_address_line(array $p): string
{
    $street = trim((string) ($p['address'] ?? ''));
    $city = trim(trim((string) ($p['postal'] ?? '')) . ' ' . trim((string) ($p['city'] ?? '')));
    return trim($street . ($street !== '' && $city !== '' ? ', ' : '') . $city);
}

/** Extrait [lat, lng, score] de la réponse JSON du géocodeur IGN ; null si aucune adresse assez sûre. */
function fv_geocode_parse(string $json, float $minScore = 0.45): ?array
{
    $d = json_decode($json, true);
    $f = is_array($d) ? ($d['features'][0] ?? null) : null;
    $c = is_array($f) ? ($f['geometry']['coordinates'] ?? null) : null;
    if (!is_array($c) || count($c) < 2) return null;
    $score = (float) ($f['properties']['score'] ?? 0);
    if ($score < $minScore) return null;
    $lat = (float) $c[1];
    $lng = (float) $c[0];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return ['lat' => $lat, 'lng' => $lng, 'score' => $score];
}

/** Géocode une adresse (Géoplateforme IGN) ; null si introuvable ou service indisponible. */
function fv_geocode(string $address, string $postal = ''): ?array
{
    $address = trim($address);
    if ($address === '') return null;
    $q = ['index' => 'address', 'limit' => 1, 'q' => $address];
    if (preg_match('/^\d{5}$/', $postal)) $q['postcode'] = $postal;
    $url = 'https://data.geopf.fr/geocodage/search?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nUser-Agent: Brocs-tournee\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? fv_geocode_parse($body) : null;
}
