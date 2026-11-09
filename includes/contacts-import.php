<?php

// Import de contacts (prospects, clients) : fichier CSV ou Excel (.xls/.xlsx), carnet d'adresses vCard (.vcf, export d'un téléphone) ou lecture directe
// d'un CRM par son interface (HubSpot, Brevo, Mailchimp, Pipedrive). Moteur partagé par l'administration d'une boutique (comptes) et par les
// galeries (prospects) : lecture, reconnaissance des colonnes, nettoyage (e-mail, téléphone), doublons, désinscrits, puis écriture par une « cible ».
//
// Respect des personnes : les désinscrits des CRM (unsubscribed, opt-out, blacklisted…) ne sont jamais importés ; l'importateur confirme avoir le
// droit de les contacter ; chaque fiche garde sa source. Les jetons des CRM ne sont ni enregistrés ni journalisés (utilisés le temps de la requête).

const CI_MAX_BYTES = 10 * 1024 * 1024;
const CI_MAX_ROWS = 5000;
const CI_MAX_COLUMNS = 200;
const CI_API_HOSTS = ['hubspot' => 'https://api.hubapi.com', 'brevo' => 'https://api.brevo.com', 'pipedrive' => 'https://api.pipedrive.com'];

/** CRM lisibles directement : id => [nom, aide sur le jeton]. Les autres (Zoho, Google Contacts, Outlook, Odoo, Salesforce…) passent par leur export CSV. */
const CI_CRMS = [
    'hubspot' => ['HubSpot', "Jeton d'une « application privée » (Paramètres → Intégrations → Applications privées), droit de lecture sur les contacts."],
    'brevo' => ['Brevo (Sendinblue)', 'Clé API v3 (Paramètres → SMTP et API → Clés API).'],
    'mailchimp' => ['Mailchimp', 'Clé API (Compte → Extras → Clés API), de la forme xxxxxxxx-us21. Identifiant d\'audience facultatif : la première audience est lue.'],
    'pipedrive' => ['Pipedrive', 'Jeton API personnel (Préférences personnelles → API).'],
];

/** Colonnes sans champ propre, reconnues par leur intitulé : intitulé du champ personnalisé => synonymes (sans accents, minuscules). Proposées d'office quand la page l'active ('auto_custom'). */
const CI_AUTO_CUSTOM = [
    'Site web' => ['site', 'site web', 'site internet', 'website', 'web', 'url du site', 'site officiel'],
    'Facebook' => ['facebook', 'page facebook'],
    'Instagram' => ['instagram', 'compte instagram'],
    'Adresse' => ['adresse', 'adresse postale', 'address', 'rue', 'voie'],
    'Code postal' => ['code postal', 'cp', 'zip', 'postal code', 'zipcode'],
    'Ville' => ['ville', 'commune', 'city', 'localite'],
    'Catégorie' => ['categorie', 'category', 'type', 'secteur', 'famille'],
    'Horaires' => ['horaires', 'horaire', 'heures d\'ouverture', 'opening hours'],
    'SIRET' => ['siret', 'n° siret', 'numero siret'],
    'Emplacement' => ['emplacement', 'localisation'],
    'Zone' => ['zone', 'quartier', 'secteur geographique'],
    'N° d\'emplacement' => ['numero d\'emplacement', 'n° d\'emplacement', 'stand', 'numero de stand'],
    'Latitude' => ['lat', 'latitude'],
    'Longitude' => ['lon', 'lng', 'long', 'longitude'],
    'Lien' => ['url', 'lien', 'lien fiche', 'fiche'],
];

/** Valeurs qui disent « rien » (cellule remplie par un robot ou à la main) : « Non trouvé », « Non spécifié », « N/A »… */
function ci_placeholder(string $v): bool
{
    $n = ci_norm_header($v);
    return $n === '' || (bool) preg_match('/^(non (trouve|specifie|renseigne|communique|disponible|applicable)e?s?|aucune?( .*)?( trouve| renseigne| disponible)e?s?|n\/?a|na|nc|neant|inconnu(e)?|null|none|nan|undefined|\?+|[-–—_.\/\s]+)$/u', $n);
}

