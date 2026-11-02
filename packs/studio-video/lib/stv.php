<?php
// Studio vidéo : préparation du projet, appel de l'IA (script et plan de montage) et normalisation des réponses.
// Les prises vidéo ne passent jamais par ce fichier : elles restent dans le navigateur de la boutique.
defined('PLUGIN_RUNTIME') || exit;

const STV_KINDS = [
    'piece' => 'Une pièce ou un objet',
    'boutique' => 'La boutique, les lieux',
    'evenement' => 'Un événement, une braderie',
    'temoignage' => 'Le témoignage d’un client',
    'coulisses' => 'Les coulisses, le métier',
    'annonce' => 'Une annonce courte (réseaux sociaux)',
];
const STV_TONES = [
    'simple' => 'Simple et direct',
    'chaleureux' => 'Chaleureux, proche des gens',
    'expert' => 'Expert, sans jargon',
    'drole' => 'Léger, avec une pointe d’humour',
];
const STV_FRAMINGS = ['plan large', 'plan moyen', 'gros plan', 'détail', 'mains à l’ouvrage', 'plan de coupe'];
const STV_STATUSES = ['draft', 'ready', 'done'];

/** Texte sur une ligne (espaces réduits), coupé à $max caractères. */
function stv_line(mixed $value, int $max): string
{
    $text = preg_replace('/\s+/u', ' ', (string) $value) ?? '';
    return mb_substr(trim($text), 0, $max);
}

/** Texte multiligne : retours à la ligne conservés, coupé à $max caractères. */
function stv_text(mixed $value, int $max): string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $text = preg_replace("/[ \t]+/u", ' ', $text) ?? '';
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? '';
    return mb_substr(trim($text), 0, $max);
}

function stv_float(mixed $value, float $min, float $max, float $default = 0.0): float
{
    $n = is_numeric($value) ? (float) $value : $default;
    if (!is_finite($n)) $n = $default;
    return round(max($min, min($max, $n)), 1);
}

function stv_kind_label(string $kind): string
{
    return STV_KINDS[$kind] ?? STV_KINDS['piece'];
}

function stv_tone_label(string $tone): string
{
    return STV_TONES[$tone] ?? STV_TONES['simple'];
}

/** Plans normalisés : liste nette, bornée, sans champ inattendu. */
function stv_norm_shots(mixed $shots): array
{
    $out = [];
    foreach ((array) $shots as $shot) {
        if (!is_array($shot) || count($out) >= 10) continue;
        $label = stv_line($shot['label'] ?? '', 80);
        $line = stv_line($shot['line'] ?? '', 400);
        if ($label === '' && $line === '') continue;
        $framing = str_replace("'", '’', mb_strtolower(stv_line($shot['framing'] ?? '', 40)));
        $out[] = [
            'label' => $label !== '' ? $label : 'Plan ' . (count($out) + 1),
            'framing' => in_array($framing, STV_FRAMINGS, true) ? $framing : 'plan moyen',
            'seconds' => stv_float($shot['seconds'] ?? 6, 2, 20, 6),
            'line' => $line,
            'tip' => stv_line($shot['tip'] ?? '', 240),
        ];
    }
    return $out;
}

function stv_norm_titles(mixed $titles): array
{
    $out = [];
    foreach ((array) $titles as $title) {
        $text = is_array($title) ? stv_line($title['text'] ?? '', 60) : stv_line($title, 60);
        if ($text === '' || in_array($text, $out, true)) continue;
        $out[] = $text;
        if (count($out) >= 5) break;
    }
    return $out;
}

function stv_norm_checklist(mixed $items): array
{
    $out = [];
    foreach ((array) $items as $item) {
        $text = stv_line($item, 140);
        if ($text === '' || in_array($text, $out, true)) continue;
        $out[] = $text;
        if (count($out) >= 8) break;
    }
    return $out;
}

function stv_norm_hashtags(mixed $tags): array
{
    $out = [];
    foreach ((array) $tags as $tag) {
        $word = preg_replace('/[^\p{L}\p{N}_]/u', '', (string) $tag) ?? '';
        $word = mb_substr($word, 0, 24);
        if ($word === '') continue;
        $tag = '#' . $word;
        if (in_array($tag, $out, true)) continue;
        $out[] = $tag;
        if (count($out) >= 8) break;
    }
    return $out;
}

