<?php
// Types de galerie et leurs offres (réservé à l'exploitant) : chaque type (galerie marchande, marché aux puces, vide-grenier, quartier marchand…)
// a son nom, son vocabulaire, ses fonctionnalités et ses trois offres (prix, nombre de membres, essai) ; chaque galerie a son type et son offre.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';
require_once PORTAIL_ROOT . '/includes/gallery-offers.php';
require_once PORTAIL_ROOT . '/includes/addons.php';

if (defined('GA_LIMITED')) { header('Location: /portail/galerie-offre.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $anchor = '';
    if ($action === 'type') {
        $key = strtolower((string) ($_POST['key'] ?? ''));
        $err = gt_save($key, $_POST['t'] ?? []);
        portail_flash($err ?? 'Type enregistré : il s\'applique aux nouvelles galeries et aux textes affichés ; les galeries déjà abonnées gardent leur plafond de membres.', $err ? 'error' : 'ok');
        $anchor = '#t-' . $key;
    } elseif ($action === 'newtype') {
        $key = gt_clean_key(strtolower((string) ($_POST['key'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $base = gt_type(GT_DEFAULT);
        $err = $key === '' || $name === '' ? 'Indiquez un identifiant (lettres, chiffres, tirets) et un nom.' : gt_save($key, ['name' => $name, 'emoji' => '🏬', 'tagline' => $base['tagline'], 'active' => 1, 'order' => 50, 'vocab' => $base['vocab'], 'features' => $base['features'], 'offers' => array_map(static fn ($o) => $o + ['month_cents_eur' => $o['month_cents'] / 100, 'year_cents_eur' => $o['year_cents'] / 100], $base['offers'])], true);
        portail_flash($err ?? 'Type créé : réglez-le ci-dessous.', $err ? 'error' : 'ok');
        $anchor = $err ? '' : '#t-' . $key;
    } elseif ($action === 'settype') {
        $g = gallery_load(strtolower((string) ($_POST['slug'] ?? '')));
        $t = gt_types()[(string) ($_POST['type'] ?? '')] ?? null;
        if (!$g || !$t) portail_flash('Galerie ou type introuvable.', 'error');
        else {
            $g['type'] = $t['key'];
            if (!empty($g['plan']['offer']) && !isset($t['offers'][$g['plan']['offer']])) $g['plan']['offer'] = array_key_first($t['offers']);
            gallery_save($g);
            portail_flash('« ' . $g['name'] . ' » est maintenant : ' . $t['name'] . '.');
        }
    } elseif ($action === 'plan') {
        $g = gallery_load(strtolower((string) ($_POST['slug'] ?? '')));
        $offer = $g ? go_offer((string) ($_POST['offre'] ?? ''), gt_of($g)['key']) : null;
        $mode = (string) ($_POST['mode'] ?? '');
        if (!$g) portail_flash('Galerie introuvable.', 'error');
        elseif ($mode === 'manual') { $g['plan'] = null; gallery_save($g); portail_flash('Galerie remise sans abonnement (accès accordé, sans limite).'); }
        elseif (!$offer) portail_flash('Offre inconnue.', 'error');
        else {
            $p = $g['plan'] ?? ['started' => time(), 'billing' => 'month', 'stripe_subscription_id' => '', 'stripe_customer_id' => '', 'stripe_session_id' => ''];
            $p['offer'] = $offer['key']; $p['max_members'] = $offer['max_members'];
            if ($mode === 'active') { $p['status'] = 'active'; $p['trial_until'] = 0; }
            elseif ($mode === 'trial') { $p['status'] = 'trial'; $p['trial_until'] = time() + max(1, (int) ($_POST['days'] ?? $offer['trial_days'])) * 86400; }
            elseif ($mode === 'canceled') { $p['status'] = 'canceled'; $p['ended'] = time(); }
            $g['plan'] = $p;
            gallery_save($g);
            saas_event('gallery_plan_set', ['slug' => $g['slug'], 'plan' => $offer['key'], 'note' => $mode]);
            portail_flash('Offre de « ' . $g['name'] . ' » mise à jour.');
        }
    }
    header('Location: /portail/offres-galerie.php' . $anchor);
    exit;
}

$flash = portail_flash();
$types = gt_types();
$galleries = gallery_list();
$origin = saas_origin();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Types de galerie — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .gwrap { display: grid; gap: 16px; max-width: 1040px; margin: 0 auto; padding: 0 16px 48px; }
  .gcard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; } .gcard h2 { margin: 0; font-size: 1.05rem; } .gcard h3 { margin: 6px 0 0; font-size: .95rem; }
  details.gcard > summary { cursor: pointer; font-weight: 700; font-size: 1.05rem; list-style: none; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; } details.gcard > summary::-webkit-details-marker { display: none; }
  .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px; }
  .oc { border: 1px solid var(--line); border-radius: 10px; padding: 12px; display: grid; gap: 8px; background: var(--bg); }
  .oc label, .f2 { display: grid; gap: 3px; font-size: .78rem; font-weight: 600; } .oc input[type=text], .oc input[type=number], .f2 input[type=text], .f2 input[type=number], .f2 textarea, .f2 select { padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); color: inherit; font: inherit; width: 100%; }
  table.t { width: 100%; border-collapse: collapse; font-size: .9rem; } table.t th, table.t td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; } table.t th { font-size: .75rem; opacity: .75; }
  .row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; } .row select, .row input { padding: 6px 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; }
  .feats { display: grid; gap: 4px; font-size: .9rem; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Types de galerie',
    'eyebrow' => 'Boutiques',
    'desc' => 'Marché aux puces, vide-grenier, galerie marchande, quartier marchand… : chaque type a ses prix, son vocabulaire et ses fonctionnalités.',
    'actions' => '<a class="btn" href="/portail/galeries.php">Galeries</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <div class="gwrap">
    <p class="meta">Page de vente : <a href="<?= e($origin) ?>/galerie/creer.php" target="_blank" rel="noopener"><code><?= e($origin) ?>/galerie/creer.php</code></a> (choix du type) ; une page par type : <?php foreach ($types as $k => $t): if (!$t['active']) continue; ?><a href="<?= e($origin) ?>/galerie/creer.php?type=<?= e($k) ?>" target="_blank" rel="noopener"><code>?type=<?= e($k) ?></code></a> <?php endforeach; ?>— <?= saas_stripe_configured() ? 'paiement Stripe activé' : 'paiement Stripe non configuré (Offres et annuaire) : seul le test gratuit fonctionne' ?>.</p>

    <?php foreach ($types as $k => $t): ?>
    <details class="gcard" id="t-<?= e($k) ?>"<?= ($_GET['t'] ?? '') === $k ? ' open' : '' ?>>
      <summary><span style="font-size:1.4rem;"><?= e($t['emoji']) ?></span> <?= e($t['name']) ?> <span class="meta" style="font-weight:400;">· <code><?= e($k) ?></code> · <?= $t['active'] ? 'proposé à la vente' : 'masqué' ?><?= $t['custom'] ? ' · personnalisé' : '' ?></span></summary>
      <form method="post" autocomplete="off" style="display:grid;gap:12px;"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="type"><input type="hidden" name="key" value="<?= e($k) ?>">
        <div class="cols">
          <label class="f2">Nom du type<input type="text" name="t[name]" value="<?= e($t['name']) ?>" maxlength="40"></label>
          <label class="f2">Pictogramme<input type="text" name="t[emoji]" value="<?= e($t['emoji']) ?>" maxlength="8"></label>
          <label class="f2">Ordre d'affichage<input type="number" name="t[order]" value="<?= (int) $t['order'] ?>" min="1" max="99"></label>
          <label class="f2"><span><input type="checkbox" name="t[active]" value="1"<?= $t['active'] ? ' checked' : '' ?>> Proposé à la vente</span></label>
        </div>
        <label class="f2">Phrase d'accroche<input type="text" name="t[tagline]" value="<?= e($t['tagline']) ?>" maxlength="160"></label>

        <h3>Dénominations</h3>
        <div class="cols">
          <?php foreach (GT_VOCAB_KEYS as $vk => $vl): $long = in_array($vk, ['members_lede', 'create_intro'], true); ?>
            <label class="f2"<?= $long ? ' style="grid-column:1/-1"' : '' ?>><?= e($vl) ?><input type="text" name="t[vocab][<?= e($vk) ?>]" value="<?= e($t['vocab'][$vk]) ?>" maxlength="<?= $long ? 300 : 80 ?>"></label>
          <?php endforeach; ?>
        </div>

        <h3>Fonctionnalités</h3>
        <div class="feats">
          <?php foreach (GT_FEATURES as $fk => $fl): ?><label><input type="checkbox" name="t[features][<?= e($fk) ?>]" value="1"<?= $t['features'][$fk] ? ' checked' : '' ?>> <?= e($fl) ?></label><?php endforeach; ?>
        </div>

        <h3>Lieux de vente</h3>
        <p class="meta" style="margin:0;">Les types de lieux proposés sur le plan (boutique, stand, emplacement…). La <strong>clé</strong> (lettres minuscules, chiffres, tirets) identifie le lieu dans les plans ; le <strong>libellé</strong> est affiché aux visiteurs. Le tarif indicatif préremplit l'éditeur de plan.</p>
        <div class="locrows" data-locrows>
          <?php foreach ($t['locations'] as $loc): ?>
            <div class="locrow row">
              <input type="text" name="t[locations][][key]" value="<?= e($loc['key']) ?>" maxlength="24" pattern="[a-z0-9][a-z0-9_-]{0,23}" placeholder="cle" aria-label="Clé du lieu" style="width:120px">
              <input type="text" name="t[locations][][label]" value="<?= e($loc['label']) ?>" maxlength="40" placeholder="Libellé (ex. Boutique)" aria-label="Libellé du lieu" style="flex:1;min-width:140px">
              <select name="t[locations][][unit]" aria-label="Unité de tarification"><?php foreach (GT_LOCATION_UNITS as $uk => $ul): ?><option value="<?= e($uk) ?>"<?= $loc['unit'] === $uk ? ' selected' : '' ?>><?= e($ul) ?></option><?php endforeach; ?></select>
              <input type="text" inputmode="decimal" name="t[locations][][price_cents_eur]" value="<?= $loc['price_cents'] > 0 ? e(number_format($loc['price_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="Tarif €" aria-label="Tarif indicatif" style="width:90px">
            </div>
          <?php endforeach; ?>
          <?php for ($i = 0; $i < 2; $i++): ?>
            <div class="locrow row">
              <input type="text" name="t[locations][][key]" maxlength="24" pattern="[a-z0-9][a-z0-9_-]{0,23}" placeholder="cle" aria-label="Clé du lieu" style="width:120px">
              <input type="text" name="t[locations][][label]" maxlength="40" placeholder="Libellé (ex. Boutique)" aria-label="Libellé du lieu" style="flex:1;min-width:140px">
              <select name="t[locations][][unit]" aria-label="Unité de tarification"><?php foreach (GT_LOCATION_UNITS as $uk => $ul): ?><option value="<?= e($uk) ?>"><?= e($ul) ?></option><?php endforeach; ?></select>
              <input type="text" inputmode="decimal" name="t[locations][][price_cents_eur]" placeholder="Tarif €" aria-label="Tarif indicatif" style="width:90px">
            </div>
          <?php endfor; ?>
        </div>
        <p><button class="btn small" type="button" data-locadd>Ajouter un lieu</button></p>

        <h3>Plugins proposés aux vendeurs</h3>
        <p class="meta" style="margin:0;">Chaque plugin payant peut être hérité du réglage de la plateforme, proposé à la vente, inclus gratuitement ou masqué pour les boutiques de ce type de galerie. Ce choix prime sur le ciblage global du plugin.</p>
        <div class="cols">
          <?php foreach (addon_defaults() as $ak => $ad): $vmode = (string) ($t['vendor_plugins'][$ak] ?? 'inherit'); ?>
            <label class="f2"><?= e($ad['name']) ?>
              <select name="t[vendor_plugins][<?= e($ak) ?>]"><?php foreach (GT_VENDOR_PLUGIN_MODES as $vm => $vl): ?><option value="<?= e($vm) ?>"<?= $vmode === $vm ? ' selected' : '' ?>><?= e($vl) ?></option><?php endforeach; ?></select>
            </label>
          <?php endforeach; ?>
        </div>

        <h3>Modules (plugins partagés)</h3>
        <p class="meta" style="margin:0;">Un module est une fonctionnalité proposée par ce type : inclus dans l'offre, option payante (essai puis abonnement Stripe) ou non proposé. Les galeries de ce type le retrouvent dans leurs Options.</p>
        <div class="cols">
          <?php foreach (gm_modules() as $mk => $md): ?>
            <label class="f2"><span><?= e($md['emoji']) ?> <?= e($md['name']) ?></span><span class="meta" style="font-weight:400;"><?= e($md['description']) ?></span>
              <select name="t[modules][<?= e($mk) ?>]">
                <?php foreach (GM_MODE_LABELS as $mode => $label): ?>
                  <option value="<?= e($mode) ?>"<?= ($t['modules'][$mk] ?? '') === $mode ? ' selected' : '' ?>><?= e($label) ?><?= $mode === 'option' ? ' — ' . e(gm_price_text($md['month_cents'])) : '' ?></option>
                <?php endforeach; ?>
              </select></label>
          <?php endforeach; ?>
        </div>

        <h3>Les trois offres de ce type</h3>
        <div class="cols">
          <?php foreach ($t['offers'] as $ok => $o): ?>
            <div class="oc"><strong>Offre « <?= e($ok) ?> »<?= $o['month_cents'] <= 0 ? ' — gratuite (test)' : '' ?></strong>
              <label>Nom<input type="text" name="t[offers][<?= e($ok) ?>][name]" value="<?= e($o['name']) ?>" maxlength="40"></label>
              <label>Phrase d'accroche<input type="text" name="t[offers][<?= e($ok) ?>][tagline]" value="<?= e($o['tagline']) ?>" maxlength="120"></label>
              <label>Membres au plus<input type="number" min="1" max="1000" name="t[offers][<?= e($ok) ?>][max_members]" value="<?= (int) $o['max_members'] ?>"></label>
              <label>Prix mensuel (€, 0 = gratuit)<input type="number" step="0.01" min="0" name="t[offers][<?= e($ok) ?>][month_cents_eur]" value="<?= e(number_format($o['month_cents'] / 100, 2, '.', '')) ?>"></label>
              <label>Prix annuel (€)<input type="number" step="0.01" min="0" name="t[offers][<?= e($ok) ?>][year_cents_eur]" value="<?= e(number_format($o['year_cents'] / 100, 2, '.', '')) ?>"></label>
              <label>Durée de l'essai (jours, offre gratuite)<input type="number" min="0" max="365" name="t[offers][<?= e($ok) ?>][trial_days]" value="<?= (int) $o['trial_days'] ?>"></label>
              <label><span><input type="checkbox" name="t[offers][<?= e($ok) ?>][active]" value="1"<?= $o['active'] ? ' checked' : '' ?>> Proposée à la vente</span></label></div>
          <?php endforeach; ?>
        </div>
        <p><button class="btn btn-primary" type="submit">Enregistrer « <?= e($t['name']) ?> »</button></p>
      </form>
    </details>
    <?php endforeach; ?>

    <form class="gcard" method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="newtype">
      <h2>Ajouter un type</h2>
      <p class="meta">Un nouveau type part des réglages de la galerie marchande ; vous les adaptez ensuite (dénominations, fonctionnalités, prix).</p>
      <div class="row"><input type="text" name="key" placeholder="identifiant (ex. foire)" pattern="[a-z0-9][a-z0-9-]{1,23}" maxlength="24" required><input type="text" name="name" placeholder="Nom (ex. Foire artisanale)" maxlength="40" required><button class="btn" type="submit">Créer le type</button></div>
    </form>

    <section class="gcard"><h2>Type et offre de chaque galerie</h2>
      <table class="t"><thead><tr><th>Galerie</th><th>Type</th><th>Offre</th><th>État</th><th>Membres</th><th>Changer l'offre</th></tr></thead><tbody>
      <?php foreach ($galleries as $g): $st = go_status($g); $lim = go_member_limit($g); $gt = gt_of($g); ?>
        <tr><td><strong><?= e($g['name']) ?></strong><br><small class="meta"><?= e($g['slug']) ?></small></td>
          <td><form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settype"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
            <select name="type" onchange="this.form.submit()" aria-label="Type de <?= e($g['name']) ?>"><?php foreach ($types as $tk => $tt): ?><option value="<?= e($tk) ?>"<?= $gt['key'] === $tk ? ' selected' : '' ?>><?= e($tt['name']) ?></option><?php endforeach; ?></select></form></td>
          <td><?= $st === 'manual' ? 'Sans abonnement' : e($gt['offers'][$g['plan']['offer']]['name'] ?? $g['plan']['offer']) ?></td>
          <td><?= $st === 'manual' ? 'accordée' : e(GO_PLAN_STATUS[$st] ?? $st) . ($st === 'trial' ? ' → ' . e(date('d/m/Y', $g['plan']['trial_until'])) : '') ?></td>
          <td><?= count($g['members']) ?><?= $lim !== null ? ' / ' . (int) $lim : ' (illimité)' ?></td>
          <td><form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="plan"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
            <select name="offre"><?php foreach ($gt['offers'] as $k => $o): ?><option value="<?= e($k) ?>"<?= ($g['plan']['offer'] ?? '') === $k ? ' selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select>
            <select name="mode"><option value="active">Offerte / abonnée</option><option value="trial">Essai de…</option><option value="canceled">Résiliée</option><option value="manual">Sans abonnement</option></select>
            <input type="number" name="days" value="14" min="1" max="365" style="width:70px" title="Jours d'essai (mode « Essai »)"><button class="btn small" type="submit">Appliquer</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$galleries): ?><tr><td colspan="6" class="meta">Aucune galerie.</td></tr><?php endif; ?>
      </tbody></table>
    </section>
  </div>
</div>
<script>
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-locadd]');
    if (!btn) return;
    var box = btn.closest('form').querySelector('[data-locrows]');
    var row = box && box.querySelector('.locrow');
    if (!row) return;
    var copy = row.cloneNode(true);
    copy.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    copy.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
    box.appendChild(copy);
    var first = copy.querySelector('input');
    if (first) first.focus();
  });
</script>
</body>
</html>
