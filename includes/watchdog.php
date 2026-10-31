<?php

// Surveillance sans tâche planifiée : le mutualisé OVH n'offre pas de cron
// fiable, donc les contrôles se greffent sur les requêtes (au plus une fois
// toutes les cinq minutes) et health.php sert de sonde à un moniteur externe.
// Trois signaux suffisent aujourd'hui : erreurs fatales PHP, espace disque,
// quota des fournisseurs IA (Gemini, fal.ai…). Les alertes partent par e-mail,
// au plus une fois toutes les six heures par sujet, vers l'adresse de contact
// du portail (ou WATCHDOG_EMAIL / data/watchdog/email.txt).

const WD_BEAT_INTERVAL = 300;
const WD_ALERT_INTERVAL = 21600;
const WD_FATAL_WINDOW = 3600;
const WD_AI_WINDOW = 86400;
const WD_DISK_MIN = 524288000; // 500 Mo

function wd_root(): string
{
    return dirname(__DIR__);
}

function wd_dir(): string
{
    return wd_root() . '/data/watchdog';
}

function wd_log_dir(): string
{
    return wd_root() . '/data/logs';
}

function wd_json_read(string $file): array
{
    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function wd_json_write(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return;
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @file_put_contents($file, $json, LOCK_EX);
        @unlink($tmp);
    }
}

/** Ligne JSON par jour dans data/logs/wd-AAAA-MM-JJ.log ; rotation à 30 jours. */
function wd_log(string $channel, array $row): void
{
    $dir = wd_log_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return;
    $row = ['ts' => time(), 'ch' => $channel] + $row;
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;
    @file_put_contents($dir . '/wd-' . date('Y-m-d') . '.log', $json . "\n", FILE_APPEND | LOCK_EX);
    wd_prune_logs();
}

function wd_prune_logs(): void
{
    $dir = wd_log_dir();
    $limit = time() - 30 * 86400;
    foreach (glob($dir . '/wd-*.log') ?: [] as $file) {
        if (@filemtime($file) < $limit) @unlink($file);
    }
}

/** Entrées récentes des canaux demandés (fenêtre maximale : 48 h). */
function wd_log_since(int $seconds, array $channels): array
{
    $out = [];
    $since = time() - $seconds;
    for ($day = 0; $day <= 1; $day++) {
        $file = wd_log_dir() . '/wd-' . date('Y-m-d', time() - $day * 86400) . '.log';
        if (!is_file($file)) continue;
        $handle = @fopen($file, 'r');
        if (!$handle) continue;
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true);
            if (!is_array($row) || (int) ($row['ts'] ?? 0) < $since) continue;
            if (!in_array((string) ($row['ch'] ?? ''), $channels, true)) continue;
            $out[] = $row;
        }
        fclose($handle);
    }
    return $out;
}

/** Journalise un échec d'appel IA (quota, clé refusée, réseau). */
function wd_note_ai(int $code, string $url = '', string $body = ''): void
{
    if (!in_array($code, [0, 401, 402, 403, 429], true)) return;
    $message = '';
    $data = json_decode($body, true);
    if (is_array($data)) {
        $message = (string) ($data['error']['message'] ?? $data['error']['status'] ?? $data['message'] ?? '');
    }
    if ($message === '') $message = mb_substr(trim(strip_tags($body)), 0, 160);
    wd_log('ai', [
        'code' => $code,
        'host' => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
        'msg' => mb_substr(trim($message), 0, 200),
    ]);
}

/** Installe la capture des erreurs fatales et le battement de cœur. Idempotent. */
function wd_install(): void
{
    static $installed = false;
    if ($installed) return;
    $installed = true;
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            wd_log('fatal', [
                'msg' => mb_substr((string) $error['message'], 0, 300),
                'file' => basename((string) $error['file']),
                'line' => (int) $error['line'],
                'uri' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 200),
                'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            ]);
        }
        if (PHP_SAPI !== 'cli') wd_beat();
    });
}

/** Contrôles périodiques ; null quand rien n'est à signaler. */
function wd_checks(): array
{
    $issues = [];

    $free = @disk_free_space(wd_root());
    if (is_float($free) && $free < WD_DISK_MIN) {
        $issues['disk'] = 'Disque presque plein : ' . intdiv((int) $free, 1048576) . ' Mo libres.';
    }

    $fatals = wd_log_since(WD_FATAL_WINDOW, ['fatal']);
    if ($fatals) {
        $last = end($fatals);
        $issues['fatal'] = count($fatals) . ' erreur(s) fatale(s) dans l’heure — dernière sur '
            . ($last['uri'] ?? '?') . ' (' . ($last['file'] ?? '?') . ':' . ($last['line'] ?? 0) . ').';
    }

    $quota = 0;
    $auth = 0;
    $lastQuota = '';
    foreach (wd_log_since(WD_AI_WINDOW, ['ai']) as $row) {
        $code = (int) ($row['code'] ?? 0);
        if (in_array($code, [402, 429], true)) {
            $quota++;
            $lastQuota = (string) ($row['msg'] ?? '');
        } elseif (in_array($code, [401, 403], true)) {
            $auth++;
        }
    }
    if ($quota > 0) {
        $issues['quota'] = 'Quota IA atteint (' . $quota . ' refus en 24 h)' . ($lastQuota !== '' ? ' : ' . $lastQuota : '') . '.';
    }
    if ($auth > 0) {
        $issues['auth'] = 'Clé IA refusée (' . $auth . ' fois en 24 h) : vérifier la clé dans le portail.';
    }

    return $issues;
}

