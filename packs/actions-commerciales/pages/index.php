<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';

$ent = addon_entitlement(ACO_MODULE);
$cats = category_list();
$starts = date('Y-m-d\TH:i', time() + 3600);
$ends = date('Y-m-d\TH:i', time() + 4 * 3600);
$pageUrl = static fn (string $v): string => '/admin/plugin.php?' . http_build_query(['p' => $plugin['id'], 'v' => $v, 'id' => '']);
?>
<div id="aco-page" style="display:grid;gap:16px;max-width:980px;" data-api="<?= h($pluginApi('operations')) ?>" data-contacts="<?= h($pluginApi('contacts')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>" data-campaigns="<?= h($pageUrl('campagnes')) ?>" data-labels="<?= h($pageUrl('etiquettes')) ?>" data-report="<?= h($pageUrl('bilan')) ?>" data-export="<?= h($pluginApi('export')) ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:0;">Actions commerciales</h1>
  <?= addon_panel_html(ACO_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(ACO_MODULE) ?>
  <?php else: ?>
  <p class="hint" style="max-width:72ch;margin:0;">Braderie, vente flash, foire ou période de soldes : les pièces choisies passent à leur prix réduit à l'heure dite, puis retrouvent leur prix d'origine à la fin. Les prix réduits sont ceux que facturent la boutique, la caisse et les galeries dont la boutique est membre. Un bandeau et une étiquette sur les photos signalent l'opération, avec un appel à l'action dans le hero de l'accueil et en tête du catalogue. Les ventes de la période sont chiffrées dans le bilan.</p>

  <section style="border:1px solid var(--line);padding:16px;display:grid;gap:12px;">
    <h2 style="margin:0;font-size:1.05rem;">Nouvelle opération</h2>
    <form id="aco-form" style="display:grid;gap:12px;">
      <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));">
        <label>Type
          <select name="kind">
            <?php foreach (aco_kind_labels() as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Nom de l'opération
          <input type="text" name="name" maxlength="80" placeholder="Braderie d'automne">
        </label>
        <label>Nom sur les étiquettes
          <input type="text" name="label" maxlength="30" placeholder="Braderie">
        </label>
        <label>Lieu / stand
          <input type="text" name="place" maxlength="120" placeholder="Place du marché">
        </label>
        <label>Début
          <input type="datetime-local" name="starts_at" value="<?= h($starts) ?>" required>
        </label>
        <label>Fin
          <input type="datetime-local" name="ends_at" value="<?= h($ends) ?>" required>
        </label>
      </div>

      <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));">
        <label>Remise
          <select name="discount_type">
            <option value="percent">Pourcentage (%)</option>
            <option value="amount">Montant fixe (€)</option>
          </select>
        </label>
        <label>Valeur
          <input type="text" name="discount_value" value="30" inputmode="decimal" required>
        </label>
        <label>Arrondi du prix
          <select name="round_to">
            <option value="0">Aucun</option>
            <option value="5">à 0,05 €</option>
            <option value="10">à 0,10 €</option>
            <option value="50">à 0,50 €</option>
            <option value="100">à 1 €</option>
          </select>
        </label>
        <label>Prix minimum (€)
          <input type="text" name="min_price" inputmode="decimal" placeholder="facultatif">
        </label>
        <label>Prix maximum (€)
          <input type="text" name="max_price" inputmode="decimal" placeholder="facultatif">
        </label>
        <label>Pièces plus récentes que (jours)
          <input type="number" name="min_age_days" min="0" max="3650" value="0" style="width:100%;">
        </label>
      </div>

      <label>Références concernées (séparées par des virgules)
        <input type="text" name="refs" placeholder="Vide = toutes les pièces des catégories cochées">
      </label>

      <fieldset style="border:1px solid var(--line);padding:10px 12px;display:grid;gap:6px;">
        <legend class="hint" style="padding:0 4px;">Catégories (aucune cochée = toutes)</legend>
        <div style="display:flex;gap:8px 18px;flex-wrap:wrap;">
          <?php foreach ($cats as $c): ?>
            <label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="cats[]" value="<?= h($c['key']) ?>"> <?= h($c['label']) ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div style="display:grid;gap:8px;">
        <input type="hidden" name="respect_floor" value="0">
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="respect_floor" value="1" checked> Ne jamais descendre sous le prix plancher de la pièce</label>
        <input type="hidden" name="show_banner" value="0">
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="show_banner" value="1" checked> Afficher une bannière dans la boutique</label>
        <label>Texte de la bannière
          <input type="text" name="banner_text" maxlength="160" placeholder="Braderie ce week-end : jusqu'à −40 % sur une sélection">
        </label>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button type="button" class="btn-small" id="aco-preview-btn">Aperçu des prix</button>
        <button type="submit" class="btn-small">Programmer l'opération</button>
        <span class="hint" style="margin:0;">Une opération dont le début est déjà passé s'applique tout de suite.</span>
      </div>
      <p class="hint" id="aco-msg" role="status" style="margin:0;"></p>
    </form>
  </section>

  <section id="aco-preview" hidden style="border:1px solid var(--line);padding:16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Aperçu</h2>
    <p id="aco-preview-summary" style="margin:0;"></p>
    <ul id="aco-preview-warnings" class="hint" style="margin:0;padding-left:20px;"></ul>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;">
      <thead><tr><th align="left">Référence</th><th align="left">Pièce</th><th align="right">Avant</th><th align="right">Après</th></tr></thead>
      <tbody id="aco-preview-rows"></tbody>
    </table></div>
    <p class="hint" style="margin:0;" id="aco-preview-note"></p>
  </section>

  <section style="border:1px solid var(--line);padding:16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Opérations</h2>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="aco-table">
      <thead><tr><th align="left">Opération</th><th align="left">Période</th><th align="left">Remise</th><th align="left">État</th><th align="right">Pièces</th><th align="right">Valeur</th><th></th></tr></thead>
      <tbody></tbody>
    </table></div>
    <p class="hint" style="margin:0;max-width:78ch;">« Prévenir » envoie l'annonce aux visiteurs qui ont donné leur accord, sur les canaux choisis : e-mail, annonce dans l'app pour les personnes reconnues sur le site, SMS et WhatsApp pour celles qui ont laissé un numéro et accepté le canal. Chaque message contient un lien de désinscription, une adresse n'est jamais prévenue deux fois pour la même opération sur un canal, et les envois partent par lots de <?= ACO_NOTICE_BATCH ?>.</p>
  </section>

  <section style="border:1px solid var(--line);padding:16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Contacts & consentements</h2>
    <p class="hint" style="margin:0;max-width:78ch;">L'e-mail suit l'inscription aux listes de la boutique ; l'annonce dans l'app ne s'affiche qu'aux personnes reconnues (compte client ou lien de la web app). Pour le SMS et WhatsApp, saisissez le numéro et cochez l'accord que la personne a donné en boutique ou par formulaire. « Désinscrit » signifie qu'elle a demandé à ne plus recevoir d'annonces : elle n'est plus contactée, quels que soient les canaux.</p>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.88rem;" id="aco-contacts">
      <thead><tr><th align="left">Contact</th><th align="left">Listes</th><th align="left">Téléphone</th><th align="center">Dans l'app</th><th align="center">SMS</th><th align="center">WhatsApp</th><th></th></tr></thead>
      <tbody></tbody>
    </table></div>
    <p class="hint" id="aco-contacts-msg" role="status" style="margin:0;"></p>
  </section>

  <p class="hint" style="max-width:72ch;margin:0;">Pour des soldes, la période légale ne dépasse pas quatre semaines consécutives et le prix barré doit correspondre au prix pratiqué avant la période. Une opération peut être arrêtée à tout moment : les prix d'origine sont alors rétablis.</p>
  <?php endif; ?>
</div>
<?php if ($ent['entitled']): ?><script src="<?= h($pluginAsset('actions.js')) ?>" defer></script><?php endif; ?>
