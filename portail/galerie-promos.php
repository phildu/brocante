<?php
// Promos d'une galerie : choisir les articles mis en avant (tous commerces membres, y compris Shopify / WooCommerce / PrestaShop),
// ajouter une pièce à la main, et brancher l'application de prise de vue (jeton + endpoint /connecteur-shooting.php).
require __DIR__ . '/_galerie-layout.php';

$self = '/portail/galerie-promos.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$url = static function (array $params = []) use ($self): string {
    $sep = str_contains($self, '?') ? '&' : '?';
    return $self . ($params ? $sep . http_build_query($params) : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $gallery = $gaGallery;
    $promos = gallery_promos_normalize($gallery['promos'] ?? []);

    if ($action === 'save') {
        $picked = array_values(array_unique(array_filter(array_map('strval', (array) ($_POST['promo'] ?? [])))));
        $catalogue = gallery_catalogue($gallery, $gaOrigin);
        $byKey = [];
        foreach ($catalogue['products'] as $p) {
            $byKey[$p['shop'] . '|' . $p['ref']] = $p;
        }
        $selected = [];
        foreach ($picked as $key) {
            if (!isset($byKey[$key])) continue;
            $p = $byKey[$key];
            $selected[] = [
                'shop' => (string) $p['shop'],
                'shop_name' => (string) ($catalogue['shops'][$p['shop']]['name'] ?? ''),
                'ref' => (string) $p['ref'],
                'name' => (string) $p['name'],
                'price' => (string) $p['price'],
                'promo_price' => (string) $p['promo_price'],
                'photo' => (string) $p['photo'],
                'url' => (string) $p['url'],
                'cta' => (string) ($p['promo_cta'] ?? '') ?: 'Voir l’offre',
                'source' => 'connector',
                'added' => time(),
            ];
            if (count($selected) >= GALLERY_PROMOS_MAX) break;
        }
        // Les ajouts à la main ou par l'application de prise de vue qui ne viennent pas du catalogue sont conservés.
        foreach ($promos as $p) {
            if (count($selected) >= GALLERY_PROMOS_MAX) break;
            if ($p['source'] === 'connector' && !isset($byKey[$p['shop'] . '|' . $p['ref']])) continue;
            if ($p['source'] === 'connector') continue;
            $selected[] = $p;
        }
        $gallery['promos'] = gallery_promos_normalize($selected);
        $saved = gallery_save($gallery);
        portail_flash($saved ? count($gallery['promos']) . ' promo(s) enregistrée(s).' : 'Enregistrement impossible.', $saved ? 'ok' : 'error');
    } elseif ($action === 'add') {
        $promo = gallery_promo_normalize([
            'shop' => (string) ($_POST['shop'] ?? 'shoot'),
            'shop_name' => (string) ($_POST['shop_name'] ?? ''),
            'ref' => (string) ($_POST['ref'] ?? ''),
            'name' => (string) ($_POST['name'] ?? ''),
            'price' => (string) ($_POST['price'] ?? ''),
            'promo_price' => (string) ($_POST['promo_price'] ?? ''),
            'photo' => (string) ($_POST['photo'] ?? ''),
            'url' => (string) ($_POST['url'] ?? ''),
            'cta' => (string) ($_POST['cta'] ?? 'Voir l’offre'),
            'source' => 'manual',
            'added' => time(),
        ]);
        if (!$promo) {
            portail_flash('Donnez au moins un nom à l’article.', 'error');
        } else {
            $before = count($promos);
            $promos[] = $promo;
            $gallery['promos'] = gallery_promos_normalize($promos);
            $added = count($gallery['promos']) > $before;
            $saved = gallery_save($gallery);
            portail_flash($saved ? ($added ? 'Article ajouté aux promos.' : 'Les ' . GALLERY_PROMOS_MAX . ' promos sont déjà prises.') : 'Enregistrement impossible.', $saved && $added ? 'ok' : 'error');
        }
    } elseif ($action === 'remove') {
        $key = (string) ($_POST['key'] ?? '');
        $gallery['promos'] = array_values(array_filter($promos, static fn (array $p): bool => $p['shop'] . '|' . $p['ref'] !== $key));
        portail_flash(gallery_save($gallery) ? 'Promo retirée.' : 'Enregistrement impossible.');
    } elseif ($action === 'clear') {
        $gallery['promos'] = [];
        portail_flash(gallery_save($gallery) ? 'Toutes les promos ont été retirées (les visuels promo des boutiques restent affichés).' : 'Enregistrement impossible.');
    } elseif ($action === 'rotate') {
        gallery_shooting_ensure($gallery, true);
        portail_flash(gallery_save($gallery) ? 'Nouveau jeton d’application créé : l’ancien ne fonctionne plus.' : 'Enregistrement impossible.', 'ok');
    } elseif ($action === 'toggle') {
        $shooting = gallery_shooting_normalize($gallery['shooting'] ?? []);
        $shooting['enabled'] = empty($shooting['enabled']);
        $gallery['shooting'] = $shooting;
        portail_flash(gallery_save($gallery) ? ($shooting['enabled'] ? 'Application de prise de vue activée.' : 'Application de prise de vue désactivée.') : 'Enregistrement impossible.');
    }
    header('Location: ' . $url());
    exit;
}

