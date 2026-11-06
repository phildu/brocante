<?php
require_once __DIR__ . '/../includes/branding-services.php';
require_once __DIR__ . '/../includes/gallery-offers.php';

session_start();
if (empty($_SESSION['branding_csrf'])) $_SESSION['branding_csrf'] = bin2hex(random_bytes(24));

$request = saas_request_by_link((string) ($_REQUEST['r'] ?? ''), (string) ($_REQUEST['k'] ?? ''));
$errors = [];
$notice = '';
$names = [];
$logo = '';
$purchase = [];
$origin = saas_origin();
$e = static fn ($value): string => saas_e($value);

if (!$request || ($request['status'] ?? '') !== 'approved' || empty($request['slug'])) {
    http_response_code(404);
    saas_page_start('Finaliser ma boutique', 'Le lien de finalisation est invalide.', 'inscription');
    echo '<div class="wrap"><div class="form-card"><div class="notice error" role="alert">Ce lien de finalisation est invalide ou la boutique n’est pas encore créée.</div></div></div>';
    saas_page_end();
    exit;
}

$branding = (array) ($request['branding'] ?? []);
$names = (array) ($branding['name_suggestions'] ?? []);
$logo = (string) ($branding['logo'] ?? '');
$purchase = (array) ($branding['domain_purchase'] ?? []);
$csrf = (string) $_SESSION['branding_csrf'];