const CI_FIELDS = ['email' => 'E-mail', 'first' => 'Prénom', 'last' => 'Nom', 'name' => 'Nom complet', 'phone' => 'Téléphone', 'company' => 'Société', 'note' => 'Note', 'status' => 'Statut d\'abonnement'];

/** Synonymes d'en-têtes (sans accents, minuscules) des exports usuels : HubSpot, Mailchimp, Brevo, Zoho, Pipedrive, Google Contacts, Outlook, Odoo, Salesforce, Excel. */
const CI_SYNONYMS = [
    'email' => ['email', 'e-mail', 'e mail', 'mail', 'courriel', 'adresse e-mail', 'adresse email', 'email address', 'e-mail address', 'e-mail 1 - value', 'email 1 - value', 'person - email - work', 'person - email', 'work email', 'primary email', 'email principal', 'courrier electronique'],
    'first' => ['first name', 'firstname', 'prenom', 'given name', 'first', 'prenom du contact', 'person - first name'],
    'last' => ['last name', 'lastname', 'nom de famille', 'family name', 'surname', 'last', 'person - last name'],
    'name' => ['name', 'nom', 'nom complet', 'full name', 'display name', 'contact name', 'person - name', 'nom du contact', 'raison sociale', 'nom et prenom', 'contact', 'interlocuteur', 'responsable', 'gerant', 'dirigeant'],
    'phone' => ['phone', 'telephone', 'tel', 'tel.', 'mobile', 'portable', 'gsm', 'sms', 'phone number', 'mobile phone', 'mobilephone', 'phone 1 - value', 'person - phone - work', 'person - phone - mobile', 'person - phone', 'numero de telephone', 'telephone mobile', 'cell', 'cell phone', 'primary phone', 'business phone', 'work phone', 'home phone'],
    'company' => ['company', 'company name', 'societe', 'entreprise', 'organisation', 'organization', 'organization name', 'organization 1 - name', 'organization - name', 'account name', 'nom de la societe', 'nom de l\'entreprise', 'nom boutique', 'nom de la boutique', 'boutique', 'nom du commerce', 'commerce', 'enseigne', 'nom commercial', 'etablissement', 'marchand'],
    'note' => ['note', 'notes', 'commentaire', 'commentaires', 'remarque', 'description', 'tags', 'etiquettes', 'specialite / description', 'specialite', 'activite'],
    'status' => ['status', 'statut', 'subscription status', 'email marketing status', 'marketing contact status', 'opt-out', 'opt out', 'email opt out', 'unsubscribed', 'desabonne', 'blacklisted', 'emailblacklisted', 'hs_email_optout'],
];

/** Modèles reconnus d'après des en-têtes caractéristiques : nom affiché au visiteur. */
const CI_PRESETS = [
    'HubSpot' => ['marketing contact status', 'company name', 'first name'], 'Mailchimp' => ['email address', 'first name', 'last name'],
    'Brevo' => ['email', 'prenom', 'nom'], 'Pipedrive' => ['person - name', 'person - email - work'], 'Google Contacts' => ['e-mail 1 - value', 'phone 1 - value'],
    'Outlook' => ['e-mail address', 'mobile phone'], 'Zoho CRM' => ['account name', 'first name', 'email'], 'Salesforce' => ['mobilephone', 'account name'],
];

function ci_norm_header(string $h): string
{
    $h = strtr(mb_strtolower(trim($h)), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n', '’' => "'"]);
    return trim((string) preg_replace('/\s+/', ' ', $h));
}

/** Texte brut d'un fichier : UTF-8, ou Windows-1252/ISO-8859-1 (export Excel) converti ; BOM retiré. */
function ci_decode(string $raw): string
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
    elseif (!mb_check_encoding($raw, 'UTF-8')) $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    return str_replace("\0", '', $raw);
}

