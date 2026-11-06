<?php
/**
 * Module Notifications — envoi de messages aux utilisateurs sur leurs canaux
 * préférés : e-mail, SMS, WhatsApp, Discord, Telegram.
 *
 * Principes
 *  - Un « utilisateur » (nt_users) est un contact notifiable : client, bénévole,
 *    adhérent… Chaque utilisateur a une adresse par canal (nt_user_channels) et
 *    des préférences rubrique × canal (nt_preferences).
 *  - Une « rubrique » (nt_topics) classe les messages (commande, événement…).
 *    Les rubriques marketing exigent un abonnement explicite (opt-in) ; les
 *    autres sont reçues par défaut sur les canaux actifs.
 *  - nt_notify() ne fait que mettre les envois en file (nt_deliveries) ; ils
 *    partent tout de suite (petits volumes) ou via scripts/notifications-worker.php
 *    (cron). Échecs temporaires : nouvelles tentatives espacées ; canal de repli
 *    possible (stratégie « fallback »).
 *
 * Les clés d'API vivent dans .secrets/notifications.json (jamais dans la base,
 * jamais déployé — comme les clés Stripe/Gemini). Le schéma se crée tout seul au
 * premier accès (nt_boot) : le déploiement n'envoie jamais brocante.db.
 *
 * API publique pour le reste du site :
 *   nt_notify('commande', [$userId], 'Votre colis est parti', 'Bonjour {prenom}, …');
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

const NT_MAX_ATTEMPTS = 4;                 // tentatives par envoi avant abandon
const NT_BACKOFF = [60, 600, 3600];        // secondes avant les 2e, 3e et 4e tentatives
const NT_LEASE_SECONDS = 600;              // un envoi « en cours » plus vieux que ça est repris
const NT_HTTP_TIMEOUT = 15;

/* ====================================================================== */
/* Schéma                                                                 */
/* ====================================================================== */

const NT_SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS nt_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  token TEXT NOT NULL UNIQUE,                   -- lien secret : gestion des préférences par l'utilisateur
  source TEXT NOT NULL DEFAULT 'manuel',        -- manuel | commande | benevole
  source_ref TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS nt_user_channels (
  user_id INTEGER NOT NULL REFERENCES nt_users(id) ON DELETE CASCADE,
  channel TEXT NOT NULL,                        -- email | sms | whatsapp | discord | telegram
  address TEXT NOT NULL,                        -- adresse normalisée (E.164, e-mail, ID…)
  enabled INTEGER NOT NULL DEFAULT 1,
  priority INTEGER NOT NULL DEFAULT 0,          -- 0 = canal préféré ; sert à l'ordre et au repli
  disabled_reason TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (user_id, channel)
);

CREATE TABLE IF NOT EXISTS nt_topics (
  key TEXT PRIMARY KEY,
  label TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  is_marketing INTEGER NOT NULL DEFAULT 0,      -- 1 = opt-in explicite requis
  is_system INTEGER NOT NULL DEFAULT 0,         -- 1 = rubrique d'origine, non supprimable
  sort_order INTEGER NOT NULL DEFAULT 0
);

-- Écarts à la valeur par défaut uniquement (transactionnel : actif ; marketing : inactif).
-- La ligne d'un opt-in marketing sert de preuve de consentement (date + source).
CREATE TABLE IF NOT EXISTS nt_preferences (
  user_id INTEGER NOT NULL REFERENCES nt_users(id) ON DELETE CASCADE,
  topic TEXT NOT NULL,
  channel TEXT NOT NULL,
  enabled INTEGER NOT NULL,
  source TEXT NOT NULL DEFAULT '',              -- admin | utilisateur | import
  updated_at TEXT NOT NULL,
  PRIMARY KEY (user_id, topic, channel)
);

CREATE TABLE IF NOT EXISTS nt_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  topic TEXT NOT NULL,
  subject TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  short_body TEXT NOT NULL DEFAULT '',          -- version courte (SMS, WhatsApp, Discord, Telegram)
  strategy TEXT NOT NULL DEFAULT 'all',         -- all | fallback
  created_by TEXT NOT NULL DEFAULT 'admin',
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS nt_deliveries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message_id INTEGER NOT NULL REFERENCES nt_messages(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES nt_users(id) ON DELETE CASCADE,
  channel TEXT NOT NULL,
  address TEXT NOT NULL,
  rank INTEGER NOT NULL DEFAULT 0,              -- ordre dans la stratégie « fallback »
  forced INTEGER NOT NULL DEFAULT 0,            -- 1 = message de test (ignore les préférences de rubrique)
  status TEXT NOT NULL DEFAULT 'queued',        -- queued | sending | sent | failed | skipped
  attempts INTEGER NOT NULL DEFAULT 0,
  next_attempt_at INTEGER NOT NULL,             -- epoch ; sert aussi de bail pendant « sending »
  provider_id TEXT NOT NULL DEFAULT '',
  error TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  sent_at TEXT
);

CREATE TABLE IF NOT EXISTS nt_meta (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_nt_channels_addr ON nt_user_channels(channel, address);
CREATE INDEX IF NOT EXISTS idx_nt_deliveries_due ON nt_deliveries(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_nt_deliveries_user ON nt_deliveries(user_id);
CREATE INDEX IF NOT EXISTS idx_nt_deliveries_msg ON nt_deliveries(message_id, user_id);
SQL;

function nt_boot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(NT_SCHEMA);
    $seed = db()->prepare('INSERT OR IGNORE INTO nt_topics (key, label, description, is_marketing, is_system, sort_order) VALUES (?, ?, ?, ?, 1, ?)');
    foreach ([
        ['commande', 'Suivi de commande', 'Confirmation, expédition, retrait de vos achats.', 0],
        ['evenement', 'Événements & vide-greniers', 'Rappels de date, de lieu et d\'horaires.', 0],
        ['benevolat', 'Bénévolat', 'Planning, consignes et changements pour les bénévoles.', 0],
        ['invendus', 'Dons & invendus', 'Collecte et remise des invendus.', 0],
        ['newsletter', 'Actualités & nouveautés', 'Nouvelles trouvailles, prochains événements, offres.', 1],
    ] as $i => [$key, $label, $desc, $marketing]) {
        $seed->execute([$key, $label, $desc, $marketing, $i]);
    }
}

function nt_meta_get(string $key, string $default = ''): string
{
    nt_boot();
    $st = db()->prepare('SELECT v FROM nt_meta WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function nt_meta_set(string $key, string $value): void
{
    nt_boot();
    db()->prepare('INSERT INTO nt_meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$key, $value]);
}

/* ====================================================================== */
/* Temps, hooks de test                                                   */
/* ====================================================================== */

function nt_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Europe/Paris');
}

/** Horloge du module (substituable en test avec nt_set_clock). */
function nt_now(): int
{
    return nt_clock_override() ?? time();
}

function nt_clock_override(?int $set = null, bool $reset = false): ?int
{
    static $fake = null;
    if ($reset) $fake = null;
    elseif ($set !== null) $fake = $set;
    return $fake;
}

function nt_set_clock(?int $ts): void
{
    nt_clock_override($ts, $ts === null);
}

function nt_fmt(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(nt_tz())->format('Y-m-d H:i:s');
}

/** Points d'injection pour les tests : 'http' (appels d'API) et 'mail' (fonction mail()). */
function nt_hook(string $name, ?callable $set = null, bool $clear = false): ?callable
{
    static $hooks = [];
    if ($clear) unset($hooks[$name]);
    elseif ($set !== null) $hooks[$name] = $set;
    return $hooks[$name] ?? null;
}

/* ====================================================================== */
/* Configuration (.secrets/notifications.json)                            */
/* ====================================================================== */

function nt_config_path(): string
{
    if (defined('NT_CONFIG_FILE')) return NT_CONFIG_FILE;
    return getenv('BROCANTE_NT_CONFIG') ?: dirname(__DIR__) . '/.secrets/notifications.json';
}

function nt_config_defaults(): array
{
    return [
        'general' => ['country_code' => '33', 'site_name' => ''],
        // Heures calmes : SMS et WhatsApp de rubriques marketing différés hors de la plage ouverte.
        'quiet' => ['start' => 20, 'end' => 8, 'sunday' => true],
        'email' => [
            'transport' => 'mail', 'from_email' => '', 'from_name' => '', 'reply_to' => '',
            'smtp_host' => '', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'smtp_user' => '', 'smtp_pass' => '',
        ],
        'twilio' => ['account_sid' => '', 'auth_token' => ''],
        'sms' => ['from' => '', 'messaging_service_sid' => ''],
        'whatsapp' => ['from' => ''],
        'discord' => ['bot_token' => ''],
        'telegram' => ['bot_token' => ''],
    ];
}

/** Champs secrets : jamais réaffichés, un champ vide à l'enregistrement les conserve. */
function nt_secret_fields(): array
{
    return ['email.smtp_pass', 'twilio.auth_token', 'discord.bot_token', 'telegram.bot_token'];
}

function nt_config(bool $reload = false): array
{
    static $cfg = null;
    if ($cfg === null || $reload) {
        $data = [];
        $file = nt_config_path();
        if (is_file($file)) {
            $json = json_decode((string) file_get_contents($file), true);
            if (is_array($json)) $data = $json;
        }
        $cfg = array_replace_recursive(nt_config_defaults(), $data);
    }
    return $cfg;
}

/** Lecture d'un réglage par chemin pointé : nt_cfg('twilio.account_sid'). */
function nt_cfg(string $path, $default = '')
{
    $v = nt_config();
    foreach (explode('.', $path) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) return $default;
        $v = $v[$part];
    }
    return $v;
}

function nt_config_save(array $cfg): bool
{
    $file = nt_config_path();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    // Garde-fou si le serveur sert les dossiers cachés (Apache) ; sans effet sous nginx.
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) @file_put_contents($htaccess, "Require all denied\n");
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) return false;
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    nt_config(true);
    return true;
}

