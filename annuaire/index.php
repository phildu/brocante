<?php
// Annuaire public des commerces de la plateforme : recherche par nom et filtre par univers. Données : includes/saas.php (saas_directory).
require_once __DIR__ . '/../includes/saas.php';

$cfg = saas_config();
$all = saas_directory();
$q = trim((string) ($_GET['q'] ?? ''));
$cat = (string) ($_GET['univers'] ?? '');

$universes = [];
foreach ($all as $s) foreach ($s['universes'] as $u) $universes[$u] = ($universes[$u] ?? 0) + 1;
ksort($universes, SORT_NATURAL | SORT_FLAG_CASE);

$shops = array_values(array_filter($all, static function (array $s) use ($q, $cat): bool {
    if ($cat !== '' && !in_array($cat, $s['universes'], true)) return false;
    return $q === '' || mb_stripos($s['name'] . ' ' . $s['tagline'] . ' ' . implode(' ', $s['universes']), $q) !== false;
}));
// Les commerces qui ont des pièces en ligne d'abord.
usort($shops, static fn (array $a, array $b): int => [$b['count'] > 0, $a['name']] <=> [$a['count'] > 0, $b['name']]);

saas_page_start('Annuaire des commerces — ' . $cfg['name'], 'Tous les commerces de ' . $cfg['name'] . ' : recherchez une boutique, filtrez par univers.', 'annuaire');
?>
<div class="wrap">
  <div class="page-title"><p class="eyebrow">Annuaire</p><h1>Les commerces</h1>
    <p class="lede"><?= count($all) ?> commerce<?= count($all) > 1 ? 's' : '' ?> en ligne. Chacun a sa propre boutique, son panier et son paiement.</p></div>

  <form class="filters" method="get" action="/annuaire/">
    <label>Recherche<input type="search" name="q" value="<?= saas_e($q) ?>" placeholder="Un nom, un type de commerce…"></label>
    <label>Univers<select name="univers"><option value="">Tous</option>
      <?php foreach ($universes as $u => $n): ?><option value="<?= saas_e($u) ?>"<?= $cat === $u ? ' selected' : '' ?>><?= saas_e($u) ?> (<?= (int) $n ?>)</option><?php endforeach; ?></select></label>
    <button class="btn" type="submit">Filtrer</button>
    <?php if ($q !== '' || $cat !== ''): ?><a class="btn ghost" href="/annuaire/">Effacer</a><?php endif; ?>
  </form>

  <?php if ($shops): ?>
    <div class="shops" style="margin-bottom:8px">
      <?php foreach ($shops as $s): $initial = mb_strtoupper(mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $s['name']), 0, 1)); ?>
        <a class="shop" href="<?= saas_e($s['url']) ?>">
          <span class="top">
            <span class="avatar"<?= $s['accent'] !== '' ? ' style="color:' . saas_e(saas_ink_on($s['accent'])) . ';background:' . saas_e($s['accent']) . '"' : '' ?>><?= saas_e($initial) ?><?php if ($s['logo'] !== ''): ?><img src="<?= saas_e($s['logo']) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
            <span><h3><?= saas_e($s['name']) ?></h3><p><?= saas_e($s['tagline']) ?><?= $s['tagline'] !== '' ? ' · ' : '' ?><?= (int) $s['count'] ?> pièce<?= $s['count'] > 1 ? 's' : '' ?></p></span>
          </span>
          <?php if ($s['preview']): ?><span class="thumbs" aria-hidden="true"><?php foreach (array_slice($s['preview'], 0, 4) as $img): ?><span style="background-image:url('<?= saas_e($img) ?>')"></span><?php endforeach; ?></span><?php endif; ?>
          <?php if ($s['universes']): ?><span class="pills"><?php foreach (array_slice($s['universes'], 0, 4) as $u): ?><span><?= saas_e($u) ?></span><?php endforeach; ?></span><?php endif; ?>
          <span class="more">Visiter la boutique →</span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="empty"><?= $q !== '' || $cat !== '' ? 'Aucun commerce ne correspond à cette recherche.' : 'Aucun commerce dans l\'annuaire pour le moment.' ?></p>
  <?php endif; ?>

  <div class="cta-band"><h2>Votre commerce n'est pas encore là ?</h2><p>Créez votre boutique en ligne et apparaissez dans l'annuaire.</p><a class="btn" href="/inscription/">Créer ma boutique</a></div>
</div>
<?php saas_page_end(); ?>
