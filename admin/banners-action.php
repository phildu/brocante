<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/banners.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$pageKey = (string) ($_POST['page_key'] ?? '');

if (!array_key_exists($pageKey, page_banner_targets())) {
    flash_set('Page inconnue.', 'error');
    header('Location: /admin/banners.php');
    exit;
}

switch ($action) {

    case 'set': {
        $overlay = max(0, min(100, (int) ($_POST['overlay'] ?? 60)));
        $existing = get_page_banner($pageKey);

        if (!empty($_FILES['media']['tmp_name']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
            $media = store_uploaded_media('media', 'banner-' . $pageKey);
            if (!$media) {
                flash_set("Impossible d'ajouter ce fichier (format non reconnu).", 'error');
                break;
            }
            set_page_banner($pageKey, $media['type'], $media['path'], $overlay);
            flash_set('Bandeau mis à jour.');
        } elseif ($existing) {
            // Pas de nouveau fichier : on ne fait que mettre à jour le voile.
            set_page_banner_overlay($pageKey, $overlay);
            flash_set('Réglage enregistré.');
        } else {
            flash_set('Choisissez un fichier à envoyer.', 'error');
        }
        break;
    }

    case 'set_from_media': {
        $path = trim((string) ($_POST['path'] ?? ''));
        $overlay = max(0, min(100, (int) ($_POST['overlay'] ?? 60)));
        $stmt = db()->prepare('SELECT path, type FROM media_library WHERE path = ? LIMIT 1');
        $stmt->execute([$path]);
        $row = $stmt->fetch();
        if (!$row) {
            flash_set('Média introuvable dans la médiathèque.', 'error');
            break;
        }
        set_page_banner($pageKey, $row['type'], $row['path'], $overlay);
        flash_set('Bandeau mis à jour.');
        break;
    }

    case 'delete': {
        delete_page_banner($pageKey);
        flash_set('Bandeau retiré.');
        break;
    }
}

header('Location: /admin/banners.php');
exit;
