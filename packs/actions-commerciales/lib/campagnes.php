<?php
// Campagnes de communication liées aux opérations : un plan d'étapes programmées (annonce, lancement, dernier jour…)
// ancrées au début ou à la fin de l'opération. Chaque étape part sur un ou plusieurs canaux (e-mail, annonce dans l'app,
// SMS, WhatsApp) vers les mêmes contacts consentants que l'annonce simple ; « réseaux sociaux » ne publie rien tout seul :
// l'étape devient « à publier » avec son texte prêt à copier. Rien ne part pour une opération terminée ou annulée.
defined('PLUGIN_RUNTIME') || exit;

const ACO_STEP_GRACE = 6 * 3600;     // un créneau manqué de plus de 6 h n'est pas envoyé en retard : l'étape est sautée
const ACO_STEP_CLAIM = 120;          // secondes pendant lesquelles une étape reste verrouillée par une exécution
const ACO_STEP_MAX = 12;             // étapes par opération
const ACO_STEP_STATUS = ['planned' => 'programmée', 'sending' => 'envoi en cours', 'to_publish' => 'à publier', 'done' => 'envoyée',
    'skipped' => 'sautée', 'failed' => 'échec'];

function aco_campaign_channel_labels(): array
{
    return aco_channel_labels() + ['social' => 'Réseaux sociaux (à publier)'];
}

