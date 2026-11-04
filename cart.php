<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

if (!shop_sales_enabled()) {
    header('Location: /boutique.php', true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Formulaire sans le jeton de la session (autre site, page restée ouverte trop longtemps) : panier inchangé.
    if (!cart_csrf_valid()) {
        flash_set("La page avait expiré : le panier n'a pas été modifié. Recommencez, s'il vous plaît.", 'error');
        header('Location: /cart.php');
        exit;
    }
    $ref = (string) ($_POST['ref'] ?? '');
    if ($_POST['action'] === 'remove') {
        cart_remove($ref);
    } elseif ($_POST['action'] === 'qty') {
        cart_set_qty($ref, max(0, (int) ($_POST['qty'] ?? 1)));
    } elseif ($_POST['action'] === 'set_destination') {
        $clean = static fn (string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
        // Adresse de l'acheteur : toujours demandée, sert aussi d'adresse de livraison quand le destinataire est l'acheteur.
        $buyerCountry = strtoupper($clean('buyer_country', 2)) ?: 'FR';
        if (!array_key_exists($buyerCountry, shipping_allowed_countries())) $buyerCountry = 'FR';
        $_SESSION['buyer_country'] = $buyerCountry;
        $_SESSION['buyer_line1'] = $clean('buyer_line1', 120);
        $_SESSION['buyer_postal'] = preg_replace('/[^0-9A-Za-z]/', '', $clean('buyer_postal', 12));
        $_SESSION['buyer_city'] = $clean('buyer_city', 80);
        // Adresse choisie dans la liste ou trouvée par « Me localiser » : gardée pour l'afficher (jamais la position GPS).
        $buyerLabel = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['buyer_label'] ?? ''))), 0, 160);
        if ($buyerLabel !== '' && $_SESSION['buyer_postal'] !== '') $_SESSION['buyer_address_label'] = $buyerLabel; else unset($_SESSION['buyer_address_label']);
        $shipOther = !empty($_POST['ship_other']);
        // Adresse de livraison : celle de l'acheteur, sauf si le client livre ailleurs (autre adresse ou autre personne).
        if ($shipOther) {
            $country = strtoupper($clean('dest_country', 2)) ?: 'FR';
            $_SESSION['shipping_country'] = array_key_exists($country, shipping_allowed_countries()) ? $country : 'FR';
            $_SESSION['shipping_postal_code'] = preg_replace('/[^0-9A-Za-z]/', '', $clean('dest_postal', 12));
            $_SESSION['shipping_line1'] = $clean('dest_line1', 120);
            $_SESSION['shipping_city'] = $clean('dest_city', 80);
        } else {
            $_SESSION['shipping_country'] = $buyerCountry;
            $_SESSION['shipping_postal_code'] = $_SESSION['buyer_postal'];
            $_SESSION['shipping_line1'] = $_SESSION['buyer_line1'];
            $_SESSION['shipping_city'] = $_SESSION['buyer_city'];
        }
        $_SESSION['shipping_line2'] = '';
        // Le formulaire porte aussi les coordonnées déjà saisies : conservées quand la zone est recalculée.
        $firstName = $clean('contact_first_name', 40);
        $lastName = $clean('contact_last_name', 40);
        $email = mb_strtolower($clean('contact_email', 120));
        $phone = $clean('contact_phone', 30);
        if ($firstName !== '' || $lastName !== '' || $email !== '' || $phone !== '') {
            $_SESSION['checkout_contact'] = array_merge((array) ($_SESSION['checkout_contact'] ?? []), [
                'first_name' => $firstName, 'last_name' => $lastName,
                'name' => mb_substr(trim($firstName . ' ' . $lastName), 0, 80),
                'email' => $email, 'phone' => $phone,
                'ship_other' => $shipOther,
                'ship_name' => $clean('ship_name', 80),
                'ship_phone' => $clean('ship_phone', 30),
                'create_account' => !empty($_POST['create_account']),
            ]);
        }
        $_SESSION['shipping_ui'] = (string) ($_POST['shipping_choice'] ?? 'pickup');
        // Emballage cadeau : conservé quand la zone de livraison est recalculée.
        if (gift_wrap_enabled()) {
            if (!empty($_POST['gift_wrap'])) {
                $_SESSION['gift_wrap'] = 1;
                $giftMessage = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['gift_message'] ?? '')));
                if ($giftMessage !== '') $_SESSION['gift_message'] = mb_substr($giftMessage, 0, 200); else unset($_SESSION['gift_message']);
            } else {
                unset($_SESSION['gift_wrap'], $_SESSION['gift_message']);
            }
        }
    }
    header('Location: /cart.php');
    exit;
}

// Récompense de fidélité (lien « Utiliser sur mon panier » de l'espace visiteur) : mémorisée dans la session, vérifiée au calcul de la remise.
if (isset($_GET['recompense'])) {
    $rw = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) $_GET['recompense']));
    if ($rw !== '' && $rw !== '0' && strlen($rw) <= 24) $_SESSION['reward_code'] = $rw; else unset($_SESSION['reward_code']);
    header('Location: /cart.php');
    exit;
}

