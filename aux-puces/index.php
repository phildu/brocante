<?php
// Page de vente de aux-puces.com : proposer à un marché aux puces, une halle ou une association de marchands sa propre marketplace
// (une vitrine commune, une boutique par marchand). Servie à la racine du domaine (includes/vitrine-host.php) et, pour les essais,
// sur /aux-puces/ de la plateforme. Tarifs, nombre de marchands et galeries en ligne sont lus dans le type « marché aux puces » :
// rien d'écrit en dur qui puisse devenir faux.
require __DIR__ . '/../includes/landing.php';
require_once __DIR__ . '/../includes/vitrine-host.php';

// Exemple vivant : « Les puces de Villeurbanne ». Sur aux-puces.com l'adresse courte /de-villeurbanne y mène (redirection Cloudflare).
$onVitrine = vitrine_page_for_host((string) ($_SERVER['HTTP_HOST'] ?? '')) !== null;

lp_render([
    'type' => 'puces',
    'path' => $onVitrine ? '/' : '/aux-puces/',
    'accent' => '#a4452a',
    'title' => 'Une marketplace pour votre marché aux puces — aux-puces.com',
    'description' => "Une vitrine commune pour tout le marché, une boutique par marchand, un plan des emplacements et des tarifs par zone. Les marchands vendent toute la semaine, sans commission sur les ventes.",
    'eyebrow' => 'Marketplaces de marchés aux puces',
    'h1' => 'Donnez à votre marché aux puces sa propre marketplace.',
    'lede' => "Le dimanche, les visiteurs viennent chiner. Le reste de la semaine, ils trouvent vos marchands en ligne : une vitrine commune pour le marché, une boutique par marchand, et chaque pièce se règle chez celui qui la vend.",
    'demo' => ['url' => $onVitrine ? '/de-villeurbanne' : '/galerie/lespuces/', 'label' => 'Voir un exemple : Les puces de Villeurbanne'],

    'audience_title' => 'Pour ceux qui font vivre un marché',
    'audience_lede' => "Vous réunissez des marchands. La marketplace réunit leurs pièces : le public cherche à un seul endroit, chacun garde sa boutique, son prix et son paiement.",
    'audience' => [
        ['Organisateur de marché aux puces', 'Une vitrine pour toute l\'édition', "Le marché a sa page : date, plan, marchands présents et leurs trouvailles. Un lien suffit pour les affiches et les réseaux."],
        ['Halle · marché couvert', 'Des marchands visibles toute la semaine', "Les stands ne sont ouverts que certains jours ; leurs pièces, elles, restent en ligne et attirent du monde avant l'ouverture."],
        ['Association de marchands', 'Une enseigne commune', "Les marchands se présentent ensemble sous le nom du marché, tout en gardant chacun sa boutique et ses ventes."],
        ['Commune · office de commerce', 'Un outil local pour les chineurs', "Un marché de quartier ou de village gagne en visibilité : le plan publié et l'annuaire des marchands se lisent sur téléphone."],
    ],

    'pain_title' => 'Un marché aux puces reste invisible entre deux éditions',
    'pains' => [
        ['Une vitrine qui s\'éteint le lundi', "Une page Facebook et une affiche : une fois le marché fini, plus rien ne montre ce que les marchands ont en stock."],
        ['« Qui a des vinyles ? »', "Les chineurs cherchent une pièce précise ; sans catalogue commun, ils ne savent pas quel marchand contacter."],
        ['Des marchands sans outil', "Beaucoup n'ont ni boutique en ligne ni temps pour en créer une. Ils vendent sur place, ou ne vendent pas."],
        ['Un plan dans la tête du placier', "Les emplacements, les zones et les tarifs vivent dans un cahier. Personne d'autre ne sait ce qui est libre."],
        ['Une marketplace trop chère ou trop lointaine', "Les grandes places de marché prennent une commission sur chaque vente et noient le marchand local parmi des millions d'annonces."],
    ],

    'tools_title' => 'Une marketplace de marché, pas une place de marché mondiale',
    'tools_lede' => "Le public voit un marché ; chaque marchand tient une boutique. Les ventes se règlent chez le marchand, la plateforme ne touche à aucune commission.",
    'features' => [
        ['🏪', 'Une boutique par marchand', "Chaque marchand publie ses pièces avec ses photos et ses prix, prépare ses fiches avec l'aide de l'IA et encaisse ses propres ventes."],
        ['🧺', 'La vitrine commune du marché', "Une page unique réunit les marchands et leurs trouvailles : le public parcourt tout le marché sans changer de site."],
        ['🗺️', 'Le plan et les emplacements', "Plan des allées, emplacements numérotés, tarifs par zone : ce que sait le placier devient lisible pour toute l'équipe."],
        ['📱', 'Une appli pour les visiteurs', "Les visiteurs s'inscrivent, suivent leurs marchands, cumulent des points de fidélité et reviennent aux dates annoncées."],
        ['🔄', 'La bourse d\'échanges', "Les chineurs déposent leurs recherches, les marchands répondent : la pièce rare trouve son acheteur."],
        ['🔗', 'Votre adresse, vos couleurs', "Le marché peut vivre à sa propre adresse web, aux couleurs de l'organisateur, avec un lien court pour les affiches."],
    ],

    'steps_title' => 'De l\'idée à la marketplace en ligne',
    'steps' => [
        ['Créez le marché', "Donnez-lui un nom, des couleurs et une adresse. L'essai est gratuit, sans carte bancaire."],
        ['Dessinez le plan', "Allées, halls, parking : numérotez les emplacements et fixez les tarifs par zone."],
        ['Invitez vos marchands', "Chacun ouvre sa boutique ou se présente avec sa spécialité. Vous validez les demandes d'emplacement en un clic."],
        ['Ouvrez au public', "Partagez le lien : les visiteurs parcourent la vitrine, repèrent leurs marchands et préparent leur venue."],
    ],

    'plan_bullet' => "Vitrine commune, boutiques des marchands et plan des emplacements",
    'roi_title' => 'Ce que change une marketplace pour un marché de 60 marchands',
    'roi' => [
        ['60', "boutiques possibles, une par marchand, sous la même enseigne"],
        ['7 j / 7', "de vitrine, au lieu d'un seul jour d'ouverture par semaine"],
        ['1', "adresse à retenir pour le public, au lieu d'une page par marchand"],
    ],
    'roi_text' => "Les recettes d'emplacements et les ventes restent celles du marché et de ses marchands : la plateforme ne prélève aucune commission. Seul l'abonnement du marché est facturé.",

    'live_title' => 'Des marchés aux puces déjà en ligne',
    'faq' => [
        ["Qu'est-ce qu'une marketplace de marché aux puces ?", "Une vitrine commune où les marchands d'un même marché présentent leurs pièces, chacun dans sa boutique. Le public parcourt tout le marché en un seul endroit ; chaque achat se règle chez le marchand concerné."],
        ["Prenez-vous une commission sur les ventes ?", "Non. Le marché paie un abonnement fixe, quel que soit le nombre de visiteurs et de ventes. Les marchands encaissent leurs ventes eux-mêmes."],
        ["Mes marchands doivent-ils déjà avoir une boutique en ligne ?", "Non. Ils créent leur boutique depuis la plateforme, avec photos et fiches préparées par l'IA. Un marchand qui a déjà son site peut être référencé avec son adresse."],
        ["Pouvons-nous garder notre propre adresse web ?", "Oui. Le marché peut recevoir un nom de domaine à lui ; sinon il vit sous une adresse de la plateforme."],
        ["Faut-il gérer le plan et les emplacements ?", "C'est possible mais pas obligatoire : vous pouvez ne publier que la vitrine des marchands et ajouter le plan plus tard."],
        ["Combien de temps pour ouvrir ?", "L'essai gratuit permet de créer le marché, de poser quelques marchands et de montrer la page en une soirée. Vous décidez ensuite de passer à une offre payante ou non."],
    ],

    'cta_title' => 'Ouvrez la marketplace de votre marché',
    'cta_text' => "L'essai est gratuit : créez le marché, invitez deux ou trois marchands et montrez la page. Vous saurez vite si la marketplace tient votre marché.",
]);
