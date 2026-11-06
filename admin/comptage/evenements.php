<?php
require_once __DIR__ . '/../../includes/comptage-layout.php';
cp_admin_init();

/** Lit et valide les champs d'un événement ; retourne [valeurs, erreur]. */
function cp_event_fields_from_post(): array
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $date = trim((string) ($_POST['event_date'] ?? ''));
    if ($name === '') return [null, 'Le nom de l\'événement est obligatoire.'];
    if ($date !== '' && !cp_valid_date($date)) return [null, 'Date invalide.'];
    return [[
        'name' => mb_substr($name, 0, 120),
        'date' => $date !== '' ? $date : null,
        'place' => mb_substr(trim((string) ($_POST['place'] ?? '')), 0, 160),
        'capacity' => max(0, min(1000000, (int) ($_POST['capacity'] ?? 0))),
        'track_exits' => !empty($_POST['track_exits']),
    ], null];
}

$back = static fn(?int $id = null) => '/admin/comptage/evenements.php' . ($id ? '#evt-' . $id : '');

switch (cp_post_action()) {
    case 'create_event': {
        [$f, $err] = cp_event_fields_from_post();
        if ($err) cp_redirect($back(), $err, 'error');
        $id = cp_event_create($f['name'], $f['date'], $f['place'], $f['capacity'], $f['track_exits']);
        cp_set_active_event($id);
        cp_redirect($back($id), 'Événement « ' . $f['name'] . ' » créé et activé, avec un poste « Entrée principale ». Ajoutez d\'autres postes si le site a plusieurs entrées.');
    }

    case 'update_event': {
        $id = (int) ($_POST['id'] ?? 0);
        [$f, $err] = cp_event_fields_from_post();
        if (!cp_event_get($id)) cp_redirect($back(), 'Événement introuvable.', 'error');
        if ($err) cp_redirect($back($id), $err, 'error');
        db()->prepare('UPDATE cp_events SET name = ?, event_date = ?, place = ?, capacity = ?, track_exits = ? WHERE id = ?')
            ->execute([$f['name'], $f['date'], $f['place'], $f['capacity'], $f['track_exits'] ? 1 : 0, $id]);
        cp_redirect($back($id), 'Événement mis à jour. Les téléphones qui comptent proposeront de recharger la page.');
    }

    case 'activate_event': {
        $id = (int) ($_POST['id'] ?? 0);
        if (cp_event_get($id)) cp_set_active_event($id);
        cp_redirect($back($id), 'Événement activé : c\'est celui que les bénévoles comptent désormais.');
    }

    case 'delete_event': {
        cp_event_delete((int) ($_POST['id'] ?? 0));
        cp_redirect($back(), 'Événement supprimé avec ses postes et tous ses comptages.');
    }

    case 'add_gate': {
        $id = (int) ($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 60);
        if (!cp_event_get($id) || $name === '') cp_redirect($back($id), 'Donnez un nom au poste (ex : « Parking », « Entrée côté église »).', 'error');
        cp_gate_add($id, $name);
        cp_redirect($back($id), 'Poste « ' . $name . ' » ajouté.');
    }

    case 'rename_gate': {
        $gateId = (int) ($_POST['gate_id'] ?? 0);
        $id = (int) ($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 60);
        if ($name === '') cp_redirect($back($id), 'Le nom du poste ne peut pas être vide.', 'error');
        cp_gate_rename($gateId, $name);
        cp_redirect($back($id), 'Poste renommé.');
    }

    case 'delete_gate': {
        $gateId = (int) ($_POST['gate_id'] ?? 0);
        $id = (int) ($_POST['id'] ?? 0);
        if (count(cp_gates($id)) <= 1) cp_redirect($back($id), 'Un événement doit garder au moins un poste.', 'error');
        cp_gate_delete($gateId);
        cp_redirect($back($id), 'Poste supprimé avec ses comptages.');
    }
}

$events = cp_events_list();
cp_admin_header('Événements & postes de comptage', 'evenements');
?>
<p class="bv-hint">Un <strong>événement</strong> est un vide-grenier. Un <strong>poste</strong> est un endroit où un bénévole compte : chaque entrée du site, par exemple. Un seul événement est « en cours » à la fois : c'est celui que voient les bénévoles.</p>

<?php foreach ($events as $ev):
    $eid = (int) $ev['id'];
    $gates = cp_gates($eid);
    $tot = cp_totals($eid);
    $byGate = [];
    foreach ($tot['gates'] as $g) $byGate[$g['id']] = $g;
