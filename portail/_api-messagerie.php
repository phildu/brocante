<?php
// Messagerie du portail : boîte contact@bricbrok.com créée chez OVH et utilisée pour envoyer les messages de la plateforme.
header('Cache-Control: no-store');
require_once PORTAIL_ROOT . '/includes/portal-mailer.php';
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
            portal_mailer_save([
                'domain' => $_POST['domain'] ?? '', 'account' => $_POST['account'] ?? '', 'from_name' => $_POST['from_name'] ?? '',
                'host' => $_POST['host'] ?? '', 'port' => $_POST['port'] ?? '', 'secure' => $_POST['secure'] ?? '',
                'pass' => $_POST['pass'] ?? '', 'enabled' => isset($_POST['enabled']) ? '1' : '0',
            ]);
            portal_flash('Réglages de la messagerie enregistrés. Un mot de passe laissé vide a été conservé.');
            saas_event('config_changed', ['note' => 'Messagerie du portail']);
        } elseif ($action === 'create' || $action === 'reset') {
            $r = portal_mailer_ovh_ensure($action === 'reset');
            portal_flash($r['message']);
            saas_event('config_changed', ['note' => 'Messagerie du portail : ' . $r['action']]);
        } elseif ($action === 'test') {
            $r = portal_mailer_test((string) ($_POST['to'] ?? ''));
            if (!$r['ok']) throw new RuntimeException($r['error']);
            portal_flash('Message d’essai envoyé à ' . trim((string) $_POST['to']) . '. Vérifiez la boîte de réception et le dossier des indésirables.');
        } elseif ($action === 'delete') {
            portal_mailer_delete();
            portal_flash('Réglages de la messagerie supprimés : les messages repartent par la fonction mail() de l’hébergement.');
            saas_event('config_changed', ['note' => 'Messagerie du portail supprimée']);
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
    } catch (InvalidArgumentException | RuntimeException | JsonException $ex) {
        $error = $ex->getMessage();
    }
    if ($error === '') {
        header('Location: /portail/api.php?s=messagerie', true, 303);
        exit;
    }
}

