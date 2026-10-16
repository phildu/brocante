<?php

// Mises en avant des articles en promo : visuel promo (photo + encart « CTA » en overlay, fabriqué dans le navigateur depuis la fiche, voir assets/promo-visual.js),
// affiché sur la page d'accueil de la boutique et dans les promos de la galerie commerciale dont elle fait partie, et COMPTEUR d'affichages et de clics par
// emplacement — de quoi facturer ces mises en avant. Les compteurs sont centraux (data/saas/promo.db) : un déploiement compte ce qu'il sert.
//
// Ce fichier est autonome (galerie/ et le portail l'utilisent sans charger functions.php).

/** Emplacements comptés : « accueil » (page d'accueil de la boutique) et « galerie:<identifiant> » (promos d'une galerie commerciale). */
const PROMO_PLACEMENT_PATTERN = '/^(accueil|galerie:[a-z0-9][a-z0-9-]{1,39})$/';
const PROMO_MAX_PER_MINUTE = 240;

function promo_saas_dir(): string
{
    $dir = dirname(__DIR__) . '/data/saas';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Clé secrète des jetons (créée au premier usage, jamais servie : data/ est interdit au web). */
function promo_secret(): string
{
    $file = promo_saas_dir() . '/promo.secret';
    if (!is_file($file)) @file_put_contents($file, bin2hex(random_bytes(32)));
    return trim((string) @file_get_contents($file)) ?: 'promo-secret';
}

/** Jeton d'un emplacement : une page servie par la plateforme est seule à pouvoir en produire (un compteur ne s'incrémente pas à la main). */
function promo_token(string $shop, string $ref, string $placement): string
{
    return substr(hash_hmac('sha256', $shop . '|' . $ref . '|' . $placement, promo_secret()), 0, 20);
}

function promo_token_valid(string $shop, string $ref, string $placement, string $token): bool
{
    return $token !== '' && hash_equals(promo_token($shop, $ref, $placement), $token);
}

function promo_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . promo_saas_dir() . '/promo.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 3000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS promo_counters (day TEXT NOT NULL, shop TEXT NOT NULL, ref TEXT NOT NULL, placement TEXT NOT NULL,
            impressions INTEGER NOT NULL DEFAULT 0, clicks INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (day, shop, ref, placement))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS promo_hits (who TEXT NOT NULL, minute INTEGER NOT NULL, n INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (who, minute))');
    }
    return $pdo;
}

/** Robots d'indexation et outils : jamais comptés. */
function promo_is_bot(?string $ua = null): bool
{
    $ua = (string) ($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|headless|lighthouse|GTmetrix/i', $ua);
}

/** Frein par adresse IP (240 événements par minute) : un script ne gonfle pas les compteurs. */
function promo_rate_ok(): bool
{
    try {
        $who = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . promo_secret()), 0, 16);
        $minute = intdiv(time(), 60);
        $db = promo_db();
        $db->prepare('INSERT INTO promo_hits (who, minute, n) VALUES (?, ?, 1) ON CONFLICT(who, minute) DO UPDATE SET n = n + 1')->execute([$who, $minute]);
        $n = (int) $db->query('SELECT n FROM promo_hits WHERE who = ' . $db->quote($who) . ' AND minute = ' . $minute)->fetchColumn();
        if (random_int(1, 50) === 1) $db->exec('DELETE FROM promo_hits WHERE minute < ' . ($minute - 10));
        return $n <= PROMO_MAX_PER_MINUTE;
    } catch (Throwable $e) {
        return true;
    }
}

/** Ajoute des affichages ou des clics (jour courant). Retourne faux si l'emplacement est invalide. */
function promo_record(string $shop, string $ref, string $placement, int $impressions, int $clicks): bool
{
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $shop) || $ref === '' || strlen($ref) > 40 || !preg_match(PROMO_PLACEMENT_PATTERN, $placement)) return false;
    try {
        promo_db()->prepare('INSERT INTO promo_counters (day, shop, ref, placement, impressions, clicks) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(day, shop, ref, placement) DO UPDATE SET impressions = impressions + excluded.impressions, clicks = clicks + excluded.clicks')
            ->execute([gmdate('Y-m-d'), $shop, $ref, $placement, $impressions, $clicks]);
        return true;
    } catch (Throwable $e) {
        error_log('promo_record: ' . $e->getMessage());
        return false;
    }
}

/**
 * Compteurs sur une période. $by : 'ref' (par article), 'shop', 'placement'. $filter : shop, placement (préfixe « galerie: » accepté en entier).
 * Retourne [['shop', 'ref', 'placement', 'impressions', 'clicks', 'ctr'], …] (champs non demandés vides).
 */