?>
<details class="bv-fold" id="evt-<?= $eid ?>" <?= $ev['is_active'] || count($events) === 1 ? 'open' : '' ?>>
  <summary>
    <strong><?= h($ev['name']) ?></strong>
    <span class="sum-meta"><?= $ev['event_date'] ? h(cp_fr_date($ev['event_date'])) . ' · ' : '' ?><?= count($gates) ?> poste<?= count($gates) > 1 ? 's' : '' ?> · <?= cp_n($tot['in']) ?> entrée<?= $tot['in'] > 1 ? 's' : '' ?></span>
    <?php if ($ev['is_active']): ?><span class="bv-badge ok">en cours</span><?php endif; ?>
  </summary>
  <div class="bv-fold-body">
    <?= cp_form_open('update_event', ['id' => $eid]) ?>
      <div class="bv-form-grid" style="margin-top:14px;">
        <div class="field span-2"><label>Nom</label><input type="text" name="name" value="<?= h($ev['name']) ?>" required maxlength="120"></div>
        <div class="field"><label>Date</label><input type="date" name="event_date" value="<?= h($ev['event_date']) ?>"></div>
        <div class="field"><label>Lieu</label><input type="text" name="place" value="<?= h($ev['place']) ?>" maxlength="160"></div>
        <div class="field"><label>Capacité (0 = pas de jauge)</label><input type="number" name="capacity" min="0" value="<?= (int) $ev['capacity'] ?>"></div>
        <div class="field"><label class="cp-check" style="display:flex;gap:8px;align-items:center;margin-top:24px;"><input type="checkbox" name="track_exits" value="1" <?= $ev['track_exits'] ? 'checked' : '' ?>> Compter aussi les sorties</label></div>
      </div>
      <p class="bv-hint" style="margin-top:8px;">Sans les sorties, on obtient le nombre de visiteurs de la journée. Avec les sorties, on connaît aussi le nombre de personnes présentes en même temps (et le pic) — utile si le site a une capacité limitée.</p>
      <button class="btn-small" type="submit">Enregistrer</button>
    </form>

    <h3 class="bv-h2" style="margin-top:26px;">Postes de comptage</h3>
    <?php foreach ($gates as $g): $c = $byGate[(int) $g['id']] ?? ['in' => 0, 'out' => 0]; ?>
      <div class="cp-gate-row">
        <?= cp_form_open('rename_gate', ['id' => $eid, 'gate_id' => $g['id']]) ?>
          <input type="text" name="name" value="<?= h($g['name']) ?>" maxlength="60" required aria-label="Nom du poste">
          <button class="btn-small" type="submit">Renommer</button>
        </form>
        <span class="bv-muted bv-num"><?= cp_n($c['in']) ?> entrée<?= $c['in'] > 1 ? 's' : '' ?><?= $ev['track_exits'] ? ' · ' . cp_n($c['out']) . ' sortie' . ($c['out'] > 1 ? 's' : '') : '' ?></span>
        <?php if (count($gates) > 1): ?>
        <?= cp_form_open('delete_gate', ['id' => $eid, 'gate_id' => $g['id']], 'Supprimer le poste « ' . $g['name'] . ' » et ses ' . cp_n($c['in'] + $c['out']) . ' comptages ?') ?>
          <button class="btn-small bv-btn-danger" type="submit">Supprimer</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?= cp_form_open('add_gate', ['id' => $eid], '', 'cp-inline') ?>
      <label class="cp-field-wide">Nouveau poste<input type="text" name="name" maxlength="60" placeholder="ex : Parking, Entrée côté église" required></label>
      <button class="btn-small" type="submit">Ajouter un poste</button>
    </form>

    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:26px;padding-top:16px;border-top:1px solid var(--line);">
      <a class="btn-small" href="/admin/comptage/index.php?event=<?= $eid ?>">Voir les résultats</a>
      <?php if (!$ev['is_active']): ?>
        <?= cp_form_open('activate_event', ['id' => $eid]) ?><button class="btn-small" type="submit">Rendre actif</button></form>
      <?php endif; ?>
      <?= cp_form_open('delete_event', ['id' => $eid], 'Supprimer « ' . $ev['name'] . ' », ses postes et ses ' . cp_n($tot['in'] + $tot['out']) . ' comptages ? Cette action est définitive.') ?>
        <button class="btn-small bv-btn-danger" type="submit">Supprimer l'événement</button>
      </form>
    </div>
  </div>
</details>
<?php endforeach; ?>

<div class="bv-card" id="nouvel-evenement" style="margin-top:22px;">
  <h2>Nouvel événement</h2>
  <?= cp_form_open('create_event') ?>
    <div class="bv-form-grid">
      <div class="field span-2"><label>Nom</label><input type="text" name="name" required maxlength="120" placeholder="ex : Vide-grenier de printemps"></div>
      <div class="field"><label>Date</label><input type="date" name="event_date" value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="field"><label>Lieu</label><input type="text" name="place" maxlength="160" placeholder="ex : Place de la mairie"></div>
      <div class="field"><label>Capacité (0 = pas de jauge)</label><input type="number" name="capacity" min="0" value="0"></div>
      <div class="field"><label class="cp-check" style="display:flex;gap:8px;align-items:center;margin-top:24px;"><input type="checkbox" name="track_exits" value="1" checked> Compter aussi les sorties</label></div>
    </div>
    <button class="btn-small" type="submit">Créer et activer</button>
  </form>
</div>
<?php cp_admin_footer();
