<?php
// Identifiants API OVH de la plateforme : réservés à l'exploitant du portail.
header('Cache-Control: no-store');
require_once PORTAIL_ROOT . '/includes/branding-services.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';

if (defined('GA_LIMITED')) {
    http_response_code(403);
    exit('Accès réservé à l’exploitant du portail.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            branding_domain_config_save($_POST);
            portail_flash('Configuration OVH enregistrée. Les valeurs vides ont été conservées.');
            saas_event('config_changed', ['note' => 'Configuration API OVH']);
        } elseif ($action === 'test') {
            $result = branding_ovh_test_connection();
            portail_flash('Connexion OVH réussie : authentification, création de panier et recherche de domaine testées (' . $result['endpoint'] . ', filiale ' . $result['subsidiary'] . '). Aucun domaine n’a été commandé.');
            saas_event('config_changed', ['note' => 'Test de connexion API OVH réussi']);
        } elseif ($action === 'delete') {
            branding_domain_config_delete();
            portail_flash('Configuration OVH du fichier supprimée. Les variables d’environnement, si présentes, restent prioritaires.');
            saas_event('config_changed', ['note' => 'Configuration API OVH supprimée du fichier']);
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
    } catch (InvalidArgumentException | RuntimeException | JsonException $ex) {
        $error = $ex->getMessage();
    }
    if ($error === '') {
        header('Location: /portail/api.php?s=ovh', true, 303);
        exit;
    }
}

