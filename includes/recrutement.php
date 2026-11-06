<?php

// Recrutement du projet : trois pistes — emploi/freelance, primo aidants
// (amis et famille) et bénévoles de vide-grenier — avec des besoins publiés,
// un parcours de candidature public et un pipeline de suivi dans le portail.
//
// Tout vit en JSON dans data/recrutement/ (jamais servi en HTTP, data/ est
// refusé par .htaccess) :
//   offres/<id>.json        un poste, une mission de bénévolat
//   candidatures/<id>.json  une candidature reçue
//   settings.json           ouverture, e-mail de notification, introduction
//
// La piste « primo aidant » peut être renvoyée vers le module Équipe
// (includes/equipe.php) une fois la personne retenue : c'est l'équipe qui
// reste la source de vérité pour les proches qui aident le projet.

if (!defined('PORTAIL_ROOT')) {
    define('PORTAIL_ROOT', dirname(__DIR__));
}

const RC_TRACKS = [
    'emploi' => [
        'label' => 'Emploi & freelance',
        'short' => 'Postes',
        'desc' => "Salariés, indépendants ou prestataires pour le design, le fonctionnel, le financement et les tests.",
        'cta' => 'Voir les postes',
    ],
    'primo' => [
        'label' => 'Primo aidants',
        'short' => 'Proches',
        'desc' => "Amis et famille qui donnent un coup de main pour boucler le projet : design, fonctionnel, financement, tests.",
        'cta' => 'Aider le projet',
    ],
    'benevole' => [
        'label' => 'Bénévoles vide-grenier',
        'short' => 'Bénévoles',
        'desc' => "Coups de main sur les vides-grenier : installation, accueil, tenue du stand, démontage.",
        'cta' => 'Donner un coup de main',
    ],
];

const RC_DOMAINS = [
    'design' => 'Design & expérience',
    'fonctionnel' => 'Fonctionnel & recette',
    'developpement' => 'Développement',
    'financement' => 'Financement & modèle économique',
    'tests' => 'Tests & qualité',
    'logistique' => 'Logistique & terrain',
    'accueil' => 'Accueil & animation',
    'communication' => 'Communication',
    'autre' => 'Autre / à préciser',
];

const RC_CONTRACTS = [
    'cdi' => 'CDI',
    'cdd' => 'CDD',
    'freelance' => 'Freelance / indépendant',
    'prestation' => 'Prestation ponctuelle',
    'stage' => 'Stage / alternance',
    'benevolat' => 'Bénévolat',
    'a_definir' => 'À définir',
];

const RC_OFFER_STATUSES = [
    'ouvert' => 'Ouvert',
    'ferme' => 'Fermé',
    'pourvu' => 'Pourvu',
];

const RC_STATUSES = [
    'recue' => 'À traiter',
    'contactee' => 'Contacté',
    'entretien' => 'Entretien',
    'retenue' => 'Retenu',
    'integree' => 'Intégré',
    'ecartee' => 'Écarté',
];

const RC_OPEN_STATUSES = ['recue', 'contactee', 'entretien', 'retenue'];
const RC_FINAL_STATUSES = ['integree', 'ecartee'];

const RC_AVAILABILITIES = [
    '1-2 h par semaine',
    '3-5 h par semaine',
    '6-10 h par semaine',
    'Plus de 10 h par semaine',
    'Ponctuel, selon les besoins',
];

// ── Stockage ────────────────────────────────────────────────────────────────

function rc_data_dir(): string
{
    return defined('RC_DATA_DIR') ? rtrim((string) RC_DATA_DIR, '/') : PORTAIL_ROOT . '/data/recrutement';
}

function rc_dir(): string
{
    return rc_data_dir();
}

/** Sous-dossier créé à la demande (offres, candidatures). */
function rc_store(string $sub = ''): string
{
    $dir = rc_dir() . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function rc_read(string $file, $default = null)
{
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : $default;
}

function rc_write(string $file, array $data): bool
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

function rc_new_id(): string
{
    return bin2hex(random_bytes(8));
}

function rc_valid_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
}

