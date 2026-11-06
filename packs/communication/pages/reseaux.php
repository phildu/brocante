<?php
// « API & Réseaux » (administrateurs) : la page de réglages d'electroboy80, portée dans le cadre commun, réduite à ce qui
// concerne la boutique (eb80-after.js) : Réseaux sociaux (applications et jetons Facebook, Instagram, Threads, TikTok,
// diagnostic, renouvellement), Sources éditeur et SEO des images. L'IA, la connexion sociale et les e-mails sont réglés par la
// plateforme (bandeau de liens) ; billetterie et recherche ne servent pas. Réglages rangés dans data/com/<boutique>.db.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/eb80-frame.php';
com_eb80_frame($plugin, $pluginCsrf, $role, 'rs-settings', 'API & Réseaux');
