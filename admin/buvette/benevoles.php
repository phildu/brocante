<?php
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

$event = bv_active_event();
$self = '/admin/buvette/benevoles.php';

/** Champs d'un créneau depuis le POST ; retourne [valeurs, erreur]. */
function bv_shift_fields_from_post(): array
{
    $role = trim((string) ($_POST['role'] ?? ''));
    $start = (string) ($_POST['start_time'] ?? '');
    $end = (string) ($_POST['end_time'] ?? '');
    if ($role === '') return [null, 'Le poste est obligatoire.'];
    if (!bv_valid_time($start) || !bv_valid_time($end)) return [null, 'Horaires invalides.'];
    if ($end <= $start) return [null, 'L\'heure de fin doit être après l\'heure de début.'];
    return [[
        'role' => mb_substr($role, 0, 80), 'start' => $start, 'end' => $end,
        'needed' => max(1, min(50, (int) ($_POST['needed'] ?? 1))),
        'notes' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 300),
    ], null];
}

/** Vérifie qu'un créneau appartient bien à l'événement actif. */
function bv_shift_of_event(int $shiftId, ?array $event): bool
{
    if (!$event) return false;
    $s = db()->prepare('SELECT COUNT(*) FROM bv_shifts WHERE id = ? AND event_id = ?');
    $s->execute([$shiftId, $event['id']]);
    return (bool) $s->fetchColumn();
}

switch (bv_post_action()) {
    case 'add_shift': {
        if (!$event) bv_redirect($self, 'Créez d\'abord un événement.', 'error');
        [$f, $err] = bv_shift_fields_from_post();
        if ($err) bv_redirect($self . '#ajouter-creneau', $err, 'error');
        bv_add_shift((int) $event['id'], $f['role'], $f['start'], $f['end'], $f['needed'], $f['notes']);
        bv_redirect($self, 'Créneau ajouté.');
    }

    case 'update_shift': {
        $id = (int) ($_POST['id'] ?? 0);
        if (!bv_shift_of_event($id, $event)) bv_redirect($self, 'Créneau introuvable.', 'error');
        [$f, $err] = bv_shift_fields_from_post();
        if ($err) bv_redirect($self, $err, 'error');
        db()->prepare('UPDATE bv_shifts SET role = ?, start_time = ?, end_time = ?, needed = ?, notes = ? WHERE id = ?')
            ->execute([$f['role'], $f['start'], $f['end'], $f['needed'], $f['notes'], $id]);
        bv_redirect($self . '#shift-' . $id, 'Créneau enregistré.');
    }

    case 'delete_shift': {
        $id = (int) ($_POST['id'] ?? 0);
        if (bv_shift_of_event($id, $event)) db()->prepare('DELETE FROM bv_shifts WHERE id = ?')->execute([$id]);
        bv_redirect($self, 'Créneau supprimé.');
    }

    case 'assign': {
        $id = (int) ($_POST['id'] ?? 0);
        if (!bv_shift_of_event($id, $event)) bv_redirect($self, 'Créneau introuvable.', 'error');
        bv_shift_assign($id, (array) ($_POST['volunteers'] ?? []));
        bv_redirect($self . '#shift-' . $id, 'Équipe du créneau enregistrée.');
    }

    case 'load_default_shifts': {
        if (!$event) bv_redirect($self, 'Créez d\'abord un événement.', 'error');
        $n = bv_load_default_shifts((int) $event['id']);
        bv_redirect($self, $n . ' créneaux types ajoutés : adaptez les horaires et les effectifs.');
    }

    case 'add_volunteer':
    case 'update_volunteer': {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') bv_redirect($self . '#annuaire', 'Le nom du bénévole est obligatoire.', 'error');
        $vals = [mb_substr($name, 0, 80), mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 30),
            mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 120), mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 300)];
        if ($_POST['action'] === 'add_volunteer') {
            db()->prepare('INSERT INTO bv_volunteers (name, phone, email, notes) VALUES (?, ?, ?, ?)')->execute($vals);
            bv_redirect($self . '#annuaire', $name . ' ajouté(e) à l\'annuaire.');
        }
        db()->prepare('UPDATE bv_volunteers SET name = ?, phone = ?, email = ?, notes = ?, is_active = ? WHERE id = ?')
            ->execute([...$vals, !empty($_POST['is_active']) ? 1 : 0, (int) ($_POST['id'] ?? 0)]);
        bv_redirect($self . '#annuaire', $name . ' : fiche enregistrée.');
    }

    case 'delete_volunteer': {
        db()->prepare('DELETE FROM bv_volunteers WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        bv_redirect($self . '#annuaire', 'Bénévole supprimé de l\'annuaire et de tous les plannings.');
    }
}

