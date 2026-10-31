<?php

// Retours des testeurs : widget flottant dans l'administration des boutiques
// testeuses, boîte de triage dans le portail, relances et export.
//
// Données (jamais servies en HTTP, data/ est refusé par .htaccess) :
//   data/saas/feedback.json            les retours (message, contexte, suivi)
//   data/saas/feedback-settings.json   programme testeurs, widget, rappels
//   data/saas/feedback-reminders.json  date de la dernière relance par boutique
//   data/saas/feedback-media/          captures jointes (servies par portail/retour-media.php)
//
// Le fichier ne dépend d'aucune configuration de commerce : il est chargé par
// l'administration d'une boutique, par le portail et par les tests en CLI.

const FEEDBACK_TYPES = ['bug', 'idea', 'improvement', 'other'];
const FEEDBACK_TYPE_LABELS = ['bug' => 'Bug', 'idea' => 'Idée', 'improvement' => 'Amélioration', 'other' => 'Autre'];
const FEEDBACK_SEVERITIES = ['blocker', 'major', 'minor'];
const FEEDBACK_SEVERITY_LABELS = ['blocker' => 'Bloquant', 'major' => 'Gênant', 'minor' => 'Mineur'];
const FEEDBACK_STATUSES = ['new', 'qualified', 'in_progress', 'waiting', 'done', 'declined'];
const FEEDBACK_STATUS_LABELS = [
    'new' => 'Nouveau', 'qualified' => 'Qualifié', 'in_progress' => 'En cours',
    'waiting' => 'En attente testeur', 'done' => 'Traité', 'declined' => 'Écarté',
];
const FEEDBACK_OPEN_STATUSES = ['new', 'qualified', 'in_progress', 'waiting'];
const FEEDBACK_PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const FEEDBACK_PRIORITY_LABELS = ['low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute', 'urgent' => 'Urgente'];
const FEEDBACK_MAX_MESSAGE = 4000;
const FEEDBACK_MAX_ATTACHMENT = 3145728; // 3 Mo
const FEEDBACK_MAX_RECORDS = 5000;
const FEEDBACK_HOURLY_LIMIT = 10;

function feedback_data_dir(): string
{
    return defined('FEEDBACK_DATA_DIR')
        ? rtrim((string) FEEDBACK_DATA_DIR, '/')
        : dirname(__DIR__) . '/data/saas';
}

function feedback_file(): string
{
    return feedback_data_dir() . '/feedback.json';
}

function feedback_settings_file(): string
{
    return feedback_data_dir() . '/feedback-settings.json';
}

function feedback_reminders_file(): string
{
    return feedback_data_dir() . '/feedback-reminders.json';
}

function feedback_media_dir(): string
{
    return feedback_data_dir() . '/feedback-media';
}

// ── Réglages du programme testeurs ─────────────────────────────────────────

function feedback_settings_defaults(): array
{
    return [
        'widget' => true,
        'open_to_all' => false,
        'include_beta_testers' => true,
        'testers' => ['shops' => [], 'galleries' => []],
        'notify_email' => '',
        'reply_days' => 15,
        'since' => 0,
    ];
}

/** Liste d'identifiants normalisés (tableau, ou texte séparé par virgules / retours à la ligne). */
function feedback_slug_list(mixed $value): array
{
    if (is_string($value)) $value = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ((array) $value as $v) {
        $v = strtolower(trim((string) $v));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $v)) $out[$v] = $v;
    }
    return array_values($out);
}

function feedback_settings_normalize(array $in): array
{
    $d = feedback_settings_defaults();
    $testers = is_array($in['testers'] ?? null) ? $in['testers'] : [];
    $email = mb_strtolower(trim((string) ($in['notify_email'] ?? '')));
    return [
        'widget' => !array_key_exists('widget', $in) || !empty($in['widget']),
        'open_to_all' => !empty($in['open_to_all']),
        'include_beta_testers' => !array_key_exists('include_beta_testers', $in) || !empty($in['include_beta_testers']),
        'testers' => [
            'shops' => feedback_slug_list($testers['shops'] ?? []),
            'galleries' => feedback_slug_list($testers['galleries'] ?? []),
        ],
        'notify_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        'reply_days' => max(1, min(90, (int) ($in['reply_days'] ?? $d['reply_days']))),
        'since' => max(0, (int) ($in['since'] ?? 0)),
    ];
}

function feedback_settings_load(): array
{
    $file = feedback_settings_file();
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return feedback_settings_normalize(is_array($data) ? $data : []);
}

function feedback_settings_save(array $in): array
{
    if (empty($in['since'])) $in['since'] = feedback_settings_load()['since'];
    $settings = feedback_settings_normalize($in);
    if ($settings['since'] <= 0) $settings['since'] = time();
    $dir = dirname(feedback_settings_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException("Impossible de créer data/saas : droits d'écriture manquants.");
    }
    if (!feedback_json_write(feedback_settings_file(), $settings)) {
        throw new RuntimeException("Les réglages n'ont pas pu être enregistrés.");
    }
    return $settings;
}

function feedback_gallery_list(): array
{
    if (!function_exists('gallery_list')) {
        $file = __DIR__ . '/galleries.php';
        if (is_file($file)) require_once $file;
    }
    return function_exists('gallery_list') ? gallery_list() : [];
}

function feedback_tenant_list(): array
{
    if (!function_exists('tenant_list')) {
        $file = __DIR__ . '/tenant.php';
        if (is_file($file)) require_once $file;
    }
    return function_exists('tenant_list') ? tenant_list() : [];
}

