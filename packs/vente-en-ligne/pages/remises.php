<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vel.php';

$tiers = cart_discounts_list(false);
$flash = flash_get();
?>
<link rel="stylesheet" href="<?= h($pluginAsset('vel.css')) ?>">
<div id="vel-page" data-api="<?= h($pluginApi('action')) ?>" data-csrf="<?= h($pluginCsrf) ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Remises du panier</h1>
  <p class="hint" style="max-width:75ch;">
    Chaque palier retire un <strong>pourcentage</strong> ou un <strong>montant</strong> dès que le panier atteint un
    <strong>total</strong> ou un <strong>nombre d'articles</strong>. Seul le palier le plus avantageux s'applique (pas de
    cumul) ; la remise apparaît dans le panier et sur la page de paiement Stripe. Le total se calcule sur les prix affichés
    (promotions comprises), hors frais de port ; un lot compte pour un article.
  </p>

  <?php if ($flash): ?>
    <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:14px 0;"><?= h($flash['message']) ?></p>
  <?php endif; ?>
  <p class="vel-msg" id="vel-msg" role="status"></p>

  <section class="vel-card">
    <h2>Paliers</h2>
    <?php if (!$tiers): ?>
      <p class="empty-state" style="margin:10px 0 0;">Aucune remise pour l'instant : ajoutez un palier ci-dessous.</p>
    <?php else: ?>
      <table class="vel">
        <thead><tr><th>Condition</th><th>Remise</th><th>Nom affiché</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tiers as $t): $n = (int) $t['threshold']; ?>
          <tr<?= $t['is_active'] ? '' : ' style="opacity:.55"' ?>>
            <td><?= $t['kind'] === 'qty' ? 'Dès ' . $n . ' article' . ($n > 1 ? 's' : '') : 'Total dès ' . h(format_cents($n)) ?></td>
            <td><strong><?= h(cart_discount_effect($t)) ?></strong></td>
            <td><?= h(cart_discount_label($t)) ?></td>
            <td><?= $t['is_active'] ? 'Active' : 'Désactivée' ?></td>
            <td class="r">
              <form data-vel-form="discount_toggle" style="display:inline;"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button type="submit" class="btn-small"><?= $t['is_active'] ? 'Désactiver' : 'Activer' ?></button></form>
              <form data-vel-form="discount_delete" data-confirm="Supprimer ce palier ?" style="display:inline;"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button type="submit" class="admin-delete">Supprimer</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="vel-card">
    <h2>Ajouter un palier</h2>
    <form class="vel-form" data-vel-form="discount_add">
      <label>Se déclenche selon
        <select name="kind"><option value="total">le total du panier</option><option value="qty">le nombre d'articles</option></select>
      </label>
      <label>À partir de (€ ou articles) <input type="number" name="threshold" min="1" step="0.01" inputmode="decimal" placeholder="ex : 100 ou 3" required></label>
      <label>Type de remise
        <select name="type"><option value="percent">Pourcentage (%)</option><option value="fixed">Montant fixe (€)</option></select>
      </label>
      <label>Valeur <input type="number" name="value" min="0.01" step="0.01" inputmode="decimal" placeholder="ex : 10" required></label>
      <label>Nom affiché (facultatif) <input type="text" name="label" maxlength="60" placeholder="ex : Remise 3 articles"></label>
      <button type="submit" class="btn-small">Ajouter</button>
    </form>
    <p class="hint" style="margin:10px 0 0;">Exemples : « total, 100, pourcentage, 10 » = −10 % dès 100 € ; « articles, 3, montant fixe, 5 » = −5 € dès 3 articles.</p>
  </section>
</div>
<script src="<?= h($pluginAsset('vel.js')) ?>" defer></script>