bv_admin_header('Bénévoles & planning', 'benevoles');

$volunteers = bv_volunteers_list(false);
$activeVols = array_values(array_filter($volunteers, fn($v) => $v['is_active']));
$shifts = $event ? bv_shifts((int) $event['id']) : [];
$cov = bv_shift_coverage($shifts);
$conflicts = bv_shift_conflicts($shifts);
$conflictShiftIds = [];
foreach ($conflicts as $c) {
    $conflictShiftIds[(int) $c['a']['id']][$c['volunteer_id']] = true;
    $conflictShiftIds[(int) $c['b']['id']][$c['volunteer_id']] = true;
}
// Nombre de créneaux par bénévole sur l'événement en cours
$load = [];
foreach ($shifts as $s) foreach ($s['volunteers'] as $vid => $_) $load[$vid] = ($load[$vid] ?? 0) + 1;
?>
<p class="bv-hint">Construisez le planning de l'événement en cours : définissez les créneaux (poste, horaires, nombre de personnes), puis placez les bénévoles. Les trous et les doubles réservations sont signalés.</p>
<p class="bv-hint bv-noprint">Bénévole non adhérent ? Faites-lui signer la <a href="/admin/buvette/charte.php">charte du bénévole occasionnel</a> (fiche A4 à imprimer) : elle atteste de sa présence et de sa couverture par l'assurance de l'association.</p>

<?php if (!$event): ?>
  <p class="bv-callout">Créez d'abord un événement depuis le <a href="/admin/buvette/index.php#nouvel-evenement">tableau de bord</a>.</p>
<?php else: ?>

<?php if ($shifts): ?>
  <div class="bv-stats">
    <div class="bv-stat <?= $cov['filled'] >= $cov['needed'] ? 'is-good' : '' ?>">
      <div class="label">Postes pourvus</div><div class="value"><?= $cov['filled'] ?> / <?= $cov['needed'] ?></div>
      <div class="sub"><?= $cov['gaps'] ? $cov['gaps'] . ' créneau' . ($cov['gaps'] > 1 ? 'x' : '') . ' à compléter' : 'Planning complet' ?></div>
    </div>
    <div class="bv-stat"><div class="label">Bénévoles mobilisés</div><div class="value"><?= count($load) ?></div><div class="sub">sur <?= count($activeVols) ?> dans l'annuaire</div></div>
  </div>
  <?php foreach ($conflicts as $c): ?>
    <p class="bv-callout"><strong><?= h($c['volunteer']) ?></strong> est placé(e) en même temps sur « <?= h($c['a']['role']) ?> » (<?= h($c['a']['start_time']) ?>–<?= h($c['a']['end_time']) ?>) et « <?= h($c['b']['role']) ?> » (<?= h($c['b']['start_time']) ?>–<?= h($c['b']['end_time']) ?>).</p>
  <?php endforeach; ?>
  <div class="bv-actions bv-noprint" style="margin:0 0 12px;"><button class="btn btn-ghost" type="button" onclick="window.print()">Imprimer le planning</button></div>
<?php endif; ?>

<?php if (!$shifts): ?>
  <div class="bv-card">
    <h2>Aucun créneau pour l'instant</h2>
    <p class="bv-hint">Partez d'un planning type de vide-grenier (installation le matin, caisse / service / cuisine par tranches de 3 heures, rangement le soir), puis ajustez horaires et effectifs.</p>
    <div class="bv-actions"><?= bv_form_open('load_default_shifts') ?><button class="btn btn-primary" type="submit">Ajouter les créneaux types</button></form></div>
  </div>
<?php endif; ?>

