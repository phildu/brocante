<?php
// Vocabulaire des extensions : le mot employé partout (portail, administration des boutiques, espace des galeries, inscription, accueil)
// pour les fonctions complémentaires, et la portée de chacune (boutique, galerie ou les deux). Voir includes/vocabulary.php.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/vocabulary.php';
require_once PORTAIL_ROOT . '/includes/plugins.php';
require_once PORTAIL_ROOT . '/includes/addons.php';
require_once PORTAIL_ROOT . '/includes/gallery-modules.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';

/** Toutes les extensions, des deux systèmes : plugins des boutiques (packs/) et options des galeries. id => [nom, portée d'origine, origine, lien de gestion]. */
function vocab_extensions(): array
{
    $out = [];
    $sold = addon_defaults();
    foreach (plugin_catalog() as $id => $m) {
        $out[$id] = [(string) (($sold[$id]['name'] ?? '') ?: $m['name']), (string) $m['scope'], 'shop',
            isset($sold[$id]) ? '/portail/addons.php?plugin=' . rawurlencode($id) : '/portail/plugins.php'];
    }
    foreach (gm_modules() as $key => $m) {
        if (isset($out[$key])) { $out[$key][1] = 'both'; $out[$key][2] = 'both'; continue; }   // même identifiant des deux côtés
        $out[$key] = [(string) $m['name'], 'gallery', 'gallery', '/portail/offres-galerie.php'];
    }
    return $out;
}

$extensions = vocab_extensions();
$defaults = array_map(static fn (array $x): string => $x[1], $extensions);
$presets = vocab_presets();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $scopes = [];
    foreach ((array) ($_POST['scope'] ?? []) as $id => $scope) {
        if (isset($extensions[(string) $id])) $scopes[(string) $id] = (string) $scope;
    }
    $err = vocab_save([
        'singular' => $_POST['singular'] ?? '', 'plural' => $_POST['plural'] ?? '', 'gender' => $_POST['gender'] ?? 'f', 'scopes' => $scopes,
    ], $defaults);
    if ($err === null) {
        saas_event('config_changed', ['note' => 'Vocabulaire des extensions : « ' . ext_word() . ' » / « ' . ext_word('plural') . ' », portées mises à jour', 'actor' => 'exploitant']);
        portail_flash('Enregistré : l’appellation et les portées sont appliquées partout.', 'ok');
    } else {
        portail_flash($err, 'error');
    }
    header('Location: /portail/vocabulaire.php');
    exit;
}

