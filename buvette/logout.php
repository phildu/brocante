<?php
require_once __DIR__ . '/../includes/session.php';
// Ne déconnecte que l'accès bénévole : une session admin éventuelle reste ouverte.
unset($_SESSION['bv_volunteer'], $_SESSION['bv_me']);
header('Location: /buvette/login.php');
exit;