<?php foreach ($shifts as $s):
    $sid = (int) $s['id'];
    $state = $s['missing'] > 0 ? 'is-gap' : 'is-full';
    ?>
  <details class="bv-shift <?= $state ?>" id="shift-<?= $sid ?>">
    <summary class="bv-shift-head" style="cursor:pointer;list-style:none;">
      <span class="bv-shift-time"><?= h($s['start_time']) ?>–<?= h($s['end_time']) ?></span>
      <span class="bv-shift-role"><?= h($s['role']) ?></span>
      <span class="bv-shift-people">
        <?php if ($s['volunteers']): ?>
          <?= h(implode(', ', $s['volunteers'])) ?>
        <?php else: ?><span class="bv-muted">personne</span><?php endif; ?>
      </span>
      <?php if ($s['missing'] > 0): ?><span class="bv-badge warn">manque <?= (int) $s['missing'] ?></span>
      <?php else: ?><span class="bv-badge ok">complet <?= count($s['volunteers']) ?>/<?= (int) $s['needed'] ?></span><?php endif; ?>
      <?php if (!empty($conflictShiftIds[$sid])): ?><span class="bv-badge bad">conflit</span><?php endif; ?>
    </summary>
    <div class="bv-shift-body">
      <?php if ($s['notes']): ?><p class="bv-muted" style="margin:12px 0 0;"><?= h($s['notes']) ?></p><?php endif; ?>
      <?= bv_form_open('assign', ['id' => $sid]) ?>
        <p class="bv-hint" style="margin:14px 0 0;">Qui est présent sur ce créneau ? (<?= (int) $s['needed'] ?> personne<?= $s['needed'] > 1 ? 's' : '' ?> souhaitée<?= $s['needed'] > 1 ? 's' : '' ?>)</p>
        <?php if ($activeVols): ?>
          <div class="bv-vol-pick">
            <?php foreach ($activeVols as $v): ?>
              <label class="bv-check"><input type="checkbox" name="volunteers[]" value="<?= (int) $v['id'] ?>" <?= isset($s['volunteers'][(int) $v['id']]) ? 'checked' : '' ?>>
                <?= h($v['name']) ?><?php if (!empty($load[(int) $v['id']])): ?> <span class="bv-muted">(<?= (int) $load[(int) $v['id']] ?>)</span><?php endif; ?></label>
            <?php endforeach; ?>
          </div>
          <div class="bv-actions"><button class="btn-small" type="submit">Enregistrer l'équipe</button></div>
        <?php else: ?>
          <p class="bv-muted">Aucun bénévole dans l'annuaire : ajoutez-en plus bas.</p>
        <?php endif; ?>
      </form>

      <details style="margin-top:16px;border-top:1px solid var(--line);padding-top:12px;" class="bv-noprint">
        <summary style="cursor:pointer;font-size:0.85rem;font-weight:600;">Modifier le créneau</summary>
        <?= bv_form_open('update_shift', ['id' => $sid]) ?>
          <div class="bv-form-grid" style="margin-top:12px;">
            <div class="field span-2"><label>Poste</label><input type="text" name="role" list="bv-roles" value="<?= h($s['role']) ?>" required maxlength="80"></div>
            <div class="field"><label>Début</label><input type="time" name="start_time" value="<?= h($s['start_time']) ?>" required></div>
            <div class="field"><label>Fin</label><input type="time" name="end_time" value="<?= h($s['end_time']) ?>" required></div>
            <div class="field"><label>Personnes nécessaires</label><input type="number" name="needed" min="1" max="50" value="<?= (int) $s['needed'] ?>"></div>
            <div class="field span-2"><label>Consignes</label><input type="text" name="notes" value="<?= h($s['notes']) ?>" maxlength="300"></div>
          </div>
          <div class="bv-actions"><button class="btn-small" type="submit">Enregistrer</button></div>
        </form>
        <?= bv_form_open('delete_shift', ['id' => $sid], 'Supprimer ce créneau ?', 'bv-inline') ?><div class="bv-actions"><button class="btn-small bv-btn-danger" type="submit">Supprimer le créneau</button></div></form>
      </details>
    </div>
  </details>
<?php endforeach; ?>

<datalist id="bv-roles"><?php foreach (bv_shift_roles() as $r): ?><option value="<?= h($r) ?>"><?php endforeach; ?></datalist>

