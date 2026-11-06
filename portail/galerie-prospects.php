<?php
// Prospects d'une galerie : commerçants en cours d'inscription, commerces à recruter (liste libre), visiteurs abonnés aux nouveautés.
require __DIR__ . '/_galerie-layout.php';

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
        case 'pro_add':
            $err = ga_prospect_add($gaSlug, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['note'] ?? ''));
            portail_flash($err ?? 'Prospect ajouté.', $err ? 'error' : 'ok');
            break;
        case 'pro_update':
            $err = ga_prospect_update($gaSlug, $id, ['status' => $_POST['status'] ?? null, 'note' => $_POST['note'] ?? null, 'custom_fields' => $_POST['custom_fields'] ?? []]);
            portail_flash($err ?? 'Prospect mis à jour.', $err ? 'error' : 'ok');
            break;
        case 'pro_delete':
            $err = ga_prospect_update($gaSlug, $id, ['delete' => true]);
            portail_flash($err ?? 'Prospect supprimé.', $err ? 'error' : 'ok');
            break;
        case 'pro_send':
            $err = ga_prospect_send($gaSlug, $id, (string) ($_POST['subject'] ?? ''), (string) ($_POST['body'] ?? ''));
            portail_flash($err ?? 'Message envoyé.', $err ? 'error' : 'ok');
            if ($err) $back = $self . (str_contains($self, '?') ? '&' : '?') . 'ecrire=' . urlencode($id);
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
$subs = ga_subs($gaSlug);
$subsOk = array_values(array_filter($subs, static fn ($s) => !empty($s['confirmed'])));
$compose = null;
foreach ($pros as $p) if ($p['id'] === (string) ($_GET['ecrire'] ?? '')) $compose = $p;
$tpl = ga_prospect_template($gaGallery);
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
      <p><a class="btn" href="/portail/galerie-import.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Importer des contacts (CSV, téléphone, CRM)</a></p>
      <p class="meta">Votre liste de commerces à convaincre. Chaque message contient un lien de désinscription ; un commerce désinscrit n'est plus jamais contacté. Faites-le uniquement avec des professionnels dont vous avez les coordonnées professionnelles.</p>
      <form method="post" class="row" autocomplete="off"><?= $hid ?><input type="hidden" name="action" value="pro_add">
        <label class="f">Commerce<input type="text" name="name" required maxlength="100" placeholder="Boutique de Marie"></label>
        <label class="f">E-mail<input type="email" name="email" maxlength="160" placeholder="contact@exemple.fr"></label>
        <label class="f">Note<input type="text" name="note" maxlength="400" placeholder="rencontrée au marché de…"></label>
        <button class="btn btn-primary" type="submit">Ajouter</button></form>
      <?php if ($compose): ?>
        <form method="post" class="gcard" style="background:var(--bg);"><?= $hid ?><input type="hidden" name="action" value="pro_send"><input type="hidden" name="id" value="<?= e($compose['id']) ?>">
          <h3>Écrire à <?= e($compose['name']) ?> (<?= e($compose['email']) ?>)</h3>
          <label class="f">Objet<input type="text" name="subject" maxlength="150" value="<?= e($tpl['subject']) ?>"></label>
          <label class="f">Message — variables : {nom} {galerie} {lien} ; <strong>{desinscription}</strong> est obligatoire<textarea name="body"><?= e($tpl['body']) ?></textarea></label>
          <p class="row"><button class="btn btn-primary" type="submit">Envoyer</button><a class="btn" href="<?= e($self) ?>">Annuler</a></p></form>
      <?php endif; ?>
      <?php if ($pros): ?>
      <table class="t"><thead><tr><th>Commerce</th><th>Suivi</th><th class="num">Messages</th><th></th></tr></thead><tbody>
      <?php foreach ($pros as $p): $sent = (array) ($p['contacts'] ?? []); $wait = $sent && time() - max($sent) < GA_RELANCE_DELAY; $done = count($sent) >= GA_RELANCE_MAX + 1; ?>
        <tr><td><strong><?= e($p['name']) ?></strong><br><small class="meta"><?= e($p['email'] ?: 'pas d\'e-mail') ?><?= !empty($p['phone']) ? ' · ' . e($p['phone']) : '' ?><?= !empty($p['optout']) ? ' — désinscrit' : '' ?></small>
            <?php foreach (ga_prospect_custom_fields_normalize((array) ($p['custom_fields'] ?? [])) as $label => $value): ?><br><small class="meta"><?= e($label) ?> : <?= e($value) ?></small><?php endforeach; ?></td>
          <td><form method="post" class="row" style="gap:4px;"><?= $hid ?><input type="hidden" name="action" value="pro_update"><input type="hidden" name="id" value="<?= e($p['id']) ?>">
            <select name="status" style="flex:none;min-width:0;"><?php foreach (GA_PROSPECT_STATUS as $k => $l): ?><option value="<?= e($k) ?>"<?= $p['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <input type="text" name="note" value="<?= e($p['note']) ?>" maxlength="400" placeholder="note" style="min-width:120px;">
            <?php foreach (ga_prospect_custom_fields_normalize((array) ($p['custom_fields'] ?? [])) as $label => $value): ?>
              <input type="hidden" name="custom_fields[<?= e((string) $label) ?>][label]" value="<?= e((string) $label) ?>">
              <input type="text" name="custom_fields[<?= e((string) $label) ?>][value]" value="<?= e($value) ?>" maxlength="500" aria-label="<?= e((string) $label) ?>" style="min-width:120px;">
            <?php endforeach; ?>
            <button class="btn small" type="submit">Enregistrer</button></form></td>
          <td class="num"><?= count($sent) ?><?= $sent ? '<br><small class="meta">dernier ' . e(date('d/m', max($sent))) . '</small>' : '' ?></td>
          <td class="row" style="gap:4px;">
            <?php if ($p['email'] !== '' && empty($p['optout']) && !$done): ?><a class="btn small<?= $wait ? '' : ' btn-primary' ?>" href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'ecrire=' . urlencode($p['id'])) ?>" <?= $wait ? 'title="Un message est déjà parti il y a moins de 3 jours"' : '' ?>><?= $sent ? 'Relancer' : 'Écrire' ?></a><?php endif; ?>
            <form method="post" onsubmit="return confirm('Supprimer ce prospect ?')"><?= $hid ?><input type="hidden" name="action" value="pro_delete"><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn small danger" type="submit">Supprimer</button></form></td></tr>
      <?php endforeach; ?></tbody></table>
      <?php else: ?><p class="meta">Aucun prospect dans la liste.</p><?php endif; ?>
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
