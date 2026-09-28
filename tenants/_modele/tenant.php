<?php

// Modèle de commerce. Ne pas modifier directement : utiliser
//   scripts/new-tenant.sh <slug> "<Nom du commerce>"
// qui copie ce dossier dans tenants/<slug>/ puis l'adapte.
// Toute clé absente prend la valeur par défaut de includes/tenant.php.

return [
    'name' => 'Mon Commerce',
    'tagline' => 'Votre slogan',
    'site_url' => 'https://mon-commerce.example.com',
    // Ou variable d'environnement ADMIN_PASSWORD. Vide = administration fermée.
    'admin_password' => '',

    // Logos (chemins relatifs à la racine web) — déposer les fichiers dans assets/tenants/<slug>/.
    // 'logo' => 'assets/tenants/mon-commerce/logo.png',
    // 'logo_macaron' => 'assets/tenants/mon-commerce/logo.png',

    // Surcharges des couleurs de assets/style.css (clair puis sombre).
    'colors' => [
        'accent' => '#1e5f8c',
    ],
    'colors_dark' => [
        'accent' => '#6fa8d6',
    ],

    // Icônes disponibles (includes/icons.php) : ic-vase, ic-pitcher, ic-bowls, ic-stool,
    // ic-mirror, ic-plaid, ic-cushion, ic-photophore, ic-candle, ic-basket, ic-pendant, ic-pot.
    'categories' => [
        ['key' => 'categorie-a', 'label' => 'Catégorie A', 'icon' => 'ic-basket'],
        ['key' => 'categorie-b', 'label' => 'Catégorie B', 'icon' => 'ic-vase'],
    ],

    'texts' => [
        'categories_eyebrow' => 'Explorer par catégorie',
        'categories_title' => 'Nos catégories',
        'item_singular' => 'article',
        'item_plural' => 'articles',
        'follow_eyebrow' => 'En images',
        'follow_title' => 'Suivez-nous au quotidien',
        'newsletter_title' => 'Les nouveautés, avant tout le monde',
        'newsletter_text' => 'Un e-mail de temps en temps, quand il y a du nouveau. Pas plus.',
        'empty_category' => 'Aucun article dans cette catégorie pour le moment — repassez bientôt.',
    ],

    // Contexte pour l'IA (description des photos, fiches produit).
    'ai' => [
        'shop' => 'une boutique en ligne',
        'item' => 'un article',
        'examples' => '',
    ],
];
