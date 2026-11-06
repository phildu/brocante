<?php

// Espace des forces de vente : outils terrain (tournées, appels, porte-à-porte),
// mailing, kits de communication, pitch, sondages, codes de parrainage et
// récompenses. Module autonome : sa base SQLite vit dans data/vente/ et ne
// touche ni aux bases des boutiques ni aux réglages du portail.

const FV_ROOT = __DIR__ . '/..' . '/..';
const FV_SCHEMA = 1;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// ── Base de données ──────────────────────────────────────────────────────────

function fv_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = FV_ROOT . '/data/vente';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        throw new RuntimeException("Impossible de créer data/vente/ : droits d'écriture manquants.");
    }
    $file = "$dir/vente.db";
    $new = !is_file($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $hasMembers = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'members'")->fetchColumn();
    if (!$hasMembers) {
        fv_schema($pdo);
    }
    if ($new) @chmod($file, 0600);
    fv_settings_ensure($pdo);
    return $pdo;
}

function fv_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'commercial',
    email TEXT NOT NULL COLLATE NOCASE UNIQUE,
    phone TEXT NOT NULL DEFAULT '',
    city TEXT NOT NULL DEFAULT '',
    zone TEXT NOT NULL DEFAULT '',
    shop_slug TEXT NOT NULL DEFAULT '',
    access_hash TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    goal_month INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS prospects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL DEFAULT '',
    business TEXT NOT NULL DEFAULT '',
    kind TEXT NOT NULL DEFAULT '',
    address TEXT NOT NULL DEFAULT '',
    postal TEXT NOT NULL DEFAULT '',
    city TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    website TEXT NOT NULL DEFAULT '',
    zone TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'terrain',
    status TEXT NOT NULL DEFAULT 'a_contacter',
    priority INTEGER NOT NULL DEFAULT 2,
    owner_id INTEGER NOT NULL DEFAULT 0,
    notes TEXT NOT NULL DEFAULT '',
    shop_slug TEXT NOT NULL DEFAULT '',
    next_action_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fv_prospects_owner ON prospects(owner_id, status);
CREATE INDEX IF NOT EXISTS idx_fv_prospects_next ON prospects(next_action_at);

CREATE TABLE IF NOT EXISTS activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    prospect_id INTEGER NOT NULL DEFAULT 0,
    member_id INTEGER NOT NULL DEFAULT 0,
    kind TEXT NOT NULL,
    outcome TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    points INTEGER NOT NULL DEFAULT 0,
    duration_min INTEGER NOT NULL DEFAULT 0,
    occurred_at TEXT NOT NULL,
    next_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fv_activities_member ON activities(member_id, occurred_at);
CREATE INDEX IF NOT EXISTS idx_fv_activities_prospect ON activities(prospect_id, occurred_at);

CREATE TABLE IF NOT EXISTS codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL COLLATE NOCASE UNIQUE,
    member_id INTEGER NOT NULL DEFAULT 0,
    label TEXT NOT NULL DEFAULT '',
    kind TEXT NOT NULL DEFAULT 'percent',
    value INTEGER NOT NULL DEFAULT 20,
    max_uses INTEGER NOT NULL DEFAULT 0,
    uses INTEGER NOT NULL DEFAULT 0,
    is_referral INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fv_codes_member ON codes(member_id);

CREATE TABLE IF NOT EXISTS redemptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code_id INTEGER NOT NULL DEFAULT 0,
    code TEXT NOT NULL DEFAULT '',
    member_id INTEGER NOT NULL DEFAULT 0,
    prospect_id INTEGER NOT NULL DEFAULT 0,
    shop_slug TEXT NOT NULL DEFAULT '',
    amount_cents INTEGER NOT NULL DEFAULT 0,
    note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fv_redemptions_member ON redemptions(member_id, created_at);

CREATE TABLE IF NOT EXISTS mailings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL DEFAULT 0,
    subject TEXT NOT NULL DEFAULT '',
    body TEXT NOT NULL DEFAULT '',
    segment TEXT NOT NULL DEFAULT '',
    recipients INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS surveys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    intro TEXT NOT NULL DEFAULT '',
    questions TEXT NOT NULL DEFAULT '[]',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS survey_responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    survey_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL DEFAULT 0,
    prospect_id INTEGER NOT NULL DEFAULT 0,
    respondent TEXT NOT NULL DEFAULT '',
    contact TEXT NOT NULL DEFAULT '',
    answers TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fv_responses_survey ON survey_responses(survey_id, created_at);

CREATE TABLE IF NOT EXISTS settings (
    name TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
SQL);
}

// ── Réglages ─────────────────────────────────────────────────────────────────

function fv_setting_defaults(): array
{
    return [
        'schema_version' => (string) FV_SCHEMA,
        'marque_nom' => 'Brocs',
        'contact_tel' => '',
        'contact_email' => '',
        'site_url' => 'https://brocs.arrimage.com',
        'offre_pitch' => 'Votre boutique en ligne prête en 48 h, sans commission sur vos ventes.',
        'offre_prix' => 'Découverte gratuite · Standard 19,90 €/mois · Premium 49,90 €/mois',
        'points_appel' => '2',
        'points_appel_qualifie' => '5',
        'points_rdv' => '15',
        'points_boutique' => '100',
        'commission_commercial' => '6000',
        'commission_ambassadeur' => '4000',
        'commission_taux' => '0',
        'tier_argent' => '100',
        'tier_or' => '300',
        'tier_platine' => '700',
        'remise_defaut' => '20',
        'recompenses_catalogue' => "Argent — goodies Brocs et +10 % de commission\nOr — invitation aux soirées Brocs, +25 % de commission et mise en avant dans l'annuaire\nPlatine — un mois offert sur votre boutique, +40 % de commission et statut partenaire",
    ];
}

function fv_settings_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $st = $pdo->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)');
    foreach (fv_setting_defaults() as $name => $value) {
        $st->execute([$name, $value]);
    }
    $pdo->prepare('UPDATE settings SET value = ? WHERE name = ? AND value = ?')
        ->execute([(string) FV_SCHEMA, 'schema_version', '0']);
}

function fv_setting(string $name, ?string $default = null): string
{
    static $cache = [];
    if (array_key_exists($name, $cache)) return $cache[$name];
    $st = fv_db()->prepare('SELECT value FROM settings WHERE name = ?');
    $st->execute([$name]);
    $value = $st->fetchColumn();
    return $cache[$name] = $value === false ? (string) ($default ?? '') : (string) $value;
}

