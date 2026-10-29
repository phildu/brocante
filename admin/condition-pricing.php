<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    try {
        $input = is_array($_POST['rates'] ?? null) ? $_POST['rates'] : [];
        $rates = [];
        foreach (product_condition_options() as $items) {
            foreach ($items as $key => $_) {
                $raw = $input[$key] ?? null;
                if (!is_scalar($raw) || !is_numeric($raw) || !is_finite((float) $raw) || (float) $raw < 0 || (float) $raw > 300) {
                    throw new InvalidArgumentException('Chaque pourcentage doit être compris entre 0 et 300.');
                }
                $rates[$key] = round((float) $raw, 2);
            }
        }
        shop_setting_set('product_condition_price_rates', json_encode($rates, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        flash_set('Barème des états enregistré pour cette boutique.');
    } catch (InvalidArgumentException $e) {
        flash_set($e->getMessage(), 'error');
    } catch (JsonException $e) {
        flash_set("Le barème n'a pas pu être enregistré.", 'error');
    }
    header('Location: /admin/condition-pricing.php');
    exit;
}

$content = get_content();
$flash = flash_get();
$rates = product_condition_price_rates();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Barème des états — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .condition-rates { width: 100%; max-width: 720px; border-collapse: collapse; margin: 16px 0; }
  .condition-rates th, .condition-rates td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); }
  .condition-rates th { color: var(--ink-soft); font-size: .78rem; text-transform: uppercase; }
  .condition-rates input { width: 100px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'bareme-etat'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Catalogue</p>
    <h1 style="font-size:1.8rem;margin:12px 0;">Barème des états</h1>
    <p class="hint">Chaque suggestion est calculée à titre indicatif à partir du prix du neuf : prix du neuf × pourcentage de l’état. Le prix de vente n’est jamais modifié sans votre action. Ces taux sont propres à cette boutique.</p>
    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>" role="status"><?= h($flash['message']) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
      <?php foreach (product_condition_options() as $group => $items): ?>
        <h2 style="font-size:1.1rem;margin:20px 0 4px;"><?= h($group) ?></h2>
        <table class="condition-rates">
          <thead><tr><th>État du produit</th><th>Part du prix du neuf</th></tr></thead>
          <tbody>
          <?php foreach ($items as $key => [$label, $description]): ?>
            <tr><td><label for="rate-<?= h($key) ?>"><?= h($label) ?><br><small class="hint"><?= h($description) ?></small></label></td>
              <td><input id="rate-<?= h($key) ?>" type="number" name="rates[<?= h($key) ?>]" min="0" max="300" step="0.1" inputmode="decimal" required value="<?= h(rtrim(rtrim(number_format($rates[$key], 2, '.', ''), '0'), '.')) ?>"> %</td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endforeach; ?>
      <p><button type="submit" class="btn btn-primary">Enregistrer le barème</button> <a class="btn" href="/admin/catalog.php">Retour au catalogue</a></p>
    </form>
    <p class="hint">Valeurs proposées à titre de base, à adapter selon votre activité et vos pratiques d’estimation. Une valeur de 0 % désactive la suggestion pour l’état concerné.</p>
  </div>
</section>
</main>
</body>
</html>
