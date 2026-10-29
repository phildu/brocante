<?php
// Aperçu du menu latéral : le classement est commun à toutes les boutiques et
// se règle dans le portail (portail/menu.php, fichier data/admin-menu.json).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-menu.php';
require_admin();

$content = get_content();
$layout = admin_menu_layout(admin_menu_items(admin_role()));
$custom = admin_menu_global_config() !== null;
$itemCount = array_sum(array_map(static fn (array $g): int => count($g['items']), $layout));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Menu latéral — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .menu-help { max-width: 760px; }
  .menu-preview { display: grid; gap: 12px; margin: 20px 0 0; max-width: 760px; }
  .menu-preview section { border: 1px solid var(--line); background: var(--surface); padding: 12px 16px; }
  .menu-preview h2 { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin: 0; font-size: 1rem; }
  .menu-preview h2 span { font-family: var(--font-mono); font-size: 0.7rem; font-weight: 400; color: var(--ink-soft); white-space: nowrap; }
  .menu-preview ul { list-style: none; margin: 10px 0 0; padding: 0; display: grid; gap: 4px; }
  .menu-preview li { display: flex; align-items: center; gap: 10px; padding: 6px 8px; border: 1px solid var(--line); background: var(--bg); }
  .menu-preview li .admin-ico { width: 18px; height: 18px; flex: none; color: var(--ink-soft); }
  .menu-preview li span { font-size: 0.9rem; }
  .menu-preview li small { margin-left: auto; font-family: var(--font-mono); font-size: 0.64rem; color: var(--ink-soft); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .menu-preview p { margin: 10px 0 0; font-size: 0.82rem; color: var(--ink-soft); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'menu'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique · Réglages</p>
    <h1 style="font-size:2rem;margin:12px 0 8px;">Menu latéral</h1>
    <p class="hint menu-help" style="margin:0 0 4px;">Le classement de la barre latérale est <strong>commun à toutes les boutiques</strong> : catégories, ordre, entrées masquées et items créés se règlent une fois dans le portail des commerces.
      Ici, chaque boutique n’affiche que les entrées ouvertes à ses rôles et les pages de ses plugins actifs ; les entrées masquées n’apparaissent pas. <?= (int) $itemCount ?> entrée<?= $itemCount > 1 ? 's' : '' ?> au total.</p>
    <p style="margin:18px 0 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <a class="btn btn-primary" href="/portail/menu.php" target="_blank" rel="noopener">Gérer le menu dans le portail ↗</a>
      <span class="hint" style="margin:0;"><?= $custom ? 'Classement personnalisé pour toutes les boutiques.' : 'Valeurs de départ : rien n’a encore été personnalisé.' ?></span>
    </p>

    <div class="menu-preview">
      <?php foreach ($layout as $groupRow): ?>
        <section>
          <h2><?= h($groupRow['label']) ?><span><?= count($groupRow['items']) ?> entrée<?= count($groupRow['items']) > 1 ? 's' : '' ?></span></h2>
          <?php if ($groupRow['items']): ?>
            <ul>
              <?php foreach ($groupRow['items'] as $key => [$href, $label, $icon]): ?>
                <li><?= $adminIcon($icon) ?><span><?= h($label) ?></span><small><?= h($href) ?></small></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p>Catégorie vide — elle ne s’affiche pas dans la barre.</p>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
</section>
</main>
</body>
</html>