$lines = cart_lines();
$total = cart_total_cents();
$held = $lines ? cart_held_by_others() : [];
$content = get_content();
$pickupOnly = $lines ? cart_pickup_only($lines, $content) : false;
$discount = $lines ? cart_discount_for($total, cart_total_qty($lines)) : ['cents' => 0, 'tier' => null];
$discountHint = $lines ? cart_discount_hint($total, cart_total_qty($lines), null, $discount['cents']) : null;
$totalAfterDiscount = $total - $discount['cents'];
// Emballage cadeau : proposé par le vendeur, mémorisé dans la session pour survivre au recalcul de zone.
$giftWrapEnabled = $lines && gift_wrap_enabled($content);
$giftWrapCents = $giftWrapEnabled ? gift_wrap_price_cents($content) : 0;
$giftWrap = $giftWrapEnabled && !empty($_SESSION['gift_wrap']);
$giftMessage = $giftWrap ? (string) ($_SESSION['gift_message'] ?? '') : '';
if (!$giftWrapEnabled) {
    unset($_SESSION['gift_wrap'], $_SESSION['gift_message']);
    $giftWrap = false;
    $giftMessage = '';
}

$destCountry = (string) ($_SESSION['shipping_country'] ?? 'FR');
$destPostalCode = (string) ($_SESSION['shipping_postal_code'] ?? '');
$destLine1 = (string) ($_SESSION['shipping_line1'] ?? '');
$destCity = (string) ($_SESSION['shipping_city'] ?? '');
$shipOther = !empty($_SESSION['checkout_contact']['ship_other']);
// Adresse de l'acheteur : session dédiée ; reprise de l'ancienne adresse de livraison pour les paniers ouverts avant ce changement.
$buyerCountry = (string) ($_SESSION['buyer_country'] ?? ($shipOther ? 'FR' : $destCountry));
$buyerPostalCode = (string) ($_SESSION['buyer_postal'] ?? ($shipOther ? '' : $destPostalCode));
$buyerLine1 = (string) ($_SESSION['buyer_line1'] ?? ($shipOther ? '' : $destLine1));
$buyerCity = (string) ($_SESSION['buyer_city'] ?? ($shipOther ? '' : $destCity));
$buyerAddressLabel = (string) ($_SESSION['buyer_address_label'] ?? '');
$ccErr = (array) ($_SESSION['checkout_contact_errors'] ?? []);       // lues ici (adresses) et plus bas (coordonnées)
// Zone tarifaire : celle de l'adresse de livraison effective (destinataire si différent, sinon acheteur).
$destZone = shipping_zone_for_address($shipOther ? $destCountry : $buyerCountry, $shipOther ? $destPostalCode : $buyerPostalCode);
$carrierRates = ($lines && !$pickupOnly) ? matching_shipping_rates(cart_total_weight_g($lines), $destZone) : [];
$flatFeeCents = price_to_cents($content['shipping_fee']) ?? 0;
// Pays où la Base Adresse Nationale (La Poste) permet l'auto-complétion —
// la Polynésie et la Nouvelle-Calédonie ont leur propre système postal, non
// couvert par ce service.
$banEligibleCountries = ['FR', 'GP', 'MQ', 'GF', 'RE', 'YT'];

// Choix de livraison (clé = valeur du bouton radio). Le choix est mémorisé quand la zone est recalculée,
// pour retrouver l'adresse affichée après le rechargement.
$shippingChoices = [['key' => 'pickup', 'label' => (string) $content['pickup_label'], 'price' => 0, 'detail' => '']];
if (!$pickupOnly) {
    if ($carrierRates) {
        foreach ($carrierRates as $rate) {
            $shippingChoices[] = ['key' => 'rate-' . (int) $rate['id'], 'label' => $rate['carrier_name'] . ' — ' . $rate['service_name'], 'price' => (int) $rate['price_cents'], 'detail' => (string) $rate['delivery_delay']];
        }
    } else {
        $shippingChoices[] = ['key' => 'flat', 'label' => (string) $content['shipping_label'], 'price' => $flatFeeCents, 'detail' => ''];
    }
}
$uiKeys = array_column($shippingChoices, 'key');
$uiChoice = (string) ($_SESSION['shipping_ui'] ?? 'pickup');
if (!in_array($uiChoice, $uiKeys, true)) $uiChoice = $uiChoice !== 'pickup' ? ($uiKeys[1] ?? 'pickup') : 'pickup';
$deliverySelected = $uiChoice !== 'pickup';
$paymentMethods = $lines ? shop_payment_methods() : [];
$selectedPayment = (string) ($_SESSION['checkout_payment_method'] ?? '');
if (!isset($paymentMethods[$selectedPayment])) $selectedPayment = (string) array_key_first($paymentMethods);
// Panier repris après validation de la disponibilité : la commande attend le paiement.
$resumeOrder = $lines ? oa_resume_order() : null;
$availabilityLock = oa_enabled() && !$resumeOrder;

$activeNav = '';
$pageTitle = 'Panier';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/oauth.css">