/** CSV -> [en-têtes, lignes]. Séparateur détecté (; , tabulation |), champs entre guillemets et sauts de ligne internes gérés. */
function ci_parse_csv(string $text): array
{
    $first = (string) strtok($text, "\n");
    $best = ',';
    $bestN = -1;
    foreach ([',', ';', "\t", '|'] as $d) {
        $n = count(str_getcsv($first, $d, '"', ''));
        if ($n > $bestN) { $best = $d; $bestN = $n; }
    }
    $h = fopen('php://temp', 'r+');
    fwrite($h, $text);
    rewind($h);
    $rows = [];
    while (($r = fgetcsv($h, 0, $best, '"', '')) !== false) {
        if ($r === [null] || !array_filter($r, static fn ($v) => trim((string) $v) !== '')) continue;
        $rows[] = array_map(static fn ($v) => trim((string) $v), $r);
        if (count($rows) > CI_MAX_ROWS + 1) {
            fclose($h);
            throw new InvalidArgumentException('Le fichier dépasse la limite de 5 000 contacts.');
        }
    }
    fclose($h);
    $headers = array_shift($rows) ?? [];
    if (count($headers) > CI_MAX_COLUMNS) throw new InvalidArgumentException('Le fichier contient plus de 200 colonnes.');
    return [$headers, $rows];
}

/** Fichier Excel .xls/.xlsx -> [en-têtes, lignes], sans évaluer les formules. */
function ci_parse_spreadsheet(string $path): array
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Le lecteur Excel n’est pas installé sur ce serveur.');
    require_once $autoload;
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        throw new RuntimeException('Le lecteur Excel est indisponible : vérifiez l’installation Composer.');
    }
    try {
        $type = \PhpOffice\PhpSpreadsheet\IOFactory::identify($path);
        if (!in_array($type, ['Xls', 'Xlsx'], true)) {
            throw new InvalidArgumentException('Format Excel non pris en charge : choisissez un fichier .xls ou .xlsx.');
        }
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($type);
        $reader->setReadDataOnly(false);
        $reader->setLoadSheetsOnly([$reader->listWorksheetNames($path)[0] ?? '']);
        $spreadsheet = $reader->load($path);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $lastRow = $sheet->getHighestDataRow();
            $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($lastRow > CI_MAX_ROWS + 1) throw new InvalidArgumentException('Le fichier dépasse la limite de 5 000 contacts.');
            if ($lastColumn > CI_MAX_COLUMNS) throw new InvalidArgumentException('Le fichier contient plus de 200 colonnes.');
            $data = $sheet->rangeToArray('A1:' . $sheet->getHighestDataColumn() . max(1, $lastRow), null, false, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        $rows = array_values(array_filter(array_map(static fn (array $row): array => array_map(
            static fn ($cell): string => trim((string) ($cell ?? '')), $row
        ), $data), static fn (array $row): bool => (bool) array_filter($row, static fn (string $cell): bool => $cell !== '')));
        $headers = array_shift($rows) ?? [];
        return [$headers, $rows];
    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Impossible de lire ce fichier Excel : vérifiez son format et qu’il n’est pas endommagé.', 0, $e);
    }
}

/**
 * Base SQLite (.db/.sqlite) -> [en-têtes, lignes], comme un tableur. La table lue est la plus évidente (commerces, contacts, prospects… sinon la plus
 * remplie). Une colonne « xxx_id » est remplacée par le nom de la table liée (rue_id -> rue), « numero » + « rue » deviennent « adresse »,
 * et dans une table de commerces la colonne « nom » devient « commerce ». Le fichier est ouvert en lecture seule et jamais exécuté.
 */
