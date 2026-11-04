<?php

// Modèles de documents de l'assistant : courriers administratifs, notice de sécurité, règlement, convention,
// messages aux exposants et aux prospects, communiqué de presse, briefing bénévoles, rétroplanning, bilan,
// plan de financement et demandes d'aide à la numérisation.
//
// Les documents sont des modèles à adapter : ils reprennent les informations saisies dans l'assistant et laissent
// des repères visibles « [à compléter] » partout où une donnée manque. Ils ne remplacent ni le règlement local,
// ni l'avis de la commission de sécurité, ni le dépôt officiel en mairie ou en préfecture.

require_once __DIR__ . '/gallery-assistant.php';

/** Repère visible dans le document pour une information manquante. */
function gassd_todo(string $label): string
{
    return '<span class="doc-todo">[' . gass_h($label) . ']</span>';
}

/** Valeur échappée ou repère « à compléter ». */
function gassd_or($value, string $label): string
{
    $v = trim((string) ($value ?? ''));
    return $v !== '' ? gass_h($v) : gassd_todo($label);
}

function gassd_org(array $gallery, array $data): string
{
    $org = trim((string) ($data['profile']['organizer'] ?? ''));
    return $org !== '' ? $org : (string) $gallery['name'];
}

function gassd_commune(array $gallery, array $data): string
{
    $event = gass_event($gallery);
    $city = trim((string) ($event['city'] ?? ''));
    if ($city === '') $city = trim((string) ($data['profile']['commune'] ?? ''));
    return $city;
}

/** Lieu lisible : nom, adresse, code postal et ville. */
function gassd_place(array $gallery, array $data): string
{
    $event = gass_event($gallery);
    $parts = [];
    foreach (['place', 'address'] as $key) {
        $v = trim((string) ($event[$key] ?? ''));
        if ($v !== '') $parts[] = $v;
    }
    $line = trim((string) ($event['postcode'] ?? '') . ' ' . (string) ($event['city'] ?? ''));
    if ($line !== '') $parts[] = $line;
    if (!$parts) {
        $commune = trim((string) ($data['profile']['commune'] ?? ''));
        if ($commune !== '') $parts[] = $commune;
    }
    return implode(', ', $parts);
}

