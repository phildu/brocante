<?php
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

/** Lit et valide les champs communs d'un événement ; retourne [valeurs, erreur]. */
function bv_event_fields_from_post(): array
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $date = trim((string) ($_POST['event_date'] ?? ''));
    if ($name === '') return [null, 'Le nom de l\'événement est obligatoire.'];
    if ($date !== '' && !bv_valid_date($date)) return [null, 'Date invalide.'];
    return [[
        'name' => mb_substr($name, 0, 120),
        'date' => $date !== '' ? $date : null,
        'place' => mb_substr(trim((string) ($_POST['place'] ?? '')), 0, 160),
        'visitors' => max(0, (int) ($_POST['expected_visitors'] ?? 0)),
        'margin' => max(0, min(100, (int) ($_POST['margin_pct'] ?? 10))),
        'notes' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000),
    ], null];
}

switch (bv_post_action()) {
    case 'load_example':
        if (bv_seed_example()) {
            bv_redirect('/admin/buvette/index.php', 'Exemple chargé : carte, recettes, fournitures, planning et check-list. Modifiez tout librement.');
        }
        bv_redirect('/admin/buvette/index.php', 'Des données existent déjà — l\'exemple n\'a pas été chargé pour ne rien écraser.', 'error');

    case 'create_event': {
        [$f, $err] = bv_event_fields_from_post();
        if ($err) bv_redirect('/admin/buvette/index.php#nouvel-evenement', $err, 'error');
        $id = bv_event_create($f['name'], $f['date'], $f['place'], $f['visitors'], $f['margin'], $f['notes']);
        bv_set_active_event($id);
        $extra = [];
        if (!empty($_POST['with_checklist'])) $extra[] = bv_load_default_checklist($id) . ' tâches de la check-list type';
        if (!empty($_POST['with_shifts'])) $extra[] = bv_load_default_shifts($id) . ' créneaux types';
        bv_redirect('/admin/buvette/index.php', 'Événement « ' . $f['name'] . ' » créé et activé' . ($extra ? ' (' . implode(', ', $extra) . ')' : '') . '.');
    }

    case 'update_event': {
        $id = (int) ($_POST['id'] ?? 0);
        [$f, $err] = bv_event_fields_from_post();
        if (!bv_event_get($id)) bv_redirect('/admin/buvette/index.php', 'Événement introuvable.', 'error');
        if ($err) bv_redirect('/admin/buvette/index.php#evenements', $err, 'error');
        db()->prepare('UPDATE bv_events SET name = ?, event_date = ?, place = ?, expected_visitors = ?, margin_pct = ?, notes = ? WHERE id = ?')
            ->execute([$f['name'], $f['date'], $f['place'], $f['visitors'], $f['margin'], $f['notes'], $id]);
        bv_redirect('/admin/buvette/index.php#evenements', 'Événement mis à jour.');
    }

    case 'activate_event': {
        $id = (int) ($_POST['id'] ?? 0);
        if (bv_event_get($id)) bv_set_active_event($id);
        bv_redirect('/admin/buvette/index.php', 'Événement activé : toutes les pages de la buvette travaillent maintenant sur celui-ci.');
    }

    case 'duplicate_event': {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $date = trim((string) ($_POST['event_date'] ?? ''));
        if ($name === '' || ($date !== '' && !bv_valid_date($date))) {
            bv_redirect('/admin/buvette/index.php#evenements', 'Nom ou date invalide pour la copie.', 'error');
        }
        $newId = bv_event_duplicate($id, mb_substr($name, 0, 120), $date !== '' ? $date : null);
        if (!$newId) bv_redirect('/admin/buvette/index.php', 'Événement introuvable.', 'error');
        bv_set_active_event($newId);
        bv_redirect('/admin/buvette/index.php', 'Événement copié et activé : prévisions, créneaux et check-list repris (bénévoles et coches remis à zéro).');
    }

    case 'delete_event': {
        $id = (int) ($_POST['id'] ?? 0);
        bv_event_delete($id);
        bv_redirect('/admin/buvette/index.php#evenements', 'Événement supprimé avec son planning, ses prévisions et sa check-list.');
    }

    case 'set_code': {
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code !== '' && mb_strlen($code) < 4) {
            bv_redirect('/admin/buvette/index.php#acces-benevoles', 'Le code doit faire au moins 4 caractères.', 'error');
        }
        bv_set_setting('volunteer_code', mb_substr($code, 0, 40));
        bv_redirect('/admin/buvette/index.php#acces-benevoles', $code === '' ? 'Espace bénévoles fermé (plus de code).' : 'Code enregistré : communiquez-le aux bénévoles avec le lien.');
    }
}

