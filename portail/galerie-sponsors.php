<?php
// Extension de galerie « Sponsors & partenaires » : saisir ceux qui soutiennent la galerie (logo, lien, niveau) ; ils s'affichent sur la page
// publique de la galerie, rangés par niveau. Les fiches et la normalisation sont dans includes/gallery-sponsors.php.
//
// Accès : exploitant, ou administrateur de CETTE galerie (voir _galerie-layout.php). L'extension doit être active ; sinon renvoi vers l'onglet des extensions.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-modules.php';
require_once PORTAIL_ROOT . '/includes/gallery-sponsors.php';

$q = $limited ? '' : '?g=' . urlencode($gaSlug);
$self = '/portail/galerie-sponsors.php' . $q;

if (!gm_enabled($gaGallery, 'sponsors')) {
    portail_flash(ext_t('{Ext} « Sponsors & partenaires » n’est pas {?actif|active} pour cette galerie : activez-{?le|la} depuis l’onglet {Exts}.'), 'error');
    header('Location: /portail/galerie-modules.php' . $q . '#m-sponsors');
    exit;
}

$list = gsp_load($gaSlug);

/** Fiche saisie dans le formulaire (logo envoyé en $_FILES['logo'] ; $current = fiche existante ou null). Retourne [fiche|null, erreur|null]. */
$fromPost = static function (?array $current) use ($gaSlug): array {
    $item = gsp_normalize([
        'id' => $current['id'] ?? '',
        'name' => (string) ($_POST['name'] ?? ''),
        'tier' => (string) ($_POST['tier'] ?? 'partenaire'),
        'url' => (string) ($_POST['url'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'note' => (string) ($_POST['note'] ?? ''),
        'order' => (int) ($_POST['order'] ?? ($current['order'] ?? 0)),
        'visible' => !empty($_POST['visible']),
        'logo' => $current['logo'] ?? '',
        'added' => $current['added'] ?? time(),
    ]);
    if (!$item) return [null, 'Donnez au moins un nom.'];
    if (trim((string) ($_POST['url'] ?? '')) !== '' && $item['url'] === '') return [null, 'Le site web n’est pas une adresse valide (exemple : https://exemple.fr).'];
    $file = $_FILES['logo'] ?? null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $item['logo'] = gallery_image_store($file, $gaSlug, 'logo', 600);
        } catch (InvalidArgumentException $e) {
            return [null, $e->getMessage()];
        }
        if (($current['logo'] ?? '') !== '') $item['_old_logo'] = $current['logo'];
    } elseif (!empty($_POST['remove_logo']) && ($current['logo'] ?? '') !== '') {
        $item['_old_logo'] = $current['logo'];
        $item['logo'] = '';
    }
    return [$item, null];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id = strtolower((string) ($_POST['id'] ?? ''));
    $byId = [];
    foreach ($list as $i => $s) $byId[$s['id']] = $i;
    $anchor = '';

    if ($action === 'add') {
        if (count($list) >= GSP_MAX) {
            portail_flash('Vous avez atteint la limite de ' . GSP_MAX . ' fiches.', 'error');
        } else {
            [$item, $err] = $fromPost(null);
            if ($err) {
                portail_flash($err, 'error');
            } else {
                $list[] = $item;
                if (gsp_save($gaSlug, $list)) {
                    portail_flash('« ' . $item['name'] . ' » ajouté.', 'ok');
                    $anchor = '#s-' . $item['id'];
                } else {
                    if ($item['logo'] !== '') gsp_forget_logo($item['logo']);
                    portail_flash('Enregistrement impossible.', 'error');
                }
            }
        }
    } elseif (in_array($action, ['update', 'toggle', 'delete'], true)) {
        if (!isset($byId[$id])) {
            portail_flash('Fiche introuvable (déjà supprimée ?).', 'error');
        } elseif ($action === 'delete') {
            $gone = $list[$byId[$id]];
            unset($list[$byId[$id]]);
            if (gsp_save($gaSlug, array_values($list))) {
                gsp_forget_logo($gone['logo']);
                portail_flash('« ' . $gone['name'] . ' » supprimé.', 'ok');
            } else {
                portail_flash('Enregistrement impossible.', 'error');
            }
        } elseif ($action === 'toggle') {
            $list[$byId[$id]]['visible'] = empty($list[$byId[$id]]['visible']);
            $name = $list[$byId[$id]]['name'];
            $now = $list[$byId[$id]]['visible'];
            portail_flash(gsp_save($gaSlug, $list) ? '« ' . $name . ($now ? ' » est affiché sur la page publique.' : ' » est masqué.') : 'Enregistrement impossible.', 'ok');
            $anchor = '#s-' . $id;
        } else {
            [$item, $err] = $fromPost($list[$byId[$id]]);
            if ($err) {
                portail_flash($err, 'error');
            } else {
                $old = (string) ($item['_old_logo'] ?? '');
                $prevLogo = (string) $list[$byId[$id]]['logo'];
                unset($item['_old_logo']);
                $list[$byId[$id]] = $item;
                if (gsp_save($gaSlug, $list)) {
                    if ($old !== '') gsp_forget_logo($old);
                    portail_flash('« ' . $item['name'] . ' » enregistré.', 'ok');
                } else {
                    if ($item['logo'] !== $prevLogo) gsp_forget_logo($item['logo']);   // le nouveau logo n'a pas été retenu
                    portail_flash('Enregistrement impossible.', 'error');
                }
                $anchor = '#s-' . $id;
            }
        }
    }
    header('Location: ' . $self . $anchor);
    exit;
}