if (isset($_GET['domaine'])) {
    $sessionId = (string) $_GET['domaine'];
    if (!preg_match('/^cs_[A-Za-z0-9_]{6,200}$/', $sessionId)
        || (string) ($purchase['stripe_session'] ?? '') !== $sessionId) {
        $errors[] = 'Session de paiement du domaine invalide.';
    } else {
        try {
            $session = saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
            $purchase = branding_apply_domain_checkout($session);
            $notice = ($purchase['state'] ?? '') === 'ordered'
                ? 'Commande transmise à OVH. La mise en service du domaine peut prendre un peu de temps.'
                : 'Le paiement a été confirmé ; la commande OVH est en cours de vérification.';
            $request = saas_request_load((string) $request['id']) ?? $request;
        } catch (RuntimeException $ex) {
            error_log('branding domain return: ' . $ex->getMessage());
            $errors[] = $ex->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Le formulaire a expiré. Rechargez cette page et réessayez.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'apply_template') {
                $template = (string) ($_POST['template'] ?? '');
                branding_apply_shop_template((string) $request['slug'], $template);
                unset($_SESSION['modele_apercu']);
                $request['template'] = $template;
                branding_save_request($request);
                $notice = 'Le design « ' . SHOP_TEMPLATES[$template]['label'] . ' » a été appliqué à votre boutique.';
            } elseif ($action === 'join_gallery' || $action === 'leave_gallery') {
                $gallerySlug = strtolower(trim((string) ($request['gallery'] ?? '')));
                $shopSlug = (string) $request['slug'];
                $gallery = gallery_slug_valid($gallerySlug) ? gallery_load($gallerySlug) : null;
                if (!$gallery || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $shopSlug)
                    || !is_dir(dirname(__DIR__) . '/tenants/' . $shopSlug)) {
                    throw new RuntimeException('La galerie ou la boutique associée à cette demande est introuvable.');
                }
                $members = (array) $gallery['members'];
                $isMember = false;
                foreach ($members as $member) {
                    if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === $shopSlug) {
                        $isMember = true;
                        break;
                    }
                }
                if ($action === 'join_gallery' && !$isMember) {
                    if (!go_is_live($gallery)) throw new RuntimeException('Cette galerie n’est plus ouverte aux nouvelles adhésions.');
                    $memberLimit = go_member_limit($gallery);
                    if ($memberLimit !== null && count($members) >= $memberLimit) {
                        throw new RuntimeException('La galerie a atteint la limite de commerces de son offre.');
                    }
                    $members[] = ['type' => 'local', 'slug' => $shopSlug, 'featured' => false];
                } elseif ($action === 'leave_gallery' && $isMember) {
                    $members = array_values(array_filter($members, static fn (array $member): bool =>
                        !(($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === $shopSlug)));
                }
                if ($members !== $gallery['members']) {
                    $gallery['members'] = $members;
                    if (!gallery_save($gallery)) throw new RuntimeException('Impossible d’enregistrer la présence dans la galerie. Réessayez.');
                }
                $notice = $action === 'join_gallery'
                    ? 'Votre boutique a rejoint la galerie « ' . $gallery['name'] . ' ».'
                    : 'Votre boutique a été retirée de la galerie « ' . $gallery['name'] . ' ».';
            } elseif ($action === 'suggest_names') {
                branding_rate_limit((string) $request['id'], 'names', 5);
                $about = trim((string) ($_POST['about'] ?? $request['about'] ?? ''));
                if (mb_strlen($about) < 10) throw new InvalidArgumentException('Décrivez votre activité en une ou deux phrases (10 caractères minimum).');
                $request['about'] = mb_substr($about, 0, 400);
                $names = branding_generate_names($request);
                $request['branding']['name_suggestions'] = $names;
                branding_save_request($request);
                $notice = 'Voici des idées de noms à vérifier et à choisir librement.';
            } elseif ($action === 'apply_name') {
                $name = trim((string) ($_POST['shop_name'] ?? ''));
                branding_apply_shop_name((string) $request['slug'], $name);
                $request['shop_name'] = $name;
                branding_save_request($request);
                $notice = 'Le nom de la boutique a été mis à jour. Son adresse reste inchangée.';
            } elseif ($action === 'generate_logo') {
                branding_rate_limit((string) $request['id'], 'logo', 2);
                $logo = branding_generate_logo($request);
                $request['branding']['logo'] = $logo;
                branding_save_request($request);
                $notice = 'Logo généré et installé sur votre boutique.';
            } elseif ($action === 'quote_domain') {
                branding_rate_limit((string) $request['id'], 'domain_quote', 10);
                $owner = [
                    'first_name' => mb_substr(trim((string) ($_POST['owner_first_name'] ?? '')), 0, 60),
                    'last_name' => mb_substr(trim((string) ($_POST['owner_last_name'] ?? '')), 0, 60),
                    'organization' => mb_substr(trim((string) ($_POST['owner_organization'] ?? '')), 0, 100),
                    'legal_form' => trim((string) ($_POST['owner_legal_form'] ?? 'individual')),
                    'address' => mb_substr(trim((string) ($_POST['owner_address'] ?? '')), 0, 160),
                    'postal_code' => mb_substr(trim((string) ($_POST['owner_postal_code'] ?? '')), 0, 20),
                    'city' => mb_substr(trim((string) ($_POST['owner_city'] ?? '')), 0, 80),
                    'country' => strtoupper(trim((string) ($_POST['owner_country'] ?? 'FR'))),
                    'phone' => mb_substr(trim((string) ($_POST['owner_phone'] ?? '')), 0, 30),
                ];
                foreach ([
                    'first_name' => 'Indiquez le prénom du titulaire.',
                    'last_name' => 'Indiquez le nom du titulaire.',
                    'address' => 'Indiquez l’adresse du titulaire.',
                    'postal_code' => 'Indiquez le code postal du titulaire.',
                    'city' => 'Indiquez la ville du titulaire.',
                    'phone' => 'Indiquez un numéro de téléphone.',
                ] as $field => $message) {
                    if ($owner[$field] === '') throw new InvalidArgumentException($message);
                }
                if (!preg_match('/^[A-Z]{2}$/', $owner['country'])) throw new InvalidArgumentException('Le pays doit être un code ISO à deux lettres.');
                if (!in_array($owner['legal_form'], ['administration', 'association', 'corporation', 'individual', 'other', 'personalcorporation'], true)) {
                    throw new InvalidArgumentException('Choisissez la forme juridique du titulaire.');
                }
                if ($owner['legal_form'] !== 'individual' && $owner['organization'] === '') {
                    throw new InvalidArgumentException('Indiquez le nom de l’entreprise ou de l’organisation titulaire.');
                }
                $owner['phone'] = branding_normalize_owner_phone($owner['phone'], $owner['country']);
                if (empty($_POST['owner_legal_age']) || empty($_POST['domain_terms']) || empty($_POST['waive_retraction'])) {
                    throw new InvalidArgumentException('Confirmez l’éligibilité du titulaire et acceptez les conditions avant de demander un devis.');
                }
                $quote = branding_ovh_quote((string) ($_POST['domain'] ?? ''));
                $request['branding']['domain_purchase'] = [
                    'state' => 'quoted',
                    'quote' => $quote,
                    'owner' => $owner,
                    'consents' => ['legal_age' => true, 'domain_terms' => true, 'waive_retraction' => true, 'accepted_at' => time()],
                    'created_at' => time(),
                ];
                branding_save_request($request);
                $purchase = $request['branding']['domain_purchase'];
                $notice = 'Domaine disponible. Le devis OVH est valable 24 heures ; le titulaire indiqué sera le propriétaire légal.';
            } elseif ($action === 'pay_domain') {
                $purchase = (array) ($request['branding']['domain_purchase'] ?? []);
                $quote = (array) ($purchase['quote'] ?? []);
                if (($purchase['state'] ?? '') !== 'quoted' || time() - (int) ($purchase['created_at'] ?? 0) > 86400) {
                    throw new RuntimeException('Le devis est expiré. Vérifiez à nouveau la disponibilité du domaine.');
                }
                if (!saas_stripe_configured()) throw new RuntimeException('Le paiement Stripe de la plateforme n’est pas configuré.');
                $contactId = (string) ($purchase['ovh_contact_id'] ?? '');
                if ($contactId === '') {
                    $contactId = branding_ovh_prepare_domain($request, $purchase);
                    $request['branding']['domain_purchase']['ovh_contact_id'] = $contactId;
                    branding_save_request($request);
                }
                $session = branding_stripe_domain_checkout($request, $quote, $origin);
                $request['branding']['domain_purchase']['state'] = 'awaiting_payment';
                $request['branding']['domain_purchase']['stripe_session'] = (string) ($session['id'] ?? '');
                $request['branding']['domain_purchase']['stripe_url'] = (string) ($session['url'] ?? '');
                if ($request['branding']['domain_purchase']['stripe_session'] === '' || $request['branding']['domain_purchase']['stripe_url'] === '') {
                    throw new RuntimeException('Stripe n’a pas renvoyé de lien de paiement exploitable.');
                }
                branding_save_request($request);
                header('Location: ' . $request['branding']['domain_purchase']['stripe_url'], true, 303);
                exit;
            } elseif ($action === 'open_payment') {
                $url = (string) ($request['branding']['domain_purchase']['stripe_url'] ?? '');
                if (!preg_match('#^https://checkout\.stripe\.com/#', $url)) throw new RuntimeException('Lien Stripe introuvable.');
                header('Location: ' . $url, true, 303);
                exit;
            } elseif ($action === 'change_domain') {
                $purchase = (array) ($request['branding']['domain_purchase'] ?? []);
                if (($purchase['state'] ?? '') !== 'awaiting_payment') {
                    throw new RuntimeException('Cette commande ne peut plus être remplacée.');
                }
                $sessionId = (string) ($purchase['stripe_session'] ?? '');
                if ($sessionId !== '') saas_stripe_request('POST', '/checkout/sessions/' . rawurlencode($sessionId) . '/expire');
                unset($request['branding']['domain_purchase']);
                branding_save_request($request);
                $purchase = [];
                $notice = 'Le paiement précédent a été fermé. Vous pouvez demander un nouveau devis.';
            } elseif ($action === 'request_quotes') {
                $catalog = saas_service_quotes_active();
                $picked = $_POST['service_quotes'] ?? [];
                if (!is_array($picked)) throw new InvalidArgumentException('La sélection des prestations est invalide.');
                $services = [];
                foreach ($picked as $key) {
                    if (!is_string($key) || !isset($catalog[$key])) throw new InvalidArgumentException('Une prestation sélectionnée n’est pas reconnue.');
                    $services[] = $key;
                }
                $services = array_values(array_unique($services));
                if (!$services) throw new InvalidArgumentException('Choisissez au moins une prestation pour votre demande de devis.');
                $message = mb_substr(trim((string) ($_POST['service_quotes_message'] ?? '')), 0, 1200);
                if (mb_strlen($message) < 10) throw new InvalidArgumentException('Précisez votre besoin en une phrase (10 caractères minimum).');
                $quoteEmail = mb_strtolower(trim((string) ($_POST['quote_email'] ?? $request['email'] ?? '')));
                if (!filter_var($quoteEmail, FILTER_VALIDATE_EMAIL) || strlen($quoteEmail) > 254) {
                    throw new InvalidArgumentException('Indiquez une adresse e-mail valide pour recevoir le devis.');
                }
                $quotePhone = mb_substr(trim((string) ($_POST['quote_phone'] ?? '')), 0, 30);
                $request['service_quotes'] = $services;
                $request['service_quotes_message'] = $message;
                $request['service_quotes_email'] = $quoteEmail;
                $request['service_quotes_phone'] = $quotePhone;
                $request['service_quotes_at'] = time();
                branding_save_request($request);
                $labels = saas_service_quote_names($services);
                $cfg = saas_config();
                if ($cfg['contact'] !== '') {
                    saas_mail($cfg['contact'], 'Demande de devis : ' . $request['shop_name'] . ' (' . $quoteEmail . ')',
                        "Une boutique demande un devis après sa création.\n\n"
                        . 'Boutique : ' . $request['shop_name'] . "\n"
                        . 'Adresse : ' . (string) ($request['shop_url'] ?? '') . "\n"
                        . 'Contact : ' . $quoteEmail . ($quotePhone !== '' ? ' · ' . $quotePhone : '') . "\n"
                        . 'Prestations : ' . implode(', ', $labels) . "\n\n"
                        . "Message :\n" . $message . "\n\n"
                        . $origin . "/portail/services-devis.php\n");
                }
                saas_event('quote_requested', ['ref' => (string) $request['id'], 'email' => $quoteEmail, 'plan' => (string) ($request['plan'] ?? ''),
                    'gallery' => (string) ($request['gallery'] ?? ''), 'actor' => 'client', 'note' => implode(', ', $labels)]);
                $notice = 'Demande de devis envoyée : nous revenons vers vous à l’adresse ' . $quoteEmail . ' sous 2 jours ouvrés.';
            } else {
                throw new InvalidArgumentException('Action inconnue.');
            }
        } catch (InvalidArgumentException | RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}

