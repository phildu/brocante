<?php
// Caisse de la buvette, côté responsable : installer l'application sur les téléphones, bilan des ventes de l'événement actif,
// annulation (à tout moment) et export CSV. L'application elle-même est buvette/caisse.php (code bénévole).
require_once __DIR__ . '/../../includes/buvette-caisse.php';
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

$event = bv_active_event();
if ($event) bv_sales_reconcile((int) $event['id']);
$self = '/admin/buvette/caisse.php';

if (bv_post_action() === 'void') {
    $err = bv_sale_void((int) ($_POST['id'] ?? 0), true);
    bv_redirect($self, $err ?? 'Vente annulée : le bilan est corrigé.', $err ? 'error' : 'ok');
}

if (bv_post_action() === 'quotas') {
    bv_gift_set_quotas((int) ($_POST['quota_bar'] ?? 0), (int) ($_POST['quota_food'] ?? 0));
    bv_redirect($self . '#gratuites', 'Quotas de gratuités enregistrés.');
}
if (bv_post_action() === 'void_gift') {
    $err = bv_gift_void((int) ($_POST['id'] ?? 0), true);
    bv_redirect($self . '#gratuites', $err ?? 'Gratuité annulée.', $err ? 'error' : 'ok');
}

// Badges à imprimer : une carte par bénévole actif, avec son QR personnel.
if (isset($_GET['badges'])) {
    bv_admin_header('Badges des bénévoles', 'caisse');
    require_once __DIR__ . '/../../includes/app-install-card.php';
    $vols = bv_volunteers_list(true);
    $quotas = bv_gift_quotas();
    ?>
  <div class="bv-actions bv-noprint" style="margin:0 0 16px;">
    <button class="btn btn-primary" type="button" onclick="window.print()">Imprimer les badges</button>
    <a class="btn btn-ghost" href="<?= h($self) ?>#gratuites">← Retour à la caisse</a>
  </div>
  <?php if (!$vols): ?><p class="bv-callout">Aucun bénévole actif : ajoutez-les dans « Bénévoles &amp; planning ».</p><?php endif; ?>
  <style>.bdg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(62mm,1fr));gap:6mm}.bdg{border:1px dashed #999;padding:5mm;text-align:center;break-inside:avoid}.bdg .q{width:42mm;height:42mm;margin:0 auto 3mm;line-height:0}.bdg .q svg{width:100%;height:100%}.bdg h2{margin:0 0 1mm;font-size:18px}.bdg p{margin:0;font-size:12px;color:#444}</style>
  <div class="bdg-grid">
  <?php foreach ($vols as $v): ?>
    <div class="bdg"><div class="q" data-url="<?= h(bv_badge_url((int) $v['id'])) ?>" role="img" aria-label="QR de <?= h($v['name']) ?>"></div>
      <h2><?= h($v['name']) ?></h2>
      <p><?= h(bv_site_name()) ?> · bénévole</p>
      <p><?= (int) $quotas['bar'] ?> boisson(s) · <?= (int) $quotas['food'] ?> repas offert(s)</p></div>
  <?php endforeach; ?>
  </div>
  <script src="/assets/vendor/qrcode-generator.js"></script>
  <script>document.querySelectorAll('.bdg .q').forEach(function (b) { var q = qrcode(0, 'M'); q.addData(b.dataset.url); q.make(); b.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true }); });</script>
<?php
    bv_admin_footer();
    exit;
}

if ($event && isset($_GET['csv'])) {
    $q = static fn ($v) => '"' . str_replace('"', '""', ltrim((string) $v, "=+-@\t\r")) . '"';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="caisse-buvette-' . (int) $event['id'] . '.csv"');
    echo "\xEF\xBB\xBF" . "N°;Date;Heure;Paiement;Total (€);Statut;Détail;Vendu par\r\n";
    foreach (array_reverse(bv_sales_list((int) $event['id'], 100000)) as $s) {
        $t = (int) strtotime((string) $s['created_at']);
        echo implode(';', [(int) $s['id'], date('d/m/Y', $t), date('H:i', $t), bv_method_label($s['method']),
            number_format($s['total_cents'] / 100, 2, ',', ''), $s['voided_at'] !== null ? 'Annulée' : ($s['status'] === 'paid' ? 'Valable' : ($s['status'] === 'pending' ? 'En attente' : 'Abandonnée')), $q(bv_sale_label($s)), $q($s['by_name'])]) . "\r\n";
    }
    exit;
}

