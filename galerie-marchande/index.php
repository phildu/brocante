<?php
// Page d'atterrissage : galerie marchande (galerie physique, antiquaire qui réunit des exposants, collectif de créateurs).
require __DIR__ . '/../includes/landing.php';

lp_render([
    'type' => 'marchande',
    'path' => '/galerie-marchande/',
    'accent' => '#6a4a86',
    'title' => 'Galerie marchande en ligne : réunir vos commerces — Brocs',
    'description' => "Une galerie, plusieurs commerces : annuaire, plan, nouveautés et bourse d'échanges sur une page. Chaque commerce garde sa boutique et son paiement.",
    'eyebrow' => 'Galerie marchande',
    'h1' => 'Une seule adresse pour les commerces qui se recommandent entre eux.',
    'lede' => "Antiquaires, brocanteurs, créateurs : chacun garde sa boutique, son panier et son paiement. Le public trouve tout au même endroit — l'annuaire, les pièces, le plan de la galerie et les nouveautés de la semaine.",

    'audience_title' => 'Une galerie physique, ou un collectif sans murs',
    'audience_lede' => "La galerie marchande réunit des commerces qui ont déjà une activité. Elle ne les remplace pas : elle leur donne une adresse commune et une visibilité qui se cumule.",
    'audience' => [
        ['Galerie physique', 'Des exposants sous un même toit', "Vous louez des espaces ou vous accueillez des dépôts-ventes. La page prolonge la visite : qui est là, qui vend quoi, quelles pièces sont arrivées cette semaine."],
        ['Antiquaire · brocanteur', 'Votre boutique et celles de vos confrères', "Vous avez déjà une boutique Brocs. Vous créez la galerie qui réunit vos voisins : le public circule de boutique en boutique sans changer d'adresse."],
        ['Association de commerçants', 'Une vitrine commune', "L'association anime la rue ou le village. La galerie donne une page unique aux adhérents et un support pour les animations et les promotions."],
        ['Collectif de créateurs', 'Un lieu partagé, des comptes séparés', "Ateliers et boutiques partagent un local. Chacun encaisse ses ventes ; la galerie présente le collectif et son actualité."],
    ],

    'pain_title' => 'Les commerces se recommandent déjà : leurs pages ne se parlent pas',
    'pains' => [
        ['Des fiches éparpillées', "Chaque commerce a sa page Google, son compte Instagram et son adresse. Le visiteur qui cherche « brocante » doit déjà connaître les noms."],
        ['Une vitrine qui ne cumule rien', "La vitrine d'un commerce profite à ce commerce seul. Dix vitrines côte à côte n'ont jamais fait une adresse commune."],
        ['Un visiteur qui ne sait pas qui vend quoi', "Le public entre au hasard et ressort sans avoir vu la pièce qui l'intéressait, pourtant à trois mètres."],
        ['Les nouveautés qui ne circulent pas', "Une pièce arrive chez l'un, les habitués de l'autre ne le savent pas. La recommandation reste orale."],
    ],

    'tools_title' => 'Une adresse, des boutiques qui restent indépendantes',
    'tools_lede' => "La galerie apporte la page commune et les outils d'animation. Chaque commerce garde sa vitrine, ses prix, son panier et son compte de paiement.",
    'features' => [
        ['📇', 'L\'annuaire de la galerie', "Tous les commerces sur une page, avec leur spécialité et leurs filtres. Le visiteur trouve le marchand de faïence ou de bijoux anciens sans appeler la galerie."],
        ['🗺️', 'Le plan du lieu', "L'entrée, les allées, les étages : le plan situe chaque commerce. Sur un lieu à plusieurs niveaux, le visiteur prépare son parcours."],
        ['🏪', 'Une boutique par commerce', "Chaque commerce garde sa vitrine, son panier et son paiement. La galerie ne touche pas l'argent des ventes et ne prélève aucune commission."],
        ['🔔', 'Les nouveautés de la galerie', "Les visiteurs s'abonnent et reçoivent les arrivées, les ventes et les événements du lieu, toutes boutiques confondues."],
        ['🎁', 'La fidélité', "Points et récompenses à l'échelle de la galerie : le visiteur revient dans le lieu, pas seulement dans une boutique."],
        ['🔄', 'La bourse d\'échanges', "Les chineurs déposent une recherche, les commerces proposent. Une pièce demandée par trois personnes trouve son vendeur en une soirée."],
    ],

    'steps_title' => 'Ouvrir la galerie en une soirée',
    'steps' => [
        ['Créez la galerie', "Nom, logo, couleurs, adresse et horaires : la page reprend l'identité du lieu."],
        ['Invitez les commerces', "Chaque commerce rejoint la galerie avec sa boutique. Il garde ses accès, ses prix et son compte de paiement."],
        ['Publiez le plan', "Placez les commerces sur le plan du lieu : le visiteur sait où aller avant de pousser la porte."],
        ['Animez la page', "Nouveautés, promos communes, bourse d'échanges : la page vit entre deux visites."],
    ],

    'plan_bullet' => "Annuaire, plan du lieu et abonnés aux nouveautés",
    'roi_title' => 'Dix commerces réunis pour le prix d\'un seul',
    'roi' => [
        ['10', "boutiques présentées sur une seule page, avec leurs filtres"],
        ['0 %', "de commission prélevée sur les ventes des commerces"],
        ['1', "abonnement pour la galerie, quel que soit le nombre de visiteurs"],
    ],
    'roi_text' => "Le prix de la galerie se répartit entre les commerces réunis : à dix, l'adresse commune coûte quelques euros par commerce et par mois. Chaque commerce garde par ailleurs sa propre boutique et son abonnement, s'il en a un.",

    'live_title' => 'Des galeries marchandes déjà en ligne',
    'faq' => [
        ["Qui paie l'abonnement ?", "La structure qui ouvre la galerie (le lieu, l'association, le collectif). Les commerces membres gardent leur propre abonnement de boutique s'ils en ont un ; la galerie ne le prend pas en charge."],
        ["Qui encaisse les ventes ?", "Chaque commerce, directement sur son compte de paiement. La galerie est une page de présentation et d'animation : elle ne touche ni le panier ni l'argent des commerces."],
        ["Un commerce peut-il être présent sans boutique en ligne ?", "Il figure dans l'annuaire et sur le plan avec sa spécialité. Pour vendre en ligne, il ouvre sa boutique ; vous pouvez l'aider à la créer."],
        ["Que se passe-t-il si un commerce part ?", "Vous retirez son accès à la galerie : sa fiche disparaît de la page, sa boutique reste intacte et ses ventes ne sont pas touchées."],
        ["Peut-on réserver un nom de domaine pour la galerie ?", "Oui. Une galerie peut être servie sur son propre domaine, avec sa page à la racine. C'est un réglage du portail, pas une nouvelle galerie."],
        ["La galerie peut-elle accueillir des boutiques venues d'ailleurs ?", "Oui, une boutique n'a pas besoin d'être dans vos murs : la galerie peut être un collectif de créateurs répartis dans plusieurs villes."],
    ],

    'cta_title' => 'Donnez une adresse commune à vos commerces',
    'cta_text' => "Créez la galerie, invitez vos premiers commerces et partagez la page. L'essai est gratuit et ne demande pas de carte bancaire.",
]);
