<?php

// Publication des retours du module Feedback dans la base Notion « Suivi des
// jalons ». Autonome : ni portail ni session ; le jeton vit dans
// .secrets/notion.json (jamais déployé), l'état et la file d'attente dans
// data/saas/. Appelé par admin/feedback-api.php après chaque retour.

function notion_todo_secrets_dir(): string
{
    return defined('NOTION_TODO_SECRETS_DIR')
        ? rtrim((string) NOTION_TODO_SECRETS_DIR, '/')
        : dirname(__DIR__) . '/.secrets';
}

function notion_todo_data_dir(): string
{
    return defined('NOTION_TODO_DATA_DIR')
        ? rtrim((string) NOTION_TODO_DATA_DIR, '/')
        : dirname(__DIR__) . '/data/saas';
}

function notion_todo_config_file(): string
{
    return notion_todo_secrets_dir() . '/notion.json';
}

function notion_todo_state_file(): string
{
    return notion_todo_data_dir() . '/notion-state.json';
}

function notion_todo_pending_file(): string
{
    return notion_todo_data_dir() . '/notion-pending.json';
}

/** Jeton et base configurés, ou null si l'envoi n'est pas activé. */
function notion_todo_config(): ?array
{
    $file = notion_todo_config_file();
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return null;
    }
    $token = trim((string) ($data['token'] ?? ''));
    $database = notion_todo_database_id((string) ($data['database_id'] ?? ''));
    if ($token === '' || $database === '') {
        return null;
    }
    return ['token' => $token, 'database_id' => $database];
}

function notion_todo_configured(): bool
{
    return notion_todo_config() !== null;
}

/** Identifiant de base Notion au format 8-4-4-4-12, ou '' si invalide. */
function notion_todo_database_id(string $id): string
{
    $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $id) ?? '');
    if (!preg_match('/^[0-9a-f]{32}$/', $hex)) {
        return '';
    }
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

/** Enregistre jeton + base ; renvoie false si le dossier .secrets n'est pas inscriptible. */
function notion_todo_config_save(string $token, string $database): bool
{
    $token = trim($token);
    $database = notion_todo_database_id($database);
    if (!preg_match('/^(secret_|ntn_)[A-Za-z0-9]{20,200}$/', $token) || $database === '') {
        throw new InvalidArgumentException('Jeton Notion ou identifiant de base non valide.');
    }
    return notion_todo_write_json(notion_todo_config_file(), [
        'token' => $token,
        'database_id' => $database,
        'saved_at' => notion_todo_now(),
    ], 0600);
}

function notion_todo_config_delete(): bool
{
    $file = notion_todo_config_file();
    return !is_file($file) || @unlink($file);
}

/** Jeton de connexion masqué pour l'affichage (jamais le jeton complet). */
function notion_todo_token_preview(): string
{
    $cfg = notion_todo_config();
    if (!$cfg) {
        return '';
    }
    return substr($cfg['token'], 0, 8) . '…' . substr($cfg['token'], -4);
}

/**
 * Publie un retour dans Notion. Ne lève jamais : l'échec est journalisé et le
 * retour est mis en file pour une nouvelle tentative. Renvoie l'identifiant de
 * la page Notion créée ou retrouvée, null sinon.
 */
function notion_todo_import(array $record, ?callable $transport = null, ?array $config = null): ?string
{
    $cfg = $config ?? notion_todo_config();
    if (!$cfg) {
        return null;
    }
    $ref = (string) ($record['id'] ?? '');
    try {
        notion_todo_flush($transport, $cfg);
        $pageId = notion_todo_push($record, $transport, $cfg);
        if ($pageId !== '') {
            notion_todo_state_save(['last_ok_at' => notion_todo_now(), 'last_ref' => $ref, 'last_error' => '', 'last_error_at' => '']);
        }
        return $pageId !== '' ? $pageId : null;
    } catch (Throwable $e) {
        notion_todo_pending_add($record, $e->getMessage());
        notion_todo_state_save(['last_error' => mb_substr($e->getMessage(), 0, 300), 'last_error_at' => notion_todo_now(), 'last_ref' => $ref]);
        error_log('notion-todo: ' . $e->getMessage());
        return null;
    }
}

