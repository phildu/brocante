<?php
// Équipe du projet : les proches qui aident à finaliser la plateforme.
// Trois catégories (conseiller, testeur, partenaire financier) et six domaines
// d'intervention. Tout est stocké en fichiers JSON dans data/equipe/ :
//   membres/<id>.json        une fiche par personne
//   candidatures/<id>.json   les inscriptions reçues, en attente de décision
//   invitations.json         les liens d'inscription créés depuis le portail
//   settings.json            code d'accès, e-mail de notification, ouverture
//
// Aucune table SQL : comme les autres réglages du portail, ces données vivent
// dans data/ (jamais déployées) et se sauvegardent avec le reste du dossier.

if (!defined('PORTAIL_ROOT')) {
    define('PORTAIL_ROOT', dirname(__DIR__));
}

const EQ_CATEGORIES = [
    'conseiller' => [
        'label' => 'Conseiller',
        'short' => 'Conseil',
        'desc' => 'Un regard extérieur sur les choix : offre, parcours, priorités, décisions à prendre.',
    ],
    'testeur' => [
        'label' => 'Testeur',
        'short' => 'Tests',
        'desc' => 'Essaie les boutiques et le portail pour trouver les frictions, les bugs et les incompréhensions.',
    ],
    'partenaire' => [
        'label' => 'Partenaire financier',
        'short' => 'Partenaire',
        'desc' => 'Apporte un financement, une avance ou une mise à disposition, avec des conditions à définir ensemble.',
    ],
];

const EQ_DOMAINS = [
    'design' => 'Design & front-end',
    'backend' => 'Back-end',
    'dev' => 'Développement & gestion de projet',
    'marketing' => 'Marketing',
    'commercial' => 'Commercial & ventes',
    'autre' => 'Autre / à préciser',
];

const EQ_STATUSES = [
    'invite' => 'Invité',
    'actif' => 'Actif',
    'pause' => 'En pause',
    'refuse' => 'Sans suite',
    'archive' => 'Archivé',
];

const EQ_TEST_MODES = [
    'reel' => 'Testeur réel — utilise les boutiques au quotidien',
    'virtuel' => 'Testeur virtuel — scénarios, agents et audits à distance',
];

const EQ_PARTNER_NATURES = [
    'a_definir' => 'À définir',
    'don' => 'Don / mécénat',
    'pret' => 'Prêt ou avance remboursable',
    'sponsoring' => 'Sponsoring',
    'royalties' => 'Part des revenus',
    'mise_a_dispo' => 'Mise à disposition (outils, lieu, matériel)',
    'autre' => 'Autre',
];

const EQ_PARTNER_STATUSES = [
    'a_definir' => 'Conditions à définir',
    'discussion' => 'En discussion',
    'accord' => 'Accord de principe',
    'signe' => 'Signé',
    'refuse' => 'Sans suite',
];

const EQ_HEURES = ['1-2 h par semaine', '3-5 h par semaine', '6-10 h par semaine', 'Plus de 10 h par semaine', 'Ponctuel, selon les besoins'];
const EQ_FORMATS = ['À distance (visio, messages)', 'Sur place', 'Les deux'];

// ── Stockage ────────────────────────────────────────────────────────────────

function eq_dir(): string
{
    return PORTAIL_ROOT . '/data/equipe';
}