/** Fusionne des valeurs dans une section. Un secret laissé vide garde sa valeur actuelle. */
function nt_config_update(string $section, array $values): bool
{
    $cfg = nt_config();
    if (!isset($cfg[$section])) return false;
    foreach ($values as $key => $value) {
        if (!array_key_exists($key, $cfg[$section])) continue;
        $isSecret = in_array($section . '.' . $key, nt_secret_fields(), true);
        if ($isSecret && trim((string) $value) === '') continue;
        $cfg[$section][$key] = is_int($cfg[$section][$key]) ? (int) $value : (is_bool($cfg[$section][$key]) ? (bool) $value : trim((string) $value));
    }
    return nt_config_save($cfg);
}

/* ====================================================================== */
/* Canaux : référentiel, adresses                                         */
/* ====================================================================== */

function nt_channels(): array
{
    return [
        'email' => ['label' => 'E-mail', 'address_label' => 'Adresse e-mail', 'placeholder' => 'prenom@exemple.fr', 'max_len' => 0, 'quiet' => false],
        'sms' => ['label' => 'SMS', 'address_label' => 'Mobile (SMS)', 'placeholder' => '06 12 34 56 78', 'max_len' => 1600, 'quiet' => true],
        'whatsapp' => ['label' => 'WhatsApp', 'address_label' => 'Mobile (WhatsApp)', 'placeholder' => '06 12 34 56 78', 'max_len' => 4096, 'quiet' => true],
        'discord' => ['label' => 'Discord', 'address_label' => 'Discord (ID utilisateur ou webhook)', 'placeholder' => '123456789012345678', 'max_len' => 2000, 'quiet' => false],
        'telegram' => ['label' => 'Telegram', 'address_label' => 'Telegram (chat ID)', 'placeholder' => '123456789', 'max_len' => 4096, 'quiet' => false],
    ];
}

function nt_channel_label(string $channel): string
{
    return nt_channels()[$channel]['label'] ?? $channel;
}

const NT_DISCORD_WEBHOOK_RE = '#^https://(?:www\.)?discord(?:app)?\.com/api/webhooks/\d{17,20}/[A-Za-z0-9_-]{10,}$#';

/**
 * Normalise et valide une adresse pour un canal ; null si invalide.
 * Les URL de webhook Discord sont restreintes à discord.com : l'adresse est
 * saisie à la main, on ne veut pas que le serveur appelle une URL quelconque.
 */
function nt_normalize_address(string $channel, string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || preg_match('/[\x00-\x1F\x7F]/', $raw)) return null;
    switch ($channel) {
        case 'email':
            $raw = strtolower($raw);
            return (strlen($raw) <= 254 && filter_var($raw, FILTER_VALIDATE_EMAIL)) ? $raw : null;
        case 'sms':
        case 'whatsapp':
            return nt_normalize_phone($raw);
        case 'discord':
            if (preg_match('/^\d{17,20}$/', $raw)) return $raw;
            return preg_match(NT_DISCORD_WEBHOOK_RE, $raw) ? $raw : null;
        case 'telegram':
            return preg_match('/^(-?\d{3,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/', $raw) ? $raw : null;
    }
    return null;
}

/**
 * Numéro -> format international E.164 (+33612345678).
 * Accepte « 06 12 34 56 78 » (indicatif du pays par défaut), « +33 6… », « 0033 6… ».
 * Un numéro sans 0 ni + (« 612345678 ») est refusé : trop ambigu.
 */
function nt_normalize_phone(string $raw): ?string
{
    $cc = preg_replace('/\D/', '', (string) nt_cfg('general.country_code', '33')) ?: '33';
    $s = preg_replace('/[\s.\-()\/]/', '', $raw);
    if (!preg_match('/^\+?\d+$/', $s)) return null;
    if ($s[0] === '+') $digits = substr($s, 1);
    elseif (str_starts_with($s, '00')) $digits = substr($s, 2);
    elseif ($s[0] === '0') $digits = $cc . substr($s, 1);
    else return null;
    $digits = preg_replace('/^330/', '33', $digits); // « +33 (0)6 … » : le 0 de tête est de trop
    return preg_match('/^[1-9]\d{7,14}$/', $digits) ? '+' . $digits : null;
}

/** Affichage discret d'une adresse (historique, listes). */
function nt_mask_address(string $channel, string $address): string
{
    if ($channel === 'email' && str_contains($address, '@')) {
        [$local, $domain] = explode('@', $address, 2);
        return mb_substr($local, 0, 2) . '…@' . $domain;
    }
    if ($channel === 'discord' && str_starts_with($address, 'https://')) return 'webhook Discord';
    if (in_array($channel, ['sms', 'whatsapp'], true)) return substr($address, 0, 5) . '…' . substr($address, -2);
    return mb_strlen($address) > 6 ? mb_substr($address, 0, 3) . '…' . mb_substr($address, -2) : $address;
}

/** Le canal peut-il envoyer vers cette adresse avec la configuration actuelle ? */
function nt_channel_ready(string $channel, string $address = ''): bool
{
    switch ($channel) {
        case 'email':
            if (!filter_var(nt_cfg('email.from_email'), FILTER_VALIDATE_EMAIL)) return false;
            return nt_cfg('email.transport') !== 'smtp' || (nt_cfg('email.smtp_host') !== '' && (int) nt_cfg('email.smtp_port') > 0);
        case 'sms':
            return nt_twilio_ready() && (nt_cfg('sms.from') !== '' || nt_cfg('sms.messaging_service_sid') !== '');
        case 'whatsapp':
            return nt_twilio_ready() && nt_normalize_phone((string) nt_cfg('whatsapp.from')) !== null;
        case 'discord':
            if ($address !== '' && str_starts_with($address, 'https://')) return true; // webhook : aucun jeton requis
            return nt_cfg('discord.bot_token') !== '';
        case 'telegram':
            return nt_cfg('telegram.bot_token') !== '';
    }
    return false;
}

function nt_twilio_ready(): bool
{
    return nt_cfg('twilio.account_sid') !== '' && nt_cfg('twilio.auth_token') !== '';
}

/** Canaux utilisables d'après la configuration (webhook Discord compris) : pour les tableaux de bord. */
function nt_channel_status(): array
{
    $out = [];
    foreach (nt_channels() as $key => $def) {
        $ready = nt_channel_ready($key);
        // Discord sans jeton reste utilisable par webhook : on le signale à part.
        $out[$key] = ['label' => $def['label'], 'ready' => $ready, 'webhook_only' => $key === 'discord' && !$ready];
    }
    return $out;
}

/* ====================================================================== */
/* Rubriques                                                              */
/* ====================================================================== */

function nt_topics(): array
{
    nt_boot();
    $out = [];
    foreach (db()->query('SELECT * FROM nt_topics ORDER BY sort_order, label COLLATE NOCASE') as $t) {
        $t['is_marketing'] = (int) $t['is_marketing'];
        $out[$t['key']] = $t;
    }
    return $out;
}

/** Rubrique par clé ; « test » est une rubrique technique (messages d'essai), jamais marketing. */
function nt_topic_row(string $key): ?array
{
    if ($key === 'test') return ['key' => 'test', 'label' => 'Message de test', 'description' => '', 'is_marketing' => 0, 'is_system' => 1, 'sort_order' => 0];
    return nt_topics()[$key] ?? null;
}

function nt_valid_topic_key(string $key): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key) && $key !== 'test';
}

