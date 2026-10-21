<?php
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/settings.php';

$channels = ['leboncoin', 'vinted'];
$statuses = ['draft', 'published', 'error', 'removed'];

if ($method === 'GET') {
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 40)));
    $rows = db()->query("SELECT id, product_ref, channel, status, listing_url, external_id, note, published_at, updated_at, created_at
        FROM am_listings ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    plugin_json(['listings' => $rows]);
}

$in = plugin_input();
$ref = trim((string) ($in['product_ref'] ?? $in['ref'] ?? ''));
$channel = strtolower(trim((string) ($in['channel'] ?? '')));
$status = strtolower(trim((string) ($in['status'] ?? 'published')));
$url = trim((string) ($in['listing_url'] ?? $in['url'] ?? ''));
$external = trim((string) ($in['external_id'] ?? ''));
$note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 400);

if ($ref === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ref)) {
    plugin_json(['error' => 'Référence produit manquante ou invalide.'], 422);
}
if (!in_array($channel, $channels, true)) {
    plugin_json(['error' => 'Canal inconnu (leboncoin ou vinted).'], 422);
}
if (!in_array($status, $statuses, true)) {
    plugin_json(['error' => 'Statut invalide.'], 422);
}
if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    plugin_json(['error' => 'URL d\'annonce invalide.'], 422);
}

$exists = db()->prepare('SELECT 1 FROM products WHERE ref = ?');
$exists->execute([$ref]);
if (!$exists->fetchColumn()) plugin_json(['error' => 'Produit introuvable.'], 404);

$id = (int) ($in['id'] ?? 0);
$publishedAt = $status === 'published' ? gmdate('c') : null;

if ($id > 0) {
    $stmt = db()->prepare('UPDATE am_listings SET status = ?, listing_url = ?, external_id = ?, note = ?, published_at = COALESCE(?, published_at), updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$status, $url, $external, $note, $publishedAt, $id]);
    if ($stmt->rowCount() === 0) plugin_json(['error' => 'Annonce introuvable.'], 404);
} else {
    db()->prepare('INSERT INTO am_listings (product_ref, channel, status, listing_url, external_id, note, published_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$ref, $channel, $status, $url, $external, $note, $publishedAt]);
    $id = (int) db()->lastInsertId();
}

$row = db()->prepare('SELECT * FROM am_listings WHERE id = ?');
$row->execute([$id]);
plugin_json(['ok' => true, 'listing' => $row->fetch(PDO::FETCH_ASSOC)]);
