<?php

// Pages légales de la boutique : conditions générales de vente, conditions d'utilisation (site et compte client), politique de confidentialité,
// mentions légales. Chaque page part d'un MODÈLE rempli avec l'identité du vendeur (Administration → Pages légales) ; le commerçant le relit, le
// modifie et le VALIDE. Ces textes sont des modèles de départ, pas un avis juridique : à faire relire avant mise en ligne définitive.
// Réglages dans la table settings : legal_<champ> (identité), legal_text_<page> (texte modifié), legal_validated_at / legal_validated_hash.

const LEGAL_PAGES = [
    'cgv' => ['title' => 'Conditions générales de vente', 'short' => 'CGV'],
    'cgu' => ['title' => "Conditions d'utilisation", 'short' => 'Conditions d\'utilisation'],
    'confidentialite' => ['title' => 'Politique de confidentialité', 'short' => 'Confidentialité'],
    'mentions' => ['title' => 'Mentions légales', 'short' => 'Mentions légales'],
];

/** Champs d'identité du vendeur (clé => [libellé, aide]). */
const LEGAL_FIELDS = [
    'company' => ['Nom du vendeur (raison sociale ou nom)', 'Ex. La Brocante du Petit Chalet — Marie Dupont, entrepreneur individuel'],
    'siret' => ['SIRET / SIREN', 'Ex. 123 456 789 00012'],
    'address' => ['Adresse du vendeur', 'Adresse postale complète'],
    'email' => ['E-mail de contact', 'Adresse à laquelle les clients peuvent écrire'],
    'phone' => ['Téléphone de contact', 'Ex. 06 12 34 56 78'],
    'vat' => ['Mention de TVA', 'Ex. TVA non applicable, art. 293 B du CGI — ou n° de TVA intracommunautaire'],
    'director' => ['Directeur de la publication', 'Nom de la personne responsable du site'],
    'host' => ['Hébergeur du site', 'OVH par défaut ; modifiable ici ou dans l\'administration du portail'],
    'mediator' => ['Médiateur de la consommation', 'Nom, adresse du site et de contact du médiateur auquel vous adhérez (obligatoire pour vendre aux consommateurs)'],
    'withdrawal' => ['Délai de rétractation (jours)', '14 jours par défaut ; indiquez 0 si vous ne vendez pas aux consommateurs (vente entre particuliers)'],
];

/** Hébergeur proposé tant que le portail n'en a pas enregistré un autre (Réglages → Hébergeur par défaut). */
const LEGAL_HOST_OVH = 'OVH SAS — 2 rue Kellermann, 59100 Roubaix, France';

/** Où souscrire un médiateur de la consommation (liens vérifiés, ouverts dans un nouvel onglet). */
const LEGAL_MEDIATOR_LINKS = [
    ['label' => 'Liste officielle des médiateurs', 'url' => 'https://www.economie.gouv.fr/mediation-conso/vous-etes-un-professionnel/choisir-un-mediateur-de-la-consommation/mediateurs-references', 'note' => 'Choisir un médiateur référencé (economie.gouv.fr)'],
    ['label' => 'CM2C', 'url' => 'https://www.cm2c.net/inscription-professionnel.php', 'note' => 'Inscription d\'un professionnel — Centre de médiation de la consommation de conciliateurs de justice'],
    ['label' => 'SAS Médiation Solution', 'url' => 'https://sasmediationsolution-conso.fr/professionnels/demander-une-convention', 'note' => 'Demander une convention — médiateur de la consommation généraliste'],
];

function legal_get(string $field): string
{
    return trim(shop_setting('legal_' . $field, ''));
}

/** Fichier du portail qui conserve l'hébergeur par défaut, commun à tous les commerces. */
function legal_defaults_file(): string
{
    return dirname(__DIR__) . '/data/portail-legal.json';
}

/** Hébergeur par défaut réglé dans le portail, sinon OVH. */
function legal_default_host(): string
{
    $file = legal_defaults_file();
    if (is_file($file)) {
        $data = json_decode((string) file_get_contents($file), true);
        $host = trim((string) ($data['host'] ?? ''));
        if ($host !== '') return $host;
    }
    return LEGAL_HOST_OVH;
}

