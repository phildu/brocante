<?php

// Import automatique des retours dans la liste de tâches du portail.
// Autonome : ne charge ni le portail ni sa session, écrit seulement dans
// data/todo/todo.db. Appelé par admin/feedback-api.php après chaque retour.

function feedback_todo_import(array $record): ?int
{
    try {
        return feedback_todo_import_or_throw($record);
    } catch (Throwable $e) {
        error_log('feedback-todo: ' . $e->getMessage());
        return null;
    }
}

function feedback_todo_import_or_throw(array $record): ?int
{
    $ref = (string) ($record['id'] ?? '');
    if (!preg_match('/^fb\d{6}-[a-f0-9]{8}$/', $ref)) {
        return null;
    }
    $message = trim(preg_replace('/\s+/u', ' ', (string) ($record['message'] ?? '')) ?? '');
    if (mb_strlen($message) < 15) {
        return null;
    }

    $pdo = feedback_todo_db();
    $source = 'feedback:' . $ref;
    $st = $pdo->prepare('SELECT id FROM tasks WHERE source = ? OR detail LIKE ? LIMIT 1');
    $st->execute([$source, '%' . $ref . '%']);
    $existing = $st->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
    $position = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM tasks')->fetchColumn();
    $pdo->prepare('INSERT INTO tasks (title, detail, category, priority, shop, link, status, position, source, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            feedback_todo_title($record, $message),
            feedback_todo_detail($record, $message),
            feedback_todo_category($message),
            feedback_todo_priority($record),
            '',
            feedback_todo_link($ref),
            'todo',
            $position,
            $source,
            $now,
            $now,
        ]);
    return (int) $pdo->lastInsertId();
}

function feedback_todo_db(): PDO
{
    $file = defined('TD_TODO_DB') ? (string) TD_TODO_DB : dirname(__DIR__) . '/data/todo/todo.db';
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        throw new RuntimeException("Impossible de créer data/todo/ : droits d'écriture manquants.");
    }
    $new = !is_file($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    feedback_todo_schema($pdo);
    if ($new) @chmod($file, 0600);
    return $pdo;
}

function feedback_todo_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'autre',
    priority INTEGER NOT NULL DEFAULT 2,
    shop TEXT NOT NULL DEFAULT '',
    link TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'todo',
    position INTEGER NOT NULL DEFAULT 0,
    source TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    done_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_td_status ON tasks(status, priority, position);
SQL);
    $columns = $pdo->query('PRAGMA table_info(tasks)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('source', $columns, true)) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN source TEXT NOT NULL DEFAULT ''");
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_td_source ON tasks(source) WHERE source <> ''");
}

function feedback_todo_title(array $record, string $message): string
{
    $labels = defined('FEEDBACK_TYPE_LABELS') ? FEEDBACK_TYPE_LABELS : [
        'bug' => 'Bug', 'idea' => 'Idée', 'improvement' => 'Amélioration', 'other' => 'Autre',
    ];
    $kind = (string) ($record['source'] ?? '') === 'client' ? 'Client' : 'Testeur';
    $label = $labels[(string) ($record['type'] ?? 'other')] ?? 'Retour';
    $snippet = mb_substr($message, 0, 110);
    if (mb_strlen($message) > 110) {
        $cut = mb_strrpos($snippet, ' ');
        if ($cut !== false && $cut > 60) {
            $snippet = mb_substr($snippet, 0, $cut);
        }
        $snippet .= '…';
    }
    return mb_substr($kind . ' · ' . $label . ' — ' . $snippet, 0, 160);
}

function feedback_todo_detail(array $record, string $message): string
{
    $ref = (string) $record['id'];
    $at = (string) ($record['at'] ?? '');
    $ts = $at !== '' ? strtotime($at) : false;
    $date = $ts
        ? (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i')
        : 'date inconnue';
    $shop = trim((string) ($record['shop_name'] ?? '')) ?: (string) ($record['shop'] ?? '');
    $kind = (string) ($record['source'] ?? '') === 'client' ? 'client' : 'testeur';
    $page = trim((string) ($record['url'] ?? '')) ?: trim((string) ($record['page'] ?? ''));
    $parts = [
        "Retour {$kind} du {$date} ({$ref}" . ($shop !== '' ? ", boutique {$shop}" : '') . ').',
        '« ' . mb_substr($message, 0, 800) . ' »',
    ];
    if ($page !== '') {
        $parts[] = 'Page : ' . $page;
    }
    $parts[] = 'Créé automatiquement depuis le module Feedback ; qualifier le retour puis préciser l’action attendue.';
    return mb_substr(implode("\n", $parts), 0, 1200);
}

function feedback_todo_category(string $message): string
{
    $hay = ' ' . mb_strtolower($message) . ' ';
    $map = [
        'paiement' => ['stripe', 'paiement', 'carte bancaire', 'remboursement', 'virement', 'sepa', 'revolut'],
        'email' => ['e-mail', 'email', 'mail', 'smtp', 'newsletter'],
        'ia' => [' ia ', ' lia ', 'gemini', 'intelligence artificielle', 'chatgpt', 'photo ia'],
        'telephone' => ['sms', 'téléphone', 'telephone', 'appel'],
        'hebergement' => ['ovh', 'serveur', 'hébergement', 'hebergement', 'sauvegarde'],
        'boutiques' => ['boutique', 'module', 'pack', 'plugin', 'tableau de bord', 'étiquette', 'etiquette', 'marge', 'catalogue', 'galerie'],
    ];
    foreach ($map as $category => $words) {
        foreach ($words as $word) {
            if (str_contains($hay, $word)) {
                return $category;
            }
        }
    }
    return 'autre';
}

function feedback_todo_priority(array $record): int
{
    if (($record['severity'] ?? '') === 'blocker' || in_array((string) ($record['priority'] ?? ''), ['high', 'urgent'], true)) {
        return 1;
    }
    if (($record['severity'] ?? '') === 'minor') {
        return 3;
    }
    return 2;
}

function feedback_todo_link(string $ref): string
{
    $host = defined('TD_TODO_PORTAL_HOST')
        ? trim((string) TD_TODO_PORTAL_HOST)
        : (function_exists('tenant_base_host') ? trim((string) tenant_base_host()) : '');
    if ($host === '') {
        return '';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . '/portail/retours.php?focus=' . rawurlencode($ref);
}
