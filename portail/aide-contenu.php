<?php
// Contenu du centre d'aide (/aide/) : corriger les questions, les définitions et les tutoriels, ajouter une question ou un terme,
// cacher une fiche. Les corrections sont gardées dans data/saas/aide-contenu.json (jamais écrasé par un déploiement). Voir includes/aide-edition.php.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/aide-edition.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';

$type = (string) ($_GET['s'] ?? $_POST['type'] ?? 'faq');
if (!isset(AIDE_EDIT_TYPES[$type])) $type = 'faq';
$keyField = AIDE_EDIT_TYPES[$type]['key'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $key = (string) ($_POST['key'] ?? '');
    $err = null;
    $done = 'Enregistré : la modification est en ligne dans le centre d’aide.';
    switch ($action) {
        case 'update': $err = aide_edit_update($type, $key, $_POST); break;
        case 'reset': $err = aide_edit_reset($type, $key); $done = 'Le texte d’origine est rétabli.'; break;
        case 'hide': $err = aide_edit_hide($type, $key, true); $done = 'Fiche cachée du centre d’aide. Elle reste ici : vous pouvez la remettre.'; break;
        case 'show': $err = aide_edit_hide($type, $key, false); $done = 'Fiche remise dans le centre d’aide.'; break;
        case 'add': $err = aide_edit_add($type, $_POST); $done = 'Fiche ajoutée au centre d’aide.'; break;
        case 'delete': $err = aide_edit_delete_added($type, $key); $done = 'Fiche supprimée.'; break;
        default: $err = 'Action inconnue.';
    }
    if ($err === null) {
        saas_event('config_changed', ['note' => 'Contenu du centre d’aide (' . AIDE_EDIT_TYPES[$type]['label'] . ') : ' . $action . ($key !== '' ? ' · ' . $key : ''), 'actor' => 'exploitant']);
        portail_flash($done, 'ok');
    } else {
        portail_flash($err, 'error');
    }
    header('Location: /portail/aide-contenu.php?s=' . rawurlencode($type));
    exit;
}

/** Champs complets d'un tutoriel (création, ou tutoriel ajouté dans le portail) : lignes d'étapes et d'erreurs, extensibles sans rechargement. */
function ac_tutorial_fields(array $row, array $roles): void
{
    $steps = array_values((array) ($row['etapes'] ?? []));
    $errors = array_values((array) ($row['erreurs'] ?? []));
    // Toujours une ligne vide de plus que l'existant (3 étapes et 1 erreur au minimum), pour continuer sans JavaScript.
    $stepRows = max(3, count($steps) + 1);
    $errorRows = max(1, count($errors) + 1);
    while (count($steps) < $stepRows) $steps[] = [];
    while (count($errors) < $errorRows) $errors[] = [];
    ?>
    <label class="field">Titre<input type="text" name="titre" maxlength="200" required value="<?= e($row['titre'] ?? '') ?>"></label>
    <label class="field">Résumé (une ou deux phrases)<textarea name="resume" maxlength="600" required><?= e($row['resume'] ?? '') ?></textarea></label>
    <label class="field">Pour qui<select name="role"><?php foreach ($roles as $rk => $rl): ?><option value="<?= e($rk) ?>"<?= ($row['role'] ?? 'tous') === $rk ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?></select></label>
    <label class="field">Avant de commencer (un prérequis par ligne)<textarea name="prerequis" maxlength="2400"><?= e(implode("\n", (array) ($row['prerequis'] ?? []))) ?></textarea></label>
    <label class="field">Page concernée (adresse du site, facultatif, ex. /portail/galeries.php)<input type="text" name="url_cible" maxlength="200" value="<?= e($row['url_cible'] ?? '') ?>"></label>
    <label class="field">Mots-clés (séparés par des virgules, pour la recherche)<input type="text" name="mots_cles" maxlength="400" value="<?= e(implode(', ', (array) ($row['mots_cles'] ?? []))) ?>"></label>
    <fieldset class="ac-rows" data-rows="etapes"><legend>Étapes</legend>
      <?php foreach ($steps as $n => $st): ?>
        <div class="ac-step" data-row>
          <strong>Étape <span data-num><?= $n + 1 ?></span></strong>
          <label class="field">Titre<input type="text" name="etapes[<?= $n ?>][titre]" maxlength="160" value="<?= e($st['titre'] ?? '') ?>"></label>
          <label class="field">Texte<textarea name="etapes[<?= $n ?>][texte]" maxlength="1500"><?= e($st['texte'] ?? '') ?></textarea></label>
          <label class="field">Ce que l’on doit voir à l’écran (facultatif)<input type="text" name="etapes[<?= $n ?>][a_voir]" maxlength="300" value="<?= e($st['a_voir'] ?? '') ?>"></label>
          <label class="field">Lien « Ouvrir cette page » (facultatif)<input type="text" name="etapes[<?= $n ?>][lien]" maxlength="200" value="<?= e($st['lien'] ?? '') ?>"></label>
        </div>
      <?php endforeach; ?>
      <button type="button" class="btn" data-add-row>+ Ajouter une étape</button>
    </fieldset>
    <fieldset class="ac-rows" data-rows="erreurs"><legend>Erreurs fréquentes (facultatif)</legend>
      <?php foreach ($errors as $n => $er): ?>
        <div class="ac-step" data-row>
          <label class="field">Symptôme<input type="text" name="erreurs[<?= $n ?>][symptome]" maxlength="300" value="<?= e($er['symptome'] ?? '') ?>"></label>
          <label class="field">Cause<input type="text" name="erreurs[<?= $n ?>][cause]" maxlength="300" value="<?= e($er['cause'] ?? '') ?>"></label>
          <label class="field">Solution<input type="text" name="erreurs[<?= $n ?>][solution]" maxlength="500" value="<?= e($er['solution'] ?? '') ?>"></label>
        </div>
      <?php endforeach; ?>
      <button type="button" class="btn" data-add-row>+ Ajouter une erreur</button>
    </fieldset>
    <?php
}