function legal_default_host_save(string $host): void
{
    $host = mb_substr(trim(preg_replace('/\s+/u', ' ', $host)), 0, 300);
    $file = legal_defaults_file();
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
    file_put_contents($file, json_encode(['host' => $host], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

/**
 * Identité complète à partir des valeurs enregistrées, du nom du site et de sa
 * fiche : sert à la boutique (legal_identity) comme au portail, qui lit la base
 * d'un autre commerce sans changer le commerce actif.
 */
function legal_identity_values(array $saved, string $site, array $content): array
{
    $out = [];
    foreach (array_keys(LEGAL_FIELDS) as $f) $out[$f] = trim((string) ($saved[$f] ?? ''));
    if ($out['company'] === '') $out['company'] = $site;
    if ($out['address'] === '') {
        $a = trim((string) ($content['contact_address'] ?? ''));
        $out['address'] = preg_match('/^(à compléter|a completer|\[?à compléter)/iu', $a) ? '' : $a;          // texte d'attente de la boutique : pas une adresse
    }
    if ($out['host'] === '') $out['host'] = legal_default_host();
    if ($out['withdrawal'] === '') $out['withdrawal'] = '14';
    $out['site'] = $site;
    return $out;
}

/** Identité du vendeur avec valeurs par défaut tirées du contenu de la boutique ; une valeur absente reste « [À COMPLÉTER] » dans les textes. */
function legal_identity(): array
{
    $c = get_content();
    $site = trim((string) ($c['site_name'] ?? '')) ?: (string) tenant('name');
    $saved = [];
    foreach (array_keys(LEGAL_FIELDS) as $f) $saved[$f] = legal_get($f);
    return legal_identity_values($saved, $site, $c);
}

/** Valeurs `legal_*` d'une base de commerce (portail). */
function legal_settings_from_pdo(PDO $pdo): array
{
    try {
        $all = $pdo->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $all = [];
    }
    $out = [];
    foreach (array_keys(LEGAL_FIELDS) as $f) $out[$f] = (string) ($all['legal_' . $f] ?? '');
    return $out;
}

/** Enregistre les champs d'identité fournis (les autres restent inchangés). */
function legal_save_identity(PDO $pdo, array $values): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $stmt = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value');
    foreach (array_keys(LEGAL_FIELDS) as $f) {
        if (array_key_exists($f, $values)) $stmt->execute(['legal_' . $f, (string) $values[$f]]);
    }
}

/** Champ d'identité nettoyé (même règle que l'administration du commerce). */
function legal_clean_field(string $field, string $value): string
{
    $value = mb_substr(trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $value))), 0, 300);
    if ($field === 'withdrawal') return $value === '' ? '' : (string) max(0, min(60, (int) $value));
    return $value;
}

/** Nombre de champs d'identité encore vides (hors délai, aide à la validation). */
function legal_missing_fields(): array
{
    $id = legal_identity();
    $need = ['company', 'siret', 'address', 'email', 'host', 'mediator'];
    return array_values(array_filter($need, static fn ($f) => trim((string) ($id[$f] ?? '')) === ''));
}

