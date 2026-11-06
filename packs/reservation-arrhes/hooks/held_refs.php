<?php
// Pièces retenues par des réservations : indisponibles pour les autres visiteurs.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ra.php';
return addon_is_entitled(RA_MODULE) ? ra_held() : [];