$rows = aide_edit_rows($type);
$flash = portail_flash();
$roles = ['vendeur' => 'Vendeur', 'organisateur' => 'Organisateur', 'visiteur' => 'Visiteur', 'commune' => 'Commune', 'tous' => 'Général'];
$titleField = ['faq' => 'question', 'glossaire' => 'terme', 'tutoriels' => 'titre'][$type];
$titleOf = static fn (array $r): string => (string) ($r['row'][$titleField] ?? '');
$tabs = [];
foreach (AIDE_EDIT_TYPES as $t => $meta) {
    $tabs[] = ['href' => '/portail/aide-contenu.php?s=' . $t, 'label' => $meta['label'], 'active' => $t === $type];
}
$counts = ['changed' => 0, 'hidden' => 0, 'added' => 0];
foreach ($rows as $r) {
    foreach ($counts as $k => $_) if ($r[$k]) $counts[$k]++;
}
$pageTitle = 'Contenu de l’aide';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — Portail</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .ac-tools { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 14px 0; }
  .ac-tools input[type=search] { flex: 1 1 240px; max-width: 420px; }
  .ac-item { border: 1px solid var(--line); border-radius: var(--radius, 10px); background: var(--panel); margin: 8px 0; }
  .ac-item > summary { cursor: pointer; padding: 10px 14px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; list-style: none; }
  .ac-item > summary::-webkit-details-marker { display: none; }
  .ac-item > summary strong { flex: 1 1 260px; font-weight: 600; }
  .ac-item[open] > summary { border-bottom: 1px solid var(--line); }
  .ac-item.is-hidden > summary strong { text-decoration: line-through; color: var(--muted); }
  .ac-item form { padding: 12px 14px 14px; display: grid; gap: 10px; }
  .ac-item textarea { min-height: 96px; width: 100%; font: inherit; }
  .ac-item input[type=text], .ac-item select { width: 100%; }
  .ac-tag { font-size: .7rem; padding: 1px 8px; border: 1px solid var(--line); border-radius: 999px; color: var(--muted); white-space: nowrap; }
  .ac-tag.is-changed { border-color: var(--accent); color: var(--accent); }
  .ac-step { border-left: 3px solid var(--line); padding-left: 12px; display: grid; gap: 8px; }
  .ac-rows { border: 1px solid var(--line); border-radius: var(--radius, 10px); padding: 10px 12px; display: grid; gap: 12px; }
  .ac-rows legend { font-weight: 600; padding: 0 6px; }
  .ac-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .ac-add { margin: 14px 0 6px; }
  .ac-add > summary { cursor: pointer; font-weight: 600; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => $pageTitle,
    'eyebrow' => 'Plateforme',
    'desc' => 'Corrigez les textes du centre d’aide sans toucher aux fichiers : la modification est visible tout de suite sur /aide/.',
    'section' => 'Plateforme',
    'tabs' => $tabs,
]); ?>

  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <p class="hint">Les corrections sont gardées à part du code : un déploiement ne les écrase pas. Attention, le local, la préprod et la prod ont chacun les leurs —
    ce que vous modifiez ici ne vaut que pour ce site. « Revenir au texte d’origine » efface la correction ; une fiche cachée peut toujours être remise.</p>
  <p class="hint"><?= count($rows) ?> fiche<?= count($rows) > 1 ? 's' : '' ?> · <?= $counts['changed'] ?> corrigée<?= $counts['changed'] > 1 ? 's' : '' ?> · <?= $counts['hidden'] ?> cachée<?= $counts['hidden'] > 1 ? 's' : '' ?> · <?= $counts['added'] ?> ajoutée<?= $counts['added'] > 1 ? 's' : '' ?>
    · <a href="/aide/" target="_blank" rel="noopener">Ouvrir le centre d’aide</a></p>

  <details class="ac-item ac-add">
    <summary><strong><?= $type === 'faq' ? '+ Ajouter une question' : ($type === 'tutoriels' ? '+ Créer un tutoriel' : '+ Ajouter un terme') ?></strong></summary>
    <form method="post" action="/portail/aide-contenu.php?s=<?= e($type) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="type" value="<?= e($type) ?>">
      <input type="hidden" name="action" value="add">
      <?php if ($type === 'tutoriels'): ?>
        <?php ac_tutorial_fields([], $roles); ?>
      <?php elseif ($type === 'faq'): ?>
        <label class="field">Question<input type="text" name="question" maxlength="200" required></label>
        <label class="field">Réponse<textarea name="reponse" maxlength="2000" required></textarea></label>
        <label class="field">Pour qui<select name="role"><?php foreach ($roles as $rk => $rl): ?><option value="<?= e($rk) ?>"<?= $rk === 'tous' ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?></select></label>
        <label class="field">Mots-clés (séparés par des virgules, pour la recherche)<input type="text" name="mots_cles" maxlength="400"></label>
      <?php else: ?>
        <label class="field">Terme<input type="text" name="terme" maxlength="80" required></label>
        <label class="field">Définition<textarea name="definition" maxlength="1000" required></textarea></label>
        <label class="field">Exemple (facultatif)<textarea name="exemple" maxlength="500"></textarea></label>
      <?php endif; ?>
      <div class="ac-actions"><button class="btn btn-primary" type="submit"><?= $type === 'tutoriels' ? 'Créer le tutoriel' : 'Ajouter' ?></button></div>
    </form>
  </details>

  <div class="ac-tools"><input type="search" id="ac-filter" placeholder="Filtrer les fiches…" aria-label="Filtrer les fiches"></div>

  <div id="ac-list">
  <?php foreach ($rows as $i => $r): $row = $r['row']; $title = $titleOf($r); ?>
    <details class="ac-item<?= $r['hidden'] ? ' is-hidden' : '' ?>" id="fiche-<?= $i ?>" data-text="<?= e(mb_strtolower($title . ' ' . ($row['reponse'] ?? $row['definition'] ?? $row['resume'] ?? ''))) ?>">
      <summary>
        <strong><?= e($title) ?></strong>
        <?php if (isset($row['role'])): ?><span class="ac-tag"><?= e($roles[$row['role']] ?? 'Général') ?></span><?php endif; ?>
        <?php if ($r['changed']): ?><span class="ac-tag is-changed">Corrigée</span><?php endif; ?>
        <?php if ($r['added']): ?><span class="ac-tag is-changed">Ajoutée</span><?php endif; ?>
        <?php if ($r['hidden']): ?><span class="ac-tag">Cachée</span><?php endif; ?>
      </summary>
      <form method="post" action="/portail/aide-contenu.php?s=<?= e($type) ?>#fiche-<?= $i ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="key" value="<?= e($r['key']) ?>">
        <?php if ($type === 'faq'): ?>
          <label class="field">Question<input type="text" name="question" maxlength="200" required value="<?= e($row['question'] ?? '') ?>"></label>
          <label class="field">Réponse<textarea name="reponse" maxlength="2000" required><?= e($row['reponse'] ?? '') ?></textarea></label>
          <label class="field">Pour qui<select name="role"><?php foreach ($roles as $rk => $rl): ?><option value="<?= e($rk) ?>"<?= ($row['role'] ?? 'tous') === $rk ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?></select></label>
          <label class="field">Mots-clés (séparés par des virgules)<input type="text" name="mots_cles" maxlength="400" value="<?= e(implode(', ', (array) ($row['mots_cles'] ?? []))) ?>"></label>
          <?php if (!empty($row['liens'])): ?><p class="hint" style="margin:0;">Liens affichés sous la réponse (non modifiables ici) : <?= e(implode(' · ', array_map(static fn ($l) => (string) ($l['libelle'] ?? ''), (array) $row['liens']))) ?></p><?php endif; ?>
        <?php elseif ($type === 'glossaire'): ?>
          <p style="margin:0;"><strong><?= e($row['terme'] ?? '') ?></strong></p>
          <label class="field">Définition<textarea name="definition" maxlength="1000" required><?= e($row['definition'] ?? '') ?></textarea></label>
          <label class="field">Exemple (facultatif)<textarea name="exemple" maxlength="500"><?= e($row['exemple'] ?? '') ?></textarea></label>
        <?php elseif ($r['added']): ?>
          <?php ac_tutorial_fields($row, $roles); ?>
        <?php else: ?>
          <label class="field">Titre<input type="text" name="titre" maxlength="200" required value="<?= e($row['titre'] ?? '') ?>"></label>
          <label class="field">Résumé<textarea name="resume" maxlength="600" required><?= e($row['resume'] ?? '') ?></textarea></label>
          <label class="field">Pour qui<select name="role"><?php foreach ($roles as $rk => $rl): ?><option value="<?= e($rk) ?>"<?= ($row['role'] ?? 'tous') === $rk ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?></select></label>
          <?php foreach ((array) ($row['etapes'] ?? []) as $n => $step): ?>
            <div class="ac-step">
              <strong>Étape <?= $n + 1 ?></strong>
              <label class="field">Titre<input type="text" name="etapes[<?= $n ?>][titre]" maxlength="160" required value="<?= e($step['titre'] ?? '') ?>"></label>
              <label class="field">Texte<textarea name="etapes[<?= $n ?>][texte]" maxlength="1500" required><?= e($step['texte'] ?? '') ?></textarea></label>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <div class="ac-actions">
          <button class="btn btn-primary" type="submit" name="action" value="update">Enregistrer</button>
          <?php if ($type === 'tutoriels'): ?><a class="btn" href="/aide/tutoriel.php?slug=<?= e(rawurlencode($r['key'])) ?>" target="_blank" rel="noopener">Voir la fiche</a><?php endif; ?>
          <?php if ($r['changed']): ?><button class="btn" type="submit" name="action" value="reset" formnovalidate onclick="return confirm('Revenir au texte d’origine ? Votre correction sera perdue.');">Revenir au texte d’origine</button><?php endif; ?>
          <?php if (!$r['added']): ?>
            <?php if ($r['hidden']): ?><button class="btn" type="submit" name="action" value="show" formnovalidate>Remettre dans l’aide</button>
            <?php else: ?><button class="btn" type="submit" name="action" value="hide" formnovalidate>Cacher du centre d’aide</button><?php endif; ?>
          <?php else: ?>
            <button class="btn" type="submit" name="action" value="delete" formnovalidate onclick="return confirm('Supprimer cette fiche ? Cette action est définitive.');">Supprimer</button>
          <?php endif; ?>
        </div>
      </form>
    </details>
  <?php endforeach; ?>
  </div>

