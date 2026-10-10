<?php
// Tarifs de la plateforme (voir includes/saas-pricing.php) : une grille par défaut et, pour chaque galerie commerciale, des tarifs propres ou ceux par défaut.
// Chaque grille propose des formules (nom, prix mensuel, prix annuel, limite d'articles facultative) et/ou des tranches par nombre d'articles.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';

const TARIF_ROWS = 3;   // lignes vides proposées en plus des offres existantes

$galleries = gallery_list();
$pricing = saas_pricing();
$scope = (string) ($_REQUEST['scope'] ?? 'default');
if ($scope !== 'default' && !isset($galleries[$scope])) $scope = 'default';

/** « 19,90 », « 19.9 € », « 1 990 » → centimes ; '' → null. */
function tarif_cents(string $s): ?int
{
    $s = str_replace([' ', "\u{00a0}", '€'], '', trim($s));
    if ($s === '') return null;
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? max(0, (int) round((float) $s * 100)) : null;
}

/** Montant en centimes → texte d'un champ (« 19,90 », « 19 »), '' si null. */
function tarif_field(?int $cents): string
{
    if ($cents === null) return '';
    return $cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2, ',', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $default = $pricing['default'];
    $own = $pricing['galleries'];
    $back = '/portail/tarifs.php?scope=' . rawurlencode($scope);
    if ($action === 'save') {
        $grid = ['discount_pct' => (int) ($_POST['discount_pct'] ?? 0), 'yearly' => !empty($_POST['yearly']), 'plans' => [], 'tiers' => []];
        foreach ((array) ($_POST['plan_name'] ?? []) as $i => $name) {
            if (trim((string) $name) === '') continue;
            $grid['plans'][] = ['key' => (string) ($_POST['plan_key'][$i] ?? ''), 'name' => (string) $name, 'monthly' => tarif_cents((string) ($_POST['plan_monthly'][$i] ?? '')),
                'yearly' => tarif_cents((string) ($_POST['plan_yearly'][$i] ?? '')), 'max_items' => (string) ($_POST['plan_max'][$i] ?? ''),
                'featured' => (string) ($_POST['featured_plan'] ?? '') === (string) $i, 'features' => preg_split('/\R/', trim((string) ($_POST['plan_features'][$i] ?? '')), -1, PREG_SPLIT_NO_EMPTY)];
        }
        foreach ((array) ($_POST['tier_monthly'] ?? []) as $i => $m) {
            $max = trim((string) ($_POST['tier_max'][$i] ?? ''));
            $label = trim((string) ($_POST['tier_name'][$i] ?? ''));
            if (trim((string) $m) === '' && $max === '' && $label === '' && trim((string) ($_POST['tier_yearly'][$i] ?? '')) === '' && empty($_POST['tier_unlimited'][$i])) continue;
            $grid['tiers'][] = ['key' => '', 'name' => $label, 'max_items' => !empty($_POST['tier_unlimited'][$i]) ? '' : $max, 'monthly' => tarif_cents((string) $m),
                'yearly' => tarif_cents((string) ($_POST['tier_yearly'][$i] ?? '')), 'featured' => (string) ($_POST['featured_tier'] ?? '') === (string) $i,
                'features' => preg_split('/\R/', trim((string) ($_POST['tier_features'][$i] ?? '')), -1, PREG_SPLIT_NO_EMPTY)];
        }
        $norm = saas_grid_normalize($grid);
        if ($scope === 'default') {
            if (!$norm['plans'] && !$norm['tiers']) { portail_flash('Gardez au moins une offre dans les tarifs par défaut.', 'error'); header('Location: ' . $back); exit; }
            $default = $norm;
        } elseif (empty($_POST['own'])) {
            unset($own[$scope]);
        } else {
            if (!$norm['plans'] && !$norm['tiers']) { portail_flash('Ajoutez au moins une offre, ou décochez « tarifs propres » pour reprendre les tarifs par défaut.', 'error'); header('Location: ' . $back); exit; }
            $own[$scope] = $norm;
        }
        $ok = saas_pricing_save($default, $own);
        saas_event('config_changed', ['note' => 'Tarifs ' . ($scope === 'default' ? 'par défaut' : 'de la galerie ' . $scope) . ' enregistrés (' . count($norm['plans']) . ' formule(s), ' . count($norm['tiers']) . ' tranche(s))', 'actor' => 'exploitant']);
        portail_flash($ok ? 'Tarifs enregistrés.' : "Impossible d'écrire data/saas/tarifs.json (droits du dossier data).", $ok ? 'ok' : 'error');
    } elseif ($action === 'copy' && $scope !== 'default') {
        $from = (string) ($_POST['from'] ?? '');
        $src = $from === 'default' ? $default : ($own[$from] ?? null);
        if ($src) { $own[$scope] = $src; saas_pricing_save($default, $own); saas_event('config_changed', ['note' => "Tarifs de la galerie $scope copiés depuis " . ($from === 'default' ? 'les tarifs par défaut' : "la galerie $from"), 'actor' => 'exploitant']); portail_flash('Tarifs copiés : ajustez-les puis enregistrez.'); }
        else portail_flash('Source introuvable.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

$flash = portail_flash();
$grid = $scope === 'default' ? $pricing['default'] : ($pricing['galleries'][$scope] ?? $pricing['default']);
$hasOwn = $scope === 'default' || isset($pricing['galleries'][$scope]);
$offers = saas_grid_offers($grid);
$plans = $grid['plans'];
$tiers = $grid['tiers'];
// Tranches types, proposées pour partir d'un exemple (rien n'est enregistré avant « Enregistrer »).
if (($_GET['preset'] ?? '') === 'tranches' && !$tiers) {
    $tiers = array_map(static fn ($t) => saas_offer_normalize($t, 'tier'), [
        ['max_items' => 20, 'monthly' => 990], ['max_items' => 100, 'monthly' => 1990], ['max_items' => 500, 'monthly' => 3990], ['max_items' => null, 'monthly' => 6990],
    ]);
}
while (count($plans) < count($grid['plans']) + TARIF_ROWS) $plans[] = ['key' => '', 'name' => '', 'monthly' => null, 'yearly' => null, 'max_items' => null, 'featured' => false, 'features' => []];
while (count($tiers) < max(count($grid['tiers']), ($_GET['preset'] ?? '') === 'tranches' ? 4 : 0) + TARIF_ROWS) $tiers[] = ['key' => '', 'name' => '', 'monthly' => null, 'yearly' => null, 'max_items' => null, 'featured' => false, 'features' => []];

// Demandes par galerie : combien d'inscriptions, dont payées.
$counts = [];
foreach (saas_requests() as $r) {
    $o = saas_req_offer($r);
    $g = (string) ($o['gallery'] ?? '') ?: 'default';
    $counts[$g][0] = ($counts[$g][0] ?? 0) + 1;
    if (in_array($r['status'] ?? '', ['paid', 'approved'], true) && ($o['cents'] ?? 0) > 0) $counts[$g][1] = ($counts[$g][1] ?? 0) + 1;
}
$origin = saas_origin();
$signup = $origin . '/inscription/' . ($scope === 'default' ? '' : '?galerie=' . rawurlencode($scope));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Tarifs — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/assets/pricing.css">
<style>
  .twrap { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 16px; max-width: 1180px; margin: 0 auto; padding: 0 16px 56px; align-items: start; }
  @media (max-width: 880px) { .twrap { grid-template-columns: 1fr; } }
  .scopes { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 8px; display: grid; gap: 2px; position: sticky; top: 12px; }
  .scopes a { display: grid; gap: 1px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: var(--ink); font-weight: 600; font-size: .92rem; } .scopes a small { font-weight: 400; color: var(--muted); }
  .scopes a[aria-current="page"] { background: var(--accent-soft); } .scopes h3 { margin: 8px 10px 4px; font-size: .74rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; min-width: 0; margin-bottom: 14px; }
  .ocard h2 { margin: 0; font-size: 1.05rem; } .ocard h2 small { font-weight: 400; color: var(--muted); margin-left: 6px; }
  .row { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; }
  .grid-t { width: 100%; border-collapse: collapse; font-size: .88rem; } .grid-t th { text-align: left; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 4px 6px; white-space: nowrap; }
  .grid-t td { padding: 5px 6px; vertical-align: top; border-top: 1px solid var(--line); }
  .grid-t input[type=text], .grid-t input[type=number], .grid-t textarea, .ocard input[type=number] { padding: 7px 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; }
  .grid-t textarea { min-height: 62px; font-size: .84rem; } .grid-t td.n { width: 108px; } .grid-t td.c { text-align: center; width: 70px; }
  .grid-t .eur { position: relative; } .grid-t .eur::after { content: "€"; position: absolute; right: 10px; top: 7px; color: var(--muted); pointer-events: none; } .grid-t .eur input { padding-right: 24px; }
  .hint { color: var(--muted); font-size: .84rem; margin: 0; } .muted { color: var(--muted); }
  .preview { background: var(--bg); border: 1px dashed var(--line); border-radius: 10px; padding: 18px; overflow-x: auto; }
  .preview .plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
  .preview { --accent-ink: #fff; --bg: var(--panel-2); --ink-soft: var(--muted); }
  .preview .plan { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 14px; display: grid; gap: 6px; align-content: start; position: relative; }
  .preview .plan.is-featured { border-color: var(--accent); } .preview .plan h3 { margin: 0; } .preview .plan ul { margin: 4px 0 0; padding-left: 18px; font-size: .88rem; }
  .preview .plan .tag { position: absolute; top: -10px; left: 12px; background: var(--accent); color: #fff; font-size: .7rem; padding: 2px 8px; border-radius: 999px; font-weight: 700; }
  .preview .price { font-size: 1.5rem; font-weight: 700; margin: 0; } .preview .price small { font-size: .8rem; font-weight: 400; color: var(--muted); }
  .preview .btn { justify-self: start; pointer-events: none; opacity: .85; }
  .warn-t { color: var(--warn); font-weight: 600; font-size: .84rem; }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand"><h1>Tarifs</h1><p>Formules, prix mensuels et annuels, tranches par nombre d'articles — par défaut et pour chaque galerie commerciale</p></div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn" href="/portail/">Portail des commerces</a><a class="btn" href="/portail/galeries.php">Galeries commerciales</a><a class="btn" href="/portail/activite.php">Activité</a></span>
  </header>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="twrap">
    <nav class="scopes" aria-label="Tarifs à régler">
      <h3>Plateforme</h3>
      <a href="/portail/tarifs.php?scope=default"<?= $scope === 'default' ? ' aria-current="page"' : '' ?>>Tarifs par défaut<small>Accueil et inscription sans galerie · <?= (int) ($counts['default'][0] ?? 0) ?> inscription<?= ($counts['default'][0] ?? 0) > 1 ? 's' : '' ?></small></a>
      <h3>Galeries commerciales</h3>
      <?php foreach ($galleries as $slug => $g): $own = isset($pricing['galleries'][$slug]); ?>
        <a href="/portail/tarifs.php?scope=<?= e(rawurlencode($slug)) ?>"<?= $scope === $slug ? ' aria-current="page"' : '' ?>><?= e($g['name']) ?>
          <small><?= $own ? 'Tarifs propres : ' . e(saas_grid_from_text($pricing['galleries'][$slug])) : 'Tarifs par défaut' ?><?= !$g['published'] ? ' · non publiée' : '' ?> · <?= (int) ($counts[$slug][0] ?? 0) ?> inscr.</small></a>
      <?php endforeach; ?>
      <?php if (!$galleries): ?><p class="hint" style="padding:6px 10px">Aucune galerie : créez-en dans « Galeries commerciales ».</p><?php endif; ?>
    </nav>

    <main>
      <form class="ocard" method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="scope" value="<?= e($scope) ?>">
        <h2><?= $scope === 'default' ? 'Tarifs par défaut' : 'Tarifs de la galerie « ' . e($galleries[$scope]['name']) . ' »' ?></h2>
        <?php if ($scope !== 'default'): ?>
          <label class="row" style="gap:8px"><input type="checkbox" name="own" value="1" style="width:auto"<?= $hasOwn ? ' checked' : '' ?>> <strong>Cette galerie a ses propres tarifs</strong>
            <span class="hint">Décoché : elle applique les tarifs par défaut (ci-dessous, à titre d'information).</span></label>
        <?php endif; ?>
        <div class="row">
          <label style="display:flex;gap:8px;align-items:center;white-space:nowrap;width:auto"><input type="checkbox" name="yearly" style="width:auto" value="1"<?= $grid['yearly'] ? ' checked' : '' ?>> Proposer la facturation <strong>annuelle</strong></label>
          <label style="display:flex;gap:8px;align-items:center;white-space:nowrap;width:auto">Remise annuelle <input type="number" name="discount_pct" min="0" max="90" step="1" value="<?= (int) $grid['discount_pct'] ?>" style="width:84px"> %</label>
          <span class="hint">Si le prix annuel d'une offre est laissé vide, il vaut 12 mois moins cette remise. Un prix annuel saisi prime.</span>
        </div>

        <h2>Formules <small>un nom, un prix par mois, un prix par an, une limite d'articles facultative</small></h2>
        <div style="overflow-x:auto"><table class="grid-t">
          <thead><tr><th>Nom</th><th>Par mois</th><th>Par an</th><th>Articles max.</th><th class="c">Mise en avant</th><th>Avantages (un par ligne)</th></tr></thead>
          <tbody>
          <?php foreach ($plans as $i => $o): ?>
            <tr><td><input type="text" name="plan_name[<?= $i ?>]" maxlength="40" value="<?= e($o['name']) ?>" placeholder="Nom de la formule"><input type="hidden" name="plan_key[<?= $i ?>]" value="<?= e($o['key']) ?>"></td>
              <td class="n"><span class="eur"><input type="text" inputmode="decimal" name="plan_monthly[<?= $i ?>]" value="<?= e(tarif_field($o['monthly'])) ?>" placeholder="devis"></span></td>
              <td class="n"><span class="eur"><input type="text" inputmode="decimal" name="plan_yearly[<?= $i ?>]" value="<?= e(tarif_field($o['yearly'])) ?>" placeholder="auto"></span></td>
              <td class="n"><input type="number" min="1" name="plan_max[<?= $i ?>]" value="<?= e((string) ($o['max_items'] ?? '')) ?>" placeholder="illimité"></td>
              <td class="c"><input type="radio" name="featured_plan" value="<?= $i ?>"<?= !empty($o['featured']) ? ' checked' : '' ?> aria-label="La plus choisie"></td>
              <td><textarea name="plan_features[<?= $i ?>]"><?= e(implode("\n", $o['features'])) ?></textarea></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="hint">Une ligne sans nom est ignorée (c'est ainsi qu'on retire une formule). Prix à 0 : gratuit. Prix vide : « sur devis » (jamais payé en ligne).</p>

        <h2>Alternative : tarifs par tranche d'articles <small>le client choisit sa tranche selon le nombre d'articles qu'il vendra</small></h2>
        <div style="overflow-x:auto"><table class="grid-t">
          <thead><tr><th>Jusqu'à (articles)</th><th>Illimité</th><th>Intitulé (facultatif)</th><th>Par mois</th><th>Par an</th><th class="c">Mise en avant</th><th>Avantages (un par ligne)</th></tr></thead>
          <tbody>
          <?php foreach ($tiers as $i => $o): $isTier = $o['monthly'] !== null || $o['max_items'] !== null || $o['name'] !== '' || $o['key'] !== ''; ?>
            <tr><td class="n"><input type="number" min="1" name="tier_max[<?= $i ?>]" value="<?= e((string) ($o['max_items'] ?? '')) ?>" placeholder="ex. 50"></td>
              <td class="c"><input type="checkbox" name="tier_unlimited[<?= $i ?>]" value="1"<?= $isTier && $o['max_items'] === null && $o['key'] !== '' ? ' checked' : '' ?> aria-label="Illimité"></td>
              <td><input type="text" name="tier_name[<?= $i ?>]" maxlength="40" value="<?= e(preg_match('/^(Jusqu\'à|Articles illimités)/u', $o['name']) ? '' : $o['name']) ?>" placeholder="auto : « Jusqu'à 50 articles »"></td>
              <td class="n"><span class="eur"><input type="text" inputmode="decimal" name="tier_monthly[<?= $i ?>]" value="<?= e(tarif_field($o['monthly'])) ?>" placeholder="devis"></span></td>
              <td class="n"><span class="eur"><input type="text" inputmode="decimal" name="tier_yearly[<?= $i ?>]" value="<?= e(tarif_field($o['yearly'])) ?>" placeholder="auto"></span></td>
              <td class="c"><input type="radio" name="featured_tier" value="<?= $i ?>"<?= !empty($o['featured']) ? ' checked' : '' ?> aria-label="La plus choisie"></td>
              <td><textarea name="tier_features[<?= $i ?>]"><?= e(implode("\n", $o['features'])) ?></textarea></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="hint">Sans aucune tranche, seules les formules sont proposées. Avec des formules <em>et</em> des tranches, le client choisit entre les deux d'un clic.
          <?php if (!$grid['tiers']): ?><a href="/portail/tarifs.php?scope=<?= e(rawurlencode($scope)) ?>&amp;preset=tranches">Pré-remplir avec des tranches types</a> (20, 100, 500 articles et illimité : prix d'exemple à ajuster).<?php endif; ?>
          La limite d'articles de l'offre choisie est <strong>appliquée</strong> à la boutique : au-delà, le commerçant ne peut plus ajouter d'article (il doit changer de tranche).</p>
        <p><button class="btn btn-primary" type="submit">Enregistrer ces tarifs</button></p>
      </form>

      <?php if ($scope !== 'default'): ?>
        <form class="ocard" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="copy"><input type="hidden" name="scope" value="<?= e($scope) ?>">
          <h2>Partir d'une autre grille</h2>
          <div class="row"><select name="from"><option value="default">Tarifs par défaut</option>
            <?php foreach ($pricing['galleries'] as $slug => $_g): if ($slug === $scope) continue; ?><option value="<?= e($slug) ?>">Galerie « <?= e($galleries[$slug]['name'] ?? $slug) ?> »</option><?php endforeach; ?></select>
            <button class="btn" type="submit">Copier ici</button><span class="hint">Remplace les tarifs propres de cette galerie par une copie : ajustez-les ensuite.</span></div>
        </form>
      <?php endif; ?>

      <section class="ocard">
        <h2>Ce que verra le client <small>(tarifs <?= $hasOwn ? 'enregistrés' : 'par défaut' ?>)</small></h2>
        <div class="row"><a class="btn small" href="<?= e($signup) ?>" target="_blank" rel="noopener">Ouvrir la page d'inscription ↗</a><span class="hint"><?= e($signup) ?></span></div>
        <div class="preview"><?= saas_pricing_html($grid, 'prev', static fn (array $o, string $b): string => '#') ?></div>
        <?php foreach ($offers as $o): if ($o['yearly_eff'] !== null && $o['monthly'] > 0 && $o['yearly_eff'] >= $o['monthly'] * 12): ?><p class="warn-t">⚠ « <?= e($o['name']) ?> » : le prix annuel (<?= e(saas_money($o['yearly_eff'])) ?>) n'est pas moins cher que 12 mois (<?= e(saas_money($o['monthly'] * 12)) ?>).</p><?php endif; endforeach; ?>
      </section>
    </main>
  </div>
</div>
</body>
</html>