<div class="bv-card bv-noprint" id="ajouter-creneau" style="margin-top:22px;">
  <h2>Ajouter un créneau</h2>
  <?= bv_form_open('add_shift') ?>
    <div class="bv-form-grid" style="margin-top:12px;">
      <div class="field span-2"><label>Poste</label><input type="text" name="role" list="bv-roles" required maxlength="80" placeholder="Caisse"></div>
      <div class="field"><label>Début</label><input type="time" name="start_time" value="09:00" required></div>
      <div class="field"><label>Fin</label><input type="time" name="end_time" value="12:00" required></div>
      <div class="field"><label>Personnes nécessaires</label><input type="number" name="needed" min="1" max="50" value="2"></div>
      <div class="field span-2"><label>Consignes (optionnel)</label><input type="text" name="notes" maxlength="300"></div>
    </div>
    <div class="bv-actions"><button class="btn btn-primary" type="submit">Ajouter le créneau</button></div>
  </form>
</div>
<?php endif; ?>

<h2 class="bv-cat-title bv-noprint" id="annuaire">Annuaire des bénévoles</h2>
<div class="bv-noprint">
<p class="bv-hint">Les coordonnées ne sont visibles que de vous : les bénévoles ne voient que les prénoms dans le planning.</p>
<?php foreach ($volunteers as $v): ?>
  <details class="bv-fold <?= $v['is_active'] ? '' : 'is-inactive' ?>">
    <summary>
      <span class="sum-main">
        <span class="sum-name"><?= h($v['name']) ?></span>
        <span class="sum-meta"><?= h($v['phone']) ?></span>
      </span>
      <?php if (!empty($load[(int) $v['id']])): ?><span class="bv-badge"><?= (int) $load[(int) $v['id']] ?> créneau<?= $load[(int) $v['id']] > 1 ? 'x' : '' ?></span><?php endif; ?>
      <?php if (!$v['is_active']): ?><span class="bv-badge">inactif</span><?php endif; ?>
    </summary>
    <div class="bv-fold-body">
      <?= bv_form_open('update_volunteer', ['id' => $v['id']]) ?>
        <div class="bv-form-grid" style="margin-top:14px;">
          <div class="field"><label>Nom / prénom</label><input type="text" name="name" value="<?= h($v['name']) ?>" required maxlength="80"></div>
          <div class="field"><label>Téléphone</label><input type="tel" name="phone" value="<?= h($v['phone']) ?>" maxlength="30"></div>
          <div class="field"><label>E-mail</label><input type="email" name="email" value="<?= h($v['email']) ?>" maxlength="120"></div>
          <div class="field span-2"><label>Notes (disponibilités, allergies…)</label><input type="text" name="notes" value="<?= h($v['notes']) ?>" maxlength="300"></div>
        </div>
        <label class="bv-check" style="margin-top:12px;"><input type="checkbox" name="is_active" value="1" <?= $v['is_active'] ? 'checked' : '' ?>> Actif (proposé dans les plannings)</label>
        <div class="bv-actions"><button class="btn-small" type="submit">Enregistrer</button></div>
      </form>
      <?= bv_form_open('delete_volunteer', ['id' => $v['id']], 'Supprimer ' . $v['name'] . ' de l\'annuaire et de tous les plannings ?', 'bv-inline') ?><div class="bv-actions"><button class="btn-small bv-btn-danger" type="submit">Supprimer</button></div></form>
    </div>
  </details>
<?php endforeach; ?>

<div class="bv-card" style="margin-top:14px;">
  <h2>Ajouter un bénévole</h2>
  <?= bv_form_open('add_volunteer') ?>
    <div class="bv-form-grid" style="margin-top:12px;">
      <div class="field"><label>Nom / prénom</label><input type="text" name="name" required maxlength="80"></div>
      <div class="field"><label>Téléphone</label><input type="tel" name="phone" maxlength="30"></div>
      <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="120"></div>
      <div class="field span-2"><label>Notes</label><input type="text" name="notes" maxlength="300"></div>
    </div>
    <div class="bv-actions"><button class="btn btn-primary" type="submit">Ajouter</button></div>
  </form>
</div>
</div>
<?php
bv_admin_footer();
