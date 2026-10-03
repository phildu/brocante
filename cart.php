<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $ref = (string) ($_POST['ref'] ?? '');
    if ($_POST['action'] === 'remove') {
        cart_remove($ref);
    } elseif ($_POST['action'] === 'qty') {
        cart_set_qty($ref, max(0, (int) ($_POST['qty'] ?? 1)));
    } elseif ($_POST['action'] === 'set_destination') {
        $country = (string) ($_POST['country'] ?? 'FR');
        $_SESSION['shipping_country'] = array_key_exists($country, shipping_allowed_countries()) ? $country : 'FR';
        $_SESSION['shipping_postal_code'] = preg_replace('/[^0-9]/', '', (string) ($_POST['postal_code'] ?? ''));
    }
    header('Location: /cart.php');
    exit;
}

$lines = cart_lines();
$total = cart_total_cents();
$content = get_content();

$destCountry = (string) ($_SESSION['shipping_country'] ?? 'FR');
$destPostalCode = (string) ($_SESSION['shipping_postal_code'] ?? '');
$destZone = shipping_zone_for_address($destCountry, $destPostalCode);
$carrierRates = $lines ? matching_shipping_rates(cart_total_weight_g($lines), $destZone) : [];
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

    <?php if (!stripe_configured()): ?>
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
            <div class="product-admin-row" style="grid-template-columns:90px 1fr auto;">
              <div class="admin-photo-preview"><?= product_media_html($p) ?></div>
              <div class="product-admin-fields">
                <h3 style="font-size:1.05rem;"><?= h($p['name']) ?></h3>
                <p class="desc" style="color:var(--ink-soft);font-size:0.88rem;"><?= format_cents($line['unit_cents']) ?> pièce</p>
                <form method="post" style="display:flex;align-items:center;gap:10px;">
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
        <div class="shipping-destination" style="margin-top:20px;padding-top:20px;border-top:1px solid var(--line);">
          <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Destination</span>
          <form method="post" id="destination-form" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
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

            <label id="address-search-wrap" style="font-family:var(--font-mono);font-size:0.7rem;color:var(--ink-soft);position:relative;">
              Adresse
              <input type="text" id="address-search" placeholder="Tapez votre adresse..." autocomplete="off" value="<?= h($destPostalCode) ?>" style="display:block;margin-top:6px;width:240px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:8px;">
              <div id="address-suggestions" class="address-suggestions" hidden></div>
            </label>

            <label id="postal-code-wrap" hidden style="font-family:var(--font-mono);font-size:0.7rem;color:var(--ink-soft);">Code postal
              <input type="text" id="postal-code-manual" value="<?= h($destPostalCode) ?>" placeholder="ex : 10115" inputmode="numeric" style="display:block;margin-top:6px;width:140px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:8px;">
            </label>

            <input type="hidden" name="postal_code" id="postal-code-value" value="<?= h($destPostalCode) ?>">
            <button type="submit" class="btn-small">Actualiser</button>
          </form>
          <p style="font-size:0.78rem;color:var(--ink-soft);margin-top:10px;">
            Zone tarifaire détectée : <strong><?= h(shipping_zones()[$destZone] ?? $destZone) ?></strong>
            <?php if ($destZone === 'corse'): ?> — les codes postaux 20xxx sont reconnus comme Corse.<?php endif; ?>
          </p>
        </div>

        <form method="post" action="/checkout.php" id="checkout-form" style="margin-top:24px;">
          <div class="shipping-choice" style="margin-bottom:20px;padding-top:20px;border-top:1px solid var(--line);">
            <span class="eyebrow" style="font-size:0.9rem;display:block;margin-bottom:12px;">Livraison</span>

            <label class="shipping-choice-option">
              <input type="radio" name="shipping_choice" value="pickup" data-price-cents="0" checked>
              <span><?= h($content['pickup_label']) ?></span>
              <span class="shipping-choice-price">Gratuit</span>
            </label>

            <?php if ($carrierRates): ?>
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

          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <span class="eyebrow" style="font-size:0.95rem;">Total avec livraison</span>
            <span class="price" id="cart-grand-total" style="font-size:1.5rem;" data-subtotal-cents="<?= (int) $total ?>"><?= format_cents($total) ?></span>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;" <?= stripe_configured() ? '' : 'disabled' ?>>Passer à la caisse</button>
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
  var postalWrap = document.getElementById('postal-code-wrap');
  var postalManual = document.getElementById('postal-code-manual');
  var postalValue = document.getElementById('postal-code-value');

  function usesAutocomplete() {
    return countrySelect && banEligible.indexOf(countrySelect.value) !== -1;
  }

  function syncFieldVisibility() {
    if (!countrySelect) return;
    var auto = usesAutocomplete();
    addressWrap.hidden = !auto;
    postalWrap.hidden = auto;
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

  if (postalManual && postalValue) {
    postalManual.addEventListener('input', function () {
      postalValue.value = postalManual.value;
    });
    postalManual.addEventListener('change', function () {
      if (destinationForm) destinationForm.submit();
    });
  }

  if (addressInput && suggestionsBox && postalValue) {
    var debounceTimer = null;
    addressInput.addEventListener('input', function () {
      var query = addressInput.value.trim();
      clearTimeout(debounceTimer);
      if (query.length < 3) {
        suggestionsBox.hidden = true;
        suggestionsBox.innerHTML = '';
        return;
      }
      debounceTimer = setTimeout(function () {
        fetch('https://api-adresse.data.gouv.fr/search/?limit=5&q=' + encodeURIComponent(query))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            var features = (data && data.features) || [];
            if (!features.length) {
              suggestionsBox.hidden = true;
              suggestionsBox.innerHTML = '';
              return;
            }
            suggestionsBox.innerHTML = '';
            features.forEach(function (f) {
              var item = document.createElement('button');
              item.type = 'button';
              item.className = 'address-suggestion-item';
              item.textContent = f.properties.label;
              item.addEventListener('click', function () {
                addressInput.value = f.properties.label;
                postalValue.value = f.properties.postcode || '';
                suggestionsBox.hidden = true;
                suggestionsBox.innerHTML = '';
                if (destinationForm) destinationForm.submit();
              });
              suggestionsBox.appendChild(item);
            });
            suggestionsBox.hidden = false;
          })
          .catch(function () { suggestionsBox.hidden = true; });
      }, 300);
    });

    document.addEventListener('click', function (e) {
      if (e.target !== addressInput && !suggestionsBox.contains(e.target)) {
        suggestionsBox.hidden = true;
      }
    });
  }

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

<?php include __DIR__ . '/includes/footer.php'; ?>
