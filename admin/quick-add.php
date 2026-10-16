<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$aiReady = (bool) GEMINI_API_KEY;
// Angles proposés : [clé, titre, conseil de prise de vue, obligatoire].
$angles = [
    ['face', 'Face', "L'objet entier, de face, sur un fond simple", true],
    ['profil', 'Profil', 'De côté, pour montrer la forme et l’épaisseur', false],
    ['dos', 'Dos', 'L’arrière de la pièce', false],
    ['detail', 'Détail', 'Marque, signature, poinçon ou défaut', false],
    ['dessous', 'Dessous', 'Le dessous ou l’intérieur', false],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f4eee1">
<title>Nouvelle pièce — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/studio.css">
<link rel="stylesheet" href="/assets/barcode-scan.css">
<link rel="stylesheet" href="/assets/object-picker.css">
<link rel="stylesheet" href="/assets/share.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.css') ?>">
<link rel="stylesheet" href="/assets/measure-tool.css">
<style>
  .qa-phone { border: 1px solid var(--line); background: var(--surface); padding: 14px; display: grid; grid-template-columns: 112px 1fr; gap: 14px; align-items: center; }
  .qa-phone .phone-qr { background: #fff; padding: 6px; line-height: 0; border: 1px solid var(--line); }
  .qa-phone .phone-qr svg { width: 100%; height: auto; display: block; }
  .qa-phone h2 { font-size: 1rem; margin: 0 0 4px; }
  .qa-phone p { margin: 0 0 6px; font-size: 0.85rem; color: var(--ink-soft); }
  .qa-phone a.btn { display: none; }
  @media (max-width: 860px) { .qa-phone { grid-template-columns: 1fr; } .qa-phone .phone-qr { display: none; } .qa-phone a.btn { display: inline-flex; } }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'prise'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<?php
require_once __DIR__ . '/../includes/share.php';
[$studioUrl, $studioReachable] = studio_public_url();
$studioSite = trim((string) get_content()['site_name']) ?: (string) tenant('name');
$studioShare = ' data-share-url="' . h($studioUrl) . '" data-share-title="' . h('Studio — ' . $studioSite) . '" data-share-text="' . h("Studio de $studioSite : photographiez vos pièces avec votre téléphone, l'application prépare les fiches de la boutique. Ouvrez le lien, connectez-vous, puis « Ajouter à l'écran d'accueil ».") . '" data-share-dialog="1"';
$qaOpts = ['action' => '/admin/quick-add-action.php', 'again' => '/admin/quick-add.php', 'list' => '/admin/catalog.php#produit-{ref}', 'list_label' => 'Voir dans le catalogue', 'title' => true];
?>
<div class="qa" style="padding-bottom:0;gap:0;">
  <section class="qa-phone" aria-label="Application smartphone">
    <div class="phone-qr" id="studio-qr" role="img" aria-label="Code QR de l'application smartphone"></div>
    <div>
      <h2>Faire son shooting au smartphone</h2>
      <p>Ouvrez l'application <strong>Studio</strong> sur votre téléphone : pièce par pièce, ou en lot (une série de pièces photographiées à la suite, traitées ensuite). Scannez ce code, puis « Ajouter à l'écran d'accueil ».</p>
      <a class="btn btn-primary" href="<?= h($studioUrl) ?>">Ouvrir l'application Studio</a>
      <button type="button" class="btn btn-ghost" data-share-open="share"<?= $studioShare ?>>Partager le lien</button>
      <button type="button" class="btn btn-ghost" data-share-open="qr"<?= $studioShare ?>>Code QR</button>
      <p style="margin-top:6px;"><a href="<?= h($studioUrl) ?>" style="word-break:break-all;"><?= h($studioUrl) ?></a></p>
      <?php if (!$studioReachable): ?><p class="qa-warn">Adresse locale : un téléphone ne peut pas l'ouvrir. En ligne, le code fonctionne.</p><?php endif; ?>
    </div>
  </section>
</div>
<?php include __DIR__ . '/../includes/quick-add-flow.php'; ?>
</main>

<div class="qa-bar">
  <button type="button" class="btn btn-primary" id="main-btn" disabled>Prenez au moins la photo de face</button>
</div>

<script src="/assets/vendor/qrcode-generator.js"></script>
<script>
(function () {
  var box = document.getElementById('studio-qr');
  if (!box || typeof qrcode !== 'function') return;
  var qr = qrcode(0, 'M');
  qr.addData(<?= json_encode($studioUrl) ?>);
  qr.make();
  box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
})();
</script>
<script src="/assets/share.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.js') ?>" defer></script>
<script src="/assets/product-ai.js"></script>
<script src="/assets/nature-select.js"></script>
<script src="/assets/price-research.js"></script>
<script src="/assets/saved-prompts.js"></script>
<script src="/assets/prompt-helper.js"></script>
<script src="/assets/barcode-scan.js"></script>
<script src="/assets/measure-tool.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/measure-tool.js') ?>"></script>
<script src="/assets/object-picker.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/object-picker.js') ?>"></script>
<script src="/assets/quick-add.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/quick-add.js') ?>"></script>
</body>
</html>
