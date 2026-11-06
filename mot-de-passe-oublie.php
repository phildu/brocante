<?php
// Mot de passe oublié, tous espaces confondus : un seul e-mail par adresse,
// avec les liens de chaque espace qu'elle ouvre (boutique, galerie). Voir
// ua_reset_request() dans includes/unified-auth.php.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/login-ui.php';
require_once __DIR__ . '/includes/unified-auth.php';

$sent = false;
$recipients = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ua_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
        $error = 'La page avait expiré : réessayez.';
    } else {
        $recipients = ua_reset_request((string) ($_POST['login'] ?? ''));
        $sent = true;
    }
}

ob_start();
?>
    <p class="eyebrow">Connexion</p>
    <h1>Mot de passe oublié</h1>
<?php if ($sent): ?>
    <p class="login-ok" role="status">Si un compte correspond à cet identifiant, un e-mail vient d'être envoyé avec les liens pour choisir de nouveaux mots de passe (valables 1 heure). Pensez à vérifier vos courriers indésirables.</p>
    <?php if (password_reset_is_local_host()): ?>
      <?php if ($recipients): ?>
        <p class="login-ok" role="status">En local, aucun e-mail ne part réellement : le message est recopié pour <?= h(implode(', ', $recipients)) ?> dans <code>data/mail-outbox.log</code>. <a href="/portail/journal-local.php?canal=mail">Ouvrir la boîte locale</a> pour récupérer les liens.</p>
      <?php else: ?>
        <p class="login-ok" role="status">En local, aucun message n'a été déposé : aucune adresse e-mail n'est rattachée à ces comptes (ou plusieurs demandes viennent d'être faites). Renseignez <code>admin_email</code> dans <code>tenants/&lt;identifiant&gt;/tenant.php</code> ou une adresse sur le compte.</p>
      <?php endif; ?>
    <?php endif; ?>
    <p class="login-links"><a href="/connexion.php">← Retour à la connexion</a></p>
<?php else: ?>
    <p class="lede">Indiquez votre identifiant, votre nom ou votre e-mail : recevez un lien par e-mail pour chaque espace que ce compte ouvre (boutique, galerie).</p>
    <?php if ($error): ?><p class="login-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?= login_forgot_form_html([
      'id' => 'unified-forgot',
      'identifier_name' => 'login',
      'hidden' => ['csrf' => ua_csrf_token()],
      'email_label' => 'Recevoir les liens par e-mail',
      'note' => "Le compte de l'exploitant du portail se réinitialise côté serveur (fichier .secrets/portail.json).",
    ]) ?>
    <p class="login-links"><a href="/connexion.php">← Retour à la connexion</a></p>
<?php endif; ?>
<?php password_reset_page('Mot de passe oublié', (string) ob_get_clean());