/** Boutiques testeuses : liste explicite + membres des galeries testeuses + testeurs des modules « En test ». */
function feedback_tester_shops(?array $settings = null): array
{
    static $cache = [];
    $settings ??= feedback_settings_load();
    $cacheKey = md5(json_encode($settings));
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $out = (array) ($settings['testers']['shops'] ?? []);
    $galleries = feedback_gallery_list();
    foreach ((array) ($settings['testers']['galleries'] ?? []) as $slug) {
        foreach ($galleries[$slug]['members'] ?? [] as $member) {
            if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') !== '') $out[] = (string) $member['slug'];
        }
    }
    if (!empty($settings['include_beta_testers'])) {
        try {
            if (!function_exists('addon_defaults')) {
                $file = __DIR__ . '/addons.php';
                if (is_file($file)) require_once $file;
            }
            if (function_exists('addon_defaults')) {
                foreach (array_keys(addon_defaults()) as $module) {
                    $info = addon_info((string) $module);
                    if ($info && ($info['stage'] ?? '') === 'beta') {
                        foreach (addon_beta_shops($info) as $shop) $out[] = (string) $shop;
                    }
                }
            }
        } catch (Throwable $e) {
            // Réglages de modules illisibles : le programme explicite reste utilisé.
        }
    }
    $out = array_values(array_unique(array_filter($out)));
    $cache[$cacheKey] = $out;
    return $out;
}

function feedback_is_tester(string $shop, ?array $settings = null): bool
{
    if ($shop === '') return false;
    $settings ??= feedback_settings_load();
    return in_array($shop, feedback_tester_shops($settings), true);
}

function feedback_widget_enabled(string $shop): bool
{
    $settings = feedback_settings_load();
    return !empty($settings['widget']) && (!empty($settings['open_to_all']) || feedback_is_tester($shop, $settings));
}

/** Nombre de boutiques couvertes par le programme (liste explicite + galeries, ou toutes). */
function feedback_program_shop_count(?array $settings = null): int
{
    $settings ??= feedback_settings_load();
    if (!empty($settings['open_to_all'])) return count(feedback_tenant_list());
    return count(feedback_tester_shops($settings));
}

function feedback_shop_name(string $shop): string
{
    if ($shop === '') return '';
    try {
        $cfg = function_exists('tenant_load') ? tenant_load($shop) : [];
        return trim((string) ($cfg['name'] ?? '')) ?: $shop;
    } catch (Throwable $e) {
        return $shop;
    }
}

/** Galerie locale dont la boutique est membre (la première par ordre alphabétique), sinon ''. */
function feedback_shop_gallery(string $shop): string
{
    foreach (feedback_gallery_list() as $slug => $gallery) {
        foreach ($gallery['members'] ?? [] as $member) {
            if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === $shop) return (string) $slug;
        }
    }
    return '';
}

/** Adresse e-mail de réponse : compte connecté, puis identifiant principal du commerce s'il est une adresse. */
function feedback_shop_email(string $shop, ?int $accountId = null): string
{
    if ($accountId !== null && function_exists('account_find')) {
        try {
            $account = account_find($accountId);
            if ($account && filter_var((string) ($account['email'] ?? ''), FILTER_VALIDATE_EMAIL)) return (string) $account['email'];
        } catch (Throwable $e) {
            // Compte illisible : on tente l'identifiant principal.
        }
    }
    try {
        $cfg = function_exists('tenant_load') ? tenant_load($shop) : [];
        $id = trim((string) ($cfg['admin_user'] ?? ''));
        if (filter_var($id, FILTER_VALIDATE_EMAIL)) return $id;
    } catch (Throwable $e) {
        // Commerce inconnu : pas d'adresse.
    }
    return '';
}

function feedback_platform_name(): string
{
    if (function_exists('saas_config')) {
        try {
            $name = trim((string) (saas_config()['name'] ?? ''));
            if ($name !== '') return $name;
        } catch (Throwable $e) {
            // Réglages indisponibles : nom générique.
        }
    }
    return 'brocs';
}

function feedback_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost');
}

function feedback_shop_admin_url(string $shop): string
{
    $base = '';
    try {
        $cfg = function_exists('tenant_load') ? tenant_load($shop) : [];
        $url = rtrim((string) ($cfg['site_url'] ?? ''), '/');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && !in_array($host, ['localhost', '127.0.0.1'], true)
            && !str_ends_with($host, '.test') && !str_ends_with($host, '.example.com')) {
            $base = $url;
        }
    } catch (Throwable $e) {
        $base = '';
    }
    if ($base === '') $base = feedback_origin() . '/' . rawurlencode($shop);
    return $base . '/admin/dashboard.php';
}

// ── Stockage ───────────────────────────────────────────────────────────────

function feedback_store_read(string $file): array
{
    if (!is_file($file)) return [];
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
}

function feedback_store_write(string $file, array $data): bool
{
    return feedback_json_write($file, array_values($data));
}

/** Écrit un tableau associatif tel quel (réglages) ou une liste (retours) de façon atomique. */
function feedback_json_write(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0640);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Modifie le fichier des retours sous verrou exclusif ; la fonction reçoit la liste par référence. */
function feedback_mutate(callable $mutator): mixed
{
    $file = feedback_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException("Enregistrement impossible : le dossier data/saas n'est pas accessible.");
    }
    $lock = @fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Enregistrement impossible : le fichier est verrouillé.');
    }
    try {
        $data = feedback_store_read($file);
        $result = $mutator($data);
        if (!feedback_store_write($file, $data)) throw new RuntimeException("L'enregistrement a échoué (droits d'écriture).");
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function feedback_record_normalize(array $f): array
{
    $out = array_merge([
        'id' => '', 'at' => '', 'shop' => '', 'shop_name' => '', 'gallery' => '', 'module' => '',
        'account' => '', 'account_id' => null, 'email' => '', 'type' => 'other', 'severity' => '',
        'message' => '', 'page' => '', 'page_title' => '', 'context' => [],
        'status' => 'new', 'priority' => 'normal', 'attachment' => '',
        'notes' => [], 'replies' => [], 'status_log' => [],
        'first_reply_at' => '', 'resolved_at' => '', 'updated_at' => '', 'tester_seen_at' => '',
    ], $f);
    foreach (['notes', 'replies', 'status_log', 'context'] as $key) {
        if (!is_array($out[$key])) $out[$key] = [];
    }
    if (!in_array($out['type'], FEEDBACK_TYPES, true)) $out['type'] = 'other';
    if (!in_array($out['status'], FEEDBACK_STATUSES, true)) $out['status'] = 'new';
    if (!in_array($out['priority'], FEEDBACK_PRIORITIES, true)) $out['priority'] = 'normal';
    if (!in_array($out['severity'], FEEDBACK_SEVERITIES, true)) $out['severity'] = '';
    return $out;
}

function feedback_all(): array
{
    $out = [];
    foreach (feedback_store_read(feedback_file()) as $row) {
        $f = feedback_record_normalize($row);
        if ($f['id'] !== '') $out[] = $f;
    }
    usort($out, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));
    return $out;
}

