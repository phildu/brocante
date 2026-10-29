<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rt.php';
$ent = addon_entitlement(RT_MODULE);
$s = rt_settings();
?>
<div id="rt-page" style="display:grid;gap:16px;max-width:960px;" data-api="<?= h($pluginApi('pickups')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Retrait sur stand</h1>
  <?= addon_panel_html(RT_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(RT_MODULE) ?>
  <?php else: ?>
  <p class="hint" id="rt-stripe" style="max-width:70ch;"></p>
  <section style="border:2px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Scanner un billet</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
      <button type="button" class="btn-small" id="rt-cam">Ouvrir la caméra</button>
      <label>ou code du billet<br><input type="text" id="rt-code" placeholder="ex. K7Q2MX" maxlength="120" style="text-transform:uppercase;"></label>
      <button type="button" class="btn-small" id="rt-find">Chercher</button>
    </div>
    <video id="rt-video" playsinline muted style="display:none;max-width:360px;width:100%;background:#000;"></video>
    <p class="hint" id="rt-scan-msg" role="status"></p>
    <div id="rt-result" style="display:none;border:1px solid var(--line);padding:12px;gap:8px;"></div>
  </section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Réglages</h2>
    <form id="rt-settings" style="display:grid;gap:8px;max-width:700px;">
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <label>Durée du billet (jours, 6 max.)<br><input type="number" name="days" min="1" max="6" value="<?= h($s['days']) ?>" style="width:90px;"></label>
        <label><br><input type="checkbox" name="enabled" value="1"<?= $s['enabled'] === '1' ? ' checked' : '' ?>> Retrait sur stand ouvert</label>
      </div>
      <label>Lieu et horaires du stand (affiché sur le billet)<br><input type="text" name="place" value="<?= h($s['place']) ?>" placeholder="ex. Marché des Chartrons, dimanche 8h-13h" style="width:100%;"></label>
      <label>Conditions (variables : {piece} {prix} {date})<br><textarea name="terms" rows="4" style="width:100%;"><?= h($s['terms']) ?></textarea></label>
      <div><button type="submit" class="btn-small">Enregistrer</button> <span class="hint" id="rt-settings-msg" role="status"></span></div>
    </form>
    <p class="hint">La somme n'est que bloquée sur la carte du client (7 jours au plus chez Stripe) et prélevée au scan. Faites valider les conditions de vente (retrait, délai, droit de rétractation de la vente à distance).</p>
  </section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Billets</h2>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="rt-table">
      <thead><tr><th align="left">Code</th><th align="left">Pièce</th><th align="left">Client</th><th align="right">Montant</th><th align="left">État</th><th></th></tr></thead><tbody></tbody>
    </table></div>
    <p class="hint" id="rt-msg" role="status"></p>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('stand.js')) ?>" defer></script>
