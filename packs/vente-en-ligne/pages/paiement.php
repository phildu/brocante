<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vel.php';
require_once dirname(__DIR__, 3) . '/includes/key-check.php';

$connected = connect_shop();
$ready = shop_checkout_ready();
$flash = flash_get();
$carrierCount = count(shipping_carriers_list());
$rateCount = (int) db()->query('SELECT COUNT(*) FROM shipping_rates')->fetchColumn();
$mask = static function (string $key): string {
    return $key === '' ? '' : substr($key, 0, 7) . '…' . substr($key, -4);
};
?>
<link rel="stylesheet" href="<?= h($pluginAsset('vel.css')) ?>">
<div id="vel-page" data-api="<?= h($pluginApi('action')) ?>" data-csrf="<?= h($pluginCsrf) ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Paiement &amp; livraison</h1>
  <p class="hint" style="max-width:78ch;">
    Les clés Stripe de la boutique et les deux modes de livraison de repli. Le paiement est vérifié à la page de retour et
    par « Vérifier auprès de Stripe » sur les commandes en attente (aucun webhook Stripe n'est configuré).
  </p>

  <?php if ($flash): ?>
    <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:14px 0;"><?= h($flash['message']) ?></p>
  <?php endif; ?>
  <p class="vel-msg" id="vel-msg" role="status"></p>

  <div class="vel-kpis">
    <div class="vel-kpi"><span>Paiement en ligne</span><strong style="font-size:1rem;"><?= $ready ? 'Actif' : 'Désactivé' ?></strong></div>
    <div class="vel-kpi"><span>Clé secrète</span><strong style="font-size:.95rem;"><?= STRIPE_SECRET_KEY !== '' ? h($mask(STRIPE_SECRET_KEY)) : 'absente' ?></strong></div>
    <div class="vel-kpi"><span>Clé publiable</span><strong style="font-size:.95rem;"><?= STRIPE_PUBLISHABLE_KEY !== '' ? h($mask(STRIPE_PUBLISHABLE_KEY)) : 'absente' ?></strong></div>
    <div class="vel-kpi"><span>Transporteurs</span><strong><?= $carrierCount ?></strong></div>
    <div class="vel-kpi"><span>Paliers de port</span><strong><?= $rateCount ?></strong></div>
  </div>

  <p class="publish-status" data-kind="<?= $ready ? '' : 'error' ?>" style="margin:0 0 16px;">
    <?php if ($connected): ?>
      Paiement géré par la plateforme (Stripe Connect) — les clients paient sur le compte relié de la boutique.
    <?php elseif (stripe_configured()): ?>
      Paiement Stripe configuré et actif sur la boutique.
    <?php else: ?>
      Aucune clé secrète Stripe valide : le paiement est désactivé sur le site.
    <?php endif; ?>
  </p>

  <section class="vel-card">
    <h2>Clés Stripe</h2>
    <p class="hint" style="margin:0 0 10px;">Clés de <a href="https://dashboard.stripe.com/test/apikeys" target="_blank" rel="noopener">dashboard.stripe.com/test/apikeys</a> (mode test : <code>sk_test_…</code> / <code>pk_test_…</code> ; en production, les clés <code>sk_live_…</code> / <code>pk_live_…</code>). Un champ laissé vide conserve la clé déjà enregistrée — elle n'est jamais réaffichée.</p>
    <form class="vel-form" data-vel-form="save_stripe_keys">
      <label style="flex:1;min-width:260px;">Clé secrète (sk_…)
        <input type="password" name="stripe_secret" autocomplete="off" placeholder="<?= STRIPE_SECRET_KEY !== '' ? 'Déjà enregistrée — ' . h($mask(STRIPE_SECRET_KEY)) : 'sk_test_…' ?>">
      </label>
      <label style="flex:1;min-width:260px;">Clé publiable (pk_…)
        <input type="text" name="stripe_publishable" autocomplete="off" placeholder="<?= STRIPE_PUBLISHABLE_KEY !== '' ? 'Déjà enregistrée — ' . h($mask(STRIPE_PUBLISHABLE_KEY)) : 'pk_test_…' ?>">
      </label>
      <button type="submit" class="btn-small">Enregistrer les clés</button>
    </form>
    <?php if (STRIPE_SECRET_KEY !== ''): ?>
      <div style="margin-top:12px;"><?= keycheck_status_html('stripe') ?></div>
    <?php endif; ?>
  </section>

  <section class="vel-card">
    <h2>Livraison de repli</h2>
    <p class="hint" style="margin:0 0 10px;">
      Ces deux modes servent quand aucun transporteur ne correspond à la zone ou au poids du panier. Les transporteurs et
      leurs paliers se règlent dans <a href="<?= h(vel_page_url($plugin, 'livraison')) ?>">Frais de port</a>.
    </p>
    <form class="vel-form" data-vel-form="save_content">
      <label>Libellé retrait (gratuit) <input type="text" name="pickup_label" value="<?= h($content['pickup_label']) ?>"></label>
      <label>Libellé envoi <input type="text" name="shipping_label" value="<?= h($content['shipping_label']) ?>"></label>
      <label>Tarif d'envoi <input type="text" name="shipping_fee" value="<?= h($content['shipping_fee']) ?>"></label>
      <label>Pas de livraison sous (€) <input type="number" min="0" step="0.01" name="pickup_only_below" value="<?= h((string) ($content['pickup_only_below'] ?? '10')) ?>"></label>
      <label style="flex:1;min-width:240px;">Message affiché à la place de la livraison <input type="text" name="pickup_only_message" value="<?= h((string) ($content['pickup_only_message'] ?? "Retrait à l'adresse du vendeur.")) ?>"></label>
      <button type="submit" class="btn-small">Enregistrer</button>
    </form>
    <p class="hint" style="margin:10px 0 0;">Les dons (0 €) et les pièces sous ce seuil ne sont proposés qu'en retrait ; si le panier contient au moins une pièce livrable, la livraison reste proposée. Mettez 0 pour ne l'appliquer qu'aux dons.</p>
  </section>

  <section class="vel-card">
    <h2>Bon à savoir</h2>
    <ul class="hint" style="margin:0;padding-left:18px;max-width:78ch;">
      <li>Sans webhook Stripe, une commande payée dont le client a fermé l'onglet reste « en attente » : le bouton « Vérifier auprès de Stripe » la recale.</li>
      <li>Le poids de chaque pièce (champ « Poids (g) » du <a href="/admin/catalog.php">catalogue</a>) détermine le palier de port proposé.</li>
      <li>Les comptes clients et les notes internes sont dans <a href="<?= h(vel_page_url($plugin, 'clients')) ?>">Clients (CRM)</a>.</li>
    </ul>
  </section>
</div>
<script src="<?= h($pluginAsset('vel.js')) ?>" defer></script>