$events = bv_events_list();
$event = bv_active_event();
$empty = bv_is_empty();

bv_admin_header('Tableau de bord', 'dashboard');

if ($empty): ?>
  <div class="bv-card">
    <h2>Bienvenue dans la buvette</h2>
    <p class="bv-hint">Ce module prépare la buvette d'un vide-grenier de A à Z : la carte et les prix, les recettes et leur coût de revient, les fournitures, les quantités à acheter, le planning des bénévoles et la check-list du jour J.</p>
    <p class="bv-hint">Le plus simple pour démarrer : charger une buvette d'exemple (café, boissons, sandwichs, hot-dogs, crêpes, gâteaux) et la modifier. Ou créer directement un premier événement ci-dessous et tout saisir vous-même.</p>
    <div class="bv-actions">
      <?= bv_form_open('load_example') ?><button class="btn btn-primary" type="submit">Charger la buvette d'exemple</button></form>
      <a class="btn btn-ghost" href="#nouvel-evenement">Partir de zéro</a>
    </div>
  </div>
<?php endif;

if ($event):
    $eid = (int) $event['id'];
    $totals = bv_event_totals($eid);
    $shifts = bv_shifts($eid);
    $cov = bv_shift_coverage($shifts);
    $conflicts = bv_shift_conflicts($shifts);
    $prog = bv_task_progress($eid);
    $noRecipe = bv_items_without_recipe();
    $days = bv_days_until($event['event_date']);
    ?>
  <div class="bv-card">
    <p class="eyebrow">Événement en cours</p>
    <h2 style="font-size:1.6rem;margin:6px 0 4px;"><?= h($event['name']) ?></h2>
    <p style="margin:0;">
      <?php if ($event['event_date']): ?>
        <strong><?= h(bv_fr_date($event['event_date'])) ?></strong> — <span class="<?= $days !== null && $days >= 0 && $days <= 7 ? 'bv-badge warn' : 'bv-muted' ?>"><?= h(bv_countdown_label($event['event_date'])) ?></span>
      <?php else: ?><span class="bv-muted">Date non renseignée</span><?php endif; ?>
      <?php if ($event['place']): ?> · <?= h($event['place']) ?><?php endif; ?>
      <?php if ((int) $event['expected_visitors'] > 0): ?> · <?= (int) $event['expected_visitors'] ?> visiteurs attendus<?php endif; ?>
    </p>
  </div>

  <div class="bv-stats">
    <div class="bv-stat">
      <div class="label">Check-list</div>
      <div class="value"><?= $prog['done'] ?> / <?= $prog['total'] ?></div>
      <div class="bv-progress" role="progressbar" aria-valuenow="<?= $prog['pct'] ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $prog['pct'] ?>%"></span></div>
    </div>
    <div class="bv-stat <?= $cov['needed'] && $cov['filled'] >= $cov['needed'] ? 'is-good' : ($cov['gaps'] ? 'is-bad' : '') ?>">
      <div class="label">Postes pourvus</div>
      <div class="value"><?= $cov['filled'] ?> / <?= $cov['needed'] ?></div>
      <div class="sub"><?= $cov['gaps'] ? $cov['gaps'] . ' créneau' . ($cov['gaps'] > 1 ? 'x' : '') . ' à compléter' : ($cov['needed'] ? 'Planning complet' : 'Aucun créneau') ?></div>
    </div>
    <div class="bv-stat">
      <div class="label">Chiffre d'affaires prévu</div>
      <div class="value"><?= h(bv_money($totals['revenue_forecast'])) ?></div>
      <div class="sub"><?= (int) $totals['portions_forecast'] ?> portions prévues</div>
    </div>
    <div class="bv-stat <?= $totals['margin_forecast'] > 0 ? 'is-good' : '' ?>">
      <div class="label">Marge prévue</div>
      <div class="value"><?= h(bv_money($totals['margin_forecast'])) ?></div>
      <div class="sub">après coût de revient des recettes</div>
    </div>
    <div class="bv-stat">
      <div class="label">Budget courses</div>
      <div class="value"><?= h(bv_money($totals['shopping_cents'])) ?></div>
      <div class="sub"><?= (int) $totals['shopping_lines'] ?> ligne<?= $totals['shopping_lines'] > 1 ? 's' : '' ?> à acheter</div>
    </div>
    <?php if ($totals['has_actual']): ?>
    <div class="bv-stat <?= $totals['margin_actual'] > 0 ? 'is-good' : 'is-bad' ?>">
      <div class="label">Marge réelle</div>
      <div class="value"><?= h(bv_money($totals['margin_actual'])) ?></div>
      <div class="sub">sur <?= h(bv_money($totals['revenue_actual'])) ?> encaissés</div>
    </div>
    <?php endif; ?>
  </div>

  <?php
    $todo = [];
    if ($cov['gaps']) $todo[] = '<strong>' . $cov['gaps'] . ' créneau' . ($cov['gaps'] > 1 ? 'x' : '') . '</strong> sans assez de bénévoles — <a href="/admin/buvette/benevoles.php">compléter le planning</a>';
    if ($conflicts) $todo[] = '<strong>' . count($conflicts) . ' bénévole' . (count($conflicts) > 1 ? 's' : '') . '</strong> placé' . (count($conflicts) > 1 ? 's' : '') . ' sur deux créneaux en même temps — <a href="/admin/buvette/benevoles.php">voir</a>';
    if (!$cov['needed']) $todo[] = 'Aucun créneau défini — <a href="/admin/buvette/benevoles.php">créer le planning</a>';
    if ($noRecipe) $todo[] = '<strong>' . count($noRecipe) . ' article' . (count($noRecipe) > 1 ? 's' : '') . '</strong> de la carte sans recette (coût de revient inconnu) — <a href="/admin/buvette/recettes.php">compléter</a>';
    if ($totals['portions_forecast'] === 0) $todo[] = 'Aucune quantité prévue — <a href="/admin/buvette/previsionnel.php">saisir le prévisionnel</a>';
    if (!bv_volunteer_code()) $todo[] = 'L\'espace bénévoles est fermé — <a href="#acces-benevoles">définir un code d\'accès</a>';
    if ($prog['total'] === 0) $todo[] = 'Check-list vide — <a href="/admin/buvette/checklist.php">charger la check-list type</a>';
    ?>
  <div class="bv-card">
    <h2>À traiter</h2>
    <?php if ($todo): ?>
      <ul class="bv-todo"><?php foreach ($todo as $li): ?><li><span aria-hidden="true">•</span><span><?= $li ?></span></li><?php endforeach; ?></ul>
    <?php else: ?>
      <p class="bv-muted" style="margin:8px 0 0;">Rien en attente : planning complet, recettes renseignées, prévisions saisies.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="bv-card" id="evenements">
  <h2>Événements</h2>
  <p class="bv-hint">Chaque vide-grenier est un événement avec ses prévisions, son planning et sa check-list. La carte, les recettes et les fournitures sont communes à tous. Un seul événement est « en cours » : c'est celui sur lequel travaillent les autres pages.</p>
  <?php if (!$events): ?>
    <p class="bv-muted">Aucun événement pour l'instant.</p>
  <?php endif; ?>
  <?php foreach ($events as $ev): $isActive = $event && (int) $ev['id'] === (int) $event['id']; ?>
    <details class="bv-fold" <?= $isActive ? 'open' : '' ?>>
      <summary>
        <span class="sum-main">
          <span class="sum-name"><?= h($ev['name']) ?></span>
          <span class="sum-meta"><?= h(bv_fr_date($ev['event_date'], false)) ?><?= $ev['place'] ? ' · ' . h($ev['place']) : '' ?></span>
        </span>
        <?= $isActive ? '<span class="bv-badge ok">en cours</span>' : '' ?>
      </summary>
      <div class="bv-fold-body">
        <?= bv_form_open('update_event', ['id' => $ev['id']]) ?>
          <div class="bv-form-grid" style="margin-top:14px;">
            <div class="field span-2"><label>Nom</label><input type="text" name="name" value="<?= h($ev['name']) ?>" required maxlength="120"></div>
            <div class="field"><label>Date</label><input type="date" name="event_date" value="<?= h($ev['event_date']) ?>"></div>
            <div class="field"><label>Lieu</label><input type="text" name="place" value="<?= h($ev['place']) ?>" maxlength="160"></div>
            <div class="field"><label>Visiteurs attendus</label><input type="number" name="expected_visitors" min="0" value="<?= (int) $ev['expected_visitors'] ?>"></div>
            <div class="field"><label>Marge de sécurité (%)</label><input type="number" name="margin_pct" min="0" max="100" value="<?= (int) $ev['margin_pct'] ?>"></div>
            <div class="field span-2"><label>Notes</label><input type="text" name="notes" value="<?= h($ev['notes']) ?>" maxlength="2000"></div>
          </div>
          <p class="bv-hint" style="margin-top:8px;">La marge de sécurité s'ajoute aux quantités calculées par les recettes (pour la casse, les doubles portions, les gourmands…).</p>
          <div class="bv-actions"><button class="btn-small" type="submit">Enregistrer</button></div>
        </form>
        <div class="bv-actions" style="border-top:1px solid var(--line);padding-top:14px;margin-top:16px;">
          <?php if (!$isActive): ?>
            <?= bv_form_open('activate_event', ['id' => $ev['id']], '', 'bv-inline') ?><button class="btn-small" type="submit">Travailler sur cet événement</button></form>
          <?php endif; ?>
          <?= bv_form_open('delete_event', ['id' => $ev['id']], 'Supprimer « ' . $ev['name'] . ' » avec son planning, ses prévisions et sa check-list ? Cette action est définitive.', 'bv-inline') ?><button class="btn-small bv-btn-danger" type="submit">Supprimer</button></form>
        </div>
        <?= bv_form_open('duplicate_event', ['id' => $ev['id']]) ?>
          <p class="bv-hint" style="margin:16px 0 8px;"><strong>Préparer l'édition suivante</strong> : copie les prévisions, le matériel, les créneaux et la check-list (décochée), sans les bénévoles.</p>
          <div class="bv-form-grid">
            <div class="field span-2"><label>Nom de la copie</label><input type="text" name="name" value="<?= h($ev['name']) ?> (copie)" required maxlength="120"></div>
            <div class="field"><label>Date</label><input type="date" name="event_date"></div>
            <div class="field"><button class="btn-small" type="submit">Copier</button></div>
          </div>
        </form>
      </div>
    </details>
  <?php endforeach; ?>

  <details class="bv-fold" id="nouvel-evenement" <?= $events ? '' : 'open' ?> style="margin-top:14px;">
    <summary><span class="sum-main"><span class="sum-name">+ Nouvel événement</span></span></summary>
    <div class="bv-fold-body">
      <?= bv_form_open('create_event') ?>
        <div class="bv-form-grid" style="margin-top:14px;">
          <div class="field span-2"><label>Nom</label><input type="text" name="name" placeholder="Vide-grenier de printemps" required maxlength="120"></div>
          <div class="field"><label>Date</label><input type="date" name="event_date"></div>
          <div class="field"><label>Lieu</label><input type="text" name="place" placeholder="Place du village" maxlength="160"></div>
          <div class="field"><label>Visiteurs attendus</label><input type="number" name="expected_visitors" min="0" value="300"></div>
          <div class="field"><label>Marge de sécurité (%)</label><input type="number" name="margin_pct" min="0" max="100" value="10"></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;margin-top:14px;">
          <label class="bv-check"><input type="checkbox" name="with_checklist" value="1" checked> Ajouter la check-list type (autorisation, hygiène, installation, rangement…)</label>
          <label class="bv-check"><input type="checkbox" name="with_shifts" value="1" checked> Ajouter des créneaux de bénévoles types (installation, caisse, service, cuisine, rangement)</label>
        </div>
        <div class="bv-actions"><button class="btn btn-primary" type="submit">Créer l'événement</button></div>
      </form>
    </div>
  </details>
