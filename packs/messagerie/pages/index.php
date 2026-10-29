<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ms.php';
$ent = addon_entitlement(MS_MODULE);
$s = ms_settings();
?>
<div id="ms-page" style="display:grid;gap:16px;max-width:960px;" data-api="<?= h($pluginApi('inbox')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Messagerie &amp; WhatsApp</h1>
  <?= addon_panel_html(MS_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(MS_MODULE) ?>
  <?php else: ?>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Réglages</h2>
    <form id="ms-settings" style="display:grid;gap:8px;max-width:620px;">
      <label>Numéro WhatsApp (laisser vide pour masquer le bouton)<br><input type="text" name="whatsapp" value="<?= h($s['whatsapp'] !== '' ? '+' . $s['whatsapp'] : '') ?>" placeholder="+33 6 12 34 56 78" style="width:100%;"></label>
      <label>Message prérempli (utilisez {piece} et {lien})<br><input type="text" name="wa_template" value="<?= h($s['wa_template']) ?>" style="width:100%;"></label>
      <label>Recevoir chaque message par e-mail à<br><input type="email" name="notify_email" value="<?= h($s['notify_email']) ?>" style="width:100%;"></label>
      <label><input type="checkbox" name="show_whatsapp" value="1"<?= $s['show_whatsapp'] === '1' ? ' checked' : '' ?>> Afficher le bouton WhatsApp</label>
      <label><input type="checkbox" name="show_form" value="1"<?= $s['show_form'] === '1' ? ' checked' : '' ?>> Afficher le formulaire « Poser une question »</label>
      <div><button type="submit" class="btn-small">Enregistrer</button> <span class="hint" id="ms-settings-msg" role="status"></span></div>
    </form>
    <p class="hint">Le bouton WhatsApp ouvre la conversation sur le téléphone du visiteur : la discussion se poursuit dans WhatsApp, pas ici.</p>
  </section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Messages reçus</h2>
    <div id="ms-list" style="display:grid;gap:10px;"></div>
    <p class="hint" id="ms-msg" role="status"></p>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('messagerie.js')) ?>" defer></script>
