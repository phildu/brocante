<?php
// Création de boutique — ÉTAPE 3 : nom, adresse et compte de la boutique. Le design se choisit après la création. On y arrive
//   - après un paiement confirmé (retour de Stripe par merci.php, ou lien reçu par e-mail) ;
//   - directement après l'étape 1 pour une formule gratuite (ou un paiement à régler à part).
// Dans tous les cas la boutique est GÉNÉRÉE dès la validation du formulaire, sans validation de l'exploitant, et le client est conduit sur sa boutique.
// La page n'est accessible qu'avec le lien personnel (r = demande, k = jeton secret). Le mot de passe n'est conservé que haché.
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/branding-services.php';

session_start();
if (empty($_SESSION['saas_csrf'])) $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));

$cfg = saas_config();
$origin = saas_origin();
$galleries = array_filter(gallery_list(), static fn (array $g): bool => $g['published'] && empty($g['paused']));
$planName = '';
$req = saas_request_by_link((string) ($_REQUEST['r'] ?? ''), (string) ($_REQUEST['k'] ?? ''));
$offer = $req ? saas_req_offer($req) : null;
if ($req) $planName = $offer['name'];

$errors = [];
$view = 'form';           // form | done | sent | invalid | waiting | closed | cancelled
$generated = null;
$shopNameDefault = trim((string) (!empty($req['shop_name']) ? $req['shop_name'] : ($req['preferred_shop_name'] ?? '')));
$v = [
    'email' => (string) ($req['email'] ?? ''),
    'shop_name' => $shopNameDefault,
    'slug' => !empty($req['slug']) ? (string) $req['slug'] : ($shopNameDefault !== '' ? trim(saas_slug($shopNameDefault), '-') : ''),
    'first_name' => (string) ($req['first_name'] ?? ''),
    'last_name' => (string) ($req['last_name'] ?? ''),
    'phone' => (string) ($req['phone'] ?? ''),
    'about' => (string) (!empty($req['about']) ? $req['about'] : ''),
    'gallery' => (string) ($req['gallery'] ?? ''),
];

