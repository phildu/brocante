<?php
// Création de boutique — ÉTAPE 1 : choix de la formule. Ensuite :
//   formule payante (et Stripe configuré)  → étape de coordonnées (e-mail ou Google), puis Stripe Checkout ;
//   formule gratuite, ou paiement en ligne indisponible → étape 3, qui recueille l'e-mail et les accès de la boutique.
// ÉTAPE 3 (creer.php) : nom, adresse et compte de la boutique — elle GÉNÈRE la boutique avec un design par défaut.
// Protections : jeton CSRF, champ piège, 5 demandes par heure et par adresse IP. Rien n'est créé avant l'étape 3.
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/addons.php';
require_once __DIR__ . '/../includes/plugins.php';
require_once __DIR__ . '/../includes/marges.php';
require_once __DIR__ . '/../includes/branding-services.php';

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
$galleryData = $gallery !== '' ? gallery_load($gallery) : null;
// Première question : où voulez-vous vendre ? « solo » (chez moi, boutique seule) ou un type de galerie.
// Une galerie précise impose son type ; sans galerie, le type choisi pilote le vocabulaire (boutique/stand) et la liste affichée.
$galleryTypes = function_exists('gt_types') ? gt_types() : [];
$placeType = $galleryData ? (string) ($galleryData['type'] ?? GT_DEFAULT) : strtolower(trim((string) ($_POST['lieu'] ?? ($editRequest['place_type'] ?? $_GET['lieu'] ?? ''))));
if ($placeType !== '' && (!isset($galleryTypes[$placeType]) || empty($galleryTypes[$placeType]['active']))) $placeType = '';
$galleriesByType = [];
foreach ($galleries as $gSlug => $gName) {
    $gData = $gSlug === $gallery && $galleryData ? $galleryData : gallery_load($gSlug);
    if ($gData) $galleriesByType[(string) ($gData['type'] ?? GT_DEFAULT)][] = $gData;
}
$savedOffer = $editRequest ? saas_req_offer($editRequest) : [];
$siteMode = (string) ($_POST['site_mode'] ?? ($savedOffer['mode'] ?? $editRequest['site_mode'] ?? $_GET['mode'] ?? 'sales'));
if (!in_array($siteMode, ['sales', 'showcase'], true)) $siteMode = 'sales';
// Point de vente : « store » (physique et en ligne, par défaut) ou « online » (en ligne uniquement).
// Il restreint la liste des plugins proposés à l'inscription (les modules de boutique physique sont masqués).
$shopPresence = (string) ($_POST['shop_presence'] ?? ($editRequest['shop_presence'] ?? $_GET['presence'] ?? 'store'));
if (!in_array($shopPresence, ['store', 'online'], true)) $shopPresence = 'store';
// Intelligence artificielle : avec (textes, photos et pré-remplissage assistés) ou sans (aucune génération, aucun appel IA).
$aiMode = (string) ($_POST['ai_mode'] ?? ($editRequest['ai_mode'] ?? $_GET['ai'] ?? 'with'));
if (!in_array($aiMode, ['with', 'without'], true)) $aiMode = 'with';
$grid = saas_grid($gallery, $siteMode);
// Marge moyenne du type de la galerie choisie : sert au CA de rentabilité affiché sur chaque formule.
$marginType = $galleryData ? (string) ($galleryData['type'] ?? GT_DEFAULT) : $placeType;
$marginPct = $marginType !== '' ? marge_pct($marginType) : 0;
// Vocabulaire du lieu de vente : un vide-grenier ou un marché accueille des stands, pas des boutiques.
$shopWord = in_array($marginType, ['vide-grenier', 'marche-de-noel', 'marche-producteurs'], true) ? 'Stand' : 'Boutique';
// Pionniers : les prix affichés sont ceux du prochain rang (ou du verrou déjà accordé à ce brouillon).
$ebCampaign = function_exists('eb_campaign_for_signup') ? eb_campaign_for_signup($gallery) : null;
$ebTier = $ebCampaign && $ebCampaign['progress']['open'] ? eb_tier_at($ebCampaign, $ebCampaign['progress']['next_rank']) : null;
if ($editRequest && !empty($editRequest['earlybird']['discount_pct'])) {
    $ebTier = ['label' => (string) ($editRequest['earlybird']['tier_label'] ?? 'Pionnier'), 'discount_pct' => (int) $editRequest['earlybird']['discount_pct'],
        'perk' => (string) ($editRequest['earlybird']['perk'] ?? '')];
}
if ($ebTier) $grid = eb_discounted_grid($grid, $ebTier);
$offers = saas_grid_offers($grid);
$offersByKey = array_column($offers, null, 'key');
$stripeOn = saas_stripe_configured();
$pluginCatalog = plugin_catalog();
$pluginRules = plugin_rules_load();
$addonSettings = addon_settings_load();
$serviceQuoteCatalog = saas_service_quotes_active();
// Le type de site et la galerie rechargent la page (GET) : les cases cochées y sont reprises pour ne rien perdre.
$pluginPrefill = is_array($_POST['plugins'] ?? null) ? $_POST['plugins']
    : (isset($_GET['plugins']) ? explode(',', (string) $_GET['plugins']) : (array) ($editRequest['plugins'] ?? []));
