<?php

// Pré-remplissage optionnel d'une nouvelle boutique : un bandeau et quelques articles d'exemple générés par l'IA selon l'activité.
//
// Bibliothèque partagée : assets/autofeed/<activité>/manifest.json + images. La première boutique d'une activité les génère (frais de
// la plateforme) ; les suivantes réutilisent la même bibliothèque sans aucun appel IA. Chaque boutique reçoit sa propre COPIE dans
// uploads/ : la supprimer ou la remplacer ne touche jamais la bibliothèque.
//
// Régénération depuis l'administration (autofeed_regenerate) : une image à la demande, décomptée du crédit IA de la boutique.

require_once __DIR__ . '/ai-usage.php';
require_once __DIR__ . '/shared-secrets.php';

const AUTOFEED_PRODUCTS = 3;

function autofeed_dir(): string
{
    return dirname(__DIR__) . '/assets/autofeed';
}

function autofeed_key(array $activity): string
{
    $primary = (string) ($activity['primary'] ?? '');
    if ($primary === 'other') {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) ($activity['other'] ?? ''))));
        $slug = trim(substr($slug, 0, 30), '-');
        return $slug !== '' ? 'autre-' . $slug : '';
    }
    return preg_match('/^[a-z0-9_-]{1,30}$/', $primary) ? $primary : '';
}

function autofeed_api_key(): string
{
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') return (string) GEMINI_API_KEY;
    $key = trim((string) getenv('GEMINI_API_KEY'));
    if ($key !== '') return $key;
    $file = secret_path('gemini.key');
    return is_file($file) ? trim((string) file_get_contents($file)) : '';
}

function autofeed_endpoint(string $model): string
{
    return 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode(autofeed_api_key());
}

function autofeed_curl(string $model, array $payload)
{
    $ch = curl_init(autofeed_endpoint($model));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    return $ch;
}

function autofeed_image_payload(string $prompt, string $ratio): array
{
    return [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE'], 'imageConfig' => ['aspectRatio' => $ratio]],
    ];
}

/** @return array{0: ?string, 1: array} octets de l'image (null si échec) et métadonnées d'usage */
function autofeed_extract_image(?string $body): array
{
    $data = is_string($body) ? json_decode($body, true) : null;
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        $raw = base64_decode((string) ($part['inlineData']['data'] ?? ''), true);
        if ($raw !== false && $raw !== '' && @getimagesizefromstring($raw)) return [$raw, (array) ($data['usageMetadata'] ?? [])];
    }
    return [null, []];
}

/** Génère plusieurs images en parallèle : [id => ['prompt' => …, 'ratio' => '16:9']] → [id => octets|null]. */
function autofeed_generate_images(array $jobs): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($jobs as $id => $job) {
        $handles[$id] = autofeed_curl('gemini-2.5-flash-image', autofeed_image_payload($job['prompt'], $job['ratio']));
        curl_multi_add_handle($multi, $handles[$id]);
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1.0);
    } while ($running && $status === CURLM_OK);
    $out = [];
    $depleted = false;
    foreach ($handles as $id => $ch) {
        $body = (string) curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 402 || str_contains($body, 'prepayment')) $depleted = true;
        $out[$id] = autofeed_extract_image($body)[0];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    if ($depleted && function_exists('ai_trigger_depleted_alert')) {
        ai_trigger_depleted_alert('gemini');
    }
    // Les appels en parallèle peuvent être limités par l'API : une nouvelle tentative, une par une, pour ceux qui ont échoué.
    if (!$depleted) {
        foreach ($out as $id => $bytes) {
            if ($bytes !== null) continue;
            $ch = autofeed_curl('gemini-2.5-flash-image', autofeed_image_payload($jobs[$id]['prompt'], $jobs[$id]['ratio']));
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 402 || str_contains($body, 'prepayment')) {
                if (function_exists('ai_trigger_depleted_alert')) ai_trigger_depleted_alert('gemini');
                curl_close($ch);
                break;
            }
            $out[$id] = autofeed_extract_image($body)[0];
            curl_close($ch);
        }
    }
    return $out;
}