$saved = [];
$config = [];
try {
    $saved = branding_domain_config_saved();
    $config = branding_domain_config();
} catch (RuntimeException $ex) {
    $error = $ex->getMessage();
}
$envFields = [];
foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY', 'OVH_ENDPOINT', 'OVH_SUBSIDIARY', 'OVH_DOMAIN_MARGIN_EUR'] as $key) {
    $envFields[$key] = trim((string) getenv($key)) !== '';
}
$credentialsReady = !empty($config['application_key']) && !empty($config['application_secret']) && !empty($config['consumer_key']);
$fileConfigured = is_file(branding_domain_config_file());
?>
<style>
  .ovh-wrap { display:grid; gap:16px; max-width:860px; margin:0 auto; padding:0 16px 48px; }
  .ovh-card { border:1px solid var(--line); background:var(--panel); border-radius:10px; padding:16px; display:grid; gap:12px; }
  .ovh-card h2 { margin:0; font-size:1.05rem; }
  .ovh-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px; }
  .ovh-fields label { display:grid; gap:5px; font-size:.84rem; font-weight:600; }
  .ovh-fields input,.ovh-fields select { width:100%; min-width:0; padding:9px 10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:inherit; font:inherit; }
  .ovh-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
  .ovh-alert { padding:12px; border:1px solid var(--warn); border-radius:8px; }
  .ovh-error { padding:12px; border:1px solid #b3261e; border-radius:8px; color:#b3261e; }
  code { overflow-wrap:anywhere; }
</style>
  <?php if ($error !== ''): ?><p class="ovh-error" role="alert"><?= e($error) ?></p><?php endif; ?>

  <main class="ovh-wrap">
    <section class="ovh-card">
      <h2>État de la connexion</h2>
      <p><span class="pill <?= $credentialsReady ? 'done' : 'sent' ?>"><?= $credentialsReady ? 'Identifiants configurés' : 'Identifiants manquants' ?></span>
        <?php if ($fileConfigured): ?> <span class="meta">Fichier sécurisé présent</span><?php endif; ?></p>
      <p class="meta">Les champs secrets ne sont jamais réaffichés. Laisser un champ vide conserve sa valeur actuelle. Une variable d’environnement configurée sur le serveur est prioritaire sur ce formulaire.</p>
      <?php
        $overrides = array_keys(array_filter($envFields));
        if ($overrides):
      ?>
        <p class="ovh-alert"><strong>Configuration serveur prioritaire :</strong> <?= e(implode(', ', $overrides)) ?>. Modifie ces valeurs dans la configuration d’hébergement pour que le formulaire soit pris en compte.</p>
      <?php endif; ?>
    </section>

    <form class="ovh-card" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <h2>Identifiants d’application OVH</h2>
      <p class="meta">Colle ici les identifiants générés dans la console API OVH. Utilise un jeton avec uniquement les droits indiqués dans l’aide ci-dessous.</p>
      <div class="ovh-fields">
        <label>Application Key
          <input type="password" name="OVH_APPLICATION_KEY" autocomplete="new-password" placeholder="<?= !empty($saved['OVH_APPLICATION_KEY']) || $envFields['OVH_APPLICATION_KEY'] ? 'Déjà configurée — laisser vide pour conserver' : 'Application Key' ?>"<?= $envFields['OVH_APPLICATION_KEY'] ? ' disabled' : '' ?>>
        </label>
        <label>Application Secret
          <input type="password" name="OVH_APPLICATION_SECRET" autocomplete="new-password" placeholder="<?= !empty($saved['OVH_APPLICATION_SECRET']) || $envFields['OVH_APPLICATION_SECRET'] ? 'Déjà configuré — laisser vide pour conserver' : 'Application Secret' ?>"<?= $envFields['OVH_APPLICATION_SECRET'] ? ' disabled' : '' ?>>
        </label>
        <label>Consumer Key
          <input type="password" name="OVH_CONSUMER_KEY" autocomplete="new-password" placeholder="<?= !empty($saved['OVH_CONSUMER_KEY']) || $envFields['OVH_CONSUMER_KEY'] ? 'Déjà configurée — laisser vide pour conserver' : 'Consumer Key' ?>"<?= $envFields['OVH_CONSUMER_KEY'] ? ' disabled' : '' ?>>
        </label>
        <label>Endpoint OVH
          <select name="OVH_ENDPOINT"<?= $envFields['OVH_ENDPOINT'] ? ' disabled' : '' ?>>
            <?php foreach (['ovh-eu' => 'Europe (ovh-eu)', 'ovh-ca' => 'Canada (ovh-ca)', 'ovh-us' => 'États-Unis (ovh-us)'] as $value => $label): ?>
              <option value="<?= e($value) ?>"<?= ($config['endpoint'] ?? 'ovh-eu') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Filiale de facturation
          <input type="text" name="OVH_SUBSIDIARY" maxlength="2" pattern="[A-Za-z]{2}" value="<?= e((string) ($config['subsidiary'] ?? 'FR')) ?>"<?= $envFields['OVH_SUBSIDIARY'] ? ' disabled' : '' ?>>
        </label>
        <label>Marge ajoutée au devis (euros)
          <input type="number" name="OVH_DOMAIN_MARGIN_EUR" min="0" max="1000" step="0.01" value="<?= e(number_format(((int) ($config['margin_cents'] ?? 0)) / 100, 2, '.', '')) ?>"<?= $envFields['OVH_DOMAIN_MARGIN_EUR'] ? ' disabled' : '' ?>>
        </label>
      </div>
      <div class="ovh-actions"><button class="btn btn-primary" type="submit">Enregistrer la configuration</button></div>
    </form>

    <form class="ovh-card" method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="test">
      <h2>Vérifier la connexion depuis le serveur</h2>
      <p class="meta">Teste l’authentification API, la création d’un panier OVH et une lecture de disponibilité sans acheter de domaine. Enregistre d’abord les changements avant le test.</p>
      <div class="ovh-actions"><button class="btn" type="submit"<?= !$credentialsReady ? ' disabled' : '' ?>>Tester l’API OVH</button></div>
    </form>

    <section class="ovh-card">
      <h2>Où obtenir les clés</h2>
      <ol>
        <li>Connecte-toi au compte OVHcloud qui portera et paiera les domaines.</li>
        <li>Ouvre la <a href="https://api.ovh.com/createToken/" target="_blank" rel="noopener">console de création de jeton API OVH ↗</a> et choisis le bon endpoint.</li>
        <li>Crée un jeton avec ces droits : <code>POST /order/cart</code>, <code>GET /order/cart/*</code>, <code>POST /order/cart/*</code> et <code>POST /domain/contact</code>. Pour une configuration plus restrictive, ajoute aussi <code>GET /order/cart/*/item/*/requiredConfiguration</code>, <code>POST /order/cart/*/item/*/configuration</code>, <code>POST /order/cart/*/validate</code> et <code>POST /order/cart/*/checkout</code>.</li>
        <li>Pour le rattachement automatique des domaines des boutiques à l’hébergement, ajoute aussi <code>GET /hosting/web/&lt;service&gt;/attachedDomain</code>, <code>POST /hosting/web/&lt;service&gt;/attachedDomain</code> et <code>DELETE /hosting/web/&lt;service&gt;/attachedDomain/*</code> (remplace <code>&lt;service&gt;</code> par le nom du service, ou <code>*</code>). Le jeton doit être créé avec le compte OVH qui <strong>possède l’hébergement</strong>, pas celui qui détient seulement le nom de domaine.</li>
        <li>Copie l’<strong>Application Key</strong>, l’<strong>Application Secret</strong> et le <strong>Consumer Key</strong> générés dans le formulaire ci-dessus. OVH n’affiche pas toujours le secret à nouveau.</li>
      </ol>
      <p class="meta">Le jeton autorise la préparation et la validation de commandes OVH. Protège ces clés comme un mot de passe ; ne les envoie pas par e-mail ou messagerie. Les achats de domaines ne seront débités que si un moyen de paiement par défaut est configuré dans le compte OVH.</p>
    </section>

    <?php if ($fileConfigured): ?>
      <form class="ovh-card" method="post" onsubmit="return confirm('Supprimer les identifiants OVH du fichier sécurisé ?');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <h2>Retirer la configuration enregistrée</h2>
        <p class="meta">Cette action supprime uniquement <code>.secrets/ovh.json</code>. Les variables d’environnement restent actives si le serveur en définit.</p>
        <div><button class="btn small danger" type="submit">Supprimer les identifiants du fichier</button></div>
      </form>
    <?php endif; ?>
  </main>
