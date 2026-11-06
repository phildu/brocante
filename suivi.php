<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

// Suivi de commande pour l'acheteur, sans mot de passe : le lien envoyé après
// le paiement (et le QR du reçu) porte un jeton aléatoire propre à la commande.
// La page montre le lieu et les horaires de retrait, la date limite, ou le
// transporteur et l'adresse d'expédition, et propose d'installer l'appli.
header('X-Robots-Tag: noindex, nofollow');
$token = (string) ($_GET['t'] ?? '');
$order = order_by_track_token($token);
$fromQr = (string) ($_GET['from'] ?? '') === 'qr';
$activeNav = '';
$pageTitle = 'Suivi de commande';
$ogDescription = 'État de votre commande, lieu et délai de collecte.';
$ogImage = logo_url('square');

if ($order) $pageTitle = 'Commande n° ' . (int) $order['id'];

function suivi_mask_email(string $email): string
{
    [$user, $domain] = array_pad(explode('@', trim($email), 2), 2, '');
    if ($user === '' || $domain === '') return '';
    return mb_substr($user, 0, 1) . str_repeat('•', max(2, mb_strlen($user) - 1)) . '@' . $domain;
}

include __DIR__ . '/includes/header.php';
?>
<style>
  .trk-steps { list-style: none; display: flex; gap: 0; margin: 18px 0 6px; padding: 0; }
  .trk-steps li { flex: 1; position: relative; text-align: center; font-family: var(--font-mono); font-size: .68rem; color: var(--ink-soft); padding-top: 26px; line-height: 1.3; }
  .trk-steps li::before { content: ""; position: absolute; top: 9px; left: 0; right: 0; height: 2px; background: var(--line); }
  .trk-steps li:first-child::before { left: 50%; }
  .trk-steps li:last-child::before { right: 50%; }
  .trk-steps li::after { content: ""; position: absolute; top: 4px; left: 50%; transform: translateX(-50%); width: 12px; height: 12px; border-radius: 50%; background: var(--bg); border: 2px solid var(--line); }
  .trk-steps li.done { color: var(--ink); }
  .trk-steps li.done::before { background: var(--accent); }
  .trk-steps li.done::after { background: var(--accent); border-color: var(--accent); }
  .trk-steps li.now { color: var(--ink); font-weight: 700; }
  .trk-steps li.now::after { outline: 3px solid var(--line); outline-offset: 1px; }
  .trk-code { font-family: var(--font-mono); font-size: 1.5rem; letter-spacing: .16em; }
  .trk-card { display: grid; gap: 8px; margin-top: 24px; }
  .trk-card h2 { font-size: 1.05rem; margin: 0 0 4px; }
  .trk-card dl { display: grid; grid-template-columns: minmax(110px, auto) 1fr; gap: 8px 16px; margin: 0; font-size: .95rem; }
  .trk-card dt { color: var(--ink-soft); }
  .trk-card dd { margin: 0; }
  .trk-links { display: flex; gap: 8px 16px; flex-wrap: wrap; margin-top: 4px; }
  .trk-app { display: flex; gap: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-top: 30px; padding: 18px 20px; border: 1px solid var(--line); border-radius: 12px; }
  .trk-app p { margin: 4px 0 0; font-size: .9rem; color: var(--ink-soft); max-width: 46ch; }
  @media (max-width: 520px) { .trk-steps { font-size: .6rem; } .trk-steps li { padding-top: 22px; } .trk-card dl { grid-template-columns: 1fr; gap: 2px; } .trk-card dd { margin-bottom: 8px; } }
