<?php
/**
 * Module Buvette — gestion d'une buvette / restauration de vide-grenier.
 *
 * Tout ce qui concerne la buvette vit ici (logique métier, schéma SQLite,
 * données d'exemple). Les pages sont dans admin/buvette/ (responsable) et
 * buvette/ (bénévoles, accès par code partagé).
 *
 * Le schéma se crée tout seul au premier accès (bv_boot) : le déploiement
 * (deploy-brocante.sh) n'envoie jamais brocante.db, donc une migration
 * manuelle côté serveur n'est pas envisageable.
 *
 * Conventions : montants en centimes (entiers), quantités en nombres
 * décimaux exprimés dans l'unité de la fourniture (g, L, pièce…).
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

/* ====================================================================== */
/* Schéma                                                                 */
/* ====================================================================== */

const BV_SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS bv_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  event_date TEXT,                              -- AAAA-MM-JJ
  place TEXT NOT NULL DEFAULT '',
  expected_visitors INTEGER NOT NULL DEFAULT 0,
  margin_pct INTEGER NOT NULL DEFAULT 10,       -- marge de sécurité sur les quantités
  notes TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bv_supplies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'ingredient',      -- ingredient | consommable | materiel
  unit TEXT NOT NULL DEFAULT 'pièce',
  pack_qty REAL NOT NULL DEFAULT 1,             -- quantité (dans l'unité) par lot acheté
  pack_label TEXT NOT NULL DEFAULT '',
  pack_price_cents INTEGER NOT NULL DEFAULT 0,  -- prix d'un lot
  stock REAL NOT NULL DEFAULT 0,
  supplier TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bv_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'autre',
  price_cents INTEGER NOT NULL DEFAULT 0,
  description TEXT NOT NULL DEFAULT '',
  allergens TEXT NOT NULL DEFAULT '',           -- clés séparées par des virgules
  is_active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  steps TEXT NOT NULL DEFAULT '',               -- étapes de la recette
  prep_minutes INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bv_recipe_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL REFERENCES bv_items(id) ON DELETE CASCADE,
  supply_id INTEGER NOT NULL REFERENCES bv_supplies(id) ON DELETE CASCADE,
  qty REAL NOT NULL,                            -- par portion, dans l'unité de la fourniture
  UNIQUE (item_id, supply_id)
);

CREATE TABLE IF NOT EXISTS bv_event_items (
  event_id INTEGER NOT NULL REFERENCES bv_events(id) ON DELETE CASCADE,
  item_id INTEGER NOT NULL REFERENCES bv_items(id) ON DELETE CASCADE,
  forecast_qty INTEGER NOT NULL DEFAULT 0,
  sold_qty INTEGER,                             -- NULL = pas encore saisi
  PRIMARY KEY (event_id, item_id)
);

CREATE TABLE IF NOT EXISTS bv_event_supplies (
  event_id INTEGER NOT NULL REFERENCES bv_events(id) ON DELETE CASCADE,
  supply_id INTEGER NOT NULL REFERENCES bv_supplies(id) ON DELETE CASCADE,
  qty REAL NOT NULL,
  PRIMARY KEY (event_id, supply_id)
);

CREATE TABLE IF NOT EXISTS bv_volunteers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bv_shifts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL REFERENCES bv_events(id) ON DELETE CASCADE,
  role TEXT NOT NULL,
  start_time TEXT NOT NULL,                     -- HH:MM
  end_time TEXT NOT NULL,
  needed INTEGER NOT NULL DEFAULT 1,
  notes TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS bv_shift_volunteers (
  shift_id INTEGER NOT NULL REFERENCES bv_shifts(id) ON DELETE CASCADE,
  volunteer_id INTEGER NOT NULL REFERENCES bv_volunteers(id) ON DELETE CASCADE,
  PRIMARY KEY (shift_id, volunteer_id)
);

CREATE TABLE IF NOT EXISTS bv_tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL REFERENCES bv_events(id) ON DELETE CASCADE,
  phase TEXT NOT NULL,
  label TEXT NOT NULL,
  assignee_id INTEGER REFERENCES bv_volunteers(id) ON DELETE SET NULL,
  done INTEGER NOT NULL DEFAULT 0,
  done_at TEXT,
  done_by TEXT NOT NULL DEFAULT '',
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS bv_settings (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS bv_login_attempts (
  ip TEXT NOT NULL,
  at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_bv_recipe_item ON bv_recipe_lines(item_id);
CREATE INDEX IF NOT EXISTS idx_bv_shifts_event ON bv_shifts(event_id);
CREATE INDEX IF NOT EXISTS idx_bv_tasks_event ON bv_tasks(event_id);
CREATE INDEX IF NOT EXISTS idx_bv_login_ip ON bv_login_attempts(ip, at);
SQL;

function bv_boot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (!ini_get('date.timezone') || date_default_timezone_get() === 'UTC') {
        date_default_timezone_set('Europe/Paris');
    }
    db()->exec(BV_SCHEMA);
}

/* ====================================================================== */
/* Référentiels                                                           */
/* ====================================================================== */

/** Les 14 allergènes à déclaration obligatoire (règlement UE 1169/2011). */
function bv_allergens(): array
{
    return [
        'gluten' => 'Gluten',
        'crustaces' => 'Crustacés',
        'oeufs' => 'Œufs',
        'poisson' => 'Poisson',
        'arachides' => 'Arachides',
        'soja' => 'Soja',
        'lait' => 'Lait',
        'fruits_a_coque' => 'Fruits à coque',
        'celeri' => 'Céleri',
        'moutarde' => 'Moutarde',
        'sesame' => 'Sésame',
        'sulfites' => 'Sulfites',
        'lupin' => 'Lupin',
        'mollusques' => 'Mollusques',
    ];
}

function bv_item_categories(): array
{
    return [
        'boissons_chaudes' => 'Boissons chaudes',
        'boissons_froides' => 'Boissons fraîches',
        'sale' => 'Salé',
        'sucre' => 'Sucré',
        'autre' => 'Autre',
    ];
}

function bv_supply_kinds(): array
{
    return [
        'ingredient' => 'Produits & ingrédients',
        'consommable' => 'Consommables (gobelets, serviettes…)',
        'materiel' => 'Matériel à apporter',
    ];
}

function bv_task_phases(): array
{
    return [
        'avant' => 'Avant l\'événement (J-30 à J-7)',
        'veille' => 'La veille',
        'installation' => 'Installation (matin du jour J)',
        'service' => 'Pendant le service',
        'rangement' => 'Rangement & nettoyage',
        'apres' => 'Après l\'événement',
    ];
}

function bv_shift_roles(): array
{
    return ['Installation', 'Caisse', 'Service & boissons', 'Cuisine (plancha / sandwichs)', 'Crêpes', 'Plonge & propreté', 'Rangement & nettoyage', 'Responsable de stand'];
}

/* ====================================================================== */
/* Formatage & saisie                                                     */
/* ====================================================================== */

/** Centimes (éventuellement fractionnaires) -> « 1,50 € ». */
function bv_money($cents): string
{
    return format_cents((int) round((float) $cents));
}

/** Saisie libre (« 1,5 », « 2.50 €», « 3 ») -> centimes, null si illisible. */
function bv_parse_cents(?string $input): ?int
{
    $s = trim(str_replace(['€', ' ', "\u{00A0}"], '', (string) $input));
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $s)) return null;
    return (int) round(((float) $s) * 100);
}