/** Montage enregistré : une ligne par plan choisi, borné à la durée réelle des prises côté navigateur. */
function stv_norm_plan(mixed $plan): array
{
    $out = [];
    foreach ((array) $plan as $row) {
        if (!is_array($row) || count($out) >= 24) continue;
        $clip = (string) ($row['clip'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $clip)) continue;
        $in = stv_float($row['in'] ?? 0, 0, 900, 0);
        $out_ = stv_float($row['out'] ?? 0, 0, 900, 0);
        if ($out_ <= $in + 0.2) continue;
        $out[] = [
            'shot' => max(0, min(99, (int) ($row['shot'] ?? 0))),
            'clip' => $clip,
            'in' => $in,
            'out' => $out_,
            'title' => stv_line($row['title'] ?? '', 60),
        ];
    }
    return $out;
}

/** Champs du projet reçus du navigateur, nettoyés et complétés par $base (projet déjà enregistré). */
function stv_clean_project(array $in, array $base = []): array
{
    $kind = (string) ($in['kind'] ?? $base['kind'] ?? 'piece');
    $tone = (string) ($in['tone'] ?? $base['tone'] ?? 'simple');
    $format = (string) ($in['format'] ?? $base['format'] ?? 'landscape');
    $status = (string) ($in['status'] ?? $base['status'] ?? 'draft');
    return [
        'title' => stv_line($in['title'] ?? $base['title'] ?? '', 120),
        'kind' => isset(STV_KINDS[$kind]) ? $kind : 'piece',
        'audience' => stv_line($in['audience'] ?? $base['audience'] ?? '', 120),
        'tone' => isset(STV_TONES[$tone]) ? $tone : 'simple',
        'target_seconds' => max(10, min(180, (int) ($in['target_seconds'] ?? $base['target_seconds'] ?? 45))),
        'format' => in_array($format, ['landscape', 'portrait'], true) ? $format : 'landscape',
        'brief' => stv_text($in['brief'] ?? $base['brief'] ?? '', 1200),
        'script' => stv_text($in['script'] ?? $base['script'] ?? '', 4000),
        'shots' => stv_norm_shots($in['shots'] ?? json_decode((string) ($base['shots_json'] ?? '[]'), true)),
        'titles' => stv_norm_titles($in['titles'] ?? json_decode((string) ($base['titles_json'] ?? '[]'), true)),
        'checklist' => stv_norm_checklist($in['checklist'] ?? json_decode((string) ($base['checklist_json'] ?? '[]'), true)),
        'hashtags' => stv_norm_hashtags($in['hashtags'] ?? json_decode((string) ($base['hashtags_json'] ?? '[]'), true)),
        'plan' => stv_norm_plan($in['plan'] ?? json_decode((string) ($base['plan_json'] ?? '[]'), true)),
        'status' => in_array($status, STV_STATUSES, true) ? $status : 'draft',
    ];
}

/** Ligne de la table `studio_projects` telle que le navigateur la reçoit. */
function stv_project_out(array $row): array
{
    $json = static fn (string $key): array => is_array(json_decode((string) ($row[$key] ?? '[]'), true)) ? json_decode((string) $row[$key], true) : [];
    return [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'kind' => (string) $row['kind'],
        'audience' => (string) $row['audience'],
        'tone' => (string) $row['tone'],
        'target_seconds' => (int) $row['target_seconds'],
        'format' => (string) $row['format'],
        'brief' => (string) $row['brief'],
        'script' => (string) $row['script'],
        'shots' => $json('shots_json'),
        'titles' => $json('titles_json'),
        'checklist' => $json('checklist_json'),
        'hashtags' => $json('hashtags_json'),
        'plan' => $json('plan_json'),
        'status' => (string) $row['status'],
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
    ];
}