/**
 * Cœur de l'envoi : '', si le retour ne doit pas être publié (référence
 * inconnue, message trop court) ou s'il a déjà été publié ; sinon l'id de la
 * page créée. Lève une exception en cas d'échec réseau ou d'API.
 */
function notion_todo_push(array $record, ?callable $transport, array $cfg): string
{
    $ref = (string) ($record['id'] ?? '');
    if (!preg_match('/^fb\d{6}-[a-f0-9]{8}$/', $ref)) {
        return '';
    }
    $message = trim(preg_replace('/\s+/u', ' ', (string) ($record['message'] ?? '')) ?? '');
    if (mb_strlen($message) < 15) {
        return '';
    }
    $existing = notion_todo_existing_page($ref, $transport, $cfg);
    if ($existing !== '') {
        return $existing;
    }
    $payload = notion_todo_payload($record, $message, (string) $cfg['database_id']);
    $res = notion_todo_client('POST', '/v1/pages', $payload, $cfg, $transport);
    if ($res['status'] !== 200 || !is_array($res['json'])) {
        throw new RuntimeException('Création refusée par Notion' . notion_todo_error_suffix($res));
    }
    return (string) ($res['json']['id'] ?? '');
}

/** Page déjà créée pour ce retour (recherche dans le critère de done), ou ''. */
function notion_todo_existing_page(string $ref, ?callable $transport, array $cfg): string
{
    $res = notion_todo_client('POST', '/v1/databases/' . $cfg['database_id'] . '/query', [
        'filter' => ['property' => 'Critère de done', 'rich_text' => ['contains' => $ref]],
        'page_size' => 1,
    ], $cfg, $transport);
    if ($res['status'] !== 200 || !is_array($res['json'])) {
        throw new RuntimeException('Lecture de la base refusée' . notion_todo_error_suffix($res));
    }
    $first = $res['json']['results'][0] ?? null;
    return is_array($first) && !empty($first['id']) ? (string) $first['id'] : '';
}

function notion_todo_error_suffix(array $res): string
{
    $code = (string) ($res['json']['code'] ?? '');
    $message = (string) ($res['json']['message'] ?? $res['error'] ?? '');
    $text = trim(($code !== '' ? $code . ' — ' : '') . $message);
    return $text !== '' ? ' : ' . mb_substr($text, 0, 220) : ' (HTTP ' . (int) $res['status'] . ')';
}

/** Propriétés Notion d'un retour. */
function notion_todo_payload(array $record, string $message, string $databaseId): array
{
    return [
        'parent' => ['database_id' => $databaseId],
        'properties' => [
            'Jalon' => ['title' => [['type' => 'text', 'text' => ['content' => notion_todo_title($record, $message)]]]],
            'Critère de done' => ['rich_text' => [['type' => 'text', 'text' => ['content' => notion_todo_criterion($record, $message)]]]],
            'Domaine' => ['select' => ['name' => notion_todo_domain($record, $message)]],
            'Priorité' => ['select' => ['name' => notion_todo_priority($record)]],
            'Statut' => ['status' => ['name' => 'À faire']],
        ],
    ];
}

function notion_todo_title(array $record, string $message): string
{
    $labels = defined('FEEDBACK_TYPE_LABELS') ? FEEDBACK_TYPE_LABELS : [
        'bug' => 'Bug', 'idea' => 'Idée', 'improvement' => 'Amélioration', 'other' => 'Autre',
    ];
    $label = $labels[(string) ($record['type'] ?? 'other')] ?? 'Retour';
    $snippet = mb_substr($message, 0, 90);
    if (mb_strlen($message) > 90) {
        $cut = mb_strrpos($snippet, ' ');
        if ($cut !== false && $cut > 50) {
            $snippet = mb_substr($snippet, 0, $cut);
        }
        $snippet .= '…';
    }
    return mb_substr($label . ' — ' . $snippet . ' (' . (string) $record['id'] . ')', 0, 200);
}

