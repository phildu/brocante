<?php
/**
 * Tests du module « Boutiques connectées » (includes/connectors.php, api/catalog-push.php).
 *
 *   php scripts/test-connectors.php
 *
 * Lance un faux serveur Shopify/WooCommerce/PrestaShop (scripts/fixtures/connectors-mock.php)
 * et un serveur PHP pour l'API d'envoi, sur une base SQLite, un dossier de secrets et un
 * dossier d'uploads jetables : ni brocante.db, ni uploads/, ni .secrets/ ne sont touchés.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/brocante-test-connectors-' . getmypid();
mkdir($tmp . '/secrets', 0700, true);
mkdir($tmp . '/uploads', 0755, true);
$dbFile = $tmp . '/test.db';
$stateFile = $tmp . '/state.json';
file_put_contents($stateFile, '{}');

define('DB_PATH', $dbFile);
// Base jetable créée à partir de schema.sql : db() (config.php) attend les tables de base d'un commerce (settings, content…).
(new PDO('sqlite:' . $dbFile))->exec(file_get_contents(__DIR__ . '/../schema.sql'));
define('CONNECTOR_ALLOW_PRIVATE', true);
define('CONNECTOR_SECRETS_DIR', $tmp . '/secrets');
define('CONNECTOR_UPLOADS_DIR', $tmp . '/uploads');
putenv('MOCK_STATE=' . $stateFile);

$servers = [];
register_shutdown_function(function () use (&$servers, $tmp) {
    foreach ($servers as $s) { proc_terminate($s); proc_close($s); }
    exec('rm -rf ' . escapeshellarg($tmp));
});

function free_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $port;
}

function start_server(array &$servers, array $args, array $env): int
{
    $port = free_port();
    $cmd = array_merge([PHP_BINARY, '-S', "127.0.0.1:$port"], $args);
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, array_merge(getenv(), $env));
    $servers[] = $proc;
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port, $en, $es, 0.1)) return $port;
        usleep(100000);
    }
    fwrite(STDERR, "Serveur de test injoignable\n");
    exit(2);
}

$prepend = $tmp . '/prepend.php';
file_put_contents($prepend, "<?php\ndefine('CONNECTOR_ALLOW_PRIVATE', true);\ndefine('CONNECTOR_SECRETS_DIR', " . var_export($tmp . '/secrets', true) . ");\ndefine('CONNECTOR_UPLOADS_DIR', " . var_export($tmp . '/uploads', true) . ");\n");

$mockPort = start_server($servers, [$root . '/scripts/fixtures/connectors-mock.php'], ['MOCK_STATE' => $stateFile]);
$apiPort = start_server($servers, ['-d', 'auto_prepend_file=' . $prepend, '-t', $root], ['BROCANTE_DB' => $dbFile]);
$MOCK = "http://127.0.0.1:$mockPort";
$API = "http://127.0.0.1:$apiPort";

require_once $root . '/includes/connectors.php';

$pass = 0;
$fail = 0;
function check(string $label, $actual, $expected = true): void
{
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  ok   $label\n"; return; }
    $fail++;
    echo "  FAIL $label\n       attendu : " . var_export($expected, true) . "\n       obtenu  : " . var_export($actual, true) . "\n";
}
function set_state(array $s): void { global $stateFile; file_put_contents($stateFile, json_encode($s)); }
function product_by_ext(int $sid, string $ext): ?array
{
    $st = db()->prepare('SELECT * FROM products WHERE source_id = ? AND external_id = ?');
    $st->execute([$sid, $ext]);
    return $st->fetch() ?: null;
}
function photo_count(string $ref): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM product_photos WHERE product_ref = ?');
    $st->execute([$ref]);
    return (int) $st->fetchColumn();
}
function make_source(string $name, string $platform, string $url, array $secrets, array $settings = []): array
{
    $pdo = db();
    $pdo->prepare('INSERT INTO import_sources (name, platform, base_url, settings) VALUES (?, ?, ?, ?)')
        ->execute([$name, $platform, $url, json_encode(connector_clean_settings($settings + connector_default_settings()))]);
    $id = (int) $pdo->lastInsertId();
    if ($secrets) connector_secrets_save($id, $secrets);
    return connector_source_get($id);
}
function reload(array $s): array { return connector_source_get((int) $s['id']); }
function expect_throw(callable $fn): string
{
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
}

// Schéma de base minimal (celui de schema.sql) puis colonnes d'import à la volée.
db()->exec(file_get_contents($root . '/schema.sql'));
connectors_ensure_schema();

/* ================================================================== */
echo "Outils de normalisation\n";
check('prix « 12.50 »', connector_money_to_cents('12.50'), 1250);
check('prix « 12,5 € »', connector_money_to_cents('12,5 €'), 1250);
check('prix « 1.234,56 »', connector_money_to_cents('1.234,56'), 123456);
check('prix « 1,234.56 »', connector_money_to_cents('1,234.56'), 123456);
check('prix float 9.9', connector_money_to_cents(9.9), 990);
check('prix négatif refusé', connector_money_to_cents('-3'), null);
check('prix vide', connector_money_to_cents(''), null);
check('prix texte', connector_money_to_cents('gratuit'), null);
check('format 34 €', connector_format_price(3400), '34 €');
check('format 12,50 €', connector_format_price(1250), '12,50 €');
check('format 1 250,50 €', connector_format_price(125050), '1 250,50 €');
check('le prix formaté est relu par la boutique', price_to_cents(connector_format_price(125050)), 125050);
check('poids kg → g', connector_weight_to_grams('0.25', 'kg'), 250);
check('poids lbs → g', connector_weight_to_grams('1', 'lbs'), 454);
check('poids vide', connector_weight_to_grams('', 'kg'), 0);
check('HTML → texte (balises, script, entités)', connector_html_to_text('<p>Beau&nbsp;vase</p><script>alert(1)</script><ul><li>Haut 30 cm</li></ul>'), "Beau vase\n• Haut 30 cm");
check('catégorie : faïence', connector_guess_category('Tasse en faïence', 'curiosites'), 'ceramique');
check('catégorie : lampe', connector_guess_category('Lampe de chevet', 'curiosites'), 'lumiere');
check('catégorie : repli', connector_guess_category('Objet étrange', 'jardin'), 'jardin');
check('adresse Shopify normalisée (https ajouté, chemin retiré)', connector_normalize_base_url('Ma-Boutique.myshopify.com/collections/x', 'shopify')[0], 'https://ma-boutique.myshopify.com');
check('Woo : sous-dossier conservé, fin d\'API retirée', connector_normalize_base_url('http://shop.test/boutique/wp-json/wc/v3', 'woocommerce')[0], 'http://shop.test/boutique');
check('adresse avec identifiants refusée', connector_normalize_base_url('https://a:b@shop.test', 'woocommerce')[0], '');

