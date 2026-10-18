<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — Palettes des visuels générées par l'IA
 *
 * POST /api/palettes/generate   (admin, editor)
 *   { base: { color_pink: '#FF006E', … } couleurs du site, consigne: '…' facultative }
 *   → { palettes: [10 × { nom, ambiance, couleurs: { fond, titre, texte, accent, accent2, discret },
 *                         nuances: [5 × { nom, couleurs }] }], modele }
 *
 * L'IA compose les 10 palettes ; les 5 nuances de chacune sont calculées ici (plus claire, plus sombre, douce, vive, inversée) :
 * réponse 6 fois plus courte, donc rapide (pas de dépassement du délai du serveur), et nuances fidèles à leur palette.
 * Les palettes s'accordent aux couleurs du site ; l'éditeur visuel les propose (section « Palettes ») pour faire varier
 * l'ambiance des visuels. Enregistrées par la page Apparence dans le réglage editor_palettes.
 * IA : Claude si la clé claude_api_key existe (repli sur Gemini s'il échoue), sinon Gemini (gemini_api_key).
 */
require_once __DIR__ . '/../compat.php';

if (getMethod() !== 'POST' || rtrim(getPath(), '/') !== '/palettes/generate') {
    errorResponse('Route non trouvée [' . getMethod() . ' ' . getPath() . ']', 404);
}
requireAuth(['admin', 'editor']);

const PAL_ROLES = ['fond', 'titre', 'texte', 'accent', 'accent2', 'discret'];

$in       = getJsonInput();
$base     = array_filter((array)($in['base'] ?? []), fn($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v));
$consigne = mb_substr(trim((string)($in['consigne'] ?? '')), 0, 500);
if (!$base) errorResponse('Couleurs de base manquantes', 400);

$systeme = "Tu es directeur artistique pour " . palettesIdentite() . ". Tu crées des palettes de couleurs pour des visuels "
    . "(affiches, publications sur les réseaux sociaux, vidéos courtes) qui doivent rester reconnaissables comme ceux du site : "
    . "chaque palette s'accorde aux couleurs du site fournies (mêmes familles, contrastes et énergie), tout en proposant une "
    . "ambiance différente des autres.\n\n"
    . "Rôles des couleurs d'une palette (#RRGGBB) : fond (arrière-plan du visuel), titre (grand titre, très lisible sur le fond), "
    . "texte (textes courants, lisibles sur le fond), accent (logo, cadre), accent2 (date, barre d'accent), discret (petites "
    . "mentions, plus effacées mais lisibles).\n"
    . "Règles : contraste suffisant entre fond et titre / texte (lisibilité sur téléphone) ; 10 palettes nettement différentes "
    . "(sombres, claires, saturées, douces, chaudes, froides…) ; nom court en français (1 à 3 mots) ; ambiance = une phrase.";
$texte = "Couleurs du site :\n";
foreach ($base as $k => $v) $texte .= "- $k : $v\n";
if ($consigne !== '') $texte .= "\nConsigne : $consigne\n";
$texte .= "\nPropose exactement 10 palettes.";

$couleurs = ['type' => 'object', 'additionalProperties' => false, 'required' => PAL_ROLES,
    'properties' => array_fill_keys(PAL_ROLES, ['type' => 'string'])];
$schema = [
    'type' => 'object', 'additionalProperties' => false, 'required' => ['palettes'],
    'properties' => ['palettes' => ['type' => 'array', 'items' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['nom', 'ambiance', 'couleurs'],
        'properties' => ['nom' => ['type' => 'string'], 'ambiance' => ['type' => 'string'], 'couleurs' => $couleurs],
    ]]],
];

set_time_limit(120);
[$json, $modele] = palettesAppelIA($systeme, $texte, $schema);

