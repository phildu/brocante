<?php
// Extension de galerie « Visite guidée » : régler le parcours que les visiteurs suivent pas à pas (includes/gallery-visit.php). Les étapes sont
// bâties toutes seules à partir du plan (une par hall ou bâtiment) : ici l'organisateur affiche ou masque le bouton, écrit un mot d'accueil
// et, s'il le souhaite, un mot par étape.
//
// Accès : exploitant, ou administrateur de CETTE galerie (voir _galerie-layout.php). L'extension doit être active ; sinon renvoi vers l'onglet des extensions.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-modules.php';
require_once PORTAIL_ROOT . '/includes/gallery-visit.php';

$q = $limited ? '' : '?g=' . urlencode($gaSlug);
$self = '/portail/galerie-visite.php' . $q;

if (!gm_enabled($gaGallery, 'visite')) {
    portail_flash(ext_t('{Ext} « Visite guidée » n’est pas {?actif|active} pour cette galerie : activez-{?le|la} depuis l’onglet {Exts}.'), 'error');
    header('Location: /portail/galerie-modules.php' . $q . '#m-visite');
    exit;
}

$settings = gv_load($gaSlug);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $notes = [];
    foreach ((array) ($_POST['notes'] ?? []) as $key => $text) $notes[(string) $key] = $text;
    $ok = gv_save($gaSlug, ['show' => !empty($_POST['show']), 'intro' => (string) ($_POST['intro'] ?? ''), 'notes' => $notes]);
    portail_flash($ok ? 'Visite guidée enregistrée.' : 'Enregistrement impossible (droits du dossier data).', $ok ? 'ok' : 'error');
    header('Location: ' . $self);
    exit;
}

$shops = gallery_catalogue($gaGallery, $gaOrigin, false, true)['shops'];
uasort($shops, static fn (array $a, array $b): int => [$b['featured'], $a['order']] <=> [$a['featured'], $b['order']]);
$steps = gv_steps($gaGallery, $shops, $settings);
$content = array_values(array_filter($steps, static fn (array $s): bool => !in_array($s['kind'], ['accueil', 'fin'], true)));
$hasSectors = (bool) gallery_map_sectors($gaGallery['map'] ?? null);
$visitUrl = $gaOrigin . '/galerie/visite.php?g=' . rawurlencode($gaSlug);
$fieldCss = 'width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:inherit;box-sizing:border-box';

ga_head('Visite guidée');
?>
    <section class="gcard">
      <h2>🧭 Visite guidée de « <?= e($gaGallery['name']) ?> »</h2>
      <p class="meta">Vos visiteurs suivent un parcours pas à pas : <?= $hasSectors ? 'une étape par hall ou bâtiment du plan, avec les ' . e(gt_word($gaGallery, 'members')) . ' qui s’y trouvent' : 'une étape par ' . e(gt_word($gaGallery, 'member')) . ' (dessinez des « Sous-zones » dans le <a href="/portail/galerie-plan.php' . e($q) . '">plan</a> pour obtenir une étape par hall ou bâtiment)' ?>. Le parcours se met à jour tout seul quand le plan change.</p>
      <p class="meta" style="margin:6px 0 0"><a href="<?= e($visitUrl) ?>" target="_blank" rel="noopener">Essayer la visite ↗</a> · <?= $content ? count($content) . ' étape(s) de contenu' : 'aucune étape pour le moment : elle apparaît dès qu’un ' . e(gt_word($gaGallery, 'member')) . ' est dans la galerie' ?>.</p>
    </section>

    <form method="post" class="gcard">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <?php if (!$limited): ?><input type="hidden" name="g" value="<?= e($gaSlug) ?>"><?php endif; ?>
      <h2>Réglages</h2>
      <p style="margin:0 0 10px"><label><input type="checkbox" name="show" value="1"<?= $settings['show'] ? ' checked' : '' ?>> Afficher le bouton « Visite guidée » sur la page publique de la galerie</label></p>
      <label class="field" style="display:block"><span>Mot d’accueil (<?= GV_INTRO_MAX ?> caractères au plus ; laissé vide, un texte par défaut est proposé)</span>
        <textarea name="intro" rows="3" maxlength="<?= GV_INTRO_MAX ?>" style="<?= $fieldCss ?>"><?= e($settings['intro']) ?></textarea></label>

      <?php if ($content): ?>
      <h2 style="margin-top:18px">Un mot par étape (facultatif)</h2>
      <?php foreach ($content as $s): $default = $s['kind'] === 'secteur' ? (string) ($s['sector']['note'] ?? '') : ($s['kind'] === 'marchand' ? (string) ($shops[$s['key']]['tagline'] ?? '') : ''); ?>
        <label class="field" style="display:block;margin-top:10px"><span><strong><?= e($s['title']) ?></strong> · <?= count($s['shops']) ?> <?= e(count($s['shops']) > 1 ? gt_word($gaGallery, 'members') : gt_word($gaGallery, 'member')) ?><?= $default !== '' ? ' · texte par défaut : « ' . e(mb_strimwidth($default, 0, 80, '…')) . ' »' : '' ?></span>
          <textarea name="notes[<?= e($s['key']) ?>]" rows="2" maxlength="<?= GV_NOTE_MAX ?>" style="<?= $fieldCss ?>"><?= e($settings['notes'][$s['key']] ?? '') ?></textarea></label>
      <?php endforeach; ?>
      <?php endif; ?>
      <p style="margin:14px 0 0"><button class="btn btn-primary" type="submit">Enregistrer</button></p>
    </form>
<?php ga_foot(); ?>