/* ================================================================== */
echo "\nGarde anti-SSRF (processus séparé, sans le mode test)\n";
$probe = function (string $url) use ($root): string {
    $code = 'require ' . var_export($root . '/includes/connectors.php', true) . '; try { connector_resolve_public(' . var_export($url, true) . '); echo "ALLOWED"; } catch (Throwable $e) { echo "BLOCKED"; }';
    return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($code)));
};
foreach (['https://127.0.0.1/x', 'https://localhost/x', 'https://10.0.0.5/x', 'https://192.168.1.1/x', 'https://169.254.169.254/latest/meta-data',
          'http://example.com/x', 'https://example.com:22/x', 'https://[::1]/x', 'ftp://example.com/x', 'https://user:pw@example.com/x', 'https://0.0.0.0/x'] as $bad) {
    check("refusé : $bad", $probe($bad), 'BLOCKED');
}
check('adresse publique littérale acceptée', $probe('https://93.184.216.34/x'), 'ALLOWED');
$normProbe = function (string $url) use ($root): string {
    $code = 'require ' . var_export($root . '/includes/connectors.php', true) . '; echo connector_normalize_base_url(' . var_export($url, true) . ', "woocommerce")[1] === null ? "OK" : "REFUSED";';
    return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($code)));
};
check('saisie http:// refusée (identifiants en clair)', $normProbe('http://shop.example.com'), 'REFUSED');
check('saisie sans domaine valide refusée', $normProbe('localhost'), 'REFUSED');
check('saisie https:// correcte acceptée', $normProbe('https://shop.example.com'), 'OK');

