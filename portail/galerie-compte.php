<?php
// Compte de l'administrateur de galerie : changement de mot de passe (obligatoire après un mot de passe provisoire).
require __DIR__ . '/_galerie-layout.php';

$me = ga_current();
if (!$limited || !$me) {
    portail_flash("Cette page est celle d'un administrateur de galerie : connectez-vous sur /galerie/admin.php.", 'error');
    header('Location: /portail/galeries.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $account = ga_admin_find($gaGallery, $me['email']);
    $new = (string) ($_POST['new'] ?? '');
    if (!$account || !password_verify((string) ($_POST['current'] ?? ''), $account['hash'])) {
        portail_flash('Mot de passe actuel incorrect.', 'error');
    } elseif ($new !== (string) ($_POST['confirm'] ?? '')) {
        portail_flash('Les deux nouveaux mots de passe ne sont pas identiques.', 'error');
    } else {
        $err = null;
        foreach ($me['slugs'] as $s) $err = $err ?? ga_admin_set_password($s, $me['email'], $new);   // même adresse = même mot de passe pour toutes ses galeries
        portail_flash($err ?? 'Mot de passe modifié.', $err ? 'error' : 'ok');
        if (!$err) { header('Location: /portail/galerie-tableau.php'); exit; }
    }
    header('Location: /portail/galerie-compte.php');
    exit;
}
ga_head('Mon compte');
?>
    <form class="gcard" method="post" autocomplete="off">
      <h2>Mot de passe</h2>
      <?php if ($me['must_change']): ?><p class="flash" data-kind="error">Votre mot de passe est provisoire : choisissez-en un nouveau pour continuer.</p><?php endif; ?>
      <p class="meta">Connecté·e avec <strong><?= e($me['email']) ?></strong>.</p>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="f">Mot de passe actuel<input type="password" name="current" autocomplete="current-password" required></label>
      <label class="f">Nouveau mot de passe (10 caractères au moins)<input type="password" name="new" autocomplete="new-password" minlength="10" required></label>
      <label class="f">Confirmer le nouveau mot de passe<input type="password" name="confirm" autocomplete="new-password" minlength="10" required></label>
      <p><button class="btn btn-primary" type="submit">Changer le mot de passe</button></p>
    </form>
<?php ga_foot();
