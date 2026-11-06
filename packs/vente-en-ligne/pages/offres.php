<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vel.php';

$all = isset($_GET['tout']);
$offers = db()->query('SELECT o.*, p.name AS product_name, p.price, p.promo_price, p.badge, p.price_floor, p.stock FROM price_offers o LEFT JOIN products p ON p.ref = o.ref'
    . ($all ? '' : " WHERE o.status = 'new'") . ' ORDER BY o.id DESC LIMIT 200')->fetchAll();
$newCount = (int) db()->query("SELECT COUNT(*) FROM price_offers WHERE status = 'new'")->fetchColumn();
$flash = flash_get();
?>
<link rel="stylesheet" href="<?= h($pluginAsset('vel.css')) ?>">
<div id="vel-page" data-api="<?= h($pluginApi('action')) ?>" data-csrf="<?= h($pluginCsrf) ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Offres de prix</h1>
  <p class="hint" style="max-width:78ch;">
    Les clients proposent un prix sur les pièces dont la fiche a la case « Prix négociable ». Répondez-leur par e-mail ;
    s'ils conviennent d'un prix, modifiez le prix affiché de la pièce (ou mettez-la en promo) pour qu'ils l'achètent au panier.
    Le <strong>prix de vente minimum</strong> reste interne.
  </p>

  <?php if ($flash): ?>
    <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:14px 0;"><?= h($flash['message']) ?></p>
  <?php endif; ?>
  <p class="vel-msg" id="vel-msg" role="status"></p>

  <nav class="vel-tabs" aria-label="Filtrer les offres">
    <a href="<?= h(vel_page_url($plugin, 'offres')) ?>"<?= $all ? '' : ' aria-current="page"' ?>>À traiter<small><?= $newCount ?></small></a>
    <a href="<?= h(vel_page_url($plugin, 'offres', ['tout' => 1])) ?>"<?= $all ? ' aria-current="page"' : '' ?>>Toutes</a>
  </nav>

  <?php if (!$offers): ?>
    <p class="empty-state"><?= $all ? 'Aucune offre reçue pour le moment.' : 'Aucune offre à traiter.' ?></p>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="vel">
        <thead><tr><th>Date</th><th>Article</th><th class="r">Offre</th><th class="r">Affiché</th><th class="r">Minimum</th><th>Client</th><th>Message</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($offers as $o):
          $shown = (int) (price_to_cents(effective_price(['price' => $o['price'], 'promo_price' => $o['promo_price'], 'badge' => $o['badge']])) ?? 0);
          $floor = (int) (price_to_cents((string) $o['price_floor']) ?? 0);
          $subject = rawurlencode('Votre offre sur « ' . $o['product_name'] . ' »');
        ?>
          <tr<?= $o['status'] === 'done' ? ' style="opacity:.55"' : '' ?>>
            <td style="white-space:nowrap;"><?= h(date('d/m/Y H:i', strtotime($o['created_at'] . ' UTC'))) ?></td>
            <td><a href="/admin/catalog.php#produit-<?= h($o['ref']) ?>"><?= h($o['product_name'] ?: 'Pièce supprimée') ?></a><small>Réf. N°<?= h($o['ref']) ?><?= $o['stock'] !== null && (int) $o['stock'] <= 0 ? ' · vendue' : '' ?></small></td>
            <td class="r"><strong><?= h(format_cents((int) $o['amount_cents'])) ?></strong>
              <?php if ($floor > 0): ?><small style="color:<?= (int) $o['amount_cents'] >= $floor ? '#1b6e3c' : '#b3261e' ?>;font-weight:700;"><?= (int) $o['amount_cents'] >= $floor ? 'au-dessus du minimum' : 'sous le minimum' ?></small><?php endif; ?>
            </td>
            <td class="r"><?= $shown ? h(format_cents($shown)) : '—' ?></td>
            <td class="r"><?= $floor ? h(format_cents($floor)) : '—' ?></td>
            <td><?= $o['name'] !== '' ? h($o['name']) . '<br>' : '' ?><a href="mailto:<?= h($o['email']) ?>?subject=<?= $subject ?>"><?= h($o['email']) ?></a></td>
            <td style="max-width:320px;white-space:pre-wrap;"><?= h($o['message']) ?></td>
            <td class="r" style="white-space:nowrap;">
              <?php if ($o['status'] === 'new'): ?>
                <form data-vel-form="offer_status" style="display:inline;"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="status" value="done"><button type="submit" class="btn-small">Traitée</button></form>
              <?php else: ?>
                <form data-vel-form="offer_status" style="display:inline;"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="status" value="new"><button type="submit" class="btn-small">Rouvrir</button></form>
              <?php endif; ?>
              <form data-vel-form="offer_delete" data-confirm="Supprimer cette offre ?" style="display:inline;"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><button type="submit" class="admin-delete">×</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('vel.js')) ?>" defer></script>
