<?php
/**
 * Module Comptage — comptage de la foule d'un vide-grenier depuis un smartphone.
 *
 * Des bénévoles postés aux entrées (« postes ») appuient sur un gros bouton à
 * chaque visiteur qui entre (ou qui sort). Chaque appui est enregistré avec
 * son heure : on obtient le nombre de visiteurs, le nombre de personnes
 * présentes en même temps, le pic de fréquentation et la courbe de la journée.
 *
 * Les pages sont dans admin/comptage/ (responsable) et comptage/ (bénévoles,
 * accès par code partagé, utilisable hors connexion : les appuis sont mis en
 * file d'attente sur le téléphone puis envoyés dès que le réseau revient).
 *
 * Le schéma se crée tout seul au premier accès (cp_boot) : le déploiement
 * (deploy-brocante.sh) n'envoie jamais brocante.db.
 *
 * Aucune donnée personnelle n'est collectée : uniquement des compteurs.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

/* ====================================================================== */
/* Schéma                                                                 */
/* ====================================================================== */

const CP_SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS cp_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  event_date TEXT,                              -- AAAA-MM-JJ
  place TEXT NOT NULL DEFAULT '',
  capacity INTEGER NOT NULL DEFAULT 0,          -- 0 = pas de jauge
  track_exits INTEGER NOT NULL DEFAULT 1,       -- 0 = on ne compte que les entrées
  is_active INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cp_gates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL REFERENCES cp_events(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0
);