/* ================================================================== */
echo "\nRéseau : taille, redirections, identifiants\n";
check('réponse géante coupée', expect_throw(fn() => connector_http_get($MOCK . '/giant', ['max_bytes' => 1024])), 'Réponse trop volumineuse.');
$r = connector_http_get($MOCK . '/echo-auth', ['userpwd' => 'u:p']);
check('identifiants envoyés au domaine d\'origine', json_decode($r['body'], true)['auth'], 'Basic ' . base64_encode('u:p'));
$r = connector_http_get($MOCK . '/redirect-other-host', ['userpwd' => 'u:p', 'headers' => ['X-Shopify-Access-Token: secret']]);
$echo = json_decode($r['body'], true);
check('identifiants NON renvoyés après redirection vers un autre domaine', $echo['auth'], null);
check('en-tête de jeton NON renvoyé non plus', $echo['token'], null);
check('image redirigée téléchargée', connector_download_image($MOCK . '/img/redirect') !== null);
check('faux fichier image refusé', connector_download_image($MOCK . '/img/bad.jpg'), null);
check('image géante (10000×10000) refusée sans décodage', connector_download_image($MOCK . '/img/huge.png'), null);
check('image 404 → null', connector_download_image($MOCK . '/img/missing.jpg'), null);
$png = connector_download_image($MOCK . '/img/b.png');
check('PNG transparent → JPEG', substr((string) $png, 0, 3), "\xFF\xD8\xFF");
$im = imagecreatefromstring($png);
check('transparence remplacée par du blanc', imagecolorat($im, 5, 5) & 0xFFFFFF, 0xFFFFFF);

/* ================================================================== */
echo "\nShopify (API Admin)\n";
$shop = make_source('Shopify test', 'shopify', $MOCK, ['token' => 'shpat_test'], ['default_cat' => 'curiosites']);
$r = connector_sync_all_pages($shop);
check('2 pages lues, synchro complète', $r['complete']);
check('4 fiches créées (variantes comprises)', $r['created'], 4);
check('brouillon ignoré', $r['skipped'], 1);
check('aucun échec', [$r['failed'], $r['errors']], [0, []]);

$vase = product_by_ext((int) $shop['id'], '100:1001');
check('nom', $vase['name'], 'Vase en grès');
check('promo : ancien prix barré', [$vase['price'], $vase['promo_price'], $vase['badge']], ['45 €', '34 €', 'Promo']);
check('stock suivi', (int) $vase['stock'], 3);
check('poids', (int) $vase['weight_grams'], 850);
check('masquée par défaut', (int) $vase['is_hidden'], 1);
check('catégorie devinée (type « Céramique »)', $vase['cat'], 'ceramique');
check('description nettoyée', $vase['description'], "Beau vase ancien.\n• Hauteur 30 cm");
check('lien vers l\'original', $vase['external_url'], $MOCK . '/products/vase-en-gres');
check('2 photos téléchargées', photo_count($vase['ref']), 2);
check('photo de couverture posée', str_starts_with((string) db()->query("SELECT photo FROM products WHERE ref = '{$vase['ref']}'")->fetchColumn(), 'uploads/import-'));
check('fichier image écrit', is_file($tmp . '/uploads/' . basename((string) db()->query("SELECT photo FROM products WHERE ref = '{$vase['ref']}'")->fetchColumn())));

$rouge = product_by_ext((int) $shop['id'], '200:2001');
$bleu = product_by_ext((int) $shop['id'], '200:2002');
check('variante nommée', $rouge['name'], 'Plaid — Rouge');
check('stock non suivi → 1', (int) $rouge['stock'], 1);
check('variante épuisée → stock 0', (int) $bleu['stock'], 0);
check('prix sans promo', [$rouge['price'], $rouge['promo_price'], $rouge['badge']], ['20 €', null, 'Sélection']);
check('catégorie textile', $rouge['cat'], 'textile');
check('photo propre à la variante (pas de fichier partagé)', db()->query("SELECT COUNT(DISTINCT path) FROM product_photos WHERE product_ref IN ('{$rouge['ref']}','{$bleu['ref']}')")->fetchColumn() >= 2);
$lampe = product_by_ext((int) $shop['id'], '400:4001');
check('prix à 4 chiffres', $lampe['price'], '1 250,50 €');
check('catégorie via les tags (lampe/luminaire)', $lampe['cat'], 'lumiere');
check('image 404 : fiche créée sans photo', photo_count($lampe['ref']), 0);
check('description vide → texte par défaut', $lampe['description'], 'Description à compléter.');
check('la fiche n\'apparaît pas sur la boutique publique', count(array_filter(get_products(), fn($p) => $p['source_id'] == $shop['id'])), 0);
check('résumé mémorisé', reload($shop)['last_sync_status'], 'ok');