/** Dossier de stockage créé à la demande (membres, candidatures…). */
function eq_store(string $sub = ''): string
{
    $dir = eq_dir() . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Lit un fichier JSON, ou $default s'il est absent ou illisible. */
function eq_read(string $file, $default = null)
{
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : $default;
}

/** Écrit un fichier JSON (écriture atomique par fichier temporaire). */
function eq_write(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function eq_new_id(): string
{
    return bin2hex(random_bytes(8));
}

/** Identifiant valide (16 caractères hexadécimaux) ? */
function eq_valid_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
}

/** Texte nettoyé et borné. */
function eq_text($value, int $max = 200): string
{
    $text = trim((string) ($value ?? ''));
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return mb_substr($text, 0, $max);
}

/** Texte libre sur plusieurs lignes (message, contreparties, notes). */
function eq_paragraph($value, int $max = 2000): string
{
    $text = trim(str_replace("\r\n", "\n", (string) ($value ?? '')));
    return mb_substr($text, 0, $max);
}

/** Valeur d'un tableau associatif, sinon la première clé (jamais vide si le tableau ne l'est pas). */
function eq_choice($value, array $choices, string $fallback): string
{
    $key = (string) ($value ?? '');
    if (isset($choices[$key])) return $key;
    if (isset($choices[$fallback])) return $fallback;
    return (string) array_key_first($choices);
}

/** Liste de domaines limitée aux clés connues, sans doublon. */
function eq_domains($value): array
{
    $out = [];
    foreach ((array) $value as $domain) {
        $key = (string) $domain;
        if (isset(EQ_DOMAINS[$key]) && !in_array($key, $out, true)) $out[] = $key;
    }
    return $out;
}

// ── Membres ─────────────────────────────────────────────────────────────────

/** Fiche membre complète, valeurs sûres. */
function eq_member_normalize(array $m): array
{
    $id = (string) ($m['id'] ?? '');
    if (!eq_valid_id($id)) $id = eq_new_id();
    $partner = is_array($m['partenaire'] ?? null) ? $m['partenaire'] : [];
    $tester = is_array($m['testeur'] ?? null) ? $m['testeur'] : [];
    $name = eq_text($m['name'] ?? '', 120);
    if ($name === '') {
        $name = trim(eq_text($m['first'] ?? '', 60) . ' ' . eq_text($m['last'] ?? '', 60));
    }
    $email = mb_strtolower(eq_text($m['email'] ?? '', 190));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    return [
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'phone' => eq_text($m['phone'] ?? '', 40),
        'city' => eq_text($m['city'] ?? '', 90),
        'category' => eq_choice($m['category'] ?? '', EQ_CATEGORIES, 'conseiller'),
        'domains' => eq_domains($m['domains'] ?? []),
        'status' => eq_choice($m['status'] ?? '', EQ_STATUSES, 'actif'),
        'source' => eq_text($m['source'] ?? 'portail', 20),
        'invitation' => eq_text($m['invitation'] ?? '', 30),
        'dispo' => eq_text($m['dispo'] ?? '', 120),
        'heures' => eq_text($m['heures'] ?? '', 60),
        'note' => eq_paragraph($m['note'] ?? '', 2000),
        'testeur' => [
            'mode' => eq_choice($tester['mode'] ?? '', EQ_TEST_MODES, 'reel'),
            'outils' => eq_paragraph($tester['outils'] ?? '', 600),
        ],
        'partenaire' => [
            'nature' => eq_choice($partner['nature'] ?? '', EQ_PARTNER_NATURES, 'a_definir'),
            'montant' => eq_text($partner['montant'] ?? '', 60),
            'echeance' => eq_text($partner['echeance'] ?? '', 60),
            'contreparties' => eq_paragraph($partner['contreparties'] ?? '', 1200),
            'statut' => eq_choice($partner['statut'] ?? '', EQ_PARTNER_STATUSES, 'a_definir'),
            'notes' => eq_paragraph($partner['notes'] ?? '', 1200),
        ],
        'created' => (int) ($m['created'] ?? time()),
        'updated' => (int) ($m['updated'] ?? time()),
    ];
}

function eq_member_load(string $id): ?array
{
    if (!eq_valid_id($id)) return null;
    $data = eq_read(eq_dir() . '/membres/' . $id . '.json');
    return $data ? eq_member_normalize($data) : null;
}

function eq_member_save(array $member): ?array
{
    $member = eq_member_normalize($member);
    $member['updated'] = time();
    return eq_write(eq_dir() . '/membres/' . $member['id'] . '.json', $member) ? $member : null;
}

function eq_member_delete(string $id): bool
{
    if (!eq_valid_id($id)) return false;
    $file = eq_dir() . '/membres/' . $id . '.json';
    return is_file($file) ? @unlink($file) : false;
}

/**
 * Tous les membres, triés par nom. Filtres facultatifs : catégorie, domaine,
 * statut, recherche libre (nom, e-mail, ville, note).
 */
function eq_members(array $filter = []): array
{
    $out = [];
    foreach (glob(eq_dir() . '/membres/*.json') ?: [] as $file) {
        if ($m = eq_member_load(basename($file, '.json'))) $out[] = $m;
    }
    $q = mb_strtolower(trim((string) ($filter['q'] ?? '')));
    $category = (string) ($filter['category'] ?? '');
    $domain = (string) ($filter['domain'] ?? '');
    $status = (string) ($filter['status'] ?? '');
    $out = array_values(array_filter($out, static function (array $m) use ($q, $category, $domain, $status): bool {
        if ($category !== '' && $m['category'] !== $category) return false;
        if ($domain !== '' && !in_array($domain, $m['domains'], true)) return false;
        if ($status !== '' && $m['status'] !== $status) return false;
        if ($q !== '') {
            $hay = mb_strtolower(implode(' ', [$m['name'], $m['email'], $m['phone'], $m['city'], $m['note'],
                implode(' ', $m['domains']), $m['partenaire']['montant'], $m['partenaire']['echeance']]));
            if (!str_contains($hay, $q)) return false;
        }
        return true;
    }));
    usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'] !== '' ? $a['name'] : $a['email'], $b['name'] !== '' ? $b['name'] : $b['email']));
    return $out;
}

/** Membre déjà enregistré avec cette adresse (ou ce téléphone), pour l'import. */
function eq_member_find(string $email, string $phone = ''): ?array
{
    $email = mb_strtolower(trim($email));
    $phone = preg_replace('/\D+/', '', $phone);
    foreach (glob(eq_dir() . '/membres/*.json') ?: [] as $file) {
        $m = eq_member_load(basename($file, '.json'));
        if (!$m) continue;
        if ($email !== '' && $m['email'] === $email) return $m;
        if ($email === '' && $phone !== '' && preg_replace('/\D+/', '', $m['phone']) === $phone) return $m;
    }
    return null;
}

/**
 * Crée un membre depuis un formulaire (portail ou import). $input accepte les
 * clés de la fiche ; les champs partenaire/testeur sont imbriqués ou à plat.
 * Retourne [membre, erreurs].
 */
function eq_member_from_input(array $input): array
{
    $errors = [];
    $name = eq_text($input['name'] ?? trim(($input['first'] ?? '') . ' ' . ($input['last'] ?? '')), 120);
    if ($name === '') $errors[] = 'Indiquez au moins un nom.';
    $email = mb_strtolower(eq_text($input['email'] ?? '', 190));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'adresse e-mail n'est pas valide.";
    if ($email === '' && eq_text($input['phone'] ?? '', 40) === '' && $name === '') $errors[] = 'Indiquez un nom, une adresse e-mail ou un téléphone.';
    if ($email !== '') {
        $existing = eq_member_find($email);
        if ($existing && $existing['id'] !== (string) ($input['id'] ?? '')) $errors[] = 'Un membre utilise déjà cette adresse e-mail.';
    }
    if ($errors) return [null, $errors];

    $partner = is_array($input['partenaire'] ?? null) ? $input['partenaire'] : $input;
    $tester = is_array($input['testeur'] ?? null) ? $input['testeur'] : $input;
    $member = eq_member_normalize([
        'id' => (string) ($input['id'] ?? ''),
        'name' => $name,
        'email' => $email,
        'phone' => $input['phone'] ?? '',
        'city' => $input['city'] ?? '',
        'category' => $input['category'] ?? '',
        'domains' => $input['domains'] ?? [],
        'status' => $input['status'] ?? 'actif',
        'source' => $input['source'] ?? 'portail',
        'invitation' => $input['invitation'] ?? '',
        'dispo' => $input['dispo'] ?? '',
        'heures' => $input['heures'] ?? '',
        'note' => $input['note'] ?? '',
        'testeur' => ['mode' => $tester['mode'] ?? 'reel', 'outils' => $tester['outils'] ?? ''],
        'partenaire' => [
            'nature' => $partner['nature'] ?? 'a_definir',
            'montant' => $partner['montant'] ?? '',
            'echeance' => $partner['echeance'] ?? '',
            'contreparties' => $partner['contreparties'] ?? '',
            'statut' => $partner['statut'] ?? 'a_definir',
            'notes' => $partner['notes'] ?? '',
        ],
        'created' => $input['created'] ?? time(),
    ]);
    return [$member, []];
}

// ── Conditions des partenaires ──────────────────────────────────────────────

/** Les champs de conditions d'un partenaire, nettoyés. */
function eq_partner_from_input(array $input): array
{
    return [
        'nature' => eq_choice($input['nature'] ?? '', EQ_PARTNER_NATURES, 'a_definir'),
        'montant' => eq_text($input['montant'] ?? '', 60),
        'echeance' => eq_text($input['echeance'] ?? '', 60),
        'contreparties' => eq_paragraph($input['contreparties'] ?? '', 1200),
        'statut' => eq_choice($input['statut'] ?? '', EQ_PARTNER_STATUSES, 'a_definir'),
        'notes' => eq_paragraph($input['notes'] ?? '', 1200),
    ];
}

/** Ce partenaire a-t-il encore des conditions à définir (aucune condition posée) ? */
function eq_partner_open(array $member): bool
{
    $p = $member['partenaire'];
    return $p['statut'] !== 'signe' && ($p['nature'] === 'a_definir' || ($p['montant'] === '' && $p['contreparties'] === ''));
}

// ── Candidatures ────────────────────────────────────────────────────────────

const EQ_APPLICATION_STATUSES = [
    'nouvelle' => 'À traiter',
    'acceptee' => 'Acceptée',
    'refusee' => 'Refusée',
    'archivee' => 'Archivée',
];

function eq_application_normalize(array $a): array
{
    $id = (string) ($a['id'] ?? '');
    if (!eq_valid_id($id)) $id = eq_new_id();
    $first = eq_text($a['first'] ?? '', 60);
    $last = eq_text($a['last'] ?? '', 60);
    $name = eq_text($a['name'] ?? '', 120);
    if ($name === '') $name = trim($first . ' ' . $last);
    $partner = is_array($a['partner'] ?? null) ? $a['partner'] : [];
    return [
        'id' => $id,
        'name' => $name,
        'first' => $first,
        'last' => $last,
        'email' => mb_strtolower(eq_text($a['email'] ?? '', 190)),
        'phone' => eq_text($a['phone'] ?? '', 40),
        'city' => eq_text($a['city'] ?? '', 90),
        'category' => eq_choice($a['category'] ?? '', EQ_CATEGORIES, 'conseiller'),
        'domains' => eq_domains($a['domains'] ?? []),
        'mode' => eq_choice($a['mode'] ?? '', EQ_TEST_MODES, 'reel'),
        'outils' => eq_paragraph($a['outils'] ?? '', 600),
        'heures' => eq_text($a['heures'] ?? '', 60),
        'dispo' => eq_text($a['dispo'] ?? '', 200),
        'message' => eq_paragraph($a['message'] ?? '', 2000),
        'nature' => eq_choice($partner['nature'] ?? ($a['nature'] ?? ''), EQ_PARTNER_NATURES, 'a_definir'),
        'montant' => eq_text($partner['montant'] ?? ($a['montant'] ?? ''), 60),
        'echeance' => eq_text($partner['echeance'] ?? ($a['echeance'] ?? ''), 60),
        'source' => eq_text($a['source'] ?? 'lien', 20),
        'invitation' => eq_text($a['invitation'] ?? '', 30),
        'status' => eq_choice($a['status'] ?? '', EQ_APPLICATION_STATUSES, 'nouvelle'),
        'created' => (int) ($a['created'] ?? time()),
        'decided' => (int) ($a['decided'] ?? 0),
        'reason' => eq_paragraph($a['reason'] ?? '', 600),
        'member_id' => eq_text($a['member_id'] ?? '', 20),
    ];
}

function eq_application_load(string $id): ?array
{
    if (!eq_valid_id($id)) return null;
    $data = eq_read(eq_dir() . '/candidatures/' . $id . '.json');
    return $data ? eq_application_normalize($data) : null;
}

function eq_application_save(array $application): ?array
{
    $application = eq_application_normalize($application);
    return eq_write(eq_dir() . '/candidatures/' . $application['id'] . '.json', $application) ? $application : null;
}

function eq_application_delete(string $id): bool
{
    if (!eq_valid_id($id)) return false;
    $file = eq_dir() . '/candidatures/' . $id . '.json';
    return is_file($file) ? @unlink($file) : false;
}

/** Candidatures, les plus récentes d'abord. */
function eq_applications(string $status = ''): array
{
    $out = [];
    foreach (glob(eq_dir() . '/candidatures/*.json') ?: [] as $file) {
        if ($a = eq_application_load(basename($file, '.json'))) $out[] = $a;
    }
    if ($status !== '') $out = array_values(array_filter($out, static fn (array $a): bool => $a['status'] === $status));
    usort($out, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);
    return $out;
}

function eq_application_pending_count(): int
{
    return count(eq_applications('nouvelle'));
}

// ── Invitations et réglages ─────────────────────────────────────────────────

function eq_settings(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $defaults = ['code' => '', 'notify' => '', 'open' => false];
    $settings = eq_read(eq_dir() . '/settings.json', []) + $defaults;
    if (!preg_match('/^[A-Z0-9]{6,10}$/', (string) $settings['code'])) {
        $settings['code'] = strtoupper(bin2hex(random_bytes(4)));
        eq_write(eq_dir() . '/settings.json', $settings);
    }
    $settings['notify'] = eq_text($settings['notify'], 190);
    $settings['open'] = !empty($settings['open']);
    return $cache = $settings;
}

function eq_settings_save(array $settings): bool
{
    $saved = eq_write(eq_dir() . '/settings.json', [
        'code' => (string) ($settings['code'] ?? ''),
        'notify' => eq_text($settings['notify'] ?? '', 190),
        'open' => !empty($settings['open']),
    ]);
    return $saved;
}

function eq_code_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function eq_invitations(): array
{
    $rows = eq_read(eq_dir() . '/invitations.json', []);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $out[] = [
            'code' => (string) ($row['code'] ?? ''),
            'label' => eq_text($row['label'] ?? '', 80),
            'category' => isset(EQ_CATEGORIES[(string) ($row['category'] ?? '')]) ? (string) $row['category'] : '',
            'domain' => isset(EQ_DOMAINS[(string) ($row['domain'] ?? '')]) ? (string) $row['domain'] : '',
            'created' => (int) ($row['created'] ?? 0),
            'expires' => (int) ($row['expires'] ?? 0),
            'max' => (int) ($row['max'] ?? 0),
            'uses' => (int) ($row['uses'] ?? 0),
            'active' => !empty($row['active']),
        ];
    }
    usort($out, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);
    return $out;
}