function ci_parse_sqlite(string $path): array
{
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Le lecteur de bases SQLite est indisponible sur ce serveur.');
    $fh = @fopen($path, 'rb');
    $magic = $fh ? (string) fread($fh, 16) : '';
    if ($fh) fclose($fh);
    if ($magic !== "SQLite format 3\0") throw new InvalidArgumentException('Ce fichier n’est pas une base SQLite valide.');
    $q = static fn (string $id): string => '"' . str_replace('"', '""', $id) . '"';
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA query_only = 1');
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'")->fetchAll(PDO::FETCH_COLUMN);
        if (!$tables) throw new InvalidArgumentException('Cette base ne contient aucune table.');
        $counts = [];
        foreach ($tables as $t) $counts[$t] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $q($t))->fetchColumn();
        $table = null;
        foreach (['commerces', 'commerce', 'prospects', 'contacts', 'marchands', 'boutiques', 'entreprises', 'societes', 'clients'] as $want) {
            foreach ($tables as $t) if (strtolower($t) === $want && $counts[$t] > 0) { $table = $t; break 2; }
        }
        if ($table === null) { arsort($counts); $table = (string) array_key_first($counts); }
        if (($counts[$table] ?? 0) === 0) throw new InvalidArgumentException('La table « ' . $table . ' » est vide.');
        if ($counts[$table] > CI_MAX_ROWS) throw new InvalidArgumentException('La base dépasse la limite de 5 000 contacts (' . $counts[$table] . ' lignes dans « ' . $table . ' »).');
        $cols = array_column($pdo->query('PRAGMA table_info(' . $q($table) . ')')->fetchAll(), 'name');
        if (count($cols) > CI_MAX_COLUMNS) throw new InvalidArgumentException('Le fichier contient plus de 200 colonnes.');
        $lower = array_map('strtolower', $tables);
        $select = [];
        $headers = [];
        $joins = '';
        $n = 0;
        foreach ($cols as $col) {
            $lc = strtolower($col);
            if ($lc === 'id') continue;
            if (str_ends_with($lc, '_id')) {
                $base = substr($lc, 0, -3);
                $ref = null;
                foreach ([$base . 's', $base, $base . 'es'] as $cand) { $i = array_search($cand, $lower, true); if ($i !== false) { $ref = $tables[$i]; break; } }
                if ($ref !== null) {
                    $refCols = array_map('strtolower', array_column($pdo->query('PRAGMA table_info(' . $q($ref) . ')')->fetchAll(), 'name'));
                    $label = in_array('nom', $refCols, true) ? 'nom' : (in_array('name', $refCols, true) ? 'name' : null);
                    if ($label !== null) {
                        $a = 'j' . (++$n);
                        $joins .= ' LEFT JOIN ' . $q($ref) . ' ' . $a . ' ON ' . $a . '.id = t.' . $q($col);
                        $select[] = $a . '.' . $q($label);
                        $headers[] = $base;
                    }
                }
                continue;
            }
            $select[] = 't.' . $q($col);
            $headers[] = $lc === 'nom' && preg_match('/commerce|boutique|marchand|entreprise|societe/i', $table) ? 'commerce' : $col;
        }
        if (!$select) throw new InvalidArgumentException('Aucune colonne lisible dans la table « ' . $table . ' ».');
        $rows = [];
        $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM ' . $q($table) . ' t' . $joins . ' LIMIT ' . (CI_MAX_ROWS + 1));
        while (($r = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $rows[] = array_map(static fn ($v): string => trim((string) ($v ?? '')), $r);
        }
        $hn = array_map('strtolower', $headers);
        $iNum = array_search('numero', $hn, true);
        $iRue = array_search('rue', $hn, true);
        if ($iNum !== false && $iRue !== false) {      // « 12 » + « rue des Halles » -> « 12 rue des Halles »
            foreach ($rows as &$r) {
                $r[$iRue] = trim($r[$iNum] . ' ' . $r[$iRue]);
                unset($r[$iNum]);
                $r = array_values($r);
            }
            unset($r);
            $headers[$iRue] = 'adresse';
            unset($headers[$iNum]);
            $headers = array_values($headers);
        }
        $rows = array_values(array_filter($rows, static fn (array $r): bool => (bool) array_filter($r, static fn (string $c): bool => $c !== '')));
        return [$headers, $rows];
    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Impossible de lire cette base SQLite : vérifiez qu’elle n’est pas endommagée.', 0, $e);
    }
}

