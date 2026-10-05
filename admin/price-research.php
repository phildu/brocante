<?php
// Recherche du prix du marché d'une pièce (admin/catalog.php et relectures) : le même article, neuf et d'occasion, cherché
// sur le web par l'IA, croisé avec les pièces similaires du catalogue. Réponse JSON :
//   {ok, catalogue: {n, median, min, max, items}, web: {identification, new, used, reliability, advice_price, advice_text}|null,
//    sources: [{title, uri}], queries: [...], suggested: "35 €"|null}
// POST ref (pièce existante) ou photo (formulaire d'ajout) ; name, description, materials, etat, nature, sous_categorie,
// size_text, cat, notes : valeurs actuelles du formulaire.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function price_research_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) price_research_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') price_research_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
session_write_close();
@set_time_limit(90);

$ref = (string) ($_POST['ref'] ?? '');
$product = $ref !== '' ? get_product($ref) : null;
if ($ref !== '' && !$product) price_research_reply(['ok' => false, 'error' => 'Pièce introuvable.'], 404);

$text = static fn (string $key, string $fallback = ''): string => mb_substr(trim((string) ($_POST[$key] ?? '')) ?: $fallback, 0, 1200);
$pair = product_nature_resolve($_POST['nature'] ?? ($product['nature'] ?? ''), $_POST['sous_categorie'] ?? ($product['sous_categorie'] ?? ''));
$etat = product_condition(product_condition_key($_POST['etat'] ?? ($product['etat'] ?? '')));
$ctx = [
    'name' => $text('name', (string) ($product['name'] ?? '')),
    'description' => $text('description', (string) ($product['description'] ?? '')),
    'materials' => $text('materials', (string) ($product['materials'] ?? '')),
    'size_text' => $text('size_text', (string) ($product['size_text'] ?? '')),
    'nature' => product_nature_text($pair['nature'], $pair['sous_categorie']),
    'etat' => $etat ? $etat[0] . ' (' . $etat[1] . ')' : '',
    'notes' => $text('notes'),
];
if ($ctx['name'] === '' && $ctx['description'] === '' && !$product && empty($_FILES['photo']['tmp_name'])) {
    price_research_reply(['ok' => false, 'error' => 'Saisissez au moins un nom, ou choisissez une photo.']);
}

// 1) Le catalogue : pièces similaires déjà enregistrées.
$comparables = product_comparables([
    'name' => $ctx['name'], 'nature' => $pair['nature'], 'sous_categorie' => $pair['sous_categorie'],
    'cat' => $text('cat', (string) ($product['cat'] ?? '')),
], $ref);
$catalogue = price_research_catalogue($comparables);

// 2) Le web : photos de la pièce (ou celle du formulaire) pour reconnaître marque et modèle.
$root = realpath(__DIR__ . '/..');
$images = [];
if ($product) {
    foreach (array_slice(product_source_photos($ref), 0, 2) as $p) $images[] = $root . '/' . $p['path'];
    if (!$images && !empty($product['photo']) && is_file($root . '/' . $product['photo'])) $images[] = $root . '/' . $product['photo'];
} elseif (!empty($_FILES['photo']['tmp_name']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && @getimagesize($_FILES['photo']['tmp_name'])) {
    $images[] = $_FILES['photo']['tmp_name'];
}
$smalls = [];
foreach ($images as $path) $smalls[] = downscale_for_ai($path, 900) ?? $path;

$result = GEMINI_API_KEY ? price_research($ctx, $smalls, $comparables) : null;
foreach ($smalls as $i => $small) if (($images[$i] ?? '') !== $small) @unlink($small);

if (!$result && $catalogue['n'] === 0) {
    price_research_reply(['ok' => false, 'error' => GEMINI_API_KEY
        ? "La recherche n'a rien donné (service surchargé ?) : réessayez dans un instant."
        : 'Clé Gemini non configurée (Réglages du site) : la recherche web est indisponible.']);
}

$web = $result['web'] ?? null;
$suggested = $web['advice_price'] ?? ($web['used']['median'] ?? null) ?? $catalogue['median'];
price_research_reply([
    'ok' => true,
    'catalogue' => $catalogue,
    'web' => $web,
    'sources' => $result['sources'] ?? [],
    'queries' => $result['queries'] ?? [],
    'suggested' => $suggested ? price_research_format((float) $suggested) : null,
    'suggested_from' => !$suggested ? null : ($web && isset($web['advice_price']) ? 'web' : (($web['used']['median'] ?? null) ? 'web' : 'catalogue')),
]);
