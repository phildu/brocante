<?php
// Résultats JSON de la recherche globale du portail (palette ⌘K, voir admin.js).
// Recherche le contenu des pages et les fiches gérées, pas seulement les titres.

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_search.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);

// Administrateur d'une galerie : recherche limitée à sa galerie et à ses pages.
if (defined('GA_LIMITED')) {
    require_once __DIR__ . '/_search-galerie.php';
    $items = [];
    try {
        $items = mb_strlen($query) >= 2 ? portail_search_gallery_query(GA_LIMITED, $query) : [];
    } catch (Throwable $error) {
        error_log('recherche galerie : ' . $error->getMessage());
    }
    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(
    ['items' => mb_strlen($query) >= 2 ? portail_search_query($query) : []],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
