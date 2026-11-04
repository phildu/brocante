<?php

// Transcription et résumé d'un appel par IA : le commercial enregistre l'échange
// (ou colle le verbatim), le serveur renvoie un JSON prêt à remplir les notes.
// L'audio ne quitte pas la requête : il est envoyé au fournisseur, analysé, puis
// le fichier temporaire est supprimé. Aucune écriture en base ici : c'est le
// formulaire d'activité existant qui enregistre, comme avant.

require __DIR__ . '/_bootstrap.php';
require_once FV_ROOT . '/includes/ai-providers.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fv_ia_out(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

const FV_IA_MAX_AUDIO = 8 * 1024 * 1024;   // 8 Mo : environ 4 min de WAV 16 kHz mono
const FV_IA_MAX_TEXT = 6000;
const FV_IA_PER_HOUR = 12;

$ctx = fv_current();
if (!$ctx['member'] && !$ctx['manager']) {
    fv_ia_out(401, ['ok' => false, 'error' => 'Connexion requise.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    fv_ia_out(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
}
// Requête plus lourde que post_max_size : PHP la vide entièrement, jeton compris.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
    fv_ia_out(413, ['ok' => false, 'error' => 'Enregistrement trop lourd pour le serveur : enregistrez par séquences plus courtes.']);
}
if (!hash_equals(fv_csrf(), (string) ($_POST['csrf'] ?? ''))) {
    fv_ia_out(400, ['ok' => false, 'error' => 'Session expirée : rechargez la page.']);
}

$prospectId = (int) ($_POST['prospect_id'] ?? 0);
$prospect = $prospectId > 0 ? fv_prospect_get($prospectId) : null;
if (!$prospect) {
    fv_ia_out(404, ['ok' => false, 'error' => 'Fiche introuvable ou hors de votre périmètre.']);
}

$now = time();
$stamps = array_values(array_filter((array) ($_SESSION['fv_ia_stamps'] ?? []), static fn ($t): bool => (int) $t > $now - 3600));
$_SESSION['fv_ia_stamps'] = $stamps;
if (count($stamps) >= FV_IA_PER_HOUR) {
    fv_ia_out(429, ['ok' => false, 'error' => 'Trop de demandes sur une heure : réessayez plus tard.']);
}
$_SESSION['fv_ia_stamps'][] = $now;

$audio = null;
$mime = '';
$error = (int) ($_FILES['audio']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_NO_FILE) {
    if ($error !== UPLOAD_ERR_OK) {
        fv_ia_out(400, ['ok' => false, 'error' => 'Envoi de l’enregistrement interrompu.']);
    }
    $size = (int) ($_FILES['audio']['size'] ?? 0);
    if ($size <= 0) {
        fv_ia_out(400, ['ok' => false, 'error' => 'Enregistrement vide.']);
    }
    if ($size > FV_IA_MAX_AUDIO) {
        fv_ia_out(413, ['ok' => false, 'error' => 'Enregistrement trop long : trois minutes suffisent pour un appel.']);
    }
    $tmp = (string) ($_FILES['audio']['tmp_name'] ?? '');
    $audio = is_file($tmp) ? @file_get_contents($tmp) : false;
    if ($audio === false || $audio === '') {
        fv_ia_out(400, ['ok' => false, 'error' => 'Enregistrement illisible.']);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $raw = (string) $finfo->buffer($audio);
    $map = [
        'audio/wav' => 'audio/wav', 'audio/x-wav' => 'audio/wav', 'audio/wave' => 'audio/wav', 'audio/vnd.wave' => 'audio/wav',
        'audio/mpeg' => 'audio/mp3', 'audio/mp3' => 'audio/mp3',
        'audio/ogg' => 'audio/ogg', 'audio/opus' => 'audio/ogg',
        'audio/mp4' => 'audio/mp4', 'audio/x-m4a' => 'audio/mp4', 'audio/aac' => 'audio/mp4',
        'video/webm' => 'audio/webm', 'audio/webm' => 'audio/webm',
    ];
    if (!isset($map[$raw])) {
        fv_ia_out(415, ['ok' => false, 'error' => 'Format audio non pris en charge.']);
    }
    $mime = $map[$raw];
}

$texte = '';
if ($audio === null) {
    $texte = fv_paragraph($_POST['texte'] ?? '', FV_IA_MAX_TEXT);
    if (mb_strlen($texte) < 40) {
        fv_ia_out(400, ['ok' => false, 'error' => 'Enregistrez l’appel ou collez au moins quelques phrases.']);
    }
}

$provider = fv_ia_gemini();
if (!$provider) {
    fv_ia_out(503, ['ok' => false, 'error' => 'L’IA de la plateforme n’est pas disponible : vérifiez la clé Gemini dans le portail.']);
}

$prompt = fv_ia_prompt($prospect, $audio !== null);
$key = ai_provider_key_value($provider);
$base = trim((string) ($provider['base_url'] ?? ''));
if ($base === '') {
    $base = 'https://generativelanguage.googleapis.com/v1beta';
}
$model = trim((string) ($provider['model'] ?? ''));
if ($model === '') {
    $model = defined('GEMINI_TEXT_MODEL') && GEMINI_TEXT_MODEL !== '' ? GEMINI_TEXT_MODEL : 'gemini-3.8-flash';
}

$parts = [];
if ($audio !== null) {
    $parts[] = ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($audio)]];
}
$parts[] = ['text' => $prompt];
if ($audio === null) {
    $parts[] = ['text' => "Verbatim de l'appel, à traiter :\n\"\"\"\n" . $texte . "\n\"\"\""];
}
$payload = json_encode([
    'contents' => [['parts' => $parts]],
    'generationConfig' => ['temperature' => 0.2, 'responseMimeType' => 'application/json'],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init(rtrim($base, '/') . '/models/' . rawurlencode($model) . ':generateContent');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_CONNECTTIMEOUT => 15,
]);
$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$fromAudio = $audio !== null;
$audio = null;

