<?php
// « Faire une offre » sur un article au prix négociable : l'offre est enregistrée (Administration → Offres de prix) et les administrateurs de la boutique
// sont prévenus par e-mail. Rien n'est débité : le vendeur répond au client, qui peut ensuite acheter au prix convenu.
//   POST ref, amount (euros), email, name, message ; « website » : champ piège masqué (un robot qui le remplit est ignoré sans réponse différente).
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store');
$ajax = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
function offre_reply(bool $ok, string $message, string $ref = '', bool $ajax = true, int $status = 200): never
{
    if ($ajax) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: ' . app_prefix('/produit.php') . '?ref=' . rawurlencode($ref) . '&offre=' . ($ok ? '1' : '0'));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') offre_reply(false, 'Méthode non autorisée.', '', $ajax, 405);
$ref = (string) ($_POST['ref'] ?? '');
if (trim((string) ($_POST['website'] ?? '')) !== '') offre_reply(true, 'Merci, votre offre est envoyée.', $ref, $ajax);   // robot : réponse identique, rien d'enregistré

$product = get_product($ref);
if (!$product || $product['is_hidden'] || !in_stock($product) || empty($product['negotiable'])) offre_reply(false, "Cet article n'accepte pas d'offre.", $ref, $ajax, 404);
$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) offre_reply(false, 'Indiquez une adresse e-mail valide pour recevoir la réponse.', $ref, $ajax, 422);
$amount = pricing_euros($_POST['amount'] ?? '');
if (!$amount || $amount <= 0) offre_reply(false, 'Indiquez le montant de votre offre.', $ref, $ajax, 422);
$shown = (int) (price_to_cents(effective_price($product)) ?? 0);
if ($shown > 0 && (int) round($amount * 100) >= $shown) offre_reply(false, 'Votre offre atteint le prix affiché (' . effective_price($product) . ') : vous pouvez ajouter l\'article au panier.', $ref, $ajax, 422);

// Freins : 5 offres par heure et par visiteur, et pas deux fois la même offre en 10 minutes.
$now = time();
$_SESSION['offers_sent'] = array_values(array_filter((array) ($_SESSION['offers_sent'] ?? []), static fn ($t) => $now - (int) $t < 3600));
if (count($_SESSION['offers_sent']) >= 5) offre_reply(false, "Vous avez envoyé plusieurs offres : réessayez dans un moment.", $ref, $ajax, 429);
$dup = db()->prepare("SELECT 1 FROM price_offers WHERE ref = ? AND email = ? AND amount_cents = ? AND created_at >= datetime('now', '-10 minutes')");
$cents = (int) round($amount * 100);
$dup->execute([$ref, $email, $cents]);
if (!$dup->fetchColumn()) {
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
    $message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 600);
    db()->prepare('INSERT INTO price_offers (ref, name, email, amount_cents, message) VALUES (?, ?, ?, ?, ?)')->execute([$ref, $name, $email, $cents, $message]);
    $_SESSION['offers_sent'][] = $now;
    // Prévenir les administrateurs (le prix de vente minimum, interne, n'est jamais montré au client : il ne figure que dans ce message).
    $floor = (int) (price_to_cents((string) ($product['price_floor'] ?? '')) ?? 0);
    $site = (string) (get_content()['site_name'] ?: tenant('name'));
    $body = "Nouvelle offre sur « {$product['name']} » (réf. N°{$product['ref']}) :\n\n"
        . 'Offre : ' . pricing_fmt($amount) . ' — prix affiché : ' . effective_price($product) . "\n"
        . ($floor > 0 ? 'Votre prix de vente minimum : ' . pricing_fmt($floor / 100) . ($cents >= $floor ? " → l'offre le respecte.\n" : " → l'offre est en dessous.\n") : '')
        . "De : " . ($name !== '' ? "$name <$email>" : $email) . "\n" . ($message !== '' ? "\nMessage :\n$message\n" : '')
        . "\nRépondez directement au client (adresse ci-dessus), ou retrouvez l'offre dans Administration → Offres de prix.\n";
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $from = 'noreply@' . (str_contains($host, '.') && !str_ends_with($host, '.test') ? $host : 'localhost.localdomain');
    foreach (pricing_admin_emails() as $to) {
        if (function_exists('mail')) @mail($to, '=?UTF-8?B?' . base64_encode("Offre de prix — {$product['name']}") . '?=', $body, "From: $site <$from>\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0");
    }
}
offre_reply(true, 'Merci ! Votre offre est transmise au vendeur, qui vous répond par e-mail.', $ref, $ajax);
