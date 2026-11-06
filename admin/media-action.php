<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/media.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');

switch ($action) {

    case 'add': {
        $slug = trim((string) ($_POST['filename_slug'] ?? ''));
        $baseName = $slug !== '' ? slugify($slug) : 'media';
        $result = store_uploaded_media('media', $baseName);
        if (!$result) {
            flash_set("Impossible d'ajouter ce fichier (format non reconnu — image ou vidéo mp4/mov/webm attendue).", 'error');
            break;
        }
        $label = trim((string) ($_POST['label'] ?? '')) ?: ($result['type'] === 'video' ? 'Vidéo' : 'Photo');
        $source = trim((string) ($_POST['source'] ?? ''));
        $tags = trim((string) ($_POST['tags'] ?? ''));
        add_media_item($result['type'], $result['path'], $label, $source, $tags);
        flash_set('Ressource ajoutée à la médiathèque.');
        break;
    }

    case 'update': {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $source = trim((string) ($_POST['source'] ?? ''));
        $tags = trim((string) ($_POST['tags'] ?? ''));
        $stmt = db()->prepare('UPDATE media_library SET label = ?, source = ?, tags = ? WHERE id = ?');
        $stmt->execute([$label, $source, $tags, $id]);
        flash_set('Ressource mise à jour.');
        break;
    }

    case 'delete': {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM media_library WHERE id = ?');
        $stmt->execute([$id]);
        flash_set('Ressource retirée de la médiathèque.');
        break;
    }

    case 'bulk_delete': {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if (!$ids) {
            flash_set('Aucune ressource cochée.', 'error');
            break;
        }
        $stmt = db()->prepare('DELETE FROM media_library WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $n = $stmt->rowCount();
        flash_set($n . ' ressource' . ($n > 1 ? 's retirées' : ' retirée') . ' de la médiathèque.');
        break;
    }
}

// Retour à la vue d'où vient l'action (liste, filtres), jamais vers un autre site.
$back = app_unprefix((string) ($_POST['back'] ?? ''));
header('Location: ' . (str_starts_with($back, '/admin/media.php') ? $back : '/admin/media.php'));
exit;
