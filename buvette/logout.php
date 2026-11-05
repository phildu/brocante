<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Ne déconnecte que l'accès bénévole : une session admin éventuelle reste ouverte.
unset($_SESSION['bv_volunteer'], $_SESSION['bv_me']);
header('Location: /buvette/login.php');
exit;
