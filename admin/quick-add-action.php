<?php
// Ajout rapide d'une pièce depuis un smartphone (admin/quick-add.php).
// Chaque étape est un appel séparé, pour afficher la progression et rester
// sous la limite de durée d'exécution des hébergements mutualisés :
//   create   → photos enregistrées, fiche masquée créée
//   detoure  → photo principale détourée (fal.ai, sinon Gemini)
//   ambiance → mise en situation à partir du détourage (Gemini)
//   sheet    → nom, description, catégorie et prix suggérés d'après tous les angles
//   save     → corrections du vendeur et publication éventuelle
// Réponses JSON : {ok: true, ...} ou {ok: false, error: "..."}.

session_start();
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function quick_add_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_admin_logged_in()) {
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

/** Photos d'origine de la fiche (hors détourage et illustrations IA), principale en premier. */
function quick_add_source_photos(string $ref): array
{
    $photos = array_values(array_filter(product_photos_list($ref), static fn ($p) => $p['type'] === 'photo' && !$p['is_illustration'] && !str_starts_with($p['label'], 'Détourée')));
    usort($photos, static fn ($a, $b) => ($b['label'] === 'Photo principale' || str_starts_with($b['label'], '★')) <=> ($a['label'] === 'Photo principale' || str_starts_with($a['label'], '★')));
    return $photos;
}

switch ($action) {
    case 'create': {
        $files = $_FILES['photos'] ?? null;
        if (!$files || !is_array($files['tmp_name']) || !array_filter($files['tmp_name'])) {
            quick_add_reply(['ok' => false, 'error' => 'Aucune photo reçue.'], 400);
        }
        $labels = (array) ($_POST['labels'] ?? []);
        $main = (int) ($_POST['main'] ?? 0);

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
        quick_add_reply([
            'ok' => true,
            'ref' => $ref,
            'photos' => $saved,
            'ai' => ['gemini' => (bool) GEMINI_API_KEY, 'fal' => (bool) FAL_API_KEY],
        ]);
    }

    case 'detoure': {
        $product = quick_add_product();
        $source = quick_add_source_photos($product['ref'])[0] ?? null;
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
        $source = $detoure ?? (quick_add_source_photos($product['ref'])[0] ?? null);
        if (!$source) quick_add_reply(['ok' => false, 'error' => 'Aucune photo à mettre en situation.']);
        // Version 3:2 maintenant ; la 9:16 suit à part (queue_mobile_variant).
        $prompt = build_ambiance_prompt(trim((string) ($_POST['notes'] ?? '')));
        $desktop = generate_desktop_image($root . '/' . $source['path'], $prompt, 'product-' . $product['ref'] . '-ambiance');
        if (!$desktop) quick_add_reply(['ok' => false, 'error' => 'La mise en situation a échoué, réessayez plus tard depuis le catalogue.']);
        queue_mobile_variant(add_product_photo($product['ref'], $desktop, 'Ambiance', true), $source['path'], $prompt);
        quick_add_reply(['ok' => true, 'path' => $desktop, 'label' => 'Ambiance']);
    }

    case 'sheet': {
        $product = quick_add_product();
        if (!GEMINI_API_KEY) quick_add_reply(['ok' => false, 'error' => 'Rédaction automatique indisponible : clé Gemini manquante (réglages du site).']);
        // Jusqu'à 5 angles, réduits pour l'envoi.
        $smalls = [];
        foreach (array_slice(quick_add_source_photos($product['ref']), 0, 5) as $p) {
            $abs = $root . '/' . $p['path'];
            $smalls[] = downscale_for_ai($abs, 900) ?? $abs;
        }
        if (!$smalls) quick_add_reply(['ok' => false, 'error' => 'Aucune photo à analyser.']);
        $text = gemini_describe_image($smalls[0], build_product_sheet_prompt(count($smalls), (string) ($_POST['notes'] ?? '')), 1, array_slice($smalls, 1));
        foreach ($smalls as $i => $small) {
            if (str_starts_with($small, sys_get_temp_dir())) @unlink($small);
        }
        $sheet = $text ? parse_product_sheet_response($text) : null;
        if (!$sheet) quick_add_reply(['ok' => false, 'error' => 'La rédaction automatique a échoué : complétez la fiche à la main.']);
        db()->prepare('UPDATE products SET name = ?, description = ?, cat = ?, price = ? WHERE ref = ?')
            ->execute([$sheet['name'], $sheet['description'] ?: 'Description à compléter.', $sheet['category'], $sheet['price_hint'] ?: '0 €', $product['ref']]);
        quick_add_reply(['ok' => true, 'sheet' => $sheet]);
    }

    case 'save': {
        $product = quick_add_product();
        $validCats = array_column(category_list(), 'key');
        $cat = in_array($_POST['cat'] ?? '', $validCats, true) ? $_POST['cat'] : $product['cat'];
        db()->prepare('UPDATE products SET name = ?, cat = ?, price = ?, badge = ?, description = ?, weight_grams = ?, is_hidden = ? WHERE ref = ?')
            ->execute([
                trim((string) ($_POST['name'] ?? '')) ?: $product['name'],
                $cat,
                trim((string) ($_POST['price'] ?? '')) ?: $product['price'],
                trim((string) ($_POST['badge'] ?? '')),
                trim((string) ($_POST['description'] ?? '')) ?: $product['description'],
                max(0, (int) ($_POST['weight_grams'] ?? $product['weight_grams'])),
                empty($_POST['publish']) ? 1 : 0,
                $product['ref'],
            ]);
        quick_add_reply(['ok' => true, 'ref' => $product['ref'], 'published' => !empty($_POST['publish'])]);
    }
}

quick_add_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
