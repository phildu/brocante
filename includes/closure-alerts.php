<?php

// Alerte de fermeture : prévenir les clients et visiteurs référencés d'une fermeture exceptionnelle, d'un jour férié ou de congés.
//
// Les fermetures se saisissent dans Administration → Pages → Horaires & fermetures (includes/shop-hours.php) et s'affichent déjà en bandeau
// sur le site. Ce fichier ajoute l'envoi aux personnes référencées, par le module Notifications (includes/notifications.php) : chacune est
// prévenue sur son canal préféré (e-mail, SMS, WhatsApp…) et peut se retirer de la rubrique « Fermetures exceptionnelles » depuis sa page de
// préférences. L'envoi est TOUJOURS décidé par le vendeur (page de confirmation) : jamais automatique à l'enregistrement.
//
// Public : les clients ayant commandé, les inscrits à la lettre d'information et les contacts ajoutés à la main. Les bénévoles de la buvette
// n'en font jamais partie. Les contacts sont créés dans le module au moment de l'envoi, sans abonnement marketing.
// Journal des alertes déjà envoyées : réglage « opening_closure_alerts » (JSON, une entrée par période Du/Au).

require_once __DIR__ . '/shop-hours.php';
require_once __DIR__ . '/notifications.php';

const CLOSURE_ALERT_TOPIC = 'fermeture';
const CLOSURE_ALERT_GROUPS = [
    'commande' => 'Clients ayant commandé',
    'newsletter' => 'Inscrits à la lettre d’information',
    'manuel' => 'Contacts ajoutés à la main',
];

/** Identifiant stable d'une période de fermeture (dates Du/Au) : il survit à un changement de type ou de motif. */
function closure_alert_key(array $closure): string
{
    return substr(sha1((string) ($closure['from'] ?? '') . '|' . (string) ($closure['to'] ?? '')), 0, 12);
}

function closure_alert_find(string $key): ?array
{
    foreach (shop_hours_closures() as $closure) {
        if (closure_alert_key($closure) === $key) return $closure;
    }
    return null;
}

/** Une fermeture terminée n'a plus à être annoncée. */
function closure_alert_is_upcoming(array $closure, ?DateTimeImmutable $now = null): bool
{
    $now ??= shop_hours_now();
    return (string) $closure['to'] >= $now->format('Y-m-d');
}

/** Alertes déjà envoyées : clé de période => ['at', 'by', 'recipients', 'queued', 'message_id']. */
function closure_alert_log(): array
{
    $data = json_decode((string) shop_setting('opening_closure_alerts', ''), true);
    return is_array($data) ? $data : [];
}

function closure_alert_log_add(string $key, array $entry): void
{
    $log = closure_alert_log();
    $log[$key] = $entry;
    if (count($log) > 60) {
        uasort($log, static fn ($a, $b) => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0));
        $log = array_slice($log, 0, 60, true);
    }
    shop_setting_set('opening_closure_alerts', json_encode($log, JSON_UNESCAPED_UNICODE));
}

/** Périodes à venir, absentes de $knownKeys (clés d'avant l'enregistrement) et jamais annoncées aux clients. */
function closure_alert_new_pending(array $knownKeys, ?DateTimeImmutable $now = null): array
{
    $log = closure_alert_log();
    $out = [];
    foreach (shop_hours_closures() as $closure) {
        $key = closure_alert_key($closure);
        if (in_array($key, $knownKeys, true) || isset($log[$key]) || !closure_alert_is_upcoming($closure, $now)) continue;
        $out[$key] = $closure;
    }
    return $out;
}

/** « le mardi 3 novembre » ou « du lundi 3 au samedi 8 novembre ». */
function closure_alert_range_text(array $closure): string
{
    $tz = shop_hours_timezone();
    $from = new DateTimeImmutable($closure['from'] . ' 00:00', $tz);
    $to = new DateTimeImmutable($closure['to'] . ' 00:00', $tz);
    if ($closure['from'] === $closure['to']) return 'le ' . shop_hours_date_label($from);
    return 'du ' . shop_hours_date_label($from) . ' au ' . shop_hours_date_label($to);
}

/**
 * Message proposé au vendeur (modifiable avant l'envoi), avec les variables du module Notifications : {prenom} {site} {lien_preferences}.
 * @return array{subject: string, body: string, short_body: string}
 */
function closure_alert_message(array $closure): array
{
    $type = SHOP_HOURS_CLOSURE_TYPES[$closure['type']] ?? 'Fermeture exceptionnelle';
    $range = closure_alert_range_text($closure);
    $label = trim((string) $closure['label']);
    $reopen = '';
    $next = shop_hours_next_open(new DateTimeImmutable($closure['to'] . ' 23:59', shop_hours_timezone()));
    if ($next) $reopen = 'réouverture ' . shop_hours_date_label($next['date']) . ' à ' . shop_hours_format_time($next['time']);

    $body = "Bonjour {prenom},\n\n{site} sera fermé " . $range . ($label !== '' ? ' (' . $label . ')' : '') . '.'
        . ($reopen !== '' ? "\nNous vous accueillons de nouveau : " . $reopen . '.' : '')
        . "\n\nMerci de votre compréhension, et à très bientôt.\n{site}"
        . "\n\n—\nPour gérer vos notifications ou vous désinscrire : {lien_preferences}";
    $short = '{site} : fermé ' . $range . ($label !== '' ? ' (' . $label . ')' : '') . '.' . ($reopen !== '' ? ' ' . ucfirst($reopen) . '.' : '');
    return ['subject' => $type . ' — {site}', 'body' => $body, 'short_body' => $short];
}

