<?php
// Les comptes « photographe » se créent dans l'administration (Comptes → rôle
// Photographe) : un ancien lien de shooting partagé mène simplement à la connexion.
require_once __DIR__ . '/../includes/session.php';

header('Location: /admin/login.php');
exit;