<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Votre sélection</p>
    <h1 style="font-size:2rem;margin:12px 0 30px;">Panier</h1>

    <?php if ($resumeOrder): ?>
      <p class="publish-status" data-kind="ok" style="margin-bottom:24px;">
        Disponibilité confirmée pour votre demande n° <?= (int) $resumeOrder['id'] ?> : la pièce est mise de côté jusqu'au
        <?= h(date('d/m/Y à H:i', (int) strtotime((string) $resumeOrder['availability_deadline']))) ?>.
        Vérifiez votre panier puis réglez votre commande.
      </p>
    <?php endif; ?>

    <?php if ($availabilityLock): ?>
      <p class="publish-status" style="margin-bottom:24px;">
        Avant tout paiement, le vendeur vérifie que la pièce est toujours disponible en boutique : vous serez prévenu·e par e-mail dès sa réponse.
        Aucun montant n'est prélevé avant votre paiement.
      </p>
    <?php endif; ?>

    <?php if (!shop_checkout_ready()): ?>
      <p class="publish-status" data-kind="error" style="margin-bottom:24px;">
        Aucun moyen de paiement n'est configuré pour cette boutique — la commande ne pourra pas être finalisée pour l'instant.
      </p>
    <?php endif; ?>

    <?php
    $paymentNotice = (string) ($_GET['paiement'] ?? '');
    $paymentNotices = [
        'refuse' => ['error', "Le paiement n'a pas abouti. Votre panier est conservé : vous pouvez réessayer ou choisir un autre moyen de paiement."],
        'attente' => ['', "Le paiement est en cours de confirmation : ne payez pas une seconde fois. La commande sera mise à jour dès la confirmation du prestataire."],
        'indisponible' => ['error', "Ce moyen de paiement n'est pas disponible pour le moment. Choisissez-en un autre."],
        'erreur' => ['error', "Une erreur est survenue pendant la redirection vers le prestataire. Vous pouvez réessayer."],
    ];
    if (isset($paymentNotices[$paymentNotice])): [$noticeKind, $noticeText] = $paymentNotices[$paymentNotice]; ?>
      <p class="publish-status" data-kind="<?= h($noticeKind) ?>" style="margin-bottom:24px;"><?= h($noticeText) ?></p>
    <?php endif; ?>

    <?php if (!$lines): ?>
      <p class="empty-state">Votre panier est vide. <a href="/boutique.php" style="color:var(--accent);">Voir la boutique →</a></p>
    <?php else: ?>
      <div class="admin-block">
        <div class="product-admin-list">
          <?php foreach ($lines as $line): $p = $line['product']; $singlePiece = (int) $p['stock'] <= 1 && (int) $line['qty'] <= 1; ?>
            <div class="product-admin-row cart-line" style="grid-template-columns:90px 1fr auto;">
              <div class="admin-photo-preview"><?= product_media_html($p) ?></div>
              <div class="product-admin-fields">
                <h3 style="font-size:1.05rem;"><?= h($p['name']) ?></h3>
                <p class="desc" style="color:var(--ink-soft);font-size:0.88rem;"><?= format_cents($line['unit_cents']) ?> <?= product_lot_qty($p) > 1 ? 'le lot de ' . product_lot_qty($p) . ' pièces' : 'pièce' ?></p>
                <?php if ($line['qty'] > product_free_qty($p, $held)): ?>
                  <p class="desc" style="color:var(--accent);font-size:0.88rem;">En cours de paiement par un autre client : de nouveau disponible d'ici une demi-heure si ce paiement n'aboutit pas.</p>
                <?php endif; ?>
                <?php if ($singlePiece): ?>
                  <p class="desc" style="color:var(--ink-soft);font-size:0.88rem;"><?= product_lot_qty($p) > 1 ? 'Lot unique' : 'Pièce unique' ?> — pas de quantité à choisir.</p>
                <?php else: ?>
                  <form method="post" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <?= cart_csrf_field() ?>
                    <input type="hidden" name="ref" value="<?= h($p['ref']) ?>">
                    <input type="hidden" name="action" value="qty">
                    <label style="font-family:var(--font-mono);font-size:0.7rem;color:var(--ink-soft);">Quantité
                      <input type="number" name="qty" value="<?= (int) $line['qty'] ?>" min="0" max="<?= max(1, min(20, (int) $p['stock'])) ?>" style="width:64px;margin-left:8px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:6px 8px;">
                    </label>
                    <button type="submit" class="btn-small">Mettre à jour</button>
                  </form>
                <?php endif; ?>
              </div>
              <div style="text-align:right;">
                <p class="price" style="margin-bottom:10px;"><?= format_cents($line['total_cents']) ?></p>
                <form method="post">
                  <?= cart_csrf_field() ?>
                  <input type="hidden" name="ref" value="<?= h($p['ref']) ?>">
                  <input type="hidden" name="action" value="remove">
                  <button type="submit" class="admin-delete">Retirer</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:28px;padding-top:20px;border-top:1px solid var(--line);">
          <span class="eyebrow" style="font-size:0.9rem;">Sous-total</span>
          <span class="price" style="font-size:1.3rem;"><?= format_cents($total) ?></span>
        </div>
        <?php if ($discount['cents'] > 0): ?>
          <div class="cart-discount" style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;color:var(--accent);">
            <span class="eyebrow" style="font-size:0.9rem;">Remise — <?= h(cart_discount_label($discount['tier'])) ?><?php if (!empty($_SESSION['reward_code']) && !empty($discount['reward'])): ?> <a href="/cart.php?recompense=0" style="font-size:.8rem;">(retirer ma récompense)</a><?php endif; ?></span>
            <span class="price" style="font-size:1.1rem;">−<?= h(format_cents($discount['cents'])) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($discountHint): ?>
          <p class="discount-hint" style="font-size:0.85rem;color:var(--ink-soft);margin:10px 0 0;">💡 <?= h($discountHint) ?></p>
        <?php endif; ?>
        <?php
          require_once __DIR__ . '/includes/customers.php';
          require_once __DIR__ . '/includes/legal.php';
          $cc = (array) ($_SESSION['checkout_contact'] ?? []);
          $custSess = customer_session();
          $custAcc = $custSess ? customer_account($custSess['email']) : null;
          $lastContact = cart_contact_cookie_get();
          $ccFirst = (string) ($cc['first_name'] ?? '');
          $ccLast = (string) ($cc['last_name'] ?? '');
          $ccName = (string) ($cc['name'] ?? ($custAcc['name'] ?? ($custSess['name'] ?? '')));
          if ($ccFirst === '' && $ccLast === '' && $ccName !== '') [$ccFirst, $ccLast] = contact_name_parts($ccName);
          if ($ccFirst === '') $ccFirst = (string) ($lastContact['first_name'] ?? '');
          if ($ccLast === '') $ccLast = (string) ($lastContact['last_name'] ?? '');
          $ccFirst = mb_substr($ccFirst, 0, 40);
          $ccLast = mb_substr($ccLast, 0, 40);
          $ccEmail = (string) ($cc['email'] ?? '');
          if ($ccEmail === '') $ccEmail = (string) ($custSess['email'] ?? ($lastContact['email'] ?? ''));
          $ccPhone = (string) ($cc['phone'] ?? '');
          if ($ccPhone === '') $ccPhone = $custAcc ? customer_phone_display((string) $custAcc['phone']) : (string) ($lastContact['phone'] ?? '');
          // Un compte ouvert par un réseau social n'a pas encore de mot de passe : la case « créer mon compte » reste utile.
          $socialNeedsPassword = $custSess && $custAcc && empty($custAcc['password_hash']);
          $socialButtons = !$custSess ? oauth_buttons_html('customer', ['next' => '/cart.php'], false) : '';
          $countryOptionsHtml = static function (string $selected): string {
              $html = '<option value="FR"' . ($selected === 'FR' ? ' selected' : '') . '>France (métropole &amp; Corse)</option>';
              $groups = [];
              foreach (shipping_countries() as $code => [$label, $zone]) $groups[$zone][$code] = $label;
              foreach ($groups as $zoneKey => $countries) {
                  $html .= '<optgroup label="' . h(shipping_zones()[$zoneKey] ?? $zoneKey) . '">';
                  foreach ($countries as $code => $label) {
                      $html .= '<option value="' . h($code) . '"' . ($selected === $code ? ' selected' : '') . '>' . h($label) . '</option>';
                  }
                  $html .= '</optgroup>';
              }
              return $html;
          };
          $buyerSummary = implode(', ', array_filter([$buyerLine1, trim($buyerPostalCode . ' ' . $buyerCity)]));
          unset($_SESSION['checkout_contact_errors']);
        ?>
        <form method="post" action="/checkout.php" id="checkout-form" style="margin-top:24px;">
          <?= cart_csrf_field() ?>

          <div class="checkout-contact" id="checkout-contact" style="margin-bottom:20px;padding-top:20px;border-top:1px solid var(--line);">
            <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Vos coordonnées</span>
            <?php if ($custSess): ?><p class="cc-note">Connecté·e en tant que <strong><?= h($custSess['email']) ?></strong><?= $custSess['provider'] !== 'password' ? ' (' . h(OAUTH_PROVIDERS[$custSess['provider']]['label'] ?? $custSess['provider']) . ')' : '' ?> — <a href="/compte.php">mon compte</a>.</p><?php endif; ?>
            <div class="cc-grid">
              <label>Prénom <span class="cc-req" aria-hidden="true">*</span>
                <input type="text" name="contact_first_name" value="<?= h($ccFirst) ?>" required maxlength="40" autocomplete="given-name" placeholder="Marie"<?= isset($ccErr['first_name']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (isset($ccErr['first_name'])): ?><em class="cc-err" role="alert"><?= h($ccErr['first_name']) ?></em><?php endif; ?>
              </label>
              <label>Nom <span class="cc-req" aria-hidden="true">*</span>
                <input type="text" name="contact_last_name" value="<?= h($ccLast) ?>" required maxlength="40" autocomplete="family-name" placeholder="Dupont"<?= isset($ccErr['last_name']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (isset($ccErr['last_name'])): ?><em class="cc-err" role="alert"><?= h($ccErr['last_name']) ?></em><?php endif; ?>
              </label>
              <label>Téléphone <span class="cc-req" aria-hidden="true">*</span>
                <input type="tel" name="contact_phone" value="<?= h($ccPhone) ?>" required maxlength="30" autocomplete="tel" inputmode="tel" placeholder="06 12 34 56 78"<?= isset($ccErr['phone']) ? ' aria-invalid="true"' : '' ?>>
                <small>Pour vous joindre à propos de la livraison ou du retrait.</small>
                <?php if (isset($ccErr['phone'])): ?><em class="cc-err" role="alert"><?= h($ccErr['phone']) ?></em><?php endif; ?>
              </label>
              <label class="cc-wide">E-mail <span class="cc-req" aria-hidden="true">*</span>
                <input type="email" name="contact_email" value="<?= h($ccEmail) ?>" required maxlength="120" autocomplete="email" inputmode="email" placeholder="vous@exemple.fr"<?= $custSess ? ' readonly' : '' ?><?= isset($ccErr['email']) ? ' aria-invalid="true"' : '' ?>>
                <small>Votre confirmation de commande et vos accès y sont envoyés.</small>
                <?php if (isset($ccErr['email'])): ?><em class="cc-err" role="alert"><?= h($ccErr['email']) ?></em><?php endif; ?>
              </label>
            </div>
            <div class="buyer-address dst-form" id="buyer-address" data-ban="<?= h(implode(',', $banEligibleCountries)) ?>">
              <span class="eyebrow" style="font-size:0.85rem;display:block;margin:18px 0 10px;">Votre adresse <span class="cc-req" aria-hidden="true">*</span></span>
              <label>Pays <span class="cc-req" aria-hidden="true">*</span>
                <select name="buyer_country" id="buyer-country" style="display:block;margin-top:6px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:8px;min-width:220px;">
                  <?= $countryOptionsHtml($buyerCountry) ?>
                </select>
              </label>
              <div class="dst-grid">
                <label class="dst-wide">Adresse (numéro, rue, complément) <span class="cc-req" aria-hidden="true">*</span>
                  <div id="address-search-wrap" style="position:relative;">
                    <input type="text" name="buyer_line1" id="address-line1" value="<?= h($buyerLine1) ?>" required maxlength="120" autocomplete="address-line1" enterkeyhint="search" placeholder="Tapez votre adresse…"<?= isset($ccErr['buyer_addr']) ? ' aria-invalid="true"' : '' ?>>
                    <div id="address-suggestions" class="address-suggestions" hidden></div>
                  </div>
                </label>
                <label>Code postal <span class="cc-req" aria-hidden="true">*</span>
                  <input type="text" name="buyer_postal" id="postal-code-value" value="<?= h($buyerPostalCode) ?>" required maxlength="12" autocomplete="postal-code" inputmode="text" placeholder="75004"<?= isset($ccErr['buyer_addr']) ? ' aria-invalid="true"' : '' ?>>
                </label>
                <label>Ville <span class="cc-req" aria-hidden="true">*</span>
                  <input type="text" name="buyer_city" id="address-city" value="<?= h($buyerCity) ?>" required maxlength="80" autocomplete="address-level2" placeholder="Paris"<?= isset($ccErr['buyer_addr']) ? ' aria-invalid="true"' : '' ?>>
                </label>
              </div>
              <button type="button" class="btn-small" id="geo-locate" title="Retrouver votre adresse grâce à la position de votre appareil">📍 Me localiser</button>
              <input type="hidden" name="buyer_label" id="address-label-value" value="<?= h($buyerAddressLabel) ?>">
              <p id="geo-status" class="geo-status" role="status" aria-live="polite"><?= $buyerAddressLabel !== '' ? 'Adresse retenue : ' . h($buyerAddressLabel) : '' ?></p>
              <?php if (isset($ccErr['buyer_addr'])): ?><em class="cc-err" role="alert" id="addr-err"><?= h($ccErr['buyer_addr']) ?></em><?php endif; ?>
            </div>
            <p class="cc-note" style="font-size:0.78rem;color:var(--ink-soft);">Vos coordonnées sont mémorisées sur cet appareil pendant 6 mois pour préremplir vos prochaines commandes. <a href="<?= h(legal_url('confidentialite')) ?>" target="_blank" rel="noopener">En savoir plus</a>.</p>
          </div>

          <div class="shipping-choice" style="margin-bottom:20px;padding-top:20px;border-top:1px solid var(--line);">
            <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Livraison</span>
            <?php foreach ($shippingChoices as $opt): ?>
              <label class="shipping-choice-option">
                <input type="radio" name="shipping_choice" value="<?= h($opt['key']) ?>" data-price-cents="<?= (int) $opt['price'] ?>"<?= $uiChoice === $opt['key'] ? ' checked' : '' ?>>
                <span>
                  <?= h($opt['label']) ?>
                  <?php if ($opt['detail'] !== ''): ?><span style="color:var(--ink-soft);font-size:0.82rem;"> (<?= h($opt['detail']) ?>)</span><?php endif; ?>
                </span>
                <span class="shipping-choice-price"><?= $opt['price'] > 0 ? h(format_cents($opt['price'])) : 'Gratuit' ?></span>
              </label>
            <?php endforeach; ?>
            <?php if ($pickupOnly): ?>
              <p class="pickup-only-note" style="font-size:0.9rem;color:var(--ink-soft);margin-top:8px;"><?= h(pickup_only_message($content)) ?> Pas de livraison pour <?= count($lines) > 1 ? 'ces articles' : 'cet article' ?>.</p>
            <?php endif; ?>
            <p style="font-size:0.78rem;color:var(--ink-soft);margin-top:10px;">Le mode choisi ici est celui qui sera facturé et utilisé pour la commande — vérifiez-le avant de payer.</p>
          </div>

          <?php if (!$pickupOnly): ?>
          <div class="delivery-address dst-form checkout-contact" id="delivery-address"<?= $deliverySelected ? '' : ' hidden' ?>>
            <span class="eyebrow" style="font-size:0.9rem;display:block;">Adresse de livraison</span>
            <p style="font-size:0.82rem;color:var(--ink-soft);margin:0;" id="delivery-summary"><?= $shipOther ? 'Le colis part à l’adresse du destinataire ci-dessous.' : 'Livraison à votre adresse' . ($buyerSummary !== '' ? ' : <strong>' . h($buyerSummary) . '</strong>' : ' (renseignez-la plus haut).') ?></p>
            <label class="cc-check"><input type="checkbox" name="ship_other" value="1" id="ship-other"<?= $shipOther ? ' checked' : '' ?>>
              <span>Livrer à une autre adresse ou à une autre personne (cadeau, proche…)</span></label>
            <div id="ship-other-fields"<?= $shipOther ? '' : ' hidden' ?>>
              <div class="cc-grid" style="margin-bottom:12px;">
                <label>Nom du destinataire <span class="cc-req" aria-hidden="true">*</span>
                  <input type="text" name="ship_name" value="<?= h((string) ($cc['ship_name'] ?? '')) ?>" maxlength="80" autocomplete="off" placeholder="Paul Martin"<?= isset($ccErr['ship_name']) ? ' aria-invalid="true"' : '' ?>>
                  <?php if (isset($ccErr['ship_name'])): ?><em class="cc-err" role="alert"><?= h($ccErr['ship_name']) ?></em><?php endif; ?>
                </label>
                <label>Téléphone du destinataire <span class="cc-req" aria-hidden="true">*</span>
                  <input type="tel" name="ship_phone" value="<?= h((string) ($cc['ship_phone'] ?? '')) ?>" maxlength="30" autocomplete="off" inputmode="tel" placeholder="06 98 76 54 32"<?= isset($ccErr['ship_phone']) ? ' aria-invalid="true"' : '' ?>>
                  <small>Pour joindre la personne qui reçoit le colis.</small>
                  <?php if (isset($ccErr['ship_phone'])): ?><em class="cc-err" role="alert"><?= h($ccErr['ship_phone']) ?></em><?php endif; ?>
                </label>
              </div>
              <label>Pays du destinataire
                <select name="dest_country" id="destination-country" style="display:block;margin-top:6px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:8px;min-width:220px;">
                  <?= $countryOptionsHtml($destCountry) ?>
                </select>
              </label>
              <div class="dst-grid">
                <label class="dst-wide">Adresse du destinataire (numéro, rue, complément) <span class="cc-req" aria-hidden="true">*</span>
                  <input type="text" name="dest_line1" id="dest-line1" value="<?= h($destLine1) ?>" maxlength="120" autocomplete="address-line1" placeholder="12 rue de Rivoli, bâtiment B"<?= isset($ccErr['addr']) ? ' aria-invalid="true"' : '' ?>>
                </label>
                <label>Code postal <span class="cc-req" aria-hidden="true">*</span>
                  <input type="text" name="dest_postal" id="dest-postal" value="<?= h($destPostalCode) ?>" maxlength="12" autocomplete="postal-code" inputmode="text" placeholder="75004"<?= isset($ccErr['addr']) ? ' aria-invalid="true"' : '' ?>>
                </label>
                <label>Ville <span class="cc-req" aria-hidden="true">*</span>
                  <input type="text" name="dest_city" id="dest-city" value="<?= h($destCity) ?>" maxlength="80" autocomplete="address-level2" placeholder="Paris"<?= isset($ccErr['addr']) ? ' aria-invalid="true"' : '' ?>>
                </label>
              </div>
              <?php if (isset($ccErr['addr'])): ?><em class="cc-err" role="alert" id="addr-err"><?= h($ccErr['addr']) ?></em><?php endif; ?>
            </div>
            <button type="submit" class="btn-small" id="address-refresh" name="action" value="set_destination" formaction="/cart.php" formnovalidate>Actualiser la zone de livraison</button>
            <p style="font-size:0.78rem;color:var(--ink-soft);margin:0;">Zone tarifaire détectée : <strong><?= h(shipping_zones()[$destZone] ?? $destZone) ?></strong><?php if ($destZone === 'corse'): ?> — les codes postaux 20xxx sont reconnus comme Corse.<?php endif; ?></p>
          </div>
          <?php endif; ?>

          <?php if (!$custSess || $socialNeedsPassword || $socialButtons !== ''): ?>
          <div class="checkout-contact" id="checkout-account" style="margin-bottom:20px;padding-top:20px;border-top:1px solid var(--line);">
            <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Mon compte</span>
            <?php if (!$custSess): ?>
              <label class="cc-check"><input type="checkbox" name="create_account" value="1"<?= !empty($cc['create_account']) ? ' checked' : '' ?>>
                <span>Créer mon compte client (je recevrai un e-mail pour choisir mon mot de passe) — pour suivre mes commandes et commander plus vite. <a href="/compte.php?next=cart">J'ai déjà un compte</a></span></label>
            <?php elseif ($socialNeedsPassword): ?>
              <label class="cc-check"><input type="checkbox" name="create_account" value="1"<?= !empty($cc['create_account']) ? ' checked' : '' ?>>
                <span>Créer mon compte client (je recevrai un e-mail pour choisir mon mot de passe) — pour me connecter sans réseau social.</span></label>
            <?php endif; ?>
            <?php if ($socialButtons !== ''): ?>
              <p class="cc-note" style="margin:14px 0 8px;">…ou créez votre compte avec un réseau social :</p>
              <?= $socialButtons ?>
              <p class="cc-note" style="font-size:0.78rem;">Avec un réseau social, nous ne recevons que votre nom et votre adresse e-mail.</p>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if (count($paymentMethods) > 1): ?>
          <div class="checkout-contact" id="checkout-payment" style="margin-bottom:20px;padding-top:20px;border-top:1px solid var(--line);">
            <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Moyen de paiement</span>
            <?php foreach ($paymentMethods as $methodKey => $methodLabel): ?>
              <label class="cc-check"><input type="radio" name="payment_method" value="<?= h($methodKey) ?>"<?= $selectedPayment === $methodKey ? ' checked' : '' ?>>
                <span><?= h($methodLabel) ?></span></label>
            <?php endforeach; ?>
            <p class="cc-note" style="font-size:0.78rem;color:var(--ink-soft);margin-top:6px;">Vous serez redirigé vers la page sécurisée du prestataire choisi.</p>
          </div>
          <?php elseif ($paymentMethods): ?>
            <input type="hidden" name="payment_method" value="<?= h((string) array_key_first($paymentMethods)) ?>">
          <?php endif; ?>

          <?php if ($giftWrapEnabled): ?>
          <div class="checkout-contact" id="checkout-gift" style="margin-bottom:20px;padding-top:20px;border-top:1px solid var(--line);">
            <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Emballage cadeau</span>
            <label class="cc-check">
              <input type="checkbox" name="gift_wrap" value="1" id="gift-wrap" data-price-cents="<?= (int) $giftWrapCents ?>"<?= $giftWrap ? ' checked' : '' ?>>
              <span><?= h(gift_wrap_label($content)) ?> — <?= $giftWrapCents > 0 ? h(format_cents($giftWrapCents)) : 'offert' ?></span>
            </label>
            <div id="gift-message-wrap"<?= $giftWrap ? '' : ' hidden' ?>>
              <label style="display:block;margin-top:14px;">Petit mot pour le destinataire (facultatif)
                <textarea name="gift_message" id="gift-message" maxlength="200" rows="3" placeholder="Joyeux anniversaire !"><?= h($giftMessage) ?></textarea>
              </label>
              <p class="cc-note" style="font-size:0.78rem;color:var(--ink-soft);margin-top:6px;">Il sera recopié sur le paquet. 200 caractères maximum.</p>
            </div>
          </div>
          <?php endif; ?>

          <div class="checkout-contact" id="checkout-terms" style="margin-bottom:20px;padding-top:20px;border-top:1px solid var(--line);">
            <label class="cc-check"><input type="checkbox" name="accept_terms" value="1" required<?= isset($ccErr['terms']) ? ' aria-invalid="true"' : '' ?>>
              <span>J'ai lu et j'accepte les <a href="<?= h(legal_url('cgv')) ?>" target="_blank" rel="noopener">conditions générales de vente</a>, les <a href="<?= h(legal_url('cgu')) ?>" target="_blank" rel="noopener">conditions d'utilisation</a> et la <a href="<?= h(legal_url('confidentialite')) ?>" target="_blank" rel="noopener">politique de confidentialité</a>. <span class="cc-req" aria-hidden="true">*</span></span></label>
            <?php if (isset($ccErr['terms'])): ?><em class="cc-err" role="alert"><?= h($ccErr['terms']) ?></em><?php endif; ?>
          </div>

          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <span class="eyebrow" style="font-size:0.95rem;">Total avec livraison<?= $giftWrapEnabled ? ' et emballage' : '' ?></span>
            <span class="price" id="cart-grand-total" style="font-size:1.5rem;" data-subtotal-cents="<?= (int) $totalAfterDiscount ?>"><?= format_cents($totalAfterDiscount + ($giftWrap ? $giftWrapCents : 0)) ?></span>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;" <?= shop_checkout_ready() ? '' : 'disabled' ?>><?= $availabilityLock ? 'Envoyer ma demande de disponibilité' : 'Passer à la caisse' ?></button>
          <?php require_once __DIR__ . '/includes/payment-badges.php'; ?>
          <?php if (($__paymentBadges = payment_badges_html('', 28)) !== ''): ?>
            <div style="display:flex;flex-direction:column;align-items:center;gap:6px;margin-top:14px;">
              <span style="font-size:0.68rem;letter-spacing:0.14em;text-transform:uppercase;opacity:0.6;">Paiement sécurisé</span>
              <?= $__paymentBadges ?>
            </div>
          <?php endif; ?>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
(function () {
  var countrySelect = document.getElementById('buyer-country');
  var postalValue = document.getElementById('postal-code-value');
  var deliveryBlock = document.getElementById('delivery-address');
  var refreshButton = document.getElementById('address-refresh');
  var shipOther = document.getElementById('ship-other');
  var shipOtherFields = document.getElementById('ship-other-fields');
  var deliverySummary = document.getElementById('delivery-summary');
  var buyerFields = ['address-line1', 'postal-code-value', 'address-city'].map(function (id) { return document.getElementById(id); }).filter(Boolean);

  // Recalcul de la zone tarifaire sans valider les autres champs (bouton formnovalidate).
  function refreshZone() {
    if (refreshButton) refreshButton.click();
  }

  function buyerSummary() {
    var line1 = document.getElementById('address-line1'), postal = document.getElementById('postal-code-value'), city = document.getElementById('address-city');
    var street = line1 ? line1.value.trim() : '', rest = postal && city ? (postal.value.trim() + ' ' + city.value.trim()).trim() : '';
    return [street, rest].filter(Boolean).join(', ');
  }

  function syncDeliverySummary() {
    if (!deliverySummary) return;
    if (shipOther && shipOther.checked) {
      deliverySummary.textContent = 'Le colis part à l’adresse du destinataire ci-dessous.';
      return;
    }
    var summary = buyerSummary();
    deliverySummary.innerHTML = 'Livraison à votre adresse' + (summary ? ' : <strong>' + summary.replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }) + '</strong>' : ' (renseignez-la plus haut).');
  }

  function syncShipOther() {
    if (!shipOther || !shipOtherFields) return;
    var on = shipOther.checked && !shipOther.disabled;
    shipOtherFields.hidden = !on;
    shipOtherFields.querySelectorAll('input, select').forEach(function (i) { i.required = on; });
    syncDeliverySummary();
  }

  // Le destinataire (nom, téléphone, adresse) n'est demandé que si le colis part ailleurs que chez l'acheteur.
  function syncDelivery() {
    var checked = document.querySelector('input[name="shipping_choice"]:checked');
    var delivery = !!checked && checked.value !== 'pickup';
    if (deliveryBlock) deliveryBlock.hidden = !delivery;
    if (shipOther) { shipOther.disabled = !delivery; if (!delivery) shipOther.checked = false; }
    syncShipOther();
  }

  if (countrySelect) {
    countrySelect.addEventListener('change', refreshZone);   // le pays seul fixe la zone (sauf Corse, distinguée par le code postal)
  }
  if (postalValue) postalValue.addEventListener('change', refreshZone);
  buyerFields.forEach(function (input) { input.addEventListener('input', syncDeliverySummary); });

  var radios = document.querySelectorAll('input[name="shipping_choice"]');
  var subtotalEl = document.getElementById('cart-grand-total');
  var giftCheckbox = document.getElementById('gift-wrap');
  var giftMessageWrap = document.getElementById('gift-message-wrap');
  var giftMessageInput = document.getElementById('gift-message');
  if (subtotalEl) {
    var subtotalCents = parseInt(subtotalEl.dataset.subtotalCents, 10) || 0;
    var giftCents = giftCheckbox ? (parseInt(giftCheckbox.dataset.priceCents, 10) || 0) : 0;
    var formatCents = function (cents) {
      return (cents / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €';
    };
    var updateTotal = function () {
      var checked = document.querySelector('input[name="shipping_choice"]:checked');
      var gift = giftCheckbox && giftCheckbox.checked ? giftCents : 0;
      subtotalEl.textContent = formatCents(subtotalCents + (checked ? (parseInt(checked.dataset.priceCents, 10) || 0) : 0) + gift);
    };
    var syncGift = function () {
      var on = !!(giftCheckbox && giftCheckbox.checked);
      if (giftMessageWrap) giftMessageWrap.hidden = !on;
      if (giftMessageInput) giftMessageInput.disabled = !on;
      updateTotal();
    };
    radios.forEach(function (r) { r.addEventListener('change', function () { syncDelivery(); updateTotal(); }); });
    if (giftCheckbox) giftCheckbox.addEventListener('change', syncGift);
    syncGift();
    updateTotal();
  }
  if (shipOther) shipOther.addEventListener('change', syncShipOther);
  syncDelivery();
})();
</script>

<style>
  .dst-form { display: grid; gap: 12px; max-width: 640px; }
  .dst-form label { display: block; font-family: var(--font-mono); font-size: 0.7rem; color: var(--ink-soft); }
  .dst-form input[type=text], .dst-form select { display: block; width: 100%; margin-top: 6px; min-height: 44px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font-family: var(--font-body); font-size: 1rem; }
  .dst-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; } .dst-grid .dst-wide { grid-column: 1 / -1; }
  .dst-form .btn-small { justify-self: start; }
  @media (max-width: 480px) { .dst-grid { grid-template-columns: 1fr; } }
  .checkout-contact .cc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
  .checkout-contact label { display: block; font-family: var(--font-mono); font-size: 0.7rem; color: var(--ink-soft); }
  .checkout-contact .cc-wide { grid-column: 1 / -1; }
  .checkout-contact input[type=text], .checkout-contact input[type=tel], .checkout-contact input[type=email], .checkout-contact textarea { display: block; width: 100%; margin-top: 6px; min-height: 44px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font: inherit; font-family: var(--font-body); font-size: 1rem; }
  .checkout-contact textarea { min-height: 90px; resize: vertical; }
  .checkout-contact input[aria-invalid=true] { border-color: #b3261e; }
  .checkout-contact small { display: block; margin-top: 4px; font-family: var(--font-body); font-size: 0.78rem; }
  .checkout-contact .cc-check { display: flex; gap: 10px; align-items: flex-start; margin-top: 14px; font-family: var(--font-body); font-size: 0.88rem; color: var(--ink); line-height: 1.45; }
  .checkout-contact .cc-check input { width: 22px; height: 22px; flex: none; margin-top: 1px; }
  .checkout-contact .cc-err { display: block; color: #b3261e; font-family: var(--font-body); font-size: 0.82rem; font-style: normal; margin-top: 4px; }
  .checkout-contact .cc-note { font-size: 0.88rem; margin: 0 0 12px; } .checkout-contact .cc-req { color: #b3261e; }
  .delivery-address[hidden], .checkout-contact .cc-grid[hidden], #gift-message-wrap[hidden] { display: none !important; }
  #checkout-account .oauth-box { max-width: 340px; }
</style>
<script>
(function () {   // Coordonnées mémorisées dès la saisie : préremplies à la prochaine visite, même sans commande envoyée
  var form = document.getElementById('checkout-form');
  if (!form) return;
  var cookieName = <?= json_encode(cart_contact_cookie_name()) ?>;
  var cookiePath = <?= json_encode(tenant_base_path() . '/') ?>;
  var fields = { contact_first_name: 'first_name', contact_last_name: 'last_name', contact_email: 'email', contact_phone: 'phone' };

  function readCookie() {
    var prefix = cookieName + '=';
    var parts = document.cookie.split('; ');
    for (var i = 0; i < parts.length; i++) {
      if (parts[i].indexOf(prefix) === 0) {
        try { var data = JSON.parse(decodeURIComponent(parts[i].slice(prefix.length))); return data && typeof data === 'object' ? data : {}; } catch (e) { return {}; }
      }
    }
    return {};
  }

  function save() {
    var data = readCookie();
    Object.keys(fields).forEach(function (name) {
      var input = form.elements[name];
      if (!input) return;
      var value = input.value.trim();
      if (value !== '') data[fields[name]] = value; else delete data[fields[name]];
    });
    if (!Object.keys(data).length) {
      document.cookie = cookieName + '=; path=' + cookiePath + '; max-age=0; SameSite=Lax';
      return;
    }
    document.cookie = cookieName + '=' + encodeURIComponent(JSON.stringify(data)) + '; path=' + cookiePath + '; max-age=' + (<?= CART_CONTACT_COOKIE_DAYS ?> * 86400) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  Object.keys(fields).forEach(function (name) {
    var input = form.elements[name];
    if (!input) return;
    input.addEventListener('change', save);
    input.addEventListener('blur', save);
  });
})();
</script>
<script src="/assets/cart-address.js?v=<?= (int) @filemtime(__DIR__ . '/assets/cart-address.js') ?>" defer></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
