<?php
// Bourse d'échanges de la galerie (voir includes/gallery-bourse.php) : propositions reçues des visiteurs, recherches d'achat publiées
// par l'organisateur et réponses à ces recherches. Page publique : /galerie/bourse.php?g=<identifiant>.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-bourse.php';
require_once PORTAIL_ROOT . '/includes/gallery-types.php';

$selfLink = static function (array $params = []) use ($limited, $gaSlug): string {
    if (!$limited) $params = ['g' => $gaSlug] + $params;
    return '/portail/galerie-bourse.php' . ($params ? '?' . http_build_query($params) : '');
};
$self = $selfLink();
$publicUrl = '/galerie/bourse.php?g=' . rawurlencode($gaSlug);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $msg = null;
    $kind = 'ok';
    switch ((string) ($_POST['action'] ?? '')) {
        case 'status':
            $err = gbo_set_status($gaSlug, $id, (string) ($_POST['status'] ?? ''));
            [$msg, $kind] = $err ? [$err, 'error'] : ['État mis à jour.', 'ok'];
            break;
        case 'note':
            [$msg, $kind] = gbo_set_note($gaSlug, $id, (string) ($_POST['notes'] ?? ''))
                ? ['Note interne enregistrée.', 'ok'] : ['Demande introuvable.', 'error'];
            break;
        case 'delete':
            [$msg, $kind] = gbo_delete($gaSlug, $id) ? ['Demande supprimée.', 'ok'] : ['Demande introuvable.', 'error'];
            break;
        case 'create':
            $_POST['kind'] = 'recherche';
            [$newId, $err] = gbo_add($gaSlug, $_POST, null, true);
            [$msg, $kind] = $err ? [$err, 'error'] : ['Recherche publiée : elle est visible sur la page publique.', 'ok'];
            break;
        default:
            [$msg, $kind] = ['Action non reconnue.', 'error'];
    }
    portail_flash($msg, $kind);
    header('Location: ' . $self);
    exit;
}

$kindFilter = isset(GBO_KINDS[(string) ($_GET['type'] ?? '')]) ? (string) $_GET['type'] : '';
$all = isset($_GET['tout']);
$items = gbo_list($gaSlug, $kindFilter, '', $all);
$counts = gbo_counts($gaSlug);
$pending = (int) ($counts['new'] ?? 0) + (int) ($counts['discussion'] ?? 0);
$statusesFor = static fn (string $itemKind): array => $itemKind === 'recherche'
    ? ['new' => 'À traiter', 'published' => 'Publiée', 'closed' => 'Close', 'refused' => 'Écartée']
    : ['new' => 'À traiter', 'discussion' => 'En discussion', 'accepted' => 'Acceptée', 'published' => 'Montrer sur la page publique', 'refused' => 'Écartée', 'closed' => 'Close'];

