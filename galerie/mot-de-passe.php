<?php
// Mot de passe oublié d'un administrateur de galerie : demande du lien par e-mail, puis choix du nouveau mot de passe.
session_start();
require_once __DIR__ . '/../includes/gallery-admin.php';

function ga_e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['ga_csrf'])) $_SESSION['ga_csrf'] = bin2hex(random_bytes(16));
$email = (string) ($_GET['e'] ?? $_POST['e'] ?? '');
$exp = (int) ($_GET['x'] ?? $_POST['x'] ?? 0);
$sig = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$resetMode = $sig !== '';
$error = null;
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['ga_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'La page avait expiré : réessayez.';
    } elseif ($resetMode) {
        $error = ga_reset_apply($email, $exp, $sig, (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
        if ($error === null) {
            $_SESSION['ga_notice'] = 'Mot de passe modifié : connectez-vous avec le nouveau.';
            header('Location: /galerie/admin.php');
            exit;
        }
    } else {
        ga_reset_request((string) ($_POST['email'] ?? ''));
        $sent = true;
    }
}
$linkOk = $resetMode && ga_reset_valid($email, $exp, $sig);
http_response_code($error ? 400 : 200);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Administration de galerie — Mot de passe oublié</title>
<style>
  body { font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f0e8; color: #221f1a; margin: 0; }
  main { max-width: 380px; margin: 12vh auto 0; padding: 0 18px; }
  form, .box { background: #fff; border: 1px solid #ddd5c6; border-radius: 10px; padding: 22px; display: grid; gap: 12px; }
  h1 { font-size: 1.3rem; margin: 0; }
  label { display: grid; gap: 4px; font-size: .85rem; font-weight: 600; }
  input { padding: 10px; border: 1px solid #cfc6b4; border-radius: 8px; font: inherit; }
  button { padding: 11px; border: 0; border-radius: 8px; background: #221f1a; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
  .err { color: #a4361b; margin: 0; } .ok { color: #1e6b3a; margin: 0; } small, a { color: #6b6457; }
</style>
</head>
<body>
<main>
<?php if ($resetMode && !$linkOk && !$error): ?>
  <div class="box"><h1>Lien non valable</h1><p class="err">Ce lien a expiré ou a déjà servi (valable 1 heure, à usage unique).</p><a href="/galerie/mot-de-passe.php">Redemander un lien</a></div>
<?php elseif ($resetMode): ?>
  <form method="post">
    <h1>Nouveau mot de passe</h1>
    <?php if ($error): ?><p class="err" role="alert"><?= ga_e($error) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= ga_e($_SESSION['ga_csrf']) ?>">
    <input type="hidden" name="e" value="<?= ga_e($email) ?>"><input type="hidden" name="x" value="<?= (int) $exp ?>"><input type="hidden" name="t" value="<?= ga_e($sig) ?>">
    <label>Nouveau mot de passe<input type="password" name="password" autocomplete="new-password" minlength="10" required autofocus></label>
    <label>Confirmer<input type="password" name="confirm" autocomplete="new-password" minlength="10" required></label>
    <small>10 caractères au moins.</small>
    <button type="submit">Enregistrer</button>
  </form>
<?php elseif ($sent): ?>
  <div class="box"><h1>Mot de passe oublié</h1>
    <p class="ok" role="status">Si cette adresse administre une galerie, un e-mail avec un lien (valable 1 heure) vient de lui être envoyé. Pensez à vérifier vos courriers indésirables.</p>
    <a href="/galerie/admin.php">← Retour à la connexion</a></div>
<?php else: ?>
  <form method="post">
    <h1>Mot de passe oublié</h1>
    <?php if ($error): ?><p class="err" role="alert"><?= ga_e($error) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= ga_e($_SESSION['ga_csrf']) ?>">
    <label>Adresse e-mail de votre compte<input type="email" name="email" autocomplete="username" required autofocus></label>
    <button type="submit">Envoyer le lien</button>
    <small><a href="/galerie/admin.php">← Retour à la connexion</a></small>
  </form>
<?php endif; ?>
</main>
</body>
</html>
