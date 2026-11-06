<?php
require_once __DIR__ . '/../../includes/impression-layout.php';
pr_admin_init();

switch (pr_post_action()) {
    case 'cp_products':
        break; // traité plus bas : le résultat s'affiche sur cette page (pas de redirection)

    case 'save_key': {
        $name = (string) ($_POST['key_name'] ?? '');
        $value = trim((string) ($_POST['key_value'] ?? ''));
        // Champ vide = on ne touche pas à la clé déjà enregistrée (même logique que les autres clés du site).
        if (!isset(pr_secret_names()[$name])) pr_redirect('/admin/impression/fournisseurs.php#cles', 'Clé inconnue.', 'error');
        if ($value === '') pr_redirect('/admin/impression/fournisseurs.php#cles', 'Aucune clé fournie — rien de changé.');
        if (!preg_match('/^[\x21-\x7E]{8,300}$/', $value)) pr_redirect('/admin/impression/fournisseurs.php#cles', 'Clé invalide (8 à 300 caractères, sans espace).', 'error');
        if (!pr_secret_set($name, $value)) pr_redirect('/admin/impression/fournisseurs.php#cles', 'Impossible d\'écrire la clé sur le serveur (droits sur .secrets/ ?).', 'error');
        pr_redirect('/admin/impression/fournisseurs.php#cles', pr_secret_names()[$name] . ' enregistrée.');
    }

    case 'save_supplier':
    case 'add_supplier': {
        $sid = $_POST['action'] === 'save_supplier' ? (int) ($_POST['id'] ?? 0) : null;
        [$saved, $err] = pr_supplier_save($sid, $_POST);
        pr_redirect('/admin/impression/fournisseurs.php' . ($saved ? '#s' . $saved : ''), $err ?? ($sid ? 'Fournisseur enregistré.' : 'Fournisseur ajouté.'), $err ? 'error' : 'ok');
    }

    case 'delete_supplier': {
        $err = pr_supplier_delete((int) ($_POST['id'] ?? 0));
        pr_redirect('/admin/impression/fournisseurs.php', $err ?? 'Fournisseur supprimé.', $err ? 'error' : 'ok');
    }
}

// Liste des produits Cloudprinter (aide à trouver les références à copier dans le catalogue).
$cpProducts = null;
$cpError = '';
if (($_POST['action'] ?? '') === 'cp_products') {
    $cp = new PrCloudprinter();
    if (!$cp->isConfigured(pr_is_test())) {
        $cpError = 'Clé Cloudprinter ' . (pr_is_test() ? 'sandbox' : 'live') . ' non renseignée.';
    } else {
        $r = $cp->listProducts(pr_is_test());
        if ($r['ok']) $cpProducts = $r['products']; else $cpError = $r['error'];
    }
}

$suppliers = pr_suppliers_list();
$kinds = pr_supplier_kinds();

/** « ••••1234 » : on n'affiche jamais une clé en entier. */
function pr_mask_secret(string $s): string
{
    return $s === '' ? '' : '••••' . substr($s, -4);
}

function pr_supplier_fields(?array $s): void
{
    $v = fn($k, $d = '') => h($s[$k] ?? $d);
    $connected = $s && $s['adapter'] !== '';
    ?>
    <div class="bv-form-grid" style="margin-top:14px;">
      <div class="field span-2"><label>Nom</label><input type="text" name="name" value="<?= $v('name') ?>" maxlength="120" required></div>
      <?php if (!$connected): ?>
      <div class="field"><label>Type</label>
        <select name="kind" class="bv-input"><?php foreach (['api_externe', 'devis'] as $k): ?><option value="<?= $k ?>"<?= ($s['kind'] ?? 'devis') === $k ? ' selected' : '' ?>><?= h(pr_supplier_kinds()[$k]) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field"><label>Pays / zone</label><input type="text" name="country" value="<?= $v('country') ?>" maxlength="60"></div>
      <div class="field span-2"><label>Spécialités</label><input type="text" name="specialties" value="<?= $v('specialties') ?>" maxlength="400" placeholder="cartes de visite, affiches, goodies…"></div>
      <div class="field"><label>Site web</label><input type="text" name="website" value="<?= $v('website') ?>" maxlength="300" placeholder="https://…"></div>
      <div class="field"><label>Documentation API</label><input type="text" name="docs_url" value="<?= $v('docs_url') ?>" maxlength="300" placeholder="https://…"></div>
      <div class="field"><label>Catalogue produits</label><input type="text" name="catalog_url" value="<?= $v('catalog_url') ?>" maxlength="300" placeholder="https://…"></div>
      <div class="field"><label>E-mail (demandes de devis)</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
      <div class="field"><label>Téléphone</label><input type="tel" name="phone" value="<?= $v('phone') ?>" maxlength="40"></div>
      <div class="field span-2"><label>Notes</label><input type="text" name="notes" value="<?= $v('notes') ?>" maxlength="2000"></div>
      <label class="bv-check"><input type="checkbox" name="is_active" value="1"<?= !$s || $s['is_active'] ? ' checked' : '' ?>> Actif (pris en compte dans les comparaisons)</label>
    </div>
    <?php
}

