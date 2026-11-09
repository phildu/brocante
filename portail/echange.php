<?php
// Échange de boutiques et de galeries entre deux environnements (prod ↔ préprod ↔ local), par paquet .zip.
// Exporter ici → importer sur l'autre portail, et inversement. Voir includes/exchange.php pour ce qui voyage (et ce qui ne voyage jamais).
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/exchange.php';

if (defined('GA_LIMITED')) { header('Location: /portail/galerie-tableau.php'); exit; }

$self = '/portail/echange.php';
$env = app_env();
$envLabel = ['local' => 'LOCAL (Mac)', 'preprod' => 'PRÉPROD', 'prod' => 'PRODUCTION'][$env] ?? strtoupper($env);
$siteBase = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$tmpDir = exchange_root() . '/data/echange';

function echange_back(string $message, string $kind = 'error'): never
{
    portail_flash($message, $kind);
    header('Location: /portail/echange.php', true, 303);
    exit;
}

/** Paquets en attente d'import : supprimés au bout de deux heures. */
function echange_purge(string $dir): void
{
    foreach (glob($dir . '/p-*.zip') ?: [] as $file) {
        if (filemtime($file) < time() - 7200) @unlink($file);
    }
}

echange_purge($tmpDir);
$pending = $_SESSION['echange_pending'] ?? null;
if ($pending && !is_file((string) ($pending['file'] ?? ''))) {
    unset($_SESSION['echange_pending']);
    $pending = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    @set_time_limit(0);

    // 1. Export : le paquet est construit puis envoyé en téléchargement.
    if ($action === 'export') {
        $shops = array_values(array_filter((array) ($_POST['shops'] ?? []), 'is_string'));
        $galleries = array_values(array_filter((array) ($_POST['galleries'] ?? []), 'is_string'));
        if (!$shops && !$galleries) echange_back('Cochez au moins une boutique ou une galerie à exporter.');
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true)) echange_back('Dossier temporaire impossible à créer (data/echange).');
        $file = $tmpDir . '/x-' . bin2hex(random_bytes(6)) . '.zip';
        try {
            $manifest = exchange_build_zip($shops, $galleries, $file, !empty($_POST['with_files']));
        } catch (Throwable $e) {
            @unlink($file);
            echange_back('Export impossible : ' . $e->getMessage());
        }
        exchange_log(['action' => 'export', 'shops' => $shops, 'galleries' => $galleries, 'files' => count($manifest['files'])]);
        session_write_close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="echange-' . $env . '-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store');
        readfile($file);
        @unlink($file);
        exit;
    }

    // 2. Aperçu : le paquet envoyé est rangé en attente, rien n'est modifié.
    if ($action === 'analyze') {
        $up = $_FILES['package'] ?? null;
        if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $big = in_array($up['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            echange_back($big ? 'Paquet plus lourd que la limite du serveur (' . ini_get('upload_max_filesize') . ').' : 'Choisissez le fichier .zip exporté depuis l\'autre portail.');
        }
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true)) echange_back('Dossier temporaire impossible à créer (data/echange).');
        $dest = $tmpDir . '/p-' . bin2hex(random_bytes(8)) . '.zip';
        if (!move_uploaded_file($up['tmp_name'], $dest)) echange_back('Paquet impossible à ranger sur le serveur.');
        try {
            exchange_plan($dest);   // contrôle seulement : lève une exception si le paquet est invalide
        } catch (Throwable $e) {
            @unlink($dest);
            echange_back($e->getMessage());
        }
        if ($pending) @unlink((string) $pending['file']);
        $_SESSION['echange_pending'] = ['file' => $dest, 'name' => mb_substr(basename((string) $up['name']), 0, 120), 'at' => time()];
        header('Location: ' . $self, true, 303);
        exit;
    }

    if ($action === 'cancel') {
        if ($pending) @unlink((string) $pending['file']);
        unset($_SESSION['echange_pending']);
        echange_back('Import annulé : rien n\'a été modifié.', 'ok');
    }

    // 3. Import : seulement après l'aperçu, avec confirmation en production.
    if ($action === 'import') {
        if (!$pending) echange_back('Aucun paquet en attente : envoyez-le de nouveau.');
        if ($env === 'prod' && trim((string) ($_POST['confirm'] ?? '')) !== 'PROD') {
            echange_back('Import en production : tapez PROD pour confirmer.');
        }
        $shops = array_values(array_filter((array) ($_POST['shops'] ?? []), 'is_string'));
        $galleries = array_values(array_filter((array) ($_POST['galleries'] ?? []), 'is_string'));
        if (!$shops && !$galleries) echange_back('Cochez au moins un élément à importer.');
        $opts = ['delete_missing' => !empty($_POST['delete_missing']), 'keep_stock' => !empty($_POST['keep_stock'])];
        try {
            $results = exchange_import_zip((string) $pending['file'], $shops, $galleries, $opts, $siteBase);
        } catch (Throwable $e) {
            echange_back('Import impossible : ' . $e->getMessage());
        }
        @unlink((string) $pending['file']);
        unset($_SESSION['echange_pending']);
        $_SESSION['echange_results'] = $results;
        header('Location: ' . $self, true, 303);
        exit;
    }
    echange_back('Action inconnue.');
}

