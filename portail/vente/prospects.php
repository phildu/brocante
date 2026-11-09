<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
$gal = fv_is_scoped();      // carnet d'une galerie ou d'une boutique (extension « Force de vente ») : pas d'appels, de mailing ni de pitch de la plateforme
$member = $ctx['member'];
$pdo = fv_db();
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => (string) ($_GET['status'] ?? ''),
    'zone' => trim((string) ($_GET['zone'] ?? '')),
    'source' => (string) ($_GET['source'] ?? ''),
    'owner_id' => (int) ($_GET['owner'] ?? 0),
    'due' => (string) ($_GET['due'] ?? ''),
];
$editId = (int) ($_GET['editer'] ?? 0);
$viewId = (int) ($_GET['id'] ?? 0);
$isNew = isset($_GET['nouveau']);
$members = $ctx['manager'] ? fv_members_list(false) : [];
$zones = fv_zones();

if (isset($_GET['export'])) {
    $rows = fv_prospects_search($filters, 2000);
    // Colonnes du commerce et des réseaux : de quoi alimenter l'audit (scripts/audit-prospects.php) sans ressaisie.
    $custom = static function (array $p, array $labels): string {
        foreach (fv_custom_fields_of($p) as $label => $value) {
            if (in_array(mb_strtolower(trim((string) $label)), $labels, true)) return trim((string) $value);
        }
        return '';
    };
    $csv = [['Commerce', 'Contact', 'Activité', 'Téléphone', 'E-mail', 'Site web', 'Facebook', 'Instagram', 'TikTok', 'YouTube', 'LinkedIn', 'Fiche Google', 'Adresse', 'Code postal', 'Ville', 'Zone', 'Source', 'Statut', 'Statut (code)', 'Priorité', 'Prochaine action', 'Propriétaire', 'Notes']];
    foreach ($rows as $p) {
        $csv[] = [
            $p['business'], $p['name'], $p['kind'], $p['phone'], $p['email'],
            (string) $p['website'] !== '' ? $p['website'] : $custom($p, ['site web', 'site', 'site internet', 'website']),
            $custom($p, ['facebook', 'page facebook']), $custom($p, ['instagram', 'compte instagram']), $custom($p, ['tiktok']),
            $custom($p, ['youtube', 'chaîne youtube', 'chaine youtube']), $custom($p, ['linkedin']),
            $custom($p, ['fiche google', 'google', 'google business', 'google maps']),
            $p['address'], $p['postal'], $p['city'], $p['zone'], fv_source_label($p['source']),
            fv_status_label($p['status']), $p['status'], $p['priority'], $p['next_action_at'] ?? '', $p['owner_name'] ?? '', str_replace("\n", ' ', $p['notes']),
        ];
    }
    fv_csv_download('prospects-' . date('Y-m-d') . '.csv', $csv);
}

$editing = null;
if ($editId > 0) {
    $editing = fv_prospect_get($editId);
    if (!$editing) {
        fv_flash('Ce prospect n’existe pas ou ne vous est pas attribué.', 'error');
        fv_redirect('prospects.php');
    }
}

