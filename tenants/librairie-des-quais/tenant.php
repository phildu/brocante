<?php

// Configuration du commerce : Librairie des Quais (créée depuis le portail local).

return [
    'name' => 'Librairie des Quais',
    'tagline' => '',
    'site_url' => 'https://librairie-des-quais.example.com',
    'presence_mode' => 'online',
    'item_limit' => 0,
    'admin_user' => 'contact@librairie-des-quais.test',
    'sales_enabled' => true,
    'admin_password' => '$2y$12$/M5m0XA3dVUlqXfXfcrwEe8FtxIn./qrFGLAL.Eo.DqbnOlPpvTk2',
    'logo' => 'assets/tenants/librairie-des-quais/logo.svg',
    'logo_macaron' => 'assets/tenants/librairie-des-quais/logo.svg',
    'colors' => [
        'bg' => '#f7f3ea',
        'surface' => '#eae7df',
        'surface-2' => '#dddad4',
        'ink' => '#1d2630',
        'ink-soft' => '#707477',
        'accent' => '#7a1f2b',
        'accent-ink' => '#fffaf2',
        'accent-2' => '#2f5d50',
        'sage' => '#2f5d50',
        'line' => '#c1c0bc',
    ],
    'colors_dark' => [
        'bg' => '#13191f',
        'surface' => '#21262b',
        'surface-2' => '#2c3136',
        'ink' => '#f8f5ed',
        'ink-soft' => '#b3b3af',
        'accent' => '#a2626b',
        'accent-ink' => '#fffaf2',
        'accent-2' => '#78968d',
        'sage' => '#78968d',
        'line' => '#45494c',
    ],
    'template' => 'librairie',
    'fonts' => [
        'display' => 'Libre Baskerville',
        'body' => 'Source Serif 4',
    ],
    'categories' => [
        ['key' => 'nos-articles', 'label' => 'Nos articles', 'icon' => 'ic-vase'],
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
    'ai' => [
        'shop' => 'la boutique en ligne de Librairie des Quais',
        'item' => 'un article',
        'examples' => 'nos articles',
    ],
];
