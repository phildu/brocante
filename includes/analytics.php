<?php
/**
 * Tableau de bord de la boutique : suivi des visites en ligne, journal d'usage
 * des API payantes, frais, chiffre d'affaires, rentabilité.
 *
 * Principes :
 *  - Aucune dépendance : tout tient dans la base SQLite existante, les tables
 *    sont créées à la volée (analytics_ensure_schema) — pas de migration à
 *    lancer à la main.
 *  - Suivi des visites SANS cookie ni adresse IP stockée : un « visiteur » est
 *    une empreinte salée (IP + navigateur + jour) qui change chaque jour et
 *    ne permet pas de retrouver la personne. Les visiteurs ayant activé « Do
 *    Not Track » / Global Privacy Control, les robots et l'administrateur
 *    connecté ne sont pas comptés. Conséquence : « visiteurs » = visiteurs
 *    uniques PAR JOUR (une même personne revenue deux jours compte deux fois).
 *  - Tous les montants d'argent du tableau de bord sont en centimes (int/float),
 *    les dates sont affichées à l'heure de Paris alors que SQLite stocke en UTC.
 */

require_once __DIR__ . '/../config.php';

const DASH_TZ = 'Europe/Paris';
const ANALYTICS_SCHEMA_VERSION = 1;
const ANALYTICS_RETENTION_MONTHS = 25; // durée maximale de conservation recommandée par la CNIL

/* ------------------------------------------------------------------ */
/* Dates                                                               */
/* ------------------------------------------------------------------ */

function dash_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(DASH_TZ);
}

function dash_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', dash_tz());
}

function dash_from_utc(string $utc): DateTimeImmutable
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(dash_tz());
}