echo "  — re-synchronisation\n";
db()->prepare("UPDATE products SET name = 'Vase RETOUCHÉ', is_hidden = 0, cost_cents = 500 WHERE ref = ?")->execute([$vase['ref']]);
set_state(['shopify_vase_price' => '30.00', 'shopify_vase_stock' => 7, 'shopify_drop_lamp' => true]);
$before = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
$r = connector_sync_all_pages($shop);
check('aucune création', $r['created'], 0);
check('3 fiches mises à jour', $r['updated'], 3);
check('pas de doublon', (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn(), $before);
$vase2 = product_by_ext((int) $shop['id'], '100:1001');
check('prix mis à jour', [$vase2['price'], $vase2['promo_price']], ['45 €', '30 €']);
check('stock mis à jour', (int) $vase2['stock'], 7);
check('nom retouché à la main conservé', $vase2['name'], 'Vase RETOUCHÉ');
check('publication décidée à la main conservée', (int) $vase2['is_hidden'], 0);
check('prix d\'achat conservé', (int) $vase2['cost_cents'], 500);
check('pas de photos en double', photo_count($vase['ref']), 2);
check('produit disparu de la boutique → en rupture (pas supprimé)', [(int) product_by_ext((int) $shop['id'], '400:4001')['stock'], product_by_ext((int) $shop['id'], '400:4001') !== null], [0, true]);
check('retraits comptés dans le résumé', str_contains(reload($shop)['last_sync_summary'], '1 retiré(s)'));

set_state(['shopify_vase_price' => '45.00']);
connector_sync_all_pages($shop);
$vase3 = product_by_ext((int) $shop['id'], '100:1001');
check('fin de promo : badge et prix promo retirés', [$vase3['price'], $vase3['promo_price'], $vase3['badge']], ['45 €', null, 'Sélection']);
$lamp3 = product_by_ext((int) $shop['id'], '400:4001');
check('produit qui revient : remis en vente', (int) $lamp3['stock'], 1);

echo "  — options\n";
$st = db()->prepare('UPDATE import_sources SET settings = ? WHERE id = ?');
$st->execute([json_encode(connector_clean_settings(['overwrite_content' => 1, 'sync_stock' => 0, 'default_cat' => 'curiosites'])), $shop['id']]);
db()->prepare("UPDATE products SET stock = 9 WHERE ref = ?")->execute([$vase['ref']]);
set_state(['shopify_vase_stock' => 2]);
connector_sync_all_pages(reload($shop));
$vase4 = product_by_ext((int) $shop['id'], '100:1001');
check('« réécrire le nom » : nom remis à celui de la boutique', $vase4['name'], 'Vase en grès');
check('« stock local » : stock non écrasé', (int) $vase4['stock'], 9);

echo "  — garde-fous\n";
set_state(['shopify_empty' => true]);
$stockBefore = (int) db()->query("SELECT SUM(stock) FROM products WHERE source_id = {$shop['id']}")->fetchColumn();
$r = connector_sync_all_pages(reload($shop));
check('boutique qui répond « vide » : rien n\'est retiré', (int) db()->query("SELECT SUM(stock) FROM products WHERE source_id = {$shop['id']}")->fetchColumn(), $stockBefore);
check('… et c\'est signalé', str_contains(reload($shop)['last_sync_summary'], 'aucun produit'));
set_state([]);
db()->prepare('UPDATE import_sources SET settings = ? WHERE id = ?')->execute([json_encode(connector_default_settings()), $shop['id']]);

$bad = make_source('Mauvais jeton', 'shopify', $MOCK, ['token' => 'nope']);
$r = connector_sync_all_pages($bad);
check('jeton refusé : synchro incomplète', $r['complete'], false);
check('message lisible', str_contains($r['errors'][0], 'Accès refusé'));
check('statut « error »', reload($bad)['last_sync_status'], 'error');

echo "  — Shopify sans jeton (catalogue public)\n";
$pub = make_source('Shopify public', 'shopify', $MOCK, []);
$r = connector_sync_all_pages($pub);
check('2 fiches', $r['created'], 2);
check('stock déduit de « disponible »', (int) product_by_ext((int) $pub['id'], '100:1001')['stock'], 1);

/* ================================================================== */
echo "\nWooCommerce\n";
$woo = make_source('Woo test', 'woocommerce', $MOCK, ['key' => 'ck_test', 'secret' => 'cs_test'], ['publish_mode' => 'visible']);
$r = connector_sync_all_pages($woo);
check('synchro complète', $r['complete']);
check('4 fiches (simple, 2 variations publiées, simple)', $r['created'], 4);
check('produit groupé ignoré', $r['skipped'], 1);
check('aucun échec', [$r['failed'], $r['errors']], [0, []]);
$b = product_by_ext((int) $woo['id'], '11');
check('promo Woo', [$b['price'], $b['promo_price'], $b['badge']], ['25 €', '18 €', 'Promo']);
check('stock géré', (int) $b['stock'], 2);
check('poids converti selon l\'unité du site (g)', (int) $b['weight_grams'], 500);
check('catégorie via les catégories Woo', $b['cat'], 'lumiere');
check('publiée directement', (int) $b['is_hidden'], 0);
check('visible sur la boutique publique', in_array($b['ref'], array_column(get_products(), 'ref'), true));
$c1 = product_by_ext((int) $woo['id'], '12:121');
$c2 = product_by_ext((int) $woo['id'], '12:122');
check('variation nommée', $c1['name'], 'Chaise — Chêne');
check('poids de la variation', (int) $c1['weight_grams'], 4000);
check('poids hérité du parent', (int) $c2['weight_grams'], 3500);
check('variation épuisée → stock 0', (int) $c2['stock'], 0);
check('variation privée ignorée', product_by_ext((int) $woo['id'], '12:123'), null);
check('catégorie « Mobilier » → bois', $c1['cat'], 'bois');
check('image de variation en premier + image parent', photo_count($c1['ref']), 2);
$d = product_by_ext((int) $woo['id'], '14');
check('description courte en repli', $d['description'], 'Court.');
check('prix 9.9 → 9,90 €', $d['price'], '9,90 €');
check('stock non géré → 1', (int) $d['stock'], 1);
$bad = make_source('Woo mauvaise clé', 'woocommerce', $MOCK, ['key' => 'ck_x', 'secret' => 'cs_x']);
check('clés refusées : message clair', str_contains(expect_throw(fn() => connector_fetch_page($bad, null)), 'Accès refusé'));

/* ================================================================== */
echo "\nPrestaShop\n";
$presta = make_source('Presta test', 'prestashop', $MOCK, ['key' => 'prestakey'], ['vat_rate' => '20']);
$r = connector_sync_all_pages($presta);
check('synchro complète, 2 fiches', [$r['complete'], $r['created'], $r['failed']], [true, 2, 0]);
$t = product_by_ext((int) $presta['id'], '1');
check('nom multilingue', $t['name'], 'Tasse en faïence');
check('TVA ajoutée au prix HT (10 → 12 €)', $t['price'], '12 €');
check('poids kg → g', (int) $t['weight_grams'], 250);
check('stock depuis stock_availables', (int) $t['stock'], 4);
check('catégorie devinée par le nom', $t['cat'], 'ceramique');
check('images téléchargées avec la clé du webservice', photo_count($t['ref']), 2);
$cad = product_by_ext((int) $presta['id'], '2');
check('1re langue sans id_lang', $cad['name'], 'Cadre');
check('description courte en repli', $cad['description'], 'Cadre doré');
check('stock inconnu → 1', (int) $cad['stock'], 1);
check('prix 5 HT → 6 €', $cad['price'], '6 €');
check('mauvaise clé : message clair', str_contains(expect_throw(fn() => connector_fetch_page(make_source('x', 'prestashop', $MOCK, ['key' => 'zzz']), null)), 'Accès refusé'));

/* ================================================================== */
echo "\nAPI d'envoi (plateformes qui poussent)\n";
$push = make_source('Plateforme X', 'api', '', []);
$token = connector_api_new_token((int) $push['id']);
check('jeton au format id.hex', (bool) preg_match('/^' . $push['id'] . '\.[a-f0-9]{48}$/', $token));
check('seule l\'empreinte est stockée', strpos((string) file_get_contents(connector_secrets_path((int) $push['id'])), $token), false);
check('jeton reconnu', connector_api_source_for_token($token)['id'] ?? null, $push['id']);
check('jeton falsifié refusé', connector_api_source_for_token(substr($token, 0, -1) . '0'), null);
check('jeton d\'une autre source refusé', connector_api_source_for_token('999.' . str_repeat('a', 48)), null);

function call_api(string $url, ?string $token, $body, string $method = 'POST', array $extra = []): array
{
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($token !== null) $h[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge($h, $extra),
        CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 30] + ($body !== null ? [CURLOPT_POSTFIELDS => is_string($body) ? $body : json_encode($body)] : []));
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $out, true)];
}
$ep = $API . '/api/catalog-push.php';
check('GET refusé (405)', call_api($ep, $token, null, 'GET')[0], 405);
check('sans jeton (401)', call_api($ep, null, ['products' => [['external_id' => 'a']]])[0], 401);
check('mauvais jeton (401)', call_api($ep, 'abc', ['products' => [['external_id' => 'a']]])[0], 401);
check('X-Api-Key accepté', call_api($ep, null, ['products' => [['external_id' => 'k1', 'name' => 'Via clé', 'price' => 5]]], 'POST', ['X-Api-Key: ' . $token])[0], 200);
check('JSON invalide (400)', call_api($ep, $token, '{pas du json')[0], 400);
check('liste vide (400)', call_api($ep, $token, ['products' => []])[0], 400);
check('trop de produits (413)', call_api($ep, $token, ['products' => array_fill(0, 26, ['external_id' => 'x'])])[0], 413);

