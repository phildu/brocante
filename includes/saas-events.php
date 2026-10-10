<?php

// Suivi de l'activité de la plateforme (portail → Activité) : un journal d'événements et des chiffres calculés.
//
// Journal : data/saas/events.jsonl, une ligne JSON par événement (ajout seul). Ce qui s'est passé AVANT la mise en place du journal est reconstitué
// à la lecture (inscriptions, paiements, créations d'après les demandes ; suppressions d'après data/corbeille/) : ces lignes sont marquées
// « reconstitué ». Les événements qu'on ne peut pas reconstituer (résiliations, renouvellements, réglages, créations et suppressions manuelles)
// n'existent que depuis le journal.
//
// Types : clé → [libellé, groupe, gravité]. Groupes : inscription, paiement, boutique, suppression, reglage, note.

require_once __DIR__ . '/saas.php';

const SAAS_EVENT_TYPES = [
    'signup_started'        => ['Inscription commencée', 'inscription', 'info'],
    'payment_started'       => ['Paiement ouvert (Stripe)', 'paiement', 'info'],
    'payment_manual'        => ['Paiement à régler à part', 'paiement', 'warn'],
    'payment_confirmed'     => ['Paiement confirmé', 'paiement', 'ok'],
    'payment_renewed'       => ['Abonnement renouvelé', 'paiement', 'ok'],
    'payment_failed'        => ['Paiement refusé', 'paiement', 'bad'],
    'payment_refunded'      => ['Paiement remboursé', 'paiement', 'warn'],
    'subscription_canceled' => ['Abonnement résilié', 'suppression', 'bad'],
    'shop_created'          => ['Boutique créée (par le client)', 'boutique', 'ok'],
    'shop_created_manual'   => ['Boutique créée (à la main)', 'boutique', 'ok'],
    'shop_created_approved' => ['Boutique créée (par l\'exploitant)', 'boutique', 'ok'],
    'shop_failed'           => ['Création de boutique en échec', 'boutique', 'bad'],
    'shop_deleted'          => ['Boutique supprimée', 'suppression', 'bad'],
    'shop_reset'            => ['Boutique remise à zéro', 'boutique', 'warn'],
    'access_changed'        => ['Accès administrateur changé', 'boutique', 'warn'],
    'link_resent'           => ['Lien de création renvoyé', 'inscription', 'info'],
    'request_rejected'      => ['Demande refusée', 'suppression', 'warn'],
    'request_forgotten'     => ['Demande retirée de la liste', 'suppression', 'info'],
    'config_changed'        => ['Réglage modifié', 'reglage', 'info'],
    'webhook'               => ['Webhook Stripe reçu', 'reglage', 'info'],
    'note'                  => ['Note', 'note', 'info'],
];

const SAAS_EVENT_GROUPS = [
    'inscription' => 'Inscriptions', 'paiement' => 'Paiements', 'boutique' => 'Boutiques', 'suppression' => 'Suppressions et résiliations',
    'reglage' => 'Réglages et système', 'note' => 'Notes',
];

function saas_events_file(): string
{
    return saas_data_dir() . '/saas/events.jsonl';
}

/**
 * Ajoute un événement au journal. Champs usuels : slug, email, plan, amount (centimes), ref (identifiant de la demande), actor
 * (client | stripe | exploitant | système), note. Ne lève jamais d'exception : le suivi ne doit pas faire échouer une action.
 */
function saas_event(string $type, array $data = []): void
{
    try {
        $file = saas_events_file();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
        if (!isset($data['actor'])) $data['actor'] = str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/portail/') ? 'exploitant' : 'client';
        $row = ['ts' => time(), 'type' => $type] + array_filter($data, static fn ($v) => $v !== null && $v !== '');
        @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('saas_event : ' . $e->getMessage());
    }
}

/** Événements du journal (les plus récents d'abord). */
function saas_events_logged(): array
{
    $file = saas_events_file();
    $rows = [];
    if (!is_file($file)) return $rows;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && isset($r['ts'], $r['type'])) $rows[] = $r;
    }
    usort($rows, static fn ($a, $b) => $b['ts'] <=> $a['ts']);
    return $rows;
}

