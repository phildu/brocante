<?php
// ÉTAPE 3 : Choix du modèle de design et aide à l'identité visuelle avant le paiement / la création.
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/branding-services.php';
require_once __DIR__ . '/../includes/templates.php';

session_start();
if (empty($_SESSION['saas_csrf'])) $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));

$origin = saas_origin();
$req = saas_request_by_link((string) ($_REQUEST['r'] ?? ''), (string) ($_REQUEST['k'] ?? ''));
$errors = [];
$notice = '';

if (!$req || ($req['status'] ?? '') !== 'draft' || !saas_request_active($req)) {
    $req = null;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['saas_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors['form'] = 'Le formulaire a expiré : rechargez la page et recommencez.';
    } else {
        $skip = !empty($_POST['skip_design']);
        $template = trim((string) ($_POST['template'] ?? $req['template'] ?? SHOP_TEMPLATE_DEFAULT));
        if ($skip || !shop_template_valid($template)) {
            $template = shop_template_valid($req['template'] ?? null) ? (string) $req['template'] : SHOP_TEMPLATE_DEFAULT;
        }
        $req['template'] = $template;
        if (!saas_request_save($req)) {
            $errors['form'] = 'Impossible d’enregistrer votre choix de design. Réessayez.';
        } else {
            $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));
            if (saas_req_payable($req)) {
                header('Location: ' . saas_payment_url($req, $origin), true, 303);
            } else {
                header('Location: ' . saas_creation_url($req, $origin), true, 303);
            }
            exit;
        }
    }
}

$req = $req ? (saas_request_load((string) $req['id']) ?? $req) : null;
$offer = $req ? saas_req_offer($req) : null;
$isPayable = $req && saas_req_payable($req);
$escape = static fn (string $value): string => saas_e($value);

$shopName = trim((string) (!empty($req['shop_name']) ? $req['shop_name'] : ($req['preferred_shop_name'] ?? '')));
$currentTemplate = shop_template_valid($req['template'] ?? null) ? (string) $req['template'] : SHOP_TEMPLATE_DEFAULT;
$domainChoice = (array) ($req['branding']['domain_choice'] ?? []);
$activity = (array) ($req['activity_details'] ?? []);
$activityContext = branding_activity_context($activity);
$location = trim((string) ($req['location'] ?? ''));

$rawHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'brocs.arrimage.com'));
$baseDomain = tenant_base_host($rawHost);
$defaultSlug = trim(saas_slug($shopName !== '' ? $shopName : 'ma-boutique'), '-');
if ($defaultSlug === '') $defaultSlug = 'ma-boutique';

$backToPlan = $req ? '/inscription/?' . http_build_query([
    'galerie' => (string) ($offer['gallery'] ?? $req['gallery'] ?? ''),
    'mode' => (string) ($offer['mode'] ?? $req['site_mode'] ?? 'sales'),
    'formule' => (string) ($offer['key'] ?? $req['plan'] ?? ''),
    'facturation' => (string) ($offer['billing'] ?? 'month'),
    'r' => (string) $req['id'],
    'k' => (string) $req['token'],
], '', '&', PHP_QUERY_RFC3986) : '';

$backToConfig = $req ? '/inscription/configurateur.php?r=' . rawurlencode((string) $req['id']) . '&k=' . rawurlencode((string) $req['token']) : '';

$yearly = $offer && ($offer['billing'] ?? '') === 'year';
$yearSavings = 0;
if ($offer && $yearly) {
    $gridOffers = saas_grid_offers(saas_grid((string) ($offer['gallery'] ?? ''), (string) ($offer['mode'] ?? 'sales')));
    $pricingOffer = array_column($gridOffers, null, 'key')[(string) ($offer['key'] ?? '')] ?? null;
    if ($pricingOffer) $yearSavings = saas_offer_saving_cents($pricingOffer);
}

