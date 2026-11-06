<?php
require_once __DIR__ . '/../../includes/impression-layout.php';
pr_admin_init();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$job = pr_job_get($id);
if (!$job) {
    pr_redirect('/admin/impression/index.php', 'Commande introuvable.', 'error');
}
if (pr_job_recover_stuck($id)) $job = pr_job_get($id);
$self = '/admin/impression/commande.php?id=' . $id;

switch (pr_post_action()) {
    case 'quote_all': {
        $r = pr_job_quote_all($id);
        if (!$r['ok']) pr_redirect($self, $r['error'], 'error');
        pr_redirect($self . '#devis', $r['offers'] > 0
            ? $r['offers'] . ' offre(s) reçue(s)' . ($r['failures'] ? ' — ' . $r['failures'] . ' fournisseur(s) sans réponse (détail ci-dessous).' : '.')
            : 'Aucune offre reçue : voir les raisons ci-dessous (clé API, référence produit…).', $r['offers'] > 0 ? 'ok' : 'error');
    }

    case 'order': {
        $r = pr_job_order($id, (int) ($_POST['quote_id'] ?? 0));
        $j = pr_job_get($id);
        pr_redirect($self, $r['ok']
            ? ($j['test_mode'] ? 'Commande envoyée en MODE TEST (rien n\'est produit ni facturé). N° ' . $j['provider_order_id'] . '.' : 'Commande envoyée au fournisseur. N° ' . $j['provider_order_id'] . '.')
            : 'Commande non envoyée : ' . $r['error'], $r['ok'] ? 'ok' : 'error');
    }

    case 'sync': {
        $r = pr_job_sync($id);
        pr_redirect($self, $r['ok'] ? 'Statut actualisé.' : 'Actualisation impossible : ' . $r['error'], $r['ok'] ? 'ok' : 'error');
    }

    case 'cancel': {
        $r = pr_job_cancel($id);
        pr_redirect($self, $r['ok'] ? 'Commande annulée.' : 'Annulation impossible : ' . $r['error'], $r['ok'] ? 'ok' : 'error');
    }

    case 'delete': {
        $e = pr_job_delete($id);
        if ($e) pr_redirect($self, $e, 'error');
        pr_redirect('/admin/impression/index.php', 'Commande supprimée.');
    }

    case 'quote_requested': {
        $e = pr_job_mark_quote_requested($id, (int) ($_POST['supplier_id'] ?? 0));
        pr_redirect($self . '#sans-api', $e ?? 'Demande de devis notée dans le journal.', $e ? 'error' : 'ok');
    }

    case 'manual_quote': {
        $days = (int) ($_POST['days'] ?? 0);
        $e = pr_manual_quote_add($id, (int) ($_POST['supplier_id'] ?? 0), (string) ($_POST['total'] ?? ''), (string) ($_POST['note'] ?? ''), $days ?: null);
        pr_redirect($self . '#devis', $e ?? 'Prix enregistré.', $e ? 'error' : 'ok');
    }

    case 'order_manual': {
        $e = pr_job_mark_ordered_manually($id, (int) ($_POST['quote_id'] ?? 0), (string) ($_POST['reference'] ?? ''));
        pr_redirect($self, $e ?? 'Commande notée comme passée hors API. Mettez à jour le suivi au fil de l\'eau.', $e ? 'error' : 'ok');
    }

    case 'manual_status': {
        $e = pr_job_set_status_manual($id, (string) ($_POST['status'] ?? ''), (string) ($_POST['tracking_url'] ?? ''));
        pr_redirect($self, $e ?? 'Suivi mis à jour.', $e ? 'error' : 'ok');
    }
}

