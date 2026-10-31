<?php
// Les comptes « photographe » se créent dans l'administration (Comptes → rôle
// Photographe) : un ancien lien d'invitation mène simplement à l'application de
// shooting, qui demande la connexion si besoin.
require_once __DIR__ . '/../includes/session.php';

header('Location: /admin/shop-shooting.php');
exit;