/** Nom de la boutique, pour situer la vidéo dans les consignes données à l'IA. */
function stv_shop_name(): string
{
    try {
        $content = get_content();
        $name = trim((string) ($content['site_name'] ?? ''));
        return $name !== '' ? $name : 'la boutique';
    } catch (Throwable $e) {
        return 'la boutique';
    }
}

/** Découpe une réponse IA en objet JSON, même entourée de texte ou de balises de code. */
function stv_json_loose(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text) ?? $text;
    $data = json_decode($text, true);
    if (is_array($data)) return $data;
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($data)) return $data;
    }
    return null;
}

/**
 * Appel de l'IA de texte de la boutique (fournisseur choisi au portail, Gemini par défaut).
 * Retourne le texte brut, ou null avec la raison dans $error.
 */
function stv_ai_call(string $prompt, ?string &$error = null, int $timeout = 90): ?string
{
    $error = null;
    if (function_exists('ai_gate')) {
        $blocked = ai_gate();
        if ($blocked !== null) {
            $error = $blocked;
            return null;
        }
    }
    $provider = function_exists('ai_provider_active') ? ai_provider_active('text') : null;
    $driver = (string) ($provider['driver'] ?? '');

    if ($provider && in_array($driver, ['openai', 'anthropic'], true)) {
        $text = function_exists('ai_provider_text') ? ai_provider_text($provider, $prompt, [], $timeout) : null;
        if ($text === null || trim($text) === '') {
            $error = "L'IA n'a pas répondu. Réessayez dans un instant.";
            return null;
        }
        return $text;
    }

    $key = $provider && function_exists('ai_provider_key_value') ? ai_provider_key_value($provider) : '';
    if ($key === '' && defined('GEMINI_API_KEY')) $key = (string) GEMINI_API_KEY;

    if (!function_exists('ai_provider_http')) {
        // Hôte autonome (brocante.arrimage.com) : pas d'includes/ai-providers.php,
        // mais functions.php sait déjà interroger Gemini en texte seul — et gère sa propre clé.
        if (function_exists('gemini_describe_image')) {
            // Une seule tentative : deux essais à 90 s dépasseraient le set_time_limit(120) d'api/ai.php.
            $text = gemini_describe_image('', $prompt, 0);
            if ($text === null || trim($text) === '') {
                $error = "L'IA n'a pas répondu. Réessayez dans un instant.";
                return null;
            }
            return $text;
        }
    }

    if ($key === '') {
        $error = "Aucune IA n'est configurée pour cette boutique (portail → IA et clés).";
        return null;
    }
    if (!function_exists('ai_provider_http')) {
        $error = "L'IA de la plateforme n'est pas disponible sur cette installation.";
        return null;
    }

    $model = function_exists('gemini_text_model') ? gemini_text_model() : 'gemini-2.5-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
        . ':generateContent?key=' . rawurlencode($key);
    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature' => 0.4,
            'responseMimeType' => 'application/json',
            'maxOutputTokens' => 4096,
        ],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        [$status, $raw] = ai_provider_http('POST', $url, ['Content-Type: application/json'], $body, $timeout);
        if ($status === 200) break;
        if ($attempt === 1 && in_array($status, [429, 500, 502, 503], true)) {
            usleep(1500000);
            continue;
        }
        $data = json_decode($raw, true) ?: [];
        $detail = stv_line((string) ($data['error']['message'] ?? ''), 160);
        $error = 'IA indisponible' . ($status ? ' (HTTP ' . $status . ')' : '') . '.' . ($detail !== '' ? ' ' . $detail : ' Réessayez dans un instant.');
        return null;
    }

    $data = json_decode($raw, true) ?: [];
    $parts = [];
    foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (isset($part['text'])) $parts[] = (string) $part['text'];
    }
    $text = trim(implode("\n", $parts));
    if ($text === '') {
        $error = "L'IA a renvoyé une réponse vide. Réessayez en précisant le sujet.";
        return null;
    }
    if (function_exists('ai_usage_log_text')) ai_usage_log_text((array) ($data['usageMetadata'] ?? []), $model);
    return $text;
}

