<?php
// Page d'atterrissage : organisateurs de marché des producteurs et créateurs (association, commune, comité des fêtes, halle).
require __DIR__ . '/../includes/landing.php';

lp_render([
    'type' => 'marche-producteurs',
    'path' => '/marche-producteurs/',
    'accent' => '#7a6a2f',
    'title' => 'Marché des producteurs et créateurs : stands et plan — Brocs',
    'description' => "Producteurs locaux, artisans et créateurs réunis : plan des stands, tarifs par zone, réservations en ligne et page publique. Sans cahier ni tableur.",
    'eyebrow' => 'Organisateurs de marché de producteurs',
    'h1' => 'Les producteurs et créateurs du territoire, réunis sur une même place.',
    'lede' => "Un marché de proximité se juge à la régularité : les mêmes habitués retrouvent les mêmes stands. La plateforme rend le plan lisible, les places réservables et les exposants visibles, semaine après semaine.",

    'audience_title' => 'Quatre façons de faire vivre les producteurs et les créateurs',
    'audience_lede' => "Qu'il soit hebdomadaire, mensuel ou saisonnier, le marché repose sur une organisation simple que tout le monde peut consulter.",
    'audience' => [
        ['Association de producteurs', 'Une place et des règles communes', "Les producteurs se répartissent les stands et les tours de permanence. Le plan et les tarifs doivent rester visibles pour éviter les arrangements informels."],
        ['Commune · régie', 'Le domaine public et ses tarifs', "La commune fixe les emplacements, encaisse les droits de place et doit pouvoir justifier l'attribution. Le plan publié et l'historique répondent aux questions du conseil."],
        ['Comité des fêtes · association', 'Un marché qui anime le village', "Un marché de producteurs le dimanche matin, quelques créateurs l'été : il faut recruter les stands, remplir les places libres et annoncer la date."],
        ['Halle · place commerciale', 'Un lieu à faire vivre toute l\'année', "La halle accueille producteurs et créateurs selon les jours. Les exposants réservent, le public sait qui vient, et les places vides se proposent aux nouveaux."],
    ],

    'pain_title' => 'Un marché de proximité se vide quand le recrutement des stands devient un casse-tête',
    'pains' => [
        ['Le cahier des stands', "Un cahier, un tableur et la mémoire de deux bénévoles. Quand l'un s'absente, personne ne sait qui vient samedi ni quelle place reste libre."],
        ['« Il reste une place ? »', "La même question dix fois par semaine, par téléphone ou sur le groupe de messagerie. Sans plan à jour, la réponse demande un détour par un collègue."],
        ['Les places inégales', "Le coin près de l'entrée se remplit, le fond de la place reste vide. Sans tarif par zone visible, la négociation se fait au cas par cas."],
        ['Les créateurs invisibles', "Le public connaît les producteurs, moins les artisans et créateurs présents. Sans page publique, chaque stand doit se faire connaître seul."],
        ['Les habitués qui oublient', "Le marché change de jour ou reprend après l'hiver : sans liste d'abonnés, les visiteurs ne savent pas quand il rouvre."],
    ],

    'tools_title' => 'Le plan, les stands et le public sur une seule page',
    'tools_lede' => "Le plan fixe les zones et les numéros ; les exposants s'y rattachent ; le public lit la même page. Ce qui est vrai au bureau est vrai sur le téléphone du visiteur.",
    'features' => [
        ['🗺️', 'Le plan et les stands', "Tracez la place, la halle ou les allées, numérotez chaque emplacement. Le plan publié montre les places libres et les places attribuées."],
        ['💶', 'Les tarifs par zone', "Un prix par stand, au mètre linéaire ou au mètre carré, différent selon l'emplacement. Les zones passent de la négociation à un tarif affiché."],
        ['📝', 'Les réservations en ligne', "L'exposant choisit son stand, indique ses produits ou ses créations et envoie sa demande. Vous validez ; la liste des présents se met à jour."],
        ['🧑‍🌾', 'Une fiche par producteur ou créateur', "Chaque exposant a sa page : activité, produits de saison, créations, contact. Le public sait qui trouver et à quel stand avant d'arriver."],
        ['🏪', 'Une boutique entre deux marchés', "Le producteur ou le créateur qui le souhaite ouvre sa boutique en ligne : ses produits restent visibles toute la semaine, pas seulement le samedi."],
        ['🔔', 'Les habitués prévenus', "Les visiteurs s'abonnent aux nouveautés du marché et reçoivent la prochaine date, avec le plan et la liste des exposants déjà en ligne."],
    ],

    'steps_title' => 'Du plan de la place au marché publié',
    'steps' => [
        ['Tracez les emplacements', "Partez d'une photo de la place ou du plan vierge, délimitez les allées et la halle, puis numérotez les stands."],
        ['Fixez les tarifs', "Un prix par stand ou au mètre, éventuellement différent selon la zone et la saison."],
        ['Installez les exposants', "Attribuez les places aux habitués, puis ouvrez les places restantes aux nouveaux producteurs et créateurs."],
        ['Publiez et partagez', "La page du marché annonce la date, le plan et les exposants présents. Un lien suffit pour les réseaux, les affiches et la lettre d'information."],
    ],

    'plan_bullet' => "Tarifs par zone, fiches exposants et réservations en ligne",
    'roi_title' => 'Ce que représente un marché de 30 stands',
    'roi' => [
        ['30', "emplacements identifiés, numérotés et attribuables depuis un navigateur"],
        ['3', "zones tarifaires possibles, au lieu d'un prix unique négocié"],
        ['1', "page publique à partager, au lieu d'une affiche par allée"],
    ],
    'roi_text' => "Les droits de place restent ceux de la structure qui gère le marché : Brocs ne prélève aucune commission sur les places ni sur les ventes des exposants.",

    'live_title' => 'Des marchés de producteurs déjà en ligne',
    'faq' => [
        ["Est-ce que la plateforme encaisse les droits de place ?", "Non. Le règlement des places reste votre affaire (régie, association, espèces ou chèque). La plateforme gère le plan, l'attribution et la page publique du marché."],
        ["Peut-on accueillir des producteurs et des créateurs sur le même marché ?", "Oui, le type est fait pour cela : les deux familles d'exposants partagent le plan, les tarifs et la page publique, avec une fiche par stand."],
        ["Le marché est hebdomadaire : faut-il tout refaire chaque semaine ?", "Non. Le plan, les zones et les tarifs restent ; vous ne changez que les dates et les présences. Les habitués gardent leur place d'une semaine à l'autre."],
        ["Un exposant peut-il s'inscrire seul ?", "Oui, s'il choisit une place libre sur le plan. Sinon, vous l'installez depuis l'administration : la page publique se met à jour."],
        ["Un producteur sans boutique peut-il figurer sur la page ?", "Oui, il apparaît avec son activité et ses produits. S'il ouvre une boutique, ses produits s'affichent et il peut vendre entre deux marchés."],
        ["Est-ce que ça remplace le règlement du marché ?", "Non. Le règlement intérieur, les autorisations et les contrôles restent les vôtres. La plateforme rend le plan et les exposants consultables par toute l'équipe."],
    ],

    'cta_title' => 'Mettez votre marché de producteurs en ligne avant la prochaine édition',
    'cta_text' => "L'offre d'essai est gratuite : dessinez les stands, attribuez quelques places et montrez la page à vos exposants. Vous saurez en une heure si l'outil tient votre marché.",
]);
