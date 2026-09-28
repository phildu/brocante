<?php

// La Brocante du Petit Chalet — commerce d'origine de la boutique.
// Les chemins conservent l'emplacement historique (brocante.db, .secrets/,
// assets/logo.png) pour que le site en ligne continue de fonctionner tel quel.

return [
    'name' => 'La Brocante du Petit Chalet',
    'tagline' => 'Trouvailles chinées & pièces uniques',
    'site_url' => 'https://brocante.arrimage.com',
    // Compte de l'administration — mot de passe à changer avant toute mise en
    // ligne réelle (ou variables d'environnement ADMIN_USER / ADMIN_PASSWORD).
    'admin_user' => 'admin',
    'admin_password' => 'armoire2026',

    'db_file' => 'brocante.db',
    'secrets_dir' => '.secrets',
    'logo' => 'assets/logo.png',
    'logo_macaron' => 'assets/logo-macaron.png',
    'deploy_uploads' => true,

    // Couleurs et polices : celles de assets/style.css, rien à surcharger.
    'colors' => [],

    'categories' => [
        ['key' => 'ceramique', 'label' => 'Céramique', 'icon' => 'ic-vase'],
        ['key' => 'bois', 'label' => 'Bois & mobilier', 'icon' => 'ic-stool'],
        ['key' => 'textile', 'label' => 'Textile', 'icon' => 'ic-plaid'],
        ['key' => 'lumiere', 'label' => 'Lumière', 'icon' => 'ic-candle'],
        ['key' => 'jardin', 'label' => 'Jardin', 'icon' => 'ic-pot'],
        ['key' => 'curiosites', 'label' => 'Curiosités', 'icon' => 'ic-mirror'],
    ],

    'texts' => [
        'categories_eyebrow' => 'Explorer par univers',
        'categories_title' => 'Cinq univers, une même armoire',
        'item_singular' => 'pièce',
        'item_plural' => 'pièces',
        'follow_eyebrow' => 'Au fil des trouvailles',
        'follow_title' => "Suivez l'armoire au quotidien",
        'newsletter_title' => 'Les nouvelles trouvailles, avant tout le monde',
        'newsletter_text' => "Un e-mail par mois, quand une nouvelle tournée de brocante rentre à l'atelier. Pas plus.",
        'empty_category' => 'Aucune pièce dans cet univers pour le moment — repassez après notre prochaine tournée de brocante.',
    ],

    'ai' => [
        'shop' => 'une brocante en ligne',
        'item' => 'un objet chiné',
        'examples' => 'meubles, céramique, luminaires, textile, jardin, curiosités...',
    ],
];
