<?php
// Espace visiteur et fidélité de la galerie : réglages du programme, lieux de scan (QR codes à imprimer), membres, récompenses à remettre.
// Indépendant des boutiques membres et du plugin « Espace visiteur » (programme propre à chaque boutique). Voir includes/gallery-loyalty.php.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-loyalty.php';

$self = '/portail/galerie-fidelite.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
if (!gt_feature($gaGallery, 'fidelite')) {
    portail_flash("L'espace visiteur n'est pas proposé pour ce type de galerie.", 'error');
    header('Location: /portail/galeries.php' . ($limited ? '' : '?g=' . urlencode($gaSlug)));
    exit;
}
$set = gl_settings($gaGallery);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'save') {
        $tiers = [];
        foreach ((array) ($_POST['tier_pts'] ?? []) as $i => $pts) $tiers[] = ['pts' => $pts, 'label' => ((array) ($_POST['tier_label'] ?? []))[$i] ?? ''];
        $stands = preg_split('/\R/u', (string) ($_POST['stands'] ?? '')) ?: [];
        $new = gl_settings_normalize(['enabled' => !empty($_POST['enabled']), 'program_name' => $_POST['program_name'] ?? '', 'welcome' => $_POST['welcome'] ?? 0, 'per_scan' => $_POST['per_scan'] ?? 10,
            'intro' => $_POST['intro'] ?? '', 'tiers' => $tiers, 'stands' => $stands], $set);
        gl_update($gaSlug, static function (array &$d) use ($new) { $d['settings'] = $new; });
        portail_flash('Réglages enregistrés.');
    } elseif ($act === 'prefill') {
        $names = array_map(static fn ($s) => $s['name'], $set['stands']);
        try {
            foreach (gallery_catalogue($gaGallery, $gaOrigin)['shops'] as $s) if ($s['name'] !== '') $names[] = $s['name'];
        } catch (Throwable $e) {
        }
        $new = gl_settings_normalize(['enabled' => $set['enabled'], 'program_name' => $set['program_name'], 'welcome' => $set['welcome'], 'per_scan' => $set['per_scan'], 'intro' => $set['intro'], 'tiers' => $set['tiers'], 'stands' => $names], $set);
        gl_update($gaSlug, static function (array &$d) use ($new) { $d['settings'] = $new; });
        portail_flash('Lieux de scan complétés avec les membres de la galerie.');
    } else {
        $err = gl_admin_action($gaGallery, $act, $_POST);
        portail_flash($err ?? 'Fait.', $err ? 'error' : 'ok');
    }
    header('Location: ' . $self);
    exit;
}

$d = gl_read($gaSlug);
$members = array_values($d['members']);
usort($members, static fn ($a, $b) => $b['points'] <=> $a['points']);
$pending = array_values(array_filter($d['rewards'], static fn ($r) => ($r['status'] ?? '') === 'pending'));
$used = count($d['rewards']) - count($pending);
$appUrl = gl_app_url($gaSlug);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fidelite-' . $gaSlug . '.csv"');
    $q = static fn ($v) => '"' . str_replace('"', '""', ltrim((string) $v, "=+-@\t\r")) . '"';
    echo "\xEF\xBB\xBF" . "E-mail;Prénom;Points;Points gagnés;Inscrit le\r\n";
    foreach ($members as $m) echo implode(';', [$q($m['email']), $q($m['name']), (int) $m['points'], (int) $m['earned'], date('d/m/Y', (int) $m['created'])]) . "\r\n";
    exit;
}
if (isset($_GET['print'])) {                 // planche de QR codes à imprimer : un par lieu de scan
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>QR codes — <?= e($gaGallery['name']) ?></title>
<style>body{font:16px system-ui,sans-serif;margin:0;padding:12px}.bar{margin-bottom:12px}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10mm}.qr{border:1px dashed #999;padding:8mm;text-align:center;break-inside:avoid}.qr svg{width:60mm;height:60mm}.qr h2{margin:0 0 4mm;font-size:20px}.qr p{margin:4mm 0 0;font-size:13px;color:#444}@media print{.bar{display:none}}</style></head><body>
<div class="bar"><button onclick="print()">Imprimer</button> <a href="<?= e($self) ?>">← Retour</a></div>
<div class="grid"><?php foreach ($set['stands'] as $st): ?><div class="qr"><h2><?= e($st['name']) ?></h2><div class="q" data-url="<?= e(gl_scan_url($gaSlug, $st)) ?>"></div><p>Scannez avec l'appareil photo de votre téléphone<br>et gagnez <?= (int) $set['per_scan'] ?> points « <?= e($set['program_name']) ?> » — <?= e($gaGallery['name']) ?></p></div><?php endforeach; ?></div>
<script src="/assets/vendor/qrcode-generator.js"></script>
<script>document.querySelectorAll('.q').forEach(function(b){var q=qrcode(0,'M');q.addData(b.dataset.url);q.make();b.innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});});</script></body></html><?php
    exit;
}