/** Retire les bandes unies (blanches ou beiges) que le modèle ajoute parfois en haut et en bas pour atteindre le format demandé. */
function autofeed_trim_bars(GdImage $img): GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    $ref = imagecolorat($img, (int) ($w / 2), 0);
    $uniform = static function (int $y) use ($img, $w, $ref): bool {
        for ($x = 0; $x < $w; $x += max(1, intdiv($w, 24))) {
            $c = imagecolorat($img, $x, $y);
            foreach ([16, 8, 0] as $shift) {
                if (abs((($c >> $shift) & 255) - (($ref >> $shift) & 255)) > 10) return false;
            }
        }
        return true;
    };
    $top = 0;
    while ($top < $h / 4 && $uniform($top)) $top++;
    $ref = imagecolorat($img, (int) ($w / 2), $h - 1);
    $bottom = $h;
    while ($bottom > $h * 3 / 4 && $uniform($bottom - 1)) $bottom--;
    if ($top === 0 && $bottom === $h) return $img;
    $top = min($h, $top + 2);
    $bottom = max($top + 1, $bottom - 2);
    return imagecrop($img, ['x' => 0, 'y' => $top, 'width' => $w, 'height' => $bottom - $top]) ?: $img;
}

/** Enregistre des octets d'image en JPEG redimensionné ; retourne le chemin écrit ou null. */
function autofeed_save_jpeg(string $bytes, string $absPath, int $maxDim): bool
{
    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;
    $src = autofeed_trim_bars($src);
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxDim / max($w, $h));
    $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    $ok = imagejpeg($dst, $absPath, 82);
    return $ok;
}

