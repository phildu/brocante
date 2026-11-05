<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';

$ent = addon_entitlement(ACO_MODULE);
$back = '/admin/plugin.php?' . http_build_query(['p' => $plugin['id'], 'v' => 'index']);
$wanted = (int) ($_GET['id'] ?? 0);
?>
<div id="aco-camp" style="display:grid;gap:16px;max-width:980px;" data-api="<?= h($pluginApi('campagnes')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>" data-op="<?= $wanted ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:0;">Campagnes de communication</h1>
  <?= addon_panel_html(ACO_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(ACO_MODULE) ?>
  <?php else: ?>
  <p class="hint" style="max-width:78ch;margin:0;">Une campagne est un plan d'envois rattaché à une opération : annonce avant le début, lancement, dernier jour… Chaque étape part à l'heure prévue vers les personnes qui ont donné leur accord (e-mail, annonce dans l'app, SMS, WhatsApp), avec un lien de désinscription. Pour les réseaux sociaux, l'étape vous rappelle quoi publier avec le texte prêt à copier. Une étape manquée de plus de 6 h n'est pas envoyée en retard. <a href="<?= h($back) ?>">← Actions commerciales</a></p>

  <section style="border:1px solid var(--line);padding:16px;display:grid;gap:12px;">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
      <label style="min-width:260px;flex:1;">Opération
        <select id="ac-op"></select>
      </label>
      <button type="button" class="btn-small" id="ac-generate">Plan standard</button>
      <button type="button" class="btn-small" id="ac-add">Ajouter une étape</button>
    </div>
    <p id="ac-summary" class="hint" style="margin:0;"></p>
    <p id="ac-msg" class="hint" role="status" style="margin:0;"></p>
  </section>

  <section style="border:1px solid var(--line);padding:16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Étapes</h2>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="ac-table">
      <thead><tr><th align="left">Étape</th><th align="left">Échéance</th><th align="left">Canaux</th><th align="left">État</th><th></th></tr></thead>
      <tbody></tbody>
    </table></div>
    <p class="hint" id="ac-empty" hidden style="margin:0;">Aucune étape : « Plan standard » propose une annonce, un lancement et un rappel du dernier jour.</p>
  </section>

  <section id="ac-form-box" hidden style="border:1px solid var(--line);padding:16px;display:grid;gap:12px;">
    <h2 style="margin:0;font-size:1.05rem;" id="ac-form-title">Étape</h2>
    <form id="ac-form" style="display:grid;gap:12px;">
      <input type="hidden" name="id" value="0">
      <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));">
        <label>Nom de l'étape
          <input type="text" name="title" maxlength="60" placeholder="Annonce" required>
        </label>
        <label>Décalage
          <span style="display:flex;gap:6px;"><input type="number" name="days" min="0" max="60" value="0" style="width:64px;" aria-label="Jours"> j
            <input type="number" name="hours" min="0" max="23" value="0" style="width:64px;" aria-label="Heures"> h
            <input type="number" name="minutes" min="0" max="59" value="0" style="width:64px;" aria-label="Minutes"> min</span>
        </label>
        <label>Avant ou après
          <select name="direction"><option value="before">Avant</option><option value="after">Après</option></select>
        </label>
        <label>Par rapport à
          <select name="anchor"><option value="start">Début de l'opération</option><option value="end">Fin de l'opération</option></select>
        </label>
      </div>
      <fieldset style="border:1px solid var(--line);padding:10px 12px;display:grid;gap:6px;">
        <legend class="hint" style="padding:0 4px;">Canaux</legend>
        <div id="ac-channels" style="display:flex;gap:8px 18px;flex-wrap:wrap;"></div>
        <p class="hint" style="margin:0;">Le SMS et WhatsApp ne partent que vers les personnes qui ont donné leur numéro et accepté le canal ; un canal non configuré est ignoré.</p>
      </fieldset>
      <label>Objet (e-mail)
        <input type="text" name="subject" maxlength="150" placeholder="Vide = « opération, dates — boutique »">
      </label>
      <label>Message
        <textarea name="body" rows="8" maxlength="3000" required></textarea>
      </label>
      <p class="hint" style="margin:0;" id="ac-fields"></p>
      <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="enabled" value="1" checked> Étape active</label>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" class="btn-small">Enregistrer l'étape</button>
        <button type="button" class="btn-small" id="ac-cancel">Annuler</button>
      </div>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php if ($ent['entitled']): ?><script src="<?= h($pluginAsset('campagnes.js')) ?>" defer></script><?php endif; ?>