function notion_todo_criterion(array $record, string $message): string
{
    $ref = (string) $record['id'];
    $ts = isset($record['at']) ? strtotime((string) $record['at']) : false;
    $date = $ts
        ? (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i')
        : 'date inconnue';
    $kind = (string) ($record['source'] ?? '') === 'client' ? 'client' : 'testeur';
    $shop = trim((string) ($record['shop_name'] ?? '')) ?: (string) ($record['shop'] ?? '');
    $page = trim((string) ($record['url'] ?? '')) ?: trim((string) ($record['page'] ?? ''));
    $lines = [
        "Retour {$kind} du {$date} ({$ref}" . ($shop !== '' ? ", boutique {$shop}" : '') . ').',
        '« ' . mb_substr($message, 0, 900) . ' »',
    ];
    if ($page !== '') {
        $lines[] = 'Page : ' . $page;
    }
    $lines[] = "À qualifier : préciser l'action attendue, le domaine et le critère de clôture. Créé automatiquement depuis le module Feedback. Retour {$ref}.";
    return mb_substr(implode("\n", $lines), 0, 1900);
}

/** Domaine de pilotage déduit du texte : Technique par défaut. */
function notion_todo_domain(array $record, string $message): string
{
    $hay = ' ' . mb_strtolower($message . ' ' . (string) ($record['module'] ?? '') . ' ' . (string) ($record['page'] ?? '')) . ' ';
    $rules = [
        'Pricing' => ['prix', 'tarif', 'abonnement', 'pack ', 'packs ', 'commission', 'gratuit', 'payant', 'offre ', 'offres '],
        'Commercial' => ['prospection', 'commercial', ' marketing', 'communication', 'annonce', 'leboncoin', 'vinted', 'facebook marketplace', 'campagne'],
        'Ops' => ['déploiement', 'deploiement', 'serveur', 'hébergement', 'hebergement', 'ovh', 'sauvegarde', 'domaine', 'cron', 'certificat'],
        'Testing' => ['recette', ' à tester', 'test ', 'vérifier', 'verifier', 'plantage'],
    ];
    foreach ($rules as $domain => $words) {
        foreach ($words as $word) {
            if (str_contains($hay, $word)) {
                return $domain;
            }
        }
    }
    return 'Technique';
}

/** P0 bloquant, P1 gênant, P2 le reste — la priorité du retour reste tranchée à la main. */
function notion_todo_priority(array $record): string
{
    if (($record['severity'] ?? '') === 'blocker' || in_array((string) ($record['priority'] ?? ''), ['high', 'urgent'], true)) {
        return 'P0';
    }
    return ($record['severity'] ?? '') === 'major' ? 'P1' : 'P2';
}

/**
 * Appel HTTP vers l'API Notion. Le transport est injectable pour les tests :
 * callable(string $method, string $url, ?array $body, array $headers, int $timeout)
 * renvoyant ['status' => int, 'json' => ?array, 'error' => ?string].
 */
function notion_todo_client(string $method, string $path, ?array $body, array $cfg, ?callable $transport): array
{
    $headers = [
        'Authorization: Bearer ' . $cfg['token'],
        'Notion-Version: 2022-06-28',
        'Content-Type: application/json',
    ];
    $handler = $transport ?? 'notion_todo_curl';
    return $handler($method, 'https://api.notion.com' . $path, $body, $headers, 8);
}

function notion_todo_curl(string $method, string $url, ?array $body, array $headers, int $timeout): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL indisponible sur ce serveur.');
    }
    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $raw === false ? (string) curl_error($ch) : '';
    $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'error' => $error];
}

