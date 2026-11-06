<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/pr.php';
$ent = addon_entitlement(PR_MODULE);
?>
<div id="pr-page" style="display:grid;gap:16px;max-width:1000px;" data-api="<?= h($pluginApi('entries')) ?>" data-export="<?= h($pluginApi('export')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Registre de police</h1>
  <?= addon_panel_html(PR_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(PR_MODULE) ?>
  <?php else: ?>
  <p class="hint" style="max-width:70ch;">Les pièces mises en ligne <strong>depuis l'activation du module</strong> sont inscrites automatiquement (numérotation continue, description). Complétez pour chacune le prix d'achat et l'identité du vendeur ; la sortie (date, prix, acquéreur) est renseignée dès la vente. Les entrées ne peuvent pas être supprimées et chaque modification est journalisée dans une chaîne infalsifiable. Les pièces plus anciennes se rajoutent à la main, plus bas.</p>
  <p class="hint" style="max-width:70ch;"><strong>À vérifier de votre côté :</strong> la tenue d'un livre de police numérique doit respecter les conditions fixées par la réglementation (inviolabilité, impression à la demande des autorités). Renseignez-vous auprès de votre préfecture ; l'export papier ci-dessous sert à le présenter. Les pièces d'identité sont des données personnelles : à conserver le temps légal puis à effacer.</p>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
      <h2 style="margin:0;font-size:1.05rem;">Entrées</h2><span class="hint" id="pr-integrity"></span>
      <form id="pr-export" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-left:auto;">
        <label>Du <input type="date" name="from" value="<?= h(date('Y-01-01')) ?>"></label>
        <label>au <input type="date" name="to" value="<?= h(date('Y-m-d')) ?>"></label>
        <a class="btn-small" id="pr-print" target="_blank" rel="noopener">Imprimer le livre</a>
        <a class="btn-small" id="pr-csv">CSV</a>
      </form>
    </div>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="pr-table">
      <thead><tr><th align="left">N°</th><th align="left">Entrée</th><th align="left">Description</th><th align="left">Acquisition</th><th align="left">Sortie</th><th></th></tr></thead><tbody></tbody>
    </table></div>
    <p class="hint" id="pr-msg" role="status"></p>
  </section>
  <section id="pr-edit" style="border:1px solid var(--line);padding:14px 16px;display:none;gap:8px;">
    <h2 style="margin:0;font-size:1.05rem;" id="pr-edit-title"></h2>
    <form id="pr-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;"></form>
  </section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Pièces non inscrites <span class="hint" id="pr-notreg-count"></span></h2>
    <div id="pr-notreg" style="max-height:220px;overflow:auto;display:grid;gap:4px;"></div>
    <div><button type="button" class="btn-small" id="pr-register">Inscrire les pièces cochées</button></div>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('registre.js')) ?>" defer></script>
