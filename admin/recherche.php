<?php
// Recherche plein contenu de l'administration boutique : alimente la palette ⌘K
// (marque et script dans includes/admin-nav.php). Réponse JSON ; les pages et les
// fiches renvoyées sont filtrées selon le rôle du compte connecté. Les adresses
// sont préfixées ici — la réécriture automatique du préfixe ne touche que l'HTML.
require_once __DIR__ . '/_search.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function shop_search_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) {
    shop_search_reply(['items' => [], 'error' => 'Session expirée : reconnectez-vous.'], 401);
}

$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
if (mb_strlen($query) < 2) {
    shop_search_reply(['items' => []]);
}

$items = [];
try {
    foreach (shop_search_query($query, admin_role()) as $item) {
        $items[] = [
            'group' => (string) $item['group'],
            'label' => (string) $item['label'],
            'hint' => (string) $item['hint'],
            'href' => app_prefix((string) $item['href']),
        ];
    }
} catch (Throwable $error) {
    error_log('recherche admin : ' . $error->getMessage());
}
shop_search_reply(['items' => $items]);