/** Carnet d'adresses vCard (.vcf, 2.1 / 3.0 / 4.0) -> contacts normalisés. */
function ci_parse_vcf(string $text): array
{
    $text = preg_replace("/\r?\n[ \t]/", '', $text);          // lignes repliées
    $out = [];
    foreach (preg_split('/BEGIN:VCARD/i', $text) as $card) {
        if (!preg_match('/END:VCARD/i', $card)) continue;
        $c = ['email' => '', 'first' => '', 'last' => '', 'name' => '', 'phone' => '', 'company' => '', 'note' => '', 'status' => ''];
        $emails = [];
        $phones = [];
        foreach (preg_split('/\r?\n/', $card) as $line) {
            if (!str_contains($line, ':')) continue;
            [$k, $v] = explode(':', $line, 2);
            $key = strtoupper(explode(';', preg_replace('/^item\d+\./i', '', $k))[0]);
            $v = trim(str_replace(['\n', '\,', '\;'], [' ', ',', ';'], $v));
            if (stripos($k, 'ENCODING=QUOTED-PRINTABLE') !== false) $v = quoted_printable_decode($v);
            if ($key === 'FN') $c['name'] = $v;
            elseif ($key === 'N') { $p = explode(';', $v); $c['last'] = trim($p[0] ?? ''); $c['first'] = trim($p[1] ?? ''); }
            elseif ($key === 'EMAIL') $emails[] = $v;
            elseif ($key === 'TEL') $phones[] = ['v' => $v, 'mobile' => stripos($k, 'CELL') !== false];
            elseif ($key === 'ORG') $c['company'] = trim(str_replace(';', ' ', $v));
            elseif ($key === 'NOTE') $c['note'] = $v;
        }
        usort($phones, static fn ($a, $b) => (int) $b['mobile'] <=> (int) $a['mobile']);      // mobile d'abord
        $c['email'] = $emails[0] ?? '';
        $c['phone'] = $phones[0]['v'] ?? '';
        if ($c['email'] !== '' || $c['phone'] !== '' || $c['name'] !== '') $out[] = $c;
        if (count($out) >= CI_MAX_ROWS) break;
    }
    return $out;
}

/** Colonnes reconnues : champ => index de colonne (ou -1). + nom du CRM reconnu d'après les en-têtes (ou ''). */
function ci_guess_mapping(array $headers, bool $autoCustom = false): array
{
    $norm = array_map('ci_norm_header', $headers);
    $map = [];
    foreach (CI_FIELDS as $f => $_) {
        $map[$f] = -1;
        foreach (CI_SYNONYMS[$f] as $syn) {
            $i = array_search($syn, $norm, true);
            if ($i !== false) { $map[$f] = (int) $i; break; }
        }
    }
    if ($map['first'] >= 0 && $map['last'] < 0 && $map['name'] >= 0) { $map['last'] = $map['name']; $map['name'] = -1; }   // « Prénom » + « Nom » : Nom est le nom de famille
    if ($autoCustom) {      // colonnes restantes reconnues (site, adresse, catégorie…) : champ personnalisé proposé d'office
        $used = array_filter($map, static fn ($v): bool => is_int($v) && $v >= 0);
        foreach (CI_AUTO_CUSTOM as $label => $syns) {
            foreach ($norm as $i => $hn) {
                if (in_array($i, $used, true) || !in_array($hn, $syns, true)) continue;
                $map['custom'][$i] = ['label' => $label];
                $used[] = $i;
                break;
            }
        }
    }
    $preset = '';
    foreach (CI_PRESETS as $name => $need) if (!array_diff($need, $norm)) { $preset = $name; break; }
    return [$map, $preset];
}

/** Numéro nettoyé : chiffres et « + » ; 0X… français devient +33X… ; 00… devient +… ; '' si invraisemblable. */
function ci_phone(string $p): string
{
    $p = trim((string) preg_replace('/\(\s*0\s*\)/', '', $p));      // +33 (0)6… : le 0 entre parenthèses ne se compose pas
    if ($p === '' || ci_placeholder($p)) return '';
    $plus = str_starts_with($p, '+');
    $d = preg_replace('/\D/', '', $p);
    if (str_starts_with($d, '00')) { $d = substr($d, 2); $plus = true; }
    if (!$plus && strlen($d) === 10 && $d[0] === '0') { $d = '33' . substr($d, 1); $plus = true; }
    elseif (!$plus && strlen($d) === 9 && in_array($d[0], ['6', '7'], true)) { $d = '33' . $d; $plus = true; }   // 6 12 34 56 78 sans le 0
    if (strlen($d) < 8 || strlen($d) > 15) return '';
    return ($plus ? '+' : '') . $d;
}

function ci_unsubscribed(string $status): bool
{
    $s = ci_norm_header($status);
    return $s !== '' && (bool) preg_match('/unsub|desabonn|opt.?out|blacklist|bounc|cleaned|invalid|archiv|^true$|^oui$|^yes$|^1$/', $s) && !preg_match('/^(non|no|false|0|subscribed|abonne|opted.?in)/', $s);
}

