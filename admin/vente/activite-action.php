<?php
// Force de vente de la boutique (extension packs/force-de-vente) : même moteur que portail/vente/, carnet propre à la boutique.
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_admin(); // réservée aux administrateurs
define('FV_SCOPE_BOUTIQUE', true);
require __DIR__ . '/../../portail/vente/activite-action.php';
