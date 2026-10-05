<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$flash = flash_get();
$universes = category_list();
$counts = universes_counts();
$orphans = universes_orphan_refs();
$customized = universes_saved() !== null;
$profile = shop_profile();
$staleExamples = $profile === '' && trim((string) tenant('ai.examples')) !== '';
$total = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
$allRefs = db()->query('SELECT ref FROM products ORDER BY ref')->fetchAll(PDO::FETCH_COLUMN);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Univers — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .un-list { list-style: none; margin: 18px 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  .un-row { display: grid; grid-template-columns: 1fr 190px 110px auto; gap: 10px; align-items: center; background: var(--surface); border: 1px solid var(--line); padding: 10px 12px; }
  .un-row input[type="text"], .un-row select { width: 100%; box-sizing: border-box; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 9px 10px; font-family: var(--font-body); font-size: 0.92rem; }
  .un-count { font-family: var(--font-mono); font-size: 0.78rem; color: var(--ink-soft); }
  .un-row .un-move { display: flex; gap: 4px; }
  .un-row .un-move button, .un-row .un-del { background: none; border: 1px solid var(--line); color: var(--ink); padding: 6px 9px; cursor: pointer; font-size: 0.85rem; }
  .un-row .un-del:hover { border-color: #b3261e; color: #b3261e; }
  .un-profile { margin: 18px 0 6px; padding: 14px 16px; background: var(--surface); border: 1px solid var(--line); }
  .un-profile label { font-weight: 600; font-size: 0.92rem; }
  .un-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .un-ideas { margin-top: 14px; padding: 14px 16px; border: 1px solid var(--accent); background: var(--surface); }
  .un-ideas ul { list-style: none; margin: 8px 0 12px; padding: 0; display: flex; flex-wrap: wrap; gap: 6px; }
  .un-ideas li { border: 1px solid var(--line); background: var(--bg); padding: 4px 10px; font-size: 0.88rem; }
  @media (max-width: 780px) { .un-row { grid-template-columns: 1fr 1fr; } .un-row .un-count { grid-column: 1; } }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'univers'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Catalogue</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Univers de la boutique</h1>
    <p class="hint">Les univers sont les rayons de votre boutique : filtres de la page « La boutique », accueil, classement des pièces. L'IA les utilise pour classer chaque pièce (champ « Univers » du catalogue) : ils doivent donc correspondre à ce que vous vendez. Changer le nom d'un univers garde ses pièces ; en supprimer un laisse ses pièces à reclasser.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php if ($staleExamples): ?>
      <p class="publish-status" style="margin:16px 0;">L'IA ne sait pas encore ce que vend votre boutique : elle s'appuie sur la description d'origine (« <?= h(tenant('ai.examples')) ?> »), qui vient peut-être d'un modèle d'exemple. Cliquez sur « Détecter ce que vend ma boutique » : elle regarde vos photos et vos pièces.</p>
    <?php endif; ?>
    <form method="post" action="/admin/universes-action.php" id="un-form">
      <input type="hidden" name="action" value="save">
      <div class="un-profile">
        <label for="un-profile">Ce que vend votre boutique, en une phrase <span class="hint" style="margin:0;">(guide l'IA dans tous ses prompts : fiches, images, prix)</span></label>
        <div class="un-actions" style="margin-top:6px;">
          <input type="text" name="profile" id="un-profile" maxlength="120" value="<?= h($profile) ?>" placeholder="ex : friperie et mode vintage pour femme" style="flex:1;min-width:240px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:9px 10px;font-family:var(--font-body);font-size:0.95rem;">
          <button type="button" class="btn-small" id="un-detect"<?= GEMINI_API_KEY ? '' : ' disabled title="Clé Gemini non configurée"' ?>>Détecter ce que vend ma boutique (IA)</button>
        </div>
        <p class="hint" style="margin:6px 0 0;">Laissez vide et cliquez pour que l'IA décide d'après vos photos ; ou écrivez-le vous-même, l'IA le suivra pour proposer les univers.</p>
      </div>
      <ul class="un-list" id="un-list">
        <?php foreach ($universes as $u): $n = $counts[$u['key']] ?? 0; ?>
          <li class="un-row">
            <input type="hidden" name="key[]" value="<?= h($u['key']) ?>">
            <input type="text" name="label[]" value="<?= h($u['label']) ?>" maxlength="40" aria-label="Nom de l'univers" required>
            <select name="icon[]" aria-label="Pictogramme">
              <?php foreach (UNIVERSE_ICONS as $iconKey => $iconLabel): ?>
                <option value="<?= h($iconKey) ?>"<?= $u['icon'] === $iconKey ? ' selected' : '' ?>><?= h($iconLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="un-count"><?= $n ?> pièce<?= $n > 1 ? 's' : '' ?></span>
            <span class="un-move">
              <button type="button" data-up aria-label="Monter">↑</button>
              <button type="button" data-down aria-label="Descendre">↓</button>
              <button type="button" class="un-del" data-del data-count="<?= $n ?>" aria-label="Retirer cet univers">Retirer</button>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="un-actions">
        <button type="button" class="btn-small" id="un-add">+ Ajouter un univers</button>
        <button type="button" class="btn-small" id="un-suggest"<?= GEMINI_API_KEY ? '' : ' disabled title="Clé Gemini non configurée"' ?>>Proposer des univers (IA)</button>
        <button type="submit" class="btn btn-primary">Enregistrer les univers</button>
        <span class="hint" id="un-status" role="status" style="margin:0;"></span>
      </div>
    </form>

    <div class="un-ideas" id="un-ideas" hidden>
      <strong>Propositions de l'IA</strong>, d'après le nom de la boutique, son catalogue et la nature de ses pièces :
      <ul id="un-ideas-list"></ul>
      <button type="button" class="btn-small" id="un-apply">Remplacer la liste par ces univers</button>
      <span class="hint" style="margin:0 0 0 8px;">Rien n'est enregistré avant « Enregistrer les univers ».</span>
    </div>

    <div class="admin-block" style="margin-top:28px;">
      <h2 style="font-size:1.1rem;">Reclasser les pièces par l'IA</h2>
      <p class="hint">L'IA regarde les photos de chaque pièce et choisit son univers parmi ceux ci-dessus (ou n'en choisit aucun s'ils ne conviennent pas : la pièce reste alors telle quelle). Une requête par pièce, d'une dizaine de secondes ; chaque résultat est enregistré tout de suite, vous pouvez arrêter à tout moment.</p>
      <div class="un-actions">
        <?php if ($orphans): ?>
          <button type="button" class="btn btn-primary" id="un-reclass-orphans" data-refs="<?= h(json_encode($orphans)) ?>">Reclasser les <?= count($orphans) ?> pièce<?= count($orphans) > 1 ? 's' : '' ?> sans univers valide</button>
        <?php endif; ?>
        <button type="button" class="btn-small" id="un-reclass-all" data-refs="<?= h(json_encode($allRefs)) ?>"<?= $total ? '' : ' disabled' ?><?= GEMINI_API_KEY ? '' : ' title="Clé Gemini non configurée"' ?>>Reclasser toutes les pièces (<?= $total ?>)</button>
        <span class="hint" id="un-reclass-status" role="status" style="margin:0;"></span>
      </div>
    </div>

    <?php if ($customized): ?>
      <form method="post" action="/admin/universes-action.php" style="margin-top:20px;" onsubmit="return confirm('Revenir aux univers d\'origine de la boutique ?');">
        <input type="hidden" name="action" value="reset">
        <button type="submit" class="btn-small">Rétablir les univers d'origine</button>
      </form>
    <?php endif; ?>
  </div>
</section>
</main>
<template id="un-row-tpl">
  <li class="un-row">
    <input type="hidden" name="key[]" value="">
    <input type="text" name="label[]" value="" maxlength="40" aria-label="Nom de l'univers" required>
    <select name="icon[]" aria-label="Pictogramme">
      <?php foreach (UNIVERSE_ICONS as $iconKey => $iconLabel): ?><option value="<?= h($iconKey) ?>"><?= h($iconLabel) ?></option><?php endforeach; ?>
    </select>
    <span class="un-count">nouveau</span>
    <span class="un-move">
      <button type="button" data-up aria-label="Monter">↑</button>
      <button type="button" data-down aria-label="Descendre">↓</button>
      <button type="button" class="un-del" data-del data-count="0" aria-label="Retirer cet univers">Retirer</button>
    </span>
  </li>
</template>
<script src="/assets/universes.js"></script>
</body>
</html>