function dash_to_utc(DateTimeImmutable $d): string
{
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Modificateur SQLite « +60 minutes » correspondant au décalage de Paris à la date donnée. */
function dash_sql_offset(DateTimeImmutable $at): string
{
    $minutes = (int) ($at->setTimezone(dash_tz())->getOffset() / 60);
    return ($minutes >= 0 ? '+' : '') . $minutes . ' minutes';
}

/* ------------------------------------------------------------------ */
/* Schéma                                                              */
/* ------------------------------------------------------------------ */

function analytics_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= ANALYTICS_SCHEMA_VERSION) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS visits (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          ts TEXT NOT NULL,                 -- UTC
          vid TEXT NOT NULL,                -- empreinte salée, change chaque jour
          event TEXT NOT NULL DEFAULT 'pageview', -- pageview | add_to_cart | checkout
          path TEXT NOT NULL DEFAULT '',
          product_ref TEXT,
          channel TEXT NOT NULL DEFAULT 'direct',
          ref_host TEXT,
          utm_source TEXT,
          utm_campaign TEXT,
          device TEXT NOT NULL DEFAULT 'ordinateur',
          country TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_visits_ts ON visits(ts);
        CREATE INDEX IF NOT EXISTS idx_visits_vid ON visits(vid, ts);

        CREATE TABLE IF NOT EXISTS physical_visits (
          day TEXT PRIMARY KEY,             -- AAAA-MM-JJ (heure locale)
          visitors INTEGER NOT NULL DEFAULT 0,
          sales_count INTEGER NOT NULL DEFAULT 0,
          sales_cents INTEGER NOT NULL DEFAULT 0,
          cogs_cents INTEGER,               -- prix d'achat des pièces vendues, si connu
          note TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS shop_costs (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          label TEXT NOT NULL,
          category TEXT NOT NULL DEFAULT 'autre',
          kind TEXT NOT NULL DEFAULT 'fixed',      -- fixed | per_order | per_order_pct | per_unit
          amount REAL NOT NULL DEFAULT 0,          -- euros (ou % pour per_order_pct)
          period TEXT NOT NULL DEFAULT 'monthly',  -- fixed : monthly | yearly | once
          unit TEXT,                               -- per_unit : gemini_image | gemini_text | fal_cutout
          status TEXT NOT NULL DEFAULT 'actif',    -- actif (réellement payé) | test (gratuit/test aujourd'hui, simulé payant)
          start_date TEXT,
          end_date TEXT,
          note TEXT NOT NULL DEFAULT '',
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS api_usage (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          provider TEXT NOT NULL,
          kind TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_api_usage_ts ON api_usage(ts);

        CREATE TABLE IF NOT EXISTS dash_settings (
          k TEXT PRIMARY KEY,
          v TEXT NOT NULL DEFAULT ''
        );
    ");

    // Colonnes ajoutées aux tables existantes. Si l'une de ces tables n'existe
    // pas encore (base vide), on ne fige pas la version : on réessaiera.
    $ok = analytics_add_column('products', 'cost_cents', 'INTEGER');          // prix d'achat (NULL = non renseigné)
    $ok = analytics_add_column('orders', 'source_channel', 'TEXT') && $ok;     // provenance du client à la commande
    if ($ok) $pdo->exec('PRAGMA user_version = ' . ANALYTICS_SCHEMA_VERSION);
}

function analytics_add_column(string $table, string $column, string $definition): bool
{
    $pdo = db();
    $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    if (!$cols) return false;
    foreach ($cols as $c) {
        if ($c['name'] === $column) return true;
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    return true;
}

function analytics_setting(string $key, ?string $default = null): ?string
{
    analytics_ensure_schema();
    $stmt = db()->prepare('SELECT v FROM dash_settings WHERE k = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function analytics_set_setting(string $key, string $value): void
{
    analytics_ensure_schema();
    db()->prepare('INSERT INTO dash_settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')
        ->execute([$key, $value]);
}

/* ------------------------------------------------------------------ */
/* Catalogue des frais                                                 */
/* ------------------------------------------------------------------ */

function cost_categories(): array
{
    return [
        'hebergement' => 'Hébergement & domaine',
        'paiement' => 'Paiement en ligne',
        'ia' => 'IA & outils photo',
        'logiciel' => 'Logiciels & abonnements',
        'expedition' => 'Emballage & expédition',
        'marketing' => 'Communication & marketing',
        'local' => 'Local, stand, déplacements',
        'autre' => 'Autre',
    ];
}

function cost_kinds(): array
{
    return [
        'fixed' => 'Montant fixe (par mois, par an ou ponctuel)',
        'per_order' => 'Par commande en ligne (€)',
        'per_order_pct' => 'Pourcentage du montant encaissé en ligne (%)',
        'per_unit' => "À l'usage mesuré (€ par appel)",
    ];
}

function cost_usage_units(): array
{
    return [
        'gemini_image' => 'Image générée par Gemini (galerie)',
        'gemini_text' => 'Analyse Gemini (fiche, tri, description)',
        'fal_cutout' => 'Détourage fal.ai',
    ];
}

/** Frais préremplis la toute première fois — montants indicatifs, à ajuster. */
function analytics_seed_costs(): void
{
    if (analytics_setting('costs_seeded') !== null) return;
    analytics_set_setting('costs_seeded', '1');
    if ((int) db()->query('SELECT COUNT(*) FROM shop_costs')->fetchColumn() > 0) return;

    $rows = [
        // label, category, kind, amount, period, unit, status, note
        ['Hébergement OVH', 'hebergement', 'fixed', 0, 'monthly', null, 'actif', 'À renseigner : le montant réel de votre offre.'],
        ['Nom de domaine', 'hebergement', 'fixed', 0, 'yearly', null, 'actif', 'À renseigner : le montant annuel réel.'],
        ['Emballage & port réellement payé', 'expedition', 'per_order', 0, 'monthly', null, 'actif', "À renseigner : coût moyen par colis (carton, scotch, affranchissement). Le port facturé au client est déjà dans le chiffre d'affaires."],
        ['Stripe — commission sur paiement', 'paiement', 'per_order_pct', 1.5, 'monthly', null, 'test', 'Estimation du tarif public Stripe pour cartes européennes standard, à vérifier sur stripe.com/fr/pricing. Aujourd\'hui : clés de test, aucun frais.'],
        ['Stripe — frais fixe par paiement', 'paiement', 'per_order', 0.25, 'monthly', null, 'test', 'Estimation du tarif public Stripe, à vérifier. Aujourd\'hui : clés de test, aucun frais.'],
        ['Gemini — images générées (galerie)', 'ia', 'per_unit', 0.04, 'monthly', 'gemini_image', 'test', 'Estimation ≈ 0,04 € par image, à vérifier sur ai.google.dev/pricing. Aujourd\'hui : palier gratuit.'],
        ['Gemini — analyses (fiches, tri des photos)', 'ia', 'per_unit', 0.002, 'monthly', 'gemini_text', 'test', 'Estimation, à vérifier sur ai.google.dev/pricing. Aujourd\'hui : palier gratuit.'],
        ['fal.ai — détourage de fond', 'ia', 'per_unit', 0.001, 'monthly', 'fal_cutout', 'test', 'Estimation, à vérifier sur fal.ai/pricing.'],
    ];
    $stmt = db()->prepare('INSERT INTO shop_costs (label, category, kind, amount, period, unit, status, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) $stmt->execute($r);
}

function shop_costs_all(): array
{
    analytics_ensure_schema();
    analytics_seed_costs();
    return db()->query("SELECT * FROM shop_costs ORDER BY CASE status WHEN 'actif' THEN 0 ELSE 1 END, category, label")->fetchAll();
}

/* ------------------------------------------------------------------ */
/* Suivi des visites                                                   */
/* ------------------------------------------------------------------ */

function analytics_channels(): array
{
    return [
        'direct' => 'Accès direct',
        'search' => 'Moteurs de recherche',
        'social' => 'Réseaux sociaux',
        'email' => 'E-mail & newsletter',
        'market' => 'Annonces & places de marché',
        'paid' => 'Publicité payante',
        'referral' => 'Autres sites',
    ];
}

/** @return array{0:string,1:?string} [canal, hôte du site référent] */
function analytics_classify_source(?string $referer, ?string $utmSource, ?string $utmMedium, string $ownHost): array
{
    $host = null;
    if ($referer) {
        $h = strtolower((string) parse_url($referer, PHP_URL_HOST));
        $host = preg_replace('/^(www|m|l|lm)\./', '', $h) ?: null;
    }
    $own = preg_replace('/:\d+$/', '', preg_replace('/^www\./', '', strtolower($ownHost)));
    $utmSource = strtolower((string) $utmSource);
    $utmMedium = strtolower((string) $utmMedium);

    if (preg_match('/^(cpc|ppc|paid|display|ads?)/', $utmMedium)) return ['paid', $host ?: ($utmSource ?: null)];
    if ($utmMedium === 'email' || $utmMedium === 'newsletter' || $utmSource === 'newsletter') return ['email', $host ?: ($utmSource ?: null)];
    if ($utmMedium === 'social' || preg_match('/facebook|instagram|pinterest|tiktok/', $utmSource)) return ['social', $host ?: ($utmSource ?: null)];

    if ($host === null) return [$utmSource !== '' ? 'referral' : 'direct', $utmSource !== '' ? $utmSource : null];
    if ($host === $own || str_ends_with($host, '.' . $own)) return ['internal', $host];

    // Les webmails d'abord : « mail.google.com » ne doit pas passer pour Google Search.
    if (str_starts_with($host, 'mail.') || str_contains($host, 'webmail') || preg_match('/^(gmail\.com|outlook\.(live|office|office365)\.com)$/', $host)) return ['email', $host];
    if (preg_match('/(^|\.)(google|bing|duckduckgo|qwant|ecosia|yahoo|brave|startpage|yandex|lilo)\./', $host)) return ['search', $host];
    if (preg_match('/(^|\.)(facebook|fb|instagram|pinterest|tiktok|twitter|t|x|linkedin|youtube|youtu|threads|snapchat|reddit)\.(com|co|be|me|net)$/', $host)) return ['social', $host];
    if (preg_match('/(leboncoin|vinted|ebay|etsy|selency|videdressing|rakuten)/', $host)) return ['market', $host];

    return ['referral', $host];
}

function analytics_is_bot(string $ua): bool
{
    if ($ua === '') return true;
    return (bool) preg_match('/bot|crawl|spider|slurp|archiver|fetch|scan|monitor|uptime|preview|headless|lighthouse|pingdom|curl|wget|python-requests|httpclient|facebookexternalhit|whatsapp|telegram|discord|embedly|bing/i', $ua);
}

function analytics_device(string $ua): string
{
    if (preg_match('/ipad|tablet|kindle|playbook|(android(?!.*mobile))/i', $ua)) return 'tablette';
    if (preg_match('/mobi|iphone|ipod|android|phone/i', $ua)) return 'mobile';
    return 'ordinateur';
}

/** Enregistre une page vue (appelé depuis le header public). Ne doit JAMAIS faire échouer la page. */
function track_request(?string $productRef = null): void
{
    analytics_record('pageview', $productRef);
}

/** Enregistre un évènement du tunnel de vente : 'add_to_cart' | 'checkout'. */
function track_event(string $event, ?string $productRef = null): void
{
    analytics_record($event, $productRef);
}

function analytics_record(string $event, ?string $productRef): void
{
    try {
        if (PHP_SAPI === 'cli') return;
        if (!empty($_SESSION['is_admin'])) return; // les visites du propriétaire ne comptent pas
        if (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') return;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($event === 'pageview' && $method !== 'GET') return;
        $purpose = strtolower(($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ($_SERVER['HTTP_X_PURPOSE'] ?? '') . ($_SERVER['HTTP_X_MOZ'] ?? ''));
        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'preview')) return;
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (analytics_is_bot($ua)) return;

        analytics_ensure_schema();

        $salt = analytics_setting('visitor_salt');
        if ($salt === null || $salt === '') {
            $salt = bin2hex(random_bytes(16));
            analytics_set_setting('visitor_salt', $salt);
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $vid = substr(hash('sha256', $salt . '|' . $ip . '|' . $ua . '|' . gmdate('Y-m-d')), 0, 16);

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $path = preg_match('#^/[A-Za-z0-9._/-]*$#', $script) ? substr($script, 0, 120) : '/';

        $utmSource = isset($_GET['utm_source']) ? substr(trim((string) $_GET['utm_source']), 0, 60) : null;
        $utmMedium = isset($_GET['utm_medium']) ? substr(trim((string) $_GET['utm_medium']), 0, 60) : null;
        $utmCampaign = isset($_GET['utm_campaign']) ? substr(trim((string) $_GET['utm_campaign']), 0, 80) : null;
        [$channel, $refHost] = analytics_classify_source($_SERVER['HTTP_REFERER'] ?? null, $utmSource, $utmMedium, (string) ($_SERVER['HTTP_HOST'] ?? ''));

        // Première provenance externe de la session : conservée jusqu'à la
        // commande pour savoir quel canal a réellement rapporté du chiffre d'affaires.
        if ($channel !== 'internal' && empty($_SESSION['first_source']) && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['first_source'] = $channel;
        }

        $country = null;
        $cf = strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
        if (preg_match('/^[A-Z]{2}$/', $cf) && $cf !== 'XX') $country = $cf;

        db()->prepare('INSERT INTO visits (ts, vid, event, path, product_ref, channel, ref_host, utm_source, utm_campaign, device, country)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([gmdate('Y-m-d H:i:s'), $vid, $event, $path, $productRef, $channel, $refHost, $utmSource, $utmCampaign, analytics_device($ua), $country]);

        if (random_int(1, 300) === 1) {
            $cut = gmdate('Y-m-d H:i:s', strtotime('-' . ANALYTICS_RETENTION_MONTHS . ' months'));
            db()->prepare('DELETE FROM visits WHERE ts < ?')->execute([$cut]);
        }
    } catch (Throwable $e) {
        error_log('analytics_record: ' . $e->getMessage());
    }
}

/** Provenance mémorisée en session pour l'inscrire sur la commande. */
function analytics_session_source(): ?string
{
    $s = $_SESSION['first_source'] ?? null;
    return is_string($s) && isset(analytics_channels()[$s]) ? $s : null;
}

/** Journalise un appel à une API payante (Gemini, fal.ai) — sert à chiffrer ce que coûterait le passage en payant. */
function log_api_usage(string $provider, string $kind): void
{
    try {
        analytics_ensure_schema();
        db()->prepare('INSERT INTO api_usage (ts, provider, kind) VALUES (?, ?, ?)')->execute([gmdate('Y-m-d H:i:s'), $provider, $kind]);
    } catch (Throwable $e) {
        error_log('log_api_usage: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Ventes                                                              */
/* ------------------------------------------------------------------ */

function dash_product_map(): array
{
    static $map = null;
    if ($map !== null) return $map;
    analytics_ensure_schema();
    $map = [];
    foreach (db()->query('SELECT ref, name, cat, price, promo_price, photo, cost_cents, stock, is_hidden, created_at FROM products')->fetchAll() as $p) {
        $map[$p['ref']] = $p;
    }
    return $map;
}

/** Commandes payées, enrichies : date locale, prix d'achat connu, etc. */
function dash_paid_orders(): array
{
    static $orders = null;
    if ($orders !== null) return $orders;
    analytics_ensure_schema();
    $products = dash_product_map();
    $orders = [];
    foreach (db()->query("SELECT id, amount_total, shipping_cents, items, source_channel, created_at FROM orders WHERE status = 'paid' ORDER BY created_at")->fetchAll() as $o) {
        $items = json_decode((string) $o['items'], true) ?: [];
        $cogsKnown = 0;
        $revKnown = 0;
        $revItems = 0;
        $qty = 0;
        foreach ($items as &$it) {
            $q = max(0, (int) ($it['qty'] ?? 0));
            $unit = (int) ($it['unit_cents'] ?? 0);
            $cost = $it['cost_cents'] ?? ($products[$it['ref'] ?? '']['cost_cents'] ?? null);
            $it['_cost'] = $cost === null ? null : (int) $cost;
            $revItems += $unit * $q;
            $qty += $q;
            if ($cost !== null) {
                $cogsKnown += (int) $cost * $q;
                $revKnown += $unit * $q;
            }
        }
        unset($it);
        $o['local'] = dash_from_utc($o['created_at']);
        $o['items_list'] = $items;
        $o['qty'] = $qty;
        $o['cogs_known'] = $cogsKnown;
        $o['rev_known'] = $revKnown;
        $o['rev_items'] = $revItems;
        $orders[] = $o;
    }
    return $orders;
}

/** Ventes en ligne sur [s, e[ */
function dash_online_sales(DateTimeImmutable $s, DateTimeImmutable $e): array
{
    $r = ['orders' => 0, 'revenue' => 0, 'shipping' => 0, 'items' => 0, 'cogs_known' => 0, 'rev_known' => 0, 'rev_items' => 0,
          'by_channel' => [], 'products' => []];
    $map = dash_product_map();
    foreach (dash_paid_orders() as $o) {
        if ($o['local'] < $s || $o['local'] >= $e) continue;
        $r['orders']++;
        $r['revenue'] += (int) $o['amount_total'];
        $r['shipping'] += (int) $o['shipping_cents'];
        $r['items'] += $o['qty'];
        $r['cogs_known'] += $o['cogs_known'];
        $r['rev_known'] += $o['rev_known'];
        $r['rev_items'] += $o['rev_items'];
        $ch = $o['source_channel'] ?: 'inconnu';
        $r['by_channel'][$ch]['orders'] = ($r['by_channel'][$ch]['orders'] ?? 0) + 1;
        $r['by_channel'][$ch]['revenue'] = ($r['by_channel'][$ch]['revenue'] ?? 0) + (int) $o['amount_total'];
        foreach ($o['items_list'] as $it) {
            $ref = (string) ($it['ref'] ?? '');
            $q = max(0, (int) ($it['qty'] ?? 0));
            $row = &$r['products'][$ref];
            $row ??= ['ref' => $ref, 'name' => $it['name'] ?? $ref, 'cat' => $map[$ref]['cat'] ?? 'autre', 'qty' => 0, 'revenue' => 0, 'cogs' => 0, 'cogs_known_rev' => 0];
            $row['qty'] += $q;
            $row['revenue'] += (int) ($it['unit_cents'] ?? 0) * $q;
            if ($it['_cost'] !== null) {
                $row['cogs'] += $it['_cost'] * $q;
                $row['cogs_known_rev'] += (int) ($it['unit_cents'] ?? 0) * $q;
            }
            unset($row);
        }
    }
    return $r;
}

/** Fréquentation et ventes en boutique physique, saisies à la main, sur [s, e[ */
function dash_physical(DateTimeImmutable $s, DateTimeImmutable $e): array
{
    analytics_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM physical_visits WHERE day >= ? AND day < ? ORDER BY day');
    $stmt->execute([$s->format('Y-m-d'), $e->format('Y-m-d')]);
    $rows = $stmt->fetchAll();
    $r = ['visitors' => 0, 'sales_count' => 0, 'sales_cents' => 0, 'cogs_cents' => 0, 'sales_with_cogs' => 0, 'days' => count($rows), 'rows' => $rows];
    foreach ($rows as $row) {
        $r['visitors'] += (int) $row['visitors'];
        $r['sales_count'] += (int) $row['sales_count'];
        $r['sales_cents'] += (int) $row['sales_cents'];
        if ($row['cogs_cents'] !== null) {
            $r['cogs_cents'] += (int) $row['cogs_cents'];
            $r['sales_with_cogs'] += (int) $row['sales_cents'];
        }
    }
    return $r;
}

function dash_usage(DateTimeImmutable $s, DateTimeImmutable $e): array
{
    analytics_ensure_schema();
    $stmt = db()->prepare('SELECT kind, COUNT(*) AS n FROM api_usage WHERE ts >= ? AND ts < ? GROUP BY kind');
    $stmt->execute([dash_to_utc($s), dash_to_utc($e)]);
    return array_column($stmt->fetchAll(), 'n', 'kind');
}

/* ------------------------------------------------------------------ */
/* Frais                                                               */
/* ------------------------------------------------------------------ */

function dash_cost_bounds(array $c): array
{
    $cs = $c['start_date'] ? new DateTimeImmutable($c['start_date'] . ' 00:00:00', dash_tz()) : null;
    $ce = $c['end_date'] ? (new DateTimeImmutable($c['end_date'] . ' 00:00:00', dash_tz()))->modify('+1 day') : null;
    return [$cs, $ce];
}

/** Part d'un frais fixe tombant dans [s, e[ (au prorata des jours, mois par mois). */
function dash_fixed_amount_cents(array $c, DateTimeImmutable $s, DateTimeImmutable $e): float
{
    [$cs, $ce] = dash_cost_bounds($c);
    $amount = (float) $c['amount'] * 100;

    if ($c['period'] === 'once') {
        $at = $cs ?? dash_from_utc($c['created_at'])->setTime(0, 0);
        return ($at >= $s && $at < $e) ? $amount : 0.0;
    }
    $perMonth = $c['period'] === 'yearly' ? $amount / 12 : $amount;
    // Frais « engendrés à ce jour » : on ne compte pas les jours qui n'ont pas encore eu lieu,
    // pour rester comparable au chiffre d'affaires de la période en cours.
    $now = dash_now();
    if ($e > $now) $e = $now;
    $lo = $cs && $cs > $s ? $cs : $s;
    $hi = $ce && $ce < $e ? $ce : $e;
    if ($hi <= $lo) return 0.0;

    $total = 0.0;
    $m = $lo->modify('first day of this month')->setTime(0, 0);
    while ($m < $hi) {
        $next = $m->modify('first day of next month');
        $a = $lo > $m ? $lo : $m;
        $b = $hi < $next ? $hi : $next;
        if ($b > $a) $total += $perMonth * ($b->getTimestamp() - $a->getTimestamp()) / ($next->getTimestamp() - $m->getTimestamp());
        $m = $next;
    }
    return $total;
}

/**
 * Lignes de frais sur [s, e[ pour les statuts demandés.
 * @param string[] $statuses ['actif'] = frais réels ; ['actif','test'] = simulation « tout devient payant »
 * @return array<int,array{id:int,label:string,category:string,kind:string,status:string,cents:float,type:string,detail:string}>
 */
function dash_cost_lines(DateTimeImmutable $s, DateTimeImmutable $e, array $statuses): array
{
    $lines = [];
    foreach (shop_costs_all() as $c) {
        if (!in_array($c['status'], $statuses, true)) continue;
        [$cs, $ce] = dash_cost_bounds($c);
        $lo = $cs && $cs > $s ? $cs : $s;
        $hi = $ce && $ce < $e ? $ce : $e;
        $cents = 0.0;
        $type = 'variable';
        $detail = '';
        if ($c['kind'] === 'fixed') {
            $type = 'fixed';
            $cents = dash_fixed_amount_cents($c, $s, $e);
            $detail = $c['period'] === 'once' ? 'ponctuel' : ($c['period'] === 'yearly' ? 'annuel' : 'mensuel');
        } elseif ($hi > $lo) {
            if ($c['kind'] === 'per_order' || $c['kind'] === 'per_order_pct') {
                $on = dash_online_sales($lo, $hi);
                if ($c['kind'] === 'per_order') {
                    $cents = $on['orders'] * (float) $c['amount'] * 100;
                    $detail = $on['orders'] . ' commande' . ($on['orders'] > 1 ? 's' : '');
                } else {
                    $cents = $on['revenue'] * (float) $c['amount'] / 100;
                    $detail = rtrim(rtrim(number_format((float) $c['amount'], 2, ',', ''), '0'), ',') . ' % de ' . format_cents($on['revenue']);
                }
            } elseif ($c['kind'] === 'per_unit') {
                $n = (int) (dash_usage($lo, $hi)[$c['unit'] ?? ''] ?? 0);
                $cents = $n * (float) $c['amount'] * 100;
                $detail = $n . ' appel' . ($n > 1 ? 's' : '');
            }
        }
        $lines[] = ['id' => (int) $c['id'], 'label' => $c['label'], 'category' => $c['category'], 'kind' => $c['kind'],
                    'status' => $c['status'], 'cents' => $cents, 'type' => $type, 'detail' => $detail];
    }
    return $lines;
}

/** Frais fixes mensuels équivalents à une date donnée (hors frais ponctuels). */
function dash_monthly_fixed_cents(DateTimeImmutable $at, array $statuses): float
{
    $total = 0.0;
    foreach (shop_costs_all() as $c) {
        if ($c['kind'] !== 'fixed' || $c['period'] === 'once' || !in_array($c['status'], $statuses, true)) continue;
        [$cs, $ce] = dash_cost_bounds($c);
        if (($cs && $at < $cs) || ($ce && $at >= $ce)) continue;
        $total += $c['period'] === 'yearly' ? (float) $c['amount'] * 100 / 12 : (float) $c['amount'] * 100;
    }
    return $total;
}

/* ------------------------------------------------------------------ */
/* Économie d'une période                                              */
/* ------------------------------------------------------------------ */

function dash_economics(DateTimeImmutable $s, DateTimeImmutable $e, array $statuses = ['actif']): array
{
    $on = dash_online_sales($s, $e);
    $ph = dash_physical($s, $e);
    $lines = dash_cost_lines($s, $e, $statuses);

    $fixed = $variable = 0.0;
    foreach ($lines as $l) {
        if ($l['type'] === 'fixed') $fixed += $l['cents']; else $variable += $l['cents'];
    }
    $revenue = $on['revenue'] + $ph['sales_cents'];
    $cogs = $on['cogs_known'] + $ph['cogs_cents'];
    $itemsRev = $on['rev_items'] + $ph['sales_cents'];
    $coverage = $itemsRev > 0 ? min(1.0, ($on['rev_known'] + $ph['sales_with_cogs']) / $itemsRev) : null;

    return [
        'online' => $on,
        'physical' => $ph,
        'lines' => $lines,
        'orders' => $on['orders'] + $ph['sales_count'],
        'revenue' => $revenue,
        'cogs' => $cogs,
        'cogs_coverage' => $coverage,
        'fixed' => $fixed,
        'variable' => $variable,
        'costs' => $fixed + $variable,
        'gross_margin' => $revenue - $cogs,
        'result' => $revenue - $cogs - $fixed - $variable,
    ];
}

/** Date de lancement : réglage manuel, sinon la plus ancienne trace d'activité. */
function dash_launch_date(): DateTimeImmutable
{
    $manual = analytics_setting('launch_date');
    if ($manual && preg_match('/^\d{4}-\d{2}-\d{2}$/', $manual)) return new DateTimeImmutable($manual . ' 00:00:00', dash_tz());
    $candidates = [];
    $orders = dash_paid_orders();
    if ($orders) $candidates[] = $orders[0]['local']->setTime(0, 0);
    $v = db()->query('SELECT MIN(ts) FROM visits')->fetchColumn();
    if ($v) $candidates[] = dash_from_utc($v)->setTime(0, 0);
    $p = db()->query('SELECT MIN(day) FROM physical_visits')->fetchColumn();
    if ($p) $candidates[] = new DateTimeImmutable($p . ' 00:00:00', dash_tz());
    foreach (shop_costs_all() as $c) {
        if ($c['status'] === 'actif' && $c['start_date']) $candidates[] = new DateTimeImmutable($c['start_date'] . ' 00:00:00', dash_tz());
    }
    return $candidates ? min($candidates) : dash_now()->setTime(0, 0);
}

/**
 * Point de bascule mensuel : chiffre d'affaires à atteindre pour couvrir les
 * frais fixes, d'après la marge réellement dégagée sur les 12 derniers mois
 * (ou, à défaut de ventes, d'après les prix et prix d'achat du catalogue).
 */
function dash_breakeven(DateTimeImmutable $asOf, array $statuses): array
{
    $now = dash_now();
    $end = min($asOf, $now->modify('+1 day')->setTime(0, 0));
    $launch = dash_launch_date();
    $start = max($launch, $end->modify('-12 months'));
    $fixed = dash_monthly_fixed_cents($end->modify('-1 second'), $statuses);

    $out = ['fixed_monthly' => $fixed, 'rate' => null, 'avg_order' => null, 'be_revenue' => null, 'be_orders' => null,
            'basis' => null, 'coverage' => null, 'window_start' => $start, 'window_end' => $end];

    $eco = $end > $start ? dash_economics($start, $end, $statuses) : null;
    if ($eco && $eco['orders'] >= 1 && $eco['revenue'] > 0) {
        $out['basis'] = 'ventes';
        $out['avg_order'] = $eco['revenue'] / $eco['orders'];
        $out['rate'] = 1 - ($eco['cogs'] + $eco['variable']) / $eco['revenue'];
        $out['coverage'] = $eco['cogs_coverage'];
    } else {
        // Pas encore de vente : estimation d'après le catalogue en vente.
        $sumPrice = $sumCost = 0;
        $n = 0;
        $costKnownPrice = 0;
        foreach (dash_product_map() as $p) {
            if ((int) $p['stock'] <= 0 || (int) $p['is_hidden']) continue;
            $price = price_to_cents($p['promo_price'] ?: $p['price']);
            if (!$price) continue;
            $n++;
            $sumPrice += $price;
            if ($p['cost_cents'] !== null) {
                $sumCost += (int) $p['cost_cents'];
                $costKnownPrice += $price;
            }
        }
        if ($n > 0) {
            $avg = $sumPrice / $n;
            $cogsRatio = $costKnownPrice > 0 ? $sumCost / $costKnownPrice : 0;
            $varRatio = 0.0;
            $perOrder = 0.0;
            foreach (shop_costs_all() as $c) {
                if (!in_array($c['status'], $statuses, true)) continue;
                if ($c['kind'] === 'per_order_pct') $varRatio += (float) $c['amount'] / 100;
                if ($c['kind'] === 'per_order') $perOrder += (float) $c['amount'] * 100;
            }
            $out['basis'] = 'catalogue';
            $out['avg_order'] = $avg;
            $out['rate'] = 1 - $cogsRatio - $varRatio - $perOrder / $avg;
            $out['coverage'] = $sumPrice > 0 ? $costKnownPrice / $sumPrice : null;
        }
    }
    if ($out['rate'] !== null && $out['rate'] > 0.0001) {
        $out['be_revenue'] = $fixed / $out['rate'];
        $out['be_orders'] = $out['avg_order'] > 0 ? $out['be_revenue'] / $out['avg_order'] : null;
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Trafic en ligne                                                     */
/* ------------------------------------------------------------------ */

function dash_online_now(int $minutes = 5): int
{
    analytics_ensure_schema();
    $stmt = db()->prepare('SELECT COUNT(DISTINCT vid) FROM visits WHERE ts >= ?');
    $stmt->execute([gmdate('Y-m-d H:i:s', time() - $minutes * 60)]);
    return (int) $stmt->fetchColumn();
}

function dash_traffic(DateTimeImmutable $s, DateTimeImmutable $e): array
{
    analytics_ensure_schema();
    $pdo = db();
    $off = dash_sql_offset($s);
    $p = [dash_to_utc($s), dash_to_utc($e)];
    $range = 'ts >= ? AND ts < ?';

    $one = function (string $sql, array $params = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    };
    $all = function (string $sql, array $params = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    };

    $t = [];
    $t['since'] = $one('SELECT MIN(ts) FROM visits');
    $t['visitors'] = (int) $one("SELECT COUNT(*) FROM (SELECT DISTINCT date(ts, '$off'), vid FROM visits WHERE event = 'pageview' AND $range)", $p);
    $t['pageviews'] = (int) $one("SELECT COUNT(*) FROM visits WHERE event = 'pageview' AND $range", $p);
    $t['viewed_product'] = (int) $one("SELECT COUNT(*) FROM (SELECT DISTINCT date(ts, '$off'), vid FROM visits WHERE event = 'pageview' AND product_ref IS NOT NULL AND $range)", $p);
    $t['added_cart'] = (int) $one("SELECT COUNT(*) FROM (SELECT DISTINCT date(ts, '$off'), vid FROM visits WHERE event = 'add_to_cart' AND $range)", $p);
    $t['checkout'] = (int) $one("SELECT COUNT(*) FROM (SELECT DISTINCT date(ts, '$off'), vid FROM visits WHERE event = 'checkout' AND $range)", $p);

    // Une ligne par visiteur-jour : sa toute première page vue, qui porte la provenance.
    $first = "id IN (SELECT MIN(id) FROM visits WHERE event = 'pageview' AND $range GROUP BY date(ts, '$off'), vid)";

    $channels = [];
    foreach ($all("SELECT channel, COUNT(*) AS n FROM visits WHERE $first GROUP BY channel", $p) as $r) {
        $k = $r['channel'] === 'internal' ? 'direct' : $r['channel'];
        $channels[$k] = ($channels[$k] ?? 0) + (int) $r['n'];
    }
    arsort($channels);
    $t['channels'] = $channels;

    $t['referrers'] = $all("SELECT ref_host, channel, COUNT(*) AS n FROM visits WHERE $first AND ref_host IS NOT NULL AND channel != 'internal' GROUP BY ref_host, channel ORDER BY n DESC LIMIT 8", $p);
    $t['campaigns'] = $all("SELECT utm_campaign, COALESCE(utm_source, '') AS src, COUNT(*) AS n FROM visits WHERE $first AND utm_campaign IS NOT NULL AND utm_campaign != '' GROUP BY utm_campaign, src ORDER BY n DESC LIMIT 5", $p);
    $t['devices'] = array_column($all("SELECT device, COUNT(*) AS n FROM visits WHERE $first GROUP BY device ORDER BY n DESC", $p), 'n', 'device');
    $t['countries'] = array_column($all("SELECT country, COUNT(*) AS n FROM visits WHERE $first AND country IS NOT NULL GROUP BY country ORDER BY n DESC LIMIT 8", $p), 'n', 'country');
    $t['pages'] = $all("SELECT path, COUNT(*) AS n FROM visits WHERE event = 'pageview' AND $range GROUP BY path ORDER BY n DESC LIMIT 8", $p);
    $t['top_products'] = $all("SELECT product_ref AS ref, COUNT(*) AS n FROM visits WHERE event = 'pageview' AND product_ref IS NOT NULL AND $range GROUP BY product_ref ORDER BY n DESC LIMIT 8", $p);
    $t['hours'] = array_column($all("SELECT CAST(strftime('%H', ts, '$off') AS INTEGER) AS h, COUNT(*) AS n FROM visits WHERE event = 'pageview' AND $range GROUP BY h", $p), 'n', 'h');
    $t['by_day'] = array_column($all("SELECT date(ts, '$off') AS d, COUNT(DISTINCT vid) AS n FROM visits WHERE event = 'pageview' AND $range GROUP BY d", $p), 'n', 'd');
    $t['by_month'] = array_column($all("SELECT m, COUNT(*) AS n FROM (SELECT DISTINCT date(ts, '$off') AS dd, vid, strftime('%Y-%m', ts, '$off') AS m FROM visits WHERE event = 'pageview' AND $range) GROUP BY m", $p), 'n', 'm');
    return $t;
}

/* ------------------------------------------------------------------ */
/* Catalogue & galerie                                                 */
/* ------------------------------------------------------------------ */

function dash_catalog(): array
{
    analytics_ensure_schema();
    $pdo = db();
    $c = ['total' => 0, 'on_sale' => 0, 'hidden' => 0, 'sold' => 0, 'stock_value' => 0, 'stock_cost' => 0, 'stock_cost_known' => 0,
          'stock_value_cost_known' => 0, 'no_photo' => 0, 'few_photos' => 0, 'photos_avg' => 0.0, 'ai_images' => 0, 'avg_days_to_sell' => null,
          'no_cost' => 0];

    $photoCounts = array_column($pdo->query('SELECT product_ref, COUNT(*) AS n FROM product_photos WHERE is_hidden = 0 GROUP BY product_ref')->fetchAll(), 'n', 'product_ref');
    $sumPhotos = 0;
    $onSaleN = 0;
    foreach (dash_product_map() as $ref => $p) {
        $c['total']++;
        $price = price_to_cents($p['promo_price'] ?: $p['price']) ?? 0;
        if ((int) $p['stock'] <= 0) { $c['sold']++; continue; }
        if ((int) $p['is_hidden']) { $c['hidden']++; continue; }
        $c['on_sale']++;
        $onSaleN++;
        $qty = (int) $p['stock'];
        $c['stock_value'] += $price * $qty;
        if ($p['cost_cents'] !== null) {
            $c['stock_cost'] += (int) $p['cost_cents'] * $qty;
            $c['stock_cost_known'] += $qty;
            $c['stock_value_cost_known'] += $price * $qty;
        } else {
            $c['no_cost']++;
        }
        // Une pièce ajoutée avec une simple photo de couverture n'a pas forcément de ligne dans product_photos.
        $n = max((int) ($photoCounts[$ref] ?? 0), !empty($p['photo']) ? 1 : 0);
        $sumPhotos += $n;
        if ($n === 0) $c['no_photo']++;
        elseif ($n < 3) $c['few_photos']++;
    }
    $c['photos_avg'] = $onSaleN ? $sumPhotos / $onSaleN : 0.0;
    $c['ai_images'] = (int) $pdo->query('SELECT COUNT(*) FROM product_photos WHERE is_illustration = 1')->fetchColumn();

    // Délai moyen entre la mise en ligne et la première vente de chaque pièce.
    $first = [];
    foreach (dash_paid_orders() as $o) {
        foreach ($o['items_list'] as $it) {
            $ref = (string) ($it['ref'] ?? '');
            if ($ref !== '' && !isset($first[$ref])) $first[$ref] = $o['local'];
        }
    }
    $days = [];
    foreach ($first as $ref => $at) {
        $created = dash_product_map()[$ref]['created_at'] ?? null;
        if ($created) $days[] = max(0, (int) round(($at->getTimestamp() - dash_from_utc($created)->getTimestamp()) / 86400));
    }
    $c['avg_days_to_sell'] = $days ? array_sum($days) / count($days) : null;
    return $c;
}

/* ------------------------------------------------------------------ */
/* Saisie : conversions                                                */
/* ------------------------------------------------------------------ */

/** « 12,5 » / « 12.5 € » → 12.5 ; null si vide ou invalide. */
function parse_euros(?string $s): ?float
{
    $s = trim((string) $s);
    if ($s === '') return null;
    $s = str_replace([' ', "\xc2\xa0", '€'], '', $s);
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) return null;
    return (float) $s;
}