if ($viewId > 0 && !$editing && !$isNew) {
    $prospect = fv_prospect_get($viewId);
    if (!$prospect) {
        fv_flash('Ce prospect n’existe pas ou ne vous est pas attribué.', 'error');
        fv_redirect('prospects.php');
    }
    $timeline = fv_prospect_activity($prospect);
    $activities = fv_prospect_activity($prospect);
    fv_layout_start($prospect['business'] !== '' ? $prospect['business'] : $prospect['name'], [
        'eyebrow' => 'Prospect · ' . fv_status_label($prospect['status']),
        'actions' => '<a class="fv-btn fv-btn-sm" href="prospects.php">' . fv_icon('arrow', 14) . ' Liste</a>'
            . ($prospect['phone'] !== '' ? '<a class="fv-btn fv-btn-sm fv-btn-primary" href="' . ($gal ? 'tel:' . e($prospect['phone']) : 'appels.php?id=' . (int) $prospect['id']) . '">' . fv_icon('phone', 14) . ' Appeler</a>' : ''),
    ]);
    ?>
    <div class="fv-grid fv-grid-2-1">
      <div class="fv-grid" style="gap:14px;">
        <section class="fv-card">
          <div class="fv-card-head">
            <div>
              <h2><?= e($prospect['business'] !== '' ? $prospect['business'] : $prospect['name']) ?></h2>
              <p><?= e(trim(($prospect['name'] !== '' ? $prospect['name'] : 'Contact inconnu') . ($prospect['kind'] !== '' ? ' · ' . $prospect['kind'] : ''))) ?></p>
            </div>
            <span class="fv-badge" data-s="<?= e($prospect['status']) ?>"><?= e(fv_status_label($prospect['status'])) ?></span>
          </div>
          <div class="fv-card-body">
            <dl class="fv-dl">
              <div><dt>Téléphone</dt><dd><?= $prospect['phone'] !== '' ? '<a href="tel:' . e($prospect['phone']) . '">' . e($prospect['phone']) . '</a>' : '—' ?></dd></div>
              <div><dt>E-mail</dt><dd><?= $prospect['email'] !== '' ? '<a href="mailto:' . e($prospect['email']) . '">' . e($prospect['email']) . '</a>' : '—' ?></dd></div>
              <div><dt>Adresse</dt><dd><?= e(trim($prospect['address'] . ($prospect['postal'] !== '' ? "\n" . $prospect['postal'] : '') . ($prospect['city'] !== '' ? ' ' . $prospect['city'] : '')) ?: '—') ?></dd></div>
              <div><dt>Zone</dt><dd><?= e($prospect['zone'] !== '' ? $prospect['zone'] : '—') ?></dd></div>
              <div><dt>Source</dt><dd><?= e(fv_source_label($prospect['source'])) ?></dd></div>
              <?php if (!$gal): ?><div><dt>Propriétaire</dt><dd><?= e($prospect['owner_name'] ?? 'Direction') ?></dd></div><?php endif; ?>
              <div><dt>Prochaine action</dt><dd><?= e(fv_date($prospect['next_action_at'])) ?> · priorité <?= (int) $prospect['priority'] ?>/3</dd></div>
              <div><dt>Créé le</dt><dd><?= e(fv_datetime($prospect['created_at'])) ?></dd></div>
              <?php foreach (fv_custom_fields_of($prospect) as $cfLabel => $cfValue): ?>
                <div><dt><?= e($cfLabel) ?></dt><dd><?= preg_match('#^https?://\S+$#i', $cfValue) ? '<a href="' . e($cfValue) . '" target="_blank" rel="noopener noreferrer">' . e(preg_replace('#^https?://(www\.)?#i', '', rtrim($cfValue, '/'))) . '</a>' : e($cfValue) ?></dd></div>
              <?php endforeach; ?>
              <?php if ($prospect['shop_slug'] !== ''): ?><div><dt>Boutique</dt><dd><code><?= e($prospect['shop_slug']) ?></code></dd></div><?php endif; ?>
            </dl>
            <?php if (!empty($prospect['optout'])): ?><div class="fv-note"><b>Désinscrit</b> : ce prospect a demandé à ne plus être contacté par e-mail.</div><?php endif; ?>
            <?php if ($prospect['notes'] !== ''): ?><div class="fv-note"><?= nl2br(e($prospect['notes'])) ?></div><?php endif; ?>
            <div class="fv-actions">
              <a class="fv-btn fv-btn-sm" href="prospects.php?editer=<?= (int) $prospect['id'] ?>">Modifier la fiche</a>
              <a class="fv-btn fv-btn-sm" href="tournees.php?ville=<?= e(rawurlencode($prospect['city'])) ?>">Voir dans la tournée</a>
              <?php if ($prospect['email'] !== ''): ?><a class="fv-btn fv-btn-sm" href="mailto:<?= e($prospect['email']) ?>">Écrire</a><?php endif; ?>
              <form method="post" action="prospect-action.php" data-confirm="Supprimer définitivement ce prospect et son historique ?" style="margin-left:auto;">
                <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="id" value="<?= (int) $prospect['id'] ?>">
                <input type="hidden" name="retour" value="prospects.php">
                <button class="fv-btn fv-btn-sm fv-btn-danger" type="submit">Supprimer</button>
              </form>
            </div>
          </div>
        </section>

        <section class="fv-card">
          <div class="fv-card-head"><div><h2>Historique</h2><p>Chaque échange, consigné au même endroit.</p></div></div>
          <div class="fv-card-body" style="padding-top:6px;">
            <?php if (!$timeline): ?>
              <div class="fv-empty"><b>Aucun échange pour le moment</b>Consignez un appel, une visite ou une note à droite.</div>
            <?php else: ?>
              <div class="fv-tl">
                <?php foreach ($timeline as $a): ?>
                  <div class="fv-tl-item" data-k="<?= e($a['kind']) ?>">
                    <span class="fv-tl-dot" aria-hidden="true"></span>
                    <div class="fv-tl-body">
                      <b><?= e(fv_kind_label($a['kind'])) ?><?= $a['outcome'] !== '' ? ' · ' . e(fv_outcome_label($a['kind'], $a['outcome'])) : '' ?></b>
                      <span class="when"><?= e(fv_datetime($a['occurred_at'])) ?><?= $a['member_name'] ? ' · ' . e($a['member_name']) : '' ?><?= (int) $a['duration_min'] > 0 ? ' · ' . (int) $a['duration_min'] . ' min' : '' ?></span>
                      <?php if ($a['notes'] !== ''): ?><p><?= nl2br(e($a['notes'])) ?></p><?php endif; ?>
                      <?php if ((int) $a['points'] > 0): ?><span class="pts">+<?= (int) $a['points'] ?> pts</span><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </div>

      <div class="fv-grid" style="gap:14px;">
        <section class="fv-card">
          <div class="fv-card-head"><div><h2>Consigner un échange</h2><p>Le statut et la prochaine action se mettent à jour automatiquement.</p></div></div>
          <div class="fv-card-body">
            <form class="fv-form" method="post" action="activite-action.php" data-activity-form>
              <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
              <input type="hidden" name="prospect_id" value="<?= (int) $prospect['id'] ?>">
              <input type="hidden" name="retour" value="prospects.php?id=<?= (int) $prospect['id'] ?>">
              <label class="fv-field">Type d’échange
                <select name="kind" data-activity-kind>
                  <?php foreach (['appel', 'visite', 'email', 'rdv', 'note'] as $kind): ?>
                    <option value="<?= e($kind) ?>"><?= e(fv_kind_label($kind)) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="fv-field">Résultat
                <select name="outcome" data-activity-outcome>
                  <?php foreach (fv_outcomes() as $kind => $list): if ($kind === 'creation') continue; foreach ($list as $value => $label): ?>
                    <option value="<?= e($value) ?>" data-kind="<?= e($kind) ?>"<?= $kind !== 'appel' ? ' hidden disabled' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; endforeach; ?>
                </select>
              </label>
              <label class="fv-field">Notes
                <textarea name="notes" rows="3" placeholder="Ce qui s’est dit, la prochaine étape…"></textarea>
              </label>
              <div class="fv-form-grid">
                <label class="fv-field">Prochaine action
                  <input type="date" name="next_at" value="<?= e($prospect['next_action_at'] ?? '') ?>">
                </label>
                <label class="fv-field">Durée (min)
                  <input type="number" name="duration" min="0" max="600" step="5" placeholder="10">
                </label>
              </div>
              <button class="fv-btn fv-btn-primary" type="submit">Enregistrer l’échange</button>
            </form>
          </div>
        </section>

        <?php if (fv_is_gallery()): ?>
        <?php
          $sentCount = 0;
          foreach ($timeline as $a) if ($a['kind'] === 'email' && str_starts_with((string) $a['notes'], VS_SENT_NOTE)) $sentCount++;
          $tpl = ga_prospect_template(fv_scope()['gallery']);
        ?>
        <section class="fv-card">
          <div class="fv-card-head"><div><h2>Écrire au commerce</h2><p><?= $sentCount ?> message<?= $sentCount > 1 ? 's' : '' ?> de recrutement envoyé<?= $sentCount > 1 ? 's' : '' ?> · 4 au plus, un tous les 3 jours.</p></div></div>
          <div class="fv-card-body">
            <?php if ($prospect['email'] === ''): ?><p class="fv-hint">Ce prospect n’a pas d’adresse e-mail : ajoutez-la sur la fiche pour lui écrire.</p>
            <?php elseif (!empty($prospect['optout'])): ?><p class="fv-hint">Ce prospect s’est désinscrit : il ne reçoit plus de message.</p>
            <?php else: ?>
            <form class="fv-form" method="post" action="prospect-action.php">
              <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
              <input type="hidden" name="action" value="envoyer">
              <input type="hidden" name="id" value="<?= (int) $prospect['id'] ?>">
              <input type="hidden" name="retour" value="prospects.php?id=<?= (int) $prospect['id'] ?>">
              <label class="fv-field">Objet<input name="subject" maxlength="150" value="<?= e($tpl['subject']) ?>"></label>
              <label class="fv-field">Message
                <textarea name="body" rows="9"><?= e($tpl['body']) ?></textarea>
                <span class="fv-hint">Variables : {nom} {galerie} {lien}. <b>{desinscription}</b> est obligatoire : c’est le lien pour ne plus être contacté. Réservé aux professionnels dont vous avez les coordonnées professionnelles.</span>
              </label>
              <button class="fv-btn fv-btn-primary" type="submit" data-confirm="Envoyer ce message à <?= e($prospect['email']) ?> ?">Envoyer</button>
            </form>
            <?php endif; ?>
          </div>
        </section>
        <?php elseif (!$gal): ?>
        <section class="fv-card">
          <div class="fv-card-head"><div><h2>Passer à l’action</h2></div></div>
          <div class="fv-card-body">
            <a class="fv-btn" href="appels.php?id=<?= (int) $prospect['id'] ?>"><?= fv_icon('phone', 15) ?> Mode appel avec script</a>
            <a class="fv-btn" href="tournees.php?ville=<?= e(rawurlencode($prospect['city'])) ?>"><?= fv_icon('route', 15) ?> Préparer la visite</a>
            <a class="fv-btn" href="mailing.php?statut=<?= e($prospect['status']) ?>"><?= fv_icon('mail', 15) ?> Inclure dans un mailing</a>
            <a class="fv-btn" href="pitch.php"><?= fv_icon('presentation', 15) ?> Ouvrir le pitch</a>
          </div>
        </section>
        <?php endif; ?>
      </div>
    </div>
    <?php
    fv_layout_end();
    exit;
}