// Résultat d'un import : affiché une seule fois (les mots de passe créés n'existent nulle part ailleurs en clair).
$results = $_SESSION['echange_results'] ?? null;
unset($_SESSION['echange_results']);

$shopsHere = exchange_shop_inventory();
$galleriesHere = exchange_gallery_inventory();
$plan = null;
$planError = '';
if ($pending) {
    try {
        $plan = exchange_plan((string) $pending['file'], ['delete_missing' => !empty($_GET['delete_missing'])]);
    } catch (Throwable $e) {
        $planError = $e->getMessage();
    }
}
$csrf = csrf_token();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Échange prod / préprod — Portail des commerces</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .exwrap { display: grid; gap: 16px; max-width: 880px; margin: 0 auto; padding: 0 16px 48px; }
  .excard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .excard h2 { margin: 0; font-size: 1.05rem; }
  .excard p { margin: 0; }
  .exmeta { color: var(--muted); font-size: .84rem; }
  .exwarn { border: 1px solid #e0c58a; background: #fff7e0; color: #5a4300; border-radius: 10px; padding: 10px 14px; font-size: .88rem; }
  .exdanger { border: 1px solid var(--warn); border-radius: 10px; padding: 10px 14px; font-size: .88rem; }
  .exlist { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }
  .exlist label { display: flex; gap: 10px; align-items: baseline; font-size: .92rem; }
  .exlist small { color: var(--muted); }
  .exrow { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .exopt { display: flex; gap: 8px; align-items: flex-start; font-size: .88rem; }
  .exopt input { margin-top: 3px; }
  .exenv { display: inline-block; padding: 2px 10px; border-radius: 999px; font-weight: 700; font-size: .8rem; border: 1px solid var(--line); }
  .exenv.is-prod { background: var(--warn); color: #fff; border-color: var(--warn); }
  .exres li { margin: 2px 0; }
  .exok { color: var(--ok); font-weight: 600; }
  .exko { color: var(--warn); font-weight: 600; }
  .exfield input[type=text], .exfield input[type=file] { padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Échange prod / préprod',
    'eyebrow' => 'Plateforme',
    'desc' => 'Copier des boutiques et des galeries d\'un environnement à l\'autre, dans les deux sens, par un paquet .zip.',
]); ?>
  <?php $flash = portail_flash(); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <div class="exwrap">

    <section class="excard">
      <h2>Vous êtes sur <span class="exenv<?= $env === 'prod' ? ' is-prod' : '' ?>"><?= e($envLabel) ?></span></h2>
      <p class="exmeta"><?= e($_SERVER['HTTP_HOST'] ?? '') ?> · Un paquet exporté ici s'importe sur l'autre portail (et inversement). Voyagent : fiches, photos, vidéos, apparence, textes, livraison, plan et présentation des galeries. Ne voyagent jamais : commandes, clients, comptes, mots de passe, clés de paiement ou d'IA, abonnements, pièces d'identité, adresses propres à chaque environnement.</p>
    </section>

    <?php if ($results): ?>
      <section class="excard exres">
        <h2>Résultat de l'import</h2>
        <ul class="exlist">
          <?php foreach ($results as $r): ?>
            <li>
              <span class="<?= $r['ok'] ? 'exok' : 'exko' ?>"><?= $r['ok'] ? '✔' : '✘' ?></span>
              <strong><?= e($r['kind'] === 'shop' ? 'Boutique' : 'Galerie') ?> <?= e($r['name']) ?></strong> (<?= e($r['slug']) ?>) — <?= e($r['message'] ?? '') ?>
              <?php if (!empty($r['password'])): ?>
                <div class="exwarn">Boutique créée : administration <code>admin</code> / mot de passe <code><?= e($r['password']) ?></code> — notez-le maintenant, il ne sera plus affiché.</div>
              <?php endif; ?>
              <?php foreach ((array) ($r['warnings'] ?? []) as $w): ?><div class="exmeta">⚠ <?= e($w) ?></div><?php endforeach; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="exmeta">La base d'une boutique mise à jour a été sauvegardée avant l'import (data/backups/…-echange-….db). Journal : data/logs/echange.log.</p>
      </section>
    <?php endif; ?>

    <section class="excard">
      <h2>1. Exporter depuis cet environnement</h2>
      <form method="post" action="<?= e($self) ?>" class="exwrap" style="padding:0;gap:12px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="export">
        <div>
          <strong>Boutiques</strong>
          <ul class="exlist">
            <?php foreach ($shopsHere as $slug => $s): if ($slug[0] === '_') continue; ?>
              <li><label><input type="checkbox" name="shops[]" value="<?= e($slug) ?>"> <span><?= e($s['name']) ?> <small><?= e($slug) ?> · <?= $s['products'] === null ? 'base absente' : (int) $s['products'] . ' fiche(s)' ?></small></span></label></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div>
          <strong>Galeries</strong>
          <ul class="exlist">
            <?php foreach ($galleriesHere as $slug => $g): ?>
              <li><label><input type="checkbox" name="galleries[]" value="<?= e($slug) ?>"> <span><?= e($g['name']) ?> <small><?= e($slug) ?> · <?= (int) $g['members'] ?> membre(s)</small></span></label></li>
            <?php endforeach; ?>
            <?php if (!$galleriesHere): ?><li class="exmeta">Aucune galerie sur cet environnement.</li><?php endif; ?>
          </ul>
        </div>
        <label class="exopt"><input type="checkbox" name="with_files" value="1" checked> <span>Joindre les images et vidéos (sinon, seules les fiches voyagent : les fichiers doivent déjà exister sur la cible)</span></label>
        <div><button class="btn btn-primary" type="submit">Télécharger le paquet (.zip)</button></div>
      </form>
    </section>

    <section class="excard">
      <h2>2. Importer sur cet environnement</h2>
      <?php if (!$pending): ?>
        <form method="post" action="<?= e($self) ?>" enctype="multipart/form-data" class="exwrap" style="padding:0;gap:12px">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="analyze">
          <label class="exfield"><input type="file" name="package" accept=".zip,application/zip" required></label>
          <p class="exmeta">L'envoi ne modifie rien : un aperçu s'affiche d'abord et vous choisissez ce qui est importé (limite du serveur : <?= e(ini_get('upload_max_filesize')) ?>).</p>
          <div><button class="btn" type="submit">Envoyer et voir l'aperçu</button></div>
        </form>
      <?php elseif ($planError !== ''): ?>
        <p class="exdanger"><?= e($planError) ?></p>
        <form method="post" action="<?= e($self) ?>"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="cancel"><button class="btn" type="submit">Abandonner ce paquet</button></form>
      <?php else:
          $src = (array) ($plan['manifest']['source'] ?? []); ?>
        <p>Paquet <strong><?= e($pending['name']) ?></strong> — exporté de <strong><?= e(strtoupper((string) ($src['env'] ?? '?'))) ?></strong> (<?= e((string) ($src['host'] ?? '')) ?>) le <?= e(date('d/m/Y H:i', (int) strtotime((string) ($plan['manifest']['created'] ?? 'now')))) ?>.</p>
        <?php foreach ($plan['warnings'] as $w): ?><p class="exwarn"><?= e($w) ?></p><?php endforeach; ?>
        <form method="post" action="<?= e($self) ?>" class="exwrap" style="padding:0;gap:12px">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="import">
          <?php if ($plan['shops']): ?>
            <div>
              <strong>Boutiques</strong>
              <ul class="exlist">
                <?php foreach ($plan['shops'] as $slug => $s): ?>
                  <li><label><input type="checkbox" name="shops[]" value="<?= e($slug) ?>" checked>
                    <span><?= e($s['name']) ?> <small><?= e($slug) ?> ·
                      <?php if ($s['exists']): ?>mise à jour : <?= (int) $s['products_new'] ?> nouvelle(s) fiche(s), <?= (int) $s['products_updated'] ?> mise(s) à jour<?php else: ?>sera <strong>créée</strong> avec <?= (int) $s['products_in'] ?> fiche(s) (mot de passe d'administration neuf)<?php endif; ?>
                      · fichiers : <?= (int) $s['files']['new'] ?> nouveau(x), <?= (int) $s['files']['same'] ?> déjà présent(s)<?= $s['files']['renamed'] ? ', ' . (int) $s['files']['renamed'] . ' renommé(s) (nom déjà pris)' : '' ?></small></span></label></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <?php if ($plan['galleries']): ?>
            <div>
              <strong>Galeries</strong>
              <ul class="exlist">
                <?php foreach ($plan['galleries'] as $slug => $g): ?>
                  <li><label><input type="checkbox" name="galleries[]" value="<?= e($slug) ?>" checked>
                    <span><?= e($g['name']) ?> <small><?= e($slug) ?> · <?= $g['exists'] ? 'mise à jour (administrateurs, options, adresse et publication conservés)' : 'sera <strong>créée hors ligne</strong>' ?>
                      · fichiers : <?= (int) $g['files']['new'] ?> nouveau(x), <?= (int) $g['files']['same'] ?> déjà présent(s)</small></span></label>
                    <?php foreach ($g['warnings'] as $w): ?><div class="exmeta">⚠ <?= e($w) ?></div><?php endforeach; ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <label class="exopt"><input type="checkbox" name="keep_stock" value="1" checked> <span>Conserver le stock et l'état de vente des fiches déjà présentes ici (recommandé, surtout vers la production)</span></label>
          <label class="exopt"><input type="checkbox" name="delete_missing" value="1"> <span><strong>Miroir :</strong> supprimer ici les fiches absentes du paquet (et leurs photos). À réserver à une copie de la production vers la préprod.</span></label>
          <?php if ($env === 'prod'): ?>
            <p class="exdanger"><strong>Vous êtes en production.</strong> Les boutiques mises à jour sont sauvegardées avant l'import, mais les clients voient le résultat tout de suite. Tapez <code>PROD</code> pour confirmer.</p>
            <label class="exfield"><input type="text" name="confirm" autocomplete="off" placeholder="PROD" required></label>
          <?php endif; ?>
          <div class="exrow">
            <button class="btn btn-primary" type="submit">Importer la sélection</button>
            <button class="btn" type="submit" name="action" value="cancel" formnovalidate>Annuler</button>
          </div>
        </form>
      <?php endif; ?>
    </section>

  </div>
</div>
</body>
</html>
