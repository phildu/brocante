<?php
require_once __DIR__ . '/../includes/buvette-layout.php';
require_once __DIR__ . '/../includes/session.php';
bv_boot();

if (bv_is_volunteer()) {
    header('Location: /buvette/index.php');
    exit;
}

$error = null;
if (bv_post_action() === 'login') {
    $error = bv_volunteer_login((string) ($_POST['code'] ?? ''));
    if ($error === null) {
        header('Location: /buvette/index.php');
        exit;
    }
}

bv_vol_header('Accès bénévoles', 'accueil', false);
?>
<div class="bvv-login" style="margin-top:0;padding:0;">
  <div class="box">
    <p class="eyebrow">Buvette</p>
    <p style="margin:6px 0 0;">Entrez le code que vous a donné le responsable pour voir la carte, les recettes, le planning et la check-list.</p>
    <?php if ($error): ?><p class="publish-status" data-kind="error" role="alert" style="margin:14px 0 0;"><?= h($error) ?></p><?php endif; ?>
    <?= bv_form_open('login') ?>
      <input type="text" name="code" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="Code" required autofocus aria-label="Code d'accès">
      <button class="btn btn-primary" type="submit">Entrer</button>
    </form>
  </div>
</div>
<?php bv_vol_footer();