/** Étape par identifiant. */
function aco_step(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM aco_campaign_steps WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Moment prévu de l'étape (horodatage), d'après le début ou la fin de l'opération. */
function aco_step_when(array $op, array $step): int
{
    $base = $step['anchor'] === 'end' ? (int) $op['ends_at'] : (int) $op['starts_at'];
    return $base + (int) $step['offset_min'] * 60;
}

/** Valeurs des champs {…} utilisables dans les textes d'une étape. */
function aco_step_vars(array $op, string $shopUrl, string $stopUrl): array
{
    $site = (string) (get_content()['site_name'] ?: tenant('name'));
    $place = trim((string) $op['place']);
    return [
        '{boutique}' => $site,
        '{operation}' => trim((string) $op['label']) ?: aco_kind_label((string) $op['kind']),
        '{remise}' => aco_discount_text($op),
        '{periode}' => aco_period_short($op),
        '{lieu}' => $place !== '' ? ' — ' . $place : '',
        '{lien}' => $shopUrl,
        '{desinscription}' => $stopUrl,
    ];
}

/** Objet et texte d'une étape pour une adresse : champs remplacés, lien de désinscription ajouté. */
function aco_step_texts(array $op, array $step, string $shopUrl, string $stopUrl): array
{
    $vars = aco_step_vars($op, $shopUrl, $stopUrl);
    $subject = trim(strtr((string) $step['subject'], $vars)) ?: trim($vars['{operation}'] . ' ' . $vars['{periode}'] . ' — ' . $vars['{boutique}']);
    $body = rtrim(strtr((string) $step['body'], $vars));
    if (strpos((string) $step['body'], '{desinscription}') === false) {
        $body .= "\n\nVous recevez ce message parce que vous suivez cette boutique. Pour ne plus recevoir ses annonces : " . $stopUrl;
    }
    return [$subject, $body];
}

/** Texte court (SMS, WhatsApp) : début du message, lien de la boutique et désinscription. */
function aco_step_short(array $op, array $step, string $stopUrl): string
{
    $vars = aco_step_vars($op, shop_abs_url('/boutique.php'), $stopUrl);
    $text = trim(preg_replace('/\s+/', ' ', strtr((string) $step['body'], $vars + ['{desinscription}' => ''])));
    $tail = ' ' . $vars['{lien}'] . ' Désinscription : ' . $stopUrl;
    return mb_substr($text, 0, max(40, 480 - mb_strlen($tail))) . $tail;
}

/** Plan standard d'une opération : seules les étapes encore à venir sont proposées. */
function aco_step_defaults(array $op): array
{
    $now = time();
    $minutes = max(1, intdiv((int) $op['ends_at'] - (int) $op['starts_at'], 60));
    $last = -min(1440, max(30, intdiv($minutes, 2)));
    $plan = [
        ['title' => 'Annonce', 'anchor' => 'start', 'offset_min' => -3 * 1440, 'channels' => 'mail,inapp,social',
            'subject' => 'À noter : {operation} {periode} — {boutique}',
            'body' => "Bonjour,\n\n{boutique} prépare {operation} {periode}{lieu}.\n{remise} sur une sélection de pièces.\n\nNotez la date, on vous y attend : {lien}\n\nÀ bientôt,\n{boutique}"],
        ['title' => 'Lancement', 'anchor' => 'start', 'offset_min' => 0, 'channels' => 'mail,inapp,social',
            'subject' => "C'est parti : {operation} chez {boutique}",
            'body' => "Bonjour,\n\n{operation} commence maintenant{lieu} : {remise} sur une sélection de pièces.\n\nVoir les pièces : {lien}\n\nÀ tout de suite,\n{boutique}"],
        ['title' => 'Dernier jour', 'anchor' => 'end', 'offset_min' => $last, 'channels' => 'mail,inapp,social',
            'subject' => 'Dernière chance : {operation} se termine bientôt — {boutique}',
            'body' => "Bonjour,\n\n{operation} touche à sa fin : {remise} sur une sélection de pièces, encore un peu de temps pour en profiter.\n\nVoir les pièces : {lien}\n\nÀ bientôt,\n{boutique}"],
    ];
    return array_values(array_filter($plan, static fn (array $s): bool => aco_step_when($op, $s) > $now + 300));
}

/** Étapes d'une opération dans l'ordre d'envoi, avec l'échéance et les chiffres d'envoi. */
function aco_campaign_steps(array $op): array
{
    $st = db()->prepare('SELECT * FROM aco_campaign_steps WHERE operation_id = ?');
    $st->execute([(int) $op['id']]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $step) {
        $step['when'] = aco_step_when($op, $step);
        $step['channel_list'] = array_values(array_filter(explode(',', (string) $step['channels'])));
        $step['stats'] = aco_step_stats($op, $step);
        [$subject, $body] = aco_step_texts($op, $step, shop_abs_url('/boutique.php'), shop_abs_url('/api/braderie/stop/…'));
        $step['preview_subject'] = $subject;
        $step['preview_body'] = $body;
        $rows[] = $step;
    }
    usort($rows, static fn (array $a, array $b): int => [$a['when'], $a['id']] <=> [$b['when'], $b['id']]);
    return $rows;
}

/** Envoyés, refusés, restants par canal, et ouvertures des e-mails de l'étape. */
function aco_step_stats(array $op, array $step): array
{
    $by = [];
    $st = db()->prepare('SELECT channel, status, COUNT(*) AS n FROM aco_campaign_sends WHERE step_id = ? GROUP BY channel, status');
    $st->execute([(int) $step['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['channel']][$r['status']] = (int) $r['n'];
    $channels = [];
    $sent = 0;
    $failed = 0;
    foreach (array_intersect(ACO_CHANNELS, explode(',', (string) $step['channels'])) as $channel) {
        $s = $by[$channel]['sent'] ?? 0;
        $f = $by[$channel]['failed'] ?? 0;
        $channels[$channel] = ['sent' => $s, 'failed' => $f, 'ready' => aco_channel_ready($channel),
            'eligible' => in_array((string) $op['status'], ['scheduled', 'running'], true) ? aco_notice_audience($op, $channel, (int) $step['id'])['pending_count'] : 0];
        $sent += $s;
        $failed += $f;
    }
    $opens = ['opened' => 0, 'opens' => 0];
    if (in_array('mail', explode(',', (string) $step['channels']), true)) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/mail-tracking.php';
            $opens = mail_open_context_summary('aco:' . (int) $op['id'] . ':s' . (int) $step['id']);
        } catch (Throwable $e) {
        }
    }
    return ['sent' => $sent, 'failed' => $failed, 'channels' => $channels, 'opened' => (int) $opens['opened']];
}

/** Champs reçus → étape normalisée. -> [étape, erreur]. */
function aco_step_from_input(array $in, array $op): array
{
    $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 60);
    if ($title === '') return [null, "Donnez un nom à l'étape."];
    $anchor = ($in['anchor'] ?? 'start') === 'end' ? 'end' : 'start';
    $channelsIn = $in['channels'] ?? [];
    $channelsIn = is_array($channelsIn) ? $channelsIn : explode(',', (string) $channelsIn);
    $channels = array_values(array_intersect(array_merge(ACO_CHANNELS, ['social']), array_map('strval', $channelsIn)));
    if (!$channels) return [null, 'Choisissez au moins un canal.'];
    // Décalage saisi en jours et heures, « avant » ou « après » l'ancre.
    $minutes = ((int) ($in['days'] ?? 0) * 1440 + (int) ($in['hours'] ?? 0) * 60 + (int) ($in['minutes'] ?? 0));
    $minutes = max(0, min(60 * 1440, $minutes));
    $offset = ($in['direction'] ?? 'before') === 'after' ? $minutes : -$minutes;
    $body = trim(str_replace("\r", '', (string) ($in['body'] ?? '')));
    if ($body === '') return [null, 'Le message ne peut pas être vide.'];
    $step = [
        'title' => $title, 'anchor' => $anchor, 'offset_min' => $offset, 'channels' => implode(',', $channels),
        'subject' => mb_substr(trim((string) ($in['subject'] ?? '')), 0, 150), 'body' => mb_substr($body, 0, 3000),
        'enabled' => array_key_exists('enabled', $in) ? (!empty($in['enabled']) ? 1 : 0) : 1,
    ];
    if (aco_step_when($op, $step) <= time() && $step['enabled']) {
        return [null, "Cette échéance est déjà passée : choisissez un moment à venir, ou utilisez « Envoyer maintenant » sur l'étape."];
    }
    return [$step, null];
}

/** Crée ou modifie une étape (modifiée : elle redevient programmée si elle n'est pas déjà partie). */
function aco_step_save(array $op, array $step, int $id = 0): int
{
    if ($id > 0) {
        db()->prepare('UPDATE aco_campaign_steps SET title = ?, anchor = ?, offset_min = ?, channels = ?, subject = ?, body = ?, enabled = ?,
            status = CASE WHEN status IN (\'skipped\', \'failed\') THEN \'planned\' ELSE status END, detail = \'\' WHERE id = ? AND operation_id = ?')
            ->execute([$step['title'], $step['anchor'], $step['offset_min'], $step['channels'], $step['subject'], $step['body'], $step['enabled'], $id, (int) $op['id']]);
        return $id;
    }
    db()->prepare('INSERT INTO aco_campaign_steps (operation_id, title, anchor, offset_min, channels, subject, body, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int) $op['id'], $step['title'], $step['anchor'], $step['offset_min'], $step['channels'], $step['subject'], $step['body'], $step['enabled']]);
    return (int) db()->lastInsertId();
}

/** Supprime une étape et son journal d'envoi. */
function aco_step_delete(array $op, int $id): bool
{
    $step = aco_step($id);
    if (!$step || (int) $step['operation_id'] !== (int) $op['id']) return false;
    db()->prepare('DELETE FROM aco_campaign_sends WHERE step_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM aco_campaign_steps WHERE id = ?')->execute([$id]);
    return true;
}

/** Installe le plan standard (seulement si l'opération n'a encore aucune étape). -> nombre d'étapes créées. */
function aco_campaign_generate(array $op): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM aco_campaign_steps WHERE operation_id = ?');
    $st->execute([(int) $op['id']]);
    if ((int) $st->fetchColumn() > 0) return 0;
    $n = 0;
    foreach (aco_step_defaults($op) as $step) {
        $step['enabled'] = 1;
        aco_step_save($op, $step);
        $n++;
    }
    return $n;
}

function aco_step_set(int $id, string $status, string $detail = ''): void
{
    $done = in_array($status, ['done', 'to_publish'], true) ? time() : null;
    db()->prepare('UPDATE aco_campaign_steps SET status = ?, detail = ?, done_at = COALESCE(?, done_at) WHERE id = ?')->execute([$status, mb_substr($detail, 0, 300), $done, $id]);
}

/**
 * Exécute une étape : verrou, envoi par lots sur ses canaux, nouvel état. Une même étape ne tourne jamais deux fois en
 * parallèle (deux visites, ou une visite et la tâche planifiée). -> résultat de l'envoi, ou ['error' => …].
 */
function aco_campaign_process(array $op, array $step): array
{
    $now = time();
    $claim = db()->prepare('UPDATE aco_campaign_steps SET claimed_at = ? WHERE id = ? AND claimed_at < ?');
    $claim->execute([$now, (int) $step['id'], $now - ACO_STEP_CLAIM]);
    if ($claim->rowCount() === 0) return ['error' => 'Cette étape est déjà en cours d\'envoi.'];
    try {
        if (!in_array((string) $op['status'], ['scheduled', 'running'], true)) {
            aco_step_set((int) $step['id'], 'skipped', 'Opération terminée ou annulée.');
            return ['error' => 'Opération terminée ou annulée : rien n\'est envoyé.'];
        }
        $wanted = array_values(array_filter(explode(',', (string) $step['channels'])));
        $social = in_array('social', $wanted, true);
        $channels = array_values(array_intersect(ACO_CHANNELS, $wanted));
        $result = ['ok' => true, 'sent' => 0, 'failed' => 0, 'remaining' => 0, 'message' => ''];
        if ($channels) {
            $result = aco_notice_send($op, $channels, $step);
            if (isset($result['error'])) {
                aco_step_set((int) $step['id'], 'failed', (string) $result['error']);
                return $result;
            }
        }
        if ((int) $result['remaining'] > 0) {
            aco_step_set((int) $step['id'], 'sending', (string) $result['message']);
        } else {
            $note = (string) $result['message'] . ($social ? ($result['message'] !== '' ? ' ' : '') . 'À publier sur les réseaux sociaux.' : '');
            aco_step_set((int) $step['id'], $social ? 'to_publish' : 'done', $note);
        }
        return $result;
    } catch (Throwable $e) {
        error_log('Actions commerciales (campagne) : ' . $e->getMessage());
        aco_step_set((int) $step['id'], 'failed', 'Erreur pendant l\'envoi.');
        return ['error' => 'Erreur pendant l\'envoi.'];
    } finally {
        db()->prepare('UPDATE aco_campaign_steps SET claimed_at = 0 WHERE id = ?')->execute([(int) $step['id']]);
    }
}

/** Relance les envois refusés d'une étape (adresses retirées du journal, puis nouvel envoi). */
function aco_step_retry(array $op, array $step): array
{
    db()->prepare("DELETE FROM aco_campaign_sends WHERE step_id = ? AND status = 'failed'")->execute([(int) $step['id']]);
    return aco_campaign_process($op, aco_step((int) $step['id']) ?: $step);
}

/**
 * Fait avancer les campagnes : envoie les étapes dont l'heure est venue (appelé par les visites et par la tâche planifiée).
 * Une étape manquée de plus de ACO_STEP_GRACE, ou dont l'opération est terminée ou annulée, est sautée sans rien envoyer.
 */
function aco_campaign_run(): void
{
    if (!aco_table_exists('aco_campaign_steps')) return;
    $now = time();
    $rows = db()->query("SELECT * FROM aco_campaign_steps WHERE enabled = 1 AND status IN ('planned', 'sending') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $step) {
        $op = aco_op((int) $step['operation_id']);
        if (!$op) continue;
        if (!in_array((string) $op['status'], ['scheduled', 'running'], true)) {
            aco_step_set((int) $step['id'], 'skipped', 'Opération terminée ou annulée.');
            continue;
        }
        $when = aco_step_when($op, $step);
        if ($when > $now) continue;
        if ($step['status'] === 'planned' && $now - $when > ACO_STEP_GRACE) {
            aco_step_set((int) $step['id'], 'skipped', 'Créneau manqué : plus de 6 h de retard, rien n\'a été envoyé.');
            continue;
        }
        aco_campaign_process($op, $step);
    }
}