function fv_setting_int(string $name, int $default = 0): int
{
    $value = fv_setting($name, (string) $default);
    return is_numeric($value) ? (int) $value : $default;
}

function fv_setting_save(string $name, string $value): void
{
    fv_db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value')
        ->execute([$name, $value]);
}

/** Catalogue des récompenses, une ligne par palier : « Or — invitation… ». */
function fv_reward_catalog(): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', fv_setting('recompenses_catalogue', '')) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = preg_split('/\s+[—–]\s+/u', $line, 2) ?: [];
        $out[] = count($parts) === 2
            ? ['title' => $parts[0], 'desc' => $parts[1]]
            : ['title' => '', 'desc' => $line];
    }
    return $out;
}

// ── Dictionnaires ────────────────────────────────────────────────────────────

function fv_roles(): array
{
    return [
        'commercial' => 'Commercial',
        'ambassadeur' => 'Ambassadeur · commerçant référent',
    ];
}

function fv_statuses(): array
{
    return [
        'a_contacter' => 'À contacter',
        'contacte' => 'Contacté',
        'interesse' => 'Intéressé',
        'rdv' => 'RDV pris',
        'boutique_creee' => 'Boutique créée',
        'perdu' => 'Sans suite',
    ];
}

function fv_sources(): array
{
    return [
        'terrain' => 'Terrain · porte-à-porte',
        'telephone' => 'Téléphone',
        'mailing' => 'Mailing',
        'recommandation' => 'Recommandation',
        'ambassadeur' => 'Ambassadeur',
        'autre' => 'Autre',
    ];
}

function fv_kinds(): array
{
    return [
        'appel' => 'Appel',
        'visite' => 'Visite',
        'email' => 'E-mail',
        'rdv' => 'Rendez-vous',
        'note' => 'Note',
        'creation' => 'Boutique créée',
    ];
}

function fv_outcomes(): array
{
    return [
        'appel' => [
            '' => 'Choisir…',
            'sans_reponse' => 'Pas de réponse',
            'messagerie' => 'Messagerie',
            'rappeler' => 'À rappeler',
            'interesse' => 'Intéressé',
            'rdv' => 'RDV obtenu',
            'refus' => 'Refus',
            'faux_numero' => 'Faux numéro',
        ],
        'visite' => [
            '' => 'Choisir…',
            'absent' => 'Absent / fermé',
            'rappeler' => 'À repasser',
            'interesse' => 'Intéressé',
            'rdv' => 'RDV obtenu',
            'refus' => 'Refus',
        ],
        'email' => [
            '' => 'Choisir…',
            'envoye' => 'Envoyé',
            'repondu' => 'Réponse reçue',
            'refus' => 'Refus',
        ],
        'rdv' => [
            '' => 'Choisir…',
            'realise' => 'Réalisé',
            'annule' => 'Annulé',
            'reporte' => 'Reporté',
        ],
        'note' => ['' => 'Note'],
        'creation' => ['' => 'Boutique créée'],
    ];
}

function fv_status_label(string $status): string
{
    return fv_statuses()[$status] ?? $status;
}

function fv_source_label(string $source): string
{
    return fv_sources()[$source] ?? $source;
}

function fv_kind_label(string $kind): string
{
    return fv_kinds()[$kind] ?? $kind;
}

function fv_role_label(string $role): string
{
    return fv_roles()[$role] ?? $role;
}

function fv_outcome_label(string $kind, string $outcome): string
{
    $list = fv_outcomes()[$kind] ?? [];
    return $list[$outcome] ?? $outcome;
}

// ── Authentification ─────────────────────────────────────────────────────────

function fv_local_request(): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
        && ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test'));
}

function fv_portal_logged(): bool
{
    return !empty($_SESSION['portail_user']);
}

/** L'exploitant (pas un membre de l'équipe de vente) peut administrer l'espace. */
function fv_real_manager(): bool
{
    return fv_local_request() || fv_portal_logged();
}