/** Prompt du script : contexte de la boutique, contraintes de parole, schéma JSON attendu. */
function stv_prompt_script(array $project, string $shop): string
{
    $seconds = (int) $project['target_seconds'];
    $words = max(50, min(420, (int) round($seconds * 2.4)));
    $audience = $project['audience'] !== '' ? $project['audience'] : 'les visiteurs de la boutique et des réseaux sociaux';
    $brief = $project['brief'] !== '' ? $project['brief'] : 'Aucune précision : propose une vidéo qui présente le sujet simplement.';

    return "Tu prépares le script d'une courte vidéo pour la boutique de brocante « {$shop} ».\n"
        . "Demande de la personne qui tourne :\n"
        . "- Sujet : {$project['title']}\n"
        . "- Type : " . stv_kind_label($project['kind']) . "\n"
        . '- Durée visée : ' . $seconds . " secondes\n"
        . '- Public : ' . $audience . "\n"
        . '- Ton : ' . stv_tone_label($project['tone']) . "\n"
        . "- Ce qu'elle veut montrer et dire : {$brief}\n\n"
        . "Règles :\n"
        . "- Français parlé, simple et direct. Une idée par phrase, 15 mots au plus par phrase.\n"
        . "- Pas de jargon, pas de superlatif, pas de promesse de résultat, pas de chiffre inventé (prix, date, quantité, nombre de clients).\n"
        . "- Si une information manque, écris une formule neutre (par exemple « le prix est affiché en boutique ») au lieu d'inventer.\n"
        . '- Le texte dit à voix haute doit tenir en ' . $seconds . ' secondes, soit environ ' . $words . " mots.\n"
        . "- Découpe la vidéo en 3 à 8 plans. Chaque plan a un cadrage parmi : " . implode(', ', STV_FRAMINGS) . ".\n"
        . "- « line » = la phrase dite pendant ce plan (vide pour un plan muet).\n"
        . "- « tip » = un conseil concret de prise de vue pour ce plan (lumière, angle, geste, durée).\n"
        . "- « titles » = 2 à 4 textes très courts à afficher à l'écran (titre puis sous-titres), 48 caractères au plus.\n"
        . "- « checklist » = 4 à 6 vérifications concrètes avant de tourner.\n"
        . "- « hashtags » = 4 à 6 mots-dièses pertinents pour cette boutique.\n\n"
        . "Réponds uniquement par un objet JSON, sans commentaire ni balise de code :\n"
        . '{"title":"titre de la vidéo","script":"texte complet à dire","shots":[{"label":"nom du plan","framing":"' . STV_FRAMINGS[1] . '","seconds":6,"line":"phrase dite","tip":"conseil"}],"titles":["titre à l\'écran"],"checklist":["vérification"],"hashtags":["#brocante"]}';
}

/** Prompt du montage : les prises réellement disponibles, les plans prévus, un ordre et des coupes. */
function stv_prompt_plan(array $project, array $shots, array $clips, string $shop): string
{
    $clipLines = [];
    foreach ($clips as $clip) {
        $clipLines[] = '- identifiant « ' . $clip['id'] . ' » · '
            . ((int) $clip['shot'] > 0 ? 'plan ' . $clip['shot'] : 'prise libre, hors plan')
            . ' · ' . $clip['seconds'] . ' s'
            . ($clip['label'] !== '' ? ' · ' . $clip['label'] : '');
    }
    $shotLines = [];
    foreach ($shots as $i => $shot) {
        $shotLines[] = '- plan ' . ($i + 1) . ' · ' . $shot['label'] . ' · cadrage ' . $shot['framing']
            . ' · ' . $shot['seconds'] . ' s' . ($shot['line'] !== '' ? ' · à dire : ' . $shot['line'] : '');
    }
    $script = $project['script'] !== '' ? "Texte prévu :\n" . $project['script'] . "\n\n" : '';

    return "Tu prépares le montage d'une courte vidéo pour la boutique « {$shop} ».\n\n"
        . $script
        . "Plans prévus :\n" . (implode("\n", $shotLines) ?: '- aucun plan détaillé') . "\n\n"
        . "Prises tournées, avec leur durée totale (toutes ne sont pas bonnes) :\n" . implode("\n", $clipLines) . "\n\n"
        . "Règles :\n"
        . "- Une prise au plus par plan, dans l'ordre des plans.\n"
        . "- Une « prise libre, hors plan » peut servir pour n'importe quel plan : indique alors le numéro du plan qu'elle illustre. Utilise-la même si les plans sont détaillés.\n"
        . "- Écarte seulement les prises vraiment inutilisables : moins de 1,5 s, ou moins de la moitié de la durée du plan visé.\n"
        . "- « in » et « out » sont des secondes dans la prise : garde la meilleure partie, sans couper une phrase au milieu quand c'est possible.\n"
        . "- La durée totale doit rester proche de " . (int) $project['target_seconds'] . " secondes (à 20 % près au plus).\n"
        . "- « title » : un texte à afficher par-dessus, 48 caractères au plus, uniquement s'il apporte une information (nom de l'objet, époque, matière, prix affiché…). Sinon une chaîne vide.\n"
        . "- N'utilise que les identifiants de prises fournis, recopiés exactement.\n\n"
        . "Réponds uniquement par un objet JSON, sans commentaire ni balise de code :\n"
        . '{"plan":[{"clip":"identifiant","shot":1,"in":0,"out":5.5,"title":""}],"notes":"conseil de montage en une phrase"}';
}

