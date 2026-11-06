<?php
// Bourse d'échanges — boîte de réception du commerçant : propositions de vente envoyées depuis /bourse.php, recherches d'achat
// publiées ici, et réponses reçues à ces recherches. Les écritures passent par l'API du plugin (api/action.php), car le socle
// a déjà commencé à envoyer la page quand elle est incluse.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/bourse.php';

$self = plugin_page_url($plugin['id'], 'index');
$kind = isset(BOURSE_KINDS[(string) ($_GET['type'] ?? '')]) ? (string) $_GET['type'] : '';
$all = isset($_GET['tout']);
$items = bourse_list($kind, '', $all);
$counts = bourse_counts();
$pending = (int) ($counts['new'] ?? 0) + (int) ($counts['discussion'] ?? 0);
$published = (int) ($counts['published'] ?? 0);
$statusesFor = static fn (string $itemKind): array => $itemKind === 'recherche'
    ? ['new' => 'À traiter', 'published' => 'Publiée', 'closed' => 'Close', 'refused' => 'Écartée']
    : ['new' => 'À traiter', 'discussion' => 'En discussion', 'accepted' => 'Acceptée', 'refused' => 'Écartée', 'closed' => 'Close'];
$link = static function (array $params = []) use ($self): string {
    return $self . ($params ? '&' . http_build_query($params) : '');
};
?>
<style>
  .bo-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0 22px; }
  .bo-tabs a { text-decoration: none; }
  .bo-tabs a[aria-current="page"] { background: var(--ink); color: var(--bg); }
  .bo-new { background: var(--surface); border: 1px solid var(--line); padding: 16px 18px; margin-bottom: 26px; }
  .bo-new summary { cursor: pointer; font-weight: 600; }
  .bo-new form { display: grid; grid-template-columns: 1fr 220px 180px; gap: 12px; margin-top: 14px; align-items: start; }
  .bo-new .bo-wide { grid-column: 1 / -1; }
  .bo-new input, .bo-new select, .bo-new textarea { width: 100%; padding: 9px 10px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font: inherit; }
  @media (max-width: 760px) { .bo-new form { grid-template-columns: 1fr; } }
  .bo-list { display: flex; flex-direction: column; gap: 16px; }
  .bo-item { border: 1px solid var(--line); background: var(--surface); padding: 16px 18px; }
  .bo-item[data-status="accepted"], .bo-item[data-status="published"] { border-left: 4px solid var(--sage); }
  .bo-item[data-status="refused"], .bo-item[data-status="closed"] { opacity: .62; }
  .bo-head { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: baseline; justify-content: space-between; }
  .bo-head h2 { font-size: 1.12rem; margin: 0; }
  .bo-tags { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
  .bo-tag { font-size: .66rem; letter-spacing: .06em; text-transform: uppercase; border: 1px solid var(--line); border-radius: 999px; padding: 2px 8px; color: var(--ink-soft); white-space: nowrap; }
  .bo-tag.is-offre { color: var(--accent); }
  .bo-tag.is-recherche { color: var(--accent-2); }
  .bo-tag.is-new { color: #b3261e; border-color: currentColor; }
  .bo-desc { white-space: pre-wrap; margin: 10px 0 12px; font-size: .92rem; overflow-wrap: anywhere; }
  .bo-meta { display: flex; flex-wrap: wrap; gap: 4px 16px; color: var(--ink-soft); font-size: .8rem; margin-bottom: 12px; }
  .bo-photo { max-width: 190px; border: 1px solid var(--line); margin: 0 0 12px; }
  .bo-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; padding-top: 12px; border-top: 1px dashed var(--line); }
  .bo-actions form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
  .bo-actions select, .bo-actions input[type=text] { padding: 7px 9px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font: inherit; font-size: .84rem; }
  .bo-actions input[type=text] { min-width: 230px; flex: 1 1 230px; }
  .bo-parent { font-size: .82rem; color: var(--ink-soft); margin: 2px 0 0; }
  .bo-empty { color: var(--ink-soft); margin-top: 10px; }
</style>

<div id="bourse-admin" data-api="<?= h($pluginApi('action')) ?>" data-csrf="<?= h($pluginCsrf) ?>">
  <p class="eyebrow">Achat &amp; vente</p>
  <h1 style="font-size:1.8rem;margin:12px 0 8px;">Bourse d’échanges</h1>
  <p class="hint">Les visiteurs vous proposent des pièces depuis la page publique <a href="/bourse.php" target="_blank">Bourse d’échanges</a>.
    Publiez ici vos recherches d’achat (« je recherche des céramiques ») : elles s’affichent sur cette page et les particuliers peuvent y répondre.
    Une réponse à une recherche arrive dans la même liste, rattachée à la recherche.</p>
  <p class="publish-status" id="bo-status" role="status" hidden></p>

  <details class="bo-new">
    <summary>Publier une recherche d’achat</summary>
    <form method="post" data-bourse="create" action="<?= h($self) ?>">
      <label class="bo-wide">Ce que vous recherchez
        <input type="text" name="title" maxlength="140" required placeholder="Ex. Céramiques de Vallauris, faïence de Gien…">
      </label>
      <label>Catégorie
        <select name="category">
          <option value="">Sans catégorie</option>
          <?php foreach (BOURSE_CATEGORIES as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Budget maximum (€)
        <input type="text" name="price" inputmode="decimal" placeholder="facultatif">
      </label>
      <label>Lieu
        <input type="text" name="location" maxlength="80" placeholder="facultatif">
      </label>
      <label class="bo-wide">Description
        <textarea name="description" rows="4" maxlength="1600" required placeholder="Époque, style, dimensions, état accepté… Les particuliers verront ce texte."></textarea>
      </label>
      <div class="bo-wide"><button class="btn btn-primary" type="submit">Publier la recherche</button></div>
    </form>
  </details>

  <div class="bo-tabs" role="group" aria-label="Filtrer les demandes">
    <a class="btn-small" href="<?= h($self) ?>" aria-current="<?= !$all && $kind === '' ? 'page' : 'false' ?>">À traiter (<?= $pending ?>)</a>
    <a class="btn-small" href="<?= h($link(['tout' => 1])) ?>" aria-current="<?= $all && $kind === '' ? 'page' : 'false' ?>">Toutes (<?= (int) $counts['total'] ?>)</a>
    <a class="btn-small" href="<?= h($link(array_filter(['type' => 'offre', 'tout' => $all ? 1 : null]))) ?>" aria-current="<?= $kind === 'offre' ? 'page' : 'false' ?>">Propositions (<?= (int) ($counts['new'] ?? 0) + (int) ($counts['discussion'] ?? 0) + (int) ($counts['accepted'] ?? 0) ?>)</a>
    <a class="btn-small" href="<?= h($link(['type' => 'recherche', 'tout' => 1])) ?>" aria-current="<?= $kind === 'recherche' ? 'page' : 'false' ?>">Recherches publiées (<?= $published ?>)</a>
  </div>

  <?php if (!$items): ?>
    <p class="bo-empty"><?= $all ? 'Aucune demande pour le moment.' : 'Aucune demande à traiter.' ?></p>
  <?php else: ?>
    <div class="bo-list">
      <?php foreach ($items as $it):
        $isSearch = $it['kind'] === 'recherche';
        $status = (string) $it['status'];
        $replySubject = rawurlencode('Votre proposition — ' . $it['title']);
      ?>
        <article class="bo-item" data-status="<?= h($status) ?>">
          <div class="bo-head">
            <div>
              <div class="bo-tags">
                <span class="bo-tag <?= $isSearch ? 'is-recherche' : 'is-offre' ?>"><?= h(bourse_kind_label((string) $it['kind'])) ?></span>
                <span class="bo-tag<?= $status === 'new' ? ' is-new' : '' ?>"><?= h(bourse_status_label($status)) ?></span>
                <?php if ($it['category'] !== ''): ?><span class="bo-tag"><?= h($it['category']) ?></span><?php endif; ?>
                <?php if ($it['condition'] !== ''): ?><span class="bo-tag"><?= h(bourse_condition_label((string) $it['condition'])) ?></span><?php endif; ?>
              </div>
              <h2><?= h($it['title']) ?></h2>
              <?php if (!empty($it['parent_title'])): ?><p class="bo-parent">En réponse à la recherche « <?= h($it['parent_title']) ?> »</p><?php endif; ?>
            </div>
            <span class="bo-meta" style="margin:0;"><?= h(date('d/m/Y H:i', strtotime($it['created_at'] . ' UTC'))) ?></span>
          </div>

          <?php if ($it['photo']): ?>
            <p style="margin:12px 0 0;"><a href="/<?= h($it['photo']) ?>" target="_blank" rel="noopener"><img class="bo-photo" src="/<?= h($it['photo']) ?>" alt="Photo de <?= h($it['title']) ?>" loading="lazy"></a></p>
          <?php endif; ?>
          <p class="bo-desc"><?= h($it['description']) ?></p>
          <p class="bo-meta">
            <?php if ($it['price_cents']): ?><span><?= $isSearch ? 'Budget' : 'Prix souhaité' ?> : <strong><?= h(bourse_money((int) $it['price_cents'])) ?></strong></span><?php endif; ?>
            <?php if ($it['location'] !== ''): ?><span><?= h($it['location']) ?></span><?php endif; ?>
            <?php if ($it['contact_name'] !== '' || $it['email'] !== ''): ?>
              <span><?= h($it['contact_name']) ?><?= $it['email'] !== '' ? ' · <a href="mailto:' . h($it['email']) . '?subject=' . $replySubject . '">' . h($it['email']) . '</a>' : '' ?><?= $it['phone'] !== '' ? ' · ' . h($it['phone']) : '' ?></span>
            <?php endif; ?>
          </p>

          <div class="bo-actions">
            <form method="post" data-bourse="status" action="<?= h($self) ?>">
              <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <select name="status" aria-label="État de la demande">
                <?php foreach ($statusesFor((string) $it['kind']) as $value => $label): ?>
                  <option value="<?= h($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn-small" type="submit">Mettre à jour</button>
            </form>
            <form method="post" data-bourse="note" action="<?= h($self) ?>">
              <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <input type="text" name="notes" maxlength="2000" value="<?= h((string) $it['notes']) ?>" placeholder="Note interne (jamais publiée)" aria-label="Note interne">
              <button class="btn-small" type="submit">Noter</button>
            </form>
            <form method="post" data-bourse="delete" data-confirm="Supprimer cette demande ?" action="<?= h($self) ?>">
              <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <button class="btn-small" type="submit" title="Supprimer">×</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('admin.js')) ?>" defer></script>
