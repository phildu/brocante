<?php
// Ajout rapide d'une pièce depuis un smartphone (admin/quick-add.php).
// Chaque étape est un appel séparé, pour afficher la progression et rester
// sous la limite de durée d'exécution des hébergements mutualisés :
//   create   → photos enregistrées, fiche masquée créée
//   detoure  → photo principale détourée (fal.ai, sinon Gemini)
//   ambiance → mise en situation à partir du détourage (Gemini)
//   sheet    → nom, description, catégorie et prix suggérés d'après tous les angles
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
    return $product;
}

/** Indications saisies pour l'IA : celles de la requête, sinon celles mémorisées à la création (traitement différé). */
function quick_add_notes(string $ref): string
{
    $posted = trim((string) ($_POST['notes'] ?? ''));
    return $posted !== '' ? $posted : (string) (studio_job_get($ref)['ai_notes'] ?? '');
}

switch ($action) {
    case 'create': {
        $files = $_FILES['photos'] ?? null;
        if (!$files || !is_array($files['tmp_name']) || !array_filter($files['tmp_name'])) {
            quick_add_reply(['ok' => false, 'error' => 'Aucune photo reçue.'], 400);
        }
        $labels = (array) ($_POST['labels'] ?? []);
        $main = (int) ($_POST['main'] ?? 0);
        if (count(array_filter($files['tmp_name'])) > STUDIO_MAX_PHOTOS) {
            quick_add_reply(['ok' => false, 'error' => 'Trop de photos pour une pièce (' . STUDIO_MAX_PHOTOS . ' au plus).'], 400);
        }

        $ref = next_ref();
        db()->prepare('INSERT INTO products (ref, name, cat, photo, icon, description, price, badge, is_hidden, featured, sort_order)
                       VALUES (:ref, :name, :cat, NULL, NULL, :description, :price, :badge, 1, 0, :sort_order)')
            ->execute([
                'ref' => $ref,
                'name' => 'Pièce à décrire',
                'cat' => default_category_key(),
                'description' => 'Description à compléter.',
                'price' => '0 €',
                'badge' => 'Chiné',
                'sort_order' => (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn(),
            ]);

        // Photo principale d'abord : elle sert de couverture et de source aux générations.
        $order = array_keys(array_filter($files['tmp_name']));
        usort($order, static fn ($a, $b) => ($b === $main) <=> ($a === $main));
        $saved = [];
        foreach ($order as $i) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !@getimagesize($files['tmp_name'][$i])) {
                continue;
            }
            $path = copy_photo_into_uploads($files['tmp_name'][$i], 'product-' . $ref);
            if (!$path) continue;
            $label = trim((string) ($labels[$i] ?? '')) ?: 'Photo';
            add_product_photo($ref, $path, $i === $main ? 'Photo principale' : mb_substr($label, 0, 40), false);
            $saved[] = $path;
        }
        if (!$saved) {
            db()->prepare('DELETE FROM products WHERE ref = ?')->execute([$ref]);
            quick_add_reply(['ok' => false, 'error' => 'Les photos n\'ont pas pu être enregistrées (format non reconnu ?).'], 400);
        }
        studio_job_create($ref, (string) ($_POST['source'] ?? 'single'), trim((string) ($_POST['notes'] ?? '')));
        quick_add_reply([
            'ok' => true,
            'ref' => $ref,
            'photos' => $saved,
            'ai' => ['gemini' => (bool) GEMINI_API_KEY, 'fal' => (bool) FAL_API_KEY],
        ]);
    }

    case 'detoure': {
        $product = quick_add_product();
        $source = product_source_photos($product['ref'])[0] ?? null;
        if (!$source) quick_add_reply(['ok' => false, 'error' => 'Photo principale introuvable.']);
        $detoure = detoure_photo_synchronous($root . '/' . $source['path']);
        if (!$detoure) {
            quick_add_reply(['ok' => false, 'error' => 'Détourage indisponible : configurez fal.ai ou Gemini dans les réglages du site.']);
        }
        $path = save_binary_photo($detoure['bytes'], 'product-' . $product['ref'] . '-detoure', $detoure['ext']);
        if (!$path) quick_add_reply(['ok' => false, 'error' => 'Échec de l\'enregistrement de l\'image détourée.']);
        add_product_photo($product['ref'], $path, $detoure['label'], $detoure['illustration']);
        quick_add_reply(['ok' => true, 'path' => $path, 'label' => $detoure['label']]);
    }

    case 'ambiance': {
        $product = quick_add_product();
        if (!GEMINI_API_KEY) quick_add_reply(['ok' => false, 'error' => 'Mise en situation indisponible : clé Gemini manquante (réglages du site).']);
        $detoure = null;
        foreach (product_photos_list($product['ref']) as $p) {
            if (str_starts_with($p['label'], 'Détourée')) $detoure = $p;
        }
        $source = $detoure ?? (product_source_photos($product['ref'])[0] ?? null);
        if (!$source) quick_add_reply(['ok' => false, 'error' => 'Aucune photo à mettre en situation.']);
        // Version 3:2 maintenant ; la 9:16 suit à part (queue_mobile_variant).
        $notes = quick_add_notes($product['ref']);
        $desktop = generate_desktop_image($root . '/' . $source['path'], build_ambiance_prompt($notes), 'product-' . $product['ref'] . '-ambiance', 1, $notes !== '');
        if (!$desktop) quick_add_reply(['ok' => false, 'error' => 'La mise en situation a échoué, réessayez plus tard depuis le catalogue.']);
        queue_mobile_variant(add_product_photo($product['ref'], $desktop, 'Ambiance', true), $desktop, $source['path'], $notes);
        quick_add_reply(['ok' => true, 'path' => $desktop, 'label' => 'Ambiance']);
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
        $text = gemini_describe_image($smalls[0], build_product_sheet_prompt(count($smalls), quick_add_notes($product['ref'])), 1, array_slice($smalls, 1));
        foreach ($smalls as $i => $small) {
            if (str_starts_with($small, sys_get_temp_dir())) @unlink($small);
        }
        $sheet = $text ? parse_product_sheet_response($text) : null;
        if (!$sheet) quick_add_reply(['ok' => false, 'error' => 'La rédaction automatique a échoué : complétez la fiche à la main.']);
        db()->prepare('UPDATE products SET name = ?, description = ?, cat = ?, price = ?, materials = ? WHERE ref = ?')
            ->execute([$sheet['name'], $sheet['description'] ?: 'Description à compléter.', $sheet['category'], $sheet['price_hint'] ?: '0 €', $sheet['materials'], $product['ref']]);
        quick_add_reply(['ok' => true, 'sheet' => $sheet]);
    }

    case 'finish': {
        $product = quick_add_product();
        // Étapes échouées (« detoure,ambiance »), gardées pour l'afficher dans la liste.
        $failed = preg_replace('/[^a-z,]/', '', (string) ($_POST['failed'] ?? ''));
        studio_job_set($product['ref'], 'ready', $failed);
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
            'description' => $product['description'], 'materials' => (string) $product['materials'], 'badge' => $product['badge'], 'weight_grams' => (int) $product['weight_grams'],
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
        $validCats = array_column(category_list(), 'key');
        $cat = in_array($_POST['cat'] ?? '', $validCats, true) ? $_POST['cat'] : $product['cat'];
        db()->prepare('UPDATE products SET name = ?, cat = ?, price = ?, badge = ?, description = ?, materials = ?, weight_grams = ?, is_hidden = ? WHERE ref = ?')
            ->execute([
                trim((string) ($_POST['name'] ?? '')) ?: $product['name'],
                $cat,
                trim((string) ($_POST['price'] ?? '')) ?: $product['price'],
                trim((string) ($_POST['badge'] ?? '')),
                trim((string) ($_POST['description'] ?? '')) ?: $product['description'],
                trim((string) ($_POST['materials'] ?? $product['materials'])),
                max(0, (int) ($_POST['weight_grams'] ?? $product['weight_grams'])),
                empty($_POST['publish']) ? 1 : 0,
                $product['ref'],
            ]);
        if (studio_job_get($product['ref'])) studio_job_set($product['ref'], 'reviewed');
        quick_add_reply(['ok' => true, 'ref' => $product['ref'], 'published' => !empty($_POST['publish'])]);
    }
}

quick_add_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
