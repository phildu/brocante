<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/catalog.php');
    exit;
}

$ref = (string) ($_POST['ref'] ?? '');
$product = get_product($ref);
$action = (string) ($_POST['action'] ?? '');
$redirect = '/admin/gallery.php?ref=' . urlencode($ref);

if (!$product) {
    flash_set('Pièce introuvable.', 'error');
    header('Location: /admin/catalog.php');
    exit;
}

function get_photo_row(string $ref, int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM product_photos WHERE id = ? AND product_ref = ?');
    $stmt->execute([$id, $ref]);
    return $stmt->fetch() ?: null;
}

switch ($action) {

    case 'add': {
        $path = store_uploaded_photo('photo', 'product-' . $ref);
        if (!$path) {
            flash_set("Impossible d'ajouter cette photo (format non reconnu).", 'error');
            break;
        }
        $label = trim((string) ($_POST['label'] ?? '')) ?: 'Photo';
        add_product_photo($ref, $path, $label, false);
        flash_set('Photo ajoutée.');
        break;
    }

    case 'toggle_hidden': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $hidden = !empty($_POST['hidden']) ? 1 : 0;
        $stmt = db()->prepare('UPDATE product_photos SET is_hidden = ? WHERE id = ? AND product_ref = ?');
        $stmt->execute([$hidden, $id, $ref]);
        sync_cover_photo($ref);
        flash_set($hidden ? 'Photo masquée — elle ne sera plus visible sur la fiche publique.' : 'Photo réaffichée.');
        break;
    }

    case 'rename': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        if ($label === '') {
            flash_set('Le nom ne peut pas être vide.', 'error');
            break;
        }
        $stmt = db()->prepare('UPDATE product_photos SET label = ? WHERE id = ? AND product_ref = ?');
        $stmt->execute([$label, $id, $ref]);
        flash_set('Photo renommée.');
        break;
    }

    case 'replace': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $row = get_photo_row($ref, $id);
        if (!$row) { flash_set('Photo introuvable.', 'error'); break; }
        $path = store_uploaded_photo('photo', 'product-' . $ref);
        if (!$path) { flash_set('Impossible de charger cette photo.', 'error'); break; }
        $stmt = db()->prepare('UPDATE product_photos SET path = ?, is_illustration = 0 WHERE id = ? AND product_ref = ?');
        $stmt->execute([$path, $id, $ref]);
        sync_cover_photo($ref);
        flash_set('Photo remplacée.');
        break;
    }

    case 'delete': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM product_photos WHERE id = ? AND product_ref = ?');
        $stmt->execute([$id, $ref]);
        sync_cover_photo($ref);
        flash_set('Photo supprimée.');
        break;
    }

    case 'move_up':
    case 'move_down': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $rows = product_photos_list($ref);
        $index = null;
        foreach ($rows as $i => $r) if ((int) $r['id'] === $id) { $index = $i; break; }
        if ($index === null) break;
        $swapWith = $action === 'move_up' ? $index - 1 : $index + 1;
        if ($swapWith < 0 || $swapWith >= count($rows)) break;

        $a = $rows[$index]; $b = $rows[$swapWith];
        $stmt = db()->prepare('UPDATE product_photos SET sort_order = ? WHERE id = ?');
        $stmt->execute([$b['sort_order'], $a['id']]);
        $stmt->execute([$a['sort_order'], $b['id']]);
        sync_cover_photo($ref);
        break;
    }

    case 'crop': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $row = get_photo_row($ref, $id);
        $dataUrl = (string) ($_POST['image_data'] ?? '');
        if (!$row || !preg_match('/^data:image\/(jpeg|png);base64,(.+)$/', $dataUrl, $m)) {
            flash_set('Recadrage impossible.', 'error');
            break;
        }
        $binary = base64_decode($m[2]);
        $path = save_binary_photo($binary, 'product-' . $ref . '-recadre', 'jpg');
        if (!$path) { flash_set('Recadrage impossible.', 'error'); break; }
        $stmt = db()->prepare('UPDATE product_photos SET path = ? WHERE id = ? AND product_ref = ?');
        $stmt->execute([$path, $id, $ref]);
        sync_cover_photo($ref);
        flash_set('Photo recadrée.');
        break;
    }

    case 'rotate': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $row = get_photo_row($ref, $id);
        if (!$row || ($row['type'] ?? 'photo') !== 'photo') {
            flash_set('Photo introuvable.', 'error');
            break;
        }
        if (!rotate_photo_clockwise(__DIR__ . '/../' . $row['path'])) {
            flash_set('Rotation impossible.', 'error');
            break;
        }
        flash_set('Photo tournée de 90°.');
        break;
    }

    case 'export_formats': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $row = get_photo_row($ref, $id);
        if (!$row || ($row['type'] ?? 'photo') !== 'photo') {
            flash_set('Photo introuvable.', 'error');
            break;
        }
        $srcAbs = __DIR__ . '/../' . $row['path'];
        $count = generate_media_export_variants($srcAbs, $product['name'] . ' — ' . $row['label'], $ref, $product['name']);
        flash_set($count > 0
            ? "$count formats (catalogue, produit, diaporama, format réel, post, story) ajoutés à la médiathèque."
            : 'Impossible de générer les formats.', $count > 0 ? 'ok' : 'error');
        break;
    }

    case 'detail': {
        $dataUrl = (string) ($_POST['image_data'] ?? '');
        if (!preg_match('/^data:image\/(jpeg|png);base64,(.+)$/', $dataUrl, $m)) {
            flash_set('Recadrage du détail impossible.', 'error');
            break;
        }
        $ratioLabel = trim((string) ($_POST['ratio_label'] ?? ''));
        $enhance = !empty($_POST['enhance']);
        if ($enhance && !GEMINI_API_KEY) {
            flash_set("Clé Gemini absente dans config.php — le détail sera enregistré sans amélioration IA.", 'error');
            $enhance = false;
        }
        $binary = base64_decode($m[2]);
        $path = save_binary_photo($binary, 'product-' . $ref . '-detail', 'jpg');
        if (!$path) { flash_set('Recadrage du détail impossible.', 'error'); break; }

        $label = 'Détail' . ($ratioLabel !== '' ? " ($ratioLabel)" : '');
        $photoId = add_product_photo($ref, $path, $label, false);

        if (!$enhance) {
            flash_set('Détail enregistré.');
            break;
        }

        // Synchrone : un hébergement mutualisé (OVH inclus) désactive
        // exec()/shell_exec(), donc pas de tâche de fond possible — voir
        // includes/functions.php::downscale_for_ai() pour pourquoi ça reste
        // rapide malgré tout.
        $srcAbs = __DIR__ . '/../' . $path;
        $small = downscale_for_ai($srcAbs) ?? $srcAbs;
        $bytes = gemini_generate_image($small, build_detail_enhance_prompt(), 1);
        if ($small !== $srcAbs) @unlink($small);

        if (!$bytes) {
            flash_set('Détail enregistré, mais l’amélioration IA a échoué (service indisponible ou surchargé) — réessayez depuis la galerie.', 'error');
            break;
        }
        $enhancedPath = save_binary_photo($bytes, 'product-' . $ref . '-detail-ameliore', 'jpg');
        if (!$enhancedPath) {
            flash_set("Détail enregistré, mais l'enregistrement de la version améliorée a échoué.", 'error');
            break;
        }
        $upd = db()->prepare('UPDATE product_photos SET path = ? WHERE id = ?');
        $upd->execute([$enhancedPath, $photoId]);
        flash_set('Détail enregistré et amélioré par l’IA.');
        break;
    }

    case 'generate': {
        $kind = (string) ($_POST['kind'] ?? '');
        if (!in_array($kind, ['detoure', 'ambiance', 'angle', 'complete', 'video'], true)) break;

        $sourcePhotoId = (int) ($_POST['source_photo_id'] ?? 0);
        $anglePreset = (string) ($_POST['angle_preset'] ?? 'auto');
        $keywords = trim((string) ($_POST['keywords'] ?? ''));

        $sourceRow = $sourcePhotoId ? get_photo_row($ref, $sourcePhotoId) : null;
        $sourcePath = $sourceRow['path'] ?? $product['photo'];
        if (!$sourcePath) {
            flash_set("Cette pièce n'a pas encore de photo à partir de laquelle générer.", 'error');
            break;
        }

        if ($kind === 'video') {
            // Effet Ken Burns via ffmpeg : rapide (1-3s), pas d'IA — exécuté
            // directement dans la requête, pas besoin de tâche de fond.
            $videoEffect = (string) ($_POST['video_effect'] ?? 'zoom_in');
            if (!array_key_exists($videoEffect, video_effects())) $videoEffect = 'zoom_in';
            $srcAbs = __DIR__ . '/../' . $sourcePath;
            $path = run_ken_burns_video($srcAbs, $videoEffect);
            if (!$path) {
                flash_set('La génération de la vidéo a échoué.', 'error');
                break;
            }
            add_product_photo($ref, $path, video_effects()[$videoEffect], false, 'video');
            flash_set('Vidéo générée et ajoutée à la galerie.');
            break;
        }

        if ($kind === 'detoure') {
            // Rembg (Python) tourne en local via exec() — vrai détourage,
            // fond réellement transparent (PNG + canal alpha). Reste le
            // choix par défaut quand disponible (meilleure qualité).
            if (shell_exec_available() && PHP_CLI_BIN && PYTHON_BIN) {
                $logDir = __DIR__ . '/../var/log';
                if (!is_dir($logDir)) mkdir($logDir, 0755, true);
                $logFile = $logDir . '/generate-' . $ref . '-detoure-' . time() . '.log';
                $cmd = '/usr/bin/nohup ' . escapeshellarg(PHP_CLI_BIN) . ' ' . escapeshellarg(__DIR__ . '/cli/generate.php') . ' '
                    . escapeshellarg($ref) . ' ' . escapeshellarg('detoure') . ' '
                    . escapeshellarg((string) $sourcePhotoId)
                    . ' < /dev/null > ' . escapeshellarg($logFile) . ' 2>&1 &';
                exec($cmd);
                flash_set("Le détourage est en cours de génération (~1 à 2 minutes) — actualisez cette page dans un instant pour le voir apparaître.");
                break;
            }

            // rembg local indisponible (exec() désactivé) : fal.ai héberge le
            // même modèle rembg, appelé en HTTP classique — vrai fond
            // transparent, sans dépendre d'exec(). Reste le meilleur repli.
            if (FAL_API_KEY) {
                $srcAbs = __DIR__ . '/../' . $sourcePath;
                $bytes = fal_remove_background($srcAbs);
                if ($bytes) {
                    $detourePath = save_binary_photo($bytes, 'product-' . $ref . '-detoure', 'png');
                    if ($detourePath) {
                        add_product_photo($ref, $detourePath, 'Détourée', false);
                        flash_set('Photo détourée (fond transparent) et ajoutée à la galerie.');
                        break;
                    }
                }
                flash_set("Le détourage via fal.ai a échoué (service indisponible ou surchargé) — réessayez dans un instant.", 'error');
                break;
            }

            // Dernier repli, quand ni rembg local ni fal.ai ne sont
            // disponibles : Gemini ne peut pas produire de vrai fond
            // transparent, seulement remplacer le fond par du blanc uni —
            // étiqueté différemment pour ne jamais faire croire à un export
            // PNG transparent.
            if (!GEMINI_API_KEY) {
                flash_set("Le détourage nécessite Python (rembg) ou une clé fal.ai, tous deux indisponibles ici — configurez une clé fal.ai dans Réglages du site pour un vrai fond transparent.", 'error');
                break;
            }
            $srcAbs = __DIR__ . '/../' . $sourcePath;
            $small = downscale_for_ai($srcAbs, 1280, 85) ?? $srcAbs;
            $bytes = gemini_generate_image($small, build_detoure_fallback_prompt(), 1);
            if ($small !== $srcAbs) @unlink($small);

            if (!$bytes) {
                flash_set('Le fond blanc de repli a échoué (service IA indisponible ou surchargé) — réessayez dans un instant.', 'error');
                break;
            }
            $detourePath = save_binary_photo($bytes, 'product-' . $ref . '-detoure-repli', 'jpg');
            if (!$detourePath) {
                flash_set("Impossible d'enregistrer l'image générée.", 'error');
                break;
            }
            add_product_photo($ref, $detourePath, 'Détourée (fond blanc, approx. IA)', true);
            flash_set("Détourage indisponible sur cet hébergement (rembg et fal.ai absents) — une version à fond blanc via l'IA a été générée à la place, ce n'est pas un vrai fond transparent.", 'ok');
            break;
        }

        // ambiance / angle / complete : appel Gemini synchrone (pas de tâche
        // de fond, donc ça marche aussi sur un hébergement qui désactive
        // exec()/shell_exec()) — l'image source est réduite avant l'envoi
        // pour que la réponse reste rapide même sur une grosse photo (voir
        // downscale_for_ai()).
        if (!GEMINI_API_KEY) {
            flash_set("Clé Gemini absente dans config.php — impossible de générer cette vue.", 'error');
            break;
        }

        $srcAbs = __DIR__ . '/../' . $sourcePath;
        $small = downscale_for_ai($srcAbs, 1280, 85) ?? $srcAbs;
        $prompt = match ($kind) {
            'ambiance' => build_ambiance_prompt($keywords),
            'angle' => build_angle_prompt($anglePreset, $keywords),
            'complete' => build_complete_prompt($keywords),
        };
        $bytes = gemini_generate_image($small, $prompt, 1);
        if ($small !== $srcAbs) @unlink($small);

        if (!$bytes) {
            flash_set('La génération a échoué (service IA indisponible ou surchargé) — réessayez dans un instant.', 'error');
            break;
        }
        $genPath = save_binary_photo($bytes, 'product-' . $ref . '-' . $kind, 'jpg');
        if (!$genPath) {
            flash_set("Impossible d'enregistrer l'image générée.", 'error');
            break;
        }
        $label = ['ambiance' => 'Ambiance', 'angle' => 'Autre angle', 'complete' => 'Objet complété'][$kind];
        add_product_photo($ref, $genPath, $label, true);
        $doneLabel = ['ambiance' => "La photo d'ambiance", 'angle' => 'La vue sous un autre angle', 'complete' => "Le complément de l'objet"][$kind];
        flash_set("$doneLabel a été générée et ajoutée à la galerie.");
        break;
    }
}

header('Location: ' . $redirect);
exit;
