<?php
// Aide de l'IA du Studio vidéo : « script » écrit le texte et les plans, « plan » propose le montage des prises.
// L'IA ne reçoit que du texte (consignes, script, durées) : jamais les fichiers vidéo, qui restent dans le navigateur.
defined('PLUGIN_RUNTIME') || exit;

require_once dirname(__DIR__) . '/lib/stv.php';

@set_time_limit(120);

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
if (!in_array($action, ['script', 'plan'], true)) plugin_json(['error' => 'Action inconnue.'], 422);

$now = time();
$hits = array_values(array_filter((array) ($_SESSION['stv_ai_hits'] ?? []), static fn ($t): bool => (int) $t > $now - 600));
if (count($hits) >= 20) {
    plugin_json(['error' => "Trop de demandes à l'IA en peu de temps. Patientez quelques minutes."], 429);
}
$hits[] = $now;
$_SESSION['stv_ai_hits'] = $hits;

$project = stv_clean_project((array) ($in['project'] ?? []));
$shop = stv_shop_name();
$error = null;

if ($action === 'script') {
    $data = stv_ai_script($project, $shop, $error);
    if ($data === null) plugin_json(['error' => $error ?: "L'IA n'a pas répondu."], 502);
    plugin_json(['ok' => true, 'data' => $data]);
}

$shots = stv_norm_shots($in['shots'] ?? []);
$clips = [];
foreach ((array) ($in['clips'] ?? []) as $clip) {
    if (!is_array($clip) || count($clips) >= 40) continue;
    $id = (string) ($clip['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) continue;
    $clips[] = [
        'id' => $id,
        'shot' => max(0, min(99, (int) ($clip['shot'] ?? 0))),
        'seconds' => stv_float($clip['seconds'] ?? 0, 0.3, 900, 0.3),
        'label' => stv_line($clip['label'] ?? '', 80),
    ];
}
if (!$clips) plugin_json(['error' => 'Aucune prise enregistrée à monter.'], 422);

$data = stv_ai_plan($project, $shots, $clips, $shop, $error);
if ($data === null) plugin_json(['error' => $error ?: "L'IA n'a pas répondu."], 502);
plugin_json(['ok' => true, 'data' => $data]);