// Contrôle : couleurs #RRGGBB, 10 palettes et 5 nuances au plus, noms courts
$hex = fn($v, $d) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? strtoupper((string)$v) : $d;
$jeu = function ($c) use ($hex) {
    $out = [];
    foreach (PAL_ROLES as $i => $r) $out[$r] = $hex(is_array($c) ? ($c[$r] ?? '') : '', ['#000000', '#FFFFFF', '#F0F0F0', '#FF006E', '#FFFF00', '#888888'][$i]);
    return $out;
};
$palettes = [];
foreach (array_slice((array)($json['palettes'] ?? []), 0, 10) as $i => $p) {
    if (!is_array($p)) continue;
    $c = $jeu($p['couleurs'] ?? []);
    $palettes[] = [
        'nom'      => mb_substr(trim((string)($p['nom'] ?? '')), 0, 40) ?: 'Palette ' . ($i + 1),
        'ambiance' => mb_substr(trim((string)($p['ambiance'] ?? '')), 0, 200),
        'couleurs' => $c,
        'nuances'  => palettesNuances($c),
    ];
}
if (!$palettes) errorResponse("L'IA n'a proposé aucune palette exploitable, réessayez.", 502);
jsonResponse(['palettes' => $palettes, 'modele' => $modele]);

/** Les 5 nuances d'une palette : plus claire, plus sombre, douce, vive, inversée (fond et titre échangés). */
function palettesNuances(array $c): array {
    $t = fn(callable $f) => array_map(fn($hex) => palettesHsl($hex, $f), $c);
    $inv = $c;
    [$inv['fond'], $inv['titre'], $inv['texte']] = [$c['titre'], $c['fond'], $c['fond']];
    return [
        ['nom' => 'Plus claire', 'couleurs' => $t(fn($h, $s, $l) => [$h, $s, min(.97, $l + .12)])],
        ['nom' => 'Plus sombre', 'couleurs' => $t(fn($h, $s, $l) => [$h, $s, max(.03, $l - .12)])],
        ['nom' => 'Douce',       'couleurs' => $t(fn($h, $s, $l) => [$h, $s * .55, $l])],
        ['nom' => 'Vive',        'couleurs' => $t(fn($h, $s, $l) => [$h, min(1, $s * 1.35 + .05), $l])],
        ['nom' => 'Inversée',    'couleurs' => $inv],
    ];
}

