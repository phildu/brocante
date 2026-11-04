<?php
require __DIR__ . '/_bootstrap.php';

$flash = portail_flash();
$vue = (string) ($_GET['vue'] ?? 'ouvert');
if (!in_array($vue, ['ouvert', 'fait', 'tout'], true)) {
    $vue = 'ouvert';
}
$cat = (string) ($_GET['cat'] ?? '');
if (!isset(TD_CATEGORIES[$cat])) {
    $cat = '';
}
$editId = (int) ($_GET['modifier'] ?? 0);
$edit = $editId > 0 ? td_find($editId) : null;
$confirmId = (int) ($_GET['supprimer'] ?? 0);
$counts = td_count();
$shops = tenant_list();

$open = $vue === 'fait' ? [] : td_tasks('todo', $cat);
$done = $vue === 'ouvert' ? [] : td_tasks('done', $cat);
$tasks = array_merge($open, $done);

$catCounts = [];
foreach (td_tasks('todo') as $task) {
    $catCounts[$task['category']] = ($catCounts[$task['category']] ?? 0) + 1;
}
$shopName = static fn (string $slug): string => $slug !== '' ? (string) ($shops[$slug]['name'] ?? $slug) : '';
$viewUrl = static fn (string $v): string => '/portail/todo/?vue=' . $v . ($cat !== '' ? '&amp;cat=' . e($cat) : '');
$catUrl = static fn (string $c): string => '/portail/todo/?vue=' . e($vue) . ($c !== '' ? '&amp;cat=' . e($c) : '');
$f = $edit ?: ['title' => '', 'detail' => '', 'category' => 'autre', 'priority' => 2, 'shop' => '', 'link' => ''];
$taskUrl = static fn (int $id, string $param): string => '/portail/todo/?'
    . $param . '=' . $id . '&amp;vue=' . e($vue) . ($cat !== '' ? '&amp;cat=' . e($cat) : '');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Tâches et paramétrages — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<link rel="stylesheet" href="/portail/todo/todo.css">
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Tâches et paramétrages',
    'eyebrow' => 'Pilotage',
    'desc' => "Ce qui reste à configurer sur Brocs, dans l'ordre de priorité. Les tâches sont aussi rappelées sur le tableau de bord.",
    'actions' => '<a class="btn btn-primary" href="#nouvelle">Nouvelle tâche</a>',
]); ?>

  <?php if ($flash): ?>
    <p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p>
  <?php endif; ?>

  <div class="twrap">

  <section class="td-stats" aria-label="Avancement">
    <a class="td-stat" href="/portail/todo/?vue=ouvert">
      <span class="td-stat-num"><?= (int) $counts['open'] ?></span>
      <span class="td-stat-label">À faire</span>
    </a>
    <a class="td-stat<?= $counts['urgent'] ? ' is-urgent' : '' ?>" href="/portail/todo/?vue=ouvert">
      <span class="td-stat-num"><?= (int) $counts['urgent'] ?></span>
      <span class="td-stat-label">Prioritaires</span>
    </a>
    <a class="td-stat" href="/portail/todo/?vue=fait">
      <span class="td-stat-num"><?= (int) $counts['done'] ?></span>
      <span class="td-stat-label">Faites</span>
    </a>
  </section>

  <div class="td-grid">
    <aside class="td-form-col" id="nouvelle">
      <form class="td-form" method="post" action="/portail/todo/action.php">
        <h2><?= $edit ? 'Modifier la tâche' : 'Nouvelle tâche' ?></h2>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
        <?php if ($edit): ?>
          <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
          <input type="hidden" name="vue" value="<?= e($vue) ?>">
          <input type="hidden" name="cat" value="<?= e($cat) ?>">
        <?php endif; ?>

        <label class="field">Tâche
          <input name="title" required maxlength="160" value="<?= e($f['title']) ?>" placeholder="Ex. Brancher le webhook Stripe de la boutique X">
        </label>
        <label class="field">Ce qu'il faut faire
          <textarea name="detail" rows="5" maxlength="1200" placeholder="Où aller, quel bouton, quel fichier, ce qui manque…"><?= e($f['detail']) ?></textarea>
        </label>
        <div class="td-two">
          <label class="field">Catégorie
            <select name="category">
              <?php foreach (TD_CATEGORIES as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $f['category'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">Priorité
            <select name="priority">
              <?php foreach (TD_PRIORITIES as $value => $label): ?>
                <option value="<?= $value ?>"<?= (int) $f['priority'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <label class="field">Boutique concernée (facultatif)
          <select name="shop">
            <option value="">Toutes / aucune</option>
            <?php foreach ($shops as $slug => $shop): ?>
              <option value="<?= e($slug) ?>"<?= $f['shop'] === $slug ? ' selected' : '' ?>><?= e($shop['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">Lien (facultatif)
          <input name="link" type="url" maxlength="500" value="<?= e($f['link']) ?>" placeholder="https://…">
        </label>
        <div class="td-form-actions">
          <button class="btn btn-primary" type="submit"><?= $edit ? 'Enregistrer' : 'Ajouter la tâche' ?></button>
          <?php if ($edit): ?><a class="btn" href="/portail/todo/">Annuler</a><?php endif; ?>
        </div>
      </form>
    </aside>

    <main class="td-list-col">
      <div class="td-filters">
        <nav class="td-views" aria-label="Filtrer par état">
          <a class="td-view" href="<?= $viewUrl('ouvert') ?>"<?= $vue === 'ouvert' ? ' aria-current="page"' : '' ?>>À faire <span><?= (int) $counts['open'] ?></span></a>
          <a class="td-view" href="<?= $viewUrl('fait') ?>"<?= $vue === 'fait' ? ' aria-current="page"' : '' ?>>Faites <span><?= (int) $counts['done'] ?></span></a>
          <a class="td-view" href="<?= $viewUrl('tout') ?>"<?= $vue === 'tout' ? ' aria-current="page"' : '' ?>>Tout <span><?= (int) $counts['open'] + (int) $counts['done'] ?></span></a>
        </nav>
        <?php if ($catCounts || $cat !== ''): ?>
          <div class="td-cats" aria-label="Filtrer par catégorie">
            <a class="td-chip" href="<?= $catUrl('') ?>"<?= $cat === '' ? ' aria-current="page"' : '' ?>>Toutes</a>
            <?php foreach (TD_CATEGORIES as $key => $label): ?>
              <?php if (!isset($catCounts[$key]) && $cat !== $key) continue; ?>
              <a class="td-chip" href="<?= $catUrl($key) ?>"<?= $cat === $key ? ' aria-current="page"' : '' ?>>
                <span class="td-dot" style="background:<?= e(TD_CATEGORY_COLORS[$key]) ?>"></span><?= e($label) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$tasks): ?>
        <p class="td-empty">
          <?php if ($vue === 'fait'): ?>Aucune tâche terminée pour le moment.
          <?php elseif ($cat !== ''): ?>Rien à faire dans « <?= e(TD_CATEGORIES[$cat]) ?> ».
          <?php else: ?>Tout est fait. Ajoutez une tâche quand un réglage reste à brancher.
          <?php endif; ?>
        </p>
      <?php else: ?>
        <ul class="td-list">
          <?php foreach ($tasks as $task): $isDone = $task['status'] === 'done'; ?>
            <li class="td-item<?= $isDone ? ' is-done' : '' ?>">
              <form class="td-toggle" method="post" action="/portail/todo/action.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                <input type="hidden" name="vue" value="<?= e($vue) ?>">
                <input type="hidden" name="cat" value="<?= e($cat) ?>">
                <button class="td-check" type="submit" aria-pressed="<?= $isDone ? 'true' : 'false' ?>" aria-label="<?= $isDone ? 'Remettre à faire' : 'Marquer comme fait' ?> : <?= e($task['title']) ?>">
                  <span aria-hidden="true"></span>
                </button>
              </form>

              <div class="td-body">
                <div class="td-title-row">
                  <h3 class="td-title"><?= e($task['title']) ?></h3>
                  <?php if ($task['link'] !== ''): ?>
                    <a class="td-link" href="<?= e($task['link']) ?>" target="_blank" rel="noopener noreferrer">Ouvrir &#8599;</a>
                  <?php endif; ?>
                </div>
                <?php if ($task['detail'] !== ''): ?>
                  <p class="td-detail"><?= nl2br(e($task['detail'])) ?></p>
                <?php endif; ?>
                <div class="td-meta">
                  <span class="td-pill">
                    <span class="td-dot" style="background:<?= e(TD_CATEGORY_COLORS[$task['category']] ?? TD_CATEGORY_COLORS['autre']) ?>"></span>
                    <?= e(TD_CATEGORIES[$task['category']] ?? TD_CATEGORIES['autre']) ?>
                  </span>
                  <?php if ((int) $task['priority'] === 1): ?>
                    <span class="td-prio" data-p="1">Prioritaire</span>
                  <?php elseif ((int) $task['priority'] === 3): ?>
                    <span class="td-prio" data-p="3">Plus tard</span>
                  <?php endif; ?>
                  <?php if ($shopName($task['shop']) !== ''): ?>
                    <span class="td-shop"><?= e($shopName($task['shop'])) ?></span>
                  <?php endif; ?>
                  <?php if ($isDone && $task['done_at']): ?>
                    <span class="td-done-at">Fait le <?= e(date('d/m/Y', strtotime($task['done_at']))) ?></span>
                  <?php endif; ?>
                </div>

                <?php if ($confirmId === (int) $task['id']): ?>
                  <form class="td-confirm" method="post" action="/portail/todo/action.php">
                    <span>Supprimer définitivement « <strong><?= e($task['title']) ?></strong> » ?</span>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                    <input type="hidden" name="vue" value="<?= e($vue) ?>">
                    <input type="hidden" name="cat" value="<?= e($cat) ?>">
                    <span class="td-confirm-actions">
                      <button class="btn small danger-solid" type="submit">Supprimer</button>
                      <a class="btn small" href="<?= $viewUrl($vue) ?>">Annuler</a>
                    </span>
                  </form>
                <?php endif; ?>
              </div>

              <div class="td-actions">
                <a class="btn small" href="<?= $taskUrl((int) $task['id'], 'modifier') ?>">Modifier</a>
                <a class="btn small danger" href="<?= $taskUrl((int) $task['id'], 'supprimer') ?>">Supprimer</a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </main>
  </div>
  </div>
</div>
</body>
</html>
