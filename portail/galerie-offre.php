<?php
// Offre de la galerie : état (essai, abonnement), commerces utilisés, passage à une offre supérieure (Stripe) et résiliation.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-offers.php';

$self = '/portail/galerie-offre.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$me = $limited ? ga_current() : null;
$contact = $me['email'] ?? ((string) ($gaGallery['admins'][0]['email'] ?? ''));

// Retour de Stripe après un changement d'offre.
if (isset($_GET['r'], $_GET['s']) && preg_match('/^cs_[A-Za-z0-9_]+$/', (string) $_GET['s'])) {
    try {
        $res = go_apply_session(saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode((string) $_GET['s'])));
        portail_flash(in_array($res['state'], ['updated', 'already', 'added'], true) ? 'Merci ! Votre nouvelle offre est active.' : "Paiement pas encore confirmé par Stripe : l'offre s'activera dès sa confirmation.", in_array($res['state'], ['updated', 'already', 'added'], true) ? 'ok' : 'error');
    } catch (Throwable $e) {
        portail_flash('Impossible de vérifier le paiement pour le moment.', 'error');
    }
    header('Location: ' . $self);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'choose') {
        $offer = go_offer((string) ($_POST['offre'] ?? ''), gt_of($gaGallery)['key']);
        if (!$offer || !$offer['active'] || $offer['month_cents'] <= 0) portail_flash('Offre indisponible.', 'error');
        elseif ($offer['max_members'] < count($gaGallery['members'])) portail_flash('Cette offre permet ' . $offer['max_members'] . ' ' . gt_word($gaGallery, 'members') . ' : retirez-en d\'abord de votre galerie (vous en avez ' . count($gaGallery['members']) . ').', 'error');
        else {
            [$url, $err] = go_checkout($offer, ($_POST['billing'] ?? '') === 'year' ? 'year' : 'month', $contact ?: 'client@invalid.test', $gaGallery['name'], $gaSlug, '/portail/galerie-offre.php' . ($limited ? '' : '?g=' . urlencode($gaSlug)), $gaOrigin . $self, gt_of($gaGallery)['key']);
            if ($err) portail_flash($err, 'error'); else { header('Location: ' . $url, true, 303); exit; }
        }
    } elseif ($action === 'cancel') {
        $sub = (string) ($gaGallery['plan']['stripe_subscription_id'] ?? '');
        if ($sub === '') portail_flash('Aucun abonnement à résilier.', 'error');
        else {
            try {
                saas_stripe_request('POST', '/subscriptions/' . rawurlencode($sub), ['cancel_at_period_end' => 'true']);
                saas_event('gallery_cancel_requested', ['slug' => $gaSlug, 'plan' => $gaGallery['plan']['offer'], 'actor' => $limited ? 'client' : 'exploitant']);
                portail_flash('Résiliation enregistrée : la galerie reste active jusqu\'à la fin de la période déjà payée.');
            } catch (Throwable $e) {
                portail_flash('Résiliation impossible : ' . $e->getMessage(), 'error');
            }
        }
    }
    header('Location: ' . $self);
    exit;
}

$st = go_status($gaGallery);
$plan = $gaGallery['plan'] ?? null;
$cur = $plan ? go_offer($plan['offer'], gt_of($gaGallery)['key']) : null;
$limit = go_member_limit($gaGallery);
$hid = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">');
ga_head('Mon offre');
?>
    <section class="gcard"><h2>Offre actuelle</h2>
      <?php if ($st === 'manual'): ?>
        <p>Galerie sans abonnement : accès accordé par l'exploitant, sans limite de commerces.</p>
      <?php else: ?>
        <p><strong><?= e($cur['name'] ?? $plan['offer']) ?></strong> — <?= e(GO_PLAN_STATUS[$st] ?? $st) ?>
          <?php if ($st === 'trial'): ?>jusqu'au <strong><?= e(date('d/m/Y', $plan['trial_until'])) ?></strong><?php endif; ?>
          <?php if ($st === 'active' && $cur): ?>· <?= e(go_price_text($plan['billing'] === 'year' ? $cur['year_cents'] : $cur['month_cents'], $plan['billing'])) ?><?php endif; ?></p>
        <p class="meta"><?= count($gaGallery['members']) ?> commerce<?= count($gaGallery['members']) > 1 ? 's' : '' ?> sur <?= (int) $limit ?> possibles.</p>
        <?php if (in_array($st, ['expired', 'canceled'], true)): ?><p class="flash" data-kind="error">Votre galerie n'est plus visible du public : choisissez une offre ci-dessous pour la remettre en ligne.</p><?php endif; ?>
        <?php if ($st === 'active' && !empty($plan['stripe_subscription_id'])): ?>
          <form method="post" onsubmit="return confirm('Résilier l\'abonnement ? La galerie reste active jusqu\'à la fin de la période payée.')"><?= $hid ?><input type="hidden" name="action" value="cancel"><button class="btn danger" type="submit">Résilier l'abonnement</button></form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <section class="gcard"><h2>Changer d'offre</h2>
      <div class="kpis">
      <?php foreach (go_offers(gt_of($gaGallery)['key']) as $o): if (!$o['active'] || $o['month_cents'] <= 0) continue; $isCur = $plan && $plan['offer'] === $o['key'] && $st === 'active'; ?>
        <form method="post" class="kpi" style="display:grid;gap:6px;"><?= $hid ?><input type="hidden" name="action" value="choose"><input type="hidden" name="offre" value="<?= e($o['key']) ?>">
          <b style="font-size:1.1rem;"><?= e($o['name']) ?></b><span><?= (int) $o['max_members'] ?> commerces — <?= e(go_price_text($o['month_cents'])) ?><?= $o['year_cents'] > 0 ? ' ou ' . e(go_price_text($o['year_cents'], 'year')) : '' ?></span>
          <select name="billing" style="min-width:0;"><option value="month">Mensuel</option><option value="year">Annuel</option></select>
          <button class="btn<?= $isCur ? '' : ' btn-primary' ?>" type="submit" <?= $isCur ? 'disabled' : '' ?>><?= $isCur ? 'Offre actuelle' : 'Choisir' ?></button></form>
      <?php endforeach; ?>
      </div>
      <p class="meta">Le paiement se fait sur la page sécurisée Stripe. Le nouvel abonnement remplace l'ancien, qui est résilié automatiquement.</p>
    </section>
<?php ga_foot();
