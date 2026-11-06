<?php
// Fiches d'annuaire des commerces qui ont déjà leur propre site : validation, refus, relecture et rattachement à une galerie.
// Les demandes arrivent de /annuaire/inscrire.php ; l'exploitant peut aussi ajouter une adresse à la main.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/annuaire-externe.php';

$cfg = saas_config();
$galleries = gallery_list();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id = (string) ($_POST['id'] ?? '');
    $record = $id !== '' ? annuaire_ext_load($id) : null;

    if ($action === 'add') {
        $result = annuaire_ext_add((string) ($_POST['url'] ?? ''));
        portail_flash($result['ok'] ? 'Fiche « ' . $result['record']['name'] . ' » ajoutée et publiée dans l’annuaire.' : $result['error'], $result['ok'] ? 'ok' : 'error');
    } elseif (!$record) {
        portail_flash('Fiche introuvable.', 'error');
    } elseif ($action === 'approve') {
        $saved = annuaire_ext_review($record['id'], 'approved', (string) ($_POST['note'] ?? ''));
        if ($saved && $saved['contact_email'] !== '') {
            saas_mail($saved['contact_email'], 'Votre fiche est en ligne — ' . $cfg['name'],
                "Bonjour " . ($saved['contact_name'] !== '' ? $saved['contact_name'] : '') . ",\n\nLa fiche de « {$saved['name']} » est publiée dans l’annuaire :\n" . saas_origin() . "/annuaire/\n\n" . $cfg['name'] . "\n", 'annuaire');
        }
        portail_flash($saved ? 'Fiche publiée dans l’annuaire.' : 'Publication impossible.', $saved ? 'ok' : 'error');
    } elseif ($action === 'reject') {
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 400);
        $saved = annuaire_ext_review($record['id'], 'rejected', $note);
        if ($saved && $saved['contact_email'] !== '') {
            saas_mail($saved['contact_email'], 'Votre fiche annuaire — ' . $cfg['name'],
                "Bonjour " . ($saved['contact_name'] !== '' ? $saved['contact_name'] : '') . ",\n\nNous ne publions pas la fiche de « {$saved['name']} » pour le moment."
                . ($note !== '' ? "\n\n$note" : '') . "\n\nRépondez à ce message pour en parler.\n" . $cfg['name'] . "\n", 'annuaire');
        }
        portail_flash($saved ? 'Fiche refusée ; le commerçant est prévenu par e-mail.' : 'Refus impossible.', $saved ? 'ok' : 'error');
    } elseif ($action === 'refresh') {
        $profile = annuaire_ext_scan($record['url']);
        if (!$profile) {
            portail_flash('Le site n’a pas pu être relu.', 'error');
        } else {
            foreach (['name', 'tagline', 'description', 'address', 'phone', 'email', 'hours', 'logo', 'image', 'images', 'socials', 'accent', 'platform'] as $field) {
                if (isset($profile[$field]) && $profile[$field] !== '' && $profile[$field] !== []) $record[$field] = $profile[$field];
            }
            portail_flash(annuaire_ext_save($record) ? 'Fiche relue depuis le site.' : 'Enregistrement impossible.', 'ok');
        }
    } elseif ($action === 'attach') {
        $slug = strtolower((string) ($_POST['gallery'] ?? ''));
        if ($slug === '') {
            $record['gallery'] = '';
            portail_flash(annuaire_ext_save($record) ? 'Rattachement retiré.' : 'Enregistrement impossible.', 'ok');
        } else {
            $ok = annuaire_ext_attach_gallery($record, $slug);
            portail_flash($ok ? 'Commerce ajouté à la galerie.' : 'Rattachement impossible (galerie inconnue ou écriture refusée).', $ok ? 'ok' : 'error');
        }
    } elseif ($action === 'delete') {
        @unlink(annuaire_ext_dir() . '/' . $record['id'] . '.json');
        annuaire_ext_cache_clear();
        portail_flash('Fiche supprimée de la liste (le site du commerçant n’est pas touché).');
    }
    header('Location: /portail/annuaire-demandes.php');
    exit;
}

