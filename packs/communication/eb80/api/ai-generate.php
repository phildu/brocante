<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * POST /api/ai-generate
 * Génère un script vidéo via Claude (Anthropic) ou Gemini (Google)
 * Body JSON : { provider: 'claude'|'gemini', poster: {...} }
 */
require_once __DIR__ . '/../compat.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

requireAuth(['admin']);
if (($eb80Gate = ai_gate()) !== null) errorResponse($eb80Gate, 402);

$input           = json_decode(file_get_contents('php://input'), true) ?? [];
$provider        = 'gemini'; // la boutique n'utilise que Gemini
$poster          = $input['poster']          ?? [];
$context         = trim($input['context']    ?? '');
$duration        = max(6, min(300, (int)($input['duration'] ?? 15)));
$shorten         = !empty($input['shorten']);
$existingScript  = $input['existing_script'] ?? null;

// Sujet alimenté depuis l'admin (plugin sources) : profil evenement (défaut), produit, organisation ou generique.
$source     = is_array($input['source'] ?? null) ? $input['source'] : null;
$sourceType = in_array($source['profil'] ?? '', ['produit', 'organisation', 'generique'], true) ? $source['profil'] : 'event';
$sourceCtx  = $source ? mb_substr(trim((string)($source['contexte'] ?? '')), 0, 1200) : '';

// Construire le contexte affiche
$artist = $poster['logo']['text']   ?? 'Artiste';
$title  = $poster['title']['text']  ?? '';
$date   = $poster['date']['text']   ?? '';
$venue  = $poster['venue']['text']  ?? '';
$info   = $poster['info']['text']   ?? '';
$footer = $poster['footer']['text'] ?? '';

// Calculer l'urgence selon le délai avant l'événement
$urgencyCtx = '';
if ($date && $sourceType === 'event') {
    // Tenter de parser la date (formats courants : "Vendredi 12 septembre 2025", "12/09/2025", ISO...)
    $parsedDate = date_create($date) ?: null;
    // Essai avec strtotime pour formats français partiels
    if (!$parsedDate) {
        $cleaned = preg_replace('/[A-Za-zÀ-ÖØ-öø-ÿ]+\s+/u', '', $date); // supprime jours/mois en lettres
        $parsedDate = date_create(trim($cleaned)) ?: null;
    }
    if ($parsedDate) {
        $now  = new DateTime();
        $diff = $now->diff($parsedDate);
        $days = (int)$diff->days;
        if (!$diff->invert) { // événement dans le futur
            if ($days === 0)     $urgencyCtx = "URGENCE MAXIMALE : l'événement a lieu AUJOURD'HUI.";
            elseif ($days === 1) $urgencyCtx = "L'événement est DEMAIN — ton ultra urgent.";
            elseif ($days <= 7)  $urgencyCtx = "L'événement est dans $days jours — ton très urgent, compte à rebours.";
            elseif ($days <= 30) $urgencyCtx = "L'événement est dans $days jours — ton anticipation, appel à réserver.";
            else                 $urgencyCtx = "L'événement est dans $days jours — ton teasing, créer l'envie.";
        } else {
            $urgencyCtx = "L'événement a eu lieu il y a $days jours — ton recap / aftermovie.";
        }
    }
}

$posterDesc  = "Artiste / Nom : $artist";
if ($title)       $posterDesc .= "\nTitre / Événement : $title";
if ($date)        $posterDesc .= "\nDate et heure : $date";
if ($venue)       $posterDesc .= "\nLieu : $venue";
if ($info)        $posterDesc .= "\nInformation : $info";
if ($footer)      $posterDesc .= "\nSite/contact : $footer";
if ($urgencyCtx)  $posterDesc .= "\n\nContexte temporel : $urgencyCtx";
if ($sourceCtx)   $posterDesc .= "\n\nSujet fourni (informations à respecter, sans rien inventer) :\n$sourceCtx";
if ($context)     $posterDesc .= "\nStyle souhaité par l'artiste : $context";

// Consignes selon le sujet
if ($sourceType === 'produit') {
    $mustMention = "Le script DOIT nommer l'article, donner son prix et donner envie de l'acheter (boutique, lien en bio). N'invente ni promotion ni stock. ";
    $corpsRule   = "narration qui présente l'ARTICLE et son PRIX";
    $captionRule = "légende Instagram complète avec nom de l'article, prix, lien boutique en bio";
} elseif ($sourceType === 'organisation') {

    $mustMention = "Le script DOIT mettre en valeur le partenaire (nom, ville) avec un ton chaleureux de remerciement ou de recommandation. N'invente aucun fait sur lui. ";
    $corpsRule   = "narration qui met en valeur le PARTENAIRE (nom, ville)";
    $captionRule = "légende Instagram complète qui cite le partenaire et sa ville, avec un appel à le soutenir";
} elseif ($sourceType === 'generique') {
    $mustMention = "Le script DOIT présenter clairement le sujet fourni (nom, ce qui le caractérise) et donner envie d'en savoir plus. N'invente aucun fait. ";
    $corpsRule   = "narration qui présente le SUJET (nom et points forts fournis)";
    $captionRule = "légende Instagram complète qui présente le sujet et renvoie vers le lien fourni";
} else {
    $mustMention = "Le script DOIT mentionner la date et le lieu de l'événement. Adapte le ton à l'urgence fournie. ";
    $corpsRule   = "narration avec DATE et LIEU";
    $captionRule = "légende Instagram complète avec date, lieu, lien bio";
}

