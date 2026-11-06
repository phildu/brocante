<?php

// Conditions d'utilisation de la plateforme (boutiques, galeries, marchés) et annexe
// « engagements de service ». Distinctes des CGV de chaque commerce, qui régissent
// la vente des pièces. Texte de départ à faire relire par un conseil juridique :
// l'identité de l'éditeur reste à compléter dans platform_terms_identity().

const PLATFORM_TERMS_VERSION = '2026-11-02';

function platform_terms_version(): string
{
    return PLATFORM_TERMS_VERSION;
}

function platform_terms_identity(): array
{
    $cfg = function_exists('saas_config') ? saas_config() : ['name' => 'Brocs', 'contact' => ''];
    $dir = (function_exists('saas_data_dir') ? saas_data_dir() : dirname(__DIR__) . '/data') . '/saas';
    $saved = is_file($dir . '/identite.json') ? (json_decode((string) @file_get_contents($dir . '/identite.json'), true) ?: []) : [];
    $str = static fn (string $key, int $max): string => mb_substr(trim((string) ($saved[$key] ?? '')), 0, $max);
    $siret = preg_replace('/\D/', '', $str('siret', 20));
    $registered = strlen($siret) === 14;
    $mediatorUrl = $str('mediator_url', 200);
    return [
        'name' => (string) ($cfg['name'] ?: 'Brocs'),
        'registered' => $registered,
        'editor' => $str('editor', 120) ?: ($registered ? '[Raison sociale à compléter]' : ''),
        'siret' => $registered ? substr($siret, 0, 3) . ' ' . substr($siret, 3, 3) . ' ' . substr($siret, 6, 3) . ' ' . substr($siret, 9, 5) : '',
        'address' => $str('address', 240) ?: ($registered ? '[Adresse à compléter]' : ''),
        'email' => filter_var($str('email', 160), FILTER_VALIDATE_EMAIL) ?: (string) ($cfg['contact'] ?: '[e-mail de contact à compléter]'),
        'host' => 'OVH SAS, 2 rue Kellermann, 59100 Roubaix, France',
        'mediator' => $str('mediator', 240) ?: ($registered ? '[Médiateur de la consommation à compléter]' : ''),
        'mediator_url' => filter_var($mediatorUrl, FILTER_VALIDATE_URL) ? $mediatorUrl : '',
    ];
}