if ($body === false || $status === 0) {
    error_log('vente/appel-ia : transport ' . $curlError);
    fv_ia_out(504, ['ok' => false, 'error' => 'L’IA n’a pas répondu à temps. Réessayez.']);
}
if ($status === 402 || str_contains((string) $body, 'prepayment')) {
    error_log('vente/appel-ia : crédit IA épuisé (HTTP ' . $status . ')');
    fv_ia_out(402, ['ok' => false, 'error' => 'Le crédit IA de la plateforme est épuisé.']);
}
if ($status >= 400) {
    error_log('vente/appel-ia : HTTP ' . $status . ' ' . substr((string) $body, 0, 300));
    fv_ia_out(502, ['ok' => false, 'error' => $status === 401 || $status === 403
        ? 'La clé IA a été refusée par le fournisseur.'
        : 'Le fournisseur d’IA a renvoyé une erreur.']);
}

$data = json_decode((string) $body, true);
$chunks = [];
foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
    if (is_array($part) && isset($part['text'])) $chunks[] = (string) $part['text'];
}
$text = trim(implode("\n", $chunks));
if ($text === '') {
    error_log('vente/appel-ia : réponse sans texte');
    fv_ia_out(502, ['ok' => false, 'error' => 'L’IA n’a rien renvoyé d’exploitable.']);
}

$text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text));
$result = json_decode($text, true);
if (!is_array($result)) {
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $result = json_decode(substr($text, $start, $end - $start + 1), true);
    }
}
if (!is_array($result)) {
    error_log('vente/appel-ia : JSON illisible ' . substr($text, 0, 200));
    fv_ia_out(502, ['ok' => false, 'error' => 'Résumé illisible. Réessayez ou collez le texte.']);
}

