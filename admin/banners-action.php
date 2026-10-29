<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/autofeed.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/banners.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$pageKey = (string) ($_POST['page_key'] ?? '');

if ($action !== 'reserve_from_library' && !array_key_exists($pageKey, page_banner_targets())) {
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
            media_library_register($media['path'], 'Bandeau — ' . (page_banner_targets()[$pageKey] ?? $pageKey), 'Bandeau', 'bandeau', $media['type']);
            flash_set('Bandeau mis à jour et ajouté à la médiathèque.');
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

    case 'set_from_library': {
        $file = (string) ($_POST['file'] ?? '');
        $item = banner_library_find($file);
        if ($item === null) {
            flash_set('Bandeau introuvable dans la médiathèque commune.', 'error');
            break;
        }
        if (!empty($item['exclusive']) && (string) ($item['exclusive_by'] ?? '') !== tenant_slug()) {
            flash_set('Cette illustration est réservée à une autre boutique.', 'error');
            break;
        }
        $overlay = max(0, min(100, (int) ($_POST['overlay'] ?? 60)));
        $path = 'uploads/banner-library/' . $file;
        set_page_banner($pageKey, 'image', $path, $overlay);
        media_library_register($path, 'Bandeau partagé — ' . (page_banner_targets()[$pageKey] ?? $pageKey), 'Médiathèque bandeaux', 'bandeau');
        flash_set('Bandeau appliqué depuis la médiathèque commune.');
        break;
    }

    case 'reserve_from_library': {
        $file = (string) ($_POST['file'] ?? '');
        $item = banner_library_find($file);
        if ($item === null) {
            flash_set('Bandeau introuvable dans la médiathèque commune.', 'error');
            break;
        }
        if (!empty($item['exclusive'])) {
            flash_set('Cette illustration est déjà réservée.', 'error');
            break;
        }
        try {
            if ($gate = ai_gate()) throw new RuntimeException($gate);
            if (!banner_library_reserve($file)) throw new RuntimeException('La réservation a échoué, rien n’a été débité.');
            ai_usage_log_image();   // exclusivité : une image décomptée du crédit IA
            if (function_exists('ai_check_low_balance_alert')) ai_check_low_balance_alert('gemini');
            flash_set('Illustration réservée à votre boutique (' . ai_format_eur(autofeed_regen_cost_eur()) . ' décomptés de votre crédit IA). Elle n’apparaît plus chez les autres boutiques.');
        } catch (Throwable $e) {
            flash_set($e instanceof RuntimeException ? $e->getMessage() : 'La réservation a échoué, rien n’a été débité.', 'error');
        }
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
