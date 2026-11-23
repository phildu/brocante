<?php
// Prix suggérés par les plateformes face aux prix de la boutique : rapport pour repricer.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/catalog.php';
require_once __DIR__ . '/../lib/pricing.php';

$report = am_price_report();
$rows = $report['rows'];
$count = ['lower' => 0, 'raise' => 0, 'ok' => 0];
foreach ($rows as $r) if (isset($count[$r['state']])) $count[$r['state']]++;
$channelName = ['leboncoin' => 'Leboncoin', 'vinted' => 'Vinted'];
$stateLabel = ['lower' => 'À baisser', 'raise' => 'À monter', 'ok' => 'Dans la fourchette', 'unknown' => 'Prix absent'];
?>
<link rel="stylesheet" href="<?= h($pluginAsset('admin.css')) ?>">
<div class="am-page am-price-page" id="am-price" style="max-width:980px;">
  <p class="eyebrow">Option · <?= h($plugin['name']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 8px;">Prix suggérés par les plateformes</h1>
  <p class="hint" style="max-width:68ch;">
    À chaque remplissage d’annonce, l’extension relève le prix que la plateforme propose (fourchette ou valeur) et le compare au prix de la boutique.
    Un prix à plus de <?= (int) round(AM_PRICE_TOLERANCE * 100) ?> % hors fourchette est signalé. Rien n’est modifié automatiquement : vous gardez la main sur vos prix.
  </p>
  <p><a href="<?= h(plugin_page_url('annonces-marche', 'index')) ?>">← Retour aux annonces</a></p>

  <section class="am-card">
    <h2>Rapport
      <span class="am-pill"><?= (int) $count['lower'] ?> à baisser</span>
      <span class="am-pill"><?= (int) $count['raise'] ?> à monter</span>
      <span class="am-pill"><?= (int) $count['ok'] ?> dans la fourchette</span>
    </h2>
    <?php if (!$rows): ?>
      <p class="hint">Aucun prix relevé pour l’instant. Lancez un remplissage (simulation, brouillon ou publication) depuis l’extension : le prix proposé par la plateforme s’y ajoute quand elle en affiche un.</p>
    <?php else: ?>
      <div class="am-actions"><button type="button" class="btn-small" id="am-price-csv">Exporter en CSV</button></div>
      <div class="am-table-scroll"><table class="am-table" id="am-price-table">
        <thead><tr><th>Pièce</th><th>Plateforme</th><th>Mon prix</th><th>Prix proposé</th><th>Conseil</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr data-state="<?= h($r['state']) ?>">
            <td><strong><?= h($r['ref']) ?></strong> · <?= h($r['name']) ?></td>
            <td><?= h($channelName[$r['channel']] ?? $r['channel']) ?></td>
            <td><?= $r['price'] === null ? '—' : h(am_money((float) $r['price'])) ?></td>
            <td><?= $r['min'] === $r['max'] ? h(am_money($r['min'])) : h(am_money($r['min']) . ' – ' . am_money($r['max'])) ?></td>
            <td class="<?= $r['state'] === 'ok' ? 'muted' : '' ?>"><?= h($r['label']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <?php if ($report['unreadable']): ?>
      <p class="hint"><?= (int) $report['unreadable'] ?> relevé(s) où la plateforme parlait d’un prix sans que le montant soit lisible : le texte relevé figure dans l’historique de l’extension.</p>
    <?php endif; ?>
  </section>
</div>
<script src="<?= h($pluginAsset('prix.js')) ?>" defer></script>