function closure_alert_ensure_topic(): void
{
    nt_boot();
    if (!isset(nt_topics()[CLOSURE_ALERT_TOPIC])) {
        nt_topic_save(CLOSURE_ALERT_TOPIC, 'Fermetures exceptionnelles', 'Fermetures exceptionnelles, jours fériés et congés du commerce.', false);
    }
}

/**
 * Public estimé SANS rien écrire : contacts déjà dans le module et adresses qui y seraient créées à l'envoi.
 * @return array<string, array{label: string, existing: int, to_import: int}>
 */
function closure_alert_audience(): array
{
    nt_boot();
    $out = [];
    foreach (CLOSURE_ALERT_GROUPS as $key => $label) $out[$key] = ['label' => $label, 'existing' => 0, 'to_import' => 0];
    foreach (nt_users_load(null) as $u) {
        if ($u['is_active'] && isset($out[$u['source']])) $out[$u['source']]['existing']++;
    }
    $queries = [
        'commande' => "SELECT DISTINCT lower(trim(email)) AS email FROM orders WHERE status = 'paid' AND email IS NOT NULL AND trim(email) <> ''",
        'newsletter' => "SELECT DISTINCT lower(trim(email)) AS email FROM accounts WHERE source = 'newsletter' AND is_active = 1 AND role IN ('prospect', 'client') AND trim(email) <> ''",
    ];
    $seen = [];
    foreach ($queries as $group => $sql) {
        $table = $group === 'commande' ? 'orders' : 'accounts';
        if (!nt_table_exists($table)) continue;
        foreach (db()->query($sql) as $row) {
            $email = nt_normalize_address('email', (string) $row['email']);
            if (!$email || isset($seen[$email]) || nt_user_find_by_address('email', $email)) continue;
            $seen[$email] = true;
            $out[$group]['to_import']++;
        }
    }
    return $out;
}

/**
 * Envoie l'alerte d'une période de fermeture au public choisi. Refuse un second envoi sans $force.
 * @return array{error: ?string, recipients: int, queued: int, imported: int, message_id: ?int, processed: ?array, none: int}
 */
function closure_alert_send(string $key, array $groups, string $subject, string $body, string $short, string $actor = '', bool $force = false): array
{
    $res = ['error' => null, 'recipients' => 0, 'queued' => 0, 'imported' => 0, 'message_id' => null, 'processed' => null, 'none' => 0];
    $closure = closure_alert_find($key);
    if (!$closure) return ['error' => 'Fermeture introuvable : enregistrez-la d’abord dans les horaires.'] + $res;
    if (!closure_alert_is_upcoming($closure)) return ['error' => 'Cette fermeture est terminée : il n’y a plus rien à annoncer.'] + $res;
    $groups = array_values(array_intersect($groups, array_keys(CLOSURE_ALERT_GROUPS)));
    if (!$groups) return ['error' => 'Choisissez au moins un public à prévenir.'] + $res;
    if (trim($body) === '') return ['error' => 'Le message est vide.'] + $res;
    $prev = closure_alert_log()[$key] ?? null;
    if ($prev && !$force) return ['error' => 'Les clients ont déjà été prévenus le ' . date('d/m/Y', (int) $prev['at']) . ' pour cette fermeture.'] + $res;

    nt_boot();
    closure_alert_ensure_topic();
    if (in_array('commande', $groups, true)) $res['imported'] += nt_import_customers()['created'];
    if (in_array('newsletter', $groups, true)) $res['imported'] += nt_import_newsletter()['created'];
    $ids = [];
    foreach (nt_users_load(null) as $id => $u) {
        if ($u['is_active'] && in_array($u['source'], $groups, true)) $ids[] = $id;
    }
    if (!$ids) return ['error' => 'Aucun contact à prévenir : la liste des clients et inscrits est vide.'] + $res;

    $r = nt_notify(CLOSURE_ALERT_TOPIC, $ids, $subject, $body, ['short_body' => $short, 'strategy' => 'fallback', 'created_by' => 'fermeture']);
    $res = array_merge($res, array_intersect_key($r, $res));
    $res['error'] = $r['error'] ?? null;
    if ($res['error']) return $res;
    if ($res['queued'] === 0) {
        $res['error'] = 'Personne à prévenir : aucun contact n’a de canal actif, ou tous se sont retirés de cette rubrique. Vérifiez les canaux dans Notifications.';
        return $res;
    }
    closure_alert_log_add($key, ['at' => time(), 'by' => mb_substr($actor, 0, 120), 'recipients' => $res['recipients'], 'queued' => $res['queued'],
        'message_id' => $res['message_id'], 'groups' => $groups]);
    return $res;
}