$product = pr_product_get((int) $job['product_id']);
$quotes = pr_quotes_for_job($id);
$log = pr_job_log($id);
$supplier = $job['supplier_id'] ? pr_supplier_get((int) $job['supplier_id']) : null;
$locked = pr_job_is_locked($job);
$canQuote = !$locked && $job['status'] !== 'cancelled';
$dpi = pr_dpi_check($job, $product);
$priced = array_values(array_filter($quotes, fn($q) => $q['total_cents'] !== null));
$bestId = $priced ? (int) $priced[0]['id'] : 0;
$noApi = array_values(array_filter(pr_suppliers_list(true), fn($s) => $s['adapter'] === ''));
$trackUrl = pr_safe_url($job['tracking_url']);
$fileUrl = $job['file_path'] !== '' ? '/' . $job['file_path'] : '';
$isImage = str_starts_with((string) $job['file_mime'], 'image/') && $job['file_mime'] !== 'image/tiff';
$testNow = pr_is_test();

pr_admin_header($job['ref'] . ' — ' . $job['title'], 'commandes');
?>
<p style="margin:-8px 0 18px;"><a href="/admin/impression/index.php">← Toutes les commandes</a> · <?= pr_status_badge($job) ?></p>

<?php if ($job['status'] === 'failed' && $job['error'] !== ''): ?>
  <p class="pr-warn" role="alert"><strong>L'envoi au fournisseur a échoué :</strong> <?= h($job['error']) ?><br>Corrigez la cause (clé, référence, adresse…) puis relancez une comparaison et une commande — aucun doublon n'est créé.</p>
<?php endif; ?>

