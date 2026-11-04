<?php

// Fond de plan stylisé par l'IA : la vue courante de l'éditeur de plan est capturée auprès du WMS de la
// Géoplateforme IGN (sans clé), redessinée par Gemini image, puis enregistrée comme fond importé
// (uploads/galeries/<slug>-fond-*.jpg). L'image est calée sur les mêmes coordonnées que la capture :
// le dessin (repères, zones, tracés) reste superposé au bon endroit. Appelé par portail/galerie-plan.php
// (action « stylize »). Un appel = une image générée (crédit IA du portail).

require_once __DIR__ . '/banner-ai-usage.php';
require_once __DIR__ . '/omniroute.php';

const GAIM_MODEL = 'gemini-2.5-flash-image';
const GAIM_MIN_SPAN_M = 12.0;         // en deçà, la vue est trop zoomée pour être redessinée utilement
const GAIM_MAX_SPAN_M = 4000.0;       // au-delà, le fond n'est plus lisible derrière les emplacements
const GAIM_MAX_BYTES = 12 * 1024 * 1024;

/** Styles proposés dans l'éditeur : clé => libellé et consigne de rendu (en anglais, plus stable pour le modèle). */
function gallery_ai_map_styles(): array
{
    return [
        'aquarelle' => [
            'label' => 'Aquarelle douce',
            'prompt' => 'a soft hand-painted watercolour site plan: delicate washes of terracotta, sage green, ochre and warm grey on cream paper, '
                . 'fine ink outlines, gentle paper grain, no harsh contrast',
        ],
        'croquis' => [
            'label' => 'Croquis d\'architecte',
            'prompt' => 'a precise architectural ink sketch on warm white paper: fine dark-brown linework, light cross-hatching, '
                . 'subtle grey washes, a single warm accent colour, clean and technical',
        ],
        'retro' => [
            'label' => 'Affiche rétro',
            'prompt' => 'a vintage French fair poster map: flat simplified shapes, cream paper, terracotta, mustard and deep teal, '
                . 'slight screen-print grain, bold but calm composition, 1930s travel-poster mood',
        ],
        'bd' => [
            'label' => 'BD franco-belge',
            'prompt' => 'a Franco-Belgian comic-book map illustration: clean confident ink outlines, flat cheerful colours, '
                . 'soft halftone texture, warm and inviting, readable at small size',
        ],
    ];
}

/** Clé Gemini du portail (même clé que le reste du portail et que l'assistant de zonage). */
function gallery_ai_map_key(): string
{
    if (function_exists('portail_gemini_key')) {
        $key = portail_gemini_key();
        if ($key !== '') return $key;
    }
    $env = trim((string) getenv('GEMINI_API_KEY'));
    if ($env !== '') return $env;
    $file = dirname(__DIR__) . '/.secrets/gemini.key';
    return is_file($file) ? trim((string) file_get_contents($file)) : '';
}

function gallery_ai_map_available(): bool
{
    return gallery_ai_map_key() !== '' || omniroute_image_ready();
}

function gaim_mercator(float $lat, float $lng): array
{
    $x = $lng * 20037508.34 / 180.0;
    $y = log(tan((90.0 + $lat) * M_PI / 360.0)) * 20037508.34 / M_PI;
    return [$x, $y];
}

function gaim_latlng(float $x, float $y): array
{
    $lng = $x * 180.0 / 20037508.34;
    $lat = 360.0 / M_PI * atan(exp($y * M_PI / 20037508.34)) - 90.0;
    return [$lat, $lng];
}

/** Formats d'image acceptés par le modèle, du plus courant au plus allongé : libellé => [largeur, hauteur] de la capture. */
function gaim_ratios(): array
{
    return ['1:1' => [1024, 1024], '4:3' => [1024, 768], '3:4' => [768, 1024],
        '3:2' => [1024, 683], '2:3' => [683, 1024], '16:9' => [1024, 576], '9:16' => [576, 1024]];
}

/**
 * Emprise Web Mercator de la vue, élargie au format d'image le plus proche pour que la capture garde
 * les proportions de l'écran sans rogner. -> ['bbox' => [x0, y0, x1, y1], 'ratio', 'width', 'height',
 * 'bounds' => [[sud, ouest], [nord, est]]]
 */
