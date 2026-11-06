<?php
// Tableau de bord : mesure d'audience sans cookie et statistiques.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';

const TD_MODULE = 'tableau-de-bord';
const TD_KEEP_DAYS = 400;

function td_setting(string $k, string $d = ''): string
{
    $st = db()->prepare('SELECT value FROM td_settings WHERE key = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $d : (string) $v;
}

function td_setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO td_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$k, $v]);
}

function td_is_bot(string $ua): bool
{
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|curl|wget|python|monitor|lighthouse|pingdom|uptime/i', $ua);
}

/** Empreinte du visiteur pour la journée : ni stockable ailleurs, ni reliable d'un jour à l'autre. */
function td_vid(string $day): string
{
    $secret = td_setting('secret');
    if ($secret === '') { $secret = bin2hex(random_bytes(16)); td_setting_set('secret', $secret); }
    return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . $day . '|' . $secret), 0, 16);
}

/** Classe l'origine de la visite : [source, medium]. */
function td_source(): array
{
    $utm = strtolower(trim((string) ($_GET['utm_source'] ?? '')));
    if ($utm !== '') return [preg_replace('/[^a-z0-9._-]/', '', $utm) ?: 'direct', strtolower(preg_replace('/[^a-z0-9._-]/i', '', (string) ($_GET['utm_medium'] ?? ''))) ?: 'campagne'];
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
    if ($host === '') return ['direct', ''];
    $mine = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === $mine || str_ends_with($host, '.' . $mine)) return ['interne', ''];
    $groups = [
        'recherche' => ['google.', 'bing.', 'duckduckgo.', 'qwant.', 'ecosia.', 'yahoo.'],
        'réseau social' => ['facebook.', 'fb.', 'instagram.', 'tiktok.', 'pinterest.', 'twitter.', 't.co', 'x.com', 'youtube.', 'linkedin.', 'whatsapp.', 'threads.'],
        'place de marché' => ['leboncoin.', 'vinted.', 'ebay.', 'etsy.'],
    ];
    foreach ($groups as $medium => $needles) {
        foreach ($needles as $n) if (str_contains($host, $n)) return [preg_replace('/^(www|m|l|lm)\./', '', $host), $medium];
    }
    return [preg_replace('/^www\./', '', $host), 'site'];
}

/** Enregistre la page vue en cours (appelée à chaque page de la boutique, jamais pour l'administration ni les robots). */
function td_track_view(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || td_is_bot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) return;
    if (isset($_SERVER['HTTP_PURPOSE']) || ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document') !== 'document') return;
    if (function_exists('admin_session') && admin_session() !== null) return;
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($path === '' || str_contains($path, '/admin/') || str_contains($path, '/api/')) return;
    $path = mb_substr($path, 0, 160);
    $ref = str_ends_with($path, 'produit.php') ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['ref'] ?? '')) : '';
    [$source, $medium] = td_source();
    $day = gmdate('Y-m-d');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $device = preg_match('/Mobi|Android|iPhone|iPad/i', $ua) ? 'mobile' : 'ordinateur';
    db()->prepare('INSERT INTO td_views (day, vid, path, product_ref, source, medium, campaign, device) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $day, td_vid($day), $path, $ref, $source, $medium, mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_GET['utm_campaign'] ?? '')), 0, 60), $device]);
    if (td_setting('pruned') !== $day) {
        td_setting_set('pruned', $day);
        $cut = gmdate('Y-m-d', time() - TD_KEEP_DAYS * 86400);
        db()->prepare('DELETE FROM td_views WHERE day < ?')->execute([$cut]);
        db()->prepare('DELETE FROM td_events WHERE day < ?')->execute([$cut]);
    }
}

/** Ajout au panier : rattaché à la source de la première visite externe du jour de ce visiteur. */
function td_track_cart(string $ref): void
{
    if (td_is_bot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) return;
    $day = gmdate('Y-m-d');
    $vid = td_vid($day);
    $st = db()->prepare("SELECT source, campaign FROM td_views WHERE day = ? AND vid = ? AND source <> 'interne' ORDER BY id LIMIT 1");
    $st->execute([$day, $vid]);
    $s = $st->fetch(PDO::FETCH_ASSOC) ?: ['source' => 'direct', 'campaign' => ''];
    db()->prepare("INSERT INTO td_events (day, vid, kind, product_ref, source, campaign) VALUES (?, ?, 'cart_add', ?, ?, ?)")->execute([$day, $vid, preg_replace('/[^A-Za-z0-9_-]/', '', $ref), $s['source'], $s['campaign']]);
}