function gassd_kv(array $rows): string
{
    $html = '<table class="doc-table doc-kv"><tbody>';
    foreach ($rows as $label => $value) {
        $html .= '<tr><th>' . gass_h($label) . '</th><td>' . $value . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

function gassd_signature(array $gallery, array $data, string $role = 'L’organisateur'): string
{
    $commune = gassd_commune($gallery, $data);
    $where = $commune !== '' ? 'Fait à ' . gass_h($commune) : 'Fait à ' . gassd_todo('ville');
    return '<p class="doc-sign">' . $where . ', le ' . date('d/m/Y') . '<br><br>' . gass_h($role) . '<br><br><br>Signature et cachet</p>';
}

function gassd_sender(array $data): string
{
    $p = $data['profile'];
    $html = '<div class="doc-letterhead"><strong>' . gassd_or($p['organizer'], 'nom de l’organisateur') . '</strong>';
    foreach (['structure' => 'structure', 'contact' => 'responsable', 'email' => 'e-mail', 'phone' => 'téléphone'] as $key => $label) {
        $v = trim((string) ($p[$key] ?? ''));
        if ($v !== '') $html .= '<br>' . gass_h($v);
    }
    return $html . '</div>';
}

function gassd_recipient(string $line1, string $line2, string $line3 = ''): string
{
    return '<div class="doc-recipient">' . $line1 . ($line2 !== '' ? '<br>' . $line2 : '') . ($line3 !== '' ? '<br>' . $line3 : '') . '</div>';
}

/** Tableau du budget ; $withFinancing ajoute la répartition des financements. */
function gass_budget_html(array $data, bool $withFinancing = true): string
{
    $plan = gass_aid_plan($data);
    $html = '<table class="doc-table doc-budget"><thead><tr><th>Poste de dépense</th><th class="num">Montant</th></tr></thead><tbody>';
    foreach ($plan['lines'] as $line) {
        $html .= '<tr><td>' . gass_h($line['label']) . '</td><td class="num">' . gass_h(gass_eur((int) $line['amount_cents'])) . '</td></tr>';
    }
    if (!$plan['lines']) {
        $html .= '<tr><td>' . gassd_todo('poste de dépense') . '</td><td class="num">—</td></tr>';
    }
    $html .= '<tr class="total"><th>Total du projet</th><th class="num">' . gass_h(gass_eur($plan['total'])) . '</th></tr></tbody></table>';
    if ($withFinancing) {
        $html .= '<table class="doc-table doc-budget"><thead><tr><th>Financement</th><th class="num">Montant</th><th class="num">Part</th></tr></thead><tbody>';
        foreach ([['Aide demandée', $plan['requested']], ['Autofinancement', $plan['own']], ['Autres cofinancements', $plan['other']]] as [$label, $amount]) {
            $pct = $plan['total'] > 0 ? (int) round($amount / $plan['total'] * 100) : 0;
            $html .= '<tr><td>' . gass_h($label) . '</td><td class="num">' . gass_h(gass_eur($amount)) . '</td><td class="num">' . $pct . ' %</td></tr>';
        }
        $committedPct = $plan['total'] > 0 ? (int) round($plan['committed'] / $plan['total'] * 100) : 0;
        $html .= '<tr class="total"><th>Total financé</th><th class="num">' . gass_h(gass_eur($plan['committed'])) . '</th><th class="num">' . $committedPct . ' %</th></tr>';
        if ($plan['gap'] > 0) {
            $html .= '<tr class="doc-warn"><td>Reste à financer</td><td class="num">' . gass_h(gass_eur($plan['gap'])) . '</td><td class="num">—</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    return $html;
}

/** Feuille de style commune aux documents (aperçu et impression). */
function gassd_css(): string
{
    return <<<'CSS'
<style>
  .doc { max-width: 188mm; margin: 0 auto; padding: 10mm 0; color: #1b1b1b; font: 11.5pt/1.5 Georgia, "Times New Roman", serif; }
  .doc h1 { font: 700 20pt/1.2 "Schibsted Grotesk", Arial, sans-serif; margin: 0 0 4mm; letter-spacing: -.01em; }
  .doc h2 { font: 600 12.5pt/1.3 "Schibsted Grotesk", Arial, sans-serif; margin: 7mm 0 2mm; text-transform: uppercase; letter-spacing: .06em; }
  .doc h3 { font: 600 11.5pt/1.3 "Schibsted Grotesk", Arial, sans-serif; margin: 5mm 0 1.5mm; }
  .doc p { margin: 0 0 3mm; }
  .doc ul, .doc ol { margin: 0 0 3mm; padding-left: 6mm; }
  .doc li { margin: 0 0 1.4mm; }
  .doc-meta { font: 500 9.5pt/1.4 "IBM Plex Mono", monospace; color: #666; margin-bottom: 6mm; }
  .doc-letterhead { font-size: 10pt; line-height: 1.35; border-left: 2px solid #1b1b1b; padding-left: 4mm; margin: 0 0 7mm; }
  .doc-recipient { margin: 0 0 7mm 55%; font-size: 10.5pt; line-height: 1.4; }
  .doc-object { font-weight: 700; margin-bottom: 5mm; }
  .doc-table { width: 100%; border-collapse: collapse; margin: 0 0 5mm; font-size: 10pt; }
  .doc-table th, .doc-table td { border: 1px solid #c9c9c4; padding: 2mm 2.5mm; text-align: left; vertical-align: top; }
  .doc-table thead th { background: #f2f1ed; font: 600 8.5pt/1.2 "IBM Plex Mono", monospace; text-transform: uppercase; letter-spacing: .04em; }
  .doc-table .num { text-align: right; white-space: nowrap; }
  .doc-kv th { width: 34%; background: #f7f6f2; font-weight: 600; }
  .doc-budget tr.total th, .doc-budget tr.total td { background: #f2f1ed; border-top: 2px solid #1b1b1b; }
  .doc-warn td { background: #fff4e5; }
  .doc-todo { background: #fff3c4; outline: 1px dashed #c79a18; padding: 0 2px; font-family: "IBM Plex Mono", monospace; font-size: .82em; }
  .doc-note { font-size: 9pt; color: #555; border-top: 1px solid #c9c9c4; padding-top: 3mm; margin-top: 6mm; }
  .doc-sign { margin-top: 9mm; text-align: right; font-size: 10.5pt; }
  .doc-box { border: 1px solid #1b1b1b; padding: 4mm 5mm; margin: 0 0 5mm; background: #faf9f5; }
  .doc-box h3 { margin-top: 0; }
  .doc-risk { display: inline-block; padding: 1.5mm 4mm; border: 1px solid currentColor; font: 600 10pt/1 "IBM Plex Mono", monospace; text-transform: uppercase; letter-spacing: .05em; }
  .doc-risk.ok { color: #1d6b42; } .doc-risk.warn { color: #8a5606; } .doc-risk.danger { color: #9b1c1c; }
  .doc-press-head { font: 700 10pt/1 "IBM Plex Mono", monospace; letter-spacing: .18em; text-transform: uppercase; border-bottom: 2px solid #1b1b1b; padding-bottom: 2mm; margin-bottom: 6mm; }
  .doc-chapo { font-size: 12.5pt; line-height: 1.5; font-style: italic; margin-bottom: 5mm; }
  .doc-foot { font: 8.5pt/1.4 "IBM Plex Mono", monospace; color: #777; border-top: 1px solid #ddd; margin-top: 8mm; padding-top: 2mm; }
  @media print {
    .doc { max-width: none; padding: 0; }
    .doc-todo { background: transparent; outline: 0; border-bottom: 1px dotted #999; }
    .doc a { color: inherit; text-decoration: none; }
  }
</style>
CSS;
}

/**
 * Construit un document. Retourne ['kind','label','title','html','filename'] ou null pour une clé inconnue.
 * $gallery est la galerie chargée (gallery_load), $data les réglages de l'assistant (gass_read).
 */
function gass_document(string $kind, array $gallery, array $data): ?array
{
    $catalog = gass_document_catalog();
    if (!isset($catalog[$kind])) return null;

    $h = static fn ($v): string => gass_h($v);
    $event = gass_event($gallery);
    $typeName = (string) (gt_of($gallery)['name'] ?? 'manifestation');
    $title = $catalog[$kind]['label'];

    $p = $data['profile'];
    $org = gassd_org($gallery, $data);
    $structure = trim((string) ($p['structure'] ?? ''));
    $contactName = trim((string) ($p['contact'] ?? ''));
    $email = trim((string) ($p['email'] ?? ''));
    $phone = trim((string) ($p['phone'] ?? ''));
    $siret = trim((string) ($p['siret'] ?? ''));
    $region = trim((string) ($p['region'] ?? ''));
    $notes = trim((string) ($p['notes'] ?? ''));
    $attendance = (int) ($p['attendance'] ?? 0);
    $commune = gassd_commune($gallery, $data);
    $date = gass_target_date($gallery, $data);
    $dateText = $date !== '' ? gass_date_fr($date) : '';
    $when = $dateText !== '' ? 'le ' . $h($dateText) : 'le ' . gassd_todo('date de la manifestation');
    $hours = $event ? ge_hours_text($event) : '';
    $hoursText = $hours !== '' ? $h($hours) : gassd_todo('horaires');
    $placeText = gassd_place($gallery, $data);
    $where = $placeText !== '' ? $h($placeText) : gassd_todo('lieu précis');
    $places = $event ? ge_place_count($event) : 0;
    $price = $event ? ge_place_price($event) : null;
    $pricePro = $event ? ge_place_price($event, true) : null;
    $eventTitle = trim((string) ($event['title'] ?? '')) ?: (string) $gallery['name'];
    $security = gass_security_result((array) ($data['security']['answers'] ?? []));
    $rules = gcn_rules((array) ($data['rules'] ?? []));
    $surveyOn = !empty($data['survey']['enabled']) && !empty($data['survey']['open']);
    $surveyUrl = gass_survey_url((string) $gallery['slug']);
    $publicUrl = gallery_public_url($gallery);
    $sign = gassd_signature($gallery, $data);
    $sender = gassd_sender($data);
    $recipientCity = $commune !== '' ? $h($commune) : gassd_todo('commune');
    $footer = '<p class="doc-foot">Document généré par l’assistant d’organisation de ' . $h($gallery['name']) . ' le ' . date('d/m/Y') . '. Modèle à adapter avant envoi.</p>';

    switch ($kind) {
        // ── Demande d'autorisation à la mairie ─────────────────────────────
        case 'mairie':
            $title = 'Demande d’autorisation d’occupation du domaine public';
            $placesText = $places > 0 ? $places . ' emplacements numérotés' : gassd_todo('nombre d’emplacements');
            $attendanceText = $attendance > 0 ? number_format($attendance, 0, ',', ' ') . ' visiteurs attendus' : gassd_todo('fréquentation attendue');
            $securityText = $security['answered'] > 0
                ? 'Une notice de sécurité est jointe (niveau : ' . $h($security['level']) . '). Elle récapitule la jauge, les issues, les moyens de secours et les recommandations.'
                : 'Une notice de sécurité sera jointe après l’évaluation du site ; nous vous proposons une réunion de préparation avec les services compétents.';
            $html = '<h1>' . $h($title) . '</h1>';
            $html .= '<p class="doc-meta">Objet : organisation d’un ' . $h($typeName) . ' sur le domaine public communal — demande d’autorisation</p>';
            $html .= $sender;
            $html .= gassd_recipient('Mairie de ' . $recipientCity, 'À l’attention de Monsieur ou Madame le Maire', gassd_todo('adresse de la mairie'));
            $html .= '<p>Madame, Monsieur le Maire,</p>';
            $html .= '<p>Au nom de ' . gassd_or($org, 'nom de l’organisateur') . ', je vous adresse une demande d’autorisation pour organiser un ' . $h($typeName) . ' intitulé « ' . $h($eventTitle) . ' » ' . $when . ', à ' . $where . '.</p>';
            $html .= '<p>Cette manifestation rassemble des exposants particuliers et professionnels et accueille un public familial. Elle contribue à l’animation commerciale et à la vie locale de ' . $recipientCity . '.</p>';
            $html .= '<h2>Caractéristiques de la manifestation</h2>';
            $html .= gassd_kv([
                'Organisateur' => gassd_or($org, 'nom de l’organisateur'),
                'Structure' => $structure !== '' ? $h($structure) : gassd_todo('association, société ou collectif'),
                'Date' => $dateText !== '' ? $h($dateText) : gassd_todo('date'),
                'Horaires' => $hoursText,
                'Lieu' => $where,
                'Emplacements' => $placesText,
                'Fréquentation' => $attendanceText,
                'Nature' => $h($typeName),
            ]);
            $html .= '<h2>Dispositions que nous prenons</h2><ul>';
            $html .= '<li><strong>Sécurité :</strong> ' . $securityText . '</li>';
            $html .= '<li><strong>Secours :</strong> un point d’appel et un référent sécurité seront désignés ; les accès pompiers resteront dégagés pendant toute la manifestation.</li>';
            $html .= '<li><strong>Assurance :</strong> une attestation de responsabilité civile organisateur vous sera transmise avant l’événement.</li>';
            $html .= '<li><strong>Propreté :</strong> la zone sera nettoyée après le démontage et les déchets évacués selon les consignes du service technique.</li>';
            $html .= '<li><strong>Information :</strong> les riverains et les commerces seront prévenus du stationnement et de la circulation modifiée.</li>';
            $html .= '</ul>';
            $html .= '<p>Je vous remercie de bien vouloir m’indiquer les pièces à fournir, le montant de la redevance éventuelle et les conditions d’occupation. Je sollicite également la délivrance de l’arrêté municipal autorisant la manifestation, ainsi que, si nécessaire, l’arrêté de circulation et de stationnement.</p>';
            $html .= '<p>Je reste à votre disposition pour présenter le dossier et ajuster le dispositif aux règles locales.</p>';
            $html .= $sign;
            $html .= '<h2>Pièces jointes</h2><ol>';
            $html .= '<li>Présentation de l’organisateur (statuts ou extrait SIRET) ;</li>';
            $html .= '<li>Notice de sécurité et plan d’implantation ;</li>';
            $html .= '<li>Règlement intérieur destiné aux exposants ;</li>';
            $html .= '<li>Attestation d’assurance responsabilité civile ;</li>';
            $html .= '<li>Fiche de présentation de la manifestation.</li>';
            $html .= '</ol>';
            $html .= $footer;
            break;

        // ── Notice de sécurité ─────────────────────────────────────────────
        case 'securite':
            $title = 'Notice de sécurité';
            $html = '<h1>' . $h($title) . '</h1>';
            $html .= '<p class="doc-meta">' . $h($eventTitle) . ' — ' . ($dateText !== '' ? $h($dateText) : gassd_todo('date')) . ' — ' . $where . '</p>';
            $html .= '<h2>1. Identification</h2>';
            $html .= gassd_kv([
                'Organisateur' => gassd_or($org, 'nom de l’organisateur'),
                'Responsable sécurité' => $contactName !== '' ? $h($contactName) : gassd_todo('nom et téléphone du référent sécurité'),
                'Lieu' => $where,
                'Date et horaires' => ($dateText !== '' ? $h($dateText) : gassd_todo('date')) . ' — ' . $hoursText,
                'Jauge attendue' => $attendance > 0 ? number_format($attendance, 0, ',', ' ') . ' personnes' : gassd_todo('jauge attendue'),
                'Emplacements' => $places > 0 ? $places . ' emplacements numérotés' : gassd_todo('nombre d’emplacements'),
            ]);
            $html .= '<h2>2. Analyse des risques</h2>';
            if ($security['rows']) {
                $html .= '<table class="doc-table"><thead><tr><th>Point vérifié</th><th>Réponse</th><th>Niveau</th></tr></thead><tbody>';
                foreach ($security['rows'] as $row) {
                    $risk = (int) $row['risk'];
                    $riskLabel = $risk === 0 ? 'Satisfaisant' : ($risk === 1 ? 'À surveiller' : 'À corriger');
                    $html .= '<tr><td>' . $h($row['label']) . '</td><td>' . $h($row['answer']) . '</td><td>' . $riskLabel . '</td></tr>';
                }
                $html .= '</tbody></table>';
            } else {
                $html .= '<p>' . gassd_todo('questionnaire de sécurité à remplir dans l’assistant') . '</p>';
            }
            $html .= '<p><span class="doc-risk ' . $h($security['tone']) . '">' . $h($security['label']) . '</span> ';
            $html .= '(' . (int) $security['answered'] . '/' . (int) $security['total'] . ' questions renseignées, score de risque ' . (int) $security['score'] . '/' . (int) $security['max'] . ')</p>';
            $html .= '<h2>3. Recommandations</h2>';
            if ($security['recommendations']) {
                $html .= '<ul>';
                foreach ($security['recommendations'] as $advice) $html .= '<li>' . $h($advice) . '</li>';
                $html .= '</ul>';
            } else {
                $html .= '<p>Compléter le questionnaire pour obtenir des recommandations adaptées au site.</p>';
            }
            $html .= '<h2>4. Dispositif prévu le jour J</h2><ul>';
            $html .= '<li>Un référent sécurité unique, joignable en permanence, coordonne les bénévoles et les secours.</li>';
            $html .= '<li>Les issues de secours restent dégagées et signalées ; aucun stand ne peut les obstruer.</li>';
            $html .= '<li>Les extincteurs sont repérés sur le plan et accessibles ; les bénévoles savent les utiliser.</li>';
            $html .= '<li>Un point de rassemblement est défini et communiqué aux exposants.</li>';
            $html .= '<li>Les véhicules de secours disposent d’un accès libre jusqu’au poste de secours.</li>';
            $html .= '</ul>';
            if ($security['missing']) {
                $html .= '<h2>5. Points à compléter</h2><ul>';
                foreach ($security['missing'] as $label) $html .= '<li>' . $h($label) . '</li>';
                $html .= '</ul>';
            }
            $html .= '<p class="doc-note">Cette notice est un document de travail destiné à préparer la manifestation. Elle ne vaut pas avis de la commission de sécurité ni autorisation d’ouverture : vérifiez auprès de la mairie, du SDIS et, le cas échéant, de la préfecture les obligations applicables à votre manifestation.</p>';
            $html .= $footer;
            break;

        // ── Règlement intérieur ────────────────────────────────────────────
        case 'reglement':
            $title = 'Règlement intérieur — exposants';
            $tarif = $price !== null ? gass_eur($price) : gassd_todo('tarif d’un emplacement');
            $tarifPro = $pricePro !== null && $pricePro !== $price ? gass_eur($pricePro) : '';
            $html = '<h1>' . $h($title) . '</h1>';
            $html .= '<p class="doc-meta">' . $h($eventTitle) . ' — ' . ($dateText !== '' ? $h($dateText) : gassd_todo('date')) . ' — ' . $where . '</p>';
            $html .= '<p><strong>Article 1 — Objet.</strong> Le présent règlement fixe les conditions de participation au ' . $h($typeName) . ' « ' . $h($eventTitle) . ' », organisé par ' . gassd_or($org, 'nom de l’organisateur') . ' ' . $when . ' à ' . $where . '. Toute inscription vaut acceptation sans réserve de ce règlement.</p>';
            $html .= '<p><strong>Article 2 — Inscription.</strong> L’inscription se fait auprès de l’organisateur, par le formulaire en ligne ou par courrier. Elle n’est définitive qu’après validation par l’organisateur et règlement de l’emplacement. Le nombre d’emplacements est limité ; les demandes sont traitées dans l’ordre de réception.</p>';
            $html .= '<p><strong>Article 3 — Tarifs.</strong> Le tarif d’un emplacement est de <strong>' . $tarif . '</strong>' . ($tarifPro !== '' ? ', et de <strong>' . $tarifPro . '</strong> pour les professionnels' : '') . '. Le paiement peut être effectué en ligne ou sur place selon les modalités indiquées lors de l’inscription. L’absence de l’exposant le jour de la manifestation ne donne pas lieu à remboursement, sauf décision de l’organisateur ; les annulations et les désistements sont régis par l’article 15.</p>';
            $html .= '<p><strong>Article 4 — Emplacements.</strong> Les emplacements sont numérotés et attribués nominativement. Ils ne peuvent être ni cédés, ni revendus, ni partagés sans accord écrit de l’organisateur. L’exposant installe son stand dans les limites de l’emplacement qui lui a été attribué et laisse les allées libres.</p>';
            $html .= '<p><strong>Article 5 — Horaires.</strong> Installation à partir de ' . gassd_todo('heure d’installation') . ', ouverture au public ' . $hoursText . ', démontage après la fermeture et au plus tard à ' . gassd_todo('heure de fin de démontage') . '. Les véhicules doivent avoir quitté la zone de vente avant l’ouverture au public.</p>';
            $html .= '<p><strong>Article 6 — Véhicules.</strong> Le stationnement sur les emplacements est interdit pendant la manifestation, sauf autorisation. Les exposants utilisent les parkings indiqués par l’organisateur et respectent les zones de déchargement.</p>';
            $html .= '<p><strong>Article 7 — Sécurité.</strong> Les allées, issues de secours et accès pompiers doivent rester dégagés en permanence. Les barnums et tentes sont lestés. Les exposants suivent les consignes des bénévoles et du référent sécurité. Aucun feu, barbecue, produit inflammable ou bouteille de gaz n’est autorisé sans accord préalable.</p>';
            $html .= '<p><strong>Article 8 — Électricité.</strong> Aucun branchement improvisé n’est autorisé. Les rallonges ne doivent pas traverser les allées au sol. Les appareils électriques utilisés doivent être en bon état ; l’organisateur peut refuser un branchement dangereux.</p>';
            $html .= '<p><strong>Article 9 — Propreté.</strong> Chaque exposant maintient son emplacement propre et évacue ses déchets dans les contenants prévus. La zone doit être rendue dans l’état où elle a été trouvée.</p>';
            $html .= '<p><strong>Article 10 — Objets et denrées.</strong> La vente d’armes, de munitions, de produits illicites, de contrefaçons ou de denrées alimentaires non conformes est interdite. La vente de denrées alimentaires est soumise à l’autorisation de l’organisateur et au respect de la réglementation sanitaire. Les animaux ne sont admis que dans le respect des règles de sécurité et de bien-être.</p>';
            $html .= '<p><strong>Article 11 — Assurances.</strong> L’exposant doit être couvert par une assurance responsabilité civile pour son activité et ses biens. L’organisateur décline toute responsabilité en cas de vol, de perte ou de détérioration du matériel exposé.</p>';
            $html .= '<p><strong>Article 12 — Contrôle et sanctions.</strong> L’organisateur peut refuser l’accès ou faire évacuer un stand en cas de non-respect du règlement, de trouble à l’ordre public ou de danger pour le public. Aucun remboursement n’est alors dû.</p>';
            $html .= '<p><strong>Article 13 — Données personnelles.</strong> Les informations transmises lors de l’inscription sont utilisées uniquement pour l’organisation de la manifestation et la tenue du registre des exposants. Elles ne sont ni vendues ni transmises à des tiers. Chaque exposant peut demander leur rectification ou leur suppression.</p>';
            $html .= '<p><strong>Article 14 — Litiges.</strong> En cas de difficulté d’interprétation, l’organisateur tranche après avis du responsable sécurité. Les participants s’engagent à rechercher une solution amiable avant toute action contentieuse.</p>';
            $refundSentence = static fn (int $p): string => $p >= 100
                ? 'l’exposant est remboursé intégralement'
                : ($p <= 0 ? 'aucun remboursement n’est dû' : 'l’exposant est remboursé à hauteur de ' . $p . ' % du tarif');
            $html .= '<p><strong>Article 15 — Annulation et remboursement.</strong> '
                . 'En cas d’annulation décidée par l’organisateur, ' . $refundSentence((int) $rules['organizer_pct']) . '. '
                . 'En cas d’annulation pour météo ou force majeure, ' . $refundSentence((int) $rules['force_majeure_pct']) . '. '
                . 'Si l’exposant se désiste plus de ' . (int) $rules['full_days'] . ' jours avant la manifestation, il est remboursé intégralement ; '
                . 'entre ' . (int) $rules['partial_days'] . ' et ' . (int) $rules['full_days'] . ' jours avant, ' . $refundSentence((int) $rules['partial_pct']) . ' ; '
                . 'moins de ' . (int) $rules['partial_days'] . ' jours avant, ' . $refundSentence((int) $rules['late_pct']) . '. '
                . 'Les sommes sont versées dans un délai de ' . (int) $rules['refund_delay_days'] . ' jours après l’annonce, par ' . gass_h($rules['refund_method']) . '. '
                . (trim((string) $rules['custom']) !== '' ? 'Condition particulière : ' . gass_h($rules['custom']) . '. ' : '')
                . 'Aucun remboursement n’est dû en cas d’exclusion prononcée en application de l’article 12. '
                . 'Pour demander un remboursement, l’exposant adresse sa demande à l’organisateur en rappelant son nom et son emplacement.</p>';
            $html .= '<p class="doc-sign">Lu et approuvé, le ' . gassd_todo('date') . '<br><br>Nom de l’exposant : ' . gassd_todo('nom') . '<br><br>Signature :</p>';
            $html .= $footer;
            break;

        // ── Convention avec la commune ────────────────────────────────────
        case 'convention':
            $title = 'Convention d’occupation et de partenariat';
            $html = '<h1>' . $h($title) . '</h1>';
            $html .= '<p class="doc-meta">Modèle à adapter au règlement local et au régime de responsabilité de la commune.</p>';
            $html .= '<p><strong>Entre :</strong> la commune de ' . $recipientCity . ', représentée par ' . gassd_todo('nom et qualité du signataire') . ', ci-après « la Commune »,</p>';
            $html .= '<p><strong>Et :</strong> ' . gassd_or($org, 'nom de l’organisateur') . ($structure !== '' ? ', ' . $h($structure) : '') . ', représentée par ' . gassd_or($contactName, 'nom du représentant') . ', ci-après « l’Organisateur »,</p>';
            $html .= '<h2>Article 1 — Objet</h2><p>La Commune autorise l’Organisateur à occuper le domaine public pour y tenir le ' . $h($typeName) . ' « ' . $h($eventTitle) . ' » ' . $when . ', à ' . $where . '. La présente convention définit les conditions de cette occupation et les obligations réciproques des parties.</p>';
            $html .= '<h2>Article 2 — Durée et horaires</h2><p>L’occupation est autorisée ' . $when . ', de ' . gassd_todo('heure de début d’installation') . ' à ' . gassd_todo('heure de fin de démontage') . '. L’ouverture au public est prévue ' . $hoursText . '.</p>';
            $html .= '<h2>Article 3 — Emplacement</h2><p>La surface mise à disposition est située ' . $where . '. Elle comprend ' . ($places > 0 ? $places . ' emplacements numérotés' : gassd_todo('nombre d’emplacements')) . '. L’Organisateur s’interdit toute occupation en dehors de cette zone.</p>';
            $html .= '<h2>Article 4 — Conditions financières</h2><p>L’occupation est consentie moyennant une redevance de ' . gassd_todo('montant de la redevance') . ', payable selon les modalités fixées par la Commune. Les recettes d’inscription des exposants restent acquises à l’Organisateur, qui supporte les frais d’organisation.</p>';
            $html .= '<h2>Article 5 — Obligations de l’Organisateur</h2><ul>';
            $html .= '<li>Tenir la manifestation aux dates et horaires convenus et signaler toute modification ;</li>';
            $html .= '<li>Fournir une notice de sécurité et un plan d’implantation avant l’événement ;</li>';
            $html .= '<li>Désigner un référent sécurité et assurer la coordination avec les services de secours ;</li>';
            $html .= '<li>Maintenir les accès pompiers et les issues de secours dégagés ;</li>';
            $html .= '<li>Nettoyer le site après le démontage et évacuer les déchets ;</li>';
            $html .= '<li>Respecter les consignes de circulation et de stationnement de la Commune ;</li>';
            $html .= '<li>Justifier d’une assurance responsabilité civile couvrant l’événement.</li>';
            $html .= '</ul>';
            $html .= '<h2>Article 6 — Obligations de la Commune</h2><p>La Commune met à disposition l’emplacement convenu, délivre les autorisations dont elle est compétente et informe l’Organisateur des contraintes locales connues (voirie, travaux, autres manifestations).</p>';
            $html .= '<h2>Article 7 — Sécurité et responsabilité</h2><p>L’Organisateur est responsable du déroulement de la manifestation et de la sécurité du public dans la zone occupée, dans les conditions prévues par la réglementation et la présente convention. La Commune conserve ses pouvoirs de police. Chaque partie assume la responsabilité de ses propres fautes.</p>';
            $html .= '<h2>Article 8 — Assurance</h2><p>L’Organisateur produit avant le début de l’occupation une attestation d’assurance responsabilité civile organisateur couvrant les dommages corporels, matériels et immatériels causés à des tiers.</p>';
            $html .= '<h2>Article 9 — Communication</h2><p>Les supports de communication mentionnent la Commune comme partenaire selon les modalités convenues. L’utilisation des logos respecte les chartes graphiques respectives.</p>';
            $html .= '<h2>Article 10 — Résiliation</h2><p>En cas de manquement grave, de danger pour le public ou de force majeure, chaque partie peut mettre fin à la convention après information écrite. Les frais déjà engagés restent à la charge de la partie qui les a supportés.</p>';
            $html .= '<h2>Article 11 — Litiges</h2><p>Les parties recherchent une solution amiable avant toute action contentieuse. À défaut, le tribunal compétent est celui du lieu de la manifestation.</p>';
            $html .= '<table class="doc-table"><thead><tr><th>Pour la Commune</th><th>Pour l’Organisateur</th></tr></thead><tbody><tr><td>Nom et qualité :<br><br><br><br>Date et signature :</td><td>Nom et qualité :<br><br><br><br>Date et signature :</td></tr></tbody></table>';
            $html .= $footer;
            break;

        // ── Courrier aux anciens exposants ────────────────────────────────
        case 'exposants':
            $title = 'Courrier aux anciens exposants';
            $link = $surveyOn ? $surveyUrl : $publicUrl;
            $html = '<h1>' . $h($eventTitle) . '</h1>';
            $html .= '<p class="doc-meta">Message aux exposants des éditions précédentes</p>';
            $html .= '<p>Bonjour,</p>';
            $html .= '<p>Nous préparons la prochaine édition du ' . $h($typeName) . ' « ' . $h($eventTitle) . ' », prévue ' . $when . ' à ' . $where . ', et nous pensons à vous.</p>';
            $html .= '<p>Pour choisir la date la plus pratique, ajuster le tarif des emplacements et améliorer les services (parking, restauration, aide au déchargement, paiement en ligne…), nous avons besoin de votre avis. Deux minutes suffisent :</p>';
            $html .= '<p><a href="' . $h($link) . '">' . $h($link) . '</a></p>';
            $html .= '<p>Vos réponses nous permettent de réserver les emplacements dans les meilleures conditions et de vous prévenir en priorité dès l’ouverture des inscriptions.</p>';
            if ($notes !== '') $html .= '<p>' . nl2br($h($notes)) . '</p>';
            $html .= '<p>Merci pour votre fidélité, et à très bientôt.</p>';
            $html .= '<p>' . gassd_or($contactName ?: $org, 'nom du contact') . ($phone !== '' ? '<br>' . $h($phone) : '') . ($email !== '' ? '<br>' . $h($email) : '') . '</p>';
            $html .= '<p class="doc-note">Vous recevez ce message parce que vous avez exposé lors d’une précédente édition. Pour ne plus être contacté, répondez « STOP » à ce message.</p>';
            $html .= $footer;
            break;

        // ── Invitation au sondage ─────────────────────────────────────────
        case 'sondage':
            $title = 'Invitation au sondage';
            $html = '<h1>' . $h($data['survey']['title']) . '</h1>';
            $html .= '<p class="doc-meta">Message court à envoyer aux prospects — ' . $h($dateText ?: 'date à préciser') . '</p>';
            $html .= '<p>Bonjour,</p>';
            $html .= '<p>Nous organisons le ' . $h($typeName) . ' « ' . $h($eventTitle) . ' » ' . $when . ' à ' . $where . '. Avant d’ouvrir les inscriptions, nous souhaitons connaître vos attentes.</p>';
            $html .= '<p><strong>Répondez au sondage (2 minutes) :</strong><br><a href="' . $h($surveyUrl) . '">' . $h($surveyUrl) . '</a></p>';
            $html .= '<p>Date, tarif, services : vos réponses nous aident à construire une édition qui vous convient. Les résultats sont utilisés uniquement pour l’organisation de l’événement.</p>';
            $html .= '<p>Merci de votre participation.</p>';
            $html .= '<p>' . gassd_or($contactName ?: $org, 'nom du contact') . ($email !== '' ? '<br>' . $h($email) : '') . '</p>';
            $html .= '<p class="doc-note">Vous recevez ce message parce que vous êtes en relation avec l’organisation. Pour ne plus être contacté, répondez « STOP ».</p>';
            $html .= $footer;
            break;

        // ── Communiqué de presse ──────────────────────────────────────────
        case 'presse':
            $title = 'Communiqué de presse';
            $dateLabel = $dateText !== '' ? $dateText : gassd_todo('date');
            $html = '<div class="doc-press-head">Communiqué de presse</div>';
            $html .= '<h1>' . $h($eventTitle) . ' : ' . $h($typeName) . ' à ' . $recipientCity . '</h1>';
            $html .= '<p class="doc-meta">' . $recipientCity . ', le ' . date('d/m/Y') . '</p>';
            $html .= '<p class="doc-chapo">' . gassd_or($org, 'nom de l’organisateur') . ' organise le ' . $h($typeName) . ' « ' . $h($eventTitle) . ' » le ' . $dateLabel . ' à ' . $where . '. Une journée de découvertes, de rencontres et de bonnes affaires, ouverte à tous.</p>';
            $html .= '<p>Le public y trouvera ' . ($places > 0 ? $places . ' exposants' : 'des exposants particuliers et professionnels') . ' réunis sur un même site : objets anciens, mobilier, livres, disques, vêtements, artisanat et pièces insolites. L’entrée est ' . gassd_todo('gratuite ou payante') . '.</p>';
            if ($attendance > 0) {
                $html .= '<p>Avec une fréquentation attendue de ' . number_format($attendance, 0, ',', ' ') . ' visiteurs, l’événement s’inscrit dans la vie commerciale et associative de ' . $recipientCity . '.</p>';
            }
            $html .= '<div class="doc-box"><h3>Informations pratiques</h3>';
            $html .= '<p><strong>Quand :</strong> ' . $dateLabel . ', ' . $hoursText . '<br>';
            $html .= '<strong>Où :</strong> ' . $where . '<br>';
            $html .= '<strong>Entrée :</strong> ' . gassd_todo('gratuite ou tarif') . '<br>';
            $html .= '<strong>Organisateur :</strong> ' . gassd_or($org, 'nom') . '</p></div>';
            if ((string) ($gallery['description'] ?? '') !== '') {
                $html .= '<p>' . nl2br($h($gallery['description'])) . '</p>';
            }
            $html .= '<p><strong>À propos de ' . gassd_or($org, 'l’organisateur') . '</strong><br>' . gassd_or($structure, 'présentation de la structure') . '. ' . ($email !== '' ? 'Contact : ' . $h($email) : '') . '</p>';
            $html .= '<p><strong>Contact presse</strong><br>' . gassd_or($contactName ?: $org, 'nom du contact presse') . ($phone !== '' ? ' — ' . $h($phone) : '') . ($email !== '' ? ' — ' . $h($email) : '') . '</p>';
            $html .= '<p class="doc-note">Photos et visuels disponibles sur demande. Merci de nous signaler toute publication.</p>';
            $html .= $footer;
            break;

        // ── Briefing des bénévoles ────────────────────────────────────────
        case 'benevoles':
            $title = 'Briefing des bénévoles';
            $html = '<h1>' . $h($title) . '</h1>';
            $html .= '<p class="doc-meta">' . $h($eventTitle) . ' — ' . ($dateText !== '' ? $h($dateText) : gassd_todo('date')) . ' — ' . $where . '</p>';
            $html .= '<p><strong>Référent sécurité :</strong> ' . gassd_or($contactName, 'nom et téléphone') . '<br>';
            $html .= '<strong>Contact organisation :</strong> ' . ($phone !== '' ? $h($phone) : gassd_todo('téléphone')) . ' — ' . ($email !== '' ? $h($email) : gassd_todo('e-mail')) . '</p>';
            $html .= '<h2>Missions de la journée</h2>';
            $html .= '<table class="doc-table"><thead><tr><th>Créneau</th><th>Mission</th><th>Qui</th></tr></thead><tbody>';
            $rows = [
                ['6 h 30 – 8 h 30', 'Installation : montage des barnums, fléchage, vérification des issues et des extincteurs.', gassd_todo('équipe installation')],
                ['8 h 30 – 10 h', 'Accueil des exposants, vérification des inscriptions, attribution des emplacements.', gassd_todo('accueil')],
                ['10 h – 12 h', 'Ouverture au public, circulation, renseignements, point d’appel.', gassd_todo('binôme accueil')],
                ['12 h – 14 h', 'Relève, surveillance des allées et des accès secours, gestion des objets trouvés.', gassd_todo('relève')],
                ['14 h – 17 h', 'Après-midi, animation, contrôle des issues, assistance au public.', gassd_todo('équipe')],
                ['17 h – 18 h', 'Fermeture, fin des ventes, accompagnement des exposants vers la sortie.', gassd_todo('équipe')],
                ['18 h – 20 h', 'Démontage, nettoyage, inventaire du matériel et des objets trouvés.', gassd_todo('équipe démontage')],
            ];
            foreach ($rows as [$slot, $mission, $who]) {
                $html .= '<tr><td>' . gass_h($slot) . '</td><td>' . gass_h($mission) . '</td><td>' . $who . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<h2>Consignes de sécurité</h2><ul>';
            $html .= '<li>Connaître les issues de secours, le point de rassemblement et l’emplacement des extincteurs.</li>';
            $html .= '<li>Ne jamais encombrer une issue ou un accès pompiers, même quelques minutes.</li>';
            $html .= '<li>En cas d’incident : alerter le référent sécurité, appeler le 18 ou le 112, ne pas déplacer un blessé sauf danger immédiat.</li>';
            $html .= '<li>Enfant perdu : conduire l’enfant au point d’accueil et prévenir le référent ; ne pas diffuser d’information sans accord.</li>';
            $html .= '<li>Objet trouvé : le déposer au point d’accueil avec l’heure et le lieu de découverte.</li>';
            $html .= '<li>Litige entre exposants ou avec un visiteur : rester calme, ne pas intervenir seul, appeler le référent.</li>';
            $html .= '</ul>';
            $html .= '<h2>Contacts du jour</h2>';
            $html .= '<table class="doc-table"><thead><tr><th>Rôle</th><th>Nom</th><th>Téléphone</th></tr></thead><tbody>';
            foreach (['Référent sécurité', 'Accueil exposants', 'Secours / protection civile', 'Mairie', 'Police ou gendarmerie'] as $role) {
                $html .= '<tr><td>' . gass_h($role) . '</td><td>' . gassd_todo('nom') . '</td><td>' . gassd_todo('téléphone') . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<p class="doc-note">Chaque bénévole reçoit ce briefing avant la manifestation et signe la feuille de présence. En cas de doute, appliquer la consigne la plus prudente.</p>';
            $html .= $footer;
            break;

        // ── Rétroplanning ─────────────────────────────────────────────────
        case 'planning':
            $title = 'Rétroplanning';
            $planning = gass_planning($gallery, $data);
            $html = '<h1>' . $h($title) . '</h1>';
            $html .= '<p class="doc-meta">' . ($dateText !== '' ? 'Manifestation le ' . $h($dateText) : 'Définissez la date dans l’assistant pour calculer le rétroplanning') . '</p>';
            if (!$planning) {
                $html .= '<p>Aucune date de travail n’est définie. Renseignez la date de l’événement ou la date visée dans l’assistant : le rétroplanning sera calculé automatiquement de J-120 à J+7.</p>';
            } else {
                $html .= '<table class="doc-table"><thead><tr><th>Échéance</th><th>Date</th><th>Tâche</th><th>Détail</th><th>État</th></tr></thead><tbody>';
                foreach ($planning as $task) {
                    $state = $task['done'] ? 'Fait' : ($task['status'] === 'late' ? 'En retard' : ($task['status'] === 'soon' ? 'À venir' : 'Planifié'));
                    $html .= '<tr><td>' . $h($task['when']) . '</td><td>' . $h($task['date_text']) . '</td><td><strong>' . $h($task['title']) . '</strong></td><td>' . $h($task['detail']) . '</td><td>' . $state . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
            $html .= '<p class="doc-note">Les délais sont indicatifs : la demande en mairie et la coordination avec les secours doivent être anticipées selon les règles locales et la taille de la manifestation.</p>';
            $html .= $footer;
            break;

        // ── Bilan ─────────────────────────────────────────────────────────
        case 'bilan':
            $title = 'Bilan d’organisation';
            $rows = gb_rows($gallery);
            $evRows = array_values(array_filter($rows, static fn ($r) => (bool) preg_match('/^ev[0-9]{1,4}$/', (string) ($r['slot_id'] ?? ''))));
            $taken = count(array_filter($evRows, static fn ($r) => ($r['status'] ?? '') === 'taken'));
            $pending = count(array_filter($evRows, static fn ($r) => ($r['status'] ?? '') === 'pending' && empty($r['expired'])));
            $revenue = array_sum(array_map(static fn ($r) => (int) ($r['price_cents'] ?? 0), array_filter($evRows, static fn ($r) => ($r['status'] ?? '') === 'taken')));
            $survey = gass_survey_results((string) $gallery['slug'], $data);
            $html = '<h1>' . $h($title) . '</h1>';
            $html .= '<p class="doc-meta">' . $h($eventTitle) . ' — ' . ($dateText !== '' ? $h($dateText) : gassd_todo('date')) . ' — ' . $where . '</p>';
            $html .= '<h2>Chiffres clés</h2>';
            $html .= gassd_kv([
                'Emplacements' => $places > 0 ? (string) $places : gassd_todo('nombre'),
                'Attribués' => (string) $taken,
                'En attente' => (string) $pending,
                'Recettes d’emplacements' => gass_eur($revenue),
                'Fréquentation estimée' => $attendance > 0 ? number_format($attendance, 0, ',', ' ') . ' visiteurs' : gassd_todo('nombre de visiteurs'),
                'Réponses au sondage' => (string) $survey['total'],
            ]);
            $html .= '<table class="doc-table"><thead><tr><th>Poste</th><th class="num">Montant</th></tr></thead><tbody>';
            foreach (['Recettes d’inscriptions', 'Recettes buvette / restauration', 'Subventions et partenariats', 'Dépenses de sécurité', 'Dépenses de communication', 'Autres dépenses'] as $line) {
                $html .= '<tr><td>' . gass_h($line) . '</td><td class="num">' . gassd_todo('montant') . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<h2>Incidents et points de vigilance</h2>';
            $html .= '<p>' . gassd_todo('incidents, retards, problèmes de sécurité ou de propreté, litiges') . '</p>';
            $html .= '<h2>Satisfaction des exposants</h2>';
            if ($survey['total'] === 0) {
                $html .= '<p>Aucune réponse au sondage pour cette édition.</p>';
            } else {
                $html .= '<p>' . (int) $survey['total'] . ' réponse(s), dont ' . (int) $survey['emails'] . ' avec une adresse de contact.</p>';
                foreach (array_slice($survey['questions'], 0, 4) as $question) {
                    if ($question['type'] === 'textarea') {
                        $html .= '<p><strong>' . $h($question['label']) . '</strong> — ' . count($question['comments']) . ' commentaire(s).</p>';
                        continue;
                    }
                    $html .= '<p><strong>' . $h($question['label']) . '</strong></p><ul>';
                    foreach ($question['options'] as $option) {
                        $html .= '<li>' . $h($option['label']) . ' : ' . (int) $option['count'] . '</li>';
                    }
                    $html .= '</ul>';
                }
            }
            $html .= '<h2>Décisions pour l’édition suivante</h2><ul>';
            $html .= '<li>' . gassd_todo('date retenue') . '</li>';
            $html .= '<li>' . gassd_todo('tarif et services') . '</li>';
            $html .= '<li>' . gassd_todo('communication') . '</li>';
            $html .= '<li>' . gassd_todo('sécurité et logistique') . '</li>';
            $html .= '</ul>';
            $html .= $footer;
            break;

        // ── Plan de financement ───────────────────────────────────────────
        case 'budget':
            $title = 'Plan de financement — numérisation';
            $plan = gass_aid_plan($data);
            $html = '<h1>' . $h($title) . '</h1>';
            $html .= '<p class="doc-meta">' . gassd_or($org, 'nom de l’organisateur') . ' — ' . gassd_or($data['aid']['project'], 'intitulé du projet') . '</p>';
            if (trim((string) $data['aid']['needs']) !== '') {
                $html .= '<h2>Le projet</h2><p>' . nl2br($h($data['aid']['needs'])) . '</p>';
            }
            $html .= '<h2>Budget prévisionnel</h2>';
            $html .= gass_budget_html($data);
            if ($plan['gap'] > 0) {
                $html .= '<p><strong>Reste à financer : ' . $h(gass_eur($plan['gap'])) . '.</strong> Ce montant doit être couvert par un autofinancement complémentaire ou une autre aide avant le lancement du projet.</p>';
            }
            $html .= '<h2>Pièces à joindre</h2><ul>';
            $html .= '<li>Devis détaillés des prestataires ou fournisseurs ;</li>';
            $html .= '<li>Présentation de la structure et de son projet ;</li>';
            $html .= '<li>Compte de résultat ou budget prévisionnel de l’exercice ;</li>';
            $html .= '<li>Attestation de vigilance et extrait SIRET, selon le financeur.</li>';
            $html .= '</ul>';
            $html .= '<p class="doc-note">Les montants saisis sont indicatifs. Les taux d’intervention, plafonds et pièces demandées varient selon la commune, la région et le dispositif mobilisé : vérifiez le règlement d’intervention avant dépôt.</p>';
            $html .= $footer;
            break;

        // ── Demande d'aide à la commune ───────────────────────────────────
        case 'aide-commune':
            $title = 'Demande de soutien — commune';
            $plan = gass_aid_plan($data);
            $requested = $plan['requested'] > 0 ? $h(gass_eur($plan['requested'])) : gassd_todo('montant sollicité');
            $html = '<h1>Demande de soutien à la numérisation</h1>';
            $html .= '<p class="doc-meta">Objet : soutien financier à la numérisation de la galerie commerciale</p>';
            $html .= $sender;
            $html .= gassd_recipient('Mairie de ' . $recipientCity, 'À l’attention de Monsieur ou Madame le Maire', gassd_todo('adresse de la mairie'));
            $html .= '<p>Madame, Monsieur le Maire,</p>';
            $html .= '<p>' . gassd_or($org, 'nom de l’organisateur') . ' anime la galerie commerciale ' . $h($gallery['name']) . ' et souhaite engager sa numérisation : vitrine en ligne, réservation des emplacements, paiement sécurisé, communication et suivi de fréquentation.</p>';
            if (trim((string) $data['aid']['project']) !== '') {
                $html .= '<p><strong>Projet :</strong> ' . $h($data['aid']['project']) . '</p>';
            }
            if (trim((string) $data['aid']['needs']) !== '') {
                $html .= '<p>' . nl2br($h($data['aid']['needs'])) . '</p>';
            }
            $html .= '<h2>Budget et financement</h2>';
            $html .= gass_budget_html($data);
            $html .= '<p>Sur un budget total de ' . $h(gass_eur($plan['total'])) . ', nous sollicitons une aide de <strong>' . $requested . '</strong>. Le reste est financé par ' . $h(gass_eur($plan['own'])) . ' d’autofinancement et ' . $h(gass_eur($plan['other'])) . ' d’autres cofinancements.</p>';
            $html .= '<h2>Intérêt pour la commune</h2><ul>';
            $html .= '<li>Une vitrine en ligne qui rend les commerces et les événements locaux visibles toute l’année ;</li>';
            $html .= '<li>Des inscriptions et des paiements dématérialisés, moins de gestion manuelle et moins d’impayés ;</li>';
            $html .= '<li>Des données de fréquentation utiles pour piloter l’animation commerciale ;</li>';
            $html .= '<li>Un service accessible aux habitants et aux visiteurs, y compris depuis un mobile.</li>';
            $html .= '</ul>';
            $html .= '<p>Nous serions heureux de vous présenter le projet et de vous transmettre les devis. Nous restons à votre disposition pour adapter la demande au règlement d’intervention de la commune.</p>';
            $html .= $sign;
            $html .= '<h2>Pièces jointes</h2><ol><li>Présentation de la structure ;</li><li>Budget prévisionnel et devis ;</li><li>Extrait SIRET ou statuts ;</li><li>Attestation d’assurance.</li></ol>';
            $html .= '<p class="doc-note">Les dispositifs d’aide et leurs conditions varient selon les communes : ce courrier est un modèle à adapter au règlement en vigueur.</p>';
            $html .= $footer;
            break;

        // ── Demande d'aide à la région ────────────────────────────────────
        case 'aide-region':
            $title = 'Demande de soutien — région';
            $plan = gass_aid_plan($data);
            $requested = $plan['requested'] > 0 ? $h(gass_eur($plan['requested'])) : gassd_todo('montant sollicité');
            $regionName = $region !== '' ? $h($region) : gassd_todo('région');
            $html = '<h1>Demande de soutien à la transformation numérique</h1>';
            $html .= '<p class="doc-meta">Objet : accompagnement d’un projet de numérisation d’une galerie commerciale</p>';
            $html .= $sender;
            $html .= gassd_recipient('Région ' . $regionName, 'À l’attention de Monsieur ou Madame le Président', gassd_todo('adresse du conseil régional'));
            $html .= '<p>Madame, Monsieur le Président,</p>';
            $html .= '<p>' . gassd_or($org, 'nom de l’organisateur') . ' porte le projet de numérisation de la galerie commerciale ' . $h($gallery['name']) . ', située à ' . $recipientCity . '. Ce projet vise à doter les commerces et les exposants d’outils numériques simples : vitrine en ligne, réservation et paiement, communication, mesure de la fréquentation.</p>';
            if (trim((string) $data['aid']['project']) !== '') {
                $html .= '<p><strong>Projet :</strong> ' . $h($data['aid']['project']) . '</p>';
            }
            if (trim((string) $data['aid']['needs']) !== '') {
                $html .= '<p>' . nl2br($h($data['aid']['needs'])) . '</p>';
            }
            $html .= '<h2>Budget et cofinancements</h2>';
            $html .= gass_budget_html($data);
            $html .= '<p>Le budget total s’élève à ' . $h(gass_eur($plan['total'])) . '. Nous sollicitons un soutien régional de <strong>' . $requested . '</strong>, en complément de ' . $h(gass_eur($plan['own'])) . ' d’autofinancement et de ' . $h(gass_eur($plan['other'])) . ' d’autres financements.</p>';
            $html .= '<h2>Effets attendus</h2><ul>';
            $html .= '<li>Montée en compétence numérique des commerçants et des exposants ;</li>';
            $html .= '<li>Meilleure visibilité des commerces de proximité et des événements locaux ;</li>';
            $html .= '<li>Dématérialisation des inscriptions et des paiements ;</li>';
            $html .= '<li>Données de fréquentation pour évaluer et ajuster l’animation du territoire.</li>';
            $html .= '</ul>';
            $html .= '<p>Nous souhaitons inscrire ce projet dans les dispositifs régionaux de transformation numérique des commerces et de développement local. Nous vous remercions de bien vouloir nous indiquer le dispositif adapté, le taux d’intervention et les pièces à fournir, et nous tenir à disposition pour un échange avec le chargé de mission compétent.</p>';
            $html .= $sign;
            $html .= '<h2>Pièces jointes</h2><ol><li>Présentation de la structure et du projet ;</li><li>Budget prévisionnel et devis détaillés ;</li><li>Statuts et extrait SIRET ;</li><li>Attestation de vigilance, selon le dispositif.</li></ol>';
            $html .= '<p class="doc-note">Les dispositifs, taux et plafonds régionaux évoluent : vérifiez l’appel à projets en cours et son règlement avant de déposer ce courrier.</p>';
            $html .= $footer;
            break;

        default:
            return null;
    }

    return [
        'kind' => $kind,
        'label' => $catalog[$kind]['label'],
        'title' => $title,
        'html' => $html,
        'filename' => $kind . '-' . $gallery['slug'] . '.html',
    ];
}
