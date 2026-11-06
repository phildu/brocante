<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/invendus-layout.php';
unsold_public_enter();   // galerie d'après l'adresse (/galerie/<galerie>/dons/…), extension active
unsold_private_headers();

$token = unsold_param($_POST, 't') ?: unsold_param($_GET, 't');
$offers = unsold_offers_by_token($token);


if (!$offers) {
    http_response_code(404);
    unsold_public_head('Suivi de mes dons', true);
    echo '<section class="tight"><div class="don-wrap don-narrow"><h1 style="font-size:1.8rem;">Lien introuvable</h1>'
        . '<p class="lede">Ce lien de suivi n\'existe pas ou n\'est plus valable. Vérifiez que vous l\'avez copié en entier.</p>'
        . '<p><a class="btn btn-ghost" href="/dons.php">Retour aux dons &amp; invendus</a></p></div></section>';
    unsold_public_foot();
    exit;
}

$self = '/dons-suivi.php?t=' . $token;

// Ce lien sert aussi à rattacher de nouvelles propositions faites depuis cet appareil.
foreach ($offers as $o) {
    $_SESSION['dons_tokens'][(int) $o['event_id']] = $token;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_require_csrf();
    unsold_scalar_post();
    $action = unsold_param($_POST, 'action');
    $offerId = (int) unsold_param($_POST, 'offer_id');
    // Le donateur n'agit que sur SES propositions.
    $mine = null;
    foreach ($offers as $o) {
        if ((int) $o['id'] === $offerId) $mine = $o;
    }

    if ($action === 'confirm' && $mine) {
        flash_set(unsold_confirm_offer($offerId) ? 'Merci ! « ' . $mine['title'] . ' » est confirmé comme invendu.' : 'Cette proposition ne peut plus être confirmée.', 'ok');
    } elseif ($action === 'confirm_all') {
        $n = 0;
        foreach ($offers as $o) {
            if ($o['status'] === 'proposed' && !$o['confirmed_at'] && unsold_confirm_offer((int) $o['id'])) $n++;
        }
        flash_set($n ? $n . ' proposition' . ($n > 1 ? 's confirmées' : ' confirmée') . ' comme invendue' . ($n > 1 ? 's' : '') . '. Merci !' : 'Rien à confirmer.');
    } elseif ($action === 'withdraw' && $mine) {
        $ok = unsold_transition($offerId, 'withdrawn', 'donor', 'Retiré par le donateur');
        flash_set($ok ? '« ' . $mine['title'] . ' » est retiré de la liste (vendu ou repris).' : 'Cet objet ne peut plus être retiré : un bénévole l\'a déjà pris en charge.', $ok ? 'ok' : 'error');
    }
    header('Location: ' . $self);
    exit;
}

// Regroupement par édition.
$byEvent = [];
foreach ($offers as $o) {
    $byEvent[(int) $o['event_id']][] = $o;
}

$donorStatus = function (array $o): string {
    switch ($o['status']) {
        case 'proposed':
            if ($o['confirmed_at']) {
                return $o['handover'] === 'pickup' ? 'Invendu confirmé — un bénévole passera à votre stand.' : 'Invendu confirmé — apportez-le au point de collecte.';
            }
            return 'En attente. Si l\'objet n\'est pas vendu, confirmez-le en fin de journée.';
        case 'picked': return 'Récupéré par un bénévole, en route vers le point de collecte.';
        case 'at_point': return 'Au point de collecte : à disposition des visiteurs.';
        case 'taken': return 'Pris par un visiteur. Merci pour ce don !';
        case 'donated': return 'Remis à une donnerie. Merci pour ce don !';
        case 'withdrawn': return 'Retiré de la liste (vendu ou repris).';
    }
    return '';
};