/**
 * Journal complet : les événements enregistrés + ceux qu'on peut reconstituer d'après les demandes et la corbeille (champ « reconstitue »).
 * Un événement reconstitué n'apparaît pas s'il existe déjà au journal (même type et même demande / même boutique).
 */
function saas_events(): array
{
    $rows = saas_events_logged();
    $created = ['shop_created', 'shop_created_manual', 'shop_created_approved'];
    $has = [];
    foreach ($rows as $r) {
        if (!empty($r['ref'])) $has[$r['type'] . '|' . $r['ref']] = true;
        if (in_array($r['type'], $created, true) && !empty($r['slug'])) $has['created|' . $r['slug']] = true;
        if ($r['type'] === 'shop_deleted' && !empty($r['trash'])) $has['trash|' . $r['trash']] = true;
    }
    $add = static function (array $r) use (&$rows, &$has): void {
        $key = match (true) {
            $r['type'] === 'shop_created' => 'created|' . $r['slug'],
            $r['type'] === 'shop_deleted' => 'trash|' . $r['trash'],
            default => $r['type'] . '|' . ($r['ref'] ?? ''),
        };
        if (isset($has[$key])) return;
        $has[$key] = true;
        $rows[] = $r + ['reconstitue' => true];
    };
    foreach (saas_requests() as $q) {
        $base = ['ref' => $q['id'], 'email' => $q['email'] ?? '', 'plan' => $q['plan'] ?? '', 'actor' => 'client'];
        if (!empty($q['created'])) $add(['ts' => (int) $q['created'], 'type' => 'signup_started'] + $base);
        $pay = $q['payment'] ?? [];
        if (($pay['state'] ?? '') === 'manual' && !empty($q['created'])) $add(['ts' => (int) $q['created'] + 1, 'type' => 'payment_manual', 'note' => (string) ($pay['note'] ?? '')] + $base);
        if (!empty($pay['paid_at'])) $add(['ts' => (int) $pay['paid_at'], 'type' => 'payment_confirmed', 'amount' => (int) ($pay['amount'] ?? 0), 'actor' => 'stripe'] + $base);
        if (!empty($pay['canceled_at'])) $add(['ts' => (int) $pay['canceled_at'], 'type' => 'subscription_canceled', 'actor' => 'stripe'] + $base);
        if (($q['status'] ?? '') === 'approved' && !empty($q['approved']) && !empty($q['slug'])) $add(['ts' => (int) $q['approved'], 'type' => 'shop_created', 'slug' => (string) $q['slug']] + $base);
        if (($q['status'] ?? '') === 'rejected' && !empty($q['rejected'])) $add(['ts' => (int) $q['rejected'], 'type' => 'request_rejected', 'actor' => 'exploitant'] + $base);
    }
    foreach (glob(saas_data_dir() . '/corbeille/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!preg_match('/^(.+)-(\d{8})-(\d{6})$/', basename($dir), $m)) continue;
        $ts = DateTime::createFromFormat('Ymd-His', $m[2] . '-' . $m[3]);
        if ($ts) $add(['ts' => $ts->getTimestamp(), 'type' => 'shop_deleted', 'slug' => $m[1], 'trash' => 'data/corbeille/' . basename($dir), 'actor' => 'exploitant']);
    }
    usort($rows, static fn ($a, $b) => $b['ts'] <=> $a['ts']);
    return $rows;
}

function saas_event_label(string $type): string
{
    return SAAS_EVENT_TYPES[$type][0] ?? $type;
}

/** Montant en centimes → « 19,90 € » (sans décimales si rond). */
function saas_money(int $cents): string
{
    return ($cents % 100 === 0 ? number_format($cents / 100, 0, ',', ' ') : number_format($cents / 100, 2, ',', ' ')) . ' €';
}

// ── Chiffres ─────────────────────────────────────────────────────────────────

/** Offre d'une demande : ['key', 'name', 'cents' (équivalent MENSUEL), 'billing', 'gallery', 'max_items'] ; cents = 0 pour une offre gratuite. */
function saas_plan_of(array $req): array
{
    $o = saas_req_offer($req);
    return ['key' => $o['key'], 'name' => $o['name'] . ($o['billing'] === 'year' ? ' (annuel)' : ''), 'cents' => $o['cents'] === null ? null : saas_offer_monthly_equivalent($o),
        'billing' => $o['billing'], 'gallery' => (string) ($o['gallery'] ?? ''), 'max_items' => $o['max_items']];
}

/** Boutiques de ce serveur avec leurs chiffres : commandes payées, chiffre d'affaires, pièces, comptes, abonnement. */
function saas_shop_stats(): array
{
    $byShop = [];
    foreach (saas_requests() as $q) {
        if (!empty($q['slug']) && ($q['status'] ?? '') === 'approved') $byShop[$q['slug']] = $q;
    }
    $now = time();
    $out = [];
    foreach (tenant_list() as $slug => $cfg) {
        $row = ['slug' => $slug, 'name' => (string) ($cfg['name'] ?? $slug), 'orders' => 0, 'revenue' => 0, 'orders30' => 0, 'revenue30' => 0, 'last_order' => null,
            'products' => null, 'limit' => (int) ($cfg['item_limit'] ?? 0), 'accounts' => null, 'created' => null, 'db' => false, 'plan' => null, 'payment' => null, 'request' => null, 'template' => (string) ($cfg['template'] ?? '')];
        $q = $byShop[$slug] ?? null;
        if ($q) {
            $row['request'] = $q['id'];
            $row['created'] = (int) ($q['approved'] ?? 0) ?: null;
            $row['plan'] = saas_plan_of($q);
            $pay = $q['payment'] ?? [];
            $row['payment'] = ($pay['state'] ?? '') !== '' ? $pay['state'] : (($row['plan']['cents'] ?? 0) === 0 ? 'free' : '');
            $row['auth'] = !empty($q['social']) ? (string) $q['social']['provider'] : '';
        }
        $row['created'] ??= (is_dir(tenant_dir($slug)) ? (int) filectime(tenant_dir($slug)) : null);
        $db = tenant_file($cfg, 'db_file');
        if (is_file($db)) {
            $row['db'] = true;
            try {
                $pdo = new PDO('sqlite:' . $db);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA query_only = 1');
                if ($pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'orders'")->fetchColumn()) {
                    $o = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount_total), 0) AS s, MAX(created_at) AS last FROM orders WHERE status = 'paid'")->fetch(PDO::FETCH_ASSOC);
                    $row['orders'] = (int) $o['n'];
                    $row['revenue'] = (int) $o['s'];
                    $row['last_order'] = $o['last'] ? strtotime($o['last'] . ' UTC') : null;
                    $o30 = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(amount_total), 0) AS s FROM orders WHERE status = 'paid' AND created_at >= ?");
                    $o30->execute([gmdate('Y-m-d H:i:s', $now - 30 * 86400)]);
                    $r = $o30->fetch(PDO::FETCH_ASSOC);
                    $row['orders30'] = (int) $r['n'];
                    $row['revenue30'] = (int) $r['s'];
                }
                if ($pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'products'")->fetchColumn()) {
                    $row['products'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_hidden = 0 AND stock > 0')->fetchColumn();
                }
                if ($pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'accounts'")->fetchColumn()) {
                    $row['accounts'] = (int) $pdo->query("SELECT COUNT(*) FROM accounts WHERE role IN ('client', 'prospect')")->fetchColumn();
                }
            } catch (Throwable $e) {
                error_log('saas_shop_stats ' . $slug . ' : ' . $e->getMessage());
            }
        }
        $out[$slug] = $row;
    }
    return $out;
}