if (!$req) {
    $view = 'invalid';
} else {
    $status = (string) $req['status'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($v as $k => $_) {
            $v[$k] = trim((string) ($_POST[$k] ?? ''));
        }
    }
    if ($status === 'approved') {
        $view = 'done';
    } elseif ($status === 'pending') {
        $view = 'sent';
    } elseif ($status === 'awaiting_payment') {
        $view = 'waiting';
    } elseif ($status === 'cancelled') {
        $view = 'cancelled';
    } elseif (!in_array($status, ['draft', 'paid'], true) || ($status === 'draft' && !saas_request_active($req))) {
        $view = 'closed';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals((string) $_SESSION['saas_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            $errors['form'] = 'Le formulaire a expiré : rechargez la page et recommencez.';
        } elseif (($_POST['action'] ?? '') === 'cancel_request' && $status === 'draft') {
            $latest = saas_request_load((string) $req['id']);
            if (!$latest || ($latest['status'] ?? '') !== 'draft' || !saas_request_active($latest)) {
                $view = ($latest['status'] ?? '') === 'cancelled' ? 'cancelled' : 'closed';
            } else {
                $latest['status'] = 'cancelled';
                $latest['cancelled'] = time();
                if (!saas_request_save($latest)) {
                    $errors['form'] = "Impossible d'annuler la demande pour le moment : réessayez.";
                } else {
                    $req = $latest;
                    $view = 'cancelled';
                    saas_event('signup_cancelled', ['ref' => $req['id'], 'email' => $req['email'], 'plan' => $req['plan'], 'actor' => 'client']);
                }
            }
        } else {
            $res = saas_request_complete($req, $_POST);
            if (!$res['ok']) {
                $errors = $res['errors'];
            } else {
                $req = $res['request'];
                $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));
                // Aucune validation de l'exploitant : la boutique est générée tout de suite (formule payante payée, gratuite, ou paiement à régler à part),
                // et le client arrive sur sa boutique.
                try {
                    $generated = saas_generate_shop($req, $origin);
                    $openToken = bin2hex(random_bytes(16));
                    $req = array_merge($req, ['open_token' => $openToken, 'open_exp' => time() + 1800, 'status' => 'approved', 'approved' => time(), 'shop_url' => $generated['shop_url'], 'login' => $generated['login'], 'gallery' => $generated['gallery'], 'error' => '']);
                    saas_request_save($req);
                    saas_event('shop_created', ['slug' => $generated['slug'], 'ref' => $req['id'], 'email' => $req['email'], 'plan' => $req['plan'], 'actor' => 'client', 'note' => !empty($req['social']) ? 'via ' . $req['social']['provider'] : '']);
                    $manual = ($req['payment']['state'] ?? '') === 'manual';
                    if ($cfg['contact'] !== '') {
                        $serviceQuoteLabels = saas_service_quote_names((array) ($req['service_quotes'] ?? []));
                        $pluginLabels = saas_plugin_interest_names((array) ($req['plugins'] ?? []));
                        saas_mail($cfg['contact'], 'Nouvelle boutique créée : ' . $req['shop_name'],
                            "Une boutique vient d'être créée.\n\nBoutique : {$req['shop_name']} ({$generated['shop_url']})\nContact : {$req['first_name']} {$req['last_name']} — {$req['email']} {$req['phone']}\nFormule : $planName"
                            . ($status === 'paid' ? ' — payée' : ($manual ? ' — ⚠ paiement à régler avec le client (' . ($req['payment']['note'] ?? '') . ')' : ' — gratuite'))
                            . ($serviceQuoteLabels ? "\nPrestations demandées sur devis : " . implode(', ', $serviceQuoteLabels) : '')
                            . ($pluginLabels ? "\nPlugins cochés à l'inscription (à activer) : " . implode(', ', $pluginLabels) : '')
                            . "\nDesign initial : " . (SHOP_TEMPLATES[$req['template']]['label'] ?? $req['template']) . "\n" . ($req['gallery'] !== '' ? "Galerie : {$req['gallery']}\n" : '') . "\n$origin/portail/inscriptions.php\n");
                    }
                    $brandingUrl = $origin . '/inscription/identite.php?r=' . rawurlencode((string) $req['id'])
                        . '&k=' . rawurlencode((string) $req['token']);
                    saas_mail((string) $req['email'], 'Votre boutique est prête : finalisez votre identité',
                        "Bonjour {$req['first_name']},\n\nVotre boutique est en ligne : {$generated['shop_url']}\n"
                        . "Vous pouvez maintenant choisir le design de votre boutique, obtenir des suggestions de nom, générer un logo ou commander un nom de domaine :\n$brandingUrl\n\n"
                        . "Ces options sont facultatives.\n");
                    header('Location: ' . $brandingUrl, true, 303);
                    exit;
                } catch (Throwable $e) {
                    // La demande est enregistrée mais la boutique n'a pas pu être créée (adresse prise entre-temps, droits d'écriture…).
                    $req['error'] = 'La boutique n\'a pas pu être créée : ' . $e->getMessage();
                    saas_request_save($req);
                    saas_event('shop_failed', ['slug' => $req['slug'] ?? '', 'ref' => $req['id'], 'email' => $req['email'], 'plan' => $req['plan'], 'note' => $e->getMessage(), 'actor' => 'système']);
                    if ($status === 'paid' && $cfg['contact'] !== '') saas_mail($cfg['contact'], 'Paiement reçu, boutique à finaliser : ' . $req['shop_name'], $req['error'] . "\n\n$origin/portail/inscriptions.php\n");
                    $errors[str_contains($e->getMessage(), 'déjà prise') ? 'slug' : 'form'] = $status === 'paid'
                        ? 'Votre paiement est bien enregistré, mais la création a rencontré un problème (' . $e->getMessage() . '). Vous pouvez corriger l\'adresse puis réessayer ; sinon nous finalisons votre boutique et vous écrivons.'
                        : $e->getMessage() . ' Vous pouvez corriger l\'adresse puis réessayer.';
                }
            }
        }
    }
}

