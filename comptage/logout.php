<?php
require_once __DIR__ . '/../includes/comptage.php';
require_once __DIR__ . '/../includes/session.php';
cp_public_enter();   // galerie d'après l'adresse (/galerie/<galerie>/comptage/…), extension active
// Ne déconnecte que l'accès au comptage : une session admin éventuelle reste ouverte.
unset($_SESSION['cp_volunteer']);   // (accès bénévole de cette galerie)
header('Location: ' . cp_url_map('/comptage/login.php'));
exit;
