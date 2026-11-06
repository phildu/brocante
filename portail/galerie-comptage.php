<?php
// Comptage de foule d'une galerie (extension « Comptage de foule », réservée aux organisateurs de vide-greniers) : ouvre les pages du module
// (admin/comptage/*.php) dans l'espace de la galerie. L'accès (exploitant, ou administrateur de CETTE galerie) vient de _galerie-layout.php ;
// l'extension doit être active pour la galerie (gm_enabled) ; les données sont celles de la base SQL de la galerie (includes/gallery-sql.php),
// jamais d'une boutique. Les adresses du module sont réécrites vers cette page (cp_url_map()) : ?s=<section>[&g=<galerie>].
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/comptage-layout.php';

$optionsUrl = '/portail/galerie-modules.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
if (!gm_enabled($gaGallery, 'comptage')) {
    portail_flash(ext_t('{Ext} « Comptage de foule » n’est pas {?actif|active} pour cette galerie : activez-{?le|la} depuis l’onglet {Exts}.'), 'error');
    header('Location: ' . $optionsUrl);
    exit;
}

// Section demandée -> fichier du module (admin/comptage/index.php pour les résultats).
$files = ['resultats' => 'index.php', 'evenements' => 'evenements.php', 'acces' => 'acces.php', 'export' => 'export.php'];
$section = (string) ($_GET['s'] ?? 'resultats');
if (!isset($files[$section])) $section = 'resultats';

cp_enter_gallery($gaGallery, (bool) $limited);
require PORTAIL_ROOT . '/admin/comptage/' . $files[$section];
