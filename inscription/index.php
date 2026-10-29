<?php
// Création de boutique — ÉTAPE 1 : choix de la formule. Ensuite :
//   formule payante (et Stripe configuré)  → étape de coordonnées (e-mail ou Google), puis Stripe Checkout ;
//   formule gratuite, ou paiement en ligne indisponible → étape 3, qui recueille l'e-mail et les accès de la boutique.
// ÉTAPE 3 (creer.php) : nom, adresse et compte de la boutique — elle GÉNÈRE la boutique avec un design par défaut.
// Protections : jeton CSRF, champ piège, 5 demandes par heure et par adresse IP. Rien n'est créé avant l'étape 3.
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/addons.php';
require_once __DIR__ . '/../includes/plugins.php';

session_start();
if (empty($_SESSION['saas_csrf'])) $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));

$cfg = saas_config();
$origin = saas_origin();
$editRequested = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? isset($_POST['edit_request'])
    : isset($_GET['r']) || isset($_GET['k']);
$editRequest = $editRequested
    ? saas_request_by_link(
        (string) ($_POST['r'] ?? $_GET['r'] ?? ''),
        (string) ($_POST['k'] ?? $_GET['k'] ?? '')
    )
    : null;
if ($editRequest && (($editRequest['status'] ?? '') !== 'draft' || !saas_request_active($editRequest) || !saas_req_payable($editRequest))) {
    $editRequest = null;
}
$invalidEditLink = $editRequested && !$editRequest && $_SERVER['REQUEST_METHOD'] !== 'POST';
if ($invalidEditLink) $editRequested = false;
// La galerie et le type de site déterminent la grille de tarifs ; sans grille de galerie, les tarifs plateforme s'appliquent.
$galleries = saas_signup_galleries();
$gallery = strtolower(trim((string) ($_POST['galerie'] ?? ($editRequest['gallery'] ?? $_GET['galerie'] ?? ''))));
if (!isset($galleries[$gallery])) $gallery = '';
$savedOffer = $editRequest ? saas_req_offer($editRequest) : [];
$siteMode = (string) ($_POST['site_mode'] ?? ($savedOffer['mode'] ?? $editRequest['site_mode'] ?? $_GET['mode'] ?? 'sales'));
if (!in_array($siteMode, ['sales', 'showcase'], true)) $siteMode = 'sales';
$grid = saas_grid($gallery, $siteMode);
$offers = saas_grid_offers($grid);
$offersByKey = array_column($offers, null, 'key');
$stripeOn = saas_stripe_configured();
$pluginCatalog = plugin_catalog();
$addonSettings = addon_settings_load();
$serviceQuoteCatalog = saas_service_quote_catalog();
$selectedServiceQuotes = [];
foreach (is_array($_POST['service_quotes'] ?? null) ? $_POST['service_quotes'] : (array) ($editRequest['service_quotes'] ?? []) as $serviceQuote) {
    if (is_string($serviceQuote) && isset($serviceQuoteCatalog[$serviceQuote])) $selectedServiceQuotes[] = $serviceQuote;
}
$errors = [];
if ($invalidEditLink) {
    $errors['form'] = 'Ce lien de modification a expiré ou n’est plus valide. Vous pouvez choisir une nouvelle formule.';
}
if (!empty($_SESSION['inscription_flash'])) {   // message laissé par la connexion sociale (oauth/finish.php)
    $errors['form'] = (string) $_SESSION['inscription_flash'];
    unset($_SESSION['inscription_flash']);
}
$resumable = null;
$featured = array_values(array_filter($offers, static fn ($o) => $o['featured']))[0] ?? ($offers[1] ?? $offers[0] ?? null);
$defaultBilling = ($_GET['facturation'] ?? '') === 'year' && $grid['yearly'] ? 'year' : 'month';
$v = [
    'plan' => (string) ($_POST['plan'] ?? ($savedOffer['key'] ?? $_GET['formule'] ?? ($featured['key'] ?? ''))),
    'billing' => (string) ($_POST['facturation'] ?? ($savedOffer['billing'] ?? $_GET['facturation'] ?? $defaultBilling)),
    'site_mode' => $siteMode,
];
if (!in_array($v['billing'], ['month', 'year'], true)) $v['billing'] = 'month';
$explicitOfferSelection = $_SERVER['REQUEST_METHOD'] === 'POST'
    || !empty($_GET['formule'])
    || $editRequest !== null;
