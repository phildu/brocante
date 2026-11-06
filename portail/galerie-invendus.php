<?php
// Invendus & dons d'une galerie (extension « Invendus & dons », réservée aux organisateurs de vide-greniers) : ouvre les pages du module
// (admin/invendus*.php) dans l'espace de la galerie. L'accès (exploitant, ou administrateur de CETTE galerie) vient de _galerie-layout.php ;
// l'extension doit être active pour la galerie (gm_enabled) ; les données sont celles de la base SQL de la galerie (includes/gallery-sql.php),
// jamais d'une boutique. Les adresses du module sont réécrites vers cette page (unsold_url_map()) : ?s=<page>[&g=<galerie>].
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/invendus-layout.php';

$optionsUrl = '/portail/galerie-modules.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
if (!gm_enabled($gaGallery, 'invendus')) {
    portail_flash(ext_t('{Ext} « Invendus & dons » n’est pas {?actif|active} pour cette galerie : activez-{?le|la} depuis l’onglet {Exts}.'), 'error');
    header('Location: ' . $optionsUrl);
    exit;
}

// Page demandée -> fichier du module (admin/invendus.php pour l'accueil).
$pages = [
    'invendus' => 'invendus.php', 'invendus-event' => 'invendus-event.php', 'invendus-offer' => 'invendus-offer.php',
    'invendus-structures' => 'invendus-structures.php', 'invendus-bordereau' => 'invendus-bordereau.php',
    'invendus-export' => 'invendus-export.php', 'invendus-action' => 'invendus-action.php',
];
$section = (string) ($_GET['s'] ?? 'invendus');
if (!isset($pages[$section])) $section = 'invendus';

unsold_enter_gallery($gaGallery, (bool) $limited);
require PORTAIL_ROOT . '/admin/' . $pages[$section];
