<?php
// Impression d'étiquettes pour les pièces cochées du catalogue : planches adhésives A4 ou rouleau, format réglable.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/share.php';
require_admin();

/** Formats prêts à l'emploi (dimensions en mm). « sheet » : planche A4 ; « roll » : une étiquette par page. À vérifier par un essai sur papier ordinaire. */
$formats = [
    'a4-24' => ['label' => 'Planche A4 — 24 étiquettes de 70 × 37 mm (3 × 8)', 'type' => 'sheet', 'cols' => 3, 'rows' => 8, 'w' => 70, 'h' => 37, 'top' => 0, 'left' => 0, 'gx' => 0, 'gy' => 0],
    'a4-40' => ['label' => 'Planche A4 — 40 étiquettes de 52,5 × 29,7 mm (4 × 10)', 'type' => 'sheet', 'cols' => 4, 'rows' => 10, 'w' => 52.5, 'h' => 29.7, 'top' => 0, 'left' => 0, 'gx' => 0, 'gy' => 0],
    'a4-24m' => ['label' => 'Planche A4 — 24 étiquettes de 63,5 × 33,9 mm (3 × 8, marges)', 'type' => 'sheet', 'cols' => 3, 'rows' => 8, 'w' => 63.5, 'h' => 33.9, 'top' => 12.9, 'left' => 7.2, 'gx' => 2.5, 'gy' => 0],
    'a4-14' => ['label' => 'Planche A4 — 14 étiquettes de 99,1 × 38,1 mm (2 × 7)', 'type' => 'sheet', 'cols' => 2, 'rows' => 7, 'w' => 99.1, 'h' => 38.1, 'top' => 15.1, 'left' => 4.7, 'gx' => 2.5, 'gy' => 0],
    'a4-8' => ['label' => 'Planche A4 — 8 étiquettes de 99,1 × 67,7 mm (2 × 4)', 'type' => 'sheet', 'cols' => 2, 'rows' => 4, 'w' => 99.1, 'h' => 67.7, 'top' => 13.1, 'left' => 4.7, 'gx' => 2.5, 'gy' => 0],
    'a4-65' => ['label' => 'Planche A4 — 65 petites étiquettes de 38,1 × 21,2 mm (5 × 13)', 'type' => 'sheet', 'cols' => 5, 'rows' => 13, 'w' => 38.1, 'h' => 21.2, 'top' => 10.7, 'left' => 4.7, 'gx' => 2.5, 'gy' => 0],
    'roll-57x32' => ['label' => 'Rouleau — 57 × 32 mm', 'type' => 'roll', 'w' => 57, 'h' => 32],
    'roll-62x29' => ['label' => 'Rouleau — 62 × 29 mm', 'type' => 'roll', 'w' => 62, 'h' => 29],
    'roll-40x30' => ['label' => 'Rouleau — 40 × 30 mm', 'type' => 'roll', 'w' => 40, 'h' => 30],
    'roll-fichero' => ['label' => 'Fichero D11s — 15 × 30 mm (Bluetooth)', 'type' => 'roll', 'w' => 15, 'h' => 30],
    'roll-custom' => ['label' => 'Rouleau — dimensions à saisir', 'type' => 'roll', 'w' => 50, 'h' => 30],
];

$num = static fn (string $k, float $d, float $min, float $max): float => max($min, min($max, (float) str_replace(',', '.', (string) ($_GET[$k] ?? $d))));
$fmtKey = isset($formats[$_GET['fmt'] ?? '']) ? (string) $_GET['fmt'] : 'a4-24';
$fmt = $formats[$fmtKey];
if ($fmtKey === 'roll-custom') {
    $fmt['w'] = $num('w', 50, 20, 150);
    $fmt['h'] = $num('h', 30, 15, 150);
}
$skip = (int) $num('skip', 0, 0, 200);
$copies = (int) $num('copies', 1, 1, 20);
$dx = $num('dx', 0, -10, 10);
$dy = $num('dy', 0, -10, 10);
$show = ['name' => !isset($_GET['go']) || !empty($_GET['name']), 'price' => !isset($_GET['go']) || !empty($_GET['price']), 'ref' => !isset($_GET['go']) || !empty($_GET['ref']), 'qr' => !isset($_GET['go']) || !empty($_GET['qr']), 'shop' => !empty($_GET['shop'])];

$refs = array_slice(array_values(array_unique(array_filter(array_map(static fn ($r) => preg_replace('/[^A-Za-z0-9_-]/', '', trim($r)), explode(',', (string) ($_GET['refs'] ?? '')))))), 0, 300);
$products = [];
if ($refs) {
    $in = implode(',', array_fill(0, count($refs), '?'));
    $st = db()->prepare("SELECT * FROM products WHERE ref IN ($in)");
    $st->execute($refs);
    $byRef = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $byRef[$p['ref']] = $p;
    foreach ($refs as $r) if (isset($byRef[$r])) $products[] = $byRef[$r];
}
$site = (string) (get_content()['site_name'] ?? tenant('name'));

