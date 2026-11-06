<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$tiers = cart_discounts_list(false);
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Remises du panier — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  table.discount-table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 0.92rem; }
  table.discount-table th, table.discount-table td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--line); }
  table.discount-table th { color: var(--ink-soft); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; }
  .discount-off { opacity: 0.5; }
  .discount-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; align-items: end; margin-top: 14px; }
  .discount-form label { display: block; font-size: 0.8rem; color: var(--ink-soft); margin-bottom: 4px; }
  .discount-form input, .discount-form select { width: 100%; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px; font-family: var(--font-body); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'remises'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Panier</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Remises du panier</h1>
    <p class="hint">
      Chaque palier retire un <strong>pourcentage</strong> ou un <strong>montant en euros</strong> dès que le panier atteint
      un <strong>total</strong> d'achats ou un <strong>nombre d'articles</strong>. Quand plusieurs paliers sont atteints,
      seul le plus avantageux pour le client s'applique (pas de cumul). La remise s'affiche dans le panier, avec une
      incitation vers le palier suivant, puis elle est reprise sur la page de paiement Stripe.
      Le total se calcule sur les prix affichés (promotions comprises), hors frais de port ; un lot compte pour un article.
    </p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div class="admin-block" style="margin-top:20px;">
      <h2 style="font-size:1.1rem;">Paliers</h2>
      <?php if (!$tiers): ?>
        <p class="empty-state" style="margin:14px 0 0;">Aucune remise pour l'instant : ajoutez un palier ci-dessous.</p>
      <?php else: ?>
        <table class="discount-table">
          <thead><tr><th>Condition</th><th>Remise</th><th>Nom affiché</th><th>État</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($tiers as $t): $n = (int) $t['threshold']; ?>
            <tr class="<?= $t['is_active'] ? '' : 'discount-off' ?>">
              <td><?= $t['kind'] === 'qty' ? 'Dès ' . $n . ' article' . ($n > 1 ? 's' : '') : 'Total dès ' . h(format_cents($n)) ?></td>
              <td><strong><?= h(cart_discount_effect($t)) ?></strong></td>
              <td><?= h(cart_discount_label($t)) ?></td>
              <td><?= $t['is_active'] ? 'Active' : 'Désactivée' ?></td>
              <td style="white-space:nowrap;">
                <form method="post" action="/admin/discounts-action.php" style="display:inline;">
                  <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button type="submit" class="btn-small"><?= $t['is_active'] ? 'Désactiver' : 'Activer' ?></button>
                </form>
                <form method="post" action="/admin/discounts-action.php" style="display:inline;" onsubmit="return confirm('Supprimer ce palier ?');">
                  <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button type="submit" class="admin-delete">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="admin-block" style="margin-top:24px;">
      <h2 style="font-size:1.1rem;">Ajouter un palier</h2>
      <form method="post" action="/admin/discounts-action.php" class="discount-form">
        <input type="hidden" name="action" value="add">
        <div>
          <label for="d-kind">Se déclenche selon…</label>
          <select id="d-kind" name="kind">
            <option value="total">le total du panier</option>
            <option value="qty">le nombre d'articles</option>
          </select>
        </div>
        <div>
          <label for="d-threshold">À partir de (€ ou nombre d'articles)</label>
          <input id="d-threshold" type="number" name="threshold" min="1" step="0.01" inputmode="decimal" placeholder="ex : 100 ou 3" required>
        </div>
        <div>
          <label for="d-type">Type de remise</label>
          <select id="d-type" name="type">
            <option value="percent">Pourcentage (%)</option>
            <option value="fixed">Montant fixe (€)</option>
          </select>
        </div>
        <div>
          <label for="d-value">Valeur</label>
          <input id="d-value" type="number" name="value" min="0.01" step="0.01" inputmode="decimal" placeholder="ex : 10" required>
        </div>
        <div>
          <label for="d-label">Nom affiché (facultatif)</label>
          <input id="d-label" type="text" name="label" maxlength="60" placeholder="ex : Remise 3 articles">
        </div>
        <div><button type="submit" class="btn btn-primary">Ajouter</button></div>
      </form>
      <p class="hint" style="margin-top:12px;">Exemples : « le total du panier, à partir de 100, pourcentage, 10 » = −10 % dès 100 € d'achats ; « le nombre d'articles, à partir de 3, montant fixe, 5 » = −5 € dès 3 articles.</p>
    </div>
  </div>
</section>
</main>
</body>
</html>