pr_admin_header('Fournisseurs & clés API', 'fournisseurs');
?>
<div class="bv-card" id="cles">
  <h2>Clés API</h2>
  <p class="bv-hint">Les clés sont enregistrées dans <code>.secrets/</code> sur le serveur (jamais affichées en entier, jamais déployées avec le code). Laisser un champ vide ne modifie pas la clé existante. Créez vos clés dans le tableau de bord de chaque fournisseur : <a href="https://dashboard.gelato.com/" target="_blank" rel="noopener noreferrer">Gelato</a> · <a href="https://dashboard.prodigi.com/" target="_blank" rel="noopener noreferrer">Prodigi</a> · <a href="https://account.cloudprinter.com/registration" target="_blank" rel="noopener noreferrer">Cloudprinter</a> (clé dans « CloudCore API Interfaces » de leur tableau de bord). Ouvrir un compte est gratuit chez les trois ; la facturation se règle chez eux, par carte. <strong>Cloudprinter facture un abonnement mensuel</strong> dès que vous enregistrez votre carte pour passer en live.</p>
  <div class="bv-table-wrap pr-table-narrow">
    <table class="bv-table">
      <thead><tr><th>Clé</th><th>État</th><th>Nouvelle valeur</th></tr></thead>
      <tbody>
      <?php foreach (pr_secret_names() as $name => $label): $cur = pr_secret_get($name); ?>
        <tr>
          <td><strong><?= h($label) ?></strong><?php
            $notes = [
                'gelato' => 'sert en test et en live (le test passe par des commandes « brouillon »)',
                'prodigi_sandbox' => 'bac à sable : gratuit, rien n\'est produit',
                'prodigi_live' => 'production réelle et facturée',
                'cloudprinter_sandbox' => 'clé d\'une interface CloudCore API en mode Sandbox : gratuit, rien n\'est produit',
                'cloudprinter_live' => 'clé d\'une interface CloudCore API en mode Live : production réelle et facturée',
            ];
            echo isset($notes[$name]) ? '<br><span class="bv-muted">' . h($notes[$name]) . '</span>' : '';
          ?></td>
          <td><?= $cur !== '' ? '<span class="bv-badge ok">enregistrée ' . h(pr_mask_secret($cur)) . '</span>' : '<span class="bv-badge warn">absente</span>' ?></td>
          <td>
            <?= pr_form_open('save_key', ['key_name' => $name], '', 'bv-inline') ?>
              <input type="password" name="key_value" class="bv-input" style="width:200px;" autocomplete="off" placeholder="coller la clé" aria-label="<?= h($label) ?>">
              <button class="btn-small" type="submit">Enregistrer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="bv-card" id="cp-produits">
  <h2>Produits Cloudprinter</h2>
  <p class="bv-hint">Cloudprinter n'active que certains produits sur chaque compte. Cette liste (lue avec la clé <?= pr_is_test() ? 'sandbox' : 'live' ?>) donne les <strong>références exactes</strong> à copier dans « Catalogue &amp; références » : affiches, cartes de visite… s'ils existent chez eux.</p>
  <?= pr_form_open('cp_products', [], '', 'bv-inline') ?><button class="btn-small" type="submit">Voir les produits Cloudprinter</button></form>
  <?php if ($cpError !== ''): ?><p class="pr-warn" style="margin-top:12px;"><?= h($cpError) ?></p><?php endif; ?>
  <?php if ($cpProducts !== null): ?>
    <?php if (!$cpProducts): ?>
      <p class="bv-callout">Aucun produit activé sur ce compte : activez-en dans le tableau de bord Cloudprinter.</p>
    <?php else: ?>
      <p style="margin:14px 0 6px;"><input type="text" id="cp-filter" class="bv-input" style="max-width:320px;" placeholder="Filtrer (poster, card, a2…)" aria-label="Filtrer les produits"> <span class="bv-muted"><?= count($cpProducts) ?> produit(s)</span></p>
      <div class="bv-table-wrap">
        <table class="bv-table" id="cp-table">
          <thead><tr><th>Référence</th><th>Nom</th><th>Catégorie</th><th class="num">À partir de</th></tr></thead>
          <tbody>
          <?php foreach ($cpProducts as $p): ?>
            <tr><td><code><?= h($p['reference']) ?></code></td><td><?= h($p['name']) ?><?= $p['note'] !== '' && $p['note'] !== $p['name'] ? '<br><span class="bv-muted">' . h($p['note']) . '</span>' : '' ?></td><td><?= h($p['category']) ?></td><td class="num"><?= $p['from_price'] !== '' ? h($p['from_price'] . ' ' . $p['currency']) : '—' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <script>
      document.getElementById('cp-filter').addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('#cp-table tbody tr').forEach(function (tr) { tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : ''; });
      });
      </script>
    <?php endif; ?>
  <?php endif; ?>