$items = array_fill(0, $fmt['type'] === 'sheet' ? $skip : 0, null);
foreach ($products as $p) for ($i = 0; $i < $copies; $i++) $items[] = $p;
$perSheet = $fmt['type'] === 'sheet' ? $fmt['cols'] * $fmt['rows'] : 1;
$pages = $items ? array_chunk($items, $perSheet) : [];
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mm = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . 'mm';
$u = min($fmt['w'], $fmt['h']) / 10;   // unité de mise à l'échelle du texte
$qrMm = max(8, min($fmt['h'] - 4, $fmt['w'] * 0.32));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Étiquettes — <?= $h($site) ?></title>
<style>
  @page { size: <?= $fmt['type'] === 'sheet' ? 'A4 portrait' : $mm($fmt['w']) . ' ' . $mm($fmt['h']) ?>; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #111; background: #e8e8e8; }
  .bar { position: sticky; top: 0; z-index: 5; background: #fff; border-bottom: 1px solid #ccc; padding: 10px 14px; display: flex; gap: 10px 16px; flex-wrap: wrap; align-items: end; font-size: 13px; }
  .bar label { display: grid; gap: 2px; }
  .bar input[type=number], .bar select { padding: 4px 6px; }
  .bar .checks { display: flex; gap: 10px; align-items: center; }
  .bar button { padding: 7px 14px; font-weight: 600; cursor: pointer; }
  .hint { font-size: 12px; color: #555; padding: 8px 14px; background: #fff8e1; border-bottom: 1px solid #e6d9a8; }
  .page { background: #fff; margin: 14px auto; box-shadow: 0 1px 6px rgba(0,0,0,.25); position: relative; overflow: hidden;
          <?php if ($fmt['type'] === 'sheet'): ?>width: 210mm; height: 297mm;<?php else: ?>width: <?= $mm($fmt['w']) ?>; height: <?= $mm($fmt['h']) ?>;<?php endif; ?> }
  .grid { position: absolute; display: grid; <?php if ($fmt['type'] === 'sheet'): ?>
          top: <?= $mm($fmt['top'] + $dy) ?>; left: <?= $mm($fmt['left'] + $dx) ?>;
          grid-template-columns: repeat(<?= (int) $fmt['cols'] ?>, <?= $mm($fmt['w']) ?>); grid-auto-rows: <?= $mm($fmt['h']) ?>; column-gap: <?= $mm($fmt['gx']) ?>; row-gap: <?= $mm($fmt['gy']) ?>;
          <?php else: ?>top: <?= $mm($dy) ?>; left: <?= $mm($dx) ?>; grid-template-columns: <?= $mm($fmt['w']) ?>; grid-auto-rows: <?= $mm($fmt['h']) ?>;<?php endif; ?> }
  .lab { width: <?= $mm($fmt['w']) ?>; height: <?= $mm($fmt['h']) ?>; padding: <?= $mm(max(1, $u * 0.35)) ?>; display: flex; align-items: center; gap: <?= $mm($u * 0.4) ?>; overflow: hidden; --u: <?= $mm($u) ?>; }
  .lab .txt { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: <?= $mm($u * 0.15) ?>; }
  .lab .nm { font-size: calc(var(--u) * 0.72); line-height: 1.12; font-weight: 600; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .lab .pr { font-size: calc(var(--u) * 1.5); line-height: 1; font-weight: 800; }
  .lab .rf { font-size: calc(var(--u) * 0.56); color: #333; font-family: ui-monospace, Menlo, monospace; }
  .lab .sh { font-size: calc(var(--u) * 0.5); color: #555; }
  .lab .qr { flex: 0 0 <?= $mm($qrMm) ?>; width: <?= $mm($qrMm) ?>; height: <?= $mm($qrMm) ?>; }
  .lab .qr svg { width: 100%; height: 100%; display: block; }
  .guides .lab { outline: 0.2mm dashed #bbb; outline-offset: -0.2mm; }
  .empty { display: block; }
  @media print { body { background: #fff; } .bar, .hint { display: none; } .page { margin: 0; box-shadow: none; page-break-after: always; break-after: page; } .guides .lab { outline: none; } }
</style>
</head>
<body>
<form class="bar" method="get" id="bar">
  <input type="hidden" name="go" value="1">
  <input type="hidden" name="refs" value="<?= $h(implode(',', $refs)) ?>">
  <label>Format<select name="fmt" id="fmt"><?php foreach ($formats as $k => $f): ?><option value="<?= $h($k) ?>"<?= $k === $fmtKey ? ' selected' : '' ?>><?= $h($f['label']) ?></option><?php endforeach; ?></select></label>
  <?php if ($fmtKey === 'roll-custom'): ?>
    <label>Largeur (mm)<input type="number" name="w" value="<?= $h($fmt['w']) ?>" min="20" max="150" step="0.5" style="width:80px"></label>
    <label>Hauteur (mm)<input type="number" name="h" value="<?= $h($fmt['h']) ?>" min="15" max="150" step="0.5" style="width:80px"></label>
  <?php endif; ?>
  <?php if ($fmt['type'] === 'sheet'): ?><label>Étiquettes déjà utilisées<input type="number" name="skip" value="<?= (int) $skip ?>" min="0" max="200" style="width:80px"></label><?php endif; ?>
  <label>Exemplaires<input type="number" name="copies" value="<?= (int) $copies ?>" min="1" max="20" style="width:70px"></label>
  <label>Décalage ← → (mm)<input type="number" name="dx" value="<?= $h($dx) ?>" min="-10" max="10" step="0.5" style="width:80px"></label>
  <label>Décalage ↑ ↓ (mm)<input type="number" name="dy" value="<?= $h($dy) ?>" min="-10" max="10" step="0.5" style="width:80px"></label>
  <span class="checks">
    <label><input type="checkbox" name="name" value="1"<?= $show['name'] ? ' checked' : '' ?>> Nom</label>
    <label><input type="checkbox" name="price" value="1"<?= $show['price'] ? ' checked' : '' ?>> Prix</label>
    <label><input type="checkbox" name="ref" value="1"<?= $show['ref'] ? ' checked' : '' ?>> Réf.</label>
    <label><input type="checkbox" name="qr" value="1"<?= $show['qr'] ? ' checked' : '' ?>> QR</label>
    <label><input type="checkbox" name="shop" value="1"<?= $show['shop'] ? ' checked' : '' ?>> Boutique</label>
  </span>
  <button type="submit">Actualiser</button>
  <button type="button" id="remember" title="Réutiliser ces réglages la prochaine fois">Mémoriser ce format</button>
  <button type="button" id="print" style="background:#111;color:#fff;">Imprimer / PDF</button>
  <?php if ($products): ?><button type="button" id="fichero" style="background:#0b57d0;color:#fff;" title="Envoie le QR à l’étiqueteuse Fichero depuis Chrome (Bluetooth). Pas la fenêtre Imprimer / PDF.">Imprimer sur Fichero (Chrome)</button><?php endif; ?>
  <span><?= count($products) ?> pièce(s) · <?= count($pages) ?> page(s)</span>
</form>
<p class="hint">« Imprimer / PDF » ouvre la fenêtre d’impression de Chrome (planche A4, PDF). « Imprimer sur Fichero (Chrome) » envoie le QR à l’étiqueteuse Bluetooth — ouvrez cette page dans <strong>Google Chrome</strong>, imprimante allumée à côté. Pour une planche papier : essai à la lumière, échelle 100 %, marges « aucune ».</p>

<?php if (!$products):
    $all = db()->query('SELECT * FROM products ORDER BY name COLLATE NOCASE LIMIT 500')->fetchAll(PDO::FETCH_ASSOC); ?>
  <section style="max-width:760px;margin:18px auto;padding:16px;background:#fff;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.2);">
    <h1 style="margin:0 0 6px;font-size:1.2rem;">Imprimer des étiquettes</h1>
    <p style="margin:0 0 10px;font-size:13px;color:#555;">Cochez les pièces à étiqueter (nom, prix, référence, QR code), puis « Préparer les étiquettes » : vous choisirez ensuite le format (planche A4 ou rouleau). Vous pouvez aussi cocher des pièces dans le <a href="/admin/catalog.php">catalogue</a> puis « 🏷️ Étiquettes ». <a href="/admin/catalog.php">← Retour au catalogue</a></p>
    <?php if (!$all): ?><p>Aucune pièce dans le catalogue pour le moment.</p><?php else: ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
      <input type="search" id="pick-q" placeholder="Filtrer par nom ou référence" style="padding:6px 8px;flex:1;min-width:200px;">
      <label style="font-size:13px;"><input type="checkbox" id="pick-all"> Tout cocher (pièces affichées)</label>
      <button type="button" id="pick-go" style="padding:7px 14px;font-weight:600;cursor:pointer;background:#111;color:#fff;" disabled>Préparer les étiquettes</button>
      <span id="pick-n" style="font-size:13px;color:#555;"></span>
    </div>
    <div style="max-height:55vh;overflow:auto;border:1px solid #ddd;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;"><tbody id="pick-body">
      <?php foreach ($all as $p): ?>
        <tr data-q="<?= $h(mb_strtolower($p['name'] . ' ' . $p['ref'])) ?>" style="border-bottom:1px solid #eee;"><td style="width:28px;padding:5px 8px;"><input type="checkbox" class="pick" value="<?= $h($p['ref']) ?>"></td><td><?= $h($p['name']) ?></td><td style="color:#555;"><?= $h($p['ref']) ?></td><td style="text-align:right;padding-right:8px;"><?= $h(effective_price($p)) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <script>
    (function () {
      var rows = [].slice.call(document.querySelectorAll('#pick-body tr')), go = document.getElementById('pick-go'), n = document.getElementById('pick-n');
      function checked() { return [].slice.call(document.querySelectorAll('.pick:checked')).map(function (c) { return c.value; }); }
      function upd() { var k = checked().length; go.disabled = k === 0; n.textContent = k ? k + ' pièce' + (k > 1 ? 's' : '') + ' cochée' + (k > 1 ? 's' : '') : ''; }
      document.getElementById('pick-q').addEventListener('input', function () { var q = this.value.trim().toLowerCase(); rows.forEach(function (r) { r.hidden = q !== '' && r.dataset.q.indexOf(q) < 0; }); });
      document.getElementById('pick-all').addEventListener('change', function () { var on = this.checked; rows.forEach(function (r) { if (!r.hidden) r.querySelector('.pick').checked = on; }); upd(); });
      document.getElementById('pick-body').addEventListener('change', upd);
      go.addEventListener('click', function () { location.href = '/admin/labels.php?refs=' + encodeURIComponent(checked().slice(0, 300).join(',')); });
    })();
    </script>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php foreach ($pages as $page): ?>
  <section class="page guides"><div class="grid">
    <?php foreach ($page as $p): ?>
      <?php if ($p === null): ?><div class="lab"></div><?php continue; endif; ?>
      <div class="lab">
        <div class="txt">
          <?php if ($show['shop']): ?><span class="sh"><?= $h($site) ?></span><?php endif; ?>
          <?php if ($show['name']): ?><span class="nm"><?= $h($p['name']) ?></span><?php endif; ?>
          <?php if ($show['price']): ?><span class="pr"><?= $h(effective_price($p)) ?></span><?php endif; ?>
          <?php if ($show['ref']): ?><span class="rf">Réf. <?= $h($p['ref']) ?></span><?php endif; ?>
        </div>
        <?php if ($show['qr']): ?><div class="qr" data-url="<?= $h(shop_abs_url('/produit.php?ref=' . rawurlencode($p['ref']))) ?>"></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div></section>
<?php endforeach; ?>

<script>window.APP_BASE=<?= json_encode(tenant_base_path()) ?>;</script>
<script src="/assets/vendor/qrcode-generator.js"></script>
<script src="/assets/fichero-print.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/fichero-print.js') ?>"></script>
<script>
(function () {
  document.querySelectorAll('.qr').forEach(function (b) {
    var q = qrcode(0, 'M'); q.addData(b.dataset.url); q.make(); b.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
  });
  var form = document.getElementById('bar');
  document.getElementById('fmt').addEventListener('change', function () { form.submit(); });
  document.getElementById('print').addEventListener('click', function () { window.print(); });
  var ficheroBtn = document.getElementById('fichero');
  if (ficheroBtn && window.FicheroPrint) {
    FicheroPrint.bindButton(
      ficheroBtn,
      function () {
        var refs = (form.querySelector('[name=refs]') || {}).value || '';
        return refs.split(',').map(function (r) { return r.trim(); }).filter(Boolean);
      },
      function () { return parseInt((form.querySelector('[name=copies]') || {}).value, 10) || 1; }
    );
  }
  var KEY = 'etiquettes-reglages';
  document.getElementById('remember').addEventListener('click', function () {
    var p = new URLSearchParams(new FormData(form)); p.delete('refs'); p.delete('go');
    try { localStorage.setItem(KEY, p.toString()); alert('Réglages mémorisés pour les prochaines impressions.'); } catch (e) { alert('Mémorisation impossible dans ce navigateur.'); }
  });
  // Première ouverture depuis le catalogue (aucun réglage dans l'adresse) : on reprend le format mémorisé
  var cur = new URLSearchParams(location.search);
  if (!cur.has('go') && cur.get('refs')) {
    try { var saved = localStorage.getItem(KEY); if (saved) { location.replace(location.pathname + '?go=1&refs=' + encodeURIComponent(cur.get('refs')) + '&' + saved); } } catch (e) {}
  }
})();
</script>
</body>
</html>
