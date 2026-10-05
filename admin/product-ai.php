<?php
// (Re)génère par l'IA des champs d'une fiche produit depuis le formulaire du catalogue :
// nom, description, catégorie, matières, prix — un seul, ou tous. Réponse JSON.
//   POST fields   : liste (« name,price »), vide = tous
//   POST ref      : pièce existante → l'IA regarde ses photos d'origine
//   POST photo    : sinon, la photo choisie dans le formulaire d'ajout (pas encore envoyée)
//   POST name, description, cat, materials, price, size_text : valeurs actuelles du formulaire (contexte)
// Sans photo, l'IA travaille d'après le texte déjà saisi.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function product_ai_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) {
    product_ai_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    product_ai_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}
if (!GEMINI_API_KEY) {
    product_ai_reply(['ok' => false, 'error' => 'Clé Gemini non configurée (Réglages du site).']);
}
session_write_close(); // ne bloque pas la navigation pendant l'appel à l'IA
@set_time_limit(90);

$requested = array_values(array_intersect(PRODUCT_AI_FIELDS, array_filter(explode(',', (string) ($_POST['fields'] ?? '')))));
$fields = $requested ?: PRODUCT_AI_FIELDS;

// Valeurs actuelles du formulaire : contexte, bornées.
$current = [];
foreach (['name', 'description', 'materials', 'price', 'size_text'] as $key) {
    $current[$key] = mb_substr(trim((string) ($_POST[$key] ?? '')), 0, 1200);
}
$pair = product_nature_resolve($_POST['nature'] ?? '', $_POST['sous_categorie'] ?? '');
if ($pair['nature'] !== '') $current['nature'] = product_nature_text($pair['nature'], $pair['sous_categorie']);
$etat = product_condition(product_condition_key($_POST['etat'] ?? ''));
if ($etat) $current['etat'] = $etat[0];
$validCats = array_column(category_list(), 'key');
$cat = (string) ($_POST['cat'] ?? '');
foreach (category_list() as $c) {
    if ($c['key'] === $cat) $current['category'] = $c['label'];
}

// Photos à regarder : celles de la pièce, sinon la photo choisie dans le formulaire.
$root = realpath(__DIR__ . '/..');
$sources = [];
$ref = (string) ($_POST['ref'] ?? '');
if ($ref !== '') {
    $product = get_product($ref);
    if (!$product) product_ai_reply(['ok' => false, 'error' => 'Pièce introuvable.'], 404);
    foreach (array_slice(product_source_photos($ref), 0, 4) as $p) {
        $sources[] = $root . '/' . $p['path'];
    }
    // Pièce ajoutée à la main : sa photo vit dans la fiche, sans ligne dans la galerie.
    if (!$sources && !empty($product['photo']) && is_file($root . '/' . $product['photo'])) {
        $sources[] = $root . '/' . $product['photo'];
    }
} elseif (!empty($_FILES['photo']['tmp_name']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && @getimagesize($_FILES['photo']['tmp_name'])) {
    $sources[] = $_FILES['photo']['tmp_name'];
}

$smalls = [];
foreach ($sources as $path) {
    $smalls[] = downscale_for_ai($path, 900) ?? $path;
}
$hasText = $current['name'] !== '' || $current['description'] !== '' || $current['materials'] !== '';
if (!$smalls && !$hasText) {
    product_ai_reply(['ok' => false, 'error' => "Rien à analyser : choisissez d'abord une photo, ou saisissez au moins un nom."]);
}

$prompt = build_product_fields_prompt($fields, $current, count($smalls), (string) ($_POST['notes'] ?? ''));
$text = gemini_describe_image($smalls[0] ?? '', $prompt, 1, array_slice($smalls, 1));
foreach ($smalls as $i => $small) {
    if (($sources[$i] ?? '') !== $small) @unlink($small);
}
$values = $text ? parse_product_fields_response($text, $fields) : [];
if (!$values) {
    product_ai_reply(['ok' => false, 'error' => "L'IA n'a pas pu répondre (service surchargé ?) : réessayez dans un instant."]);
}
// Détection en série depuis le catalogue (save=1, nature seule, pièce existante) : la nature est enregistrée tout de suite.
if (!empty($_POST['save']) && $ref !== '' && $fields === ['nature'] && !empty($values['nature'])) {
    db()->prepare('UPDATE products SET nature = ?, sous_categorie = ? WHERE ref = ?')
        ->execute([$values['nature'], $values['sous_categorie'] ?? null, $ref]);
}
product_ai_reply(['ok' => true, 'values' => $values, 'fields' => $fields]);
