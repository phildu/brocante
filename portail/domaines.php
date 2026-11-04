<?php
// Noms de domaine de la plateforme, achetés depuis le portail : même parcours
// qu'une boutique (recherche OVH, titulaire, devis, paiement Stripe), enregistré
// sous l'identifiant réservé « plateforme » — même fichier d'état et même webhook.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/domain-shop.php';
require_once PORTAIL_ROOT . '/includes/domain-custom.php';

if (defined('GA_LIMITED')) { header('Location: /portail/galerie-tableau.php'); exit; }

$slug = 'plateforme';
$self = '/portail/domaines.php';
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' €';

// Retour de Stripe : la session est relue chez Stripe, jamais crue sur parole.
if (isset($_GET['s'])) {
    $sessionId = (string) $_GET['s'];
    if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
        try {
            $session = saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
            $result = domain_shop_apply_session($session);
            $domain = (string) ($result['purchase']['quote']['domain'] ?? 'le domaine');
            if ($result['state'] === 'ordered') {
                portail_flash('Merci ! La commande de ' . $domain . ' a été transmise à OVH.');
            } elseif ($result['state'] === 'test') {
                portail_flash('Paiement de test enregistré : la commande de ' . $domain . ' n’a pas été transmise à OVH (aucun débit réel). Passez Stripe en mode réel pour commander.', 'error');
            } elseif ($result['state'] === 'already') {
                $alreadyState = (string) ($result['purchase']['state'] ?? '');
                if ($alreadyState === 'ordered') {
                    portail_flash('Ce paiement est déjà pris en compte : la commande de ' . $domain . ' est enregistrée.');
                } elseif ($alreadyState === 'test_paid') {
                    portail_flash('Ce paiement de test est déjà enregistré : aucune commande OVH n’a été passée.', 'error');
                } elseif ($alreadyState === 'paid_needs_review') {
                    portail_flash('Ce paiement est déjà pris en compte, mais OVH n’a pas confirmé la commande : vérifiez-la depuis le compte OVH.', 'error');
                } else {
                    portail_flash('Ce paiement est déjà pris en compte : la commande de ' . $domain . ' est en cours de transmission à OVH.');
                }
            } elseif ($result['state'] === 'review') {
                portail_flash('Le paiement est confirmé, mais OVH n’a pas confirmé la commande. Vérifiez le panier et la commande dans le compte OVH.', 'error');
            } elseif ($result['state'] === 'unpaid') {
                portail_flash('Le paiement n’est pas encore confirmé par Stripe. La commande partira dès sa confirmation.', 'error');
            } else {
                portail_flash('Le paiement a été reçu, mais la commande du domaine n’a pas pu être appliquée : ' . (string) ($result['message'] ?? 'vérifiez le domaine et recommencez.'), 'error');
            }
        } catch (Throwable $ex) {
            error_log('portail domaines return: ' . $ex->getMessage());
            portail_flash('Impossible de vérifier le paiement pour le moment : réessayez dans un instant.', 'error');
        }
    } else {
        portail_flash('Session de paiement invalide.', 'error');
    }
    header('Location: ' . $self, true, 303);
    exit;
}

$data = domain_shop_load($slug);
$search = is_array($data['search'] ?? null) ? $data['search'] : null;
$purchase = is_array($data['purchase'] ?? null) ? $data['purchase'] : null;
$history = array_reverse((array) ($data['history'] ?? []));
$state = (string) ($purchase['state'] ?? '');
$quote = (array) ($purchase['quote'] ?? []);
$amount = (int) ($quote['amount_cents'] ?? 0);
$ovhPrice = (int) ($quote['ovh_cents'] ?? 0);
$margin = max(0, $amount - $ovhPrice);
$quoteExpired = $state === 'quoted' && time() - (int) ($purchase['created_at'] ?? 0) > 86400;

$ovhReady = false;
$ovhError = '';
try {
    $ovhConfig = branding_domain_config();
    $ovhReady = $ovhConfig['application_key'] !== '' && $ovhConfig['application_secret'] !== '' && $ovhConfig['consumer_key'] !== '';
    if (!$ovhReady) $ovhError = 'Les identifiants API OVH manquent : renseignez-les dans API & réseaux → API OVH (domaines).';
} catch (Throwable $ex) {
    $ovhError = $ex->getMessage();
}
$stripeReady = saas_stripe_configured();
$stripeLive = $stripeReady && saas_stripe_live();

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
$platformHost = preg_replace('#^https?://#', '', saas_origin());