/** Modèle d'une page, rempli avec l'identité du vendeur. */
function legal_default_text(string $page): string
{
    $id = legal_identity();
    $v = static fn (string $k): string => trim((string) ($id[$k] ?? '')) !== '' ? trim((string) $id[$k]) : '[À COMPLÉTER]';
    $site = $id['site'];
    $days = max(0, (int) $id['withdrawal']);
    $mail = $v('email');
    $retract = $days > 0
        ? "## 7. Droit de rétractation\nSi vous êtes un consommateur, vous disposez d'un délai de {$days} jours à compter de la réception de la pièce pour vous rétracter, sans avoir à justifier de motif ni à payer de pénalités. Pour l'exercer, écrivez-nous à {$mail} en indiquant clairement votre décision, puis renvoyez la pièce, en bon état, dans les {$days} jours suivant votre notification. Les frais de retour sont à votre charge. Nous vous remboursons au plus tard {$days} jours après réception de la pièce, par le même moyen de paiement.\nLe droit de rétractation ne s'applique pas aux pièces confectionnées sur mesure ni aux pièces qui ne peuvent être renvoyées pour des raisons d'hygiène ou de protection de la santé une fois descellées.\n"
        : "## 7. Droit de rétractation\nLes ventes sont conclues par un particulier : le droit de rétractation prévu par le Code de la consommation ne s'applique pas. Vous pouvez néanmoins nous contacter en cas de difficulté ({$mail}).\n";
    switch ($page) {
        case 'cgv':
            return "## 1. Vendeur\n{$v('company')}, {$v('address')} — SIRET {$v('siret')}. {$v('vat')}. Contact : {$mail}" . ($id['phone'] !== '' ? ", {$id['phone']}" : '') . ".\n\n"
                . "## 2. Objet\nLes présentes conditions régissent les ventes de pièces proposées sur le site {$site} (boutique en ligne). Toute commande implique l'acceptation sans réserve de ces conditions, que vous cochez avant de payer.\n\n"
                . "## 3. Pièces proposées\nLes pièces sont, sauf mention contraire, d'occasion, chinées et le plus souvent uniques. Chaque fiche décrit la pièce (état, dimensions, matières, défauts éventuels) et la présente en photos ; les photos et les couleurs peuvent légèrement varier selon votre écran. Une pièce unique ne peut être vendue qu'une fois : elle est réservée pendant le paiement, puis retirée de la boutique.\n\n"
                . "## 4. Prix\nLes prix sont indiqués en euros, toutes taxes comprises le cas échéant ({$v('vat')}), hors frais de livraison. Le prix applicable est celui affiché au moment de la commande. Les frais de livraison sont indiqués avant le paiement.\n\n"
                . "## 5. Commande et paiement\nVous choisissez vos pièces, votre mode de livraison, puis vous payez en ligne par carte bancaire (paiement sécurisé par Stripe : nous ne conservons aucun numéro de carte). La commande est confirmée dès l'acceptation du paiement et un e-mail de confirmation vous est envoyé. Nous pouvons annuler et rembourser une commande en cas de pièce devenue indisponible.\n\n"
                . "## 6. Livraison et retrait\nLes pièces sont expédiées à l'adresse indiquée lors du paiement, dans les pays proposés dans le panier, ou retirées sur place lorsque cette option est proposée. Les délais indiqués sont des estimations ; les pièces fragiles sont emballées avec soin. Les risques de transport sont à notre charge jusqu'à la remise de la pièce.\n\n"
                . $retract . "\n"
                . "## 8. Garanties\nLes pièces vendues par un professionnel à un consommateur bénéficient de la garantie légale de conformité et de la garantie légale des vices cachés (articles L.217-3 et suivants du Code de la consommation et 1641 et suivants du Code civil). Pour les pièces d'occasion, la durée de la garantie légale de conformité peut être réduite à douze mois, ce que la fiche de la pièce précise le cas échéant. Contactez-nous à {$mail} en cas de problème.\n\n"
                . "## 9. Réclamations et médiation\nEn cas de litige, écrivez-nous d'abord à {$mail}. Si aucune solution amiable n'est trouvée, vous pouvez saisir gratuitement le médiateur de la consommation : {$v('mediator')}.\n\n"
                . "## 10. Données personnelles\nLes informations recueillies (identité, e-mail, téléphone, adresse) servent à traiter et livrer votre commande, dans les conditions de notre politique de confidentialité.\n\n"
                . "## 11. Droit applicable\nLes présentes conditions sont soumises au droit français. Les juridictions françaises sont compétentes, sans préjudice de vos droits de consommateur.\n";
        case 'cgu':
            return "## 1. Objet\nLes présentes conditions d'utilisation encadrent l'accès et l'utilisation du site {$site} et du compte client. En naviguant, en créant un compte ou en commandant, vous les acceptez. Les ventes sont régies par nos conditions générales de vente.\n\n"
                . "## 2. Accès au site\nLe site est accessible gratuitement, sans engagement. Nous pouvons le suspendre ou le modifier à tout moment (maintenance, évolution), sans que cela ouvre droit à une indemnité.\n\n"
                . "## 3. Compte client\nVous pouvez créer un compte avec votre adresse e-mail (ou un compte Google, Facebook, etc. si ces options sont proposées) pour retrouver vos commandes et commander plus vite. Vous vous engagez à fournir des informations exactes (nom, e-mail, téléphone) et à garder votre mot de passe confidentiel : toute action faite avec votre compte est réputée faite par vous. Prévenez-nous à {$mail} si vous soupçonnez un usage frauduleux.\nVous pouvez modifier vos informations ou supprimer votre compte à tout moment depuis la page « Mon compte » ; l'historique de vos commandes est conservé pour nos obligations comptables.\n\n"
                . "## 4. Règles d'usage\nVous vous engagez à ne pas perturber le fonctionnement du site, à ne pas tenter d'accéder aux espaces réservés, à ne pas extraire massivement le contenu du site et à respecter les droits de chacun. Nous pouvons suspendre un compte en cas d'usage abusif.\n\n"
                . "## 5. Propriété intellectuelle\nLes photographies, textes, logos et éléments du site appartiennent à {$v('company')} ou sont utilisés avec autorisation. Toute reproduction sans accord écrit est interdite, sauf pour un usage strictement privé.\n\n"
                . "## 6. Responsabilité\nNous veillons à l'exactitude des informations publiées mais ne garantissons pas l'absence d'erreur. Le site peut contenir des liens vers des sites tiers dont nous ne contrôlons pas le contenu. Notre responsabilité ne saurait être engagée pour les dommages indirects liés à l'utilisation du site, dans la limite de la loi.\n\n"
                . "## 7. Évolution des conditions\nNous pouvons modifier ces conditions ; la version applicable est celle en ligne au moment de votre utilisation du site ou de votre commande.\n\n"
                . "## 8. Droit applicable\nLes présentes conditions sont soumises au droit français.\n";
        case 'confidentialite':
            return "## Qui est responsable de vos données ?\n{$v('company')}, {$v('address')} (SIRET {$v('siret')}), responsable du traitement. Contact pour toute question : {$mail}.\n\n"
                . "## Quelles données recueillons-nous ?\n- Commande : nom, adresse e-mail, numéro de téléphone, adresse de livraison, contenu et montant de la commande.\n- Compte client : nom, adresse e-mail, téléphone, mot de passe (conservé chiffré, jamais en clair) ou identifiant du compte Google, Facebook, etc. utilisé.\n- Newsletter, alertes ou programme de fidélité si vous vous y inscrivez : adresse e-mail et préférences.\n- Navigation : un cookie technique de session (panier, connexion) ; pas de cookie publicitaire.\n\n"
                . "## Pourquoi, et sur quelle base ?\n- Traiter, livrer et suivre votre commande, et répondre à vos demandes (exécution du contrat).\n- Gérer votre compte client (exécution du contrat).\n- Vous joindre à propos d'une livraison : votre numéro de téléphone n'est utilisé que pour cela (intérêt légitime).\n- Tenir notre comptabilité et répondre à nos obligations légales (obligation légale).\n- Vous envoyer notre lettre d'information ou vos alertes (votre consentement, retirable à tout moment).\n\n"
                . "## Qui reçoit vos données ?\nNos prestataires, uniquement pour ce qui les concerne : Stripe (paiement sécurisé), les transporteurs (livraison), notre hébergeur ({$v('host')}) et les services d'envoi d'e-mails. L'aide à la saisie d'adresse utilise les services de l'État (Base Adresse Nationale) et d'OpenStreetMap. Nous ne vendons pas vos données.\n\n"
                . "## Combien de temps ?\nCommandes et factures : 10 ans (obligations comptables). Compte client : jusqu'à sa suppression, ou 3 ans sans activité. Prospects, newsletter et fidélité : jusqu'à votre désinscription ou 3 ans sans contact.\n\n"
                . "## Vos droits\nVous pouvez demander l'accès à vos données, leur rectification, leur effacement, leur portabilité, la limitation ou vous opposer à leur traitement, en écrivant à {$mail}. Depuis « Mon compte », vous modifiez vos informations ou supprimez votre compte. Vous pouvez aussi introduire une réclamation auprès de la CNIL (www.cnil.fr).\n";
        case 'mentions':
            return "## Éditeur du site\n{$v('company')}\nAdresse : {$v('address')}\nSIRET : {$v('siret')}\n{$v('vat')}\nE-mail : {$mail}" . ($id['phone'] !== '' ? "\nTéléphone : {$id['phone']}" : '') . "\nDirecteur de la publication : {$v('director')}\n\n"
                . "## Hébergement\n{$v('host')}\n\n"
                . "## Médiation de la consommation\nConformément à l'article L.612-1 du Code de la consommation, le consommateur peut recourir gratuitement à un médiateur : {$v('mediator')}.\n\n"
                . "## Propriété intellectuelle\nLes contenus du site (photographies, textes, logos) sont protégés. Toute reproduction sans autorisation est interdite.\n\n"
                . "## Données personnelles\nVoir notre politique de confidentialité.\n";
    }
    return '';
}