/** Statistiques de la période. */
function td_stats(int $days): array
{
    $days = max(1, min(365, $days));
    $from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
    $one = static function (string $sql, array $p = []): array {
        $st = db()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $totals = $one("SELECT COUNT(*) AS views, COUNT(DISTINCT day || vid) AS visitors FROM td_views WHERE day >= ?", [$from])[0];
    $products = $one("SELECT COUNT(*) AS n FROM td_views WHERE day >= ? AND product_ref <> ''", [$from])[0]['n'];
    $carts = $one("SELECT COUNT(*) AS n FROM td_events WHERE day >= ? AND kind = 'cart_add'", [$from])[0]['n'];
    $orders = $one("SELECT COUNT(*) AS n, COALESCE(SUM(amount_total), 0) AS total FROM orders WHERE status = 'paid' AND date(created_at) >= ?", [$from])[0];
    $perDay = [];
    foreach ($one('SELECT day, COUNT(*) AS views, COUNT(DISTINCT vid) AS visitors FROM td_views WHERE day >= ? GROUP BY day', [$from]) as $r) $perDay[$r['day']] = $r;
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = gmdate('Y-m-d', time() - $i * 86400);
        $series[] = ['day' => $d, 'views' => (int) ($perDay[$d]['views'] ?? 0), 'visitors' => (int) ($perDay[$d]['visitors'] ?? 0)];
    }
    $topProducts = $one("SELECT v.product_ref AS ref, COALESCE(p.name, v.product_ref) AS name, COUNT(*) AS views, COUNT(DISTINCT v.vid) AS visitors,
        (SELECT COUNT(*) FROM td_events e WHERE e.product_ref = v.product_ref AND e.kind = 'cart_add' AND e.day >= ?) AS carts
        FROM td_views v LEFT JOIN products p ON p.ref = v.product_ref WHERE v.day >= ? AND v.product_ref <> '' GROUP BY v.product_ref ORDER BY views DESC LIMIT 15", [$from, $from]);
    // Une ligne par origine (le type « réseau social » l'emporte sur « campagne » quand les deux existent).
    $sources = $one("SELECT source, CASE WHEN SUM(medium = 'social') > 0 THEN 'social' ELSE MAX(medium) END AS medium, COUNT(*) AS views, COUNT(DISTINCT day || vid) AS visitors,
        SUM(product_ref <> '') AS product_views FROM td_views WHERE day >= ? AND source <> 'interne' GROUP BY source ORDER BY visitors DESC LIMIT 20", [$from]);
    $cartBySource = [];
    foreach ($one("SELECT source, COUNT(*) AS n FROM td_events WHERE day >= ? AND kind = 'cart_add' GROUP BY source", [$from]) as $r) $cartBySource[$r['source']] = (int) $r['n'];
    foreach ($sources as &$s) { $s['carts'] = $cartBySource[$s['source']] ?? 0; $s['views'] = (int) $s['views']; $s['visitors'] = (int) $s['visitors']; $s['product_views'] = (int) $s['product_views']; }
    unset($s);
    $campaigns = $one("SELECT campaign, source, COUNT(DISTINCT day || vid) AS visitors, COUNT(*) AS views FROM td_views WHERE day >= ? AND campaign <> '' GROUP BY campaign, source ORDER BY visitors DESC LIMIT 15", [$from]);
    $devices = $one('SELECT device, COUNT(DISTINCT day || vid) AS visitors FROM td_views WHERE day >= ? GROUP BY device', [$from]);
    return [
        'days' => $days, 'from' => $from, 'views' => (int) $totals['views'], 'visitors' => (int) $totals['visitors'], 'product_views' => (int) $products, 'carts' => (int) $carts,
        'orders' => (int) $orders['n'], 'revenue_cents' => (int) $orders['total'], 'series' => $series, 'top_products' => $topProducts, 'sources' => $sources, 'campaigns' => $campaigns, 'devices' => $devices,
    ];
}
