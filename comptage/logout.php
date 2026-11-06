<?php
require_once __DIR__ . '/../includes/session.php';
// Ne déconnecte que l'accès au comptage : une session admin éventuelle reste ouverte.
unset($_SESSION['cp_volunteer']);
header('Location: /comptage/login.php');
exit;