/** Texte en vigueur d'une page : celui enregistré par le commerçant, sinon le modèle rempli. */
function legal_text(string $page): string
{
    if (!isset(LEGAL_PAGES[$page])) return '';
    $saved = shop_setting('legal_text_' . $page, '');
    return trim($saved) !== '' ? $saved : legal_default_text($page);
}

/** Texte → HTML (échappé) : « ## Titre », paragraphes séparés par une ligne vide, listes « - … ». */
function legal_render(string $text): string
{
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $out = '';
    $list = false;
    foreach (preg_split('/\R/u', trim($text)) as $line) {
        $line = rtrim($line);
        if (preg_match('/^-\s+(.+)$/u', $line, $m)) {
            if (!$list) { $out .= '<ul>'; $list = true; }
            $out .= '<li>' . $h($m[1]) . '</li>';
            continue;
        }
        if ($list) { $out .= '</ul>'; $list = false; }
        if ($line === '') continue;
        $out .= preg_match('/^##\s+(.+)$/u', $line, $m) ? '<h2>' . $h($m[1]) . '</h2>' : '<p>' . $h($line) . '</p>';
    }
    return $out . ($list ? '</ul>' : '');
}

/** Empreinte d'une page (son texte et l'identité) : change dès qu'on la modifie. */
function legal_hash(string $page): string
{
    return substr(sha1(legal_text($page)), 0, 10);
}

