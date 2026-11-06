<?php
// Applications d'une galerie : codes QR et liens pour installer l'administration et l'espace visiteur sur un téléphone.
// L'espace exposant n'a pas de code commun : chaque exposant reçoit son lien personnel par e-mail (galerie/exposant.php).
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-loyalty.php';
require_once PORTAIL_ROOT . '/includes/app-install-card.php';

$adminName = gallery_app_name($gaGallery, 'gallery_admin');
$visitorName = gallery_app_name($gaGallery, 'gallery_visitor');

ga_head('Applications');
?>
<p class="meta">Scannez un code avec l'appareil photo du téléphone, connectez-vous si besoin, puis « Ajouter à l'écran d'accueil ». Le nom et l'icône de chaque application (icône de base ou logo de la galerie) se règlent dans <a href="/portail/galeries.php<?= $limited ? '' : '?g=' . urlencode($gaSlug) ?>#apps-galerie">Présentation et commerces → Noms et icônes des applications</a>.</p>
<?php
app_install_card([
    'title' => $adminName,
    'icon' => gallery_app_icon_url($gaGallery, 'gallery_admin', 120),
    'desc' => "L'administration de la galerie sur téléphone : commerces, exposants, plan et événement. Réservée aux administrateurs (connexion par e-mail et mot de passe).",
    'url' => ga_public_url(ga_login_url($gaSlug)),
]);

if (gt_feature($gaGallery, 'fidelite')) {
    $set = gl_settings($gaGallery);
    app_install_card([
        'title' => $visitorName,
        'icon' => gallery_app_icon_url($gaGallery, 'gallery_visitor', 120),
        'desc' => "L'espace des visiteurs : points de fidélité, récompenses, alertes chineur et scan des QR codes des lieux. À afficher à l'entrée et sur les stands.",
        'url' => gl_app_url($gaSlug),
        'disabled' => !empty($set['enabled']) ? '' : "L'espace visiteur est en veille : activez-le dans Fidélité visiteurs avant de partager ce code.",
        'note' => 'Les QR codes des lieux de scan (un par commerce ou stand) s\'impriment depuis Fidélité visiteurs.',
    ]);
}
if (gm_enabled($gaGallery, 'buvette')) {
    app_install_card([
        'title' => gallery_app_name($gaGallery, 'gallery_caisse'),
        'icon' => gallery_app_icon_url($gaGallery, 'gallery_caisse', 120),
        'desc' => "La caisse de la buvette : encaisser, gratuités des bénévoles, mode dégradé sans réseau. Accès par le code bénévole.",
        'url' => ga_public_url('/galerie/' . $gaSlug . '/buvette/caisse.php'),
    ]);
}
?>
<section class="appcard" style="grid-template-columns:1fr">
  <div>
    <h2>Espace exposant</h2>
    <p>Pas de code commun : chaque exposant reçoit son lien personnel par e-mail après sa demande d'emplacement. Il y trouve son pass de confirmation (QR code), le plan et l'itinéraire.</p>
  </div>
</section>
<?php
app_install_cards_script();
ga_foot();
