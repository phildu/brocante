<?php
/** Bordereau de remise à une donnerie — page autonome, pensée pour l'impression. */
session_start();
require_once __DIR__ . '/../includes/invendus-layout.php';
require_admin();

$donation = unsold_donation_get((int) unsold_param($_GET, 'id'));
if (!$donation) {
    flash_set('Bordereau introuvable.', 'error');
    header('Location: /admin/invendus.php');
    exit;
}
$event = unsold_event_get((int) $donation['event_id']);
$items = unsold_donation_offers((int) $donation['id']);
$content = get_content();

// Regroupement par catégorie + totaux.
$byCat = [];
$pieces = 0;
foreach ($items as $o) {
    $byCat[$o['category']][] = $o;
    $pieces += (int) $o['quantity'];
}
uksort($byCat, fn($a, $b) => strcmp(unsold_category_label($a), unsold_category_label($b)));
$ref = sprintf('REM-%s-%04d', date('Y', strtotime($donation['created_at']) ?: time()), $donation['id']);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Bordereau <?= h($ref) ?></title>
<style>
  body { font: 15px/1.5 Arial, Helvetica, sans-serif; color: #111; margin: 0; background: #eee; }
  .sheet { max-width: 800px; margin: 20px auto; background: #fff; padding: 36px 44px; }
  h1 { font-size: 1.5rem; margin: 0 0 4px; }
  h2 { font-size: 1.05rem; margin: 22px 0 6px; border-bottom: 2px solid #111; padding-bottom: 3px; }
  .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 18px; }
  .meta div { border: 1px solid #999; padding: 10px 14px; }
  .meta b { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: #555; margin-bottom: 2px; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; }
  th, td { text-align: left; padding: 5px 8px; border-bottom: 1px solid #ccc; font-size: .92rem; }
  th { font-size: .75rem; text-transform: uppercase; color: #555; }
  td.q { width: 60px; text-align: right; }
  .total { font-weight: bold; font-size: 1.05rem; margin-top: 14px; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 36px; }
  .sign div { border: 1px solid #999; height: 110px; padding: 8px 12px; font-size: .85rem; color: #555; }
  .actions { max-width: 800px; margin: 16px auto 0; display: flex; gap: 10px; }
  .actions a, .actions button { font: inherit; padding: 8px 16px; border: 1px solid #111; background: #fff; cursor: pointer; text-decoration: none; color: #111; }
  .actions button { background: #111; color: #fff; }
  @media print { body { background: #fff; } .sheet { margin: 0; padding: 0; max-width: none; } .actions { display: none; } }
</style>
</head>
<body>
<div class="actions">
  <button type="button" onclick="window.print()">Imprimer</button>
  <a href="/admin/invendus-event.php?id=<?= (int) $donation['event_id'] ?>&amp;tab=reliquat">← Retour</a>
</div>
<div class="sheet">
  <h1>Bordereau de remise de dons</h1>
  <div style="color:#555;"><?= h($ref) ?> · <?= h(unsold_fr_date(substr($donation['created_at'], 0, 10))) ?> à <?= h(unsold_fr_time($donation['created_at'])) ?></div>

  <div class="meta">
    <div><b>Donateur</b><?= h($content['site_name'] ?? '') ?><br><?= h($event['name'] ?? '') ?><?= $event ? '<br>' . h(unsold_fr_date($event['event_date'])) : '' ?><?= !empty($event['location']) ? '<br>' . h($event['location']) : '' ?></div>
    <div><b>Structure bénéficiaire</b><?= h($donation['recipient_name']) ?><br><?= h($donation['recipient_address']) ?><?= $donation['recipient_phone'] !== '' ? '<br>' . h($donation['recipient_phone']) : '' ?></div>
  </div>
  <?php if ($donation['handed_by'] !== '' || $donation['note'] !== ''): ?>
    <p style="margin:14px 0 0;">
      <?php if ($donation['handed_by'] !== ''): ?>Remis par : <strong><?= h($donation['handed_by']) ?></strong>. <?php endif; ?>
      <?php if ($donation['note'] !== ''): ?><?= h($donation['note']) ?><?php endif; ?>
    </p>
  <?php endif; ?>

  <?php foreach ($byCat as $cat => $list): ?>
    <h2><?= h(unsold_category_label($cat)) ?></h2>
    <table>
      <thead><tr><th>Objet</th><th>Volume</th><th class="q">Qté</th></tr></thead>
      <tbody>
      <?php foreach ($list as $o): ?>
        <tr><td><?= h($o['title']) ?></td><td><?= h(unsold_sizes()[$o['size']]['label'] ?? '') ?></td><td class="q"><?= (int) $o['quantity'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>

  <p class="total">Total : <?= count($items) ?> lot<?= count($items) > 1 ? 's' : '' ?> — <?= (int) $pieces ?> pièce<?= $pieces > 1 ? 's' : '' ?></p>
  <p style="font-size:.85rem;color:#555;">Objets donnés à titre gratuit et sans contrepartie.</p>

  <div class="sign">
    <div>Pour <?= h($content['site_name'] ?? 'l\'organisation') ?><br>Nom, date, signature</div>
    <div>Pour <?= h($donation['recipient_name']) ?><br>Nom, date, signature</div>
  </div>
</div>
</body>
</html>