$request = saas_request_load((string) $request['id']) ?? $request;
$branding = (array) ($request['branding'] ?? []);
$names = (array) ($branding['name_suggestions'] ?? $names);
$logo = (string) ($branding['logo'] ?? $logo);
$purchase = (array) ($branding['domain_purchase'] ?? $purchase);
$currentTemplate = shop_template_valid($request['template'] ?? null) ? (string) $request['template'] : SHOP_TEMPLATE_DEFAULT;
$quote = (array) ($purchase['quote'] ?? []);
$owner = (array) ($purchase['owner'] ?? []);
$amount = (int) ($quote['amount_cents'] ?? 0);
$margin = (int) ($quote['amount_cents'] ?? 0) - (int) ($quote['ovh_cents'] ?? 0);
$shopUrl = (string) ($request['shop_url'] ?? '');
$link = '?r=' . rawurlencode((string) $request['id']) . '&k=' . rawurlencode((string) $request['token']);
$gallerySlug = strtolower(trim((string) ($request['gallery'] ?? '')));
$membershipGallery = gallery_slug_valid($gallerySlug) ? gallery_load($gallerySlug) : null;
$galleryLive = $membershipGallery && go_is_live($membershipGallery);
$galleryMember = false;
if ($membershipGallery) {
    foreach ($membershipGallery['members'] as $member) {
        if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === (string) $request['slug']) {
            $galleryMember = true;
            break;
        }
    }
}
$galleryTestUrl = $membershipGallery
    ? $origin . '/galerie/' . rawurlencode($gallerySlug) . '/?test_shop=' . rawurlencode((string) $request['slug'])
        . '&r=' . rawurlencode((string) $request['id']) . '&k=' . rawurlencode((string) $request['token'])
    : '';

