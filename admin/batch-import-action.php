<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/batch-import.php');
    exit;
}

$root = __DIR__ . '/..';
$action = (string) ($_POST['action'] ?? '');
$batch = $_SESSION['batch_import'] ?? null;

/** Supprime le dossier d'extraction d'un lot (photos originales copiées du zip). */
function batch_import_delete_dir(string $absDir): void
{
    if (!is_dir($absDir)) return;
    foreach (glob($absDir . '/*') ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($absDir);
}

/**
 * Reconstruit la liste des groupes à afficher/traiter à partir de l'état du
 * regroupement IA progressif : les lots déjà analysés par IA (résultat
 * figé, voir 'ai_chunk_groups') + un regroupement par heure pour le reste
 * des photos pas encore analysées — c'est ce reste qui rétrécit à chaque
 * clic sur "Analyser un lot par IA" (voir l'action correspondante).
 */
function batch_import_rebuild_groups(array $batch, string $root): array
{
    $sortedRel = $batch['sorted_paths'];
    $processed = $batch['ai_processed_count'];
    $groupsRel = $batch['ai_chunk_groups']; // déjà une liste de groupes de chemins relatifs

    $remainingRel = array_slice($sortedRel, $processed);
    if ($remainingRel) {
        $absToRel = [];
        $remainingAbs = [];
        foreach ($remainingRel as $rel) {
            $abs = $root . '/' . $rel;
            $remainingAbs[] = $abs;
            $absToRel[$abs] = $rel;
        }
        foreach (batch_import_auto_group($remainingAbs) as $groupAbs) {
            $groupsRel[] = array_map(fn($abs) => $absToRel[$abs], $groupAbs);
        }
    }

    $groups = [];
    foreach ($groupsRel as $photos) {
        $groups[] = ['photos' => $photos, 'chosen_index' => 0, 'status' => 'pending', 'product_ref' => null, 'scores' => null];
    }
    return $groups;
}

switch ($action) {

    case 'upload': {
        if (empty($_FILES['zip']['tmp_name']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
            flash_set('Aucun fichier zip reçu.', 'error');
            break;
        }
        if (!class_exists('ZipArchive')) {
            flash_set("L'extraction de zip n'est pas disponible sur cet hébergement (extension ZipArchive absente).", 'error');
            break;
        }

        // Un nouvel import remplace silencieusement un lot précédent resté
        // inachevé dans CETTE session (photos jamais nettoyées).
        if ($batch) {
            batch_import_delete_dir($root . '/' . $batch['dir']);
        }

        $batchId = 'lot-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $destDirAbs = $root . '/uploads/batch-import/' . $batchId;

        // Lots abandonnés d'AUTRES sessions (onglet fermé, session expirée
        // sans jamais cliquer "Terminer l'import") — balayage à chaque upload
        // plutôt qu'une tâche planifiée, indisponible sur cet hébergement.
        batch_import_cleanup_stale($destDirAbs);
        $extracted = batch_import_extract_zip($_FILES['zip']['tmp_name'], $destDirAbs);

        if (!$extracted['paths']) {
            flash_set("Aucune photo exploitable trouvée dans ce zip (JPEG/PNG/WEBP attendus — les formats HEIC/HEIF d'iPhone doivent être exportés en JPEG au préalable).", 'error');
            break;
        }

        // L'heure de prise de vue seule ne suffit pas (deux pièces
        // photographiées à la suite sans pause, ou reprises plus tard) —
        // Gemini compare visuellement les photos entre elles, mais un seul
        // lot borné à la fois (voir BATCH_IMPORT_AI_CHUNK_SIZE ci-dessus) :
        // celui-ci traite tout de suite le premier lot (chronologique), le
        // reste démarre groupé par heure et se corrige lot par lot via
        // "Analyser un lot par IA" sur l'écran suivant.
        $sortedRel = array_map(
            fn($abs) => 'uploads/batch-import/' . $batchId . '/' . basename($abs),
            batch_import_sort_by_capture_time($extracted['paths'])
        );

        $aiChunkGroups = [];
        $aiProcessedCount = 0;
        if (GEMINI_API_KEY) {
            $firstChunkRel = array_slice($sortedRel, 0, BATCH_IMPORT_AI_CHUNK_SIZE);
            $firstChunkAbs = array_map(fn($r) => $root . '/' . $r, $firstChunkRel);
            $chunkGroupsAbs = gemini_group_photos_chunk($firstChunkAbs);
            if ($chunkGroupsAbs !== null) {
                $absToRel = array_combine($firstChunkAbs, $firstChunkRel);
                foreach ($chunkGroupsAbs as $groupAbs) {
                    $aiChunkGroups[] = array_map(fn($abs) => $absToRel[$abs], $groupAbs);
                }
                $aiProcessedCount = count($firstChunkRel);
            }
        }

        $batch = [
            'dir' => 'uploads/batch-import/' . $batchId,
            'confirmed' => false,
            'sorted_paths' => $sortedRel,
            'ai_chunk_groups' => $aiChunkGroups,
            'ai_processed_count' => $aiProcessedCount,
            'skipped_files' => $extracted['skipped'],
        ];
        $batch['groups'] = batch_import_rebuild_groups($batch, $root);
        $_SESSION['batch_import'] = $batch;

        $total = count($sortedRel);
        $msg = "$total photo(s) réparties en " . count($batch['groups']) . ' groupe(s) détecté(s)';
        if ($aiProcessedCount >= $total && $total > 0) {
            $msg .= ' — regroupement entièrement par reconnaissance visuelle IA.';
        } elseif ($aiProcessedCount > 0) {
            $msg .= " — IA appliquée aux $aiProcessedCount premières photos (par ordre chronologique), le reste par heure ; cliquez « Analyser le lot suivant par IA » pour continuer.";
        } else {
            $msg .= GEMINI_API_KEY
                ? ' par heure de prise de vue (l\'analyse IA du premier lot a échoué — réessayez ci-dessous).'
                : ' par heure de prise de vue (clé Gemini absente — IA indisponible).';
        }
        if ($extracted['skipped']) {
            $msg .= ' (' . count($extracted['skipped']) . ' fichier(s) ignoré(s) : format non pris en charge.)';
        }
        flash_set($msg);
        break;
    }

    case 'regroup': {
        if (!$batch) { flash_set('Aucun import en cours.', 'error'); break; }

        // $_POST['group'][photo_path] = numéro de groupe choisi par l'admin —
        // reconstruit les groupes en respectant l'ordre chronologique déjà
        // établi, seule l'affectation à un groupe change.
        $assignments = $_POST['group'] ?? [];
        $byGroupNumber = [];
        $order = 0;
        foreach ($batch['groups'] as $group) {
            foreach ($group['photos'] as $photo) {
                $num = (int) ($assignments[$photo] ?? 0);
                $byGroupNumber[$num][] = ['photo' => $photo, 'order' => $order++];
            }
        }
        ksort($byGroupNumber);

        $newGroups = [];
        foreach ($byGroupNumber as $items) {
            $newGroups[] = [
                'photos' => array_column($items, 'photo'),
                'chosen_index' => 0,
                'status' => 'pending',
                'product_ref' => null,
                'scores' => null,
            ];
        }
        $batch['groups'] = $newGroups;
        $_SESSION['batch_import'] = $batch;
        flash_set('Regroupement mis à jour — ' . count($newGroups) . ' groupe(s).');
        break;
    }

    case 'ai_regroup_chunk': {
        if (!$batch || empty($batch['sorted_paths'])) { flash_set('Aucun import en cours.', 'error'); break; }
        if (!GEMINI_API_KEY) { flash_set('Clé Gemini absente — suggestion indisponible.', 'error'); break; }

        $sortedRel = $batch['sorted_paths'];
        $processed = $batch['ai_processed_count'];
        if ($processed >= count($sortedRel)) {
            flash_set('Toutes les photos ont déjà été analysées par IA.');
            break;
        }

        // Un seul lot borné par requête (voir BATCH_IMPORT_AI_CHUNK_SIZE) —
        // ne PAS boucler ici sur plusieurs lots dans la même requête, c'est
        // exactement ce qui dépasse les 60s de fastcgi_read_timeout.
        $nextChunkRel = array_slice($sortedRel, $processed, BATCH_IMPORT_AI_CHUNK_SIZE);
        $nextChunkAbs = array_map(fn($r) => $root . '/' . $r, $nextChunkRel);
        $chunkGroupsAbs = gemini_group_photos_chunk($nextChunkAbs);

        if ($chunkGroupsAbs === null) {
            flash_set("L'analyse IA a échoué pour ce lot — réessayez, ou laissez le regroupement par heure déjà en place pour ces photos.", 'error');
            break;
        }

        $absToRel = array_combine($nextChunkAbs, $nextChunkRel);
        foreach ($chunkGroupsAbs as $groupAbs) {
            $batch['ai_chunk_groups'][] = array_map(fn($abs) => $absToRel[$abs], $groupAbs);
        }
        $batch['ai_processed_count'] = $processed + count($nextChunkRel);
        $batch['groups'] = batch_import_rebuild_groups($batch, $root);
        $_SESSION['batch_import'] = $batch;

        $remaining = count($sortedRel) - $batch['ai_processed_count'];
        flash_set($remaining > 0
            ? "Lot analysé par IA — $remaining photo(s) restent groupées par heure, continuez si besoin."
            : 'Toutes les photos ont été analysées par IA.');
        break;
    }

    case 'confirm_grouping': {
        if (!$batch) { flash_set('Aucun import en cours.', 'error'); break; }
        $batch['confirmed'] = true;
        $_SESSION['batch_import'] = $batch;
        flash_set('Regroupement validé — traitez les fiches une par une ci-dessous.');
        break;
    }

    case 'suggest_best_photo': {
        $groupIndex = (int) ($_POST['group_index'] ?? -1);
        if (!$batch || !isset($batch['groups'][$groupIndex])) { flash_set('Groupe introuvable.', 'error'); break; }
        if (!GEMINI_API_KEY) { flash_set('Clé Gemini absente — suggestion indisponible.', 'error'); break; }

        $group = $batch['groups'][$groupIndex];
        $scores = [];
        $best = 0;
        $bestScore = -1;
        foreach ($group['photos'] as $i => $photo) {
            $score = gemini_score_cutout_simplicity($root . '/' . $photo);
            $scores[$i] = $score;
            if ($score !== null && $score > $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }
        $batch['groups'][$groupIndex]['scores'] = $scores;
        if ($bestScore >= 0) {
            $batch['groups'][$groupIndex]['chosen_index'] = $best;
        }
        $_SESSION['batch_import'] = $batch;
        flash_set($bestScore >= 0
            ? 'Suggestion IA appliquée — vous pouvez encore choisir une autre photo avant de traiter la fiche.'
            : "La notation IA a échoué pour ce groupe — choisissez la photo manuellement.", $bestScore >= 0 ? 'ok' : 'error');
        break;
    }

    case 'choose_photo': {
        $groupIndex = (int) ($_POST['group_index'] ?? -1);
        $photoIndex = (int) ($_POST['photo_index'] ?? -1);
        if (!$batch || !isset($batch['groups'][$groupIndex]['photos'][$photoIndex])) break;
        $batch['groups'][$groupIndex]['chosen_index'] = $photoIndex;
        $_SESSION['batch_import'] = $batch;
        break;
    }

    case 'skip_group': {
        $groupIndex = (int) ($_POST['group_index'] ?? -1);
        if (!$batch || !isset($batch['groups'][$groupIndex])) break;
        $batch['groups'][$groupIndex]['status'] = 'skipped';
        $_SESSION['batch_import'] = $batch;
        flash_set('Groupe ignoré — aucune fiche créée.');
        break;
    }

    case 'process_group': {
        $groupIndex = (int) ($_POST['group_index'] ?? -1);
        if (!$batch || !isset($batch['groups'][$groupIndex])) { flash_set('Groupe introuvable.', 'error'); break; }
        $group = $batch['groups'][$groupIndex];
        if ($group['status'] !== 'pending') break;

        $chosenPhoto = $group['photos'][$group['chosen_index']] ?? $group['photos'][0];
        $chosenAbs = $root . '/' . $chosenPhoto;

        // 1) Détourage synchrone (fal.ai si configuré, sinon repli Gemini fond
        // blanc) — voir detoure_photo_synchronous() : jamais le rembg local
        // via exec(), qui est asynchrone et casserait l'enchaînement direct
        // sur la mise en situation ci-dessous.
        $detoure = detoure_photo_synchronous($chosenAbs);
        if (!$detoure) {
            flash_set("Le détourage a échoué pour ce groupe (aucun service disponible — configurez fal.ai ou Gemini dans les réglages du site) — réessayez, ou traitez cette pièce manuellement depuis le catalogue.", 'error');
            break;
        }
        $detourePath = save_binary_photo($detoure['bytes'], 'batch-detoure', $detoure['ext']);
        if (!$detourePath) {
            flash_set("Échec de l'enregistrement de l'image détourée.", 'error');
            break;
        }

        // 2) Mise en situation à partir du détourage.
        $ambiancePath = null;
        if (GEMINI_API_KEY) {
            $detoureAbs = $root . '/' . $detourePath;
            $ambianceBytes = gemini_generate_image($detoureAbs, build_ambiance_prompt(''), 1);
            if ($ambianceBytes) {
                $ambiancePath = save_binary_photo($ambianceBytes, 'batch-ambiance', 'jpg');
            }
        }

        // 3) Fiche produit suggérée par l'IA à partir de la photo choisie —
        // en cas d'échec, une fiche minimale est tout de même créée (nom
        // générique) puisque la pièce reste masquée jusqu'à relecture.
        $sheet = null;
        if (GEMINI_API_KEY) {
            $small = downscale_for_ai($chosenAbs) ?? $chosenAbs;
            $text = gemini_describe_image($small, build_product_sheet_prompt());
            if ($small !== $chosenAbs) @unlink($small);
            $sheet = $text ? parse_product_sheet_response($text) : null;
        }
        $sheet ??= ['name' => 'Pièce à décrire', 'description' => 'Description à compléter.', 'category' => 'curiosites', 'price_hint' => ''];

        // 4) Création de la fiche produit, masquée jusqu'à relecture par
        // l'admin (nom/description/prix suggérés par l'IA à corriger).
        $ref = next_ref();
        $coverPhoto = $ambiancePath ?? $detourePath;
        $stmt = db()->prepare('INSERT INTO products (ref, name, cat, photo, icon, description, price, badge, is_hidden, featured, sort_order)
                                VALUES (:ref, :name, :cat, :photo, NULL, :description, :price, :badge, 1, 0, :sort_order)');
        $stmt->execute([
            'ref' => $ref,
            'name' => $sheet['name'],
            'cat' => $sheet['category'],
            'photo' => $coverPhoto,
            'description' => $sheet['description'] ?: 'Description à compléter.',
            'price' => $sheet['price_hint'] ?: '0 €',
            'badge' => 'Chiné',
            'sort_order' => (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn(),
        ]);

        // Les photos originales vivent dans le dossier d'extraction temporaire
        // du lot (uploads/batch-import/<id>/) — copiées vers le stockage
        // permanent avant d'être rattachées à la fiche, puisque ce dossier
        // est supprimé une fois l'import terminé (action "finish" ci-dessous).
        foreach ($group['photos'] as $i => $photo) {
            $permanentPath = copy_photo_into_uploads($root . '/' . $photo, 'product-' . $ref);
            if (!$permanentPath) continue;
            add_product_photo($ref, $permanentPath, $i === $group['chosen_index'] ? 'Photo principale' : 'Photo', false);
        }
        add_product_photo($ref, $detourePath, $detoure['label'], $detoure['illustration']);
        if ($ambiancePath) {
            add_product_photo($ref, $ambiancePath, 'Ambiance', true);
        }

        $batch['groups'][$groupIndex]['status'] = 'done';
        $batch['groups'][$groupIndex]['product_ref'] = $ref;
        $_SESSION['batch_import'] = $batch;

        flash_set('Fiche « ' . $sheet['name'] . ' » créée (Réf. N°' . $ref . ', masquée) — relisez-la dans le catalogue.'
            . (!$ambiancePath ? ' La mise en situation a échoué, seule la photo détourée est utilisée comme couverture.' : ''));
        break;
    }

    case 'finish': {
        if ($batch) {
            $stillPending = array_filter($batch['groups'], fn($g) => $g['status'] === 'pending');
            if ($stillPending) {
                flash_set(count($stillPending) . ' groupe(s) pas encore traité(s) ni ignoré(s) — terminez-les avant de clôturer l\'import, sinon leurs photos seront perdues.', 'error');
                break;
            }
            batch_import_delete_dir($root . '/' . $batch['dir']);
        }
        unset($_SESSION['batch_import']);
        flash_set('Import par lot terminé.');
        header('Location: /admin/catalog.php');
        exit;
    }
}

header('Location: /admin/batch-import.php');
exit;
