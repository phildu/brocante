<?php
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/plugins.php';
require_once PORTAIL_ROOT . '/includes/addons.php';
require_once PORTAIL_ROOT . '/includes/galleries.php';
require_once PORTAIL_ROOT . '/includes/gallery-types.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';

$catalog = addon_defaults();
$shops = tenant_list();
$galleries = gallery_list();
$limited = defined('GA_LIMITED') ? (string) GA_LIMITED : '';
$scope = (string) ($_REQUEST['scope'] ?? 'global');
$module = (string) ($_REQUEST['plugin'] ?? array_key_first($catalog));
$gallerySlug = strtolower((string) ($_REQUEST['gallery'] ?? $limited));
$shopSlug = strtolower((string) ($_REQUEST['shop'] ?? ''));
if (!isset($catalog[$module])) $module = (string) array_key_first($catalog);
if (!in_array($scope, ['global', 'gallery', 'shop', 'gallery_shop'], true)) $scope = $limited !== '' ? 'gallery' : 'global';
if ($limited !== '') {
    if (!in_array($scope, ['gallery', 'gallery_shop'], true)) $scope = 'gallery';
    $gallerySlug = $limited;
}
if (!isset($galleries[$gallerySlug])) $gallerySlug = $limited !== '' ? $limited : (string) array_key_first($galleries);
if (!isset($shops[$shopSlug])) $shopSlug = (string) array_key_first($shops);

$isGalleryMember = static function (string $gallery, string $shop) use ($galleries): bool {
    foreach ($galleries[$gallery]['members'] ?? [] as $member) {
        if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === $shop) return true;
    }
    return false;
};
if ($scope === 'gallery_shop' && !$isGalleryMember($gallerySlug, $shopSlug)) {
    foreach ($galleries[$gallerySlug]['members'] ?? [] as $member) {
        if (($member['type'] ?? '') === 'local' && isset($shops[$member['slug']])) {
            $shopSlug = (string) $member['slug'];
            break;
        }
    }
}
if ($scope === 'gallery_shop' && (!$gallerySlug || !$shopSlug || !$isGalleryMember($gallerySlug, $shopSlug))) {
    portail_flash('Cette boutique ne fait pas partie de la galerie sélectionnée.', 'error');
    header('Location: /portail/addons.php?scope=' . ($limited !== '' ? 'gallery&gallery=' . rawurlencode($limited) : 'global'));
    exit;
}

$settings = addon_settings_load();
$target =& $settings['global'];
$targetKey = $module;
if ($scope === 'gallery') {
    $target =& $settings['galleries'][$gallerySlug];
} elseif ($scope === 'shop') {
    $target =& $settings['shops'][$shopSlug];
} elseif ($scope === 'gallery_shop') {
    $target =& $settings['gallery_shops'][$gallerySlug][$shopSlug];
}
$savedOverride = (array) ($target[$targetKey] ?? []);
$effectiveGallery = $scope === 'gallery' || $scope === 'gallery_shop' ? $gallerySlug
    : ($scope === 'shop' ? addon_gallery_for_shop($shopSlug, $module) : '');
$effectiveShop = in_array($scope, ['shop', 'gallery_shop'], true) ? $shopSlug : '';
$effective = addon_info($module, $effectiveShop, $effectiveGallery) ?? $catalog[$module];
$form = $scope === 'global' ? array_replace($effective, $savedOverride) : $savedOverride;
$shopGalleries = [];
if ($effectiveShop !== '') {
    foreach ($galleries as $slug => $gallery) {
        foreach ($gallery['members'] as $member) {
            if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === $effectiveShop) {
                $shopGalleries[] = (string) $slug;
                break;
            }
        }
    }
}