/** Sections de la page : [id, titre, HTML]. */
function platform_terms_sections(): array
{
    $id = platform_terms_identity();
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $date = date('d/m/Y', strtotime(platform_terms_version()));
    $name = $e($id['name']);
    $mail = $e($id['email']);
    $mediator = $e($id['mediator']);
    if ($id['mediator_url'] !== '') {
        $mediator = '<a href="' . $e($id['mediator_url']) . '" target="_blank" rel="noopener">' . $mediator . '</a>';
    }
    $editor = $id['editor'] !== '' ? $e($id['editor']) : 'l’entrepreneur individuel qui l’exploite';
    $identity = $id['registered']
        ? "La plateforme <strong>{$name}</strong> est éditée par {$e($id['editor'])}, {$e($id['address'])}, SIRET {$e($id['siret'])}. Contact : <a href=\"mailto:{$mail}\">{$mail}</a>. Hébergeur : {$e($id['host'])}."
        : "La plateforme <strong>{$name}</strong> est éditée par {$editor}, entreprise en cours d’immatriculation. Le SIRET et l’adresse de l’établissement seront publiés dès leur attribution. Contact : <a href=\"mailto:{$mail}\">{$mail}</a>. Hébergeur : {$e($id['host'])}.";
    $mediation = $id['registered']
        ? "Pour les professionnels, la médiation est facultative ; pour les consommateurs, le médiateur de la consommation compétent est : {$mediator}."
        : "La désignation d’un médiateur de la consommation est en cours. En attendant, adressez votre réclamation à <a href=\"mailto:{$mail}\">{$mail}</a> : une réponse est apportée sous 10 jours ouvrés.";

    $sections = [];

    $sections[] = ['editeur', '1. Éditeur et objet', <<<HTML
<p>{$identity}</p>
<p>Les présentes conditions régissent l'accès et l'utilisation de la plateforme par les commerçants, galeries, marchés, vide-greniers et exposants qui créent un compte. Elles ne régissent pas la vente des pièces : chaque boutique publie ses propres conditions générales de vente et reste seule responsable de ses ventes.</p>
<p>Version du {$date}. En créant un compte, en souscrivant une offre ou en utilisant la plateforme, vous acceptez ces conditions.</p>
HTML];

    $sections[] = ['role', '2. Rôle de la plateforme', <<<HTML
<p>La plateforme fournit des outils d'hébergement, de publication, de paiement et de gestion. Elle n'est pas le vendeur des pièces, ne fixe pas les prix, ne garantit pas la conformité des annonces et n'intervient pas dans la relation entre le commerçant et son client.</p>
<p>Chaque commerçant est responsable de son catalogue, de ses prix, de ses stocks, de ses obligations fiscales et sociales, du respect du droit de la consommation et de la protection des données de ses clients. Les galeries mettent en relation des commerces et des visiteurs ; elles ne vendent pas les pièces et ne perçoivent pas le prix des ventes, sauf mention contraire.</p>
<p>La plateforme agit comme hébergeur de contenus. Elle retire les contenus manifestement illicites qui lui sont signalés et peut suspendre un compte en cas de danger pour les personnes, les données ou le service.</p>
HTML];

    $sections[] = ['compte', '3. Compte et accès', <<<HTML
<p>Vous devez être majeur, capable et disposer du pouvoir d'engager la structure pour laquelle vous créez un compte. Les informations fournies doivent être exactes et tenues à jour. Un compte est personnel ; les identifiants sont confidentiels et toute action réalisée avec votre compte est réputée faite par vous. Prévenez-nous sans délai en cas d'usage frauduleux.</p>
<p>Vous pouvez créer des comptes pour votre équipe ou vos administrateurs de galerie. Vous répondez de leurs actes dans l'utilisation du service. Nous pouvons suspendre un compte en cas de manquement grave, d'impayé, d'usage abusif ou de risque de sécurité.</p>
HTML];

    $sections[] = ['offres', '4. Offres, prix et paiement', <<<HTML
<p>Les offres et les prix applicables sont ceux affichés au moment de la souscription, sur le site, dans le portail ou dans un devis accepté. Les prix sont indiqués en euros ; la TVA et les taxes applicables sont précisées sur la facture. Le paiement est réalisé par carte bancaire via Stripe ; la plateforme ne conserve aucun numéro de carte.</p>
<p>Les abonnements sont mensuels ou annuels et se renouvellent automatiquement, sauf résiliation avant l'échéance. La résiliation prend effet à la fin de la période payée. Sauf disposition légale impérative ou geste commercial accordé dans les conditions de l'article 7, les sommes déjà payées ne sont pas remboursées. Un défaut de paiement peut entraîner la suspension du service après information.</p>
<p>Les plugins et options sont facturés selon leur tarif propre. Les commissions éventuelles d'une galerie sont régies par les conditions de cette galerie. Nous pouvons modifier les prix avec un préavis de 30 jours ; les offres gratuites peuvent évoluer ou être retirées.</p>
HTML];

    $sections[] = ['disponibilite', '5. Disponibilité et engagements de service', <<<HTML
<p>Nous visons une disponibilité mensuelle de <strong>99,5 %</strong> pour les offres Standard, les galeries et les plugins, et de <strong>99,7 %</strong> pour l'offre Premium, hors maintenance planifiée et exclusions ci-dessous. La disponibilité se mesure depuis l'extérieur, toutes les 5 minutes, sur la page d'accueil publique de la boutique ou de la galerie. Elle se calcule ainsi : <em>(minutes du mois − minutes d'indisponibilité) ÷ minutes du mois</em>. Un incident commence lorsque deux contrôles consécutifs échouent et se termine au premier contrôle réussi.</p>
<table class="terms-table">
<thead><tr><th>Offre</th><th>Disponibilité visée</th><th>Déclenchement d'un crédit</th><th>Crédit</th></tr></thead>
<tbody>
<tr><td>Découverte (gratuite)</td><td>Meilleur effort</td><td>—</td><td>Aucun crédit, information du client</td></tr>
<tr><td>Standard</td><td>99,5 %</td><td>Plus de 24 h consécutives</td><td>1 mois d'abonnement</td></tr>
<tr><td>Premium</td><td>99,7 %</td><td>Plus de 12 h consécutives</td><td>1 mois d'abonnement</td></tr>
<tr><td>Galerie</td><td>99,5 %</td><td>Plus de 24 h consécutives</td><td>1 mois d'abonnement</td></tr>
<tr><td>Plugin</td><td>99,5 %</td><td>Plus de 24 h consécutives</td><td>1 mois du plugin</td></tr>
</tbody>
</table>
<p><strong>Exclusions.</strong> Ne déclenchent pas de crédit : la maintenance planifiée annoncée au moins 48 h à l'avance ; la force majeure au sens de l'article 1218 du Code civil ; une panne de l'hébergeur, de Stripe, du registraire, d'un opérateur ou d'une API tierce ; un domaine expiré ou un DNS modifié par le client ; une personnalisation, un code ou un plugin ajouté par le client ; un compte suspendu pour impayé ou usage abusif ; un usage anormal ; un problème de réseau, de navigateur ou d'appareil du client.</p>
<p>Les engagements de disponibilité ne couvrent pas les pertes de chiffre d'affaires, de clientèle, de chances, d'image ou de données non sauvegardées. La plateforme ne garantit aucun résultat commercial : trafic, nombre de ventes, nombre d'exposants ou de visiteurs.</p>
HTML];

    $sections[] = ['credits', '6. Crédits de service et réclamations', <<<HTML
<p>Si la disponibilité mensuelle est inférieure au seuil de l'offre, ou si une indisponibilité continue dépasse le déclencheur du tableau, le client peut demander un <strong>crédit de service</strong> : un avoir sur la prochaine facture, non remboursable en espèces et non transférable. Le crédit est calculé ainsi :</p>
<ul>
<li><strong>1 mois d'abonnement par incident</strong> ayant dépassé le déclencheur, dans la limite de <strong>2 mois par période de 12 mois</strong> et par boutique, galerie ou plugin ;</li>
<li><strong>3 incidents de plus de 4 h sur 30 jours</strong> ouvrent droit à 1 mois supplémentaire, dans la même limite ;</li>
<li>pour un abonnement annuel, le crédit d'un mois correspond à <strong>1/12 du prix annuel</strong> ; pour un plugin, il est plafonné au prix mensuel du plugin ;</li>
<li>l'indisponibilité de plus de 7 jours consécutifs ouvre en outre le droit de résilier sans pénalité, avec remboursement au prorata des mois annuels non consommés.</li>
</ul>
<p>La demande doit être adressée à <a href="mailto:{$mail}">{$mail}</a> dans les 30 jours suivant l'incident, avec les dates et heures constatées. Nous répondons sous 10 jours ouvrés après vérification de nos contrôles et journaux. Le crédit est appliqué sur la facture suivante. Pour les incidents de plus de 24 h causés par la plateforme et constatés par nos contrôles, le crédit est accordé d'office, sans attendre la demande.</p>
<p>Exemples : Standard à 19,90 €/mois, 30 h d'arrêt → 19,90 € de crédit ; Premium à 49,90 €/mois, 15 h → 49,90 € ; plugin à 9,90 €/mois, 26 h → 9,90 €. Le crédit de service est la réparation exclusive des engagements de disponibilité, sans préjudice des droits légaux impératifs du client.</p>
HTML];

    $sections[] = ['geste', '7. Geste commercial discrétionnaire', <<<HTML
<p>En plus des crédits de service, l'éditeur peut, <strong>à sa seule discrétion et sans y être tenu</strong>, accorder un geste commercial : gratuité totale ou temporaire, avoir, remise, mois offert, extension d'une option ou offre d'un plugin. Ce geste est une libéralité commerciale accordée en considération de la relation client.</p>
<p>Il ne constitue ni un droit acquis, ni un précédent, ni une obligation de renouvellement, ni une reconnaissance de responsabilité, ni une modification des présentes conditions. Il ne crée aucun droit pour d'autres clients et ne peut être invoqué pour obtenir un geste identique. Il est mentionné dans le dossier du client avec son montant, sa période et son périmètre ; un geste accordé pour une période déterminée ne se renouvelle pas automatiquement.</p>
<p>Sauf mention contraire, un geste commercial ne modifie pas l'abonnement Stripe en cours : le client ou l'éditeur doit suspendre, remiser ou ajuster le prélèvement dans Stripe pour la même période. Un geste accordé par erreur manifeste, obtenu par fraude ou utilisé de manière abusive peut être retiré. Les crédits de service de l'article 6 sont distincts et relèvent de leur propre barème.</p>
HTML];

    $sections[] = ['donnees', '8. Données et réversibilité', <<<HTML
<p>Le client reste propriétaire de ses contenus : textes, photos, catalogues, fiches, plans et données de ses clients. La plateforme les héberge et les traite uniquement pour fournir le service. Pour les données personnelles des clients finaux, le commerçant est responsable du traitement et la plateforme agit comme sous-traitant ; OVH et Stripe sont sous-traitants ultérieurs.</p>
<p>Une sauvegarde est réalisée quotidiennement. La perte maximale de données visée est de 24 h et la remise en service visée est de 4 h ouvrées après un incident majeur, sans garantie absolue. Le client peut demander un export de ses données (CSV, JSON ou archive) et les récupérer sous 30 jours. Après résiliation, les données sont supprimées sous 30 jours, sauf obligations comptables ou légales de conservation.</p>
<p>Le client s'engage à respecter le RGPD et les obligations d'information de ses propres clients. Il ne doit pas stocker dans la plateforme de données interdites ou disproportionnées.</p>
HTML];

    $sections[] = ['responsabilite', '9. Responsabilité', <<<HTML
<p>Dans les limites permises par la loi, la responsabilité totale de l'éditeur au titre de l'exécution des présentes est plafonnée aux sommes effectivement payées par le client au cours des 12 mois précédant le fait générateur. Les dommages indirects — perte de chiffre d'affaires, de clientèle, de chance, d'image, de données ou de bénéfice — sont exclus.</p>
<p>Ces limitations ne s'appliquent pas en cas de faute lourde, de dol, d'atteinte à la vie ou à l'intégrité physique, ni aux obligations légales impératives. Le client est responsable de ses contenus, de ses ventes, de ses obligations fiscales et de la conformité de ses produits. La plateforme ne garantit aucun résultat commercial.</p>
<p>Si le client est un consommateur ou une association non professionnelle, les garanties légales applicables s'appliquent et les clauses ci-dessus s'interprètent sans le priver de ses droits.</p>
HTML];

    $sections[] = ['resiliation', '10. Suspension et résiliation', <<<HTML
<p>Le client peut résilier son abonnement à tout moment depuis son espace ou en écrivant à <a href="mailto:{$mail}">{$mail}</a> ; la résiliation prend effet à la fin de la période en cours. Ses données restent accessibles 30 jours pour l'export, puis sont supprimées selon l'article 8.</p>
<p>L'éditeur peut suspendre immédiatement l'accès en cas de non-paiement, de contenu manifestement illicite, d'usage abusif, d'atteinte à la sécurité ou de manquement grave. Lorsque le manquement peut être corrigé, une mise en demeure préalable est adressée. L'éditeur peut résilier sans motif avec un préavis de 30 jours ; dans ce cas, les mois annuels non consommés sont remboursés au prorata. La résiliation ne remet pas en cause les crédits déjà accordés ni les sommes dues.</p>
HTML];

    $sections[] = ['support', '11. Support, médiation et droit applicable', <<<HTML
<p>Le support est joignable à <a href="mailto:{$mail}">{$mail}</a>, du lundi au vendredi. Délais indicatifs de première réponse : 72 h pour l'offre Découverte, 48 h pour Standard, galeries et plugins, 24 h pour Premium. Une réclamation reçoit une réponse sous 10 jours ouvrés.</p>
<p>En cas de litige, écrivez-nous d'abord pour rechercher une solution amiable. {$mediation} Les présentes conditions sont soumises au droit français. Les tribunaux français sont compétents, sans préjudice des règles impératives protégeant les consommateurs.</p>
HTML];

    $sections[] = ['evolution', '12. Évolution des conditions', <<<HTML
<p>Nous pouvons modifier les présentes conditions pour tenir compte de l'évolution du service ou de la loi. La version applicable est celle publiée en ligne au moment de l'utilisation. Les changements substantiels sont notifiés au moins 30 jours avant leur entrée en vigueur ; la poursuite de l'utilisation après cette date vaut acceptation. La date de version figure en tête de page.</p>
HTML];

    return $sections;
}