/**
 * Lignes + colonnes -> contacts normalisés [email, name, phone, company, note, line, skip => '' | motif]. Un contact sans e-mail ni téléphone, un
 * e-mail invalide ou un désinscrit est écarté avec son motif ; un doublon dans le fichier (même e-mail) aussi.
 */
function ci_contacts_from_rows(array $rows, array $map): array
{
    $get = static fn (array $r, string $f): string => isset($map[$f]) && $map[$f] >= 0 ? (string) ($r[$map[$f]] ?? '') : '';
    $out = [];
    $seen = [];
    foreach ($rows as $i => $r) {
        $c = ['email' => $get($r, 'email'), 'first' => $get($r, 'first'), 'last' => $get($r, 'last'), 'name' => $get($r, 'name'), 'phone' => $get($r, 'phone'),
            'company' => $get($r, 'company'), 'note' => $get($r, 'note'), 'status' => $get($r, 'status')];
        foreach ((array) ($map['custom'] ?? []) as $column => $field) {
            if (!is_array($field) || !is_numeric($column)) continue;
            $label = trim((string) ($field['label'] ?? ''));
            $value = trim((string) ($r[(int) $column] ?? ''));
            if ($label !== '' && $value !== '') $c['custom_fields'][$label] = $value;
        }
        $out[] = ci_clean($c, $i + 2, $seen);
    }
    return $out;
}

/** Nettoie un contact et lui donne son motif d'écart éventuel ($seen : e-mails déjà vus dans ce lot). */
function ci_clean(array $c, int $line, array &$seen): array
{
    $clean = static fn ($v): string => ci_placeholder((string) $v) ? '' : trim((string) $v);
    $email = mb_strtolower($clean($c['email'] ?? ''));
    $email = preg_replace('/^mailto:/', '', $email);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL) && preg_match('/[^\s<>,;:"\'()\[\]–—-][^\s<>,;:"\'()\[\]]*@[^\s<>,;:"\'()\[\]]+\.[a-z]{2,}/u', $email, $m)) $email = $m[0];   // « – nom@site.fr », « <nom@site.fr> »
    $name = $clean($c['name'] ?? '') !== '' ? $clean($c['name']) : trim($clean($c['first'] ?? '') . ' ' . $clean($c['last'] ?? ''));
    if ($name !== '' && !preg_match('/\p{L}/u', $name)) $name = '';      // « 43 » (numéro de stand) n'est pas un nom
    if (mb_strlen($name) > 3 && $name === mb_strtoupper($name) && $name !== mb_strtolower($name)) $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');   // « CLÉMENCE AMBLARD »
    $phone = ci_phone((string) ($c['phone'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL) && $phone !== '') $email = '';      // adresse inutilisable mais téléphone valide : on garde la fiche
    $skip = '';
    if (ci_unsubscribed((string) ($c['status'] ?? ''))) $skip = 'désinscrit ou exclu du CRM';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $skip = 'e-mail invalide';
    elseif ($email === '' && $phone === '') $skip = 'ni e-mail ni téléphone';
    elseif ($email !== '' && isset($seen[$email])) $skip = 'doublon dans le fichier';
    if ($email !== '' && $skip === '') $seen[$email] = true;
    return ['email' => mb_substr($email, 0, 120), 'name' => mb_substr($name, 0, 100), 'phone' => $phone, 'company' => mb_substr($clean($c['company'] ?? ''), 0, 100),
        'note' => mb_substr($clean($c['note'] ?? ''), 0, 300),
        'custom_fields' => array_slice(array_filter((array) ($c['custom_fields'] ?? []), static fn ($v): bool => is_scalar($v) && !ci_placeholder((string) $v)), 0, 30, true),
        'line' => $line, 'skip' => $skip];
}

/** Contacts d'un carnet vCard, nettoyés avec le même contrôle. */
function ci_contacts_from_vcf(string $text): array
{
    $seen = [];
    $out = [];
    foreach (ci_parse_vcf($text) as $i => $c) $out[] = ci_clean($c, $i + 1, $seen);
    return $out;
}

// ── CRM : lecture par l'API ────────────────────────────────────────────────