function promo_stats(string $from, string $to, string $by = 'ref', array $filter = []): array
{
    $cols = ['ref' => 'shop, ref, placement', 'shop' => 'shop', 'placement' => 'placement'][$by] ?? 'shop, ref, placement';
    $where = ['day BETWEEN ? AND ?'];
    $params = [$from, $to];
    if (!empty($filter['shop'])) { $where[] = 'shop = ?'; $params[] = $filter['shop']; }
    if (!empty($filter['placement'])) { $where[] = 'placement = ?'; $params[] = $filter['placement']; }
    try {
        $stmt = promo_db()->prepare("SELECT $cols, SUM(impressions) AS impressions, SUM(clicks) AS clicks FROM promo_counters WHERE " . implode(' AND ', $where) . " GROUP BY $cols ORDER BY impressions DESC, clicks DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        $r += ['shop' => '', 'ref' => '', 'placement' => ''];
        $r['impressions'] = (int) $r['impressions'];
        $r['clicks'] = (int) $r['clicks'];
        $r['ctr'] = $r['impressions'] > 0 ? round($r['clicks'] / $r['impressions'] * 100, 1) : 0.0;
    }
    return $rows;
}

// ── Visuels promo (base du commerce) ────────────────────────────────────────────────────────────────────────────────────────

function promo_visuals_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS promo_visuals (
      ref TEXT PRIMARY KEY,
      path TEXT NOT NULL,
      path_mobile TEXT,
      headline TEXT NOT NULL DEFAULT \'\',
      cta TEXT NOT NULL DEFAULT \'\',
      price TEXT NOT NULL DEFAULT \'\',
      promo_price TEXT NOT NULL DEFAULT \'\',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
}

/** Visuel promo enregistré d'un article (ligne), ou null. */
function promo_visual_get(string $ref): ?array
{
    $stmt = db()->prepare('SELECT * FROM promo_visuals WHERE ref = ?');
    $stmt->execute([$ref]);
    return $stmt->fetch() ?: null;
}

/** Le visuel dit la vérité : l'article est toujours en promo, aux prix inscrits dessus (sinon il n'est plus montré et doit être refait). */
function promo_visual_fresh(array $product, ?array $visual): bool
{
    return $visual && has_promo_price($product) && trim((string) $product['price']) === (string) $visual['price'] && trim((string) $product['promo_price']) === (string) $visual['promo_price']
        && is_file(dirname(__DIR__) . '/' . $visual['path']);
}

/** Articles en promo avec un visuel à jour, les plus récemment générés d'abord : [['product' => ligne, 'visual' => ligne], …]. */
function promo_visuals_live(int $limit = 4): array
{
    $rows = db()->query("SELECT p.*, v.path AS v_path, v.path_mobile AS v_mobile, v.headline AS v_headline, v.cta AS v_cta, v.price AS v_price, v.promo_price AS v_promo_price
        FROM promo_visuals v JOIN products p ON p.ref = v.ref
        WHERE p.badge = 'Promo' AND p.is_hidden = 0 AND p.stock > 0 ORDER BY v.created_at DESC, p.ref")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $visual = ['path' => $r['v_path'], 'path_mobile' => $r['v_mobile'], 'headline' => $r['v_headline'], 'cta' => $r['v_cta'], 'price' => $r['v_price'], 'promo_price' => $r['v_promo_price']];
        if (!promo_visual_fresh($r, $visual)) continue;
        $out[] = ['product' => $r, 'visual' => $visual];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** Supprime les fichiers d'un visuel (remplacement ou retrait). */
function promo_visual_files_delete(?array $visual): void
{
    foreach ([$visual['path'] ?? null, $visual['path_mobile'] ?? null] as $p) {
        if ($p && str_starts_with($p, 'uploads/promo-') && is_file(dirname(__DIR__) . '/' . $p)) @unlink(dirname(__DIR__) . '/' . $p);
    }
}

/**
 * Section « En promo » de la page d'accueil : les visuels promo (photo + encart), chacun un lien compté vers la fiche, avec les balises du compteur
 * d'affichages (assets/promo-track.js). Chaîne vide s'il n'y en a aucun.
 */
function promo_section_html(string $title = 'À saisir en ce moment', string $class = ''): string
{
    $items = promo_visuals_live(4);
    if (!$items) return '';
    $shop = tenant_slug();
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $cards = '';
    foreach ($items as $it) {
        $p = $it['product']; $v = $it['visual'];
        $ref = (string) $p['ref'];
        $tok = promo_token($shop, $ref, 'accueil');
        $href = app_prefix('/promo-click.php') . '?r=' . rawurlencode($ref) . '&p=accueil&t=' . $tok;
        $alt = $p['name'] . ' — ' . $v['promo_price'] . ' au lieu de ' . $v['price'] . '. ' . ($v['cta'] ?: 'Voir l\'offre');
        $cards .= '<a class="promo-visual" href="' . $h($href) . '" data-promo-track data-promo-shop="' . $h($shop) . '" data-promo-ref="' . $h($ref) . '" data-promo-placement="accueil" data-promo-token="' . $h($tok) . '">'
            . '<picture>' . ($v['path_mobile'] ? '<source media="(max-width: 700px)" srcset="/' . $h($v['path_mobile']) . '">' : '')
            . '<img src="/' . $h($v['path']) . '" alt="' . $h($alt) . '" loading="lazy"></picture></a>';
    }
    return '<section class="tight promo-section ' . $h($class) . '" id="promos" aria-label="' . $h($title) . '"><div class="wrap"><h2 class="promo-title">' . $h($title) . '</h2><div class="promo-grid">' . $cards . '</div></div></section>'
        . '<link rel="stylesheet" href="/assets/promo.css?v=' . (int) @filemtime(dirname(__DIR__) . '/assets/promo.css') . '">'
        . '<script src="/assets/promo-track.js?v=' . (int) @filemtime(dirname(__DIR__) . '/assets/promo-track.js') . '" defer></script>';
}