/** Code public court d'un besoin : 8 caractères, partageable dans un lien. */
function rc_new_code(): string
{
    return strtoupper(bin2hex(random_bytes(4)));
}

function rc_text($value, int $max = 200): string
{
    $text = trim((string) ($value ?? ''));
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return mb_substr($text, 0, $max);
}

function rc_paragraph($value, int $max = 3000): string
{
    $text = trim(str_replace("\r\n", "\n", (string) ($value ?? '')));
    return mb_substr($text, 0, $max);
}

function rc_choice($value, array $choices, string $fallback): string
{
    $key = (string) ($value ?? '');
    if (isset($choices[$key])) return $key;
    if (isset($choices[$fallback])) return $fallback;
    return (string) array_key_first($choices);
}

/** Domaines limités aux clés connues, sans doublon. */
function rc_domains($value): array
{
    $out = [];
    foreach ((array) $value as $domain) {
        $key = (string) $domain;
        if (isset(RC_DOMAINS[$key]) && !in_array($key, $out, true)) $out[] = $key;
    }
    return $out;
}

function rc_url($value): string
{
    $url = trim((string) ($value ?? ''));
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
    return mb_substr(filter_var($url, FILTER_VALIDATE_URL) ?: '', 0, 300);
}

// ── Besoins (postes et missions) ───────────────────────────────────────────

