<?php
require_once __DIR__ . '/../includes/session.php';
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
ai_usage_context($ref); // le coût des générations de cette page est rattaché à la pièce

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
        $stmt = db()->prepare('UPDATE product_photos SET path = ?, path_mobile = NULL, mobile_pending = NULL, is_illustration = 0 WHERE id = ? AND product_ref = ?');
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
        $stmt = db()->prepare('UPDATE product_photos SET path = ?, path_mobile = NULL, mobile_pending = NULL WHERE id = ? AND product_ref = ?');
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
        db()->prepare('UPDATE product_photos SET path_mobile = NULL, mobile_pending = NULL WHERE id = ?')->execute([$id]);
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

    // Visuel IA à un seul format (généré avant, ou échec) : fabrique le
    // format manquant selon SON format réel (3:2 → 9:16, 9:16 → 3:2,
    // autre → les deux), en prolongeant le visuel lui-même.
    case 'mobile_variant': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $row = get_photo_row($ref, $id);
        if (!$row || ($row['type'] ?? 'photo') !== 'photo') {
            flash_set('Photo introuvable.', 'error');
            break;
        }
        if (is_cutout_photo($row)) {
            flash_set('Photo détourée : utilisez « Format 3:2 + 9:16 » (objet entier, ombre portée par l\'IA en option) — prolonger le décor inventerait une mise en situation.', 'error');
            break;
        }
        // Version smartphone existante mais ratée (ex. bandes floutées) : l'IA la refait comme une vraie photo verticale.
        if (!empty($_POST['force']) && !empty($row['path_mobile'])) {
            if (image_format_kind(__DIR__ . '/../' . $row['path']) !== 'desktop') {
                flash_set('Seuls les visuels en 3:2 peuvent voir leur version smartphone refaite.', 'error');
                break;
            }
            queue_format_job($id, $row['path'], 'mobile');
            flash_set("« {$row['label']} » — sa version smartphone (9:16) est refaite par l'IA : une vraie photo verticale de la même scène (encadré en bas à droite). L'ancienne reste affichée jusqu'à la fin.");
            break;
        }
        $kind = queue_format_completion($row);
        $what = match ($kind) {
            'desktop' => 'Ce visuel est en 3:2 : sa version smartphone (9:16) se génère maintenant, décor prolongé en hauteur.',
            'mobile' => 'Ce visuel est en 9:16 : il devient la version smartphone, et sa version ordinateur (3:2) se génère maintenant, décor prolongé sur les côtés.',
            'other' => 'Ce visuel n\'est ni en 3:2 ni en 9:16 : ses deux versions se génèrent maintenant, l\'une après l\'autre.',
            default => null,
        };
        flash_set($what ? "« {$row['label']} » — $what (encadré en bas à droite)" : 'Ce visuel a déjà ses deux formats.', $what ? 'ok' : 'error');
        break;
    }

    // Version 3:2 (ordinateur) + 9:16 (smartphone) d'une photo, ajoutée à la
    // galerie : photo entière, fond ajouté autour (transparent si détourée).
    case 'fit_formats': {
        $id = (int) ($_POST['photo_id'] ?? 0);
        $row = get_photo_row($ref, $id);
        if (!$row || ($row['type'] ?? 'photo') !== 'photo') {
            flash_set('Photo introuvable.', 'error');
            break;
        }
        $srcAbs = __DIR__ . '/../' . $row['path'];
        $base = 'product-' . $ref . '-format';
        $shadow = !empty($_POST['shadow']) && is_cutout_photo($row);
        @set_time_limit(120);
        $aiNote = '';

        // Ombre portée par l'IA : 3:2 maintenant, 9:16 ensuite en arrière-plan
        // (même consigne, depuis le même détourage). Repli sans IA si échec.
        if ($shadow && GEMINI_API_KEY) {
            $desktop = generate_shadow_image($srcAbs, GENERATED_IMAGE_FORMATS['desktop'], $base . '-ombre');
            if ($desktop) {
                $label = mb_substr(preg_replace('/ — 3:2( avec ombre)?$/u', '', $row['label']) . ' — 3:2 avec ombre', 0, 80);
                $newId = add_product_photo($ref, $desktop, $label, true);
                queue_format_job($newId, $row['path'], 'mobile', null, 'shadow');
                flash_set("« $label » ajoutée à la galerie : ombre portée ajoutée par l'IA, objet entier sur fond blanc (format ordinateur 3:2). La version smartphone 9:16 se génère maintenant (encadré en bas à droite).");
                break;
            }
            $aiNote = " — L'IA n'a pas pu ajouter l'ombre (service indisponible ou surchargé) : ombre calculée sans IA à la place, réessayez plus tard pour la version IA.";
        }

        $desktop = fit_ratio_file($srcAbs, aspect_ratio_value(GENERATED_IMAGE_FORMATS['desktop']), $base, shadow: $shadow);
        $mobile = fit_ratio_file($srcAbs, aspect_ratio_value(GENERATED_IMAGE_FORMATS['mobile']), $base . '-mobile', shadow: $shadow);
        if (!$desktop) {
            flash_set('Mise au format impossible.', 'error');
            break;
        }
        $isPng = str_ends_with($desktop, '.png');
        $withShadow = $shadow && ($isPng || is_cutout_photo($row));
        $label = mb_substr(preg_replace('/ — 3:2( avec ombre)?$/u', '', $row['label']) . ' — 3:2' . ($withShadow ? ' avec ombre' : ''), 0, 80);
        add_product_photo($ref, $desktop, $label, (bool) $row['is_illustration'], 'photo', $mobile);
        flash_set("« $label » ajoutée à la galerie : format 3:2 sur ordinateur et 9:16 sur smartphone, photo entière"
            . ($isPng ? ', fond transparent conservé' : ', fond prolongé autour') . ($withShadow ? ', ombre portée ajoutée.' : '.') . $aiNote, $aiNote ? 'error' : 'ok');
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

        // Une ou plusieurs photos de départ (ids dans l'ordre choisi ; la première est la photo principale).
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['source_photo_ids'] ?? ''))))));
        if (!$ids && $sourcePhotoId) $ids = [$sourcePhotoId];
        $sourcePaths = [];
        $pathToId = [];
        foreach ($ids as $id) {
            $row = get_photo_row($ref, $id);
            if ($row && ($row['type'] ?? 'photo') === 'photo') { $sourcePaths[] = $row['path']; $pathToId[$row['path']] = $id; }
        }
        if (!$sourcePaths && $product['photo']) $sourcePaths = [$product['photo']];
        $sourcePath = $sourcePaths[0] ?? null;
        if (!$sourcePath) {
            flash_set("Cette pièce n'a pas encore de photo à partir de laquelle générer.", 'error');
            break;
        }

        // Vidéo zoom/travelling et détourage : un résultat PAR photo choisie (les autres types utilisent toutes les photos ensemble).
        if (in_array($kind, ['video', 'detoure'], true)) {
            $perPhotoMax = $kind === 'detoure' ? 3 : 6;
            $skipped = max(0, count($sourcePaths) - $perPhotoMax);
            $messages = [];
            $capture = static function () use (&$messages): void {
                if (!empty($_SESSION['flash'])) { $messages[] = $_SESSION['flash']; unset($_SESSION['flash']); }
            };
            foreach (array_slice($sourcePaths, 0, $perPhotoMax) as $sourcePath) {
                $capture();
                $sourcePhotoId = $pathToId[$sourcePath] ?? 0; // le détourage en tâche de fond reçoit l'id de CETTE photo
                if ($kind === 'video') {
                    // Effet Ken Burns via ffmpeg : rapide (1-3s), pas d'IA — exécuté
                    // directement dans la requête, pas besoin de tâche de fond.
                    $videoEffect = (string) ($_POST['video_effect'] ?? 'zoom_in');
                    if (!array_key_exists($videoEffect, video_effects())) $videoEffect = 'zoom_in';
                    $srcAbs = __DIR__ . '/../' . $sourcePath;
                    $path = run_ken_burns_video($srcAbs, $videoEffect);
                    if (!$path) {
                        flash_set('La génération de la vidéo a échoué.', 'error');
                        continue;
                    }
                    add_product_photo($ref, $path, video_effects()[$videoEffect], false, 'video');
                    flash_set('Vidéo générée et ajoutée à la galerie.');
                    continue;
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
                        continue;
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
                                continue;
                            }
                        }
                        flash_set("Le détourage via fal.ai a échoué (service indisponible ou surchargé) — réessayez dans un instant.", 'error');
                        continue;
                    }

                    // Dernier repli, quand ni rembg local ni fal.ai ne sont
                    // disponibles : Gemini ne peut pas produire de vrai fond
                    // transparent, seulement remplacer le fond par du blanc uni —
                    // étiqueté différemment pour ne jamais faire croire à un export
                    // PNG transparent.
                    if (!GEMINI_API_KEY) {
                        flash_set("Le détourage nécessite Python (rembg) ou une clé fal.ai, tous deux indisponibles ici — configurez une clé fal.ai dans Réglages du site pour un vrai fond transparent.", 'error');
                        continue;
                    }
                    $srcAbs = __DIR__ . '/../' . $sourcePath;
                    $small = downscale_for_ai($srcAbs, 1280, 85) ?? $srcAbs;
                    $bytes = gemini_generate_image($small, build_detoure_fallback_prompt(), 1);
                    if ($small !== $srcAbs) @unlink($small);

                    if (!$bytes) {
                        flash_set('Le fond blanc de repli a échoué (service IA indisponible ou surchargé) — réessayez dans un instant.', 'error');
                        continue;
                    }
                    $detourePath = save_binary_photo($bytes, 'product-' . $ref . '-detoure-repli', 'jpg');
                    if (!$detourePath) {
                        flash_set("Impossible d'enregistrer l'image générée.", 'error');
                        continue;
                    }
                    add_product_photo($ref, $detourePath, 'Détourée (fond blanc, approx. IA)', true);
                    flash_set("Détourage indisponible sur cet hébergement (rembg et fal.ai absents) — une version à fond blanc via l'IA a été générée à la place, ce n'est pas un vrai fond transparent.", 'ok');
                    continue;
                }
            }
            $capture();
            if (count($messages) === 1 && !$skipped) {
                flash_set($messages[0]['message'], $messages[0]['kind'], $messages[0]['link'] ?? null);
            } else {
                $failed = array_values(array_filter($messages, static fn ($m) => ($m['kind'] ?? 'ok') === 'error'));
                $okCount = count($messages) - count($failed);
                $summary = $okCount . ' ' . ($kind === 'video' ? 'vidéo(s)' : 'détourage(s)') . ' sur ' . count($messages) . ' ajouté(s) à la galerie'
                    . ($skipped ? " ($skipped photo(s) en trop ignorée(s) : " . $perPhotoMax . ' au plus par demande)' : '') . '.'
                    . ($failed ? ' Échec : ' . $failed[0]['message'] : '');
                flash_set($summary, $okCount > 0 ? 'ok' : 'error');
            }
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
        // Autres photos choisies (3 au plus) : envoyées avec la principale, pour que l'IA voie l'objet sous plusieurs côtés.
        $extras = [];
        $extraTemps = [];
        foreach (array_slice($sourcePaths, 1, 3) as $extraPath) {
            $extraAbs = __DIR__ . '/../' . $extraPath;
            $tmp = downscale_for_ai($extraAbs, 1024, 82);
            if ($tmp) $extraTemps[] = $tmp;
            $extras[] = $tmp ?? $extraAbs;
        }
        $prompt = match ($kind) {
            'ambiance' => build_ambiance_prompt($keywords),
            'angle' => build_angle_prompt($anglePreset, $keywords),
            'complete' => build_complete_prompt($keywords),
        } . multi_photo_prompt(1 + count($extras));
        // Version ordinateur (3:2) maintenant ; la version smartphone (9:16)
        // suit dans une requête séparée (queue_mobile_variant), pour rester
        // sous le délai du serveur.
        @set_time_limit(120);
        $desktop = generate_desktop_image($small, $prompt, generated_photo_basename('product-' . $ref . '-' . $kind, $keywords), 1, $kind === 'ambiance' && $keywords !== '', $extras);
        if ($small !== $srcAbs) @unlink($small);
        foreach ($extraTemps as $tmp) @unlink($tmp);

        if (!$desktop) {
            flash_set('La génération a échoué (service IA indisponible ou surchargé) — réessayez dans un instant.', 'error');
            break;
        }
        // La photo prend le nom de la consigne (« Ambiance — portée par une femme en mouvement ») : renommable ensuite.
        $label = generated_photo_label(['ambiance' => 'Ambiance', 'angle' => 'Autre angle', 'complete' => 'Objet complété'][$kind], $keywords);
        $photoId = add_product_photo($ref, $desktop, $label, true);
        queue_mobile_variant($photoId, $desktop, $kind === 'ambiance' ? $sourcePath : null, $kind === 'ambiance' ? $keywords : '');
        $doneLabel = ['ambiance' => "La photo d'ambiance", 'angle' => 'La vue sous un autre angle', 'complete' => "Le complément de l'objet"][$kind];
        flash_set("$doneLabel a été générée et ajoutée à la galerie (format ordinateur 3:2) sous le nom « $label »" . ($keywords !== '' ? ' d\'après votre consigne' : '') . '. La version smartphone 9:16 se génère maintenant, sans rien bloquer.' . ($extras ? ' (à partir de ' . (1 + count($extras)) . ' photos de la pièce)' : ''));
        break;
    }
}

header('Location: ' . $redirect);
exit;