// Calcul du découpage temporel selon la durée réelle
$hookEnd  = min(3, (int)round($duration * 0.15));
$ctaStart = max($hookEnd + 3, (int)round($duration * 0.78));
$timingEx = "hook 0-{$hookEnd}s | corps {$hookEnd}-{$ctaStart}s | cta {$ctaStart}-{$duration}s";

$systemPrompt = "Tu es un expert en communication pour artistes de musique électronique et DJs. "
    . "Tu crées des scripts de Reels vidéo (format 9:16, durée EXACTE : {$duration} secondes). "
    . "Découpage imposé : $timingEx. "
    . "Hook = 2-4 mots percutants lus en {$hookEnd}s. Corps = 2-3 phrases max adaptées au temps disponible. CTA = phrase courte + emoji. "
    . $mustMention
    . "Réponds UNIQUEMENT en JSON valide, sans markdown, sans texte avant ou après. "
    . "Format : {\"hook\":\"...\",\"corps\":\"...\",\"cta\":\"...\",\"timing\":\"$timingEx\",\"hashtags\":[\"...\"],\"caption\":\"...\"}";

if ($shorten && $existingScript) {
    $prevHook  = $existingScript['hook']  ?? '';
    $prevCorps = $existingScript['corps'] ?? '';
    $prevCta   = $existingScript['cta']   ?? '';
    $userPrompt = "Le script ci-dessous est TROP LONG pour une vidéo de {$duration} secondes.\n\n"
        . "Script actuel :\n"
        . "HOOK: $prevHook\nCORPS: $prevCorps\nCTA: $prevCta\n\n"
        . "Raccourcis-le STRICTEMENT pour tenir en {$duration}s selon le découpage : $timingEx.\n"
        . "Garde les infos essentielles : artiste, sujet, et selon le cas date, lieu ou prix. Coupe tout ce qui est superflu.\n"
        . "Même format JSON : hook, corps, cta, timing (\"$timingEx\"), hashtags, caption.";
} else {
    $userPrompt = "À partir de l'affiche ci-dessous, génère un script de Reel vidéo de {$duration} secondes :\n\n"
        . $posterDesc . "\n\n"
        . "JSON attendu :\n"
        . "- hook : accroche MAJUSCULES (2-4 mots, lu en {$hookEnd}s)\n"
        . "- corps : $corpsRule (durée " . ($ctaStart - $hookEnd) . "s max)\n"
        . "- cta : appel à l'action + emoji (durée " . ($duration - $ctaStart) . "s)\n"
        . "- timing : \"$timingEx\"\n"
        . "- hashtags : 6-8 hashtags pertinents\n"
        . "- caption : $captionRule";
}

// --- Lecture clé API depuis settings DB ---
function _eb80_orig_getAiKey(string $keyName): string {
    try {
        $db   = getDb();
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$keyName]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['value'] ?? '';
    } catch (Exception $e) {
        return '';
    }
}

if ($provider === 'gemini') {
    $apiKey = getAiKey('gemini_api_key');
    if (!$apiKey) { errorResponse('Clé Gemini non configurée (Réglages → IA)', 400); }

    // gemini-2.0-flash — plus stable que 1.5-flash-latest (alias déprécié)
    // Pas de responseMimeType pour éviter les réponses vides en mode JSON strict
    $body = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents'           => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
        'generationConfig'   => ['temperature' => 0.8, 'maxOutputTokens' => 4096],
    ]);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=" . urlencode($apiKey);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);

    if ($err) { errorResponse('Erreur réseau Gemini : ' . $err, 500); }

    $resp = json_decode($raw, true);

    // Erreur explicite de l'API Google
    if (!empty($resp['error'])) {
        errorResponse('Gemini API : ' . ($resp['error']['message'] ?? json_encode($resp['error'])), 500);
    }

    $text = $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text) ai_usage_log_text($resp['usageMetadata'] ?? []);
    if (!$text) {
        // Diagnostic : renvoyer la réponse brute pour déboguer
        $finishReason = $resp['candidates'][0]['finishReason'] ?? 'inconnu';
        errorResponse("Réponse Gemini vide (finishReason: $finishReason) — brut: " . substr($raw, 0, 300), 500);
    }

    // Tenter plusieurs stratégies pour extraire et parser le JSON
    $script = json_decode($text, true);

    if (!$script) {
        // Bloc markdown ```json ... ```
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/s', $text, $m))
            $script = json_decode(trim($m[1]), true);
    }

    if (!$script) {
        // Premier { ... dernier } (greedy, sans couper à l'intérieur)
        if (preg_match('/(\{[\s\S]*\})/s', $text, $m))
            $script = json_decode(trim($m[1]), true);
    }

    if (!$script) {
        errorResponse('JSON Gemini non parsable — réponse brute : ' . substr($text, 0, 500), 500);
    }
    jsonResponse(['ok' => true, 'provider' => 'gemini', 'script' => $script]);

} else {
    // Claude (Anthropic)
    $apiKey = getAiKey('claude_api_key');
    if (!$apiKey) { errorResponse('Clé Claude non configurée (Réglages → IA)', 400); }

    $body = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 512,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $userPrompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);

    if ($err) { errorResponse('Erreur réseau Claude : ' . $err, 500); }

    $resp = json_decode($raw, true);
    if (isset($resp['error'])) { errorResponse('Claude API : ' . $resp['error']['message'], 500); }
    $text = $resp['content'][0]['text'] ?? '';
    if (!$text) { errorResponse('Réponse Claude vide', 500); }

    $script = json_decode($text, true);
    if (!$script) { errorResponse('JSON Claude non parsable', 500); }
    jsonResponse(['ok' => true, 'provider' => 'claude', 'script' => $script]);
}