/**
 * Tableau de bord : indicateurs de la période (jours), entonnoir, abonnements, alertes. $events = saas_events().
 */
function saas_dashboard(int $days, array $events, array $shops): array
{
    $now = time();
    $from = $now - $days * 86400;
    $count = static fn (string $type, int $since) => count(array_filter($events, static fn ($e) => $e['type'] === $type && $e['ts'] >= $since));
    $sum = static function (array $types, int $since) use ($events): int {
        $s = 0;
        foreach ($events as $e) if (in_array($e['type'], $types, true) && $e['ts'] >= $since) $s += (int) ($e['amount'] ?? 0);
        return $s;
    };
    $created = static fn (int $since) => count(array_filter($events, static fn ($e) => in_array($e['type'], ['shop_created', 'shop_created_manual', 'shop_created_approved'], true) && $e['ts'] >= $since));

    $requests = saas_requests();
    $active = $mrr = $cancelled = 0;
    $toCollect = $paidNoShop = $failed = [];
    $planBreakdown = [];
    foreach ($requests as $q) {
        $plan = saas_plan_of($q);
        $pay = $q['payment'] ?? [];
        $state = (string) ($pay['state'] ?? '');
        if (($q['status'] ?? '') === 'approved') {
            $planBreakdown[$plan['name']] = ($planBreakdown[$plan['name']] ?? 0) + 1;
            if ($state === 'paid' && !empty($pay['subscription'])) { $active++; $mrr += (int) ($plan['cents'] ?? 0); }
            elseif ($state === 'canceled') $cancelled++;
            elseif ($state === 'manual' && ($plan['cents'] ?? 0) > 0) $toCollect[] = $q;
        }
        if (($q['status'] ?? '') === 'paid') $paidNoShop[] = $q;
        if (!empty($q['error'])) $failed[] = $q;
    }
    $shopCreated = $created($from);
    $started = $count('signup_started', $from);
    $paid = $count('payment_confirmed', $from);

    $perDay = static function (callable $keep, callable $value) use ($events, $days, $now): array {
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) $series[date('Y-m-d', $now - $i * 86400)] = 0;
        foreach ($events as $e) {
            $d = date('Y-m-d', $e['ts']);
            if (isset($series[$d]) && $keep($e)) $series[$d] += $value($e);
        }
        return $series;
    };
    $shopOrders30 = array_sum(array_column($shops, 'orders30'));
    $shopRevenue30 = array_sum(array_column($shops, 'revenue30'));
    return [
        'days' => $days, 'from' => $from,
        'shops_total' => count($shops),
        'created' => $shopCreated, 'created_prev' => $created($from - $days * 86400) - $created($from),
        'started' => $started, 'paid' => $paid,
        'plat_revenue' => $sum(['payment_confirmed', 'payment_renewed'], $from),
        'refunds' => $sum(['payment_refunded'], $from),
        'renewals' => $count('payment_renewed', $from), 'failed_payments' => $count('payment_failed', $from),
        'deleted' => $count('shop_deleted', $from), 'canceled' => $count('subscription_canceled', $from),
        'manual_created' => $count('shop_created_manual', $from) + $count('shop_created_approved', $from),
        'subscriptions' => $active, 'mrr' => $mrr, 'canceled_total' => $cancelled,
        'shop_orders' => $shopOrders30, 'shop_revenue' => $shopRevenue30,
        'shop_orders_all' => array_sum(array_column($shops, 'orders')), 'shop_revenue_all' => array_sum(array_column($shops, 'revenue')),
        'to_collect' => $toCollect, 'paid_no_shop' => $paidNoShop, 'failed' => $failed, 'plans' => $planBreakdown,
        'series_created' => $perDay(static fn ($e) => in_array($e['type'], ['shop_created', 'shop_created_manual', 'shop_created_approved'], true), static fn ($e) => 1),
        'series_revenue' => $perDay(static fn ($e) => in_array($e['type'], ['payment_confirmed', 'payment_renewed'], true), static fn ($e) => (int) ($e['amount'] ?? 0)),
        'series_started' => $perDay(static fn ($e) => $e['type'] === 'signup_started', static fn ($e) => 1),
    ];
}

