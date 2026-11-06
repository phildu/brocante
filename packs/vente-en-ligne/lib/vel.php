<?php
// Pack « Vente en ligne » : règles et données d'exemple. Les commandes, comptes,
// transporteurs, tarifs et remises restent dans les tables du cœur.
defined('PLUGIN_RUNTIME') || exit;

/** Adresse d'une page du pack, avec ses paramètres d'affichage. */
function vel_page_url(array $plugin, string $page, array $params = []): string
{
    $url = plugin_page_url($plugin['id'], $page);
    return $params ? $url . '&' . http_build_query($params) : $url;
}

/** E-mail normalisé : clé de rapprochement entre commandes, comptes et notes. */
function vel_email(string $email): string
{
    return strtolower(trim($email));
}

function vel_order(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Poids en grammes → « 1,5 » (kg), sans zéros inutiles. */
function vel_weight(int $grams): string
{
    return rtrim(rtrim(number_format($grams / 1000, 3, ',', ''), '0'), ',');
}

function vel_events_add(int $orderId, string $type, string $message): void
{
    $stmt = db()->prepare('INSERT INTO vel_events (order_id, type, message) VALUES (?, ?, ?)');
    $stmt->execute([$orderId, $type, mb_substr(trim($message), 0, 500)]);
}

/** Événements des commandes affichées, groupés par commande (du plus récent au plus ancien). */
function vel_order_events(array $orderIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM vel_events WHERE order_id IN ($in) ORDER BY id DESC");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $event) {
        $out[(int) $event['order_id']][] = $event;
    }
    return $out;
}

/** Journal récent, avec l'e-mail de la commande concernée. */
function vel_journal(int $limit = 60): array
{
    $stmt = db()->prepare('SELECT e.*, o.email FROM vel_events e LEFT JOIN orders o ON o.id = e.order_id ORDER BY e.id DESC LIMIT ?');
    $stmt->execute([max(1, min(300, $limit))]);
    return $stmt->fetchAll();
}

/** Notes clients : e-mail normalisé => ligne vel_notes. */
function vel_note_map(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM vel_notes') as $row) {
        $out[vel_email((string) $row['email'])] = $row;
    }
    return $out;
}

