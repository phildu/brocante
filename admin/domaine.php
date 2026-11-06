<?php
// Nom de domaine de la boutique : rechercher, vérifier le titulaire, payer et
// suivre la commande OVH — depuis l'administration de la boutique, avec le
// même compte Stripe de plateforme que le parcours d'inscription.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/domain-shop.php';
require_admin();

$slug = tenant_slug();
$content = get_content();
$flash = flash_get();
$csrf = admin_csrf_token();
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' €';

// Retour de Stripe : la session est relue chez Stripe, jamais crue sur parole.
if (isset($_GET['s'])) {
    $sessionId = (string) $_GET['s'];
    if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
        try {
            $session = saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
            $result = domain_shop_apply_session($session);
            if ($result['state'] === 'ordered') {
                $domain = (string) ($result['purchase']['quote']['domain'] ?? 'votre domaine');
                flash_set('Merci ! La commande de ' . $domain . ' a été transmise à OVH.', 'ok');
            } elseif ($result['state'] === 'test') {
                $domain = (string) ($result['purchase']['quote']['domain'] ?? 'votre domaine');
                flash_set('Paiement de test enregistré : la commande de ' . $domain . ' n’a pas été transmise à OVH (aucun débit réel).', 'error');
            } elseif ($result['state'] === 'already') {
                $alreadyState = (string) ($result['purchase']['state'] ?? '');
                $alreadyDomain = (string) ($result['purchase']['quote']['domain'] ?? 'ce domaine');
                if ($alreadyState === 'ordered') {
                    flash_set('Ce paiement est déjà pris en compte : la commande de ' . $alreadyDomain . ' est enregistrée.', 'ok');
                } elseif ($alreadyState === 'test_paid') {
                    flash_set('Ce paiement de test est déjà enregistré : aucune commande OVH n’a été passée.', 'error');
                } elseif ($alreadyState === 'paid_needs_review') {
                    flash_set('Ce paiement est déjà pris en compte, mais OVH n’a pas confirmé la commande. L’exploitant de la plateforme la vérifie.', 'error');
                } else {
                    flash_set('Ce paiement est déjà pris en compte : la commande de ' . $alreadyDomain . ' est en cours de transmission à OVH.', 'ok');
                }
            } elseif ($result['state'] === 'review') {
                flash_set('Le paiement est confirmé, mais OVH n’a pas encore confirmé la commande. L’exploitant de la plateforme vérifie le statut.', 'error');
            } elseif ($result['state'] === 'unpaid') {
                flash_set('Le paiement n’est pas encore confirmé par Stripe. La commande partira dès sa confirmation.', 'error');
            } else {
                flash_set('Le paiement a été reçu, mais la commande du domaine n’a pas pu être appliquée : ' . (string) ($result['message'] ?? 'vérifiez le domaine et recommencez.'), 'error');
            }
        } catch (Throwable $ex) {
            error_log('domain return: ' . $ex->getMessage());
            flash_set('Impossible de vérifier le paiement pour le moment : réessayez dans un instant.', 'error');
        }
    } else {
        flash_set('Session de paiement invalide.', 'error');
    }
    header('Location: /admin/domaine.php', true, 303);
    exit;
}

$data = domain_shop_load($slug);
$search = is_array($data['search'] ?? null) ? $data['search'] : null;
$purchase = is_array($data['purchase'] ?? null) ? $data['purchase'] : null;
$state = (string) ($purchase['state'] ?? '');
$quote = (array) ($purchase['quote'] ?? []);
$amount = (int) ($quote['amount_cents'] ?? 0);
$ovhPrice = (int) ($quote['ovh_cents'] ?? 0);
$margin = max(0, $amount - $ovhPrice);
$quoteExpired = $state === 'quoted' && time() - (int) ($purchase['created_at'] ?? 0) > 86400;

$ovhError = '';
try {
    $ovhConfig = branding_domain_config();
    if ($ovhConfig['application_key'] === '' || $ovhConfig['application_secret'] === '' || $ovhConfig['consumer_key'] === '') {
        $ovhError = "L’achat de domaines n’est pas encore configuré sur ce serveur : les identifiants API OVH manquent. L’exploitant de la plateforme les renseigne depuis le portail.";
    }
} catch (Throwable $ex) {
    $ovhError = $ex->getMessage();
}
$stripeReady = saas_stripe_configured();

