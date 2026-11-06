<?php

// Assistance IA du zonage : l'orthophoto IGN du territoire et la liste des zones détectées par la BD TOPO sont
// envoyées à Gemini, qui propose des libellés et des affectations, signale les espaces à exclure du déballage et
// repère dans l'image les éléments absents des vecteurs (tentes, stands, zones de stationnement). Le résultat est
// mis en cache 30 jours ; aucune donnée du plan n'est écrite ici. Appelé par portail/galerie-plan.php (action
// « discover » avec ai=1), après gallery_discover_site().

require_once __DIR__ . '/omniroute.php';

const GAIZ_VERSION = 1;
const GAIZ_MODEL = 'gemini-2.5-flash';
const GAIZ_CACHE_TTL = 2592000;        // 30 jours : l'orthophoto et la BD TOPO évoluent lentement
const GAIZ_MAX_ZONES = 60;             // zones envoyées au modèle (les plus grandes)
const GAIZ_MAX_EXTRAS = 12;            // éléments supplémentaires proposés par le modèle
const GAIZ_IMAGE_SIZE = 1024;

/** Clé Gemini du portail (même clé que la génération de contenu), ou variable d'environnement. */
function gaiz_key(): string
{
    if (function_exists('portail_gemini_key')) return portail_gemini_key();
    $env = trim((string) getenv('GEMINI_API_KEY'));
    return $env;
}

function gaiz_mercator(float $lat, float $lng): array
{
    $x = $lng * 20037508.34 / 180.0;
    $y = log(tan((90.0 + $lat) * M_PI / 360.0)) * 20037508.34 / M_PI;
    return [$x, $y];
}

function gaiz_latlng(float $x, float $y): array
{
    $lng = $x * 180.0 / 20037508.34;
    $lat = 360.0 / M_PI * atan(exp($y * M_PI / 20037508.34)) - 90.0;
    return [$lat, $lng];
}

/** Emprise carrée (Web Mercator) du territoire, avec 8 % de marge : l'image et les boîtes de l'IA partagent ce cadre. */
function gaiz_bbox(array $pts): array
{
    $xs = [];
    $ys = [];
    foreach ($pts as $p) {
        [$x, $y] = gaiz_mercator((float) $p[0], (float) $p[1]);
        $xs[] = $x;
        $ys[] = $y;
    }
    $cx = (min($xs) + max($xs)) / 2;
    $cy = (min($ys) + max($ys)) / 2;
    $half = max(max($xs) - min($xs), max($ys) - min($ys)) / 2;
    $half = max($half * 1.08, 40.0);   // au moins 80 m de côté
    return [$cx - $half, $cy - $half, $cx + $half, $cy + $half];
}