/** Vérifie jeton et accès à la base : titre de la base et nombre de propriétés. */
function notion_todo_test(?callable $transport = null, ?array $config = null): array
{
    $cfg = $config ?? notion_todo_config();
    if (!$cfg) {
        return ['ok' => false, 'message' => "Aucun jeton enregistré : l'envoi Notion est désactivé."];
    }
    try {
        $res = notion_todo_client('GET', '/v1/databases/' . $cfg['database_id'], null, $cfg, $transport);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    if ($res['status'] !== 200 || !is_array($res['json'])) {
        return ['ok' => false, 'message' => notion_todo_error_suffix($res)];
    }
    $title = '';
    foreach (($res['json']['title'] ?? []) as $part) {
        $title .= (string) ($part['plain_text'] ?? '');
    }
    $count = is_array($res['json']['properties'] ?? null) ? count($res['json']['properties']) : 0;
    return [
        'ok' => true,
        'message' => 'Connexion réussie : base « ' . ($title !== '' ? $title : 'sans titre') . ' », ' . $count . ' propriétés accessibles.',
    ];
}

// ── File d'attente et état ──────────────────────────────────────────────────

/** Réessaie les retours en attente (au plus 3 par appel). Renvoie le nombre publié. */
function notion_todo_flush(?callable $transport = null, ?array $config = null, int $limit = 3): int
{
    $cfg = $config ?? notion_todo_config();
    if (!$cfg || !is_file(notion_todo_pending_file())) {
        return 0;
    }
    $items = notion_todo_pending_load();
    if (!$items) {
        return 0;
    }
    $done = 0;
    $tried = 0;
    $keep = [];
    foreach ($items as $item) {
        if ($tried >= $limit) {
            $keep[] = $item;
            continue;
        }
        $record = is_array($item['record'] ?? null) ? $item['record'] : null;
        if ($record === null) {
            continue;
        }
        $tried++;
        try {
            if (notion_todo_push($record, $transport, $cfg) !== '') {
                $done++;
                continue;
            }
            // Retour ignoré (trop court, référence inconnue) : inutile de réessayer.
            continue;
        } catch (Throwable $e) {
            $item['attempts'] = (int) ($item['attempts'] ?? 0) + 1;
            $item['last_error'] = mb_substr($e->getMessage(), 0, 300);
            if ($item['attempts'] < 5) {
                $keep[] = $item;
            } else {
                error_log('notion-todo: abandon après 5 tentatives (' . (string) ($record['id'] ?? '?') . ')');
            }
        }
    }
    notion_todo_pending_save($keep);
    return $done;
}

function notion_todo_pending_add(array $record, string $error): void
{
    $ref = (string) ($record['id'] ?? '');
    if (!preg_match('/^fb\d{6}-[a-f0-9]{8}$/', $ref)) {
        return;
    }
    $items = notion_todo_pending_load();
    foreach ($items as $item) {
        if ((string) ($item['record']['id'] ?? '') === $ref) {
            return;
        }
    }
    if (count($items) >= 100) {
        array_shift($items);
    }
    $items[] = [
        'record' => notion_todo_record_min($record),
        'attempts' => 1,
        'first_at' => notion_todo_now(),
        'last_error' => mb_substr($error, 0, 300),
    ];
    notion_todo_pending_save($items);
}

/** Champs utiles pour republier un retour plus tard (pas d'UA ni de pièce jointe). */
function notion_todo_record_min(array $record): array
{
    $out = [];
    foreach (['id', 'at', 'shop', 'shop_name', 'source', 'type', 'severity', 'priority', 'message', 'page', 'url', 'module'] as $key) {
        if (isset($record[$key])) {
            $out[$key] = $record[$key];
        }
    }
    return $out;
}

function notion_todo_pending_load(): array
{
    $raw = @file_get_contents(notion_todo_pending_file());
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
}

function notion_todo_pending_save(array $items): void
{
    if (!$items) {
        @unlink(notion_todo_pending_file());
        return;
    }
    notion_todo_write_json(notion_todo_pending_file(), $items, 0600);
}

function notion_todo_state(): array
{
    $raw = @file_get_contents(notion_todo_state_file());
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function notion_todo_state_save(array $patch): void
{
    notion_todo_write_json(notion_todo_state_file(), array_merge(notion_todo_state(), $patch), 0600);
}

function notion_todo_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
}

/** Écriture JSON atomique (fichier temporaire + renommage) avec permissions explicites. */
function notion_todo_write_json(string $file, array $data, int $mode): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($file, $mode);
    return true;
}
