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
        $country = (string) ($_POST['country'] ?? 'FR');
        $_SESSION['shipping_country'] = array_key_exists($country, shipping_allowed_countries()) ? $country : 'FR';
        $_SESSION['shipping_postal_code'] = preg_replace('/[^0-9]/', '', (string) ($_POST['postal_code'] ?? ''));
        // Adresse choisie dans la liste ou trouvée par « Me localiser » : gardée pour l'afficher (jamais la position GPS).
        $addr = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['address_label'] ?? ''))), 0, 160);
        if ($addr !== '' && $_SESSION['shipping_postal_code'] !== '') $_SESSION['shipping_address'] = $addr; else unset($_SESSION['shipping_address']);
        // Adresse de livraison complète (rue, complément, ville) : gardée pour le paiement et la préparation de la commande.
        $clean = static fn (string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
        $_SESSION['shipping_line1'] = $clean('address_line1', 120);
        $_SESSION['shipping_line2'] = $clean('address_line2', 120);
        $_SESSION['shipping_city'] = $clean('address_city', 80);
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

$destCountry = (string) ($_SESSION['shipping_country'] ?? 'FR');
$destPostalCode = (string) ($_SESSION['shipping_postal_code'] ?? '');
$destAddress = (string) ($_SESSION['shipping_address'] ?? '');
$destLine1 = (string) ($_SESSION['shipping_line1'] ?? '');
$destLine2 = (string) ($_SESSION['shipping_line2'] ?? '');
$destCity = (string) ($_SESSION['shipping_city'] ?? '');
$ccErr = (array) ($_SESSION['checkout_contact_errors'] ?? []);       // lues ici (bloc Destination) et plus bas (coordonnées)
$destZone = shipping_zone_for_address($destCountry, $destPostalCode);
$carrierRates = ($lines && !$pickupOnly) ? matching_shipping_rates(cart_total_weight_g($lines), $destZone) : [];
$flatFeeCents = price_to_cents($content['shipping_fee']) ?? 0;
// Pays où la Base Adresse Nationale (La Poste) permet l'auto-complétion —
// la Polynésie et la Nouvelle-Calédonie ont leur propre système postal, non
// couvert par ce service.
$banEligibleCountries = ['FR', 'GP', 'MQ', 'GF', 'RE', 'YT'];

$activeNav = '';
$pageTitle = 'Panier';
include __DIR__ . '/includes/header.php';
?>

<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Votre sélection</p>
    <h1 style="font-size:2rem;margin:12px 0 30px;">Panier</h1>

    <?php if (!shop_checkout_ready()): ?>
      <p class="publish-status" data-kind="error" style="margin-bottom:24px;">
        Le paiement Stripe n'est pas encore configuré (clé absente dans config.php) — la commande ne pourra pas être finalisée pour l'instant.
      </p>
    <?php endif; ?>

    <?php if (!$lines): ?>
      <p class="empty-state">Votre panier est vide. <a href="/boutique.php" style="color:var(--accent);">Voir la boutique →</a></p>
    <?php else: ?>
      <div class="admin-block">
        <div class="product-admin-list">
          <?php foreach ($lines as $line): $p = $line['product']; ?>
            <div class="product-admin-row cart-line" style="grid-template-columns:90px 1fr auto;">
              <div class="admin-photo-preview"><?= product_media_html($p) ?></div>
              <div class="product-admin-fields">
                <h3 style="font-size:1.05rem;"><?= h($p['name']) ?></h3>
                <p class="desc" style="color:var(--ink-soft);font-size:0.88rem;"><?= format_cents($line['unit_cents']) ?> <?= product_lot_qty($p) > 1 ? 'le lot de ' . product_lot_qty($p) . ' pièces' : 'pièce' ?></p>
                <?php if ($line['qty'] > product_free_qty($p, $held)): ?>
                  <p class="desc" style="color:var(--accent);font-size:0.88rem;">En cours de paiement par un autre client : de nouveau disponible d'ici une demi-heure si ce paiement n'aboutit pas.</p>
                <?php endif; ?>
                <form method="post" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <?= cart_csrf_field() ?>
                  <input type="hidden" name="ref" value="<?= h($p['ref']) ?>">
                  <input type="hidden" name="action" value="qty">
                  <label style="font-family:var(--font-mono);font-size:0.7rem;color:var(--ink-soft);">Quantité
                    <input type="number" name="qty" value="<?= (int) $line['qty'] ?>" min="0" max="20" style="width:64px;margin-left:8px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:6px 8px;">
                  </label>
                  <button type="submit" class="btn-small">Mettre à jour</button>
                </form>
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
        <div class="shipping-destination" style="margin-top:20px;padding-top:20px;border-top:1px solid var(--line);"<?= $pickupOnly ? ' hidden' : '' ?>>
          <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Destination</span>
          <form method="post" id="destination-form" class="dst-form" data-ban="<?= h(implode(',', $banEligibleCountries)) ?>">
            <?= cart_csrf_field() ?>
            <input type="hidden" name="action" value="set_destination">
            <label style="font-family:var(--font-mono);font-size:0.7rem;color:var(--ink-soft);">Pays
              <select name="country" id="destination-country" style="display:block;margin-top:6px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:8px;min-width:220px;">
                <option value="FR"<?= $destCountry === 'FR' ? ' selected' : '' ?>>France (métropole & Corse)</option>
                <?php
                  $countryGroups = [];
                  foreach (shipping_countries() as $code => [$label, $zone]) {
                      $countryGroups[$zone][$code] = $label;
                  }
                ?>
                <?php foreach ($countryGroups as $zoneKey => $countries): ?>
                  <optgroup label="<?= h(shipping_zones()[$zoneKey] ?? $zoneKey) ?>">
                    <?php foreach ($countries as $code => $label): ?>
                      <option value="<?= h($code) ?>"<?= $destCountry === $code ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              </select>
            </label>

            <label id="address-search-wrap" class="dst-search" style="position:relative;">
              Rechercher mon adresse
              <input type="text" id="address-search" placeholder="Tapez votre adresse…" autocomplete="off" enterkeyhint="search" value="">
              <div id="address-suggestions" class="address-suggestions" hidden></div>
            </label>
            <button type="button" class="btn-small" id="geo-locate" title="Retrouver votre adresse grâce à la position de votre appareil">📍 Me localiser</button>
            <div class="dst-grid">
              <label class="dst-wide">Adresse (numéro et rue) <span class="cc-req" aria-hidden="true">*</span>
                <input type="text" name="address_line1" id="address-line1" value="<?= h($destLine1) ?>" maxlength="120" autocomplete="address-line1" placeholder="12 rue de Rivoli">
              </label>
              <label class="dst-wide">Complément d'adresse
                <input type="text" name="address_line2" id="address-line2" value="<?= h($destLine2) ?>" maxlength="120" autocomplete="address-line2" placeholder="Bâtiment, étage, digicode, lieu-dit…">
              </label>
              <label>Code postal <span class="cc-req" aria-hidden="true">*</span>
                <input type="text" name="postal_code" id="postal-code-value" value="<?= h($destPostalCode) ?>" maxlength="12" autocomplete="postal-code" inputmode="text" placeholder="75004">
              </label>
              <label>Ville <span class="cc-req" aria-hidden="true">*</span>
                <input type="text" name="address_city" id="address-city" value="<?= h($destCity) ?>" maxlength="80" autocomplete="address-level2" placeholder="Paris">
              </label>
            </div>
            <input type="hidden" name="address_label" id="address-label-value" value="<?= h($destAddress) ?>">
            <button type="submit" class="btn-small">Actualiser la zone de livraison</button>
          </form>
          <?php if (isset($ccErr['addr'])): ?><em class="cc-err" role="alert" id="addr-err"><?= h($ccErr['addr']) ?></em><?php endif; ?>
          <p id="geo-status" class="geo-status" role="status" aria-live="polite"><?= $destAddress !== '' ? 'Adresse retenue : ' . h($destAddress) : '' ?></p>
          <p style="font-size:0.78rem;color:var(--ink-soft);margin-top:10px;">
            Zone tarifaire détectée : <strong><?= h(shipping_zones()[$destZone] ?? $destZone) ?></strong>
            <?php if ($destZone === 'corse'): ?> — les codes postaux 20xxx sont reconnus comme Corse.<?php endif; ?>
          </p>
        </div>

        <form method="post" action="/checkout.php" id="checkout-form" style="margin-top:24px;">
          <?= cart_csrf_field() ?>
          <?php foreach (["dest_country", "dest_line1", "dest_line2", "dest_postal", "dest_city"] as $dk): ?><input type="hidden" name="<?= $dk ?>" value=""><?php endforeach; ?>
          <div class="shipping-choice" style="margin-bottom:20px;padding-top:20px;border-top:1px solid var(--line);">
            <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Livraison</span>

            <label class="shipping-choice-option">
              <input type="radio" name="shipping_choice" value="pickup" data-price-cents="0" checked>
              <span><?= h($content['pickup_label']) ?></span>
              <span class="shipping-choice-price">Gratuit</span>
            </label>

            <?php if ($pickupOnly): ?>
              <p class="pickup-only-note" style="font-size:0.9rem;color:var(--ink-soft);margin-top:8px;"><?= h(pickup_only_message($content)) ?> Pas de livraison pour <?= count($lines) > 1 ? 'ces articles' : 'cet article' ?>.</p>
            <?php elseif ($carrierRates): ?>
              <?php foreach ($carrierRates as $rate): ?>
                <label class="shipping-choice-option">
                  <input type="radio" name="shipping_choice" value="rate-<?= (int) $rate['id'] ?>" data-price-cents="<?= (int) $rate['price_cents'] ?>">
                  <span>
                    <?= h($rate['carrier_name']) ?> — <?= h($rate['service_name']) ?>
                    <?php if ($rate['delivery_delay'] !== ''): ?><span style="color:var(--ink-soft);font-size:0.82rem;"> (<?= h($rate['delivery_delay']) ?>)</span><?php endif; ?>
                  </span>
                  <span class="shipping-choice-price"><?= h(format_cents((int) $rate['price_cents'])) ?></span>
                </label>
              <?php endforeach; ?>
            <?php else: ?>
              <label class="shipping-choice-option">
                <input type="radio" name="shipping_choice" value="flat" data-price-cents="<?= (int) $flatFeeCents ?>">
                <span><?= h($content['shipping_label']) ?></span>
                <span class="shipping-choice-price"><?= h($content['shipping_fee']) ?></span>
              </label>
            <?php endif; ?>

            <p style="font-size:0.78rem;color:var(--ink-soft);margin-top:10px;">Le mode choisi ici est présélectionné à l'étape de paiement — vous pourrez encore en changer juste avant de payer.</p>
          </div>

          <?php
            require_once __DIR__ . '/includes/customers.php';
            require_once __DIR__ . '/includes/legal.php';
            $cc = (array) ($_SESSION['checkout_contact'] ?? []);
            $custSess = customer_session();
            $custAcc = $custSess ? customer_account($custSess['email']) : null;
            $ccEmail = (string) ($cc['email'] ?? ($custSess['email'] ?? ''));
            $ccName = (string) ($cc['name'] ?? ($custAcc['name'] ?? ($custSess['name'] ?? '')));
            $ccPhone = (string) ($cc['phone'] ?? ($custAcc ? customer_phone_display((string) $custAcc['phone']) : ''));
            unset($_SESSION['checkout_contact_errors']);
          ?>
          <div class="checkout-contact" id="checkout-contact" style="margin-bottom:20px;padding-top:20px;border-top:1px solid var(--line);">
            <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Vos coordonnées</span>
            <?php if ($custSess): ?><p class="cc-note">Connecté·e en tant que <strong><?= h($custSess['email']) ?></strong> — <a href="/compte.php">mon compte</a>.</p><?php endif; ?>
            <div class="cc-grid">
              <label>Nom et prénom
                <input type="text" name="contact_name" value="<?= h($ccName) ?>" maxlength="80" autocomplete="name" placeholder="Marie Dupont">
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
            <label class="cc-check"><input type="checkbox" name="ship_other" value="1" id="ship-other"<?= !empty($cc['ship_other']) ? ' checked' : '' ?>>
              <span>La livraison est pour une autre personne (cadeau, proche…) : indiquer son nom et son téléphone</span></label>
            <div class="cc-grid" id="ship-other-fields" style="margin-top:12px;"<?= empty($cc['ship_other']) ? ' hidden' : '' ?>>
              <label>Nom du destinataire <span class="cc-req" aria-hidden="true">*</span>
                <input type="text" name="ship_name" value="<?= h((string) ($cc['ship_name'] ?? '')) ?>" maxlength="80" autocomplete="off" placeholder="Paul Martin"<?= isset($ccErr['ship_name']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (isset($ccErr['ship_name'])): ?><em class="cc-err" role="alert"><?= h($ccErr['ship_name']) ?></em><?php endif; ?>
              </label>
              <label>Téléphone du destinataire <span class="cc-req" aria-hidden="true">*</span>
                <input type="tel" name="ship_phone" value="<?= h((string) ($cc['ship_phone'] ?? '')) ?>" maxlength="30" autocomplete="off" inputmode="tel" placeholder="06 98 76 54 32"<?= isset($ccErr['ship_phone']) ? ' aria-invalid="true"' : '' ?>>
                <small>Pour le transporteur. L'adresse de livraison se saisit à l'étape de paiement : elle peut être différente de la vôtre.</small>
                <?php if (isset($ccErr['ship_phone'])): ?><em class="cc-err" role="alert"><?= h($ccErr['ship_phone']) ?></em><?php endif; ?>
              </label>
            </div>
            <?php if (!$custSess): ?>
              <label class="cc-check"><input type="checkbox" name="create_account" value="1"<?= !empty($cc['create_account']) ? ' checked' : '' ?>>
                <span>Créer mon compte client (je recevrai un e-mail pour choisir mon mot de passe) — pour suivre mes commandes et commander plus vite. <a href="/compte.php">J'ai déjà un compte</a></span></label>
            <?php endif; ?>
            <label class="cc-check"><input type="checkbox" name="accept_terms" value="1" required<?= isset($ccErr['terms']) ? ' aria-invalid="true"' : '' ?>>
              <span>J'ai lu et j'accepte les <a href="<?= h(legal_url('cgv')) ?>" target="_blank" rel="noopener">conditions générales de vente</a>, les <a href="<?= h(legal_url('cgu')) ?>" target="_blank" rel="noopener">conditions d'utilisation</a> et la <a href="<?= h(legal_url('confidentialite')) ?>" target="_blank" rel="noopener">politique de confidentialité</a>. <span class="cc-req" aria-hidden="true">*</span></span></label>
            <?php if (isset($ccErr['terms'])): ?><em class="cc-err" role="alert"><?= h($ccErr['terms']) ?></em><?php endif; ?>
          </div>

          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <span class="eyebrow" style="font-size:0.95rem;">Total avec livraison</span>
            <span class="price" id="cart-grand-total" style="font-size:1.5rem;" data-subtotal-cents="<?= (int) $totalAfterDiscount ?>"><?= format_cents($totalAfterDiscount) ?></span>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;" <?= shop_checkout_ready() ? '' : 'disabled' ?>>Passer à la caisse</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
(function () {
  var banEligible = <?= json_encode($banEligibleCountries) ?>;
  var countrySelect = document.getElementById('destination-country');
  var addressWrap = document.getElementById('address-search-wrap');
  var addressInput = document.getElementById('address-search');
  var suggestionsBox = document.getElementById('address-suggestions');
  var postalValue = document.getElementById('postal-code-value');

  function usesAutocomplete() {
    return countrySelect && banEligible.indexOf(countrySelect.value) !== -1;
  }

  function syncFieldVisibility() {
    if (!countrySelect) return;
    var auto = usesAutocomplete();
    addressWrap.hidden = !auto;
  }

  var destinationForm = document.getElementById('destination-form');

  if (countrySelect) {
    countrySelect.addEventListener('change', function () {
      syncFieldVisibility();
      // Le pays seul suffit à déterminer la zone tarifaire (sauf pour la
      // France, où le code postal distingue Corse et métropole) — pas besoin
      // d'attendre un clic sur "Actualiser" pour refléter le changement.
      if (destinationForm) destinationForm.submit();
    });
    syncFieldVisibility();
  }

  if (postalValue) {
    // Le code postal fixe la zone tarifaire : envoi du formulaire (qui garde aussi l'adresse saisie) dès qu'il change.
    postalValue.addEventListener('change', function () { if (destinationForm) destinationForm.submit(); });
  }

  // Au passage à la caisse, l'adresse affichée dans « Destination » (même non « actualisée ») part avec le formulaire de paiement.
  var checkoutForm = document.getElementById('checkout-form');
  if (checkoutForm) checkoutForm.addEventListener('submit', function () {
    var map = { dest_country: 'destination-country', dest_line1: 'address-line1', dest_line2: 'address-line2', dest_postal: 'postal-code-value', dest_city: 'address-city' };
    Object.keys(map).forEach(function (k) { var src = document.getElementById(map[k]), dst = checkoutForm.elements[k]; if (src && dst) dst.value = src.value; });
  });

  var subtotalEl = document.getElementById('cart-grand-total');
  var radios = document.querySelectorAll('input[name="shipping_choice"]');
  if (subtotalEl && radios.length) {
    var subtotalCents = parseInt(subtotalEl.dataset.subtotalCents, 10) || 0;
    function formatCents(cents) {
      return (cents / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €';
    }
    function updateTotal() {
      var checked = document.querySelector('input[name="shipping_choice"]:checked');
      var shippingCents = checked ? (parseInt(checked.dataset.priceCents, 10) || 0) : 0;
      subtotalEl.textContent = formatCents(subtotalCents + shippingCents);
    }
    radios.forEach(function (r) { r.addEventListener('change', updateTotal); });
    updateTotal();
  }
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
  .checkout-contact input[type=text], .checkout-contact input[type=tel], .checkout-contact input[type=email] { display: block; width: 100%; margin-top: 6px; min-height: 44px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font: inherit; font-family: var(--font-body); font-size: 1rem; }
  .checkout-contact input[aria-invalid=true] { border-color: #b3261e; }
  .checkout-contact small { display: block; margin-top: 4px; font-family: var(--font-body); font-size: 0.78rem; }
  .checkout-contact .cc-check { display: flex; gap: 10px; align-items: flex-start; margin-top: 14px; font-family: var(--font-body); font-size: 0.88rem; color: var(--ink); line-height: 1.45; }
  .checkout-contact .cc-check input { width: 22px; height: 22px; flex: none; margin-top: 1px; }
  .checkout-contact .cc-err { display: block; color: #b3261e; font-family: var(--font-body); font-size: 0.82rem; font-style: normal; margin-top: 4px; }
  .checkout-contact .cc-note { font-size: 0.88rem; margin: 0 0 12px; } .checkout-contact .cc-req { color: #b3261e; }
</style>
<script>
(function () {   // « Livraison pour une autre personne » : champs du destinataire affichés et exigés seulement si la case est cochée
  var box = document.getElementById('ship-other'), wrap = document.getElementById('ship-other-fields');
  if (!box || !wrap) return;
  function sync() { wrap.hidden = !box.checked; wrap.querySelectorAll('input').forEach(function (i) { i.required = box.checked; }); }
  box.addEventListener('change', sync); sync();
})();
</script>
<script src="/assets/cart-address.js?v=<?= (int) @filemtime(__DIR__ . '/assets/cart-address.js') ?>" defer></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