/** Orthophoto JPEG du territoire par le WMS de la Géoplateforme (sans clé). -> [octets, emprise] ou null. */
function gaiz_orthophoto(array $pts, int $size = GAIZ_IMAGE_SIZE): ?array
{
    [$x0, $y0, $x1, $y1] = gaiz_bbox($pts);
    $url = 'https://data.geopf.fr/wms-r/wms?' . http_build_query([
        'SERVICE' => 'WMS', 'VERSION' => '1.3.0', 'REQUEST' => 'GetMap',
        'LAYERS' => 'ORTHOIMAGERY.ORTHOPHOTOS', 'STYLES' => '', 'FORMAT' => 'image/jpeg',
        'CRS' => 'EPSG:3857', 'BBOX' => implode(',', array_map(static fn ($v) => round($v, 3), [$x0, $y0, $x1, $y1])),
        'WIDTH' => $size, 'HEIGHT' => $size,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'Bricbrok-galerie/1.0 (+https://bricbrok.com)', CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if ($body === false || $code !== 200 || !str_starts_with($type, 'image/')) return null;
    return [$body, [$x0, $y0, $x1, $y1]];
}

/** Consigne envoyée au modèle : zones détectées + recherche des éléments visibles absents des vecteurs. */
function gaiz_prompt(array $zones, array $idx): string
{
    $lines = '';
    foreach ($idx as $i) {
        $z = $zones[$i];
        $lines .= $i . ' | ' . $z['type'] . ' | ' . mb_substr((string) $z['label'], 0, 80) . ' | '
            . round((float) $z['area']) . ' m² | ' . (($z['usage'] ?? '') !== '' ? $z['usage'] : 'vente') . ' | '
            . (!empty($z['indoor']) ? 'intérieur' : 'extérieur') . "\n";
    }
    return "Tu aides un organisateur de brocante, vide-grenier, marché aux puces, foire ou galerie marchande à préparer le plan de son événement.\n\n"
        . "L'image jointe est l'orthophoto aérienne du territoire de l'événement ; le nord est en haut. Les zones ci-dessous ont été détectées par la BD TOPO de l'IGN ; leur emprise et leur surface sont fiables. L'image sert à préciser leur usage, à repérer ce qu'il faut exclure du déballage et à voir les installations absentes des vecteurs.\n\n"
        . "Zones détectées (indice | type | libellé | surface | affectation proposée | intérieur/extérieur) :\n" . $lines . "\n"
        . "Pour chaque zone, propose :\n"
        . "- label : libellé court et concret en français (60 caractères au plus) ;\n"
        . "- usage : vente (déballage), circulation, service, parking ou exclu (espace où aucun emplacement ne doit être installé) ;\n"
        . "- service : seulement si usage = service, précision de 40 caractères au plus (accueil, restauration, sanitaires, secours, technique…) ; sinon \"\" ;\n"
        . "- indoor : true si la zone est couverte ou dans un bâtiment, false sinon ;\n"
        . "- confidence : haute, moyenne ou basse selon ce que l'image permet réellement de voir ;\n"
        . "- why : justification en 12 mots au plus.\n\n"
        . "Règles : un bâtiment est en principe « vente » (le public déambule à l'intérieur) ; ne l'exclus que si l'image montre clairement une zone technique, un accès secours, une construction privée ou un espace inaccessible. Une route est « circulation », un parking « parking », un équipement public « service » avec le service précis. Si l'image ne permet pas de juger, mets confidence = basse et conserve l'affectation proposée.\n\n"
        . "Cherche aussi dans l'image les éléments importants absents de la liste (rangées de tentes, stands, barnums, zone de stationnement, espace d'accueil visible…). Donne au plus " . GAIZ_MAX_EXTRAS . " éléments : label, usage, indoor, confidence, why, et box = [x, y, w, h] normalisés sur l'image (x, y = coin haut gauche, valeurs de 0 à 1). Ne propose que des éléments nettement visibles et de taille significative ; n'invente rien.\n\n"
        . "Réponds UNIQUEMENT avec un JSON strict, sans texte autour, de cette forme :\n"
        . '{"site":{"indoor":true,"summary":"1 phrase sur le site"},"zones":[{"i":0,"label":"...","usage":"vente","service":"","indoor":true,"confidence":"haute","why":"..."}],"extras":[{"label":"...","usage":"vente","box":[0.1,0.2,0.05,0.08],"indoor":false,"confidence":"moyenne","why":"..."}]}';
}

/** Objet JSON de la réponse du modèle, avec ou sans clôture ``` ; null si inexploitable. */
function gaiz_json(string $text): ?array
{
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));
    $data = json_decode($text, true);
    if (!is_array($data)) {
        // Certains modèles ajoutent une phrase avant le JSON : on isole le premier objet complet.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) $data = json_decode(substr($text, $start, $end - $start + 1), true);
    }
    return is_array($data) ? $data : null;
}

/** Relais par la passerelle locale : même consigne, même orthophoto. Null si elle est muette. */
function gaiz_relay(string $prompt, string $jpeg): ?array
{
    $tmp = tempnam(sys_get_temp_dir(), 'gaiz-');
    if ($tmp === false) return null;
    try {
        file_put_contents($tmp, $jpeg);
        $text = omniroute_text($prompt, [$tmp], 45);
        return $text !== null ? gaiz_json($text) : null;
    } finally {
        @unlink($tmp);
    }
}