// Domaines déjà achetés par les marchands et rattachés à leur boutique.
$customDomains = domain_custom_list();
$domainIp = (string) (saas_config()['domain_ip'] ?? '');
$shopNames = [];
foreach (array_keys(tenant_list()) as $shopSlug) {
    $shopNames[$shopSlug] = (string) tenant_load($shopSlug)['name'];
}

$owner = (array) ($purchase['owner'] ?? []);
if (!$owner) {
    $owner = [
        'first_name' => '',
        'last_name' => '',
        'organization' => '',
        'legal_form' => 'individual',
        'address' => '',
        'postal_code' => '',
        'city' => '',
        'country' => 'FR',
        'phone' => '',
        'email' => (string) saas_config()['contact'],
    ];
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Noms de domaine — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .dmwrap { display: grid; gap: 16px; max-width: 880px; margin: 0 auto; padding: 0 16px 48px; }
  .dmcard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .dmcard h2 { margin: 0; font-size: 1.05rem; }
  .dmcard p { margin: 0; }
  .dmcard .meta { color: var(--muted); font-size: .84rem; }
  .dmwarn { border: 1px solid #e0c58a; background: #fff7e0; color: #5a4300; border-radius: 10px; padding: 12px 14px; font-size: .88rem; }
  .dmfield { display: grid; gap: 4px; font-size: .8rem; font-weight: 600; }
  .dmfield input, .dmfield select { padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; font-weight: 400; width: 100%; min-width: 0; }
  .dmrow { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
  .dmform { display: grid; gap: 12px; }
  .dmcheck { display: flex; gap: 8px; align-items: flex-start; font-size: .86rem; color: var(--muted); font-weight: 400; }
  .dmcheck input { margin-top: 3px; }
  .dmsearch { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; }
  .dmsearch .dmfield { flex: 1 1 260px; }
  .dmtable { width: 100%; border-collapse: collapse; font-size: .9rem; }
  .dmtable th, .dmtable td { padding: 8px; border-bottom: 1px solid var(--line); text-align: left; }
  .dmtable th { font-size: .75rem; opacity: .7; font-weight: 600; }
  .dmtable .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .dmok { color: var(--ok); font-weight: 600; }
  .dmno { opacity: .6; }
  .dmactions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .dmquote { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; }
  .dmprice { font-family: var(--font-mono, monospace); font-size: 1.4rem; font-variant-numeric: tabular-nums; }
  .dmprice small { display: block; font-size: .72rem; opacity: .7; font-weight: 400; }
  @media (max-width: 720px) { .dmquote { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Noms de domaine',
    'eyebrow' => 'Plateforme',
    'desc' => 'Adresse de la plateforme : disponibilité et prix chez OVH, paiement par carte, commande transmise à OVH après confirmation du paiement.',
    'actions' => '<a class="btn" href="/portail/api.php?s=ovh">Identifiants OVH</a><a class="btn" href="/portail/offres.php#stripe">Clés Stripe</a>',
]); ?>
  <?php $flash = portail_flash(); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <div class="dmwrap">
    <section class="dmcard">
      <h2>Comptes utilisés</h2>
      <p>
        <span class="pill <?= $ovhReady ? 'done' : 'sent' ?>">OVH <?= $ovhReady ? 'configuré' : 'non configuré' ?></span>
        <span class="pill <?= $stripeReady ? 'done' : 'sent' ?>">Stripe <?= !$stripeReady ? 'non configuré' : ($stripeLive ? 'mode réel' : 'mode test') ?></span>
      </p>
      <p class="meta">Les identifiants OVH et le compte Stripe sont ceux de la plateforme : les mêmes que pour les inscriptions et les boutiques. L’adresse actuelle reste <strong><?= e($platformHost) ?></strong> tant que le domaine acheté n’est pas mis en service.</p>
      <?php if ($stripeReady && !$stripeLive): ?>
        <p class="dmwarn"><strong>Stripe est en mode test.</strong> Le paiement d’essai ne débite aucune carte et n’est pas transmis à OVH (il est enregistré comme paiement de test). Pour commander réellement, enregistrez les clés réelles dans les clés Stripe de la plateforme.</p>
      <?php endif; ?>
      <?php if (!$ovhReady): ?>
        <p class="dmwarn"><?= e($ovhError) ?></p>
      <?php endif; ?>
    </section>

    <?php if ($state === 'ordered'): ?>
      <?php
        $orderedDomain = (string) ($quote['domain'] ?? '');
        $orderedDate = date('d/m/Y à H:i', (int) ($purchase['ordered_at'] ?? time()));
      ?>
      <section class="dmcard">
        <h2>Domaine commandé — <?= e($orderedDomain) ?></h2>
        <p>Commande transmise à OVH le <?= e($orderedDate) ?><?= !empty($purchase['ovh_order_id']) ? ' (n° ' . e((string) $purchase['ovh_order_id']) . ')' : '' ?>.</p>
        <p class="meta">La réservation couvre la première année ; le renouvellement est facturé chaque année par OVH sur le compte de la plateforme. La mise en service (DNS, certificat) se fait ensuite côté hébergement.</p>
        <form method="post" action="/portail/domaines-action.php" class="dmactions" onsubmit="return confirm('Archiver cette commande et préparer un autre domaine ?');">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="archive">
          <button type="submit" class="btn btn-primary">Acheter un autre domaine</button>
        </form>
      </section>
    <?php elseif ($state === 'test_paid'): ?>
      <section class="dmcard">
        <h2>Paiement de test enregistré</h2>
        <p><strong><?= e((string) ($quote['domain'] ?? 'le domaine')) ?></strong> a été « payé » avec Stripe en mode test : aucun argent n’a été encaissé et <strong>aucune commande n’a été passée chez OVH</strong>.</p>
        <p class="meta">Effacez ce paiement de test, puis passez Stripe en mode réel pour commander le domaine.</p>
        <form method="post" action="/portail/domaines-action.php" class="dmactions">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn">Effacer et recommencer</button>
        </form>
      </section>
    <?php elseif ($state === 'paid_needs_review'): ?>
      <section class="dmcard">
        <h2>Paiement reçu — commande à vérifier</h2>
        <p>Le paiement de <strong><?= e((string) ($quote['domain'] ?? 'votre domaine')) ?></strong> est confirmé, mais OVH n’a pas confirmé la commande. Aucune nouvelle tentative n’est faite automatiquement.</p>
        <p class="meta"><?= e((string) ($purchase['message'] ?? 'Vérifiez la commande dans le compte OVH.')) ?></p>
      </section>
    <?php elseif ($state === 'ordering'): ?>
      <section class="dmcard">
        <h2>Commande en cours</h2>
        <p>Le paiement de <strong><?= e((string) ($quote['domain'] ?? 'votre domaine')) ?></strong> est confirmé. La commande est en cours de transmission à OVH.</p>
        <?php if (time() - (int) ($purchase['paid_at'] ?? 0) > 600): ?>
          <p class="dmwarn">Cet état dure anormalement longtemps : vérifiez la commande dans le compte OVH.</p>
        <?php endif; ?>
      </section>
    <?php elseif ($state === 'awaiting_payment'): ?>
      <section class="dmcard">
        <h2>Paiement en attente</h2>
        <p>Le paiement de <strong><?= e((string) ($quote['domain'] ?? '')) ?></strong> (<?= e($money($amount)) ?>) est ouvert chez Stripe mais n’a pas encore été confirmé.</p>
        <div class="dmactions">
          <?php if (!empty($purchase['stripe_url'])): ?>
            <a class="btn btn-primary" href="<?= e((string) $purchase['stripe_url']) ?>">Reprendre le paiement</a>
          <?php endif; ?>
          <form method="post" action="/portail/domaines-action.php" onsubmit="return confirm('Annuler ce paiement et libérer le domaine ?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn">Annuler la commande</button>
          </form>
        </div>
        <p class="meta">La commande n’est transmise à OVH qu’après confirmation du paiement. Après environ une heure, le lien Stripe peut expirer : annulez puis recommencez.</p>
      </section>
    <?php elseif ($state === 'quoted' && !$quoteExpired): ?>
      <section class="dmcard">
        <h2>Devis prêt — <?= e((string) ($quote['domain'] ?? '')) ?></h2>
        <div class="dmquote">
          <div>
            <p>Titulaire : <strong><?= e(trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''))) ?><?= ($owner['organization'] ?? '') !== '' ? ' — ' . e((string) $owner['organization']) : '' ?></strong><br>
              <?= e((string) ($owner['address'] ?? '')) ?>, <?= e((string) ($owner['postal_code'] ?? '')) ?> <?= e((string) ($owner['city'] ?? '')) ?>, <?= e((string) ($owner['country'] ?? '')) ?><br>
              <?= e((string) ($owner['email'] ?? '')) ?> · <?= e((string) ($owner['phone'] ?? '')) ?></p>
            <p class="meta">Enregistrement pour 1 an. Devis OVH valable 24 heures. Le renouvellement annuel n’est pas inclus.</p>
          </div>
          <div class="dmprice"><?= e($money($amount)) ?><small><?= $margin > 0 ? 'dont ' . e($money($margin)) . ' de frais de service' : 'prix OVH TTC' ?></small></div>
        </div>
        <div class="dmactions">
          <?php if ($stripeReady): ?>
            <form method="post" action="/portail/domaines-action.php">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="pay">
              <button type="submit" class="btn btn-primary">Payer par carte</button>
            </form>
          <?php else: ?>
            <p class="meta">Le paiement en ligne n’est pas configuré : enregistrez les clés Stripe de la plateforme.</p>
          <?php endif; ?>
          <form method="post" action="/portail/domaines-action.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn">Changer de domaine</button>
          </form>
        </div>
      </section>
    <?php elseif ($state === 'quoted' && $quoteExpired): ?>
      <section class="dmcard">
        <h2>Devis expiré</h2>
        <p>Le devis pour <strong><?= e((string) ($quote['domain'] ?? '')) ?></strong> a plus de 24 heures. Vérifiez à nouveau le domaine pour obtenir un prix actuel.</p>
        <form method="post" action="/portail/domaines-action.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn">Recommencer</button>
        </form>
      </section>
    <?php else: ?>
      <section class="dmcard">
        <h2>Rechercher une adresse</h2>
        <p class="meta">Saisissez un nom (nous testons les extensions proches) ou une adresse complète avec son extension, par exemple <code>brocs.fr</code>.</p>
        <form class="dmsearch" method="post" action="/portail/domaines-action.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="search">
          <label class="dmfield" for="dm-q">Nom ou domaine
            <input type="text" id="dm-q" name="q" maxlength="120" value="<?= e((string) ($search['query'] ?? 'brocs')) ?>" placeholder="Ex. brocs ou brocs.fr"<?= $ovhReady ? '' : ' disabled' ?>>
          </label>
          <button type="submit" class="btn btn-primary"<?= $ovhReady ? '' : ' disabled' ?>>Vérifier la disponibilité</button>
        </form>
        <p class="meta">La recherche interroge OVH : les prix sont ceux du jour et peuvent changer avant la commande. Rien n’est commandé tant que le paiement n’est pas confirmé.</p>
      </section>

      <?php if ($search): ?>
        <section class="dmcard">
          <h2>Résultats</h2>
          <p class="meta">Recherche « <?= e((string) ($search['query'] ?? '')) ?> » du <?= e(date('d/m/Y à H:i', (int) ($search['searched_at'] ?? time()))) ?> auprès d’OVH · <?= $available ?> adresse<?= $available > 1 ? 's' : '' ?> disponible<?= $available > 1 ? 's' : '' ?>.</p>
          <?php if ($searchStale): ?>
            <p class="dmwarn">Cette recherche date de plus de 24 heures : relancez-la pour obtenir un prix actuel.</p>
          <?php endif; ?>
          <?php if (!empty($search['price_errors'])): ?>
            <p class="meta">Certains tarifs n’ont pas pu être obtenus : <?= e(implode(' ; ', (array) $search['price_errors'])) ?></p>
          <?php endif; ?>
          <table class="dmtable">
            <thead><tr><th>Domaine</th><th>Disponibilité</th><th class="num">1re année</th><th></th></tr></thead>
            <tbody>
              <?php foreach ((array) ($search['domains'] ?? []) as $candidate): ?>
                <?php $candidateDomain = (string) ($candidate['domain'] ?? ''); ?>
                <tr>
                  <td><strong><?= e($candidateDomain) ?></strong><?= ($candidate['kind'] ?? '') === 'alternative' ? ' <span class="pill sent">variante</span>' : '' ?></td>
                  <td><?= !empty($candidate['available']) ? '<span class="dmok">Disponible</span>' : '<span class="dmno">Indisponible</span>' ?></td>
                  <td class="num"><?= isset($candidate['price_cents']) ? e($money((int) $candidate['price_cents'])) : '—' ?></td>
                  <td>
                    <?php if (!empty($candidate['available']) && !$searchStale): ?>
                      <a class="btn small" href="<?= e($self . '?choisir=' . rawurlencode($candidateDomain)) ?>">Choisir</a>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php if (!empty($candidate['price_error'])): ?>
                  <tr><td colspan="4" class="dmno"><?= e((string) $candidate['price_error']) ?></td></tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      <?php endif; ?>

      <?php if ($chosen): ?>
        <section class="dmcard">
          <h2>Titulaire du domaine <?= e((string) $chosen['domain']) ?></h2>
          <p class="meta">Ces informations créent le contact titulaire chez OVH : la personne ou l’organisation indiquée devient propriétaire du domaine. Elles doivent être exactes.</p>
          <form class="dmform" method="post" action="/portail/domaines-action.php" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="quote">
            <input type="hidden" name="domain" value="<?= e((string) $chosen['domain']) ?>">
            <div class="dmrow">
              <label class="dmfield" for="dm-first">Prénom *<input type="text" id="dm-first" name="owner_first_name" maxlength="60" required value="<?= e((string) $owner['first_name']) ?>"></label>
              <label class="dmfield" for="dm-last">Nom *<input type="text" id="dm-last" name="owner_last_name" maxlength="60" required value="<?= e((string) $owner['last_name']) ?>"></label>
            </div>
            <div class="dmrow">
              <label class="dmfield" for="dm-form">Forme juridique *
                <select id="dm-form" name="owner_legal_form" required>
                  <?php foreach (DOMAIN_SHOP_LEGAL_FORMS as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= (string) $owner['legal_form'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="dmfield" for="dm-org">Entreprise / organisation (si différente du nom)<input type="text" id="dm-org" name="owner_organization" maxlength="100" value="<?= e((string) $owner['organization']) ?>"></label>
            </div>
            <div class="dmrow">
              <label class="dmfield" for="dm-address">Adresse *<input type="text" id="dm-address" name="owner_address" maxlength="160" required value="<?= e((string) $owner['address']) ?>"></label>
              <label class="dmfield" for="dm-cp">Code postal *<input type="text" id="dm-cp" name="owner_postal_code" maxlength="20" required value="<?= e((string) $owner['postal_code']) ?>"></label>
              <label class="dmfield" for="dm-city">Ville *<input type="text" id="dm-city" name="owner_city" maxlength="80" required value="<?= e((string) $owner['city']) ?>"></label>
            </div>
            <div class="dmrow">
              <label class="dmfield" for="dm-country">Pays (code ISO) *<input type="text" id="dm-country" name="owner_country" maxlength="2" pattern="[A-Za-z]{2}" required value="<?= e((string) $owner['country']) ?>"></label>
              <label class="dmfield" for="dm-phone">Téléphone *<input type="tel" id="dm-phone" name="owner_phone" maxlength="30" required value="<?= e((string) $owner['phone']) ?>" placeholder="06 12 34 56 78"></label>
            </div>
            <label class="dmfield" for="dm-email">E-mail du titulaire *<input type="email" id="dm-email" name="owner_email" maxlength="254" required value="<?= e((string) $owner['email']) ?>"></label>
            <label class="dmcheck"><input type="checkbox" name="owner_legal_age" value="1" required> Je certifie que le titulaire est majeur et habilité à enregistrer ce domaine.</label>
            <label class="dmcheck"><input type="checkbox" name="domain_terms" value="1" required> J’accepte les conditions d’enregistrement d’OVH et les règles de l’extension choisie.</label>
            <label class="dmcheck"><input type="checkbox" name="waive_retraction" value="1" required> Je demande l’exécution immédiate du service et renonce au délai de rétractation une fois le domaine enregistré.</label>
            <div class="dmactions">
              <button type="submit" class="btn btn-primary">Vérifier le prix chez OVH</button>
              <a class="btn" href="<?= e($self) ?>">Annuler</a>
            </div>
            <p class="meta">Le devis et le paiement suivent : le prix est confirmé à l’étape suivante avant tout encaissement.</p>
          </form>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <section class="dmcard">
      <h2>Domaines déjà achetés par les boutiques</h2>
      <p class="meta">Une boutique peut rattacher un domaine qu’elle possède déjà : elle prouve la propriété par un enregistrement TXT, pointe le domaine vers le serveur, puis demande la mise en service. Avant d’activer, déclarez le domaine comme alias d’hébergement (le certificat HTTPS est alors émis) et vérifiez que <code>https://&lt;domaine&gt;/</code> affiche la boutique.</p>
      <?php if (!$customDomains): ?>
        <p class="meta">Aucune boutique n’a rattaché de domaine existant pour le moment.</p>
      <?php else: ?>
        <table class="dmtable">
          <thead><tr><th>Boutique</th><th>Adresse</th><th>État</th><th>Dernier contrôle</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($customDomains as $item): ?>
              <?php
                $itemSlug = (string) $item['slug'];
                $itemState = (string) ($item['state'] ?? '');
                $itemDomain = (string) ($item['domain'] ?? '');
                $itemChecked = (int) ($item['checked_at'] ?? 0);
              ?>
              <tr>
                <td><strong><?= e($shopNames[$itemSlug] ?? $itemSlug) ?></strong><br><span class="meta"><?= e($itemSlug) ?></span></td>
                <td><code><?= e($itemDomain) ?></code></td>
                <td><span class="pill <?= $itemState === 'active' ? 'done' : ($itemState === 'ready' ? 'sent' : '') ?>"><?= e(domain_custom_state_label($itemState)) ?></span></td>
                <td><?= $itemChecked > 0 ? e(date('d/m/Y à H:i', $itemChecked)) : '<span class="dmno">—</span>' ?></td>
                <td>
                  <div class="dmactions">
                    <?php if ($itemState === 'ready'): ?>
                      <form method="post" action="/portail/domaines-action.php" onsubmit="return confirm('Activer <?= e($itemDomain) ?> pour la boutique <?= e($itemSlug) ?> ? Vérifiez d’abord que https://<?= e($itemDomain) ?>/ affiche bien la boutique.');">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="custom_activate">
                        <input type="hidden" name="shop" value="<?= e($itemSlug) ?>">
                        <button type="submit" class="btn btn-primary small">Activer</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" action="/portail/domaines-action.php" onsubmit="return confirm('Détacher <?= e($itemDomain) ?> de la boutique <?= e($itemSlug) ?> ?<?= $itemState === 'active' ? ' La boutique ne répondra plus à cette adresse ; retirez aussi l’alias d’hébergement et le certificat.' : '' ?>');">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="custom_remove">
                      <input type="hidden" name="shop" value="<?= e($itemSlug) ?>">
                      <button type="submit" class="btn small">Détacher</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <form class="dmform" method="post" action="/portail/domaines-action.php" style="margin-top:6px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_custom_ip">
        <div class="dmrow">
          <label class="dmfield">Adresse IP du serveur (enregistrement A)
            <input type="text" name="domain_ip" maxlength="45" value="<?= e($domainIp) ?>" placeholder="Ex. 213.186.33.3">
          </label>
          <div class="dmactions" style="align-self:end;">
            <button type="submit" class="btn">Enregistrer l’adresse</button>
          </div>
        </div>
        <p class="meta">Adresse donnée aux boutiques dans les consignes DNS et utilisée pour confirmer le pointage du domaine. Sur un hébergement mutualisé OVH, c’est l’adresse IP mutualisée du compte. Vide : l’adresse du serveur qui répond est proposée.</p>
      </form>
    </section>

    <?php if ($history): ?>
      <section class="dmcard">
        <h2>Domaines achetés</h2>
        <table class="dmtable">
          <thead><tr><th>Domaine</th><th>Commandé le</th><th>Commande OVH</th></tr></thead>
          <tbody>
            <?php foreach ($history as $item): ?>
              <tr>
                <td><strong><?= e((string) ($item['quote']['domain'] ?? '—')) ?></strong></td>
                <td><?= e(date('d/m/Y', (int) ($item['ordered_at'] ?? $item['archived_at'] ?? time()))) ?></td>
                <td><?= !empty($item['ovh_order_id']) ? e((string) $item['ovh_order_id']) : '<span class="dmno">—</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>

    <section class="dmcard">
      <h2>Ce que couvre le paiement</h2>
      <p class="meta">Première année d’enregistrement, prix OVH TTC plus les frais de service de la plateforme (réglables dans API & réseaux → API OVH). Le renouvellement annuel est facturé par OVH sur le compte de la plateforme ; il n’est pas prélevé ici. Après l’achat, la mise en service du domaine (enregistrements DNS vers ce serveur, certificat HTTPS) reste à faire côté hébergement.</p>
    </section>
  </div>
</div>
</body>
</html>
