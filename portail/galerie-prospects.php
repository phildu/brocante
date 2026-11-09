<?php
// Prospects d'une galerie : commerçants en cours d'inscription, commerces à recruter (carnet de l'extension « Force de vente »), visiteurs abonnés aux nouveautés.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/vente-scope.php';

$self = '/portail/galerie-prospects.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id = (string) ($_POST['id'] ?? '');
    $back = $self;
    switch ($action) {
        case 'remind':
            $err = ga_signup_remind($gaSlug, $id);
            portail_flash($err ?? 'Relance envoyée.', $err ? 'error' : 'ok');
            break;
        case 'sub_send':
            [$n, $err] = ga_sub_broadcast($gaSlug, (string) ($_POST['subject'] ?? ''), (string) ($_POST['body'] ?? ''));
            portail_flash($err ?? "Message envoyé à $n abonné" . ($n > 1 ? 's' : '') . '.', $err ? 'error' : 'ok');
            break;
    }
    header('Location: ' . $back);
    exit;
}

$signups = ga_signups($gaSlug);
$pros = ga_prospects($gaSlug);
$venteOn = vs_gallery_enabled($gaGallery);
$subs = ga_subs($gaSlug);
$subsOk = array_values(array_filter($subs, static fn ($s) => !empty($s['confirmed'])));
$kindLabels = ['draft' => 'inscription commencée', 'pending' => 'demande à valider', 'paid' => 'payée, boutique à créer'];
$hid = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">');
ga_head('Prospects');
?>
    <section class="gcard"><h2>1. Commerçants en cours d'inscription</h2>
      <p class="meta">Ils ont demandé à rejoindre <strong><?= e($gaGallery['name']) ?></strong> sans aller au bout. Une relance par e-mail au plus tous les 3 jours, 3 au total.</p>
      <?php if (!$signups): ?><p class="meta">Aucune inscription en cours pour cette galerie.</p><?php else: ?>
      <table class="t"><thead><tr><th>Contact</th><th>État</th><th>Depuis</th><th class="num">Relances</th><th></th></tr></thead><tbody>
      <?php foreach ($signups as $s): $r = $s['req']; ?>
        <tr><td><?= e($r['email']) ?><br><small class="meta"><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '') . ' ' . ($r['shop_name'] ?? ''))) ?></small></td>
          <td><?= e($kindLabels[$s['kind']] ?? $s['kind']) ?></td><td><?= e(date('d/m/Y', (int) ($r['created'] ?? 0))) ?></td><td class="num"><?= (int) $s['reminders'] ?>/<?= GA_RELANCE_MAX ?></td>
          <td><form method="post"><?= $hid ?><input type="hidden" name="action" value="remind"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
            <button class="btn small" type="submit" <?= $s['can_remind'] ? '' : 'disabled title="' . e($s['reminders'] >= GA_RELANCE_MAX ? 'Relances épuisées' : 'Prochaine relance possible le ' . date('d/m/Y H:i', $s['next_at'])) . '"' ?>>Relancer</button></form></td></tr>
      <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </section>

    <section class="gcard"><h2>2. Commerces à recruter</h2>
      <?php if ($venteOn): ?>
        <p class="meta">Votre liste de commerces à convaincre est dans l'extension <strong>Force de vente</strong> : fiches et champs personnalisés, import d'un fichier Excel, CSV ou base SQLite, tournée sur carte, journal des échanges et messages de recrutement avec lien de désinscription.</p>
        <p class="row"><a class="btn btn-primary" href="<?= e(vs_gallery_url($gaSlug, 'prospects.php')) ?>">Ouvrir la Force de vente</a>
          <a class="btn" href="<?= e(vs_gallery_url($gaSlug, 'prospects-import.php')) ?>">Importer un fichier</a></p>
        <p class="meta"><?= count($pros) ?> commerce<?= count($pros) > 1 ? 's' : '' ?> dans le carnet.</p>
      <?php else: ?>
        <p class="meta">La liste des commerces à recruter (import de fichiers, tournée sur carte, messages de recrutement) fait partie de l'extension <strong>Force de vente</strong>. Elle n'est pas activée pour cette galerie.</p>
        <p><a class="btn btn-primary" href="/portail/galerie-modules.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Voir les extensions de la galerie</a></p>
      <?php endif; ?>
    </section>

    <section class="gcard"><h2>3. Visiteurs abonnés aux nouveautés</h2>
      <p class="meta">Les visiteurs s'inscrivent sur la page publique de la galerie, avec une confirmation par e-mail (double accord) ; chaque message contient un lien de désinscription. Les e-mails des alertes et newsletters des boutiques membres ne sont pas repris ici : leurs abonnés ont consenti auprès de chaque boutique, pas auprès de la galerie.</p>
      <div class="kpis"><div class="kpi"><b><?= count($subsOk) ?></b><span>abonnés confirmés</span></div><div class="kpi"><b><?= count($subs) - count($subsOk) ?></b><span>en attente de confirmation</span></div></div>
      <form method="post" class="gcard" style="background:var(--bg);"><?= $hid ?><input type="hidden" name="action" value="sub_send">
        <h3>Écrire aux abonnés (un message par jour au plus)</h3>
        <label class="f">Objet<input type="text" name="subject" maxlength="150" placeholder="Les nouveautés de la semaine"></label>
        <label class="f">Message<textarea name="body" placeholder="Bonjour, cette semaine dans la galerie…"></textarea></label>
        <p><button class="btn btn-primary" type="submit" <?= $subsOk ? '' : 'disabled' ?> onclick="return confirm('Envoyer ce message à tous les abonnés confirmés ?')">Envoyer à <?= count($subsOk) ?> abonné<?= count($subsOk) > 1 ? 's' : '' ?></button></p></form>
    </section>
<?php ga_foot();