function eq_invitation_save(array $invitation): bool
{
    $code = eq_code_normalize((string) ($invitation['code'] ?? ''));
    if ($code === '') return false;
    $rows = eq_read(eq_dir() . '/invitations.json', []);
    $found = false;
    foreach ($rows as $i => $row) {
        if (eq_code_normalize((string) ($row['code'] ?? '')) === $code) {
            $rows[$i] = array_merge($row, $invitation, ['code' => $code]);
            $found = true;
        }
    }
    if (!$found) $rows[] = array_merge(['created' => time(), 'expires' => 0, 'max' => 0, 'uses' => 0, 'active' => true], $invitation, ['code' => $code]);
    return eq_write(eq_dir() . '/invitations.json', array_values($rows));
}

function eq_invitation_create(array $input): array
{
    $invitation = [
        'code' => strtoupper(bin2hex(random_bytes(4))),
        'label' => eq_text($input['label'] ?? '', 80),
        'category' => isset(EQ_CATEGORIES[(string) ($input['category'] ?? '')]) ? (string) $input['category'] : '',
        'domain' => isset(EQ_DOMAINS[(string) ($input['domain'] ?? '')]) ? (string) $input['domain'] : '',
        'created' => time(),
        'expires' => (int) ($input['days'] ?? 0) > 0 ? time() + (int) $input['days'] * 86400 : 0,
        'max' => max(0, min(500, (int) ($input['max'] ?? 0))),
        'uses' => 0,
        'active' => true,
    ];
    return eq_invitation_save($invitation) ? $invitation : [];
}

