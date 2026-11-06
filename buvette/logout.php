<?php
require_once __DIR__ . '/../includes/buvette-layout.php';
require_once __DIR__ . '/../includes/session.php';
bv_public_enter();
// Ne déconnecte que l'accès bénévole : une session admin éventuelle reste ouverte.
unset($_SESSION['bv_volunteer'], $_SESSION['bv_me']);
header('Location: ' . bv_url_map('/buvette/login.php'));
exit;