$hid = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">');
$tiers = $set['tiers'] ?: [['pts' => '', 'label' => '']];
while (count($tiers) < 3) $tiers[] = ['pts' => '', 'label' => ''];
ga_head('Fidélité');
?>
    <section class="gcard">
      <h2>Espace visiteur et fidélité de la galerie</h2>
      <p class="meta">Une web app installable pour vos visiteurs, <strong>propre à la galerie</strong> : elle fonctionne même si aucun commerce n'a activé son propre espace visiteur. Les visiteurs gagnent des points en scannant le QR code des lieux participants (chaque commerce, stand ou entrée) et les échangent contre des récompenses que vous remettez. Chaque commerce garde son propre programme s'il en a un.</p>
      <?php if (empty($gaGallery['published']) || !empty($gaGallery['paused'])): ?><p class="meta"><strong><?= !empty($gaGallery['paused']) ? 'La galerie est en pause' : "La galerie n'est pas publiée" ?></strong> : l'espace visiteur ne sera ouvert qu'une fois la galerie publiée et réactivée.</p><?php endif; ?>
      <div class="kpis">
        <div class="kpi"><b><?= count($members) ?></b><span>membres</span></div>
        <div class="kpi"><b><?= count($pending) ?></b><span>récompenses à remettre</span></div>
        <div class="kpi"><b><?= $used ?></b><span>récompenses remises</span></div>
        <div class="kpi"><b><?= array_sum(array_map(static fn ($m) => (int) $m['points'], $members)) ?></b><span>points en circulation</span></div>
      </div>
      <p class="meta">Adresse de l'application : <a href="<?= e($appUrl) ?>" target="_blank" rel="noopener"><?= e($appUrl) ?></a> — le bouton « Application visiteurs » apparaît automatiquement sur la page publique de la galerie quand le programme est ouvert.</p>
    </section>

    <section class="gcard">
      <h2>Réglages</h2>
      <form method="post" style="display:grid;gap:12px;">
        <?= $hid ?><input type="hidden" name="action" value="save">
        <label class="row" style="gap:6px;"><input type="checkbox" name="enabled" value="1"<?= $set['enabled'] ? ' checked' : '' ?>> Programme ouvert (l'application est accessible au public)</label>
        <div class="row">
          <label class="f">Nom du programme<input type="text" name="program_name" maxlength="40" value="<?= e($set['program_name']) ?>"></label>
          <label class="f" style="max-width:170px;">Points de bienvenue<input type="number" name="welcome" min="0" max="1000" value="<?= (int) $set['welcome'] ?>"></label>
          <label class="f" style="max-width:190px;">Points par lieu scanné<input type="number" name="per_scan" min="1" max="100" value="<?= (int) $set['per_scan'] ?>"></label>
        </div>
        <label class="f">Message d'accueil (facultatif)<input type="text" name="intro" maxlength="300" value="<?= e($set['intro']) ?>" placeholder="Ex. Visitez les stands, cumulez des points et gagnez des cadeaux."></label>
        <div>
          <strong style="font-size:.9rem;">Récompenses (jusqu'à <?= GL_MAX_TIERS ?>)</strong>
          <p class="meta">Un palier = un nombre de points échangé contre une récompense que vous remettez vous-même (café offert, remise, cadeau…). Laissez une ligne vide pour ne pas l'utiliser.</p>
          <?php foreach (array_slice($tiers, 0, GL_MAX_TIERS) as $t): ?>
            <div class="row" style="margin-bottom:6px;"><label class="f" style="max-width:130px;">Points<input type="number" name="tier_pts[]" min="1" max="100000" value="<?= e($t['pts']) ?>"></label><label class="f">Récompense<input type="text" name="tier_label[]" maxlength="60" value="<?= e($t['label']) ?>" placeholder="Ex. Un café offert"></label></div>
          <?php endforeach; ?>
        </div>
        <label class="f">Lieux de scan (un par ligne, <?= GL_MAX_STANDS ?> au plus)<textarea name="stands" rows="6" placeholder="Entrée principale&#10;Stand de Paul&#10;Buvette"><?= e(implode("\n", array_map(static fn ($s) => $s['name'], $set['stands']))) ?></textarea></label>
        <div class="row"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
      </form>
      <form method="post" class="row"><?= $hid ?><input type="hidden" name="action" value="prefill"><button class="btn" type="submit">+ Ajouter les <?= e(gt_word($gaGallery, 'members')) ?> de la galerie aux lieux de scan</button></form>
    </section>

    <section class="gcard">
      <h2>QR codes des lieux de scan</h2>
      <?php if (!$set['stands']): ?><p class="meta">Ajoutez des lieux de scan ci-dessus et enregistrez.</p><?php else: ?>
        <p class="meta">Un QR code par lieu : à imprimer et à afficher sur place. Le visiteur le scanne avec l'appareil photo de son téléphone (une fois par lieu et par jour). Chaque code est secret : un lieu retiré de la liste cesse de rapporter des points.</p>
        <div class="row"><a class="btn btn-primary" href="<?= e($self . ($limited ? '?' : '&') . 'print=1') ?>" target="_blank" rel="noopener">Imprimer les QR codes (<?= count($set['stands']) ?>)</a></div>
      <?php endif; ?>
    </section>

    <section class="gcard">
      <h2>Récompenses à remettre</h2>
      <?php if (!$pending): ?><p class="meta">Aucune récompense en attente.</p><?php else: ?>
      <table class="t"><thead><tr><th>Code</th><th>Récompense</th><th>Membre</th><th>Demandée le</th><th></th></tr></thead><tbody>
        <?php foreach ($pending as $r): ?><tr><td><b><?= e($r['code']) ?></b></td><td><?= e($r['label']) ?></td><td><?= e($r['email']) ?></td><td><?= e(date('d/m/Y H:i', (int) $r['created'])) ?></td><td>
          <form method="post" style="display:inline;"><?= $hid ?><input type="hidden" name="reward" value="<?= e($r['id']) ?>"><button class="btn mini" name="action" value="used" type="submit">Remise ✓</button>
          <button class="btn mini" name="action" value="cancel" type="submit" onclick="return confirm('Annuler et rendre les <?= (int) $r['pts'] ?> points au membre ?')">Annuler</button></form></td></tr><?php endforeach; ?>
      </tbody></table><?php endif; ?>
    </section>

    <section class="gcard">
      <h2>Membres</h2>
      <?php if (!$members): ?><p class="meta">Aucun membre pour le moment.</p><?php else: ?>
      <div class="row"><a class="btn" href="<?= e($self . ($limited ? '?' : '&') . 'export=csv') ?>">Exporter (CSV)</a></div>
      <table class="t"><thead><tr><th>E-mail</th><th>Prénom</th><th class="num">Points</th><th class="num">Gagnés</th><th>Ajuster</th><th></th></tr></thead><tbody>
        <?php foreach (array_slice($members, 0, 300) as $m): ?><tr><td><?= e($m['email']) ?></td><td><?= e($m['name']) ?></td><td class="num"><?= (int) $m['points'] ?></td><td class="num"><?= (int) $m['earned'] ?></td><td>
          <form method="post" class="row" style="gap:4px;flex-wrap:nowrap;"><?= $hid ?><input type="hidden" name="email" value="<?= e($m['email']) ?>"><input type="hidden" name="action" value="adjust"><input type="number" name="delta" value="10" style="width:80px;min-width:0;flex:none;"><button class="btn mini" type="submit">Ajouter / retirer</button></form></td><td>
          <form method="post" onsubmit="return confirm('Supprimer ce membre et ses données ?')"><?= $hid ?><input type="hidden" name="email" value="<?= e($m['email']) ?>"><button class="btn mini danger" name="action" value="remove" type="submit">Supprimer</button></form></td></tr><?php endforeach; ?>
      </tbody></table><?php if (count($members) > 300): ?><p class="meta">300 premiers membres affichés (classés par points) ; l'export contient tout le monde.</p><?php endif; endif; ?>
    </section>
<?php ga_foot(); ?>