$clean = static function ($value, int $max): string {
    $s = str_replace(["\r\n", "\r"], "\n", (string) ($value ?? ''));
    $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    return mb_substr(trim($s), 0, $max);
};
$interet = (string) ($result['interet'] ?? '');
if (!in_array($interet, ['chaud', 'tiede', 'froid', 'inconnu'], true)) $interet = 'inconnu';
$resultat = (string) ($result['resultat'] ?? '');
if (!array_key_exists($resultat, fv_outcomes()['appel'])) $resultat = '';

fv_ia_out(200, [
    'ok' => true,
    'mode' => $fromAudio ? 'audio' : 'texte',
    'verbatim' => $clean($result['verbatim'] ?? '', FV_IA_MAX_TEXT),
    'resume' => $clean($result['resume'] ?? '', 1200),
    'besoin' => $clean($result['besoin'] ?? '', 300),
    'objection' => $clean($result['objection'] ?? '', 300),
    'prochaine_etape' => $clean($result['prochaine_etape'] ?? '', 300),
    'interet' => $interet,
    'resultat' => $resultat,
    'resultat_label' => $resultat !== '' ? (fv_outcomes()['appel'][$resultat] ?? '') : '',
]);

/** Fournisseur Gemini du texte : celui choisi s'il l'est, sinon la ligne Gemini activée. */
function fv_ia_gemini(): ?array
{
    $active = ai_provider_active('text');
    if ($active && $active['driver'] === 'gemini' && ai_provider_has_key($active)) return $active;
    foreach (ai_provider_rows('text') as $row) {
        if ($row['driver'] === 'gemini' && $row['enabled'] && ai_provider_has_key($row)) return $row;
    }
    return null;
}

/** Consigne : transcription fidèle puis résumé strictement JSON, ancré sur la fiche. */
function fv_ia_prompt(array $prospect, bool $hasAudio): string
{
    $business = trim((string) ($prospect['business'] ?? '')) !== '' ? (string) $prospect['business'] : (string) ($prospect['name'] ?? '');
    $city = (string) ($prospect['city'] ?? '');
    $kind = (string) ($prospect['kind'] ?? '');
    $source = $hasAudio
        ? "Tu reçois l'enregistrement d'un appel de prospection téléphonique."
        : "Tu reçois le verbatim d'un appel de prospection téléphonique.";

    return $source . " Tu travailles pour l'espace des forces de vente de Brocs, plateforme de boutiques en ligne "
        . "pour brocanteurs, galeries et marchés.\n\n"
        . "Fiche du prospect : « " . $business . " »" . ($city !== '' ? ', ' . $city : '') . ($kind !== '' ? ' (' . $kind . ')' : '') . ".\n\n"
        . "Transcris fidèlement ce qui est dit, en français, sans corriger le sens et sans rien inventer. "
        . "Puis résume l'échange. Toute information absente reste une chaîne vide.\n\n"
        . "Réponds UNIQUEMENT par un objet JSON, sans texte autour, avec exactement ces clés :\n"
        . '{"verbatim":"transcription fidèle, avec les hésitations utiles mais sans bruit","resume":"3 à 5 phrases: qui a été joint, ce qui a été dit, ce qui a été décidé","besoin":"ce que le commerçant veut obtenir, en une phrase","objection":"la principale réticence exprimée","prochaine_etape":"l\'action convenue et quand","interet":"chaud|tiede|froid|inconnu","resultat":"sans_reponse|messagerie|rappeler|interesse|rdv|refus|faux_numero|"}' . "\n\n"
        . "- resultat : le résultat le plus probable, choisi dans la liste ; chaîne vide si aucun échange n'a eu lieu.\n"
        . "- interet : chaud (demande un rendez-vous ou un devis), tiede (curieux mais hésitant), froid (refus poli), inconnu.\n"
        . "- Si l'audio ne contient pas d'échange commercial exploitable, mets resume à « Audio inexploitable » et laisse les autres champs vides.\n"
        . "- N'invente ni prix, ni dates, ni noms.";
}