function fv_member_get(int $id): ?array
{
    if ($id <= 0) return null;
    $st = fv_db()->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function fv_member_by_email(string $email): ?array
{
    $st = fv_db()->prepare('SELECT * FROM members WHERE email = ? COLLATE NOCASE LIMIT 1');
    $st->execute([mb_strtolower(trim($email))]);
    $row = $st->fetch();
    return $row ?: null;
}

function fv_access_code_new(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

function fv_code_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function fv_member_login(string $email, string $code): ?array
{
    $member = fv_member_by_email($email);
    if (!$member || !$member['active']) return null;
    $given = fv_code_normalize($code);
    $hash = (string) $member['access_hash'];
    if ($given === '' || (!password_verify($given, $hash) && !password_verify($code, $hash))) {
        return null;
    }
    fv_db()->prepare('UPDATE members SET last_login_at = ? WHERE id = ?')->execute([fv_now(), $member['id']]);
    return $member;
}

/**
 * Contexte courant : membre effectif (ou null), droits d'administration et
 * éventuel « voir comme ». Le gérant peut se mettre à la place d'un membre
 * sans perdre son accès réel.
 */
function fv_current(): array
{
    static $ctx = null;
    if ($ctx !== null) return $ctx;

    $member = null;
    $realManager = fv_real_manager();
    $viewAs = false;
    if ($realManager && !empty($_SESSION['fv_view'])) {
        $member = fv_member_get((int) $_SESSION['fv_view']);
        if (!$member || !$member['active']) {
            unset($_SESSION['fv_view']);
            $member = null;
        } else {
            $viewAs = true;
        }
    }
    if (!$member && !empty($_SESSION['fv_member_id'])) {
        $member = fv_member_get((int) $_SESSION['fv_member_id']);
        if (!$member || !$member['active']) {
            unset($_SESSION['fv_member_id']);
            $member = null;
        }
    }
    return $ctx = [
        'member' => $member,
        'member_id' => $member ? (int) $member['id'] : 0,
        'manager' => $realManager && !$viewAs,
        'real_manager' => $realManager,
        'view_as' => $viewAs,
    ];
}

function fv_require_auth(): void
{
    $ctx = fv_current();
    if ($ctx['member'] || $ctx['manager']) return;
    $target = $_SERVER['REQUEST_URI'] ?? '/portail/vente/';
    $path = (string) parse_url($target, PHP_URL_PATH);
    $query = (string) parse_url($target, PHP_URL_QUERY);
    $rel = basename($path === '' ? 'index.php' : $path) . ($query !== '' ? '?' . $query : '');
    header('Location: connexion.php?retour=' . rawurlencode($rel));
    exit;
}

function fv_can_manage(): bool
{
    return fv_current()['manager'];
}

function fv_effective_member_id(): int
{
    return fv_current()['member_id'];
}

/** Le membre effectif peut-il toucher ce prospect ? */
function fv_can_access_prospect(array $prospect): bool
{
    $ctx = fv_current();
    if ($ctx['manager']) return true;
    return $ctx['member_id'] > 0 && (int) $prospect['owner_id'] === $ctx['member_id'];
}

function fv_prospect_get(int $id, bool $scoped = true): ?array
{
    $st = fv_db()->prepare('SELECT * FROM prospects WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    if ($scoped && !fv_can_access_prospect($row)) return null;
    return $row;
}

// ── Petits utilitaires ───────────────────────────────────────────────────────

function fv_now(): string
{
    return date('Y-m-d H:i:s');
}

function fv_today(): string
{
    return date('Y-m-d');
}

function fv_date(?string $value, string $fallback = '—'): string
{
    $value = trim((string) $value);
    if ($value === '') return $fallback;
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $fallback;
}

function fv_datetime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') return '—';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

function fv_money(int $cents): string
{
    $cents = max(0, $cents);
    $decimals = $cents % 100 === 0 ? 0 : 2;
    return number_format($cents / 100, $decimals, ',', ' ') . ' €';
}

function fv_days_from(int $days): string
{
    return date('Y-m-d', strtotime("+$days days"));
}

function fv_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

function fv_text($value, int $max = 200): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
    return mb_substr($text, 0, $max);
}

function fv_paragraph($value, int $max = 4000): string
{
    return mb_substr(trim(str_replace("\r\n", "\n", (string) ($value ?? ''))), 0, $max);
}

function fv_choice($value, array $choices, string $fallback): string
{
    $key = (string) ($value ?? '');
    if (array_key_exists($key, $choices)) return $key;
    return array_key_exists($fallback, $choices) ? $fallback : (string) array_key_first($choices);
}

function fv_csrf(): string
{
    if (empty($_SESSION['fv_csrf'])) {
        $_SESSION['fv_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['fv_csrf'];
}

function fv_csrf_check(): void
{
    if (!hash_equals(fv_csrf(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Formulaire expiré : rechargez la page et recommencez.');
    }
}

function fv_flash(?string $message = null, string $kind = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['fv_flash'] = ['message' => $message, 'kind' => $kind];
        return null;
    }
    $flash = $_SESSION['fv_flash'] ?? null;
    unset($_SESSION['fv_flash']);
    return $flash;
}

function fv_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function fv_back(string $fallback, string $field = 'retour'): string
{
    $back = (string) ($_POST[$field] ?? ($_GET[$field] ?? ''));
    if ($back !== '' && preg_match('#^[a-z0-9._-]+\.php(?:\?[a-z0-9_=&%.\-]*)?$#i', $back) && !str_starts_with($back, 'sondage.php')) {
        return $back;
    }
    return $fallback;
}

/**
 * Remplace les champs {contact}, {commerce}, {ville}… dans un texte de mailing,
 * de kit ou de message. Le code filleul du membre est créé à la demande.
 */
function fv_mail_merge(string $text, array $prospect = [], ?array $member = null): string
{
    $contact = trim((string) ($prospect['name'] ?? ''));
    if ($contact !== '') {
        $contact = (string) (preg_split('/\s+/', $contact)[0] ?? $contact);
    }
    if ($contact === '') $contact = 'Madame, Monsieur';
    $code = '';
    if ($member && (int) ($member['id'] ?? 0) > 0) {
        $referral = fv_referral_code((int) $member['id']);
        $code = $referral ? (string) $referral['code'] : '';
    }
    $brand = fv_setting('marque_nom', 'Brocs');
    return strtr($text, [
        '{contact}' => $contact,
        '{commerce}' => trim((string) ($prospect['business'] ?? '')) ?: 'votre commerce',
        '{ville}' => trim((string) ($prospect['city'] ?? '')) ?: 'votre ville',
        '{marque}' => $brand,
        '{lien}' => rtrim(fv_setting('site_url', ''), '/'),
        '{telephone}' => fv_setting('contact_tel', ''),
        '{email}' => fv_setting('contact_email', ''),
        '{offre}' => fv_setting('offre_pitch', ''),
        '{prix}' => fv_setting('offre_prix', ''),
        '{commercial}' => $member ? (string) $member['name'] : 'l’équipe ' . $brand,
        '{code}' => $code,
        '{remise}' => fv_setting_int('remise_defaut', 20) . ' %',
    ]);
}

function fv_period(): array
{
    $period = (string) ($_GET['periode'] ?? 'mois');
    if (!in_array($period, ['mois', 'trimestre', 'annee', 'tout'], true)) $period = 'mois';
    $from = match ($period) {
        'trimestre' => date('Y-m-01', strtotime('-2 months')),
        'annee' => date('Y-01-01'),
        'tout' => '2000-01-01',
        default => date('Y-m-01'),
    };
    $labels = ['mois' => 'Ce mois', 'trimestre' => '3 derniers mois', 'annee' => 'Cette année', 'tout' => 'Depuis le début'];
    return ['key' => $period, 'from' => $from, 'label' => $labels[$period]];
}

function fv_period_switch(array $period): string
{
    $html = '<div class="fv-seg" role="group" aria-label="Période">';
    foreach (['mois' => 'Mois', 'trimestre' => 'Trimestre', 'annee' => 'Année', 'tout' => 'Tout'] as $key => $label) {
        $href = '?periode=' . $key;
        $html .= '<a href="' . e($href) . '"' . ($period['key'] === $key ? ' aria-current="page"' : '') . '>' . e($label) . '</a>';
    }
    return $html . '</div>';
}

// ── Points, paliers et primes ────────────────────────────────────────────────

function fv_tier_thresholds(): array
{
    return [
        'bronze' => 0,
        'argent' => max(1, fv_setting_int('tier_argent', 100)),
        'or' => max(2, fv_setting_int('tier_or', 300)),
        'platine' => max(3, fv_setting_int('tier_platine', 700)),
    ];
}

function fv_tier_labels(): array
{
    return ['bronze' => 'Bronze', 'argent' => 'Argent', 'or' => 'Or', 'platine' => 'Platine'];
}

function fv_tier_multipliers(): array
{
    return ['bronze' => 1.0, 'argent' => 1.1, 'or' => 1.25, 'platine' => 1.4];
}

function fv_tier(int $points): array
{
    $thresholds = fv_tier_thresholds();
    $labels = fv_tier_labels();
    $multipliers = fv_tier_multipliers();
    $key = 'bronze';
    foreach ($thresholds as $tier => $min) {
        if ($points >= $min) $key = $tier;
    }
    $keys = array_keys($thresholds);
    $index = array_search($key, $keys, true);
    $nextKey = $keys[$index + 1] ?? null;
    $nextMin = $nextKey !== null ? $thresholds[$nextKey] : null;
    $currentMin = $thresholds[$key];
    $span = $nextMin !== null ? max(1, $nextMin - $currentMin) : 0;
    $progress = $nextMin !== null ? max(0, min(100, (int) round(($points - $currentMin) / $span * 100))) : 100;
    return [
        'key' => $key,
        'label' => $labels[$key],
        'min' => $currentMin,
        'next_key' => $nextKey,
        'next_label' => $nextKey ? $labels[$nextKey] : null,
        'next_min' => $nextMin,
        'multiplier' => $multipliers[$key],
        'progress' => $progress,
    ];
}

function fv_points_for(string $kind, string $outcome = ''): int
{
    return match ($kind) {
        'creation' => fv_setting_int('points_boutique', 100),
        'rdv' => fv_setting_int('points_rdv', 15),
        'appel', 'visite' => in_array($outcome, ['interesse', 'rdv', 'refus', 'faux_numero'], true)
            ? fv_setting_int('points_appel_qualifie', 5)
            : fv_setting_int('points_appel', 2),
        default => 0,
    };
}

function fv_member_points(int $memberId, ?string $from = null): int
{
    $sql = 'SELECT COALESCE(SUM(points), 0) FROM activities WHERE member_id = ?';
    $params = [$memberId];
    if ($from !== null) {
        $sql .= ' AND occurred_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    $st = fv_db()->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function fv_member_commission(array $member, int $shops, int $amountCents, int $points): int
{
    $role = $member['role'] === 'ambassadeur' ? 'ambassadeur' : 'commercial';
    $base = fv_setting_int('commission_' . $role, $role === 'ambassadeur' ? 4000 : 6000);
    $rate = max(0, min(100, fv_setting_int('commission_taux', 0)));
    $commission = $shops * $base + (int) round($amountCents * $rate / 100);
    $tier = fv_tier($points);
    return (int) round($commission * $tier['multiplier']);
}

// ── Prospects et activités ───────────────────────────────────────────────────

function fv_prospect_from_input(array $input, ?array $existing = null): array
{
    $name = fv_text($input['name'] ?? '', 120);
    $business = fv_text($input['business'] ?? '', 160);
    if ($name === '' && $business === '') {
        throw new InvalidArgumentException('Indiquez au moins le nom du commerce ou le nom du contact.');
    }
    $postal = preg_replace('/\D+/', '', (string) ($input['postal'] ?? '')) ?? '';
    $email = mb_strtolower(fv_text($input['email'] ?? '', 190));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("L'adresse e-mail du prospect n'est pas valide.");
    }
    $next = trim((string) ($input['next_action_at'] ?? ''));
    if ($next !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next)) $next = '';
    $owner = (int) ($input['owner_id'] ?? 0);
    if (!fv_can_manage()) {
        $owner = fv_effective_member_id();
    } else {
        $allowed = array_map('intval', array_column(fv_db()->query('SELECT id FROM members')->fetchAll(), 'id'));
        if ($owner > 0 && !in_array($owner, $allowed, true)) $owner = 0;
    }
    return [
        'name' => $name,
        'business' => $business,
        'kind' => fv_text($input['kind'] ?? '', 80),
        'address' => fv_text($input['address'] ?? '', 200),
        'postal' => mb_substr($postal, 0, 5),
        'city' => fv_text($input['city'] ?? '', 90),
        'phone' => fv_text($input['phone'] ?? '', 40),
        'email' => $email,
        'website' => fv_text($input['website'] ?? '', 190),
        'zone' => fv_text($input['zone'] ?? '', 90),
        'source' => fv_choice($input['source'] ?? '', fv_sources(), 'terrain'),
        'status' => fv_choice($input['status'] ?? '', fv_statuses(), 'a_contacter'),
        'priority' => max(1, min(3, (int) ($input['priority'] ?? 2))),
        'owner_id' => $owner,
        'notes' => fv_paragraph($input['notes'] ?? '', 4000),
        'next_action_at' => $next !== '' ? $next : null,
    ];
}

function fv_prospect_save(array $data, ?array $existing = null): int
{
    $pdo = fv_db();
    if ($existing) {
        $st = $pdo->prepare('UPDATE prospects SET name=?, business=?, kind=?, address=?, postal=?, city=?, phone=?, email=?, website=?, zone=?, source=?, status=?, priority=?, owner_id=?, notes=?, next_action_at=?, updated_at=? WHERE id=?');
        $st->execute([$data['name'], $data['business'], $data['kind'], $data['address'], $data['postal'], $data['city'], $data['phone'], $data['email'], $data['website'], $data['zone'], $data['source'], $data['status'], $data['priority'], $data['owner_id'], $data['notes'], $data['next_action_at'], fv_now(), $existing['id']]);
        return (int) $existing['id'];
    }
    $st = $pdo->prepare('INSERT INTO prospects (name, business, kind, address, postal, city, phone, email, website, zone, source, status, priority, owner_id, notes, next_action_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$data['name'], $data['business'], $data['kind'], $data['address'], $data['postal'], $data['city'], $data['phone'], $data['email'], $data['website'], $data['zone'], $data['source'], $data['status'], $data['priority'], $data['owner_id'], $data['notes'], $data['next_action_at'], fv_now(), fv_now()]);
    return (int) $pdo->lastInsertId();
}

/** Effet d'un résultat sur le prospect : [statut, prochaine action]. */
function fv_outcome_effect(string $kind, string $outcome, ?string $nextAt): array
{
    $next = ($nextAt !== null && $nextAt !== '') ? $nextAt : null;
    switch ($kind) {
        case 'appel':
            return match ($outcome) {
                'refus', 'faux_numero' => ['perdu', null],
                'interesse' => ['interesse', $next ?: fv_days_from(2)],
                'rdv' => ['rdv', $next ?: fv_days_from(2)],
                'rappeler' => ['contacte', $next ?: fv_days_from(2)],
                default => ['contacte', $next ?: fv_days_from(3)],
            };
        case 'visite':
            return match ($outcome) {
                'refus' => ['perdu', null],
                'interesse' => ['interesse', $next ?: fv_days_from(2)],
                'rdv' => ['rdv', $next ?: fv_days_from(2)],
                'rappeler' => ['contacte', $next ?: fv_days_from(3)],
                default => ['contacte', $next ?: fv_days_from(7)],
            };
        case 'email':
            return match ($outcome) {
                'refus' => ['perdu', null],
                'repondu' => ['interesse', $next ?: fv_days_from(2)],
                default => ['contacte', $next ?: fv_days_from(5)],
            };
        case 'rdv':
            return match ($outcome) {
                'realise' => ['interesse', $next ?: fv_days_from(2)],
                'annule', 'reporte' => ['rdv', $next ?: fv_days_from(7)],
                default => ['rdv', $next],
            };
        case 'creation':
            return ['boutique_creee', null];
        default:
            return ['', $next];
    }
}

function fv_log_activity(int $prospectId, string $kind, string $outcome = '', string $notes = '', ?string $nextAt = null, int $duration = 0): int
{
    $pdo = fv_db();
    $memberId = fv_effective_member_id();
    $points = fv_points_for($kind, $outcome);
    $st = $pdo->prepare('INSERT INTO activities (prospect_id, member_id, kind, outcome, notes, points, duration_min, occurred_at, next_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([$prospectId, $memberId, $kind, $outcome, $notes, $points, max(0, $duration), fv_now(), $nextAt]);
    $id = (int) $pdo->lastInsertId();

    if ($prospectId > 0 && ($p = fv_prospect_get($prospectId, false))) {
        [$status, $next] = fv_outcome_effect($kind, $outcome, $nextAt);
        if ($status !== '' || $next !== null) {
            $pdo->prepare('UPDATE prospects SET status = ?, next_action_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$status !== '' ? $status : $p['status'], $next, fv_now(), $prospectId]);
        }
    }
    return $id;
}

function fv_prospect_activity(array $prospect): array
{
    $st = fv_db()->prepare('SELECT a.*, m.name AS member_name FROM activities a LEFT JOIN members m ON m.id = a.member_id WHERE a.prospect_id = ? ORDER BY a.occurred_at DESC, a.id DESC LIMIT 80');
    $st->execute([$prospect['id']]);
    return $st->fetchAll();
}

function fv_prospect_last_activity(int $prospectId): ?array
{
    $st = fv_db()->prepare('SELECT * FROM activities WHERE prospect_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 1');
    $st->execute([$prospectId]);
    return $st->fetch() ?: null;
}

function fv_members_list(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM members' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name COLLATE NOCASE';
    return fv_db()->query($sql)->fetchAll();
}

function fv_zones(): array
{
    $rows = fv_db()->query('SELECT DISTINCT zone FROM prospects WHERE zone <> "" ORDER BY zone COLLATE NOCASE')->fetchAll();
    return array_column($rows, 'zone');
}

/**
 * Recherche de prospects avec les filtres de l'interface. Le périmètre du
 * membre connecté est toujours appliqué ; le gérant peut filtrer par owner.
 */
function fv_prospects_search(array $filters = [], ?int $limit = 300): array
{
    $ctx = fv_current();
    $where = [];
    $params = [];
    if ($ctx['member_id'] > 0) {
        $where[] = 'p.owner_id = ?';
        $params[] = $ctx['member_id'];
    } elseif ((int) ($filters['owner_id'] ?? 0) > 0) {
        $where[] = 'p.owner_id = ?';
        $params[] = (int) $filters['owner_id'];
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(p.name LIKE ? OR p.business LIKE ? OR p.city LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR p.notes LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    $status = (string) ($filters['status'] ?? '');
    if ($status !== '' && isset(fv_statuses()[$status])) {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    $zone = trim((string) ($filters['zone'] ?? ''));
    if ($zone !== '') {
        $where[] = 'p.zone = ?';
        $params[] = $zone;
    }
    $city = trim((string) ($filters['city'] ?? ''));
    if ($city !== '') {
        $where[] = 'p.city = ?';
        $params[] = $city;
    }
    $source = (string) ($filters['source'] ?? '');
    if ($source !== '' && isset(fv_sources()[$source])) {
        $where[] = 'p.source = ?';
        $params[] = $source;
    }
    $due = (string) ($filters['due'] ?? '');
    if ($due === 'today') {
        $where[] = 'p.next_action_at IS NOT NULL AND p.next_action_at <= ?';
        $params[] = fv_today();
    } elseif ($due === 'week') {
        $where[] = 'p.next_action_at IS NOT NULL AND p.next_action_at <= ?';
        $params[] = fv_days_from(7);
    } elseif ($due === 'none') {
        $where[] = 'p.next_action_at IS NULL';
    }
    if (!empty($filters['active_only'])) {
        $where[] = 'p.status NOT IN ("perdu", "boutique_creee")';
    }
    if (!empty($filters['has_address'])) {
        $where[] = 'p.address <> ""';
    }
    if (!empty($filters['has_phone'])) {
        $where[] = 'p.phone <> ""';
    }
    if (!empty($filters['has_email'])) {
        $where[] = 'p.email <> ""';
    }
    $sql = 'SELECT p.*, m.name AS owner_name, m.role AS owner_role FROM prospects p LEFT JOIN members m ON m.id = p.owner_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY CASE p.status WHEN "rdv" THEN 0 WHEN "interesse" THEN 1 WHEN "contacte" THEN 2 WHEN "a_contacter" THEN 3 WHEN "boutique_creee" THEN 4 ELSE 5 END,
            p.next_action_at IS NULL, p.next_action_at, p.priority DESC, p.updated_at DESC';
    if ($limit !== null) $sql .= ' LIMIT ' . max(1, min(2000, $limit));
    $st = fv_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function fv_prospect_duplicate(array $data, int $ignoreId = 0): ?array
{
    $pdo = fv_db();
    $checks = [];
    if ($data['phone'] !== '') $checks[] = ['phone = ?', $data['phone']];
    if ($data['email'] !== '') $checks[] = ['email = ? COLLATE NOCASE', $data['email']];
    if ($data['business'] !== '' && $data['city'] !== '') $checks[] = ['business = ? COLLATE NOCASE AND city = ? COLLATE NOCASE', $data['business'], $data['city']];
    foreach ($checks as $check) {
        $sql = 'SELECT * FROM prospects WHERE ' . $check[0] . ($ignoreId > 0 ? ' AND id <> ?' : '') . ' LIMIT 1';
        $params = array_slice($check, 1);
        if ($ignoreId > 0) $params[] = $ignoreId;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if ($row = $st->fetch()) return $row;
    }
    return null;
}

function fv_csv_download(string $filename, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($rows as $row) fputcsv($out, $row, ';');
    fclose($out);
    exit;
}

function fv_prospect_count_by_status(int $memberId = 0, ?string $from = null): array
{
    $sql = 'SELECT status, COUNT(*) AS n FROM prospects WHERE 1=1';
    $params = [];
    if ($memberId > 0) {
        $sql .= ' AND owner_id = ?';
        $params[] = $memberId;
    }
    if ($from !== null) {
        $sql .= ' AND created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    $sql .= ' GROUP BY status';
    $st = fv_db()->prepare($sql);
    $st->execute($params);
    $out = array_fill_keys(array_keys(fv_statuses()), 0);
    foreach ($st->fetchAll() as $row) {
        $out[$row['status']] = (int) $row['n'];
    }
    return $out;
}

// ── Codes et filleuls ────────────────────────────────────────────────────────

function fv_referral_code_new(string $name): string
{
    $slug = preg_replace('/[^A-Za-z0-9]+/', '', (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)) ?? '';
    $slug = strtoupper(mb_substr($slug, 0, 8)) ?: 'BROCS';
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $suffix = '';
        for ($i = 0; $i < 4; $i++) $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $code = 'BROCS-' . $slug . '-' . $suffix;
        $st = fv_db()->prepare('SELECT 1 FROM codes WHERE code = ? COLLATE NOCASE');
        $st->execute([$code]);
        if (!$st->fetchColumn()) return $code;
    }
    return 'BROCS-' . $slug . '-' . bin2hex(random_bytes(3));
}

function fv_referral_code(int $memberId): ?array
{
    if ($memberId <= 0) return null;
    $st = fv_db()->prepare('SELECT * FROM codes WHERE member_id = ? AND is_referral = 1 ORDER BY active DESC, id DESC LIMIT 1');
    $st->execute([$memberId]);
    $row = $st->fetch();
    if ($row) return $row;
    $member = fv_member_get($memberId);
    if (!$member) return null;
    $code = fv_referral_code_new($member['name']);
    $st = fv_db()->prepare('INSERT INTO codes (code, member_id, label, kind, value, max_uses, uses, is_referral, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$code, $memberId, 'Code parrainage de ' . $member['name'], 'percent', max(0, min(100, fv_setting_int('remise_defaut', 20))), 0, 0, 1, 1, fv_now()]);
    return fv_db()->query('SELECT * FROM codes WHERE id = ' . (int) fv_db()->lastInsertId())->fetch() ?: null;
}

function fv_code_value_label(array $code): string
{
    return $code['kind'] === 'fixed' ? '−' . fv_money((int) $code['value']) : '−' . (int) $code['value'] . ' %';
}

function fv_code_status(array $code): array
{
    if (!$code['active']) return ['Inactif', 'off'];
    if (!empty($code['expires_at']) && $code['expires_at'] < fv_today()) return ['Expiré', 'off'];
    if ((int) $code['max_uses'] > 0 && (int) $code['uses'] >= (int) $code['max_uses']) return ['Épuisé', 'off'];
    return ['Actif', 'on'];
}

function fv_codes_list(int $memberId = 0): array
{
    $sql = 'SELECT c.*, m.name AS member_name FROM codes c LEFT JOIN members m ON m.id = c.member_id';
    $params = [];
    if ($memberId > 0) {
        $sql .= ' WHERE c.member_id = ?';
        $params[] = $memberId;
    }
    $sql .= ' ORDER BY c.is_referral DESC, c.created_at DESC';
    $st = fv_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

// ── Tableau de bord et classement ────────────────────────────────────────────

function fv_member_stats(int $memberId, string $from): array
{
    $pdo = fv_db();
    $st = $pdo->prepare('SELECT COALESCE(SUM(points),0) pts, COUNT(CASE WHEN kind = "creation" THEN 1 END) shops, COUNT(CASE WHEN kind = "rdv" AND outcome = "realise" THEN 1 END) rdvs, COUNT(CASE WHEN kind = "appel" THEN 1 END) calls, COUNT(CASE WHEN kind = "visite" THEN 1 END) visits FROM activities WHERE member_id = ? AND occurred_at >= ?');
    $st->execute([$memberId, $from . ' 00:00:00']);
    $row = $st->fetch() ?: [];
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM redemptions WHERE member_id = ? AND created_at >= ?');
    $st->execute([$memberId, $from . ' 00:00:00']);
    $amount = (int) $st->fetchColumn();
    $lifetime = fv_member_points($memberId);
    $member = fv_member_get($memberId);
    $tier = fv_tier($lifetime);
    $shops = (int) ($row['shops'] ?? 0);
    $commission = $member ? fv_member_commission($member, $shops, $amount, $lifetime) : 0;
    $st = $pdo->prepare('SELECT COUNT(*) FROM prospects WHERE owner_id = ?');
    $st->execute([$memberId]);
    $prospects = (int) $st->fetchColumn();
    return [
        'points' => (int) ($row['pts'] ?? 0),
        'points_lifetime' => $lifetime,
        'shops' => $shops,
        'rdvs' => (int) ($row['rdvs'] ?? 0),
        'calls' => (int) ($row['calls'] ?? 0),
        'visits' => (int) ($row['visits'] ?? 0),
        'amount_cents' => $amount,
        'commission_cents' => $commission,
        'prospects' => $prospects,
        'tier' => $tier,
    ];
}

function fv_leaderboard(string $from): array
{
    $st = fv_db()->prepare('SELECT m.id, m.name, m.role, m.goal_month,
        COALESCE(SUM(a.points), 0) AS points,
        COUNT(CASE WHEN a.kind = "creation" THEN 1 END) AS shops,
        COUNT(CASE WHEN a.kind = "rdv" AND a.outcome = "realise" THEN 1 END) AS rdvs,
        COUNT(CASE WHEN a.kind = "appel" THEN 1 END) AS calls,
        COUNT(CASE WHEN a.kind = "visite" THEN 1 END) AS visits
        FROM members m
        LEFT JOIN activities a ON a.member_id = m.id AND a.occurred_at >= ?
        WHERE m.active = 1
        GROUP BY m.id
        ORDER BY points DESC, shops DESC, m.name COLLATE NOCASE');
    $st->execute([$from . ' 00:00:00']);
    $rows = $st->fetchAll();
    $pdo = fv_db();
    foreach ($rows as &$row) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM redemptions WHERE member_id = ? AND created_at >= ?');
        $st->execute([$row['id'], $from . ' 00:00:00']);
        $row['amount_cents'] = (int) $st->fetchColumn();
        $row['points_lifetime'] = fv_member_points((int) $row['id']);
        $row['tier'] = fv_tier($row['points_lifetime']);
        $row['commission_cents'] = fv_member_commission($row, (int) $row['shops'], (int) $row['amount_cents'], $row['points_lifetime']);
    }
    unset($row);
    return $rows;
}

function fv_todo_prospects(int $memberId = 0, int $limit = 12): array
{
    $sql = 'SELECT p.*, m.name AS owner_name FROM prospects p LEFT JOIN members m ON m.id = p.owner_id
        WHERE p.status NOT IN ("boutique_creee", "perdu") AND p.next_action_at IS NOT NULL AND p.next_action_at <= ?';
    $params = [fv_today()];
    if ($memberId > 0) {
        $sql .= ' AND p.owner_id = ?';
        $params[] = $memberId;
    }
    $sql .= ' ORDER BY p.next_action_at ASC, p.priority DESC LIMIT ' . max(1, min(50, $limit));
    $st = fv_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

// ── Sondages ─────────────────────────────────────────────────────────────────

function fv_question_types(): array
{
    return ['choice' => 'Choix unique', 'scale' => 'Note de 1 à 5', 'scale10' => 'Note de 1 à 10', 'text' => 'Texte libre'];
}

function fv_survey_questions_normalize(array $labels, array $types, array $options, array $required): array
{
    $out = [];
    foreach ($labels as $i => $label) {
        $label = fv_text($label, 200);
        if ($label === '') continue;
        $type = (string) ($types[$i] ?? 'choice');
        if (!isset(fv_question_types()[$type])) $type = 'choice';
        $opts = [];
        if ($type === 'choice') {
            foreach (preg_split('/\s*[;|\n]\s*/', (string) ($options[$i] ?? '')) ?: [] as $option) {
                $option = fv_text($option, 80);
                if ($option !== '' && !in_array($option, $opts, true)) $opts[] = $option;
            }
            if (count($opts) < 2) continue;
        }
        $out[] = [
            'id' => 'q' . (count($out) + 1),
            'label' => $label,
            'type' => $type,
            'options' => $opts,
            'required' => !empty($required[$i]),
        ];
        if (count($out) >= 12) break;
    }
    return $out;
}

function fv_survey_slug(string $title): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title)) ?? '', '-'));
    return substr($slug, 0, 50) ?: 'sondage';
}

function fv_survey_by_slug(string $slug): ?array
{
    $st = fv_db()->prepare('SELECT * FROM surveys WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['questions'] = json_decode($row['questions'], true) ?: [];
    return $row;
}

function fv_survey_get(int $id): ?array
{
    $st = fv_db()->prepare('SELECT * FROM surveys WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['questions'] = json_decode($row['questions'], true) ?: [];
    return $row;
}

function fv_survey_response_count(int $surveyId): int
{
    $st = fv_db()->prepare('SELECT COUNT(*) FROM survey_responses WHERE survey_id = ?');
    $st->execute([$surveyId]);
    return (int) $st->fetchColumn();
}

function fv_survey_results(array $survey): array
{
    $st = fv_db()->prepare('SELECT * FROM survey_responses WHERE survey_id = ? ORDER BY created_at DESC, id DESC');
    $st->execute([$survey['id']]);
    $responses = $st->fetchAll();
    $stats = [];
    foreach ($survey['questions'] as $q) {
        $stats[$q['id']] = ['counts' => [], 'values' => [], 'texts' => []];
        if ($q['type'] === 'choice') {
            foreach ($q['options'] as $option) $stats[$q['id']]['counts'][$option] = 0;
        }
    }
    foreach ($responses as $response) {
        $answers = json_decode($response['answers'], true) ?: [];
        foreach ($survey['questions'] as $q) {
            $value = $answers[$q['id']] ?? null;
            if ($value === null || $value === '') continue;
            if ($q['type'] === 'choice') {
                $stats[$q['id']]['counts'][(string) $value] = ($stats[$q['id']]['counts'][(string) $value] ?? 0) + 1;
            } elseif ($q['type'] === 'scale' || $q['type'] === 'scale10') {
                $n = (int) $value;
                if ($n > 0) $stats[$q['id']]['values'][] = $n;
            } else {
                $stats[$q['id']]['texts'][] = fv_text($value, 500);
            }
        }
    }
    foreach ($stats as &$s) {
        $s['average'] = $s['values'] ? round(array_sum($s['values']) / count($s['values']), 1) : null;
        $s['total'] = count($s['values']) ?: array_sum($s['counts']) ?: count($s['texts']);
    }
    unset($s);
    return ['responses' => $responses, 'stats' => $stats];
}

function fv_seed_survey(): void
{
    $pdo = fv_db();
    if ((int) $pdo->query('SELECT COUNT(*) FROM surveys')->fetchColumn() > 0) return;
    $questions = [
        ['id' => 'q1', 'label' => 'Depuis combien de temps utilisez-vous votre boutique ?', 'type' => 'choice', 'options' => ["Moins d'un mois", '1 à 3 mois', '3 à 6 mois', 'Plus de 6 mois'], 'required' => true],
        ['id' => 'q2', 'label' => 'Comment s’est passée la création de votre boutique ?', 'type' => 'scale', 'options' => [], 'required' => true],
        ['id' => 'q3', 'label' => 'Qu’avez-vous réussi à faire sans aide ?', 'type' => 'text', 'options' => [], 'required' => false],
        ['id' => 'q4', 'label' => 'Quelle amélioration vous aiderait le plus ?', 'type' => 'choice', 'options' => ['Plus de photos', 'Aide pour les annonces', 'Formation', 'Paiement en ligne', 'Autre'], 'required' => false],
        ['id' => 'q5', 'label' => 'Recommanderiez-vous Brocs à un autre commerçant ?', 'type' => 'scale10', 'options' => [], 'required' => false],
    ];
    $st = $pdo->prepare('INSERT INTO surveys (slug, title, intro, questions, active, created_at) VALUES (?,?,?,?,?,?)');
    $st->execute([
        'satisfaction-boutique',
        'Satisfaction après création de boutique',
        'Cinq questions rapides pour savoir ce qui marche et ce qu’il faut améliorer. Merci !',
        json_encode($questions, JSON_UNESCAPED_UNICODE),
        1,
        fv_now(),
    ]);
}

// ── Icônes et mise en page ───────────────────────────────────────────────────

function fv_icon(string $name, int $size = 18): string
{
    $paths = [
        'gauge' => '<path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="M13.4 10.6 17 7"/><path d="M3 18a9 9 0 1 1 18 0"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'route' => '<circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M8 19h6a4 4 0 0 0 0-8H10a4 4 0 0 1 0-8h6"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.09 4.18 2 2 0 0 1 4.08 2h3a2 2 0 0 1 2 1.72c.12.9.32 1.78.6 2.63a2 2 0 0 1-.45 2.11L8 9.66a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.11-.45c.85.28 1.73.48 2.63.6A2 2 0 0 1 22 16.92Z"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        'presentation' => '<path d="M2 3h20"/><path d="M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3"/><path d="m7 21 5-5 5 5"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="m7 15 4-4 4 4 5-6"/>',
        'tag' => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82Z"/><circle cx="7" cy="7" r="1"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'print' => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'arrow' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'star' => '<path d="m12 3 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8l-5.8 3.1 1.1-6.5L2.6 9.8l6.5-.9L12 3Z"/>',
    ];
    $path = $paths[$name] ?? $paths['arrow'];
    return '<svg class="fv-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

function fv_nav_items(): array
{
    $manager = fv_can_manage();
    $items = [
        ['index.php', 'Tableau de bord', 'gauge'],
        ['prospects.php', 'Prospects', 'users'],
        ['tournees.php', 'Tournées & terrain', 'route'],
        ['appels.php', 'Appels', 'phone'],
        ['mailing.php', 'Mailing', 'mail'],
        ['kits.php', 'Kits & visuels', 'image'],
        ['pitch.php', 'Pitch & slides', 'presentation'],
        ['sondages.php', 'Sondages', 'chart'],
        ['codes.php', 'Codes & filleuls', 'tag'],
        ['recompenses.php', 'Récompenses', 'gift'],
    ];
    if ($manager) {
        $items[] = ['equipe.php', 'Équipe & rôles', 'users'];
        $items[] = ['reglages.php', 'Réglages', 'settings'];
    }
    return $items;
}

function fv_layout_start(string $title, array $options = []): void
{
    fv_require_auth();
    $ctx = fv_current();
    $member = $ctx['member'];
    $who = $member ? $member['name'] : 'Direction';
    $role = $member ? fv_role_label($member['role']) : 'Administration';
    $flash = fv_flash();
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $actions = (string) ($options['actions'] ?? '');
    $eyebrow = (string) ($options['eyebrow'] ?? 'Forces de vente');
    $desc = (string) ($options['desc'] ?? '');
    $wide = !empty($options['wide']);
    $head = (string) ($options['head'] ?? '');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — <?= e(fv_setting('marque_nom', 'Brocs')) ?> · Vente</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="assets/vente.css?v=6">
<?= $head ?>
</head>
<body class="fv-body<?= $wide ? ' fv-wide' : '' ?>">
<div class="fv-app">
  <aside class="fv-side" id="fv-side">
    <div class="fv-brand">
      <span class="fv-mark" aria-hidden="true">B</span>
      <span><b><?= e(fv_setting('marque_nom', 'Brocs')) ?></b><small>Forces de vente</small></span>
    </div>
    <nav class="fv-nav" aria-label="Navigation de l’espace vente">
      <?php foreach (fv_nav_items() as [$href, $label, $icon]): ?>
        <a href="<?= e($href) ?>"<?= $current === $href ? ' aria-current="page"' : '' ?>><?= fv_icon($icon, 17) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="fv-side-foot">
      <?php if ($member): ?>
        <a href="deconnexion.php"><?= fv_icon('logout', 16) ?> Se déconnecter</a>
      <?php endif; ?>
      <a href="/portail/"><?= fv_icon('arrow', 16) ?> Retour au portail</a>
    </div>
  </aside>

  <div class="fv-shell">
    <header class="fv-top">
      <button class="fv-burger" type="button" data-fv-burger aria-controls="fv-side" aria-expanded="false" aria-label="Ouvrir le menu"><?= fv_icon('menu', 20) ?></button>
      <div class="fv-top-title">
        <span class="fv-eyebrow"><?= e($eyebrow) ?></span>
        <h1><?= e($title) ?></h1>
      </div>
      <div class="fv-top-actions">
        <?= $actions ?>
        <div class="fv-user" title="<?= e($role) ?>">
          <span class="fv-avatar"><?= e(fv_initials($who)) ?></span>
          <span class="fv-user-txt"><b><?= e($who) ?></b><small><?= e($role) ?></small></span>
        </div>
      </div>
    </header>

    <?php if ($ctx['view_as'] && $member): ?>
      <div class="fv-banner fv-banner-view">
        <span>Vous voyez l’espace comme <strong><?= e($member['name']) ?></strong> (<?= e(fv_role_label($member['role'])) ?>).</span>
        <a class="fv-btn fv-btn-sm" href="equipe.php?quitter=1">Quitter la vue</a>
      </div>
    <?php endif; ?>
    <?php if ($flash): ?>
      <p class="fv-flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p>
    <?php endif; ?>
    <?php if ($desc !== ''): ?><p class="fv-lede"><?= $desc ?></p><?php endif; ?>

    <main class="fv-main">
<?php
}

function fv_layout_end(): void
{
    ?></main>
    <footer class="fv-foot">
      <span><?= e(fv_setting('marque_nom', 'Brocs')) ?> · espace des forces de vente</span>
      <span class="fv-mono">données locales · <?= e(date('d/m/Y')) ?></span>
    </footer>
  </div>
</div>
<script src="assets/vente.js?v=6" defer></script>
</body>
</html><?php
}

function fv_public_start(string $title): void
{
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="assets/vente.css?v=6">
</head>
<body class="fv-body fv-public">
<main class="fv-public-wrap">
<?php
}

function fv_public_end(): void
{
    ?></main>
</body>
</html><?php
}
