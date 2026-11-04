<?php
// Paiement d'une pièce par QR code (fiche produit → includes/shop-qr.php).
// Le client, dans la boutique, scanne le code avec son téléphone, choisit son
// moyen de paiement et règle en ligne. La commande est une vente en boutique :
// dès la confirmation, la pièce passe en « Vendue » (order_mark_shop_qr_sold).
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/shop-qr.php';

if (!shop_sales_enabled()) {
    header('Location: /boutique.php', true, 302);
    exit;
}

$ref = (string) ($_POST['ref'] ?? $_GET['ref'] ?? '');
$product = $ref !== '' ? get_product($ref) : null;
$methods = shop_qr_payment_methods();
$content = get_content();
$shopName = (string) (($content['site_name'] ?? '') ?: tenant('name'));
$error = '';
$notice = isset($_GET['cancel']) ? 'Paiement annulé : vous pouvez réessayer quand vous voulez.' : '';

$emailPrefill = (string) ($_SESSION['checkout_contact']['email'] ?? '');
if ($emailPrefill === '' && !empty($_SESSION['customer']['email']) && ($_SESSION['customer']['tenant'] ?? '') === tenant_slug()) {
    $emailPrefill = (string) $_SESSION['customer']['email'];
}

$payable = $product && shop_qr_payable($product);
if ($product && $payable && product_free_qty($product) < 1) {
    $payable = false;
    $error = product_held_message((string) $product['name']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cart_csrf_valid()) {
        flash_set('La page avait expiré : recommencez le paiement, s’il vous plaît.', 'error');
        header('Location: /payer.php?ref=' . rawurlencode($ref));
        exit;
    }
    if (!$product) {
        flash_set('Cette pièce n’existe plus.', 'error');
        header('Location: /boutique.php');
        exit;
    }
    if (!$payable || $methods === []) {
        flash_set($error !== '' ? $error : 'Cette pièce ne peut pas être réglée en ligne pour le moment.', 'error');
        header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
        exit;
    }

    $method = (string) ($_POST['payment_method'] ?? '');
    if (!isset($methods[$method])) $method = (string) array_key_first($methods);
    if ($method === '') {
        flash_set('Aucun moyen de paiement n’est disponible pour le moment.', 'error');
        header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
        exit;
    }

    $email = mb_strtolower(mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 120));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('L’adresse e-mail n’est pas valide (laissez-la vide si vous ne voulez pas de reçu par e-mail).', 'error');
        header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
        exit;
    }

    $cents = price_to_cents((string) effective_price($product));
    if ($cents === null || $cents < 50) {
        flash_set('Cette pièce ne peut pas être réglée en ligne (montant trop faible).', 'error');
        header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
        exit;
    }

    $items = [[
        'ref' => (string) $product['ref'],
        'name' => (string) $product['name'],
        'qty' => 1,
        'unit_cents' => $cents,
    ]];
    $payUrl = shop_qr_pay_url($product);
    $cancelUrl = $payUrl . (str_contains($payUrl, '?') ? '&' : '?') . 'cancel=1';
    $photo = !empty($product['photo']) ? shop_media_url((string) $product['photo']) : '';
    $inShopMethod = 'Vente en boutique — QR';

    if ($method === 'stripe') {
        $params = [
            'mode' => 'payment',
            'locale' => 'fr',
            'success_url' => SITE_URL . '/checkout-success.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => ['type' => 'shop_order', 'shop' => tenant_slug(), 'qr_ref' => (string) $product['ref']],
            'expires_at' => time() + CHECKOUT_HOLD_MINUTES * 60,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $cents,
                    'product_data' => ['name' => mb_substr((string) $product['name'], 0, 100)],
                ],
            ]],
        ];
        if ($photo !== '') $params['line_items'][0]['price_data']['product_data']['images'][0] = $photo;
        if ($email !== '') $params['customer_email'] = $email;

        // Compte Stripe relié à la plateforme : même règle que le panier (commission plateforme).
        $connect = function_exists('connect_plan') ? connect_plan($cents) : null;
        if ($connect) {
            $params['payment_intent_data'] = [
                'application_fee_amount' => $connect['fee'],
                'transfer_data' => ['destination' => $connect['account']],
                'metadata' => ['type' => 'shop_order', 'shop' => tenant_slug(), 'qr_ref' => (string) $product['ref']],
            ];
        }

        try {
            $session = stripe_request('POST', '/checkout/sessions', $params, (bool) $connect);
        } catch (Throwable $e) {
            flash_set('Impossible d’ouvrir le paiement : ' . $e->getMessage(), 'error');
            header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
            exit;
        }

        try {
            db()->prepare('INSERT INTO orders (stripe_session_id, amount_total, status, items, payment_via, connect_fee_cents, connect_gallery_cents, email, shop_slug, shipping_mode, shipping_label, shipping_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    (string) $session['id'],
                    $cents,
                    'pending',
                    json_encode($items, JSON_UNESCAPED_UNICODE),
                    $connect ? 'connect' : null,
                    $connect ? $connect['fee'] : null,
                    $connect ? $connect['gallery_cents'] : null,
                    $email !== '' ? $email : null,
                    tenant_slug(),
                    'pickup',
                    'Paiement en boutique',
                    $inShopMethod,
                ]);
        } catch (Throwable $e) {
            flash_set('Impossible de créer la commande : ' . $e->getMessage(), 'error');
            header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
            exit;
        }
        checkout_remember_order((int) db()->lastInsertId());
        header('Location: ' . (string) $session['url'], true, 303);
        exit;
    }

    // Monetico, PayPal, Revolut : la commande est créée ici, puis le client est
    // envoyé sur la page sécurisée du prestataire (retours : *-return.php).
    $reference = payment_reference();
    try {
        db()->prepare('INSERT INTO orders (stripe_session_id, amount_total, shipping_cents, status, items, payment_provider, payment_reference, provider_checkout_id, email, shop_slug, shipping_mode, shipping_label, shipping_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $method . ':' . $reference,
                $cents,
                0,
                'pending',
                json_encode($items, JSON_UNESCAPED_UNICODE),
                $method,
                $reference,
                $reference,
                $email !== '' ? $email : null,
                tenant_slug(),
                'pickup',
                'Paiement en boutique',
                $inShopMethod,
            ]);
    } catch (Throwable $e) {
        flash_set('Impossible de créer la commande : ' . $e->getMessage(), 'error');
        header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
        exit;
    }
    $orderId = (int) db()->lastInsertId();
    checkout_remember_order($orderId);
    $order = order_get($orderId);
    $cancelExternal = static function (int $id, string $provider, string $reason): void {
        db()->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'pending'")->execute([gmdate('c'), $id]);
        order_event($id, 'annulee', 'Ouverture du paiement QR impossible : ' . $reason, $provider);
    };

    if ($method === 'monetico') {
        $config = monetico_config();
        if (!$config || !$order) {
            $cancelExternal($orderId, 'monetico', 'réglages absents');
            flash_set('Monetico n’est pas configuré pour cette boutique.', 'error');
            header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
            exit;
        }
        $fields = monetico_payment_fields($order, $config, $cents, $reference, $email, $shopName, rtrim(SITE_URL, '/'));
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Paiement sécurisé</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f2ec;color:#2a2420;font-family:system-ui,-apple-system,sans-serif;}
  .box{max-width:420px;padding:28px;text-align:center}
  .spinner{width:34px;height:34px;margin:0 auto 18px;border:3px solid #d8d0c4;border-top-color:#8a5a2b;border-radius:50%;animation:spin 1s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  button{margin-top:18px;padding:12px 22px;border:0;border-radius:8px;background:#2a2420;color:#fff;font-size:1rem;cursor:pointer}
</style>
</head>
<body>
<div class="box">
  <div class="spinner" aria-hidden="true"></div>
  <h1 style="font-size:1.2rem;margin:0 0 8px;">Redirection vers le paiement sécurisé</h1>
  <p style="margin:0;color:#6b6259;">Vous allez être redirigé vers Monetico. Ne fermez pas cette page.</p>
  <form method="post" action="<?= h(monetico_payment_url($config)) ?>" id="monetico-form">
    <?php foreach ($fields as $fieldName => $fieldValue): ?>
      <input type="hidden" name="<?= h($fieldName) ?>" value="<?= h($fieldValue) ?>">
    <?php endforeach; ?>
    <noscript><button type="submit">Payer maintenant</button></noscript>
  </form>
</div>
<script>document.getElementById('monetico-form').submit();</script>
</body>
</html><?php
        exit;
    }

    if ($method === 'paypal') {
        $config = paypal_config();
        if (!$config || !$order) {
            $cancelExternal($orderId, 'paypal', 'réglages absents');
            flash_set('PayPal n’est pas configuré pour cette boutique.', 'error');
            header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
            exit;
        }
        try {
            $returnUrl = rtrim(SITE_URL, '/') . '/paypal-return.php?order=' . $orderId . '&sig=' . rawurlencode(payment_return_token($orderId, 'paypal'));
            $paypalOrder = paypal_create_order($config, $cents, $reference, $returnUrl, $cancelUrl, $shopName);
            $paypalId = (string) ($paypalOrder['id'] ?? '');
            $approve = paypal_approve_url($paypalOrder);
            if ($paypalId === '' || $approve === '') throw new RuntimeException('PayPal n’a pas renvoyé de lien de paiement.');
            db()->prepare('UPDATE orders SET provider_checkout_id = ? WHERE id = ?')->execute([$paypalId, $orderId]);
            header('Location: ' . $approve, true, 303);
            exit;
        } catch (Throwable $e) {
            $cancelExternal($orderId, 'paypal', $e->getMessage());
            flash_set('Le paiement PayPal n’a pas pu être ouvert : ' . $e->getMessage(), 'error');
            header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
            exit;
        }
    }

    if ($method === 'revolut') {
        $config = revolut_config();
        if (!$config || !$order) {
            $cancelExternal($orderId, 'revolut', 'réglages absents');
            flash_set('Revolut n’est pas configuré pour cette boutique.', 'error');
            header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
            exit;
        }
        try {
            $returnUrl = rtrim(tenant_site_url(), '/') . '/revolut-return.php?order=' . $orderId . '&sig=' . rawurlencode(payment_return_token($orderId, 'revolut'));
            $revolutOrder = revolut_create_order($config, $cents, $reference, $returnUrl, $email, $shopName);
            $checkoutUrl = revolut_checkout_url($revolutOrder);
            $revolutId = (string) ($revolutOrder['id'] ?? '');
            if ($revolutId === '' || $checkoutUrl === '') throw new RuntimeException('Revolut n’a pas renvoyé de lien de paiement.');
            db()->prepare('UPDATE orders SET provider_checkout_id = ? WHERE id = ?')->execute([$revolutId, $orderId]);
            header('Location: ' . $checkoutUrl, true, 303);
            exit;
        } catch (Throwable $e) {
            $cancelExternal($orderId, 'revolut', $e->getMessage());
            flash_set('Le paiement Revolut n’a pas pu être ouvert : ' . $e->getMessage(), 'error');
            header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
            exit;
        }
    }

    flash_set('Ce moyen de paiement n’est pas disponible.', 'error');
    header('Location: /payer.php?ref=' . rawurlencode((string) $product['ref']));
    exit;
}