function eq_invitation_delete(string $code): bool
{
    $code = eq_code_normalize($code);
    $rows = eq_read(eq_dir() . '/invitations.json', []);
    $kept = array_values(array_filter($rows, static fn ($row): bool => eq_code_normalize((string) ($row['code'] ?? '')) !== $code));
    return count($kept) !== count($rows) && eq_write(eq_dir() . '/invitations.json', $kept);
}

/** Invitation encore utilisable (active, non expirée, quota restant). */
function eq_invitation_find(string $code): ?array
{
    $code = eq_code_normalize($code);
    if ($code === '') return null;
    foreach (eq_invitations() as $invitation) {
        if ($invitation['code'] !== $code) continue;
        if (!$invitation['active']) return null;
        if ($invitation['expires'] > 0 && $invitation['expires'] < time()) return null;
        if ($invitation['max'] > 0 && $invitation['uses'] >= $invitation['max']) return null;
        return $invitation;
    }
    return null;
}

function eq_invitation_use(string $code): void
{
    $code = eq_code_normalize($code);
    $rows = eq_read(eq_dir() . '/invitations.json', []);
    foreach ($rows as $i => $row) {
        if (eq_code_normalize((string) ($row['code'] ?? '')) === $code) {
            $rows[$i]['uses'] = (int) ($row['uses'] ?? 0) + 1;
            eq_write(eq_dir() . '/invitations.json', array_values($rows));
            return;
        }
    }
}

