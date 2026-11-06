<?php

return array (
  'name' => 'L\'Encre du Temps',
  'tagline' => '',
  'site_url' => 'https://mes-livres.example.com',
  'admin_user' => 'fildeschamps@gmail.com',
  'admin_password' => '$2y$12$6eg00x7nWLZUeZFe/KJQvugCqCRMm1sj4JZTPVq/iUF.Y21BjcxiW',
  'sales_enabled' => false,
  'db_file' => 'data/mes-livres.db',
  'secrets_dir' => '.secrets/mes-livres',
  'seed_file' => 'tenants/mes-livres/seed-data.json',
  'logo' => 'assets/tenants/mes-livres/logo-genere-17dbc19f.png',
  'logo_macaron' => 'assets/tenants/mes-livres/logo-genere-17dbc19f.png',
  'colors' => 
  array (
    'bg' => '#f4eee1',
    'surface' => '#e8e2d5',
    'surface-2' => '#dcd6c9',
    'ink' => '#2e2418',
    'ink-soft' => '#797164',
    'accent' => '#b5502e',
    'accent-ink' => '#fffaf2',
    'accent-2' => '#46647a',
    'sage' => '#46647a',
    'line' => '#c3bcaf',
  ),
  'colors_dark' => 
  array (
    'bg' => '#1e1710',
    'surface' => '#2b241d',
    'surface-2' => '#362f28',
    'ink' => '#f6f1e6',
    'ink-soft' => '#b5b0a6',
    'accent' => '#cb856d',
    'accent-ink' => '#fffaf2',
    'accent-2' => '#879aa9',
    'sage' => '#879aa9',
    'line' => '#4e473f',
  ),
  'fonts_url' => 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap',
  'categories' => 
  array (
    0 => 
    array (
      'key' => 'nos-articles',
      'label' => 'Nos articles',
      'icon' => 'ic-vase',
    ),
  ),
  'texts' => 
  array (
    'categories_eyebrow' => 'Explorer par catégorie',
    'categories_title' => 'Nos catégories',
    'item_singular' => 'article',
    'item_plural' => 'articles',
    'follow_eyebrow' => 'En images',
    'follow_title' => 'Suivez-nous au quotidien',
    'newsletter_title' => 'Les nouveautés, avant tout le monde',
    'newsletter_text' => 'Un e-mail de temps en temps, quand il y a du nouveau. Pas plus.',
    'empty_category' => 'Aucun article dans cette catégorie pour le moment — repassez bientôt.',
  ),
  'ai' => 
  array (
    'shop' => 'la boutique en ligne de mes livres (livres anciens)',
    'item' => 'un article',
    'examples' => 'livres anciens',
  ),
  'deploy_uploads' => false,
  'item_limit' => 0,
  'template' => 'brocante',
  'fonts' => 
  array (
    'display' => 'Fraunces',
    'body' => 'Archivo',
  ),
  'slug' => 'mes-livres',
);