function nt_topic_save(string $key, string $label, string $description, bool $marketing): ?string
{
    nt_boot();
    $label = trim($label);
    if (!nt_valid_topic_key($key)) return 'Clé invalide : lettres minuscules, chiffres et _ (2 à 32 caractères, commence par une lettre).';
    if ($label === '') return 'Le libellé est obligatoire.';
    $existing = nt_topics()[$key] ?? null;
    if ($existing) {
        // Le caractère « marketing » ne se modifie pas : passer une rubrique de marketing à
        // transactionnelle abonnerait d'office tout le monde, sans consentement.
        db()->prepare('UPDATE nt_topics SET label = ?, description = ? WHERE key = ?')
            ->execute([mb_substr($label, 0, 80), mb_substr(trim($description), 0, 240), $key]);
        return null;
    }
    $next = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM nt_topics')->fetchColumn();
    db()->prepare('INSERT INTO nt_topics (key, label, description, is_marketing, is_system, sort_order) VALUES (?, ?, ?, ?, 0, ?)')
        ->execute([$key, mb_substr($label, 0, 80), mb_substr(trim($description), 0, 240), (int) $marketing, $next]);
    return null;
}

function nt_topic_delete(string $key): bool
{
    nt_boot();
    $t = nt_topics()[$key] ?? null;
    if (!$t || $t['is_system']) return false;
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM nt_preferences WHERE topic = ?')->execute([$key]);
    $pdo->prepare('DELETE FROM nt_topics WHERE key = ?')->execute([$key]);
    $pdo->commit();
    return true;
}

/* ====================================================================== */
/* Utilisateurs                                                           */
/* ====================================================================== */