/** Adresse de base d'une API (surchargeable par la constante CI_TEST_BASE : tableau crm => adresse, pour les essais hors ligne). */
function ci_api_base(string $crm, string $secret = ''): string
{
    if (defined('CI_TEST_BASE') && isset(CI_TEST_BASE[$crm])) return CI_TEST_BASE[$crm];
    if ($crm === 'mailchimp') {
        if (!preg_match('/-([a-z]{2}\d{1,3})$/', $secret, $m)) throw new InvalidArgumentException('Clé Mailchimp invalide : elle se termine par un suffixe de datacenter (ex. -us21).');
        return 'https://' . $m[1] . '.api.mailchimp.com';
    }
    return CI_API_HOSTS[$crm] ?? throw new InvalidArgumentException('CRM inconnu.');
}

function ci_http_get(string $url, array $headers, ?string $basic = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_FOLLOWLOCATION => false]);
    if ($basic !== null) curl_setopt($ch, CURLOPT_USERPWD, $basic);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($body === false) throw new RuntimeException('Le CRM ne répond pas (' . $err . ').');
    $data = json_decode((string) $body, true);
    if ($code === 401 || $code === 403) throw new RuntimeException('Le CRM refuse la clé ou le jeton (accès non autorisé).');
    if ($code >= 400) throw new RuntimeException('Le CRM a répondu une erreur (' . $code . ').');
    return is_array($data) ? $data : [];
}

/** Lit les contacts d'un CRM. -> contacts bruts [email, first, last, name, phone, company, note, status]. Lève une exception au message lisible. */
function ci_fetch_crm(string $crm, string $secret, string $extra = ''): array
{
    $secret = trim($secret);
    if ($secret === '' || preg_match('/[\s\x00-\x1f]/', $secret)) throw new InvalidArgumentException('Collez la clé ou le jeton du CRM.');
    $base = ci_api_base($crm, $secret);
    $out = [];
    if ($crm === 'hubspot') {
        $after = '';
        for ($page = 0; $page < 50 && count($out) < CI_MAX_ROWS; $page++) {
            $d = ci_http_get($base . '/crm/v3/objects/contacts?limit=100&properties=email,firstname,lastname,phone,mobilephone,company,hs_email_optout,lifecyclestage' . ($after !== '' ? '&after=' . rawurlencode($after) : ''), ['Authorization: Bearer ' . $secret]);
            foreach ($d['results'] ?? [] as $r) {
                $p = $r['properties'] ?? [];
                $out[] = ['email' => (string) ($p['email'] ?? ''), 'first' => (string) ($p['firstname'] ?? ''), 'last' => (string) ($p['lastname'] ?? ''), 'phone' => (string) (($p['mobilephone'] ?? '') ?: ($p['phone'] ?? '')),
                    'company' => (string) ($p['company'] ?? ''), 'note' => '', 'status' => (string) ($p['hs_email_optout'] ?? '')];
            }
            $after = (string) ($d['paging']['next']['after'] ?? '');
            if ($after === '') break;
        }
    } elseif ($crm === 'brevo') {
        for ($off = 0; $off < CI_MAX_ROWS; $off += 500) {
            $d = ci_http_get($base . '/v3/contacts?limit=500&offset=' . $off, ['api-key: ' . $secret]);
            $list = $d['contacts'] ?? [];
            foreach ($list as $r) {
                $a = $r['attributes'] ?? [];
                $out[] = ['email' => (string) ($r['email'] ?? ''), 'first' => (string) ($a['PRENOM'] ?? $a['FIRSTNAME'] ?? ''), 'last' => (string) ($a['NOM'] ?? $a['LASTNAME'] ?? ''), 'phone' => (string) ($a['SMS'] ?? $a['TELEPHONE'] ?? $a['MOBILE'] ?? ''),
                    'company' => (string) ($a['SOCIETE'] ?? $a['COMPANY'] ?? ''), 'note' => '', 'status' => !empty($r['emailBlacklisted']) ? 'blacklisted' : ''];
            }
            if (count($list) < 500) break;
        }
    } elseif ($crm === 'mailchimp') {
        $auth = 'anystring:' . $secret;
        $list = preg_match('/^[a-z0-9]{6,20}$/i', $extra) ? $extra : '';
        if ($list === '') {
            $l = ci_http_get($base . '/3.0/lists?count=1&fields=lists.id', [], $auth);
            $list = (string) ($l['lists'][0]['id'] ?? '');
            if ($list === '') throw new RuntimeException('Aucune audience trouvée dans ce compte Mailchimp.');
        }
        for ($off = 0; $off < CI_MAX_ROWS; $off += 500) {
            $d = ci_http_get($base . '/3.0/lists/' . rawurlencode($list) . '/members?count=500&offset=' . $off . '&fields=members.email_address,members.status,members.merge_fields', [], $auth);
            $members = $d['members'] ?? [];
            foreach ($members as $r) {
                $m = $r['merge_fields'] ?? [];
                $out[] = ['email' => (string) ($r['email_address'] ?? ''), 'first' => (string) ($m['FNAME'] ?? ''), 'last' => (string) ($m['LNAME'] ?? ''), 'phone' => (string) ($m['PHONE'] ?? ''),
                    'company' => (string) ($m['COMPANY'] ?? ''), 'note' => '', 'status' => (string) ($r['status'] ?? '')];
            }
            if (count($members) < 500) break;
        }
    } elseif ($crm === 'pipedrive') {
        for ($start = 0; $start < CI_MAX_ROWS; $start += 500) {
            $d = ci_http_get($base . '/v1/persons?limit=500&start=' . $start, ['x-api-token: ' . $secret]);
            $data = is_array($d['data'] ?? null) ? $d['data'] : [];
            foreach ($data as $r) {
                $pick = static function ($arr): string {
                    foreach ((array) $arr as $x) if (is_array($x) && !empty($x['primary']) && ($x['value'] ?? '') !== '') return (string) $x['value'];
                    foreach ((array) $arr as $x) if (is_array($x) && ($x['value'] ?? '') !== '') return (string) $x['value'];
                    return '';
                };
                $out[] = ['email' => $pick($r['email'] ?? []), 'first' => (string) ($r['first_name'] ?? ''), 'last' => (string) ($r['last_name'] ?? ''), 'name' => (string) ($r['name'] ?? ''),
                    'phone' => $pick($r['phone'] ?? []), 'company' => (string) ($r['org_name'] ?? ''), 'note' => '', 'status' => ''];
            }
            if (count($data) < 500 || empty($d['additional_data']['pagination']['more_items_in_collection'])) break;
        }
    } else {
        throw new InvalidArgumentException('CRM inconnu.');
    }
    return array_slice($out, 0, CI_MAX_ROWS);
}