if (!isset($offersByKey[$v['plan']])) {
    if ($explicitOfferSelection) {
        $errors['form'] = 'La formule précédemment choisie n’est pas disponible pour ce type de site ou cette galerie. Aucune autre formule payante n’a été sélectionnée à sa place : choisissez votre offre ci-dessous.';
        $v['plan'] = '';
    } elseif ($featured) {
        $v['plan'] = $featured['key'];
    }
}

// Retour d'un paiement annulé : la demande reste réservée 24 h et peut être reprise.
if (isset($_GET['annule']) && ($r = saas_request_load((string) $_GET['annule'])) && ($r['status'] ?? '') === 'awaiting_payment' && saas_request_active($r)) {
    $resumable = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['plan'] = trim((string) ($_POST['plan'] ?? $v['plan']));
    $v['site_mode'] = $siteMode;
    $v['billing'] = ($_POST['facturation'] ?? '') === 'year' && $grid['yearly'] ? 'year' : 'month';
    if (!hash_equals((string) $_SESSION['saas_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors['form'] = 'Le formulaire a expiré : rechargez la page et recommencez.';
    } elseif (isset($_POST['resume'])) {
        // Reprendre le paiement d'une demande annulée.
        $r = saas_request_load((string) $_POST['resume']);
        if ($r && ($r['status'] ?? '') === 'awaiting_payment' && saas_request_active($r) && saas_req_payable($r)) {
            header('Location: ' . saas_payment_url($r, $origin), true, 303);
            exit;
        }
        $errors['form'] = 'Cette demande ne peut plus être reprise : refaites-la ci-dessous.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        // Champ piège rempli : un robot. On fait comme si c'était accepté.
        header('Location: /inscription/?recu=1', true, 303);
        exit;
    } elseif (isset($_POST['edit_request'])) {
        $editRequest = saas_request_by_link((string) ($_POST['r'] ?? ''), (string) ($_POST['k'] ?? ''));
        if (!$editRequest || ($editRequest['status'] ?? '') !== 'draft' || !saas_request_active($editRequest) || !saas_req_payable($editRequest)) {
            $editRequest = null;
            $errors['form'] = 'Ce brouillon ne peut plus être modifié. Choisissez à nouveau une formule.';
        } else {
            $offer = saas_offer_snapshot($gallery, $v['plan'], $v['billing'], $siteMode);
            $quoteInput = $_POST['service_quotes'] ?? [];
            $serviceQuotes = [];
            if (!is_array($quoteInput)) {
                $errors['service_quotes'] = 'La sélection des prestations est invalide.';
            } else {
                foreach ($quoteInput as $serviceQuote) {
                    if (!is_string($serviceQuote) || !isset($serviceQuoteCatalog[$serviceQuote])) {
                        $errors['service_quotes'] = 'Une prestation sélectionnée n’est pas reconnue.';
                        break;
                    }
                    $serviceQuotes[] = $serviceQuote;
                }
                $serviceQuotes = array_values(array_unique($serviceQuotes));
            }
            if (!$offer) $errors['plan'] = 'Choisissez une formule.';
            if ($offer && (int) ($offer['cents'] ?? 1) === 0 && saas_free_cap_reached()) {
                $errors['form'] = "Les créations de boutiques gratuites sont complètes pour aujourd'hui : revenez demain, ou choisissez une formule payante.";
            }
            if (!$errors) {
                $previousOffer = saas_req_offer($editRequest);
                $offerChanged = ($previousOffer['key'] ?? '') !== $offer['key']
                    || ($previousOffer['billing'] ?? '') !== $offer['billing']
                    || ($previousOffer['gallery'] ?? '') !== $gallery
                    || ($previousOffer['mode'] ?? $editRequest['site_mode'] ?? 'sales') !== $siteMode;
                $editRequest['plan'] = $offer['key'];
                $editRequest['site_mode'] = $siteMode;
                $editRequest['gallery'] = $gallery;
                $editRequest['offer'] = $offer;
                $editRequest['service_quotes'] = $serviceQuotes;
                if ($offerChanged) {
                    $editRequest['email'] = '';
                    unset($editRequest['contact_method'], $editRequest['terms_accepted'], $editRequest['social']);
                    $editRequest['payment'] = [];
                }
                if (!saas_request_save($editRequest)) {
                    $errors['form'] = 'Impossible d’enregistrer les changements : réessayez.';
                } else {
                    $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));
                    $next = saas_configurateur_url($editRequest, $origin);
                    header('Location: ' . $next, true, 303);
                    exit;
                }
            }
        }
    } elseif (saas_rate_limited()) {
        $errors['form'] = 'Trop de demandes depuis votre connexion : réessayez dans une heure.';
    } elseif (isset($offersByKey[$v['plan']]) && (saas_offer_cents($offersByKey[$v['plan']], $v['billing']) ?? 1) === 0 && saas_free_cap_reached()) {
        $errors['form'] = "Les créations de boutiques gratuites sont complètes pour aujourd'hui : revenez demain, ou choisissez une formule payante.";
    } else {
        $res = saas_request_start($_POST + ['galerie' => $gallery]);
        if ($res['ok']) {
            $req = $res['request'];
            $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));
            $next = saas_configurateur_url($req, $origin);
            header('Location: ' . $next, true, 303);
            exit;
        }
        $errors = $res['errors'];
    }
}