/** Saisie libre décimale (« 0,25 ») -> float, null si illisible. */
function bv_parse_float(?string $input): ?float
{
    $s = trim(str_replace([' ', "\u{00A0}"], '', (string) $input));
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d{1,9}(\.\d{1,4})?$/', $s)) return null;
    return (float) $s;
}

/**
 * Quantité à acheter, arrondie par excès pour l'affichage : à l'unité au-delà
 * de 10 (924 g), au centième en dessous (6,05 L).
 */
function bv_need_qty($q): string
{
    $q = round((float) $q, 6);
    $up = $q >= 10 ? ceil($q) : ceil(round($q * 100, 4)) / 100;
    return bv_qty($up);
}

/** 1.0 -> « 1 », 0.25 -> « 0,25 » (jamais de zéros inutiles). */
function bv_qty($q): string
{
    $s = number_format((float) $q, 3, ',', '');
    return rtrim(rtrim($s, '0'), ',');
}

function bv_fr_date(?string $ymd, bool $withDay = true): string
{
    if (!$ymd || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return '';
    $t = strtotime($ymd . ' 12:00:00');
    if ($t === false) return '';
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return ($withDay ? $days[(int) date('w', $t)] . ' ' : '')
        . (int) date('j', $t) . ' ' . $months[(int) date('n', $t)] . ' ' . date('Y', $t);
}

/** Nombre de jours avant la date (négatif si passée), null si pas de date. */
function bv_days_until(?string $ymd): ?int
{
    if (!$ymd || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return null;
    $a = strtotime($ymd . ' 00:00:00');
    $b = strtotime(date('Y-m-d') . ' 00:00:00');
    if ($a === false || $b === false) return null;
    return (int) round(($a - $b) / 86400);
}

function bv_countdown_label(?string $ymd): string
{
    $d = bv_days_until($ymd);
    if ($d === null) return '';
    if ($d > 1) return 'dans ' . $d . ' jours';
    if ($d === 1) return 'demain';
    if ($d === 0) return 'c\'est aujourd\'hui !';
    return 'terminé depuis ' . abs($d) . ' jour' . (abs($d) > 1 ? 's' : '');
}

function bv_valid_time(?string $t): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $t);
}