$selectedPlugins = [];
foreach ($pluginPrefill as $pluginId) {
    if (!is_string($pluginId) || !isset($pluginCatalog[$pluginId], $pluginRules[$pluginId])) continue;
    if (plugin_rule_matches($pluginId, $pluginRules[$pluginId], $siteMode, $shopPresence)) $selectedPlugins[] = $pluginId;
}
$servicePrefill = is_array($_POST['service_quotes'] ?? null) ? $_POST['service_quotes']
    : (isset($_GET['prestations']) ? explode(',', (string) $_GET['prestations']) : (array) ($editRequest['service_quotes'] ?? []));
$selectedServices = [];
foreach ($servicePrefill as $serviceKey) {
    if (is_string($serviceKey) && isset($serviceQuoteCatalog[$serviceKey])) $selectedServices[] = $serviceKey;
}
$selectedServices = array_values(array_unique($selectedServices));
// Catalogue des plugins prêt à afficher (prix réglés pour la galerie, profils du catalogue appliqués) et réutilisé par le récapitulatif.
$pluginRows = [];
foreach ($pluginCatalog as $pluginId => $plugin) {
    $rule = $pluginRules[$pluginId] ?? plugin_rule_defaults($pluginId);
    if (empty($rule['listed']) || !plugin_rule_matches($pluginId, $rule, $siteMode, $shopPresence)) continue;
    $free = plugin_rule_free($rule);
    $addon = addon_info_resolve($pluginId, $addonSettings, '', $gallery);
    $modes = $addon['payment_modes'] ?? [];
    $pluginPrices = [];
    foreach (['monthly' => ['monthly_cents', 'mois'], 'yearly' => ['yearly_cents', 'an'], 'once' => ['once_cents', 'une fois']] as $mode => [$key, $period]) {
        if (!in_array($mode, $modes, true)) continue;
        $cents = $addon[$key] ?? null;
        if ($cents !== null) $pluginPrices[] = $cents > 0 ? saas_money((int) $cents) . ' / ' . $period : 'Gratuit';
    }
    if (in_array('manual', $modes, true)) $pluginPrices[] = 'Sur devis';
    if (in_array('free', $modes, true)) $pluginPrices[] = 'Gratuit';
    // Coût récurrent mensuel pour le total du récapitulatif : mensuel, sinon annuel ramené au mois ; un achat unique reste à part.
    $recurMonth = 0;
    $recurOnce = 0;
    if (!$free && $addon && $addon['access'] !== 'unavailable') {
        if ($addon['access'] === 'included' || in_array('free', $modes, true)) {
            $recurMonth = 0;
        } elseif (in_array('monthly', $modes, true) && ($addon['monthly_cents'] ?? null) !== null) {
            $recurMonth = (int) $addon['monthly_cents'];
        } elseif (in_array('yearly', $modes, true) && ($addon['yearly_cents'] ?? null) !== null) {
            $recurMonth = (int) ceil($addon['yearly_cents'] / 12);
        }
        if (in_array('once', $modes, true) && ($addon['once_cents'] ?? null) !== null) $recurOnce = (int) $addon['once_cents'];
    }
    $pluginRows[$pluginId] = [
        'plugin' => $plugin,
        'addon' => $addon,
        'rule' => $rule,
        'free' => $free,
        'stage' => $rule['stage'],
        'activity_only' => (array) $rule['activities'],
        'activity_labels' => array_values(array_map(
            static fn ($a): string => branding_activity_catalog()[$a] ?? (string) $a,
            (array) $rule['activities']
        )),
        'price' => $free ? ('Gratuit' . ($rule['stage'] === 'test' ? ' (en test)' : ''))
            : (!$addon ? 'Tarif à confirmer'
                : ($addon['access'] === 'unavailable' ? 'Non proposé actuellement'
                    : ($addon['access'] === 'included' ? 'Inclus dans votre offre'
                        : ($pluginPrices ? implode(' · ', array_unique($pluginPrices)) : 'Tarif à confirmer')))),
        'selectable' => !$addon || $addon['access'] !== 'unavailable',
        'trial' => (int) (!empty($addon['trial_days']) ? $addon['trial_days'] : 0),
        'recur_month' => $recurMonth,
        'recur_once' => $recurOnce,
        'quote_only' => !$free && $recurMonth === 0 && $recurOnce === 0
            && (!$addon || ($addon['access'] !== 'unavailable' && $addon['access'] !== 'included' && !in_array('free', $modes, true))),
    ];
}
// Un plugin coché puis masqué (type de site ou présence modifiés) ne reste pas sélectionné.
$selectedPlugins = array_values(array_filter($selectedPlugins, static fn (string $id): bool => isset($pluginRows[$id])));
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
            $ebLock = ['offer' => $offer, 'earlybird' => null];
            if ($offer && function_exists('eb_lock_offer')) $ebLock = eb_lock_offer($gallery, $offer, $editRequest['earlybird'] ?? null);
            $offer = $ebLock['offer'];
            $pluginsInput = $_POST['plugins'] ?? [];
            $editPlugins = [];
            if (!is_array($pluginsInput)) {
                $errors['plugins'] = 'La sélection des plugins est invalide.';
            } else {
                foreach ($pluginsInput as $pluginId) {
                    if (!is_string($pluginId) || !isset($pluginCatalog[$pluginId])) {
                        $errors['plugins'] = 'Un plugin sélectionné n’est pas reconnu.';
                        break;
                    }
                    $editPlugins[] = $pluginId;
                }
                $editPlugins = array_values(array_unique($editPlugins));
                if ($shopPresence === 'online') {
                    $editPlugins = array_values(array_filter($editPlugins, static fn (string $id): bool => !plugin_requires_store($id)));
                }
            }
            $servicesInput = $_POST['service_quotes'] ?? [];
            $editServices = [];
            if (!is_array($servicesInput)) {
                $errors['service_quotes'] = 'La sélection des prestations est invalide.';
            } else {
                foreach ($servicesInput as $serviceKey) {
                    if (!is_string($serviceKey) || !isset($serviceQuoteCatalog[$serviceKey])) {
                        $errors['service_quotes'] = 'Une prestation sélectionnée n’est pas reconnue.';
                        break;
                    }
                    $editServices[] = $serviceKey;
                }
                $editServices = array_values(array_unique($editServices));
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
                $editRequest['shop_presence'] = $shopPresence;
                $editRequest['ai_mode'] = $aiMode;
                $editRequest['place_type'] = $placeType;
                $editRequest['gallery'] = $gallery;
                $editRequest['offer'] = $offer;
                if (!empty($ebLock['earlybird'])) $editRequest['earlybird'] = $ebLock['earlybird'];
                else unset($editRequest['earlybird']);
                $editRequest['plugins'] = $editPlugins;
                $editRequest['service_quotes'] = $editServices;
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

saas_page_start('Je veux vendre en ligne — ' . $cfg['name'], 'Choisissez où vous vendez et votre formule, réglez en ligne, puis créez votre boutique ou votre stand : nom et compte. Le design se choisit après la création.', 'inscription');
?>
<div class="wrap">
  <div class="page-title"><p class="eyebrow">Création de boutique ou de stand</p><h1>Je veux vendre en ligne</h1>
    <ol class="stepper" aria-label="Étapes"><li class="is-current" aria-current="step"><b>1</b> Où je vends</li><li><b>2</b> Ma boutique</li><li><b>3</b> Design</li><li><b>4</b> Paiement</li><li><b>5</b> En ligne</li></ol>
    <p class="lede">Commencez par le lieu : chez vous, en galerie, sur un marché, dans un vide-grenier ou dans un quartier. Choisissez ensuite votre formule. Vous configurerez enfin le nom de votre <?= $shopWord === 'Stand' ? 'stand' : 'boutique' ?> et son design avant <?= $stripeOn ? 'le paiement sécurisé (pour une offre payante) ou la création de votre espace' : 'la création de votre espace' ?>.</p></div>

  <?php if (isset($_GET['recu'])): ?>
    <div class="form-card"><div class="notice ok" role="status"><strong>Demande reçue, merci !</strong></div><p><a class="btn" href="/">Retour à l'accueil</a></p></div>
  <?php else: ?>
  <div class="step2-layout">
    <div class="step2-main">
    <?php if ($ebCampaign && $ebCampaign['enabled']): ?><?= eb_widget_html($gallery, ['show_cta' => false]) ?><?php endif; ?>
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
        <?php
        // Libellés de la première question : le lieu de vente, avant la formule.
        $placeLabels = [
            'marchande' => ['Dans une galerie marchande', 'Vos pièces réunies avec celles d’autres commerces, sur une page commune.'],
            'puces' => ['Sur un marché aux puces', 'Un emplacement numéroté, avec les autres marchands du marché.'],
            'vide-grenier' => ['Dans un vide-grenier', 'Un stand le temps d’une édition, avec le plan et les exposants.'],
            'quartier' => ['Dans un quartier marchand', 'Les commerces d’une rue, d’un village ou d’un quartier, ensemble.'],
            'marche-de-noel' => ['Dans un marché de Noël', 'Un chalet le temps des fêtes, avec le plan et les autres exposants.'],
            'marche-producteurs' => ['Sur un marché de producteurs et créateurs', 'Un stand avec les producteurs locaux, artisans et créateurs du marché.'],
        ];
        ?>
        <div class="field full">
          <fieldset class="mode-field">
            <legend>Où voulez-vous vendre ?</legend>
            <div class="mode-picks place-picks">
              <label class="mode-choice">
                <input type="radio" name="lieu" value="solo" onchange="signupReload('lieu', this.value)"<?= $placeType === '' ? ' checked' : '' ?>>
                <span><strong>Chez moi, en ligne</strong><small>Une boutique à votre nom, sans galerie : vous vendez sous votre propre adresse.</small></span>
              </label>
              <?php foreach ($galleryTypes as $typeKey => $typeDef): if (empty($typeDef['active'])) continue;
                  [$placeTitle, $placeDesc] = $placeLabels[$typeKey] ?? [(string) $typeDef['name'], (string) ($typeDef['tagline'] ?? '')]; ?>
                <label class="mode-choice">
                  <input type="radio" name="lieu" value="<?= saas_e($typeKey) ?>" onchange="signupReload('lieu', this.value)"<?= $placeType === $typeKey ? ' checked' : '' ?>>
                  <span><strong><?= saas_e($placeTitle) ?></strong><small><?= saas_e($placeDesc) ?></small></span>
                </label>
              <?php endforeach; ?>
            </div>
            <small>Changez de lieu à tout moment : la liste des galeries et le vocabulaire (boutique ou stand) suivent ce choix.</small>
          </fieldset>
        </div>

        <?php if ($placeType !== ''): ?>
          <?php
          $placeName = $placeLabels[$placeType][0] ?? ($galleryTypes[$placeType]['name'] ?? $placeType);
          $typeGalleries = $galleriesByType[$placeType] ?? [];
          ?>
          <?php if ($typeGalleries): ?>
            <div class="field full place-galleries">
              <p class="place-galleries-title"><strong><?= saas_e($placeName) ?></strong> — choisissez la galerie qui vous accueille. Vous pourrez la visiter avant de créer votre <?= $shopWord === 'Stand' ? 'stand' : 'boutique' ?>.</p>
              <div class="place-cards">
                <?php foreach ($typeGalleries as $gData):
                    $memberNames = [];
                    foreach (array_slice((array) $gData['members'], 0, 3) as $member) {
                        $label = (string) ($member['name'] ?? '');
                        if ($label === '' && !empty($member['slug']) && function_exists('tenant_load')) {
                            $label = (string) (tenant_load((string) $member['slug'])['name'] ?? $member['slug']);
                        }
                        if ($label === '') $label = (string) ($member['slug'] ?? '');
                        $memberNames[] = $label;
                    }
                    $memberNames = array_values(array_filter($memberNames));
                    $desc = trim((string) $gData['tagline']) !== '' ? (string) $gData['tagline'] : (string) $gData['description'];
                ?>
                  <article class="place-card<?= $gData['slug'] === $gallery ? ' is-on' : '' ?>" data-type="<?= saas_e($placeType) ?>">
                    <a class="place-shot" style="--shot:<?= saas_e($gData['accent']) ?>" href="/galerie/<?= saas_e($gData['slug']) ?>/" target="_blank" rel="noopener" aria-label="Visiter <?= saas_e($gData['name']) ?>">
                      <span class="place-shot-band"></span>
                      <strong><?= saas_e($gData['name']) ?></strong>
                      <i></i><i class="short"></i>
                      <span class="place-shot-chips">
                        <?php $chipFallback = [$shopWord === 'Stand' ? 'Votre stand' : 'Votre boutique', 'Vos pièces', 'Votre place']; ?>
                        <?php for ($chip = 0; $chip < 3; $chip++): ?><b><?= saas_e($memberNames[$chip] ?? $chipFallback[$chip]) ?></b><?php endfor; ?>
                      </span>
                    </a>
                    <div class="place-body">
                      <h3><?= saas_e($gData['name']) ?></h3>
                      <?php if ($desc !== ''): ?><p><?= saas_e(mb_strimwidth($desc, 0, 150, '…')) ?></p><?php endif; ?>
                      <p><a href="/galerie/<?= saas_e($gData['slug']) ?>/" target="_blank" rel="noopener">Visiter la galerie ↗</a></p>
                      <div class="place-actions">
                        <?php if ($gData['slug'] === $gallery): ?>
                          <span class="place-selected">✓ Galerie choisie</span>
                          <button type="button" class="btn small ghost" onclick="signupReload('galerie', '')">Retirer</button>
                        <?php else: ?>
                          <button type="button" class="btn small" onclick="signupReload('galerie', <?= saas_e((string) json_encode($gData['slug'])) ?>)">Vendre dans cette galerie</button>
                        <?php endif; ?>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="field full">
              <p class="notice" role="status">Aucune galerie « <?= saas_e($placeName) ?> » n’accueille d’inscriptions pour le moment.
                Vous pouvez créer votre <?= $shopWord === 'Stand' ? 'stand' : 'boutique' ?> en ligne seule, ou
                <?php $lp = function_exists('gt_landing_path') ? gt_landing_path($placeType) : ''; ?>
                <?php if ($lp !== ''): ?><a href="<?= saas_e($lp) ?>" target="_blank" rel="noopener">découvrir ce type de lieu</a> et le proposer à ses organisateurs.<?php else: ?>revenir plus tard.<?php endif; ?>
                <button type="button" class="btn small ghost" onclick="signupReload('lieu', 'solo')">Choisir « chez moi »</button></p>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <input type="hidden" name="galerie" value="<?= saas_e($gallery) ?>">
        <div class="field full"><fieldset class="mode-field">
          <legend>Type de site</legend>
          <div class="mode-picks">
            <label class="mode-choice">
              <input type="radio" name="site_mode" value="sales" onchange="signupReload('mode', this.value)"<?= $siteMode === 'sales' ? ' checked' : '' ?>>
              <span><strong><?= $shopWord ?> avec vente en ligne</strong><small>Proposez vos produits à la vente, avec panier et paiement en ligne.</small></span>
            </label>
            <label class="mode-choice">
              <input type="radio" name="site_mode" value="showcase" onchange="signupReload('mode', this.value)"<?= $siteMode === 'showcase' ? ' checked' : '' ?>>
              <span><strong><?= $shopWord ?> sans paiement en ligne</strong><small>Présentez vos produits sans panier ni paiement en ligne.</small></span>
            </label>
          </div>
        </fieldset></div>
        <div class="field full"><fieldset class="mode-field">
          <legend>Votre point de vente</legend>
          <div class="mode-picks">
            <label class="mode-choice">
              <input type="radio" name="shop_presence" value="store" onchange="signupReload('presence', this.value)"<?= $shopPresence === 'store' ? ' checked' : '' ?>>
              <span><strong><?= $shopWord === 'Stand' ? 'Stand et vente en ligne' : 'Boutique physique et en ligne' ?></strong><small>Vous accueillez du public dans un local ou sur un stand.</small></span>
            </label>
            <label class="mode-choice">
              <input type="radio" name="shop_presence" value="online" onchange="signupReload('presence', this.value)"<?= $shopPresence === 'online' ? ' checked' : '' ?>>
              <span><strong><?= $shopWord === 'Stand' ? 'Vente en ligne uniquement' : 'Boutique en ligne uniquement' ?></strong><small>Pas de local ouvert au public : tout se passe en ligne.</small></span>
            </label>
          </div>
          <small>Ce choix adapte la liste des plugins : les modules de point de vente physique ne sont pas proposés pour une boutique en ligne uniquement.</small>
        </fieldset></div>
        <div class="field full"><fieldset class="mode-field">
          <legend>Intelligence artificielle</legend>
          <div class="mode-picks">
            <label class="mode-choice">
              <input type="radio" name="ai_mode" value="with"<?= $aiMode === 'with' ? ' checked' : '' ?>>
              <span><strong>Avec l’IA</strong><small>Idées de noms, textes et photos d’exemple, pré-remplissage de la boutique : l’IA vous fait gagner du temps, avec votre validation.</small></span>
            </label>
            <label class="mode-choice">
              <input type="radio" name="ai_mode" value="without"<?= $aiMode === 'without' ? ' checked' : '' ?>>
              <span><strong>Sans IA</strong><small>Aucune génération ni pré-remplissage automatique : vous saisissez vous-même vos textes et vos photos. Les fonctions d’IA restent coupées pour cette boutique.</small></span>
            </label>
          </div>
          <small>Ce choix se règle ensuite dans l’administration de la boutique. Il ne change pas le prix de la formule.</small>
        </fieldset></div>
        <fieldset class="field full<?= isset($errors['plan']) ? ' has-error' : '' ?>" style="border:0;padding:0;margin:0"><span style="font-size:.82rem;font-weight:600">Votre offre</span>
          <?php if ($ebTier && (int) $ebTier['discount_pct'] > 0): ?><p class="eb-apply" style="margin:0 0 8px;font-size:.86rem;font-weight:600;color:var(--accent)">Tarif pionnier appliqué : <?= saas_e((string) $ebTier['label']) ?> −<?= (int) $ebTier['discount_pct'] ?> % sur les prix ci-dessous.</p><?php endif; ?>
          <?= saas_offer_picker_html($grid, $v['plan'], $v['billing'], $marginPct) ?>
          <?= $err('plan') ?><?php if ($stripeOn): ?><small>Offre payante : paiement par carte (Stripe), abonnement résiliable. Offre gratuite : aucun paiement.</small><?php endif; ?></fieldset>

        <details class="field full signup-extra plugin-block"<?= $selectedPlugins || isset($errors['plugins']) ? ' open' : '' ?>>
          <summary class="plugin-block-summary">
            <span><strong>Plugins &amp; options</strong> <small>facultatif</small></span>
            <span class="plugin-block-count" id="plugin-count" data-empty="Aucun sélectionné"><?= count($selectedPlugins) ? count($selectedPlugins) . ' sélectionné' . (count($selectedPlugins) > 1 ? 's' : '') : 'Aucun sélectionné' ?></span>
          </summary>
          <p class="plugin-block-intro">Cochez dès maintenant ce qui vous intéresse : nous en tenons compte à la création de la boutique. Leur abonnement s’active ensuite depuis l’administration, avec l’essai éventuel — rien n’est prélevé à cette étape.</p>
          <?php if ($shopPresence === 'online'): ?><p class="plugin-block-intro">Boutique en ligne uniquement : les modules de point de vente physique (retrait sur stand, assistance vendeur, QR Code, registre de police) ne sont pas proposés ici.</p><?php endif; ?>
          <?php if ($pluginRows): ?>
            <div class="plugin-picks">
              <?php foreach ($pluginRows as $pluginId => $row):
                  $checked = in_array($pluginId, $selectedPlugins, true);
              ?>
                <label class="plugin-choice<?= $row['selectable'] ? '' : ' is-off' ?>">
                  <input type="checkbox" name="plugins[]" value="<?= saas_e($pluginId) ?>" data-plugin-name="<?= saas_e($row['plugin']['name']) ?>" data-plugin-month="<?= (int) $row['recur_month'] ?>" data-plugin-once="<?= (int) $row['recur_once'] ?>"<?= $row['quote_only'] ? ' data-plugin-quote="1"' : '' ?><?= $checked ? ' checked' : '' ?><?= $row['selectable'] ? '' : ' disabled' ?>>
                  <span class="plugin-choice-body">
                    <span class="plugin-choice-head"><strong><?= saas_e($row['plugin']['name']) ?></strong><span class="plugin-price"><?= saas_e($row['price']) ?></span></span>
                    <?php if ($row['plugin']['description'] !== ''): ?><span class="plugin-desc"><?= saas_e(mb_strimwidth((string) $row['plugin']['description'], 0, 150, '…')) ?></span><?php endif; ?>
                    <?php if ($row['trial'] > 0 && !$row['free']): ?><span class="plugin-trial">Essai <?= $row['trial'] ?> jours</span><?php endif; ?>
                    <?php if ($row['stage'] === 'test'): ?><span class="plugin-trial">En test — gratuit jusqu’à sa mise en service</span><?php endif; ?>
                    <?php if ($row['activity_only']): ?><span class="plugin-trial">Réservé aux activités : <?= saas_e(implode(', ', $row['activity_labels'])) ?> (choix à l’étape 2)</span><?php endif; ?>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p>Aucun plugin n’est actuellement disponible.</p>
          <?php endif; ?>
          <?php if (isset($errors['plugins'])): ?><span class="err" role="alert"><?= saas_e($errors['plugins']) ?></span><?php endif; ?>
        </details>

        <?php if ($serviceQuoteCatalog): ?>
        <details class="field full signup-extra service-block"<?= $selectedServices || isset($errors['service_quotes']) ? ' open' : '' ?>>
          <summary class="plugin-block-summary">
            <span><strong>Prestations sur devis</strong> <small>facultatif</small></span>
            <span class="plugin-block-count" id="service-count" data-empty="Aucune sélectionnée"><?= count($selectedServices) ? count($selectedServices) . ' sélectionnée' . (count($selectedServices) > 1 ? 's' : '') : 'Aucune sélectionnée' ?></span>
          </summary>
          <p class="plugin-block-intro">Cochez ce dont vous avez besoin : la demande de devis vous sera proposée à la fin de la création de votre boutique, avec ces prestations déjà cochées. Nous revenons vers vous avec un tarif ferme.</p>
          <div class="plugin-picks">
            <?php foreach ($serviceQuoteCatalog as $key => $service): $checked = in_array($key, $selectedServices, true); ?>
              <label class="plugin-choice service-choice">
                <input type="checkbox" name="service_quotes[]" value="<?= saas_e($key) ?>" data-service-name="<?= saas_e($service['name']) ?>"<?= $checked ? ' checked' : '' ?>>
                <span class="plugin-choice-body">
                  <span class="plugin-choice-head"><strong><?= saas_e($service['name']) ?></strong><span class="plugin-price"><?= saas_e(saas_service_quote_price($service)) ?></span></span>
                  <?php if ($service['description'] !== ''): ?><span class="plugin-desc"><?= saas_e($service['description']) ?></span><?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if (isset($errors['service_quotes'])): ?><span class="err" role="alert"><?= saas_e($errors['service_quotes']) ?></span><?php endif; ?>
          <p class="plugin-block-foot">Aucun engagement et aucun paiement à cette étape : le devis est gratuit.</p>
        </details>
        <?php endif; ?>

      </div>
      <p style="margin:22px 0 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center"><button class="btn" type="submit"><?= $editRequested ? 'Mettre à jour et continuer' : 'Je veux vendre en ligne' ?></button>
        <a class="btn ghost" href="/">← Retour à l’accueil</a></p>
      <p class="oauth-note" style="margin-top:12px">Aucun compte ni e-mail à saisir à cette étape. Pour une offre payante, Stripe vous demandera votre e-mail au paiement ; pour une offre gratuite, vous le renseignerez à l’étape de création.</p>
    </form>
    </div>

    <?php
    $stickerOffer = $offersByKey[$v['plan']] ?? null;
    $stickerGallery = $gallery !== '' ? ($galleries[$gallery] ?? $gallery)
        : ($placeType !== '' ? (($galleryTypes[$placeType]['name'] ?? $placeType) . ' — à choisir') : 'Chez moi, en ligne');
    $stickerPrice = $stickerOffer ? saas_price_text(saas_offer_cents($stickerOffer, $v['billing']), $v['billing']) : '—';
    // Total formulaire + plugins cochés, ramené au mois (l'annuel est divisé par 12) : c'est un coût d'abonnement, pas un prélèvement.
    $stickerPlugMonth = 0;
    $stickerPlugOnce = 0;
    $stickerPlugQuote = 0;
    foreach ($selectedPlugins as $stickerPluginId) {
        $stickerPlugMonth += (int) ($pluginRows[$stickerPluginId]['recur_month'] ?? 0);
        $stickerPlugOnce += (int) ($pluginRows[$stickerPluginId]['recur_once'] ?? 0);
        if (!empty($pluginRows[$stickerPluginId]['quote_only'])) $stickerPlugQuote++;
    }
    $stickerPlanMonth = $stickerOffer ? saas_offer_cents($stickerOffer, $v['billing']) : null;
    if ($stickerPlanMonth !== null && $v['billing'] === 'year') $stickerPlanMonth = (int) round($stickerPlanMonth / 12);
    $stickerTotal = $stickerPlanMonth === null ? null : $stickerPlanMonth + $stickerPlugMonth;
    $stickerTotalText = $stickerTotal === null ? 'Sur devis' : ($stickerTotal === 0 ? 'Gratuit' : saas_money($stickerTotal) . ' / mois');
    $stickerPlugBits = [];
    if ($stickerPlugMonth > 0) $stickerPlugBits[] = ($stickerTotal === null ? 'plugins : ' : 'dont ') . saas_money($stickerPlugMonth) . ' / mois de plugins';
    if ($stickerPlugOnce > 0) $stickerPlugBits[] = saas_money($stickerPlugOnce) . ' en une fois';
    if ($stickerPlugQuote > 0) $stickerPlugBits[] = $stickerPlugQuote . ' plugin' . ($stickerPlugQuote > 1 ? 's' : '') . ' au tarif à confirmer';
    if ($stickerPlugMonth === 0 && $stickerPlugOnce === 0 && $stickerPlugQuote === 0) $stickerPlugBits[] = 'aucun plugin sélectionné';
    $stickerPlugBits[] = 'activation après la création';
    $stickerPlugNote = implode(' · ', $stickerPlugBits);
    ?>
    <aside class="step2-summary form-card order-sticker" aria-label="Résumé de la commande">
      <p class="sticker-eyebrow">Votre commande</p>
      <h2>Résumé</h2>
      <dl class="sticker-list">
        <div><dt>Type de site</dt><dd><?= $siteMode === 'showcase' ? 'Sans paiement en ligne' : 'Vente en ligne' ?></dd></div>
        <div><dt>Galerie</dt><dd><?= saas_e($stickerGallery) ?></dd></div>
        <div><dt>Formule</dt><dd id="sticker-plan" data-empty="À choisir"><?= $stickerOffer ? saas_e($stickerOffer['name']) : 'À choisir' ?></dd></div>
        <div><dt>Facturation</dt><dd id="sticker-billing"><?= $v['billing'] === 'year' ? 'Annuelle' : 'Mensuelle' ?></dd></div>
        <div><dt>Point de vente</dt><dd id="sticker-presence"><?= $shopPresence === 'online' ? 'En ligne uniquement' : 'Physique et en ligne' ?></dd></div>
        <div><dt>Intelligence artificielle</dt><dd id="sticker-ai"><?= $aiMode === 'without' ? 'Sans IA' : 'Avec l’IA' ?></dd></div>
        <div><dt>Plugins</dt><dd id="sticker-plugins" data-empty="Aucun" data-more="+%d"><?= $selectedPlugins ? saas_e(implode(', ', array_map(static fn ($id) => $pluginCatalog[$id]['name'] ?? $id, $selectedPlugins))) : 'Aucun' ?></dd></div>
        <div><dt>Prestations</dt><dd id="sticker-services" data-empty="Aucune"><?= $selectedServices ? saas_e(implode(', ', array_map(static fn ($k) => $serviceQuoteCatalog[$k]['name'] ?? $k, $selectedServices))) : 'Aucune' ?></dd></div>
      </dl>
      <p class="sticker-total"><span>À payer aujourd’hui</span><strong id="sticker-price"><?= saas_e($stickerPrice) ?></strong></p>
      <p class="sticker-total sticker-total-all"><span>Total avec les plugins</span><strong id="sticker-plugins-total"><?= saas_e($stickerTotalText) ?></strong></p>
      <p class="sticker-estimate-note" id="sticker-plugins-note"><?= saas_e($stickerPlugNote) ?></p>
      <?php $ebSticker = $ebTier && (int) $ebTier['discount_pct'] > 0 ? (string) $ebTier['label'] . ' −' . (int) $ebTier['discount_pct'] . ' %' : ''; ?>
      <p class="sticker-badge" id="sticker-eb"<?= $ebSticker === '' || !$stickerOffer || (int) saas_offer_cents($stickerOffer, $v['billing']) <= 0 ? ' style="display:none"' : '' ?>><?= $ebSticker !== '' ? saas_e($ebSticker) . ' appliqué' : '' ?></p>
      <p class="sticker-note">Les plugins et les prestations ne sont pas prélevés à cette étape. Stripe vous demandera votre e-mail au paiement ; abonnement résiliable à tout moment.</p>
    </aside>
  </div>
  <?php endif; ?>
</div>
<script>
window.signupReload = function (key, value) {
  var f = document.querySelector('.step2-layout .step2-main form');
  var pick = function (sel) { var el = f ? f.querySelector(sel) : null; return el ? el.value : ''; };
  var pickAll = function (sel) {
    var out = []; if (f) f.querySelectorAll(sel).forEach(function (i) { out.push(i.value); }); return out;
  };
  var p = new URLSearchParams(location.search);
  p.set('mode', key === 'mode' ? value : (pick('input[name=site_mode]:checked') || 'sales'));
  p.set('lieu', key === 'lieu' ? value : (pick('input[name=lieu]:checked') || 'solo'));
  var galerie = key === 'galerie' ? value : (key === 'lieu' ? '' : pick('input[name=galerie]'));
  if (galerie) p.set('galerie', galerie); else p.delete('galerie');
  p.set('presence', key === 'presence' ? value : (pick('input[name=shop_presence]:checked') || 'store'));
  p.set('ai', pick('input[name=ai_mode]:checked') || 'with');
  // Le changement de lieu, de type ou de galerie change la grille : on repart de la formule mise en avant, sans alerte sur une offre absente.
  if (key === 'mode' || key === 'galerie' || key === 'lieu') p.delete('formule');
  else p.set('formule', pick('input[name=plan]:checked'));
  p.set('facturation', pick('input[name=facturation]:checked') || 'month');
  var plugins = pickAll('input[name="plugins[]"]:checked');
  if (plugins.length) p.set('plugins', plugins.join(',')); else p.delete('plugins');
  var services = pickAll('input[name="service_quotes[]"]:checked');
  if (services.length) p.set('prestations', services.join(',')); else p.delete('prestations');
  location.href = '/inscription/?' + p.toString();
};
(function () {
  var form = document.querySelector('.step2-layout .step2-main form');
  if (!form) return;
  var plan = document.getElementById('sticker-plan'), billing = document.getElementById('sticker-billing'),
      price = document.getElementById('sticker-price'), plugins = document.getElementById('sticker-plugins'),
      count = document.getElementById('plugin-count'), eb = document.getElementById('sticker-eb'),
      total = document.getElementById('sticker-plugins-total'), note = document.getElementById('sticker-plugins-note'),
      services = document.getElementById('sticker-services'), serviceCount = document.getElementById('service-count'),
      stickerAi = document.getElementById('sticker-ai');
  function money(cents) {
    var eur = cents / 100;
    return eur.toLocaleString('fr-FR', { minimumFractionDigits: cents % 100 === 0 ? 0 : 2, maximumFractionDigits: cents % 100 === 0 ? 0 : 2 }) + ' €';
  }
  function checked(sel) { return Array.prototype.slice.call(form.querySelectorAll(sel)); }
  function refreshTotal() {
    var offer = form.querySelector('input[name=plan]:checked');
    var year = (form.querySelector('input[name=facturation]:checked') || {}).value === 'year';
    var card = offer ? offer.closest('.offer') : null;
    var raw = card ? card.dataset[year ? 'centsYear' : 'centsMonth'] : '';
    var planCents = (raw === '' || raw === undefined) ? null : parseInt(raw, 10);
    if (planCents !== null && year) planCents = Math.round(planCents / 12);
    var month = 0, once = 0, quote = 0;
    checked('input[name="plugins[]"]:checked').forEach(function (i) {
      month += parseInt(i.dataset.pluginMonth || '0', 10) || 0;
      once += parseInt(i.dataset.pluginOnce || '0', 10) || 0;
      if (i.dataset.pluginQuote) quote++;
    });
    if (total) {
      if (planCents === null) total.textContent = 'Sur devis';
      else {
        var sum = planCents + month;
        total.textContent = sum === 0 ? 'Gratuit' : money(sum) + ' / mois';
      }
    }
    if (note) {
      var bits = [];
      if (month > 0) bits.push((planCents === null ? 'plugins : ' : 'dont ') + money(month) + ' / mois de plugins');
      if (once > 0) bits.push(money(once) + ' en une fois');
      if (quote > 0) bits.push(quote + ' plugin' + (quote > 1 ? 's' : '') + ' au tarif à confirmer');
      if (month === 0 && once === 0 && quote === 0) bits.push('aucun plugin sélectionné');
      bits.push('activation après la création');
      note.textContent = bits.join(' · ');
    }
  }
  function refreshPrice() {
    var offer = form.querySelector('input[name=plan]:checked');
    var year = (form.querySelector('input[name=facturation]:checked') || {}).value === 'year';
    var card = offer ? offer.closest('.offer') : null;
    if (plan) plan.textContent = card ? card.dataset.name : plan.dataset.empty;
    if (billing) billing.textContent = year ? 'Annuelle' : 'Mensuelle';
    if (price) price.textContent = card ? card.dataset[year ? 'priceYear' : 'priceMonth'] : '—';
    if (eb) {
      var flag = card ? (card.dataset.ebFlag || '') : '';
      eb.style.display = flag ? '' : 'none';
      if (flag) eb.textContent = flag + ' appliqué';
    }
    refreshTotal();
  }
  function refreshPlugins() {
    var names = [];
    checked('input[name="plugins[]"]:checked').forEach(function (i) { names.push(i.dataset.pluginName || i.value); });
    if (plugins) plugins.textContent = names.length
      ? names.slice(0, 3).join(', ') + (names.length > 3 ? ' ' + plugins.dataset.more.replace('%d', names.length - 3) : '')
      : plugins.dataset.empty;
    if (count) count.textContent = names.length
      ? names.length + (names.length > 1 ? ' sélectionnés' : ' sélectionné')
      : count.dataset.empty;
    refreshTotal();
  }
  function refreshServices() {
    var list = checked('input[name="service_quotes[]"]:checked');
    if (services) services.textContent = list.length
      ? list.map(function (i) { return i.dataset.serviceName || i.value; }).join(', ')
      : services.dataset.empty;
    if (serviceCount) serviceCount.textContent = list.length
      ? list.length + (list.length > 1 ? ' sélectionnées' : ' sélectionnée')
      : serviceCount.dataset.empty;
  }
  form.addEventListener('change', function (e) {
    if (e.target.name === 'plan' || e.target.name === 'facturation') refreshPrice();
    if (e.target.name === 'plugins[]') refreshPlugins();
    if (e.target.name === 'service_quotes[]') refreshServices();
    if (e.target.name === 'ai_mode' && stickerAi) stickerAi.textContent = e.target.value === 'without' ? 'Sans IA' : 'Avec l’IA';
  });
  refreshPrice();
  refreshPlugins();
  refreshServices();
})();</script>
<?php if ($errors): ?><script>(function(){var f=document.querySelector('.has-error input,.has-error textarea,.has-error select');if(f){f.scrollIntoView({block:'center'});f.focus({preventScroll:true});}})();</script><?php endif; ?>
<?php saas_page_end(); ?>
