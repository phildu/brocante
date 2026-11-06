<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ev.php';
$ent = addon_entitlement(EV_MODULE);
?>
<div id="ev-page" style="display:grid;gap:16px;max-width:1000px;" data-api="<?= h($pluginApi('fidelite')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Espace visiteur & fidélité</h1>
  <?= addon_panel_html(EV_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(EV_MODULE) ?>
  <?php else: ?>
  <p class="hint" style="max-width:72ch;">Vos visiteurs ouvrent leur espace sur <a id="ev-link" href="/visiteur.php" target="_blank" rel="noopener">/visiteur.php</a> (lien affiché sur la page d'accueil de la boutique et sur la page de la galerie). Ils y retrouvent leurs points, leurs alertes, leurs réservations et le scan des QR codes — les rubriques des autres modules n'apparaissent que si ces modules sont actifs. Aucun mot de passe : un lien personnel est envoyé par e-mail ; les points ne sont crédités qu'aux adresses vérifiées par ce lien.</p>
  <p class="hint" id="ev-sections" style="max-width:72ch;"></p>
  <div id="ev-stats" style="display:flex;gap:10px;flex-wrap:wrap;"></div>

  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Programme de fidélité</h2>
    <form id="ev-settings" style="display:grid;gap:10px;max-width:760px;">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <label>Nom du programme<br><input type="text" name="program_name" maxlength="40" style="width:200px;"></label>
        <label>Points par euro dépensé<br><input type="text" name="points_per_euro" style="width:110px;" inputmode="decimal"></label>
        <label>Points de bienvenue<br><input type="number" name="welcome_points" min="0" max="1000" style="width:110px;"></label>
        <label><br><input type="checkbox" name="enabled" value="1"> Programme ouvert</label>
      </div>
      <div>
        <strong style="font-size:.9rem;">Récompenses (jusqu'à <?= EV_MAX_TIERS ?>)</strong>
        <p class="hint" style="margin:2px 0 6px;">Un palier = un nombre de points échangé contre une remise en euros, utilisable une fois sur une commande (au moins 1 € reste à payer). Laissez une ligne vide pour la retirer.</p>
        <div id="ev-tiers" style="display:grid;gap:6px;"></div>
      </div>
      <div><button type="submit" class="btn-small">Enregistrer</button> <span class="hint" id="ev-settings-msg" role="status"></span></div>
    </form>
    <p class="hint" style="max-width:72ch;">Les points sont calculés sur le montant des pièces payé en ligne, hors livraison, après remises. À faire valider de votre côté : l'information des membres sur leurs données (leur adresse sert uniquement au programme), la durée de conservation et les conditions du programme.</p>
  </section>

  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><h2 style="margin:0;font-size:1.05rem;">Membres</h2><a class="btn-small" id="ev-csv" href="#">Exporter (CSV)</a></div>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="ev-table">
      <thead><tr><th align="left">E-mail</th><th align="left">Inscrit le</th><th align="right">Points</th><th align="right">Gagnés</th><th align="left">Ajuster</th><th></th></tr></thead><tbody></tbody>
    </table></div>
    <p class="hint" id="ev-msg" role="status"></p>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('espace.js')) ?>" defer></script>
