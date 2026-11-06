<?php
// Réglages SMS de la plateforme : un compte OVH ou Twilio saisi une fois ici
// alimente toutes les boutiques qui n'ont pas les leurs (code de connexion,
// annonces des actions commerciales, alertes de l'assistance vendeur). Une
// boutique peut toujours surcharger ces valeurs dans son administration : elles
// restent prioritaires.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/sms.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';   // saas_money(), saas_event()

$shops = tenant_list();

/** « 0,09 » ou « 9 c » → 9 centimes ; '' ou illisible → null. */
function portail_sms_cents(string $s): ?int
{
    $s = str_replace([' ', "\u{00a0}", '€'], '', trim($s));
    if ($s === '' || !is_numeric(str_replace(',', '.', $s))) return null;
    return max(0, (int) round((float) str_replace(',', '.', $s) * 100));
}

/** Habitudes SMS propres à une boutique, lues dans sa base (le portail n'est pas dans son contexte). */
function portail_sms_shop_own(array $shop): array
{
    $dbFile = tenant_file($shop, 'db_file');
    $rows = [];
    if (!is_file($dbFile)) return ['known' => false, 'ovh' => false, 'twilio' => false];
    try {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
        foreach (['ovh_sms_account', 'ovh_sms_login', 'ovh_sms_password', 'ovh_sms_from', 'twilio_sid', 'twilio_token', 'twilio_from'] as $name) {
            $stmt->execute([$name]);
            $rows[$name] = trim((string) $stmt->fetchColumn());
        }
    } catch (Throwable $e) {
        return ['known' => false, 'ovh' => false, 'twilio' => false];
    }
    return [
        'known' => true,
        'ovh' => $rows['ovh_sms_account'] !== '' && $rows['ovh_sms_login'] !== '' && $rows['ovh_sms_password'] !== '' && $rows['ovh_sms_from'] !== '',
        'twilio' => $rows['twilio_sid'] !== '' && $rows['twilio_token'] !== '' && $rows['twilio_from'] !== '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    switch ((string) ($_POST['action'] ?? '')) {
        case 'save':
            [$values, $warn] = sms_shared_merge(sms_shared(), $_POST);
            if (!sms_shared_save($values)) {
                portail_flash("Impossible d'enregistrer les réglages (écriture de data/sms-partage.json refusée).", 'error');
                break;
            }
            $provider = sms_shared_provider_name();
            $label = sms_provider_label($provider);
            $message = $label !== ''
                ? 'Réglages SMS enregistrés : ' . $label . ' est complet, les boutiques sans identifiants propres l’utilisent.'
                : "Réglages SMS enregistrés, mais aucun fournisseur n'est complet : les boutiques sans identifiants propres restent bloquées.";
            portail_flash($message . ($warn !== '' ? ' ' . $warn : ''), $label !== '' && $warn === '' ? 'ok' : 'error');
            break;
        case 'test':
            $to = trim((string) ($_POST['sms_test_to'] ?? ''));
            if (sms_phone_e164($to) === null) {
                portail_flash('Numéro non reconnu : indiquez-le au format international, par exemple +33 6 12 34 56 78.', 'error');
                break;
            }
            $provider = sms_shared_provider_name();
            if ($provider === '') {
                portail_flash("Aucun fournisseur complet dans les réglages du portail : enregistrez d'abord les identifiants.", 'error');
                break;
            }
            $body = 'Brocs : test SMS du portail.';
            if ($provider === 'ovh') {
                $answer = '';
                $ok = sms_send_ovh($to, $body, sms_ovh_config(SMS_SOURCE_SHARED), $answer);
                portail_flash($ok
                    ? 'SMS de test envoyé par OVH à ' . $to . '.'
                    : 'OVH n’a pas accepté le message : ' . ($answer !== '' ? mb_substr(trim($answer), 0, 200) : 'aucune réponse (vérifiez le compte, le mot de passe et l’IP autorisée).'),
                    $ok ? 'ok' : 'error');
            } else {
                $status = null;
                $ok = sms_send_twilio($to, $body, sms_twilio_config(SMS_SOURCE_SHARED), $status);
                portail_flash($ok
                    ? 'SMS de test envoyé par Twilio à ' . $to . '.'
                    : 'Twilio a refusé le message (HTTP ' . ($status ?? '—') . ') : vérifiez le SID, le jeton et le numéro expéditeur.',
                    $ok ? 'ok' : 'error');
            }
            break;
        case 'pricing':
            $unit = portail_sms_cents((string) ($_POST['unit_eur'] ?? ''));
            $cost = portail_sms_cents((string) ($_POST['cost_eur'] ?? ''));
            if ($unit === null || $cost === null) {
                portail_flash('Indiquez les deux prix en euros, par exemple 0,09 et 0,04.', 'error');
                break;
            }
            $ok = saas_sms_pricing_save(['unit_cents' => $unit, 'cost_cents' => $cost]);
            if ($ok) saas_event('config_changed', ['note' => 'Barème des crédits SMS : ' . saas_money($unit) . ' facturé, ' . saas_money($cost) . ' de coût estimé', 'actor' => 'exploitant']);
            portail_flash($ok
                ? 'Barème SMS enregistré : ' . saas_money($unit) . ' facturés au SMS, ' . saas_money($cost) . ' de coût réel estimé.'
                : "Impossible d'écrire data/saas/sms-costs.json.", $ok ? 'ok' : 'error');
            break;
        case 'packs':
            $rows = [];
            foreach ((array) ($_POST['pack_name'] ?? []) as $i => $name) {
                $count = (int) ($_POST['pack_sms'][$i] ?? 0);
                $price = portail_sms_cents((string) ($_POST['pack_price'][$i] ?? ''));
                if (trim((string) $name) === '' || $count <= 0 || $price === null || $price <= 0) continue;
                $rows[] = ['key' => (string) ($_POST['pack_key'][$i] ?? ''), 'name' => (string) $name, 'sms' => $count, 'price_cents' => $price];
            }
            $ok = saas_sms_packs_save($rows);
            if ($ok) saas_event('config_changed', ['note' => 'Packs de crédit SMS enregistrés (' . count($rows) . ')', 'actor' => 'exploitant']);
            portail_flash($ok ? 'Packs de crédit SMS enregistrés.' : "Impossible d'écrire data/saas/sms-costs.json.", $ok ? 'ok' : 'error');
            break;
        case 'credit':
            $slug = (string) ($_POST['slug'] ?? '');
            if (!isset($shops[$slug])) {
                portail_flash('Boutique introuvable.', 'error');
                break;
            }
            if (!empty($_POST['unlimit'])) {
                $ok = sms_credit_reset($slug);
                if ($ok) saas_event('config_changed', ['slug' => $slug, 'note' => 'Crédit SMS retiré : boutique sans limite', 'actor' => 'exploitant']);
                portail_flash($ok
                    ? "Boutique « $slug » : les SMS sont de nouveau sans limite (son historique de crédit est effacé)."
                    : "Impossible d'écrire data/saas/sms-credits.json.", $ok ? 'ok' : 'error');
                break;
            }
            $count = (int) ($_POST['sms'] ?? 0);
            if ($count <= 0) {
                portail_flash('Indiquez un nombre de SMS à attribuer.', 'error');
                break;
            }
            $state = sms_credit_add($slug, $count, ['source' => 'exploitant', 'pack' => 'offert']);
            if ($state === 'added') {
                $left = sms_credit_account($slug)['left'];
                saas_event('sms_credit_granted', ['slug' => $slug, 'note' => 'Crédit SMS attribué : ' . number_format($count, 0, ',', ' ') . ' SMS', 'actor' => 'exploitant']);
                portail_flash("Boutique « $slug » : " . number_format($count, 0, ',', ' ') . ' SMS offerts (' . number_format($left, 0, ',', ' ') . ' restants). Ses envois sont désormais décomptés.');
            } else {
                portail_flash("Le crédit n'a pas pu être ajouté pour « $slug ».", 'error');
            }
            break;
        default:
            portail_flash('Action inconnue.', 'error');
    }
    header('Location: /portail/sms.php');
    exit;
}