saas_page_start('Nom, logo et domaine — ' . saas_config()['name'], 'Personnalisez votre boutique et choisissez éventuellement un nom de domaine.', 'inscription');
?>
<style>
  .brand-steps { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; }
  .brand-card.brand-design { grid-column:1/-1; }
  .brand-card { background:#fff; border:1px solid #e7e0d7; border-radius:14px; padding:22px; }
  .brand-card h2 { margin:0 0 8px; font-size:1.35rem; }
  .brand-card p { color:#625d57; line-height:1.55; }
  .brand-form { display:grid; gap:12px; margin-top:18px; }
  .brand-form label { display:grid; gap:5px; font-size:.9rem; }
  .brand-form input, .brand-form select { width:100%; min-height:42px; border:1px solid #d5cec5; border-radius:7px; padding:8px 10px; font:inherit; }
  .brand-form textarea { width:100%; border:1px solid #d5cec5; border-radius:7px; padding:9px 10px; font:inherit; resize:vertical; }
  .quote-services { border:0; margin:0; padding:0; min-width:0; }
  .quote-services legend { padding:0; margin-bottom:8px; font-size:.9rem; font-weight:600; color:#221f1a; }
  .brand-form input[type=checkbox] { width:auto; min-height:auto; }
  .brand-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
  .brand-names { display:flex; flex-wrap:wrap; gap:8px; margin:14px 0; }
  .brand-name { padding:7px 11px; border:1px solid #d5cec5; border-radius:18px; background:#faf8f5; }
  .brand-logo { display:block; max-width:240px; max-height:180px; object-fit:contain; margin:14px auto; }
  .brand-price { padding:14px; background:#f5f1eb; border-radius:8px; }
  .brand-design-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin-top:14px; }
  .brand-design-option { display:grid; align-content:start; gap:8px; }
  .brand-design-option label { position:relative; display:block; cursor:pointer; }
  .brand-design-option input { position:absolute; width:1px; height:1px; min-height:1px; padding:0; border:0; overflow:hidden; clip-path:inset(50%); white-space:nowrap; opacity:0; }
  .brand-design-card { display:grid; gap:7px; height:100%; padding:10px; border:2px solid #e7e0d7; border-radius:10px; background:var(--p-bg); color:var(--p-ink); }
  .brand-design-option input:checked + .brand-design-card { border-color:var(--p-accent); box-shadow:0 0 0 3px color-mix(in srgb,var(--p-accent) 22%,transparent); }
  .brand-design-option input:focus-visible + .brand-design-card { outline:3px solid var(--p-accent); outline-offset:2px; }
  .brand-design-mock { display:grid; gap:6px; min-height:82px; padding:11px; border-radius:7px; background:color-mix(in srgb,var(--p-bg),var(--p-ink) 5%); }
  .brand-design-mock strong { font-size:1.5rem; line-height:1; }
  .brand-design-mock i { display:block; width:72%; height:5px; border-radius:4px; background:color-mix(in srgb,var(--p-ink),transparent 72%); }
  .brand-design-mock i.short { width:48%; }
  .brand-design-mock b { display:block; width:44px; height:13px; border-radius:8px; background:var(--p-accent); }
  .brand-design-card > small { color:inherit; line-height:1.4; }
  .brand-design-option .tpl-preview { padding:0; }
  @media(max-width:540px) { .brand-grid { grid-template-columns:1fr; } }
</style>
<div class="wrap">
  <div class="page-title">
    <p class="eyebrow">Votre boutique est créée</p>
    <h1>Affinez votre identité</h1>
    <p class="lede">Ces options sont facultatives. Votre boutique <?= $shopUrl !== '' ? 'est déjà en ligne' : 'est prête' ?> ; vous pourrez aussi tout modifier plus tard.</p>
  </div>
  <?php foreach ($errors as $message): ?><div class="notice error" role="alert"><?= $e($message) ?></div><?php endforeach; ?>
  <?php if ($notice !== ''): ?><div class="notice ok" role="status"><?= $e($notice) ?></div><?php endif; ?>

  <div class="brand-steps">
    <?php if (!empty($request['gallery'])): ?>
      <section class="brand-card brand-design">
        <h2>Présence dans la galerie</h2>
        <?php if ($membershipGallery): ?>
          <p>
            <?php if ($galleryMember): ?>
              Votre boutique est présente dans <strong><?= $e($membershipGallery['name']) ?></strong>. Vous pouvez tester son affichage dans un aperçu privé ou la retirer de la galerie.
            <?php else: ?>
              Votre boutique n’est pas ajoutée automatiquement à <strong><?= $e($membershipGallery['name']) ?></strong>. Testez d’abord son affichage dans un aperçu privé, puis choisissez explicitement si vous souhaitez la rejoindre.
            <?php endif; ?>
          </p>
          <?php if ($galleryLive): ?>
            <p><a class="btn ghost" href="<?= $e($galleryTestUrl) ?>" target="_blank" rel="noopener noreferrer">Tester ma boutique dans la galerie ↗</a></p>
          <?php else: ?>
            <p class="notice" role="status">Cette galerie n’est pas disponible actuellement pour un aperçu ou une nouvelle adhésion.</p>
          <?php endif; ?>
          <?php if ($galleryMember): ?>
            <p class="notice ok" role="status">Votre boutique est actuellement présente dans cette galerie.</p>
            <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="leave_gallery">
              <button class="btn ghost" type="submit">Retirer ma boutique de la galerie</button>
            </form>
          <?php elseif ($galleryLive): ?>
            <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="join_gallery">
              <button class="btn" type="submit">Rejoindre « <?= $e($membershipGallery['name']) ?> »</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <p>La galerie choisie lors de l’inscription n’est plus disponible. Votre boutique n’y a pas été ajoutée.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>
    <section class="brand-card brand-design">
      <h2>Choisissez le design de votre boutique</h2>
      <p>Votre boutique est déjà créée avec le modèle « <?= $e(SHOP_TEMPLATES[$currentTemplate]['label']) ?> ». Choisissez un modèle et appliquez-le pour modifier son apparence et sa mise en page ; vos produits et contenus restent inchangés.</p>
      <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="apply_template">
        <div class="brand-design-grid">
          <?php foreach (SHOP_TEMPLATES as $key => $design): ?>
            <div class="brand-design-option">
              <label>
                <input type="radio" name="template" value="<?= $e($key) ?>"<?= $currentTemplate === $key ? ' checked' : '' ?> required>
                <span class="brand-design-card" style="--p-bg:<?= $e($design['colors']['bg']) ?>;--p-ink:<?= $e($design['colors']['ink']) ?>;--p-accent:<?= $e($design['colors']['accent']) ?>">
                  <span class="brand-design-mock" aria-hidden="true"><strong style="font-family:'<?= $e($design['fonts']['display']) ?>',Georgia,serif">Aa</strong><i></i><i class="short"></i><b></b></span>
                  <strong><?= $e($design['label']) ?><?= !empty($design['sector']) ? ' · ' . $e($design['sector']) : '' ?></strong>
                  <small><?= $e($design['tagline']) ?></small>
                </span>
              </label>
              <?php if ($shopUrl !== ''): ?><a class="tpl-preview" href="<?= $e($shopUrl) ?>?modele=<?= $e($key) ?>" target="_blank" rel="noopener">Aperçu sur ma boutique ↗</a><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button class="btn" type="submit">Appliquer le design choisi</button>
      </form>
    </section>

    <section class="brand-card">
      <h2>Idées de noms</h2>
      <p>Décrivez votre activité : Gemini proposera des alternatives à « <?= $e($request['shop_name']) ?> ». Vérifiez la disponibilité de la marque avant de retenir un nom.</p>
      <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="suggest_names">
        <label>Description du projet<textarea name="about" minlength="10" maxlength="400" required rows="3"><?= $e($request['about'] ?? '') ?></textarea></label>
        <button class="btn" type="submit">Générer des suggestions</button>
      </form>
      <?php if ($names): ?>
        <div class="brand-names"><?php foreach ($names as $candidate): ?>
          <form method="post" action="/inscription/identite.php<?= $link ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="apply_name">
            <input type="hidden" name="shop_name" value="<?= $e($candidate) ?>">
            <button class="brand-name" type="submit"><?= $e($candidate) ?> · Utiliser</button>
          </form>
        <?php endforeach; ?></div>
      <?php endif; ?>
    </section>

    <section class="brand-card">
      <h2>Votre logo</h2>
      <p>Générez un logo avec Gemini. Il sera installé automatiquement et pourra être remplacé dans Apparence → Logos.</p>
      <?php if ($logo !== ''): ?><img class="brand-logo" src="/<?= $e($logo) ?>" alt="Logo de <?= $e($request['shop_name']) ?>"><?php endif; ?>
      <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="generate_logo">
        <button class="btn" type="submit">Générer mon logo</button>
      </form>
    </section>

    <section class="brand-card">
      <h2>Nom de domaine personnalisé</h2>
      <p>Le domaine sera enregistré au nom du titulaire que vous indiquez ; le compte OVH de la plateforme en assurera la gestion. Le prix exact TTC est vérifié auprès d’OVH avant paiement ; la commande n’est déclenchée qu’après confirmation de Stripe. Le prix affiché couvre la première année seulement : le renouvellement annuel fera l’objet d’une facturation séparée.</p>
      <?php if (($purchase['state'] ?? '') === 'ordered'): ?>
        <div class="notice ok" role="status">Commande OVH <?= $e($purchase['ovh_order_id'] ?? '') ?> transmise pour <strong><?= $e($quote['domain'] ?? '') ?></strong>.</div>
      <?php elseif (($purchase['state'] ?? '') === 'paid_needs_review' || ($purchase['state'] ?? '') === 'ordering'): ?>
        <div class="notice" role="status">Paiement reçu. Votre commande de <?= $e($quote['domain'] ?? '') ?> est en cours de vérification par OVH.</div>
      <?php elseif (($purchase['state'] ?? '') === 'awaiting_payment' && !empty($purchase['stripe_url'])): ?>
        <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="open_payment">
          <p>Vous aviez commencé le paiement de <strong><?= $e($quote['domain'] ?? '') ?></strong>.</p>
          <button class="btn" type="submit">Reprendre le paiement</button>
        </form>
        <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="change_domain">
          <button class="btn ghost" type="submit">Annuler ce paiement et choisir un autre domaine</button>
        </form>
      <?php else: ?>
        <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="quote_domain">
          <label>Nom de domaine souhaité<input type="text" name="domain" required maxlength="253" placeholder="moncommerce.fr" value="<?= $e($quote['domain'] ?? ($request['branding']['domain_choice']['domain'] ?? '')) ?>" autocomplete="off"></label>
          <div class="brand-grid">
            <label>Prénom du titulaire<input name="owner_first_name" required maxlength="60" autocomplete="given-name" value="<?= $e($owner['first_name'] ?? $request['first_name'] ?? '') ?>"></label>
            <label>Nom du titulaire<input name="owner_last_name" required maxlength="60" autocomplete="family-name" value="<?= $e($owner['last_name'] ?? $request['last_name'] ?? '') ?>"></label>
          </div>
          <div class="brand-grid">
            <label>Forme juridique du titulaire
              <select name="owner_legal_form" required>
                <?php foreach (['individual' => 'Personne physique', 'corporation' => 'Société', 'personalcorporation' => 'Entreprise individuelle', 'association' => 'Association', 'administration' => 'Administration', 'other' => 'Autre'] as $value => $label): ?>
                  <option value="<?= $e($value) ?>"<?= ($owner['legal_form'] ?? 'individual') === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Entreprise / organisation<input name="owner_organization" maxlength="100" autocomplete="organization" value="<?= $e($owner['organization'] ?? '') ?>"></label>
          </div>
          <label>Adresse<input name="owner_address" required maxlength="160" autocomplete="street-address" value="<?= $e($owner['address'] ?? '') ?>"></label>
          <div class="brand-grid">
            <label>Code postal<input name="owner_postal_code" required maxlength="20" autocomplete="postal-code" value="<?= $e($owner['postal_code'] ?? '') ?>"></label>
            <label>Ville<input name="owner_city" required maxlength="80" autocomplete="address-level2" value="<?= $e($owner['city'] ?? '') ?>"></label>
          </div>
          <div class="brand-grid">
            <label>Pays (code ISO à 2 lettres)<input name="owner_country" required pattern="[A-Za-z]{2}" maxlength="2" value="<?= $e($owner['country'] ?? 'FR') ?>" autocomplete="country"></label>
            <label>Téléphone (format international)<input name="owner_phone" required maxlength="30" placeholder="+33612345678" value="<?= $e($owner['phone'] ?? $request['phone'] ?? '') ?>" autocomplete="tel"></label>
          </div>
          <label><span><input type="checkbox" name="owner_legal_age" value="1" required> Je certifie que le titulaire est majeur et autorisé à enregistrer ce domaine.</span></label>
          <label><span><input type="checkbox" name="domain_terms" value="1" required> J’accepte les conditions d’enregistrement OVH, confirme l’exactitude des coordonnées et accepte leur transmission à OVH pour préparer le contact titulaire, même si je n’achève pas le paiement.</span></label>
          <label><span><input type="checkbox" name="waive_retraction" value="1" required> Je demande le démarrage immédiat de l’enregistrement et reconnais la renonciation au délai de rétractation applicable à cette commande.</span></label>
          <button class="btn" type="submit">Vérifier la disponibilité et le prix</button>
        </form>
        <?php if (($purchase['state'] ?? '') === 'quoted' && $amount > 0): ?>
          <div class="brand-price">
            <p><strong><?= $e($quote['domain']) ?></strong> — 1 an</p>
            <p>Prix OVH TTC : <?= number_format(((int) $quote['ovh_cents']) / 100, 2, ',', ' ') ?> €<?= $margin > 0 ? ' · frais de service : ' . number_format($margin / 100, 2, ',', ' ') . ' €' : '' ?></p>
            <p><strong>Total à payer : <?= number_format($amount / 100, 2, ',', ' ') ?> €</strong></p>
            <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="pay_domain">
              <button class="btn" type="submit">Payer et commander chez OVH</button>
            </form>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <?php $quoteCatalog = saas_service_quotes_active(); ?>
    <?php if ($quoteCatalog): ?>
      <section class="brand-card brand-design" id="devis">
        <h2>Prestations sur devis</h2>
        <?php if (!empty($request['service_quotes_at'])): ?>
          <div class="notice ok" role="status">Demande envoyée le <?= $e(date('d/m/Y', (int) $request['service_quotes_at'])) ?> : nous répondons à <strong><?= $e((string) ($request['service_quotes_email'] ?? '')) ?></strong> sous 2 jours ouvrés. Vous pouvez la compléter ci-dessous.</div>
        <?php endif; ?>
        <p>Choisissez ce dont vous avez besoin. Les fourchettes ci-dessous sont indicatives : chaque devis est établi sur mesure et gratuitement, et aucun paiement n’est déclenché par cette demande.</p>
        <form class="brand-form" method="post" action="/inscription/identite.php<?= $link ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="action" value="request_quotes">
          <fieldset class="quote-services">
            <legend>Prestations souhaitées</legend>
            <div class="plugin-picks">
              <?php foreach ($quoteCatalog as $key => $service): ?>
                <label class="plugin-choice">
                  <input type="checkbox" name="service_quotes[]" value="<?= $e($key) ?>"<?= in_array($key, (array) ($request['service_quotes'] ?? []), true) ? ' checked' : '' ?>>
                  <span class="plugin-choice-body">
                    <span class="plugin-choice-head"><strong><?= $e($service['name']) ?></strong><span class="plugin-price"><?= $e(saas_service_quote_price($service)) ?></span></span>
                    <span class="plugin-desc"><?= $e($service['description']) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <label>Votre besoin<textarea name="service_quotes_message" rows="4" minlength="10" maxlength="1200" required placeholder="Ex. : refonte du design et installation du logo avant l’ouverture."><?= $e((string) ($request['service_quotes_message'] ?? '')) ?></textarea></label>
          <div class="brand-grid">
            <label>E-mail pour le devis<input type="email" name="quote_email" maxlength="254" required value="<?= $e((string) ($request['service_quotes_email'] ?? $request['email'] ?? '')) ?>"></label>
            <label>Téléphone (facultatif)<input type="tel" name="quote_phone" maxlength="30" autocomplete="tel" value="<?= $e((string) ($request['service_quotes_phone'] ?? $request['phone'] ?? '')) ?>"></label>
          </div>
          <button class="btn" type="submit"><?= empty($request['service_quotes_at']) ? 'Envoyer ma demande de devis' : 'Mettre à jour ma demande' ?></button>
        </form>
      </section>
    <?php endif; ?>
  </div>
  <p style="margin:22px 0"><a class="btn ghost" href="<?= $e($shopUrl) ?>">Continuer vers ma boutique</a></p>
</div>
<?php saas_page_end(); ?>
