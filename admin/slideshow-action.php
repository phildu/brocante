<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/slideshow.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');

switch ($action) {

    case 'add_media': {
        $media = store_uploaded_media('media', 'slideshow');
        if (!$media) {
            flash_set("Impossible d'ajouter ce fichier (format non reconnu).", 'error');
            break;
        }
        $kind = $media['type'] === 'video' ? 'video' : 'ambiance';
        $duration = max(0, (int) ($_POST['duration_seconds'] ?? 8));
        add_slideshow_slide($kind, $media['path'], null, trim((string) ($_POST['caption'] ?? '')), $duration);
        flash_set('Média ajouté au diaporama.');
        break;
    }

    case 'add_from_media': {
        $path = trim((string) ($_POST['path'] ?? ''));
        $stmt = db()->prepare('SELECT path, type FROM media_library WHERE path = ? LIMIT 1');
        $stmt->execute([$path]);
        $row = $stmt->fetch();
        if (!$row) {
            flash_set('Média introuvable dans la médiathèque.', 'error');
            break;
        }
        $kind = $row['type'] === 'video' ? 'video' : 'ambiance';
        add_slideshow_slide($kind, $row['path'], null, trim((string) ($_POST['caption'] ?? '')), 8);
        flash_set('Média ajouté au diaporama.');
        break;
    }

    case 'add_product': {
        $ref = trim((string) ($_POST['ref'] ?? ''));
        $product = $ref !== '' ? get_product($ref) : null;
        if (!$product) {
            flash_set('Produit introuvable.', 'error');
            break;
        }
        $duration = max(1, (int) ($_POST['duration_seconds'] ?? 8));
        add_slideshow_slide('product', null, $product['ref'], '', $duration);
        flash_set('Fiche produit ajoutée au diaporama.');
        break;
    }

    case 'update': {
        $id = (int) ($_POST['id'] ?? 0);
        $caption = trim((string) ($_POST['caption'] ?? ''));
        $duration = max(0, (int) ($_POST['duration_seconds'] ?? 8));
        $stmt = db()->prepare('UPDATE slideshow_slides SET caption = ?, duration_seconds = ? WHERE id = ?');
        $stmt->execute([$caption, $duration, $id]);
        flash_set('Diapositive mise à jour.');
        break;
    }

    case 'delete': {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM slideshow_slides WHERE id = ?')->execute([$id]);
        flash_set('Diapositive retirée.');
        break;
    }

    case 'move_up':
    case 'move_down': {
        $id = (int) ($_POST['id'] ?? 0);
        $rows = slideshow_slides_list();
        $index = null;
        foreach ($rows as $i => $r) if ((int) $r['id'] === $id) { $index = $i; break; }
        if ($index === null) break;
        $swapWith = $action === 'move_up' ? $index - 1 : $index + 1;
        if ($swapWith < 0 || $swapWith >= count($rows)) break;
        $a = $rows[$index]; $b = $rows[$swapWith];
        $stmt = db()->prepare('UPDATE slideshow_slides SET sort_order = ? WHERE id = ?');
        $stmt->execute([$b['sort_order'], $a['id']]);
        $stmt->execute([$a['sort_order'], $b['id']]);
        break;
    }

    case 'set_orientation': {
        $orientation = ($_POST['orientation'] ?? '') === 'vertical' ? 'vertical' : 'horizontal';
        db()->prepare('UPDATE content SET slideshow_orientation = ? WHERE id = 1')->execute([$orientation]);
        flash_set('Réglage enregistré.');
        break;
    }
}

header('Location: /admin/slideshow.php');
exit;