function nt_user_create(string $name, string $notes = '', string $source = 'manuel', string $sourceRef = ''): int
{
    nt_boot();
    db()->prepare('INSERT INTO nt_users (name, token, source, source_ref, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([mb_substr(trim($name), 0, 120), bin2hex(random_bytes(16)), $source, $sourceRef, mb_substr(trim($notes), 0, 500), nt_fmt(nt_now())]);
    return (int) db()->lastInsertId();
}

/**
 * Charge des utilisateurs avec leurs canaux et leurs préférences explicites,
 * en 3 requêtes (pas de N+1 pour les diffusions). $ids = null : tous.
 * @return array<int, array>
 */
function nt_users_load(?array $ids = null): array
{
    nt_boot();
    $pdo = db();
    $users = [];
    if ($ids === null) {
        foreach ($pdo->query('SELECT * FROM nt_users ORDER BY name COLLATE NOCASE') as $u) $users[(int) $u['id']] = $u;
    } else {
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT * FROM nt_users WHERE id IN ($in)");
            $st->execute($chunk);
            foreach ($st as $u) $users[(int) $u['id']] = $u;
        }
    }
    foreach ($users as &$u) {
        $u['id'] = (int) $u['id'];
        $u['is_active'] = (int) $u['is_active'];
        $u['channels'] = [];
        $u['prefs'] = [];
    }
    unset($u);
    if (!$users) return [];

    $keys = array_keys($users);
    foreach (array_chunk($keys, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT * FROM nt_user_channels WHERE user_id IN ($in)");
        $st->execute($chunk);
        foreach ($st as $c) {
            $c['enabled'] = (int) $c['enabled'];
            $c['priority'] = (int) $c['priority'];
            $users[(int) $c['user_id']]['channels'][$c['channel']] = $c;
        }
        $st = $pdo->prepare("SELECT * FROM nt_preferences WHERE user_id IN ($in)");
        $st->execute($chunk);
        foreach ($st as $p) $users[(int) $p['user_id']]['prefs'][$p['topic']][$p['channel']] = (bool) $p['enabled'];
    }
    return $users;
}

function nt_user_get(int $id): ?array
{
    return nt_users_load([$id])[$id] ?? null;
}

function nt_user_by_token(string $token): ?array
{
    nt_boot();
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    $st = db()->prepare('SELECT id FROM nt_users WHERE token = ?');
    $st->execute([$token]);
    $id = $st->fetchColumn();
    return $id ? nt_user_get((int) $id) : null;
}

/** Recherche (nom, notes ou adresse). Les jokers LIKE saisis sont neutralisés. */
function nt_users_search(string $q = '', int $limit = 200): array
{
    nt_boot();
    $limit = max(1, min(1000, $limit));
    if (trim($q) === '') {
        $ids = db()->query('SELECT id FROM nt_users ORDER BY name COLLATE NOCASE LIMIT ' . $limit)->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $like = '%' . addcslashes(trim($q), '%_\\') . '%';
        $st = db()->prepare("SELECT DISTINCT u.id FROM nt_users u LEFT JOIN nt_user_channels c ON c.user_id = u.id
            WHERE u.name LIKE ? ESCAPE '\\' OR u.notes LIKE ? ESCAPE '\\' OR c.address LIKE ? ESCAPE '\\'
            ORDER BY u.name COLLATE NOCASE LIMIT $limit");
        $st->execute([$like, $like, $like]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    $loaded = nt_users_load($ids);
    $out = [];
    foreach ($ids as $id) if (isset($loaded[(int) $id])) $out[] = $loaded[(int) $id];
    return $out;
}

function nt_user_update(int $id, string $name, string $notes, bool $active): bool
{
    $name = trim($name);
    if ($name === '') return false;
    return db()->prepare('UPDATE nt_users SET name = ?, notes = ?, is_active = ? WHERE id = ?')
        ->execute([mb_substr($name, 0, 120), mb_substr(trim($notes), 0, 500), (int) $active, $id]);
}

/** Suppression complète (droit à l'effacement) : canaux, préférences et historique partent avec. */
function nt_user_delete(int $id): void
{
    nt_boot();
    db()->prepare('DELETE FROM nt_users WHERE id = ?')->execute([$id]);
    db()->exec('DELETE FROM nt_messages WHERE id NOT IN (SELECT DISTINCT message_id FROM nt_deliveries)');
}

function nt_user_first_name(array $user): string
{
    $parts = preg_split('/\s+/u', trim((string) ($user['name'] ?? ''))) ?: [];
    return $parts[0] ?? '';
}

/** Enregistre (ou met à jour) l'adresse d'un utilisateur sur un canal. Retourne un message d'erreur ou null. */
function nt_user_set_channel(int $userId, string $channel, string $rawAddress, bool $enabled = true, int $priority = 0): ?string
{
    nt_boot();
    $def = nt_channels()[$channel] ?? null;
    if (!$def) return 'Canal inconnu.';
    $address = nt_normalize_address($channel, $rawAddress);
    if ($address === null) return $def['label'] . ' : adresse invalide' . (in_array($channel, ['sms', 'whatsapp'], true) ? ' (format 06 12 34 56 78 ou +33 6 12 34 56 78).' : '.');
    $old = db()->prepare('SELECT address, enabled, disabled_reason FROM nt_user_channels WHERE user_id = ? AND channel = ?');
    $old->execute([$userId, $channel]);
    $prev = $old->fetch();
    // Une nouvelle adresse ou une réactivation efface le motif de blocage ; un canal qui reste
    // désactivé sur la même adresse garde son motif (ex. « désabonné par STOP »).
    $reason = ($prev && $prev['address'] === $address && !$enabled) ? (string) $prev['disabled_reason'] : '';
    db()->prepare('INSERT INTO nt_user_channels (user_id, channel, address, enabled, priority, disabled_reason) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(user_id, channel) DO UPDATE SET address = excluded.address, enabled = excluded.enabled, priority = excluded.priority, disabled_reason = excluded.disabled_reason')
        ->execute([$userId, $channel, $address, (int) $enabled, max(0, min(99, $priority)), $reason]);
    return null;
}

function nt_user_remove_channel(int $userId, string $channel): void
{
    db()->prepare('DELETE FROM nt_user_channels WHERE user_id = ? AND channel = ?')->execute([$userId, $channel]);
    // Les envois en attente vers ce canal n'ont plus de destinataire.
    db()->prepare("UPDATE nt_deliveries SET status = 'skipped', error = 'Canal supprimé' WHERE user_id = ? AND channel = ? AND status = 'queued'")->execute([$userId, $channel]);
}

/** Coupe un canal (retour d'erreur définitive du fournisseur, désinscription…). */
function nt_user_disable_channel(int $userId, string $channel, string $reason): void
{
    db()->prepare('UPDATE nt_user_channels SET enabled = 0, disabled_reason = ? WHERE user_id = ? AND channel = ?')
        ->execute([mb_substr($reason, 0, 160), $userId, $channel]);
}

function nt_user_find_by_address(string $channel, string $address): ?int
{
    $st = db()->prepare('SELECT user_id FROM nt_user_channels WHERE channel = ? AND address = ? LIMIT 1');
    $st->execute([$channel, $address]);
    $id = $st->fetchColumn();
    return $id ? (int) $id : null;
}

/* ====================================================================== */
/* Préférences                                                            */
/* ====================================================================== */

/** Valeur par défaut d'une rubrique : transactionnelle = reçue ; marketing = non (opt-in). */
function nt_pref_default(array $topic): bool
{
    return !$topic['is_marketing'];
}

/** Préférence effective d'un utilisateur chargé (nt_users_load) pour une rubrique et un canal. */
function nt_pref_effective(array $user, array $topic, string $channel): bool
{
    return $user['prefs'][$topic['key']][$channel] ?? nt_pref_default($topic);
}

/** N'enregistre que les écarts à la valeur par défaut ; supprime la ligne si elle redevient inutile. */
function nt_set_pref(int $userId, string $topicKey, string $channel, bool $enabled, string $source = 'admin'): bool
{
    $topic = nt_topics()[$topicKey] ?? null;
    if (!$topic || !isset(nt_channels()[$channel])) return false;
    if ($enabled === nt_pref_default($topic)) {
        db()->prepare('DELETE FROM nt_preferences WHERE user_id = ? AND topic = ? AND channel = ?')->execute([$userId, $topicKey, $channel]);
        return true;
    }
    db()->prepare('INSERT INTO nt_preferences (user_id, topic, channel, enabled, source, updated_at) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(user_id, topic, channel) DO UPDATE SET enabled = excluded.enabled, source = excluded.source, updated_at = excluded.updated_at')
        ->execute([$userId, $topicKey, $channel, (int) $enabled, $source, nt_fmt(nt_now())]);
    return true;
}

/** Désabonne l'utilisateur de toutes les rubriques marketing. Retourne le nombre de cellules touchées. */
function nt_user_optout_marketing(int $userId, string $source = 'utilisateur'): int
{
    $n = 0;
    foreach (nt_topics() as $key => $topic) {
        if (!$topic['is_marketing']) continue;
        foreach (array_keys(nt_channels()) as $ch) {
            if (nt_set_pref($userId, $key, $ch, false, $source)) $n++;
        }
    }
    return $n;
}

/** « Tout arrêter » : coupe tous les canaux de l'utilisateur (même les messages de suivi). */
function nt_user_disable_all(int $userId, string $reason): void
{
    db()->prepare("UPDATE nt_user_channels SET enabled = 0, disabled_reason = ? WHERE user_id = ?")->execute([mb_substr($reason, 0, 160), $userId]);
    db()->prepare("UPDATE nt_deliveries SET status = 'skipped', error = 'Désinscription' WHERE user_id = ? AND status = 'queued'")->execute([$userId]);
}

/* ====================================================================== */
/* Heures calmes                                                          */
/* ====================================================================== */

/**
 * Premier instant autorisé à partir de $ts pour un envoi marketing par SMS /
 * WhatsApp : hors plage nocturne (20h–8h par défaut) et, si demandé, hors
 * dimanche. Les jours fériés ne sont pas gérés : outil d'aide, pas avis juridique.
 */
function nt_quiet_adjust(int $ts): int
{
    $q = nt_cfg('quiet', []);
    $start = (int) ($q['start'] ?? 20);
    $end = (int) ($q['end'] ?? 8);
    $sunday = !empty($q['sunday']);
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone(nt_tz());
    for ($i = 0; $i < 10; $i++) {
        if ($sunday && $d->format('N') === '7') {
            $d = $d->modify('next monday')->setTime(0, 0);
            continue;
        }
        $h = (int) $d->format('G');
        if ($start === $end) break;
        $quiet = $start > $end ? ($h >= $start || $h < $end) : ($h >= $start && $h < $end);
        if (!$quiet) break;
        $d = ($start > $end && $h >= $start) ? $d->modify('+1 day')->setTime($end, 0) : $d->setTime($end, 0);
    }
    return $d->getTimestamp();
}

/* ====================================================================== */
/* Planification & mise en file                                           */
/* ====================================================================== */

/**
 * Canaux qu'on utiliserait pour un utilisateur (chargé) et une rubrique, avec les raisons des exclusions.
 * @return array{targets: array, skipped: array}
 */
function nt_targets(array $user, array $topic, array $opts = []): array
{
    $only = isset($opts['channels']) && is_array($opts['channels']) ? $opts['channels'] : null;
    $ignorePrefs = !empty($opts['ignore_preferences']);
    if (!$user['is_active']) return ['targets' => [], 'skipped' => [['*', 'Utilisateur désactivé']]];
    $order = array_flip(array_keys(nt_channels()));
    $targets = $skipped = [];
    foreach ($user['channels'] as $ch => $row) {
        if (!isset($order[$ch])) continue;
        if ($only !== null && !in_array($ch, $only, true)) continue;
        if (!$row['enabled']) {
            $skipped[] = [$ch, 'Canal désactivé' . ($row['disabled_reason'] !== '' ? ' (' . $row['disabled_reason'] . ')' : '')];
        } elseif (!nt_channel_ready($ch, $row['address'])) {
            $skipped[] = [$ch, 'Canal non configuré'];
        } elseif (!$ignorePrefs && !nt_pref_effective($user, $topic, $ch)) {
            $skipped[] = [$ch, 'Non abonné à cette rubrique'];
        } else {
            $targets[] = ['channel' => $ch, 'address' => $row['address'], 'priority' => $row['priority']];
        }
    }
    usort($targets, fn($a, $b) => [$a['priority'], $order[$a['channel']]] <=> [$b['priority'], $order[$b['channel']]]);
    return ['targets' => $targets, 'skipped' => $skipped];
}

/**
 * Simulation d'une diffusion : pour chaque utilisateur, canaux retenus et exclus.
 * $userIds = null : tous les utilisateurs.
 * @return array{users: array, counts: array<string,int>, recipients: int, none: int}
 */
function nt_plan(string $topicKey, ?array $userIds, array $opts = []): array
{
    $topic = nt_topic_row($topicKey);
    if (!$topic) return ['users' => [], 'counts' => [], 'recipients' => 0, 'none' => 0, 'error' => 'Rubrique inconnue.'];
    $fallback = ($opts['strategy'] ?? 'all') === 'fallback';
    $users = nt_users_load($userIds);
    $plan = [];
    $counts = array_fill_keys(array_keys(nt_channels()), 0);
    $recipients = $none = 0;
    foreach ($users as $id => $u) {
        $r = nt_targets($u, $topic, $opts);
        $plan[$id] = ['user' => $u, 'targets' => $r['targets'], 'skipped' => $r['skipped']];
        if ($r['targets']) {
            $recipients++;
            // En repli, seul le premier canal est visé a priori ; les suivants ne servent qu'en cas d'échec.
            foreach ($fallback ? [$r['targets'][0]] : $r['targets'] as $t) $counts[$t['channel']]++;
        } else {
            $none++;
        }
    }
    return ['users' => $plan, 'counts' => $counts, 'recipients' => $recipients, 'none' => $none];
}

/**
 * Met un message en file pour des utilisateurs, selon leurs canaux et préférences.
 *
 * $opts : strategy 'all' (défaut : tous les canaux retenus) | 'fallback' (le premier
 *         qui réussit, les suivants en repli) ; channels [] (restreindre) ;
 *         short_body (texte court SMS/WhatsApp/Discord/Telegram) ; immediate (défaut true :
 *         tente l'envoi tout de suite) ; ignore_preferences (messages de test) ; created_by.
 *
 * @return array{error: ?string, message_id: ?int, queued: int, recipients: int, none: int, skipped: array, processed: ?array}
 */
function nt_notify(string $topicKey, array $userIds, string $subject, string $body, array $opts = []): array
{
    nt_boot();
    $res = ['error' => null, 'message_id' => null, 'queued' => 0, 'recipients' => 0, 'none' => 0, 'skipped' => [], 'processed' => null];
    $topic = nt_topic_row($topicKey);
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    $body = trim($body);
    if (!$topic) return ['error' => 'Rubrique inconnue.'] + $res;
    if ($body === '' && trim((string) ($opts['short_body'] ?? '')) === '') return ['error' => 'Le message est vide.'] + $res;
    if ($body === '') $body = trim((string) $opts['short_body']);

    $strategy = ($opts['strategy'] ?? 'all') === 'fallback' ? 'fallback' : 'all';
    $plan = nt_plan($topicKey, $userIds, ['strategy' => $strategy] + $opts);
    $res['recipients'] = $plan['recipients'];
    $res['none'] = $plan['none'];
    foreach ($plan['users'] as $uid => $p) foreach ($p['skipped'] as [$ch, $why]) $res['skipped'][] = [$uid, $p['user']['name'], $ch, $why];
    if ($plan['recipients'] === 0) return $res;

    $pdo = db();
    $now = nt_now();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO nt_messages (topic, subject, body, short_body, strategy, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$topicKey, mb_substr($subject, 0, 200), mb_substr($body, 0, 20000), mb_substr(trim((string) ($opts['short_body'] ?? '')), 0, 2000), $strategy, (string) ($opts['created_by'] ?? 'admin'), nt_fmt($now)]);
        $messageId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO nt_deliveries (message_id, user_id, channel, address, rank, forced, status, next_attempt_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($plan['users'] as $uid => $p) {
            foreach ($p['targets'] as $rank => $t) {
                $when = $now;
                if ($topic['is_marketing'] && (nt_channels()[$t['channel']]['quiet'] ?? false)) $when = nt_quiet_adjust($now);
                $ins->execute([$messageId, $uid, $t['channel'], $t['address'], $strategy === 'fallback' ? $rank : 0, !empty($opts['ignore_preferences']) ? 1 : 0, 'queued', $when, nt_fmt($now)]);
                $res['queued']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('nt_notify: ' . $e->getMessage());
        return ['error' => 'Impossible d\'enregistrer le message.'] + $res;
    }
    $res['message_id'] = $messageId;
    if (($opts['immediate'] ?? true) && $res['queued'] > 0) {
        $res['processed'] = nt_process_queue((int) ($opts['immediate_limit'] ?? 40), (float) ($opts['immediate_budget'] ?? 20), $messageId);
    }
    return $res;
}

/** Message d'essai sur un canal d'un utilisateur (ignore les préférences de rubrique, pas l'état du canal). */
function nt_send_test(int $userId, string $channel): array
{
    $user = nt_user_get($userId);
    if (!$user) return ['error' => 'Utilisateur introuvable.'];
    $site = nt_site_name();
    return nt_notify('test', [$userId], 'Message de test', 'Bonjour {prenom}, ceci est un message de test envoyé par ' . $site . '. Si vous le lisez, ce canal fonctionne !', [
        'channels' => [$channel], 'ignore_preferences' => true, 'created_by' => 'test',
    ]);
}

/* ====================================================================== */
/* File d'envoi                                                           */
/* ====================================================================== */

/**
 * Traite les envois dus. Sûr en parallèle (cron + bouton admin) : chaque envoi est
 * « réservé » par un UPDATE atomique, avec un bail qui expire si le processus meurt.
 * @return array{sent:int, failed:int, retry:int, skipped:int, deferred:int}
 */
function nt_process_queue(int $limit = 50, float $budget = 20.0, ?int $messageId = null): array
{
    nt_boot();
    $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'deferred' => 0];
    $pdo = db();
    $now = nt_now();
    $pdo->prepare("UPDATE nt_deliveries SET status = 'queued' WHERE status = 'sending' AND next_attempt_at <= ?")->execute([$now]);

    $limit = max(1, min(500, $limit));
    $sql = "SELECT d.*, m.topic, m.subject, m.body, m.short_body FROM nt_deliveries d JOIN nt_messages m ON m.id = d.message_id
            WHERE d.status = 'queued' AND d.next_attempt_at <= ?" . ($messageId !== null ? ' AND d.message_id = ' . (int) $messageId : '') . " ORDER BY d.id LIMIT $limit";
    $st = $pdo->prepare($sql);
    $st->execute([$now]);
    $rows = $st->fetchAll();

    $t0 = microtime(true);
    $claim = $pdo->prepare("UPDATE nt_deliveries SET status = 'sending', next_attempt_at = ? WHERE id = ? AND status = 'queued'");
    $setSkip = $pdo->prepare("UPDATE nt_deliveries SET status = 'skipped', error = ? WHERE id = ?");
    $siblings = $pdo->prepare('SELECT status FROM nt_deliveries WHERE message_id = ? AND user_id = ? AND rank < ?');

    foreach ($rows as $d) {
        if (microtime(true) - $t0 > $budget) break;

        // Stratégie « fallback » : on n'envoie le rang n que si tous les rangs précédents ont échoué.
        if ((int) $d['rank'] > 0) {
            $siblings->execute([$d['message_id'], $d['user_id'], $d['rank']]);
            $sib = $siblings->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('sent', $sib, true)) {
                $setSkip->execute(['Déjà reçu par un canal prioritaire', $d['id']]);
                $stats['skipped']++;
                continue;
            }
            if (array_intersect($sib, ['queued', 'sending'])) {
                $stats['deferred']++;
                continue;
            }
        }

        $claim->execute([$now + NT_LEASE_SECONDS, $d['id']]);
        if ($claim->rowCount() !== 1) continue; // un autre processus l'a pris

        $user = nt_user_get((int) $d['user_id']);
        $topic = nt_topic_row($d['topic']);
        $chRow = $user['channels'][$d['channel']] ?? null;
        // Revérification au moment d'envoyer : une désinscription survenue entre-temps l'emporte.
        $why = null;
        if (!$topic) $why = 'Rubrique supprimée'; // sinon une rubrique marketing supprimée perdrait son opt-in
        elseif (!$user || !$user['is_active']) $why = 'Utilisateur désactivé ou supprimé';
        elseif (!$chRow) $why = 'Canal supprimé';
        elseif (!$chRow['enabled']) $why = 'Canal désactivé entre-temps';
        elseif (!(int) $d['forced'] && !nt_pref_effective($user, $topic, $d['channel'])) $why = 'Désabonné entre-temps';
        if ($why !== null) {
            $setSkip->execute([$why, $d['id']]);
            $stats['skipped']++;
            continue;
        }

        $r = nt_deliver($d, $user, $topic, $chRow['address']);
        $stats[nt_record_result($d, $r, $now)]++;
    }
    return $stats;
}

/** Enregistre le résultat d'un envoi ; retourne la clé de statistique (sent|failed|retry). */
function nt_record_result(array $d, array $r, int $now): string
{
    $pdo = db();
    $attempts = (int) $d['attempts'] + 1;
    if ($r['ok']) {
        $pdo->prepare("UPDATE nt_deliveries SET status = 'sent', attempts = ?, provider_id = ?, error = '', sent_at = ? WHERE id = ?")
            ->execute([$attempts, mb_substr((string) $r['id'], 0, 120), nt_fmt($now), $d['id']]);
        return 'sent';
    }
    $error = mb_substr((string) $r['error'], 0, 300);
    if (!empty($r['disable'])) nt_user_disable_channel((int) $d['user_id'], $d['channel'], $error);
    if (!empty($r['permanent']) || $attempts >= NT_MAX_ATTEMPTS) {
        $pdo->prepare("UPDATE nt_deliveries SET status = 'failed', attempts = ?, error = ? WHERE id = ?")
            ->execute([$attempts, empty($r['permanent']) ? $error . ' (abandon après ' . $attempts . ' tentatives)' : $error, $d['id']]);
        return 'failed';
    }
    $delay = NT_BACKOFF[min($attempts, count(NT_BACKOFF)) - 1];
    if (!empty($r['retry_after'])) $delay = max($delay, min(3600, (int) $r['retry_after']));
    $next = $now + $delay;
    $topic = nt_topic_row($d['topic']);
    if ($topic && $topic['is_marketing'] && (nt_channels()[$d['channel']]['quiet'] ?? false)) $next = nt_quiet_adjust($next);
    $pdo->prepare("UPDATE nt_deliveries SET status = 'queued', attempts = ?, error = ?, next_attempt_at = ? WHERE id = ?")
        ->execute([$attempts, $error, $next, $d['id']]);
    return 'retry';
}

/** Remet un envoi échoué en file (bouton « Réessayer »). */
function nt_retry_delivery(int $id): bool
{
    $st = db()->prepare("UPDATE nt_deliveries SET status = 'queued', attempts = 0, error = '', next_attempt_at = ? WHERE id = ? AND status = 'failed'");
    $st->execute([nt_now(), $id]);
    return $st->rowCount() === 1;
}

/** Supprime l'historique terminé de plus de $days jours (minimisation des données personnelles). */
function nt_purge(int $days = 90): int
{
    nt_boot();
    $cut = nt_fmt(nt_now() - max(1, $days) * 86400);
    $st = db()->prepare("DELETE FROM nt_deliveries WHERE status IN ('sent', 'failed', 'skipped') AND created_at < ?");
    $st->execute([$cut]);
    $n = $st->rowCount();
    db()->exec('DELETE FROM nt_messages WHERE id NOT IN (SELECT DISTINCT message_id FROM nt_deliveries)');
    return $n;
}

/** Chiffres pour le tableau de bord. */
function nt_stats(int $days = 7): array
{
    nt_boot();
    $pdo = db();
    $since = nt_fmt(nt_now() - $days * 86400);
    $out = [
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM nt_users WHERE is_active = 1')->fetchColumn(),
        'without_channel' => (int) $pdo->query('SELECT COUNT(*) FROM nt_users u WHERE u.is_active = 1 AND NOT EXISTS (SELECT 1 FROM nt_user_channels c WHERE c.user_id = u.id AND c.enabled = 1)')->fetchColumn(),
        'pending' => (int) $pdo->query("SELECT COUNT(*) FROM nt_deliveries WHERE status IN ('queued', 'sending')")->fetchColumn(),
        'sent' => 0, 'failed' => 0, 'by_channel' => [],
    ];
    $st = $pdo->prepare("SELECT channel, status, COUNT(*) n FROM nt_deliveries WHERE created_at >= ? GROUP BY channel, status");
    $st->execute([$since]);
    foreach ($st as $r) {
        if ($r['status'] === 'sent') $out['sent'] += (int) $r['n'];
        if ($r['status'] === 'failed') $out['failed'] += (int) $r['n'];
        $out['by_channel'][$r['channel']][$r['status']] = (int) $r['n'];
    }
    return $out;
}

/* ====================================================================== */
/* Rendu du message                                                       */
/* ====================================================================== */

function nt_site_name(): string
{
    $n = trim((string) nt_cfg('general.site_name'));
    if ($n !== '') return $n;
    try {
        $c = get_content();
        if (!empty($c['site_name'])) return (string) $c['site_name'];
    } catch (Throwable $e) {
        // base sans table « content » (tests) : repli ci-dessous
    }
    return 'La Brocante';
}

function nt_preferences_url(array $user): string
{
    $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    return $base . '/notifications/preferences.php?t=' . $user['token'];
}

/**
 * Applique les variables {prenom} {nom} {site} {lien_preferences} et, pour une rubrique
 * marketing, ajoute le lien de désinscription s'il n'y est pas déjà.
 * @return array{subject: string, text: string, unsubscribe_url: string}
 */
function nt_render(array $d, array $user, array $topic, string $channel): array
{
    $url = nt_preferences_url($user);
    // Valeurs insérées dans le message (donc possiblement dans l'objet) : aucun caractère de contrôle, retours à la ligne compris.
    $clean = fn($s) => trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $s));
    $vars = [
        '{prenom}' => $clean(nt_user_first_name($user)),
        '{nom}' => $clean($user['name']),
        '{site}' => nt_site_name(),
        '{lien_preferences}' => $url,
    ];
    $subject = strtr((string) $d['subject'], $vars);
    $marketing = (bool) $topic['is_marketing'];

    if ($channel === 'email') {
        $text = strtr((string) $d['body'], $vars);
        if ($subject === '') $subject = nt_site_name();
        if ($marketing && !str_contains((string) $d['body'], '{lien_preferences}')) {
            $text .= "\n\n—\nPour gérer vos notifications ou vous désinscrire : " . $url;
        }
    } else {
        $short = trim((string) ($d['short_body'] ?? ''));
        $src = $short !== '' ? $short : (trim((string) $d['subject']) !== '' ? $d['subject'] . "\n\n" . $d['body'] : $d['body']);
        $text = strtr((string) $src, $vars);
        if ($marketing && !str_contains((string) $src, '{lien_preferences}')) $text .= "\nDésinscription : " . $url;
    }
    $max = nt_channels()[$channel]['max_len'] ?? 0;
    if ($max > 0 && mb_strlen($text) > $max) $text = rtrim(mb_substr($text, 0, $max - 1)) . '…';
    return ['subject' => $subject, 'text' => $text, 'unsubscribe_url' => $marketing ? $url : ''];
}

/** Rend le message d'un envoi puis le confie au pilote du canal. */
function nt_deliver(array $d, array $user, array $topic, string $address): array
{
    $msg = nt_render($d, $user, $topic, $d['channel']);
    try {
        return nt_driver_send($d['channel'], $address, $msg);
    } catch (Throwable $e) {
        error_log('nt_deliver (' . $d['channel'] . '): ' . $e->getMessage());
        return nt_fail('Erreur interne : ' . $e->getMessage());
    }
}

/* ====================================================================== */
/* Pilotes de canaux                                                      */
/* ====================================================================== */

function nt_ok(string $id = ''): array
{
    return ['ok' => true, 'id' => $id, 'error' => '', 'permanent' => false, 'disable' => false];
}

function nt_fail(string $error, bool $permanent = false, bool $disable = false, ?int $retryAfter = null): array
{
    return ['ok' => false, 'id' => '', 'error' => $error, 'permanent' => $permanent, 'disable' => $disable, 'retry_after' => $retryAfter];
}

function nt_driver_send(string $channel, string $address, array $msg): array
{
    switch ($channel) {
        case 'email': return nt_send_email($address, $msg);
        case 'sms': return nt_send_twilio('sms', $address, $msg['text']);
        case 'whatsapp': return nt_send_twilio('whatsapp', $address, $msg['text']);
        case 'discord': return nt_send_discord($address, $msg['text']);
        case 'telegram': return nt_send_telegram($address, $msg['text']);
    }
    return nt_fail('Canal inconnu : ' . $channel, true);
}

/**
 * Appel HTTP sortant. Retourne ['status' => int (0 = réseau), 'body' => string, 'error' => string, 'retry_after' => ?int].
 * Les URL sont toutes construites par le module (hôtes figés) ; jamais d'URL saisie telle quelle,
 * hormis les webhooks Discord validés par NT_DISCORD_WEBHOOK_RE.
 */
function nt_http(string $method, string $url, array $headers = [], ?string $body = null, ?string $basicAuth = null): array
{
    if ($hook = nt_hook('http')) return $hook($method, $url, $headers, $body, $basicAuth);
    if (!function_exists('curl_init')) return ['status' => 0, 'body' => '', 'error' => 'Extension cURL absente', 'retry_after' => null];
    $retryAfter = null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => NT_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retryAfter) {
            if (stripos($line, 'retry-after:') === 0) $retryAfter = (int) ceil((float) trim(substr($line, 12)));
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($basicAuth !== null) curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = $resp === false ? curl_error($ch) : '';
    curl_close($ch);
    return ['status' => $status, 'body' => $resp === false ? '' : (string) $resp, 'error' => $err, 'retry_after' => $retryAfter];
}

/**
 * Interprétation commune d'une réponse HTTP : succès 2xx ; 429, 5xx et erreurs réseau =
 * temporaire (on réessaiera) ; le reste = définitif.
 * @param string $err message d'erreur lisible extrait de la réponse (sans secret)
 */
function nt_http_result(array $r, string $label, string $err = '', bool $disable = false): array
{
    $s = $r['status'];
    if ($s >= 200 && $s < 300) return nt_ok();
    if ($s === 0) return nt_fail($label . ' : ' . (($r['error'] ?? '') !== '' ? $r['error'] : 'pas de réponse'));
    $msg = $label . ' : HTTP ' . $s . ($err !== '' ? ' — ' . $err : '');
    if ($s === 429 || $s >= 500) return nt_fail($msg, false, false, $r['retry_after'] ?? null);
    return nt_fail($msg, true, $disable);
}

function nt_json(array $r): array
{
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : [];
}

/* ---------- Twilio : SMS et WhatsApp ---------- */

function nt_send_twilio(string $channel, string $to, string $text): array
{
    $sid = (string) nt_cfg('twilio.account_sid');
    $token = (string) nt_cfg('twilio.auth_token');
    if ($sid === '' || $token === '') return nt_fail('Twilio non configuré', true);
    $fields = ['Body' => $text];
    if ($channel === 'whatsapp') {
        $from = nt_normalize_phone((string) nt_cfg('whatsapp.from'));
        if ($from === null) return nt_fail('Numéro WhatsApp expéditeur non configuré', true);
        $fields += ['To' => 'whatsapp:' . $to, 'From' => 'whatsapp:' . $from];
    } else {
        $fields['To'] = $to;
        if (nt_cfg('sms.messaging_service_sid') !== '') $fields['MessagingServiceSid'] = nt_cfg('sms.messaging_service_sid');
        elseif (nt_cfg('sms.from') !== '') $fields['From'] = nt_cfg('sms.from');
        else return nt_fail('Expéditeur SMS non configuré', true);
    }
    $r = nt_http('POST', 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($fields), $sid . ':' . $token);
    $j = nt_json($r);
    $res = nt_http_result($r, 'Twilio', (string) ($j['message'] ?? ''));
    if ($res['ok']) return nt_ok((string) ($j['sid'] ?? ''));
    $code = (int) ($j['code'] ?? 0);
    if ($code === 21610) return nt_fail('Le destinataire s\'est désabonné (STOP)', true, true);
    if ($code === 63016) return nt_fail('WhatsApp : hors fenêtre de 24 h, un modèle de message approuvé est requis', true);
    return $res;
}

/* ---------- Discord : webhook ou message privé via un bot ---------- */

function nt_send_discord(string $address, string $text): array
{
    $payload = json_encode(['content' => $text, 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_UNICODE);
    $json = ['Content-Type: application/json'];
    if (str_starts_with($address, 'https://')) {
        if (!preg_match(NT_DISCORD_WEBHOOK_RE, $address)) return nt_fail('URL de webhook Discord invalide', true);
        $r = nt_http('POST', $address . '?wait=true', $json, $payload);
        $res = nt_http_result($r, 'Discord', (string) (nt_json($r)['message'] ?? ''));
        return $res['ok'] ? nt_ok((string) (nt_json($r)['id'] ?? '')) : $res;
    }
    $token = (string) nt_cfg('discord.bot_token');
    if ($token === '') return nt_fail('Jeton du bot Discord non configuré', true);
    $headers = array_merge($json, ['Authorization: Bot ' . $token, 'User-Agent: DiscordBot (https://brocante.arrimage.com, 1.0)']);
    $r = nt_http('POST', 'https://discord.com/api/v10/users/@me/channels', $headers, json_encode(['recipient_id' => $address]));
    $res = nt_http_result($r, 'Discord (ouverture du MP)', (string) (nt_json($r)['message'] ?? ''));
    if (!$res['ok']) return $res;
    $channelId = (string) (nt_json($r)['id'] ?? '');
    if (!preg_match('/^\d{5,25}$/', $channelId)) return nt_fail('Discord : réponse inattendue');
    $r = nt_http('POST', 'https://discord.com/api/v10/channels/' . $channelId . '/messages', $headers, $payload);
    $j = nt_json($r);
    $res = nt_http_result($r, 'Discord', (string) ($j['message'] ?? ''));
    if ($res['ok']) return nt_ok((string) ($j['id'] ?? ''));
    if ((int) ($j['code'] ?? 0) === 50007) return nt_fail('Discord : cet utilisateur n\'accepte pas les messages privés', true);
    return $res;
}

/* ---------- Telegram ---------- */

function nt_send_telegram(string $chatId, string $text): array
{
    $token = (string) nt_cfg('telegram.bot_token');
    if (!preg_match('/^\d{3,12}:[A-Za-z0-9_-]{20,}$/', $token)) return nt_fail('Jeton du bot Telegram absent ou invalide', true);
    // Texte brut (pas de parse_mode) : aucun risque de balisage cassé par un prénom ou un lien.
    $r = nt_http('POST', 'https://api.telegram.org/bot' . $token . '/sendMessage', ['Content-Type: application/json'],
        json_encode(['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true], JSON_UNESCAPED_UNICODE));
    $j = nt_json($r);
    $desc = (string) ($j['description'] ?? '');
    $res = nt_http_result($r, 'Telegram', $desc);
    if ($res['ok']) return nt_ok((string) ($j['result']['message_id'] ?? ''));
    if ($r['status'] === 403) return nt_fail('Telegram : le bot est bloqué par l\'utilisateur', true, true);
    if ($r['status'] === 429 && isset($j['parameters']['retry_after'])) $res['retry_after'] = (int) $j['parameters']['retry_after'];
    return $res;
}

/* ---------- E-mail : mail() ou SMTP ---------- */

/** Supprime retours à la ligne et caractères de contrôle (anti-injection d'en-têtes). */
function nt_header_clean(string $s): string
{
    return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
}

/**
 * Construit le message MIME (texte + HTML). Les en-têtes To/Subject sont fournis à part
 * car mail() les ajoute lui-même.
 * @return array{headers: string[], subject: string, body: string, message_id: string}
 */
function nt_email_build(array $msg): array
{
    $from = nt_header_clean((string) nt_cfg('email.from_email'));
    $fromName = nt_header_clean((string) nt_cfg('email.from_name'));
    $replyTo = nt_header_clean((string) nt_cfg('email.reply_to'));
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $boundary = 'nt_' . bin2hex(random_bytes(12));

    $text = str_replace(["\r\n", "\r"], "\n", $msg['text']);
    $htmlBody = nl2br(preg_replace('#(https?://[^\s<]+)#', '<a href="$1">$1</a>', h($text)), false);
    $html = '<!DOCTYPE html><html lang="fr"><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#2e2418">' . $htmlBody . '</body></html>';

    if ($fromName === '') {
        $fromHeader = $from;
    } elseif (preg_match('/^[\x20-\x7E]+$/', $fromName)) {
        $fromHeader = '"' . addcslashes($fromName, '"\\') . '" <' . $from . '>'; // ASCII : guillemets (virgules, points…)
    } else {
        $fromHeader = mb_encode_mimeheader($fromName, 'UTF-8', 'B', "\r\n") . ' <' . $from . '>';
    }
    $headers = [
        'From: ' . $fromHeader,
        'Date: ' . date('r', nt_now()),
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Auto-Response-Suppress: OOF, AutoReply',
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;
    if (!empty($msg['unsubscribe_url'])) {
        $headers[] = 'List-Unsubscribe: <' . nt_header_clean($msg['unsubscribe_url']) . '>';
        $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
    }
    $part = fn($type, $content) => "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(str_replace("\n", "\r\n", $content)) . "\r\n";
    $body = $part('text/plain', $text) . $part('text/html', $html) . "--$boundary--\r\n";

    return [
        'headers' => $headers,
        'subject' => mb_encode_mimeheader(nt_header_clean($msg['subject']), 'UTF-8', 'B', "\r\n"),
        'body' => $body,
        'message_id' => $messageId,
    ];
}

function nt_send_email(string $to, array $msg): array
{
    $from = (string) nt_cfg('email.from_email');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return nt_fail('Adresse d\'expédition e-mail non configurée', true);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\x00-\x1F]/', $to)) return nt_fail('Adresse e-mail invalide', true, true);
    $m = nt_email_build($msg);

    if (nt_cfg('email.transport') === 'smtp') {
        $raw = implode("\r\n", array_merge($m['headers'], ['To: ' . $to, 'Subject: ' . $m['subject']])) . "\r\n\r\n" . $m['body'];
        $r = nt_smtp_send($from, $to, $raw);
        return $r['ok'] ? nt_ok($m['message_id']) : $r;
    }
    $mail = nt_hook('mail') ?? 'mail';
    $ok = @$mail($to, $m['subject'], $m['body'], implode("\r\n", $m['headers']), '-f' . $from);
    return $ok ? nt_ok($m['message_id']) : nt_fail('mail() a refusé le message (serveur de messagerie local)');
}

/**
 * Client SMTP minimal : SSL implicite (465) ou STARTTLS (587), AUTH PLAIN/LOGIN.
 * 4xx et problèmes réseau = temporaire ; 5xx = définitif.
 */
function nt_smtp_send(string $from, string $to, string $raw): array
{
    $host = (string) nt_cfg('email.smtp_host');
    $port = (int) nt_cfg('email.smtp_port');
    $secure = (string) nt_cfg('email.smtp_secure');
    $user = (string) nt_cfg('email.smtp_user');
    $pass = (string) nt_cfg('email.smtp_pass');
    $fp = null;
    try {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException('connexion impossible (' . $errstr . ')', 0);
        stream_set_timeout($fp, 15);

        $read = function () use ($fp): array {
            $text = '';
            $code = 0;
            while (($line = fgets($fp, 2048)) !== false) {
                $text .= $line;
                if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                    $code = (int) $m[1];
                    if ($m[2] === ' ') break;
                } else {
                    break;
                }
            }
            if ($code === 0) throw new RuntimeException('pas de réponse du serveur SMTP', 0);
            return [$code, trim($text)];
        };
        $cmd = function (string $line, array $expect) use ($fp, $read): array {
            if ($line !== '') fwrite($fp, $line . "\r\n");
            [$code, $text] = $read();
            if (!in_array($code, $expect, true)) throw new RuntimeException(substr($text, 0, 200), $code);
            return [$code, $text];
        };

        $cmd('', [220]);
        $helo = parse_url(defined('SITE_URL') ? (string) SITE_URL : '', PHP_URL_HOST) ?: 'localhost';
        [, $caps] = $cmd('EHLO ' . $helo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('négociation TLS échouée', 0);
            [, $caps] = $cmd('EHLO ' . $helo, [250]);
        }
        if ($user !== '') {
            if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') === false && stripos($caps, 'LOGIN') !== false) {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($user), [334]);
                $cmd(base64_encode($pass), [235]);
            } else {
                $cmd('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235]);
            }
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $data = preg_replace("/\r\n|\r|\n/", "\r\n", $raw);
        $data = preg_replace('/^\./m', '..', $data); // dot-stuffing
        fwrite($fp, rtrim($data, "\r\n") . "\r\n.\r\n");
        [, $ack] = $read();
        if (!preg_match('/^250/', $ack)) throw new RuntimeException(substr($ack, 0, 200), 0);
        @fwrite($fp, "QUIT\r\n");
        return nt_ok();
    } catch (RuntimeException $e) {
        $code = $e->getCode();
        // 535 (identifiants refusés), 5xx = définitif ; 4xx et réseau = on réessaiera.
        return nt_fail('SMTP : ' . $e->getMessage() . ($code ? ' (' . $code . ')' : ''), $code >= 500);
    } finally {
        if (is_resource($fp)) fclose($fp);
    }
}

/* ====================================================================== */
/* Import depuis les données existantes                                   */
/* ====================================================================== */

function nt_table_exists(string $table): bool
{
    $st = db()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $st->execute([$table]);
    return (bool) $st->fetchColumn();
}

/**
 * Crée des utilisateurs à partir des bénévoles de la buvette (e-mail + mobile pour le SMS).
 * Aucun consentement marketing n'est créé : seuls les messages de suivi leur parviennent.
 * @return array{created: int, existing: int}
 */
function nt_import_volunteers(): array
{
    nt_boot();
    $res = ['created' => 0, 'existing' => 0];
    if (!nt_table_exists('bv_volunteers')) return $res;
    foreach (db()->query("SELECT id, name, phone, email FROM bv_volunteers WHERE is_active = 1") as $v) {
        $email = nt_normalize_address('email', (string) $v['email']);
        $phone = nt_normalize_address('sms', (string) $v['phone']);
        if (!$email && !$phone) continue;
        if (($email && nt_user_find_by_address('email', $email)) || ($phone && nt_user_find_by_address('sms', $phone))) {
            $res['existing']++;
            continue;
        }
        $id = nt_user_create((string) $v['name'], '', 'benevole', (string) $v['id']);
        if ($email) nt_user_set_channel($id, 'email', $email, true, 0);
        if ($phone) nt_user_set_channel($id, 'sms', $phone, true, 1);
        $res['created']++;
    }
    return $res;
}

/**
 * Crée des utilisateurs (e-mail seul) à partir des clients ayant payé une commande.
 * Pas d'abonnement marketing : une commande ne vaut pas consentement à la newsletter.
 * @return array{created: int, existing: int}
 */
function nt_import_customers(): array
{
    nt_boot();
    $res = ['created' => 0, 'existing' => 0];
    if (!nt_table_exists('orders')) return $res;
    foreach (db()->query("SELECT DISTINCT lower(trim(email)) AS email FROM orders WHERE status = 'paid' AND email IS NOT NULL AND trim(email) <> ''") as $o) {
        $email = nt_normalize_address('email', (string) $o['email']);
        if (!$email) continue;
        if (nt_user_find_by_address('email', $email)) {
            $res['existing']++;
            continue;
        }
        $id = nt_user_create(ucfirst(strstr($email, '@', true)), '', 'commande', $email);
        nt_user_set_channel($id, 'email', $email, true, 0);
        $res['created']++;
    }
    return $res;
}

/**
 * Crée des utilisateurs (e-mail seul) à partir des inscrits à la lettre d'information du site (comptes « newsletter »).
 * Pas d'abonnement marketing : l'inscription à la lettre ne vaut pas consentement aux autres messages commerciaux.
 * @return array{created: int, existing: int}
 */
function nt_import_newsletter(): array
{
    nt_boot();
    $res = ['created' => 0, 'existing' => 0];
    if (!nt_table_exists('accounts')) return $res;
    foreach (db()->query("SELECT DISTINCT lower(trim(email)) AS email, name FROM accounts WHERE source = 'newsletter' AND is_active = 1 AND role IN ('prospect', 'client') AND trim(email) <> ''") as $a) {
        $email = nt_normalize_address('email', (string) $a['email']);
        if (!$email) continue;
        if (nt_user_find_by_address('email', $email)) {
            $res['existing']++;
            continue;
        }
        $name = trim((string) $a['name']) !== '' ? (string) $a['name'] : ucfirst((string) strstr($email, '@', true));
        $id = nt_user_create($name, '', 'newsletter', $email);
        nt_user_set_channel($id, 'email', $email, true, 0);
        $res['created']++;
    }
    return $res;
}

/* ====================================================================== */
/* Intégration : commandes de la boutique                                 */
/* ====================================================================== */

/** Nom du client : premier élément de l'adresse de livraison (« Nom, rue, … »), sinon début de l'e-mail. */
function nt_order_customer_name(array $order, string $email): string
{
    $first = trim((string) strtok((string) ($order['shipping_address'] ?? ''), ','));
    if ($first !== '' && !preg_match('/\d/', $first)) return mb_substr($first, 0, 120);
    return ucfirst((string) strstr($email, '@', true));
}

/**
 * Prévient le client que sa commande est expédiée, sur ses canaux (rubrique « commande »).
 * Appelée au passage du statut à « expédiée » ; ne lève jamais d'exception pour ne pas
 * gêner la mise à jour de la commande. Une commande n'est notifiée qu'une fois.
 *
 * @return array{status: string, message: string} status : sent | queued | skipped | duplicate | error
 */
function nt_notify_order_shipped(array $order): array
{
    try {
        nt_boot();
        $id = (int) ($order['id'] ?? 0);
        if (($order['status'] ?? '') !== 'paid') return ['status' => 'skipped', 'message' => 'commande non payée'];
        $email = nt_normalize_address('email', (string) ($order['email'] ?? ''));
        if ($email === null) return ['status' => 'skipped', 'message' => 'pas d\'adresse e-mail pour ce client'];
        $marker = 'order_shipped_notified:' . $id;
        if (nt_meta_get($marker) !== '') return ['status' => 'duplicate', 'message' => 'client déjà prévenu pour cette commande'];

        $uid = nt_user_find_by_address('email', $email);
        if ($uid === null) {
            $uid = nt_user_create(nt_order_customer_name($order, $email), '', 'commande', $email);
            nt_user_set_channel($uid, 'email', $email, true, 0);
        }

        $items = [];
        foreach (json_decode((string) ($order['items'] ?? ''), true) ?: [] as $it) {
            if (!empty($it['name'])) $items[] = (int) ($it['qty'] ?? 1) . ' × ' . $it['name'];
        }
        $when = !empty($order['created_at']) ? date('d/m/Y', strtotime((string) $order['created_at'])) : '';
        $ref = 'votre commande' . ($when !== '' ? ' du ' . $when : '');
        $tracking = trim((string) ($order['tracking_number'] ?? ''));
        // Sans adresse de livraison (retrait à l'atelier), « expédiée » serait trompeur.
        $shipped = trim((string) ($order['shipping_address'] ?? '')) !== '';
        $verb = $shipped ? 'a été expédiée' : 'est prête';

        $body = "Bonjour {prenom},\n\nBonne nouvelle : $ref $verb.\n";
        if ($items) $body .= "\nContenu : " . implode(', ', $items) . "\n";
        if ($tracking !== '') $body .= "\nNuméro de suivi : $tracking\n";
        $body .= "\nMerci de votre confiance,\n{site}";
        $short = '{site} : ' . $ref . ' ' . $verb . '.' . ($tracking !== '' ? ' Suivi : ' . $tracking : '');

        $r = nt_notify('commande', [$uid], ucfirst($ref) . ' ' . $verb, $body, [
            'short_body' => $short, 'created_by' => 'commande', 'immediate_budget' => 10,
        ]);
        if ($r['error']) return ['status' => 'error', 'message' => $r['error']];
        if ($r['queued'] === 0) {
            $why = $r['skipped'][0][3] ?? 'aucun canal actif';
            return ['status' => 'skipped', 'message' => 'client non prévenu (' . mb_strtolower((string) $why) . ')'];
        }
        nt_meta_set($marker, nt_fmt(nt_now()));

        $st = db()->prepare("SELECT channel FROM nt_deliveries WHERE message_id = ? AND status = 'sent'");
        $st->execute([$r['message_id']]);
        $sent = array_map('nt_channel_label', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($sent) return ['status' => 'sent', 'message' => 'client prévenu (' . implode(', ', $sent) . ')'];
        return ['status' => 'queued', 'message' => 'notification au client en file d\'attente (voir Notifications → Historique)'];
    } catch (Throwable $e) {
        error_log('nt_notify_order_shipped: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'notification non envoyée (erreur interne)'];
    }
}