function rc_offer_normalize(array $o): array
{
    $id = (string) ($o['id'] ?? '');
    if (!rc_valid_id($id)) $id = rc_new_id();
    $track = rc_choice($o['track'] ?? '', RC_TRACKS, 'emploi');
    $contract = rc_choice($o['contract'] ?? '', RC_CONTRACTS, $track === 'benevole' ? 'benevolat' : ($track === 'primo' ? 'a_definir' : 'freelance'));
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($o['code'] ?? '')) ?? '');
    if (!preg_match('/^[A-Z0-9]{6,10}$/', $code)) $code = rc_new_code();
    $date = trim((string) ($o['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
    $deadline = trim((string) ($o['deadline'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) $deadline = '';
    return [
        'id' => $id,
        'code' => $code,
        'track' => $track,
        'title' => rc_text($o['title'] ?? '', 120),
        'contract' => $contract,
        'domains' => rc_domains($o['domains'] ?? []),
        'description' => rc_paragraph($o['description'] ?? '', 3000),
        'missions' => rc_paragraph($o['missions'] ?? '', 3000),
        'profile' => rc_paragraph($o['profile'] ?? '', 2000),
        'compensation' => rc_text($o['compensation'] ?? '', 200),
        'location' => rc_text($o['location'] ?? '', 120),
        'remote' => !empty($o['remote']),
        'slots' => max(1, min(500, (int) ($o['slots'] ?? 1))),
        'event' => preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', (string) ($o['event'] ?? '')) ? (string) $o['event'] : '',
        'date' => $date,
        'deadline' => $deadline,
        'status' => rc_choice($o['status'] ?? '', RC_OFFER_STATUSES, 'ouvert'),
        'created' => (int) ($o['created'] ?? time()),
        'updated' => (int) ($o['updated'] ?? time()),
    ];
}

function rc_offer_load(string $id): ?array
{
    if (!rc_valid_id($id)) return null;
    $data = rc_read(rc_dir() . '/offres/' . $id . '.json');
    return $data ? rc_offer_normalize($data) : null;
}

function rc_offer_save(array $offer): ?array
{
    $offer = rc_offer_normalize($offer);
    $offer['updated'] = time();
    return rc_write(rc_dir() . '/offres/' . $offer['id'] . '.json', $offer) ? $offer : null;
}

function rc_offer_delete(string $id): bool
{
    if (!rc_valid_id($id)) return false;
    $file = rc_dir() . '/offres/' . $id . '.json';
    return is_file($file) ? @unlink($file) : false;
}

/** Tous les besoins : ouverts d'abord, puis par date de création décroissante. */
function rc_offers(array $filter = []): array
{
    $out = [];
    foreach (glob(rc_dir() . '/offres/*.json') ?: [] as $file) {
        if ($o = rc_offer_load(basename($file, '.json'))) $out[] = $o;
    }
    $track = (string) ($filter['track'] ?? '');
    $status = (string) ($filter['status'] ?? '');
    $q = mb_strtolower(trim((string) ($filter['q'] ?? '')));
    $out = array_values(array_filter($out, static function (array $o) use ($track, $status, $q): bool {
        if ($track !== '' && $o['track'] !== $track) return false;
        if ($status !== '' && $o['status'] !== $status) return false;
        if ($q !== '') {
            $hay = mb_strtolower(implode(' ', [$o['title'], $o['description'], $o['location'], $o['compensation'], implode(' ', $o['domains'])]));
            if (!str_contains($hay, $q)) return false;
        }
        return true;
    }));
    usort($out, static function (array $a, array $b): int {
        $rank = ['ouvert' => 0, 'ferme' => 1, 'pourvu' => 2];
        return [$rank[$a['status']] ?? 3, -$a['created']] <=> [$rank[$b['status']] ?? 3, -$b['created']];
    });
    return $out;
}

function rc_offer_find_code(string $code): ?array
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    if ($code === '') return null;
    foreach (glob(rc_dir() . '/offres/*.json') ?: [] as $file) {
        if ($o = rc_offer_load(basename($file, '.json'))) {
            if (hash_equals($o['code'], $code)) return $o;
        }
    }
    return null;
}

function rc_offer_is_open(array $offer): bool
{
    if ($offer['status'] !== 'ouvert') return false;
    if ($offer['deadline'] !== '' && $offer['deadline'] < date('Y-m-d')) return false;
    return true;
}

/** Besoin créé depuis un formulaire (création ou modification). */
function rc_offer_from_input(array $input, ?array $existing = null): array
{
    $errors = [];
    $title = rc_text($input['title'] ?? '', 120);
    if ($title === '') $errors[] = 'Donnez un titre au besoin (ex. « Développeur PHP freelance »).';
    $track = (string) ($input['track'] ?? '');
    if (!isset(RC_TRACKS[$track])) $errors[] = 'Choisissez une piste : emploi, primo aidant ou bénévole.';
    if (!$errors && empty(rc_domains($input['domains'] ?? [])) && !empty($input['domains_required'])) {
        $errors[] = 'Choisissez au moins un domaine.';
    }
    $offer = rc_offer_normalize([
        'id' => $existing['id'] ?? '',
        'code' => $existing['code'] ?? '',
        'track' => $track,
        'title' => $title,
        'contract' => $input['contract'] ?? '',
        'domains' => $input['domains'] ?? [],
        'description' => $input['description'] ?? '',
        'missions' => $input['missions'] ?? '',
        'profile' => $input['profile'] ?? '',
        'compensation' => $input['compensation'] ?? '',
        'location' => $input['location'] ?? '',
        'remote' => !empty($input['remote']),
        'slots' => $input['slots'] ?? 1,
        'event' => $input['event'] ?? '',
        'date' => $input['date'] ?? '',
        'deadline' => $input['deadline'] ?? '',
        'status' => $input['status'] ?? 'ouvert',
        'created' => $existing['created'] ?? time(),
    ]);
    return [$offer, $errors];
}

// ── Candidatures ───────────────────────────────────────────────────────────

function rc_application_normalize(array $a): array
{
    $id = (string) ($a['id'] ?? '');
    if (!rc_valid_id($id)) $id = rc_new_id();
    $first = rc_text($a['first'] ?? '', 60);
    $last = rc_text($a['last'] ?? '', 60);
    $name = rc_text($a['name'] ?? '', 120);
    if ($name === '') $name = trim($first . ' ' . $last);
    $notes = [];
    foreach ((array) ($a['notes'] ?? []) as $note) {
        if (!is_array($note)) continue;
        $text = rc_paragraph($note['text'] ?? '', 1500);
        if ($text === '') continue;
        $notes[] = ['ts' => (int) ($note['ts'] ?? time()), 'text' => $text];
    }
    return [
        'id' => $id,
        'offer_id' => rc_text($a['offer_id'] ?? '', 20),
        'offer_code' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($a['offer_code'] ?? '')) ?? ''),
        'track' => rc_choice($a['track'] ?? '', RC_TRACKS, 'emploi'),
        'name' => $name,
        'first' => $first,
        'last' => $last,
        'email' => mb_strtolower(rc_text($a['email'] ?? '', 190)),
        'phone' => rc_text($a['phone'] ?? '', 40),
        'city' => rc_text($a['city'] ?? '', 90),
        'domains' => rc_domains($a['domains'] ?? []),
        'contract' => rc_choice($a['contract'] ?? '', RC_CONTRACTS, 'a_definir'),
        'availability' => rc_text($a['availability'] ?? '', 120),
        'experience' => rc_paragraph($a['experience'] ?? '', 2500),
        'portfolio' => rc_url($a['portfolio'] ?? ''),
        'salary' => rc_text($a['salary'] ?? '', 80),
        'tasks' => rc_paragraph($a['tasks'] ?? '', 1200),
        'mobility' => rc_text($a['mobility'] ?? '', 120),
        'event_date' => rc_text($a['event_date'] ?? '', 80),
        'motivation' => rc_paragraph($a['motivation'] ?? '', 2500),
        'consent' => !empty($a['consent']),
        'source' => rc_text($a['source'] ?? 'lien', 20),
        'status' => rc_choice($a['status'] ?? '', RC_STATUSES, 'recue'),
        'created' => (int) ($a['created'] ?? time()),
        'updated' => (int) ($a['updated'] ?? time()),
        'decided' => (int) ($a['decided'] ?? 0),
        'reason' => rc_paragraph($a['reason'] ?? '', 600),
        'notes' => $notes,
        'team_sent' => !empty($a['team_sent']),
        'team_ref' => rc_text($a['team_ref'] ?? '', 20),
    ];
}

function rc_application_load(string $id): ?array
{
    if (!rc_valid_id($id)) return null;
    $data = rc_read(rc_dir() . '/candidatures/' . $id . '.json');
    return $data ? rc_application_normalize($data) : null;
}

function rc_application_save(array $application): ?array
{
    $application = rc_application_normalize($application);
    $application['updated'] = time();
    return rc_write(rc_dir() . '/candidatures/' . $application['id'] . '.json', $application) ? $application : null;
}

function rc_application_delete(string $id): bool
{
    if (!rc_valid_id($id)) return false;
    $file = rc_dir() . '/candidatures/' . $id . '.json';
    return is_file($file) ? @unlink($file) : false;
}

/** Candidatures, les plus récentes d'abord ; filtres piste, statut, besoin, recherche. */
function rc_applications(array $filter = []): array
{
    $out = [];
    foreach (glob(rc_dir() . '/candidatures/*.json') ?: [] as $file) {
        if ($a = rc_application_load(basename($file, '.json'))) $out[] = $a;
    }
    $track = (string) ($filter['track'] ?? '');
    $status = (string) ($filter['status'] ?? '');
    $offer = (string) ($filter['offer'] ?? '');
    $q = mb_strtolower(trim((string) ($filter['q'] ?? '')));
    $out = array_values(array_filter($out, static function (array $a) use ($track, $status, $offer, $q): bool {
        if ($track !== '' && $a['track'] !== $track) return false;
        if ($status !== '' && $a['status'] !== $status) return false;
        if ($offer !== '' && $a['offer_id'] !== $offer) return false;
        if ($q !== '') {
            $hay = mb_strtolower(implode(' ', [$a['name'], $a['email'], $a['phone'], $a['city'], $a['motivation'],
                implode(' ', $a['domains']), $a['reason']]));
            if (!str_contains($hay, $q)) return false;
        }
        return true;
    }));
    usort($out, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);
    return $out;
}

function rc_application_pending_count(): int
{
    return count(rc_applications(['status' => 'recue']));
}

/** Nombre de candidatures actives (non écartées) sur un besoin. */
function rc_offer_candidate_count(string $offerId): int
{
    $count = 0;
    foreach (rc_applications(['offer' => $offerId]) as $a) {
        if ($a['status'] !== 'ecartee') $count++;
    }
    return $count;
}

/** Candidature déjà reçue pour ce besoin avec cette adresse (30 derniers jours). */
function rc_application_exists(string $email, string $offerId): bool
{
    $email = mb_strtolower(trim($email));
    if ($email === '') return false;
    foreach (rc_applications(['offer' => $offerId]) as $a) {
        if ($a['email'] !== $email) continue;
        if ($a['created'] > time() - 30 * 86400) return true;
    }
    return false;
}

/** Candidature préparée depuis le formulaire public. Retourne [application, erreurs]. */
function rc_application_from_input(array $input, ?array $offer = null): array
{
    $errors = [];
    $first = rc_text($input['first'] ?? '', 60);
    $last = rc_text($input['last'] ?? '', 60);
    $name = trim($first . ' ' . $last);
    if ($name === '') $name = rc_text($input['name'] ?? '', 120);
    if ($name === '') $errors[] = 'Indiquez votre prénom et votre nom.';
    $email = mb_strtolower(rc_text($input['email'] ?? '', 190));
    if ($email === '') $errors[] = 'Indiquez votre adresse e-mail.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'adresse e-mail n'est pas valide.";
    $track = $offer['track'] ?? (isset(RC_TRACKS[(string) ($input['track'] ?? '')]) ? (string) $input['track'] : '');
    if ($track === '') $errors[] = 'Choisissez une piste de candidature.';
    $domains = rc_domains($input['domains'] ?? []);
    if (!$domains) $errors[] = 'Choisissez au moins un domaine, même « Autre / à préciser ».';
    if (empty($input['consent'])) $errors[] = 'Cochez l’accord pour envoyer votre candidature.';
    if ($offer && rc_application_exists($email, $offer['id'])) {
        $errors[] = 'Une candidature avec cette adresse a déjà été reçue pour ce besoin.';
    }
    if ($errors) return [null, $errors];

    $application = rc_application_normalize([
        'offer_id' => $offer['id'] ?? '',
        'offer_code' => $offer['code'] ?? '',
        'track' => $track,
        'first' => $first,
        'last' => $last,
        'name' => $name,
        'email' => $email,
        'phone' => $input['phone'] ?? '',
        'city' => $input['city'] ?? '',
        'domains' => $domains,
        'contract' => $input['contract'] ?? ($offer['contract'] ?? ''),
        'availability' => $input['availability'] ?? '',
        'experience' => $input['experience'] ?? '',
        'portfolio' => $input['portfolio'] ?? '',
        'salary' => $input['salary'] ?? '',
        'tasks' => $input['tasks'] ?? '',
        'mobility' => $input['mobility'] ?? '',
        'event_date' => $input['event_date'] ?? '',
        'motivation' => $input['motivation'] ?? '',
        'consent' => true,
        'source' => $offer ? 'offre' : 'generique',
        'status' => 'recue',
    ]);
    return [$application, []];
}

/** Libellé du statut, adapté à la piste (le bénévolat se confirme, il ne s'intègre pas). */
function rc_status_label(string $status, string $track = ''): string
{
    if ($track === 'benevole') {
        $labels = ['retenue' => 'Retenu', 'integree' => 'Confirmé', 'entretien' => 'Point de préparation', 'contactee' => 'Contacté'];
        if (isset($labels[$status])) return $labels[$status];
    }
    return RC_STATUSES[$status] ?? $status;
}

function rc_track_label(string $track): string
{
    return RC_TRACKS[$track]['label'] ?? $track;
}

// ── Réglages ────────────────────────────────────────────────────────────────

function rc_settings_defaults(): array
{
    return [
        'notify' => '',
        'open' => true,
        'intro' => "Nous construisons les brocs : des boutiques en ligne pour les commerçants et les vide-greniers. Trois façons de nous aider, selon le temps et l'envie que vous avez.",
    ];
}

function rc_settings(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $in = rc_read(rc_dir() . '/settings.json', []);
    $d = rc_settings_defaults();
    $notify = mb_strtolower(rc_text($in['notify'] ?? '', 190));
    $cache = [
        'notify' => filter_var($notify, FILTER_VALIDATE_EMAIL) ? $notify : '',
        'open' => array_key_exists('open', $in) ? !empty($in['open']) : $d['open'],
        'intro' => rc_paragraph($in['intro'] ?? $d['intro'], 600) ?: $d['intro'],
    ];
    return $cache;
}

function rc_settings_save(array $in): bool
{
    $notify = mb_strtolower(rc_text($in['notify'] ?? '', 190));
    if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) return false;
    return rc_write(rc_dir() . '/settings.json', [
        'notify' => $notify,
        'open' => !empty($in['open']),
        'intro' => rc_paragraph($in['intro'] ?? '', 600),
    ]);
}