-- Un appui = une ligne. dir : +1 entrée, -1 sortie. ts : heure de l'appui
-- (secondes Unix), fixée par le téléphone pour rester juste même hors ligne.
-- uid : identifiant tiré au hasard par le téléphone, qui rend l'envoi
-- idempotent (un renvoi après coupure réseau ne compte pas deux fois).
CREATE TABLE IF NOT EXISTS cp_taps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  uid TEXT NOT NULL UNIQUE,
  event_id INTEGER NOT NULL REFERENCES cp_events(id) ON DELETE CASCADE,
  gate_id INTEGER NOT NULL REFERENCES cp_gates(id) ON DELETE CASCADE,
  dir INTEGER NOT NULL,
  qty INTEGER NOT NULL DEFAULT 1,
  ts INTEGER NOT NULL,
  kind TEXT NOT NULL DEFAULT 'tap',             -- tap | correction
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cp_settings (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS cp_login_attempts (
  ip TEXT NOT NULL,
  at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_cp_gates_event ON cp_gates(event_id);
CREATE INDEX IF NOT EXISTS idx_cp_taps_event_ts ON cp_taps(event_id, ts);
CREATE INDEX IF NOT EXISTS idx_cp_taps_gate ON cp_taps(gate_id);
CREATE INDEX IF NOT EXISTS idx_cp_login_ip ON cp_login_attempts(ip, at);
SQL;

/** Quantités proposées sur le téléphone (groupes, familles…). */
const CP_QTY_CHOICES = [1, 2, 3, 5, 10];
/** Plafond d'un appui : au-delà, c'est une erreur de manipulation. */
const CP_MAX_QTY = 50;
/** Nombre maximal d'opérations acceptées en un envoi. */
const CP_MAX_OPS = 500;

function cp_boot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (!ini_get('date.timezone') || date_default_timezone_get() === 'UTC') {
        date_default_timezone_set('Europe/Paris');
    }
    db()->exec(CP_SCHEMA);
}

/* ====================================================================== */
/* Utilitaires                                                            */
/* ====================================================================== */

function cp_n($n): string
{
    return number_format((float) $n, 0, ',', "\u{202F}");
}

function cp_valid_date(?string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $d, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function cp_fr_date(?string $ymd): string
{
    if (!$ymd || !cp_valid_date($ymd)) return '';
    $t = strtotime($ymd . ' 12:00:00');
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $days[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' ' . $months[(int) date('n', $t)] . ' ' . date('Y', $t);
}

/** Heure locale « 14:35 » d'un horodatage Unix. */
function cp_hm(int $ts): string
{
    return date('H:i', $ts);
}

/** Durée « 2 h 15 » ou « 40 min » à partir d'un nombre de secondes. */
function cp_duration(int $seconds): string
{
    $m = intdiv(max(0, $seconds), 60);
    if ($m < 60) return $m . ' min';
    return intdiv($m, 60) . ' h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
}

/* ====================================================================== */
/* Réglages & accès bénévoles                                             */
/* ====================================================================== */

function cp_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT v FROM cp_settings WHERE k = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function cp_set_setting(string $key, string $value): void
{
    db()->prepare('INSERT OR REPLACE INTO cp_settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
}

/** Code partagé donné aux bénévoles ('' = comptage fermé). */
function cp_volunteer_code(): string
{
    return cp_setting('volunteer_code');
}

function cp_volunteer_space_url(): string
{
    $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    return $base . '/comptage/';
}

function cp_is_volunteer(): bool
{
    return !empty($_SESSION['cp_volunteer']) || !empty($_SESSION['is_admin']);
}

function cp_require_volunteer(): void
{
    if (!cp_is_volunteer()) {
        header('Location: /comptage/login.php');
        exit;
    }
}

const CP_LOGIN_MAX_ATTEMPTS = 5;
const CP_LOGIN_WINDOW_SECONDS = 900;

function cp_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function cp_login_blocked(): bool
{
    db()->prepare('DELETE FROM cp_login_attempts WHERE at < ?')->execute([time() - CP_LOGIN_WINDOW_SECONDS]);
    $stmt = db()->prepare('SELECT COUNT(*) FROM cp_login_attempts WHERE ip = ? AND at >= ?');
    $stmt->execute([cp_client_ip(), time() - CP_LOGIN_WINDOW_SECONDS]);
    return (int) $stmt->fetchColumn() >= CP_LOGIN_MAX_ATTEMPTS;
}

/** Tente la connexion bénévole. Retourne null si OK, sinon le message d'erreur. */
function cp_volunteer_login(string $submitted): ?string
{
    $code = cp_volunteer_code();
    if ($code === '') {
        return 'Le comptage n\'est pas encore ouvert : demandez au responsable de définir un code.';
    }
    if (cp_login_blocked()) {
        return 'Trop d\'essais. Réessayez dans quelques minutes.';
    }
    if (hash_equals($code, trim($submitted))) {
        db()->prepare('DELETE FROM cp_login_attempts WHERE ip = ?')->execute([cp_client_ip()]);
        session_regenerate_id(true);
        $_SESSION['cp_volunteer'] = true;
        return null;
    }
    db()->prepare('INSERT INTO cp_login_attempts (ip, at) VALUES (?, ?)')->execute([cp_client_ip(), time()]);
    return 'Code incorrect.';
}

/* ====================================================================== */
/* Événements & postes de comptage                                        */
/* ====================================================================== */

function cp_events_list(): array
{
    return db()->query('SELECT * FROM cp_events ORDER BY COALESCE(event_date, created_at) DESC, id DESC')->fetchAll();
}

function cp_event_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM cp_events WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function cp_active_event(): ?array
{
    $row = db()->query('SELECT * FROM cp_events WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch();
    return $row ?: null;
}

function cp_set_active_event(int $id): void
{
    db()->prepare('UPDATE cp_events SET is_active = CASE WHEN id = ? THEN 1 ELSE 0 END')->execute([$id]);
}

/** Crée un événement avec un premier poste « Entrée principale ». Le premier événement créé devient actif. */
function cp_event_create(string $name, ?string $date, string $place, int $capacity, bool $trackExits): int
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO cp_events (name, event_date, place, capacity, track_exits) VALUES (?, ?, ?, ?, ?)')
        ->execute([$name, $date, $place, max(0, $capacity), $trackExits ? 1 : 0]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO cp_gates (event_id, name, sort_order) VALUES (?, ?, 1)')->execute([$id, 'Entrée principale']);
    if (!cp_active_event()) cp_set_active_event($id);
    $pdo->commit();
    return $id;
}

function cp_event_delete(int $id): void
{
    db()->prepare('DELETE FROM cp_events WHERE id = ?')->execute([$id]);
}

function cp_gates(int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM cp_gates WHERE event_id = ? ORDER BY sort_order, id');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function cp_gate_add(int $eventId, string $name): int
{
    $next = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cp_gates WHERE event_id = ?');
    $next->execute([$eventId]);
    db()->prepare('INSERT INTO cp_gates (event_id, name, sort_order) VALUES (?, ?, ?)')
        ->execute([$eventId, $name, (int) $next->fetchColumn()]);
    return (int) db()->lastInsertId();
}

function cp_gate_rename(int $gateId, string $name): void
{
    db()->prepare('UPDATE cp_gates SET name = ? WHERE id = ?')->execute([$name, $gateId]);
}

function cp_gate_delete(int $gateId): void
{
    db()->prepare('DELETE FROM cp_gates WHERE id = ?')->execute([$gateId]);
}

/* ====================================================================== */
/* Enregistrement des appuis                                              */
/* ====================================================================== */

/**
 * Applique un lot d'opérations venant d'un téléphone.
 *
 * Chaque opération est :
 *   ['op' => 'tap',  'uid' => …, 'gate' => id, 'dir' => 1|-1, 'qty' => 1..50, 'ts' => secondes Unix]
 *   ['op' => 'undo', 'uid' => …]            (annule l'appui portant cet uid)
 *
 * Idempotent : rejouer le même lot ne change rien. Une opération invalide est
 * ignorée (et comptée dans `rejected`), jamais bloquante : le téléphone
 * considère tout le lot comme traité, sinon il renverrait indéfiniment la
 * même erreur.
 *
 * @return array{applied:int, undone:int, rejected:int}
 */
function cp_record(int $eventId, array $ops, ?int $now = null): array
{
    $now ??= time();
    $out = ['applied' => 0, 'undone' => 0, 'rejected' => 0];
    $gateIds = array_flip(array_map(fn($g) => (int) $g['id'], cp_gates($eventId)));

    $insert = db()->prepare('INSERT OR IGNORE INTO cp_taps (uid, event_id, gate_id, dir, qty, ts) VALUES (?, ?, ?, ?, ?, ?)');
    $undo = db()->prepare('DELETE FROM cp_taps WHERE uid = ? AND event_id = ? AND kind = \'tap\'');

    db()->beginTransaction();
    foreach (array_slice($ops, 0, CP_MAX_OPS) as $op) {
        if (!is_array($op)) { $out['rejected']++; continue; }
        $uid = $op['uid'] ?? null;
        if (!is_string($uid) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uid)) { $out['rejected']++; continue; }

        if (($op['op'] ?? '') === 'undo') {
            $undo->execute([$uid, $eventId]);
            $out['undone'] += $undo->rowCount();
            continue;
        }
        if (($op['op'] ?? '') !== 'tap') { $out['rejected']++; continue; }

        $gate = (int) ($op['gate'] ?? 0);
        $dir = (int) ($op['dir'] ?? 0);
        $qty = (int) ($op['qty'] ?? 0);
        if (!isset($gateIds[$gate]) || ($dir !== 1 && $dir !== -1) || $qty < 1 || $qty > CP_MAX_QTY) {
            $out['rejected']++;
            continue;
        }
        // L'heure vient du téléphone (il a pu être hors ligne), mais une
        // horloge déréglée ne doit pas fausser la courbe : hors d'une fenêtre
        // plausible, on prend l'heure du serveur.
        $ts = (int) ($op['ts'] ?? 0);
        if ($ts < $now - 36 * 3600 || $ts > $now + 120) $ts = $now;

        $insert->execute([$uid, $eventId, $gate, $dir, $qty, $ts]);
        $out['applied'] += $insert->rowCount();
    }
    db()->commit();
    return $out;
}

/** Correction manuelle par le responsable (« il en manque 12 »). */
function cp_correction(int $eventId, int $gateId, int $dir, int $qty, ?int $ts = null): bool
{
    $gate = db()->prepare('SELECT COUNT(*) FROM cp_gates WHERE id = ? AND event_id = ?');
    $gate->execute([$gateId, $eventId]);
    if (!$gate->fetchColumn() || ($dir !== 1 && $dir !== -1) || $qty < 1 || $qty > 100000) return false;
    db()->prepare('INSERT INTO cp_taps (uid, event_id, gate_id, dir, qty, ts, kind) VALUES (?, ?, ?, ?, ?, ?, \'correction\')')
        ->execute(['corr-' . bin2hex(random_bytes(8)), $eventId, $gateId, $dir, $qty, $ts ?? time()]);
    return true;
}

/** Remet les compteurs d'un événement à zéro (les postes restent). */
function cp_reset(int $eventId): int
{
    $stmt = db()->prepare('DELETE FROM cp_taps WHERE event_id = ?');
    $stmt->execute([$eventId]);
    return $stmt->rowCount();
}

/* ====================================================================== */
/* Résultats                                                              */
/* ====================================================================== */

/**
 * Entrées / sorties par poste, et totaux.
 *
 * @return array{gates:array<int,array{id:int,name:string,in:int,out:int}>, in:int, out:int, present:int}
 */
function cp_totals(int $eventId): array
{
    $stmt = db()->prepare(
        'SELECT g.id, g.name,
                COALESCE(SUM(CASE WHEN t.dir = 1 THEN t.qty END), 0) AS n_in,
                COALESCE(SUM(CASE WHEN t.dir = -1 THEN t.qty END), 0) AS n_out
         FROM cp_gates g LEFT JOIN cp_taps t ON t.gate_id = g.id
         WHERE g.event_id = ? GROUP BY g.id ORDER BY g.sort_order, g.id'
    );
    $stmt->execute([$eventId]);
    $gates = [];
    $in = $out = 0;
    foreach ($stmt->fetchAll() as $r) {
        $gates[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'in' => (int) $r['n_in'], 'out' => (int) $r['n_out']];
        $in += (int) $r['n_in'];
        $out += (int) $r['n_out'];
    }
    // Les sorties peuvent dépasser les entrées (oubli d'un compteur) : on ne
    // montre jamais un nombre de présents négatif.
    return ['gates' => $gates, 'in' => $in, 'out' => $out, 'present' => max(0, $in - $out)];
}

/**
 * Chiffres clés : visiteurs, présents, pic de présence, premier / dernier appui.
 *
 * @return array{visitors:int, exits:int, present:int, peak:int, peak_at:?int, first:?int, last:?int, taps:int}
 */
function cp_stats(int $eventId): array
{
    $stmt = db()->prepare('SELECT ts, dir * qty AS d, dir, qty FROM cp_taps WHERE event_id = ? ORDER BY ts, id');
    $stmt->execute([$eventId]);
    $run = 0; $peak = 0; $peakAt = null; $in = 0; $out = 0; $first = null; $last = null; $n = 0;
    foreach ($stmt as $r) {
        $n++;
        $ts = (int) $r['ts'];
        $first ??= $ts;
        $last = $ts;
        if ((int) $r['dir'] === 1) $in += (int) $r['qty']; else $out += (int) $r['qty'];
        $run += (int) $r['d'];
        if ($run > $peak) { $peak = $run; $peakAt = $ts; }
    }
    return [
        'visitors' => $in, 'exits' => $out, 'present' => max(0, $in - $out),
        'peak' => $peak, 'peak_at' => $peakAt, 'first' => $first, 'last' => $last, 'taps' => $n,
    ];
}

/**
 * Courbe de la journée par tranches (sans trou : les tranches vides sont incluses).
 *
 * @return array<int,array{label:string, start:int, in:int, out:int, present:int}>
 *         `present` = personnes sur place à la fin de la tranche.
 */
function cp_timeline(int $eventId, int $bucketMinutes = 30): array
{
    $bucket = max(5, min(240, $bucketMinutes)) * 60;
    $stmt = db()->prepare(
        'SELECT (ts / ' . $bucket . ') AS slot,
                SUM(CASE WHEN dir = 1 THEN qty ELSE 0 END) AS n_in,
                SUM(CASE WHEN dir = -1 THEN qty ELSE 0 END) AS n_out
         FROM cp_taps WHERE event_id = ? GROUP BY slot ORDER BY slot'
    );
    $stmt->execute([$eventId]);
    $bySlot = [];
    foreach ($stmt->fetchAll() as $r) {
        $bySlot[(int) $r['slot']] = [(int) $r['n_in'], (int) $r['n_out']];
    }
    if (!$bySlot) return [];

    $first = min(array_keys($bySlot));
    $last = max(array_keys($bySlot));
    $multiDay = date('Y-m-d', $first * $bucket) !== date('Y-m-d', $last * $bucket);
    $rows = [];
    $run = 0;
    for ($s = $first; $s <= $last; $s++) {
        [$in, $out] = $bySlot[$s] ?? [0, 0];
        $run += $in - $out;
        $start = $s * $bucket;
        $rows[] = [
            'label' => $multiDay ? date('d/m H\hi', $start) : date('H:i', $start),
            'start' => $start, 'in' => $in, 'out' => $out, 'present' => max(0, $run),
        ];
    }
    return $rows;
}

/**
 * Empreinte de la configuration d'un événement vue par le téléphone (nom,
 * postes, capacité, sorties). Quand elle change côté serveur, le téléphone
 * propose de recharger la page au lieu de continuer avec des postes périmés.
 */
function cp_config_hash(array $event, array $gates): string
{
    return md5(json_encode([
        array_map(fn($g) => [(int) $g['id'], $g['name']], $gates),
        (int) $event['capacity'], (int) $event['track_exits'], $event['name'],
    ]));
}

/** Jauge de remplissage : ratio 0..n et niveau (ok | warn | full), ou null sans capacité. */
function cp_gauge(int $present, int $capacity): ?array
{
    if ($capacity <= 0) return null;
    $ratio = $present / $capacity;
    return ['ratio' => $ratio, 'pct' => (int) round($ratio * 100), 'level' => $ratio >= 0.9 ? 'full' : ($ratio >= 0.7 ? 'warn' : 'ok')];
}

/* ====================================================================== */
/* Exports                                                                */
/* ====================================================================== */

/** Protège une cellule CSV contre l'injection de formule (Excel / LibreOffice). */
function cp_csv_cell($v): string
{
    $s = (string) $v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) $s = "'" . $s;
    return $s;
}

/** CSV (séparateur « ; », BOM UTF-8 : s'ouvre directement dans Excel français). */
function cp_csv(array $header, array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\u{FEFF}");
    fputcsv($fh, array_map('cp_csv_cell', $header), ';', '"', '');
    foreach ($rows as $r) fputcsv($fh, array_map('cp_csv_cell', $r), ';', '"', '');
    rewind($fh);
    $out = (string) stream_get_contents($fh);
    fclose($fh);
    return $out;
}

function cp_export_timeline(int $eventId, int $bucketMinutes): string
{
    $rows = [];
    foreach (cp_timeline($eventId, $bucketMinutes) as $r) {
        $rows[] = [$r['label'], $r['in'], $r['out'], $r['present']];
    }
    return cp_csv(['Tranche (début)', 'Entrées', 'Sorties', 'Présents en fin de tranche'], $rows);
}

function cp_export_taps(int $eventId): string
{
    $stmt = db()->prepare(
        'SELECT t.ts, g.name AS gate, t.dir, t.qty, t.kind FROM cp_taps t JOIN cp_gates g ON g.id = t.gate_id
         WHERE t.event_id = ? ORDER BY t.ts, t.id'
    );
    $stmt->execute([$eventId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [date('Y-m-d H:i:s', (int) $r['ts']), $r['gate'], (int) $r['dir'] === 1 ? 'entrée' : 'sortie', (int) $r['qty'], $r['kind'] === 'correction' ? 'correction' : 'appui'];
    }
    return cp_csv(['Heure', 'Poste', 'Sens', 'Nombre', 'Origine'], $rows);
}

/* ====================================================================== */
/* Aides pour les formulaires du module (réutilisées par les pages admin) */
/* ====================================================================== */

function cp_redirect(string $url, ?string $message = null, string $kind = 'ok'): void
{
    if ($message !== null) flash_set($message, $kind);
    header('Location: ' . $url);
    exit;
}

function cp_form_open(string $action, array $hidden = [], string $confirm = '', string $class = ''): string
{
    $html = '<form method="post"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . shop_csrf_input()
        . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    return $html;
}

/** Début de traitement d'un POST : CSRF + action demandée ('' si GET). */
function cp_post_action(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';
    shop_require_csrf();
    return (string) ($_POST['action'] ?? '');
}
