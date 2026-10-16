<?php
// Ajout rapide d'une pièce depuis un smartphone (admin/quick-add.php).
// Chaque étape est un appel séparé, pour afficher la progression et rester
// sous la limite de durée d'exécution des hébergements mutualisés :
//   create   → photos enregistrées, fiche masquée créée (+ type de pièce, code-barres et pochette d'un média)
//   video    → vidéo de la pièce, envoyée par morceaux
//   objects  → objets distincts d'une photo (ensemble), pour en isoler un
//   measure  → estimation des dimensions par l'IA, d'après un repère de taille connue (règle, pièce…)
//   detoure  → photo principale détourée (fal.ai, sinon Gemini)
//   sheet    → nom, description, catégorie et prix suggérés d'après tous les angles (avant la mise en situation : la
//              nature de la pièce décide du décor)
//   ambiance → mise en situation à partir du détourage (Gemini)
//   angles   → détourage des autres angles, un par appel (2 photos ou plus)
//   recto, verso → deux visuels de studio générés à partir de tous les détourages (2 photos ou plus)
//   finish   → traitement terminé (la fiche passe « à relire »)
//   save     → corrections du vendeur et publication éventuelle
//   jobs     → liste « Mes pièces » de l'application smartphone (studio.php)
//   get      → une pièce, pour la relire ; discard → la jeter avant relecture
// Réponses JSON : {ok: true, ...} ou {ok: false, error: "..."}.

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function quick_add_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) {
    quick_add_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    quick_add_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}
@set_time_limit(120);

$root = realpath(__DIR__ . '/..');
$action = (string) ($_POST['action'] ?? '');

/** Fiche créée par ce flux (masquée tant qu'elle n'est pas publiée). */
function quick_add_product(): array
{
    $ref = (string) ($_POST['ref'] ?? '');
    $product = $ref !== '' ? get_product($ref) : null;
    if (!$product) {
        quick_add_reply(['ok' => false, 'error' => 'Fiche introuvable.'], 404);
    }
    ai_usage_context($product['ref']);
    return $product;
}

/** Indications saisies pour l'IA : celles de la requête, sinon celles mémorisées à la création (traitement différé). */
function quick_add_notes(string $ref): string
{
    $posted = trim((string) ($_POST['notes'] ?? ''));
    return $posted !== '' ? $posted : (string) (studio_job_get($ref)['ai_notes'] ?? '');
}

// Quota d'IA gratuite épuisé et plus de crédit racheté : les étapes qui appellent l'IA répondent d'emblée (« quota » : le Studio propose alors de racheter du crédit).
if (in_array($action, ['detoure', 'sheet', 'ambiance', 'angles', 'recto', 'verso', 'objects', 'measure'], true) && ($gate = ai_gate())) {
    quick_add_reply(['ok' => false, 'quota' => true, 'error' => $gate]);
}

