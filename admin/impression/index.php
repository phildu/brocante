<?php
require_once __DIR__ . '/../../includes/impression-layout.php';
pr_admin_init();

switch (pr_post_action()) {
    case 'set_mode': {
        $mode = ($_POST['mode'] ?? '') === 'live' ? 'live' : 'test';
        pr_setting_set('mode', $mode);
        pr_redirect('/admin/impression/index.php', $mode === 'live'
            ? 'Mode LIVE activé : les prochaines commandes seront réellement produites et facturées par les fournisseurs.'
            : 'Mode TEST activé : rien n\'est produit ni facturé.');
    }

    case 'set_max': {
        $euros = str_replace([' ', ','], ['', '.'], trim((string) ($_POST['max_euros'] ?? '')));
        if (!is_numeric($euros) || (float) $euros < 1 || (float) $euros > 100000) {
            pr_redirect('/admin/impression/index.php#reglages', 'Plafond invalide (entre 1 et 100 000 €).', 'error');
        }
        pr_setting_set('max_order_cents', (string) pr_cents($euros));
        pr_redirect('/admin/impression/index.php', 'Plafond par commande mis à jour : ' . pr_eur(pr_cents($euros)) . '.');
    }
}

$jobs = pr_jobs_list();
$stats = pr_stats();

pr_admin_header('Impression', 'commandes');
?>
<p class="bv-hint">Commandez l'impression d'affiches, de cartes de visite, de flyers, de plans ou de goodies : le module compare les prix des fournisseurs connectés par API, passe la commande et suit la livraison. Pour un imprimeur sans API, il prépare le brief de devis et vous suivez la commande à la main.</p>

<?php foreach (pr_config_warnings() as $w): ?><p class="pr-warn"><?= $w ?></p><?php endforeach; ?>

<div class="bv-stats">
  <div class="bv-stat"><div class="label">À finaliser</div><div class="value"><?= $stats['open'] ?></div><div class="sub">brouillons et devis</div></div>
  <div class="bv-stat"><div class="label">En cours</div><div class="value"><?= $stats['running'] + $stats['shipped'] ?></div><div class="sub">commandées, en production ou expédiées</div></div>
  <div class="bv-stat<?= $stats['failed'] ? ' is-bad' : '' ?>"><div class="label">En échec</div><div class="value"><?= $stats['failed'] ?></div><div class="sub"><?= $stats['failed'] ? 'à reprendre' : 'aucun' ?></div></div>
  <div class="bv-stat"><div class="label">Dépensé (live)</div><div class="value"><?= h(pr_eur($stats['spent_cents'])) ?></div><div class="sub">hors commandes en mode test</div></div>
</div>

<div class="bv-actions" style="margin:0 0 18px;">
  <a class="btn btn-primary" href="/admin/impression/nouvelle.php">Nouvelle commande d'impression</a>
</div>

<?php if (!$jobs): ?>
  <p class="bv-callout">Aucune commande pour l'instant. <a href="/admin/impression/nouvelle.php">Créez la première</a> — en mode test, vous pouvez tout essayer sans rien dépenser.</p>
<?php else: ?>
<div class="bv-table-wrap">
  <table class="bv-table">
    <thead><tr><th>Réf.</th><th>Commande</th><th>Produit</th><th class="num">Qté</th><th>Fournisseur</th><th class="num">Prix</th><th>Statut</th><th>Créée le</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td class="bv-num"><?= h($j['ref']) ?></td>
        <td><a href="/admin/impression/commande.php?id=<?= (int) $j['id'] ?>"><strong><?= h($j['title']) ?></strong></a></td>
        <td><?= h($j['product_label']) ?></td>
        <td class="num"><?= (int) $j['quantity'] ?></td>
        <td><?= h($j['supplier_name'] ?? '—') ?></td>
        <td class="num"><?= h(pr_eur($j['charged_cents'] !== null ? (int) $j['charged_cents'] : ($j['estimate_cents'] !== null ? (int) $j['estimate_cents'] : null), $j['currency'])) ?></td>
        <td><?= pr_status_badge($j) ?></td>
        <td class="bv-num"><?= h(pr_fr_datetime($j['created_at'])) ?></td>
        <td><a class="btn-small" href="/admin/impression/commande.php?id=<?= (int) $j['id'] ?>">Ouvrir</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="bv-card" id="reglages" style="margin-top:26px;">
  <h2>Mode et plafond de sécurité</h2>
  <p class="bv-hint">Commander par API engage de l'argent réel. En <strong>mode test</strong> (par défaut), Gelato enregistre un brouillon jamais produit et Prodigi utilise son bac à sable gratuit. Passez en <strong>mode live</strong> seulement quand une commande test s'est déroulée comme prévu.</p>
  <div class="bv-form-grid">
    <div>
      <?= pr_form_open('set_mode', ['mode' => pr_is_test() ? 'live' : 'test'], pr_is_test()
          ? 'Passer en mode LIVE ? Les prochaines commandes seront RÉELLEMENT produites et facturées par les fournisseurs.'
          : 'Revenir en mode TEST ?') ?>
        <p>Mode actuel : <strong><?= pr_is_test() ? 'TEST' : 'LIVE' ?></strong></p>
        <div class="bv-actions"><button class="btn-small<?= pr_is_test() ? '' : ' bv-btn-danger' ?>" type="submit"><?= pr_is_test() ? 'Passer en mode live…' : 'Revenir en mode test' ?></button></div>
      </form>
    </div>
    <div>
      <?= pr_form_open('set_max') ?>
        <div class="field"><label for="pr-max">Plafond par commande (€ TTC)</label>
          <input type="text" inputmode="decimal" id="pr-max" name="max_euros" class="bv-input" value="<?= h(number_format(pr_max_order_cents() / 100, 2, ',', '')) ?>"></div>
        <div class="bv-actions"><button class="btn-small" type="submit">Enregistrer</button></div>
      </form>
      <p class="bv-hint" style="margin-top:8px;">Une offre au-dessus de ce montant est refusée : garde-fou contre une faute de frappe sur la quantité.</p>
    </div>
  </div>
</div>
<?php
pr_admin_footer();