$list = fv_prospects_search($filters, 300);
$total = count($list);
$form = [
    'name' => $editing['name'] ?? '',
    'business' => $editing['business'] ?? '',
    'kind' => $editing['kind'] ?? '',
    'address' => $editing['address'] ?? '',
    'postal' => $editing['postal'] ?? '',
    'city' => $editing['city'] ?? '',
    'phone' => $editing['phone'] ?? '',
    'email' => $editing['email'] ?? '',
    'website' => $editing['website'] ?? '',
    'zone' => $editing['zone'] ?? '',
    'source' => $editing['source'] ?? 'terrain',
    'status' => $editing['status'] ?? 'a_contacter',
    'priority' => $editing['priority'] ?? 2,
    'owner_id' => $editing['owner_id'] ?? ($member['id'] ?? 0),
    'notes' => $editing['notes'] ?? '',
    'next_action_at' => $editing['next_action_at'] ?? '',
    'custom_fields' => fv_custom_fields_of($editing),
];
$showForm = $isNew || $editing !== null;
$defaultOwner = (int) ($member['id'] ?? 0);

fv_layout_start('Prospects', [
    'eyebrow' => 'Terrain · ' . $total . ' fiche' . ($total > 1 ? 's' : ''),
    'desc' => $gal ? 'Votre carnet de prospects : coordonnées, statut, prochaine action et historique des échanges. Il est à vous : aucune autre galerie ne le voit.'
        : 'Le carnet de bord de la prospection : coordonnées, statut, prochaine action et historique. Chaque fiche est reliée aux appels, visites, mailings et codes.',
    'actions' => '<a class="fv-btn fv-btn-sm" href="prospects.php?export=1&amp;' . e(http_build_query(array_filter($filters))) . '">' . fv_icon('download', 14) . ' CSV</a>'
        . '<a class="fv-btn fv-btn-sm" href="prospects-import.php">' . fv_icon('plus', 14) . ' Importer un fichier</a>'
        . '<a class="fv-btn fv-btn-sm fv-btn-primary" href="prospects.php?nouveau=1">' . fv_icon('plus', 14) . ' Nouveau prospect</a>',
]);
?>