saas_page_start('Aide au design — ' . saas_config()['name'], 'Choisissez le style et l’identité graphique de votre boutique.', 'inscription');
?>
<div class="wrap">
  <div class="page-title">
    <p class="eyebrow">Création de boutique</p>
    <h1>Aide au design de ma boutique</h1>
    <ol class="stepper" aria-label="Étapes">
      <li class="is-done"><?php if ($req): ?><a class="stepper-link" href="<?= $escape($backToPlan) ?>" aria-label="Revenir à l’étape Ma formule"><b>✓</b> Ma formule</a><?php else: ?><b>✓</b> Ma formule<?php endif; ?></li>
      <li class="is-done"><?php if ($req): ?><a class="stepper-link" href="<?= $escape($backToConfig) ?>" aria-label="Revenir à l’étape Préconfiguration"><b>✓</b> Préconfiguration</a><?php else: ?><b>✓</b> Préconfiguration<?php endif; ?></li>
      <li class="is-current" aria-current="step"><b>3</b> Design</li>
      <?php if ($isPayable): ?>
        <li><b>4</b> Paiement</li>
        <li><b>5</b> Ma boutique</li>
      <?php else: ?>
        <li><b>4</b> Ma boutique</li>
      <?php endif; ?>
    </ol>
    <p class="lede">Sélectionnez le thème visuel qui correspond le mieux à votre activité. Vous pourrez modifier le design, les couleurs et les polices à tout moment depuis votre administration.</p>
  </div>

  <?php if (!$req): ?>
    <div class="form-card"><div class="notice error" role="alert"><strong>Cette étape n’est plus disponible.</strong> Le lien a expiré ou a déjà été utilisé.</div>
      <p><a class="btn" href="/inscription/">Choisir une offre</a></p></div>
  <?php else: ?>
    <div class="step2-layout">
      <div class="step2-main">
        <form class="form-card" method="post" action="/inscription/design.php" novalidate>
          <input type="hidden" name="csrf" value="<?= $escape($_SESSION['saas_csrf']) ?>">
          <input type="hidden" name="r" value="<?= $escape($req['id']) ?>">
          <input type="hidden" name="k" value="<?= $escape($req['token']) ?>">

          <?php if (isset($errors['form'])): ?><div class="notice error" role="alert"><?= $escape($errors['form']) ?></div><?php endif; ?>

          <h2 style="margin-top:0">Choisissez votre univers graphique</h2>
          <p>Chaque modèle définit une palette harmonieuse, des typographies soignées et une mise en page adaptée. Votre boutique sera créée avec ce style par défaut.</p>

          <div class="tpl-pick" style="margin:20px 0 24px">
            <?php foreach (SHOP_TEMPLATES as $key => $tpl):
              $colors = $tpl['colors'] ?? [];
              $bg = $colors['bg'] ?? '#ffffff';
              $ink = $colors['ink'] ?? '#111111';
              $accent = $colors['accent'] ?? '#0f766e';
              $accent2 = $colors['accent-2'] ?? '#d97706';
              $fonts = $tpl['fonts'] ?? [];
              $fontDisplay = $fonts['display'] ?? 'sans-serif';
              $fontBody = $fonts['body'] ?? 'sans-serif';
              $isChecked = ($key === $currentTemplate);
            ?>
              <label>
                <input type="radio" name="template" value="<?= $escape($key) ?>"<?= $isChecked ? ' checked' : '' ?>>
                <div class="tpl-card">
                  <div class="tpl-mock tpl-<?= $escape($key) ?>" style="background:<?= $escape($bg) ?>;color:<?= $escape($ink) ?>;border:1px solid var(--line);border-radius:10px;padding:12px">
                    <span class="tpl-title" style="font-family:'<?= $escape($fontDisplay) ?>',serif;font-size:1.4rem;line-height:1.1;font-weight:700"><?= $escape($tpl['label']) ?></span>
                    <span class="tpl-line" style="background:<?= $escape($ink) ?>;opacity:.3;height:5px;border-radius:3px;margin:6px 0 4px;width:75%"></span>
                    <span class="tpl-line short" style="background:<?= $escape($ink) ?>;opacity:.2;height:5px;border-radius:3px;margin-bottom:8px;width:45%"></span>
                    <span class="tpl-btn" style="background:<?= $escape($accent) ?>;display:inline-block;padding:3px 10px;border-radius:999px;font-size:.65rem;color:#fff;font-weight:600">Boutique</span>
                  </div>
                  <strong><?= $escape($tpl['label']) ?></strong>
                  <small><?= $escape($tpl['tagline']) ?></small>
                  <div class="tpl-sw" style="display:flex;gap:5px;margin-top:6px">
                    <i style="background:<?= $escape($bg) ?>" title="Fond"></i>
                    <i style="background:<?= $escape($ink) ?>" title="Texte"></i>
                    <i style="background:<?= $escape($accent) ?>" title="Couleur principale"></i>
                    <i style="background:<?= $escape($accent2) ?>" title="Couleur secondaire"></i>
                  </div>
                </div>
              </label>
            <?php endforeach; ?>
          </div>

          <div style="background:var(--surface-2);border:1px solid var(--line);border-radius:12px;padding:14px 18px;margin-bottom:20px">
            <strong style="display:block;margin-bottom:4px;font-size:.95rem">💡 Conseil pour votre boutique « <?= $escape($shopName !== '' ? $shopName : 'Votre boutique') ?> »</strong>
            <p style="margin:0;font-size:.85rem;color:var(--ink-soft);line-height:1.4">
              <?php if (!empty($activity['primary']) && in_array($activity['primary'], ['livres', 'librairie'], true)): ?>
                Le modèle <strong>Librairie</strong> est particulièrement recommandé pour votre activité : il valorise la recherche par thèmes et les fiches détaillées.
              <?php elseif (!empty($activity['primary']) && in_array($activity['primary'], ['mode', 'friperie'], true)): ?>
                Le modèle <strong>Mode</strong> est optimisé pour les vêtements avec des grandes photos de lookbook et une grille épurée.
              <?php elseif (!empty($activity['primary']) && in_array($activity['primary'], ['art', 'galerie'], true)): ?>
                Le modèle <strong>Galerie</strong> met en majesté les œuvres avec un design éditorial sobre et élégant.
              <?php else: ?>
                Les modèles <strong>Brocante</strong> et <strong>Atelier</strong> sont les plus polyvalents pour les objets uniques, pièces d’artisanat et trouvailles de seconde main.
              <?php endif; ?>
              Vous pourrez également générer un logo et affiner votre identité visuelle dès que votre boutique sera créée.
            </p>
          </div>

          <div class="step2-payment-actions" style="margin-top:24px">
            <button class="btn" type="submit"><?= $isPayable ? 'Valider et continuer vers le paiement' : 'Valider et créer ma boutique' ?></button>
            <button class="btn ghost" type="submit" name="skip_design" value="1">Je choisirai plus tard (passer cette étape)</button>
          </div>
        </form>
      </div>

      <aside class="step2-summary form-card" aria-labelledby="step3-summary-title">
        <h2 id="step3-summary-title">Récapitulatif</h2>
        <ol class="step2-summary-steps">
          <li><span>1</span><div><strong>Formule</strong><small><?= $escape((string) $offer['name']) ?><?= ($offer['mode'] ?? 'sales') === 'showcase' ? ' · vitrine' : ' · vente en ligne' ?></small></div></li>
          <li><span>2</span><div><strong>Nom et domaine</strong>
            <small><?= $escape($shopName !== '' ? $shopName : 'À préciser') ?></small>
            <?php if (!empty($activityContext)): ?><small><?= $escape($activityContext) ?></small><?php endif; ?>
            <?php if (!empty($location)): ?><small><?= $escape($location) ?></small><?php endif; ?>
            <small><?= !empty($domainChoice['domain']) ? 'Domaine : ' . $escape((string) $domainChoice['domain']) : 'Sous-domaine : ' . $escape($defaultSlug . '.' . $baseDomain) ?></small>
          </div></li>
          <li class="is-current"><span>3</span><div><strong>Design</strong><small id="step3-summary-tpl"><?= $escape(SHOP_TEMPLATES[$currentTemplate]['label']) ?></small></div></li>
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
        <p class="meta">Le modèle de design choisi s’appliquera dès l’ouverture de votre boutique. Vous pourrez en changer à tout moment sans perte de données.</p>
      </aside>
    </div>
  <?php endif; ?>
</div>
<script>
  (function () {
    var radios = document.querySelectorAll('input[name="template"]');
    var summaryTpl = document.getElementById('step3-summary-tpl');
    var labels = <?= json_encode(array_map(static fn ($t) => $t['label'], SHOP_TEMPLATES)) ?>;
    radios.forEach(function (r) {
      r.addEventListener('change', function () {
        if (summaryTpl && labels[r.value]) summaryTpl.textContent = labels[r.value];
      });
    });
  })();
</script>
<?php saas_page_end(); ?>