<script>
(function () {
  var input = document.getElementById('ac-filter');
  var items = document.querySelectorAll('#ac-list .ac-item');
  function strip(s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  var texts = Array.prototype.map.call(items, function (el) { return strip(el.getAttribute('data-text') || ''); });
  input.addEventListener('input', function () {
    var q = strip(input.value.trim());
    items.forEach(function (el, i) { el.hidden = q !== '' && texts[i].indexOf(q) === -1; });
  });
  // Lignes extensibles (étapes, erreurs fréquentes) : « + Ajouter » clone la dernière ligne, vide, et renumérote.
  document.querySelectorAll('[data-rows]').forEach(function (box) {
    var kind = box.getAttribute('data-rows');
    box.querySelector('[data-add-row]').addEventListener('click', function () {
      var rows = box.querySelectorAll('[data-row]');
      var copy = rows[rows.length - 1].cloneNode(true);
      var n = rows.length;
      copy.querySelectorAll('input, textarea').forEach(function (f) {
        f.value = '';
        f.name = f.name.replace(/\[\d+\]/, '[' + n + ']');
      });
      var num = copy.querySelector('[data-num]'); if (num) num.textContent = n + 1;
      box.insertBefore(copy, this);
      var first = copy.querySelector('input'); if (first) first.focus();
    });
  });
  if (location.hash) { var t = document.querySelector(location.hash); if (t && t.tagName === 'DETAILS') t.open = true; }
})();
</script>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