$v = vocab_load();
$flash = portail_flash();
$sample = '{Un ext} activé{e} · {le ext} · {ce ext} · prix {du ext} · {des exts}';
$pageTitle = ext_t('{Exts} · nom et portée');
$counts = ['shop' => 0, 'gallery' => 0, 'both' => 0];
foreach ($extensions as $id => $x) $counts[ext_scope($id, $x[1])]++;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — Portail</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .vc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; margin: 10px 0 16px; }
  .vc-preset { display: grid; gap: 4px; padding: 10px 12px; border: 1px solid var(--line); border-radius: var(--radius, 10px); background: var(--panel); cursor: pointer; text-align: left; font: inherit; color: var(--ink); }
  .vc-preset:hover, .vc-preset.is-current { border-color: var(--accent); }
  .vc-preset.is-current { box-shadow: 0 0 0 1px var(--accent) inset; }
  .vc-preset b { font-size: 1rem; }
  .vc-preset small { color: var(--muted); line-height: 1.35; }
  .vc-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; max-width: 720px; }
  .vc-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
  .vc-table th, .vc-table td { padding: 8px 10px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; }
  .vc-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }
  .vc-table select { min-width: 130px; }
  .vc-table tr.is-changed td { background: var(--accent-soft); }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => $pageTitle,
    'eyebrow' => 'Boutiques',
    'desc' => ext_t('Le mot employé partout pour {les exts} (boutiques et galeries), et ce que chacune concerne.'),
    'section' => 'Boutiques',
]); ?>

  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <form class="send" method="post" action="/portail/vocabulaire.php" id="vocab-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <h2>Le mot</h2>
    <p class="hint">« Plugin » parle surtout aux développeurs. Choisissez le mot que comprendront vos clients : il remplace « plugin », « module » et « option » dans le portail, l’administration des boutiques, l’espace des galeries, l’accueil et l’inscription.
      Les conditions générales de vente ne sont pas modifiées.</p>
    <div class="vc-grid" role="group" aria-label="Mots proposés">
      <?php foreach ($presets as $key => [$ps, $pp, $pg]): $current = $v['singular'] === $ps && $v['plural'] === $pp && $v['gender'] === $pg;
          $pv = ['singular' => $ps, 'plural' => $pp, 'gender' => $pg, 'scopes' => []]; ?>
        <button type="button" class="vc-preset<?= $current ? ' is-current' : '' ?>" data-singular="<?= e($ps) ?>" data-plural="<?= e($pp) ?>" data-gender="<?= e($pg) ?>">
          <b><?= e(vocab_ucfirst($ps)) ?><?= $current ? ' · actuel' : '' ?></b>
          <small><?= e(ext_t($sample, $pv)) ?></small>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="vc-fields">
      <label class="field">Au singulier<input type="text" name="singular" id="vc-singular" maxlength="30" required value="<?= e($v['singular']) ?>"></label>
      <label class="field">Au pluriel<input type="text" name="plural" id="vc-plural" maxlength="30" required value="<?= e($v['plural']) ?>"></label>
      <label class="field">Genre<select name="gender" id="vc-gender">
        <?php foreach (VOCAB_GENDERS as $gk => $gl): ?><option value="<?= e($gk) ?>"<?= $v['gender'] === $gk ? ' selected' : '' ?>><?= e($gl) ?></option><?php endforeach; ?>
      </select></label>
    </div>
    <p class="hint">Le genre sert aux accords : « une extension activée » ou « un plugin activé ». Un mot libre fonctionne aussi (lettres, espaces, tiret, 30 caractères au plus).</p>

    <h2 style="margin-top:28px;">Ce que chacun concerne</h2>
    <p class="hint">La portée s’affiche sous forme d’étiquette (<?= ext_scope_badge('shop') ?> <?= ext_scope_badge('gallery') ?> <?= ext_scope_badge('both') ?>)
      dans la liste <?= e(ext_t('{des exts}')) ?>, leurs tarifs et les écrans des boutiques et des galeries. Pour l’instant, c’est une indication : les réglages de prix et de disponibilité restent
      ceux de chaque côté (portail → Tarifs, Types de galerie).</p>
    <p class="hint"><?= $counts['shop'] ?> pour les boutiques · <?= $counts['gallery'] ?> pour les galeries · <?= $counts['both'] ?> pour les deux.</p>
    <div style="overflow-x:auto;">
    <table class="vc-table">
      <thead><tr><th><?= e(vocab_ucfirst(ext_word())) ?></th><th>Gérée dans</th><th>Concerne</th></tr></thead>
      <tbody>
      <?php foreach ($extensions as $id => [$name, $defaultScope, $origin, $link]): $scope = ext_scope($id, $defaultScope); ?>
        <tr<?= $scope !== $defaultScope ? ' class="is-changed"' : '' ?>>
          <td><strong><?= e($name) ?></strong><br><small class="hint" style="margin:0;"><?= e($id) ?></small></td>
          <td><a href="<?= e($link) ?>"><?= $origin === 'gallery' ? 'Types de galerie' : ($origin === 'both' ? 'Tarifs et types de galerie' : 'Tarifs') ?></a></td>
          <td>
            <select name="scope[<?= e($id) ?>]" aria-label="Portée de <?= e($name) ?>">
              <?php foreach (VOCAB_SCOPES as $sk => $sl): ?><option value="<?= e($sk) ?>"<?= $scope === $sk ? ' selected' : '' ?>><?= e($sl) ?><?= $sk === $defaultScope ? ' (d’origine)' : '' ?></option><?php endforeach; ?>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div class="send-actions" style="margin-top:16px;">
      <button class="btn btn-primary" type="submit">Enregistrer</button>
      <span class="hint">Les lignes surlignées ont une portée différente de celle d’origine.</span>
    </div>
  </form>
</div>
<script>
(function () {
  var s = document.getElementById('vc-singular'), p = document.getElementById('vc-plural'), g = document.getElementById('vc-gender');
  document.querySelectorAll('.vc-preset').forEach(function (b) {
    b.addEventListener('click', function () {
      s.value = b.dataset.singular; p.value = b.dataset.plural; g.value = b.dataset.gender;
      document.querySelectorAll('.vc-preset').forEach(function (o) { o.classList.toggle('is-current', o === b); });
    });
  });
})();
</script>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