/** Script et plans par l'IA. Retourne les champs normalisés, ou null avec la raison dans $error. */
function stv_ai_script(array $project, string $shop, ?string &$error = null): ?array
{
    $text = stv_ai_call(stv_prompt_script($project, $shop), $error, 100);
    if ($text === null) return null;
    $data = stv_json_loose($text);
    if ($data === null) {
        $error = "L'IA n'a pas renvoyé un script lisible. Réessayez.";
        return null;
    }
    $script = stv_text($data['script'] ?? '', 4000);
    $shots = stv_norm_shots($data['shots'] ?? []);
    if ($script === '' || !$shots) {
        $error = "L'IA n'a pas produit de script complet. Réessayez en précisant le sujet et ce que vous voulez montrer.";
        return null;
    }
    return [
        'title' => stv_line($data['title'] ?? $project['title'], 120),
        'script' => $script,
        'shots' => $shots,
        'titles' => stv_norm_titles($data['titles'] ?? []),
        'checklist' => stv_norm_checklist($data['checklist'] ?? []),
        'hashtags' => stv_norm_hashtags($data['hashtags'] ?? []),
    ];
}

/** Plan de montage par l'IA, validé contre les prises réellement disponibles. */
function stv_ai_plan(array $project, array $shots, array $clips, string $shop, ?string &$error = null): ?array
{
    if (!$clips) {
        $error = 'Aucune prise à monter pour le moment.';
        return null;
    }
    $text = stv_ai_call(stv_prompt_plan($project, $shots, $clips, $shop), $error, 100);
    if ($text === null) return null;
    $data = stv_json_loose($text);
    if ($data === null) {
        $error = "L'IA n'a pas renvoyé un montage lisible. Réessayez.";
        return null;
    }
    $known = [];
    foreach ($clips as $clip) $known[$clip['id']] = (float) $clip['seconds'];

    $plan = [];
    foreach ((array) ($data['plan'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $id = (string) ($row['clip'] ?? '');
        if (!isset($known[$id])) continue;
        $max = $known[$id];
        $in = stv_float($row['in'] ?? 0, 0, $max, 0);
        $out = stv_float($row['out'] ?? $max, 0, $max, $max);
        if ($out <= $in + 0.2) continue;
        $plan[] = [
            'shot' => max(0, min(99, (int) ($row['shot'] ?? 0))),
            'clip' => $id,
            'in' => $in,
            'out' => $out,
            'title' => stv_line($row['title'] ?? '', 60),
        ];
        if (count($plan) >= 24) break;
    }
    if (!$plan) {
        $error = "L'IA n'a proposé aucune coupe valable. Vérifiez que les prises sont bien enregistrées, puis réessayez.";
        return null;
    }
    return ['plan' => $plan, 'notes' => stv_line($data['notes'] ?? '', 400)];
}
