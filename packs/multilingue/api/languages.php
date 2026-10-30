<?php

// API du module Version multilingue : état des langues, génération par étapes (une page puis un lot de textes
// par requête, pour rester sous les limites de temps), publication et suppression.
// Toutes les actions passent par la session d'administration et le jeton CSRF (admin/plugin-api.php).

defined('PLUGIN_RUNTIME') || exit;

// Chaque requête fait au plus un lot d'IA (55 s) ou la lecture d'une page : la marge évite une coupure du serveur.
@set_time_limit(90);

require_once dirname(__DIR__) . '/lib/ml.php';

if (!addon_is_entitled(ML_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

/** Résumé d'une génération en cours. */
function ml_state_summary(string $code): array
{
    $state = ml_state($code);
    return [
        'pages_done' => (int) ($state['pages_done'] ?? 0),
        'total_pages' => count((array) ($state['pages'] ?? [])),
        'queued' => count((array) ($state['queue'] ?? [])),
        'strings' => count((array) ($state['map'] ?? [])),
        'cost' => round((float) ($state['cost_usd'] ?? 0), 4),
        'started_at' => (int) ($state['started_at'] ?? 0),
    ];
}

if ($method === 'GET') {
    $languages = [];
    foreach (ml_rows() as $row) {
        $code = (string) $row['code'];
        $languages[] = [
            'code' => $code,
            'label' => (string) $row['label'],
            'status' => (string) $row['status'],
            'strings' => (int) $row['strings'],
            'chars' => (int) $row['chars'],
            'cost' => round((float) $row['cost_usd'], 4),
            'generated_at' => (string) ($row['generated_at'] ?? ''),
            'has_file' => is_file(ml_file($code)),
            'work' => is_file(ml_work_file($code)) ? ml_state_summary($code) : null,
        ];
    }
    $published = [];
    foreach (ml_published() as $code => $label) $published[] = ['code' => $code, 'label' => $label];
    plugin_json([
        'languages' => $languages,
        'catalog' => ml_catalog(),
        'source' => ML_SOURCE,
        'published' => $published,
        'pages' => count(ml_pages()),
        'batch' => ML_BATCH,
        'estimate_per_batch' => ai_text_call_usd(),
        'usd_eur' => ai_pricing()['usd_eur'],
        'ai_gate' => ai_gate(),
    ]);
}

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
$code = strtolower(trim((string) ($in['code'] ?? '')));

if ($action === 'delete') {
    if (!ml_row($code) && !is_file(ml_file($code))) plugin_json(['error' => 'Langue introuvable.'], 404);
    ml_delete($code);
    plugin_json(['ok' => true, 'message' => 'Version supprimée.']);
}

if (!ml_code_ok($code)) plugin_json(['error' => 'Choisissez une langue proposée (différente du français).'], 422);

if ($action === 'start') {
    $restart = !empty($in['restart']);
    $state = ml_state($code);
    $isNew = $restart || !is_file(ml_work_file($code));
    // Liste des pages figée au démarrage : une vente pendant la génération ne décale pas les index.
    $pages = $isNew ? ml_pages() : (array) ($state['pages'] ?? []);
    if (!$pages) $pages = ml_pages();
    if ($isNew) {
        $state = [
            'code' => $code,
            'label' => ml_label($code),
            'queue' => [],
            'map' => [],
            'retry' => [],
            'pages' => $pages,
            'pages_done' => 0,
            'cost_usd' => 0.0,
            'started_at' => time(),
        ];
        ml_state_save($code, $state);
    }
    plugin_json([
        'ok' => true,
        'fresh' => $isNew,
        'pages' => array_map(static fn (array $p): string => $p['label'], $pages),
        'total_pages' => count($pages),
        'progress' => ml_state_summary($code),
        'replaces_published' => is_file(ml_file($code)),
    ]);
}

if ($action === 'extract') {
    $index = (int) ($in['index'] ?? -1);
    $state = ml_state($code);
    $pages = (array) ($state['pages'] ?? []);
    if (!$pages) $pages = ml_pages();
    if ($index < 0 || $index >= count($pages)) plugin_json(['error' => 'Page inconnue.'], 422);
    $page = $pages[$index];
    $html = ml_fetch($page['url']);
    $state['label'] = ml_label($code);
    if ($html === null) {
        plugin_json(['ok' => true, 'fetched' => false, 'page' => $page['label'], 'new' => 0,
            'error' => 'Page inaccessible (pièce vendue entre-temps ?) — ignorée.', 'progress' => ml_state_summary($code)]);
    }
    $known = [];
    foreach (array_keys((array) ($state['map'] ?? [])) as $text) $known[$text] = true;
    foreach ((array) ($state['queue'] ?? []) as $text) $known[$text] = true;
    $new = 0;
    foreach (ml_extract_texts($html) as $text) {
        if (isset($known[$text])) continue;
        $state['queue'][] = $text;
        $known[$text] = true;
        $new++;
    }
    $state['pages_done'] = max((int) ($state['pages_done'] ?? 0), $index + 1);
    ml_state_save($code, $state);
    plugin_json(['ok' => true, 'fetched' => true, 'page' => $page['label'], 'new' => $new, 'progress' => ml_state_summary($code)]);
}

if ($action === 'translate') {
    $state = ml_state($code);
    $queue = array_values((array) ($state['queue'] ?? []));
    if (!$queue) plugin_json(['ok' => true, 'translated' => 0, 'remaining' => 0, 'progress' => ml_state_summary($code)]);
    $chunk = ml_next_chunk($queue);
    $result = ml_translate_batch($chunk, $code);
    if (!$result['ok']) {
        // Le lot sorti de la file n'a pas été traduit : il y retourne, sinon ces textes seraient perdus.
        $state['queue'] = array_merge($chunk, $queue);
        ml_state_save($code, $state);
        plugin_json(['ok' => false, 'error' => (string) ($result['error'] ?? "Échec de l'IA."), 'remaining' => count($state['queue'])], 502);
    }
    $retry = (array) ($state['retry'] ?? []);
    $translated = 0;
    foreach ($chunk as $source) {
        if (isset($result['map'][$source])) {
            $state['map'][$source] = $result['map'][$source];
            $translated++;
            continue;
        }
        $retry[$source] = (int) ($retry[$source] ?? 0) + 1;
        if ($retry[$source] <= 1) {
            $queue[] = $source;
        } else {
            // Deux échecs sur ce texte : on garde le français plutôt que de bloquer la génération.
            $state['map'][$source] = $source;
            unset($retry[$source]);
        }
    }
    $state['queue'] = $queue;
    $state['retry'] = $retry;
    $state['cost_usd'] = (float) ($state['cost_usd'] ?? 0) + (float) ($result['cost'] ?? 0);
    ml_state_save($code, $state);
    plugin_json(['ok' => true, 'translated' => $translated, 'remaining' => count($queue),
        'progress' => ml_state_summary($code), 'model' => (string) ($result['model'] ?? '')]);
}

if ($action === 'finish') {
    $state = ml_state($code);
    $remaining = count((array) ($state['queue'] ?? []));
    if ($remaining > 0) plugin_json(['error' => "Traduction incomplète : {$remaining} texte(s) en attente. Continuez la génération."], 409);
    if (empty($state['map'])) plugin_json(['error' => 'Aucun texte traduit : relancez la génération.'], 422);
    $result = ml_finalize($code, $state);
    if (!$result['ok']) plugin_json(['error' => (string) ($result['error'] ?? 'Échec de l’écriture.')], 500);
    $row = ml_row($code);
    plugin_json([
        'ok' => true,
        'message' => 'Version générée' . (($row['status'] ?? '') === 'published' ? ' et publiée.' : '. Publiez-la pour la rendre visible.'),
        'strings' => (int) $result['strings'],
        'chars' => (int) $result['chars'],
        'status' => (string) ($row['status'] ?? 'draft'),
    ]);
}

if ($action === 'publish' || $action === 'unpublish') {
    if (!is_file(ml_file($code))) plugin_json(['error' => 'Générez la version avant de la publier.'], 409);
    ml_row_status($code, $action === 'publish' ? 'published' : 'draft');
    plugin_json(['ok' => true, 'message' => $action === 'publish' ? 'Version publiée : le sélecteur de langue apparaît sur le site.' : 'Version dépubliée.']);
}

plugin_json(['error' => 'Action inconnue.'], 400);