/** Transforme une couleur #RRGGBB dans l'espace teinte / saturation / luminosité ($f(h, s, l) → [h, s, l]). */
function palettesHsl(string $hex, callable $f): string {
    [$r, $g, $b] = array_map(fn($x) => hexdec($x) / 255, str_split(substr($hex, 1), 2));
    $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2; $d = $max - $min;
    $h = 0; $s = 0;
    if ($d > 0) {
        $s = $d / (1 - abs(2 * $l - 1));
        $h = $max === $r ? fmod(($g - $b) / $d, 6) : ($max === $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4);
        $h = fmod($h * 60 + 360, 360);
    }
    [$h, $s, $l] = $f($h, max(0, min(1, $s)), $l);
    $c2 = (1 - abs(2 * $l - 1)) * $s; $x = $c2 * (1 - abs(fmod($h / 60, 2) - 1)); $m = $l - $c2 / 2;
    [$r, $g, $b] = $h < 60 ? [$c2, $x, 0] : ($h < 120 ? [$x, $c2, 0] : ($h < 180 ? [0, $c2, $x] : ($h < 240 ? [0, $x, $c2] : ($h < 300 ? [$x, 0, $c2] : [$c2, 0, $x]))));
    return sprintf('#%02X%02X%02X', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
}

/** Présentation du site pour le prompt. */
function _eb80_orig_palettesIdentite(): string {
    $nom = getSetting('artist_name') ?: 'Electroboy 80';
    return "« $nom », projet musical new wave";
}

/**
 * Appel de l'IA avec une réponse JSON conforme au schéma : Claude si sa clé existe, et **repli sur Gemini** si Claude échoue
 * (clé invalide, quota, réseau, réponse illisible) et qu'une clé Gemini existe ; sinon Gemini directement.
 * Retour [réponse décodée, modèle] ; arrête la requête avec un message clair si aucune IA n'a répondu.
 */
function _eb80_orig_palettesAppelIA(string $systeme, string $texte, array $schema): array {
    $claude = getSetting('claude_api_key') ?: getSetting('anthropic_api_key');
    $gemini = getSetting('gemini_api_key');
    if (!$claude && !$gemini) errorResponse('Aucune clé IA : API & Réseaux → Clés IA (Claude ou Gemini)', 400);
    $erreurs = [];
    if ($claude) {
        [$json, $modele, $err] = palettesClaude($claude, $systeme, $texte, $schema);
        if ($json) return [$json, $modele];
        $erreurs[] = 'Claude : ' . $err;
    }
    if ($gemini) {
        [$json, $modele, $err] = palettesGemini($gemini, $systeme, $texte, $schema);
        if ($json) return [$json, $modele . ($erreurs ? ' (repli : ' . $erreurs[0] . ')' : '')];
        $erreurs[] = 'Gemini : ' . $err;
    }
    errorResponse(implode(' · ', $erreurs), 502);
}

/** Claude avec sortie JSON structurée : [réponse, modèle, ''] ou [null, '', message d'erreur]. */
function palettesClaude(string $cle, string $systeme, string $texte, array $schema): array {
    $modele = 'claude-sonnet-5-5';
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 100,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $modele, 'max_tokens' => 4000, 'system' => $systeme,
            'messages' => [['role' => 'user', 'content' => $texte]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $cle, 'anthropic-version: 2023-06-01'],
    ]);
    $brut = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($err) return [null, '', 'erreur réseau (' . $err . ')'];
    $rep = json_decode((string)$brut, true) ?: [];
    if ($code >= 400 || isset($rep['error'])) {
        $msg = $rep['error']['message'] ?? "HTTP $code";
        if ($code === 401 || stripos($msg, 'api-key') !== false || stripos($msg, 'api key') !== false) $msg = 'clé invalide (API & Réseaux → Clés IA)';
        return [null, '', $msg];
    }
    foreach ($rep['content'] ?? [] as $bloc) {
        if (($bloc['type'] ?? '') === 'text') { $json = json_decode($bloc['text'], true); if (is_array($json)) return [$json, $rep['model'] ?? $modele, '']; }
    }
    return [null, '', 'réponse illisible'];
}

/** Gemini avec réponse JSON selon le schéma : [réponse, modèle, ''] ou [null, '', message d'erreur]. */
function palettesGemini(string $cle, string $systeme, string $texte, array $schema): array {
    $modele = 'gemini-2.5-flash';
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $modele . ':generateContent?key=' . $cle);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 100, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'systemInstruction' => ['parts' => [['text' => $systeme]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $texte]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => palettesSchemaGemini($schema), 'maxOutputTokens' => 8000],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $brut = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($err) return [null, '', 'erreur réseau (' . $err . ')'];
    $rep = json_decode((string)$brut, true) ?: [];
    if ($code >= 400 || isset($rep['error'])) {
        $msg = $rep['error']['message'] ?? "HTTP $code";
        if (stripos($msg, 'API key not valid') !== false) $msg = 'clé invalide (API & Réseaux → Clés IA)';
        return [null, '', $msg];
    }
    $txt = '';
    foreach ((array)($rep['candidates'][0]['content']['parts'] ?? []) as $part) $txt .= (string)($part['text'] ?? '');
    $json = json_decode($txt, true);
    return is_array($json) ? [$json, $modele, ''] : [null, '', 'réponse illisible'];
}

/** Schéma JSON → schéma de réponse de Gemini (types en capitales, sans additionalProperties). */
function palettesSchemaGemini(array $s): array {
    $out = [];
    foreach ($s as $k => $v) {
        if ($k === 'additionalProperties') continue;
        if ($k === 'type' && is_string($v)) { $out[$k] = strtoupper($v); continue; }
        if ($k === 'properties' && is_array($v)) { $out[$k] = array_map('palettesSchemaGemini', $v); continue; }
        $out[$k] = ($k === 'items' && is_array($v)) ? palettesSchemaGemini($v) : $v;
    }
    return $out;
}