ga_head('Bourse d’échanges', '<a class="btn" href="' . e($publicUrl) . '" target="_blank" rel="noopener">Page publique ↗</a>');
?>
    <?php if (!gt_feature($gaGallery, 'bourse')): ?>
      <section class="gcard">
        <h2>Fonctionnalité absente de ce type de galerie</h2>
        <p class="meta" style="margin:0;">La bourse d’échanges se règle dans <a href="/portail/galerie-types.php">Types de galerie</a> (fonctionnalité « Bourse d’échanges »).</p>
      </section>
    <?php else: ?>
    <section class="gcard">
      <h2 style="margin:0;">Bourse d’échanges</h2>
      <p class="meta" style="margin:6px 0 0;">Les visiteurs proposent une pièce à la vente depuis la page publique. Publiez ici vos recherches
        d’achat (« je recherche des céramiques ») : elles s’affichent sur cette page et les particuliers peuvent y répondre. Une réponse
        à une recherche arrive dans cette même liste, rattachée à la recherche.</p>
      <div class="kpis" style="margin-top:14px;">
        <div class="kpi"><b><?= $pending ?></b><span>à traiter</span></div>
        <div class="kpi"><b><?= (int) ($counts['published'] ?? 0) ?></b><span>pièces montrées</span></div>
        <div class="kpi"><b><?= count(gbo_public_searches($gaSlug)) ?></b><span>recherches publiées</span></div>
        <div class="kpi"><b><?= (int) $counts['total'] ?></b><span>demandes reçues</span></div>
      </div>
    </section>

    <section class="gcard">
      <details>
        <summary style="cursor:pointer;font-weight:700;">Publier une recherche d’achat</summary>
        <form method="post" action="<?= e($self) ?>" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="create">
          <div class="row">
            <label class="field" style="flex:1 1 100%;">Ce que vous recherchez
              <input type="text" name="title" maxlength="140" required placeholder="Ex. Céramiques de Vallauris, faïence de Gien…">
            </label>
          </div>
          <div class="row">
            <label class="field">Catégorie
              <select name="category">
                <option value="">Sans catégorie</option>
                <?php foreach (GBO_CATEGORIES as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label class="field">Budget maximum (€)
              <input type="text" name="price" inputmode="decimal" placeholder="facultatif">
            </label>
            <label class="field">Lieu
              <input type="text" name="location" maxlength="80" placeholder="facultatif">
            </label>
          </div>
          <label class="field">Description
            <textarea name="description" rows="4" maxlength="1600" required placeholder="Époque, style, dimensions, état accepté… Les visiteurs verront ce texte."></textarea>
          </label>
          <button class="btn btn-primary" type="submit">Publier la recherche</button>
        </form>
      </details>
    </section>

    <section class="gcard">
      <div class="row" style="margin-bottom:10px;">
        <a class="btn<?= !$all && $kindFilter === '' ? ' btn-primary' : '' ?>" href="<?= e($selfLink()) ?>">À traiter (<?= $pending ?>)</a>
        <a class="btn<?= $all && $kindFilter === '' ? ' btn-primary' : '' ?>" href="<?= e($selfLink(['tout' => 1])) ?>">Toutes (<?= (int) $counts['total'] ?>)</a>
        <a class="btn<?= $kindFilter === 'offre' ? ' btn-primary' : '' ?>" href="<?= e($selfLink(['type' => 'offre'] + ($all ? ['tout' => 1] : []))) ?>">Propositions</a>
        <a class="btn<?= $kindFilter === 'recherche' ? ' btn-primary' : '' ?>" href="<?= e($selfLink(['type' => 'recherche', 'tout' => 1])) ?>">Recherches</a>
      </div>

      <?php if (!$items): ?>
        <p class="meta" style="margin:0;"><?= $all ? 'Aucune demande pour le moment.' : 'Aucune demande à traiter.' ?></p>
      <?php endif; ?>

      <?php foreach ($items as $it):
        $isSearch = $it['kind'] === 'recherche';
        $status = (string) $it['status'];
        $subject = rawurlencode('Votre proposition — ' . $it['title']);
      ?>
        <article style="border-top:1px solid var(--line);padding:14px 0 6px;">
          <div class="row" style="justify-content:space-between;align-items:flex-start;">
            <div>
              <div class="row" style="gap:6px;">
                <span class="pill <?= $isSearch ? 'sent' : '' ?>"><?= e(gbo_kind_label((string) $it['kind'])) ?></span>
                <span class="pill<?= $status === 'new' ? ' sent' : '' ?>"><?= e(gbo_status_label($status)) ?></span>
                <?php if ($it['category'] !== ''): ?><span class="pill"><?= e($it['category']) ?></span><?php endif; ?>
                <?php if ($it['condition'] !== ''): ?><span class="pill"><?= e($it['condition']) ?></span><?php endif; ?>
              </div>
              <h3 style="margin:6px 0 0;"><?= e($it['title']) ?></h3>
              <?php if (!empty($it['parent_id']) && ($parentItem = gbo_get($gaSlug, (int) $it['parent_id']))): ?>
                <p class="meta" style="margin:2px 0 0;">En réponse à la recherche « <?= e($parentItem['title']) ?> »</p>
              <?php endif; ?>
            </div>
            <span class="meta"><?= e(date('d/m/Y H:i', strtotime($it['created_at'] . ' UTC'))) ?></span>
          </div>
          <?php if ($it['photo']): ?><p style="margin:8px 0 0;"><img src="/<?= e($it['photo']) ?>" alt="<?= e($it['title']) ?>" style="max-width:180px;border:1px solid var(--line);border-radius:8px;"></p><?php endif; ?>
          <p style="white-space:pre-wrap;margin:8px 0;font-size:.92rem;overflow-wrap:anywhere;"><?= e($it['description']) ?></p>
          <p class="meta" style="margin:0 0 10px;">
            <?php if ($it['price_cents']): ?><span><?= $isSearch ? 'Budget' : 'Prix souhaité' ?> : <strong><?= e(gbo_money((int) $it['price_cents'])) ?></strong></span> · <?php endif; ?>
            <?php if ($it['location'] !== ''): ?><span><?= e($it['location']) ?></span> · <?php endif; ?>
            <?php if ($it['contact_name'] !== ''): ?><span><?= e($it['contact_name']) ?></span><?php endif; ?>
            <?php if ($it['email'] !== ''): ?> · <a href="mailto:<?= e($it['email']) ?>?subject=<?= $subject ?>"><?= e($it['email']) ?></a><?php endif; ?>
            <?php if ($it['phone'] !== ''): ?> · <?= e($it['phone']) ?><?php endif; ?>
          </p>
          <div class="row">
            <form method="post" action="<?= e($self) ?>" class="row">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <input type="hidden" name="action" value="status">
              <select name="status" aria-label="État de la demande">
                <?php foreach ($statusesFor((string) $it['kind']) as $value => $label): ?>
                  <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn" type="submit">Mettre à jour</button>
            </form>
            <form method="post" action="<?= e($self) ?>" class="row" style="flex:1 1 220px;">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <input type="hidden" name="action" value="note">
              <input type="text" name="notes" maxlength="2000" value="<?= e($it['notes']) ?>" placeholder="Note interne (jamais publiée)" aria-label="Note interne" style="flex:1 1 180px;">
              <button class="btn" type="submit">Noter</button>
            </form>
            <form method="post" action="<?= e($self) ?>" onsubmit="return confirm('Supprimer cette demande ?');">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn" type="submit" title="Supprimer">×</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
<?php ga_foot(); ?>