bv_admin_header('Caisse', 'caisse');
require_once __DIR__ . '/../../includes/app-install-card.php';
?>
<p class="bv-hint">La caisse se joue sur téléphone : le bénévole touche les articles de la carte, la caisse calcule le total et le rendu de monnaie, et chaque vente alimente les « quantités vendues » du bilan. Pour la carte, le barman choisit le terminal de l'association (la caisse note seulement « carte ») ou le <strong>QR de paiement</strong> : le client le scanne avec son téléphone et paie sur Stripe, la vente passe à « payée » dès que Stripe confirme.</p>
<?php if (bv_card_account() !== null): ?>
  <p class="bv-hint">✓ Paiement par QR actif : l'argent est versé sur le compte Stripe de la galerie. Frais Stripe : environ 1,5 % + 0,25 € par carte européenne (à partir de 0,50 €) — peu rentable sur un café à 1 €, préférez le terminal pour les petits montants.</p>
<?php else: ?>
  <p class="bv-callout">Paiement par QR indisponible : la galerie n'a pas de compte Stripe prêt (Portail → Commissions → Comptes Stripe). En attendant, la carte se note au terminal.</p>
<?php endif; ?>

<?php
app_install_card([
    'title' => 'Caisse de la buvette',
    'desc' => "Scannez ce code avec le téléphone de la caisse, entrez le code bénévole, puis « Ajouter à l'écran d'accueil ».",
    'url' => bv_volunteer_space_url() . 'caisse.php',
    'note' => bv_volunteer_code() === '' ? "Aucun code bénévole défini : définissez-le dans le tableau de bord, sinon personne ne pourra entrer." : '',
]);

if (!$event): ?>
  <p class="bv-callout">Aucun événement actif : <a href="/admin/buvette/index.php#nouvel-evenement">activez ou créez un événement</a> pour ouvrir la caisse.</p>
