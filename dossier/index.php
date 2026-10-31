<?php
// Page publique du dossier de subvention : formulaire prérempli, synthèse chiffrée, aperçu et archive ZIP (dossier/generer.php).
// Aucune donnée n'est enregistrée : les documents sont calculés à chaque envoi du formulaire.
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/form.php';

// Un renvoi depuis l'aperçu (« Modifier ») réaffiche le formulaire tel qu'il a été saisi.
$p = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ddoc_defaults();
$p['brand'] = saas_config()['name'];

ddoc_page_form($p, [], [], true);
