<?php

// Configuration du commerce : La "fripe"  a naty (créée depuis le portail local).

return [
    'name' => 'La "fripe"  a naty',
    'tagline' => 'couleur et tissus',
    'site_url' => 'https://naty.arrimage.com',
    'admin_user' => 'admin',
    'admin_password' => '$2y$12$LVOf4eGEsspM2J3eWb6Gm.GKziPvV5YOpl6V5thbGKNT5i6Lzs34G',
    'logo' => 'assets/tenants/la-fripe-a-naty/logo.svg',
    'logo_macaron' => 'assets/tenants/la-fripe-a-naty/logo.svg',
    'colors' => [
        'bg' => '#faf5ec',
        'surface' => '#eee9e0',
        'surface-2' => '#e3ded6',
        'line' => '#c8c3bb',
        'accent' => '#b0622b',
        'accent-2' => '#5b7a4a',
        'sage' => '#5b7a4a',
    ],
    'colors_dark' => [
        'accent' => '#cc9975',
        'accent-2' => '#94a989',
    ],
    'categories' => [
        ['key' => 'pains', 'label' => 'Pains', 'icon' => 'ic-basket'],
        ['key' => 'viennoiseries', 'label' => 'Viennoiseries', 'icon' => 'ic-bowls'],
        ['key' => 'patisseries', 'label' => 'Pâtisseries', 'icon' => 'ic-candle'],
        ['key' => 'epicerie-fine', 'label' => 'Épicerie fine', 'icon' => 'ic-pot'],
    ],
    'texts' => [
        'categories_eyebrow' => 'Explorer par catégorie',
        'categories_title' => 'fripe',
        'item_singular' => 'produit',
        'item_plural' => 'produits',
        'follow_eyebrow' => 'En images',
        'follow_title' => 'Suivez-nous au quotidien',
        'newsletter_title' => 'Les fournées spéciales, en avant-première',
        'newsletter_text' => 'Un e-mail par semaine avec les pains du week-end. Pas plus.',
        'empty_category' => 'Aucun produit dans cette catégorie pour le moment — repassez bientôt.',
    ],
    'ai' => [
        'shop' => 'la boutique en ligne de La "fripe"  a naty',
        'item' => 'un produit',
        'examples' => 'pains, viennoiseries, pâtisseries, épicerie fine',
    ],
];
