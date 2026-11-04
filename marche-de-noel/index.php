<?php
// Page d'atterrissage : organisateurs de marché de Noël (comité des fêtes, association de commerçants, commune, place commerciale).
require __DIR__ . '/../includes/landing.php';

lp_render([
    'type' => 'marche-de-noel',
    'path' => '/marche-de-noel/',
    'accent' => '#a3333d',
    'title' => 'Marché de Noël : chalets, plan et réservations — Brocs',
    'description' => "Marché de Noël sans cahier : les exposants réservent leur chalet en ligne, vous validez. Plan des allées, tarifs par zone et page publique éphémère.",
    'eyebrow' => 'Organisateurs de marché de Noël',
    'h1' => 'Un marché de Noël qui se réserve en ligne, pas au téléphone.',
    'lede' => "Le comité des fêtes, l'association de commerçants ou la commune dessine les allées une fois. Les exposants réservent leur chalet, vous validez, et le public trouve le plan et les exposants dans son téléphone, du premier au dernier week-end.",

    'audience_title' => 'Quatre organisateurs, la même urgence de décembre',
    'audience_lede' => "Le marché de Noël se prépare en six semaines, souvent avec une équipe bénévole qui n'a pas de temps à perdre en tableurs.",
    'audience' => [
        ['Comité des fêtes · association', 'Un ou deux week-ends par an', "Une quarantaine de chalets à attribuer, des exposants qui rappellent, un plan à afficher : chaque bénévole doit pouvoir prendre une réservation sans rappeler l'organisateur."],
        ['Association de commerçants', 'Faire vivre le centre-ville', "Les commerçants ouvrent leur vitrine dehors le temps d'un marché. Il faut répartir les emplacements, éviter les doublons et annoncer les animations."],
        ['Commune · régie', 'Le domaine public et la sécurité', "La mairie doit montrer qui occupe quelle place, sur quel fondement, et transmettre le plan aux services techniques et de secours."],
        ['Place commerciale · organisateur privé', 'Plusieurs marchés par saison', "Un modèle de plan par site, dupliqué pour chaque édition, avec les exposants habituels déjà dans la base et leurs anciennes places."],
    ],

    'pain_title' => 'Un marché de Noël se rate rarement le jour J : il se rate dans les six semaines d\'avant',
    'pains' => [
        ['Les réservations au téléphone', "Un bénévole note un nom, un numéro et une taille de chalet. Deux recopies plus tard, deux exposants pensent avoir la même place."],
        ['Le plan redessiné chaque année', "Le plan est une feuille A4 au feutre. Les allées changent, les numéros se perdent et les habitués ne retrouvent plus leur emplacement."],
        ['Les places attribuées trop tard', "Les exposants attendent une réponse, relancent, puis renoncent. Le marché se remplit en retard et les allées restent trouées."],
        ['Le public qui ne sait pas qui vient', "Une affiche annonce le marché, pas les exposants. Les visiteurs arrivent au hasard et repartent sans avoir trouvé le stand qu'ils cherchaient."],
        ['L\'argent et les cautions dispersés', "Les chèques passent de main en main, les cautions se confondent avec les loyers, et personne ne sait le lundi combien de chalets ont été payés."],
    ],

    'tools_title' => 'Les chalets, les réservations et la page du marché au même endroit',
    'tools_lede' => "Tout part du plan : il fixe les zones, il limite les réservations et il alimente la page publique. Un seul dessin, trois usages, du premier devis au dernier démontage.",
    'features' => [
        ['🗺️', 'Un plan et des chalets numérotés', "Tracez les allées et l'esplanade, puis numérotez chaque chalet. L'exposant voit sa place avant de la réserver ; vous voyez ce qui reste en un coup d'œil."],
        ['💶', 'Un tarif par zone et par durée', "L'entrée de la place ne se vend pas comme le fond du parking. Le prix s'affiche selon la zone, et le week-end peut avoir son propre tarif."],
        ['📝', 'Les réservations en ligne', "Nom, contact, activité, chalet souhaité : l'exposant réserve, vous validez ou refusez. La liste des exposants confirmés se met à jour toute seule."],
        ['🏪', 'Une page par exposant', "Chaque exposant confirmé apparaît sur la page du marché avec son activité et, s'il en a une, sa boutique en ligne. Le public prépare sa visite avant de venir."],
        ['🎄', 'Une identité saisonnière', "Couleurs, logo et textes du marché se règlent une fois : la page publique annonce les dates, les horaires et les animations sans repartir de zéro chaque année."],
        ['🔔', 'Les habitués prévenus', "Les visiteurs s'abonnent aux nouveautés du marché et reçoivent l'annonce de l'édition suivante, avec la date et le plan déjà en ligne."],
    ],

    'steps_title' => 'De la feuille blanche au premier week-end de décembre',
    'steps' => [
        ['Dessinez le plan', "Importez une photo de la place ou partez du plan vierge : tracez les allées et l'esplanade, puis numérotez les chalets."],
        ['Fixez les tarifs', "Un prix par chalet ou au mètre carré, éventuellement différent selon la zone et la durée (un ou deux week-ends)."],
        ['Ouvrez les réservations', "Un lien unique à partager : chaque exposant choisit son chalet, indique son activité et envoie sa réservation."],
        ['Validez et publiez', "Vous validez les demandes, le plan se met à jour et la page publique annonce les exposants confirmés, les dates et les animations."],
    ],

    'plan_bullet' => "Réservations de chalets, tarifs par zone et page publique éphémère",
    'roi_title' => 'Ce que change un marché de 40 chalets',
    'roi' => [
        ['20 h', "de saisie, de relances et de recopies économisées avant l'ouverture"],
        ['40', "réservations reçues au même endroit, avec le chalet choisi par l'exposant"],
        ['1', "plan à jour, partagé avec le public, les exposants et les services de secours"],
    ],
    'roi_text' => "Les recettes de chalets restent les vôtres : la plateforme n'encaisse rien sur les places et ne prend aucune commission. Elle remplace le carnet, le plan au feutre et la liste de diffusion.",

    'live_title' => 'Des marchés de Noël déjà préparés avec Brocs',
    'faq' => [
        ["Est-ce que la plateforme prend une commission sur les chalets ?", "Non. Vous fixez le prix de vos chalets et vous encaissez comme aujourd'hui (espèces, chèque, régie ou votre propre moyen de paiement). Brocs facture uniquement l'abonnement de l'offre choisie."],
        ["Combien de chalets peut-on accueillir ?", "L'offre Essentiel couvre 35 exposants, l'offre Pro 120. Au-delà, le plafond se règle : écrivez-nous avant l'événement, pas la veille."],
        ["Le marché dure deux week-ends : peut-on facturer par durée ?", "Oui. Les tarifs sont les vôtres et peuvent dépendre de la zone comme de la durée. Les réservations restent regroupées sur une seule page."],
        ["Nos exposants ne sont pas à l'aise avec l'informatique", "Vous pouvez réserver une place à leur nom : le plan est le même, la saisie prend une minute. La page publique reste consultable sans compte, sur téléphone."],
        ["Que devient la page après Noël ?", "Elle reste consultable avec les exposants et leurs boutiques. Vous la rouvrez pour l'édition suivante : le plan, les zones et les tarifs sont déjà là."],
        ["Est-ce que ça remplace l'autorisation en mairie ?", "Non. L'autorisation d'occupation du domaine public, la déclaration, l'assurance et les consignes de sécurité restent les vôtres. La plateforme gère le plan, les réservations et la page publique."],
    ],

    'cta_title' => 'Préparez votre marché de Noël avec le plan déjà en ligne',
    'cta_text' => "L'offre d'essai est gratuite et sans carte bancaire : dessinez les allées, ouvrez les réservations et montrez la page à vos exposants avant décembre.",
]);
