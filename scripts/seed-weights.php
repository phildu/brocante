<?php
// Estimation du poids (en grammes) des pièces du catalogue, faute de pesée
// réelle — sert à faire fonctionner la suggestion de transporteur par poids
// (voir includes/functions.php -> matching_shipping_rates()). Base par
// univers + ajustements par mot-clé dans le nom, avec une variation pseudo-
// aléatoire déterministe (seedée sur la réf) pour éviter que toutes les
// pièces d'un même univers pèsent exactement pareil.
//
// À exécuter une fois en CLI :  php scripts/seed-weights.php
// Peut être relancé sans risque : il recalcule et écrase weight_grams à
// chaque exécution — à éviter une fois que des poids réels auront été saisis
// à la main dans l'admin.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

require_once __DIR__ . '/../config.php';

$baseRanges = [
    'ceramique' => [300, 1800],
    'bois' => [1500, 8000],
    'curiosites' => [200, 3000],
    'jardin' => [500, 6000],
    'lumiere' => [800, 3000],
    'textile' => [150, 1200],
];

// [motif (insensible à la casse), poids min, poids max] — vérifiés dans
// l'ordre, le premier motif qui matche le nom l'emporte sur la fourchette
// de l'univers.
$keywordOverrides = [
    ['/violon/i', 400, 900],
    ['/guitare|mandoline/i', 1800, 2800],
    ['/gu[ée]ridon|vitrine|[ée]tag[èe]re/i', 4000, 9000],
    ['/coupelle|coupe\b|bol\b/i', 250, 550],
    ['/plat\b|vase\b|th[ée]i[èe]re/i', 700, 1600],
    ['/plaid|couverture/i', 700, 1300],
    ['/range-bouteilles/i', 2000, 3200],
];

function seeded_weight(string $ref, int $min, int $max): int
{
    // Décimales de sha1(ref) -> variation reproductible sans dépendance à mt_rand.
    $hash = hexdec(substr(sha1($ref), 0, 8));
    $fraction = $hash / 0xFFFFFFFF;
    $weight = $min + (int) round($fraction * ($max - $min));
    // Un lot ("Lot de ...") pèse plus lourd que la pièce seule.
    return $weight;
}

$pdo = db();
$products = $pdo->query('SELECT ref, name, cat FROM products')->fetchAll();

$stmt = $pdo->prepare('UPDATE products SET weight_grams = ? WHERE ref = ?');
$updated = 0;

foreach ($products as $p) {
    [$min, $max] = $baseRanges[$p['cat']] ?? [300, 2000];
    foreach ($keywordOverrides as [$pattern, $kwMin, $kwMax]) {
        if (preg_match($pattern, $p['name'])) {
            [$min, $max] = [$kwMin, $kwMax];
            break;
        }
    }
    $weight = seeded_weight($p['ref'], $min, $max);
    if (preg_match('/^lot de/i', $p['name'])) {
        $weight = (int) round($weight * 1.5);
    }
    $stmt->execute([$weight, $p['ref']]);
    $updated++;
}

echo "Poids estimé pour $updated pièces.\n";