$flash = portail_flash();
$pending = annuaire_ext_list('pending');
$approved = annuaire_ext_list('approved');
$rejected = annuaire_ext_list('rejected');
$dt = static fn (int $t): string => $t > 0 ? date('d/m/Y à H:i', $t) : '—';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Fiches annuaire — Portail des commerces</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .awrap { display: grid; gap: 16px; max-width: 980px; margin: 0 auto; padding: 0 16px 48px; }
  .acard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .acard h2 { margin: 0; font-size: 1.05rem; }
  .arow { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .athumb { width: 68px; height: 68px; border-radius: 10px; object-fit: cover; background: var(--bg); border: 1px solid var(--line); }
  dl.d { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 0; font-size: .92rem; }
  dl.d dt { opacity: .65; } dl.d dd { margin: 0; word-break: break-word; }
  .acard input[type=text], .acard input[type=url], .acard select { padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; }
  .acard input[type=url] { flex: 1; min-width: 260px; }
  .plat { font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; opacity: .7; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Fiches annuaire (sites existants)',
    'eyebrow' => 'Pilotage',
    'desc' => 'Commerces déjà en ligne ailleurs : fiche lue sur leur page d’accueil, publication après vérification.',
    'actions' => '<a class="btn" href="' . e(saas_origin()) . '/annuaire/inscrire.php" target="_blank" rel="noopener">Voir la page publique</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="awrap">
    <section class="acard">
      <h2>Ajouter par adresse</h2>
      <p class="meta" style="margin:0">Le site est lu (nom, adresse, téléphone, images, solution) et la fiche est publiée directement. À utiliser pour un commerce rencontré sur le terrain.</p>
      <form method="post" class="arow">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <input type="url" name="url" required maxlength="500" placeholder="https://www.mon-commerce.fr">
        <button class="btn" type="submit">Lire et publier</button>
      </form>
    </section>

    <h2 style="margin:0"><?= count($pending) ?> demande<?= count($pending) > 1 ? 's' : '' ?> à valider</h2>
    <?php if (!$pending): ?><p class="meta" style="margin:0">Aucune demande en attente.</p><?php endif; ?>
    <?php foreach ($pending as $r): ?>
      <section class="acard">
        <div class="arow" style="justify-content:space-between">
          <div class="arow">
            <?php if ($r['image'] !== ''): ?><img class="athumb" src="<?= e($r['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
            <div><h2><?= e($r['name'] ?: parse_url($r['url'], PHP_URL_HOST)) ?></h2>
              <span class="plat"><?= e(annuaire_ext_platform_label($r['platform'])) ?> · reçue le <?= e($dt($r['created'])) ?></span></div>
          </div>
          <a class="btn small ghost" href="<?= e($r['url']) ?>" target="_blank" rel="noopener noreferrer">Ouvrir le site</a>
        </div>
        <dl class="d">
          <dt>Site</dt><dd><?= e($r['url']) ?></dd>
          <dt>Contact</dt><dd><?= e($r['contact_name']) ?> · <a href="mailto:<?= e($r['contact_email']) ?>"><?= e($r['contact_email']) ?></a></dd>
          <?php if ($r['tagline'] !== ''): ?><dt>Accroche</dt><dd><?= e($r['tagline']) ?></dd><?php endif; ?>
          <?php if ($r['description'] !== ''): ?><dt>Description</dt><dd><?= e($r['description']) ?></dd><?php endif; ?>
          <?php if ($r['address'] !== ''): ?><dt>Adresse</dt><dd><?= e($r['address']) ?></dd><?php endif; ?>
          <?php if ($r['phone'] !== ''): ?><dt>Téléphone</dt><dd><?= e($r['phone']) ?></dd><?php endif; ?>
          <?php if ($r['hours'] !== ''): ?><dt>Horaires</dt><dd><?= e($r['hours']) ?></dd><?php endif; ?>
          <?php if ($r['universes']): ?><dt>Univers</dt><dd><?= e(implode(', ', $r['universes'])) ?></dd><?php endif; ?>
          <?php if ($r['socials']): ?><dt>Réseaux</dt><dd><?= e(implode(' · ', $r['socials'])) ?></dd><?php endif; ?>
        </dl>
        <form method="post" class="arow">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= e($r['id']) ?>">
          <input type="text" name="note" maxlength="400" placeholder="Motif du refus (facultatif)" style="flex:1;min-width:220px">
          <button class="btn small" type="submit" name="action" value="approve">Publier</button>
          <button class="btn small ghost" type="submit" name="action" value="reject">Refuser</button>
          <button class="btn small ghost" type="submit" name="action" value="refresh">Relire le site</button>
        </form>
      </section>
    <?php endforeach; ?>

    <h2 style="margin:12px 0 0">Publiées (<?= count($approved) ?>)</h2>
    <?php foreach ($approved as $r): ?>
      <section class="acard">
        <div class="arow" style="justify-content:space-between">
          <div class="arow">
            <?php if ($r['logo'] !== ''): ?><img class="athumb" src="<?= e($r['logo']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
            <div><h2><?= e($r['name']) ?></h2>
              <span class="plat"><?= e(annuaire_ext_platform_label($r['platform'])) ?> · publiée le <?= e($dt($r['reviewed'])) ?></span></div>
          </div>
          <a class="btn small ghost" href="<?= e($r['url']) ?>" target="_blank" rel="noopener noreferrer">Ouvrir le site</a>
        </div>
        <div class="arow">
          <form method="post" class="arow">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <input type="hidden" name="action" value="attach">
            <select name="gallery">
              <option value="">Aucune galerie</option>
              <?php foreach ($galleries as $g): ?><option value="<?= e($g['slug']) ?>"<?= $r['gallery'] === $g['slug'] ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn small" type="submit">Rattacher aux promos</button>
          </form>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="action" value="refresh"><button class="btn small ghost" type="submit">Relire le site</button></form>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="action" value="delete"><button class="btn small ghost" type="submit">Retirer</button></form>
        </div>
      </section>
    <?php endforeach; ?>

    <?php if ($rejected): ?>
      <h2 style="margin:12px 0 0">Refusées (<?= count($rejected) ?>)</h2>
      <?php foreach ($rejected as $r): ?>
        <section class="acard">
          <div class="arow" style="justify-content:space-between">
            <div><h2><?= e($r['name'] ?: $r['url']) ?></h2><span class="plat">refusée le <?= e($dt($r['reviewed'])) ?><?= $r['note'] !== '' ? ' · ' . e($r['note']) : '' ?></span></div>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="action" value="delete"><button class="btn small ghost" type="submit">Supprimer</button></form>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>