$err = static fn (string $k): string => isset($errors[$k]) ? '<span class="err" role="alert">' . saas_e($errors[$k]) . '</span>' : '';

saas_page_start('Créer ma boutique — ' . $cfg['name'], 'Choisissez votre formule, réglez en ligne, puis créez votre boutique : nom et compte. Le design se choisit après la création.', 'inscription');
?>
<div class="wrap">
  <div class="page-title"><p class="eyebrow">Création de boutique</p><h1>Créer ma boutique</h1>
    <ol class="stepper" aria-label="Étapes"><li class="is-current" aria-current="step"><b>1</b> Ma formule</li><li><b>2</b> Préconfiguration</li><li><b>3</b> Design</li><li><b>4</b> Paiement</li><li><b>5</b> Ma boutique</li></ol>
    <p class="lede">Choisissez votre formule. Vous configurerez ensuite le nom de votre boutique et son domaine, puis son design avant <?= $stripeOn ? 'le paiement sécurisé (pour une offre payante) ou la création de votre boutique' : 'la création de votre boutique' ?>.</p></div>

  <?php if (isset($_GET['recu'])): ?>
    <div class="form-card"><div class="notice ok" role="status"><strong>Demande reçue, merci !</strong></div><p><a class="btn" href="/">Retour à l'accueil</a></p></div>
  <?php else: ?>
    <?php if ($resumable): ?>
      <div class="form-card" style="margin-bottom:20px">
        <div class="notice" role="status"><strong>Paiement non terminé.</strong> Votre demande est gardée 24 heures.</div>
        <form method="post" action="/inscription/"><input type="hidden" name="csrf" value="<?= saas_e($_SESSION['saas_csrf']) ?>"><input type="hidden" name="resume" value="<?= saas_e($resumable['id']) ?>">
          <button class="btn" type="submit">Reprendre le paiement</button></form>
      </div>
    <?php endif; ?>
    <form class="form-card" method="post" action="/inscription/" novalidate>
      <?php if (isset($errors['form'])): ?><div class="notice error" role="alert"><?= saas_e($errors['form']) ?></div><?php elseif ($errors): ?><div class="notice error" role="alert"><strong>La création n'a pas pu aboutir :</strong><ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($errors as $m): ?><li><?= saas_e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= saas_e($_SESSION['saas_csrf']) ?>">
      <?php if ($editRequested): ?>
        <input type="hidden" name="edit_request" value="1">
        <input type="hidden" name="r" value="<?= saas_e((string) ($_POST['r'] ?? $editRequest['id'] ?? '')) ?>">
        <input type="hidden" name="k" value="<?= saas_e((string) ($_POST['k'] ?? $editRequest['token'] ?? '')) ?>">
      <?php endif; ?>
      <div class="hp" aria-hidden="true"><label>Ne pas remplir<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <div class="form-grid">
        <div class="field full"><label for="site-mode" style="font-size:.82rem;font-weight:600">Type de site</label>
          <select id="site-mode" name="site_mode" onchange="var p=new URLSearchParams(location.search);p.set('mode',this.value);p.set('galerie',document.getElementById('galerie-pick')?document.getElementById('galerie-pick').value:'');p.set('formule',document.querySelector('input[name=plan]:checked')?.value||'');p.set('facturation',document.querySelector('input[name=facturation]:checked')?.value||'month');location.href='/inscription/?'+p.toString()">
            <option value="sales"<?= $siteMode === 'sales' ? ' selected' : '' ?>>Boutique avec vente en ligne</option>
            <option value="showcase"<?= $siteMode === 'showcase' ? ' selected' : '' ?>>Boutique vitrine, sans vente</option>
          </select>
          <small><?= $siteMode === 'showcase' ? 'Présentez vos produits sans afficher de prix ni proposer le panier ou le paiement.' : 'Proposez vos produits à la vente avec panier et paiement en ligne.' ?></small>
        </div>
        <?php if ($galleries): ?>
          <div class="field full"><label for="galerie-pick" style="font-size:.82rem;font-weight:600">Galerie commerciale <small>(les tarifs peuvent y être différents)</small></label>
            <select id="galerie-pick" onchange="var p=new URLSearchParams(location.search);p.set('galerie',this.value);p.set('mode',document.getElementById('site-mode').value);p.set('formule',document.querySelector('input[name=plan]:checked')?.value||'');p.set('facturation',document.querySelector('input[name=facturation]:checked')?.value||'month');location.href='/inscription/?'+p.toString()">
              <option value="">Aucune : ma boutique seule</option>
              <?php foreach ($galleries as $slug => $name): ?><option value="<?= saas_e($slug) ?>"<?= $slug === $gallery ? ' selected' : '' ?>><?= saas_e($name) ?></option><?php endforeach; ?>
            </select>
            <?php if ($gallery !== ''): ?><small><?= $grid['own'] ? 'Tarifs propres à cette galerie.' : 'Cette galerie applique les tarifs habituels.' ?></small><?php endif; ?></div>
        <?php endif; ?>
        <input type="hidden" name="galerie" value="<?= saas_e($gallery) ?>">
        <fieldset class="field full<?= isset($errors['plan']) ? ' has-error' : '' ?>" style="border:0;padding:0;margin:0"><span style="font-size:.82rem;font-weight:600">Votre offre</span>
          <?= saas_offer_picker_html($grid, $v['plan'], $v['billing']) ?>
          <?= $err('plan') ?><?php if ($stripeOn): ?><small>Offre payante : paiement par carte (Stripe), abonnement résiliable. Offre gratuite : aucun paiement.</small><?php endif; ?></fieldset>

        <section class="field full signup-extra" aria-labelledby="plugin-catalog-title">
          <h2 id="plugin-catalog-title">Catalogue des plugins</h2>
          <p>Découvrez les options et leurs tarifs configurés. Les plugins se configurent et s’achètent depuis l’administration de votre boutique après sa création ; ils ne sont pas ajoutés au paiement de l’offre ci-dessus.</p>
          <?php if ($pluginCatalog): ?>
            <div class="signup-catalog">
              <?php foreach ($pluginCatalog as $plugin):
                  $addon = addon_info_resolve($plugin['id'], $addonSettings, '', $gallery);
                  $modes = $addon['payment_modes'] ?? [];
                  $pluginPrices = [];
                  foreach (['monthly' => ['monthly_cents', 'mois'], 'yearly' => ['yearly_cents', 'an'], 'once' => ['once_cents', 'une fois']] as $mode => [$key, $period]) {
                      if (!in_array($mode, $modes, true)) continue;
                      $cents = $addon[$key] ?? null;
                      if ($cents !== null) $pluginPrices[] = $cents > 0 ? saas_money((int) $cents) . ' / ' . $period : 'Gratuit';
                  }
                  if (in_array('manual', $modes, true)) $pluginPrices[] = 'Sur devis';
                  if (in_array('free', $modes, true)) $pluginPrices[] = 'Gratuit';
                  $priceText = !$addon ? 'Tarif à confirmer'
                      : ($addon['access'] === 'unavailable' ? 'Non proposé actuellement'
                          : ($addon['access'] === 'included' ? 'Inclus dans votre offre'
                              : ($pluginPrices ? implode(' · ', array_unique($pluginPrices)) : 'Tarif à confirmer')));
              ?>
                <details class="signup-item">
                  <summary><strong><?= saas_e($plugin['name']) ?></strong><span class="price-list"><?= saas_e($priceText) ?><?= !empty($addon['trial_days']) ? ' · essai ' . (int) $addon['trial_days'] . ' jours' : '' ?></span></summary>
                  <?php if ($plugin['description'] !== ''): ?><p><?= saas_e($plugin['description']) ?></p><?php endif; ?>
                </details>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p>Aucun plugin n’est actuellement disponible.</p>
          <?php endif; ?>
        </section>

        <fieldset class="field full signup-extra" style="border:0;padding:0" aria-labelledby="service-quotes-title">
          <h2 id="service-quotes-title">Prestations personnalisées — sur devis</h2>
          <p>Choisissez les prestations qui vous intéressent. Votre demande sera transmise à l’exploitant après la création du site ; aucun paiement n’est ajouté à votre inscription.</p>
          <div class="service-grid">
            <?php foreach ($serviceQuoteCatalog as $key => $service): ?>
              <details class="signup-item service-choice"<?= in_array($key, $selectedServiceQuotes, true) ? ' open' : '' ?>>
                <summary><strong><?= saas_e($service['name']) ?></strong><span class="quote-label">Sur devis</span></summary>
                <label class="service-select">
                  <input type="checkbox" name="service_quotes[]" value="<?= saas_e($key) ?>"<?= in_array($key, $selectedServiceQuotes, true) ? ' checked' : '' ?>>
                  <span><?= saas_e($service['description']) ?></span>
                  <span class="service-select-label">Demander un devis pour cette prestation</span>
                </label>
              </details>
            <?php endforeach; ?>
          </div>
          <?php if (isset($errors['service_quotes'])): ?><span class="err" role="alert"><?= saas_e($errors['service_quotes']) ?></span><?php endif; ?>
        </fieldset>

      </div>
      <p style="margin:22px 0 0"><button class="btn" type="submit"><?= $editRequested ? 'Mettre à jour et continuer' : 'Continuer' ?></button></p>
      <p class="oauth-note" style="margin-top:12px">Aucun compte ni e-mail à saisir à cette étape. Pour une offre payante, Stripe vous demandera votre e-mail au paiement ; pour une offre gratuite, vous le renseignerez à l’étape de création.</p>
    </form>
  <?php endif; ?>
</div>
<?php if ($errors): ?><script>(function(){var f=document.querySelector('.has-error input,.has-error textarea,.has-error select');if(f){f.scrollIntoView({block:'center'});f.focus({preventScroll:true});}})();</script><?php endif; ?>
<?php saas_page_end(); ?>