$activeNav = 'boutique';
$pageTitle = $product ? 'Payer ' . $product['name'] : 'Paiement en boutique';
http_response_code($product ? 200 : 404);
include __DIR__ . '/includes/header.php';

$priceHtml = $product && shop_sales_enabled()
    ? (has_promo_price($product)
        ? '<span class="price-old">' . h($product['price']) . '</span> <span class="price price-promo">' . h(effective_price($product)) . '</span>'
        : '<span class="price">' . h($product['price']) . '</span>')
    : '';
$photoUrl = $product && !empty($product['photo']) ? '/' . ltrim((string) $product['photo'], '/') : '';
?>
<section class="tight pay-page">
  <div class="wrap" style="max-width:720px;">
    <?php if (!$product): ?>
      <p class="eyebrow">Paiement en boutique</p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Pièce introuvable</h1>
      <p class="lede">Cette pièce n’existe plus ou a été vendue. <a href="/boutique.php" style="color:var(--accent);">Retour à la boutique →</a></p>
    <?php else: ?>
      <p class="eyebrow"><a href="/produit.php?ref=<?= urlencode((string) $product['ref']) ?>" style="color:inherit;text-decoration:none;">← Retour à la fiche</a> / Paiement en boutique</p>
      <h1 style="font-size:clamp(1.5rem,3vw,2rem);margin:12px 0 8px;">Payer cette pièce</h1>
      <p class="lede" style="margin-bottom:22px;">Vous êtes dans la boutique : réglez avec votre téléphone, la pièce vous est remise dès le paiement confirmé.</p>

      <div class="pay-product">
        <?php if ($photoUrl !== ''): ?><img src="<?= h($photoUrl) ?>" alt=""><?php endif; ?>
        <div>
          <strong><?= h($product['name']) ?></strong>
          <span class="pay-ref">Réf. N°<?= h($product['ref']) ?></span>
        </div>
        <span class="price-group"><?= $priceHtml ?></span>
      </div>

      <?php if ($notice !== ''): ?>
        <p class="pay-notice"><?= h($notice) ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="pay-notice pay-notice-error"><?= h($error) ?></p>
      <?php endif; ?>

      <?php if (!$payable): ?>
        <div class="pay-card">
          <p style="margin:0 0 8px;font-weight:600;">Le paiement en ligne n’est pas disponible pour cette pièce.</p>
          <p style="margin:0;color:var(--ink-soft);font-size:0.92rem;">Adressez-vous au vendeur : il peut l’encaisser directement dans la boutique.</p>
        </div>
      <?php else: ?>
        <form method="post" action="/payer.php" class="pay-card pay-form">
          <?= cart_csrf_field() ?>
          <input type="hidden" name="ref" value="<?= h((string) $product['ref']) ?>">
          <fieldset class="pay-methods">
            <legend>Moyen de paiement</legend>
            <?php foreach ($methods as $key => $label): ?>
              <label class="pay-method">
                <input type="radio" name="payment_method" value="<?= h($key) ?>"<?= $key === array_key_first($methods) ? ' checked' : '' ?>>
                <span><?= h($label) ?></span>
              </label>
            <?php endforeach; ?>
          </fieldset>
          <label class="pay-email">
            <span>E-mail pour le reçu <em>(facultatif)</em></span>
            <input type="email" name="email" value="<?= h($emailPrefill) ?>" inputmode="email" autocomplete="email" maxlength="120" placeholder="vous@exemple.fr">
          </label>
          <button type="submit" class="btn btn-primary pay-submit">Payer <?= h((string) effective_price($product)) ?></button>
          <p class="pay-legal">Paiement sécurisé chez <?= h($shopName) ?>. Dès la confirmation, la pièce passe en « Vendue » et vous est remise sur place.</p>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