$config = [];
try {
    $config = portal_mailer_config();
} catch (RuntimeException $ex) {
    $error = $error !== '' ? $error : $ex->getMessage();
    $config = ['domain' => 'bricbrok.com', 'account' => 'contact', 'from' => 'contact@bricbrok.com', 'from_name' => 'Bricbrok', 'host' => 'ssl0.ovh.net', 'port' => 465, 'secure' => 'ssl', 'pass' => '', 'enabled' => true];
}
$ready = portal_mailer_ready($config);
$dns = portal_mailer_dns_check($config['domain']);
$ovhConfigured = is_file(PORTAIL_ROOT . '/.secrets/ovh.json') || trim((string) getenv('OVH_APPLICATION_KEY')) !== '';
$fileConfigured = is_file(portal_mailer_file());
?>
<style>
  .mel-wrap { display:grid; gap:16px; max-width:860px; margin:0 auto; padding:0 16px 48px; }
  .mel-card { border:1px solid var(--line); background:var(--panel); border-radius:10px; padding:16px; display:grid; gap:12px; }
  .mel-card h2 { margin:0; font-size:1.05rem; }
  .mel-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
  .mel-fields label { display:grid; gap:5px; font-size:.84rem; font-weight:600; }
  .mel-fields input,.mel-fields select { width:100%; min-width:0; padding:9px 10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:inherit; font:inherit; }
  .mel-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
  .mel-error { padding:12px; border:1px solid #b3261e; border-radius:8px; color:#b3261e; }
  code { overflow-wrap:anywhere; }
</style>
  <?php if ($error !== ''): ?><p class="mel-error" role="alert"><?= e($error) ?></p><?php endif; ?>

  <main class="mel-wrap">
    <section class="mel-card">
      <h2>État</h2>
      <p>
        <span class="pill <?= $ready ? 'done' : 'sent' ?>"><?= $ready ? 'Envoi par ' . e($config['from']) . ' actif' : 'Inactif : fonction mail() de l’hébergement' ?></span>
        <span class="pill <?= $dns['mx'] ? 'done' : 'sent' ?>">MX OVH <?= $dns['mx'] ? 'détecté' : 'non détecté' ?></span>
        <span class="pill <?= $dns['spf'] ? 'done' : 'sent' ?>">SPF OVH <?= $dns['spf'] ? 'détecté' : 'non détecté' ?></span>
      </p>
      <p class="meta">Les messages de la plateforme (inscriptions, reçus, équipe, retours) partent de <code><?= e($config['from']) ?></code> par le serveur SMTP d’OVH. Les boutiques qui écrivent depuis leur propre domaine ne sont pas concernées. Si l’envoi échoue, le message repart par <code>mail()</code> : rien n’est bloqué. Le mot de passe n’est jamais réaffiché. Les contrôles MX et SPF sont indicatifs (DNS vu depuis ce serveur) ; sans SPF, ajoutez <code>include:mx.ovh.com</code> à l’enregistrement TXT du domaine.</p>
    </section>

    <section class="mel-card">
      <h2>1. Créer la boîte <?= e($config['from']) ?> chez OVH</h2>
      <p class="meta">Utilise les identifiants de l’onglet « API OVH (domaines) » (<?= $ovhConfigured ? 'configurés' : '<strong>manquants</strong>' ?>). Le jeton doit autoriser <code>GET /email/domain/*</code>, <code>POST /email/domain/*</code> ; c’est une offre « MX Plan » (e-mail inclus avec l’hébergement ou offre E-mail). Un mot de passe aléatoire est généré et rangé dans <code>.secrets/mailer.json</code>.</p>
      <div class="mel-actions">
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create">
          <button class="btn btn-primary" type="submit"<?= !$ovhConfigured ? ' disabled' : '' ?>>Créer / vérifier la boîte</button></form>
        <form method="post" onsubmit="return confirm('Générer un nouveau mot de passe pour <?= e($config['from']) ?> ? L’ancien cessera de fonctionner (webmail, téléphone…).');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset">
          <button class="btn small" type="submit"<?= !$ovhConfigured ? ' disabled' : '' ?>>Changer le mot de passe</button></form>
      </div>
    </section>

    <form class="mel-card" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <h2>2. Réglages d’envoi</h2>
      <div class="mel-fields">
        <label>Domaine<input type="text" name="domain" value="<?= e($config['domain']) ?>" required></label>
        <label>Boîte (avant @)<input type="text" name="account" value="<?= e($config['account']) ?>" required></label>
        <label>Nom affiché<input type="text" name="from_name" value="<?= e($config['from_name']) ?>"></label>
        <label>Serveur SMTP<input type="text" name="host" value="<?= e($config['host']) ?>" required></label>
        <label>Port<input type="number" name="port" min="1" max="65535" value="<?= e((string) $config['port']) ?>" required></label>
        <label>Sécurité
          <select name="secure"><?php foreach (['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $config['secure'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label>Mot de passe de la boîte
          <input type="password" name="pass" autocomplete="new-password" placeholder="<?= $config['pass'] !== '' ? 'Déjà enregistré — laisser vide pour conserver' : 'Mot de passe (ou créez la boîte ci-dessus)' ?>"></label>
      </div>
      <label><input type="checkbox" name="enabled" value="1"<?= $config['enabled'] ? ' checked' : '' ?>> Utiliser ce mailer (décoché : retour à <code>mail()</code>)</label>
      <div class="mel-actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>

    <form class="mel-card" method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="test">
      <h2>3. Envoyer un message d’essai</h2>
      <div class="mel-fields"><label>Destinataire<input type="email" name="to" required></label></div>
      <div class="mel-actions"><button class="btn" type="submit"<?= !$ready ? ' disabled' : '' ?>>Envoyer l’essai</button></div>
    </form>

    <?php if ($fileConfigured): ?>
      <form class="mel-card" method="post" onsubmit="return confirm('Supprimer les réglages de la messagerie ? Les messages repartiront par mail().');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <h2>Retirer les réglages</h2>
        <p class="meta">Supprime uniquement <code>.secrets/mailer.json</code>. La boîte chez OVH n’est pas supprimée.</p>
        <div><button class="btn small danger" type="submit">Supprimer les réglages</button></div>
      </form>
    <?php endif; ?>
  </main>