$stats = gsp_stats($list);
$publicUrl = gallery_public_url($gaGallery, $gaOrigin) . '#partenaires';
$fieldCss = 'width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:inherit;box-sizing:border-box';

/** Champs communs au formulaire d'ajout et de modification. */
$fields = static function (?array $s) use ($fieldCss): void {
    $s = $s ?? ['name' => '', 'tier' => 'partenaire', 'url' => '', 'description' => '', 'note' => '', 'order' => 0, 'visible' => true, 'logo' => ''];
    ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
            <label class="field"><span>Nom *</span><input type="text" name="name" required maxlength="80" value="<?= e($s['name']) ?>" style="<?= $fieldCss ?>"></label>
            <label class="field"><span>Niveau</span>
              <select name="tier" style="<?= $fieldCss ?>"><?php foreach (GSP_TIERS as $key => $t): ?><option value="<?= e($key) ?>"<?= $s['tier'] === $key ? ' selected' : '' ?>><?= e($t[0]) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Site web</span><input type="text" name="url" maxlength="300" placeholder="https://exemple.fr" value="<?= e($s['url']) ?>" style="<?= $fieldCss ?>"></label>
            <label class="field"><span>Ordre dans le niveau</span><input type="number" name="order" min="0" max="999" value="<?= (int) $s['order'] ?>" style="<?= $fieldCss ?>"></label>
            <label class="field"><span>Logo (JPEG, PNG ou WebP)</span><input type="file" name="logo" accept="image/jpeg,image/png,image/webp" style="<?= $fieldCss ?>"></label>
          </div>
          <label class="field" style="margin-top:10px;display:block"><span>Mot de présentation (240 caractères au plus, affiché sous le logo)</span><input type="text" name="description" maxlength="240" value="<?= e($s['description']) ?>" style="<?= $fieldCss ?>"></label>
          <label class="field" style="margin-top:10px;display:block"><span>Note interne (jamais affichée : contact, montant, contrepartie…)</span><textarea name="note" maxlength="500" rows="2" style="<?= $fieldCss ?>"><?= e($s['note']) ?></textarea></label>
          <p class="row" style="margin:10px 0 0;gap:16px;align-items:center">
            <label><input type="checkbox" name="visible" value="1"<?= $s['visible'] ? ' checked' : '' ?>> Affiché sur la page publique</label>
            <?php if ($s['logo'] !== ''): ?><label><input type="checkbox" name="remove_logo" value="1"> Retirer le logo actuel</label><?php endif; ?>
          </p>
    <?php
};

