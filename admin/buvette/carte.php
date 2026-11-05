<?php
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

/** Champs d'un article depuis le POST ; retourne [valeurs, erreur]. */
function bv_item_fields_from_post(): array
{
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') return [null, 'Le nom de l\'article est obligatoire.'];
    $price = bv_parse_cents((string) ($_POST['price'] ?? ''));
    if ($price === null) return [null, 'Prix invalide pour « ' . $name . ' » (exemple : 1,50).'];
    $cat = (string) ($_POST['category'] ?? 'autre');
    if (!isset(bv_item_categories()[$cat])) $cat = 'autre';
    $allergens = array_values(array_intersect(array_keys(bv_allergens()), (array) ($_POST['allergens'] ?? [])));
    return [[
        'name' => mb_substr($name, 0, 100),
        'category' => $cat,
        'price' => $price,
        'description' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 300),
        'allergens' => implode(',', $allergens),
        'active' => !empty($_POST['is_active']) ? 1 : 0,
        'sort' => (int) ($_POST['sort_order'] ?? 0),
    ], null];
}

switch (bv_post_action()) {
    case 'add_item': {
        [$f, $err] = bv_item_fields_from_post();
        if ($err) bv_redirect('/admin/buvette/carte.php', $err, 'error');
        $next = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM bv_items')->fetchColumn();
        db()->prepare('INSERT INTO bv_items (name, category, price_cents, description, allergens, is_active, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)')
            ->execute([$f['name'], $f['category'], $f['price'], $f['description'], $f['allergens'], $next]);
        bv_redirect('/admin/buvette/carte.php', '« ' . $f['name'] . ' » ajouté à la carte. Ajoutez maintenant sa recette pour connaître son coût de revient.');
    }

    case 'update_item': {
        $id = (int) ($_POST['id'] ?? 0);
        [$f, $err] = bv_item_fields_from_post();
        if (!bv_item_get($id)) bv_redirect('/admin/buvette/carte.php', 'Article introuvable.', 'error');
        if ($err) bv_redirect('/admin/buvette/carte.php', $err, 'error');
        db()->prepare('UPDATE bv_items SET name = ?, category = ?, price_cents = ?, description = ?, allergens = ?, is_active = ?, sort_order = ? WHERE id = ?')
            ->execute([$f['name'], $f['category'], $f['price'], $f['description'], $f['allergens'], $f['active'], $f['sort'], $id]);
        bv_redirect('/admin/buvette/carte.php', '« ' . $f['name'] . ' » enregistré.');
    }

    case 'delete_item': {
        $id = (int) ($_POST['id'] ?? 0);
        $item = bv_item_get($id);
        if ($item) db()->prepare('DELETE FROM bv_items WHERE id = ?')->execute([$id]);
        bv_redirect('/admin/buvette/carte.php', $item ? '« ' . $item['name'] . ' » supprimé (avec sa recette et ses prévisions).' : 'Article introuvable.', $item ? 'ok' : 'error');
    }
}

$costs = bv_item_costs();
$poster = !empty($_GET['affiche']);
$content = get_content();

if ($poster):
    bv_admin_header('Affiche de la carte', 'carte');
    $groups = bv_items_by_category(true);
    ?>
  <div class="bv-actions bv-noprint" style="margin:0 0 16px;">
    <button class="btn btn-primary" type="button" onclick="window.print()">Imprimer l'affiche</button>
    <a class="btn btn-ghost" href="/admin/buvette/carte.php">← Retour à la carte</a>
  </div>
  <div class="bv-poster">
    <h1>La Buvette</h1>
    <p class="sub"><?= h($content['site_name'] ?? '') ?></p>
    <?php if (!$groups): ?><p>La carte est vide.</p><?php endif; ?>
    <?php foreach ($groups as $cat => $items): ?>
      <h2><?= h(bv_item_categories()[$cat]) ?></h2>
      <?php foreach ($items as $it): ?>
        <div class="row"><span class="name"><?= h($it['name']) ?></span><span class="dots"></span><span class="price"><?= h(bv_money($it['price_cents'])) ?></span></div>
        <?php $al = bv_allergen_labels($it); if ($al): ?><p class="allergens">Contient : <?= h(implode(', ', $al)) ?></p><?php endif; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <p class="legal">Les allergènes présents dans chaque préparation sont indiqués sous l'article. Une question ? Demandez à l'équipe de la buvette.</p>
  </div>
<?php
    bv_admin_footer();
    exit;
endif;

bv_admin_header('Carte & prix', 'carte');
$groups = bv_items_by_category(false);
?>
<p class="bv-hint">Ce que vous vendez, à quel prix, avec les allergènes. Le coût de revient vient des recettes (onglet « Recettes ») : il permet de vérifier que chaque prix laisse une marge.</p>

<div class="bv-actions bv-noprint" style="margin:0 0 6px;">
  <a class="btn btn-ghost" href="/admin/buvette/carte.php?affiche=1">Voir / imprimer l'affiche de la carte</a>
</div>

<?php if (!$groups): ?>
  <p class="bv-callout">La carte est vide. Ajoutez votre premier article ci-dessous, ou <a href="/admin/buvette/index.php">chargez la buvette d'exemple</a> depuis le tableau de bord.</p>
