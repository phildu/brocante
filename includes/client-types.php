<?php

// Types de client d'une manifestation (vide-grenier, marché aux puces) : particulier, association, entreprise.
// Chaque type a ses champs, exigés par le registre des vendeurs (code pénal, art. 321-7 et R321-9 à R321-12) :
//   - particulier : nom, prénoms, domicile, pièce d'identité (nature, numéro, date de délivrance, autorité),
//     attestation sur l'honneur de non-participation à plus de deux autres manifestations dans l'année ;
//   - personne morale (association, entreprise) : nom et adresse du siège, identité et fonction du représentant,
//     références de sa pièce d'identité.
// La copie de la pièce d'identité n'est pas exigée par le registre : la CNIL considère sa conservation comme excessive.
// Elle reste donc facultative, avec accord explicite et suppression programmée (voir gallery-booking.php).

const CT_TYPES = [
    'particulier' => 'Particulier',
    'association' => 'Association',
    'entreprise' => 'Entreprise',
];

const CT_ID_DOC_TYPES = [
    'cni' => "Carte nationale d'identité",
    'passeport' => 'Passeport',
    'titre_sejour' => 'Titre de séjour',
    'permis' => 'Permis de conduire',
];

const CT_LEGAL_FORMS = [
    'EI' => 'Entreprise individuelle',
    'EURL' => 'EURL',
    'SARL' => 'SARL',
    'SAS' => 'SAS',
    'SASU' => 'SASU',
    'SA' => 'SA',
    'SNC' => 'SNC',
    'SCI' => 'SCI',
    'SCOP' => 'SCOP',
    'autre' => 'Autre',
];

/** Type normalisé : « pro » et « professionnel » (demandes antérieures) deviennent « entreprise ». */
function ct_kind(string $raw): string
{
    $k = mb_strtolower(trim($raw));
    $k = ['pro' => 'entreprise', 'professionnel' => 'entreprise'][$k] ?? $k;
    return isset(CT_TYPES[$k]) ? $k : 'particulier';
}

function ct_label(string $type): string
{
    return CT_TYPES[ct_kind($type)];
}

function ct_date(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $value, $m)) {
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
    }
    return '';
}

function ct_date_display(string $value): string
{
    $d = ct_date($value);
    return $d === '' ? '' : date('d/m/Y', strtotime($d));
}

/** Nettoyage des champs d'un client (aucune exigence ici : voir ct_validate). */
function ct_input(array $in): array
{
    $text = static fn (string $key, int $max): string => mb_substr(trim((string) ($in[$key] ?? '')), 0, $max);
    return [
        'firstname' => $text('firstname', 60),
        'lastname' => $text('lastname', 60),
        'function' => $text('function', 80),
        'birth_date' => ct_date((string) ($in['birth_date'] ?? '')),
        'birth_place' => $text('birth_place', 120),
        'address' => $text('address', 160),
        'postal_code' => $text('postal_code', 12),
        'city' => $text('city', 80),
        'org_name' => $text('org_name', 120),
        'legal_form' => $text('legal_form', 20),
        'rna' => mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $text('rna', 20))),
        'siren' => preg_replace('/\D/', '', $text('siren', 20)),
        'siret' => preg_replace('/\D/', '', $text('siret', 20)),
        'vat' => mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $text('vat', 20))),
        'org_address' => $text('org_address', 160),
        'org_postal_code' => $text('org_postal_code', 12),
        'org_city' => $text('org_city', 80),
        'rep_firstname' => $text('rep_firstname', 60),
        'rep_lastname' => $text('rep_lastname', 60),
        'rep_function' => $text('rep_function', 80),
        'id_doc_type' => isset(CT_ID_DOC_TYPES[$text('id_doc_type', 20)]) ? $text('id_doc_type', 20) : '',
        'id_doc_number' => mb_substr(trim((string) ($in['id_doc_number'] ?? '')), 0, 40),
        'id_doc_date' => ct_date((string) ($in['id_doc_date'] ?? '')),
        'id_doc_issuer' => $text('id_doc_issuer', 100),
        'attestation' => !empty($in['attestation']),
        'association_declaration' => !empty($in['association_declaration']),
        'cni_consent' => !empty($in['cni_consent']),
    ];
}

/** Somme de contrôle SIREN/SIRET (algorithme de Luhn). */
function ct_luhn_ok(string $number): bool
{
    if (!preg_match('/^\d+$/', $number)) return false;
    $sum = 0;
    $double = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int) $number[$i];
        if ($double) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $double = !$double;
    }
    return $sum % 10 === 0;
}

