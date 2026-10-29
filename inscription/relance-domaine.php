<?php
require_once __DIR__ . '/../includes/saas.php';

session_start();
if (empty($_SESSION['saas_csrf'])) $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));

$req = saas_request_by_link((string) ($_REQUEST['r'] ?? ''), (string) ($_REQUEST['k'] ?? ''));
$error = '';
$done = false;
if (!$req || ($req['branding']['domain_followup']['status'] ?? '') !== 'pending') {
    $error = 'Ce lien de désinscription n’est plus valide.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['saas_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Le formulaire a expiré : rechargez la page et recommencez.';
    } else {
        $latest = saas_request_by_link((string) ($_POST['r'] ?? ''), (string) ($_POST['k'] ?? ''));
        if (!$latest || ($latest['branding']['domain_followup']['status'] ?? '') !== 'pending') {
            $error = 'Ce lien de désinscription n’est plus valide.';
        } else {
            $latest['branding']['domain_followup']['status'] = 'opted_out';
            $latest['branding']['domain_followup']['consent_revoked_at'] = time();
            if (!saas_request_save($latest)) {
                $error = 'Impossible d’enregistrer votre désinscription. Réessayez.';
            } else {
                saas_event('domain_followup_opted_out', [
                    'ref' => (string) $latest['id'],
                    'email' => (string) ($latest['email'] ?? ''),
                    'plan' => (string) ($latest['plan'] ?? ''),
                    'actor' => 'client',
                ]);
                $done = true;
            }
        }
    }
}

$cfg = saas_config();
saas_page_start('Relances de domaine — ' . $cfg['name'], 'Gérez votre accord pour recevoir des relances au sujet de votre nom de domaine.', 'inscription');
?>
<div class="wrap">
  <div class="page-title"><p class="eyebrow">Préférences e-mail</p><h1>Relances de nom de domaine</h1></div>
  <section class="form-card">
    <?php if ($done): ?>
      <div class="notice ok" role="status">Votre adresse ne recevra plus de relance au sujet du nom de domaine.</div>
    <?php elseif ($error !== ''): ?>
      <div class="notice error" role="alert"><?= saas_e($error) ?></div>
    <?php else: ?>
      <p>Confirmez que vous ne souhaitez plus recevoir de relance concernant le choix de votre nom de domaine.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= saas_e($_SESSION['saas_csrf']) ?>">
        <input type="hidden" name="r" value="<?= saas_e((string) $req['id']) ?>">
        <input type="hidden" name="k" value="<?= saas_e((string) $req['token']) ?>">
        <button class="btn" type="submit">Ne plus recevoir de relance</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php saas_page_end(); ?>