$euroField = static function (?int $cents): string {
    if ($cents === null) return '';
    return $cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2, ',', '');
};
$pricePlaceholder = static function (string $key) use ($scope, $effective, $euroField): string {
    if ($scope === 'global') return 'vide = non proposé';
    $cents = $effective[$key] ?? null;
    return 'hérité : ' . ($cents === null ? 'non proposé' : $euroField((int) $cents) . ' €');
};
$modesInherit = $scope !== 'global' && !array_key_exists('payment_modes', $savedOverride);
$accessValue = $scope === 'global' ? ($form['access'] ?? 'available') : ($savedOverride['access'] ?? 'inherit');
$termsInherit = $scope !== 'global' && !array_key_exists('terms', $savedOverride);
$termsCleared = $scope !== 'global' && array_key_exists('terms', $savedOverride) && (string) $savedOverride['terms'] === '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $invalidPrice = false;
        foreach (['monthly', 'yearly', 'once'] as $field) {
            if (isset($_POST[$field]) && !is_scalar($_POST[$field])) {
                $invalidPrice = true;
                continue;
            }
            $value = trim(str_replace([' ', "\u{00a0}", '€'], '', (string) ($_POST[$field] ?? '')));
            $value = str_replace(',', '.', $value);
            if ($value !== '' && (!is_numeric($value) || (float) $value < 0 || (float) $value > 99900)) $invalidPrice = true;
        }
        $validModes = ['monthly', 'yearly', 'once', 'manual', 'free'];
        $modesInput = $_POST['payment_modes'] ?? [];
        $modes = is_array($modesInput) ? $modesInput : [$modesInput];
        $invalidModes = array_filter($modes, static fn ($mode): bool => !is_string($mode) || !in_array($mode, $validModes, true));
        $invalidText = false;
        foreach (['name', 'description', 'terms', 'trial_days', 'access', 'payment_modes_inherit', 'terms_clear'] as $field) {
            if (isset($_POST[$field]) && !is_scalar($_POST[$field])) $invalidText = true;
        }
        $trialInput = is_scalar($_POST['trial_days'] ?? '') ? trim((string) ($_POST['trial_days'] ?? '')) : '';
        $trialDays = $trialInput === '' && $scope !== 'global' ? null : filter_var($trialInput, FILTER_VALIDATE_INT);
        $accessInput = is_scalar($_POST['access'] ?? '') ? (string) ($_POST['access'] ?? '') : '';
        $validAccess = in_array($accessInput, ['available', 'included', 'unavailable'], true)
            || ($scope !== 'global' && $accessInput === 'inherit');
        if ($invalidPrice || !$validAccess || $invalidModes || $invalidText
            || ($trialDays !== null && ($trialDays === false || $trialDays < 0 || $trialDays > 90))) {
            portail_flash('Vérifiez les prix (0 à 99 900 €), la durée d’essai, l’accès et les modalités de paiement.', 'error');
            header('Location: /portail/addons.php?' . http_build_query(array_filter([
                'scope' => $scope, 'plugin' => $module, 'gallery' => $gallerySlug, 'shop' => $shopSlug,
            ], static fn ($value): bool => $value !== '')), true, 303);
            exit;
        }
        if ($scope === 'global') {
            $_POST['gallery_types'] = (array) ($_POST['gallery_types'] ?? []);
            $_POST['shop_modes'] = (array) ($_POST['shop_modes'] ?? []);
            $_POST['testers'] = is_array($_POST['testers'] ?? null) ? $_POST['testers'] : [];
            $_POST['feedback_required'] = !empty($_POST['feedback_required']);
            $_POST['beta_reminders'] = !empty($_POST['beta_reminders']);
            $_POST['beta_since'] = ($_POST['stage'] ?? '') === 'beta' ? ((int) ($savedOverride['beta_since'] ?? 0) ?: time()) : 0;
            // Un module en test sans aucun testeur n'est visible de personne : l'exploitant doit le savoir.
        }
        $normalized = addon_settings_normalize($_POST);
        if ($scope !== 'global') {
            foreach (['monthly' => 'monthly_cents', 'yearly' => 'yearly_cents', 'once' => 'once_cents'] as $field => $key) {
                if (trim((string) ($_POST[$field] ?? '')) === '') unset($normalized[$key]);
            }
            if ($trialInput === '') unset($normalized['trial_days']);
            if ($accessInput === 'inherit') unset($normalized['access']);
            if (!empty($_POST['payment_modes_inherit'])) unset($normalized['payment_modes']);
            else $normalized['payment_modes'] = array_values(array_intersect($validModes, array_unique($modes)));
            if (trim((string) ($_POST['terms'] ?? '')) === '' && empty($_POST['terms_clear'])) unset($normalized['terms']);
            if (!empty($_POST['terms_clear'])) $normalized['terms'] = '';
        } else {
            $normalized['payment_modes'] = array_values(array_intersect($validModes, array_unique($modes)));
        }
        if ($scope === 'global') $normalized['name'] = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        if ($scope === 'global') $normalized['description'] = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
        if ($normalized) $target[$targetKey] = $normalized;
        else unset($target[$targetKey]);
        $ok = addon_settings_save($settings);
        $activationErrors = [];
        if ($ok && ($normalized['access'] ?? '') === 'included') {
            $manifest = plugin_manifest($module);
            $activationSlugs = match ($scope) {
                'global' => array_keys($shops),
                'gallery' => array_values(array_map(static fn (array $member): string => (string) $member['slug'], array_filter($galleries[$gallerySlug]['members'] ?? [], static fn (array $member): bool => ($member['type'] ?? '') === 'local'))),
                'shop' => [$shopSlug],
                default => [$shopSlug],
            };
            if (!$manifest) $activationErrors[] = 'Le manifeste du plugin est introuvable.';
            else foreach ($activationSlugs as $activationSlug) {
                if (!isset($shops[$activationSlug])) continue;
                try {
                    [$pdo, $dbFile] = plugin_open_tenant_db($activationSlug);
                    plugin_enable($pdo, $dbFile, $manifest);
                } catch (Throwable $e) {
                    $activationErrors[] = $activationSlug . ' : ' . $e->getMessage();
                }
            }
        }
        if ($ok) saas_event('config_changed', ['note' => 'Modalités du plugin ' . $module . ' mises à jour (' . $scope . ')', 'actor' => 'exploitant']);
        if (!$ok) portail_flash("Impossible d'écrire data/saas/addons.json.", 'error');
        elseif ($activationErrors) portail_flash('Modalités enregistrées, mais activation incomplète : ' . implode(' ; ', $activationErrors), 'error');
        else portail_flash('Modalités du plugin enregistrées.' . (($normalized['access'] ?? '') === 'included' ? ' Plugin installé et activé pour les boutiques concernées.' : ''), 'ok');
    } elseif ($action === 'remind') {
        $n = addon_beta_send_reminders();
        portail_flash($n ? "$n rappel(s) envoyé(s)." : 'Aucun rappel à envoyer (retours à jour ou rappel déjà envoyé).', 'ok');
    } elseif ($action === 'reset') {
        unset($target[$targetKey]);
        $ok = addon_settings_save($settings);
        portail_flash($ok ? 'La configuration locale est supprimée : les modalités héritées s’appliquent.' : "Impossible d'écrire data/saas/addons.json.", $ok ? 'ok' : 'error');
    }
    $params = ['scope' => $scope, 'plugin' => $module];
    if ($gallerySlug !== '') $params['gallery'] = $gallerySlug;
    if (in_array($scope, ['shop', 'gallery_shop'], true)) $params['shop'] = $shopSlug;
    header('Location: /portail/addons.php?' . http_build_query($params), true, 303);
    exit;
}