$err = static fn (string $k): string => isset($errors[$k]) ? '<span class="err" role="alert">' . saas_e($errors[$k]) . '</span>' : '';
$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$baseDomain = tenant_base_host($host);
$subdomainSlug = trim(saas_slug($v['shop_name'] !== '' ? $v['shop_name'] : 'ma-boutique'), '-');
if ($subdomainSlug === '') $subdomainSlug = 'ma-boutique';
$paid = $req && ($req['status'] ?? '') === 'paid';
$isPaidOrPayable = $paid || ($req && saas_req_payable($req));
$domainChoice = (array) ($req['branding']['domain_choice'] ?? []);
$activityContext = branding_activity_context((array) ($req['activity_details'] ?? []));
$yearly = $offer && ($offer['billing'] ?? '') === 'year';
$yearSavings = 0;
if ($offer && $yearly) {
    $gridOffers = saas_grid_offers(saas_grid((string) ($offer['gallery'] ?? ''), (string) ($offer['mode'] ?? 'sales')));
    $pricingOffer = array_column($gridOffers, null, 'key')[(string) ($offer['key'] ?? '')] ?? null;
    if ($pricingOffer) $yearSavings = saas_offer_saving_cents($pricingOffer);
}
$social = $req && !empty($req['social']);
$link = $req ? '?r=' . saas_e($req['id']) . '&amp;k=' . saas_e($req['token']) : '';