$choose = trim((string) ($_GET['choisir'] ?? ''));
$chosen = null;
foreach ((array) ($search['domains'] ?? []) as $candidate) {
    if ($choose !== '' && (string) ($candidate['domain'] ?? '') === $choose && !empty($candidate['available'])) {
        $chosen = $candidate;
        break;
    }
}
$available = (int) array_sum(array_map(static fn ($c): int => !empty($c['available']) ? 1 : 0, (array) ($search['domains'] ?? [])));
$searchStale = $search !== null && time() - (int) ($search['searched_at'] ?? 0) > 86400;
$shopAddress = tenant_site_url() . '/';

// Adresse personnalisée déjà possédée par le marchand (hors achat OVH).
$custom = domain_custom_load($slug);
$customState = (string) ($custom['state'] ?? '');
$customDomain = (string) ($custom['domain'] ?? '');
$customToken = (string) ($custom['token'] ?? '');
$customProofAt = (int) ($custom['ownership_at'] ?? 0);
$customRoutingAt = (int) ($custom['routing_at'] ?? 0);
$customCheckedAt = (int) ($custom['checked_at'] ?? 0);
$customObserved = (array) ($custom['observed'] ?? []);
$expectedIps = domain_custom_expected_ips();
$targetIp = (string) ($expectedIps[0] ?? '');
$customPrefill = trim((string) ($_GET['deja'] ?? ''));
$customActive = $customState === 'active';