// ── Vides-greniers (galeries) ───────────────────────────────────────────────

/**
 * Prochains vides-greniers déclarés dans les galeries, pour y rattacher des
 * missions de bénévolat. Ne dépend de galleries.php qu'à l'appel, pour que le
 * module reste utilisable en CLI et hors du portail.
 */
function rc_upcoming_events(int $limit = 20, bool $publishedOnly = false): array
{
    try {
        if (!function_exists('gallery_list')) require_once __DIR__ . '/galleries.php';
        if (!function_exists('gallery_list')) return [];
    } catch (Throwable $e) {
        return [];
    }
    $today = date('Y-m-d');
    $out = [];
    foreach (gallery_list() as $slug => $gallery) {
        $event = is_array($gallery['event'] ?? null) ? $gallery['event'] : null;
        $type = (string) ($gallery['type'] ?? '');
        if (!$event || !in_array($type, ['vide-grenier', 'puces', 'marche-de-noel', 'marche-producteurs'], true)) continue;
        $date = (string) ($event['date'] ?? '');
        if ($date !== '' && $date < $today) continue;
        if ($publishedOnly && empty($event['published'])) continue;
        $out[] = [
            'slug' => (string) $slug,
            'gallery' => (string) ($gallery['name'] ?? $slug),
            'title' => (string) ($event['title'] ?? 'Vide-grenier'),
            'date' => $date,
            'end_date' => (string) ($event['end_date'] ?? ''),
            'opening' => (string) ($event['opening'] ?? ''),
            'closing' => (string) ($event['closing'] ?? ''),
            'place' => (string) ($event['place'] ?? ''),
            'address' => (string) ($event['address'] ?? ''),
            'city' => (string) ($event['city'] ?? ''),
            'published' => !empty($event['published']),
        ];
    }
    usort($out, static fn (array $a, array $b): int => ($a['date'] !== '' ? $a['date'] : '9999-12-31') <=> ($b['date'] !== '' ? $b['date'] : '9999-12-31'));
    return array_slice($out, 0, $limit);
}