<div class="pr-grid">
  <div>
    <?php if ($job['channel'] !== ''): ?>
    <div class="bv-card">
      <h2>Suivi de la commande</h2>
      <dl class="pr-dl" style="margin-top:10px;">
        <dt>Fournisseur</dt><dd><?= h($supplier['name'] ?? '—') ?><?= $job['channel'] === 'manual' ? ' <span class="bv-muted">(commande passée hors API)</span>' : '' ?></dd>
        <?php if ($job['option_label'] !== ''): ?><dt>Livraison</dt><dd><?= h($job['option_label']) ?></dd><?php endif; ?>
        <?php if ($job['provider_order_id'] !== ''): ?><dt>N° chez le fournisseur</dt><dd class="bv-num"><?= h($job['provider_order_id']) ?></dd><?php endif; ?>
        <?php if ($job['provider_status'] !== ''): ?><dt>Statut fournisseur</dt><dd><?= h($job['provider_status']) ?></dd><?php endif; ?>
        <dt>Prix du devis</dt><dd><?= h(pr_eur($job['estimate_cents'] !== null ? (int) $job['estimate_cents'] : null, $job['currency'])) ?></dd>
        <?php if ($job['charged_cents'] !== null): ?><dt>Montant confirmé</dt><dd><?= h(pr_eur((int) $job['charged_cents'], $job['currency'])) ?></dd><?php endif; ?>
        <dt>Commandée le</dt><dd><?= h(pr_fr_datetime($job['ordered_at'])) ?></dd>
        <?php if ($trackUrl !== '' || $job['tracking_code'] !== ''): ?>
          <dt>Suivi colis</dt><dd><?php if ($trackUrl !== ''): ?><a href="<?= h($trackUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($job['tracking_code'] !== '' ? $job['tracking_code'] : 'Suivre le colis') ?></a><?php else: ?><?= h($job['tracking_code']) ?><?php endif; ?></dd>
        <?php endif; ?>
      </dl>
      <?php if ($job['channel'] === 'api' && $job['test_mode']): ?>
        <p class="bv-hint" style="margin-top:10px;">Commande passée en <strong>mode test</strong> : <?= ($supplier['adapter'] ?? '') === 'gelato' ? 'enregistrée chez Gelato comme brouillon, jamais produite ni facturée.' : 'envoyée au bac à sable du fournisseur, jamais produite ni facturée.' ?></p>
      <?php endif; ?>
      <div class="bv-actions">
        <?php if ($job['channel'] === 'api'): ?>
          <?= pr_form_open('sync', ['id' => $id], '', 'bv-inline') ?><button class="btn-small" type="submit">Actualiser le statut</button></form>
        <?php endif; ?>
        <?php if (!in_array($job['status'], ['cancelled', 'delivered', 'ordering'], true)): ?>
          <?= pr_form_open('cancel', ['id' => $id], 'Annuler cette commande' . ($job['channel'] === 'api' ? ' chez le fournisseur' : '') . ' ?', 'bv-inline') ?><button class="btn-small bv-btn-danger" type="submit">Annuler la commande</button></form>
        <?php endif; ?>
      </div>
      <?php if ($job['channel'] === 'manual' && !in_array($job['status'], ['cancelled', 'delivered'], true)): ?>
        <?= pr_form_open('manual_status', ['id' => $id], '', 'bv-form-grid') ?>
          <div class="field"><label for="pr-ms">Mettre à jour le suivi</label>
            <select id="pr-ms" name="status" class="bv-input">
              <?php foreach (['ordered', 'in_production', 'shipped', 'delivered'] as $s): ?><option value="<?= $s ?>"<?= $job['status'] === $s ? ' selected' : '' ?>><?= h(pr_status_labels()[$s][0]) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="pr-mt">Lien de suivi (facultatif)</label><input type="text" id="pr-mt" name="tracking_url" value="<?= h($job['tracking_url']) ?>" placeholder="https://…"></div>
          <div class="bv-actions"><button class="btn-small" type="submit">Enregistrer</button></div>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="bv-card" id="devis">
      <h2>Comparer les fournisseurs</h2>
      <?php if ($canQuote): ?>
        <p class="bv-hint">Demande un devis à chaque fournisseur connecté (<?= $testNow ? 'mode test' : '<strong>mode live</strong>' ?>) pour « <?= h($product['label']) ?> » × <?= (int) $job['quantity'] ?>, livré à <?= h($job['rec_city']) ?> (<?= h($job['rec_country']) ?>). Les prix sont des estimations du fournisseur à cet instant.</p>
        <?= pr_form_open('quote_all', ['id' => $id], '', 'bv-inline') ?><button class="btn btn-primary" type="submit"><?= $quotes ? 'Actualiser les devis' : 'Comparer les fournisseurs' ?></button></form>
      <?php else: ?>
        <p class="bv-hint">Cette commande est <?= $job['status'] === 'cancelled' ? 'annulée' : 'déjà passée' ?> : les devis ci-dessous sont conservés pour mémoire.</p>
      <?php endif; ?>

      <?php if ($quotes): ?>
      <div class="bv-table-wrap" style="margin-top:14px;">
        <table class="bv-table">
          <thead><tr><th>Fournisseur</th><th>Livraison</th><th class="num">Articles</th><th class="num">Port</th><th class="num">Total</th><th>Délai</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($quotes as $q): ?>
            <?php if ($q['total_cents'] === null): ?>
              <tr class="pr-quote-err"><td><?= h($q['supplier_name']) ?></td><td colspan="6">⚠ <?= h($q['error']) ?></td></tr>
            <?php else: ?>
              <tr<?= (int) $q['id'] === $bestId ? ' class="pr-quote-best"' : '' ?>>
                <td><strong><?= h($q['supplier_name']) ?></strong><?= (int) $q['id'] === $bestId ? ' <span class="bv-badge ok">le moins cher</span>' : '' ?><?= (int) $q['manual'] ? ' <span class="bv-badge">saisi à la main</span>' : '' ?></td>
                <td><?= h($q['label'] !== '' ? $q['label'] : $q['option_key']) ?><?= $q['note'] !== '' ? '<br><span class="bv-muted">' . h($q['note']) . '</span>' : '' ?></td>
                <td class="num"><?= h(pr_eur($q['items_cents'] !== null ? (int) $q['items_cents'] : null, $q['currency'])) ?></td>
                <td class="num"><?= h(pr_eur($q['shipping_cents'] !== null ? (int) $q['shipping_cents'] : null, $q['currency'])) ?></td>
                <td class="num"><strong><?= h(pr_eur((int) $q['total_cents'], $q['currency'])) ?></strong></td>
                <td class="bv-num"><?php
                    if ($q['days_min'] !== null && $q['days_max'] !== null) echo h($q['days_min'] == $q['days_max'] ? $q['days_max'] . ' j' : $q['days_min'] . '–' . $q['days_max'] . ' j');
                    elseif ($q['days_max'] !== null) echo h($q['days_max'] . ' j');
                    else echo '—'; ?></td>
                <td>
                  <?php if ($canQuote && !(int) $q['manual']): ?>
                    <?= pr_form_open('order', ['id' => $id, 'quote_id' => $q['id']],
                        ($testNow ? 'Envoyer en MODE TEST chez ' : 'COMMANDER pour de vrai chez ') . $q['supplier_name'] . ' — ' . pr_eur((int) $q['total_cents'], $q['currency']) . ' ('
                        . ($q['label'] !== '' ? $q['label'] : $q['option_key']) . ') ?' . ($testNow ? ' Rien ne sera produit ni facturé.' : ' La production et la facturation démarrent.'), 'bv-inline') ?>
                      <button class="btn-small<?= $testNow ? '' : ' btn-primary' ?>" type="submit">Commander</button></form>
                  <?php elseif ($canQuote && (int) $q['manual']): ?>
                    <?= pr_form_open('order_manual', ['id' => $id, 'quote_id' => $q['id']], 'Noter cette commande comme passée chez ' . $q['supplier_name'] . ' ?', 'bv-inline') ?>
                      <input type="text" name="reference" class="bv-input" style="width:120px;" maxlength="80" placeholder="N° de commande" aria-label="N° de commande chez le fournisseur">
                      <button class="btn-small" type="submit">Marquer commandée</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="bv-hint" style="margin-top:8px;">Plafond de sécurité : <?= h(pr_eur(pr_max_order_cents())) ?> par commande (<a href="/admin/impression/index.php#reglages">modifier</a>).</p>
      <?php endif; ?>
    </div>

    <?php if ($canQuote && $noApi): ?>
    <div class="bv-card" id="sans-api">
      <h2>Imprimeurs sans API</h2>
      <p class="bv-hint">Pour un imprimeur sans API (ou un imprimeur local) : envoyez-lui ce brief, puis saisissez le prix reçu pour le comparer aux autres. Vous pourrez ensuite noter la commande comme passée et la suivre à la main.</p>
      <?php $brief = pr_job_brief($job, $product); ?>
      <textarea class="pr-brief" readonly onclick="this.select()" aria-label="Brief de demande de devis"><?= h($brief) ?></textarea>
      <div class="bv-table-wrap" style="margin-top:12px;">
        <table class="bv-table">
          <thead><tr><th>Imprimeur</th><th>Contact</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($noApi as $s): ?>
            <tr>
              <td><strong><?= h($s['name']) ?></strong><br><span class="bv-muted"><?= h($s['specialties']) ?></span></td>
              <td>
                <?php if (pr_safe_url($s['website']) !== ''): ?><a href="<?= h(pr_safe_url($s['website'])) ?>" target="_blank" rel="noopener noreferrer">Site web</a><?php endif; ?>
                <?php if ($s['email'] !== ''): ?> · <a href="mailto:<?= h($s['email']) ?>?subject=<?= h(rawurlencode('Demande de devis — ' . $product['label'])) ?>&amp;body=<?= h(rawurlencode($brief)) ?>">Écrire</a><?php endif; ?>
                <?php if ($s['phone'] !== ''): ?> · <?= h($s['phone']) ?><?php endif; ?>
              </td>
              <td><?= pr_form_open('quote_requested', ['id' => $id, 'supplier_id' => $s['id']], '', 'bv-inline') ?><button class="btn-small" type="submit">J'ai demandé un devis</button></form></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pr_form_open('manual_quote', ['id' => $id], '', 'bv-form-grid') ?>
        <div class="field"><label for="pr-mq-s">Prix reçu de…</label>
          <select id="pr-mq-s" name="supplier_id" class="bv-input" required>
            <?php foreach ($noApi as $s): ?><option value="<?= (int) $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="pr-mq-t">Total TTC, livraison comprise (€)</label><input type="text" inputmode="decimal" id="pr-mq-t" name="total" required placeholder="34,90"></div>
        <div class="field"><label for="pr-mq-d">Délai (jours)</label><input type="number" id="pr-mq-d" name="days" class="bv-input" min="1" max="365"></div>
        <div class="field"><label for="pr-mq-n">Note</label><input type="text" id="pr-mq-n" name="note" maxlength="300" placeholder="devis du …"></div>
        <div class="bv-actions"><button class="btn-small" type="submit">Enregistrer ce prix</button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="bv-card">
      <h2>Fichier à imprimer</h2>
      <?php if ($isImage && $fileUrl !== ''): ?><img class="pr-preview" src="<?= h($fileUrl) ?>" alt="Aperçu du fichier à imprimer"><?php endif; ?>
      <p><?php if ($fileUrl !== ''): ?><a href="<?= h($fileUrl) ?>" target="_blank" rel="noopener"><?= h($job['file_name']) ?></a><?php else: ?>—<?php endif; ?>
        <span class="bv-muted">· <?= h(pr_size_label((int) $job['file_bytes'])) ?><?= $job['file_w'] ? ' · ' . (int) $job['file_w'] . ' × ' . (int) $job['file_h'] . ' px' : '' ?></span></p>
      <?php if ($dpi): ?>
        <div class="pr-check <?= h($dpi['level']) ?>"><?= h($dpi['text']) ?></div>
      <?php elseif ($job['file_mime'] === 'application/pdf'): ?>
        <div class="pr-check">PDF : la résolution n'est pas vérifiable ici. Vérifiez que les images y sont en 300 dpi et que le format correspond au produit.</div>
      <?php endif; ?>
      <?php if (!pr_site_is_public()): ?>
        <div class="pr-check warn">Le site n'est pas public en https : un fournisseur ne pourra pas télécharger ce fichier depuis ici.</div>
      <?php endif; ?>
    </div>

    <div class="bv-card">
      <h2>Détail</h2>
      <dl class="pr-dl" style="margin-top:10px;">
        <dt>Produit</dt><dd><?= h($product['label']) ?></dd>
        <dt>Quantité</dt><dd><?= (int) $job['quantity'] ?></dd>
        <dt>Destinataire</dt><dd><?= h(trim($job['rec_first'] . ' ' . $job['rec_last'])) ?><?= $job['rec_company'] !== '' ? '<br>' . h($job['rec_company']) : '' ?></dd>
        <dt>Adresse</dt><dd><?= h($job['rec_address1']) ?><?= $job['rec_address2'] !== '' ? '<br>' . h($job['rec_address2']) : '' ?><br><?= h($job['rec_postcode'] . ' ' . $job['rec_city']) ?> (<?= h($job['rec_country']) ?>)</dd>
        <dt>Contact</dt><dd><?= h($job['rec_email']) ?><?= $job['rec_phone'] !== '' ? '<br>' . h($job['rec_phone']) : '' ?></dd>
        <?php if ($job['notes'] !== ''): ?><dt>Remarques</dt><dd><?= h($job['notes']) ?></dd><?php endif; ?>
        <dt>Créée le</dt><dd><?= h(pr_fr_datetime($job['created_at'])) ?></dd>
      </dl>
      <?php if (pr_job_can_delete($job)): ?>
        <?= pr_form_open('delete', ['id' => $id], 'Supprimer définitivement cette commande et son fichier ?', 'bv-inline') ?>
          <div class="bv-actions"><button class="btn-small bv-btn-danger" type="submit">Supprimer la commande</button></div></form>
      <?php endif; ?>
    </div>

    <div class="bv-card">
      <h2>Journal</h2>
      <ul class="bv-todo" style="margin-top:8px;">
        <?php foreach ($log as $l): ?>
          <li><span class="bv-muted bv-num" style="min-width:110px;"><?= h(pr_fr_datetime($l['at'])) ?></span>
            <span><strong><?= h($l['event']) ?></strong><?= $l['detail'] !== '' ? '<br><span class="bv-muted">' . h($l['detail']) . '</span>' : '' ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<?php
pr_admin_footer();
