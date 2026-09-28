<?php

// Exemple de second commerce, pour montrer la transposition de la boutique.

return [
    'name' => 'Librairie des Quais',
    'tagline' => "Livres anciens & d'occasion",
    'site_url' => 'https://librairie-des-quais.example.com',
    // Ou variable d'environnement ADMIN_PASSWORD. Vide = administration fermée.
    'admin_password' => '',

    'logo' => 'assets/tenants/exemple-librairie/logo.svg',
    'logo_macaron' => 'assets/tenants/exemple-librairie/logo.svg',

    'colors' => [
        'bg' => '#f3f1ea',
        'surface' => '#e6e3d6',
        'surface-2' => '#d9d5c2',
        'accent' => '#2f6b4f',
        'accent-2' => '#7a4a2a',
        'line' => '#bdb79f',
    ],
    'colors_dark' => [
        'accent' => '#7cc3a0',
        'accent-2' => '#d39a72',
    ],

    'categories' => [
        ['key' => 'romans', 'label' => 'Romans', 'icon' => 'ic-cushion'],
        ['key' => 'beaux-livres', 'label' => 'Beaux livres', 'icon' => 'ic-mirror'],
        ['key' => 'jeunesse', 'label' => 'Jeunesse', 'icon' => 'ic-basket'],
        ['key' => 'anciens', 'label' => 'Livres anciens', 'icon' => 'ic-candle'],
    ],

    'texts' => [
        'categories_eyebrow' => 'Explorer par rayon',
        'categories_title' => 'Quatre rayons, mille histoires',
        'item_singular' => 'livre',
        'item_plural' => 'livres',
        'follow_eyebrow' => 'Sur les étagères',
        'follow_title' => 'Les derniers arrivages',
        'newsletter_title' => 'Les nouveaux arrivages, avant tout le monde',
        'newsletter_text' => 'Un e-mail par mois avec nos plus belles trouvailles. Pas plus.',
        'empty_category' => 'Aucun livre dans ce rayon pour le moment — repassez bientôt.',
    ],

    'ai' => [
        'shop' => 'une librairie de livres anciens et d\'occasion en ligne',
        'item' => 'un livre d\'occasion',
        'examples' => 'romans, beaux livres, jeunesse, livres anciens',
    ],
];
