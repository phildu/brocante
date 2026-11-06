<?php
// Buvette d'une galerie (extension « Buvette », réservée aux organisateurs de vide-greniers) : ouvre les pages du module (admin/buvette/*.php)
// dans l'espace de la galerie. L'accès (exploitant, ou administrateur de CETTE galerie) vient de _galerie-layout.php ; l'extension doit être
// active pour la galerie (gm_enabled) ; les données sont celles de la base SQL de la galerie (includes/gallery-sql.php), jamais d'une boutique.
// Les adresses du module sont réécrites vers cette page (bv_url_map()) : ?s=<section>[&g=<galerie>].
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/buvette-layout.php';

$optionsUrl = '/portail/galerie-modules.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
if (!gm_enabled($gaGallery, 'buvette')) {
    portail_flash(ext_t('{Ext} « Buvette » n’est pas {?actif|active} pour cette galerie : activez-{?le|la} depuis l’onglet {Exts}.'), 'error');
    header('Location: ' . $optionsUrl);
    exit;
}

// Section demandée -> fichier du module (admin/buvette/index.php pour le tableau de bord).
$files = [];
foreach (bv_admin_sections() as $key => [$href]) $files[$key] = basename($href);
$section = (string) ($_GET['s'] ?? 'dashboard');
if (!isset($files[$section])) $section = 'dashboard';

bv_enter_gallery($gaGallery, (bool) $limited);
require PORTAIL_ROOT . '/admin/buvette/' . $files[$section];