<?php if ($showForm): ?>
<section class="fv-card" id="fiche">
  <div class="fv-card-head">
    <div><h2><?= $editing ? 'Modifier le prospect' : 'Nouveau prospect' ?></h2><p>Seul le nom du commerce ou le contact est obligatoire. Le reste peut se compléter sur le terrain.</p></div>
    <a class="fv-btn fv-btn-sm" href="prospects.php">Fermer</a>
  </div>
  <div class="fv-card-body">
    <form class="fv-form" method="post" action="prospect-action.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
      <input type="hidden" name="action" value="<?= $editing ? 'modifier' : 'creer' ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
      <input type="hidden" name="retour" value="prospects.php">
      <div class="fv-form-grid">
        <label class="fv-field">Nom du commerce
          <input name="business" maxlength="160" value="<?= e($form['business']) ?>" placeholder="Le Comptoir des Halles" autofocus>
        </label>
        <label class="fv-field">Contact
          <input name="name" maxlength="120" value="<?= e($form['name']) ?>" placeholder="Prénom et nom">
        </label>
        <label class="fv-field">Activité
          <input name="kind" maxlength="80" value="<?= e($form['kind']) ?>" placeholder="Fleuriste, brocante, café…">
        </label>
        <label class="fv-field">Téléphone
          <input name="phone" maxlength="40" value="<?= e($form['phone']) ?>" placeholder="06 12 34 56 78" inputmode="tel">
        </label>
        <label class="fv-field">E-mail
          <input type="email" name="email" maxlength="190" value="<?= e($form['email']) ?>" placeholder="contact@commerce.fr">
        </label>
        <label class="fv-field">Adresse
          <input name="address" maxlength="200" value="<?= e($form['address']) ?>" placeholder="12 rue des Halles">
        </label>
        <label class="fv-field">Code postal
          <input name="postal" maxlength="5" value="<?= e($form['postal']) ?>" inputmode="numeric" placeholder="75001">
        </label>
        <label class="fv-field">Ville
          <input name="city" maxlength="90" value="<?= e($form['city']) ?>" placeholder="Paris">
        </label>
        <label class="fv-field">Zone de tournée
          <input name="zone" maxlength="90" value="<?= e($form['zone']) ?>" placeholder="Centre-ville, nord…" list="fv-zones">
          <datalist id="fv-zones"><?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"><?php endforeach; ?></datalist>
        </label>
        <label class="fv-field">Source
          <select name="source">
            <?php foreach (fv_sources() as $key => $label): ?><option value="<?= e($key) ?>"<?= $form['source'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="fv-field">Statut
          <select name="status">
            <?php foreach (fv_statuses() as $key => $label): ?><option value="<?= e($key) ?>"<?= $form['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="fv-field">Priorité
          <select name="priority">
            <option value="1"<?= (int) $form['priority'] === 1 ? ' selected' : '' ?>>1 · À froid</option>
            <option value="2"<?= (int) $form['priority'] === 2 ? ' selected' : '' ?>>2 · Normale</option>
            <option value="3"<?= (int) $form['priority'] === 3 ? ' selected' : '' ?>>3 · Haute</option>
          </select>
        </label>
        <label class="fv-field">Prochaine action
          <input type="date" name="next_action_at" value="<?= e($form['next_action_at']) ?>">
        </label>
        <?php if ($ctx['manager'] && $members): ?>
          <label class="fv-field">Attribuer à
            <select name="owner_id">
              <option value="0"<?= (int) $form['owner_id'] === 0 ? ' selected' : '' ?>>Direction (non attribué)</option>
              <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (int) $form['owner_id'] === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?> · <?= e(fv_role_label($m['role'])) ?></option><?php endforeach; ?>
            </select>
          </label>
        <?php else: ?>
          <input type="hidden" name="owner_id" value="<?= $defaultOwner ?>">
        <?php endif; ?>
        <div class="fv-field full">
          <span>Champs personnalisés <small style="font-weight:400;">(emplacement, Instagram, Facebook… vide = supprimé)</small></span>
          <?php $cfRows = $form['custom_fields']; $cfRows[''] = ''; $cfRows[' '] = ''; $cfi = 0; ?>
          <?php foreach ($cfRows as $cfLabel => $cfValue): $cfLabel = trim((string) $cfLabel); ?>
            <div style="display:grid;grid-template-columns:minmax(120px,1fr) 2fr;gap:8px;margin-top:6px;">
              <input name="custom_fields[<?= $cfi ?>][label]" maxlength="60" value="<?= e($cfLabel) ?>" placeholder="Intitulé (ex. Emplacement)" aria-label="Intitulé du champ">
              <input name="custom_fields[<?= $cfi ?>][value]" maxlength="500" value="<?= e($cfValue) ?>" placeholder="Valeur" aria-label="Valeur du champ">
            </div>
          <?php $cfi++; endforeach; ?>
        </div>
        <label class="fv-field full">Notes
          <textarea name="notes" rows="3" placeholder="Contexte, besoins, objections, prochain angle…"><?= e($form['notes']) ?></textarea>
        </label>
      </div>
      <div class="fv-actions">
        <button class="fv-btn fv-btn-primary" type="submit"><?= $editing ? 'Enregistrer les modifications' : 'Créer le prospect' ?></button>
        <a class="fv-btn" href="prospects.php">Annuler</a>
      </div>
    </form>
  </div>
</section>
<?php endif; ?>

<section class="fv-card">
  <div class="fv-card-head">
    <div><h2>Fiches prospects <small class="fv-sub"><?= $total ?><?= $total >= 300 ? '+' : '' ?></small></h2><p>Filtrez par statut, zone ou échéance pour préparer la journée.</p></div>
    <a class="fv-btn fv-btn-sm" href="tournees.php?<?= e(http_build_query(array_filter(['zone' => $filters['zone'], 'owner' => $filters['owner_id'] ?: null]))) ?>"><?= fv_icon('route', 14) ?> Tournée</a>
  </div>

  <div class="fv-card-pad" style="padding-top:12px;padding-bottom:0;">
    <form class="fv-toolbar" method="get">
      <label class="fv-search"><?= fv_icon('search', 15) ?><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nom, ville, téléphone, note…" aria-label="Rechercher"></label>
      <select name="status" class="fv-select" onchange="this.form.submit()">
        <option value="">Tous les statuts</option>
        <?php foreach (fv_statuses() as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <select name="zone" onchange="this.form.submit()">
        <option value="">Toutes les zones</option>
        <?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= $filters['zone'] === $z ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?>
      </select>
      <?php if ($ctx['manager'] && !$gal): ?>
        <select name="owner" onchange="this.form.submit()">
          <option value="0">Toute l’équipe</option>
          <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $filters['owner_id'] === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="due" onchange="this.form.submit()">
        <option value="">Toutes les échéances</option>
        <option value="today"<?= $filters['due'] === 'today' ? ' selected' : '' ?>>Dues aujourd’hui</option>
        <option value="week"<?= $filters['due'] === 'week' ? ' selected' : '' ?>>Cette semaine</option>
        <option value="none"<?= $filters['due'] === 'none' ? ' selected' : '' ?>>Sans prochaine action</option>
      </select>
      <button class="fv-btn fv-btn-sm" type="submit">Filtrer</button>
      <?php if (array_filter($filters)): ?><a class="fv-btn fv-btn-sm fv-btn-ghost" href="prospects.php">Réinitialiser</a><?php endif; ?>
    </form>
  </div>

  <div class="fv-table-wrap" style="margin-top:12px;">
    <?php if (!$list): ?>
      <div class="fv-empty"><b>Aucun prospect ne correspond</b>Modifiez les filtres ou <a href="prospects.php?nouveau=1">créez une fiche</a>.</div>
    <?php else: ?>
      <table class="fv-table">
        <thead><tr><th>Commerce / contact</th><th>Statut</th><th>Coordonnées</th><th>Prochaine action</th><?php if (!$gal): ?><th>Propriétaire</th><?php endif; ?><th class="right">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($list as $p): ?>
            <tr>
              <td>
                <a class="fv-rowlink main" href="prospects.php?id=<?= (int) $p['id'] ?>"><?= e($p['business'] !== '' ? $p['business'] : $p['name']) ?></a>
                <span class="sub"><?= e(trim(($p['name'] !== '' && $p['business'] !== '' ? $p['name'] : $p['kind']) . ($p['city'] !== '' ? ' · ' . $p['city'] : ''))) ?></span>
              </td>
              <td><span class="fv-badge" data-s="<?= e($p['status']) ?>"><?= e(fv_status_label($p['status'])) ?></span><?php if ((int) $p['priority'] === 3): ?> <span class="fv-prio" data-p="3">●</span><?php endif; ?></td>
              <td>
                <?php if ($p['phone'] !== ''): ?><a href="tel:<?= e($p['phone']) ?>"><?= e($p['phone']) ?></a><br><?php endif; ?>
                <?php if ($p['email'] !== ''): ?><span class="sub"><?= e($p['email']) ?></span><?php endif; ?>
                <?php if ($p['phone'] === '' && $p['email'] === ''): ?><span class="sub">—</span><?php endif; ?>
              </td>
              <td>
                <?php if ($p['next_action_at']): ?>
                  <span <?= $p['next_action_at'] < fv_today() ? 'style="color:var(--fv-warn);font-weight:700;"' : '' ?>><?= e(fv_date($p['next_action_at'])) ?></span>
                <?php else: ?><span class="sub">À planifier</span><?php endif; ?>
              </td>
              <?php if (!$gal): ?><td><span class="sub"><?= e($p['owner_name'] ?? 'Direction') ?></span></td><?php endif; ?>
              <td>
                <div class="actions">
                  <?php if ($p['phone'] !== ''): ?><a class="fv-btn fv-btn-sm" href="<?= $gal ? 'tel:' . e($p['phone']) : 'appels.php?id=' . (int) $p['id'] ?>" title="Appeler"><?= fv_icon('phone', 13) ?></a><?php endif; ?>
                  <a class="fv-btn fv-btn-sm" href="prospects.php?id=<?= (int) $p['id'] ?>" title="Fiche"><?= fv_icon('eye', 13) ?></a>
                  <a class="fv-btn fv-btn-sm" href="prospects.php?editer=<?= (int) $p['id'] ?>" title="Modifier">✎</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <details class="fv-card-pad" style="border-top:1px solid var(--fv-line);">
    <summary style="cursor:pointer;font-weight:600;font-size:.86rem;">Importer des prospects (copier-coller CSV) — ou <a href="prospects-import.php">depuis un fichier Excel, CSV ou base SQLite</a></summary>
    <form class="fv-form" method="post" action="prospect-action.php" style="margin-top:12px;">
      <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
      <input type="hidden" name="action" value="importer">
      <input type="hidden" name="retour" value="prospects.php">
      <label class="fv-field">Une ligne par commerce, séparateur point-virgule
        <textarea name="csv" rows="7" placeholder="Le Comptoir;Marie Dupont;06 12 34 56 78;contact@comptoir.fr;12 rue des Halles;75001;Paris;Centre;Ouvert du mardi au samedi"></textarea>
        <span class="fv-hint">Colonnes : commerce ; contact ; téléphone ; e-mail ; adresse ; code postal ; ville ; zone ; notes. Les doublons (téléphone, e-mail ou commerce + ville) sont ignorés.</span>
      </label>
      <?php if ($ctx['manager'] && $members): ?>
        <label class="fv-field">Attribuer les fiches à
          <select name="owner_id">
            <option value="0">Direction</option>
            <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <button class="fv-btn" type="submit">Importer</button>
    </form>
  </details>
</section>

<script>
(function () {
  var form = document.querySelector('[data-activity-form]');
  if (!form) return;
  var kind = form.querySelector('[data-activity-kind]');
  var outcome = form.querySelector('[data-activity-outcome]');
  function sync() {
    var k = kind.value;
    Array.prototype.forEach.call(outcome.options, function (option) {
      var show = option.getAttribute('data-kind') === k;
      option.hidden = !show;
      option.disabled = !show;
    });
    var first = Array.prototype.find.call(outcome.options, function (option) { return !option.hidden && option.disabled === false; });
    if (first) outcome.value = first.value;
  }
  kind.addEventListener('change', sync);
  sync();
})();
</script>

<?php fv_layout_end(); ?>
