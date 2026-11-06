<?php
require_once __DIR__ . '/../../includes/impression-layout.php';
pr_admin_init();

$errors = [];
$old = [];

if (pr_post_action() === 'create') {
    $old = $_POST;
    [$clean, $errors] = pr_job_input($_POST);

    $upload = $_FILES['file'] ?? null;
    $file = null;
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Joignez le fichier prêt à imprimer (PDF, PNG, JPEG ou TIFF).';
    } elseif (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $errors[] = 'Le fichier dépasse la taille autorisée par le serveur (' . ini_get('upload_max_filesize') . '). Réduisez-le ou demandez à l\'hébergeur d\'augmenter la limite.';
    } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Le fichier n\'a pas pu être envoyé (code ' . (int) $upload['error'] . ').';
    } else {
        $insp = pr_file_inspect($upload['tmp_name'], (string) $upload['name'], (int) $upload['size']);
        if (!$insp['ok']) $errors[] = $insp['error'];
        else $file = ['insp' => $insp, 'tmp' => $upload['tmp_name'], 'name' => mb_substr(basename((string) $upload['name']), 0, 150), 'size' => (int) $upload['size']];
    }

    if (!$errors && $file) {
        $path = pr_file_store($file['tmp'], $file['insp']);
        if ($path === null) {
            $errors[] = 'Impossible d\'enregistrer le fichier sur le serveur (droits d\'écriture sur uploads/ ?).';
        } else {
            $id = pr_job_create($clean, [
                'path' => $path, 'name' => $file['name'], 'mime' => $file['insp']['mime'], 'bytes' => $file['size'],
                'width' => $file['insp']['width'], 'height' => $file['insp']['height'],
            ]);
            pr_redirect('/admin/impression/commande.php?id=' . $id, 'Commande créée. Comparez maintenant les fournisseurs.');
        }
    }
}

$products = pr_products_list(true);
$v = fn(string $k, string $d = '') => h($old[$k] ?? $d);

pr_admin_header('Nouvelle commande d\'impression', 'nouvelle');
?>
<?php if ($errors): ?>
  <div class="bv-callout" role="alert"><strong>À corriger :</strong>
    <ul style="margin:6px 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?= pr_form_open('create', [], '', '', true) ?>
  <div class="bv-card">
    <h2>Quoi imprimer ?</h2>
    <div class="bv-form-grid" style="margin-top:12px;">
      <div class="field span-2"><label for="pr-title">Titre de la commande *</label>
        <input type="text" id="pr-title" name="title" value="<?= $v('title') ?>" maxlength="120" required placeholder="ex : Affiches du vide-grenier de printemps"></div>
      <div class="field"><label for="pr-product">Produit *</label>
        <select id="pr-product" name="product_id" class="bv-input" required>
          <option value="">— choisir —</option>
          <?php $cats = pr_categories(); $byCat = []; foreach ($products as $p) $byCat[$p['category']][] = $p; ?>
          <?php foreach ($byCat as $cat => $list): ?>
            <optgroup label="<?= h($cats[$cat] ?? $cat) ?>">
              <?php foreach ($list as $p): ?>
                <option value="<?= (int) $p['id'] ?>" data-qty="<?= (int) $p['default_qty'] ?>"<?= (int) ($old['product_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= h($p['label']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <span class="bv-hint" style="margin:4px 0 0;display:block;">Un produit manque ? <a href="/admin/impression/catalogue.php#ajouter">Ajoutez-le au catalogue</a>.</span>
      </div>
      <div class="field"><label for="pr-qty">Quantité *</label>
        <input type="number" id="pr-qty" name="quantity" class="bv-input" min="1" max="100000" value="<?= $v('quantity', '1') ?>" required></div>
      <div class="field span-2"><label for="pr-file">Fichier prêt à imprimer *</label>
        <input type="file" id="pr-file" name="file" accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff,application/pdf,image/png,image/jpeg,image/tiff" required>
        <span class="bv-hint" style="margin:4px 0 0;display:block;">PDF (idéal), PNG, JPEG ou TIFF — 50 Mo maximum (limite du serveur : <?= h(ini_get('upload_max_filesize')) ?>). Prévoyez 300 dpi pour les cartes et flyers, 150 dpi pour les grandes affiches. Le fichier sera téléchargé par le fournisseur à partir d'une adresse privée du site.</span></div>
      <div class="field span-2"><label for="pr-notes">Remarques pour l'imprimeur</label>
        <input type="text" id="pr-notes" name="notes" value="<?= $v('notes') ?>" maxlength="1000" placeholder="ex : papier mat, pas de vernis"></div>
    </div>
  </div>

  <div class="bv-card">
    <h2>Livraison</h2>
    <div class="bv-form-grid" style="margin-top:12px;">
      <div class="field"><label for="pr-first">Prénom *</label><input type="text" id="pr-first" name="rec_first" value="<?= $v('rec_first') ?>" maxlength="60" required></div>
      <div class="field"><label for="pr-last">Nom *</label><input type="text" id="pr-last" name="rec_last" value="<?= $v('rec_last') ?>" maxlength="60" required></div>
      <div class="field span-2"><label for="pr-company">Association / société</label><input type="text" id="pr-company" name="rec_company" value="<?= $v('rec_company') ?>" maxlength="100"></div>
      <div class="field span-2"><label for="pr-a1">Adresse *</label><input type="text" id="pr-a1" name="rec_address1" value="<?= $v('rec_address1') ?>" maxlength="120" required></div>
      <div class="field span-2"><label for="pr-a2">Complément d'adresse</label><input type="text" id="pr-a2" name="rec_address2" value="<?= $v('rec_address2') ?>" maxlength="120"></div>
      <div class="field"><label for="pr-zip">Code postal *</label><input type="text" id="pr-zip" name="rec_postcode" value="<?= $v('rec_postcode') ?>" maxlength="12" required></div>
      <div class="field"><label for="pr-city">Ville *</label><input type="text" id="pr-city" name="rec_city" value="<?= $v('rec_city') ?>" maxlength="80" required></div>
      <div class="field"><label for="pr-country">Pays (code à 2 lettres) *</label><input type="text" id="pr-country" name="rec_country" value="<?= $v('rec_country', 'FR') ?>" maxlength="2" required style="text-transform:uppercase;"></div>
      <div class="field"><label for="pr-state">Région / état (si demandé)</label><input type="text" id="pr-state" name="rec_state" value="<?= $v('rec_state') ?>" maxlength="60"></div>
      <div class="field"><label for="pr-email">E-mail *</label><input type="email" id="pr-email" name="rec_email" value="<?= $v('rec_email') ?>" required></div>
      <div class="field"><label for="pr-phone">Téléphone</label><input type="tel" id="pr-phone" name="rec_phone" value="<?= $v('rec_phone') ?>" placeholder="+33 6 12 34 56 78"></div>
    </div>
    <div class="bv-actions"><button class="btn btn-primary" type="submit">Créer la commande</button></div>
  </div>
</form>
<script>
// Pré-remplit la quantité habituelle du produit choisi (sans écraser une valeur déjà modifiée à la main).
(function () {
  var sel = document.getElementById('pr-product'), qty = document.getElementById('pr-qty'), touched = false;
  qty.addEventListener('input', function () { touched = true; });
  sel.addEventListener('change', function () {
    var o = sel.options[sel.selectedIndex];
    if (!touched && o && o.dataset.qty) qty.value = o.dataset.qty;
  });
})();
</script>
<?php
pr_admin_footer();