function vel_note_save(string $email, string $note): void
{
    $stmt = db()->prepare('INSERT INTO vel_notes (email, note, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(email) DO UPDATE SET note = excluded.note, updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([vel_email($email), mb_substr(trim($note), 0, 2000)]);
}

/** Type d'événement → petit libellé affiché dans le journal. */
function vel_event_label(string $type): string
{
    return ['paiement' => 'Paiement', 'statut' => 'Suivi', 'suivi' => 'N° de suivi', 'note' => 'Note'][$type] ?? ucfirst($type);
}

/**
 * Jeu de tarifs d'exemple pour un transporteur : ['name' => …, 'rates' => [[service, min g, max g, centimes, délai, zone], …]].
 * Les grilles sont indicatives (grand public) : elles se corrigent ensuite dans l'écran Frais de port.
 */
function vel_seed_plan(string $key): ?array
{
    $metropole = [
        ['Chrono 13 — 0 à 1 kg', 0, 1000, 2340, '13h le lendemain'],
        ['Chrono 13 — 1 à 3 kg', 1000, 3000, 2520, '13h le lendemain'],
        ['Chrono 13 — 3 à 10 kg', 3000, 10000, 2880, '13h le lendemain'],
        ['Chrono 13 — 10 à 20 kg', 10000, 20000, 4080, '13h le lendemain'],
        ['Chrono 13 — 20 à 30 kg', 20000, 30000, 5400, '13h le lendemain'],
    ];
    $corseSupplement = 3072;

    switch ($key) {
        case 'chronopost':
            $rates = [];
            foreach ($metropole as [$service, $min, $max, $cents, $delay]) {
                $rates[] = [$service, $min, $max, $cents, $delay, 'metropole'];
                $rates[] = [str_replace('Chrono 13', 'Chrono 13 (Corse)', $service), $min, $max, $cents + $corseSupplement, $delay, 'corse'];
            }
            foreach ([
                ['Chrono Express Outre-mer — 0 à 1 kg', 0, 1000, 5800],
                ['Chrono Express Outre-mer — 1 à 3 kg', 1000, 3000, 8800],
                ['Chrono Express Outre-mer — 3 à 10 kg', 3000, 10000, 15500],
                ['Chrono Express Outre-mer — 10 à 20 kg', 10000, 20000, 28000],
                ['Chrono Express Outre-mer — 20 à 30 kg', 20000, 30000, 49600],
            ] as [$service, $min, $max, $cents]) {
                $rates[] = [$service, $min, $max, $cents, '1 à 5 jours', 'dom_tom'];
            }
            return ['name' => 'Chronopost', 'rates' => $rates];

        case 'colissimo':
            $rates = [];
            $metropoleColissimo = [
                ['Colissimo Domicile — jusqu\'à 250 g', 0, 250, 549, '2 jours'],
                ['Colissimo Domicile — 250 à 500 g', 250, 500, 759, '2 jours'],
                ['Colissimo Domicile — 500 à 750 g', 500, 750, 929, '2 jours'],
                ['Colissimo Domicile — 750 g à 1 kg', 750, 1000, 959, '2 jours'],
                ['Colissimo Domicile — 1 à 2 kg', 1000, 2000, 1119, '2 jours'],
                ['Colissimo Domicile — 2 à 5 kg', 2000, 5000, 1739, '2 jours'],
                ['Colissimo Domicile — 5 à 10 kg', 5000, 10000, 2529, '2 jours'],
                ['Colissimo Domicile — 10 à 15 kg', 10000, 15000, 3199, '2 jours'],
                ['Colissimo Domicile — 15 à 30 kg', 15000, 30000, 3959, '2 jours'],
            ];
            foreach ($metropoleColissimo as [$service, $min, $max, $cents, $delay]) {
                $rates[] = [$service, $min, $max, $cents, $delay, 'metropole'];
                $rates[] = [$service, $min, $max, $cents, $delay, 'corse'];
            }
            foreach ([
                ['Colissimo Outre-mer — jusqu\'à 500 g', 0, 500, 1202],
                ['Colissimo Outre-mer — 500 g à 1 kg', 500, 1000, 1900],
                ['Colissimo Outre-mer — 1 à 5 kg', 1000, 5000, 3890],
                ['Colissimo Outre-mer — 5 à 30 kg', 5000, 30000, 14302],
            ] as [$service, $min, $max, $cents]) {
                $rates[] = [$service, $min, $max, $cents, '6 à 18 jours', 'dom_tom'];
            }
            return ['name' => 'Colissimo', 'rates' => $rates];

        case 'colissimo_intl':
            $rates = [];
            $zones = [
                'intl_a' => [
                    ['zone A', 0, 500, 1499], ['zone A', 500, 1000, 1939], ['zone A', 1000, 2000, 2219],
                    ['zone A', 2000, 5000, 2859], ['zone A', 5000, 10000, 4699], ['zone A', 10000, 15000, 6799], ['zone A', 15000, 20000, 8799],
                ],
                'intl_b' => [
                    ['zone B', 0, 500, 2379], ['zone B', 500, 1000, 2839], ['zone B', 1000, 2000, 3109],
                    ['zone B', 2000, 5000, 3989], ['zone B', 5000, 10000, 6609], ['zone B', 10000, 15000, 8959], ['zone B', 15000, 20000, 10949],
                ],
                'intl_c' => [
                    ['zone C', 0, 500, 3519], ['zone C', 500, 1000, 3919], ['zone C', 1000, 2000, 5399],
                    ['zone C', 2000, 5000, 7869], ['zone C', 5000, 10000, 14899], ['zone C', 10000, 15000, 21079], ['zone C', 15000, 20000, 25689],
                ],
            ];
            foreach ($zones as $zone => $rows) {
                foreach ($rows as [$label, $min, $max, $cents]) {
                    $suffix = $min === 0 ? 'jusqu\'à ' . vel_weight($max) . ' kg' : vel_weight($min) . ' à ' . vel_weight($max) . ' kg';
                    $rates[] = ['Colissimo International ' . $label . ' — ' . $suffix, $min, $max, $cents, '3 à 8 jours', $zone];
                }
            }
            return ['name' => 'Colissimo', 'rates' => $rates];

        case 'mondial_relay':
            $rates = [];
            foreach ([
                ['jusqu\'à 500 g', 0, 500, 450],
                ['500 g à 1 kg', 500, 1000, 490],
                ['1 à 2 kg', 1000, 2000, 590],
                ['2 à 3 kg', 2000, 3000, 690],
                ['3 à 5 kg', 3000, 5000, 850],
                ['5 à 7 kg', 5000, 7000, 1050],
                ['7 à 10 kg', 7000, 10000, 1250],
                ['10 à 15 kg', 10000, 15000, 1590],
                ['15 à 20 kg', 15000, 20000, 1890],
                ['20 à 30 kg', 20000, 30000, 2490],
            ] as [$suffix, $min, $max, $cents]) {
                $rates[] = ['Mondial Relay Point Relais — ' . $suffix, $min, $max, $cents, '3 à 5 jours ouvrés', 'metropole'];
            }
            return ['name' => 'Mondial Relay', 'rates' => $rates];
    }
    return null;
}