function gaim_view_box(array $view): array
{
    $num = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;
    $south = $num($view['south'] ?? null);
    $west = $num($view['west'] ?? null);
    $north = $num($view['north'] ?? null);
    $east = $num($view['east'] ?? null);
    if ($south === null || $west === null || $north === null || $east === null
        || $south < -85 || $north > 85 || $west < -180 || $east > 180
        || $south >= $north || $west >= $east) {
        throw new InvalidArgumentException('Vue de carte invalide : recentrez le plan puis réessayez.');
    }
    [$x0, $y0] = gaim_mercator($south, $west);
    [$x1, $y1] = gaim_mercator($north, $east);
    $width = $x1 - $x0;
    $height = $y1 - $y0;
    $span = max($width, $height);
    if ($span < GAIM_MIN_SPAN_M) throw new InvalidArgumentException('La vue est trop rapprochée : dézoomez un peu pour capturer le site.');
    if ($span > GAIM_MAX_SPAN_M) throw new InvalidArgumentException('La vue est trop large (plus de ' . (int) (GAIM_MAX_SPAN_M / 1000) . ' km) : zoomez sur le site à styliser.');

    $ratio = $width / $height;
    $best = null;
    $bestDiff = INF;
    foreach (gaim_ratios() as $label => [$pw, $ph]) {
        $diff = abs(log($ratio / ($pw / $ph)));
        if ($diff < $bestDiff) { $bestDiff = $diff; $best = $label; }
    }
    [$pw, $ph] = gaim_ratios()[$best];
    $target = $pw / $ph;
    $cx = ($x0 + $x1) / 2;
    $cy = ($y0 + $y1) / 2;
    if ($ratio > $target) $height = $width / $target;
    else $width = $height * $target;
    if (max($width, $height) > GAIM_MAX_SPAN_M) {
        throw new InvalidArgumentException('La vue est trop large (plus de ' . (int) (GAIM_MAX_SPAN_M / 1000) . ' km) : zoomez sur le site à styliser.');
    }
    $x0 = $cx - $width / 2;
    $x1 = $cx + $width / 2;
    $y0 = $cy - $height / 2;
    $y1 = $cy + $height / 2;
    [$south] = gaim_latlng(0, $y0);
    [$north] = gaim_latlng(0, $y1);
    [, $west] = gaim_latlng($x0, 0);
    [, $east] = gaim_latlng($x1, 0);
    return [
        'bbox' => [$x0, $y0, $x1, $y1], 'ratio' => $best, 'width' => $pw, 'height' => $ph,
        'bounds' => [[round($south, 7), round($west, 7)], [round($north, 7), round($east, 7)]],
    ];
}

/** Capture JPEG de la vue auprès du WMS de la Géoplateforme (plan ou orthophoto IGN, sans clé). */
function gaim_source(array $bbox, string $base, int $width, int $height): string
{
    $sat = $base === 'sat';
    $url = 'https://data.geopf.fr/wms-r/wms?' . http_build_query([
        'SERVICE' => 'WMS', 'VERSION' => '1.3.0', 'REQUEST' => 'GetMap',
        'LAYERS' => $sat ? 'ORTHOIMAGERY.ORTHOPHOTOS' : 'GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2',
        'STYLES' => $sat ? '' : 'normal', 'FORMAT' => 'image/jpeg',
        'CRS' => 'EPSG:3857', 'BBOX' => implode(',', array_map(static fn ($v) => round($v, 3), $bbox)),
        'WIDTH' => $width, 'HEIGHT' => $height,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'Brocs-galerie/1.0 (+https://brocs.arrimage.com)', CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if ($body === false || $code !== 200 || !str_starts_with($type, 'image/')) {
        throw new RuntimeException("Le fond IGN n'est pas disponible pour cette vue : déplacez ou zoomez le plan, puis réessayez.");
    }
    return (string) $body;
}

/** Consigne de stylisation : géométrie, orientation et cadrage intacts, aucun texte ni élément inventé. */
function gaim_prompt(string $style, string $base): string
{
    $styles = gallery_ai_map_styles();
    $look = $styles[$style]['prompt'] ?? $styles['aquarelle']['prompt'];
    $source = $base === 'sat'
        ? "The attached image is an aerial photograph of the site. Interpret buildings, roads, paths, parking areas, trees and open ground as a drawn map."
        : "The attached image is a topographic street map of the site. Turn it into a drawn map.";
    return "Redraw this map view as {$look}.\n\n"
        . $source . "\n\n"
        . "Keep the exact same geographic layout, orientation (north stays up), framing and scale: roads, paths, building footprints, "
        . "squares, water and green areas must stay in the same positions and proportions. Strictly top-down orthographic view, "
        . "no perspective and no 3D isometric effect. Preserve the full frame edge to edge: do not crop, zoom, rotate or shift the view. "
        . "Do not add, remove or displace streets, buildings, landmarks or vehicles. Remove all text, labels, letters, numbers, icons, "
        . "map symbols, legends and watermarks. No border. The result is used as a clean background behind vector markers, "
        . "so keep the whole image evenly readable and free of dark empty areas.";
}

/** Relais par la passerelle locale : même capture, même consigne. Null si aucune route image n'y est réglée. */
function gaim_relay(string $jpeg, string $prompt, string $ratio): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'gaim-');
    if ($tmp === false) return null;
    try {
        file_put_contents($tmp, $jpeg);
        return omniroute_image($tmp, $prompt, $ratio, 120);
    } finally {
        @unlink($tmp);
    }
}

