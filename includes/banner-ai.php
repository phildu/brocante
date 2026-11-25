<?php

// Génération d'images de bandeau par l'IA pour les pages publiques de la plateforme (accueil du portail, galeries) :
// même fournisseur et même modèle que l'IA des boutiques (Gemini image), avec la clé partagée du portail.
// Contrairement à autofeed_regenerate(), aucun commerce n'est en contexte : aucune base de boutique n'est lue.

require_once __DIR__ . '/autofeed.php';
require_once __DIR__ . '/ai-providers.php';
require_once __DIR__ . '/banner-ai-usage.php';

/** Un fournisseur de la chaîne peut-il générer ici ? (Gemini : clé du portail ; les autres : leur propre clé) */
function banner_ai_usable(array $provider): bool
{
    return $provider['driver'] === 'gemini' ? autofeed_api_key() !== '' : ai_provider_has_key($provider);
}

/** Fournisseurs d'images à essayer dans l'ordre : l'actif, puis les secours cochés (Gemini, Cloudflare, Hugging Face). */
function banner_ai_chain(): array
{
    return array_values(array_filter(ai_provider_chain('image', 'text_image'), 'banner_ai_usable'));
}

/** Génération possible ? (au moins un fournisseur image utilisable, IA de la plateforme ouverte) */
function banner_ai_available(): bool
{
    if (!banner_ai_chain()) return false;
    $settings = ai_provider_read();
    return !array_key_exists('enabled', $settings) || (bool) $settings['enabled'];
}

/** Invite de génération d'un bandeau panoramique, à partir du sujet et d'une précision facultative. */
function banner_ai_prompt(string $subject, string $hint = ''): string
{
    $subject = trim(preg_replace('/\s+/u', ' ', strip_tags($subject)));
    $prompt = 'Wide website hero banner photograph, 16:9 panorama, for '
        . ($subject !== '' ? $subject : 'une place de marché conviviale')
        . '. Warm natural daylight, inviting French market atmosphere, rich but calm colours, shallow depth of field, '
        . 'clear empty space on one side for a title overlay. Photorealistic, no text, no watermark, no logo.';
    if ($hint !== '') $prompt .= ' Client request: ' . mb_substr(strip_tags($hint), 0, 200) . '.';
    return $prompt;
}

/** Message affichable pour un échec de génération (même classification que l'administration des boutiques). */
function banner_ai_error(?string $body, int $httpCode): string
{
    $err = is_string($body) ? json_decode($body, true) : null;
    $apiMsg = (string) ($err['error']['message'] ?? '');
    $apiCode = (int) ($err['error']['code'] ?? $httpCode);
    $blockReason = (string) ($err['candidates'][0]['finishReason'] ?? $err['promptFeedback']['blockReason'] ?? '');
    if ($apiCode === 402 || stripos($apiMsg, 'prepayment') !== false || stripos($apiMsg, 'depleted') !== false) {
        return "Crédits Google AI Studio épuisés : rechargez le compte sur https://ai.studio/projects. Rien n'a été débité.";
    }
    if ($apiCode === 429) {
        return 'Quota ou débit temporairement dépassé chez Google (erreur 429) : réessayez dans quelques instants. Rien n\'a été débité.';
    }
    if (in_array($apiCode, [400, 401, 403], true)) {
        error_log('banner_ai: autorisation HTTP ' . $apiCode . ' - ' . substr((string) $body, 0, 300));
        return "La clé d'API Google Gemini est refusée" . ($apiMsg !== '' ? ' (' . mb_substr($apiMsg, 0, 100) . ')' : ' (code ' . $apiCode . ')') . '. Vérifiez-la dans Fournisseurs IA.';
    }
    if ($blockReason === 'SAFETY') {
        return "L'image demandée a été bloquée par les filtres de sécurité de l'IA. Ajustez la description pour réessayer.";
    }
    error_log('banner_ai: échec HTTP ' . $httpCode . ' - ' . substr((string) $body, 0, 400));
    return "L'IA n'a pas renvoyé d'image" . ($apiMsg !== '' ? ' (' . mb_substr($apiMsg, 0, 120) . ')' : '') . " : rien n'a été débité, réessayez.";
}

/**
 * Génère plusieurs images en parallèle et rend leurs octets (JPEG/PNG renvoyés par le modèle).
 * Lève RuntimeException avec un message affichable si aucune image n'a pu être produite.
 */
