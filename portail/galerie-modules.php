<?php
// Options (modules) d'une galerie : carte interactive, espace organisateur… Chaque module est proposé ou non par le type de galerie.
// Un module inclus est actif d'office ; une option payante s'active par un essai gratuit ou un abonnement Stripe.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-modules.php';

$self = '/portail/galerie-modules.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$back = static fn (string $extra = ''): string => $self . ($extra !== '' ? (str_contains($self, '?') ? '&' : '?') . $extra : '');

// Retour de Stripe : on vérifie le paiement sans attendre le webhook.
if (isset($_GET['r'], $_GET['s']) && preg_match('/^cs_[A-Za-z0-9_]+$/', (string) $_GET['s'])) {
    try {
        $session = saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode((string) $_GET['s']));
        $res = gm_apply_session($session);
        portail_flash(in_array($res['state'], ['added', 'already'], true) ? 'Option activée : elle est disponible dès maintenant.' : "Le paiement n'est pas encore confirmé par Stripe : l'option s'activera dès sa confirmation.", in_array($res['state'], ['added', 'already'], true) ? 'ok' : 'error');
    } catch (Throwable $e) {
        portail_flash('Impossible de vérifier le paiement pour le moment. Actualisez la page dans un instant.', 'error');
    }
    header('Location: ' . $self);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $key = (string) ($_POST['module'] ?? '');
    $st = gm_module($key) ? gm_state($gaGallery, $key) : null;
    $action = (string) ($_POST['action'] ?? '');
    if (!$st) {
        portail_flash('Module inconnu.', 'error');
    } elseif ($action === 'trial') {
        $err = gm_start_trial($gaGallery, $key);
        if (!$err && !gallery_save($gaGallery)) $err = "Impossible d'enregistrer l'option.";
        portail_flash($err ?? 'Essai de ' . (int) $st['module']['trial_days'] . ' jours démarré.', $err ? 'error' : 'ok');
    } elseif ($action === 'subscribe') {
        [$url, $err] = gm_checkout($gaGallery, $key, $self, $back('annule=1'), ($_POST['billing'] ?? 'month') === 'year' ? 'year' : 'month');
        if ($err) portail_flash($err, 'error');
        else { header('Location: ' . $url, true, 303); exit; }
    } elseif ($action === 'stop_trial' || $action === 'cancel') {
        $sub = (string) ($gaGallery['modules'][$key]['stripe_subscription_id'] ?? '');
        if ($sub !== '' && saas_stripe_configured()) {
            try { saas_stripe_request('DELETE', '/subscriptions/' . rawurlencode($sub)); } catch (Throwable $e) { error_log('galerie-modules cancel: ' . $e->getMessage()); }
        }
        gm_deactivate($gaGallery, $key, $action === 'stop_trial' ? 'expired' : 'canceled');
        $ok = gallery_save($gaGallery);
        portail_flash($ok ? ($action === 'stop_trial' ? 'Essai arrêté.' : 'Option résiliée : elle n\'est plus disponible.') : "Impossible d'enregistrer l'option.", $ok ? 'ok' : 'error');
    } elseif (in_array($action, ['grant', 'revoke'], true) && !$limited) {
        if ($action === 'grant') gm_activate($gaGallery, $key, 'complimentary');
        else gm_deactivate($gaGallery, $key, 'canceled');
        $ok = gallery_save($gaGallery);
        portail_flash($ok ? ($action === 'grant' ? 'Option offerte : elle est active.' : 'Option retirée.') : "Impossible d'enregistrer l'option.", $ok ? 'ok' : 'error');
    } else {
        portail_flash('Action non autorisée.', 'error');
    }
    header('Location: ' . $self);
    exit;
}