function ct_org_number_ok(array $c, string $field): bool
{
    $n = (string) ($c[$field] ?? '');
    if ($n === '') return false;
    return $field === 'siren' ? (strlen($n) === 9 && ct_luhn_ok($n)) : (strlen($n) === 14 && ct_luhn_ok($n));
}

function ct_id_doc_ok(array $c): bool
{
    return $c['id_doc_type'] !== '' && mb_strlen($c['id_doc_number']) >= 3 && $c['id_doc_date'] !== '' && $c['id_doc_issuer'] !== '';
}

/** Mentions manquantes pour le registre des vendeurs (libellés affichables). */
function ct_missing(array $c, string $type): array
{
    $type = ct_kind($type);
    $m = [];
    if ($type === 'particulier') {
        if ($c['lastname'] === '') $m[] = 'nom';
        if ($c['firstname'] === '') $m[] = 'prénom';
        if ($c['address'] === '') $m[] = 'adresse du domicile';
        if ($c['postal_code'] === '') $m[] = 'code postal';
        if ($c['city'] === '') $m[] = 'ville';
        if (!$c['attestation']) $m[] = "attestation sur l'honneur (2 participations maximum dans l'année)";
    } else {
        if ($c['org_name'] === '') $m[] = $type === 'association' ? "nom de l'association" : "raison sociale";
        if ($c['org_address'] === '') $m[] = 'adresse du siège';
        if ($c['org_postal_code'] === '') $m[] = 'code postal du siège';
        if ($c['org_city'] === '') $m[] = 'ville du siège';
        if ($c['rep_lastname'] === '') $m[] = 'nom du représentant';
        if ($c['rep_firstname'] === '') $m[] = 'prénom du représentant';
        if ($c['rep_function'] === '') $m[] = 'fonction du représentant';
        if ($type === 'association') {
            if ($c['rna'] === '' && $c['siret'] === '') $m[] = 'numéro RNA ou SIRET';
            elseif ($c['siret'] !== '' && !ct_org_number_ok($c, 'siret')) $m[] = 'SIRET valide (14 chiffres)';
            if ($c['rna'] !== '' && !preg_match('/^[A-Z0-9]{9,10}$/', $c['rna'])) $m[] = 'numéro RNA valide';
            if (!$c['association_declaration']) $m[] = "déclaration : objets usagés issus de dons de particuliers";
        } else {
            if ($c['legal_form'] === '' || !isset(CT_LEGAL_FORMS[$c['legal_form']])) $m[] = 'forme juridique';
            if ($c['siren'] === '' && $c['siret'] === '') $m[] = 'SIREN ou SIRET';
            elseif ($c['siret'] !== '' && !ct_org_number_ok($c, 'siret')) $m[] = 'SIRET valide (14 chiffres)';
            elseif ($c['siren'] !== '' && !ct_org_number_ok($c, 'siren')) $m[] = 'SIREN valide (9 chiffres)';
        }
    }
    if (!$c['id_doc_type']) $m[] = "nature de la pièce d'identité";
    if (mb_strlen($c['id_doc_number']) < 3) $m[] = "numéro de la pièce d'identité";
    if ($c['id_doc_date'] === '') $m[] = "date de délivrance de la pièce d'identité";
    if ($c['id_doc_issuer'] === '') $m[] = "autorité émettrice de la pièce d'identité";
    return $m;
}

/** Première erreur bloquante, ou null. */
function ct_validate(array $c, string $type): ?string
{
    $missing = ct_missing($c, $type);
    if ($missing) return 'Complétez : ' . implode(', ', $missing) . '.';
    $today = date('Y-m-d');
    if ($c['id_doc_date'] > $today) return "La date de délivrance de la pièce d'identité ne peut pas être dans le futur.";
    if ($c['id_doc_date'] < '1950-01-01') return "Vérifiez la date de délivrance de la pièce d'identité.";
    if ($c['birth_date'] !== '' && ($c['birth_date'] > $today || $c['birth_date'] < '1900-01-01')) return 'Vérifiez la date de naissance.';
    if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z .\-]{2,39}$/', $c['id_doc_number'])) return "Numéro de pièce d'identité invalide.";
    return null;
}

/** Nom affiché d'un client (particulier : prénom nominal ; personne morale : sa dénomination). */
function ct_name(array $c, string $type): string
{
    $type = ct_kind($type);
    if ($type === 'particulier') return trim($c['firstname'] . ' ' . $c['lastname']);
    return trim((string) $c['org_name']);
}