saas_page_start('Ma boutique — ' . $cfg['name'], 'Dernière étape : le nom et le compte de votre boutique.', 'inscription');
?>
<div class="wrap">
  <div class="page-title"><p class="eyebrow">Création de boutique</p><h1><?= $view === 'done' ? 'Votre boutique est prête' : ($view === 'cancelled' ? 'Demande annulée' : 'Ma boutique') ?></h1>
    <ol class="stepper" aria-label="Étapes">
      <li class="is-done"><b>✓</b> Ma formule</li>
      <li class="is-done"><b>✓</b> Préconfiguration</li>
      <li class="is-done"><b>✓</b> Design</li>
      <?php if ($isPaidOrPayable): ?><li class="is-done"><b>✓</b> Paiement</li><?php endif; ?>
      <li class="<?= $view === 'form' ? 'is-current' : 'is-done' ?>"<?= $view === 'form' ? ' aria-current="step"' : '' ?>>
        <b><?= $view === 'form' ? ($isPaidOrPayable ? '5' : '4') : '✓' ?></b> Ma boutique
      </li>
    </ol>
    <?php if ($view === 'form'): ?><p class="lede"><?= $paid ? 'Votre paiement est confirmé. ' : '' ?>Choisissez le nom et les accès de votre boutique. Elle sera créée dès validation avec un design par défaut, que vous pourrez personnaliser juste après.</p><?php endif; ?></div>

  <?php if ($view === 'invalid'): ?>
    <div class="form-card"><div class="notice error" role="alert"><strong>Ce lien n'est pas valable.</strong> Il est peut-être incomplet ou a expiré.</div>
      <p><a class="btn" href="/inscription/">Recommencer</a></p></div>

  <?php elseif ($view === 'closed'): ?>
    <div class="form-card"><div class="notice error" role="alert"><strong>Cette demande n'est plus ouverte.</strong> Elle a expiré (24 heures sans suite) ou a été refusée.</div>
      <p><a class="btn" href="/inscription/">Refaire une demande</a></p></div>

  <?php elseif ($view === 'cancelled'): ?>
    <div class="form-card"><div class="notice ok" role="status"><strong>Demande annulée.</strong> Cette demande non payée est close et son adresse e-mail est libérée.</div>
      <p><a class="btn" href="/inscription/">Recommencer une création</a></p></div>

  <?php elseif ($view === 'waiting'): ?>
    <div class="form-card"><div class="notice" role="status"><strong>Le paiement n'est pas encore confirmé.</strong> Terminez-le sur la page de Stripe, ou reprenez-le : cette page s'ouvrira ensuite.</div>
      <p><a class="btn" href="/inscription/?annule=<?= saas_e($req['id']) ?>">Reprendre le paiement</a></p>
      <p class="meta">Si vous ne souhaitez pas poursuivre, cette demande se libérera automatiquement après 24 heures.</p></div>

  <?php elseif ($view === 'sent'): ?>
    <div class="form-card"><div class="notice ok" role="status"><strong>Demande reçue, merci !</strong></div>
      <p>Votre demande pour <strong><?= saas_e($req['shop_name']) ?></strong> est bien enregistrée. Nous vous écrivons à <?= saas_e($req['email']) ?> dès que votre boutique est prête.</p>
      <?php if (($req['payment']['state'] ?? '') === 'manual'): ?><div class="notice" role="status">Le paiement de votre formule se règle avec nous : nous vous écrivons à ce sujet.</div><?php endif; ?>
      <p style="margin-top:18px"><a class="btn" href="/">Retour à l'accueil</a></p></div>

  <?php elseif ($view === 'done'): $shopUrl = (string) ($req['shop_url'] ?? ''); ?>
    <div class="form-card"><div class="notice ok" role="status"><strong>Bravo, votre boutique est en ligne.</strong></div>
      <p><strong><?= saas_e($req['shop_name']) ?></strong> est prête.</p>
      <p><a class="btn" href="<?= saas_e($shopUrl) ?>">Voir ma boutique</a>
         <a class="btn" style="margin-left:8px" href="<?= saas_e(rtrim($shopUrl, '/') . (!empty($req['open_token']) && (int) ($req['open_exp'] ?? 0) > time() ? '/admin/ouvrir.php?r=' . rawurlencode((string) $req['id']) . '&t=' . rawurlencode((string) $req['open_token']) : '/admin/login.php')) ?>">Ouvrir mon administration</a></p>
      <?php if (!empty($req['social'])): ?><p class="lede">Sur la page de connexion de l’administration, cliquez sur le bouton <strong><?= saas_e(OAUTH_PROVIDERS[$req['social']['provider']]['label'] ?? 'Google') ?></strong> avec l’adresse <strong><?= saas_e($req['email']) ?></strong>. La connexion faite pour l’inscription ouvre seulement votre compte client.</p><?php endif; ?>
      <p><a class="btn ghost" href="/inscription/identite.php<?= $link ?>">Choisir le design et personnaliser ma boutique</a></p>
      <p class="lede" style="margin-top:16px">Identifiant : <strong><?= saas_e($req['login'] ?? $req['email']) ?></strong> — mot de passe : celui que vous venez de choisir. Nous vous avons aussi écrit ces liens.</p></div>

  <?php else: ?>
    <div class="step2-layout">
      <div class="step2-main">
        <form class="form-card" method="post" action="/inscription/creer.php" novalidate>
          <?php if (isset($errors['form'])): ?><div class="notice error" role="alert"><?= saas_e($errors['form']) ?></div><?php elseif ($errors): ?><div class="notice error" role="alert"><strong>La création n'a pas pu aboutir :</strong><ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($errors as $m): ?><li><?= saas_e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
          <?php if (($req['payment']['state'] ?? '') === 'manual'): ?><div class="notice" role="status">Le paiement en ligne n'est pas disponible pour le moment : votre boutique est créée tout de suite et nous vous écrivons pour régler votre formule <strong><?= saas_e($planName) ?></strong>.</div><?php endif; ?>
          <?php if ($offer): ?><div class="notice" role="status">Votre offre : <strong><?= saas_e($offer['name']) ?></strong> — <?= saas_e(saas_price_text($offer['cents'], $offer['billing'])) ?><?= $offer['billing'] === 'year' ? ' (facturation annuelle)' : '' ?><?= $offer['max_items'] ? ' · jusqu\'à ' . (int) $offer['max_items'] . ' articles' : '' ?>.</div><?php endif; ?>
          <input type="hidden" name="csrf" value="<?= saas_e($_SESSION['saas_csrf']) ?>"><input type="hidden" name="r" value="<?= saas_e($req['id']) ?>"><input type="hidden" name="k" value="<?= saas_e($req['token']) ?>">

          <div class="form-grid">
            <label class="field full<?= isset($errors['email']) ? ' has-error' : '' ?>"><span>E-mail (identifiant d’administration)</span>
              <input type="email" name="email" value="<?= saas_e(!empty($req['email']) ? (string) $req['email'] : $v['email']) ?>" required maxlength="254" autocomplete="email"<?= !empty($req['email']) ? ' readonly' : '' ?>>
              <?= $err('email') ?>
              <?php if (($req['contact_method'] ?? '') === 'google'): ?><small>Adresse vérifiée avec Google ; elle servira d’identifiant pour l’administration de votre boutique.</small><?php elseif (!empty($req['email'])): ?><small>Adresse fournie à l’étape précédente ; elle servira d’identifiant pour l’administration de votre boutique.</small><?php else: ?><small>Cette adresse servira d’identifiant pour l’administration et les informations relatives à votre boutique.</small><?php endif; ?>
            </label>
            <label class="field full<?= isset($errors['shop_name']) ? ' has-error' : '' ?>"><span>Nom de votre boutique</span>
              <input type="text" name="shop_name" value="<?= saas_e($v['shop_name']) ?>" required maxlength="60" autocomplete="organization" placeholder="La Brocante du Petit Chalet"><?= $err('shop_name') ?></label>
            <label class="field full<?= isset($errors['slug']) ? ' has-error' : '' ?>"><span>Adresse de votre boutique</span>
              <span class="slug-row"><span class="pre"><?= saas_e($host) ?>/</span><input type="text" name="slug" value="<?= saas_e($v['slug']) ?>" maxlength="40" pattern="[a-z0-9][a-z0-9\-]{2,39}" placeholder="laissez vide : tiré du nom" autocapitalize="none" spellcheck="false"></span>
              <small>Lettres minuscules, chiffres et tirets.</small><?= $err('slug') ?></label>

            <label class="field<?= isset($errors['first_name']) ? ' has-error' : '' ?>"><span>Prénom</span><input type="text" name="first_name" value="<?= saas_e($v['first_name']) ?>" required maxlength="60" autocomplete="given-name"><?= $err('first_name') ?></label>
            <label class="field"><span>Nom</span><input type="text" name="last_name" value="<?= saas_e($v['last_name']) ?>" maxlength="60" autocomplete="family-name"></label>
            <label class="field"><span>Téléphone (facultatif)</span><input type="tel" name="phone" value="<?= saas_e($v['phone']) ?>" maxlength="30" autocomplete="tel"></label>
            <?php if ($social): ?>
              <p class="field full oauth-note" style="margin:0">Vous vous connecterez avec <strong><?= saas_e(OAUTH_PROVIDERS[$req['social']['provider']]['label'] ?? 'votre compte') ?></strong> : pas de mot de passe à retenir. Vous pouvez en ajouter un si vous le souhaitez (facultatif).</p>
            <?php endif; ?>
            <label class="field<?= isset($errors['password']) ? ' has-error' : '' ?>"><span>Mot de passe (<?= $social ? 'facultatif, 8 caractères au moins' : '8 caractères au moins' ?>)</span><input type="password" name="password"<?= $social ? '' : ' required' ?> minlength="8" autocomplete="new-password"><?= $err('password') ?></label>
            <label class="field<?= isset($errors['password2']) ? ' has-error' : '' ?>"><span>Confirmer le mot de passe</span><input type="password" name="password2"<?= $social ? '' : ' required' ?> minlength="8" autocomplete="new-password"><?= $err('password2') ?></label>
            <label class="field full password-visibility"><input type="checkbox" id="show-passwords"><span>Afficher les mots de passe</span></label>
            <label class="field full"><span>Que vendez-vous ? (facultatif)</span><textarea name="about" maxlength="400" placeholder="Vêtements de seconde main, objets de brocante, livres anciens…"><?= saas_e($v['about']) ?></textarea></label>
            <?php if (($req['ai_mode'] ?? 'with') !== 'without' && autofeed_api_key() !== '' && autofeed_key((array) ($req['activity_details'] ?? [])) !== ''): ?>
              <label class="field full password-visibility" style="grid-template-columns:auto 1fr;align-items:start;gap:10px">
                <input type="checkbox" name="autofeed" value="1"<?= !empty($_POST['autofeed']) || (!empty($req['autofeed']) && $_SERVER['REQUEST_METHOD'] !== 'POST') ? ' checked' : '' ?>>
                <span><strong>Pré-remplir ma boutique (facultatif)</strong><br><small>Une image de bandeau et <?= (int) AUTOFEED_PRODUCTS ?> articles d’exemple, générés par IA selon votre activité. Vous pourrez les modifier, les supprimer ou régénérer les images depuis votre administration.</small></span>
              </label>
            <?php endif; ?>

            <?php if (!empty($req['gallery']) && isset($galleries[$req['gallery']])): ?>
              <p class="field full oauth-note" style="margin:0">Votre boutique sera créée sans être ajoutée automatiquement à <strong><?= saas_e($galleries[$req['gallery']]['name']) ?></strong>. Après sa création, vous pourrez tester son affichage dans cette galerie et décider si vous souhaitez la rejoindre.</p>
            <?php endif; ?>
            <?php if (empty($req['terms_accepted'])): ?>
              <?php
            $creerType = !empty($req['gallery']) && isset($galleries[$req['gallery']])
                ? (string) ($galleries[$req['gallery']]['type'] ?? '')
                : (string) ($req['place_type'] ?? '');
            $creerWord = $creerType === 'vide-grenier' ? 'stand' : 'boutique';
            ?>
            <label class="field full<?= isset($errors['terms']) ? ' has-error' : '' ?>" style="grid-template-columns:auto 1fr;align-items:start;gap:10px">
                <input type="checkbox" name="terms" value="1" style="width:auto;margin-top:4px"<?= !empty($_POST['terms']) ? ' checked' : '' ?>>
                <span style="font-weight:400">J'accepte d'être contacté(e) pour la création de mon <?= $creerWord ?> et que mes informations servent à ce seul usage.<?= $err('terms') ?></span>
              </label>
            <?php endif; ?>
          </div>
          <p style="margin:22px 0 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center"><button class="btn" type="submit">Créer mon <?= $creerWord ?></button>
            <?php if ($status === 'draft'): ?><a class="btn ghost" href="/inscription/design.php?r=<?= rawurlencode((string) $req['id']) ?>&amp;k=<?= rawurlencode((string) $req['token']) ?>">← Retour au design</a><?php endif; ?></p>
        </form>
        <?php if ($status === 'draft'): ?>
          <form class="form-card" method="post" action="/inscription/creer.php" onsubmit="return confirm('Annuler cette demande non payée ? Vous pourrez recommencer immédiatement avec cette adresse e-mail.');" style="margin-top:12px">
            <input type="hidden" name="csrf" value="<?= saas_e($_SESSION['saas_csrf']) ?>">
            <input type="hidden" name="r" value="<?= saas_e($req['id']) ?>">
            <input type="hidden" name="k" value="<?= saas_e($req['token']) ?>">
            <input type="hidden" name="action" value="cancel_request">
            <button class="btn ghost" type="submit">Annuler cette demande</button>
          </form>
        <?php endif; ?>
      </div>
      <aside class="step2-summary form-card" aria-labelledby="step3-summary-title">
        <h2 id="step3-summary-title">Récapitulatif</h2>
        <ol class="step2-summary-steps">
          <li><span>1</span><div><strong>Formule</strong><small><?= saas_e((string) $offer['name']) ?><?= ($offer['mode'] ?? 'sales') === 'showcase' ? ' · vitrine' : ' · vente en ligne' ?></small></div></li>
          <li><span>2</span><div><strong>Nom et domaine</strong>
            <small id="step3-summary-shop-name"><?= saas_e($v['shop_name'] !== '' ? $v['shop_name'] : 'À préciser') ?></small>
            <?php if (!empty($activityContext)): ?><small><?= saas_e($activityContext) ?></small><?php endif; ?>
            <?php if (!empty($req['location'])): ?><small><?= saas_e((string) $req['location']) ?></small><?php endif; ?>
            <small id="step3-summary-domain"><?= !empty($domainChoice['domain']) ? 'Domaine : ' . saas_e((string) $domainChoice['domain']) : 'Sous-domaine : <span id="step3-summary-subdomain">' . saas_e($subdomainSlug . '.' . $baseDomain) . '</span>' ?></small>
          </div></li>
          <li><span>3</span><div><strong>Design</strong><small><?= saas_e(SHOP_TEMPLATES[$req['template'] ?? SHOP_TEMPLATE_DEFAULT]['label']) ?></small></div></li>
          <?php if ($isPaidOrPayable): ?>
            <li><span>4</span><div><strong>Paiement</strong><small>Confirmé via Stripe</small></div></li>
            <li class="is-current"><span>5</span><div><strong>Création de la boutique</strong><small>Accès et mot de passe</small></div></li>
          <?php else: ?>
            <li class="is-current"><span>4</span><div><strong>Création de la boutique</strong><small>Accès et mot de passe</small></div></li>
          <?php endif; ?>
        </ol>
        <dl class="step2-total">
          <div><dt>Formule</dt><dd><?= (int) ($offer['cents'] ?? 0) === 0 ? 'Gratuit' : saas_e(saas_price_text((int) $offer['cents'], (string) $offer['billing'])) ?></dd></div>
          <?php if ($yearly && (int) ($offer['cents'] ?? 0) > 0): ?><div><dt>Équivalent mensuel</dt><dd><?= saas_e(saas_money((int) round((int) $offer['cents'] / 12))) ?> / mois</dd></div><?php endif; ?>
          <?php if ($yearSavings > 0): ?><div><dt>Économie annuelle</dt><dd><?= saas_e(saas_money($yearSavings)) ?> / an</dd></div><?php endif; ?>
          <div class="step2-grand-total"><dt>Total payé / dû</dt><dd><?= (int) ($offer['cents'] ?? 0) === 0 ? 'Gratuit (0 €)' : saas_e(saas_price_text((int) $offer['cents'], (string) $offer['billing'])) ?></dd></div>
        </dl>
        <?php if (!empty($domainChoice['domain'])): ?>
          <p class="meta">Domaine préselectionné : <strong><?= saas_e((string) $domainChoice['domain']) ?></strong>. L’achat sera proposé séparément après la création.</p>
        <?php else: ?>
          <p class="meta">Votre boutique utilisera l’adresse incluse de la plateforme. Vous pourrez ajouter un domaine personnalisé plus tard.</p>
        <?php endif; ?>
      </aside>
    </div>
  <?php endif; ?>