$flash = portail_flash();
$values = sms_shared();
$ovh = sms_ovh_config(SMS_SOURCE_SHARED);
$twilio = sms_twilio_config(SMS_SOURCE_SHARED);
$provider = sms_shared_provider_name();
$chosen = strtolower($values['sms_provider']);
$from = $provider === 'ovh' ? $ovh['from'] : ($provider === 'twilio' ? $twilio['from'] : '');

$smsPricing = saas_sms_pricing();
$smsPackRows = saas_sms_packs();
while (count($smsPackRows) < 5) $smsPackRows[] = ['key' => '', 'name' => '', 'sms' => 0, 'price_cents' => 0];
$smsDecimal = static fn (int $cents, int $d): string => rtrim(rtrim(number_format($cents / 100, $d, ',', ''), '0'), ',');
$smsEuro = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' €';
$smsCreditShops = [];
foreach ($shops as $slug => $shop) $smsCreditShops[$slug] = sms_credit_account($slug);
$smsCredited = count(array_filter($smsCreditShops, static fn (array $a): bool => $a['enabled']));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>SMS — Portail des commerces</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .key-pair { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; margin: 10px 0; }
  .key-pair summary { cursor: pointer; font-weight: 700; }
  .shop-sms { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--line); }
  .shop-sms:first-of-type { border-top: 0; }
  .shop-sms .who { min-width: 220px; flex: 1; }
  .shop-sms .who small { display: block; opacity: .75; }
  .credit-table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
  .credit-table th, .credit-table td { padding: 8px 10px; border-top: 1px solid var(--line); text-align: left; vertical-align: middle; }
  .credit-table thead th { border-top: 0; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.75; font-weight: 600; white-space: nowrap; }
  .credit-table td.r, .credit-table th.r { text-align: right; font-variant-numeric: tabular-nums; }
  .credit-table input[type=text], .credit-table input[type=number] { width: 100%; min-width: 72px; }
  .credit-table small { display: block; opacity: 0.7; }
  .credit-grant { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
  .credit-grant input { width: 92px; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'SMS',
    'eyebrow' => 'Boutiques',
    'desc' => 'Un compte SMS commun à toutes les boutiques — code de connexion, annonces des actions commerciales, alertes de l’assistance vendeur — et les crédits SMS de chaque boutique.',
    'section' => 'Boutiques',
    'actions' => '<a class="btn" href="/portail/">← Portail des commerces</a>'
        . '<a class="btn" href="/portail/plugins.php">Plugins</a>'
        . '<a class="btn" href="/portail/api.php">API &amp; réseaux</a>',
]); ?>

  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="gwrap ga-page">
    <section class="gcard" id="reglages">
      <h2>Compte SMS du portail
        <span class="pill <?= $provider !== '' ? 'done' : 'sent' ?>"><?= $provider !== '' ? e(sms_provider_label($provider)) : 'à configurer' ?></span>
      </h2>
      <p class="meta">Ces identifiants servent à toutes les boutiques qui n’ont pas les leurs (leurs réglages d’administration restent prioritaires). <strong>OVH</strong> : compte <code>sms-xxxxx-1</code>, utilisateur SMS et son mot de passe, expéditeur déclaré — l’adresse IP du serveur doit être autorisée sur le compte, sinon OVH répond « unauthorized IP ». <strong>Twilio</strong> : SID, jeton et numéro expéditeur.</p>
      <p class="meta">Un mot de passe ou un jeton laissé vide garde la valeur enregistrée ; les autres champs se vident quand vous les videz (bascule d’un fournisseur à l’autre).</p>
      <p class="flash" data-kind="<?= $provider !== '' ? 'ok' : 'error' ?>" role="status">
        <?= $provider !== ''
          ? 'Prêt : ' . e(sms_provider_label($provider)) . ' — expéditeur « ' . e($from) . ' ».'
          : 'Aucun fournisseur complet : les boutiques sans identifiants propres ne peuvent pas envoyer de SMS (en local, les messages sont écrits dans data/sms-outbox.log).' ?>
      </p>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <label class="f">Fournisseur
          <select name="sms_provider">
            <option value=""<?= $chosen === '' ? ' selected' : '' ?>>Automatique — le premier des deux configuré</option>
            <option value="ovh"<?= $chosen === 'ovh' ? ' selected' : '' ?>>OVH</option>
            <option value="twilio"<?= $chosen === 'twilio' ? ' selected' : '' ?>>Twilio</option>
          </select>
        </label>

        <details class="key-pair"<?= $chosen !== 'twilio' ? ' open' : '' ?>>
          <summary>OVH — <?= sms_ovh_ready($ovh) ? 'complet' : 'à compléter' ?></summary>
          <div class="row">
            <label class="f">Compte SMS<input type="text" name="ovh_sms_account" value="<?= e($values['ovh_sms_account']) ?>" autocomplete="off" placeholder="sms-xxxxx-1"></label>
            <label class="f">Identifiant<input type="text" name="ovh_sms_login" value="<?= e($values['ovh_sms_login']) ?>" autocomplete="off"></label>
          </div>
          <div class="row">
            <label class="f">Mot de passe<input type="password" name="ovh_sms_password" autocomplete="new-password" placeholder="<?= $values['ovh_sms_password'] !== '' ? 'déjà enregistré — laisser vide pour le garder' : 'mot de passe de l’utilisateur SMS' ?>"></label>
            <label class="f">Expéditeur affiché<input type="text" name="ovh_sms_from" value="<?= e($values['ovh_sms_from']) ?>" maxlength="15" autocomplete="off" placeholder="BROCS"></label>
          </div>
          <p class="meta">11 caractères alphanumériques au plus (ou 15 chiffres) ; l’expéditeur doit être déclaré chez OVH et validé (1 à 2 jours ouvrés).</p>
        </details>

        <details class="key-pair"<?= $chosen === 'twilio' ? ' open' : '' ?>>
          <summary>Twilio — <?= sms_twilio_ready($twilio) ? 'complet' : 'à compléter' ?></summary>
          <div class="row">
            <label class="f">Account SID<input type="text" name="twilio_sid" value="<?= e($values['twilio_sid']) ?>" autocomplete="off" placeholder="AC…"></label>
            <label class="f">Numéro expéditeur<input type="text" name="twilio_from" value="<?= e($values['twilio_from']) ?>" autocomplete="off" placeholder="+33…"></label>
          </div>
          <label class="f">Auth token<input type="password" name="twilio_token" autocomplete="new-password" placeholder="<?= $values['twilio_token'] !== '' ? 'déjà enregistré — laisser vide pour le garder' : '' ?>"></label>
        </details>

        <p><button class="btn btn-primary" type="submit">Enregistrer les réglages SMS</button></p>
      </form>
    </section>

    <section class="gcard" id="test">
      <h2>SMS de test</h2>
      <p class="meta">Envoie un message au numéro indiqué avec les identifiants <strong>du portail</strong> (ceux de la boutique sont ignorés ici). OVH répond « OK » ou « KO » avec le motif, affiché ci-dessus après l’envoi.</p>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="test">
        <label class="f">Votre numéro<input type="tel" name="sms_test_to" placeholder="+33 6 12 34 56 78" required></label>
        <button class="btn btn-primary" type="submit"<?= $provider === '' ? ' disabled' : '' ?>>Envoyer un SMS de test</button>
      </form>
    </section>

    <section class="gcard" id="credits">
      <h2>Crédits SMS par boutique
        <span class="pill <?= $smsCredited > 0 ? 'done' : 'sent' ?>"><?= $smsCredited > 0 ? $smsCredited . ' décomptée' . ($smsCredited > 1 ? 's' : '') : 'toutes sans limite' ?></span>
      </h2>
      <p class="meta">Une boutique <strong>sans crédit n’est pas décomptée</strong> : c’est l’état de départ, pour ne couper aucun envoi existant. Dès que vous lui attribuez un crédit — offert ici, ou racheté par la boutique en Stripe — chaque SMS envoyé le consomme et les envois s’arrêtent à zéro. Un texte long compte plusieurs segments : 160 caractères sans accents (153 par partie au-delà), 70 avec accents (67 par partie). Le crédit n’expire pas.</p>

      <form method="post" autocomplete="off" style="margin-top:4px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="pricing">
        <div class="row">
          <label class="f">Prix facturé au SMS (€)
            <input type="text" inputmode="decimal" name="unit_eur" value="<?= e($smsDecimal($smsPricing['unit_cents'], 3)) ?>">
            <small>un segment ; sert à valoriser le crédit restant</small>
          </label>
          <label class="f">Coût réel estimé du SMS (€)
            <input type="text" inputmode="decimal" name="cost_eur" value="<?= e($smsDecimal($smsPricing['cost_cents'], 3)) ?>">
            <small>prix payé au fournisseur, pour la marge affichée</small>
          </label>
          <button class="btn btn-primary" type="submit" style="align-self:end">Enregistrer le barème</button>
        </div>
        <p class="meta">Marge brute par SMS : <strong><?= e($smsEuro(max(0, $smsPricing['unit_cents'] - $smsPricing['cost_cents']))) ?></strong> (<?= e($smsEuro($smsPricing['unit_cents'])) ?> facturés − <?= e($smsEuro($smsPricing['cost_cents'])) ?> de coût).</p>
      </form>

      <h3 style="margin-top:18px;">Packs à racheter</h3>
      <p class="meta">Proposés dans l’administration de chaque boutique décomptée (paiement unique par Stripe). Une ligne sans nom, sans nombre de SMS ou sans prix est ignorée.</p>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="packs">
        <div class="scroll"><table class="credit-table">
          <thead><tr><th>Nom</th><th>SMS</th><th>Prix payé (€)</th><th class="r">Prix du SMS</th><th class="r">Marge brute</th></tr></thead>
          <tbody>
          <?php foreach ($smsPackRows as $i => $pk): $c = (int) $pk['price_cents']; $n = (int) $pk['sms']; ?>
            <tr>
              <td>
                <input type="text" name="pack_name[<?= $i ?>]" maxlength="40" value="<?= e($pk['name']) ?>" placeholder="ex : Recharge">
                <input type="hidden" name="pack_key[<?= $i ?>]" value="<?= e($pk['key']) ?>">
              </td>
              <td><input type="number" min="0" step="1" name="pack_sms[<?= $i ?>]" value="<?= $n ?: '' ?>" placeholder="100"></td>
              <td><input type="text" inputmode="decimal" name="pack_price[<?= $i ?>]" value="<?= $c > 0 ? e($smsDecimal($c, 2)) : '' ?>" placeholder="9,00"></td>
              <td class="r"><?= $n > 0 && $c > 0 ? e($smsDecimal((int) round($c / $n), 3)) . ' €' : '—' ?></td>
              <td class="r"><?= $n > 0 && $c > 0 ? e($smsEuro($c - $n * $smsPricing['cost_cents'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="meta" style="margin-top:8px"><?= saas_stripe_configured() ? '' : '<strong style="color:#b3261e">Le paiement Stripe de la plateforme n’est pas configuré : les boutiques ne peuvent pas racheter de crédit pour l’instant.</strong>' ?></p>
        <p><button class="btn btn-primary" type="submit">Enregistrer les packs</button></p>
      </form>

      <h3 style="margin-top:18px;">Boutiques</h3>
      <p class="meta">Attribuer un crédit active le décompte de la boutique. « Sans limite » efface son crédit et son historique d’envois : elle repasse en envois non décomptés.</p>
      <div class="scroll"><table class="credit-table">
        <thead><tr><th>Boutique</th><th>État</th><th class="r">Acheté</th><th class="r">Envoyés</th><th class="r">Restant</th><th>Attribuer</th></tr></thead>
        <tbody>
        <?php foreach ($shops as $slug => $shop): $acc = $smsCreditShops[$slug]; ?>
          <tr>
            <td><strong><?= e($shop['name']) ?></strong><small><?= e($slug) ?></small></td>
            <td>
              <?php if (!$acc['enabled']): ?><span class="pill">sans limite</span>
              <?php elseif ($acc['left'] > 0): ?><span class="pill done">actif</span>
              <?php else: ?><span class="pill sent">suspendu</span><?php endif; ?>
            </td>
            <td class="r"><?= $acc['enabled'] ? number_format($acc['purchased'], 0, ',', ' ') : '—' ?></td>
            <td class="r"><?= $acc['enabled'] ? number_format($acc['used'], 0, ',', ' ') : '—' ?></td>
            <td class="r"><?= $acc['enabled'] ? number_format($acc['left'], 0, ',', ' ') : '—' ?></td>
            <td>
              <form method="post" class="credit-grant">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="credit">
                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                <input type="number" name="sms" min="1" max="1000000" placeholder="SMS" aria-label="SMS à offrir à <?= e($shop['name']) ?>">
                <button class="btn small" type="submit">Attribuer</button>
                <?php if ($acc['enabled']): ?><button class="btn small" type="submit" name="unlimit" value="1" title="Retire le crédit et repasse la boutique en SMS non décomptés">Sans limite</button><?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="meta">Les boutiques hébergées sur un autre serveur ne figurent pas ici : leur crédit se règle sur ce même portail, sur leur serveur.</p>
    </section>

    <section class="gcard" id="boutiques">
      <h2>Boutiques</h2>
      <p class="meta">Une boutique avec ses propres identifiants ne dépend pas du portail. Sinon, elle reprend le compte ci-dessus. Les identifiants d’une boutique se règlent dans son administration, Réglages du site → « SMS : OVH ou Twilio ».</p>
      <?php foreach ($shops as $slug => $shop): $own = portail_sms_shop_own($shop); ?>
        <div class="shop-sms">
          <div class="who">
            <strong><?= e($shop['name']) ?></strong>
            <small><?= e($slug) ?> ·
              <?php if ($own['ovh']): ?><span class="pill done">OVH propre</span>
              <?php elseif ($own['twilio']): ?><span class="pill done">Twilio propre</span>
              <?php else: ?><span class="pill <?= $provider !== '' ? 'done' : 'sent' ?>"><?= $provider !== '' ? 'hérite du portail' : 'sans SMS' ?></span><?php endif; ?>
            </small>
          </div>
          <div>
            <a class="btn small" href="<?= e(rtrim(portail_shop_url($slug, $shop), '/')) ?>/admin/#sms-settings">Réglages de la boutique</a>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  </div>
</div>
</body>
</html>