switch ($action) {
    case 'create': {
        $files = $_FILES['photos'] ?? null;
        $hasFiles = $files && is_array($files['tmp_name']) && array_filter($files['tmp_name']);
        // Type de pièce (objet, vêtement, média) et code lu par le scanner (livres, CD, vinyles, DVD) ; la pochette trouvée tient
        // lieu de photo quand le média n'a pas été photographié.
        $kind = studio_kind((string) ($_POST['kind'] ?? 'objet'));
        $barcode = product_barcode_clean($_POST['barcode'] ?? '');
        $coverUrl = trim((string) ($_POST['cover_url'] ?? ''));
        if (!$hasFiles && !($kind === 'media' && $coverUrl !== '')) {
            quick_add_reply(['ok' => false, 'error' => 'Aucune photo reçue.'], 400);
        }
        if (!shop_can_add_items()) quick_add_reply(['ok' => false, 'error' => shop_limit_message()], 403);
        $labels = (array) ($_POST['labels'] ?? []);
        $main = (int) ($_POST['main'] ?? 0);
        if ($hasFiles && count(array_filter($files['tmp_name'])) > STUDIO_MAX_PHOTOS) {
            quick_add_reply(['ok' => false, 'error' => 'Trop de photos pour une pièce (' . STUDIO_MAX_PHOTOS . ' au plus).'], 400);
        }

        $ref = next_ref();
        // Taille mesurée sur une photo (assets/measure-tool.js) : elle prime sur l'estimation de l'IA.
        $sizeText = mb_substr(trim((string) ($_POST['size_text'] ?? '')), 0, 60);
        db()->prepare('INSERT INTO products (ref, name, cat, photo, icon, description, price, badge, is_hidden, featured, sort_order, nature, barcode, size_text)
                       VALUES (:ref, :name, :cat, NULL, NULL, :description, :price, :badge, 1, 0, :sort_order, :nature, :barcode, :size_text)')
            ->execute([
                'ref' => $ref,
                'name' => 'Pièce à décrire',
                'cat' => default_category_key(),
                'description' => 'Description à compléter.',
                'price' => '0 €',
                'badge' => 'Chiné',
                'sort_order' => (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn(),
                'nature' => ['vetement' => 'vetements', 'media' => 'livres_medias'][$kind] ?? null,
                'barcode' => $barcode !== '' ? $barcode : null,
                'size_text' => $sizeText,
            ]);

        // Photo principale d'abord : elle sert de couverture et de source aux générations.
        $saved = [];
        if ($hasFiles) {
            $order = array_keys(array_filter($files['tmp_name']));
            usort($order, static fn ($a, $b) => ($b === $main) <=> ($a === $main));
            foreach ($order as $i) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !@getimagesize($files['tmp_name'][$i])) {
                    continue;
                }
                $path = copy_photo_into_uploads($files['tmp_name'][$i], 'product-' . $ref);
                if (!$path) continue;
                $label = trim((string) ($labels[$i] ?? '')) ?: 'Photo';
                $photoId = add_product_photo($ref, $path, $i === $main ? 'Photo principale' : mb_substr($label, 0, 40), false);
                // Photo de mesure (repère posé à côté de la pièce) : gardée pour mémoire, masquée dans la boutique.
                if ($i !== $main && preg_match('/^mesure/iu', $label)) db()->prepare('UPDATE product_photos SET is_hidden = 1 WHERE id = ?')->execute([$photoId]);
                $saved[] = $path;
            }
        }
        if (!$saved && $kind === 'media' && $coverUrl !== '' && ($path = product_photo_from_cover_url($coverUrl, 'product-' . $ref))) {
            add_product_photo($ref, $path, 'Photo principale', false);
            $saved[] = $path;
        }
        if (!$saved) {
            db()->prepare('DELETE FROM products WHERE ref = ?')->execute([$ref]);
            quick_add_reply(['ok' => false, 'error' => 'Les photos n\'ont pas pu être enregistrées (format non reconnu ?).'], 400);
        }
        studio_job_create($ref, (string) ($_POST['source'] ?? 'single'), trim((string) ($_POST['notes'] ?? '')), $kind);
        quick_add_reply([
            'ok' => true,
            'ref' => $ref,
            'photos' => $saved,
            'kind' => $kind,
            'chunk' => min(4 * 1048576, capture_chunk_bytes()),
            'ai' => ['gemini' => (bool) GEMINI_API_KEY, 'fal' => (bool) FAL_API_KEY],
        ]);
    }

    // Vidéo de la pièce, envoyée par morceaux (une vidéo dépasse la limite d'une requête) puis recollée : upload_id, index, total, size, name + file.
    case 'video': {
        $product = quick_add_product();
        $uploadId = (string) ($_POST['upload_id'] ?? '');
        $index = (int) ($_POST['index'] ?? -1);
        $total = (int) ($_POST['total'] ?? 0);
        $size = (int) ($_POST['size'] ?? 0);
        $chunkBytes = min(4 * 1048576, capture_chunk_bytes());
        $chunk = $_FILES['file'] ?? null;
        if (!preg_match('/^[a-f0-9]{16}$/', $uploadId) || $total < 1 || $index < 0 || $index >= $total || $size < 1 || $size > CAPTURE_VIDEO_MAX_BYTES
            || $total !== (int) ceil($size / $chunkBytes)) {
            quick_add_reply(['ok' => false, 'error' => "L'envoi de la vidéo a échoué, réessayez."], 400);
        }
        if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $chunk['size'] > $chunkBytes
            || $chunk['size'] !== ($index === $total - 1 ? $size - $index * $chunkBytes : $chunkBytes)) {
            quick_add_reply(['ok' => false, 'error' => "L'envoi de la vidéo a échoué, réessayez."], 400);
        }
        $dir = $root . '/data/capture-tmp';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $part = $dir . '/studio-' . substr(md5(tenant_slug() . '|' . $product['ref']), 0, 12) . '-' . $uploadId . '.part';
        if ($index === 0) {
            capture_part_cleanup();
            if (!in_array(capture_sniff_mime($chunk['tmp_name']), CAPTURE_VIDEO_MIMES, true)) {
                quick_add_reply(['ok' => false, 'error' => 'Format de vidéo non pris en charge (mp4, mov ou webm).'], 415);
            }
            file_put_contents($part, '');
        }
        if (!is_file($part) || filesize($part) < $index * $chunkBytes) {
            quick_add_reply(['ok' => false, 'error' => "L'envoi a été interrompu : renvoyez la vidéo."], 409);
        }
        $fh = fopen($part, 'cb');
        if (!$fh || !flock($fh, LOCK_EX)) quick_add_reply(['ok' => false, 'error' => "L'envoi de la vidéo a échoué, réessayez."], 500);
        ftruncate($fh, $index * $chunkBytes);
        fseek($fh, 0, SEEK_END);
        $written = fwrite($fh, (string) file_get_contents($chunk['tmp_name']));
        fflush($fh); flock($fh, LOCK_UN); fclose($fh);
        if ($written !== (int) $chunk['size']) quick_add_reply(['ok' => false, 'error' => 'Disque plein ou écriture impossible, réessayez.'], 507);
        if ($index < $total - 1) quick_add_reply(['ok' => true, 'done' => false]);
        if (filesize($part) !== $size) { @unlink($part); quick_add_reply(['ok' => false, 'error' => 'Vidéo incomplète : renvoyez-la.'], 422); }
        $path = capture_store_video($part, (string) ($_POST['name'] ?? 'video.mp4'));
        if (!$path) { @unlink($part); quick_add_reply(['ok' => false, 'error' => 'Format de vidéo non pris en charge (mp4, mov ou webm).'], 415); }
        add_product_photo($product['ref'], $path, 'Vidéo de la pièce', false, 'video');
        quick_add_reply(['ok' => true, 'done' => true, 'path' => $path]);
    }

    // Estimation des dimensions d'une pièce par l'IA, d'après un repère de taille connue posé à côté d'elle (photo + ref + ref_cm).
    case 'measure': {
        $file = $_FILES['photo'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !@getimagesize($file['tmp_name'])) {
            quick_add_reply(['ok' => false, 'error' => 'Photo non reçue.'], 400);
        }
        if (!GEMINI_API_KEY) quick_add_reply(['ok' => false, 'error' => 'Estimation indisponible : clé Gemini manquante (réglages du site).']);
        $ref = mb_substr(trim((string) ($_POST['ref'] ?? 'repère')), 0, 60);
        $refCm = max(0.1, min(300.0, (float) ($_POST['ref_cm'] ?? 0)));
        $small = downscale_for_ai($file['tmp_name'], 1280, 85);
        $prompt = "Tu regardes la photo d'" . tenant('ai.item') . ' pour ' . tenant('ai.shop') . ', posé à côté d\'un repère de taille connue : ' . $ref . ' (longueur ou diamètre réel : ' . $refCm . ' cm). '
            . "Estime les dimensions RÉELLES de l'objet en centimètres, en prenant le repère pour échelle et en tenant compte de la perspective (le repère et l'objet ne sont pas toujours au même niveau). "
            . "Si le repère ou l'objet n'est pas visible, réponds null. Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour, sans markdown : "
            . '{"hauteur_cm": nombre ou null, "largeur_cm": nombre ou null, "profondeur_cm": nombre ou null, "fiabilite": "haute" ou "moyenne" ou "faible", "remarque": "une phrase courte"}.';
        $text = $small ? gemini_describe_image($small, $prompt, 1) : null;
        if ($small) @unlink($small);
        $data = $text ? json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true) : null;
        if (!is_array($data)) quick_add_reply(['ok' => false, 'error' => "L'IA n'a pas pu estimer les dimensions : tracez-les à la main."]);
        $num = static fn ($v): float => is_numeric($v) ? max(0.0, min(500.0, round((float) $v, 1))) : 0.0;
        quick_add_reply(['ok' => true, 'dims' => ['hauteur' => $num($data['hauteur_cm'] ?? null), 'largeur' => $num($data['largeur_cm'] ?? null), 'profondeur' => $num($data['profondeur_cm'] ?? null)],
            'reliability' => in_array($data['fiabilite'] ?? '', ['haute', 'moyenne', 'faible'], true) ? 'fiabilité ' . $data['fiabilite'] : '', 'note' => mb_substr(trim((string) ($data['remarque'] ?? '')), 0, 160)]);
    }

    // Objets distincts d'une photo (un ensemble) : l'IA les repère, le vendeur en choisit un (assets/object-picker.js).
    case 'objects': {
        $file = $_FILES['photo'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !@getimagesize($file['tmp_name'])) {
            quick_add_reply(['ok' => false, 'error' => 'Photo non reçue.'], 400);
        }
        if (!GEMINI_API_KEY) quick_add_reply(['ok' => true, 'objects' => [], 'ai' => false]);
        quick_add_reply(['ok' => true, 'objects' => studio_detect_objects($file['tmp_name']), 'ai' => true]);
    }

    case 'detoure': {
        $product = quick_add_product();
        $source = product_source_photos($product['ref'])[0] ?? null;
        if (!$source) quick_add_reply(['ok' => false, 'error' => 'Photo principale introuvable.']);
        // Détourage rattaché à la photo principale (comme ceux des autres angles : voir l'étape « angles »).
        $cut = recto_verso_make_cutout($product['ref'], (int) $source['id']);
        if (!$cut['ok']) quick_add_reply($cut);
        quick_add_reply(['ok' => true, 'path' => $cut['cutout']['path'], 'label' => $cut['cutout']['label']]);
    }

    // Détourage des AUTRES angles, un par appel (« remaining » : combien il en reste) ; sans effet avec une seule photo.
    case 'angles': {
        $product = quick_add_product();
        $sources = recto_verso_sources($product['ref']);
        if (count($sources) < 2) quick_add_reply(['ok' => true, 'skipped' => true, 'remaining' => 0]);
        // « skip » : angles dont le détourage a déjà échoué dans cette série (on passe au suivant, sans boucler dessus).
        $skip = array_map('intval', array_filter(explode(',', preg_replace('/[^0-9,]/', '', (string) ($_POST['skip'] ?? '')))));
        $todo = array_values(array_filter($sources, static fn (array $s): bool => !in_array((int) $s['id'], $skip, true) && !recto_verso_cutout_of($product['ref'], (int) $s['id'])));
        if (!$todo) quick_add_reply(['ok' => true, 'remaining' => 0]);
        $cut = recto_verso_make_cutout($product['ref'], (int) $todo[0]['id']);
        if (!$cut['ok']) quick_add_reply($cut + ['photo_id' => (int) $todo[0]['id'], 'remaining' => count($todo) - 1]);
        quick_add_reply(['ok' => true, 'path' => $cut['cutout']['path'], 'label' => $cut['cutout']['label'], 'remaining' => count($todo) - 1]);
    }

    // Recto et verso : tous les détourages envoyés ensemble à l'IA ; sans effet avec une seule photo (le verso serait deviné).
    case 'recto':
    case 'verso': {
        $product = quick_add_product();
        if (count(recto_verso_sources($product['ref'])) < 2) quick_add_reply(['ok' => true, 'skipped' => true]);
        $r = recto_verso_generate($product['ref'], $action);
        quick_add_reply($r['ok'] ? ['ok' => true, 'path' => $r['path'], 'label' => $r['label']] : $r);
    }

    case 'ambiance': {
        $product = quick_add_product();
        if (!GEMINI_API_KEY) quick_add_reply(['ok' => false, 'error' => 'Mise en situation indisponible : clé Gemini manquante (réglages du site).']);
        // Détourage de la photo principale ; à défaut, la photo principale elle-même.
        $main = product_source_photos($product['ref'])[0] ?? null;
        $detoure = $main ? recto_verso_cutout_of($product['ref'], (int) $main['id']) : null;
        if (!$detoure) {
            foreach (product_photos_list($product['ref']) as $p) {
                if (str_starts_with($p['label'], 'Détourée')) { $detoure = $p; break; }
            }
        }
        $source = $detoure ?? $main;
        if (!$source) quick_add_reply(['ok' => false, 'error' => 'Aucune photo à mettre en situation.']);
        // Version 3:2 maintenant ; la 9:16 suit à part (queue_mobile_variant).
        $notes = quick_add_notes($product['ref']);
        $scene = ambiance_scene($product);
        $desktop = generate_desktop_image($root . '/' . $source['path'], build_ambiance_prompt($notes, $scene), generated_photo_basename('product-' . $product['ref'] . '-ambiance', $notes), 1, ambiance_is_directed($notes, $scene), [], ambiance_subject_fill($scene, 'auto', GENERATED_IMAGE_FORMATS['desktop']));
        if (!$desktop) quick_add_reply(['ok' => false, 'error' => 'La mise en situation a échoué, réessayez plus tard depuis le catalogue.']);
        $label = generated_photo_label('Ambiance', $notes);
        queue_mobile_variant(add_product_photo($product['ref'], $desktop, $label, true), $desktop, $source['path'], $notes, $scene);
        quick_add_reply(['ok' => true, 'path' => $desktop, 'label' => $label]);
    }

    case 'sheet': {
        $product = quick_add_product();
        if (!GEMINI_API_KEY) quick_add_reply(['ok' => false, 'error' => 'Rédaction automatique indisponible : clé Gemini manquante (réglages du site).']);
        // Jusqu'à 5 angles, réduits pour l'envoi.
        $smalls = [];
        foreach (array_slice(product_source_photos($product['ref']), 0, 5) as $p) {
            $abs = $root . '/' . $p['path'];
            $smalls[] = downscale_for_ai($abs, 900) ?? $abs;
        }
        if (!$smalls) quick_add_reply(['ok' => false, 'error' => 'Aucune photo à analyser.']);

        // Type de pièce choisi au shooting et code lu par le scanner : indications supplémentaires pour l'IA.
        $kind = studio_kind(studio_job_get($product['ref'])['kind'] ?? null);
        $lookup = null;
        if (!empty($product['barcode'])) {
            require_once $root . '/includes/barcode.php';
            $found = barcode_lookup((string) $product['barcode']);
            if (!empty($found['ok'])) $lookup = $found;
        }
        $hint = '';
        if ($kind === 'vetement') $hint .= ' Cette pièce est un vêtement (recto et verso photographiés) : indique sa nature et sa sous-catégorie de vêtement, et lis la taille sur l\'étiquette si elle est visible.';
        if ($lookup) $hint .= ' Informations vérifiées d\'après le code-barres ' . $lookup['code'] . ' : ' . barcode_cut(trim(($lookup['label'] ?? '') . ' « ' . ($lookup['name'] ?? '') . ' ». ' . ($lookup['description'] ?? '')), 400);

        $measured = trim((string) ($product['size_text'] ?? ''));
        $text = gemini_describe_image($smalls[0], build_product_sheet_prompt(count($smalls), trim(quick_add_notes($product['ref']) . $hint)), 1, array_slice($smalls, 1));
        foreach ($smalls as $i => $small) {
            if (str_starts_with($small, sys_get_temp_dir())) @unlink($small);
        }
        $sheet = $text ? parse_product_sheet_response($text) : null;
        if (!$sheet) quick_add_reply(['ok' => false, 'error' => 'La rédaction automatique a échoué : complétez la fiche à la main.']);
        if ($measured !== '') $sheet['size_text'] = $measured;   // la taille mesurée prime sur l'estimation de l'IA
        // Les faits du catalogue (titre, auteur, éditeur, pistes…) priment sur ce que l'IA devine d'après les photos.
        if ($lookup) {
            if (!empty($lookup['name'])) $sheet['name'] = mb_substr((string) $lookup['name'], 0, 120);
            if (!empty($lookup['description'])) $sheet['description'] = (string) $lookup['description'];
            if (!empty($lookup['brand'])) $sheet['brand'] = mb_substr((string) $lookup['brand'], 0, 80);
            if (!empty($lookup['distributor'])) $sheet['distributor'] = mb_substr((string) $lookup['distributor'], 0, 80);
            if (!empty($lookup['materials'])) $sheet['materials'] = mb_substr((string) $lookup['materials'], 0, 200);
            if (!empty($lookup['size_text']) && $measured === '') $sheet['size_text'] = mb_substr((string) $lookup['size_text'], 0, 60);
            if (!empty($lookup['weight_grams'])) $sheet['weight_grams'] = (int) $lookup['weight_grams'];
            if (!empty($lookup['nature'])) {
                $pair = product_nature_resolve((string) $lookup['nature'], (string) ($lookup['sous_categorie'] ?? ''));
                $sheet['nature'] = $pair['nature'];
                $sheet['sous_categorie'] = $pair['sous_categorie'];
                if ($cat = universe_key_for_nature($pair['nature'])) $sheet['category'] = $cat;
            }
        }
        db()->prepare('UPDATE products SET name = ?, description = ?, cat = ?, price = ?, materials = ?, brand = ?, distributor = ?, etat = ?, nature = ?, sous_categorie = ?, size_text = ?, weight_grams = ?, weight_text = ? WHERE ref = ?')
            ->execute([$sheet['name'], $sheet['description'] ?: 'Description à compléter.', $sheet['category'], $sheet['price_hint'] ?: '0 €', $sheet['materials'], $sheet['brand'] ?? '', $sheet['distributor'] ?? '', $sheet['etat'] ?: null, $sheet['nature'] ?: null, $sheet['sous_categorie'] ?: null, $sheet['size_text'], $sheet['weight_grams'], product_weight_text($sheet['weight_grams']), $product['ref']]);
        // Le catalogue d'abord : assez de pièces comparables (même nature et sous-catégorie) imposent leur poids médian.
        if (catalog_refine_weight($product['ref'])) {
            $fresh = get_product($product['ref']);
            $sheet['weight_grams'] = (int) $fresh['weight_grams'];
        }
        $sheet['barcode'] = (string) ($product['barcode'] ?? '');
        quick_add_reply(['ok' => true, 'sheet' => $sheet]);
    }

    case 'finish': {
        $product = quick_add_product();
        // Étapes échouées (« detoure,ambiance »), gardées pour l'afficher dans la liste.
        $failed = preg_replace('/[^a-z,]/', '', (string) ($_POST['failed'] ?? ''));
        studio_job_set($product['ref'], 'ready', $failed);
        // La fiche montre les images générées ; les photos du shooting sont masquées (réglage, réversible dans la galerie).
        apply_generated_only($product['ref']);
        quick_add_reply(['ok' => true]);
    }

    case 'jobs': {
        quick_add_reply(['ok' => true, 'jobs' => studio_jobs_list()]);
    }

    case 'get': {
        $product = quick_add_product();
        $photos = [];
        foreach (product_photos_list($product['ref']) as $p) {
            if ($p['type'] === 'photo') $photos[] = ['path' => $p['path'], 'label' => $p['label']];
        }
        $job = studio_job_get($product['ref']);
        quick_add_reply(['ok' => true, 'product' => [
            'ref' => $product['ref'], 'name' => $product['name'], 'price' => $product['price'], 'cat' => $product['cat'],
            'description' => $product['description'], 'materials' => (string) $product['materials'], 'brand' => (string) ($product['brand'] ?? ''), 'distributor' => (string) ($product['distributor'] ?? ''), 'etat' => (string) ($product['etat'] ?? ''), 'nature' => (string) ($product['nature'] ?? ''), 'sous_categorie' => (string) ($product['sous_categorie'] ?? ''), 'badge' => $product['badge'], 'weight_grams' => (int) $product['weight_grams'], 'size_text' => (string) ($product['size_text'] ?? ''),
            'hidden' => (bool) $product['is_hidden'],
        ], 'photos' => $photos, 'status' => $job['status'] ?? 'reviewed', 'note' => $job['note'] ?? '']);
    }

    case 'discard': {
        $product = quick_add_product();
        $job = studio_job_get($product['ref']);
        // Seulement une pièce encore masquée et pas relue : une fiche relue se supprime depuis le catalogue.
        if (!$job || $job['status'] === 'reviewed' || !$product['is_hidden']) {
            quick_add_reply(['ok' => false, 'error' => 'Cette pièce ne peut plus être jetée ici : utilisez le catalogue.'], 409);
        }
        move_product_photos_to_media($product['ref']);
        db()->prepare('DELETE FROM products WHERE ref = ?')->execute([$product['ref']]);
        studio_job_delete($product['ref']);
        quick_add_reply(['ok' => true]);
    }

    case 'save': {
        $product = quick_add_product();
        $pair = product_nature_resolve($_POST['nature'] ?? '', $_POST['sous_categorie'] ?? '');
        $validCats = array_column(category_list(), 'key');
        $cat = in_array($_POST['cat'] ?? '', $validCats, true) ? $_POST['cat'] : $product['cat'];
        db()->prepare('UPDATE products SET name = ?, cat = ?, price = ?, badge = ?, description = ?, materials = ?, brand = ?, distributor = ?, etat = ?, nature = ?, sous_categorie = ?, size_text = ?, weight_grams = ?, is_hidden = ?, barcode = ? WHERE ref = ?')
            ->execute([
                trim((string) ($_POST['name'] ?? '')) ?: $product['name'],
                $cat,
                price_from_input($_POST['price'] ?? '', (string) $product['price']),
                trim((string) ($_POST['badge'] ?? '')),
                trim((string) ($_POST['description'] ?? '')) ?: $product['description'],
                trim((string) ($_POST['materials'] ?? $product['materials'])),
                mb_substr(trim((string) ($_POST['brand'] ?? ($product['brand'] ?? ''))), 0, 80),
                mb_substr(trim((string) ($_POST['distributor'] ?? ($product['distributor'] ?? ''))), 0, 80),
                isset($_POST['etat']) ? (product_condition_key($_POST['etat']) ?: null) : $product['etat'],
                isset($_POST['nature']) ? ($pair['nature'] ?: null) : $product['nature'],
                isset($_POST['nature']) ? ($pair['sous_categorie'] ?: null) : $product['sous_categorie'],
                isset($_POST['size_text']) ? mb_substr(trim((string) $_POST['size_text']), 0, 60) : $product['size_text'],
                max(0, (int) ($_POST['weight_grams'] ?? $product['weight_grams'])),
                empty($_POST['publish']) ? 1 : 0,
                isset($_POST['barcode']) ? (product_barcode_clean($_POST['barcode']) ?: null) : ($product['barcode'] ?? null),
                $product['ref'],
            ]);
        if (studio_job_get($product['ref'])) studio_job_set($product['ref'], 'reviewed');
        quick_add_reply(['ok' => true, 'ref' => $product['ref'], 'published' => !empty($_POST['publish'])]);
    }
}

quick_add_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