if (empty($gaGallery['shooting']['token'])) {
    gallery_shooting_ensure($gaGallery);
    gallery_save($gaGallery);
}
$shooting = gallery_shooting_normalize($gaGallery['shooting'] ?? []);
$endpoint = rtrim($gaOrigin, '/') . '/connecteur-shooting.php?g=' . rawurlencode($gaSlug) . '&t=' . $shooting['token'];
$q = trim((string) ($_GET['q'] ?? ''));
$catalogue = gallery_catalogue($gaGallery, $gaOrigin, !empty($_GET['refresh']));
$selected = [];
foreach ($gaGallery['promos'] as $p) $selected[$p['shop'] . '|' . $p['ref']] = true;
$shops = $catalogue['shops'];
$rows = [];
foreach ($catalogue['products'] as $p) {
    if ($q !== '' && mb_stripos($p['name'] . ' ' . $p['cat_label'] . ' ' . ($shops[$p['shop']]['name'] ?? ''), $q) === false) continue;
    $rows[] = $p;
}
ga_head('Promos et application de prise de vue');
?>
    <section class="gcard">
      <h2>Articles en promo sur la galerie</h2>
      <p class="meta">Cochez jusqu’à <?= GALLERY_PROMOS_MAX ?> articles : ils apparaissent dans « Les promos du moment » sur la page publique. Les articles des sites Shopify, WooCommerce et PrestaShop sont lus par le connecteur quand leur commerce est membre.</p>
      <form method="get" class="row" style="margin:10px 0">
        <?php if (!$limited): ?><input type="hidden" name="g" value="<?= e($gaSlug) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filtrer par nom, univers ou commerce" style="flex:1;min-width:220px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:inherit">
        <button class="btn small" type="submit">Filtrer</button>
        <a class="btn small ghost" href="<?= e($url(['refresh' => 1])) ?>">Actualiser les catalogues</a>
      </form>
      <?php if ($catalogue['skipped']): ?><p class="flash" data-kind="error">Commerces injoignables : <?= e(implode(', ', $catalogue['skipped'])) ?>.</p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="rows" style="display:grid;gap:6px;max-height:460px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:8px">
          <?php if (!$rows): ?><p class="meta" style="margin:8px">Aucun article ne correspond. Vérifiez que les commerces sont membres de la galerie et joignables.</p><?php endif; ?>
          <?php foreach ($rows as $p): $key = $p['shop'] . '|' . $p['ref']; ?>
            <label style="display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;padding:6px 8px;border-radius:8px">
              <input type="checkbox" name="promo[]" value="<?= e($key) ?>"<?= isset($selected[$key]) ? ' checked' : '' ?>>
              <span style="min-width:0">
                <strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($p['name']) ?></strong>
                <small class="meta"><?= e($shops[$p['shop']]['name'] ?? $p['shop']) ?> · <?= e($p['cat_label']) ?><?= $p['promo_price'] !== '' ? ' · en promo' : '' ?></small>
              </span>
              <span style="font-variant-numeric:tabular-nums;white-space:nowrap"><?= e($p['promo_price'] !== '' ? $p['promo_price'] : $p['price']) ?><?= $p['promo_price'] !== '' ? ' <s>' . e($p['price']) . '</s>' : '' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="row" style="margin:10px 0 0"><button class="btn" type="submit">Enregistrer la sélection</button>
          <?php if ($gaGallery['promos']): ?><button class="btn ghost" type="submit" name="action" value="clear" onclick="return confirm('Retirer toutes les promos retenues ?')">Tout retirer</button><?php endif; ?></p>
      </form>
    </section>

    <section class="gcard">
      <h2>Promos ajoutées à la main / par l’application</h2>
      <?php if (!$gaGallery['promos']): ?><p class="meta">Aucune pour le moment.</p><?php endif; ?>
      <?php foreach ($gaGallery['promos'] as $p): ?>
        <div class="row" style="justify-content:space-between;border-top:1px solid var(--line);padding:8px 0">
          <div><strong><?= e($p['name']) ?></strong>
            <small class="meta"><?= e($p['shop_name'] !== '' ? $p['shop_name'] : $p['shop']) ?> · <?= e($p['source']) ?> · <?= e($p['promo_price'] !== '' ? $p['promo_price'] . ' (au lieu de ' . $p['price'] . ')' : $p['price']) ?></small></div>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?= e($p['shop'] . '|' . $p['ref']) ?>"><button class="btn small ghost" type="submit">Retirer</button></form>
        </div>
      <?php endforeach; ?>
      <details style="margin-top:12px"><summary style="cursor:pointer">Ajouter un article sans catalogue (photo, nom, prix)</summary>
        <form method="post" style="display:grid;gap:10px;margin-top:10px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add">
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
            <label class="field"><span>Nom de l’article *</span><input type="text" name="name" required maxlength="120"></label>
            <label class="field"><span>Commerce affiché</span><input type="text" name="shop_name" maxlength="80"></label>
            <label class="field"><span>Prix</span><input type="text" name="price" maxlength="40" placeholder="35 €"></label>
            <label class="field"><span>Prix promo</span><input type="text" name="promo_price" maxlength="40" placeholder="25 €"></label>
            <label class="field"><span>Photo (adresse)</span><input type="url" name="photo" maxlength="500" placeholder="https://…"></label>
            <label class="field"><span>Lien de l’article</span><input type="url" name="url" maxlength="500" placeholder="https://…"></label>
            <label class="field"><span>Bouton</span><input type="text" name="cta" maxlength="40" value="Voir l’offre"></label>
            <label class="field"><span>Référence (facultatif)</span><input type="text" name="ref" maxlength="80"></label>
          </div>
          <div><button class="btn small" type="submit">Ajouter aux promos</button></div>
        </form>
      </details>
    </section>

    <section class="gcard">
      <h2>Application de prise de vue</h2>
      <p class="meta">Votre studio photo, votre scanner de stand ou votre caisse peut déposer une pièce en promo en envoyant son nom et sa photo. Le jeton ne permet que cet ajout, et seulement pour cette galerie.</p>
      <div class="row" style="margin:10px 0">
        <input type="text" readonly value="<?= e($endpoint) ?>" style="flex:1;min-width:260px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:inherit" onclick="this.select()">
        <button class="btn small" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='Copié'">Copier</button>
      </div>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle">
        <button class="btn small<?= $shooting['enabled'] ? '' : ' ghost' ?>" type="submit"><?= $shooting['enabled'] ? 'Désactiver l’application' : 'Activer l’application' ?></button>
      </form>
      <form method="post" class="row" style="margin-top:8px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="rotate">
        <button class="btn small ghost" type="submit" onclick="return confirm('Créer un nouveau jeton ? L’ancien cessera de fonctionner.')">Créer un nouveau jeton</button>
      </form>
      <details style="margin-top:12px"><summary style="cursor:pointer">Format attendu (JSON)</summary>
        <pre style="overflow:auto;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">{
  "name": "Commode années 50",
  "ref": "piece-42",
  "shop": "mon-stand",
  "shop_name": "Mon stand",
  "price": "85 €",
  "promo_price": "60 €",
  "photo": "https://exemple.fr/photo.jpg",
  "url": "https://exemple.fr/produit/42",
  "cta": "Réserver cette pièce"
}</pre>
        <p class="meta">La photo peut aussi être envoyée en <code>photo_data</code> (data URL base64, 6 Mo au plus) ou en fichier multipart <code>photo</code>. Un article déjà présent (même commerce et même référence) est mis à jour au lieu d’être dupliqué.</p>
      </details>
      <p class="meta">L’application peut vérifier le lien en l’ouvrant : la réponse indique l’état de la galerie et un exemple. Chaque dépôt est limité à 30 par heure et par connexion.</p>
    </section>
<?php ga_foot(); ?>