</div>

<h2 class="bv-cat-title">Annuaire des fournisseurs</h2>
<p class="bv-hint">Fournisseurs d'impression repérés (vérifications faites sur leurs sites et documentations publiques). « API connectée » = le module sait demander des devis et commander ; « API existante » = une API existe mais aucun connecteur n'est fourni ; « sans API » = on prépare un brief de devis et on suit à la main. Ajoutez vos imprimeurs locaux ci-dessous.</p>

<?php foreach ($suppliers as $s):
    $web = pr_safe_url($s['website']);
    $docs = pr_safe_url($s['docs_url']);
    ?>
  <details class="bv-fold<?= $s['is_active'] ? '' : ' is-inactive' ?>" id="s<?= (int) $s['id'] ?>">
    <summary>
      <span class="sum-main">
        <span class="sum-name"><?= h($s['name']) ?></span>
        <span class="sum-meta"><?= h($s['country']) ?></span>
      </span>
      <span class="bv-badge<?= $s['kind'] === 'api' ? ' ok' : '' ?>"><?= h(['api' => 'API connectée', 'api_externe' => 'API existante', 'devis' => 'sans API'][$s['kind']] ?? $s['kind']) ?></span>
      <?php if (!$s['is_active']): ?><span class="bv-badge">désactivé</span><?php endif; ?>
    </summary>
    <div class="bv-fold-body">
      <div class="pr-supplier" style="border:none;padding:10px 0 0;">
        <p><strong>Spécialités :</strong> <?= h($s['specialties'] ?: '—') ?></p>
        <?php if ($s['notes'] !== ''): ?><p><?= h($s['notes']) ?></p><?php endif; ?>
        <p class="links">
          <?php if ($web): ?><a href="<?= h($web) ?>" target="_blank" rel="noopener noreferrer">Site web</a><?php endif; ?>
          <?php if ($docs): ?><a href="<?= h($docs) ?>" target="_blank" rel="noopener noreferrer">Documentation API</a><?php endif; ?>
          <?php if ($s['email'] !== ''): ?><a href="mailto:<?= h($s['email']) ?>"><?= h($s['email']) ?></a><?php endif; ?>
        </p>
      </div>
      <?= pr_form_open('save_supplier', ['id' => $s['id']]) ?>
        <?php pr_supplier_fields($s); ?>
        <div class="bv-actions"><button class="btn-small" type="submit">Enregistrer</button></div>
      </form>
      <?php if (!(int) $s['is_builtin']): ?>
        <?= pr_form_open('delete_supplier', ['id' => $s['id']], 'Supprimer « ' . $s['name'] . ' » ?', 'bv-inline') ?>
          <div class="bv-actions"><button class="btn-small bv-btn-danger" type="submit">Supprimer ce fournisseur</button></div></form>
      <?php endif; ?>
    </div>
  </details>
<?php endforeach; ?>

<div class="bv-card" id="ajouter" style="margin-top:26px;">
  <h2>Ajouter un imprimeur</h2>
  <p class="bv-hint">Un imprimeur local, une imprimerie de l'association, un site que vous aimez… Les demandes de devis lui seront préparées par e-mail.</p>
  <?= pr_form_open('add_supplier') ?>
    <?php pr_supplier_fields(null); ?>
    <div class="bv-actions"><button class="btn btn-primary" type="submit">Ajouter</button></div>
  </form>
</div>
<?php
pr_admin_footer();
