<?php
// Page d'atterrissage : gestionnaires de marché aux puces (placier, halle, brocanteur organisateur, commune).
require __DIR__ . '/../includes/landing.php';

lp_render([
    'type' => 'puces',
    'path' => '/marche-aux-puces/',
    'accent' => '#2f6b4f',
    'title' => 'Marché aux puces : plan, emplacements et marchands — Brocs',
    'description' => "Plan des allées, emplacements numérotés, tarifs par zone, page publique avec les marchands et leurs trouvailles. Sans cahier ni tableur.",
    'eyebrow' => 'Gestionnaires de marché aux puces',
    'h1' => 'Vos emplacements numérotés, vos tarifs par zone, vos marchands présentés.',
    'lede' => "Un placier connaît son marché par cœur. La plateforme le rend lisible : plan des allées, numéros attribués, prix selon la zone, et une page où chaque marchand montre ses trouvailles avant l'ouverture.",

    'audience_title' => 'Le marché change de mains, pas de logique',
    'audience_lede' => "Que vous teniez le registre depuis vingt ans ou que vous repreniez un marché existant, le plan et les tarifs doivent tenir dans un seul document partagé.",
    'audience' => [
        ['Placier · gestionnaire de halle', 'L\'attribution des places', "Vous connaissez chaque marchand, ses mètres et ses habitudes. La plateforme conserve cette mémoire et la rend consultable par toute l'équipe."],
        ['Brocanteur organisateur', 'Un marché qui complète la boutique', "Vous vendez en boutique et vous gérez un marché. Les deux vivent sur la même plateforme, avec le même paiement et le même annuaire."],
        ['Commune · régie', 'Le domaine public et ses tarifs', "La commune fixe les tarifs par zone et doit pouvoir justifier l'attribution. Le plan publié et l'historique des places répondent aux questions du conseil."],
        ['Association de marchands', 'Une caisse commune', "Les marchands cotisent pour l'emplacement, l'association tient les comptes. Chaque place et chaque tarif restent tracés, sans tableur partagé."],
    ],

    'pain_title' => 'Un marché se désorganise quand le plan vit dans une seule tête',
    'pains' => [
        ['Le cahier d\'emplacements', "Un cahier, un tableur et la mémoire du placier. Quand il est absent, personne ne sait si l'allée 3 est complète ni qui a payé."],
        ['« Il vous reste une place ? »', "La même question dix fois par semaine, au téléphone. Sans plan à jour, la réponse demande un appel à un collègue."],
        ['Les zones inégales', "Le coin près de l'entrée se vend cher, le fond du parking reste vide. Sans tarif par zone visible, la négociation se fait au cas par cas."],
        ['Les marchands de passage', "Un marchand s'installe, repart, revient trois semaines plus tard : sans fiche, son historique et ses mètres se perdent."],
        ['Un public qui ne sait pas', "Les visiteurs ne savent pas quels marchands sont là, ni ce qu'ils vendent cette semaine. La page Facebook du marché ne répond pas à « qui a des vinyles ? »."],
    ],

    'tools_title' => 'Le plan, les marchands et le public sur une seule page',
    'tools_lede' => "Le plan fixe les zones et les numéros ; les marchands s'y rattachent ; le public lit la même page. Ce qui est vrai au bureau est vrai sur le téléphone du visiteur.",
    'features' => [
        ['🗺️', 'Le plan et les numéros', "Tracez les allées et les halls, numérotez chaque emplacement. Le plan publié montre les places libres et les places attribuées."],
        ['💶', 'Les tarifs par zone', "Un prix par emplacement ou au mètre carré, différent selon l'allée. Les zones chères et les zones de fond deviennent explicites, pas négociées."],
        ['👤', 'Une fiche par marchand', "Chaque marchand a sa page : spécialité, pièces, contact. Les visiteurs savent qui trouver et où, avant même d'arriver."],
        ['🏪', 'Une boutique pour vendre entre deux marchés', "Le marchand qui le souhaite ouvre sa boutique en ligne : ses pièces restent visibles toute la semaine, pas seulement le dimanche."],
        ['🔔', 'Les habitués et la fidélité', "Les visiteurs s'abonnent aux nouveautés du marché, suivent leurs marchands préférés et reviennent aux dates annoncées."],
        ['🔄', 'La bourse d\'échanges', "Les chineurs déposent leurs recherches, les marchands proposent : la pièce rare trouve son acheteur avant l'ouverture des portes."],
    ],

    'steps_title' => 'Du plan papier au marché publié',
    'steps' => [
        ['Tracez les allées', "Partez d'une photo du site ou du plan vierge, délimitez les allées, les halls et le parking."],
        ['Numérotez et tarifez', "Chaque emplacement reçoit un numéro et un prix selon sa zone. Les tarifs restent modifiables en cours d'année."],
        ['Installez les marchands', "Attribuez les places aux marchands habituels, puis ouvrez les places restantes aux nouveaux."],
        ['Publiez et partagez', "La page du marché annonce la date, le plan et les marchands présents. Un lien suffit pour les réseaux et les affiches."],
    ],

    'plan_bullet' => "Tarifs par zone, fiches marchands et bourse d'échanges",
    'roi_title' => 'Ce que représente un marché de 60 marchands',
    'roi' => [
        ['60', "emplacements identifiés, numérotés et attribuables depuis un navigateur"],
        ['3', "zones tarifaires possibles, au lieu d'un prix unique négocié"],
        ['1', "page publique à partager, au lieu d'une affiche par allée"],
    ],
    'roi_text' => "Les recettes d'emplacements restent celles de la structure qui gère le marché : Brocs ne prélève aucune commission sur les places ni sur les ventes des marchands.",

    'live_title' => 'Des marchés aux puces déjà en ligne',
    'faq' => [
        ["Est-ce que la plateforme encaisse les emplacements ?", "Non. Le règlement des places reste votre affaire (régie, association, espèces ou chèque). La plateforme gère le plan, l'attribution et la page publique du marché."],
        ["Peut-on gérer plusieurs marchés avec un seul compte ?", "Oui : chaque marché a sa page, son plan et ses tarifs. L'offre est comptée par marché, pas par compte."],
        ["Un marchand peut-il s'inscrire seul ?", "Un marchand peut demander une place si vous ouvrez les demandes. Sinon, vous l'installez depuis l'administration : la page publique se met à jour."],
        ["Le marché est mensuel : faut-il tout refaire chaque mois ?", "Non. Le plan, les zones et les tarifs restent ; vous ne changez que les dates et les présences. Les habitués gardent leur place d'un marché à l'autre."],
        ["Un marchand sans boutique peut-il figurer sur la page ?", "Oui, il apparaît avec sa spécialité et ses horaires. S'il ouvre une boutique, ses pièces s'affichent et il peut vendre entre deux marchés."],
        ["Est-ce que ça remplace le registre du placier ?", "Il le complète : le plan et l'historique deviennent consultables par l'équipe, sans dépendre de la mémoire d'une seule personne."],
    ],

    'cta_title' => 'Mettez votre marché en ligne avant la prochaine édition',
    'cta_text' => "L'offre d'essai est gratuite : dessinez les allées, attribuez quelques places et montrez la page à vos marchands. Vous saurez en une heure si l'outil tient votre marché.",
]);
