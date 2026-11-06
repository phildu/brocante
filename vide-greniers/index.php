<?php
// Page d'atterrissage : organisateurs de vide-grenier (comité des fêtes, association de parents, commune, brocanteur).
require __DIR__ . '/../includes/landing.php';

lp_render([
    'type' => 'vide-grenier',
    'path' => '/vide-greniers/',
    'accent' => '#b5502e',
    'title' => 'Vide-grenier en ligne : plan, emplacements, inscriptions — Brocs',
    'description' => "Vide-grenier sans paperasse : les exposants choisissent leur place sur le plan et envoient leur demande en ligne. Tarifs par zone, page publique.",
    'eyebrow' => 'Organisateurs de vide-grenier',
    'h1' => 'Le vide-grenier se prépare en ligne, pas au téléphone.',
    'lede' => "Le comité des fêtes, l'association de parents ou la commune dessine le plan une fois. Les exposants choisissent leur place, envoient leur demande, vous validez. Le jour J, le public a le plan et la liste des exposants dans son téléphone.",

    'audience_title' => 'Quatre organisateurs, les mêmes trois bénévoles',
    'audience_lede' => "La plateforme ne change pas la nature de votre événement : elle enlève la liste recopiée à la main, le plan au feutre et les appels du samedi soir.",
    'audience' => [
        ['Comité des fêtes · association', 'Deux à cinq bénévoles, un dimanche par an', "Entre 60 et 150 exposants à inscrire, un plan à tenir, une caisse à équilibrer. Chaque bénévole doit pouvoir prendre des inscriptions sans rappeler l'organisateur."],
        ['École · parents d\'élèves', 'La recette finance un projet', "Le vide-grenier doit rapporter clairement : combien d'emplacements, à quel prix, combien restent. Le bureau présente un compte simple après l'événement."],
        ['Commune', 'Autorisation et cadre', "La mairie doit pouvoir montrer qui expose, où, et sur quel fondement elle a attribué les places. L'occupation du domaine public reste encadrée par votre arrêté."],
        ['Brocanteur organisateur', 'Plusieurs événements par an', "Vous gérez deux ou trois vides-greniers : un plan modèle par site, dupliqué pour chaque édition, avec les habitués déjà dans la base."],
    ],

    'pain_title' => 'Un vide-grenier se perd rarement le jour J : il se perd dans les trois semaines d\'avant',
    'pains' => [
        ['Les inscriptions au téléphone', "Un bénévole note un nom, un numéro et une taille d'emplacement sur un carnet. Deux erreurs de recopie et deux exposants mécontents le dimanche matin."],
        ['Le plan au feutre', "Le plan est un rectangle sur une feuille A4. Le jour J, chacun cherche sa place, bloque l'allée et déplace une table pour « juste un mètre »."],
        ['L\'argent dispersé', "Les chèques passent de main en main, la caisse est recomptée le lundi, et personne ne sait combien de places ont été offertes aux bénévoles."],
        ['Le public qui ne sait pas', "Une affiche et un groupe Facebook ne disent ni qui expose, ni quoi, ni où. Les visiteurs arrivent au hasard et repartent avant d'avoir tout vu."],
    ],

    'tools_title' => 'Le plan, les demandes et la vitrine au même endroit',
    'tools_lede' => "Tout part du plan : c'est lui qui fixe les prix, qui limite les demandes et qui alimente la page publique. Un seul dessin, trois usages.",
    'features' => [
        ['🗺️', 'Un plan cliquable', "Tracez les rues, les halls et les cours, puis numérotez les emplacements. L'exposant voit sa place avant de la demander ; vous voyez ce qui reste en un coup d'œil."],
        ['📏', 'Un tarif par zone', "Tant au mètre linéaire (un mètre de trottoir devant lequel on installe une table) dans la rue, tant par emplacement sous le hall : le prix s'affiche selon la zone choisie."],
        ['📝', 'Les demandes en ligne', "Nom, contact, nature du stand, place souhaitée : l'exposant remplit, vous validez ou refusez. La liste des inscrits se met à jour toute seule."],
        ['🏪', 'Une page pour chaque exposant', "Chaque exposant confirmé apparaît sur la page du vide-grenier. S'il a une boutique, le public voit ses pièces et prépare sa visite ; les invendus restent visibles après."],
        ['🔔', 'Les habitués prévenus', "Les visiteurs s'abonnent aux nouveautés du vide-grenier et reçoivent l'annonce de l'édition suivante, avec la date et le plan déjà en ligne."],
        ['🔄', 'La bourse d\'échanges', "Un visiteur dépose une recherche (« je cherche un vélo enfant »), un exposant propose : la conversation commence avant le dimanche, pas devant la table."],
    ],

    'steps_title' => 'De la feuille blanche au dimanche matin',
    'steps' => [
        ['Dessinez le plan', "Importez une photo du site ou partez du plan vierge : tracez les rues et les halls, puis numérotez les emplacements."],
        ['Fixez les tarifs', "Un prix au mètre linéaire ou par emplacement, éventuellement différent selon la zone (rue, hall, cour)."],
        ['Ouvrez les demandes', "Un lien unique à partager : chaque exposant choisit sa place, indique son stand et envoie sa demande."],
        ['Validez la veille', "Vous validez les demandes, le plan se met à jour, la page publique annonce les exposants confirmés."],
    ],

    'plan_bullet' => "Demandes d'emplacement, tarifs par zone et bourse d'échanges",
    'roi_title' => 'Ce que change une édition de 120 exposants',
    'roi' => [
        ['15 h', "de saisie, d'appels et de recopies économisées avant l'événement"],
        ['120', "demandes reçues au même endroit, avec la place choisie par l'exposant"],
        ['1', "plan à jour, partagé avec le public et les exposants"],
    ],
    'roi_text' => "Les recettes d'emplacements restent les vôtres : la plateforme n'encaisse rien sur les places et ne prend aucune commission. Elle remplace le carnet, le plan au feutre et la liste de diffusion.",

    'live_title' => 'Des vides-greniers déjà organisés avec Brocs',
    'faq' => [
        ["Est-ce que la plateforme prend une commission sur les emplacements ?", "Non. Vous fixez le prix de vos places et vous encaissez comme aujourd'hui (espèces, chèque ou votre propre moyen de paiement). Brocs facture uniquement l'abonnement de l'offre choisie."],
        ["Combien d'exposants peut-on accueillir ?", "L'offre Essentiel couvre 40 exposants, l'offre Pro 150. Au-delà, le plafond se règle : écrivez-nous avant l'événement, pas la veille."],
        ["Nos exposants ne sont pas à l'aise avec l'informatique", "Vous pouvez inscrire une personne à sa place : le plan est le même, la saisie prend une minute. Le lien de la page reste consultable sans compte, sur téléphone."],
        ["Peut-on garder nos tarifs et nos habitués ?", "Oui. Les tarifs par zone sont les vôtres, et les habitués s'abonnent pour recevoir les prochaines dates. Rien ne vous oblige à changer votre règlement intérieur."],
        ["Que devient la page après l'événement ?", "Elle reste en ligne avec les exposants et leurs pièces. Vous la rouvrez pour l'édition suivante : le plan, les zones et les tarifs sont déjà là."],
        ["Est-ce que ça remplace l'autorisation en mairie ?", "Non. Les démarches administratives (autorisation d'occupation du domaine public, déclaration, assurance) restent les vôtres. La plateforme gère le plan, les demandes et la page publique."],
    ],

    'cta_title' => 'Préparez votre prochain vide-grenier avec le plan déjà en ligne',
    'cta_text' => "L'offre d'essai est gratuite et sans carte bancaire : dessinez le plan, ouvrez les demandes, et voyez si le dimanche est plus calme.",
]);