$type = gt_of($gaGallery);
$stripeOn = saas_stripe_configured();
ga_head('Options');
?>
    <section class="gcard">
      <h2>Modules de « <?= e($gaGallery['name']) ?> »</h2>
      <p class="meta" style="margin:0;">Type : <strong><?= e($type['emoji']) ?> <?= e($type['name']) ?></strong>. Les modules sont partagés entre les galeries d'un même type : l'exploitant règle pour chaque type s'ils sont inclus, payants ou absents. Une option payante peut être essayée gratuitement, puis souscrite par carte bancaire.</p>
      <p class="meta" style="margin:0;"><?= $stripeOn ? 'Paiement Stripe disponible.' : 'Paiement en ligne non configuré : seul l\'essai gratuit ou un accès accordé par l\'exploitant est possible.' ?></p>
    </section>

    <?php foreach (gm_modules() as $key => $m): $st = gm_state($gaGallery, $key); $status = $st['status']; ?>
    <section class="gcard" id="m-<?= e($key) ?>">
      <div class="row" style="justify-content:space-between;align-items:flex-start;">
        <div><h2 style="margin:0;"><?= e($m['emoji']) ?> <?= e($m['name']) ?></h2><p class="meta" style="margin:4px 0 0;"><?= e($m['description']) ?></p></div>
        <span class="pill <?= $st['enabled'] ? 'done' : '' ?>"><?= e($st['status_label']) ?></span>
      </div>
      <p class="meta" style="margin:0;"><?= e(GM_MODE_LABELS[$st['mode']] ?? $st['mode']) ?><?php if ($st['mode'] === 'option' && $m['month_cents'] > 0): ?> · <?= e(gm_price_text($m['month_cents'])) ?><?= $m['year_cents'] > 0 ? ' ou ' . e(gm_price_text($m['year_cents'], 'year')) : '' ?><?php endif; ?><?= $st['mode'] === 'option' && $m['trial_days'] > 0 ? ' · essai de ' . (int) $m['trial_days'] . ' jours' : '' ?></p>

      <?php if ($st['mode'] === 'off'): ?>
        <p class="meta" style="margin:0;">Ce module n'est pas proposé pour le type « <?= e($type['name']) ?> ». L'exploitant peut le proposer depuis <a href="/portail/offres-galerie.php#t-<?= e($type['key']) ?>">Types de galerie</a>.</p>
      <?php elseif ($st['mode'] === 'included'): ?>
        <p class="meta" style="margin:0;">Inclus dans l'offre de ce type de galerie : aucune démarche n'est nécessaire.</p>
        <div class="row"><a class="btn" href="<?= $key === 'carte' ? '/portail/galerie-plan.php' . ($limited ? '' : '?g=' . e(urlencode($gaSlug))) : '/portail/galerie-evenement.php' . ($limited ? '' : '?g=' . e(urlencode($gaSlug))) ?>">Ouvrir <?= e($m['name']) ?></a></div>
      <?php elseif ($status === 'trial' && $st['enabled']): ?>
        <p class="meta" style="margin:0;">Essai en cours jusqu'au <?= e(date('d/m/Y', $st['until'])) ?>. Souscrivez pour continuer sans interruption.</p>
        <div class="row">
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="subscribe"><input type="hidden" name="module" value="<?= e($key) ?>"><input type="hidden" name="billing" value="month"><button class="btn btn-primary" type="submit"<?= $stripeOn && $m['month_cents'] > 0 ? '' : ' disabled' ?>>S'abonner — <?= e(gm_price_text($m['month_cents'])) ?></button></form>
          <?php if ($m['year_cents'] > 0): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="subscribe"><input type="hidden" name="module" value="<?= e($key) ?>"><input type="hidden" name="billing" value="year"><button class="btn" type="submit"<?= $stripeOn ? '' : ' disabled' ?>>Payer l'année — <?= e(gm_price_text($m['year_cents'], 'year')) ?></button></form><?php endif; ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="stop_trial"><input type="hidden" name="module" value="<?= e($key) ?>"><button class="btn" type="submit">Arrêter l'essai</button></form>
        </div>
      <?php elseif ($status === 'active' && $st['enabled']): ?>
        <p class="meta" style="margin:0;">Abonnement actif<?= $st['until'] > 0 ? ' jusqu\'au ' . e(date('d/m/Y', $st['until'])) : '' ?>. Résilier arrête le renouvellement ; l'option reste disponible jusqu'à la fin de la période payée.</p>
        <div class="row"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="module" value="<?= e($key) ?>"><button class="btn" type="submit" onclick="return confirm('Résilier cette option ?');">Résilier</button></form></div>
      <?php elseif ($status === 'complimentary' && $st['enabled']): ?>
        <p class="meta" style="margin:0;">Accès offert par l'exploitant de la plateforme.</p>
        <div class="row"><a class="btn" href="<?= $key === 'carte' ? '/portail/galerie-plan.php' . ($limited ? '' : '?g=' . e(urlencode($gaSlug))) : '/portail/galerie-evenement.php' . ($limited ? '' : '?g=' . e(urlencode($gaSlug))) ?>">Ouvrir <?= e($m['name']) ?></a></div>
      <?php else: ?>
        <p class="meta" style="margin:0;"><?= $status === 'expired' ? "L'essai est terminé." : ($status === 'canceled' ? 'Option résiliée.' : 'Option non activée.') ?></p>
        <div class="row">
          <?php if ($m['trial_days'] > 0 && !$st['trial_used']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="trial"><input type="hidden" name="module" value="<?= e($key) ?>"><button class="btn btn-primary" type="submit">Essayer <?= (int) $m['trial_days'] ?> jours</button></form><?php elseif ($m['trial_days'] > 0): ?><span class="meta">Essai déjà utilisé.</span><?php endif; ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="subscribe"><input type="hidden" name="module" value="<?= e($key) ?>"><input type="hidden" name="billing" value="month"><button class="btn" type="submit"<?= $stripeOn && $m['month_cents'] > 0 ? '' : ' disabled' ?>>S'abonner — <?= e(gm_price_text($m['month_cents'])) ?></button></form>
          <?php if ($m['year_cents'] > 0): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="subscribe"><input type="hidden" name="module" value="<?= e($key) ?>"><input type="hidden" name="billing" value="year"><button class="btn" type="submit"<?= $stripeOn ? '' : ' disabled' ?>>Payer l'année — <?= e(gm_price_text($m['year_cents'], 'year')) ?></button></form><?php endif; ?>
        </div>
        <?php if (!$stripeOn && $m['month_cents'] > 0): ?><p class="meta" style="margin:0;">Le paiement en ligne n'est pas encore configuré : démarrez l'essai ou demandez un accès à l'exploitant.</p><?php endif; ?>
      <?php endif; ?>

      <?php if (!$limited && $st['mode'] === 'option'): ?>
      <div class="row" style="border-top:1px solid var(--line);padding-top:8px;">
        <span class="meta" style="margin:0;">Exploitant :</span>
        <?php if (!$st['enabled']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="grant"><input type="hidden" name="module" value="<?= e($key) ?>"><button class="btn small" type="submit">Offrir l'option</button></form><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="module" value="<?= e($key) ?>"><button class="btn small" type="submit">Retirer l'option</button></form><?php endif; ?>
      </div>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>

    <?php if (($_GET['annule'] ?? '') === '1'): ?><p class="flash" data-kind="error">Paiement annulé : rien n'a été débité.</p><?php endif; ?>
<?php ga_foot(); ?>
