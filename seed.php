<?php
// Charge les données de démonstration du commerce actif dans sa base. En CLI :
//   php seed.php                  (commerce actif : TENANT, .tenant ou petit-chalet)
//   TENANT=mon-commerce php seed.php
// Crée les tables manquantes (schema.sql) puis vide et recrée le contenu et
// les produits — peut être relancé sans risque.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

require_once __DIR__ . '/config.php';

if (!is_dir(dirname(DB_PATH))) {
    mkdir(dirname(DB_PATH), 0775, true);
}
$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

$data = json_decode(file_get_contents(tenant_path('seed_file')), true, flags: JSON_THROW_ON_ERROR);

$pdo->exec('DELETE FROM content');
$pdo->exec('DELETE FROM products');

$b = $data['brand'] ?? ['name' => tenant('name'), 'tagline' => tenant('tagline')];
$h = $data['hero'];
$s = $data['story'];
$c = $data['contact'];
$sh = $data['shipping'] ?? ['fee' => '6,90 €', 'pickupLabel' => "Retrait à l'atelier", 'shippingLabel' => 'Envoi postal'];
$pr = $s['principles'];

$stmt = $pdo->prepare('INSERT INTO content (
    id, site_name, site_tagline, hero_eyebrow, hero_title, hero_subtitle, hero_photo,
    story_eyebrow, story_title, story_text, story_photo, story_photo_caption,
    principle1_title, principle1_text, principle2_title, principle2_text, principle3_title, principle3_text,
    contact_address, contact_hours, contact_delivery,
    shipping_fee, pickup_label, shipping_label
) VALUES (
    1, :site_name, :site_tagline, :hero_eyebrow, :hero_title, :hero_subtitle, :hero_photo,
    :story_eyebrow, :story_title, :story_text, :story_photo, :story_photo_caption,
    :p1t, :p1x, :p2t, :p2x, :p3t, :p3x,
    :contact_address, :contact_hours, :contact_delivery,
    :shipping_fee, :pickup_label, :shipping_label
)');
$stmt->execute([
    'site_name' => $b['name'], 'site_tagline' => $b['tagline'],
    'hero_eyebrow' => $h['eyebrow'], 'hero_title' => $h['title'], 'hero_subtitle' => $h['subtitle'], 'hero_photo' => $h['photo'],
    'story_eyebrow' => $s['eyebrow'], 'story_title' => $s['title'], 'story_text' => $s['text'],
    'story_photo' => $s['photo'], 'story_photo_caption' => $s['photoCaption'],
    'p1t' => $pr[0]['title'], 'p1x' => $pr[0]['text'],
    'p2t' => $pr[1]['title'], 'p2x' => $pr[1]['text'],
    'p3t' => $pr[2]['title'], 'p3x' => $pr[2]['text'],
    'contact_address' => $c['address'], 'contact_hours' => $c['hours'], 'contact_delivery' => $c['delivery'],
    'shipping_fee' => $sh['fee'], 'pickup_label' => $sh['pickupLabel'], 'shipping_label' => $sh['shippingLabel'],
]);

$stmt = $pdo->prepare('INSERT INTO products (ref, name, cat, photo, icon, description, price, badge, featured, sort_order)
                        VALUES (:ref, :name, :cat, :photo, :icon, :description, :price, :badge, :featured, :sort_order)');

$featuredRefs = $data['featuredRefs'];
foreach ($data['products'] as $i => $p) {
    $stmt->execute([
        'ref' => $p['ref'],
        'name' => $p['name'],
        'cat' => $p['cat'],
        'photo' => $p['photo'] ?? null,
        'icon' => $p['icon'] ?? null,
        'description' => $p['desc'],
        'price' => $p['price'],
        'badge' => $p['badge'],
        'featured' => in_array($p['ref'], $featuredRefs, true) ? 1 : 0,
        'sort_order' => $i,
    ]);
}

echo "« " . $b['name'] . " » : contenu et " . count($data['products']) . " produits importés dans " . DB_PATH . "\n";