ga_head('Sponsors & partenaires');
?>
    <section class="gcard">
      <h2>🤝 Sponsors & partenaires de « <?= e($gaGallery['name']) ?> »</h2>
      <p class="meta">Ajoutez ceux qui soutiennent votre événement : leur logo, leur site et un mot de présentation s’affichent sur la <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">page publique de la galerie</a>, rangés par niveau (du sponsor principal aux partenaires médias). Une fiche masquée reste enregistrée mais n’est pas montrée.</p>
      <p class="meta" style="margin:6px 0 0"><strong><?= (int) $stats['total'] ?></strong> fiche(s), dont <strong><?= (int) $stats['visibles'] ?></strong> affichée(s)<?php foreach (GSP_TIERS as $key => $t): if ($stats['par_niveau'][$key]): ?> · <?= e($t[0]) ?> : <?= (int) $stats['par_niveau'][$key] ?><?php endif; endforeach; ?>.</p>
    </section>

    <section class="gcard">
      <h2>Ajouter un sponsor ou un partenaire</h2>
      <?php if (count($list) >= GSP_MAX): ?>
        <p class="meta">Limite de <?= GSP_MAX ?> fiches atteinte : supprimez-en une pour en ajouter.</p>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add">
          <?php if (!$limited): ?><input type="hidden" name="g" value="<?= e($gaSlug) ?>"><?php endif; ?>
          <?php $fields(null); ?>
          <p style="margin:12px 0 0"><button class="btn" type="submit">Ajouter</button></p>
        </form>
      <?php endif; ?>
    </section>

    <?php foreach (GSP_TIERS as $tierKey => $tier): $rows = array_values(array_filter($list, static fn (array $s): bool => $s['tier'] === $tierKey)); if (!$rows) continue; ?>
    <section class="gcard">
      <h2><?= e($tier[0]) ?> (<?= count($rows) ?>)</h2>
      <?php foreach ($rows as $s): ?>
        <div id="s-<?= e($s['id']) ?>" style="border-top:1px solid var(--line);padding:10px 0">
          <div class="row" style="justify-content:space-between;align-items:center;gap:12px">
            <div class="row" style="align-items:center;gap:12px;min-width:0">
              <?php if ($s['logo'] !== ''): ?><img src="/<?= e($s['logo']) ?>" alt="" style="width:56px;height:56px;object-fit:contain;border:1px solid var(--line);border-radius:8px;background:#fff;padding:4px"><?php else: ?><span aria-hidden="true" style="width:56px;height:56px;display:inline-flex;align-items:center;justify-content:center;border:1px dashed var(--line);border-radius:8px;font-size:1.4rem">🤝</span><?php endif; ?>
              <div style="min-width:0">
                <strong><?= e($s['name']) ?></strong><?= $s['visible'] ? '' : ' <small class="meta">(masqué)</small>' ?>
                <?php if ($s['url'] !== ''): ?><br><small class="meta"><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener nofollow"><?= e(preg_replace('#^https?://(www\.)?#i', '', $s['url'])) ?></a></small><?php endif; ?>
                <?php if ($s['description'] !== ''): ?><br><small class="meta"><?= e($s['description']) ?></small><?php endif; ?>
              </div>
            </div>
            <div class="row" style="gap:6px">
              <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e($s['id']) ?>"><?php if (!$limited): ?><input type="hidden" name="g" value="<?= e($gaSlug) ?>"><?php endif; ?>
                <button class="btn small ghost" type="submit"><?= $s['visible'] ? 'Masquer' : 'Afficher' ?></button></form>
              <form method="post" onsubmit="return confirm('Supprimer « <?= e(addslashes($s['name'])) ?> » et son logo ?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($s['id']) ?>"><?php if (!$limited): ?><input type="hidden" name="g" value="<?= e($gaSlug) ?>"><?php endif; ?>
                <button class="btn small ghost" type="submit">Supprimer</button></form>
            </div>
          </div>
          <details style="margin-top:8px"><summary style="cursor:pointer">Modifier</summary>
            <form method="post" enctype="multipart/form-data" style="margin-top:10px">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= e($s['id']) ?>">
              <?php if (!$limited): ?><input type="hidden" name="g" value="<?= e($gaSlug) ?>"><?php endif; ?>
              <?php $fields($s); ?>
              <p style="margin:12px 0 0"><button class="btn small" type="submit">Enregistrer</button></p>
            </form>
          </details>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>

    <?php if (!$list): ?><section class="gcard"><p class="meta" style="margin:0">Aucune fiche pour le moment : la rubrique n’apparaît sur la page publique qu’à partir de la première fiche affichée.</p></section><?php endif; ?>
<?php ga_foot(); ?>