</div>

<div class="bv-card" id="acces-benevoles">
  <h2>Accès des bénévoles</h2>
  <p class="bv-hint">Les bénévoles consultent la carte, les recettes, le planning et cochent la check-list depuis leur téléphone, sans compte : il suffit du lien et d'un code commun. Ils ne voient ni les prix d'achat, ni les marges, ni les coordonnées des autres.</p>
  <?php $code = bv_volunteer_code(); $url = bv_volunteer_space_url(); ?>
  <p style="margin:0 0 12px;">Lien à partager :
    <a href="<?= h($url) ?>" target="_blank" style="color:var(--accent);font-weight:600;"><?= h($url) ?></a>
    <?php if ($code === ''): ?><span class="bv-badge warn">fermé : pas de code</span><?php else: ?><span class="bv-badge ok">ouvert</span><?php endif; ?>
  </p>
  <?= bv_form_open('set_code') ?>
    <div class="bv-form-grid">
      <div class="field"><label>Code d'accès (4 caractères minimum)</label><input type="text" name="code" value="<?= h($code) ?>" autocomplete="off" maxlength="40" placeholder="ex : buvette2026"></div>
      <div class="field"><button class="btn-small" type="submit">Enregistrer</button></div>
    </div>
    <p class="bv-hint" style="margin-top:8px;">Laissez vide pour fermer l'espace bénévoles. Changez le code d'une édition à l'autre ; après 5 essais ratés, l'accès est bloqué 15 minutes.</p>
  </form>
</div>
<?php
bv_admin_footer();