function feedback_find(string $id): ?array
{
    if (!preg_match('/^fb[0-9]{6}-[a-f0-9]{8}$/', $id)) return null;
    foreach (feedback_all() as $f) {
        if ($f['id'] === $id) return $f;
    }
    return null;
}

/** Filtre une liste déjà chargée. filtres : statut (all|new|open|done|declined), type, shop, q, jours, module. */
function feedback_filter_list(array $list, array $filters): array
{
    $status = (string) ($filters['statut'] ?? 'all');
    $type = (string) ($filters['type'] ?? '');
    $shop = (string) ($filters['shop'] ?? '');
    $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
    $days = (int) ($filters['jours'] ?? 0);
    $module = (string) ($filters['module'] ?? '');
    $cutoff = $days > 0 ? time() - $days * 86400 : 0;
    $out = [];
    foreach ($list as $f) {
        $s = (string) $f['status'];
        if ($status === 'open' && !in_array($s, FEEDBACK_OPEN_STATUSES, true)) continue;
        if ($status === 'done' && $s !== 'done') continue;
        if ($status === 'declined' && $s !== 'declined') continue;
        if (!in_array($status, ['all', 'open', 'done', 'declined'], true) && $s !== $status) continue;
        if ($type !== '' && $f['type'] !== $type) continue;
        if ($shop !== '' && $f['shop'] !== $shop) continue;
        if ($module !== '' && $f['module'] !== $module) continue;
        if ($cutoff > 0 && (int) strtotime((string) $f['at']) < $cutoff) continue;
        if ($q !== '') {
            $haystack = mb_strtolower(implode(' ', [
                (string) $f['message'], (string) $f['shop_name'], (string) $f['shop'],
                (string) $f['account'], (string) $f['email'], (string) $f['page'], (string) $f['id'],
            ]));
            if (!str_contains($haystack, $q)) continue;
        }
        $out[] = $f;
    }
    return $out;
}

function feedback_list(array $filters = []): array
{
    return feedback_filter_list(feedback_all(), $filters);
}

/** Retours d'une boutique (les plus récents d'abord), pour le widget. */
function feedback_shop_records(string $shop, int $limit = 20): array
{
    $out = [];
    foreach (feedback_all() as $f) {
        if ($f['shop'] !== $shop) continue;
        $out[] = $f;
        if (count($out) >= max(1, $limit)) break;
    }
    return $out;
}

/** Réponses non vues par la boutique (pastille du widget). */
function feedback_shop_unread(string $shop): int
{
    $n = 0;
    foreach (feedback_all() as $f) {
        if ($f['shop'] !== $shop) continue;
        foreach ($f['replies'] as $reply) {
            if ((string) ($reply['at'] ?? '') > (string) $f['tester_seen_at']) $n++;
        }
    }
    return $n;
}

function feedback_mark_shop_seen(string $shop): void
{
    if ($shop === '' || feedback_shop_unread($shop) === 0) return;
    feedback_mutate(static function (array &$data) use ($shop): void {
        $now = gmdate('c');
        foreach ($data as &$f) {
            if (($f['shop'] ?? '') !== $shop) continue;
            $f['tester_seen_at'] = $now;
        }
        unset($f);
    });
}

// ── Création et suivi ──────────────────────────────────────────────────────