function bv_valid_date(?string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $d, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/* ====================================================================== */
/* Événements                                                             */
/* ====================================================================== */

function bv_events_list(): array
{
    return db()->query('SELECT * FROM bv_events ORDER BY COALESCE(event_date, \'9999-12-31\') DESC, id DESC')->fetchAll();
}

function bv_event_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM bv_events WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Événement actif ; à défaut, le plus récent. */
function bv_active_event(): ?array
{
    $row = db()->query('SELECT * FROM bv_events WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch();
    if ($row) return $row;
    return db()->query('SELECT * FROM bv_events ORDER BY COALESCE(event_date, \'9999-12-31\') DESC, id DESC LIMIT 1')->fetch() ?: null;
}

function bv_set_active_event(int $id): void
{
    $pdo = db();
    // Appelée aussi depuis des transactions déjà ouvertes (duplication,
    // données d'exemple) : PDO n'accepte pas les transactions imbriquées.
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    $pdo->exec('UPDATE bv_events SET is_active = 0');
    $pdo->prepare('UPDATE bv_events SET is_active = 1 WHERE id = ?')->execute([$id]);
    if ($own) $pdo->commit();
}

function bv_event_create(string $name, ?string $date, string $place, int $visitors, int $marginPct, string $notes): int
{
    $stmt = db()->prepare('INSERT INTO bv_events (name, event_date, place, expected_visitors, margin_pct, notes) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $date ?: null, $place, max(0, $visitors), max(0, min(100, $marginPct)), $notes]);
    $id = (int) db()->lastInsertId();
    // Le premier événement créé devient automatiquement l'événement actif.
    if ((int) db()->query('SELECT COUNT(*) FROM bv_events WHERE is_active = 1')->fetchColumn() === 0) {
        bv_set_active_event($id);
    }
    return $id;
}

/**
 * Reprend les prévisions, besoins en matériel, créneaux (sans les
 * bénévoles affectés) et check-list (décochée) d'un événement passé —
 * d'une édition à l'autre, on ne repart pas d'une page blanche.
 */
function bv_event_duplicate(int $sourceId, string $name, ?string $date): ?int
{
    $src = bv_event_get($sourceId);
    if (!$src) return null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $newId = bv_event_create($name, $date, $src['place'], (int) $src['expected_visitors'], (int) $src['margin_pct'], $src['notes']);
        $pdo->prepare('INSERT INTO bv_event_items (event_id, item_id, forecast_qty) SELECT ?, item_id, forecast_qty FROM bv_event_items WHERE event_id = ?')->execute([$newId, $sourceId]);
        $pdo->prepare('INSERT INTO bv_event_supplies (event_id, supply_id, qty) SELECT ?, supply_id, qty FROM bv_event_supplies WHERE event_id = ?')->execute([$newId, $sourceId]);
        $pdo->prepare('INSERT INTO bv_shifts (event_id, role, start_time, end_time, needed, notes) SELECT ?, role, start_time, end_time, needed, notes FROM bv_shifts WHERE event_id = ?')->execute([$newId, $sourceId]);
        $pdo->prepare('INSERT INTO bv_tasks (event_id, phase, label, assignee_id, done, sort_order) SELECT ?, phase, label, assignee_id, 0, sort_order FROM bv_tasks WHERE event_id = ?')->execute([$newId, $sourceId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $newId;
}

function bv_event_delete(int $id): void
{
    db()->prepare('DELETE FROM bv_events WHERE id = ?')->execute([$id]);
    if (!(int) db()->query('SELECT COUNT(*) FROM bv_events WHERE is_active = 1')->fetchColumn()) {
        $next = db()->query('SELECT id FROM bv_events ORDER BY COALESCE(event_date, \'9999-12-31\') DESC, id DESC LIMIT 1')->fetchColumn();
        if ($next) bv_set_active_event((int) $next);
    }
}

/* ====================================================================== */
/* Carte (articles vendus)                                                */
/* ====================================================================== */

function bv_items_list(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM bv_items' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name COLLATE NOCASE';
    return db()->query($sql)->fetchAll();
}

function bv_item_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM bv_items WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Articles actifs regroupés par catégorie, dans l'ordre des catégories. */
function bv_items_by_category(bool $activeOnly = true): array
{
    $grouped = [];
    foreach (bv_items_list($activeOnly) as $it) {
        $cat = isset(bv_item_categories()[$it['category']]) ? $it['category'] : 'autre';
        $grouped[$cat][] = $it;
    }
    $ordered = [];
    foreach (array_keys(bv_item_categories()) as $cat) {
        if (!empty($grouped[$cat])) $ordered[$cat] = $grouped[$cat];
    }
    return $ordered;
}

/** Clés d'allergènes d'un article, filtrées sur la liste officielle. */
function bv_item_allergens(array $item): array
{
    $keys = array_filter(array_map('trim', explode(',', (string) ($item['allergens'] ?? ''))));
    return array_values(array_intersect(array_keys(bv_allergens()), $keys));
}

function bv_allergen_labels(array $item): array
{
    $all = bv_allergens();
    return array_map(fn($k) => $all[$k], bv_item_allergens($item));
}

/** Coût de revient d'une portion (centimes, fractionnaire) par article. */
function bv_item_costs(): array
{
    $rows = db()->query('SELECT rl.item_id, SUM(rl.qty * s.pack_price_cents / s.pack_qty) AS cost
        FROM bv_recipe_lines rl JOIN bv_supplies s ON s.id = rl.supply_id GROUP BY rl.item_id')->fetchAll();
    $costs = [];
    foreach ($rows as $r) $costs[(int) $r['item_id']] = (float) $r['cost'];
    return $costs;
}

/** Lignes de recette d'un article, avec le détail des fournitures. */
function bv_recipe_lines(int $itemId): array
{
    $stmt = db()->prepare('SELECT rl.id, rl.qty, s.id AS supply_id, s.name, s.unit, s.kind,
            (rl.qty * s.pack_price_cents / s.pack_qty) AS line_cost
        FROM bv_recipe_lines rl JOIN bv_supplies s ON s.id = rl.supply_id
        WHERE rl.item_id = ?
        ORDER BY CASE s.kind WHEN \'ingredient\' THEN 0 WHEN \'consommable\' THEN 1 ELSE 2 END, s.name COLLATE NOCASE');
    $stmt->execute([$itemId]);
    return $stmt->fetchAll();
}

/* ====================================================================== */
/* Fournitures                                                            */
/* ====================================================================== */

function bv_supplies_list(?string $kind = null): array
{
    if ($kind) {
        $stmt = db()->prepare('SELECT * FROM bv_supplies WHERE kind = ? ORDER BY name COLLATE NOCASE');
        $stmt->execute([$kind]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM bv_supplies ORDER BY name COLLATE NOCASE')->fetchAll();
}

function bv_supplies_by_kind(): array
{
    $grouped = [];
    foreach (bv_supplies_list() as $s) {
        $kind = isset(bv_supply_kinds()[$s['kind']]) ? $s['kind'] : 'ingredient';
        $grouped[$kind][] = $s;
    }
    $ordered = [];
    foreach (array_keys(bv_supply_kinds()) as $k) {
        if (!empty($grouped[$k])) $ordered[$k] = $grouped[$k];
    }
    return $ordered;
}

/** Prix d'une unité (centimes, fractionnaire) : prix du lot / quantité du lot. */
function bv_supply_unit_cost(array $s): float
{
    $q = (float) ($s['pack_qty'] ?? 0);
    return $q > 0 ? ((float) $s['pack_price_cents']) / $q : 0.0;
}

/* ====================================================================== */
/* Prévisionnel, besoins & bilan                                          */
/* ====================================================================== */

/** item_id => ['forecast' => int, 'sold' => ?int] pour un événement. */
function bv_event_forecast(int $eventId): array
{
    $stmt = db()->prepare('SELECT item_id, forecast_qty, sold_qty FROM bv_event_items WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int) $r['item_id']] = [
            'forecast' => (int) $r['forecast_qty'],
            'sold' => $r['sold_qty'] === null ? null : (int) $r['sold_qty'],
        ];
    }
    return $map;
}

/**
 * Besoins en fournitures pour un événement : ce que demandent les recettes
 * (prévision × quantité par portion, + marge de sécurité) plus les besoins
 * saisis à la main (tables, glacières…), moins le stock disponible.
 * Les articles retirés de la carte ne comptent pas.
 */
function bv_event_needs(int $eventId): array
{
    $event = bv_event_get($eventId);
    if (!$event) return [];
    $factor = 1 + max(0, (int) $event['margin_pct']) / 100;

    $stmt = db()->prepare('SELECT rl.supply_id, SUM(rl.qty * ei.forecast_qty) AS need
        FROM bv_recipe_lines rl
        JOIN bv_event_items ei ON ei.item_id = rl.item_id AND ei.event_id = ?
        JOIN bv_items i ON i.id = rl.item_id AND i.is_active = 1
        GROUP BY rl.supply_id');
    $stmt->execute([$eventId]);
    $need = [];
    foreach ($stmt->fetchAll() as $r) {
        // round() : 120 × 7 × 1,1 vaut 924.0000000000001 en flottant — sans cet
        // arrondi, l'arrondi au lot supérieur ferait afficher « 924,01 g ».
        $need[(int) $r['supply_id']]['recipe'] = round((float) $r['need'] * $factor, 6);
    }

    $stmt = db()->prepare('SELECT supply_id, qty FROM bv_event_supplies WHERE event_id = ?');
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll() as $r) {
        $need[(int) $r['supply_id']]['manual'] = (float) $r['qty'];
    }

    $rows = [];
    foreach (bv_supplies_list() as $s) {
        $id = (int) $s['id'];
        if (!isset($need[$id])) continue;
        $recipe = $need[$id]['recipe'] ?? 0.0;
        $manual = $need[$id]['manual'] ?? 0.0;
        $total = round($recipe + $manual, 6);
        $toBuy = round(max(0.0, $total - (float) $s['stock']), 6);
        $packQty = max(0.001, (float) $s['pack_qty']);
        $packs = $toBuy > 0 ? (int) ceil($toBuy / $packQty - 1e-9) : 0;
        $rows[] = $s + [
            'need_recipe' => $recipe,
            'need_manual' => $manual,
            'need_total' => $total,
            'to_buy' => $toBuy,
            'packs' => $packs,
            'cost_cents' => $packs * (int) $s['pack_price_cents'],
        ];
    }
    return $rows;
}

/** Besoins saisis à la main pour un événement : supply_id => qty. */
function bv_event_manual_supplies(int $eventId): array
{
    $stmt = db()->prepare('SELECT supply_id, qty FROM bv_event_supplies WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) $map[(int) $r['supply_id']] = (float) $r['qty'];
    return $map;
}

/** Chiffres clés d'un événement : prévu, réel et budget de courses. */
function bv_event_totals(int $eventId): array
{
    $costs = bv_item_costs();
    $fc = bv_event_forecast($eventId);
    $t = [
        'revenue_forecast' => 0, 'cost_forecast' => 0.0,
        'revenue_actual' => 0, 'cost_actual' => 0.0, 'has_actual' => false,
        'portions_forecast' => 0, 'portions_sold' => 0,
        'shopping_cents' => 0, 'shopping_lines' => 0,
    ];
    foreach (bv_items_list(true) as $it) {
        $id = (int) $it['id'];
        $f = $fc[$id]['forecast'] ?? 0;
        $s = $fc[$id]['sold'] ?? null;
        $cost = $costs[$id] ?? 0.0;
        $t['revenue_forecast'] += $f * (int) $it['price_cents'];
        $t['cost_forecast'] += $f * $cost;
        $t['portions_forecast'] += $f;
        if ($s !== null) {
            $t['has_actual'] = true;
            $t['revenue_actual'] += $s * (int) $it['price_cents'];
            $t['cost_actual'] += $s * $cost;
            $t['portions_sold'] += $s;
        }
    }
    foreach (bv_event_needs($eventId) as $n) {
        $t['shopping_cents'] += $n['cost_cents'];
        if ($n['packs'] > 0 && $n['kind'] !== 'materiel') $t['shopping_lines']++;
    }
    $t['margin_forecast'] = $t['revenue_forecast'] - $t['cost_forecast'];
    $t['margin_actual'] = $t['revenue_actual'] - $t['cost_actual'];
    return $t;
}

/** Articles actifs sans aucune ligne de recette (coût de revient inconnu). */
function bv_items_without_recipe(): array
{
    return db()->query('SELECT i.* FROM bv_items i WHERE i.is_active = 1
        AND NOT EXISTS (SELECT 1 FROM bv_recipe_lines rl WHERE rl.item_id = i.id)
        ORDER BY i.sort_order, i.name COLLATE NOCASE')->fetchAll();
}

/* ====================================================================== */
/* Bénévoles & planning                                                   */
/* ====================================================================== */

function bv_volunteers_list(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM bv_volunteers' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name COLLATE NOCASE';
    return db()->query($sql)->fetchAll();
}

/** Créneaux d'un événement, avec les bénévoles affectés (id => nom). */
function bv_shifts(int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM bv_shifts WHERE event_id = ? ORDER BY start_time, end_time, role');
    $stmt->execute([$eventId]);
    $shifts = $stmt->fetchAll();
    if (!$shifts) return [];

    $assign = db()->prepare('SELECT sv.shift_id, v.id, v.name FROM bv_shift_volunteers sv
        JOIN bv_volunteers v ON v.id = sv.volunteer_id
        JOIN bv_shifts s ON s.id = sv.shift_id AND s.event_id = ?
        ORDER BY v.name COLLATE NOCASE');
    $assign->execute([$eventId]);
    $byShift = [];
    foreach ($assign->fetchAll() as $r) {
        $byShift[(int) $r['shift_id']][(int) $r['id']] = $r['name'];
    }
    foreach ($shifts as &$s) {
        $s['volunteers'] = $byShift[(int) $s['id']] ?? [];
        $s['missing'] = max(0, (int) $s['needed'] - count($s['volunteers']));
    }
    unset($s);
    return $shifts;
}

/** Statistiques de couverture : postes à pourvoir vs pourvus. */
function bv_shift_coverage(array $shifts): array
{
    $needed = 0;
    $filled = 0;
    $gaps = 0;
    foreach ($shifts as $s) {
        $needed += (int) $s['needed'];
        $filled += min((int) $s['needed'], count($s['volunteers']));
        if ($s['missing'] > 0) $gaps++;
    }
    return ['needed' => $needed, 'filled' => $filled, 'gaps' => $gaps];
}

/** Bénévoles placés sur deux créneaux qui se chevauchent. */
function bv_shift_conflicts(array $shifts): array
{
    $byVol = [];
    foreach ($shifts as $s) {
        foreach ($s['volunteers'] as $vid => $vname) {
            $byVol[$vid]['name'] = $vname;
            $byVol[$vid]['shifts'][] = $s;
        }
    }
    $conflicts = [];
    foreach ($byVol as $vid => $v) {
        $list = $v['shifts'];
        for ($i = 0; $i < count($list); $i++) {
            for ($j = $i + 1; $j < count($list); $j++) {
                $a = $list[$i];
                $b = $list[$j];
                if ($a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time']) {
                    $conflicts[] = ['volunteer_id' => $vid, 'volunteer' => $v['name'], 'a' => $a, 'b' => $b];
                }
            }
        }
    }
    return $conflicts;
}

/** Remplace les bénévoles affectés à un créneau. */
function bv_shift_assign(int $shiftId, array $volunteerIds): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM bv_shift_volunteers WHERE shift_id = ?')->execute([$shiftId]);
    $ins = $pdo->prepare('INSERT OR IGNORE INTO bv_shift_volunteers (shift_id, volunteer_id) SELECT ?, id FROM bv_volunteers WHERE id = ?');
    foreach (array_unique(array_map('intval', $volunteerIds)) as $vid) {
        $ins->execute([$shiftId, $vid]);
    }
    $pdo->commit();
}

function bv_add_shift(int $eventId, string $role, string $start, string $end, int $needed, string $notes = ''): int
{
    $stmt = db()->prepare('INSERT INTO bv_shifts (event_id, role, start_time, end_time, needed, notes) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$eventId, $role, $start, $end, max(1, $needed), $notes]);
    return (int) db()->lastInsertId();
}

/** Créneaux types d'un vide-grenier d'une journée. */
function bv_default_shifts(): array
{
    return [
        ['Installation', '07:00', '09:00', 3, 'Montage du barnum, des tables, du matériel'],
        ['Caisse', '09:00', '12:00', 1, ''],
        ['Service & boissons', '09:00', '12:00', 2, ''],
        ['Cuisine (plancha / sandwichs)', '09:00', '12:00', 2, ''],
        ['Caisse', '12:00', '15:00', 1, ''],
        ['Service & boissons', '12:00', '15:00', 2, ''],
        ['Cuisine (plancha / sandwichs)', '12:00', '15:00', 2, 'Pic d\'affluence du repas'],
        ['Caisse', '15:00', '18:00', 1, ''],
        ['Service & boissons', '15:00', '18:00', 2, ''],
        ['Rangement & nettoyage', '17:00', '18:30', 3, ''],
    ];
}

function bv_load_default_shifts(int $eventId): int
{
    $n = 0;
    foreach (bv_default_shifts() as [$role, $start, $end, $needed, $notes]) {
        bv_add_shift($eventId, $role, $start, $end, $needed, $notes);
        $n++;
    }
    return $n;
}

/* ====================================================================== */
/* Check-list                                                             */
/* ====================================================================== */

/**
 * Check-list type d'une buvette de vide-grenier. Les points réglementaires
 * (autorisation de buvette, hygiène, allergènes) sont des rappels : les
 * règles exactes dépendent de la commune et de la nature des produits
 * vendus — la mairie reste l'interlocuteur à consulter.
 */
function bv_default_checklist(): array
{
    return [
        'avant' => [
            'Fixer la date et le lieu avec la mairie ou le propriétaire du terrain',
            'Demander l\'autorisation de buvette temporaire en mairie (se renseigner sur le délai et les boissons autorisées)',
            'Vérifier que l\'assurance de l\'association couvre la buvette et la vente de nourriture',
            'Définir la carte et les prix (onglet « Carte & prix »)',
            'Recruter les bénévoles et construire le planning',
            'Réserver ou emprunter le matériel : barnum, tables, glacières, bouilloire ou percolateur, plancha',
            'Vérifier l\'accès à l\'électricité (puissance, rallonges) et à un point d\'eau potable',
            'Estimer les quantités et établir la liste de courses (onglets « Prévisionnel » et « Courses »)',
        ],
        'veille' => [
            'Faire les courses et garder tous les tickets pour la comptabilité',
            'Préparer ce qui peut l\'être à l\'avance (gâteaux, pâte à crêpes, sauces)',
            'Mettre les boissons et les produits frais au froid',
            'Imprimer et plastifier la carte avec les prix et l\'affichage des allergènes',
            'Préparer le fond de caisse : pièces et petites coupures, boîte ou caisse fermant à clé',
            'Envoyer un rappel aux bénévoles avec leurs horaires et le lieu',
        ],
        'installation' => [
            'Monter le barnum, les tables et le comptoir',
            'Brancher et tester l\'électricité, la bouilloire ou le percolateur, la plancha',
            'Installer le point de lavage des mains (eau, savon, essuie-mains)',
            'Afficher la carte, les prix et la liste des allergènes bien en vue',
            'Installer les glacières et contrôler la température des produits frais',
            'Compter le fond de caisse et le noter',
            'Briefing des bénévoles : rôles, prix, hygiène, qui prévenir en cas de problème',
            'Prévoir les poubelles et le tri des déchets',
        ],
        'service' => [
            'Se laver les mains en prenant son poste, cheveux attachés',
            'Réapprovisionner régulièrement : gobelets, café, boissons fraîches, pain',
            'Surveiller la température des produits frais',
            'Faire un point de caisse intermédiaire (par exemple à midi)',
            'Prévoir des pauses et de quoi boire et manger pour les bénévoles',
            'Garder le stand et ses abords propres',
        ],
        'rangement' => [
            'Compter la caisse finale à deux personnes et noter le montant',
            'Jeter les produits périssables entamés, ranger ceux qui se conservent',
            'Nettoyer les plans de travail, le matériel et le sol',
            'Trier et évacuer les déchets',
            'Rendre le matériel emprunté et noter ce qui manque ou est abîmé',
            'Mettre à jour les stocks restants (onglet « Fournitures »)',
        ],
        'apres' => [
            'Saisir les quantités vendues pour le bilan (onglet « Prévisionnel »)',
            'Calculer le résultat et le présenter au bureau',
            'Remercier les bénévoles',
            'Noter ce qui a manqué ou ce qui est resté invendu pour la prochaine édition',
            'Classer les tickets de courses et le décompte de caisse',
        ],
    ];
}

/** Ajoute les tâches type absentes (comparaison sur la phase + le libellé). Retourne le nombre ajouté. */
function bv_load_default_checklist(int $eventId): int
{
    $stmt = db()->prepare('SELECT phase, label FROM bv_tasks WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $existing = [];
    foreach ($stmt->fetchAll() as $r) $existing[$r['phase'] . '|' . $r['label']] = true;

    $order = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM bv_tasks WHERE event_id = ' . (int) $eventId)->fetchColumn();
    $ins = db()->prepare('INSERT INTO bv_tasks (event_id, phase, label, sort_order) VALUES (?, ?, ?, ?)');
    $n = 0;
    foreach (bv_default_checklist() as $phase => $labels) {
        foreach ($labels as $label) {
            if (isset($existing[$phase . '|' . $label])) continue;
            $ins->execute([$eventId, $phase, $label, ++$order]);
            $n++;
        }
    }
    return $n;
}

/** Tâches d'un événement regroupées par phase (dans l'ordre chronologique). */
function bv_tasks_by_phase(int $eventId): array
{
    $stmt = db()->prepare('SELECT t.*, v.name AS assignee_name FROM bv_tasks t
        LEFT JOIN bv_volunteers v ON v.id = t.assignee_id
        WHERE t.event_id = ? ORDER BY t.sort_order, t.id');
    $stmt->execute([$eventId]);
    $grouped = [];
    foreach ($stmt->fetchAll() as $t) {
        $phase = isset(bv_task_phases()[$t['phase']]) ? $t['phase'] : 'avant';
        $grouped[$phase][] = $t;
    }
    $ordered = [];
    foreach (array_keys(bv_task_phases()) as $p) {
        if (!empty($grouped[$p])) $ordered[$p] = $grouped[$p];
    }
    return $ordered;
}

/** ['done' => n, 'total' => n, 'pct' => 0..100] */
function bv_task_progress(int $eventId): array
{
    $stmt = db()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(done), 0) AS done FROM bv_tasks WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $r = $stmt->fetch();
    $total = (int) $r['total'];
    $done = (int) $r['done'];
    return ['done' => $done, 'total' => $total, 'pct' => $total ? (int) round($done * 100 / $total) : 0];
}

/* ====================================================================== */
/* Réglages & accès bénévoles                                             */
/* ====================================================================== */

function bv_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT v FROM bv_settings WHERE k = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function bv_set_setting(string $key, string $value): void
{
    db()->prepare('INSERT OR REPLACE INTO bv_settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
}

/** Code partagé donné aux bénévoles ('' = espace bénévoles désactivé). */
function bv_volunteer_code(): string
{
    return bv_setting('volunteer_code');
}

function bv_volunteer_space_url(): string
{
    $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    return $base . '/buvette/';
}

function bv_is_volunteer(): bool
{
    // Le personnel connecté n'a besoin du code bénévole que s'il a le droit de consulter la buvette.
    return !empty($_SESSION['bv_volunteer']) || auth_can('portail.buvette.lire');
}

function bv_require_volunteer(): void
{
    if (!bv_is_volunteer()) {
        header('Location: /buvette/login.php');
        exit;
    }
}

const BV_LOGIN_MAX_ATTEMPTS = 5;
const BV_LOGIN_WINDOW_SECONDS = 900;

function bv_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function bv_login_blocked(): bool
{
    db()->prepare('DELETE FROM bv_login_attempts WHERE at < ?')->execute([time() - BV_LOGIN_WINDOW_SECONDS]);
    $stmt = db()->prepare('SELECT COUNT(*) FROM bv_login_attempts WHERE ip = ? AND at >= ?');
    $stmt->execute([bv_client_ip(), time() - BV_LOGIN_WINDOW_SECONDS]);
    return (int) $stmt->fetchColumn() >= BV_LOGIN_MAX_ATTEMPTS;
}

function bv_login_record_failure(): void
{
    db()->prepare('INSERT INTO bv_login_attempts (ip, at) VALUES (?, ?)')->execute([bv_client_ip(), time()]);
}

/** Tente la connexion bénévole. Retourne null si OK, sinon le message d'erreur. */
function bv_volunteer_login(string $submitted): ?string
{
    $code = bv_volunteer_code();
    if ($code === '') {
        return 'L\'espace bénévoles n\'est pas encore ouvert : demandez au responsable de définir un code.';
    }
    if (bv_login_blocked()) {
        return 'Trop d\'essais. Réessayez dans quelques minutes.';
    }
    if (hash_equals($code, trim($submitted))) {
        db()->prepare('DELETE FROM bv_login_attempts WHERE ip = ?')->execute([bv_client_ip()]);
        session_regenerate_id(true);
        $_SESSION['bv_volunteer'] = true;
        return null;
    }
    bv_login_record_failure();
    return 'Code incorrect.';
}

/** Bénévole « Je suis… » choisi sur l'accueil (id), ou null. */
function bv_me(): ?array
{
    $id = (int) ($_SESSION['bv_me'] ?? 0);
    if (!$id) return null;
    $stmt = db()->prepare('SELECT * FROM bv_volunteers WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/* ====================================================================== */
/* Données d'exemple                                                      */
/* ====================================================================== */

function bv_is_empty(): bool
{
    foreach (['bv_events', 'bv_items', 'bv_supplies'] as $t) {
        if ((int) db()->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0) return false;
    }
    return true;
}

/**
 * Charge une buvette de vide-grenier complète et réaliste (fournitures,
 * carte, recettes, un événement avec prévisions, créneaux et check-list).
 * Prix et quantités sont indicatifs : à ajuster. Allergènes à vérifier sur
 * les étiquettes des produits réellement achetés.
 * Ne fait rien si le module contient déjà des données.
 */
function bv_seed_example(): bool
{
    if (!bv_is_empty()) return false;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // nom, type, unité, qté par lot, nom du lot, prix du lot (centimes), stock
        $supplies = [
            'cafe' => ['Café moulu', 'ingredient', 'g', 1000, 'paquet de 1 kg', 1090, 0],
            'the' => ['Thé et infusions (sachets)', 'ingredient', 'pièce', 100, 'boîte de 100', 790, 0],
            'sucre_dose' => ['Sucre en dosettes', 'ingredient', 'pièce', 500, 'boîte de 500', 590, 0],
            'eau' => ['Eau plate 50 cl', 'ingredient', 'pièce', 24, 'pack de 24', 700, 0],
            'soda' => ['Soda en canette 33 cl', 'ingredient', 'pièce', 24, 'pack de 24', 1150, 0],
            'jus' => ['Jus de fruits 1 L', 'ingredient', 'L', 6, 'carton de 6', 790, 0],
            'baguette' => ['Baguette', 'ingredient', 'pièce', 1, 'baguette', 110, 0],
            'jambon' => ['Jambon blanc', 'ingredient', 'g', 1000, 'lot de 1 kg', 1290, 0],
            'beurre' => ['Beurre', 'ingredient', 'g', 250, 'plaquette de 250 g', 280, 0],
            'saucisse' => ['Saucisses de Strasbourg', 'ingredient', 'pièce', 10, 'lot de 10', 450, 0],
            'pain_hotdog' => ['Pains à hot-dog', 'ingredient', 'pièce', 8, 'sachet de 8', 220, 0],
            'farine' => ['Farine', 'ingredient', 'g', 1000, 'paquet de 1 kg', 130, 0],
            'oeufs' => ['Œufs', 'ingredient', 'pièce', 12, 'boîte de 12', 360, 0],
            'lait' => ['Lait', 'ingredient', 'L', 6, 'pack de 6 L', 780, 0],
            'sucre' => ['Sucre en poudre', 'ingredient', 'g', 1000, 'paquet de 1 kg', 150, 0],
            'pate_tartiner' => ['Pâte à tartiner', 'ingredient', 'g', 750, 'pot de 750 g', 450, 0],
            'gobelet' => ['Gobelets carton 20 cl', 'consommable', 'pièce', 100, 'sachet de 100', 590, 0],
            'touillette' => ['Touillettes bois', 'consommable', 'pièce', 500, 'boîte de 500', 450, 0],
            'serviette' => ['Serviettes papier', 'consommable', 'pièce', 200, 'paquet de 200', 190, 0],
            'papier_sandwich' => ['Papier d\'emballage sandwich', 'consommable', 'pièce', 100, 'paquet de 100', 450, 0],
            'assiette' => ['Assiettes carton (crêpes, gâteaux)', 'consommable', 'pièce', 50, 'paquet de 50', 390, 0],
            'sac_poubelle' => ['Sacs poubelle 50 L', 'consommable', 'pièce', 20, 'rouleau de 20', 490, 0],
            'table' => ['Tables pliantes', 'materiel', 'pièce', 1, 'pièce', 0, 2],
            'barnum' => ['Barnum 3 × 6 m', 'materiel', 'pièce', 1, 'pièce', 0, 1],
            'percolateur' => ['Percolateur ou grosse bouilloire', 'materiel', 'pièce', 1, 'pièce', 0, 1],
            'plancha' => ['Plancha ou gaufrier / crêpière', 'materiel', 'pièce', 1, 'pièce', 0, 0],
            'glaciere' => ['Glacières', 'materiel', 'pièce', 1, 'pièce', 0, 2],
            'rallonge' => ['Rallonges électriques', 'materiel', 'pièce', 1, 'pièce', 0, 2],
        ];
        $insSupply = $pdo->prepare('INSERT INTO bv_supplies (name, kind, unit, pack_qty, pack_label, pack_price_cents, stock) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $sid = [];
        foreach ($supplies as $key => $s) {
            $insSupply->execute($s);
            $sid[$key] = (int) $pdo->lastInsertId();
        }

        // clé, nom, catégorie, prix, allergènes, minutes, étapes, lignes [fourniture => qté par portion]
        $items = [
            ['cafe', 'Café', 'boissons_chaudes', 100, '', 1,
                "Lancer le percolateur une demi-heure avant l'ouverture.\nServir dans un gobelet, proposer sucre et touillette.\nRelancer une fournée dès que le niveau baisse.",
                ['cafe' => 7, 'gobelet' => 1, 'sucre_dose' => 0.5, 'touillette' => 1]],
            ['the', 'Thé / infusion', 'boissons_chaudes', 100, '', 1,
                "Faire chauffer l'eau à la bouilloire.\nSachet dans le gobelet, verser l'eau chaude, laisser infuser 3 minutes.",
                ['the' => 1, 'gobelet' => 1, 'sucre_dose' => 0.5, 'touillette' => 1]],
            ['eau', 'Eau 50 cl', 'boissons_froides', 100, '', 0,
                "Servir bien fraîche : réassortir la glacière régulièrement.",
                ['eau' => 1]],
            ['soda', 'Soda (canette)', 'boissons_froides', 150, '', 0,
                "Servir bien frais, réassortir la glacière régulièrement.",
                ['soda' => 1]],
            ['jus', 'Jus de fruits', 'boissons_froides', 150, '', 1,
                "Servir un verre de 20 cl dans un gobelet.\nGarder les briques ouvertes au frais.",
                ['jus' => 0.2, 'gobelet' => 1]],
            ['sandwich', 'Sandwich jambon-beurre', 'sale', 350, 'gluten,lait', 2,
                "Couper la baguette en deux, puis chaque moitié dans la longueur.\nBeurrer, garnir de 50 g de jambon.\nEmballer dans le papier, servir avec une serviette.\nPréparer à l'avance et garder au froid jusqu'au service.",
                ['baguette' => 0.5, 'jambon' => 50, 'beurre' => 10, 'papier_sandwich' => 1, 'serviette' => 1]],
            ['hotdog', 'Hot-dog', 'sale', 300, 'gluten,moutarde', 2,
                "Chauffer les saucisses dans l'eau frémissante (jamais bouillante) ou sur la plancha.\nRéchauffer le pain, glisser la saucisse, ajouter moutarde et ketchup à la demande.\nServir avec une serviette.",
                ['saucisse' => 1, 'pain_hotdog' => 1, 'serviette' => 1]],
            ['crepe_sucre', 'Crêpe au sucre', 'sucre', 150, 'gluten,oeufs,lait', 2,
                "Pâte (pour environ 20 crêpes) : 500 g de farine, 8 œufs, 1 L de lait. Mélanger sans grumeaux, laisser reposer 1 h.\nCuire à la crêpière bien chaude, saupoudrer de sucre, plier en quatre.\nServir sur une assiette en carton.",
                ['farine' => 25, 'oeufs' => 0.4, 'lait' => 0.05, 'sucre' => 5, 'assiette' => 1]],
            ['crepe_tartiner', 'Crêpe pâte à tartiner', 'sucre', 250, 'gluten,oeufs,lait,fruits_a_coque,soja', 2,
                "Même pâte que la crêpe au sucre.\nÉtaler la pâte à tartiner sur la crêpe chaude, plier en quatre.\nServir sur une assiette en carton.",
                ['farine' => 25, 'oeufs' => 0.4, 'lait' => 0.05, 'pate_tartiner' => 20, 'assiette' => 1]],
            ['gateau', 'Part de gâteau maison', 'sucre', 200, 'gluten,oeufs,lait', 1,
                "Gâteaux apportés par les bénévoles : noter la composition de chacun (allergènes !) sur une fiche posée devant.\nCouper en parts régulières.",
                ['assiette' => 1, 'serviette' => 1]],
        ];
        $insItem = $pdo->prepare('INSERT INTO bv_items (name, category, price_cents, allergens, prep_minutes, steps, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insLine = $pdo->prepare('INSERT INTO bv_recipe_lines (item_id, supply_id, qty) VALUES (?, ?, ?)');
        $iid = [];
        foreach ($items as $order => $it) {
            [$key, $name, $cat, $price, $allergens, $minutes, $steps, $lines] = $it;
            $insItem->execute([$name, $cat, $price, $allergens, $minutes, $steps, $order]);
            $iid[$key] = (int) $pdo->lastInsertId();
            foreach ($lines as $supplyKey => $qty) {
                $insLine->execute([$iid[$key], $sid[$supplyKey], $qty]);
            }
        }

        $date = date('Y-m-d', strtotime('+30 days'));
        $eventId = bv_event_create('Vide-grenier — édition d\'exemple', $date, 'Place du village', 400, 10, 'Données d\'exemple : remplacez-les par les vôtres.');

        $forecast = ['cafe' => 120, 'the' => 25, 'eau' => 80, 'soda' => 70, 'jus' => 40, 'sandwich' => 60,
            'hotdog' => 50, 'crepe_sucre' => 60, 'crepe_tartiner' => 50, 'gateau' => 40];
        $insFc = $pdo->prepare('INSERT INTO bv_event_items (event_id, item_id, forecast_qty) VALUES (?, ?, ?)');
        foreach ($forecast as $key => $qty) $insFc->execute([$eventId, $iid[$key], $qty]);

        $manual = ['table' => 4, 'barnum' => 1, 'percolateur' => 1, 'plancha' => 1, 'glaciere' => 3, 'rallonge' => 3, 'sac_poubelle' => 10];
        $insMan = $pdo->prepare('INSERT INTO bv_event_supplies (event_id, supply_id, qty) VALUES (?, ?, ?)');
        foreach ($manual as $key => $qty) $insMan->execute([$eventId, $sid[$key], $qty]);

        bv_load_default_shifts($eventId);
        bv_load_default_checklist($eventId);

        $insVol = $pdo->prepare('INSERT INTO bv_volunteers (name) VALUES (?)');
        foreach (['Camille', 'Julien', 'Nadia', 'Pierre'] as $n) $insVol->execute([$n]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return true;
}