/** Battement de cœur : au plus un passage par cinq minutes, verrou non bloquant. */
function wd_beat(): void
{
    $stateFile = wd_dir() . '/state.json';
    $state = wd_json_read($stateFile);
    if (time() - (int) ($state['beat'] ?? 0) < WD_BEAT_INTERVAL) return;

    if (!is_dir(wd_dir()) && !@mkdir(wd_dir(), 0700, true) && !is_dir(wd_dir())) return;
    $lock = @fopen(wd_dir() . '/.beat.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return;
    }
    try {
        $state = wd_json_read($stateFile);
        if (time() - (int) ($state['beat'] ?? 0) < WD_BEAT_INTERVAL) return;
        $issues = wd_checks();
        $state['beat'] = time();
        $state['ok'] = $issues === [];
        $state['issues'] = array_keys($issues);
        wd_json_write($stateFile, $state);
        if ($issues) wd_alert($issues, $state);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function wd_alert_email(): string
{
    $env = trim((string) getenv('WATCHDOG_EMAIL'));
    if ($env !== '' && filter_var($env, FILTER_VALIDATE_EMAIL)) return $env;
    $file = wd_dir() . '/email.txt';
    if (is_file($file)) {
        $value = trim((string) @file_get_contents($file));
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) return $value;
    }
    $cfg = wd_json_read(wd_root() . '/data/saas.json');
    $value = (string) ($cfg['contact'] ?? '');
    return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
}

/** Envoie une alerte e-mail, un seul message par sujet toutes les six heures. */
function wd_alert(array $issues, array &$state): void
{
    $now = time();
    $sent = [];
    $lines = [];
    foreach ($issues as $key => $text) {
        if (($now - (int) ($state['alert'][$key] ?? 0)) < WD_ALERT_INTERVAL) continue;
        $state['alert'][$key] = $now;
        $sent[] = $key;
        $lines[] = '· ' . $text;
    }
    if (!$sent) return;

    $to = wd_alert_email();
    if ($to === '') {
        wd_log('alert', ['keys' => $sent, 'sent' => false, 'reason' => 'aucune adresse']);
        return;
    }
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost');
    $body = "Surveillance Brocs — " . date('d/m/Y H:i') . "\n\n"
        . implode("\n", $lines) . "\n\n"
        . "Détail et sonde : $origin/health.php\n"
        . "Journal : data/logs/ (fichiers wd-AAAA-MM-JJ.log).\n";
    $ok = wd_mail($to, 'Brocs — alerte : ' . implode(', ', $sent), $body);
    wd_log('alert', ['keys' => $sent, 'sent' => $ok]);
    wd_json_write(wd_dir() . '/state.json', $state);
}

function wd_mail(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $local = !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || (bool) filter_var($host, FILTER_VALIDATE_IP);
    if ($local) {
        @file_put_contents(wd_root() . '/data/mail-outbox.log', '[' . date('c') . "] À : $to\nObjet : $subject\n$body\n----\n", FILE_APPEND | LOCK_EX);
    }
    $from = 'alerte@' . ($local ? 'localhost.localdomain' : preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', $host));
    $headers = "From: Surveillance Brocs <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    return function_exists('mail') && @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/**
 * État courant pour health.php et la ligne de commande.
 * Le contrôle d'écriture de la base n'a lieu que si le socle est chargé.
 */
function wd_health(): array
{
    $state = wd_json_read(wd_dir() . '/state.json');
    $issues = wd_checks();

    $db = 'non testée';
    if (function_exists('db')) {
        try {
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('ROLLBACK');
            $db = 'ok';
        } catch (Throwable $e) {
            $db = 'erreur';
            $issues['db'] = 'Base inaccessible ou en lecture seule : ' . mb_substr($e->getMessage(), 0, 160);
        }
    }

    return [
        'ok' => $issues === [],
        'time' => date('c'),
        'db' => $db,
        'disk_free_mo' => (int) (@disk_free_space(wd_root()) / 1048576),
        'fatal_1h' => count(wd_log_since(WD_FATAL_WINDOW, ['fatal'])),
        'ia_24h' => count(wd_log_since(WD_AI_WINDOW, ['ai'])),
        'last_beat' => (int) ($state['beat'] ?? 0) ? date('c', (int) $state['beat']) : null,
        'issues' => $issues,
    ];
}
