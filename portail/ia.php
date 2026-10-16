<?php
// Coûts de l'IA et formules : barème des coûts, calculateur de forfait (quota d'IA gratuite d'une formule ou d'une boutique, avec son équivalent en textes, images
// et vidéos), packs de crédit à racheter, et état de chaque boutique (quota, consommation du mois, crédit). Voir includes/ai-plans.php.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';

$galleries = gallery_list();
$pricing = saas_pricing();

/** Boutiques de ce serveur (identifiant => nom). */
function ia_shops(): array
{
    $out = [];
    foreach (glob(PORTAIL_ROOT . '/tenants/*/tenant.php') ?: [] as $f) {
        $slug = basename(dirname($f));
        if ($slug === '_modele') continue;
        $cfg = tenant_load($slug);
        $out[$slug] = (string) ($cfg['name'] ?? $slug);
    }
    return $out;
}

/** Consommation d'IA d'une boutique d'après sa base (lecture seule), au barème de la plateforme : ['month' => euros ce mois, 'overflow' => dépassement cumulé depuis 'since']. */
function ia_shop_usage(string $slug, ?array $policy): array
{
    $file = tenant_file(tenant_load($slug), 'db_file');
    $out = ['month' => 0.0, 'overflow' => 0.0, 'all' => 0.0];
    if (!is_file($file)) return $out;
    try {
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only = 1');
        $rate = saas_ai_pricing()['usd_eur'];
        $since = ($policy['since'] ?? gmdate('Y-m')) . '-01 00:00:00';
        $stmt = $pdo->prepare("SELECT strftime('%Y-%m', at) AS m, SUM(cost_usd) AS c FROM ai_usage WHERE at >= ? GROUP BY m");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = (float) $r['c'] * $rate;
            $out['all'] += $v;
            if ($r['m'] === gmdate('Y-m')) $out['month'] = $v;
            $out['overflow'] += max(0.0, $v - (float) ($policy['quota_eur'] ?? 0));
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** « 12,50 » → 12.5 ; '' → null. */
function ia_num(string $s): ?float
{
    $s = str_replace([' ', "\u{00a0}", '€'], '', trim($s));
    return $s === '' || !is_numeric(str_replace(',', '.', $s)) ? null : max(0.0, (float) str_replace(',', '.', $s));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $u = saas_ai_unit_costs();
    if ($action === 'pricing') {
        $ok = saas_ai_pricing_save((array) ($_POST['p'] ?? []));
        saas_event('config_changed', ['note' => 'Barème des coûts IA enregistré', 'actor' => 'exploitant']);
        portail_flash($ok ? 'Barème enregistré : les estimations et le décompte des boutiques limitées l\'utilisent aussitôt.' : "Impossible d'écrire data/saas/ai-costs.json.", $ok ? 'ok' : 'error');
    } elseif ($action === 'packs') {
        $packs = [];
        foreach ((array) ($_POST['pack_name'] ?? []) as $i => $name) {
            $credit = ia_num((string) ($_POST['pack_credit'][$i] ?? ''));
            $price = ia_num((string) ($_POST['pack_price'][$i] ?? ''));
            if (trim((string) $name) === '' || !$credit || !$price) continue;
            $packs[] = ['key' => (string) ($_POST['pack_key'][$i] ?? ''), 'name' => (string) $name, 'credit_eur' => $credit, 'price_cents' => (int) round($price * 100)];
        }
        $ok = saas_ai_packs_save($packs);
        saas_event('config_changed', ['note' => 'Packs de crédit IA enregistrés (' . count($packs) . ')', 'actor' => 'exploitant']);
        portail_flash($ok ? 'Packs enregistrés.' : "Impossible d'écrire data/saas/ai-costs.json.", $ok ? 'ok' : 'error');
    } elseif ($action === 'assign') {
        // Quota calculé côté serveur d'après les quantités : textes, images, vidéos de 6 s, fiches complètes, recherches de prix.
        $q = ((int) ($_POST['n_texts'] ?? 0)) * $u['text'] + ((int) ($_POST['n_images'] ?? 0)) * $u['image'] + ((int) ($_POST['n_videos'] ?? 0)) * $u['video']
            + ((int) ($_POST['n_pieces'] ?? 0)) * $u['piece'] + ((int) ($_POST['n_search'] ?? 0)) * $u['search'];
        $q = round(ceil($q * 100) / 100, 2);
        $target = (string) ($_POST['target'] ?? '');
        if (str_starts_with($target, 'offer:')) {
            [, $scope, $key] = array_pad(explode(':', $target, 3), 3, '');
            $default = $pricing['default'];
            $own = $pricing['galleries'];
            $grid = $scope === 'default' ? $default : ($own[$scope] ?? null);
            $found = false;
            if ($grid) {
                foreach (['plans', 'tiers'] as $kind) foreach ($grid[$kind] as &$o) if ($o['key'] === $key) { $o['ai_quota_eur'] = $q; $found = true; }
                unset($o);
            }
            if ($found) {
                if ($scope === 'default') $default = $grid; else $own[$scope] = $grid;
                saas_pricing_save($default, $own);
                saas_event('config_changed', ['note' => "Quota d'IA de l'offre « $key » (" . ($scope === 'default' ? 'tarifs par défaut' : 'galerie ' . $scope) . ') : ' . number_format($q, 2, ',', '') . ' € / mois', 'actor' => 'exploitant']);
                portail_flash('Quota affecté à l\'offre : ' . number_format($q, 2, ',', ' ') . ' € de consommation par mois (' . saas_ai_estimate_text($q) . '). Il s\'applique aux nouvelles inscriptions ; les boutiques existantes se règlent plus bas.');
            } else portail_flash('Offre introuvable.', 'error');
        } elseif (str_starts_with($target, 'shop:')) {
            $slug = substr($target, 5);
            if (isset(ia_shops()[$slug]) && saas_ai_policy_set($slug, $q)) {
                saas_event('config_changed', ['slug' => $slug, 'note' => "Quota d'IA de la boutique : " . number_format($q, 2, ',', '') . ' € / mois', 'actor' => 'exploitant']);
                portail_flash("Quota de la boutique « $slug » : " . number_format($q, 2, ',', ' ') . ' € par mois.');
            } else portail_flash('Boutique introuvable.', 'error');
        } else portail_flash('Choisissez une offre ou une boutique.', 'error');
    } elseif ($action === 'shop') {
        $slug = (string) ($_POST['slug'] ?? '');
        if (!isset(ia_shops()[$slug])) { portail_flash('Boutique introuvable.', 'error'); }
        elseif (!empty($_POST['unlimit'])) { saas_ai_policy_set($slug, null); saas_event('config_changed', ['slug' => $slug, 'note' => "IA de la boutique : limite retirée", 'actor' => 'exploitant']); portail_flash("Boutique « $slug » : IA non limitée."); }
        else {
            $quota = ia_num((string) ($_POST['quota'] ?? ''));
            $gift = ia_num((string) ($_POST['gift'] ?? ''));
            if ($quota !== null) { saas_ai_policy_set($slug, $quota); saas_event('config_changed', ['slug' => $slug, 'note' => "Quota d'IA de la boutique : " . number_format($quota, 2, ',', '') . ' € / mois', 'actor' => 'exploitant']); }
            if ($gift) { saas_ai_credit_add($slug, $gift, ['source' => 'offert', 'pack' => 'offert']); saas_event('config_changed', ['slug' => $slug, 'note' => 'Crédit IA offert : ' . number_format($gift, 2, ',', '') . ' €', 'actor' => 'exploitant']); }
            portail_flash("Boutique « $slug » mise à jour." . ($gift ? ' Crédit offert : ' . number_format($gift, 2, ',', ' ') . ' €.' : ''));
        }
    }
    header('Location: /portail/ia.php');
    exit;
}

$flash = portail_flash();
$p = saas_ai_pricing();
$u = saas_ai_unit_costs();
$packs = saas_ai_packs();
while (count($packs) < 5) $packs[] = ['key' => '', 'name' => '', 'credit_eur' => null, 'price_cents' => null];
$shops = ia_shops();
$eur = static fn (float $v, int $d = 2): string => number_format($v, $d, ',', ' ') . ' €';

// Toutes les offres avec leur quota, leur prix mensuel et la marge brute qu'il laisse (prix − quota entièrement consommé).
$offerRows = [];
foreach (['default' => $pricing['default']] + $pricing['galleries'] as $scope => $grid) {
    foreach (saas_grid_offers($grid) as $o) {
        $offerRows[] = ['scope' => $scope, 'o' => $o];
    }
}
$labels = ['pricing' => 'Barème', 'calc' => 'Calculateur', 'packs' => 'Packs', 'shops' => 'Boutiques'];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Coûts IA — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .owrap { display: grid; gap: 16px; max-width: 1100px; margin: 0 auto; padding: 0 16px 56px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; min-width: 0; }
  .ocard h2 { margin: 0; font-size: 1.05rem; } .ocard h2 small { font-weight: 400; color: var(--muted); margin-left: 6px; }
  .hint { color: var(--muted); font-size: .84rem; margin: 0; } .muted { color: var(--muted); }
  .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px 14px; }
  .f { display: grid; gap: 4px; font-size: .84rem; font-weight: 600; } .f small { font-weight: 400; color: var(--muted); }
  .f input, .f select, .t input, .t select { padding: 7px 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; box-sizing: border-box; }
  table.t { width: 100%; border-collapse: collapse; font-size: .88rem; } .t th { text-align: left; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 5px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  .t td { padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; } .t td.r, .t th.r { text-align: right; font-variant-numeric: tabular-nums; } .t tr:last-child td { border-bottom: 0; }
  .scroll { overflow-x: auto; }
  .refs { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; } .ref { border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; background: var(--panel-2); } .ref b { display: block; font-size: 1.15rem; } .ref span { font-size: .76rem; color: var(--muted); }
  .result { border: 1px dashed var(--line); border-radius: 10px; padding: 12px 14px; background: var(--panel-2); display: grid; gap: 4px; } .result b { font-size: 1.3rem; }
  .bar { height: 8px; border-radius: 4px; background: var(--panel-2); overflow: hidden; min-width: 90px; } .bar i { display: block; height: 100%; background: var(--accent); } .bar.over i { background: #b3261e; }
  .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .74rem; font-weight: 700; border: 1px solid var(--line); white-space: nowrap; } .tag.bad { color: #b3261e; border-color: currentColor; } .tag.ok { color: var(--ok); }
  .inline { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; } .inline input[type=text] { width: 90px; }
  .nav a { text-decoration: none; padding: 4px 12px; border: 1px solid var(--line); border-radius: 999px; font-size: .84rem; color: var(--ink); }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand"><h1>Coûts IA et formules</h1><p>Barème, quota d'IA gratuite des formules, packs de crédit à racheter, consommation des boutiques</p></div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn" href="/portail/">Portail des commerces</a><a class="btn" href="/portail/tarifs.php">Tarifs</a><a class="btn" href="/portail/activite.php">Activité</a></span>
  </header>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="owrap">
    <p class="hint">Chaque formule peut inclure un <strong>quota d'IA gratuite par mois</strong> (réglé dans <a href="/portail/tarifs.php">Tarifs</a>, colonne « IA offerte / mois »), exprimé en euros de consommation réelle : la boutique en fait des textes, des images ou des vidéos, au choix. Au-delà, elle entame le <strong>crédit qu'elle a racheté</strong> ; sans crédit, les générations IA sont suspendues jusqu'au mois suivant ou à un rachat. Une boutique sans quota affecté n'est pas limitée.</p>

    <section class="ocard" id="bareme">
      <h2>1. Barème des coûts <small>ce que coûte chaque appel à l'IA, à vérifier chez Google (ai.google.dev/pricing), fal.ai et le fournisseur de vidéo</small></h2>
      <form method="post" class="ocard" style="border:0;padding:0" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="pricing">
        <div class="cols">
          <?php foreach ([
            'usd_eur' => ['Dollar → euro', 'taux de conversion'], 'text_call' => ['Rédaction d\'une fiche ($)', 'un appel de texte avec vision'], 'image' => ['Image générée ($)', 'par image (Gemini)'],
            'search' => ['Recherche web ($)', 'par recherche de prix (en plus du texte)'], 'cutout' => ['Détourage ($)', 'fal.ai, Modal'],
            'veo_lite' => ['Vidéo Veo Lite ($ / s)', ''], 'veo_fast' => ['Vidéo Veo Fast ($ / s)', 'sert aux estimations de vidéo'], 'veo_std' => ['Vidéo Veo Standard ($ / s)', ''],
            'wan22' => ['Vidéo Wan 2.2 ($)', 'par vidéo (SiliconFlow)'], 'modal_video' => ['Vidéo Modal ($)', 'par vidéo estimée'],
          ] as $k => [$label, $help]): ?>
            <label class="f"><?= e($label) ?><input type="text" inputmode="decimal" name="p[<?= e($k) ?>]" value="<?= e(rtrim(rtrim(number_format($p[$k], 4, ',', ''), '0'), ',')) ?>"><?php if ($help): ?><small><?= e($help) ?></small><?php endif; ?></label>
          <?php endforeach; ?>
        </div>
        <div class="refs" aria-label="Coûts de référence en euros">
          <div class="ref"><b><?= e($eur($u['text'], 3)) ?></b><span>un texte (fiche rédigée)</span></div>
          <div class="ref"><b><?= e($eur($u['image'], 3)) ?></b><span>une image</span></div>
          <div class="ref"><b><?= e($eur($u['video'])) ?></b><span>une vidéo de <?= SAAS_AI_VIDEO_SECONDS ?> s (Veo Fast)</span></div>
          <div class="ref"><b><?= e($eur($u['piece'], 3)) ?></b><span>une fiche complète : détourage, 2 visuels, texte</span></div>
          <div class="ref"><b><?= e($eur($u['search'], 3)) ?></b><span>une recherche de prix</span></div>
        </div>
        <p><button class="btn btn-primary" type="submit">Enregistrer le barème</button></p>
      </form>
    </section>

    <section class="ocard" id="calculateur">
      <h2>2. Calculateur de forfait <small>composez l'IA gratuite d'une formule, voyez son coût et affectez-la</small></h2>
      <form method="post" autocomplete="off" id="calc">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="assign">
        <div class="cols">
          <label class="f">Fiches complètes / mois<input type="number" min="0" name="n_pieces" value="30" data-n="piece"><small>détourage, 2 visuels et texte</small></label>
          <label class="f">Textes / mois<input type="number" min="0" name="n_texts" value="0" data-n="text"><small>fiches ou descriptions rédigées</small></label>
          <label class="f">Images / mois<input type="number" min="0" name="n_images" value="0" data-n="image"><small>mises en situation, recto, verso…</small></label>
          <label class="f">Vidéos de <?= SAAS_AI_VIDEO_SECONDS ?> s / mois<input type="number" min="0" name="n_videos" value="2" data-n="video"></label>
          <label class="f">Recherches de prix / mois<input type="number" min="0" name="n_search" value="10" data-n="search"></label>
        </div>
        <div class="result" aria-live="polite" style="margin-top:12px">
          <span class="muted">Coût de l'IA offerte, si tout est consommé</span><b id="calc-cost">0,00 €</b>
          <span id="calc-eq" class="muted"></span><span id="calc-margin"></span>
        </div>
        <div class="inline" style="margin-top:12px">
          <label class="f" style="min-width:280px">Affecter ce quota à
            <select name="target" id="calc-target" required>
              <option value="">— choisir —</option>
              <optgroup label="Offres (nouvelles inscriptions)">
                <?php foreach ($offerRows as $r): $o = $r['o']; ?><option value="offer:<?= e($r['scope']) ?>:<?= e($o['key']) ?>" data-price="<?= (int) ($o['monthly'] ?? 0) ?>"><?= e(($r['scope'] === 'default' ? 'Tarifs par défaut' : 'Galerie ' . ($galleries[$r['scope']]['name'] ?? $r['scope'])) . ' — ' . $o['name']) ?> (<?= e($o['monthly'] === null ? 'sur devis' : saas_money((int) $o['monthly']) . ' / mois') ?>)</option><?php endforeach; ?>
              </optgroup>
              <optgroup label="Boutiques existantes">
                <?php foreach ($shops as $slug => $name): ?><option value="shop:<?= e($slug) ?>"><?= e($name) ?> (<?= e($slug) ?>)</option><?php endforeach; ?>
              </optgroup>
            </select>
          </label>
          <button class="btn btn-primary" type="submit" style="align-self:end">Affecter</button>
        </div>
      </form>
      <div class="scroll"><table class="t"><caption class="hint" style="text-align:left;margin-bottom:6px">Quota d'IA et marge de chaque offre (marge brute = prix mensuel − IA offerte entièrement consommée)</caption>
        <thead><tr><th>Offre</th><th class="r">Prix / mois</th><th class="r">IA offerte / mois</th><th>Équivalent</th><th class="r">Marge brute</th></tr></thead><tbody>
        <?php foreach ($offerRows as $r): $o = $r['o']; $q = $o['ai_quota_eur'] ?? null; ?>
          <tr><td><?= e(($r['scope'] === 'default' ? '' : ($galleries[$r['scope']]['name'] ?? $r['scope']) . ' — ') . $o['name']) ?></td>
            <td class="r"><?= $o['monthly'] === null ? 'devis' : e(saas_money((int) $o['monthly'])) ?></td>
            <td class="r"><?= $q === null ? '<span class="muted">illimitée</span>' : e($eur($q)) ?></td>
            <td><?= $q === null ? '<span class="muted">pas de limite : à régler</span>' : e(saas_ai_estimate_text($q)) ?></td>
            <td class="r"><?php if ($o['monthly'] !== null && $q !== null): $m = $o['monthly'] / 100 - $q; ?><span<?= $m < 0 ? ' style="color:#b3261e"' : '' ?>><?= e($eur($m)) ?></span><?php else: ?>—<?php endif; ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </section>

    <section class="ocard" id="packs">
      <h2>3. Packs de crédit à racheter <small>proposés à la boutique qui dépasse son quota (paiement unique par Stripe)</small></h2>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="packs">
        <div class="scroll"><table class="t"><thead><tr><th>Nom</th><th>Crédit IA offert (€ de conso.)</th><th>Équivalent</th><th>Prix payé (€)</th><th class="r">Marge brute</th></tr></thead><tbody>
          <?php foreach ($packs as $i => $pk): $c = $pk['credit_eur']; $pr = $pk['price_cents']; ?>
            <tr><td><input type="text" name="pack_name[<?= $i ?>]" maxlength="40" value="<?= e($pk['name']) ?>" placeholder="ex : Pack moyen"><input type="hidden" name="pack_key[<?= $i ?>]" value="<?= e($pk['key']) ?>"></td>
              <td><input type="text" inputmode="decimal" name="pack_credit[<?= $i ?>]" value="<?= $c ? e(rtrim(rtrim(number_format($c, 2, ',', ''), '0'), ',')) : '' ?>"></td>
              <td class="muted" style="font-size:.8rem"><?= $c ? e(saas_ai_estimate_text((float) $c)) : '' ?></td>
              <td><input type="text" inputmode="decimal" name="pack_price[<?= $i ?>]" value="<?= $pr ? e(rtrim(rtrim(number_format($pr / 100, 2, ',', ''), '0'), ',')) : '' ?>"></td>
              <td class="r"><?= $c && $pr ? e($eur($pr / 100 - $c)) : '—' ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <p class="hint" style="margin-top:8px">Une ligne sans nom, sans crédit ou sans prix est ignorée. Le crédit n'expire pas ; il n'est entamé que lorsque le quota gratuit du mois est épuisé.
          <?= saas_stripe_configured() ? '' : '<strong style="color:#b3261e">Le paiement Stripe de la plateforme n\'est pas configuré (Offres et annuaire) : les boutiques ne peuvent pas encore racheter de crédit.</strong>' ?></p>
        <p><button class="btn btn-primary" type="submit">Enregistrer les packs</button></p>
      </form>
    </section>

    <section class="ocard" id="boutiques">
      <h2>4. Boutiques <small>quota affecté, consommation de ce mois, crédit racheté</small></h2>
      <div class="scroll"><table class="t"><thead><tr><th>Boutique</th><th class="r">Quota / mois</th><th>Ce mois</th><th class="r">Crédit restant</th><th>État</th><th>Affecter / offrir</th></tr></thead><tbody>
        <?php foreach ($shops as $slug => $name):
          $pol = saas_ai_policy($slug);
          $use = ia_shop_usage($slug, $pol);
          $cr = saas_ai_credits($slug);
          $left = $cr['purchased_eur'] - $use['overflow'];
          $free = $pol ? max(0.0, $pol['quota_eur'] - $use['month']) : null;
          $blocked = $pol && $free < 0.005 && $left < 0.005;
          $pct = $pol && $pol['quota_eur'] > 0 ? min(100, $use['month'] / $pol['quota_eur'] * 100) : ($use['month'] > 0 ? 100 : 0);
        ?>
          <tr><td><strong><?= e($name) ?></strong><br><span class="muted mono"><?= e($slug) ?></span></td>
            <td class="r"><?= $pol ? e($eur($pol['quota_eur'])) : '<span class="muted">illimitée</span>' ?></td>
            <td><?= e($eur($use['month'])) ?><?php if ($pol): ?><div class="bar<?= $pct >= 100 ? ' over' : '' ?>"><i style="width:<?= (int) $pct ?>%"></i></div><?php endif; ?></td>
            <td class="r"><?= $cr['purchased_eur'] > 0 || $pol ? e($eur(max(0.0, $left))) : '—' ?></td>
            <td><?= !$pol ? '<span class="tag">non limitée</span>' : ($blocked ? '<span class="tag bad">IA suspendue</span>' : '<span class="tag ok">active</span>') ?></td>
            <td><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="shop"><input type="hidden" name="slug" value="<?= e($slug) ?>">
              <input type="text" inputmode="decimal" name="quota" placeholder="quota €" aria-label="Quota mensuel en euros" title="Quota mensuel (€ de consommation)">
              <input type="text" inputmode="decimal" name="gift" placeholder="offrir €" aria-label="Crédit à offrir en euros" title="Crédit IA offert (€)">
              <button class="btn small" type="submit">OK</button><?php if ($pol): ?><button class="btn small" type="submit" name="unlimit" value="1" title="Retire la limite">Illimitée</button><?php endif; ?></form></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <p class="hint">Consommation lue dans la base de chaque boutique de ce serveur, au barème ci-dessus. Les boutiques hébergées ailleurs ne sont pas listées : leur quota se règle dans leur tenant.php (<code>ai_quota_eur</code>).</p>
    </section>
  </div>
</div>
<script>
(function () {
  var cost = <?= json_encode($u) ?>, form = document.getElementById('calc');
  function fmt(n, d) { return n.toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }); }
  function update() {
    var total = 0;
    form.querySelectorAll('[data-n]').forEach(function (i) { total += (parseInt(i.value, 10) || 0) * cost[i.dataset.n]; });
    total = Math.ceil(total * 100) / 100;
    document.getElementById('calc-cost').textContent = fmt(total, 2) + ' €';
    document.getElementById('calc-eq').textContent = 'Équivalent, au choix : ≈ ' + fmt(Math.floor(total / cost.text), 0) + ' textes · ≈ ' + fmt(Math.floor(total / cost.image), 0) + ' images · ≈ ' + fmt(Math.floor(total / cost.video), 0) + ' vidéos de <?= SAAS_AI_VIDEO_SECONDS ?> s';
    var opt = document.getElementById('calc-target').selectedOptions[0], m = document.getElementById('calc-margin');
    var price = opt && opt.dataset.price ? parseInt(opt.dataset.price, 10) / 100 : 0;
    m.textContent = price > 0 ? 'Marge brute sur cette offre : ' + fmt(price - total, 2) + ' € / mois (prix ' + fmt(price, 2) + ' € − IA ' + fmt(total, 2) + ' €)' : '';
    m.style.color = price > 0 && price - total < 0 ? '#b3261e' : '';
  }
  form.addEventListener('input', update); document.getElementById('calc-target').addEventListener('change', update); update();
})();
</script>
</body>
</html>
