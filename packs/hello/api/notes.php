<?php
// GET : notes de la boutique (les plus récentes d'abord). POST {text} : ajoute une note (200 caractères au plus).
defined('PLUGIN_RUNTIME') || exit;

if ($method === 'POST') {
    $text = trim((string) (plugin_input()['text'] ?? ''));
    if ($text === '' || mb_strlen($text) > 200) plugin_json(['error' => 'Note vide ou trop longue (200 caractères au plus).'], 422);
    db()->prepare('INSERT INTO hello_notes (text, author) VALUES (?, ?)')->execute([$text, $session['name'] !== '' ? $session['name'] : 'Compte principal']);
}
$notes = db()->query('SELECT id, text, author, created_at FROM hello_notes ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
plugin_json(['notes' => $notes, 'can_delete' => $role === 'admin']);