function banner_ai_generate_many(array $prompts, string $ratio = '16:9'): array
{
    $prompts = array_slice(array_values(array_filter(array_map('strval', $prompts))), 0, 3);
    if (!$prompts) throw new InvalidArgumentException('Aucune description à illustrer.');
    $chain = banner_ai_available() ? banner_ai_chain() : [];
    if (!$chain) {
        throw new RuntimeException("La génération d'images par l'IA n'est pas disponible : fournisseur image désactivé ou clé absente (voir Fournisseurs IA).");
    }
    // Chaque fournisseur reprend les descriptions que le précédent n'a pas pu illustrer (quota, crédits, clé refusée…).
    $images = [];
    $error = null;
    foreach ($chain as $n => $provider) {
        $missing = array_diff_key($prompts, $images);
        if (!$missing) break;
        $got = $provider['driver'] === 'gemini'
            ? banner_ai_gemini_many($missing, $ratio, $error)
            : banner_ai_free_many($provider, $missing, $ratio, $error);
        if ($got && $n > 0) error_log('banner_ai: secours « ' . $provider['label'] . ' » utilisé après l\'échec de « ' . $chain[0]['label'] . ' ».');
        $images += $got;
    }
    if (!$images) throw new RuntimeException($error ?? "L'IA n'a pas renvoyé d'image : rien n'a été débité, réessayez.");
    ksort($images);
    return array_values($images);
}

/** Gemini : toutes les descriptions en parallèle. Rend index => octets ; $error reçoit le message du premier échec. */
function banner_ai_gemini_many(array $prompts, string $ratio, ?string &$error): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($prompts as $i => $prompt) {
        $handles[$i] = autofeed_curl('gemini-2.5-flash-image', autofeed_image_payload($prompt, $ratio));
        curl_multi_add_handle($multi, $handles[$i]);
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1.0);
    } while ($running && $status === CURLM_OK);

    $images = [];
    foreach ($handles as $i => $ch) {
        $body = (string) curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        [$bytes] = autofeed_extract_image($body);
        if ($bytes !== null) $images[$i] = $bytes;
        elseif ($error === null) $error = banner_ai_error($body, $code);
    }
    foreach ($handles as $ch) {
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $images;
}

/** Cloudflare / Hugging Face : un appel après l'autre (débit limité des offres gratuites). Rend index => octets. */
function banner_ai_free_many(array $provider, array $prompts, string $ratio, ?string &$error): array
{
    $images = [];
    foreach ($prompts as $i => $prompt) {
        $bytes = ai_provider_text_image($provider, $prompt, $ratio);
        if ($bytes !== null) $images[$i] = $bytes;
    }
    if (!$images && $error === null) {
        $error = "Le service « " . $provider['label'] . " » n'a pas renvoyé d'image : quota du jour atteint ou jeton refusé. Testez la connexion dans Fournisseurs IA.";
    }
    return $images;
}

/** Enregistre des octets générés en JPEG dans uploads/saas ou uploads/galeries ; rend le chemin relatif. */
function banner_ai_save(string $bytes, string $relativeDir, string $prefix, int $maxDim = 2000): string
{
    if (!in_array($relativeDir, ['uploads/saas', 'uploads/galeries'], true)) {
        throw new InvalidArgumentException("Dossier d'images inattendu.");
    }
    $prefix = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($prefix)), '-') ?: 'bandeau';
    $abs = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($abs) && !@mkdir($abs, 0755, true)) {
        throw new RuntimeException("Le dossier $relativeDir n'est pas inscriptible.");
    }
    $name = $prefix . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
    if (!autofeed_save_jpeg($bytes, $abs . '/' . $name, $maxDim)) {
        throw new RuntimeException("Impossible d'enregistrer l'image générée.");
    }
    return $relativeDir . '/' . $name;
}

/**
 * Choisit un bandeau de la médiathèque commune pour une galerie : copie propre
 * à la galerie, au même format (nom stable, donc un même bandeau n'est ajouté
 * qu'une fois). La copie survit au retrait de l'image partagée.
 */
function banner_ai_adopt_library(string $file, string $slug): string
{
    $abs = banner_library_abs($file);
    if ($abs === null || !preg_match('/^banner-([a-f0-9]{8})\.jpg$/', $file, $m)) {
        throw new InvalidArgumentException('Bandeau introuvable dans la médiathèque commune.');
    }
    $relative = 'uploads/galeries/' . $slug . '-banner-' . $m[1] . '.jpg';
    $target = dirname(__DIR__) . '/' . $relative;
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Le dossier uploads/galeries/ n'est pas inscriptible.");
    }
    if (!is_file($target) && !@copy($abs, $target)) {
        throw new RuntimeException("Impossible de copier le bandeau dans la galerie.");
    }
    return $relative;
}
