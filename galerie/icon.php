<?php
// Icône des applications d'une galerie : ?g=<galerie>&app=gallery_admin|gallery_visitor|gallery_caisse&s=<taille>.
// Le logo de la galerie (zone sûre des icônes « maskable ») sur sa couleur principale, ou un pictogramme de base : réglage de la galerie
// (Portail → Galeries → Noms et icônes des applications). Sans « app », le logo, comme avant. Le dessin est dans includes/app-icons.php.
require_once __DIR__ . '/../includes/galleries.php';
require_once __DIR__ . '/../includes/apps.php';

$slug = strtolower((string) ($_GET['g'] ?? ''));
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
if (!$gallery) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Galerie introuvable.');
}
$app = (string) ($_GET['app'] ?? '');
$known = in_array($app, ['gallery_admin', 'gallery_visitor', 'gallery_caisse'], true);
$mode = $known ? app_icon_request_mode($app, gallery_app_icon_mode($gallery, $app)) : 'logo';
app_icon_send(app_icon_render($known ? $app : 'gallery_admin', app_icon_request_size(), $mode, gallery_icon_style($gallery)));