/** Appel Gemini image-to-image ; rend les octets de l'image générée. Lève RuntimeException avec un message affichable. */
function gaim_generate(string $jpeg, string $prompt, string $ratio): string
{
    $key = gallery_ai_map_key();
    if ($key === '') {
        $relayed = gaim_relay($jpeg, $prompt, $ratio);
        if ($relayed !== null) return $relayed;
        throw new RuntimeException("La clé IA du portail n'est pas configurée (Fournisseurs IA).");
    }
    $payload = [
        'contents' => [['parts' => [
            ['text' => $prompt],
            ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
        ]]],
        'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE'], 'imageConfig' => ['aspectRatio' => $ratio]],
    ];
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . GAIM_MODEL . ':generateContent?key=' . $key);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 55, CURLOPT_CONNECTTIMEOUT => 8]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $status >= 400) {
        $err = is_string($body) ? json_decode($body, true) : null;
        $apiMsg = (string) ($err['error']['message'] ?? '');
        $blocked = (string) ($err['promptFeedback']['blockReason'] ?? $err['candidates'][0]['finishReason'] ?? '');
        error_log('gaim_generate: HTTP ' . $status . ' ' . substr((string) $body, 0, 300));
        $relayed = gaim_relay($jpeg, $prompt, $ratio);
        if ($relayed !== null) return $relayed;
        throw new RuntimeException(match (true) {
            $status === 402 || stripos($apiMsg, 'prepayment') !== false => "Crédits IA épuisés : rechargez la clé Gemini du portail. Rien n'a été débité.",
            $status === 429 => "Le service d'IA est saturé, réessayez dans un instant. Rien n'a été débité.",
            in_array($status, [400, 401, 403], true) => "La clé Gemini du portail est refusée" . ($apiMsg !== '' ? ' (' . mb_substr($apiMsg, 0, 100) . ')' : '') . '.',
            $blocked === 'SAFETY' => "La retouche a été bloquée par les filtres de sécurité de l'IA. Choisissez un autre style.",
            default => "L'IA n'a pas renvoyé d'image" . ($apiMsg !== '' ? ' (' . mb_substr($apiMsg, 0, 120) . ')' : '') . ' : réessayez.',
        });
    }
    $data = json_decode((string) $body, true);
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        $raw = base64_decode((string) ($part['inlineData']['data'] ?? ''), true);
        if ($raw !== false && $raw !== '' && strlen($raw) <= GAIM_MAX_BYTES && @getimagesizefromstring($raw)) return $raw;
    }
    $relayed = gaim_relay($jpeg, $prompt, $ratio);
    if ($relayed !== null) return $relayed;
    throw new RuntimeException("L'IA n'a pas renvoyé d'image exploitable : réessayez.");
}

/** Enregistre le fond stylisé en JPEG sous uploads/galeries/ (nom accepté par le plan : <slug>-fond-*). */
function gaim_save(string $bytes, string $slug): string
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $slug)) throw new InvalidArgumentException('Galerie invalide.');
    $src = @imagecreatefromstring($bytes);
    if (!$src) throw new RuntimeException("L'image renvoyée par l'IA est illisible.");
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, 2000 / max(1, max($w, $h)));
    $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    $dir = dirname(__DIR__) . '/uploads/galeries';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Le dossier uploads/galeries/ n'est pas inscriptible.");
    }
    $name = $slug . '-fond-' . bin2hex(random_bytes(4)) . '.jpg';
    $ok = imagejpeg($dst, $dir . '/' . $name, 88);
    if (!$ok) throw new RuntimeException("Impossible d'enregistrer le fond stylisé.");
    return 'uploads/galeries/' . $name;
}

/**
 * Capture la vue, la fait styliser et enregistre le fond. -> ['ok'=>true, 'path', 'bounds', 'ratio', 'style'].
 * Lève InvalidArgumentException / RuntimeException avec un message affichable.
 */
function gallery_ai_map_stylize(array $view, string $base, string $style, string $slug): array
{
    if (!gallery_ai_map_available()) throw new RuntimeException("La clé IA du portail n'est pas configurée (Fournisseurs IA).");
    if (!in_array($base, ['osm', 'sat'], true)) $base = 'osm';
    if (!isset(gallery_ai_map_styles()[$style])) $style = 'aquarelle';
    $box = gaim_view_box($view);
    $source = gaim_source($box['bbox'], $base, $box['width'], $box['height']);
    $bytes = gaim_generate($source, gaim_prompt($style, $base), $box['ratio']);
    $path = gaim_save($bytes, $slug);
    banner_ai_usage_log('galerie-plan', $slug, 1, GAIM_MODEL);
    return ['ok' => true, 'path' => $path, 'bounds' => $box['bounds'], 'ratio' => $box['ratio'], 'style' => $style];
}