/**
 * L'inscription est-elle ouverte ? Par invitation valide, par le code d'accès
 * de l'équipe, ou parce que l'exploitant a ouvert l'inscription libre.
 */
function eq_access_valid(string $code): bool
{
    $settings = eq_settings();
    if ($settings['open']) return true;
    $normalized = eq_code_normalize($code);
    if ($normalized !== '' && hash_equals($settings['code'], $normalized)) return true;
    return eq_invitation_find($normalized) !== null;
}

// ── Chiffres ────────────────────────────────────────────────────────────────

/** Effectifs par catégorie et statut, candidatures en attente, partenaires à cadrer. */
function eq_stats(): array
{
    $members = eq_members();
    $byCategory = array_fill_keys(array_keys(EQ_CATEGORIES), 0);
    $byStatus = array_fill_keys(array_keys(EQ_STATUSES), 0);
    $active = 0;
    $partnersOpen = 0;
    foreach ($members as $m) {
        $byCategory[$m['category']] = ($byCategory[$m['category']] ?? 0) + 1;
        $byStatus[$m['status']] = ($byStatus[$m['status']] ?? 0) + 1;
        if ($m['status'] === 'actif') $active++;
        if ($m['category'] === 'partenaire' && $m['status'] !== 'archive' && eq_partner_open($m)) $partnersOpen++;
    }
    return [
        'total' => count($members),
        'actifs' => $active,
        'par_categorie' => $byCategory,
        'par_statut' => $byStatus,
        'attente' => eq_application_pending_count(),
        'partenaires_a_definir' => $partnersOpen,
    ];
}