/** Textes des exemples (nom, description, prix, scènes à illustrer) proposés par l'IA pour une activité. */
function autofeed_plan(string $activityLabel, string $about): ?array
{
    $prompt = "Tu prépares une boutique en ligne de démonstration pour ce commerce : « $activityLabel »"
        . ($about !== '' ? ". Précisions : " . mb_substr($about, 0, 300) : '') . ".\n"
        . "Réponds en JSON : {\"hero_prompt\": \"scène en anglais pour une photo de bandeau large, ambiance chaleureuse, sans texte ni logo\","
        . " \"products\": [" . AUTOFEED_PRODUCTS . " articles vendables typiques de cette activité, chacun {\"name\": \"nom court en français\","
        . " \"description\": \"1 à 2 phrases en français\", \"price_eur\": nombre réaliste, \"image_prompt\": \"photo produit en anglais, fond neutre, sans texte ni logo\"}]}";
    $ch = autofeed_curl('gemini-2.5-flash', [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseMimeType' => 'application/json'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string) $body, true);
    $plan = json_decode((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''), true);
    if (!is_array($plan) || empty($plan['hero_prompt']) || empty($plan['products']) || !is_array($plan['products'])) return null;
    return $plan;
}

/**
 * Bibliothèque d'une activité : la lit, ou la génère la première fois.
 * @return ?array{hero: string, products: list<array{name: string, desc: string, price: string, photo: string}>}
 */
function autofeed_library(array $activity, string $about): ?array
{
    $key = autofeed_key($activity);
    if ($key === '') return null;
    $dir = autofeed_dir() . '/' . $key;
    $manifestFile = $dir . '/manifest.json';
    $read = static function () use ($manifestFile): ?array {
        $m = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;
        return is_array($m) && !empty($m['products']) ? $m : null;
    };
    if ($m = $read()) return $m;
    if (autofeed_api_key() === '') return null;

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
    $lock = fopen($dir . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return null;
    try {
        if ($m = $read()) return $m;   // générée pendant l'attente du verrou
        @set_time_limit(240);
        require_once __DIR__ . '/branding-services.php';
        $label = branding_activity_context($activity) ?: (string) ($activity['other'] ?? '');
        $plan = autofeed_plan($label, $about);
        if (!$plan) return null;
        $products = array_slice(array_values(array_filter($plan['products'], 'is_array')), 0, AUTOFEED_PRODUCTS);
        $style = ' Photorealistic, natural light, no text, no watermark, no logo.';
        $jobs = ['hero' => ['prompt' => 'Wide website banner photo: ' . $plan['hero_prompt'] . $style, 'ratio' => '16:9']];
        foreach ($products as $i => $p) {
            $jobs["p$i"] = ['prompt' => 'Product photo for an online shop: ' . (string) ($p['image_prompt'] ?? $p['name'] ?? '') . $style, 'ratio' => '1:1'];
        }
        $images = autofeed_generate_images($jobs);
        $manifest = ['hero' => '', 'products' => [], 'label' => $label, 'created' => time()];
        if (!empty($images['hero']) && autofeed_save_jpeg($images['hero'], $dir . '/hero.jpg', 1600)) $manifest['hero'] = "assets/autofeed/$key/hero.jpg";
        foreach ($products as $i => $p) {
            if (empty($images["p$i"]) || !autofeed_save_jpeg($images["p$i"], $dir . "/produit-$i.jpg", 1000)) continue;
            $manifest['products'][] = [
                'name' => mb_substr(trim((string) ($p['name'] ?? '')), 0, 60),
                'desc' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 240),
                'price' => number_format(max(1, (float) ($p['price_eur'] ?? 20)), 0, ',', ' ') . ' €',
                'photo' => "assets/autofeed/$key/produit-$i.jpg",
            ];
        }
        if ($manifest['hero'] === '' && !$manifest['products']) return null;
        // Mémorisée seulement si complète : une génération partielle (quota ou crédit API épuisé) est servie cette fois-ci, puis retentée.
        $complete = $manifest['hero'] !== '' && count($manifest['products']) === count($products);
        if ($complete) file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $manifest;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Copie une image de la bibliothèque dans uploads/ (propre à la boutique) ; retourne son chemin relatif, ou '' en cas d'échec. */
function autofeed_copy_to_uploads(string $libraryPath, string $slug, string $kind): string
{
    $src = dirname(__DIR__) . '/' . $libraryPath;
    if (!preg_match('#^assets/autofeed/[a-z0-9_-]+/[a-z0-9_.-]+\.jpg$#', $libraryPath) || !is_file($src)) return '';
    $uploads = dirname(__DIR__) . '/uploads';
    if (!is_dir($uploads) && !@mkdir($uploads, 0755, true)) return '';
    $name = 'autofeed-' . $kind . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
    return @copy($src, $uploads . '/' . $name) ? 'uploads/' . $name : '';
}

/**
 * Champs à ajouter au formulaire de create_tenant_from_form() : image de bandeau et articles d'exemple.
 * Retourne [] si rien n'a pu être préparé (la boutique est alors créée vide, sans erreur).
 */
function autofeed_form_fields(array $req): array
{
    try {
        $activity = (array) ($req['activity_details'] ?? []);
        $lib = autofeed_library($activity, (string) ($req['about'] ?? ''));
        if (!$lib) return [];
        $slug = (string) $req['slug'];
        $fields = ['hero_photo' => $lib['hero'] !== '' ? autofeed_copy_to_uploads($lib['hero'], $slug, 'hero') : ''];
        $limit = (int) (saas_req_offer($req)['max_items'] ?? 0);
        $products = $limit > 0 ? array_slice($lib['products'], 0, $limit) : $lib['products'];
        foreach ($products as $p) {
            $fields['p_name'][] = $p['name'];
            $fields['p_desc'][] = $p['desc'];
            $fields['p_price'][] = $p['price'];
            $fields['p_badge'][] = 'Exemple';
            $fields['p_cat'][] = 0;
            $fields['p_photo'][] = autofeed_copy_to_uploads($p['photo'], $slug, 'produit');
        }
        return $fields;
    } catch (Throwable $e) {
        error_log('autofeed_form_fields: ' . $e->getMessage());
        return [];
    }
}

/** Coût estimé d'une régénération, en euros (pour l'afficher avant de confirmer). */
function autofeed_regen_cost_eur(): float
{
    return ai_eur(ai_pricing()['image']);
}

/**
 * Régénère une image de la boutique courante (bandeau, diaporama…) : fichier neuf dans uploads/, coût inscrit dans la
 * consommation IA de la boutique. Retourne le chemin relatif. Lève RuntimeException avec un message affichable.
 *
 * @param string $kind  'hero' (16:9) ou 'square' (1:1)
 * @param string $hint  précision facultative du client (« plus lumineux », « avec des fleurs »…)
 */
function autofeed_regenerate(string $kind, string $hint = '', ?string $fullPrompt = null, ?array $opts = null): string
{
    if ($gate = ai_gate()) throw new RuntimeException($gate);
    if (autofeed_api_key() === '') throw new RuntimeException('La génération d’images n’est pas configurée.');

    // Alerte préventive envoyée si le solde/quota de la boutique est bas
    if (function_exists('ai_check_low_balance_alert')) {
        ai_check_low_balance_alert('gemini');
    }

    $ai = (array) tenant('ai', []);
    $content = get_content();
    $subject = trim((string) ($ai['shop'] ?? '')) ?: (string) ($content['site_name'] ?? '');
    $examples = trim((string) ($ai['examples'] ?? ''));
    $scene = $kind === 'hero' ? 'Wide website banner photo' : 'Photo';
    $prompt = $fullPrompt !== null && trim($fullPrompt) !== ''
        ? mb_substr(trim(strip_tags($fullPrompt)), 0, 700) . ($opts['suffix'] ?? ' Photorealistic, no text, no watermark, no logo.')
        : $scene . ' for ' . $subject . ($examples !== '' ? ' (' . $examples . ')' : '')
        . '. Photorealistic, natural light, no text, no watermark, no logo.'
        . ($hint !== '' ? ' Client request: ' . mb_substr(strip_tags($hint), 0, 200) . '.' : ' Vary the composition from a typical stock photo.');
    $ch = autofeed_curl('gemini-2.5-flash-image', autofeed_image_payload($prompt, $opts['ratio'] ?? ($kind === 'hero' ? '16:9' : '1:1')));
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    [$bytes, $usage] = autofeed_extract_image(is_string($body) ? $body : null);
    if ($bytes === null) {
        $err = is_string($body) ? json_decode($body, true) : null;
        $apiMsg = (string) ($err['error']['message'] ?? '');
        $apiCode = (int) ($err['error']['code'] ?? $httpCode);
        $blockReason = (string) ($err['candidates'][0]['finishReason'] ?? $err['promptFeedback']['blockReason'] ?? '');

        // 1. Crédits Google AI Studio épuisés (HTTP 402 ou message explicite)
        if ($apiCode === 402 || stripos($apiMsg, 'prepayment') !== false || stripos($apiMsg, 'depleted') !== false) {
            if (function_exists('ai_trigger_depleted_alert')) {
                ai_trigger_depleted_alert('gemini', $apiMsg);
            }
            $recipient = function_exists('ai_alert_recipient') ? ai_alert_recipient('gemini') : ['is_shared' => true];
            $destMsg = !empty($recipient['is_shared']) ? "L’administrateur du portail a été averti par e-mail." : "Une alerte vous a été envoyée par e-mail.";
            throw new RuntimeException("Crédits Google AI Studio épuisés : veuillez recharger votre compte sur https://ai.studio/projects. $destMsg Rien n’a été débité.");
        }

        // 2. Limite de débit ou quota temporaire (HTTP 429)
        if ($apiCode === 429) {
            throw new RuntimeException("Quota ou débit temporairement dépassé chez Google (erreur 429) : réessayez dans quelques instants. Rien n’a été débité.");
        }

        // 3. Clé invalide ou refusée (HTTP 400 / 401 / 403)
        if (in_array($apiCode, [400, 401, 403], true)) {
            error_log("autofeed_regenerate: erreur d'autorisation HTTP $apiCode - " . substr((string) $body, 0, 300));
            throw new RuntimeException("La clé d'API Google Gemini est refusée (" . ($apiMsg ? mb_substr($apiMsg, 0, 100) : "code $apiCode") . "). Vérifiez la clé dans les réglages.");
        }

        // 4. Blocage de sécurité
        if ($blockReason === 'SAFETY') {
            throw new RuntimeException("L’image demandée a été bloquée par les filtres de sécurité de l’IA. Ajustez la description pour réessayer.");
        }

        // 5. Erreur générique avec détails
        error_log("autofeed_regenerate: échec HTTP $httpCode - " . substr((string) $body, 0, 400));
        throw new RuntimeException("L’IA n’a pas renvoyé d’image" . ($apiMsg ? ' (' . mb_substr($apiMsg, 0, 120) . ')' : '') . " : rien n’a été débité, réessayez.");
    }

    $uploads = dirname(__DIR__) . '/uploads';
    if (!is_dir($uploads)) @mkdir($uploads, 0755, true);
    $name = 'uploads/' . ($opts['prefix'] ?? 'regen-') . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
    if (!autofeed_save_jpeg($bytes, dirname(__DIR__) . '/' . $name, $opts['max'] ?? ($kind === 'hero' ? 1600 : 1000))) throw new RuntimeException('Impossible d’enregistrer l’image générée.');
    ai_usage_log_image($usage);   // débité seulement quand une image a bien été produite

    // Vérification du solde restant après génération réussie
    if (function_exists('ai_check_low_balance_alert')) {
        ai_check_low_balance_alert('gemini');
    }

    return $name;
}


/** Prompt par défaut d'un bandeau de page, inspiré de l'activité du site (modifiable par le client avant génération). */
function autofeed_banner_prompt(string $pageKey): string
{
    $ai = (array) tenant('ai', []);
    $content = get_content();
    $subject = trim((string) ($ai['shop'] ?? '')) ?: (string) ($content['site_name'] ?? 'une boutique');
    $examples = trim((string) ($ai['examples'] ?? ''));
    $tagline = trim((string) ($content['site_tagline'] ?? ''));
    $scene = [
        'accueil' => "Grand bandeau d'accueil chaleureux et lumineux : une vitrine ou un étal mettant en valeur l'ambiance du commerce",
        'boutique' => "Bandeau de la page boutique : des articles joliment présentés sur une étagère ou une table, vue d'ensemble soignée",
        'histoire' => "Bandeau de la page « notre histoire » : l'atelier ou le lieu du commerçant, matières et gestes de travail, ambiance humaine",
        'contact' => "Bandeau de la page contact : l'entrée ou le comptoir du commerce, accueillant et lumineux",
    ][$pageKey] ?? 'Bandeau de page large et soigné';
    return $scene . ' pour ' . $subject . ($examples !== '' ? ' (' . $examples . ')' : '')
        . ($tagline !== '' ? '. Esprit : ' . $tagline : '')
        . '. Format panoramique, grand espace calme sur le côté pour superposer du texte, lumière naturelle.';
}

/* ---------- médiathèque commune des bandeaux (tous les sites) ---------- */

const BANNER_LIBRARY_MAX = 80;

function banner_library_dir(): string
{
    return dirname(__DIR__) . '/uploads/banner-library';
}

function banner_library_index_file(): string
{
    return dirname(__DIR__) . '/data/banner-library.json';
}

/**
 * Bandeaux partagés, du plus récent au plus ancien. Une image réservée par une
 * boutique n'apparaît plus chez les autres. $all pour relire tout l'index.
 */
function banner_library_list(bool $all = false): array
{
    $file = banner_library_index_file();
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data)) return [];
    return array_values(array_filter($data, static fn ($item): bool => is_array($item)
        && preg_match('/^banner-[a-f0-9]{8}\.jpg$/', (string) ($item['file'] ?? ''))
        && ($all || empty($item['exclusive']) || (string) ($item['exclusive_by'] ?? '') === tenant_slug())
        && is_file(banner_library_dir() . '/' . $item['file'])));
}

/** Chemin absolu d'un bandeau partagé (null si nom invalide ou fichier absent). */
function banner_library_abs(string $file): ?string
{
    if (!preg_match('/^banner-[a-f0-9]{8}\.jpg$/', $file)) return null;
    $abs = banner_library_dir() . '/' . $file;
    return is_file($abs) ? $abs : null;
}

/** Fiche d'un bandeau partagé (null si introuvable). */
function banner_library_find(string $file): ?array
{
    foreach (banner_library_list(true) as $item) {
        if ((string) ($item['file'] ?? '') === $file) return $item;
    }
    return null;
}

/** Réserve une illustration à la boutique courante (crédit IA décompté par l'appelant). */
function banner_library_reserve(string $file): bool
{
    $list = banner_library_list(true);
    $done = false;
    foreach ($list as &$item) {
        if ((string) ($item['file'] ?? '') !== $file) continue;
        if (!empty($item['exclusive'])) break;
        $item['exclusive'] = true;
        $item['exclusive_by'] = tenant_slug();
        $item['exclusive_at'] = gmdate('c');
        $done = true;
        break;
    }
    unset($item);
    if (!$done) return false;
    @file_put_contents(banner_library_index_file(), json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return true;
}

/**
 * Copie un bandeau généré dans la médiathèque commune, même s'il n'est pas
 * retenu pour la page : il pourra resservir sur une autre page ou un autre
 * site. -> nom de fichier partagé.
 */
function banner_library_store(string $absPath, string $prompt, string $pageKey = ''): ?string
{
    if (!is_file($absPath) || @getimagesize($absPath) === false) return null;
    $dir = banner_library_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
    $file = 'banner-' . bin2hex(random_bytes(4)) . '.jpg';
    if (!@copy($absPath, $dir . '/' . $file)) return null;

    $list = banner_library_list(true);
    array_unshift($list, [
        'file' => $file,
        'page' => $pageKey,
        'prompt' => mb_substr($prompt, 0, 500),
        'site' => tenant_slug(),
        'at' => gmdate('c'),
    ]);
    while (count($list) > BANNER_LIBRARY_MAX) {
        $old = array_pop($list);
        if ($old) @unlink($dir . '/' . (string) ($old['file'] ?? ''));
    }
    $indexDir = dirname(__DIR__) . '/data';
    if (!is_dir($indexDir)) @mkdir($indexDir, 0775, true);
    @file_put_contents(banner_library_index_file(), json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $file;
}


/** Direction artistique par défaut d'un logo, inspirée de l'activité du site ; modifiable par le client. */
function autofeed_logo_direction(): string
{
    $ai = (array) tenant('ai', []);
    $subject = trim((string) ($ai['shop'] ?? '')) ?: 'une boutique';
    return 'Symbole simple et original qui évoque ' . $subject . ', dans l’esprit du commerce.';
}

/**
 * Prompt du symbole (graphique seul, sans texte) : le nom de la boutique est
 * écrit ensuite en typographie, à part, pour que les trois déclinaisons
 * partagent le même symbole et qu'il puisse resservir à une autre boutique.
 */
function autofeed_logo_symbol_prompt(string $description = ''): string
{
    $ai = (array) tenant('ai', []);
    $subject = trim((string) ($ai['shop'] ?? '')) ?: 'une boutique';
    $direction = trim(strip_tags($description));
    return 'Symbole graphique seul (pictogramme) pour une boutique : ' . $subject
        . ($direction !== '' ? '. Direction souhaitée : ' . mb_substr($direction, 0, 400) : '') . '. '
        . 'Icône vectorielle plate, simple, originale et lisible, centrée, remplissant le cadre, fond blanc pur. '
        . 'Aucun texte, aucune lettre, aucun chiffre, aucun mot, pas de logo typographique : le nom de la boutique sera écrit séparément. '
        . logo_palette_prompt() . ' '
        . 'Flat vector icon only, centered and filling the frame; no text, no letters, no numbers, no wordmark.';
}

/**
 * Génère un symbole IA puis compose les trois déclinaisons avec le nom de la
 * boutique (une seule génération débitée). Le symbole est conservé dans la
 * médiathèque commune aux sites, même s'il n'est pas retenu.
 * -> ['paths' => [déclinaison => chemin], 'failed' => [libellés en échec]]
 */
function autofeed_generate_logos(string $description = ''): array
{
    if ($gate = ai_gate()) throw new RuntimeException($gate);
    if (autofeed_api_key() === '') throw new RuntimeException('La génération d’images n’est pas configurée.');
    if (function_exists('ai_check_low_balance_alert')) ai_check_low_balance_alert('gemini');

    $prompt = autofeed_logo_symbol_prompt($description);
    $images = autofeed_generate_images(['symbol' => ['prompt' => $prompt, 'ratio' => '1:1']]);
    $bytes = $images['symbol'] ?? null;
    if ($bytes === null) return ['paths' => [], 'failed' => array_map(static fn ($v) => $v[0], LOGO_VARIANTS)];

    $png = logo_transparent_png_bytes($bytes, 1024);
    $symbolFile = $png !== null ? logo_library_store($png, $prompt) : null;
    $symbolAbs = $symbolFile !== null ? logo_library_abs($symbolFile) : null;
    if ($symbolAbs === null) return ['paths' => [], 'failed' => array_map(static fn ($v) => $v[0], LOGO_VARIANTS)];

    logo_library_claim($symbolFile);   // un logo ne sert qu'à une seule boutique

    ai_usage_log_image();   // une seule image générée : le nom est écrit localement
    $result = logo_compose_all($symbolAbs);
    if ($result['paths'] && function_exists('ai_check_low_balance_alert')) ai_check_low_balance_alert('gemini');
    return $result;
}