$flash = portail_flash();
$paymentChoices = ['monthly' => 'Abonnement mensuel Stripe', 'yearly' => 'Abonnement annuel Stripe', 'once' => 'Paiement unique Stripe',
    'manual' => 'Paiement manuel (contact exploitant)', 'free' => 'Activation gratuite par le commerçant'];
$scopeLabel = match ($scope) {
    'global' => 'Plateforme',
    'gallery' => 'Galerie « ' . ($galleries[$gallerySlug]['name'] ?? $gallerySlug) . ' »',
    'shop' => 'Boutique « ' . ($shops[$shopSlug]['name'] ?? $shopSlug) . ' »',
    default => 'Boutique « ' . ($shops[$shopSlug]['name'] ?? $shopSlug) . ' » dans la galerie « ' . ($galleries[$gallerySlug]['name'] ?? $gallerySlug) . ' »',
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Tarifs des plugins — Portail</title>
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=1">
<style>
  .aw { max-width: 900px; margin: 0 auto; padding: 0 16px 48px; display: grid; gap: 16px; }
  .ac { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 18px; display: grid; gap: 14px; }
  .ac h2 { margin: 0; font-size: 1.05rem; } .ac p { margin: 0; }
  .ag { display: grid; grid-template-columns: repeat(auto-fit,minmax(190px,1fr)); gap: 12px; }
  .ag label { display: grid; gap: 5px; font-size: .86rem; }
  .amodes { display: flex; gap: 12px 18px; flex-wrap: wrap; }
  .amodes label { display: flex; gap: 7px; align-items: center; }
  textarea { min-height: 90px; }
  .muted { color: var(--muted); font-size: .84rem; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Tarifs et modalités des plugins',
    'eyebrow' => 'Revenus',
    'desc_html' => e($scopeLabel),
    'actions' => '<a class="btn" href="/portail/galeries.php' . ($gallerySlug !== '' ? '?g=' . e(rawurlencode($gallerySlug)) : '') . '">Galeries</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <main class="aw">
    <section class="ac">
      <h2>Configuration</h2>
      <div class="ag">
        <label>Portée
          <select onchange="location.href='/portail/addons.php?scope='+encodeURIComponent(this.value)+'&plugin='+encodeURIComponent(document.getElementById('addon-plugin').value)+'&gallery=<?= e(rawurlencode($gallerySlug)) ?>&shop=<?= e(rawurlencode($shopSlug)) ?>">
            <?php foreach (['global' => 'Plateforme', 'gallery' => 'Galerie', 'shop' => 'Boutique', 'gallery_shop' => 'Boutique dans une galerie'] as $key => $label):
              if ($limited !== '' && !in_array($key, ['gallery', 'gallery_shop'], true)) continue; ?>
              <option value="<?= e($key) ?>"<?= $scope === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Plugin
          <select id="addon-plugin" onchange="location.href='/portail/addons.php?scope='+encodeURIComponent('<?= e($scope) ?>')+'&plugin='+encodeURIComponent(this.value)+'&gallery='+encodeURIComponent('<?= e($gallerySlug) ?>')+'&shop='+encodeURIComponent('<?= e($shopSlug) ?>')">
            <?php foreach ($catalog as $id => $info): ?><option value="<?= e($id) ?>"<?= $module === $id ? ' selected' : '' ?>><?= e($info['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <?php if (in_array($scope, ['gallery', 'gallery_shop'], true) && $limited === ''): ?>
          <label>Galerie<select onchange="location.href='/portail/addons.php?scope=<?= e($scope) ?>&plugin=<?= e($module) ?>&gallery='+encodeURIComponent(this.value)+'&shop=<?= e($shopSlug) ?>'">
            <?php foreach ($galleries as $id => $g): ?><option value="<?= e($id) ?>"<?= $gallerySlug === $id ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
          </select></label>
        <?php endif; ?>
        <?php if (in_array($scope, ['shop', 'gallery_shop'], true) && $limited === ''): ?>
          <label>Boutique<select onchange="location.href='/portail/addons.php?scope=<?= e($scope) ?>&plugin=<?= e($module) ?>&gallery=<?= e($gallerySlug) ?>&shop='+encodeURIComponent(this.value)">
            <?php foreach ($shops as $id => $config): if ($scope === 'gallery_shop' && !$isGalleryMember($gallerySlug, $id)) continue; ?>
              <option value="<?= e($id) ?>"<?= $shopSlug === $id ? ' selected' : '' ?>><?= e($config['name']) ?> (<?= e($id) ?>)</option>
            <?php endforeach; ?>
          </select></label>
        <?php endif; ?>
      </div>
      <p class="muted">Héritage : plateforme → galerie → configuration boutique. La configuration boutique est prioritaire. Une galerie peut aussi régler séparément un plugin pour l’une de ses boutiques membres.</p>
      <?php if ($scope === 'shop' && $effectiveGallery !== ''): ?><p class="muted">Cette boutique hérite des règles de la galerie « <?= e($galleries[$effectiveGallery]['name'] ?? $effectiveGallery) ?> » pour ce plugin.</p><?php endif; ?>
      <?php if (count($shopGalleries) > 1): ?><p class="muted">Cette boutique appartient à plusieurs galeries. Pour ce plugin, la première galerie (ordre alphabétique de l’identifiant) ayant une règle spécifique s’applique ; la surcharge boutique reste prioritaire.</p><?php endif; ?>
      <?php if ($limited !== ''): ?><p class="muted">Vous gérez uniquement les plugins pour les boutiques membres de votre galerie.</p><?php endif; ?>
    </section>
    <form class="ac" method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
      <input type="hidden" name="scope" value="<?= e($scope) ?>"><input type="hidden" name="plugin" value="<?= e($module) ?>">
      <input type="hidden" name="gallery" value="<?= e($gallerySlug) ?>"><input type="hidden" name="shop" value="<?= e($shopSlug) ?>">
      <h2><?= e($catalog[$module]['name']) ?> — <?= e($scopeLabel) ?></h2>
      <?php if ($scope === 'global'): ?>
        <div class="ag"><label>Nom affiché<input name="name" maxlength="120" value="<?= e((string) $form['name']) ?>"></label>
          <label>Description<textarea name="description" maxlength="500"><?= e((string) $form['description']) ?></textarea></label></div>
      <?php endif; ?>
      <div class="ag">
        <label>Prix mensuel (€)<input name="monthly" inputmode="decimal" value="<?= e(array_key_exists('monthly_cents', $form) ? $euroField($form['monthly_cents']) : '') ?>" placeholder="<?= e($pricePlaceholder('monthly_cents')) ?>"></label>
        <label>Prix annuel (€)<input name="yearly" inputmode="decimal" value="<?= e(array_key_exists('yearly_cents', $form) ? $euroField($form['yearly_cents']) : '') ?>" placeholder="<?= e($pricePlaceholder('yearly_cents')) ?>"></label>
        <label>Prix unique (€)<input name="once" inputmode="decimal" value="<?= e(array_key_exists('once_cents', $form) ? $euroField($form['once_cents']) : '') ?>" placeholder="<?= e($pricePlaceholder('once_cents')) ?>"></label>
        <label>Essai gratuit (jours)<input type="number" name="trial_days" min="0" max="90" value="<?= array_key_exists('trial_days', $form) ? (int) $form['trial_days'] : '' ?>" placeholder="<?= $scope === 'global' ? '0' : 'hérité : ' . (int) ($effective['trial_days'] ?? 0) ?>"></label>
        <label>Accès par défaut<select name="access">
          <?php if ($scope !== 'global'): ?><option value="inherit"<?= $accessValue === 'inherit' ? ' selected' : '' ?>>Hériter de la portée supérieure</option><?php endif; ?>
          <option value="available"<?= $accessValue === 'available' ? ' selected' : '' ?>>Proposé à la souscription</option>
          <option value="included"<?= $accessValue === 'included' ? ' selected' : '' ?>>Inclus pour cette portée</option>
          <option value="unavailable"<?= $accessValue === 'unavailable' ? ' selected' : '' ?>>Indisponible</option>
        </select></label>
      </div>
      <?php if ($scope === 'global'):
        $stageVal = (string) ($form['stage'] ?? 'live');
        $testers = (array) ($form['testers'] ?? []);
        $modeLabels = BUSINESS_PRESENCE_MODES; ?>
      <fieldset><legend>État et ciblage</legend>
        <div class="ag"><label>État du module<select name="stage">
          <option value="live"<?= $stageVal === 'live' ? ' selected' : '' ?>>Validé — en production, vendu selon les tarifs</option>
          <option value="beta"<?= $stageVal === 'beta' ? ' selected' : '' ?>>En test — réservé aux testeurs, gratuit, avec retours</option>
        </select></label></div>
        <div class="amodes">
          <label><input type="checkbox" name="feedback_required" value="1"<?= !empty($form['feedback_required']) ? ' checked' : '' ?>> Retours obligatoires : sans retour, l’accès est suspendu</label>
          <label><input type="checkbox" name="beta_reminders" value="1"<?= !empty($form['beta_reminders']) ? ' checked' : '' ?>> Rappel par e-mail aux testeurs sans retour</label>
        </div>
        <div class="ag"><label>Délai entre deux retours attendus (jours)<input type="number" name="feedback_days" min="1" max="90" value="<?= (int) ($form['feedback_days'] ?? 7) ?>"></label></div>
        <p class="muted">Le délai court depuis la mise en test, puis depuis le dernier retour de la boutique. Les rappels partent à l’adresse de connexion de la boutique, au plus une fois par délai ; planifiez <code>php scripts/beta-reminders.php</code> ou utilisez le bouton ci-dessous.</p>
        <p class="muted">Types de galerie concernés (aucun coché = tous)</p>
        <div class="amodes"><?php foreach (gt_types() as $key => $gt): ?>
          <label><input type="checkbox" name="gallery_types[]" value="<?= e($key) ?>"<?= in_array($key, (array) ($form['gallery_types'] ?? []), true) ? ' checked' : '' ?>><?= e(($gt['emoji'] ?? '') . ' ' . $gt['name']) ?></label>
        <?php endforeach; ?></div>
        <p class="muted">Types de boutique concernés — présence (aucun coché = tous)</p>
        <div class="amodes"><?php foreach ($modeLabels as $key => $label): ?>
          <label><input type="checkbox" name="shop_modes[]" value="<?= e($key) ?>"<?= in_array($key, (array) ($form['shop_modes'] ?? []), true) ? ' checked' : '' ?>><?= e($label) ?></label>
        <?php endforeach; ?></div>
        <p class="muted">Testeurs (utilisés quand le module est « En test ») : leurs boutiques l’obtiennent gratuitement jusqu’à la mise en production et doivent envoyer des retours. Une galerie testeuse ouvre le module à tous ses membres.</p>
        <p class="muted"><strong>Galeries testeuses</strong></p>
        <div class="amodes"><?php foreach ($galleries as $gs => $g): ?>
          <label><input type="checkbox" name="testers[galleries][]" value="<?= e($gs) ?>"<?= in_array($gs, (array) ($testers['galleries'] ?? []), true) ? ' checked' : '' ?>><?= e($g['name']) ?></label>
        <?php endforeach; ?><?php if (!$galleries): ?><span class="muted">Aucune galerie.</span><?php endif; ?></div>
        <p class="muted"><strong>Boutiques testeuses</strong></p>
        <div class="amodes"><?php foreach ($shops as $ss => $sh): ?>
          <label><input type="checkbox" name="testers[shops][]" value="<?= e($ss) ?>"<?= in_array($ss, (array) ($testers['shops'] ?? []), true) ? ' checked' : '' ?>><?= e((string) ($sh['name'] ?? $ss)) ?></label>
        <?php endforeach; ?></div>
      </fieldset>
      <?php endif; ?>
      <fieldset><legend>Modalités de paiement autorisées</legend>
        <?php if ($scope === 'global'): ?><input type="hidden" name="payment_modes_present" value="1">
        <?php else: ?><label><input type="checkbox" id="payment-modes-inherit" name="payment_modes_inherit" value="1"<?= $modesInherit ? ' checked' : '' ?>> Hériter des modalités de paiement</label><?php endif; ?>
        <div class="amodes"><?php foreach ($paymentChoices as $key => $label): ?>
          <label><input type="checkbox" name="payment_modes[]" value="<?= e($key) ?>"<?= in_array($key, (array) ($form['payment_modes'] ?? []), true) ? ' checked' : '' ?><?= $modesInherit ? ' disabled' : '' ?>><?= e($label) ?></label>
        <?php endforeach; ?></div>
      </fieldset>
      <label>Conditions affichées au commerçant<textarea name="terms" maxlength="1200" placeholder="<?= $termsInherit ? 'Vide = hériter des conditions (' . mb_substr((string) ($effective['terms'] ?? ''), 0, 100) . ')' : 'Informations sur la facturation, résiliation, paiement manuel…' ?>"><?= e((string) ($form['terms'] ?? '')) ?></textarea></label>
      <?php if ($scope !== 'global' && (($effective['terms'] ?? '') !== '' || $termsCleared)): ?><label><input type="checkbox" name="terms_clear" value="1"<?= $termsCleared ? ' checked' : '' ?>> Ne pas afficher les conditions héritées</label><?php endif; ?>
      <p class="muted">À cette portée, un champ vide conserve l’héritage ; pour désactiver un paiement, décochez sa modalité. À la plateforme, un prix vide retire l’offre. Le paiement manuel redirige vers un e-mail de contact : l’activation reste à confirmer dans les réglages.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary" type="submit">Enregistrer</button>
        <?php if ($savedOverride): ?><button class="btn" type="submit" name="action" value="reset" onclick="return confirm('Supprimer cette configuration et reprendre les modalités héritées ?')">Reprendre les modalités héritées</button><?php endif; ?>
      </div>
    </form>
    <?php if ($scope === 'global' && ($effective['stage'] ?? 'live') === 'beta' && !empty($effective['beta_reminders'])): ?>
    <form method="post" class="ac"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="scope" value="global"><input type="hidden" name="plugin" value="<?= e($module) ?>">
      <button class="btn" name="action" value="remind">Envoyer les rappels maintenant</button></form>
    <?php endif; ?>
    <?php if ($scope === 'global'):
      $fbList = addon_feedback_list($module);
      $fbShops = [];
      foreach ($fbList as $f) $fbShops[$f['shop']] = true;
      $effTesters = (array) ($effective['testers'] ?? []);
      $testerShops = array_values(array_unique(array_merge((array) ($effTesters['shops'] ?? []),
        ...array_map(static fn (string $gs): array => array_map(static fn ($m) => (string) ($m['slug'] ?? ''), array_filter($galleries[$gs]['members'] ?? [], static fn ($m) => ($m['type'] ?? '') === 'local')), (array) ($effTesters['galleries'] ?? []))))); ?>
    <section class="ac">
      <h2>Retours des testeurs (<?= count($fbList) ?>)</h2>
      <?php if (($effective['stage'] ?? 'live') === 'beta'): ?>
        <p class="muted">Boutiques testeuses : <?php foreach ($testerShops as $ts): ?><span><?= e($ts) ?> <?= isset($fbShops[$ts]) ? '✔' : '⏳ (aucun retour)' ?></span> · <?php endforeach; ?><?= $testerShops ? '' : 'aucune — personne ne voit encore ce module.' ?></p>
      <?php endif; ?>
      <?php foreach (array_slice($fbList, 0, 20) as $f): ?>
        <p><strong><?= e($f['shop']) ?></strong> · <?= str_repeat('★', (int) $f['rating']) . str_repeat('☆', 5 - (int) $f['rating']) ?> · <small><?= e(substr((string) $f['at'], 0, 10)) ?></small><br><?= nl2br(e((string) $f['text'])) ?></p>
      <?php endforeach; ?>
      <?php if (!$fbList): ?><p class="muted">Aucun retour pour l’instant.</p><?php endif; ?>
    </section>
    <?php endif; ?>
  </main>
</div>
<script>
const inheritModes = document.getElementById('payment-modes-inherit');
if (inheritModes) {
  inheritModes.addEventListener('change', () => {
    document.querySelectorAll('input[name="payment_modes[]"]').forEach(input => { input.disabled = inheritModes.checked; });
  });
}
</script>
</body>
</html>