// ── E-mail ──────────────────────────────────────────────────────────────────

/**
 * Ajoute une ligne au journal d'activité du portail (data/saas/events.jsonl),
 * au même format que saas_event(). Écrit ici pour ne pas charger toute la
 * vitrine SaaS depuis la page publique d'inscription.
 */
function eq_event(string $type, array $data = []): void
{
    try {
        $dir = PORTAIL_ROOT . '/data/saas';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
        if (!isset($data['actor'])) $data['actor'] = str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/portail/') ? 'exploitant' : 'client';
        $row = ['ts' => time(), 'type' => $type] + array_filter($data, static fn ($v) => $v !== null && $v !== '');
        @file_put_contents($dir . '/events.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('eq_event : ' . $e->getMessage());
    }
}

/** Message simple (UTF-8) ; en local, il est aussi écrit dans data/mail-outbox.log. */
function eq_mail(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $local = !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP);
    if ($local) {
        @file_put_contents(PORTAIL_ROOT . '/data/mail-outbox.log', '[' . date('c') . "] À : $to\nObjet : $subject\n$body\n----\n", FILE_APPEND | LOCK_EX);
    }
    $from = 'noreply@' . (!$local ? preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', $host) : 'localhost.localdomain');
    $headers = "From: Équipe Brocs <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    require_once __DIR__ . '/portal-mailer.php';
    if (!$local && portal_mailer_dispatch("Équipe Brocs <$from>", $to, '=?UTF-8?B?' . base64_encode($subject) . '?=',
        ['Content-Type: text/plain; charset=UTF-8', 'MIME-Version: 1.0'], $body) === true) {
        return true;
    }
    return function_exists('mail') && @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/** Prévient l'exploitant qu'une candidature est arrivée (si un e-mail est réglé). */
function eq_notify_application(array $application): bool
{
    $settings = eq_settings();
    if ($settings['notify'] === '') return false;
    $summary = [
        'Nom : ' . $application['name'],
        'E-mail : ' . ($application['email'] !== '' ? $application['email'] : '—'),
        'Téléphone : ' . ($application['phone'] !== '' ? $application['phone'] : '—'),
        'Ville : ' . ($application['city'] !== '' ? $application['city'] : '—'),
        'Catégorie : ' . EQ_CATEGORIES[$application['category']]['label'],
        'Domaines : ' . (($application['domains'] ? implode(', ', array_map(static fn ($d) => EQ_DOMAINS[$d], $application['domains'])) : '—')),
        'Disponibilité : ' . ($application['heures'] !== '' ? $application['heures'] : '—') . ($application['dispo'] !== '' ? ' · ' . $application['dispo'] : ''),
        'Message : ' . ($application['message'] !== '' ? "\n" . $application['message'] : '—'),
    ];
    $origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return eq_mail($settings['notify'], 'Nouvelle candidature équipe — ' . $application['name'],
        "Une candidature vient d'être déposée depuis le parcours d'inscription.\n\n" . implode("\n", $summary)
        . "\n\nÀ traiter : " . $origin . "/portail/equipe.php?vue=candidatures\n");
}

// ── Export ──────────────────────────────────────────────────────────────────

/** Lignes de l'export CSV de l'équipe (en-tête compris). */
function eq_export_rows(array $members): array
{
    $rows = [['Nom', 'E-mail', 'Téléphone', 'Ville', 'Catégorie', 'Domaines', 'Statut', 'Profil', 'Nature partenaire',
        'Montant', 'Échéance', 'Statut accord', 'Contreparties', 'Disponibilité', 'Note', 'Source', 'Créé le']];
    foreach ($members as $m) {
        $profile = $m['category'] === 'testeur'
            ? (EQ_TEST_MODES[$m['testeur']['mode']] ?? '')
            : ($m['category'] === 'partenaire' ? EQ_PARTNER_STATUSES[$m['partenaire']['statut']] : '');
        $rows[] = [
            $m['name'], $m['email'], $m['phone'], $m['city'],
            EQ_CATEGORIES[$m['category']]['label'],
            implode(', ', array_map(static fn ($d) => EQ_DOMAINS[$d], $m['domains'])),
            EQ_STATUSES[$m['status']],
            $profile,
            EQ_PARTNER_NATURES[$m['partenaire']['nature']],
            $m['partenaire']['montant'], $m['partenaire']['echeance'],
            EQ_PARTNER_STATUSES[$m['partenaire']['statut']],
            str_replace("\n", ' ', $m['partenaire']['contreparties']),
            trim($m['heures'] . ($m['dispo'] !== '' ? ' · ' . $m['dispo'] : '')),
            str_replace("\n", ' ', $m['note']),
            $m['source'],
            date('Y-m-d', $m['created']),
        ];
    }
    return $rows;
}
