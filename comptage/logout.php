<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Ne déconnecte que l'accès au comptage : une session admin éventuelle reste ouverte.
unset($_SESSION['cp_volunteer']);
header('Location: /comptage/login.php');
exit;
