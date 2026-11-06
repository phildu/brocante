<?php
if (php_sapi_name() !== 'cli-server') { http_response_code(403); exit('Fixture de test : serveur PHP intégré uniquement.'); }
/**
 * Faux serveur « Shopify + WooCommerce + PrestaShop » pour scripts/test-connectors.php
 * (php -S 127.0.0.1:PORT scripts/fixtures/connectors-mock.php). L'état modifiable
 * par les tests est lu à chaque requête dans le fichier JSON de MOCK_STATE.
 */
$state = json_decode((string) @file_get_contents(getenv('MOCK_STATE') ?: ''), true) ?: [];
$S = fn(string $k, $default = null) => $state[$k] ?? $default;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$q = $_GET;
$self = 'http://' . $_SERVER['HTTP_HOST'];

function out($data, int $status = 200, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $h) header($h);
    echo json_encode($data);
    exit;
}

function image(string $kind): void
{
    $im = imagecreatetruecolor(40, 30);
    if ($kind === 'png') {
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        header('Content-Type: image/png');
        imagepng($im);
    } else {
        imagefill($im, 0, 0, imagecolorallocate($im, 180, 90, 40));
        header('Content-Type: image/jpeg');
        imagejpeg($im);
    }
    exit;
}

/* ---------- images ---------- */
if ($path === '/img/a.jpg') image('jpg');
if ($path === '/img/b.png') image('png');
if ($path === '/img/bad.jpg') { header('Content-Type: image/jpeg'); echo 'ceci n\'est pas une image'; exit; }
if ($path === '/img/redirect') { header('Location: /img/a.jpg', true, 302); exit; }
if ($path === '/img/huge.png') { // en-tête PNG annonçant 10000×10000 : doit être refusé sans décodage
    header('Content-Type: image/png');
    echo "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 10000, 10000) . "\x08\x06\x00\x00\x00" . pack('N', 0);
    exit;
}
if ($path === '/echo-auth') out(['auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['PHP_AUTH_USER'] ?? null), 'token' => $_SERVER['HTTP_X_SHOPIFY_ACCESS_TOKEN'] ?? null]);
if ($path === '/redirect-other-host') { header('Location: http://localhost:' . $_SERVER['SERVER_PORT'] . '/echo-auth', true, 302); exit; }
if ($path === '/giant') { header('Content-Type: application/json'); echo str_repeat('x', 3 * 1024 * 1024); exit; }

/* ---------- Shopify ---------- */
$shopifyAll = function () use ($self, $S): array {
    $p1 = ['id' => 100, 'title' => 'Vase en grès', 'handle' => 'vase-en-gres', 'status' => 'active', 'product_type' => 'Céramique', 'tags' => '',
        'body_html' => '<p>Beau vase&nbsp;ancien.</p><script>alert(1)</script><ul><li>Hauteur 30 cm</li></ul>',
        'images' => [['id' => 1, 'src' => $self . '/img/a.jpg'], ['id' => 2, 'src' => $self . '/img/b.png']],
        'variants' => [['id' => 1001, 'title' => 'Default Title', 'price' => (string) $S('shopify_vase_price', '34.00'), 'compare_at_price' => '45.00',
            'inventory_management' => 'shopify', 'inventory_quantity' => $S('shopify_vase_stock', 3), 'grams' => 850, 'image_id' => null, 'available' => true]]];
    $p2 = ['id' => 200, 'title' => 'Plaid', 'handle' => 'plaid', 'status' => 'active', 'product_type' => 'Textile', 'tags' => ['laine'], 'body_html' => '<p>Doux.</p>',
        'images' => [['id' => 2, 'src' => $self . '/img/b.png']],
        'variants' => [
            ['id' => 2001, 'title' => 'Rouge', 'price' => '20.00', 'compare_at_price' => null, 'inventory_management' => null, 'inventory_quantity' => 0, 'grams' => 300, 'image_id' => 2, 'available' => true],
            ['id' => 2002, 'title' => 'Bleu', 'price' => '20.00', 'compare_at_price' => null, 'inventory_management' => 'shopify', 'inventory_quantity' => 0, 'grams' => 300, 'image_id' => null, 'available' => false]]];
    $p3 = ['id' => 300, 'title' => 'Brouillon', 'status' => 'draft', 'variants' => [['id' => 3001, 'price' => '1.00']]];
    $p4 = ['id' => 400, 'title' => 'Lampe à pétrole', 'handle' => 'lampe', 'status' => 'active', 'product_type' => '', 'tags' => 'luminaire, vintage', 'body_html' => '',
        'images' => [['id' => 9, 'src' => $self . '/img/missing.jpg']],
        'variants' => [['id' => 4001, 'title' => 'Default Title', 'price' => '1250.50', 'compare_at_price' => '', 'inventory_management' => 'shopify', 'inventory_quantity' => 1, 'grams' => 2000, 'image_id' => null, 'available' => true]]];
    return [$p1, $p2, $p3, $p4];
};

if ($path === '/admin/api/2025-10/products.json') {
    if (($_SERVER['HTTP_X_SHOPIFY_ACCESS_TOKEN'] ?? '') !== 'shpat_test') out(['errors' => 'Unauthorized'], 401);
    [$p1, $p2, $p3, $p4] = $shopifyAll();
    if (($q['page_info'] ?? '') === 'PAGE2TOKEN') out(['products' => $S('shopify_drop_lamp') ? [] : [$p4]]);
    $hdr = ['Link: <' . $self . '/admin/api/2025-10/products.json?limit=20&page_info=PAGE2TOKEN>; rel="next"'];
    if ($S('shopify_empty')) out(['products' => []]);
    out(['products' => [$p1, $p2, $p3]], 200, $hdr);
}
if ($path === '/products.json') { // catalogue public
    [$p1, , , $p4] = $shopifyAll();
    out(['products' => ((int) ($q['page'] ?? 1)) === 1 ? [$p1, $p4] : []]);
}

/* ---------- WooCommerce ---------- */
if (str_starts_with($path, '/wp-json/wc/v3/')) {
    if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'ck_test' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'cs_test') out(['code' => 'woocommerce_rest_cannot_view', 'message' => 'Sorry'], 401);
    $sub = substr($path, strlen('/wp-json/wc/v3'));
    if ($sub === '/settings/products/woocommerce_weight_unit') out(['id' => 'woocommerce_weight_unit', 'value' => 'g']);
    if ($sub === '/products/12/variations') {
        out([
            ['id' => 121, 'status' => 'publish', 'price' => '60', 'regular_price' => '60', 'on_sale' => false, 'manage_stock' => true, 'stock_quantity' => 1, 'stock_status' => 'instock', 'attributes' => [['option' => 'Chêne']], 'weight' => '4000', 'image' => ['src' => $self . '/img/b.png']],
            ['id' => 122, 'status' => 'publish', 'price' => '65', 'regular_price' => '65', 'on_sale' => false, 'manage_stock' => false, 'stock_status' => 'outofstock', 'attributes' => [['option' => 'Noyer']], 'weight' => '', 'image' => null],
            ['id' => 123, 'status' => 'private', 'price' => '70', 'regular_price' => '70', 'attributes' => [['option' => 'Hêtre']]],
        ]);
    }
    if ($sub === '/products') {
        $page = (int) ($q['page'] ?? 1);
        $img = [['src' => $self . '/img/a.jpg']];
        if ($page === 1) {
            out([
                ['id' => 11, 'type' => 'simple', 'name' => 'Bougeoir', 'permalink' => 'https://shop.test/bougeoir', 'description' => '<p>En laiton.</p>', 'short_description' => '', 'price' => '18.00', 'regular_price' => '25.00', 'on_sale' => true,
                    'manage_stock' => true, 'stock_quantity' => 2, 'stock_status' => 'instock', 'weight' => '500', 'categories' => [['name' => 'Lumière']], 'images' => $img],
                ['id' => 12, 'type' => 'variable', 'name' => 'Chaise', 'permalink' => 'https://shop.test/chaise', 'description' => 'Une chaise.', 'short_description' => '', 'price' => '60', 'regular_price' => '', 'on_sale' => false,
                    'manage_stock' => false, 'stock_status' => 'instock', 'weight' => '3500', 'categories' => [['name' => 'Mobilier']], 'images' => $img],
            ], 200, ['X-WP-TotalPages: 2', 'X-WP-Total: 4']);
        }
        out([
            ['id' => 13, 'type' => 'grouped', 'name' => 'Lot', 'price' => '', 'images' => []],
            ['id' => 14, 'type' => 'simple', 'name' => 'Cadre', 'permalink' => '', 'description' => '', 'short_description' => '<p>Court.</p>', 'price' => '9.9', 'regular_price' => '9.9', 'on_sale' => false,
                'manage_stock' => false, 'stock_status' => 'instock', 'weight' => '', 'categories' => [], 'images' => []],
        ], 200, ['X-WP-TotalPages: 2']);
    }
    out(['code' => 'rest_no_route'], 404);
}

/* ---------- PrestaShop ---------- */
if (str_starts_with($path, '/api/')) {
    if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'prestakey') out(['errors' => [['message' => 'Authentication required']]], 401);
    if ($path === '/api/products') {
        out(['products' => [
            ['id' => 1, 'active' => '1', 'name' => [['id' => 1, 'value' => 'Tasse en faïence']], 'description' => [['id' => 1, 'value' => '<p>Jolie tasse.</p>']], 'description_short' => [['id' => 1, 'value' => '']],
                'price' => '10.000000', 'weight' => '0.250000', 'id_default_image' => '10', 'associations' => ['images' => [['id' => '11'], ['id' => '10']]]],
            ['id' => 2, 'active' => '1', 'name' => [['id' => 1, 'value' => 'Cadre'], ['id' => 2, 'value' => 'Frame']], 'description' => [['id' => 1, 'value' => '']], 'description_short' => [['id' => 1, 'value' => 'Cadre doré']],
                'price' => '5', 'weight' => '0', 'id_default_image' => '0', 'associations' => []],
        ]]);
    }
    if ($path === '/api/stock_availables') out(['stock_availables' => [['id_product' => '1', 'quantity' => '4']]]);
    if (preg_match('#^/api/images/products/(\d+)/(\d+)$#', $path)) image('jpg');
}

out(['error' => 'not found: ' . $path], 404);
