<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$batch = $_SESSION['batch_import'] ?? null;
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Import par lot — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .bi-group-card { background: var(--surface); border: 1px solid var(--line); padding: 18px 20px; margin-top: 20px; }
  .bi-group-card.is-done { opacity: 0.7; }
  .bi-group-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
  .bi-group-head h2 { font-size: 1.05rem; margin: 0; }
  .bi-photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; }
  .bi-photo-item { position: relative; border: 2px solid var(--line); background: var(--bg); cursor: pointer; padding: 0; }
  .bi-photo-item.is-chosen { border-color: var(--accent); }
  .bi-photo-item img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; }
  .bi-photo-item .bi-score { position: absolute; top: 4px; right: 4px; background: var(--bg); border: 1px solid var(--line); font-family: var(--font-mono); font-size: 0.68rem; padding: 1px 5px; }
  .bi-photo-item .bi-group-select { width: 100%; margin-top: 4px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 0.75rem; padding: 3px; }
  .bi-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'batch'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Catalogue</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Import par lot</h1>
    <p class="hint">
      Déposez un zip de photos prises en vrac (plusieurs pièces mélangées). Elles sont regroupées
      automatiquement par pièce selon l'heure de prise de vue, à corriger si besoin. Pour chaque
      groupe : l'IA suggère la photo la plus simple à détourer, la détoure, la met en situation, puis
      rédige une fiche produit — créée <strong>masquée</strong> pour relecture avant publication.
    </p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php if (!$batch): ?>
      <div class="admin-block" style="margin-top:20px;">
        <h2 style="font-size:1.1rem;">Déposer un zip de photos</h2>
        <form method="post" action="/admin/batch-import-action.php" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;">
          <input type="hidden" name="action" value="upload">
          <input type="file" name="zip" accept=".zip" required>
          <button type="submit" class="btn btn-primary">Extraire et regrouper</button>
        </form>
      </div>

    <?php elseif (!$batch['confirmed']): ?>
      <?php if (!empty($batch['skipped_files'])): ?>
        <p class="hint" style="margin-top:16px;">Fichiers ignorés (format non pris en charge) : <?= h(implode(', ', $batch['skipped_files'])) ?></p>
      <?php endif; ?>

      <?php $aiRemaining = count($batch['sorted_paths'] ?? []) - ($batch['ai_processed_count'] ?? 0); ?>
      <?php if ($aiRemaining > 0): ?>
        <div class="admin-block" style="margin-top:16px;">
          <p class="hint" style="margin:0 0 10px;">
            <?= (int) ($batch['ai_processed_count'] ?? 0) ?> photo(s) sur <?= count($batch['sorted_paths'] ?? []) ?> analysées par IA —
            les <?= $aiRemaining ?> restantes sont groupées par heure de prise de vue en attendant. Chaque clic analyse un nouveau lot
            (limité pour rester rapide) ; recommencer maintenant écrase les corrections manuelles faites ci-dessous.
          </p>
          <form method="post" action="/admin/batch-import-action.php">
            <input type="hidden" name="action" value="ai_regroup_chunk">
            <?php $nextChunkSize = min($aiRemaining, BATCH_IMPORT_AI_CHUNK_SIZE); ?>
            <button type="submit" class="btn-small">Analyser le lot suivant par IA (<?= $nextChunkSize ?> photo<?= $nextChunkSize > 1 ? 's' : '' ?>)</button>
          </form>
        </div>
      <?php endif; ?>

      <form method="post" action="/admin/batch-import-action.php" id="regroup-form">
        <input type="hidden" name="action" value="regroup">
        <?php foreach ($batch['groups'] as $groupIndex => $group): ?>
          <div class="bi-group-card">
            <div class="bi-group-head"><h2>Groupe <?= $groupIndex + 1 ?> (<?= count($group['photos']) ?> photo<?= count($group['photos']) > 1 ? 's' : '' ?>)</h2></div>
            <div class="bi-photo-grid">
              <?php foreach ($group['photos'] as $photo): ?>
                <div class="bi-photo-item">
                  <img src="/<?= h($photo) ?>" alt="">
                  <select class="bi-group-select" name="group[<?= h($photo) ?>]">
                    <?php for ($n = 0; $n < count($batch['groups']) + 1; $n++): ?>
                      <option value="<?= $n ?>"<?= $n === $groupIndex ? ' selected' : '' ?>>Groupe <?= $n + 1 ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="bi-actions">
          <button type="submit" class="btn-small">Recalculer les groupes</button>
        </div>
      </form>

      <form method="post" action="/admin/batch-import-action.php" style="margin-top:20px;">
        <input type="hidden" name="action" value="confirm_grouping">
        <button type="submit" class="btn btn-primary">Valider le regroupement (<?= count($batch['groups']) ?> pièce<?= count($batch['groups']) > 1 ? 's' : '' ?>)</button>
      </form>

    <?php else: ?>
      <?php foreach ($batch['groups'] as $groupIndex => $group): ?>
        <div class="bi-group-card<?= $group['status'] !== 'pending' ? ' is-done' : '' ?>">
          <div class="bi-group-head">
            <h2>Pièce <?= $groupIndex + 1 ?></h2>
            <?php if ($group['status'] === 'done'): ?>
              <a class="btn-small" href="/admin/catalog.php#produit-<?= h($group['product_ref']) ?>" target="_blank">Fiche créée (Réf. N°<?= h($group['product_ref']) ?>) — relire →</a>
            <?php elseif ($group['status'] === 'skipped'): ?>
              <span class="publish-status" data-kind="">Ignoré</span>
            <?php endif; ?>
          </div>

          <?php if ($group['status'] === 'pending'): ?>
            <form method="post" action="/admin/batch-import-action.php">
              <input type="hidden" name="action" value="choose_photo">
              <input type="hidden" name="group_index" value="<?= $groupIndex ?>">
              <div class="bi-photo-grid">
                <?php foreach ($group['photos'] as $photoIndex => $photo): ?>
                  <button type="submit" name="photo_index" value="<?= $photoIndex ?>" class="bi-photo-item<?= $photoIndex === $group['chosen_index'] ? ' is-chosen' : '' ?>" style="border:none;text-align:left;">
                    <img src="/<?= h($photo) ?>" alt="">
                    <?php if (isset($group['scores'][$photoIndex])): ?>
                      <span class="bi-score"><?= $group['scores'][$photoIndex] !== null ? (int) $group['scores'][$photoIndex] . '/10' : '?' ?></span>
                    <?php endif; ?>
                  </button>
                <?php endforeach; ?>
              </div>
            </form>

            <div class="bi-actions">
              <form method="post" action="/admin/batch-import-action.php">
                <input type="hidden" name="action" value="suggest_best_photo">
                <input type="hidden" name="group_index" value="<?= $groupIndex ?>">
                <button type="submit" class="btn-small">Suggérer la plus simple à détourer (IA)</button>
              </form>
              <form method="post" action="/admin/batch-import-action.php">
                <input type="hidden" name="action" value="process_group">
                <input type="hidden" name="group_index" value="<?= $groupIndex ?>">
                <button type="submit" class="btn btn-primary">Traiter cette fiche →</button>
              </form>
              <form method="post" action="/admin/batch-import-action.php" onsubmit="return confirm('Ignorer ce groupe sans créer de fiche ?');">
                <input type="hidden" name="action" value="skip_group">
                <input type="hidden" name="group_index" value="<?= $groupIndex ?>">
                <button type="submit" class="admin-delete">Ignorer</button>
              </form>
            </div>
          <?php else: ?>
            <div class="bi-photo-grid">
              <?php foreach ($group['photos'] as $photo): ?>
                <div class="bi-photo-item"><img src="/<?= h($photo) ?>" alt=""></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <form method="post" action="/admin/batch-import-action.php" style="margin-top:24px;">
        <input type="hidden" name="action" value="finish">
        <button type="submit" class="btn btn-primary">Terminer l'import</button>
      </form>
    <?php endif; ?>
  </div>
</section>
</main>
</body>
</html>