unsold_public_head('Suivi de mes dons', true);
?>
<section class="tight">
  <div class="don-wrap don-narrow">
    <p class="eyebrow">Invendus &amp; dons</p>
    <h1 style="font-size:1.9rem;margin:12px 0 12px;">Suivi de mes dons</h1>

    <?php if (!empty($_GET['new'])): ?>
      <div class="don-alert" style="margin-bottom:20px;">
        <strong>Gardez cette page !</strong> Ce lien est votre accès personnel : c'est ici que vous confirmerez en fin de
        journée que l'objet est invendu, ou que vous le retirerez s'il est vendu. Mettez-la en favori ou copiez le lien.
        <div class="don-link" style="margin-top:10px;">
          <input type="text" id="don-url" readonly value="<?= h(unsold_abs_url($self)) ?>" aria-label="Votre lien de suivi">
          <button type="button" class="btn-small" id="don-copy">Copier</button>
        </div>
      </div>
    <?php endif; ?>

    <?php
      $hasUnconfirmed = false;
      foreach ($offers as $o) {
          if ($o['status'] === 'proposed' && !$o['confirmed_at']) $hasUnconfirmed = true;
      }
    ?>

    <?php foreach ($byEvent as $eid => $list):
        $event = unsold_event_get($eid);
        if (!$event) continue;
        $phase = unsold_event_phase($event);
    ?>
      <article class="don-event" style="margin-top:20px;">
        <div class="don-row">
          <h2><?= h($event['name']) ?></h2>
          <span class="don-right"><?= unsold_phase_badge($phase) ?></span>
        </div>
        <dl>
          <dt>Quand</dt><dd><?= h(unsold_fr_date($event['event_date'])) ?></dd>
          <dt><?= h($event['point_name']) ?></dt>
          <dd>
            ouvert de <?= h(unsold_fr_time($event['collect_start'])) ?> à <?= h(unsold_fr_time($event['collect_end'])) ?>
            <?php if ($event['point_details'] !== ''): ?><br><span class="don-muted"><?= nl2br(h($event['point_details'])) ?></span><?php endif; ?>
          </dd>
        </dl>
        <?php if (in_array($phase, ['proposals', 'collecting'], true)): ?>
          <div><a class="btn btn-ghost" href="/dons-proposer.php?event=<?= (int) $eid ?>">Proposer un autre objet</a></div>
        <?php endif; ?>
      </article>

      <div class="don-stack" style="margin-top:16px;">
        <?php foreach ($list as $o): ?>
          <div class="don-stop">
            <div class="don-item">
              <div class="don-row">
                <?php if (!empty($o['photo'])): ?><img class="don-thumb" src="/<?= h($o['photo']) ?>" alt=""><?php endif; ?>
                <div>
                  <h3><?= h($o['title']) ?><?= (int) $o['quantity'] > 1 ? ' <span class="don-qty">× ' . (int) $o['quantity'] . '</span>' : '' ?></h3>
                  <span class="don-muted don-small"><?= h(unsold_category_label($o['category'])) ?> · <?= h(unsold_handover_label($o['handover'])) ?><?= $o['stand_ref'] !== '' ? ' · emplacement ' . h($o['stand_ref']) : '' ?></span>
                </div>
                <span class="don-right"><?= unsold_status_badge($o['status']) ?></span>
              </div>
              <p class="don-small" style="margin:0;"><?= h($donorStatus($o)) ?></p>
              <?php if ($o['status'] === 'proposed'): ?>
                <div class="don-item-actions">
                  <?php if (!$o['confirmed_at']): ?>
                    <form method="post" action="<?= h($self) ?>">
                      <?= shop_csrf_input() ?><input type="hidden" name="t" value="<?= h($token) ?>">
                      <input type="hidden" name="action" value="confirm"><input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
                      <button class="btn-small don-ok" type="submit">C'est un invendu</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('Retirer cet objet de la liste (vendu ou repris) ?');">
                    <?= shop_csrf_input() ?><input type="hidden" name="t" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="withdraw"><input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
                    <button class="btn-small" type="submit">Je l'ai vendu / repris</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($hasUnconfirmed): ?>
      <form method="post" action="<?= h($self) ?>" style="margin-top:22px;">
        <?= shop_csrf_input() ?><input type="hidden" name="t" value="<?= h($token) ?>"><input type="hidden" name="action" value="confirm_all">
        <button class="btn btn-primary" type="submit">Tout ce qui reste est invendu — tout confirmer</button>
      </form>
    <?php endif; ?>

    <p class="don-muted don-small" style="margin-top:28px;">
      Cette page est privée : ne partagez pas son adresse.
      <a href="/dons.php" style="color:var(--accent);">Voir le point de collecte</a>
    </p>
  </div>
</section>
<script>
(function () {
  var btn = document.getElementById('don-copy'), input = document.getElementById('don-url');
  if (!btn || !input) return;
  btn.addEventListener('click', function () {
    input.select();
    var done = function () { btn.textContent = 'Copié ✓'; };
    if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); }); }
    else { document.execCommand('copy'); done(); }
  });
})();
</script>
<?php unsold_public_foot(); ?>
