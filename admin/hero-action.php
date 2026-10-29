<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/hero.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');

switch ($action) {

    case 'add': {
        $path = store_uploaded_photo('photo', 'hero-slide');
        if (!$path) {
            flash_set("Impossible d'ajouter cette photo (format non reconnu).", 'error');
            break;
        }
        $caption = trim((string) ($_POST['caption'] ?? ''));
        add_hero_slide($path, $caption);
        media_library_register($path, $caption !== '' ? $caption : "Slide page d'accueil", 'Diaporama accueil', 'diaporama, accueil');
        flash_set('Photo ajoutée au diaporama et à la médiathèque.');
        break;
    }

    case 'add_multi': {
        $count = max(1, min(10, (int) ($_POST['count'] ?? 1)));
        $caption = trim((string) ($_POST['caption'] ?? ''));
        $added = 0;
        for ($i = 0; $i < $count; $i++) {
            $path = store_uploaded_photo('photo_' . $i, 'hero-slide');
            if (!$path) continue;
            add_hero_slide($path, $caption);
            media_library_register($path, $caption !== '' ? $caption : "Slide page d'accueil", 'Diaporama accueil', 'diaporama, accueil');
            $added++;
        }
        if ($added === 0) {
            flash_set('Choisissez au moins une photo à envoyer.', 'error');
            break;
        }
        flash_set($added . ' photo' . ($added > 1 ? 's ajoutées' : ' ajoutée') . ' au diaporama et à la médiathèque.');
        break;
    }

    case 'add_from_media': {
        $path = trim((string) ($_POST['path'] ?? ''));
        $stmt = db()->prepare("SELECT path FROM media_library WHERE path = ? AND type = 'photo' LIMIT 1");
        $stmt->execute([$path]);
        $verified = $stmt->fetchColumn();
        if (!$verified) {
            flash_set('Photo introuvable dans la médiathèque.', 'error');
            break;
        }
        add_hero_slide($verified, trim((string) ($_POST['caption'] ?? '')));
        flash_set('Photo ajoutée au diaporama.');
        break;
    }

    case 'caption': {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('UPDATE hero_slides SET caption = ? WHERE id = ?');
        $stmt->execute([trim((string) ($_POST['caption'] ?? '')), $id]);
        flash_set('Légende mise à jour.');
        break;
    }

    case 'delete': {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM hero_slides WHERE id = ?')->execute([$id]);
        flash_set('Photo retirée du diaporama.');
        break;
    }

    case 'move_up':
    case 'move_down': {
        $id = (int) ($_POST['id'] ?? 0);
        $rows = hero_slides_list();
        $index = null;
        foreach ($rows as $i => $r) if ((int) $r['id'] === $id) { $index = $i; break; }
        if ($index === null) break;
        $swapWith = $action === 'move_up' ? $index - 1 : $index + 1;
        if ($swapWith < 0 || $swapWith >= count($rows)) break;
        $a = $rows[$index]; $b = $rows[$swapWith];
        $stmt = db()->prepare('UPDATE hero_slides SET sort_order = ? WHERE id = ?');
        $stmt->execute([$b['sort_order'], $a['id']]);
        $stmt->execute([$a['sort_order'], $b['id']]);
        break;
    }
}

header('Location: /admin/hero.php');
exit;