/** Bilan de santé du système : [libellé, état ok|warn|bad, détail]. */
function saas_health(array $events): array
{
    $out = [];
    $stripe = saas_stripe_config();
    $plansPaid = array_filter(saas_config()['plans'], static fn ($p) => (saas_plan_cents($p) ?? 0) > 0);
    $out[] = ['Paiement Stripe', $stripe['secret_key'] !== '' ? 'ok' : ($plansPaid ? 'bad' : 'warn'), $stripe['secret_key'] !== '' ? (saas_stripe_live() ? 'Clés RÉELLES enregistrées.' : 'Mode test : aucun vrai paiement.') : ($plansPaid ? 'Aucune clé : les formules payantes passent par un paiement à régler à part.' : 'Aucune clé (aucune formule payante).')];
    $lastHook = null;
    foreach ($events as $e) if ($e['type'] === 'webhook') { $lastHook = $e; break; }
    $out[] = ['Webhook Stripe', $stripe['webhook_secret'] === '' ? ($stripe['secret_key'] !== '' ? 'bad' : 'warn') : ($lastHook ? 'ok' : 'warn'),
        $stripe['webhook_secret'] === '' ? 'Secret de signature manquant : renouvellements, échecs et résiliations ne seront pas vus.'
            : ($lastHook ? 'Dernier événement reçu le ' . date('d/m/Y H:i', $lastHook['ts']) . ' (' . ($lastHook['note'] ?? '') . ').' : 'Secret enregistré, aucun événement reçu pour l\'instant.')];
    $contact = saas_config()['contact'];
    $out[] = ['E-mail de contact', $contact !== '' ? 'ok' : 'warn', $contact !== '' ? $contact . ' reçoit les créations et paiements.' : 'Non renseigné : vous ne recevrez aucune alerte par e-mail.'];
    $out[] = ['Envoi d\'e-mails', function_exists('mail') ? 'ok' : 'bad', function_exists('mail') ? 'Fonction mail() disponible (la remise dépend de l\'hébergeur).' : 'mail() désactivée sur ce serveur : aucun e-mail ne partira.'];
    $dir = saas_data_dir();
    $out[] = ['Dossier de données', is_writable($dir) ? 'ok' : 'bad', is_writable($dir) ? 'Inscriptible.' : 'data/ n\'est pas inscriptible : aucune inscription ne pourra être enregistrée.'];
    $free = @disk_free_space($dir);
    if ($free !== false) $out[] = ['Espace disque', $free > 1.5e9 ? 'ok' : ($free > 3e8 ? 'warn' : 'bad'), number_format($free / 1e9, 1, ',', ' ') . ' Go libres.'];
    $cap = (int) saas_config()['free_daily_cap'];
    $out[] = ['Créations gratuites', $cap > 0 && saas_free_cap_reached() ? 'warn' : 'ok', $cap > 0 ? (saas_free_cap_reached() ? 'Plafond de ' . $cap . ' par 24 h atteint : les nouvelles gratuites sont refusées.' : 'Plafond de ' . $cap . ' par 24 h.') : 'Sans plafond.'];
    return $out;
}

/** Données CSV (séparateur « ; », UTF-8 avec BOM : s'ouvre directement dans Excel). */
function saas_csv(array $header, array $rows): string
{
    // Une cellule qui commence par = + - @ serait exécutée comme formule par un tableur : on la neutralise (les e-mails et notes viennent de visiteurs).
    $safe = static fn ($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) && !is_numeric(str_replace(',', '.', (string) $v)) ? "'" . $v : (string) $v;
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $header, ';', '"', '');
    foreach ($rows as $r) fputcsv($fh, array_map($safe, $r), ';', '"', '');
    rewind($fh);
    return (string) stream_get_contents($fh);
}

/** Journalise le réglage qui vient d'être enregistré dans le portail (le message de confirmation, s'il n'est pas une erreur). */
function saas_audit_flash(string $where): void
{
    $f = $_SESSION['portail_flash'] ?? null;
    if (is_array($f) && ($f['kind'] ?? '') === 'ok') saas_event('config_changed', ['note' => $where . ' : ' . $f['message'], 'actor' => 'exploitant']);
}