/** Appel Gemini vision -> tableau JSON décodé. Lève RuntimeException avec un message affichable. */
function gaiz_gemini(string $prompt, string $jpeg): array
{
    $key = gaiz_key();
    if ($key === '') {
        $relayed = gaiz_relay($prompt, $jpeg);
        if ($relayed !== null) return $relayed;
        throw new RuntimeException("La clé IA du portail n'est pas configurée (Génération IA).");
    }
    $payload = [
        'contents' => [['parts' => [
            ['text' => $prompt],
            ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
        ]]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.2, 'thinkingConfig' => ['thinkingBudget' => 0]],
    ];
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . GAIZ_MODEL . ':generateContent?key=' . $key);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 32, CURLOPT_CONNECTTIMEOUT => 6]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $status >= 400) {
        error_log('gaiz_gemini: HTTP ' . $status . ' ' . substr((string) $body, 0, 300));
        $relayed = gaiz_relay($prompt, $jpeg);
        if ($relayed !== null) return $relayed;
        throw new RuntimeException(match (true) {
            $status === 402 || ($body !== false && str_contains((string) $body, 'prepayment')) => 'Crédits IA épuisés : rechargez la clé Gemini du portail.',
            $status === 429 => "Le service d'IA est saturé, réessayez dans un instant.",
            $status === 403 || ($body !== false && (str_contains((string) $body, 'API_KEY_INVALID') || str_contains((string) $body, 'API key not valid'))) => 'La clé Gemini du portail est refusée.',
            $status === 400 => "La requête d'analyse d'image a été refusée, réessayez.",
            default => "Le service d'IA n'a pas répondu, réessayez.",
        });
    }
    $data = gaiz_json((string) (json_decode($body, true)['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    if ($data === null) {
        $relayed = gaiz_relay($prompt, $jpeg);
        if ($relayed !== null) return $relayed;
        throw new RuntimeException("La réponse de l'IA est inexploitable, réessayez.");
    }
    return $data;
}

/** Applique les propositions de l'IA aux zones (libellé, affectation, service, intérieur) et ajoute les éléments vus. */
function gaiz_apply(array $discovery, array $data, array $pts, array $ai): array
{
    $zones = array_values((array) ($discovery['zones'] ?? []));
    $usages = ['vente', 'circulation', 'service', 'parking', 'exclu'];
    $confs = ['haute', 'moyenne', 'basse'];
    $clean = static fn ($v, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, $max);

    $applied = 0;
    foreach ((array) ($data['zones'] ?? []) as $z) {
        if (!is_array($z) || !isset($z['i']) || !is_numeric($z['i'])) continue;
        $i = (int) $z['i'];
        if (!isset($zones[$i])) continue;
        $usage = in_array((string) ($z['usage'] ?? ''), $usages, true) ? (string) $z['usage'] : ($zones[$i]['usage'] ?: 'vente');
        $label = $clean($z['label'] ?? '', 60);
        $zones[$i]['usage'] = $usage;
        $zones[$i]['service'] = $usage === 'service' ? $clean($z['service'] ?? '', 40) : '';
        $zones[$i]['indoor'] = array_key_exists('indoor', $z) ? (bool) $z['indoor'] : (bool) $zones[$i]['indoor'];
        if ($label !== '') $zones[$i]['label'] = $label;
        $zones[$i]['ai'] = true;
        $zones[$i]['ai_confidence'] = in_array((string) ($z['confidence'] ?? ''), $confs, true) ? (string) $z['confidence'] : 'moyenne';
        $zones[$i]['ai_why'] = $clean($z['why'] ?? '', 120);
        $applied++;
    }

    $bbox = gaiz_bbox($pts);
    $extra = 0;
    foreach ((array) ($data['extras'] ?? []) as $x) {
        if ($extra >= GAIZ_MAX_EXTRAS || !is_array($x)) break;
        $box = $x['box'] ?? null;
        if (!is_array($box) || count($box) < 4) continue;
        [$bx, $by, $bw, $bh] = array_map('floatval', array_values($box));
        if ($bw <= 0.005 || $bh <= 0.005 || $bx < 0 || $by < 0 || $bx + $bw > 1.001 || $by + $bh > 1.001) continue;
        $share = $bw * $bh;
        if ($share < 0.0005 || $share > 0.9) continue;
        $x0 = $bbox[0] + ($bbox[2] - $bbox[0]) * $bx;
        $x1 = $bbox[0] + ($bbox[2] - $bbox[0]) * ($bx + $bw);
        $y1 = $bbox[3] - ($bbox[3] - $bbox[1]) * $by;
        $y0 = $bbox[3] - ($bbox[3] - $bbox[1]) * ($by + $bh);
        [$lat0, $lng0] = gaiz_latlng($x0, $y0);
        [$lat1, $lng1] = gaiz_latlng($x1, $y1);
        $cLat = ($lat0 + $lat1) / 2;
        $cLng = ($lng0 + $lng1) / 2;
        if (!gallery_map_contains($pts, $cLat, $cLng)) continue;
        // Un élément déjà couvert par une zone détectée n'est pas redoublé : la zone porte déjà le libellé et l'affectation.
        $covered = false;
        foreach ($zones as $z) if (gallery_map_contains((array) $z['pts'], $cLat, $cLng)) { $covered = true; break; }
        if ($covered) continue;
        $poly = [[$lat0, $lng0], [$lat0, $lng1], [$lat1, $lng1], [$lat1, $lng0], [$lat0, $lng0]];
        $usage = in_array((string) ($x['usage'] ?? ''), $usages, true) ? (string) $x['usage'] : 'vente';
        $label = $clean($x['label'] ?? '', 60);
        if ($label === '') continue;
        $zones[] = ['type' => 'espace', 'usage' => $usage, 'flow' => '', 'indoor' => !empty($x['indoor']),
            'label' => $label, 'ztype' => 'autre', 'area' => round(gallery_map_area_m2($poly), 1), 'pts' => $poly,
            'service' => $usage === 'service' ? $clean($x['service'] ?? '', 40) : '', 'source' => 'IA · orthophoto',
            'ai' => true, 'extra' => true,
            'ai_confidence' => in_array((string) ($x['confidence'] ?? ''), $confs, true) ? (string) $x['confidence'] : 'moyenne',
            'ai_why' => $clean($x['why'] ?? '', 120)];
        $extra++;
    }

    $summary = $clean($data['site']['summary'] ?? '', 160);
    $discovery['zones'] = $zones;
    $discovery['ai'] = array_merge($ai, ['ok' => true, 'applied' => $applied, 'extras' => $extra, 'notes' => $summary]);
    return $discovery;
}

/**
 * Enrichit le résultat de gallery_discover_site() par une lecture de l'orthophoto (Gemini).
 * En cas d'échec, renvoie la découverte inchangée avec un bloc « ai » en erreur : l'assistant reste utilisable.
 */
function gallery_ai_zoning_assist(array $territory, array $discovery): array
{
    $ai = ['ok' => false, 'model' => GAIZ_MODEL, 'cached' => false, 'applied' => 0, 'extras' => 0, 'notes' => ''];
    $pts = [];
    foreach ($territory as $p) {
        if (is_array($p) && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1])) $pts[] = [(float) $p[0], (float) $p[1]];
    }
    if (empty($discovery['ok']) || count($pts) < 3) {
        $discovery['ai'] = $ai + ['error' => 'Territoire invalide pour l\'analyse IA.'];
        return $discovery;
    }
    $zones = array_values((array) ($discovery['zones'] ?? []));
    $order = array_keys($zones);
    usort($order, static fn ($a, $b) => ((float) $zones[$b]['area']) <=> ((float) $zones[$a]['area']));
    $idx = array_slice($order, 0, GAIZ_MAX_ZONES);
    sort($idx);
    $sig = [];
    foreach ($idx as $i) {
        $z = $zones[$i];
        $sig[] = [$i, $z['type'], round((float) $z['area']), $z['label'], $z['usage'], !empty($z['indoor'])];
    }
    $key = md5(json_encode([GAIZ_VERSION, GAIZ_MODEL, array_map(static fn ($p) => [round($p[0], 5), round($p[1], 5)], $pts), $sig]));
    $dir = dirname(__DIR__) . '/data/galeries/cache';
    $file = $dir . '/ai-zoning-' . $key . '.json';
    if (is_file($file) && (time() - (int) filemtime($file)) < GAIZ_CACHE_TTL) {
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached)) return gaiz_apply($discovery, $cached, $pts, array_merge($ai, ['cached' => true]));
    }
    try {
        $photo = gaiz_orthophoto($pts);
        if ($photo === null) throw new RuntimeException("L'orthophoto IGN est indisponible pour ce territoire.");
        $data = gaiz_gemini(gaiz_prompt($zones, $idx), $photo[0]);
        if (!is_dir($dir) && is_writable(dirname($dir))) @mkdir($dir, 0755, true);
        if (is_dir($dir) && is_writable($dir)) {
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $file);
            else @unlink($tmp);
        }
        return gaiz_apply($discovery, $data, $pts, $ai);
    } catch (Throwable $e) {
        $discovery['ai'] = $ai + ['error' => $e->getMessage()];
        return $discovery;
    }
}
