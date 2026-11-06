<?php
// Offres de prix reçues sur les articles « prix négociable » (formulaire « Faire une offre » de la fiche, voir offre.php) : l'offre, le prix affiché et le prix de
// vente minimum interne, le client, son message ; répondre par e-mail, marquer traitée ou supprimer.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) { flash_set('Page expirée : rechargez-la et recommencez.', 'error'); header('Location: /admin/offers.php'); exit; }
    $id = (int) ($_POST['id'] ?? 0);
    switch ((string) ($_POST['action'] ?? '')) {
        case 'done': db()->prepare("UPDATE price_offers SET status = 'done' WHERE id = ?")->execute([$id]); break;
        case 'reopen': db()->prepare("UPDATE price_offers SET status = 'new' WHERE id = ?")->execute([$id]); break;
        case 'delete': db()->prepare('DELETE FROM price_offers WHERE id = ?')->execute([$id]); flash_set('Offre supprimée.'); break;
    }
    header('Location: /admin/offers.php' . (isset($_GET['tout']) ? '?tout=1' : ''));
    exit;
}

$content = get_content();
$all = isset($_GET['tout']);
$offers = db()->query('SELECT o.*, p.name AS product_name, p.price, p.promo_price, p.badge, p.price_floor, p.stock FROM price_offers o LEFT JOIN products p ON p.ref = o.ref'
    . ($all ? '' : " WHERE o.status = 'new'") . ' ORDER BY o.id DESC LIMIT 200')->fetchAll();
$newCount = (int) db()->query("SELECT COUNT(*) FROM price_offers WHERE status = 'new'")->fetchColumn();
$flash = flash_get();
$csrf = admin_csrf_token();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offres de prix — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  table.of { width: 100%; border-collapse: collapse; font-size: .9rem; margin-top: 14px; }
  .of th, .of td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
  .of th { font-size: .74rem; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); }
  .of td.r { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .of small { color: var(--ink-soft); display: block; }
  .of .ok { color: #1b6e3c; font-weight: 700; } .of .low { color: #b3261e; font-weight: 700; }
  .of form { display: inline; } .of .msg { max-width: 320px; white-space: pre-wrap; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'offres'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Prix négociable</p>
    <h1 style="font-size:1.8rem;margin:12px 0 8px;">Offres de prix</h1>
    <p class="hint">Les clients proposent un prix sur les articles dont la fiche a la case « Prix négociable ». Répondez-leur par e-mail ; s'ils conviennent d'un prix, modifiez le prix affiché de la pièce (ou mettez-la en promo) pour qu'ils l'achètent au panier. Le <strong>prix de vente minimum</strong> reste interne.</p>
    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p><?php endif; ?>
    <p style="margin-top:12px;"><a class="btn-small" href="/admin/offers.php" style="text-decoration:none;<?= $all ? '' : 'font-weight:700;' ?>">À traiter (<?= $newCount ?>)</a> <a class="btn-small" href="/admin/offers.php?tout=1" style="text-decoration:none;<?= $all ? 'font-weight:700;' : '' ?>">Toutes</a></p>
    <?php if (!$offers): ?>
      <p class="hint" style="margin-top:14px;"><?= $all ? 'Aucune offre reçue pour le moment.' : 'Aucune offre à traiter.' ?></p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="of">
      <thead><tr><th>Date</th><th>Article</th><th class="r">Offre</th><th class="r">Affiché</th><th class="r">Minimum</th><th>Client</th><th>Message</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($offers as $o):
        $shown = (int) (price_to_cents(effective_price(['price' => $o['price'], 'promo_price' => $o['promo_price'], 'badge' => $o['badge']])) ?? 0);
        $floor = (int) (price_to_cents((string) $o['price_floor']) ?? 0);
        $sub = rawurlencode('Votre offre sur « ' . $o['product_name'] . ' »');
      ?>
        <tr<?= $o['status'] === 'done' ? ' style="opacity:.55"' : '' ?>>
          <td><?= h(date('d/m/Y H:i', strtotime($o['created_at'] . ' UTC'))) ?></td>
          <td><a href="/admin/catalog.php#produit-<?= h($o['ref']) ?>"><?= h($o['product_name'] ?: 'Pièce supprimée') ?></a><small>Réf. N°<?= h($o['ref']) ?><?= $o['stock'] !== null && (int) $o['stock'] <= 0 ? ' · vendue' : '' ?></small></td>
          <td class="r"><strong><?= h(format_cents((int) $o['amount_cents'])) ?></strong><?php if ($floor > 0): ?><small class="<?= (int) $o['amount_cents'] >= $floor ? 'ok' : 'low' ?>"><?= (int) $o['amount_cents'] >= $floor ? 'au-dessus du minimum' : 'sous le minimum' ?></small><?php endif; ?></td>
          <td class="r"><?= $shown ? h(format_cents($shown)) : '—' ?></td>
          <td class="r"><?= $floor ? h(format_cents($floor)) : '—' ?></td>
          <td><?= $o['name'] !== '' ? h($o['name']) . '<br>' : '' ?><a href="mailto:<?= h($o['email']) ?>?subject=<?= $sub ?>"><?= h($o['email']) ?></a></td>
          <td class="msg"><?= h($o['message']) ?></td>
          <td>
            <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <?php if ($o['status'] === 'new'): ?><button class="btn-small" name="action" value="done">Traitée</button><?php else: ?><button class="btn-small" name="action" value="reopen">Rouvrir</button><?php endif; ?>
              <button class="btn-small" name="action" value="delete" onclick="return confirm('Supprimer cette offre ?')">×</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</section>
</main>
</body>
</html>