</style>
<section class="tight">
  <div class="wrap" style="max-width:720px;">
    <?php if (!$order): ?>
      <p class="eyebrow">Suivi de commande</p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Lien de suivi invalide</h1>
      <p class="lede">Ce lien ne correspond à aucune commande. Recherchez l'e-mail de confirmation, ou retrouvez vos commandes depuis votre compte.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;">
        <a class="btn btn-primary" href="/compte.php">Mon compte</a>
        <a class="btn btn-ghost" href="/boutique.php">← Retour à la boutique</a>
      </div>

    <?php elseif ($order['status'] === 'cancelled'): ?>
      <p class="eyebrow">Commande n° <?= (int) $order['id'] ?></p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Commande annulée</h1>
      <p class="lede">Cette commande a été annulée et les pièces sont remises en vente. Répondez à l'e-mail de votre commande si vous avez une question.</p>
      <a class="btn btn-ghost" href="/boutique.php" style="margin-top:20px;">← Retour à la boutique</a>

    <?php else: ?>
      <?php
        $t = order_buyer_tracking($order);
        $steps = order_fulfillment_steps($order);
        $keys = array_keys($steps);
        $current = array_search($t['status'], $keys, true);
        if ($current === false) $current = 0;
        $masked = suivi_mask_email((string) $order['email']);
        $items = json_decode((string) $order['items'], true) ?: [];
        $ordered = date('d/m/Y', strtotime((string) $order['created_at']));
      ?>
      <p class="eyebrow">Suivi de commande</p>
      <h1 style="font-size:1.9rem;margin:12px 0 8px;">Commande n° <?= (int) $order['id'] ?>
        <span class="badge" style="font-size:.75rem;vertical-align:middle;"><?= h($t['step']) ?></span>
      </h1>
      <p class="lede">Passée le <?= h($ordered) ?><?= $masked !== '' ? ' avec ' . h($masked) : '' ?>. Total : <strong><?= format_cents((int) $order['amount_total']) ?></strong>.</p>

      <div class="admin-block" style="margin-top:24px;">
        <ol class="trk-steps" aria-label="Étapes de la commande">
          <?php $i = 0; foreach ($steps as $key => $label): ?>
            <li class="<?= $i < $current ? 'done' : ($i === $current ? 'done now' : '') ?>"<?= $i === $current ? ' aria-current="step"' : '' ?>><?= h($label) ?></li>
          <?php $i++; endforeach; ?>
        </ol>
        <?php if ($t['picked_up']): ?>
          <p style="margin:14px 0 0;font-size:.9rem;color:var(--ink-soft);">Retrait confirmé le <?= h(date('d/m/Y', strtotime((string) $order['picked_up_at']))) ?>.</p>
        <?php endif; ?>
      </div>

      <?php if ($t['pickup']): ?>
        <div class="admin-block trk-card">
          <h2>Retrait sur place</h2>
          <dl>
            <?php if ($t['pickup_label'] !== ''): ?><dt>Lieu</dt><dd><?= h($t['pickup_label']) ?></dd><?php endif; ?>
            <?php if ($t['address'] !== ''): ?><dt>Adresse</dt><dd><?= h($t['address']) ?></dd><?php endif; ?>
            <?php if ($t['hours'] !== ''): ?><dt>Horaires</dt><dd><?= nl2br(h($t['hours'])) ?></dd><?php endif; ?>
            <?php if ($t['code'] !== ''): ?><dt>Code de retrait</dt><dd><span class="trk-code" id="trk-code"><?= h($t['code']) ?></span> <button type="button" class="btn-small" data-copy="trk-code" style="margin-left:8px;">Copier</button></dd><?php endif; ?>
            <?php if ($t['deadline'] !== ''): ?>
              <dt>Date limite</dt><dd>À retirer avant le <?= h($t['deadline']) ?>.</dd>
            <?php elseif (!$t['picked_up']): ?>
              <dt>Délai</dt><dd>Nous vous prévenons dès que la commande est prête. Vous avez ensuite <?= ORDER_PICKUP_DAYS ?> jours pour venir la retirer.</dd>
            <?php endif; ?>
          </dl>
          <?php if ($t['maps_google'] !== '' || $t['directions'] !== ''): ?>
            <div class="trk-links">
              <a href="/nous-trouver.php" style="color:var(--accent);">Carte et horaires →</a>
              <?php if ($t['directions'] !== ''): ?><a href="<?= h($t['directions']) ?>" target="_blank" rel="noopener" style="color:var(--accent);">Itinéraire →</a><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <div class="admin-block trk-card">
          <h2>Livraison</h2>
          <dl>
            <?php if ($t['shipping_label'] !== ''): ?><dt>Mode et délai</dt><dd><?= h($t['shipping_label']) ?></dd><?php endif; ?>
            <?php if ($t['shipping_address'] !== ''): ?><dt>Adresse</dt><dd><?= h($t['shipping_address']) ?></dd><?php endif; ?>
            <?php if ($t['tracking_number'] !== ''): ?><dt>N° de suivi</dt><dd><?= h($t['tracking_number']) ?></dd><?php endif; ?>
            <?php if ($t['delivery_note'] !== ''): ?><dt>Précisions</dt><dd><?= nl2br(h($t['delivery_note'])) ?></dd><?php endif; ?>
          </dl>
          <?php if ($t['shipping_label'] === '' && $t['delivery_note'] === ''): ?>
            <p style="margin:6px 0 0;font-size:.9rem;color:var(--ink-soft);">Nous vous prévenons dès que le colis est expédié.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="admin-block trk-card">
        <h2>Votre commande</h2>
        <dl style="grid-template-columns:1fr auto;">
          <?php foreach ($items as $item): ?>
            <dt><?= (int) ($item['qty'] ?? 1) ?> × <?= h($item['name'] ?? '') ?></dt>
            <dd class="price" style="text-align:right;"><?= format_cents((int) ($item['unit_cents'] ?? 0) * (int) ($item['qty'] ?? 1)) ?></dd>
          <?php endforeach; ?>
          <?php if ((int) $order['shipping_cents'] > 0): ?>
            <dt>Livraison</dt><dd class="price" style="text-align:right;"><?= format_cents((int) $order['shipping_cents']) ?></dd>
          <?php endif; ?>
          <?php if (!empty($order['gift_wrap'])): ?>
            <dt>Emballage cadeau</dt><dd class="price" style="text-align:right;"><?= (int) ($order['gift_wrap_cents'] ?? 0) > 0 ? format_cents((int) $order['gift_wrap_cents']) : 'Offert' ?></dd>
          <?php endif; ?>
          <dt style="font-weight:700;padding-top:8px;border-top:1px solid var(--line);">Total payé</dt>
          <dd class="price" style="text-align:right;font-weight:700;padding-top:8px;border-top:1px solid var(--line);"><?= format_cents((int) $order['amount_total']) ?></dd>
        </dl>
        <?php if ((int) ($order['refunded_cents'] ?? 0) > 0): ?>
          <p style="margin:10px 0 0;font-size:.9rem;">Remboursement : <?= format_cents((int) $order['refunded_cents']) ?>.</p>
        <?php endif; ?>
      </div>

      <?php if (shop_setting('pwa_prompt', '1') !== '0'): ?>
        <div class="trk-app">
          <div>
            <strong>Gardez votre commande sous la main</strong>
            <p>Installez l'appli de la boutique sur votre téléphone : ce suivi, le lieu de retrait et vos commandes restent accessibles en un geste.</p>
          </div>
          <button class="btn btn-primary" type="button" data-pwa-install>Installer l'appli</button>
        </div>
      <?php endif; ?>

      <p style="margin-top:22px;font-size:.82rem;color:var(--ink-soft);">Ce lien est personnel : il permet de consulter votre commande sans mot de passe. Ne le partagez pas.</p>
    <?php endif; ?>
  </div>
</section>

<script>
(function () {
  // Retour direct vers cette commande depuis l'appli installée (l'accueil propose le lien).
  try {
    var u = new URL(location.href);
    var wasQr = u.searchParams.get('from') === 'qr';
    u.searchParams.delete('from');
    localStorage.setItem('brocs_track', u.pathname + u.search);
  } catch (e) { var wasQr = false; }

  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-copy'));
      if (!target) return;
      var text = target.textContent.trim();
      var done = function () {
        var old = button.textContent;
        button.textContent = 'Copié';
        setTimeout(function () { button.textContent = old; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () {});
      else {
        var area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(area);
      }
    });
  });

  // Un reçu scanné ouvre directement l'invite d'installation sur téléphone.
  if (wasQr) {
    document.addEventListener('DOMContentLoaded', function () {
      setTimeout(function () {
        if (!window.PwaSave || window.PwaSave.isStandalone()) return;
        try { if (localStorage.getItem('pwa_done') === '1') return; } catch (e) {}
        var d = window.PwaSave.detect();
        if (d && d.mobile) window.PwaSave.open();
      }, 700);
    });
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