<?php endif; ?>

<?php foreach ($groups as $cat => $items): ?>
  <h2 class="bv-cat-title"><?= h(bv_item_categories()[$cat]) ?></h2>
  <?php foreach ($items as $it):
    $cost = $costs[(int) $it['id']] ?? null;
    $margin = $cost !== null ? $it['price_cents'] - $cost : null;
    $marginPct = $margin !== null && $it['price_cents'] > 0 ? (int) round($margin * 100 / $it['price_cents']) : null;
    $allergens = bv_item_allergens($it);
    ?>
    <details class="bv-fold <?= $it['is_active'] ? '' : 'is-inactive' ?>">
      <summary>
        <span class="sum-main">
          <span class="sum-name"><?= h($it['name']) ?></span>
          <span class="sum-meta"><?= h(bv_money($it['price_cents'])) ?></span>
          <?php if ($cost === null): ?><span class="bv-badge">recette à saisir</span>
          <?php else: ?><span class="sum-meta">revient à <?= h(bv_money($cost)) ?> · marge <?= h(bv_money($margin)) ?><?= $marginPct !== null ? ' (' . $marginPct . ' %)' : '' ?></span>
          <?php if ($margin < 0): ?><span class="bv-badge bad">vendu à perte</span><?php endif; endif; ?>
        </span>
        <?php if (!$it['is_active']): ?><span class="bv-badge">masqué</span><?php endif; ?>
        <?php if ($allergens): ?><span class="bv-muted" style="font-size:0.78rem;"><?= count($allergens) ?> allergène<?= count($allergens) > 1 ? 's' : '' ?></span><?php endif; ?>
      </summary>
      <div class="bv-fold-body">
        <?= bv_form_open('update_item', ['id' => $it['id']]) ?>
          <div class="bv-form-grid" style="margin-top:14px;">
            <div class="field span-2"><label>Nom</label><input type="text" name="name" value="<?= h($it['name']) ?>" required maxlength="100"></div>
            <div class="field"><label>Prix (€)</label><input type="text" inputmode="decimal" name="price" value="<?= h(number_format($it['price_cents'] / 100, 2, ',', '')) ?>" required></div>
            <div class="field"><label>Catégorie</label>
              <select name="category"><?php foreach (bv_item_categories() as $k => $label): ?><option value="<?= h($k) ?>" <?= $it['category'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
            </div>
            <div class="field span-2"><label>Description (optionnelle)</label><input type="text" name="description" value="<?= h($it['description']) ?>" maxlength="300"></div>
            <div class="field"><label>Ordre d'affichage</label><input type="number" name="sort_order" value="<?= (int) $it['sort_order'] ?>"></div>
          </div>
          <p style="margin:16px 0 4px;" class="bv-hint"><strong>Allergènes présents</strong> — cochez tout ce que contient la préparation (vérifiez les étiquettes des produits achetés).</p>
          <div class="bv-allergen-grid">
            <?php foreach (bv_allergens() as $k => $label): ?>
              <label class="bv-check"><input type="checkbox" name="allergens[]" value="<?= h($k) ?>" <?= in_array($k, $allergens, true) ? 'checked' : '' ?>> <?= h($label) ?></label>
            <?php endforeach; ?>
          </div>
          <label class="bv-check" style="margin-top:16px;"><input type="checkbox" name="is_active" value="1" <?= $it['is_active'] ? 'checked' : '' ?>> Proposé à la carte (décochez pour le masquer sans le supprimer)</label>
          <div class="bv-actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
        </form>
        <?= bv_form_open('delete_item', ['id' => $it['id']], 'Supprimer « ' . $it['name'] . ' » ? Sa recette et ses prévisions seront aussi supprimées.', 'bv-inline') ?>
          <div class="bv-actions"><button class="btn-small bv-btn-danger" type="submit">Supprimer cet article</button></div>
        </form>
      </div>
    </details>
  <?php endforeach; ?>
<?php endforeach; ?>

<div class="bv-card" id="ajouter" style="margin-top:26px;">
  <h2>Ajouter un article</h2>
  <?= bv_form_open('add_item') ?>
    <div class="bv-form-grid" style="margin-top:12px;">
      <div class="field span-2"><label>Nom</label><input type="text" name="name" required maxlength="100" placeholder="Crêpe au sucre"></div>
      <div class="field"><label>Prix (€)</label><input type="text" inputmode="decimal" name="price" required placeholder="1,50"></div>
      <div class="field"><label>Catégorie</label>
        <select name="category"><?php foreach (bv_item_categories() as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field span-2"><label>Description (optionnelle)</label><input type="text" name="description" maxlength="300"></div>
    </div>
    <details style="margin-top:14px;">
      <summary style="cursor:pointer;font-weight:600;font-size:0.9rem;">Allergènes présents</summary>
      <div class="bv-allergen-grid">
        <?php foreach (bv_allergens() as $k => $label): ?>
          <label class="bv-check"><input type="checkbox" name="allergens[]" value="<?= h($k) ?>"> <?= h($label) ?></label>
        <?php endforeach; ?>
      </div>
    </details>
    <div class="bv-actions"><button class="btn btn-primary" type="submit">Ajouter à la carte</button></div>
  </form>
</div>
<?php
bv_admin_footer();