<?php else:
    $eid = (int) $event['id'];
    $sum = bv_sales_summary($eid);
    $sales = bv_sales_list($eid, 200);
    ?>
  <div class="bv-stats">
    <div class="bv-stat is-good"><div class="label">Recette</div><div class="value"><?= h(bv_money($sum['total_cents'])) ?></div></div>
    <div class="bv-stat"><div class="label">Ventes</div><div class="value"><?= (int) $sum['count'] ?></div></div>
    <div class="bv-stat"><div class="label">Espèces</div><div class="value"><?= h(bv_money($sum['cash_cents'])) ?></div></div>
    <div class="bv-stat"><div class="label">Carte</div><div class="value"><?= h(bv_money($sum['card_cents'])) ?></div><div class="bv-muted" style="font-size:.78rem">dont QR <?= h(bv_money($sum['stripe_cents'])) ?></div></div>
  </div>
  <p class="bv-hint"><?= h($event['name']) ?><?= ($sum['voided'] ? ' · ' . (int) $sum['voided'] . ' vente(s) annulée(s) non comptées' : '') . ($sum['pending'] ? ' · ' . (int) $sum['pending'] . ' paiement(s) QR en attente' : '') ?>. Les quantités vendues du bilan (<a href="/admin/buvette/previsionnel.php">Prévisionnel &amp; bilan</a>) se mettent à jour toutes seules ; les corriger à la main là-bas remplace le chiffre de la caisse.</p>

  <?php if ($sum['items']): ?>
    <h3 style="font-size:1rem;margin:16px 0 6px;">Par article</h3>
    <div class="bv-table-wrap"><table class="bv-table">
      <thead><tr><th>Article</th><th class="num">Portions</th><th class="num">Montant</th></tr></thead>
      <tbody><?php foreach ($sum['items'] as $x): ?><tr><td><?= h($x['name']) ?></td><td class="num"><?= (int) $x['qty'] ?></td><td class="num"><?= h(bv_money($x['cents'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>

  <h3 style="font-size:1rem;margin:16px 0 6px;">Dernières ventes <a class="btn btn-small" style="margin-left:8px" href="<?= h($self) ?>?csv=1">Exporter en CSV</a></h3>
  <?php if (!$sales): ?>
    <p class="bv-muted">Aucune vente pour le moment.</p>
  <?php else: ?>
    <div class="bv-table-wrap"><table class="bv-table">
      <thead><tr><th>Heure</th><th>Détail</th><th>Paiement</th><th class="num">Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sales as $s): $t = (int) strtotime((string) $s['created_at']); ?>
        <tr<?= $s['voided_at'] !== null || $s['status'] === 'canceled' ? ' style="opacity:.5;text-decoration:line-through"' : '' ?>>
          <td><?= h(date('d/m H:i', $t)) ?></td>
          <td><?= h(bv_sale_label($s)) ?><?= $s['by_name'] !== '' ? ' <span class="bv-muted">· ' . h($s['by_name']) . '</span>' : '' ?><?= $s['note'] !== '' ? ' <span class="bv-badge">' . h($s['note']) . '</span>' : '' ?></td>
          <td><?= h(bv_method_label($s['method'])) ?><?= $s['status'] === 'pending' ? ' · en attente' : ($s['status'] === 'canceled' ? ' · abandonné' : '') ?></td>
          <td class="num"><?= h(bv_money($s['total_cents'])) ?></td>
          <td><?php if ($s['voided_at'] === null && $s['status'] === 'paid'): ?><?= bv_form_open('void', ['id' => $s['id']], 'Annuler cette vente de ' . bv_money($s['total_cents']) . ($s['method'] === 'stripe' ? ' ? Le client sera remboursé par Stripe.' : ' ?')) ?><button class="btn btn-small" type="submit">Annuler</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
<?php endif;

// ── Gratuités des bénévoles ──
$quotas = bv_gift_quotas();
?>
<h2 id="gratuites" style="font-size:1.15rem;margin:28px 0 6px;">Gratuités des bénévoles</h2>
<p class="bv-hint">Chaque bénévole a un badge QR personnel : le serveur le scanne dans la caisse (« 📷 Scanner ») ou avec l'appareil photo du téléphone, puis touche l'article offert. Les quotas s'appliquent par événement ; un article offert n'entre ni dans la recette ni dans les quantités vendues du bilan, il est compté à part. <strong>Boissons</strong> = catégories « boissons » de la carte, tout le reste = <strong>repas</strong>.</p>
<?= bv_form_open('quotas', [], '', 'bv-inline') ?>
  <label>Boissons offertes par bénévole <input type="number" name="quota_bar" min="0" max="<?= BV_GIFT_MAX_QUOTA ?>" value="<?= (int) $quotas['bar'] ?>" style="width:5em"></label>
  <label style="margin-left:12px">Repas offerts par bénévole <input type="number" name="quota_food" min="0" max="<?= BV_GIFT_MAX_QUOTA ?>" value="<?= (int) $quotas['food'] ?>" style="width:5em"></label>
  <button class="btn btn-small" type="submit">Enregistrer</button>
  <a class="btn btn-small" style="margin-left:8px" href="<?= h($self) ?>?badges=1">Imprimer les badges</a>
</form>
<?php if ($event):
    $gs = bv_gifts_summary((int) $event['id']);
    $gifts = bv_gifts_list((int) $event['id']);
    $vols = bv_volunteers_list(true);
    ?>
  <p class="bv-hint" style="margin-top:12px"><?= (int) $gs['count'] ?> gratuité(s) servie(s) (<?= (int) $gs['bar'] ?> boisson(s), <?= (int) $gs['food'] ?> repas) · valeur offerte : <strong><?= h(bv_money($gs['value_cents'])) ?></strong></p>
  <?php if ($vols): ?>
    <div class="bv-table-wrap"><table class="bv-table">
      <thead><tr><th>Bénévole</th><th class="num">Boissons</th><th class="num">Repas</th></tr></thead>
      <tbody><?php foreach ($vols as $v): $st = bv_gift_status((int) $event['id'], (int) $v['id']); ?>
        <tr><td><?= h($v['name']) ?></td><td class="num"><?= (int) $st['bar']['used'] ?> / <?= (int) $st['bar']['quota'] ?></td><td class="num"><?= (int) $st['food']['used'] ?> / <?= (int) $st['food']['quota'] ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
  <?php if ($gifts): ?>
    <h3 style="font-size:1rem;margin:16px 0 6px;">Journal des gratuités</h3>
    <div class="bv-table-wrap"><table class="bv-table">
      <thead><tr><th>Heure</th><th>Bénévole</th><th>Article</th><th>Servi par</th><th></th></tr></thead>
      <tbody><?php foreach ($gifts as $g): $t = (int) strtotime((string) $g['created_at']); ?>
        <tr<?= $g['voided_at'] !== null ? ' style="opacity:.5;text-decoration:line-through"' : '' ?>>
          <td><?= h(date('d/m H:i', $t)) ?></td><td><?= h((string) $g['volunteer']) ?></td><td><?= h($g['name']) ?> <span class="bv-muted">· <?= h(mb_strtolower(bv_gift_kind_label($g['kind']))) ?></span></td><td><?= h($g['by_name']) ?><?= $g['note'] !== '' ? ' <span class="bv-badge">' . h($g['note']) . '</span>' : '' ?></td>
          <td><?php if ($g['voided_at'] === null): ?><?= bv_form_open('void_gift', ['id' => $g['id']], 'Annuler cette gratuité ?') ?><button class="btn btn-small" type="submit">Annuler</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
<?php endif;
app_install_cards_script();
bv_admin_footer();
