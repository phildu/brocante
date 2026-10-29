<?php
// ÉTAPE 2 : e-mail ou compte Google pour une offre payable, puis Stripe Checkout.
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/branding-services.php';

session_start();
if (empty($_SESSION['saas_csrf'])) $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));

$origin = saas_origin();
$req = saas_request_by_link((string) ($_REQUEST['r'] ?? ''), (string) ($_REQUEST['k'] ?? ''));
$errors = [];
$email = trim((string) ($_POST['email'] ?? ''));
$brandingStep = (array) ($req['branding']['step2'] ?? []);
$shopName = trim((string) (!empty($_POST['shop_name']) ? $_POST['shop_name'] : (!empty($req['shop_name']) ? $req['shop_name'] : ($req['preferred_shop_name'] ?? ''))));
$about = trim((string) ($_POST['about'] ?? $req['about'] ?? ''));
$location = trim((string) ($_POST['location'] ?? $req['location'] ?? ''));
$activity = (array) ($req['activity_details'] ?? []);
$notice = '';
if (!empty($_SESSION['inscription_flash'])) {
    $errors['form'] = (string) $_SESSION['inscription_flash'];
    unset($_SESSION['inscription_flash']);
}

if (!$req || ($req['status'] ?? '') !== 'draft' || !saas_request_active($req)) {
    $req = null;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['saas_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors['form'] = 'Le formulaire a expiré : rechargez la page et recommencez.';
    } else {
        $action = (string) ($_POST['action'] ?? 'continue_payment');
        if ($action === 'check_domains') {
            $shopName = trim((string) ($_POST['shop_name'] ?? ''));
            $about = trim((string) ($_POST['about'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            try {
                $activity = branding_activity_from_post($_POST, $activity);
                branding_activity_validate($activity);
            } catch (InvalidArgumentException $ex) {
                $errors['form'] = $ex->getMessage();
            }
            if ($shopName === '' || mb_strlen($shopName) > 60) {
                $errors['shop_name'] = 'Indiquez un nom de boutique de 1 à 60 caractères.';
            } elseif ($activity['primary'] === '') {
                $errors['form'] = 'Choisissez une activité principale.';
            } elseif (isset($errors['form'])) {
                // L’erreur de sélection est déjà affichée plus haut.
            } elseif (mb_strlen($about) > 400 || mb_strlen($location) > 100) {
                $errors['form'] = 'La description ou l’emplacement dépasse la longueur autorisée.';
            } else {
                try {
                    branding_rate_limit((string) $req['id'], 'step2_domain_search', 5);
                    $search = branding_ovh_search_domains($shopName, $location);
                    $previousPrices = [];
                    foreach ((array) ($brandingStep['availability']['domains'] ?? []) as $previousDomain) {
                        if (!empty($previousDomain['domain']) && isset($previousDomain['price_cents'])
                            && time() - (int) ($previousDomain['price_at'] ?? 0) < 86400) {
                            $previousPrices[(string) $previousDomain['domain']] = $previousDomain;
                        }
                    }
                    $priceErrors = [];
                    foreach ($search['domains'] as &$candidate) {
                        if (empty($candidate['available'])) continue;
                        $cachedPrice = $previousPrices[(string) $candidate['domain']] ?? null;
                        if (is_array($cachedPrice)) {
                            $candidate['price_cents'] = (int) $cachedPrice['price_cents'];
                            $candidate['price_at'] = (int) $cachedPrice['price_at'];
                            continue;
                        }
                        try {
                            $quote = branding_ovh_quote((string) $candidate['domain']);
                            $candidate['price_cents'] = (int) $quote['amount_cents'];
                            $candidate['price_at'] = (int) $quote['quoted_at'];
                        } catch (InvalidArgumentException | RuntimeException $priceError) {
                            $candidate['price_error'] = $priceError->getMessage();
                            $priceErrors[] = (string) $candidate['domain'] . ' : ' . $priceError->getMessage();
                        }
                    }
                    unset($candidate);
                    $req['preferred_shop_name'] = $shopName;
                    $req['shop_name'] = $shopName;
                    $req['about'] = $about;
                    $req['location'] = $location;
                    $req['activity_details'] = $activity;
                    $req['branding']['step2'] = ['availability' => $search, 'name_suggestions' => (array) ($brandingStep['name_suggestions'] ?? [])];
                    $chosen = (array) ($req['branding']['domain_choice'] ?? []);
                    if (($chosen['name'] ?? '') !== $shopName) unset($req['branding']['domain_choice']);
                    if (!saas_request_save($req)) throw new RuntimeException('Impossible d’enregistrer le résultat de la recherche.');
                    $brandingStep = (array) $req['branding']['step2'];
                    $notice = 'Recherche effectuée auprès d’OVH. La disponibilité peut changer avant la commande.';
                    if ($priceErrors) $errors['domain_prices'] = 'Disponibilité vérifiée, mais certains tarifs OVH n’ont pas pu être obtenus : ' . implode(' ; ', $priceErrors);
                } catch (InvalidArgumentException | RuntimeException $ex) {
                    $errors['form'] = $ex->getMessage();
                }
            }
        } elseif ($action === 'select_domain') {
            $intent = (string) ($_POST['intent'] ?? '');
            $postedShopName = trim((string) ($_POST['shop_name'] ?? ''));
            if ($postedShopName !== '') {
                $shopName = $postedShopName;
            } else {
                $shopName = trim((string) (!empty($req['shop_name']) ? $req['shop_name'] : ($req['preferred_shop_name'] ?? $shopName)));
            }
            try {
                $activity = branding_activity_from_post($_POST, $activity);
            } catch (InvalidArgumentException $ex) {
                // Ignore activity formatting errors on domain selection
            }
            $about = mb_substr(trim((string) ($_POST['about'] ?? $req['about'] ?? '')), 0, 400);
            $location = mb_substr(trim((string) ($_POST['location'] ?? $req['location'] ?? '')), 0, 100);
            $req['activity_details'] = $activity;
            $req['about'] = $about;
            $req['location'] = $location;
            if ($shopName !== '') {
                $req['preferred_shop_name'] = $shopName;
                $req['shop_name'] = $shopName;
            }
            if ($intent === 'platform') {
                $req['branding']['domain_choice'] = ['method' => 'platform', 'name' => $shopName, 'selected_at' => time()];
                if (isset($req['branding']['domain_followup'])) $req['branding']['domain_followup']['status'] = 'completed';
                $notice = 'Vous avez choisi l’adresse incluse de la plateforme (sous-domaine offert).';
            } elseif ($intent === 'custom') {
                $domain = (string) ($_POST['domain'] ?? '');
                $available = false;
                foreach ((array) ($brandingStep['availability']['domains'] ?? []) as $candidate) {
                    if (($candidate['domain'] ?? '') === $domain && !empty($candidate['available'])) {
                        $available = true;
                        break;
                    }
                }
                if (!$available || $shopName === '' || time() - (int) ($brandingStep['availability']['searched_at'] ?? 0) > 86400) {
                    $errors['form'] = 'Le résultat de disponibilité a expiré ou le domaine n’est plus disponible dans la recherche la plus récente. Effectuez une nouvelle recherche.';
                } else {
                    $req['branding']['domain_choice'] = ['method' => 'custom', 'domain' => $domain, 'name' => $shopName, 'selected_at' => time()];
                    if (isset($req['branding']['domain_followup'])) $req['branding']['domain_followup']['status'] = 'completed';
                    $notice = 'Domaine préselectionné. Il ne sera pas réservé avant le paiement de la commande après la création de la boutique.';
                }
            } else {
                $errors['form'] = 'Choisissez une option de domaine valide.';
            }
            if (!saas_request_save($req)) $errors['form'] = 'Impossible d’enregistrer votre choix de domaine.';
        } elseif ($action === 'suggest_names') {
            $shopName = trim((string) ($_POST['shop_name'] ?? $req['preferred_shop_name'] ?? ''));
            $about = trim((string) ($_POST['about'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            try {
                $activity = branding_activity_from_post($_POST, $activity);
                branding_activity_validate($activity);
            } catch (InvalidArgumentException $ex) {
                $errors['form'] = $ex->getMessage();
            }
            if ($shopName === '' || mb_strlen($shopName) > 60 || $activity['primary'] === '' || mb_strlen($about) < 10 || mb_strlen($about) > 400 || mb_strlen($location) > 100) {
                $errors['form'] = 'Indiquez un nom, décrivez votre métier (10 à 400 caractères) et, si utile, votre emplacement (100 caractères maximum).';
            } elseif (!$errors) {
                try {
                    branding_rate_limit((string) $req['id'], 'step2_names', 5);
                    if (($req['branding']['domain_choice']['name'] ?? '') !== $shopName) unset($req['branding']['domain_choice']);
                    $req['preferred_shop_name'] = $shopName;
                    $req['shop_name'] = $shopName;
                    $req['about'] = $about;
                    $req['location'] = $location;
                    $req['activity_details'] = $activity;
                    $names = branding_generate_names($req);
                    $priorAvailability = (array) ($brandingStep['availability'] ?? []);
                    if (($priorAvailability['primary'] ?? '') !== trim(saas_slug($shopName), '-')) $priorAvailability = [];
                    $req['branding']['step2'] = ['availability' => $priorAvailability, 'name_suggestions' => $names];
                    if (!saas_request_save($req)) throw new RuntimeException('Impossible d’enregistrer les suggestions de noms.');
                    $brandingStep = (array) $req['branding']['step2'];
                    $notice = 'Voici des idées de noms. Sélectionnez-en un pour vérifier ses domaines.';
                } catch (InvalidArgumentException | RuntimeException $ex) {
                    $errors['form'] = $ex->getMessage();
                }
            }
        } elseif ($action === 'continue_payment') {
            $shopName = trim((string) ($_POST['shop_name'] ?? ''));
            $about = trim((string) ($_POST['about'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            try {
                $activity = branding_activity_from_post($_POST, $activity);
                branding_activity_validate($activity);
            } catch (InvalidArgumentException $ex) {
                $errors['form'] = $ex->getMessage();
            }
            if ($shopName === '' || mb_strlen($shopName) > 60) {
                $errors['shop_name'] = 'Indiquez un nom de boutique de 1 à 60 caractères.';
            } elseif ($activity['primary'] === '') {
                $errors['form'] = 'Choisissez une activité principale.';
            } elseif (empty($_POST['terms'])) {
                $errors['terms'] = 'Acceptez les conditions pour continuer.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                $errors['email'] = 'Indiquez une adresse e-mail valide.';
            } elseif ($errors) {
                // Conserve l’erreur de sélection déjà ajoutée ci-dessus.
            } else {
                $req['preferred_shop_name'] = $shopName;
                $req['shop_name'] = $shopName;
                $req['about'] = mb_substr($about, 0, 400);
                $req['location'] = mb_substr($location, 0, 100);
                $req['activity_details'] = $activity;
                if (($req['branding']['domain_choice']['name'] ?? '') !== $shopName) unset($req['branding']['domain_choice']);
                $req['email'] = strtolower($email);
                $req['contact_method'] = 'email';
                $req['terms_accepted'] = time();
                if (!empty($_POST['defer_domain']) && $_POST['defer_domain'] === '1') {
                    $savedStep = (array) ($req['branding']['step2'] ?? []);
                    $savedAvailability = (array) ($savedStep['availability'] ?? []);
                    $followupConsent = !empty($_POST['domain_followup_consent']);
                    $req['branding']['domain_followup'] = [
                        'status' => $followupConsent ? 'pending' : 'consent_required',
                        'requested_at' => time(),
                        'consented_at' => $followupConsent ? time() : 0,
                        'reminders' => [],
                        'suggestions' => array_values(array_filter((array) ($savedStep['name_suggestions'] ?? []), 'is_string')),
                        'domains' => array_values(array_filter((array) ($savedAvailability['domains'] ?? []), 'is_array')),
                    ];
                }
                if (!saas_request_save($req)) {
                    $errors['form'] = "Impossible d'enregistrer votre adresse e-mail : réessayez.";
                } else {
                    if (!empty($_POST['defer_domain']) && $_POST['defer_domain'] === '1') {
                        saas_event('domain_followup_requested', [
                            'ref' => (string) $req['id'],
                            'email' => (string) $req['email'],
                            'plan' => (string) $req['plan'],
                            'note' => !empty($_POST['domain_followup_consent']) ? 'consentement relance accordé' : 'consentement relance absent',
                            'actor' => 'client',
                        ]);
                    }
                    header('Location: ' . saas_design_url($req, $origin), true, 303);
                    exit;
                }
            }
        } else {
            $errors['form'] = 'Action inconnue.';
        }
    }
}

$req = $req ? (saas_request_load((string) $req['id']) ?? $req) : null;
$shopName = trim((string) (!empty($shopName) ? $shopName : (!empty($req['shop_name']) ? $req['shop_name'] : ($req['preferred_shop_name'] ?? ''))));
if ($req && $shopName !== '') {
    $req['shop_name'] = $shopName;
    $req['preferred_shop_name'] = $shopName;
}
$brandingStep = (array) ($req['branding']['step2'] ?? $brandingStep);
$availability = (array) ($brandingStep['availability'] ?? []);
$nameSuggestions = (array) ($brandingStep['name_suggestions'] ?? []);
$domainChoice = (array) ($req['branding']['domain_choice'] ?? []);
$activity = branding_activity_normalize($activity);
$activityCatalog = branding_activity_catalog();
$activityJson = json_encode($activity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT);
$offer = $req ? saas_req_offer($req) : null;
$backToPlan = $req ? '/inscription/?' . http_build_query([
    'galerie' => (string) ($offer['gallery'] ?? $req['gallery'] ?? ''),
    'mode' => (string) ($offer['mode'] ?? $req['site_mode'] ?? 'sales'),
    'formule' => (string) ($offer['key'] ?? $req['plan'] ?? ''),
    'facturation' => (string) ($offer['billing'] ?? 'month'),
    'r' => (string) $req['id'],
    'k' => (string) $req['token'],
], '', '&', PHP_QUERY_RFC3986) : '';
$yearly = $offer && ($offer['billing'] ?? '') === 'year';
$yearSavings = 0;
if ($offer && $yearly) {
    $gridOffers = saas_grid_offers(saas_grid((string) ($offer['gallery'] ?? ''), (string) ($offer['mode'] ?? 'sales')));
    $pricingOffer = array_column($gridOffers, null, 'key')[(string) ($offer['key'] ?? '')] ?? null;
    if ($pricingOffer) $yearSavings = saas_offer_saving_cents($pricingOffer);
}
$social = $req ? oauth_buttons_html('signup', [
    'r' => (string) $req['id'],
    'k' => (string) $req['token'],
    'shop_name' => $shopName,
    'about' => $about,
    'location' => $location,
    'activity_json' => (string) $activityJson,
], false, ['google']) : '';
$isPayable = $req && saas_req_payable($req);
$rawHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'brocs.arrimage.com'));
$baseDomain = tenant_base_host($rawHost);
$defaultSlug = trim(saas_slug($shopName !== '' ? $shopName : 'ma-boutique'), '-');
if ($defaultSlug === '') $defaultSlug = 'ma-boutique';
$escape = static fn (string $value): string => saas_e($value);

saas_page_start('Préconfiguration — ' . saas_config()['name'], 'Configurez le nom de votre boutique, son activité et vos options de nom de domaine.', 'inscription');
?>
<div class="wrap">
  <div class="page-title">
    <p class="eyebrow">Création de boutique</p>
    <h1>Préconfiguration de ma boutique</h1>
    <ol class="stepper" aria-label="Étapes">
      <li class="is-done"><?php if ($req): ?><a class="stepper-link" href="<?= $escape($backToPlan) ?>" aria-label="Revenir à l’étape Ma formule"><b>✓</b> Ma formule</a><?php else: ?><b>✓</b> Ma formule<?php endif; ?></li>
      <li class="is-current" aria-current="step"><b>2</b> Préconfiguration</li>
      <li><b>3</b> Design</li>
      <?php if ($isPayable): ?>
        <li><b>4</b> Paiement</li>
        <li><b>5</b> Ma boutique</li>
      <?php else: ?>
        <li><b>4</b> Ma boutique</li>
      <?php endif; ?>
    </ol>
    <p class="lede">Choisissez le nom de votre boutique, son activité et vérifiez les domaines associés avant de passer au design de votre boutique.</p>
  </div>
  <?php if (!$req): ?>
    <div class="form-card"><div class="notice error" role="alert"><strong>Cette étape n’est plus disponible.</strong> Le lien a expiré ou a déjà été utilisé.</div>
      <p><a class="btn" href="/inscription/">Choisir une offre</a></p></div>
  <?php else: ?>
    <div class="step2-layout">
      <div class="step2-main">
    <section class="form-card" style="margin-bottom:20px">
      <h2 style="margin-top:0">Nom de la boutique et nom de domaine</h2>
      <p>Un nom de domaine personnalisé facilite la mémorisation. Vérifiez la disponibilité auprès d’OVH pour les extensions .fr, .com, .net et .shop ; des variantes proches sont également proposées si le nom exact est déjà pris.</p>
      <form method="post" action="/inscription/configurateur.php" class="form-grid">
        <input type="hidden" name="csrf" value="<?= $escape($_SESSION['saas_csrf']) ?>">
        <input type="hidden" name="r" value="<?= $escape($req['id']) ?>">
        <input type="hidden" name="k" value="<?= $escape($req['token']) ?>">
        <input type="hidden" name="action" value="check_domains">
        <input type="hidden" name="activity_json" value="<?= $escape((string) $activityJson) ?>" data-activity-json>
        <label class="field full<?= isset($errors['shop_name']) ? ' has-error' : '' ?>"><span>Nom envisagé pour la boutique</span>
          <input type="text" id="step2-shop-name-search" name="shop_name" value="<?= $escape($shopName) ?>" required maxlength="60" autocomplete="organization">
          <?php if (isset($errors['shop_name'])): ?><span class="err" role="alert"><?= $escape($errors['shop_name']) ?></span><?php endif; ?>
        </label>
        <label class="field full<?= isset($errors['form']) && $activity['primary'] === '' ? ' has-error' : '' ?>"><span>Activité principale</span>
          <select id="step2-activity-primary" required>
            <option value="">Choisir l’activité principale</option>
            <?php foreach ($activityCatalog as $key => $label): ?>
              <option value="<?= $escape($key) ?>"<?= $activity['primary'] === $key ? ' selected' : '' ?>><?= $escape($label) ?></option>
            <?php endforeach; ?>
            <option value="other"<?= $activity['primary'] === 'other' ? ' selected' : '' ?>>Autre activité</option>
          </select>
        </label>
        <fieldset class="field full step2-activity-list">
          <legend>Activités secondaires <small>(jusqu’à six choix)</small></legend>
          <div class="form-grid">
            <?php foreach ($activityCatalog as $key => $label): ?>
              <label class="step2-activity-option">
                <input type="checkbox" class="step2-activity-secondary" value="<?= $escape($key) ?>"<?= in_array($key, $activity['secondary'], true) ? ' checked' : '' ?>>
                <span><?= $escape($label) ?></span>
              </label>
            <?php endforeach; ?>
            <label class="step2-activity-option">
              <input type="checkbox" class="step2-activity-secondary" value="other"<?= in_array('other', $activity['secondary'], true) ? ' checked' : '' ?>>
              <span>Autre activité secondaire</span>
            </label>
          </div>
        </fieldset>
        <label class="field full"><span>Précisez l’activité « Autre » <small>(si choisie ci-dessus)</small></span>
          <input type="text" id="step2-activity-other" maxlength="120" value="<?= $escape($activity['other']) ?>" placeholder="Ex. réparation de vélos, photographie…" aria-describedby="step2-activity-help">
          <small id="step2-activity-help">Une activité principale est nécessaire. Vous pouvez ajouter jusqu’à six activités secondaires.</small>
        </label>
        <label class="field full"><span>Ville ou zone géographique <small>(facultatif)</small></span>
          <span style="display:flex;gap:8px;align-items:stretch">
            <input type="text" id="step2-location-search" name="location" value="<?= $escape($location) ?>" maxlength="100" autocomplete="address-level2" placeholder="Commencez à saisir une ville ou une zone" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="step2-location-suggestions">
            <button type="button" class="btn ghost" id="step2-locate" style="padding:8px 12px;white-space:nowrap">Me localiser</button>
          </span>
          <span id="step2-location-suggestions" role="listbox" hidden style="display:grid;border:1px solid var(--line);border-radius:8px;background:var(--surface);max-height:210px;overflow:auto"></span>
          <small id="step2-location-status" role="status" aria-live="polite"></small>
          <small>La saisie utilise la Base Adresse Nationale en France et OpenStreetMap ailleurs. Si vous cliquez sur « Me localiser », votre position est transmise au service d’adresse pour retrouver la ville ; les coordonnées GPS ne sont pas enregistrées. © OpenStreetMap contributors.</small>
        </label>
        <label class="field full"><span>Votre activité / métier <small>(précisez les produits, services ou spécialités pour guider les idées de nom)</small></span>
          <textarea id="step2-about-search" name="about" maxlength="400" rows="2" placeholder="Ex. sélection de livres d’occasion, réparation et vente de vélos…"><?= $escape($about) ?></textarea>
        </label>
        <div class="field full default-subdomain-box" style="background:var(--surface-2);border:1px solid var(--line);border-radius:12px;padding:14px 18px;margin:8px 0 14px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
              <span style="display:block;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--accent)">Adresse incluse par défaut</span>
              <strong style="font-size:1.08rem;word-break:break-all">https://<span id="step2-default-subdomain-slug"><?= $escape($defaultSlug) ?></span>.<?= $escape($baseDomain) ?></strong>
            </div>
            <span class="badge ok" style="font-size:.8rem;font-weight:600;padding:4px 12px;border-radius:999px;background:color-mix(in srgb,var(--ok) 18%,transparent);color:var(--ok)">✓ Sous-domaine offert</span>
          </div>
          <p style="margin:8px 0 0;font-size:.85rem;color:var(--ink-soft);line-height:1.4">Votre boutique disposera d’office de cette adresse gratuite. Vous pouvez la conserver, ou vérifier ci-dessous la disponibilité d’un nom de domaine personnalisé.</p>
        </div>
        <p class="field full"><button class="btn" type="submit">Rechercher les domaines disponibles</button></p>
      </form>
      <form method="post" action="/inscription/configurateur.php" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= $escape($_SESSION['saas_csrf']) ?>">
        <input type="hidden" name="r" value="<?= $escape($req['id']) ?>">
        <input type="hidden" name="k" value="<?= $escape($req['token']) ?>">
        <input type="hidden" name="action" value="suggest_names">
        <input type="hidden" name="shop_name" value="<?= $escape($shopName) ?>" data-step2-name-sync>
        <input type="hidden" name="about" value="<?= $escape($about) ?>" data-step2-about-sync>
        <input type="hidden" name="location" value="<?= $escape($location) ?>" data-step2-location-sync>
        <input type="hidden" name="activity_json" value="<?= $escape((string) $activityJson) ?>" data-activity-json>
        <p style="margin:0 0 6px"><strong>Besoin d’idées pour votre nom ?</strong> L’IA peut proposer des alternatives selon votre activité et votre emplacement. Choisissez une activité principale et décrivez votre métier en au moins 10 caractères.</p>
        <button class="btn ghost" type="submit">Aidez-moi à trouver un autre nom avec l’IA</button>
      </form>
      <?php if ($notice !== ''): ?><div class="notice ok" role="status"><?= $escape($notice) ?></div><?php endif; ?>
      <?php if (isset($errors['form'])): ?><div class="notice error" role="alert"><?= $escape($errors['form']) ?></div><?php endif; ?>
      <?php if (isset($errors['domain_prices'])): ?><div class="notice error" role="alert"><?= $escape($errors['domain_prices']) ?></div><?php endif; ?>
      <?php if (!empty($availability['domains'])): ?>
        <?php $availableDomains = array_values(array_filter((array) $availability['domains'], static fn (array $candidate): bool => !empty($candidate['available']))); ?>
        <?php $exactDomains = array_values(array_filter((array) $availability['domains'], static fn (array $candidate): bool => ($candidate['kind'] ?? '') === 'name')); ?>
        <?php if ($exactDomains && count(array_filter($exactDomains, static fn (array $candidate): bool => empty($candidate['available']))) === count($exactDomains)): ?>
          <div class="notice" role="status">Le nom exact « <?= $escape((string) $availability['primary']) ?> » est déjà pris dans les extensions vérifiées. Voici des variantes proches encore disponibles, si OVH en a trouvé.</div>
        <?php endif; ?>
        <?php if ($availableDomains): ?>
          <p><strong>Domaines disponibles au moment de la vérification :</strong> la disponibilité sera revérifiée au moment de l’achat.</p>
          <div class="form-grid">
            <?php foreach ($availableDomains as $candidate): ?>
              <form method="post" action="/inscription/configurateur.php">
                <input type="hidden" name="csrf" value="<?= $escape($_SESSION['saas_csrf']) ?>">
                <input type="hidden" name="r" value="<?= $escape($req['id']) ?>">
                <input type="hidden" name="k" value="<?= $escape($req['token']) ?>">
                <input type="hidden" name="action" value="select_domain">
                <input type="hidden" name="shop_name" value="<?= $escape($shopName) ?>" data-step2-name-sync>
                <input type="hidden" name="activity_json" value="<?= $escape((string) $activityJson) ?>">
                <input type="hidden" name="about" value="<?= $escape($about) ?>" data-step2-about-sync>
                <input type="hidden" name="location" value="<?= $escape($location) ?>" data-step2-location-sync>
                <input type="hidden" name="intent" value="custom">
                <input type="hidden" name="domain" value="<?= $escape((string) $candidate['domain']) ?>">
                <button class="btn ghost" type="submit">Choisir <?= $escape((string) $candidate['domain']) ?> pour l’achat</button>
                <?php if (isset($candidate['price_cents'])): ?><small><?= $escape(saas_money((int) $candidate['price_cents'])) ?> TTC / an — première année, hors total actuel</small>
                <?php elseif (!empty($candidate['price_error'])): ?><small class="step2-price-error">Prix non vérifié : <?= $escape((string) $candidate['price_error']) ?></small><?php endif; ?>
                <?php if (($candidate['kind'] ?? '') === 'alternative'): ?><small>Variante proche</small><?php endif; ?>
              </form>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="notice" role="status"><strong>Le nom et ses variantes ne sont pas disponibles</strong> dans les extensions vérifiées. Vous pouvez garder l’adresse incluse ou demander à l’IA d’imaginer d’autres noms selon votre métier et votre emplacement.</div>
        <?php endif; ?>
        <p class="meta">Vérification effectuée le <?= date('d/m/Y à H:i', (int) ($availability['searched_at'] ?? time())) ?>. Cette recherche n’est pas une réservation.</p>
      <?php endif; ?>
      <form method="post" action="/inscription/configurateur.php" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= $escape($_SESSION['saas_csrf']) ?>">
        <input type="hidden" name="r" value="<?= $escape($req['id']) ?>">
        <input type="hidden" name="k" value="<?= $escape($req['token']) ?>">
        <input type="hidden" name="action" value="select_domain">
        <input type="hidden" name="shop_name" value="<?= $escape($shopName) ?>" data-step2-name-sync>
        <input type="hidden" name="activity_json" value="<?= $escape((string) $activityJson) ?>">
        <input type="hidden" name="about" value="<?= $escape($about) ?>" data-step2-about-sync>
        <input type="hidden" name="location" value="<?= $escape($location) ?>" data-step2-location-sync>
        <input type="hidden" name="intent" value="platform">
        <button class="btn ghost" type="submit">Garder l’adresse incluse de la plateforme</button>
      </form>
      <?php if ($nameSuggestions): ?>
        <h3>Autres idées de nom</h3>
        <div class="form-grid">
          <?php foreach ($nameSuggestions as $candidate): ?>
            <form method="post" action="/inscription/configurateur.php">
              <input type="hidden" name="csrf" value="<?= $escape($_SESSION['saas_csrf']) ?>">
              <input type="hidden" name="r" value="<?= $escape($req['id']) ?>">
              <input type="hidden" name="k" value="<?= $escape($req['token']) ?>">
              <input type="hidden" name="action" value="check_domains">
              <input type="hidden" name="shop_name" value="<?= $escape((string) $candidate) ?>">
              <input type="hidden" name="about" value="<?= $escape($about) ?>" data-step2-about-sync>
              <input type="hidden" name="location" value="<?= $escape($location) ?>" data-step2-location-sync>
              <input type="hidden" name="activity_json" value="<?= $escape((string) $activityJson) ?>" data-activity-json>
              <button class="btn ghost" type="submit">Tester « <?= $escape((string) $candidate) ?> »</button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (($domainChoice['method'] ?? '') === 'custom'): ?>
        <div class="notice ok" role="status">Préselectionné : <strong><?= $escape((string) ($domainChoice['domain'] ?? '')) ?></strong>. L’achat se fera après la création, avec devis OVH et paiement dédié ; le domaine n’est pas encore réservé.</div>
      <?php elseif (($domainChoice['method'] ?? '') === 'platform'): ?>
        <div class="notice" role="status">Vous avez choisi l’adresse incluse. Un changement vers un domaine personnalisé plus tard peut nécessiter des redirections et un travail SEO pour préserver le référencement.</div>
      <?php endif; ?>
      <p class="oauth-note">Sans domaine personnalisé, votre boutique sera disponible sous l’adresse du portail, par exemple brocs.arrimage.com/nom-de-la-boutique. Une migration ultérieure peut demander des redirections et affecter temporairement le référencement.</p>
    </section>
    <form class="form-card" method="post" action="/inscription/configurateur.php" novalidate>
      <input type="hidden" name="csrf" value="<?= $escape($_SESSION['saas_csrf']) ?>">
      <input type="hidden" name="r" value="<?= $escape($req['id']) ?>">
      <input type="hidden" name="k" value="<?= $escape($req['token']) ?>">
      <input type="hidden" name="action" value="continue_payment">
      <input type="hidden" name="activity_json" value="<?= $escape((string) $activityJson) ?>" data-activity-json>
      <label class="field full<?= isset($errors['shop_name']) ? ' has-error' : '' ?>"><span>Nom de la boutique</span>
        <input type="text" id="step2-shop-name-checkout" name="shop_name" value="<?= $escape($shopName) ?>" required maxlength="60" autocomplete="organization">
        <?php if (isset($errors['shop_name'])): ?><span class="err" role="alert"><?= $escape($errors['shop_name']) ?></span><?php endif; ?>
      </label>
      <input type="hidden" id="step2-about-checkout" name="about" value="<?= $escape($about) ?>">
      <input type="hidden" id="step2-location-checkout" name="location" value="<?= $escape($location) ?>">
      <label class="field<?= isset($errors['email']) ? ' has-error' : '' ?>"><span>Adresse e-mail</span>
        <input type="email" name="email" value="<?= $escape($email) ?>" required maxlength="254" autocomplete="email">
        <?php if (isset($errors['email'])): ?><span class="err" role="alert"><?= $escape($errors['email']) ?></span><?php endif; ?>
        <small>Elle servira d’identifiant pour l’administration de votre boutique.</small>
      </label>
      <label class="field full<?= isset($errors['terms']) ? ' has-error' : '' ?>" style="grid-template-columns:auto 1fr;align-items:start;gap:10px">
        <input type="checkbox" name="terms" value="1" style="width:auto;margin-top:4px"<?= !empty($_POST['terms']) ? ' checked' : '' ?>>
        <span style="font-weight:400">J'accepte d'être contacté(e) pour la création de ma boutique et que mes informations servent à ce seul usage.
          <?php if (isset($errors['terms'])): ?><span class="err" role="alert"><?= $escape($errors['terms']) ?></span><?php endif; ?>
        </span>
      </label>
      <label class="field full step2-followup-consent" style="grid-template-columns:auto 1fr;align-items:start;gap:10px">
        <input type="checkbox" name="domain_followup_consent" value="1" style="width:auto;margin-top:4px"<?= !empty($_POST['domain_followup_consent']) ? ' checked' : '' ?>>
        <span style="font-weight:400">J’accepte de recevoir une relance par e-mail au sujet du nom de domaine si je le choisis plus tard. Cette autorisation est facultative.</span>
      </label>
      <p class="step2-payment-actions">
        <button class="btn" type="submit">Continuer vers le design</button>
        <button class="btn ghost" type="submit" name="defer_domain" value="1">Je choisirai mon domaine plus tard</button>
      </p>
      <?php if ($social !== ''): ?>
        <div class="oauth-sep"><span>ou</span></div>
        <?= $social ?>
        <p class="oauth-note">Votre adresse Google servira à vous connecter ensuite à l’administration de votre boutique.</p>
      <?php endif; ?>
    </form>
      </div>
      <aside class="step2-summary form-card" aria-labelledby="step2-summary-title">
        <h2 id="step2-summary-title">Récapitulatif</h2>
        <ol class="step2-summary-steps">
          <li><span>1</span><div><strong>Formule</strong><small><?= $escape((string) $offer['name']) ?><?= ($offer['mode'] ?? 'sales') === 'showcase' ? ' · vitrine' : ' · vente en ligne' ?></small></div></li>
          <li><span>2</span><div><strong>Nom et domaine</strong><small id="step2-summary-shop-name"><?= $escape($shopName !== '' ? $shopName : 'À préciser') ?></small><small id="step2-summary-activity"><?= $escape(branding_activity_context($activity) ?: 'Activité à préciser') ?></small><small id="step2-summary-location"><?= $escape($location !== '' ? $location : 'Zone non précisée') ?></small><small id="step2-summary-domain"><?= !empty($domainChoice['domain']) ? 'Domaine : ' . $escape((string) $domainChoice['domain']) : 'Sous-domaine : <span id="step2-summary-subdomain">' . $escape($defaultSlug . '.' . $baseDomain) . '</span>' ?></small></div></li>
          <li><span>3</span><div><strong>Design</strong><small><?= $escape(SHOP_TEMPLATES[$req['template'] ?? SHOP_TEMPLATE_DEFAULT]['label']) ?></small></div></li>
          <?php if ($isPayable): ?>
            <li><span>4</span><div><strong>Paiement</strong><small>Stripe sécurisé</small></div></li>
            <li><span>5</span><div><strong>Création de la boutique</strong><small>Après confirmation du paiement</small></div></li>
          <?php else: ?>
            <li><span>4</span><div><strong>Création de la boutique</strong><small>Accès et mot de passe</small></div></li>
          <?php endif; ?>
        </ol>
        <dl class="step2-total">
          <div><dt>Formule</dt><dd><?= (int) ($offer['cents'] ?? 0) === 0 ? 'Gratuit' : $escape(saas_price_text((int) $offer['cents'], (string) $offer['billing'])) ?></dd></div>
          <?php if ($yearly && (int) ($offer['cents'] ?? 0) > 0): ?><div><dt>Équivalent mensuel</dt><dd><?= $escape(saas_money((int) round((int) $offer['cents'] / 12))) ?> / mois</dd></div><?php endif; ?>
          <?php if ($yearSavings > 0): ?><div><dt>Économie annuelle</dt><dd><?= $escape(saas_money($yearSavings)) ?> / an</dd></div><?php endif; ?>
          <div class="step2-grand-total"><dt>Total à payer maintenant</dt><dd><?= (int) ($offer['cents'] ?? 0) === 0 ? 'Gratuit (0 €)' : $escape(saas_price_text((int) $offer['cents'], (string) $offer['billing'])) ?></dd></div>
        </dl>
        <p class="meta">Le nom de domaine est facultatif et son achat sera facturé séparément après la création. Son prix annuel estimé est indiqué à côté des disponibilités ; le tarif sera revérifié avant commande.</p>
      </aside>
    </div>
  <?php endif; ?>
</div>
<script>
  (function () {
    var pairs = [
      ['step2-shop-name-search', ['step2-shop-name-checkout']],
      ['step2-about-search', ['step2-about-checkout']],
      ['step2-location-search', ['step2-location-checkout']]
    ];
    var baseDomain = <?= json_encode($baseDomain) ?>;
    function slugify(text) {
      var s = (text || '').toString().toLowerCase().trim()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
      return s || 'ma-boutique';
    }
    function syncSubdomain(name) {
      var slug = slugify(name);
      var slugSpan = document.getElementById('step2-default-subdomain-slug');
      if (slugSpan) slugSpan.textContent = slug;
      var summarySubdomain = document.getElementById('step2-summary-subdomain');
      if (summarySubdomain) summarySubdomain.textContent = slug + '.' + baseDomain;
    }

    pairs.forEach(function (pair) {
      var source = document.getElementById(pair[0]);
      if (!source) return;
      source.addEventListener('input', function () {
        pair[1].forEach(function (id) {
          var target = document.getElementById(id);
          if (target) target.value = source.value;
        });
        if (pair[0] === 'step2-shop-name-search') {
          var summaryName = document.getElementById('step2-summary-shop-name');
          if (summaryName) summaryName.textContent = source.value || 'À préciser';
          syncSubdomain(source.value);
        }
        if (pair[0] === 'step2-location-search') {
          var summaryLocation = document.getElementById('step2-summary-location');
          if (summaryLocation) summaryLocation.textContent = source.value || 'Zone non précisée';
        }
        var attribute = pair[0] === 'step2-shop-name-search' ? 'data-step2-name-sync'
          : (pair[0] === 'step2-about-search' ? 'data-step2-about-sync'
          : (pair[0] === 'step2-location-search' ? 'data-step2-location-sync' : ''));
        if (attribute) document.querySelectorAll('[' + attribute + ']').forEach(function (target) { target.value = source.value; });
        var fieldName = pair[0] === 'step2-shop-name-search' ? 'shop_name'
          : (pair[0] === 'step2-about-search' ? 'about' : (pair[0] === 'step2-location-search' ? 'location' : ''));
        if (fieldName) document.querySelectorAll('input[name="' + fieldName + '"]').forEach(function (target) { target.value = source.value; });
      });
    });
    var searchName = document.getElementById('step2-shop-name-search');
    var checkoutName = document.getElementById('step2-shop-name-checkout');
    if (searchName && checkoutName) checkoutName.addEventListener('input', function () {
      searchName.value = checkoutName.value;
      var summaryName = document.getElementById('step2-summary-shop-name');
      if (summaryName) summaryName.textContent = checkoutName.value || 'À préciser';
      syncSubdomain(checkoutName.value);
      document.querySelectorAll('input[name="shop_name"]').forEach(function (input) { input.value = checkoutName.value; });
    });

    var primary = document.getElementById('step2-activity-primary');
    var secondary = Array.prototype.slice.call(document.querySelectorAll('.step2-activity-secondary'));
    var other = document.getElementById('step2-activity-other');
    function syncActivity() {
      if (!primary || !other) return;
      var selected = secondary.filter(function (input) { return input.checked; }).map(function (input) { return input.value; });
      var currentPrimary = primary.value;
      selected = selected.filter(function (key) { return key !== currentPrimary; });
      other.required = currentPrimary === 'other' || selected.indexOf('other') !== -1;
      var value = JSON.stringify({ primary: currentPrimary, secondary: selected, other: other.value });
      document.querySelectorAll('[data-activity-json]').forEach(function (input) { input.value = value; });
      document.querySelectorAll('input[name="activity_json"]').forEach(function (input) { input.value = value; });
      var activitySummary = document.getElementById('step2-summary-activity');
      if (activitySummary) {
        var labels = [];
        if (currentPrimary) labels.push(currentPrimary === 'other' ? other.value : primary.options[primary.selectedIndex].text);
        selected.forEach(function (key) {
          var control = secondary.find(function (item) { return item.value === key; });
          var label = control && control.parentNode.querySelector('span');
          if (label) labels.push(key === 'other' ? other.value : label.textContent);
        });
        activitySummary.textContent = labels.filter(Boolean).join(', ') || 'Activité à préciser';
      }
    }
    if (primary) primary.addEventListener('change', function () {
      secondary.forEach(function (input) { if (input.value === primary.value) input.checked = false; });
      syncActivity();
    });
    secondary.forEach(function (input) {
      input.addEventListener('change', function () {
        var checked = secondary.filter(function (item) { return item.checked; });
        if (checked.length > 6) { input.checked = false; }
        syncActivity();
      });
    });
    if (other) other.addEventListener('input', syncActivity);
    syncActivity();

    var locationInput = document.getElementById('step2-location-search');
    var suggestionBox = document.getElementById('step2-location-suggestions');
    var locateButton = document.getElementById('step2-locate');
    var locationStatus = document.getElementById('step2-location-status');
    if (!locationInput || !suggestionBox || !locateButton || !locationStatus) return;
    var locationTimer = null, locationSequence = 0, locationOptions = [], selectedOption = -1, lastOsmRequest = 0;
    function setLocationStatus(text, error) {
      locationStatus.textContent = text;
      locationStatus.dataset.kind = error ? 'error' : '';
    }
    function syncLocationValue(value) {
      document.querySelectorAll('input[name="location"],[data-step2-location-sync]').forEach(function (input) {
        if (input !== locationInput) input.value = value;
      });
    }
    function closeLocationList() {
      suggestionBox.hidden = true;
      suggestionBox.textContent = '';
      locationOptions = [];
      selectedOption = -1;
      locationInput.setAttribute('aria-expanded', 'false');
      locationInput.removeAttribute('aria-activedescendant');
    }
    function cityFromBan(feature) {
      var properties = feature.properties || {};
      var city = properties.city || properties.municipality || properties.locality || '';
      var context = properties.context ? properties.context.split(',') : [];
      var country = properties.country || (context.length ? context[context.length - 1].trim() : '');
      return city ? [city, country].filter(Boolean).join(', ') : '';
    }
    function renderLocations(options) {
      suggestionBox.textContent = '';
      locationOptions = [];
      options.forEach(function (option, index) {
        var button = document.createElement('button');
        button.type = 'button';
        button.id = 'step2-location-option-' + index;
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', 'false');
        button.style.cssText = 'border:0;border-bottom:1px solid var(--line);background:transparent;color:inherit;text-align:left;padding:10px;cursor:pointer;font:inherit';
        button.textContent = option.label;
        button.addEventListener('mousedown', function (event) { event.preventDefault(); });
        button.addEventListener('click', function () {
          locationInput.value = option.value;
          syncLocationValue(option.value);
          closeLocationList();
          setLocationStatus('Lieu sélectionné : ' + option.value + '.', false);
        });
        suggestionBox.appendChild(button);
        locationOptions.push(button);
      });
      suggestionBox.hidden = locationOptions.length === 0;
      locationInput.setAttribute('aria-expanded', locationOptions.length ? 'true' : 'false');
      selectedOption = -1;
      if (locationOptions.length) setLocationStatus(locationOptions.length + ' lieux proposés. Utilisez les flèches pour choisir.', false);
    }
    function normalizeBan(features) {
      return features.map(function (feature) {
        var properties = feature.properties || {};
        var city = properties.city || properties.name || '';
        var country = properties.country || 'France';
        var label = [city, properties.postcode, country].filter(Boolean).join(', ');
        return { label: label, value: [city, country].filter(Boolean).join(', ') };
      }).filter(function (option) { return option.value !== ''; });
    }
    function searchOsm(query, sequence) {
      var wait = Math.max(0, 1100 - (Date.now() - lastOsmRequest));
      window.setTimeout(function () {
        if (sequence !== locationSequence) return;
        lastOsmRequest = Date.now();
        fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=5&accept-language=fr&q=' + encodeURIComponent(query))
          .then(function (response) { if (!response.ok) throw new Error('Lieu indisponible'); return response.json(); })
          .then(function (results) {
            if (sequence !== locationSequence) return;
            renderLocations(results.map(function (result) {
              var address = result.address || {};
              var city = address.city || address.town || address.village || address.municipality || result.name || '';
              var country = address.country || '';
              return { label: result.display_name, value: [city, country].filter(Boolean).join(', ') };
            }).filter(function (option) { return option.value !== ''; }));
            if (!locationOptions.length) setLocationStatus('Aucun lieu trouvé. Vous pouvez saisir une ville librement.', false);
          })
          .catch(function () {
            if (sequence === locationSequence) setLocationStatus('Recherche de lieux indisponible. Vous pouvez saisir une ville librement.', true);
          });
      }, wait);
    }
    locationInput.addEventListener('input', function () {
      window.clearTimeout(locationTimer);
      var query = locationInput.value.trim();
      var sequence = ++locationSequence;
      if (locateButton.disabled) locateButton.disabled = false;
      if (query.length < 3) { closeLocationList(); return; }
      locationTimer = window.setTimeout(function () {
        fetch('https://api-adresse.data.gouv.fr/search/?autocomplete=1&limit=6&q=' + encodeURIComponent(query))
          .then(function (response) { if (!response.ok) throw new Error('BAN unavailable'); return response.json(); })
          .then(function (data) {
            if (sequence !== locationSequence) return;
            var found = normalizeBan((data && data.features) || []);
            if (found.length) renderLocations(found);
            else searchOsm(query, sequence);
          })
          .catch(function () { if (sequence === locationSequence) searchOsm(query, sequence); });
      }, 350);
    });
    locationInput.addEventListener('keydown', function (event) {
      if (!locationOptions.length) return;
      if (event.key === 'ArrowDown') selectedOption = (selectedOption + 1) % locationOptions.length;
      else if (event.key === 'ArrowUp') selectedOption = (selectedOption - 1 + locationOptions.length) % locationOptions.length;
      else if (event.key === 'Escape') { closeLocationList(); return; }
      else if (event.key === 'Enter' && selectedOption >= 0) { event.preventDefault(); locationOptions[selectedOption].click(); return; }
      else return;
      event.preventDefault();
      locationOptions.forEach(function (option, index) {
        option.setAttribute('aria-selected', index === selectedOption ? 'true' : 'false');
        if (index === selectedOption) {
          option.scrollIntoView({ block: 'nearest' });
          locationInput.setAttribute('aria-activedescendant', option.id);
        }
      });
    });
    document.addEventListener('click', function (event) {
      if (event.target !== locationInput && !suggestionBox.contains(event.target)) closeLocationList();
    });
    function reverseLocation(latitude, longitude, sequence) {
      fetch('https://api-adresse.data.gouv.fr/reverse/?lat=' + latitude + '&lon=' + longitude)
        .then(function (response) { if (!response.ok) throw new Error('BAN unavailable'); return response.json(); })
        .then(function (data) {
          var feature = data && data.features && data.features[0];
          var value = feature ? cityFromBan(feature) : '';
          if (value) return value;
          return fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=10&accept-language=fr&lat=' + latitude + '&lon=' + longitude)
            .then(function (response) { if (!response.ok) throw new Error('OSM unavailable'); return response.json(); })
            .then(function (result) {
              var address = result.address || {};
              return [address.city || address.town || address.village || address.municipality, address.country].filter(Boolean).join(', ');
            });
        })
        .then(function (value) {
          if (sequence !== locationSequence) return;
          if (!value) throw new Error('No locality found');
          locationInput.value = value;
          syncLocationValue(value);
          setLocationStatus('Lieu découvert : ' + value + '. Vous pouvez le modifier.', false);
        })
        .catch(function () {
          if (sequence === locationSequence) setLocationStatus('Lieu introuvable. Vous pouvez le saisir manuellement.', true);
        })
        .finally(function () { locateButton.disabled = false; });
    }
    locateButton.addEventListener('click', function () {
      if (!navigator.geolocation) { setLocationStatus('La découverte automatique n’est pas disponible sur cet appareil.', true); return; }
      locateButton.disabled = true;
      setLocationStatus('Demande de localisation au navigateur…', false);
      var sequence = ++locationSequence;
      navigator.geolocation.getCurrentPosition(function (position) {
        if (sequence !== locationSequence) { locateButton.disabled = false; return; }
        setLocationStatus('Recherche de votre ville…', false);
        reverseLocation(position.coords.latitude.toFixed(5), position.coords.longitude.toFixed(5), sequence);
      }, function (error) {
        locateButton.disabled = false;
        if (sequence !== locationSequence) return;
        setLocationStatus(error.code === 1 ? 'Localisation refusée. Vous pouvez saisir votre ville manuellement.' : 'Position introuvable. Vous pouvez saisir votre ville manuellement.', true);
      }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
    });
  })();
</script>
<?php saas_page_end(); ?>