/** Besoins de bénévolat rattachés à un événement (slug de galerie). */
function rc_offers_for_event(string $slug): array
{
    return array_values(array_filter(rc_offers(['track' => 'benevole']), static fn (array $o): bool => $o['event'] === $slug));
}

/** Bénévoles retenus ou confirmés pour un événement. */
function rc_event_volunteers(string $slug): array
{
    $offerIds = array_map(static fn (array $o): string => $o['id'], rc_offers_for_event($slug));
    if (!$offerIds) return [];
    $out = [];
    foreach (rc_applications() as $application) {
        if (!in_array($application['offer_id'], $offerIds, true)) continue;
        if (!in_array($application['status'], ['retenue', 'integree'], true)) continue;
        $out[] = $application;
    }
    return $out;
}

// ── Chiffres ────────────────────────────────────────────────────────────────

function rc_stats(): array
{
    $offers = rc_offers();
    $applications = rc_applications();
    $byTrack = array_fill_keys(array_keys(RC_TRACKS), ['offres' => 0, 'candidatures' => 0, 'attente' => 0, 'integrees' => 0]);
    $openOffers = 0;
    foreach ($offers as $offer) {
        if ($offer['status'] === 'ouvert') {
            $openOffers++;
            $byTrack[$offer['track']]['offres']++;
        }
    }
    $pending = 0;
    $interviews = 0;
    $integrated = 0;
    $volunteers = 0;
    foreach ($applications as $application) {
        $byTrack[$application['track']]['candidatures']++;
        if ($application['status'] === 'recue') {
            $pending++;
            $byTrack[$application['track']]['attente']++;
        }
        if ($application['status'] === 'entretien') $interviews++;
        if ($application['status'] === 'integree') {
            $integrated++;
            $byTrack[$application['track']]['integrees']++;
            if ($application['track'] === 'benevole') $volunteers++;
        }
    }
    return [
        'offres_ouvertes' => $openOffers,
        'offres' => count($offers),
        'candidatures' => count($applications),
        'attente' => $pending,
        'entretiens' => $interviews,
        'integrees' => $integrated,
        'benevoles_confirmes' => $volunteers,
        'par_piste' => $byTrack,
    ];
}