function feedback_clean_text(mixed $value, int $max): string
{
    $s = is_scalar($value) ? (string) $value : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

function feedback_message_error(string $message): ?string
{
    $length = mb_strlen(trim($message));
    if ($length < 10) return 'Décrivez votre retour en quelques mots (10 caractères minimum).';
    if ($length > FEEDBACK_MAX_MESSAGE) return 'Votre message est trop long (4 000 caractères maximum).';
    return null;
}

/** Enregistre un retour envoyé depuis le widget. Lève une exception avec un message affichable. */
function feedback_add(array $in, ?int $accountId = null, string $accountName = ''): array
{
    $shop = preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', (string) ($in['shop'] ?? '')) ? (string) $in['shop'] : '';
    if ($shop === '') throw new InvalidArgumentException('Boutique inconnue.');
    if (!feedback_widget_enabled($shop)) throw new InvalidArgumentException("Le widget de retour n'est pas ouvert à cette boutique.");

    $type = (string) ($in['type'] ?? 'other');
    if (!in_array($type, FEEDBACK_TYPES, true)) $type = 'other';
    $message = trim(str_replace(["\r\n", "\r"], "\n", feedback_clean_text($in['message'] ?? '', FEEDBACK_MAX_MESSAGE + 200)));
    if (($error = feedback_message_error($message)) !== null) throw new InvalidArgumentException($error);
    $severity = $type === 'bug' && in_array((string) ($in['severity'] ?? ''), FEEDBACK_SEVERITIES, true)
        ? (string) $in['severity'] : ($type === 'bug' ? 'major' : '');

    $email = trim((string) ($in['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = feedback_shop_email($shop, $accountId);

    $page = feedback_clean_text($in['page'] ?? '', 400);
    if ($page !== '' && !str_starts_with($page, '/')) $page = '/' . ltrim(preg_replace('#^[a-z]+://[^/]+#i', '', $page), '/');
    $viewport = (string) ($in['viewport'] ?? '');
    if (!preg_match('/^\d{2,5}\s*[×x]\s*\d{2,5}$/u', $viewport)) $viewport = '';
    $module = (string) ($in['module'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $module)) $module = '';

    $id = 'fb' . gmdate('ymd') . '-' . bin2hex(random_bytes(4));
    $attachment = '';
    if (is_array($in['attachment_file'] ?? null) && (int) ($in['attachment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $attachment = feedback_media_store($in['attachment_file'], $id);
    }

    $record = [
        'id' => $id,
        'at' => gmdate('c'),
        'shop' => $shop,
        'shop_name' => feedback_shop_name($shop),
        'gallery' => feedback_shop_gallery($shop),
        'module' => $module,
        'account' => feedback_clean_text($accountName, 80),
        'account_id' => $accountId,
        'email' => $email,
        'type' => $type,
        'severity' => $severity,
        'message' => $message,
        'page' => $page,
        'page_title' => feedback_clean_text($in['page_title'] ?? '', 200),
        'context' => [
            'ua' => feedback_clean_text($in['ua'] ?? '', 300),
            'viewport' => $viewport,
            'version' => feedback_clean_text($in['version'] ?? '', 40),
        ],
        'status' => 'new',
        'priority' => $severity === 'blocker' ? 'high' : 'normal',
        'attachment' => $attachment,
        'notes' => [], 'replies' => [], 'status_log' => [],
        'first_reply_at' => '', 'resolved_at' => '',
        'updated_at' => gmdate('c'),
        'tester_seen_at' => '',
    ];

    $duplicate = null;
    try {
        $created = feedback_mutate(static function (array &$data) use ($record, &$duplicate): array {
            $now = time();
            $recent = 0;
            foreach ($data as $f) {
                if (($f['shop'] ?? '') !== $record['shop']) continue;
                $at = (int) strtotime((string) ($f['at'] ?? ''));
                if ($at > $now - 3600) $recent++;
                if ($at > $now - 180 && ($f['message'] ?? '') === $record['message']) {
                    $duplicate = $f;
                    return $f;
                }
            }
            if ($recent >= FEEDBACK_HOURLY_LIMIT) {
                throw new InvalidArgumentException('Trop de retours envoyés récemment : réessayez dans une heure.');
            }
            $data[] = $record;
            if (count($data) > FEEDBACK_MAX_RECORDS) $data = array_slice($data, -FEEDBACK_MAX_RECORDS);
            return $record;
        });
    } catch (Throwable $e) {
        if ($attachment !== '') feedback_media_delete($attachment);
        throw $e;
    }

    if ($duplicate !== null) {
        if ($attachment !== '') feedback_media_delete($attachment);
        return feedback_record_normalize($duplicate);
    }
    feedback_notify_new($created);
    return feedback_record_normalize($created);
}

/** Met à jour le statut et/ou la priorité. Lève une exception si le retour est introuvable. */
function feedback_update(string $id, array $changes, string $by = ''): array
{
    $updated = feedback_mutate(static function (array &$data) use ($id, $changes, $by): array {
        foreach ($data as &$f) {
            if (($f['id'] ?? '') !== $id) continue;
            $f = feedback_record_normalize($f);
            if (isset($changes['status']) && in_array($changes['status'], FEEDBACK_STATUSES, true)) {
                $new = (string) $changes['status'];
                if ($new !== $f['status']) {
                    $f['status_log'][] = ['at' => gmdate('c'), 'by' => feedback_clean_text($by, 80), 'from' => $f['status'], 'to' => $new];
                    if (in_array($new, ['done', 'declined'], true) && $f['resolved_at'] === '') $f['resolved_at'] = gmdate('c');
                    if (in_array($new, FEEDBACK_OPEN_STATUSES, true)) $f['resolved_at'] = '';
                    $f['status'] = $new;
                }
            }
            if (isset($changes['priority']) && in_array($changes['priority'], FEEDBACK_PRIORITIES, true)) {
                $f['priority'] = (string) $changes['priority'];
            }
            $f['updated_at'] = gmdate('c');
            $result = $f;
            unset($f);
            return $result;
        }
        throw new InvalidArgumentException('Retour introuvable.');
    });
    return feedback_record_normalize($updated);
}

function feedback_note_add(string $id, string $text, string $by = ''): array
{
    $text = feedback_clean_text($text, 2000);
    if (mb_strlen($text) < 2) throw new InvalidArgumentException('La note est vide.');
    return feedback_update_note($id, ['at' => gmdate('c'), 'by' => feedback_clean_text($by, 80), 'text' => $text]);
}

function feedback_update_note(string $id, array $note): array
{
    $updated = feedback_mutate(static function (array &$data) use ($id, $note): array {
        foreach ($data as &$f) {
            if (($f['id'] ?? '') !== $id) continue;
            $f = feedback_record_normalize($f);
            $f['notes'][] = $note;
            $f['notes'] = array_slice($f['notes'], -100);
            $f['updated_at'] = gmdate('c');
            $result = $f;
            unset($f);
            return $result;
        }
        throw new InvalidArgumentException('Retour introuvable.');
    });
    return feedback_record_normalize($updated);
}

/** Envoie une réponse au testeur par e-mail et l'enregistre dans le suivi. */
function feedback_reply(string $id, string $text, string $by = '', string $to = ''): array
{
    $text = trim(preg_replace('/\r\n?/', "\n", feedback_clean_text($text, 4000)));
    if (mb_strlen($text) < 2) throw new InvalidArgumentException('La réponse est vide.');
    $f = feedback_find($id);
    if (!$f) throw new InvalidArgumentException('Retour introuvable.');
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $to = (string) $f['email'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("Ajoutez l'adresse e-mail du testeur : aucune adresse n'est enregistrée pour cette boutique.");
    }
    $platform = feedback_platform_name();
    $body = "Bonjour,\n\n" . $text . "\n\n— L'équipe " . $platform . "\n\n"
        . "Votre retour du " . gmdate('d/m/Y', (int) strtotime((string) $f['at'])) . " :\n"
        . mb_substr((string) $f['message'], 0, 1200) . "\n";
    if (!feedback_mail($to, 'Votre retour sur ' . $platform, $body)) {
        throw new RuntimeException("L'e-mail n'a pas pu être envoyé. Vérifiez l'adresse et réessayez.");
    }
    $reply = ['at' => gmdate('c'), 'by' => feedback_clean_text($by, 80), 'to' => $to, 'text' => $text];
    $updated = feedback_mutate(static function (array &$data) use ($id, $reply): array {
        foreach ($data as &$f) {
            if (($f['id'] ?? '') !== $id) continue;
            $f = feedback_record_normalize($f);
            $f['replies'][] = $reply;
            $f['replies'] = array_slice($f['replies'], -100);
            if ($f['first_reply_at'] === '') $f['first_reply_at'] = $reply['at'];
            $f['email'] = $reply['to'];
            if (in_array($f['status'], FEEDBACK_OPEN_STATUSES, true)) $f['status'] = 'waiting';
            $f['updated_at'] = gmdate('c');
            $result = $f;
            unset($f);
            return $result;
        }
        throw new InvalidArgumentException('Retour introuvable.');
    });
    return feedback_record_normalize($updated);
}

// ── Captures jointes ───────────────────────────────────────────────────────

function feedback_media_store(array $file, string $id): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            ? "L'image est trop lourde (3 Mo maximum)." : "L'image n'a pas pu être envoyée.");
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > FEEDBACK_MAX_ATTACHMENT) throw new InvalidArgumentException("L'image dépasse 3 Mo.");
    $tmp = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    $ext = $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null) : null;
    if ($ext === null) throw new InvalidArgumentException('Capture non reconnue : JPEG, PNG ou WebP.');
    $dir = feedback_media_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new RuntimeException("Impossible d'enregistrer la capture.");
    $name = $id . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $ok = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dir . '/' . $name) : @copy($tmp, $dir . '/' . $name);
    if (!$ok) throw new RuntimeException("Impossible d'enregistrer la capture.");
    @chmod($dir . '/' . $name, 0640);
    return $name;
}

function feedback_media_delete(string $name): void
{
    $path = feedback_media_path($name);
    if ($path !== '' && is_file($path)) @unlink($path);
}

function feedback_media_path(string $name): string
{
    if (!preg_match('/^fb[0-9]{6}-[a-f0-9]{8}-[a-f0-9]{12}\.(?:jpg|png|webp)$/', $name)) return '';
    return feedback_media_dir() . '/' . $name;
}

// ── E-mails ────────────────────────────────────────────────────────────────

/** Message-e-mail simple. En local, une copie est ajoutée à data/mail-outbox.log (comme les autres e-mails). */
function feedback_mail(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (defined('FEEDBACK_MAIL_STUB') && FEEDBACK_MAIL_STUB) return true;
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $local = !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')
        || filter_var($host, FILTER_VALIDATE_IP);
    $from = 'noreply@' . (!$local ? preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', $host) : 'localhost.localdomain');
    if ($local && !defined('FEEDBACK_DATA_DIR')) {
        @file_put_contents(dirname(feedback_data_dir()) . '/mail-outbox.log',
            '[' . date('c') . "] À : $to\nObjet : $subject\n$body\n----\n", FILE_APPEND | LOCK_EX);
    }
    $name = str_replace(['"', "\r", "\n"], '', feedback_platform_name());
    $headers = "From: $name <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    $sent = function_exists('mail') && @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    return $sent || $local;
}

/** Prévient l'exploitant d'un nouveau retour (best-effort : l'enregistrement ne dépend pas de l'envoi). */
function feedback_notify_new(array $f): bool
{
    $to = (string) feedback_settings_load()['notify_email'];
    if ($to === '' && function_exists('saas_config')) {
        try {
            $to = trim((string) (saas_config()['contact'] ?? ''));
        } catch (Throwable $e) {
            $to = '';
        }
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $firstLine = trim((string) strtok((string) $f['message'], "\n"));
    $subject = '[Retours] ' . (FEEDBACK_TYPE_LABELS[$f['type']] ?? 'Retour') . ' — ' . $f['shop_name']
        . ' : ' . mb_substr($firstLine, 0, 60);
    $link = feedback_origin() . '/portail/retours.php?focus=' . rawurlencode($f['id']);
    $body = "Nouveau retour de testeur.\n\n"
        . "Boutique : {$f['shop_name']} ({$f['shop']})\n"
        . "Type : " . (FEEDBACK_TYPE_LABELS[$f['type']] ?? $f['type']) . "\n"
        . ($f['severity'] !== '' ? 'Gravité : ' . (FEEDBACK_SEVERITY_LABELS[$f['severity']] ?? $f['severity']) . "\n" : '')
        . "Compte : " . ($f['account'] !== '' ? $f['account'] : '—') . "\n"
        . "Page : " . ($f['page'] !== '' ? $f['page'] : '—') . "\n\n"
        . $f['message'] . "\n\n"
        . "Ouvrir le suivi : $link\n";
    return feedback_mail($to, $subject, $body);
}

/** Relance les testeurs sans retour récent. Retourne le nombre d'e-mails (ou de relances à envoyer en mode --dry). */
function feedback_send_reminders(bool $dry = false): int
{
    $settings = feedback_settings_load();
    $days = (int) $settings['reply_days'];
    $since = (int) $settings['since'];
    if ($since <= 0 && is_file(feedback_settings_file())) $since = (int) filemtime(feedback_settings_file());
    if ($since <= 0) $since = time();
    $lastByShop = [];
    foreach (feedback_all() as $f) {
        $shop = (string) $f['shop'];
        $at = (int) strtotime((string) $f['at']);
        $lastByShop[$shop] = max((int) ($lastByShop[$shop] ?? 0), $at);
    }
    $sent = is_file(feedback_reminders_file())
        ? (array) json_decode((string) file_get_contents(feedback_reminders_file()), true) : [];
    $now = time();
    $count = 0;
    $changed = false;
    $shops = !empty($settings['open_to_all']) ? array_keys(feedback_tenant_list()) : feedback_tester_shops($settings);
    foreach ($shops as $shop) {
        $last = (int) ($lastByShop[$shop] ?? $since);
        if ($now - $last < $days * 86400) continue;
        $key = (string) $shop;
        if ($now - (int) ($sent[$key] ?? 0) < $days * 86400) continue;
        $email = feedback_shop_email($shop);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if ($dry) {
            $count++;
            continue;
        }
        $name = feedback_shop_name($shop);
        $body = "Bonjour,\n\nvous testez la plateforme avec la boutique « $name » et votre avis nous aide à prioriser les prochains chantiers.\n\n"
            . "Le bouton « Un retour ? » en bas de votre administration permet de signaler un bug, proposer une idée ou décrire une gêne en moins d'une minute.\n"
            . "Accès direct : " . feedback_shop_admin_url($shop) . "\n\n"
            . "Un retour par quinzaine suffit : ce qui marche, ce qui manque, ce qui vous ralentit.\n\nMerci !\n— L'équipe " . feedback_platform_name() . "\n";
        if (!feedback_mail($email, 'Votre avis sur ' . feedback_platform_name(), $body)) continue;
        $sent[$key] = $now;
        $changed = true;
        $count++;
    }
    if (!$dry && $changed) {
        $dir = dirname(feedback_reminders_file());
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return $count;
        @file_put_contents(feedback_reminders_file(), json_encode($sent, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $count;
}

// ── Statistiques ───────────────────────────────────────────────────────────

function feedback_new_count(): int
{
    $n = 0;
    foreach (feedback_all() as $f) if ($f['status'] === 'new') $n++;
    return $n;
}

function feedback_stats(?array $list = null, ?array $settings = null): array
{
    $list ??= feedback_all();
    $settings ??= feedback_settings_load();
    $now = time();
    $byStatus = array_fill_keys(FEEDBACK_STATUSES, 0);
    $byType = array_fill_keys(FEEDBACK_TYPES, 0);
    $bySeverity = array_fill_keys(FEEDBACK_SEVERITIES, 0);
    $firstReply = [];
    $resolve = [];
    $week = 0;
    $prevWeek = 0;
    $done7 = 0;
    $shops30 = [];
    foreach ($list as $f) {
        $byStatus[$f['status']] = ($byStatus[$f['status']] ?? 0) + 1;
        $byType[$f['type']] = ($byType[$f['type']] ?? 0) + 1;
        if ($f['severity'] !== '') $bySeverity[$f['severity']] = ($bySeverity[$f['severity']] ?? 0) + 1;
        $at = (int) strtotime((string) $f['at']);
        if ($at > $now - 7 * 86400) $week++;
        elseif ($at > $now - 14 * 86400) $prevWeek++;
        if ($at > $now - 30 * 86400) $shops30[$f['shop']] = true;
        if ($f['first_reply_at'] !== '') {
            $delta = (int) strtotime((string) $f['first_reply_at']) - $at;
            if ($delta >= 0) $firstReply[] = $delta;
        }
        if (in_array($f['status'], ['done', 'declined'], true)) {
            $resolvedAt = (int) strtotime((string) ($f['resolved_at'] !== '' ? $f['resolved_at'] : $f['updated_at']));
            $delta = $resolvedAt - $at;
            if ($delta >= 0) $resolve[] = $delta;
            if ($resolvedAt > $now - 7 * 86400) $done7++;
        }
    }
    $testerShops = $settings['open_to_all'] ? array_keys(feedback_tenant_list()) : feedback_tester_shops($settings);
    $open = 0;
    foreach (FEEDBACK_OPEN_STATUSES as $s) $open += (int) ($byStatus[$s] ?? 0);
    $lastByShop = [];
    foreach ($list as $f) $lastByShop[$f['shop']] = max((int) ($lastByShop[$f['shop']] ?? 0), (int) strtotime((string) $f['at']));
    $silent = 0;
    foreach ($testerShops as $shop) {
        $last = (int) ($lastByShop[$shop] ?? 0);
        if ($last === 0 || $now - $last > (int) $settings['reply_days'] * 86400) $silent++;
    }
    return [
        'total' => count($list),
        'open' => $open,
        'new' => (int) ($byStatus['new'] ?? 0),
        'week' => $week,
        'prev_week' => $prevWeek,
        'done_7' => $done7,
        'by_status' => $byStatus,
        'by_type' => $byType,
        'by_severity' => $bySeverity,
        'avg_first_reply_hours' => $firstReply ? round(array_sum($firstReply) / count($firstReply) / 3600, 1) : null,
        'avg_resolve_days' => $resolve ? round(array_sum($resolve) / count($resolve) / 86400, 1) : null,
        'active_shops_30' => count($shops30),
        'testers' => count($testerShops),
        'silent' => $silent,
    ];
}

/** Volume par semaine (semaines commençant le lundi), de la plus ancienne à la courante. */
function feedback_weekly(?array $list = null, int $weeks = 8): array
{
    $list ??= feedback_all();
    $weeks = max(1, min(26, $weeks));
    $thisMonday = strtotime('monday this week');
    $out = [];
    for ($i = $weeks - 1; $i >= 0; $i--) {
        $start = $thisMonday - $i * 7 * 86400;
        $end = $start + 7 * 86400;
        $count = 0;
        foreach ($list as $f) {
            $at = (int) strtotime((string) $f['at']);
            if ($at >= $start && $at < $end) $count++;
        }
        $out[] = ['label' => date('d/m', $start), 'start' => gmdate('Y-m-d', $start), 'count' => $count];
    }
    return $out;
}

/** Participation des testeurs : retours, dernier retour, état actif / silencieux / jamais. */
function feedback_participation(?array $list = null, ?array $settings = null): array
{
    $list ??= feedback_all();
    $settings ??= feedback_settings_load();
    $shops = $settings['open_to_all'] ? array_keys(feedback_tenant_list()) : feedback_tester_shops($settings);
    foreach ($list as $f) if (empty($settings['open_to_all'])) $shops[] = (string) $f['shop'];
    $shops = array_values(array_unique(array_filter($shops)));
    $agg = [];
    foreach ($list as $f) {
        $shop = (string) $f['shop'];
        $at = (int) strtotime((string) $f['at']);
        $agg[$shop]['count'] = (int) ($agg[$shop]['count'] ?? 0) + 1;
        $agg[$shop]['last'] = max((int) ($agg[$shop]['last'] ?? 0), $at);
        $agg[$shop]['name'] = (string) $f['shop_name'];
    }
    $now = time();
    $rows = [];
    foreach ($shops as $shop) {
        $last = (int) ($agg[$shop]['last'] ?? 0);
        $days = $last > 0 ? (int) floor(($now - $last) / 86400) : null;
        $state = $last === 0 ? 'never' : ($days > (int) $settings['reply_days'] ? 'silent' : 'active');
        $rows[] = [
            'shop' => $shop,
            'name' => (string) ($agg[$shop]['name'] ?? feedback_shop_name($shop)),
            'count' => (int) ($agg[$shop]['count'] ?? 0),
            'last' => $last,
            'days' => $days,
            'state' => $state,
            'email' => feedback_shop_email($shop),
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $rank = ['active' => 0, 'silent' => 1, 'never' => 2];
        return [$rank[$a['state']] ?? 3, -$a['last']] <=> [$rank[$b['state']] ?? 3, -$b['last']];
    });
    return $rows;
}

/** Lignes du CSV : [en-têtes, lignes]. */
function feedback_csv(array $list): array
{
    $headers = ['Identifiant', 'Date', 'Boutique', 'Type', 'Gravité', 'Statut', 'Priorité', 'Compte', 'E-mail', 'Page', 'Message', 'Réponse', 'Pièce jointe'];
    $rows = [];
    foreach ($list as $f) {
        $replies = [];
        foreach ($f['replies'] as $reply) $replies[] = (string) ($reply['text'] ?? '');
        $rows[] = [
            $f['id'], $f['at'], $f['shop_name'] . ' (' . $f['shop'] . ')',
            FEEDBACK_TYPE_LABELS[$f['type']] ?? $f['type'],
            FEEDBACK_SEVERITY_LABELS[$f['severity']] ?? '',
            FEEDBACK_STATUS_LABELS[$f['status']] ?? $f['status'],
            FEEDBACK_PRIORITY_LABELS[$f['priority']] ?? $f['priority'],
            $f['account'], $f['email'], $f['page'], $f['message'], implode("\n\n", $replies), $f['attachment'],
        ];
    }
    return [$headers, $rows];
}

// ── Libellés ───────────────────────────────────────────────────────────────

function feedback_status_tester_label(string $status): string
{
    return match ($status) {
        'new' => 'Reçu', 'qualified' => 'Qualifié', 'in_progress' => 'En cours',
        'waiting' => 'Réponse envoyée', 'done' => 'Traité', 'declined' => 'Écarté',
        default => 'Reçu',
    };
}

function feedback_status_tone(string $status): string
{
    return match ($status) {
        'new' => 'new', 'in_progress', 'qualified' => 'doing',
        'waiting' => 'wait', 'done' => 'ok', 'declined' => 'off', default => 'new',
    };
}

function feedback_type_icon(string $type): string
{
    return match ($type) {
        'bug' => '<path d="M8 4h8M9 4v2M15 4v2"/><rect x="7" y="8" width="10" height="11" rx="5"/><path d="M4 12h3M17 12h3M5 17l2-1M19 17l-2-1M5 8l2 1M19 8l-2 1"/>',
        'idea' => '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z"/>',
        'improvement' => '<path d="M4 17l6-6 4 4 6-8"/><path d="M15 7h5v5"/>',
        default => '<path d="M21 12a9 9 0 1 1-3.5-7.1"/><path d="M21 3v6h-6"/>',
    };
}

// ── Widget (administration d'une boutique) ─────────────────────────────────

function feedback_widget_html(): string
{
    static $rendered = false;
    if ($rendered) return '';
    $session = function_exists('admin_session') ? admin_session() : null;
    $shop = function_exists('tenant_slug') ? tenant_slug() : '';
    if ($session === null || $shop === '' || !feedback_widget_enabled($shop)) return '';
    $rendered = true;
    // Le cookie de session est limité au préfixe de la boutique (/<identifiant>/)
    // sur les adresses partagées : l'API doit être appelée sous ce même préfixe.
    $base = function_exists('tenant_base_path') ? tenant_base_path() : '';
    $config = [
        'api' => $base . '/admin/feedback-api.php',
        'csrf' => function_exists('admin_csrf_token') ? admin_csrf_token() : '',
        'shop' => $shop,
        'types' => FEEDBACK_TYPE_LABELS,
        'severities' => FEEDBACK_SEVERITY_LABELS,
        'statuses' => array_map('feedback_status_tester_label', array_combine(FEEDBACK_STATUSES, FEEDBACK_STATUSES)),
        'module' => (string) ($_GET['p'] ?? ''),
        'lang' => 'fr-FR',
    ];
    ob_start(); ?>
<link rel="stylesheet" href="/assets/feedback-widget.css?v=2">
<div class="fbw" data-fbw data-config="<?= htmlspecialchars(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
  <button type="button" class="fbw-launch" data-fbw-open aria-expanded="false" aria-controls="fbw-panel">
    <span class="fbw-launch-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.3 9.5 9.5 0 0 1-3.5-.7L4 20.5l1.4-4.1A8.1 8.1 0 0 1 3 11.5a8.4 8.4 0 0 1 8.5-8.3A8.4 8.4 0 0 1 21 11.5z"/></svg></span>
    <span class="fbw-launch-label">Un retour ?</span>
    <span class="fbw-dot" data-fbw-dot hidden aria-label="Nouvelle réponse"></span>
  </button>
  <section class="fbw-panel" id="fbw-panel" role="dialog" aria-labelledby="fbw-title" hidden>
    <header class="fbw-head">
      <div><p class="fbw-kicker">Programme testeurs</p><h2 id="fbw-title">Votre retour</h2></div>
      <button type="button" class="fbw-close" data-fbw-close aria-label="Fermer">×</button>
    </header>
    <div class="fbw-tabs" role="tablist">
      <button type="button" class="is-active" role="tab" aria-selected="true" data-fbw-tab="send">Envoyer</button>
      <button type="button" role="tab" aria-selected="false" data-fbw-tab="mine">Mes retours <span class="fbw-count" data-fbw-count hidden></span></button>
    </div>
    <div class="fbw-body">
      <form class="fbw-form" data-fbw-form novalidate>
        <fieldset class="fbw-types">
          <legend>Nature du retour</legend>
          <?php foreach (FEEDBACK_TYPE_LABELS as $key => $label): ?>
            <label class="fbw-type"><input type="radio" name="type" value="<?= $key ?>"<?= $key === 'bug' ? ' checked' : '' ?>><span><?= htmlspecialchars($label) ?></span></label>
          <?php endforeach; ?>
        </fieldset>
        <label class="fbw-field fbw-severity" data-fbw-severity>Gravité
          <select name="severity">
            <option value="blocker">Bloquant — je ne peux pas travailler</option>
            <option value="major" selected>Gênant — je contourne</option>
            <option value="minor">Mineur — cosmétique</option>
          </select>
        </label>
        <label class="fbw-field">Votre message
          <textarea name="message" rows="5" maxlength="4000" minlength="10" required placeholder="Ce qui ne marche pas, comment le reproduire, ou ce qui vous manque…"></textarea>
        </label>
        <div class="fbw-dictate">
          <button type="button" class="fbw-tool fbw-mic" data-fbw-mic hidden aria-pressed="false" title="Dicter le message à la voix">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M9 21h6"/></svg>
            <span class="fbw-tool-label">Dicter</span>
          </button>
          <span class="fbw-dictate-state" data-fbw-dictate-state hidden role="status" aria-live="polite">Dictée en cours…</span>
        </div>
        <div class="fbw-shot">
          <div class="fbw-shot-actions">
            <button type="button" class="fbw-tool" data-fbw-capture>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-2h6l2 2h3v11H4z"/><circle cx="12" cy="13" r="3.2"/></svg>
              <span class="fbw-tool-label">Capturer l’écran</span>
            </button>
            <button type="button" class="fbw-tool" data-fbw-annotate hidden>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l4-1 10-10-3-3L5 16z"/><path d="M14 7l3 3"/></svg>
              <span class="fbw-tool-label">Annoter l’image</span>
            </button>
            <button type="button" class="fbw-tool" data-fbw-shot-remove hidden>Retirer la capture</button>
          </div>
          <label class="fbw-field fbw-file">Joindre une capture (facultatif, 3 Mo max)
            <input type="file" name="shot" accept="image/jpeg,image/png,image/webp">
          </label>
          <p class="fbw-shot-state" data-fbw-shot-state hidden role="status" aria-live="polite"></p>
        </div>
        <p class="fbw-hint">La page, votre boutique et votre navigateur sont joints automatiquement pour aider à reproduire.</p>
        <div class="fbw-actions">
          <button type="submit" class="fbw-submit">Envoyer mon retour</button>
          <p class="fbw-msg" data-fbw-msg role="status" aria-live="polite"></p>
        </div>
      </form>
      <div class="fbw-history" data-fbw-history hidden>
        <p class="fbw-hint">Vos retours et les réponses de l’équipe.</p>
        <div data-fbw-list></div>
        <p class="fbw-empty" data-fbw-empty hidden>Aucun retour envoyé pour l’instant.</p>
      </div>
    </div>
  </section>
  <div class="fbw-editor" data-fbw-editor hidden role="dialog" aria-modal="true" aria-labelledby="fbw-editor-title">
    <div class="fbw-editor-card">
      <header class="fbw-editor-head">
        <div><p class="fbw-kicker">Capture d’écran</p><h3 id="fbw-editor-title">Entourez ou fléchez ce qui pose problème</h3></div>
        <button type="button" class="fbw-close" data-fbw-editor-cancel aria-label="Fermer l’annotation">×</button>
      </header>
      <div class="fbw-editor-tools" role="toolbar" aria-label="Outils d’annotation">
        <button type="button" class="fbw-tool is-active" data-fbw-tool="pen" aria-pressed="true">Crayon</button>
        <button type="button" class="fbw-tool" data-fbw-tool="rect" aria-pressed="false">Cadre</button>
        <button type="button" class="fbw-tool" data-fbw-tool="arrow" aria-pressed="false">Flèche</button>
        <span class="fbw-colors" role="group" aria-label="Couleur du trait">
          <button type="button" class="fbw-color is-active" data-fbw-color="#d92d20" style="--c:#d92d20" aria-label="Rouge" aria-pressed="true"></button>
          <button type="button" class="fbw-color" data-fbw-color="#f59f00" style="--c:#f59f00" aria-label="Orange" aria-pressed="false"></button>
          <button type="button" class="fbw-color" data-fbw-color="#1c7ed6" style="--c:#1c7ed6" aria-label="Bleu" aria-pressed="false"></button>
          <button type="button" class="fbw-color" data-fbw-color="#111111" style="--c:#111111" aria-label="Noir" aria-pressed="false"></button>
        </span>
        <button type="button" class="fbw-tool" data-fbw-undo>Annuler le trait</button>
        <button type="button" class="fbw-tool" data-fbw-clear>Tout effacer</button>
      </div>
      <div class="fbw-editor-stage"><canvas data-fbw-canvas></canvas></div>
      <footer class="fbw-editor-foot">
        <p class="fbw-hint">L’image reste dans votre navigateur jusqu’à l’envoi du retour.</p>
        <div class="fbw-actions">
          <button type="button" class="fbw-tool" data-fbw-editor-cancel>Annuler</button>
          <button type="button" class="fbw-submit" data-fbw-editor-ok>Joindre la capture</button>
        </div>
      </footer>
    </div>
  </div>
</div>
<script src="/assets/feedback-widget.js?v=2" defer></script>
<?php return (string) ob_get_clean();
}
