<?php

require_once __DIR__ . '/includes/functions.php';

$locate = shop_locate_info();
$activeNav = 'nous-trouver';
$pageTitle = 'Nous trouver';
$ogDescription = $locate['address_full'] !== ''
    ? 'Adresse, carte IGN et itinéraire : ' . $locate['address_full']
    : 'Adresse, carte et itinéraire pour rejoindre la boutique.';
$ogUrl = $locate['page_url'];
$ogImage = logo_url('square');

include __DIR__ . '/includes/header.php';
?>

<?= shop_locate_section_html() ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
