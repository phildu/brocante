<?php
require __DIR__ . '/_bootstrap.php';

unset($_SESSION['fv_member_id'], $_SESSION['fv_view']);
if (fv_real_manager()) {
    fv_redirect('index.php');
}
fv_redirect('connexion.php');