// ── E-mail et journal ───────────────────────────────────────────────────────

function rc_event(string $type, array $data = []): void
{
    try {
        $dir = PORTAIL_ROOT . '/data/saas';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
        if (!isset($data['actor'])) $data['actor'] = str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/portail/') ? 'exploitant' : 'client';
        $row = ['ts' => time(), 'type' => $type] + array_filter($data, static fn ($v) => $v !== null && $v !== '');
        @file_put_contents($dir . '/events.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('rc_event : ' . $e->getMessage());
    }
}

function rc_mail(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $local = !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP);
    if ($local) {
        @file_put_contents(PORTAIL_ROOT . '/data/mail-outbox.log', '[' . date('c') . "] À : $to\nObjet : $subject\n$body\n----\n", FILE_APPEND | LOCK_EX);
    }
    $from = 'noreply@' . (!$local ? preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', $host) : 'localhost.localdomain');
    $headers = "From: Brocs recrutement <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    return function_exists('mail') && @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function rc_notify_application(array $application, ?array $offer = null): bool
{
    $settings = rc_settings();
    if ($settings['notify'] === '') return false;
    $origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $summary = [
        'Nom : ' . $application['name'],
        'Piste : ' . rc_track_label($application['track']),
        'Besoin : ' . ($offer ? $offer['title'] : 'candidature spontanée'),
        'E-mail : ' . ($application['email'] !== '' ? $application['email'] : '—'),
        'Téléphone : ' . ($application['phone'] !== '' ? $application['phone'] : '—'),
        'Ville : ' . ($application['city'] !== '' ? $application['city'] : '—'),
        'Domaines : ' . ($application['domains'] ? implode(', ', array_map(static fn ($d) => RC_DOMAINS[$d], $application['domains'])) : '—'),
        'Disponibilité : ' . ($application['availability'] !== '' ? $application['availability'] : '—'),
    ];
    if ($application['motivation'] !== '') $summary[] = "Message :\n" . $application['motivation'];
    return rc_mail($settings['notify'], 'Nouvelle candidature — ' . $application['name'] . ' · ' . rc_track_label($application['track']),
        "Une candidature vient d'être déposée depuis la page recrutement.\n\n" . implode("\n", $summary)
        . "\n\nÀ traiter : " . $origin . "/portail/recrutement/?vue=candidatures\n");
}

// ── Export ──────────────────────────────────────────────────────────────────

function rc_export_rows(array $applications): array
{
    $rows = [['Nom', 'E-mail', 'Téléphone', 'Ville', 'Piste', 'Besoin', 'Domaines', 'Statut', 'Contrat', 'Disponibilité',
        'Prétentions', 'Portfolio', 'Message', 'Notes internes', 'Reçue le']];
    $offers = [];
    foreach (rc_offers() as $offer) $offers[$offer['id']] = $offer;
    foreach ($applications as $a) {
        $notes = implode(' | ', array_map(static fn (array $n): string => $n['text'], $a['notes']));
        $rows[] = [
            $a['name'], $a['email'], $a['phone'], $a['city'],
            rc_track_label($a['track']),
            $offers[$a['offer_id']]['title'] ?? 'Candidature spontanée',
            implode(', ', array_map(static fn ($d) => RC_DOMAINS[$d], $a['domains'])),
            rc_status_label($a['status'], $a['track']),
            RC_CONTRACTS[$a['contract']] ?? '',
            $a['availability'], $a['salary'], $a['portfolio'],
            str_replace("\n", ' ', $a['motivation']), $notes,
            date('Y-m-d', $a['created']),
        ];
    }
    return $rows;
}
