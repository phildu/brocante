<?php
// Campagnes de communication d'une opération : plan d'étapes, enregistrement, envoi immédiat, relance des échecs.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';

if (!aco_enabled()) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);

/** Résumé d'une opération pour l'en-tête de la page. */
function aco_campaign_op_row(array $op): array
{
    return ['id' => (int) $op['id'], 'name' => (string) $op['name'], 'kind_label' => aco_kind_label((string) $op['kind']),
        'period' => aco_period_label($op), 'status' => (string) $op['status'], 'starts_at' => (int) $op['starts_at'], 'ends_at' => (int) $op['ends_at'],
        'discount_label' => aco_discount_text($op)];
}

/** Lien vers la publication du pack Communication, seulement s'il est actif pour cette boutique. */
function aco_campaign_social_url(): string
{
    try {
        $m = plugin_manifest('communication');
        $row = plugin_states(db())['communication'] ?? null;
        if ($m && plugin_status($row, $m) === 'ready') return '/admin/plugin.php?' . http_build_query(['p' => 'communication', 'v' => 'publication']);
    } catch (Throwable $e) {
    }
    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    aco_tick(true);
    $id = (int) ($_GET['operation'] ?? 0);
    if ($id === 0) {
        $rows = [];
        foreach (aco_ops() as $op) {
            $n = db()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(status IN (\'done\', \'to_publish\')), 0) AS done FROM aco_campaign_steps WHERE operation_id = ?');
            $n->execute([(int) $op['id']]);
            $c = $n->fetch(PDO::FETCH_ASSOC);
            $rows[] = aco_campaign_op_row($op) + ['steps' => (int) $c['n'], 'steps_done' => (int) $c['done']];
        }
        plugin_json(['operations' => $rows, 'now' => time()]);
    }
    $op = aco_op($id);
    if (!$op) plugin_json(['error' => 'Opération introuvable.'], 404);
    plugin_json(['operation' => aco_campaign_op_row($op), 'steps' => aco_campaign_steps($op), 'now' => time(),
        'channel_labels' => aco_campaign_channel_labels(), 'status_labels' => ACO_STEP_STATUS, 'grace' => ACO_STEP_GRACE,
        'social_url' => aco_campaign_social_url(), 'batch' => ACO_NOTICE_BATCH,
        'fields' => ['{boutique}' => 'nom de la boutique', '{operation}' => "nom de l'opération", '{remise}' => 'remise (−30 %)',
            '{periode}' => 'dates', '{lieu}' => 'lieu précédé d\'un tiret', '{lien}' => 'adresse de la boutique', '{desinscription}' => 'lien de désinscription']]);
}

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
$op = aco_op((int) ($in['operation'] ?? 0));
if (!$op) plugin_json(['error' => 'Opération introuvable.'], 404);
$editable = in_array((string) $op['status'], ['scheduled', 'running'], true);

if ($action === 'generate') {
    if (!$editable) plugin_json(['error' => 'Cette opération est terminée ou annulée.'], 409);
    $n = aco_campaign_generate($op);
    if ($n === 0) plugin_json(['error' => 'Le plan standard ne se crée que pour une opération sans étape, et seulement pour les échéances à venir.'], 409);
    plugin_json(['ok' => true, 'message' => $n . ' étape(s) programmée(s). Relisez les messages : rien ne part avant leur échéance.']);
}

if ($action === 'save') {
    if (!$editable) plugin_json(['error' => 'Cette opération est terminée ou annulée.'], 409);
    $id = (int) ($in['id'] ?? 0);
    if ($id > 0) {
        $existing = aco_step($id);
        if (!$existing || (int) $existing['operation_id'] !== (int) $op['id']) plugin_json(['error' => 'Étape introuvable.'], 404);
        if (in_array($existing['status'], ['done', 'to_publish', 'sending'], true)) plugin_json(['error' => 'Cette étape est déjà partie : elle ne se modifie plus.'], 409);
    } else {
        $count = db()->prepare('SELECT COUNT(*) FROM aco_campaign_steps WHERE operation_id = ?');
        $count->execute([(int) $op['id']]);
        if ((int) $count->fetchColumn() >= ACO_STEP_MAX) plugin_json(['error' => 'Au plus ' . ACO_STEP_MAX . ' étapes par opération.'], 409);
    }
    [$step, $err] = aco_step_from_input($in, $op);
    if ($err) plugin_json(['error' => $err], 422);
    $newId = aco_step_save($op, $step, $id);
    plugin_json(['ok' => true, 'id' => $newId, 'message' => $id > 0 ? 'Étape enregistrée.' : 'Étape ajoutée.']);
}

$step = aco_step((int) ($in['id'] ?? 0));
if (!$step || (int) $step['operation_id'] !== (int) $op['id']) plugin_json(['error' => 'Étape introuvable.'], 404);

if ($action === 'delete') {
    if (!aco_step_delete($op, (int) $step['id'])) plugin_json(['error' => 'Étape introuvable.'], 404);
    plugin_json(['ok' => true, 'message' => 'Étape supprimée.']);
}
if ($action === 'toggle') {
    if (in_array($step['status'], ['done', 'to_publish', 'sending'], true)) plugin_json(['error' => 'Cette étape est déjà partie.'], 409);
    $enabled = empty($step['enabled']) ? 1 : 0;
    if ($enabled && aco_step_when($op, $step) <= time()) plugin_json(['error' => 'Cette échéance est déjà passée : modifiez-la, ou envoyez maintenant.'], 409);
    db()->prepare("UPDATE aco_campaign_steps SET enabled = ?, status = CASE WHEN status IN ('skipped', 'failed') THEN 'planned' ELSE status END, detail = '' WHERE id = ?")
        ->execute([$enabled, (int) $step['id']]);
    plugin_json(['ok' => true, 'message' => $enabled ? 'Étape activée.' : 'Étape mise en pause : elle ne partira pas.']);
}
if ($action === 'send' || $action === 'retry') {
    $result = $action === 'retry' ? aco_step_retry($op, $step) : aco_campaign_process($op, $step);
    if (isset($result['error'])) plugin_json($result, 409);
    $done = $result['message'] !== '' ? $result['message'] : 'Étape traitée.';
    plugin_json(['ok' => true, 'message' => $done]);
}
if ($action === 'published') {
    if ($step['status'] !== 'to_publish') plugin_json(['error' => "Cette étape n'attend pas de publication."], 409);
    aco_step_set((int) $step['id'], 'done', 'Publié sur les réseaux sociaux.');
    plugin_json(['ok' => true, 'message' => 'Étape marquée comme publiée.']);
}
plugin_json(['error' => 'Action invalide.'], 422);
