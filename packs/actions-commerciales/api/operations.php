<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';

if (!aco_enabled()) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
aco_tick(true);

/** Champs reçus du formulaire → opération normalisée (sans identifiant). -> [opération, erreur]. */
function aco_from_input(array $in): array
{
    $kind = in_array($in['kind'] ?? '', array_keys(aco_kind_labels()), true) ? (string) $in['kind'] : 'braderie';
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80) ?: aco_kind_label($kind);
    $start = strtotime(trim((string) ($in['starts_at'] ?? '')));
    $end = strtotime(trim((string) ($in['ends_at'] ?? '')));
    if (!$start || !$end || $end <= $start) return [null, 'Dates invalides : la fin doit suivre le début.'];
    if ($end <= time()) return [null, 'La fin de l\'opération est déjà passée.'];
    $type = ($in['discount_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
    $rawValue = trim((string) ($in['discount_value'] ?? ''));
    if ($type === 'percent') {
        $value = (float) str_replace(',', '.', $rawValue);
        if ($value <= 0 || $value > ACO_MAX_PERCENT) return [null, 'La remise doit être comprise entre 0,1 et ' . ACO_MAX_PERCENT . ' %.'];
    } else {
        $cents = aco_money_cents($rawValue);
        if ($cents === null || $cents <= 0 || $cents > ACO_MAX_AMOUNT_CENTS) return [null, 'La remise en euros doit être comprise entre 0,01 et 10 000 €.'];
        $value = $cents / 100;
    }
    $round = (int) ($in['round_to'] ?? 0);
    if (!in_array($round, [0, 5, 10, 50, 100], true)) $round = 0;
    $min = aco_money_cents($in['min_price'] ?? '') ?? 0;
    $max = aco_money_cents($in['max_price'] ?? '') ?? 0;
    if ($max > 0 && $min > 0 && $max < $min) return [null, 'Le prix maximum doit être supérieur au prix minimum.'];
    $catsIn = $in['cats'] ?? [];
    $catsIn = is_array($catsIn) ? $catsIn : explode(',', (string) $catsIn);
    $known = array_column(category_list(), 'key');
    $cats = array_values(array_intersect($known, array_map('strval', $catsIn)));
    $refs = [];
    foreach ((array) ($in['refs'] ?? []) as $r) {
        foreach (explode(',', (string) $r) as $one) {
            $one = preg_replace('/[^A-Za-z0-9_-]/', '', trim($one));
            if ($one !== '') $refs[$one] = true;
        }
    }
    $label = mb_substr(trim((string) ($in['label'] ?? '')), 0, 30) ?: aco_kind_label($kind);
    $op = [
        'kind' => $kind,
        'name' => $name,
        'label' => $label,
        'starts_at' => $start,
        'ends_at' => $end,
        'discount_type' => $type,
        'discount_value' => $value,
        'round_to' => $round,
        'cats' => implode(',', $cats),
        'refs' => implode(',', array_keys($refs)),
        'min_cents' => $min,
        'max_cents' => $max,
        'min_age_days' => max(0, min(3650, (int) ($in['min_age_days'] ?? 0))),
        'respect_floor' => array_key_exists('respect_floor', $in) ? (!empty($in['respect_floor']) ? 1 : 0) : 1,
        'place' => mb_substr(trim((string) ($in['place'] ?? '')), 0, 120),
        'show_banner' => array_key_exists('show_banner', $in) ? (!empty($in['show_banner']) ? 1 : 0) : 1,
        'banner_text' => mb_substr(trim((string) ($in['banner_text'] ?? '')), 0, 160),
        'status' => 'scheduled',
    ];
    return [$op, null];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = [];
    foreach (aco_ops() as $op) {
        if ($op['status'] === 'scheduled') {
            $a = aco_analyze($op);
            $count = $a['discounted'];
            $before = $a['before_cents'];
            $after = $a['after_cents'];
        } else {
            $t = aco_totals($op);
            $count = $t['count'];
            $before = $t['old_cents'];
            $after = $t['new_cents'];
        }
        $rows[] = $op + [
            'kind_label' => aco_kind_label((string) $op['kind']),
            'period' => aco_period_label($op),
            'discount_label' => aco_discount_text($op),
            'count' => $count,
            'before_cents' => $before,
            'after_cents' => $after,
        ];
        $rows[array_key_last($rows)]['notices'] = aco_notice_stats($op);
    }
    plugin_json(['operations' => $rows, 'now' => time(), 'notice_sources' => aco_notice_sources(), 'notice_batch' => ACO_NOTICE_BATCH,
        'channel_labels' => aco_channel_labels()]);
}

$in = plugin_input();
$action = (string) ($in['action'] ?? '');

if ($action === 'preview') {
    [$op, $err] = aco_from_input($in);
    if ($err) plugin_json(['error' => $err], 422);
    $a = aco_analyze($op);
    $warnings = [];
    if ($op['kind'] === 'soldes' && (int) $op['ends_at'] - (int) $op['starts_at'] > 28 * 86400) {
        $warnings[] = 'Les soldes légaux durent au plus quatre semaines consécutives.';
    }
    if ($op['kind'] === 'soldes') $warnings[] = 'Le prix barré doit correspondre au prix pratiqué avant la période de soldes.';
    if ($a['eligible'] === 0) $warnings[] = 'Aucune pièce ne correspond : vérifiez les catégories, les références et les prix.';
    elseif ($a['discounted'] === 0) $warnings[] = 'Les pièces trouvées ne peuvent pas baisser (plancher atteint ou arrondi sans effet).';
    plugin_json(['ok' => true, 'preview' => $a, 'warnings' => $warnings, 'operation' => $op]);
}

if ($action === 'create') {
    [$op, $err] = aco_from_input($in);
    if ($err) plugin_json(['error' => $err], 422);
    $a = aco_analyze($op);
    if ($a['discounted'] === 0) plugin_json(['error' => 'Aucune pièce ne peut être réduite avec ces critères : prix fixe, en stock, au-dessus du prix plancher.'], 422);
    db()->prepare('INSERT INTO aco_operations (kind, name, label, starts_at, ends_at, discount_type, discount_value, round_to, cats, refs,
        min_cents, max_cents, min_age_days, respect_floor, place, show_banner, banner_text, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$op['kind'], $op['name'], $op['label'], $op['starts_at'], $op['ends_at'], $op['discount_type'], $op['discount_value'],
            $op['round_to'], $op['cats'], $op['refs'], $op['min_cents'], $op['max_cents'], $op['min_age_days'], $op['respect_floor'],
            $op['place'], $op['show_banner'], $op['banner_text'], $op['status']]);
    $id = (int) db()->lastInsertId();
    aco_tick(true);
    $now = aco_op($id);
    plugin_json(['ok' => true, 'id' => $id, 'message' => $now && $now['status'] === 'running'
        ? $a['discounted'] . ' pièce(s) passées au prix de l\'opération, en cours maintenant.'
        : 'Opération programmée : ' . $a['discounted'] . ' pièce(s) seront réduites au démarrage.'], 201);
}

$id = (int) ($in['id'] ?? 0);
if ($action === 'start') {
    $err = aco_start_now($id);
    if ($err) plugin_json(['error' => $err], 409);
    plugin_json(['ok' => true, 'message' => 'Opération démarrée : les prix réduits sont appliqués.']);
}
if ($action === 'notify') {
    $op = aco_op($id);
    if (!$op) plugin_json(['error' => 'Opération introuvable.'], 404);
    $channels = $in['channels'] ?? ['mail'];
    $channels = is_array($channels) ? array_map('strval', $channels) : ['mail'];
    $result = aco_notice_send($op, $channels);
    plugin_json($result, isset($result['error']) ? 409 : 200);
}
if ($action === 'stop') {
    if (!aco_stop($id)) plugin_json(['error' => 'Opération introuvable ou déjà arrêtée.'], 404);
    plugin_json(['ok' => true, 'message' => "Opération arrêtée : les prix d'origine sont rétablis."]);
}
if ($action === 'delete') {
    if (!aco_delete($id)) plugin_json(['error' => 'Seule une opération terminée ou annulée peut être supprimée.'], 409);
    plugin_json(['ok' => true, 'message' => 'Opération supprimée.']);
}
plugin_json(['error' => 'Action invalide.'], 422);
