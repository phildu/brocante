<?php
// Horaires d'ouverture, fermetures exceptionnelles (jours fériés, congés) et alerte aux visiteurs d'une galerie (includes/gallery-hours.php).
// Accès : exploitant, ou administrateur de CETTE galerie (voir _galerie-layout.php). Fonctionnalité « horaires » du type de galerie.
// L'alerte n'est jamais automatique : l'organisateur choisit le public (abonnés, membres fidélité, exposants), relit le message et confirme.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-hours.php';

$q = $limited ? '' : '?g=' . urlencode($gaSlug);
$self = '/portail/galerie-horaires.php' . $q;
if (!gh_enabled($gaGallery)) {
    portail_flash('Les horaires ne sont pas proposés pour ce type de galerie : l’exploitant peut les activer dans Types de galerie.', 'error');
    header('Location: /portail/galerie-tableau.php' . $q);
    exit;
}
$data = gh_read($gaSlug);
$alertKey = (string) ($_GET['alerte'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $actor = (string) (($_SESSION['gallery_admin']['email'] ?? '') ?: 'exploitant');
    $anchor = '';
    if ($action === 'save') {
        $schedule = [];
        foreach (array_keys(SHOP_HOURS_DAYS) as $day) {
            $row = $_POST['hours'][$day] ?? [];
            $schedule[$day] = [
                'closed' => !empty($row['closed']),
                'from' => is_array($row['from'] ?? null) ? array_values($row['from']) : [],
                'to' => is_array($row['to'] ?? null) ? array_values($row['to']) : [],
            ];
        }
        $closures = [];
        foreach ((array) ($_POST['closures'] ?? []) as $row) {
            if (is_array($row) && (trim((string) ($row['from'] ?? '')) !== '' || trim((string) ($row['to'] ?? '')) !== '')) $closures[] = $row;
        }
        $known = array_map('closure_alert_key', $data['closures']);
        $ok = gh_save($gaSlug, $schedule, $closures, (string) ($_POST['notice'] ?? ''));
        if (!$ok) {
            portail_flash("Impossible d'enregistrer les horaires (droits du dossier data).", 'error');
        } else {
            $new = gh_new_pending($gaSlug, $known);
            portail_flash('Horaires et fermetures enregistrés.' . ($new
                ? ' ' . (count($new) > 1 ? count($new) . ' nouvelles fermetures à venir' : 'Nouvelle fermeture à venir') . ' : la page publique l’annonce déjà ; vous pouvez aussi prévenir vos visiteurs ci-dessous.'
                : ''), 'ok');
            $anchor = '#alertes';
        }
    } elseif ($action === 'reset') {
        $ok = gh_save($gaSlug, null, [], '');
        portail_flash($ok ? 'Horaires retirés : la page publique n’affiche plus ni horaires ni fermetures.' : "Impossible d'enregistrer (droits du dossier data).", $ok ? 'ok' : 'error');
    } elseif ($action === 'send_alert') {
        $key = (string) ($_POST['closure'] ?? '');
        $r = gh_alert_send($gaGallery, $key, array_map('strval', (array) ($_POST['groups'] ?? [])), (string) ($_POST['subject'] ?? ''), (string) ($_POST['body'] ?? ''), $actor, !empty($_POST['force']));
        if ($r['error']) {
            portail_flash($r['error'], 'error');
            header('Location: ' . $self . ($limited ? '?' : '&') . 'alerte=' . rawurlencode($key));
            exit;
        }
        portail_flash('Alerte envoyée à ' . (int) $r['sent'] . ' personne(s)' . ($r['failed'] ? ', ' . (int) $r['failed'] . ' en échec' : '')
            . ($r['skipped'] ? '. ' . (int) $r['skipped'] . ' destinataire(s) au-delà de ' . GH_MAX_RECIPIENTS . ' n’ont pas été prévenus (un envoi est limité à ' . GH_MAX_RECIPIENTS . ')' : '') . '.', $r['failed'] || $r['skipped'] ? 'error' : 'ok');
        $anchor = '#alertes';
    }
    header('Location: ' . $self . $anchor);
    exit;
}

$hidden = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">');
$alertUrl = static fn (string $key): string => $self . ($limited ? '?' : '&') . 'alerte=' . rawurlencode($key);
$closure = $alertKey !== '' ? gh_closure_find($gaSlug, $alertKey) : null;
if ($alertKey !== '' && (!$closure || !closure_alert_is_upcoming($closure))) {
    portail_flash($closure ? 'Cette fermeture est terminée : il n’y a plus rien à annoncer.' : 'Fermeture introuvable : enregistrez-la d’abord dans les horaires.', 'error');
    header('Location: ' . $self);
    exit;
}
$schedule = gh_editor_schedule($gaGallery);
$publicUrl = '/galerie/' . $gaGallery['slug'] . '/' . ($gaGallery['published'] ? '' : '?apercu=' . gallery_preview_token($gaGallery));
$summary = (string) gh_with($gaGallery, static fn () => shop_hours_summary_text());
$upcoming = gh_upcoming_closures($gaGallery, 50);
ga_head('Horaires');
?>
    <style>
      .gh-week { display: flex; flex-direction: column; gap: 8px; }
      .gh-day { display: grid; grid-template-columns: 100px 80px 1fr auto; gap: 10px; align-items: center; }
      .gh-day-name { font-weight: 600; }
      .gh-ranges { display: flex; flex-wrap: wrap; gap: 8px; }
      .gh-range { display: inline-flex; align-items: center; gap: 6px; }
      .gh-range input[type="time"] { width: 120px; }
      .gh-closure { display: grid; grid-template-columns: repeat(4, minmax(110px, 1fr)) auto; gap: 8px; align-items: end; margin-bottom: 8px; }
      .gh-closure label { display: flex; flex-direction: column; gap: 4px; font-size: .78rem; }
      .gh-groups { display: grid; gap: 8px; margin: 8px 0; }
      .gh-groups label { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; background: var(--bg); }
      textarea.gh-text { width: 100%; min-height: 200px; }
      @media (max-width: 720px) { .gh-day, .gh-closure { grid-template-columns: 1fr; align-items: stretch; } }
    </style>

    <?php if ($closure): $msg = gh_alert_message($gaGallery, $closure); $aud = gh_audience($gaGallery); $prev = $data['alerts'][$alertKey] ?? null; $type = SHOP_HOURS_CLOSURE_TYPES[$closure['type']] ?? 'Fermeture exceptionnelle'; ?>
    <section class="gcard">
      <h2>Prévenir les visiteurs</h2>
      <p class="meta" style="margin:0;"><strong><?= e($type) ?></strong> · <?= e(closure_alert_range_text($closure)) ?><?= $closure['label'] !== '' ? ' · ' . e($closure['label']) : '' ?></p>
      <p class="meta" style="margin:0;">La page publique de la galerie annonce déjà cette fermeture. Ce message prévient en plus, par e-mail, les personnes que vous choisissez.</p>
      <?php if ($prev): ?><p class="flash" data-kind="error" style="margin:0;">Les visiteurs ont déjà été prévenus le <strong><?= e(date('d/m/Y à H:i', (int) $prev['at'])) ?></strong> (<?= (int) $prev['sent'] ?> personne(s)). Cochez « Renvoyer quand même » pour envoyer de nouveau.</p><?php endif; ?>
      <form method="post" id="gh-alert"><?= $hidden ?>
        <input type="hidden" name="action" value="send_alert"><input type="hidden" name="closure" value="<?= e($alertKey) ?>">
        <h3>Qui prévenir ?</h3>
        <div class="gh-groups">
          <?php foreach ($aud['groups'] as $gk => $grp): ?>
            <label><input type="checkbox" name="groups[]" value="<?= e($gk) ?>" data-count="<?= (int) $grp['count'] ?>"<?= $grp['count'] > 0 ? ' checked' : '' ?>>
              <span><strong><?= e($grp['label']) ?></strong> — <?= (int) $grp['count'] ?> personne(s)</span></label>
          <?php endforeach; ?>
        </div>
        <p class="meta" style="margin:0;">Une même adresse ne reçoit qu’un seul message. Les abonnés et les membres de la fidélité ont un lien pour se désinscrire (ajouté automatiquement) ; les exposants reçoivent le lien de leur espace. <?= (int) $aud['unique'] ?> adresse(s) distincte(s) au total, <?= GH_MAX_RECIPIENTS ?> au plus par envoi.</p>
        <h3>Message</h3>
        <label class="f">Objet<input type="text" name="subject" maxlength="150" value="<?= e($msg['subject']) ?>"></label>
        <label class="f">Texte<textarea class="gh-text" name="body" maxlength="5000"><?= e($msg['body']) ?></textarea></label>
        <p class="meta" style="margin:0;">Variable : <code>{prenom}</code> (« Bonjour, » quand le nom est inconnu). Le nom de la galerie, son adresse et le lien de désinscription sont ajoutés en bas du message.</p>
        <?php if ($prev): ?><label class="f"><span><input type="checkbox" name="force" value="1"> Renvoyer quand même</span></label><?php endif; ?>
        <p class="row"><button class="btn btn-primary" type="submit">Envoyer l’alerte</button> <a class="btn" href="<?= e($self) ?>#alertes">Annuler</a></p>
      </form>
    </section>
    <script>
    (function () {
      var form = document.getElementById('gh-alert');
      form.addEventListener('submit', function (ev) {
        var n = 0;
        form.querySelectorAll('input[name="groups[]"]:checked').forEach(function (c) { n += parseInt(c.dataset.count, 10) || 0; });
        if (!n) { alert('Choisissez au moins un public à prévenir.'); ev.preventDefault(); return; }
        if (!confirm('Envoyer cette alerte à ' + n + ' personne(s) au plus ?\nLes messages partent tout de suite et ne peuvent pas être rappelés.')) ev.preventDefault();
      });
    })();
    </script>

    <?php else: ?>
    <form method="post" class="gcard" autocomplete="off"><?= $hidden ?><input type="hidden" name="action" value="save">
      <h2>Horaires d’ouverture</h2>
      <p class="meta" style="margin:0;">Un créneau par plage horaire : ajoutez-en un second pour une coupure le midi. Cochez « Fermé » pour un jour sans ouverture. Les horaires s’affichent sur la page publique de la galerie<?= $summary !== '' ? ' (aujourd’hui : ' . e($summary) . ')' : '' ?>.</p>
      <div class="gh-week">
        <?php foreach (SHOP_HOURS_DAYS as $dayKey => $dayLabel): $day = $schedule[$dayKey] ?? ['closed' => true, 'ranges' => []]; $ranges = $day['ranges'] ?: [['from' => '', 'to' => '']]; ?>
          <div class="gh-day" data-day-row>
            <span class="gh-day-name"><?= e($dayLabel) ?></span>
            <label><input type="checkbox" name="hours[<?= e($dayKey) ?>][closed]" value="1"<?= $day['closed'] ? ' checked' : '' ?>> Fermé</label>
            <div class="gh-ranges" data-ranges>
              <?php foreach ($ranges as $range): ?>
                <span class="gh-range">
                  <input type="time" name="hours[<?= e($dayKey) ?>][from][]" value="<?= e($range['from']) ?>" aria-label="Ouverture, <?= e($dayLabel) ?>">
                  <span aria-hidden="true">–</span>
                  <input type="time" name="hours[<?= e($dayKey) ?>][to][]" value="<?= e($range['to']) ?>" aria-label="Fermeture, <?= e($dayLabel) ?>">
                  <button type="button" class="btn small" data-remove-range aria-label="Retirer ce créneau">×</button>
                </span>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn small" data-add-range>+ Créneau</button>
          </div>
        <?php endforeach; ?>
      </div>

      <h2 id="fermetures">Fermetures exceptionnelles, jours fériés et vacances</h2>
      <p class="meta" style="margin:0;">Chaque période fermée est annoncée en haut de la page publique (avec la réouverture). Laissez « Au » vide pour une fermeture d’un seul jour.</p>
      <div data-closures>
        <?php if (!$data['closures']): ?><p class="meta" data-closures-empty>Aucune fermeture enregistrée.</p><?php endif; ?>
        <?php foreach ($data['closures'] as $i => $c): ?>
          <div class="gh-closure" data-closure-row>
            <label>Du<input type="date" name="closures[<?= (int) $i ?>][from]" value="<?= e($c['from']) ?>"></label>
            <label>Au<input type="date" name="closures[<?= (int) $i ?>][to]" value="<?= e($c['to']) ?>"></label>
            <label>Type<select name="closures[<?= (int) $i ?>][type]"><?php foreach (SHOP_HOURS_CLOSURE_TYPES as $tk => $tl): ?><option value="<?= e($tk) ?>"<?= $c['type'] === $tk ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select></label>
            <label>Motif<input type="text" name="closures[<?= (int) $i ?>][label]" maxlength="120" value="<?= e($c['label']) ?>" placeholder="ex. Congés d’automne"></label>
            <button type="button" class="btn small" data-remove-closure aria-label="Retirer cette fermeture">×</button>
          </div>
        <?php endforeach; ?>
      </div>
      <p><button type="button" class="btn small" id="gh-add-closure">+ Ajouter une fermeture</button></p>

      <h2>Message aux visiteurs</h2>
      <label class="f">Message libre (facultatif)<textarea name="notice" rows="2" maxlength="300" placeholder="ex. Le parking est fermé jusqu’au 12 novembre."><?= e($data['notice']) ?></textarea></label>
      <p class="meta" style="margin:0;">S’il est rempli, il remplace le message automatique affiché en haut de la page publique. Laissez vide pour laisser la page annoncer les fermetures.</p>
      <p class="row">
        <button class="btn btn-primary" type="submit">Enregistrer les horaires</button>
        <a class="btn" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">Voir la page publique ↗</a>
        <?php if ($data['schedule'] !== null): ?><button class="btn danger" type="submit" name="action" value="reset" formnovalidate onclick="return confirm('Retirer les horaires, les fermetures et le message ? La page publique n’affichera plus rien.');">Retirer les horaires</button><?php endif; ?>
      </p>
    </form>

    <template id="gh-closure-template">
      <div class="gh-closure" data-closure-row>
        <label>Du<input type="date" name="closures[__i__][from]"></label>
        <label>Au<input type="date" name="closures[__i__][to]"></label>
        <label>Type<select name="closures[__i__][type]"><?php foreach (SHOP_HOURS_CLOSURE_TYPES as $tk => $tl): ?><option value="<?= e($tk) ?>"><?= e($tl) ?></option><?php endforeach; ?></select></label>
        <label>Motif<input type="text" name="closures[__i__][label]" maxlength="120" placeholder="ex. Congés d’automne"></label>
        <button type="button" class="btn small" data-remove-closure aria-label="Retirer cette fermeture">×</button>
      </div>
    </template>

    <section class="gcard" id="alertes">
      <h2>Prévenir les visiteurs d’une fermeture</h2>
      <p class="meta" style="margin:0;">La page publique annonce déjà les fermetures. Vous pouvez en plus <strong>écrire directement</strong> à vos abonnés confirmés, aux membres du programme de fidélité et aux exposants ayant une place attribuée. Rien ne part sans votre confirmation.</p>
      <?php if (!$upcoming): ?>
        <p class="meta">Aucune fermeture à venir. Ajoutez-en une ci-dessus, puis enregistrez.</p>
      <?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th>Période</th><th>Type et motif</th><th>Alerte</th></tr></thead><tbody>
        <?php foreach ($upcoming as $c): $ck = closure_alert_key($c); $sent = $data['alerts'][$ck] ?? null; ?>
          <tr>
            <td><?= e(closure_alert_range_text($c)) ?></td>
            <td><?= e(SHOP_HOURS_CLOSURE_TYPES[$c['type']] ?? 'Fermeture') ?><?= $c['label'] !== '' ? ' · ' . e($c['label']) : '' ?></td>
            <td>
              <?php if ($sent): ?><span class="pill done">Visiteurs prévenus le <?= e(date('d/m/Y', (int) $sent['at'])) ?> (<?= (int) $sent['sent'] ?>)</span> <a class="btn small" href="<?= e($alertUrl($ck)) ?>">Renvoyer…</a>
              <?php else: ?><a class="btn small btn-primary" href="<?= e($alertUrl($ck)) ?>">Prévenir les visiteurs…</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
    <script>
    (function () {
      document.querySelectorAll('[data-add-range]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var list = btn.closest('[data-day-row]').querySelector('[data-ranges]');
          var first = list.querySelector('.gh-range');
          if (!first) return;
          var clone = first.cloneNode(true);
          clone.querySelectorAll('input').forEach(function (input) { input.value = ''; });
          list.appendChild(clone);
        });
      });
      document.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-remove-range]');
        if (remove) {
          var range = remove.closest('.gh-range'), list = range.closest('[data-ranges]');
          if (list.querySelectorAll('.gh-range').length > 1) range.remove();
          else range.querySelectorAll('input').forEach(function (input) { input.value = ''; });
          return;
        }
        var rm = e.target.closest('[data-remove-closure]');
        if (rm) rm.closest('[data-closure-row]').remove();
      });
      var index = Date.now();
      document.getElementById('gh-add-closure').addEventListener('click', function () {
        var html = document.getElementById('gh-closure-template').innerHTML.replace(/__i__/g, 'n' + (index++));
        var empty = document.querySelector('[data-closures-empty]');
        if (empty) empty.remove();
        document.querySelector('[data-closures]').insertAdjacentHTML('beforeend', html);
      });
    })();
    </script>
    <?php endif; ?>
<?php ga_foot(); ?>