/** Version des conditions de vente acceptées au paiement (conservée avec la commande). */
function legal_terms_version(): string
{
    return legal_hash('cgv') . '/' . legal_hash('cgu') . '/' . legal_hash('confidentialite');
}

/** État de validation par le commerçant : ['validated' => bool, 'at' => ts, 'changed' => bool (texte modifié depuis la validation)]. */
function legal_status(): array
{
    $at = (int) shop_setting('legal_validated_at', '0');
    $hash = shop_setting('legal_validated_hash', '');
    $now = '';
    foreach (array_keys(LEGAL_PAGES) as $p) $now .= legal_hash($p);
    return ['validated' => $at > 0 && $hash === sha1($now), 'at' => $at, 'changed' => $at > 0 && $hash !== sha1($now), 'hash' => sha1($now)];
}

function legal_validate(): void
{
    $st = legal_status();
    shop_setting_set('legal_validated_at', (string) time());
    shop_setting_set('legal_validated_hash', $st['hash']);
}

function legal_url(string $page): string
{
    return '/legal.php?page=' . rawurlencode($page);
}

/** Liens des pages légales (pied de page, panier, compte). */
function legal_links_html(string $sep = ' · '): string
{
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $links = [];
    foreach (LEGAL_PAGES as $key => $p) $links[] = '<a href="' . $h(legal_url($key)) . '">' . $h($p['short']) . '</a>';
    return implode($sep, $links);
}