function ci_contacts_from_raw(array $raw): array
{
    $seen = [];
    $out = [];
    foreach ($raw as $i => $c) $out[] = ci_clean($c, $i + 1, $seen);
    return $out;
}

// ── Session d'import : aperçu puis confirmation ────────────────────────────

function ci_dir(): string
{
    $d = dirname(__DIR__) . '/data/imports';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

/** Mémorise un import en attente (1 h) et nettoie les anciens. -> identifiant. */
function ci_stash(array $data): string
{
    $dir = ci_dir();
    foreach (glob($dir . '/*.json') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
    $id = bin2hex(random_bytes(12));
    file_put_contents($dir . '/' . $id . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    return $id;
}

function ci_unstash(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) return null;
    $f = ci_dir() . '/' . $id . '.json';
    $d = is_file($f) && filemtime($f) > time() - 3600 ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : null;
}

function ci_forget(string $id): void
{
    if (preg_match('/^[a-f0-9]{24}$/', $id)) @unlink(ci_dir() . '/' . $id . '.json');
}

/**
 * Écrit les contacts valides dans la cible. $target : ['exists' => fn(contact): ?array (fiche existante), 'create' => fn(contact): bool, 'complete' => fn(existant, contact): bool].
 * -> ['created' => n, 'completed' => n, 'existing' => n, 'skipped' => n, 'failed' => n].
 */
function ci_apply(array $contacts, array $target, bool $complete): array
{
    $r = ['created' => 0, 'completed' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 0];
    foreach ($contacts as $c) {
        if ($c['skip'] !== '') { $r['skipped']++; continue; }
        $ex = $target['exists']($c);
        if ($ex) {
            if ($complete && $target['complete']($ex, $c)) $r['completed']++; else $r['existing']++;
            continue;
        }
        if ($target['create']($c)) $r['created']++; else $r['failed']++;
    }
    return $r;
}