/** Adresse complète sur une ligne. */
function ct_address_line(string $address, string $postal, string $city): string
{
    return trim($address . (($postal !== '' || $city !== '') ? "\n" : '') . trim($postal . ' ' . $city));
}

/** Champs du registre des vendeurs, quel que soit le type (pour l'impression et l'export). */
function ct_register_row(array $c, string $type): array
{
    $type = ct_kind($type);
    $idType = CT_ID_DOC_TYPES[$c['id_doc_type']] ?? '';
    if ($type === 'particulier') {
        return [
            'type' => ct_label($type),
            'name' => $c['lastname'],
            'firstname' => $c['firstname'],
            'function' => 'Particulier',
            'address' => ct_address_line($c['address'], $c['postal_code'], $c['city']),
            'org' => '',
            'representative' => '',
            'id_type' => $idType,
            'id_number' => $c['id_doc_number'],
            'id_date' => ct_date_display($c['id_doc_date']),
            'id_issuer' => $c['id_doc_issuer'],
            'attestation' => $c['attestation'] ? 'Oui' : 'Non',
        ];
    }
    return [
        'type' => ct_label($type),
        'name' => $c['org_name'],
        'firstname' => '',
        'function' => $c['rep_function'],
        'address' => ct_address_line($c['org_address'], $c['org_postal_code'], $c['org_city']),
        'org' => trim(implode(' · ', array_filter([
            $c['rna'] !== '' ? 'RNA ' . $c['rna'] : '',
            $c['siret'] !== '' ? 'SIRET ' . $c['siret'] : ($c['siren'] !== '' ? 'SIREN ' . $c['siren'] : ''),
        ]))),
        'representative' => trim($c['rep_firstname'] . ' ' . $c['rep_lastname']),
        'id_type' => $idType,
        'id_number' => $c['id_doc_number'],
        'id_date' => ct_date_display($c['id_doc_date']),
        'id_issuer' => $c['id_doc_issuer'],
        'attestation' => $type === 'association' ? ($c['association_declaration'] ? 'Dons de particuliers' : 'Non') : '—',
    ];
}

/** Blocs de lecture pour l'administration : intitulé => [libellé => valeur]. */
function ct_display(array $c, string $type): array
{
    $type = ct_kind($type);
    $out = [];
    if ($type === 'particulier') {
        $out['Identité'] = [
            'Nom' => $c['lastname'],
            'Prénom' => $c['firstname'],
            'Date de naissance' => ct_date_display($c['birth_date']),
            'Lieu de naissance' => $c['birth_place'],
            'Domicile' => ct_address_line($c['address'], $c['postal_code'], $c['city']),
        ];
    } else {
        $out[$type === 'association' ? 'Association' : 'Entreprise'] = [
            'Dénomination' => $c['org_name'],
            'Forme juridique' => $type === 'entreprise' ? (CT_LEGAL_FORMS[$c['legal_form']] ?? $c['legal_form']) : '',
            'RNA' => $c['rna'],
            'SIREN' => $c['siren'],
            'SIRET' => $c['siret'],
            'TVA' => $c['vat'],
            'Siège' => ct_address_line($c['org_address'], $c['org_postal_code'], $c['org_city']),
        ];
        $out['Représentant'] = [
            'Nom' => $c['rep_lastname'],
            'Prénom' => $c['rep_firstname'],
            'Fonction' => $c['rep_function'],
        ];
    }
    $out["Pièce d'identité"] = [
        'Nature' => CT_ID_DOC_TYPES[$c['id_doc_type']] ?? '',
        'Numéro' => $c['id_doc_number'],
        'Délivrée le' => ct_date_display($c['id_doc_date']),
        'Par' => $c['id_doc_issuer'],
    ];
    $out['Déclarations'] = [
        "Attestation sur l'honneur" => $type === 'particulier' ? ($c['attestation'] ? 'Signée' : 'Manquante') : '',
        'Objets issus de dons de particuliers' => $type === 'association' ? ($c['association_declaration'] ? 'Déclaré' : 'Manquant') : '',
        'Accord pour la copie de la pièce' => $c['cni_consent'] ? 'Donné' : 'Non donné',
    ];
    foreach ($out as $k => $block) $out[$k] = array_filter($block, static fn ($v) => trim((string) $v) !== '');
    return array_filter($out);
}
