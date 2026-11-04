<?php
// « API & réseaux » : une seule page pour les réglages techniques de la plateforme, en quatre sections
//   social : connexion sociale (clés OAuth)   cles : clés API partagées de la période de test   ovh : API OVH (domaines)
//   notion : publication des retours dans la base « Suivi des jalons »
// Chaque section vit dans portail/_api-<section>.php (traitement du formulaire puis contenu).
require __DIR__ . '/_bootstrap.php';

const API_TABS = ['social' => 'Connexion sociale', 'cles' => 'Clés API partagées', 'ovh' => 'API OVH (domaines)', 'notion' => 'Retours → Notion'];
$tab = (string) ($_GET['s'] ?? 'social');
if (!isset(API_TABS[$tab])) $tab = 'social';

ob_start();
require __DIR__ . '/_api-' . $tab . '.php';
$content = (string) ob_get_clean();
$flash = portail_flash();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>API &amp; réseaux — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
</head>
<body>
<div class="app">
<?php
$apiTabs = [];
foreach (API_TABS as $k => $label) {
    $apiTabs[] = ['href' => '/portail/api.php?s=' . rawurlencode($k), 'label' => $label, 'active' => $k === $tab];
}
portail_page_start([
    'title' => 'API & réseaux',
    'eyebrow' => 'Plateforme',
    'desc' => 'Connexion sociale, clés partagées de la période de test, domaines OVH.',
    'tabs' => $apiTabs,
]);
?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <?= $content ?>
</div>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
