<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/invendus-layout.php';
require_admin();
require_once __DIR__ . '/../includes/shop-modules.php';
shop_module_require('invendus-dons');   // plugin activé + accès payé, en essai, offert ou inclus

$recipients = unsold_recipients_list();
$editId = (int) unsold_param($_GET, 'edit');
$editing = $editId ? unsold_recipient_get($editId) : null;
$return = '/admin/invendus-structures.php';

function unsold_recipient_fields(?array $r): void
{
    $v = fn(string $k) => h($r[$k] ?? '');
    ?>
    <div class="field"><label>Nom de la structure *</label><input type="text" name="name" value="<?= $v('name') ?>" maxlength="120" required placeholder="ex : Recyclerie du Petit Chalet"></div>
    <div class="field"><label>Adresse de dépôt</label><input type="text" name="address" value="<?= $v('address') ?>" maxlength="200"></div>
    <div class="field-row-2">
      <div class="field"><label>Téléphone</label><input type="tel" name="phone" value="<?= $v('phone') ?>" maxlength="20"></div>
      <div class="field"><label>E-mail</label><input type="email" name="email" value="<?= $v('email') ?>" maxlength="120"></div>
    </div>
    <div class="field"><label>Horaires de dépôt</label><input type="text" name="hours" value="<?= $v('hours') ?>" maxlength="200" placeholder="ex : mardi–samedi 9h–12h / 14h–18h"></div>
    <div class="field"><label>Ce qu'ils acceptent (et refusent)</label><textarea name="accepts" rows="2" maxlength="400" placeholder="ex : vêtements, vaisselle, livres, jouets. Pas de gros électroménager."><?= $v('accepts') ?></textarea></div>
    <?php
}

unsold_admin_head('Donneries');
?>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow"><a href="/admin/invendus.php" style="text-decoration:none;">← Invendus &amp; dons</a></p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Donneries</h1>
    <p class="hint don-muted" style="max-width:70ch;">
      Les structures qui reçoivent le reliquat en fin de journée : recyclerie, Emmaüs, association locale, vestiaire solidaire…
      Au moment de la remise, un bordereau est généré à leur nom.
    </p>

    <?php unsold_flash_html(); ?>

    <div class="don-table-wrap" style="margin-top:18px;">
      <table class="don-table">
        <thead><tr><th>Structure</th><th>Contact</th><th>Accepte</th><th>Dons reçus</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recipients as $r): ?>
          <tr style="<?= $r['is_active'] ? '' : 'opacity:.55;' ?>">
            <td><strong><?= h($r['name']) ?></strong><?= $r['is_active'] ? '' : ' <span class="don-small don-muted">(désactivée)</span>' ?><br><span class="don-small don-muted"><?= h($r['address']) ?></span></td>
            <td class="don-small"><?= h($r['phone']) ?><br><?= h($r['email']) ?><br><?= h($r['hours']) ?></td>
            <td class="don-small"><?= h($r['accepts']) ?></td>
            <td class="don-num"><?= (int) $r['donations_count'] ?></td>
            <td>
              <div class="don-row">
                <a class="btn-small" href="<?= h($return . '?edit=' . (int) $r['id']) ?>#form">Modifier</a>
                <?= unsold_admin_form_open('recipient_toggle', $return) ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn-small" type="submit"><?= $r['is_active'] ? 'Désactiver' : 'Activer' ?></button></form>
                <?= unsold_admin_form_open('recipient_delete', $return) ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="admin-delete" type="submit" onclick="return confirm('Supprimer cette structure ?');">Supprimer</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recipients): ?>
          <tr><td colspan="5" class="empty-state">Aucune structure pour l'instant — ajoutez-en une ci-dessous.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="admin-block" id="form" style="margin-top:28px;">
      <h2><?= $editing ? 'Modifier « ' . h($editing['name']) . ' »' : 'Ajouter une structure' ?></h2>
      <?= unsold_admin_form_open('recipient_save', $return, 'class="don-form" style="margin-top:14px;"') ?>
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <?php unsold_recipient_fields($editing); ?>
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Enregistrer' : 'Ajouter' ?></button>
        <?php if ($editing): ?><a class="btn btn-ghost" href="<?= h($return) ?>">Annuler</a><?php endif; ?>
      </form>
    </div>
  </div>
</section>
<?php unsold_admin_foot(); ?>
