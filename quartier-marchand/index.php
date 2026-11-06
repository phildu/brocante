<?php
// Page d'atterrissage : quartier marchand (commune, union de commerçants, association de quartier ou de village).
require __DIR__ . '/../includes/landing.php';

lp_render([
    'type' => 'quartier',
    'path' => '/quartier-marchand/',
    'accent' => '#3b5fa8',
    'title' => 'Quartier marchand : réunir les commerces d\'une rue — Brocs',
    'description' => "Une page pour la rue ou le village : annuaire des commerces, plan des boutiques, promotions, lettre d'information et commerces à recruter.",
    'eyebrow' => 'Communes · unions de commerçants',
    'h1' => 'La rue, le quartier ou le village : une adresse qui rassemble ses commerces.',
    'lede' => "Les habitants cherchent « qui livre », « qui répare », « qui est ouvert le lundi ». Une page commune répond à leur place, présente chaque commerce et donne à la collectivité un outil pour animer — et pour recruter les boutiques manquantes.",

    'audience_title' => 'Un projet de territoire, pas un site de plus',
    'audience_lede' => "Le quartier marchand s'adresse à ceux qui portent une politique commerçante : la commune, l'union des commerçants, l'association de quartier. La page est un support d'animation, pas une vitrine institutionnelle.",
    'audience' => [
        ['Commune · communauté de communes', 'Une politique commerçante visible', "La collectivité finance et anime. Elle doit pouvoir montrer aux habitants et aux élus ce que la démarche produit : commerces présents, boutiques manquantes, fréquentation."],
        ['Union de commerçants', 'Les adhérents réunis', "L'union fédère les boutiques de la rue. La page remplace le dépliant annuel et se met à jour quand un commerce ouvre ou ferme."],
        ['Association de quartier', 'Le lien avec les habitants', "L'association anime le quartier. La page donne un annuaire vivant et un canal pour les animations, les marchés et les promotions."],
        ['Village · bourg-centre', 'Garder les commerces de proximité', "Un village de 1 500 habitants a dix commerces et doit en attirer un onzième. Le plan et les fiches montrent ce qui existe et ce qui manque."],
    ],

    'pain_title' => 'Ce qui manque aux commerces, c\'est une visibilité commune',
    'pains' => [
        ['Un annuaire papier déjà périmé', "Le dépliant est imprimé en mars ; deux commerces ont fermé en juin. La version en ligne doit être la seule source à jour."],
        ['Des boutiques invisibles en ligne', "Beaucoup de commerces n'ont ni site ni fiche à jour. Le client cherche sur son téléphone et ne trouve que les grandes enseignes."],
        ['Des animations sans relais', "La braderie, le marché de Noël ou la Fête de la musique sont annoncés par affiche. Sans page commune, chaque commerce relaie — ou pas."],
        ['Aucune vision des manques', "La collectivité sait qu'il manque une boulangerie, mais pas combien de locaux sont vides ni quels services les habitants réclament."],
    ],

    'tools_title' => 'Un annuaire vivant et un outil d\'animation pour la collectivité',
    'tools_lede' => "La page se remplit avec les commerces du territoire et se maintient dans le temps. Les outils d'animation sont ceux de la galerie, adaptés à une rue ou à un village.",
    'features' => [
        ['📇', 'L\'annuaire du quartier', "Chaque commerce a sa fiche : spécialité, horaires, contact, pièces en ligne s'il a une boutique. Les habitants trouvent ce qui existe près de chez eux."],
        ['🗺️', 'Le plan des boutiques', "Les commerces placés sur le plan de la rue, du quartier ou du village. Les locaux vides peuvent y figurer pour attirer des repreneurs."],
        ['📣', 'Les promotions communes', "Une braderie, un marché de Noël, une opération « vendredi local » : l'annonce part d'un seul endroit vers tous les abonnés."],
        ['📬', 'La lettre d\'information', "Les habitants s'abonnent aux nouveautés du quartier. La collectivité dispose d'un canal direct, sans dépendre des réseaux sociaux."],
        ['🎯', 'Les commerces à recruter', "La liste des activités manquantes (boulangerie, cordonnerie, primeur) se partage avec les candidats à l'installation, chiffres en main."],
        ['📊', 'Le tableau de bord', "Nombre de commerces, fiches complètes, abonnés, pièces en ligne : la collectivité mesure ce que la démarche produit, trimestre après trimestre."],
    ],

    'steps_title' => 'De la liste des commerces à la page du quartier',
    'steps' => [
        ['Cadrez le projet', "Définissez le périmètre : une rue, un quartier, un village. Décidez qui anime la page et qui valide les fiches."],
        ['Recensez les commerces', "Listez les boutiques du périmètre, leurs spécialités et leurs horaires. Chaque commerce rejoint la page à son rythme."],
        ['Publiez le plan et les fiches', "Placez les commerces sur le plan et complétez les fiches. La page est consultable sans compte, sur téléphone."],
        ['Animez et mesurez', "Promotions, lettre d'information, commerces à recruter : la page devient le point de rendez-vous du quartier."],
    ],

    'plan_bullet' => "Fiches commerces, promotions communes et lettre d'information",
    'roi_title' => 'Quinze commerces, une seule page à animer',
    'roi' => [
        ['15', "commerces présentés, avec leurs horaires et leurs spécialités"],
        ['0', "dépliant à réimprimer quand une boutique ferme"],
        ['1', "canal pour les animations, les promos et les commerces à recruter"],
    ],
    'roi_text' => "L'abonnement est porté par la structure qui anime le quartier, pas par chaque commerce. Pour une commune ou une association, la mise en place peut entrer dans un dossier de subvention : le générateur de dossier prépare les pièces à partir de vos chiffres.",

    'live_title' => 'Des quartiers marchands déjà en ligne',
    'faq' => [
        ["Faut-il que chaque commerce ouvre une boutique ?", "Non. Le quartier présente les commerces avec leurs fiches ; la boutique en ligne est une option que chaque commerce choisit librement."],
        ["Qui porte l'abonnement ?", "La commune, l'union de commerçants ou l'association qui anime la page. Les commerces n'ont rien à payer pour figurer dans l'annuaire."],
        ["Une commune peut-elle financer la mise en place par une subvention ?", "Oui. Le dossier de subvention se prépare directement en ligne : lettre, note de cadrage, budget et calendrier se remplissent avec vos chiffres. Voir la page Dossier de subvention."],
        ["Comment recruter les commerces manquants ?", "La page liste les activités absentes et les locaux disponibles. Elle sert de support de discussion avec les candidats à l'installation et les agences immobilières."],
        ["Les habitants ont-ils besoin d'un compte ?", "Non. La page, le plan et les fiches se consultent librement. Un compte n'est demandé que pour s'abonner aux nouveautés ou acheter en ligne."],
        ["Peut-on réserver le nom de domaine du quartier ?", "Oui, la page peut être servie sur son propre domaine, à la racine. C'est un réglage du portail, sans nouvelle page à créer."],
    ],

    'cta_title' => 'Ouvrez la page du quartier avec ses premiers commerces',
    'cta_text' => "L'essai est gratuit. Recensez cinq commerces, publiez le plan, et montrez la page au prochain conseil ou à la prochaine assemblée générale.",
]);