</div>
<script>
  (function () {
    var toggle = document.getElementById('show-passwords');
    var passwords = document.querySelectorAll('input[name="password"], input[name="password2"]');
    toggle.addEventListener('change', function () {
      passwords.forEach(function (input) {
        input.type = toggle.checked ? 'text' : 'password';
      });
    });

    var baseDomain = <?= json_encode($baseDomain) ?>;
    function slugify(text) {
      var s = (text || '').toString().toLowerCase().trim()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
      return s || 'ma-boutique';
    }
    var shopInput = document.querySelector('input[name="shop_name"]');
    var summaryName = document.getElementById('step3-summary-shop-name');
    var summarySubdomain = document.getElementById('step3-summary-subdomain');
    var slugInput = document.querySelector('input[name="slug"]');
    var slugTouched = !!(slugInput && slugInput.value && slugInput.value !== slugify(shopInput && shopInput.value));
    if (slugInput) slugInput.addEventListener('input', function () { slugTouched = slugInput.value !== ''; });
    if (shopInput) {
      shopInput.addEventListener('input', function () {
        if (slugInput && !slugTouched) slugInput.value = shopInput.value.trim() ? slugify(shopInput.value) : '';
        if (summaryName) summaryName.textContent = shopInput.value || 'À préciser';
        if (summarySubdomain) summarySubdomain.textContent = slugify(shopInput.value) + '.' + baseDomain;
      });
    }
  })();
</script>
<?php if ($errors): ?><script>(function(){var f=document.querySelector('.has-error input,.has-error textarea,.has-error select');if(f){f.scrollIntoView({block:'center'});f.focus({preventScroll:true});}})();</script><?php endif; ?>
<?php saas_page_end(); ?>
