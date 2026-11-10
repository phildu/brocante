<?php
// Force de vente de la boutique (extension packs/force-de-vente) : audit des sites et réseaux du carnet de la boutique.
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_admin(); // réservée aux administrateurs
define('FV_SCOPE_BOUTIQUE', true);
require __DIR__ . '/../../portail/vente/audit-prospects.php';
