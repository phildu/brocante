<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ra.php';
$ent = addon_entitlement(RA_MODULE);
$s = ra_settings();
?>
<div id="ra-page" style="display:grid;gap:16px;max-width:1000px;" data-api="<?= h($pluginApi('reservations')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Réservations (arrhes ou acompte)</h1>
  <?= addon_panel_html(RA_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(RA_MODULE) ?>
  <?php else: ?>
  <p class="hint" id="ra-stripe" style="max-width:70ch;"></p>
  <p class="hint" style="max-width:70ch;"><strong>À faire valider de votre côté :</strong> la nature du versement (<em>arrhes</em> : chacun peut renoncer, le client qui renonce les perd, le vendeur qui renonce rend le double ; <em>acompte</em> : la vente est ferme, le solde reste dû et la boutique peut l'exiger), le droit de rétractation de 14 jours qui s'applique aux ventes à distance et la rédaction des conditions ci-dessous relèvent du droit de la consommation. Les conditions affichées au client sont enregistrées avec chaque réservation.</p>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Réglages</h2>
    <form id="ra-settings" style="display:grid;gap:8px;max-width:700px;">
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <label>Type de versement<br><select name="mode"><option value="arrhes"<?= ra_mode_of($s) === 'arrhes' ? ' selected' : '' ?>>Arrhes (chacun peut renoncer)</option><option value="acompte"<?= ra_mode_of($s) === 'acompte' ? ' selected' : '' ?>>Acompte (vente ferme)</option></select></label>
        <label>Versement (% du prix)<br><input type="text" name="percent" value="<?= h($s['percent']) ?>" style="width:90px;"></label>
        <label>Minimum (€)<br><input type="text" name="min_cents" value="<?= h(number_format((int) $s['min_cents'] / 100, 2, ',', '')) ?>" style="width:90px;"></label>
        <label>Durée (jours, 7 max.)<br><input type="number" name="days" min="1" max="7" value="<?= h($s['days']) ?>" style="width:90px;"></label>
        <label><br><input type="checkbox" name="enabled" value="1"<?= $s['enabled'] === '1' ? ' checked' : '' ?>> Réservations ouvertes</label>
      </div>
      <label>Conditions affichées au client — arrhes (variables : {arrhes} {pourcent} {piece} {date})<br><textarea name="terms" rows="5" style="width:100%;"><?= h($s['terms']) ?></textarea></label>
      <label>Conditions affichées au client — acompte (variables : {acompte} {pourcent} {piece} {date})<br><textarea name="terms_acompte" rows="5" style="width:100%;"><?= h($s['terms_acompte']) ?></textarea></label>
      <p class="hint" style="margin:0;">Seules les conditions du type choisi ci-dessus sont montrées aux clients ; chaque réservation garde le texte et le type en vigueur au moment où elle a été faite.</p>
      <div><button type="submit" class="btn-small">Enregistrer</button> <span class="hint" id="ra-settings-msg" role="status"></span></div>
    </form>
  </section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Réservations</h2>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="ra-table">
      <thead><tr><th align="left">Pièce</th><th align="left">Client</th><th align="right">Versement / prix</th><th align="left">État</th><th align="left">Échéance</th><th></th></tr></thead><tbody></tbody>
    </table></div>
    <p class="hint" id="ra-msg" role="status"></p>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('reservations.js')) ?>" defer></script>