[$code, $res] = call_api($ep, $token, ['products' => [
    ['external_id' => 'SKU-1', 'name' => 'Vase pushé', 'description' => '<b>Joli</b>', 'price' => '34,00', 'compare_at_price' => 40, 'stock' => 2, 'weight_grams' => 700, 'category' => 'vase', 'images' => [$MOCK . '/img/a.jpg', 'javascript:alert(1)'], 'url' => 'https://x.test/p/1'],
    ['external_id' => 'SKU-2', 'name' => 'Sans prix'],
    ['external_id' => 'SKU-3', 'name' => 'Épuisé', 'price' => 10, 'active' => false],
    'pas un objet',
]]);
check('200 avec résultats par produit', $code, 200);
check('compteurs', [$res['created'], $res['updated'], $res['failed']], [2, 0, 2]);
check('détail des erreurs', [$res['results'][1]['status'], str_contains($res['results'][1]['error'], 'prix')], ['failed', true]);
$p1 = product_by_ext((int) $push['id'], 'SKU-1');
check('fiche créée via l\'API', [$p1['name'], $p1['price'], $p1['promo_price'], (int) $p1['stock'], (int) $p1['weight_grams'], $p1['cat']], ['Vase pushé', '40 €', '34 €', 2, 700, 'ceramique']);
check('HTML retiré', $p1['description'], 'Joli');
check('image http(s) téléchargée, javascript: ignoré', photo_count($p1['ref']), 1);
check('active:false → stock 0', (int) product_by_ext((int) $push['id'], 'SKU-3')['stock'], 0);
[, $res] = call_api($ep, $token, ['products' => [['external_id' => 'SKU-1', 'name' => 'Vase pushé', 'price' => '30', 'stock' => 1]]]);
check('2e envoi : mise à jour, pas de doublon', [$res['created'], $res['updated']], [0, 1]);
check('prix mis à jour par l\'API', product_by_ext((int) $push['id'], 'SKU-1')['price'], '30 €');
check('résumé du dernier envoi', str_contains(reload($push)['last_sync_summary'], '1 mis à jour'));
[, $res] = call_api($ep, $token, ['products' => [['external_id' => 'SKU-9', 'name' => 'x']]]);
check('tous en échec → 422', call_api($ep, $token, ['products' => [['external_id' => 'SKU-9', 'name' => 'x']]])[0], 422);
$token2 = connector_api_new_token((int) $push['id']);
check('ancien jeton révoqué', call_api($ep, $token, ['products' => [['external_id' => 'z', 'name' => 'z', 'price' => 1]]])[0], 401);
check('nouveau jeton valable', call_api($ep, $token2, ['products' => [['external_id' => 'z', 'name' => 'z', 'price' => 1]]])[0], 200);
db()->prepare('UPDATE import_sources SET is_active = 0 WHERE id = ?')->execute([$push['id']]);
check('source désactivée : refusée', call_api($ep, $token2, ['products' => [['external_id' => 'z', 'name' => 'z', 'price' => 1]]])[0], 401);

/* ================================================================== */
echo "\nSuppression d'une source\n";
$n = (int) db()->query("SELECT COUNT(*) FROM products WHERE source_id = {$presta['id']}")->fetchColumn();
connector_delete_source((int) $presta['id']);
check('les produits restent au catalogue, détachés', [(int) db()->query("SELECT COUNT(*) FROM products WHERE name = 'Tasse en faïence'")->fetchColumn(), (int) db()->query("SELECT COUNT(*) FROM products WHERE source_id = {$presta['id']}")->fetchColumn()], [1, 0]);
check('identifiants effacés', is_file(connector_secrets_path((int) $presta['id'])), false);
check('source supprimée', connector_source_get((int) $presta['id']), null);

echo "\n$pass réussis, $fail échecs\n";
exit($fail ? 1 : 0);