$identity = legal_identity();
$owner = (array) ($purchase['owner'] ?? []);
if (!$owner) {
    $owner = [
        'first_name' => '',
        'last_name' => '',
        'organization' => trim((string) ($identity['company'] ?? '')),
        'legal_form' => 'individual',
        'address' => '',
        'postal_code' => '',
        'city' => '',
        'country' => 'FR',
        'phone' => trim((string) ($identity['phone'] ?? '')),
        'email' => filter_var((string) ($identity['email'] ?? ''), FILTER_VALIDATE_EMAIL)
            ? (string) $identity['email']
            : trim((string) tenant('admin_email', '')),
    ];
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nom de domaine — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .dm-card { background: var(--surface); border: 1px solid var(--line); padding: 16px 18px; margin-top: 16px; }
  .dm-card h2 { font-size: 1.1rem; margin: 0 0 6px; }
  .dm-card p { margin: 0 0 10px; color: var(--ink-soft); font-size: .9rem; }
  .dm-current { border-left: 3px solid var(--accent); }
  .dm-current code { overflow-wrap: anywhere; }
  .dm-table { width: 100%; border-collapse: collapse; font-size: .9rem; margin-top: 10px; }
  .dm-table th, .dm-table td { padding: 8px 10px; border-bottom: 1px solid var(--line); text-align: left; }
  .dm-table th { font-family: var(--font-mono); font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); font-weight: 400; }
  .dm-table td.num { text-align: right; font-family: var(--font-mono); font-variant-numeric: tabular-nums; white-space: nowrap; }
  .dm-ok { color: #1b6e3c; font-weight: 600; }
  .dm-no { color: var(--ink-soft); }
  .dm-form { display: grid; gap: 12px; margin-top: 8px; }
  .dm-form .field-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .dm-form .field-row-3 { display: grid; grid-template-columns: 2fr 1fr 2fr; gap: 12px; }
  .dm-form .field-row-4 { display: grid; grid-template-columns: 1fr 2fr; gap: 12px; }
  .dm-form .field label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: 4px; }
  .dm-form .field input, .dm-form .field select { width: 100%; }
  .dm-form .check { display: flex; gap: 8px; align-items: flex-start; font-size: .86rem; color: var(--ink-soft); }
  .dm-form .check input { margin-top: 3px; }
  .dm-quote { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; }
  .dm-quote .price { font-family: var(--font-mono); font-size: 1.5rem; font-variant-numeric: tabular-nums; }
  .dm-quote .price small { display: block; font-size: .72rem; color: var(--ink-soft); font-family: var(--font-body); }
  .dm-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .dm-note { font-size: .82rem; color: var(--ink-soft); }
  .dm-search { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; }
  .dm-search .field { flex: 1 1 260px; }
  .dm-search .field label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: 4px; }
  .dm-search input { width: 100%; }
  .dm-badge { display: inline-block; padding: 2px 8px; border: 1px solid var(--line); border-radius: 999px; font-family: var(--font-mono); font-size: .7rem; color: var(--ink-soft); }
  @media (max-width: 720px) {
    .dm-form .field-row-2, .dm-form .field-row-3, .dm-form .field-row-4, .dm-quote { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'domaine'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Boutique</p>
    <h1 style="font-size:1.8rem;margin:12px 0 8px;">Nom de domaine</h1>
    <p class="hint">Achetez une adresse propre pour votre boutique (par exemple <code>ma-boutique.fr</code>) sans quitter son administration. La disponibilité et le prix viennent d’OVH ; le paiement est encaissé par la plateforme, puis la commande est transmise à OVH. Rien n’est commandé avant la confirmation du paiement.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div class="dm-card dm-current">
      <h2>Adresse actuelle de la boutique</h2>
      <p><code><?= h($shopAddress) ?></code></p>
      <p class="dm-note"><?= $customActive
        ? 'Votre domaine personnalisé est en service : cette adresse et l’adresse historique ci-dessus affichent la même boutique.'
        : 'Tant qu’aucun domaine propre n’est actif, cette adresse reste celle de la boutique. Un domaine acheté ici est réservé à votre nom : sa mise en service (DNS et certificat) se fait ensuite avec l’exploitant de la plateforme.' ?></p>
    </div>

    <?php if ($customState === ''): ?>
      <div class="dm-card" id="deja">
        <h2>J’ai déjà un nom de domaine</h2>
        <p>Votre adresse a été achetée chez un autre prestataire (OVH, Gandi, Ionos…), ou vous préférez garder celle que vous possédez déjà : rattachez-la à cette boutique. Aucun achat et aucun paiement — le domaine reste chez votre fournisseur, qui continue de le renouveler.</p>
        <form class="dm-search" method="post" action="/admin/domaine-action.php">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="custom_attach">
          <div class="field">
            <label for="dm-custom">Adresse existante</label>
            <input type="text" id="dm-custom" name="domain" maxlength="253" required value="<?= h($customPrefill) ?>" placeholder="Ex. la-fripe-a-naty.fr">
          </div>
          <button type="submit" class="btn btn-primary">Rattacher ce domaine</button>
        </form>
        <p class="dm-note" style="margin-top:8px;">Deux vérifications suivent : un enregistrement TXT prouve que le domaine est bien à vous, puis le domaine est pointé vers ce serveur. La mise en service (alias d’hébergement et certificat HTTPS) est faite par l’exploitant de la plateforme.</p>
      </div>
    <?php else: ?>
      <div class="dm-card" id="deja">
        <h2>Votre nom de domaine</h2>
        <p><strong><?= h($customDomain) ?></strong> · <span class="dm-badge"><?= h(domain_custom_state_label($customState)) ?></span></p>

        <?php if ($customState === 'active'): ?>
          <p>Votre boutique répond désormais à l’adresse <a href="https://<?= h($customDomain) ?>/">https://<?= h($customDomain) ?>/</a>. L’adresse historique de la plateforme continue de fonctionner.</p>
          <p class="dm-note">Le domaine reste enregistré chez votre fournisseur : c’est lui qui prélève le renouvellement annuel. Pour le détacher, prévenez aussi l’exploitant de la plateforme afin qu’il retire l’alias d’hébergement et le certificat.</p>
          <form method="post" action="/admin/domaine-action.php" onsubmit="return confirm('Détacher <?= h($customDomain) ?> ? Votre boutique ne répondra plus à cette adresse dès maintenant. Le domaine reste chez votre fournisseur.');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="custom_remove">
            <button type="submit" class="btn">Détacher ce domaine</button>
          </form>
        <?php else: ?>
          <p>Étape 1 — prouvez que le domaine est à vous. Ajoutez cet enregistrement TXT chez votre fournisseur de domaine (la propagation prend de quelques minutes à quelques heures) :</p>
          <table class="dm-table">
            <thead><tr><th>Type</th><th>Nom</th><th>Valeur</th><th></th></tr></thead>
            <tbody>
              <tr>
                <td>TXT</td>
                <td><code><?= h('_brocs.' . $customDomain) ?></code></td>
                <td><code><?= h('brocs-verify=' . $customToken) ?></code></td>
                <td><?= $customProofAt > 0 ? '<span class="dm-ok">Vérifié</span>' : '<span class="dm-no">En attente</span>' ?></td>
              </tr>
            </tbody>
          </table>
          <p class="dm-note">Si votre fournisseur refuse les noms commençant par « _brocs », l’enregistrement TXT peut aussi être créé sur le domaine lui-même, <code><?= h($customDomain) ?></code>, avec la même valeur.</p>

          <p style="margin-top:14px;">Étape 2 — pointez le domaine vers ce serveur :</p>
          <table class="dm-table">
            <thead><tr><th>Type</th><th>Nom</th><th>Valeur</th><th></th></tr></thead>
            <tbody>
              <tr>
                <td>A</td>
                <td><code>@</code> (le domaine)</td>
                <td><code><?= $targetIp !== '' ? h($targetIp) : 'adresse à demander à l’exploitant' ?></code></td>
                <td><?= $customRoutingAt > 0 ? '<span class="dm-ok">Confirmé</span>' : '<span class="dm-no">En attente</span>' ?></td>
              </tr>
              <tr>
                <td>CNAME</td>
                <td><code>www</code></td>
                <td><code>@</code> (ou <code><?= h($customDomain) ?></code>)</td>
                <td><?= !empty($customObserved['www']) ? '<span class="dm-ok">Vu</span>' : '<span class="dm-no">Facultatif</span>' ?></td>
              </tr>
            </tbody>
          </table>
          <p class="dm-note">Conservez la durée de vie (TTL) proposée par votre fournisseur. Si le domaine a déjà des adresses e-mail, ne touchez pas aux enregistrements MX.</p>

          <?php if ($customCheckedAt > 0): ?>
            <p class="dm-note">Dernier contrôle le <?= h(date('d/m/Y à H:i', $customCheckedAt)) ?><?= !empty($customObserved['a']) ? ' — adresse vue : ' . h(implode(', ', (array) $customObserved['a'])) : '' ?>.</p>
          <?php endif; ?>

          <div class="dm-actions" style="margin-top:12px;">
            <form method="post" action="/admin/domaine-action.php">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="custom_check">
              <button type="submit" class="btn btn-primary">Vérifier maintenant</button>
            </form>
            <?php if ($customState === 'dns_ok'): ?>
              <form method="post" action="/admin/domaine-action.php">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="custom_request">
                <button type="submit" class="btn btn-primary">Demander la mise en service</button>
              </form>
            <?php endif; ?>
            <form method="post" action="/admin/domaine-action.php" onsubmit="return confirm('Détacher ce domaine ? Les consignes DNS seront oubliées et un nouveau jeton sera demandé pour le rattacher à nouveau.');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="custom_remove">
              <button type="submit" class="btn">Détacher</button>
            </form>
          </div>

          <?php if ($customState === 'ready'): ?>
            <p class="dm-note" style="margin-top:10px;">La demande est chez l’exploitant de la plateforme : il déclare le domaine sur l’hébergement, émet le certificat HTTPS puis active l’adresse. Vous n’avez rien d’autre à faire.</p>
          <?php elseif ($customState === 'dns_ok'): ?>
            <p class="dm-note" style="margin-top:10px;">Tout est en place. Demandez la mise en service : l’exploitant émet le certificat HTTPS et active l’adresse (généralement sous 24 à 48 heures).</p>
          <?php elseif ($customState === 'verified'): ?>
            <p class="dm-note" style="margin-top:10px;">La propriété est confirmée. Il reste à pointer le domaine vers le serveur (étape 2) pour pouvoir demander la mise en service.</p>
          <?php else: ?>
            <p class="dm-note" style="margin-top:10px;">L’enregistrement TXT n’est pas encore visible. Vérifiez le nom et la valeur, puis cliquez sur « Vérifier maintenant » après la propagation.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($ovhError !== ''): ?>
      <div class="dm-card">
        <h2>Achat indisponible pour le moment</h2>
        <p><?= h($ovhError) ?></p>
      </div>
    <?php endif; ?>

    <?php if ($state === 'ordered'): ?>
      <?php $orderedDomain = (string) ($quote['domain'] ?? ''); ?>
      <div class="dm-card">
        <h2>Domaine commandé</h2>
        <p><strong><?= h($orderedDomain) ?></strong> a été commandé chez OVH le <?= h(date('d/m/Y à H:i', (int) ($purchase['ordered_at'] ?? time()))) ?><?= !empty($purchase['ovh_order_id']) ? ' (commande n° ' . h((string) $purchase['ovh_order_id']) . ')' : '' ?>.</p>
        <p>La réservation couvre la première année. Le renouvellement annuel se règle ensuite dans le compte OVH de la plateforme. Pour mettre le domaine en service, ses enregistrements DNS doivent pointer vers ce serveur : demandez la configuration à l’exploitant de la plateforme, qui l’ajoutera comme adresse de la boutique.</p>
      </div>
    <?php elseif ($state === 'test_paid'): ?>
      <div class="dm-card">
        <h2>Paiement de test</h2>
        <p>Le paiement de <strong><?= h((string) ($quote['domain'] ?? 'votre domaine')) ?></strong> a été fait avec Stripe en mode test : aucun argent n’a été encaissé et aucune commande n’a été passée chez OVH.</p>
        <p class="dm-note">Effacez ce paiement de test pour recommencer : la commande réelle part quand la plateforme est en mode réel.</p>
        <form method="post" action="/admin/domaine-action.php">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn">Effacer et recommencer</button>
        </form>
      </div>
    <?php elseif ($state === 'paid_needs_review'): ?>
      <div class="dm-card">
        <h2>Paiement reçu — commande à vérifier</h2>
        <p>Le paiement de <strong><?= h((string) ($quote['domain'] ?? 'votre domaine')) ?></strong> est confirmé, mais OVH n’a pas confirmé la commande. Aucune nouvelle tentative n’est faite automatiquement.</p>
        <p class="dm-note"><?= h((string) ($purchase['message'] ?? 'L’exploitant de la plateforme vérifie le statut de la commande.')) ?></p>
      </div>
    <?php elseif ($state === 'ordering'): ?>
      <div class="dm-card">
        <h2>Commande en cours</h2>
        <p>Le paiement de <strong><?= h((string) ($quote['domain'] ?? 'votre domaine')) ?></strong> est confirmé. La commande est en cours de transmission à OVH.</p>
        <?php if (time() - (int) ($purchase['paid_at'] ?? 0) > 600): ?>
          <p class="dm-note">Cet état dure anormalement longtemps : contactez l’exploitant de la plateforme en indiquant le domaine.</p>
        <?php endif; ?>
      </div>
    <?php elseif ($state === 'awaiting_payment'): ?>
      <div class="dm-card">
        <h2>Paiement en attente</h2>
        <p>Le paiement de <strong><?= h((string) ($quote['domain'] ?? '')) ?></strong> (<?= h($money($amount)) ?>) est ouvert chez Stripe mais n’a pas encore été confirmé.</p>
        <div class="dm-actions">
          <?php if (!empty($purchase['stripe_url'])): ?>
            <a class="btn btn-primary" href="<?= h((string) $purchase['stripe_url']) ?>">Reprendre le paiement</a>
          <?php endif; ?>
          <form method="post" action="/admin/domaine-action.php" onsubmit="return confirm('Annuler ce paiement et libérer le domaine ?');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn">Annuler la commande</button>
          </form>
        </div>
        <p class="dm-note" style="margin-top:10px;">La commande n’est transmise à OVH qu’après confirmation du paiement. Après environ une heure, le lien Stripe peut expirer : annulez puis recommencez.</p>
      </div>
    <?php elseif ($state === 'quoted' && !$quoteExpired): ?>
      <div class="dm-card">
        <h2>Devis prêt — <?= h((string) ($quote['domain'] ?? '')) ?></h2>
        <div class="dm-quote">
          <div>
            <p>Titulaire : <strong><?= h(trim($owner['first_name'] . ' ' . $owner['last_name'])) ?><?= ($owner['organization'] ?? '') !== '' ? ' — ' . h((string) $owner['organization']) : '' ?></strong><br>
              <?= h((string) ($owner['address'] ?? '')) ?>, <?= h((string) ($owner['postal_code'] ?? '')) ?> <?= h((string) ($owner['city'] ?? '')) ?>, <?= h((string) ($owner['country'] ?? '')) ?><br>
              <?= h((string) ($owner['email'] ?? '')) ?> · <?= h((string) ($owner['phone'] ?? '')) ?></p>
            <p class="dm-note">Enregistrement pour 1 an. Devis OVH valable 24 heures. Le renouvellement annuel n’est pas inclus.</p>
          </div>
          <div class="price"><?= h($money($amount)) ?><small><?= $margin > 0 ? 'dont ' . h($money($margin)) . ' de frais de service' : 'prix OVH TTC' ?></small></div>
        </div>
        <div class="dm-actions" style="margin-top:12px;">
          <?php if ($stripeReady): ?>
            <form method="post" action="/admin/domaine-action.php">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="pay">
              <button type="submit" class="btn btn-primary">Payer par carte</button>
            </form>
          <?php else: ?>
            <p class="dm-note">Le paiement en ligne n’est pas configuré sur ce serveur : contactez l’exploitant de la plateforme.</p>
          <?php endif; ?>
          <form method="post" action="/admin/domaine-action.php">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn">Changer de domaine</button>
          </form>
        </div>
      </div>
    <?php elseif ($state === 'quoted' && $quoteExpired): ?>
      <div class="dm-card">
        <h2>Devis expiré</h2>
        <p>Le devis pour <strong><?= h((string) ($quote['domain'] ?? '')) ?></strong> a plus de 24 heures. Vérifiez à nouveau le domaine pour obtenir un prix actuel.</p>
        <form method="post" action="/admin/domaine-action.php">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn">Recommencer</button>
        </form>
      </div>
    <?php else: ?>
      <div class="dm-card">
        <h2>Rechercher une adresse</h2>
        <p>Saisissez le nom de votre boutique (nous testons les extensions proches) ou une adresse complète avec son extension.</p>
        <form class="dm-search" method="post" action="/admin/domaine-action.php">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="search">
          <div class="field">
            <label for="dm-q">Nom de boutique ou domaine</label>
            <input type="text" id="dm-q" name="q" maxlength="120" value="<?= h((string) ($search['query'] ?? ($content['site_name'] ?? ''))) ?>" placeholder="Ex. La fripe à Naty ou la-fripe-a-naty.fr"<?= $ovhError !== '' ? ' disabled' : '' ?>>
          </div>
          <button type="submit" class="btn btn-primary"<?= $ovhError !== '' ? ' disabled' : '' ?>>Vérifier la disponibilité</button>
        </form>
        <p class="dm-note" style="margin-top:8px;">La recherche interroge OVH : les prix indiqués sont ceux du jour et peuvent changer avant la commande.</p>
      </div>

      <?php if ($search): ?>
        <div class="dm-card">
          <h2>Résultats</h2>
          <p class="dm-note">Recherche « <?= h((string) ($search['query'] ?? '')) ?> » effectuée le <?= h(date('d/m/Y à H:i', (int) ($search['searched_at'] ?? time()))) ?> auprès d’OVH · <?= $available ?> adresse<?= $available > 1 ? 's' : '' ?> disponible<?= $available > 1 ? 's' : '' ?>.</p>
          <?php if ($searchStale): ?>
            <p class="dm-note">Cette recherche date de plus de 24 heures : relancez-la pour obtenir un prix actuel.</p>
          <?php endif; ?>
          <?php if (!empty($search['price_errors'])): ?>
            <p class="dm-note">Certains tarifs n’ont pas pu être obtenus : <?= h(implode(' ; ', (array) $search['price_errors'])) ?></p>
          <?php endif; ?>
          <table class="dm-table">
            <thead><tr><th>Domaine</th><th>Disponibilité</th><th class="num">1re année</th><th></th></tr></thead>
            <tbody>
              <?php foreach ((array) ($search['domains'] ?? []) as $candidate): ?>
                <?php $candidateDomain = (string) ($candidate['domain'] ?? ''); ?>
                <tr>
                  <td><strong><?= h($candidateDomain) ?></strong><?= ($candidate['kind'] ?? '') === 'alternative' ? ' <span class="dm-badge">variante</span>' : '' ?></td>
                  <td><?= !empty($candidate['available']) ? '<span class="dm-ok">Disponible</span>' : '<span class="dm-no">Indisponible</span>' ?></td>
                  <td class="num"><?= isset($candidate['price_cents']) ? h($money((int) $candidate['price_cents'])) : '—' ?></td>
                  <td>
                    <?php if (!empty($candidate['available']) && !$searchStale): ?>
                      <a class="btn-small" href="/admin/domaine.php?choisir=<?= h(rawurlencode($candidateDomain)) ?>">Choisir</a>
                    <?php elseif (($candidate['kind'] ?? '') === 'exact' && $customState === ''): ?>
                      <a class="btn-small" href="/admin/domaine.php?deja=<?= h(rawurlencode($candidateDomain)) ?>#deja">Déjà à vous ?</a>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php if (!empty($candidate['price_error'])): ?>
                  <tr><td colspan="4" class="dm-no"><?= h((string) $candidate['price_error']) ?></td></tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($chosen): ?>
        <div class="dm-card">
          <h2>Titulaire du domaine <?= h((string) $chosen['domain']) ?></h2>
          <p>Ces informations servent à créer le contact titulaire chez OVH. La personne ou l’organisation indiquée devient le propriétaire légal du domaine.</p>
          <form class="dm-form" method="post" action="/admin/domaine-action.php" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="quote">
            <input type="hidden" name="domain" value="<?= h((string) $chosen['domain']) ?>">
            <div class="field-row-2">
              <div class="field"><label for="dm-first">Prénom *</label><input type="text" id="dm-first" name="owner_first_name" maxlength="60" required value="<?= h((string) $owner['first_name']) ?>"></div>
              <div class="field"><label for="dm-last">Nom *</label><input type="text" id="dm-last" name="owner_last_name" maxlength="60" required value="<?= h((string) $owner['last_name']) ?>"></div>
            </div>
            <div class="field-row-2">
              <div class="field"><label for="dm-form">Forme juridique *</label>
                <select id="dm-form" name="owner_legal_form" required>
                  <?php foreach (DOMAIN_SHOP_LEGAL_FORMS as $key => $label): ?>
                    <option value="<?= h($key) ?>"<?= (string) $owner['legal_form'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field"><label for="dm-org">Entreprise / organisation (si différente du nom)</label><input type="text" id="dm-org" name="owner_organization" maxlength="100" value="<?= h((string) $owner['organization']) ?>"></div>
            </div>
            <div class="field-row-4">
              <div class="field"><label for="dm-cp">Code postal *</label><input type="text" id="dm-cp" name="owner_postal_code" maxlength="20" required value="<?= h((string) $owner['postal_code']) ?>"></div>
              <div class="field"><label for="dm-city">Ville *</label><input type="text" id="dm-city" name="owner_city" maxlength="80" required value="<?= h((string) $owner['city']) ?>"></div>
            </div>
            <div class="field"><label for="dm-address">Adresse *</label><input type="text" id="dm-address" name="owner_address" maxlength="160" required value="<?= h((string) $owner['address']) ?>"></div>
            <div class="field-row-2">
              <div class="field"><label for="dm-country">Pays (code ISO) *</label><input type="text" id="dm-country" name="owner_country" maxlength="2" pattern="[A-Za-z]{2}" required value="<?= h((string) $owner['country']) ?>"></div>
              <div class="field"><label for="dm-phone">Téléphone *</label><input type="tel" id="dm-phone" name="owner_phone" maxlength="30" required value="<?= h((string) $owner['phone']) ?>" placeholder="06 12 34 56 78"></div>
            </div>
            <div class="field"><label for="dm-email">E-mail du titulaire *</label><input type="email" id="dm-email" name="owner_email" maxlength="254" required value="<?= h((string) $owner['email']) ?>"></div>
            <label class="check"><input type="checkbox" name="owner_legal_age" value="1" required> Je certifie que le titulaire est majeur et habilité à enregistrer ce domaine.</label>
            <label class="check"><input type="checkbox" name="domain_terms" value="1" required> J’accepte les conditions d’enregistrement d’OVH et les règles de l’extension choisie.</label>
            <label class="check"><input type="checkbox" name="waive_retraction" value="1" required> Je demande l’exécution immédiate du service et renonce au délai de rétractation une fois le domaine enregistré.</label>
            <div class="dm-actions">
              <button type="submit" class="btn btn-primary">Vérifier le prix chez OVH</button>
              <a class="btn" href="/admin/domaine.php">Annuler</a>
            </div>
            <p class="dm-note">Le devis et le paiement suivent : le prix est confirmé à l’étape suivante avant tout encaissement.</p>
          </form>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
</main>
</body>
</html>
