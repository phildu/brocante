<?php

// Assistant d'organisation d'une galerie commerciale : aide l'administrateur à mettre en place son portail et à préparer
// une manifestation (vide-grenier, marché aux puces, marché de quartier). L'assistant ne remplace pas les démarches :
// il range les informations, calcule un rétroplanning, signale les événements concurrents connus de la plateforme,
// construit des modèles de documents et recueille les réponses à un sondage.
//
// Les réponses propres à une galerie vivent dans data/galeries/<id>.assistant.json (jamais déployé) :
//   profile     identité de l'organisateur, date visée, jauge, commune, région ;
//   tasks       cases du rétroplanning cochées (clé => horodatage) ;
//   docs        documents marqués comme prêts (clé => horodatage) ;
//   security    réponses au questionnaire de sécurité ;
//   dates       dates candidates, date retenue, événements concurrents saisis à la main ;
//   survey      réglages du sondage (les réponses sont dans <id>.sondage.json) ;
//   aid         budget et plan de financement de la numérisation.
//
// Les documents sont dans includes/gallery-assistant-docs.php. La page d'administration est portail/galerie-assistant.php ;
// le sondage public est galerie/sondage.php.

require_once __DIR__ . '/gallery-admin.php';
require_once __DIR__ . '/gallery-event.php';
require_once __DIR__ . '/gallery-booking.php';
require_once __DIR__ . '/gallery-types.php';

const GASS_MAX_CANDIDATES = 12;
const GASS_MAX_COMPETITORS = 30;
const GASS_MAX_BUDGET_LINES = 8;
const GASS_MAX_RESPONSES = 2000;
const GASS_RESPONSES_PER_HOUR = 8;

/** Catalogue des documents proposés. Les clés servent aussi à mémoriser « document prêt ». */
function gass_document_catalog(): array
{
    return [
        'mairie' => ['label' => "Demande d'autorisation (mairie)", 'hint' => "Courrier pour occuper le domaine public, avec les caractéristiques de la manifestation.", 'audience' => 'Commune', 'pro' => false],
        'securite' => ['label' => 'Notice de sécurité', 'hint' => 'Synthèse du questionnaire : jauge, secours, évacuation, risques et recommandations.', 'audience' => 'Mairie / secours', 'pro' => false],
        'reglement' => ['label' => 'Règlement intérieur exposants', 'hint' => 'Conditions d’inscription, horaires, emplacements, sécurité, propreté, sanctions.', 'audience' => 'Exposants', 'pro' => false],
        'convention' => ['label' => 'Convention avec la commune', 'hint' => 'Modèle de convention d’occupation et de partenariat, à adapter au règlement local.', 'audience' => 'Commune', 'pro' => false],
        'exposants' => ['label' => 'Courrier aux anciens exposants', 'hint' => 'Message de mobilisation avec le lien du sondage et les informations pratiques.', 'audience' => 'Exposants', 'pro' => false],
        'sondage' => ['label' => 'Invitation au sondage', 'hint' => 'Message court à envoyer aux prospects avec le lien du sondage.', 'audience' => 'Prospects', 'pro' => false],
        'presse' => ['label' => 'Communiqué de presse', 'hint' => 'Présentation de l’événement, informations pratiques et contact presse.', 'audience' => 'Presse', 'pro' => false],
        'benevoles' => ['label' => 'Briefing des bénévoles', 'hint' => 'Missions, horaires, consignes de sécurité et contacts du jour J.', 'audience' => 'Équipe', 'pro' => false],
        'planning' => ['label' => 'Rétroplanning imprimable', 'hint' => 'Toutes les échéances de J-120 à J+7, avec les tâches cochées.', 'audience' => 'Organisateur', 'pro' => false],
        'bilan' => ['label' => 'Bilan d’organisation', 'hint' => 'Chiffres, incidents, satisfaction et décisions pour l’édition suivante.', 'audience' => 'Organisateur', 'pro' => false],
        'budget' => ['label' => 'Plan de financement numérisation', 'hint' => 'Budget par poste, cofinancements et reste à financer.', 'audience' => 'Financeurs', 'pro' => true],
        'aide-commune' => ['label' => 'Demande d’aide — commune', 'hint' => 'Courrier de demande de soutien à la numérisation adressé à la mairie.', 'audience' => 'Commune', 'pro' => true],
        'aide-region' => ['label' => 'Demande d’aide — région', 'hint' => 'Courrier de demande de soutien à la transformation numérique adressé à la région.', 'audience' => 'Région', 'pro' => true],
    ];
}

function gass_h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function gass_text($v, int $max): string
{
    return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $v)), 0, $max);
}

function gass_multiline($v, int $max): string
{
    return mb_substr(trim((string) $v), 0, $max);
}

function gass_date_valid(string $v): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)
        && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
}

/** Montant en euros saisi par l'utilisateur (« 1 250,50 ») -> centimes. */
function gass_cents($v): int
{
    if (is_int($v) || is_float($v)) return max(0, min(100000000, (int) round((float) $v * 100)));
    $s = str_replace([' ', "\u{00a0}", "\u{202f}", ','], ['', '', '', '.'], (string) $v);
    return max(0, min(100000000, (int) round((float) $s * 100)));
}

/** Montant déjà exprimé en centimes (données stockées) : borne sans conversion. */
function gass_cents_stored($v): int
{
    return max(0, min(100000000, (int) round((float) $v)));
}

function gass_eur(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function gass_defaults(): array
{
    return [
        'profile' => ['organizer' => '', 'structure' => '', 'contact' => '', 'email' => '', 'phone' => '', 'target_date' => '',
            'attendance' => 0, 'commune' => '', 'region' => '', 'siret' => '', 'notes' => ''],
        'tasks' => [],
        'docs' => [],
        'security' => ['answers' => [], 'updated' => 0],
        'dates' => ['candidates' => [], 'competitors' => [], 'chosen' => ''],
        'survey' => ['enabled' => false, 'open' => false, 'title' => 'Votre avis pour la prochaine édition', 'intro' => 'Deux minutes pour nous aider à choisir la date, les services et les tarifs.', 'questions' => [], 'created' => 0],
        'aid' => ['project' => '', 'needs' => '', 'budget' => [], 'requested_cents' => 0, 'own_cents' => 0, 'other_cents' => 0,
            'commune' => '', 'region' => '', 'contact' => ''],
        'updated' => 0,
    ];
}

function gass_normalize(array $d): array
{
    $def = gass_defaults();
    $p = (array) ($d['profile'] ?? []);
    $profile = [
        'organizer' => gass_text($p['organizer'] ?? '', 100),
        'structure' => gass_text($p['structure'] ?? '', 80),
        'contact' => gass_text($p['contact'] ?? '', 100),
        'email' => mb_strtolower(gass_text($p['email'] ?? '', 160)),
        'phone' => gass_text($p['phone'] ?? '', 40),
        'target_date' => gass_date_valid((string) ($p['target_date'] ?? '')) ? (string) $p['target_date'] : '',
        'attendance' => max(0, min(1000000, (int) ($p['attendance'] ?? 0))),
        'commune' => gass_text($p['commune'] ?? '', 100),
        'region' => gass_text($p['region'] ?? '', 100),
        'siret' => gass_text($p['siret'] ?? '', 30),
        'notes' => gass_multiline($p['notes'] ?? '', 1200),
    ];
    if ($profile['email'] !== '' && !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) $profile['email'] = '';

    $tasks = [];
    foreach ((array) ($d['tasks'] ?? []) as $k => $ts) {
        if (preg_match('/^[a-z0-9:_-]{1,60}$/', (string) $k) && is_numeric($ts)) $tasks[(string) $k] = (int) $ts;
    }
    $docs = [];
    foreach ((array) ($d['docs'] ?? []) as $k => $ts) {
        if (isset(gass_document_catalog()[$k]) && is_numeric($ts)) $docs[(string) $k] = (int) $ts;
    }

    $answers = [];
    $questions = gass_security_questions();
    foreach ((array) ($d['security']['answers'] ?? []) as $q => $opt) {
        if (isset($questions[$q]['options'][$opt])) $answers[(string) $q] = (string) $opt;
    }

    $candidates = [];
    $seen = [];
    foreach ((array) ($d['dates']['candidates'] ?? []) as $c) {
        $date = (string) ($c['date'] ?? '');
        if (!gass_date_valid($date) || isset($seen[$date]) || count($candidates) >= GASS_MAX_CANDIDATES) continue;
        $seen[$date] = true;
        $candidates[] = ['id' => preg_match('/^[a-f0-9]{8,16}$/', (string) ($c['id'] ?? '')) ? (string) $c['id'] : substr(bin2hex(random_bytes(4)), 0, 8),
            'date' => $date, 'label' => gass_text($c['label'] ?? '', 60)];
    }
    $competitors = [];
    foreach ((array) ($d['dates']['competitors'] ?? []) as $c) {
        $date = (string) ($c['date'] ?? '');
        if (!gass_date_valid($date) || count($competitors) >= GASS_MAX_COMPETITORS) continue;
        $url = trim((string) ($c['url'] ?? ''));
        $competitors[] = ['id' => preg_match('/^[a-f0-9]{8,16}$/', (string) ($c['id'] ?? '')) ? (string) $c['id'] : substr(bin2hex(random_bytes(4)), 0, 8),
            'name' => gass_text($c['name'] ?? '', 100) ?: 'Événement concurrent',
            'date' => $date, 'city' => gass_text($c['city'] ?? '', 60),
            'url' => preg_match('#^https?://[^\s]+$#i', $url) ? mb_substr($url, 0, 300) : ''];
    }
    $chosen = (string) ($d['dates']['chosen'] ?? '');
    if (!gass_date_valid($chosen)) $chosen = '';

    $s = (array) ($d['survey'] ?? []);
    $surveyQuestions = [];
    foreach ((array) ($s['questions'] ?? []) as $q) {
        if (!is_array($q)) continue;
        $key = preg_replace('/[^a-z0-9_]/', '', (string) ($q['key'] ?? ''));
        if ($key === '') continue;
        $type = in_array($q['type'] ?? '', ['radio', 'checkbox', 'textarea'], true) ? (string) $q['type'] : 'radio';
        $opts = [];
        foreach ((array) ($q['options'] ?? []) as $ok => $ol) {
            $ok = preg_replace('/[^a-z0-9_-]/', '', (string) $ok);
            if ($ok === '' || !is_scalar($ol)) continue;
            $opts[$ok] = gass_text($ol, 90);
        }
        if ($type !== 'textarea' && !$opts) continue;
        $surveyQuestions[] = ['key' => $key, 'label' => gass_text($q['label'] ?? '', 140), 'type' => $type, 'options' => $opts];
        if (count($surveyQuestions) >= 12) break;
    }
    $survey = [
        'enabled' => !empty($s['enabled']),
        'open' => !empty($s['open']),
        'title' => gass_text($s['title'] ?? $def['survey']['title'], 100) ?: $def['survey']['title'],
        'intro' => gass_multiline($s['intro'] ?? $def['survey']['intro'], 600),
        'questions' => $surveyQuestions,
        'created' => (int) ($s['created'] ?? 0) ?: time(),
    ];

    $a = (array) ($d['aid'] ?? []);
    $budget = [];
    foreach ((array) ($a['budget'] ?? []) as $line) {
        if (!is_array($line)) continue;
        $label = gass_text($line['label'] ?? '', 100);
        $amount = gass_cents_stored($line['amount_cents'] ?? 0);
        if ($label === '' && $amount === 0) continue;
        $budget[] = ['label' => $label ?: 'Poste de dépense', 'amount_cents' => $amount];
        if (count($budget) >= GASS_MAX_BUDGET_LINES) break;
    }
    $aid = [
        'project' => gass_text($a['project'] ?? '', 160),
        'needs' => gass_multiline($a['needs'] ?? '', 1600),
        'budget' => $budget,
        'requested_cents' => gass_cents_stored($a['requested_cents'] ?? 0),
        'own_cents' => gass_cents_stored($a['own_cents'] ?? 0),
        'other_cents' => gass_cents_stored($a['other_cents'] ?? 0),
        'commune' => gass_text($a['commune'] ?? '', 100),
        'region' => gass_text($a['region'] ?? '', 100),
        'contact' => gass_text($a['contact'] ?? '', 160),
    ];

    return [
        'profile' => $profile,
        'tasks' => $tasks,
        'docs' => $docs,
        'security' => ['answers' => $answers, 'updated' => (int) ($d['security']['updated'] ?? 0)],
        'dates' => ['candidates' => $candidates, 'competitors' => $competitors, 'chosen' => $chosen],
        'survey' => $survey,
        'aid' => $aid,
        'updated' => (int) ($d['updated'] ?? time()),
    ];
}

function gass_read(string $slug): array
{
    return gass_normalize(ga_read($slug, 'assistant', []));
}

function gass_save(string $slug, array $data): bool
{
    $data['updated'] = time();
    return ga_write($slug, 'assistant', gass_normalize($data));
}

function gass_event(array $gallery): array
{
    return is_array($gallery['event'] ?? null) ? $gallery['event'] : [];
}

/** Date de travail : événement publié, sinon date retenue dans l'assistant, sinon date visée. */
function gass_target_date(array $gallery, array $data): string
{
    foreach ([$gallery['event']['date'] ?? '', $data['dates']['chosen'] ?? '', $data['profile']['target_date'] ?? ''] as $d) {
        if (gass_date_valid((string) $d)) return (string) $d;
    }
    return '';
}

function gass_date_fr(string $date): string
{
    if (!gass_date_valid($date)) return '';
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $ts = strtotime($date . ' 12:00:00');
    return $days[(int) date('w', $ts)] . ' ' . (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function gass_survey_url(string $slug): string
{
    return ga_public_url('/galerie/sondage.php?g=' . rawurlencode($slug));
}

function gass_portal_link(string $file, string $slug): string
{
    return '/portail/' . $file . (defined('GA_LIMITED') ? '' : '?g=' . rawurlencode($slug));
}

function gass_assistant_link(string $slug, string $vue = 'demarrage'): string
{
    return '/portail/galerie-assistant.php' . (defined('GA_LIMITED') ? '?' : '?g=' . rawurlencode($slug) . '&') . 'vue=' . rawurlencode($vue);
}

function gass_dept(string $postcode): string
{
    $postcode = trim($postcode);
    if (!preg_match('/^[0-9]{5}$/', $postcode)) return '';
    return str_starts_with($postcode, '97') || str_starts_with($postcode, '98') ? substr($postcode, 0, 3) : substr($postcode, 0, 2);
}

// ── Événements de la plateforme et jours fériés ────────────────────────────

function gass_platform_events(string $excludeSlug): array
{
    $out = [];
    $cut = date('Y-m-d', time() - 30 * 86400);
    foreach (gallery_list() as $slug => $g) {
        if ($slug === $excludeSlug) continue;
        $ev = is_array($g['event'] ?? null) ? $g['event'] : null;
        if (!$ev || !gass_date_valid((string) ($ev['date'] ?? '')) || $ev['date'] < $cut) continue;
        $out[] = [
            'slug' => $slug, 'gallery' => $g['name'], 'title' => (string) ($ev['title'] ?? $g['name']),
            'date' => (string) $ev['date'], 'end_date' => (string) ($ev['end_date'] ?? ''),
            'city' => (string) ($ev['city'] ?? ''), 'postcode' => (string) ($ev['postcode'] ?? ''),
            'url' => gallery_public_url($g),
        ];
    }
    usort($out, static fn ($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
}

function gass_easter(int $year): int
{
    $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return (int) strtotime(sprintf('%04d-%02d-%02d 12:00:00', $year, $month, $day));
}

function gass_public_holidays(int $year): array
{
    $out = [];
    foreach ([[1, 1, 'Jour de l’an'], [5, 1, 'Fête du Travail'], [5, 8, 'Victoire 1945'], [7, 14, 'Fête nationale'],
        [8, 15, 'Assomption'], [11, 1, 'Toussaint'], [11, 11, 'Armistice 1918'], [12, 25, 'Noël']] as [$m, $d, $label]) {
        $out[sprintf('%04d-%02d-%02d', $year, $m, $d)] = $label;
    }
    $easter = gass_easter($year);
    $out[date('Y-m-d', $easter + 86400)] = 'Lundi de Pâques';
    $out[date('Y-m-d', $easter + 39 * 86400)] = 'Ascension';
    $out[date('Y-m-d', $easter + 50 * 86400)] = 'Lundi de Pentecôte';
    return $out;
}

function gass_bridge(string $date, array $holidays): string
{
    $ts = strtotime($date . ' 12:00:00');
    $n = (int) date('N', $ts);
    $prev = date('Y-m-d', $ts - 86400);
    $next = date('Y-m-d', $ts + 86400);
    if ($n === 2 && isset($holidays[date('Y-m-d', $ts - 2 * 86400)])) return 'Pont possible (férié le lundi)';
    if ($n === 4 && isset($holidays[date('Y-m-d', $ts + 2 * 86400)])) return 'Pont possible (férié le vendredi)';
    if ($n === 3 && (isset($holidays[$prev]) || isset($holidays[$next]))) return 'Veille ou lendemain de jour férié';
    return '';
}

function gass_date_signals(array $gallery, array $data, string $date, ?array $platformEvents = null): ?array
{
    if (!gass_date_valid($date)) return null;
    $ts = strtotime($date . ' 12:00:00');
    $weekday = (int) date('N', $ts);
    $dayNames = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
    $holidays = gass_public_holidays((int) date('Y', $ts));
    $holiday = $holidays[$date] ?? '';
    $bridge = gass_bridge($date, $holidays);
    $event = gass_event($gallery);
    $dept = gass_dept((string) ($event['postcode'] ?? ''));
    $city = mb_strtolower(trim((string) ($event['city'] ?? '')));
    $platformEvents ??= gass_platform_events($gallery['slug']);

    $competitors = [];
    foreach ($platformEvents as $pe) {
        $peTs = strtotime((string) $pe['date'] . ' 12:00:00');
        if (!$peTs) continue;
        $days = (int) round(($peTs - $ts) / 86400);
        if (abs($days) > 30) continue;
        $sameArea = ($dept !== '' && gass_dept((string) $pe['postcode']) === $dept)
            || ($city !== '' && mb_strtolower(trim((string) $pe['city'])) === $city);
        $competitors[] = ['source' => 'plateforme', 'name' => (string) $pe['title'], 'city' => (string) $pe['city'],
            'date' => (string) $pe['date'], 'days' => $days, 'same_area' => $sameArea, 'url' => (string) $pe['url']];
    }
    foreach ((array) ($data['dates']['competitors'] ?? []) as $c) {
        $cTs = strtotime((string) $c['date'] . ' 12:00:00');
        if (!$cTs) continue;
        $days = (int) round(($cTs - $ts) / 86400);
        if (abs($days) > 30) continue;
        $competitors[] = ['source' => 'saisi', 'name' => (string) $c['name'], 'city' => (string) $c['city'],
            'date' => (string) $c['date'], 'days' => $days, 'same_area' => true, 'url' => (string) $c['url']];
    }
    usort($competitors, static fn ($a, $b) => abs($a['days']) <=> abs($b['days']));

    $sameArea = array_values(array_filter($competitors, static fn ($c) => !empty($c['same_area'])));
    $month = (int) date('n', $ts);
    $score = 70;
    $reasons = [];
    if ($weekday === 6) { $score += 14; $reasons[] = 'Samedi : jour habituel des vides-greniers.'; }
    elseif ($weekday === 7) { $score += 8; $reasons[] = 'Dimanche : fréquentation familiale, mais concurrence des autres sorties.'; }
    else { $score -= 18; $reasons[] = 'En semaine : peu adapté à une manifestation grand public.'; }
    if ($holiday !== '') { $score -= 25; $reasons[] = 'Jour férié (' . $holiday . ') : commerces et publics décalés.'; }
    if ($bridge !== '') $reasons[] = $bridge . ' : vérifier la disponibilité des bénévoles et des exposants.';
    if ($month >= 5 && $month <= 9) { $score += 8; $reasons[] = 'Belle saison : météo plus favorable en extérieur.'; }
    elseif ($month === 4 || $month === 10) { $score += 4; $reasons[] = 'Mois de transition : prévoir un plan pluie.'; }
    else { $score -= 6; $reasons[] = 'Hors saison : prévoir un repli couvert ou un barnum.'; }
    $penalty = 0;
    foreach ($sameArea as $c) {
        $penalty += abs($c['days']) <= 3 ? 14 : (abs($c['days']) <= 10 ? 8 : 4);
    }
    if ($penalty > 0) {
        $score -= min(34, $penalty);
        $reasons[] = count($sameArea) . ' événement(s) proche(s) dans le même secteur : ' . implode(', ', array_map(static fn ($c) => $c['name'] . ' (' . $c['date'] . ')', array_slice($sameArea, 0, 3))) . '.';
    } elseif ($competitors) {
        $reasons[] = 'Aucun concurrent direct connu dans le même secteur, mais ' . count($competitors) . ' événement(s) à moins de 30 jours.';
    } else {
        $reasons[] = 'Aucun événement concurrent connu de la plateforme dans les 30 jours.';
    }
    $score = max(0, min(100, $score));
    $advice = $score >= 80 ? 'Très favorable' : ($score >= 60 ? 'Favorable' : ($score >= 40 ? 'À vérifier' : 'Déconseillée'));

    return ['date' => $date, 'label' => gass_date_fr($date), 'weekday' => $dayNames[$weekday], 'weekend' => $weekday >= 6,
        'holiday' => $holiday, 'bridge' => $bridge, 'competitors' => $competitors, 'same_area' => $sameArea,
        'score' => $score, 'advice' => $advice, 'reasons' => $reasons];
}

/** Meilleures dates parmi les prochains week-ends (samedis et dimanches). */
function gass_date_candidates(array $gallery, array $data, int $limit = 6): array
{
    $events = gass_platform_events($gallery['slug']);
    $today = strtotime('today');
    $out = [];
    for ($i = 0; $i < 120 && count($out) < 40; $i++) {
        $date = date('Y-m-d', $today + $i * 86400);
        $n = (int) date('N', strtotime($date . ' 12:00:00'));
        if ($n !== 6 && $n !== 7) continue;
        $signals = gass_date_signals($gallery, $data, $date, $events);
        if ($signals) $out[] = $signals;
    }
    usort($out, static fn ($a, $b) => [$b['score'], $a['date']] <=> [$a['score'], $a['date']]);
    return array_slice($out, 0, max(1, min(12, $limit)));
}

// ── Sécurité ───────────────────────────────────────────────────────────────

function gass_security_questions(): array
{
    return [
        'site' => ['label' => 'Type de site', 'hint' => 'Un site mixte demande deux dispositifs d’évacuation à coordonner.', 'options' => [
            'plein-air' => ['label' => 'Plein air', 'risk' => 1, 'advice' => 'Délimiter les allées, baliser les issues et prévoir un plan pluie/vent.'],
            'hall' => ['label' => 'Salle ou hall couvert', 'risk' => 0, 'advice' => ''],
            'mixte' => ['label' => 'Mixte intérieur / extérieur', 'risk' => 1, 'advice' => 'Coordonner les flux entre intérieur et extérieur et afficher un plan unique.'],
        ]],
        'jauge' => ['label' => 'Jauge attendue', 'hint' => 'Nombre de visiteurs et exposants au même moment.', 'options' => [
            'moins-300' => ['label' => 'Moins de 300 personnes', 'risk' => 0, 'advice' => ''],
            '300-1500' => ['label' => 'De 300 à 1 500 personnes', 'risk' => 1, 'advice' => 'Prévoir un poste de secours et une coordination avec les pompiers.'],
            'plus-1500' => ['label' => 'Plus de 1 500 personnes', 'risk' => 2, 'advice' => 'Saisir la commission de sécurité compétente et la DDSP ; prévoir un dispositif de secours dimensionné.'],
        ]],
        'issues' => ['label' => 'Issues de secours', 'hint' => 'Nombre d’issues dégagées et signalées.', 'options' => [
            'deux' => ['label' => 'Au moins deux issues dégagées', 'risk' => 0, 'advice' => ''],
            'une' => ['label' => 'Une seule issue', 'risk' => 1, 'advice' => 'Ouvrir une seconde issue ou réduire la jauge ; vérifier qu’aucun stand ne l’obstrue.'],
            'non-verifie' => ['label' => 'Non vérifiées', 'risk' => 2, 'advice' => 'Faire un tour des issues avec le responsable sécurité avant l’ouverture.'],
        ]],
        'extincteurs' => ['label' => 'Moyens de lutte contre l’incendie', 'hint' => 'Extincteurs adaptés et contrôlés.', 'options' => [
            'verifies' => ['label' => 'Présents et vérifiés', 'risk' => 0, 'advice' => ''],
            'non-verifies' => ['label' => 'Présents mais non vérifiés', 'risk' => 1, 'advice' => 'Faire vérifier les extincteurs et repérer leur emplacement sur le plan.'],
            'aucun' => ['label' => 'Aucun', 'risk' => 2, 'advice' => 'Fournir des extincteurs adaptés (au moins un par zone) et former deux bénévoles.'],
        ]],
        'secours' => ['label' => 'Dispositif de secours', 'hint' => 'Secouristes ou poste de secours sur place.', 'options' => [
            'poste' => ['label' => 'Poste de secours ou secouristes', 'risk' => 0, 'advice' => ''],
            'a-organiser' => ['label' => 'À organiser', 'risk' => 1, 'advice' => 'Contacter la protection civile, la Croix-Rouge ou un organisme agréé.'],
            'aucun' => ['label' => 'Aucun', 'risk' => 2, 'advice' => 'Ne pas ouvrir sans dispositif de secours : c’est une condition de sécurité de base.'],
        ]],
        'defibrillateur' => ['label' => 'Défibrillateur', 'hint' => 'Appareil sur site ou accessible en moins de cinq minutes.', 'options' => [
            'sur-site' => ['label' => 'Sur site', 'risk' => 0, 'advice' => ''],
            'proche' => ['label' => 'Accessible à moins de 5 minutes', 'risk' => 1, 'advice' => 'Afficher le lieu du défibrillateur et le numéro d’urgence au poste d’accueil.'],
            'non' => ['label' => 'Aucun à proximité', 'risk' => 2, 'advice' => 'Demander à la mairie ou au centre commercial un défibrillateur pour la journée.'],
        ]],
        'evacuation' => ['label' => 'Plan d’évacuation', 'hint' => 'Plan affiché, consignes connues et exercice éventuel.', 'options' => [
            'affiche' => ['label' => 'Plan affiché et consignes diffusées', 'risk' => 0, 'advice' => ''],
            'plan-seul' => ['label' => 'Plan seul, sans briefing', 'risk' => 1, 'advice' => 'Faire un briefing évacuation de dix minutes avec tous les bénévoles.'],
            'aucun' => ['label' => 'Aucun plan', 'risk' => 2, 'advice' => 'Dessiner un plan simple avec issues, extincteurs, secours et point de rassemblement.'],
        ]],
        'benevoles' => ['label' => 'Bénévoles formés', 'hint' => 'Personnes capables d’alerter, d’évacuer et de guider.', 'options' => [
            'tous' => ['label' => 'Tous briefés', 'risk' => 0, 'advice' => ''],
            'quelques-uns' => ['label' => 'Quelques-uns', 'risk' => 1, 'advice' => 'Désigner un référent sécurité et briefé tous les bénévoles avant l’ouverture.'],
            'non' => ['label' => 'Aucun', 'risk' => 2, 'advice' => 'Former un binôme sécurité et diffuser une fiche réflexe à tous les bénévoles.'],
        ]],
        'coordination' => ['label' => 'Coordination avec les secours', 'hint' => 'Mairie, pompiers, police ou gendarmerie.', 'options' => [
            'faite' => ['label' => 'Réunion faite', 'risk' => 0, 'advice' => ''],
            'prevue' => ['label' => 'Prévue', 'risk' => 1, 'advice' => 'Confirmer la réunion et transmettre la notice de sécurité.'],
            'non' => ['label' => 'Pas encore', 'risk' => 2, 'advice' => 'Contacter la mairie et le SDIS au moins un mois avant pour caler le dispositif.'],
        ]],
        'circulation' => ['label' => 'Circulation et véhicules', 'hint' => 'Séparation des visiteurs, exposants et secours.', 'options' => [
            'separee' => ['label' => 'Zones séparées', 'risk' => 0, 'advice' => ''],
            'partielle' => ['label' => 'Séparation partielle', 'risk' => 1, 'advice' => 'Baliser les allées et interdire la circulation des véhicules pendant la manifestation.'],
            'aucune' => ['label' => 'Pas de séparation', 'risk' => 2, 'advice' => 'Interdire les véhicules dans la zone publique et prévoir un parking séparé.'],
        ]],
        'electricite' => ['label' => 'Installations électriques', 'hint' => 'Groupes, rallonges, branchements des exposants.', 'options' => [
            'verifiee' => ['label' => 'Installations vérifiées', 'risk' => 0, 'advice' => ''],
            'groupe' => ['label' => 'Groupe électrogène', 'risk' => 1, 'advice' => 'Placer le groupe à l’écart du public, contrôler les câbles et prévoir des extincteurs.'],
            'improvisée' => ['label' => 'Branchements improvisés', 'risk' => 2, 'advice' => 'Faire vérifier les branchements par un électricien et supprimer les rallonges au sol.'],
        ]],
        'meteo' => ['label' => 'Plan météo', 'hint' => 'Pluie, vent, chaleur ou froid.', 'options' => [
            'complet' => ['label' => 'Plan pluie/vent prêt', 'risk' => 0, 'advice' => ''],
            'partiel' => ['label' => 'Plan partiel', 'risk' => 1, 'advice' => 'Prévoir des barnums lestés et un repli couvert ; diffuser la décision la veille.'],
            'aucun' => ['label' => 'Aucun', 'risk' => 2, 'advice' => 'Définir un seuil d’annulation ou de repli et un canal d’information des exposants.'],
        ]],
        'acces' => ['label' => 'Accès secours et stationnement', 'hint' => 'Les secours doivent pouvoir entrer à tout moment.', 'options' => [
            'ok' => ['label' => 'Accès dégagé', 'risk' => 0, 'advice' => ''],
            'a-revoir' => ['label' => 'À revoir', 'risk' => 1, 'advice' => 'Tracer une voie libre de 3 mètres et la signaler aux exposants.'],
            'non' => ['label' => 'Accès difficile', 'risk' => 2, 'advice' => 'Déplacer les stands qui gênent l’accès et prévenir les secours du point d’entrée.'],
        ]],
    ];
}

function gass_security_result(array $answers): array
{
    $questions = gass_security_questions();
    $score = 0;
    $answered = 0;
    $missing = [];
    $recommendations = [];
    $rows = [];
    foreach ($questions as $key => $q) {
        $optKey = (string) ($answers[$key] ?? '');
        $opt = $q['options'][$optKey] ?? null;
        if (!$opt) { $missing[] = $q['label']; continue; }
        $answered++;
        $score += (int) ($opt['risk'] ?? 0);
        if (!empty($opt['advice'])) $recommendations[] = $opt['advice'];
        $rows[] = ['key' => $key, 'label' => $q['label'], 'answer' => $opt['label'], 'risk' => (int) ($opt['risk'] ?? 0)];
    }
    $recommendations = array_values(array_unique($recommendations));
    $max = count($questions) * 2;
    $ratio = $max > 0 ? $score / $max : 0;
    if ($answered < count($questions) / 2) {
        $level = 'À compléter'; $tone = 'warn'; $label = 'Questionnaire incomplet';
    } elseif ($ratio <= 0.25) {
        $level = 'Faible'; $tone = 'ok'; $label = 'Risque faible';
    } elseif ($ratio <= 0.5) {
        $level = 'Modéré'; $tone = 'warn'; $label = 'Risque modéré';
    } elseif ($ratio <= 0.75) {
        $level = 'Élevé'; $tone = 'danger'; $label = 'Risque élevé';
    } else {
        $level = 'Critique'; $tone = 'danger'; $label = 'Risque critique';
    }
    return ['score' => $score, 'max' => $max, 'answered' => $answered, 'total' => count($questions),
        'missing' => $missing, 'level' => $level, 'label' => $label, 'tone' => $tone, 'recommendations' => $recommendations, 'rows' => $rows];
}

// ── Checklist de mise en place ─────────────────────────────────────────────

function gass_checklist(array $gallery, array $data): array
{
    $slug = (string) $gallery['slug'];
    $event = gass_event($gallery);
    $isOrg = in_array((string) $gallery['type'], ['vide-grenier', 'puces'], true) || $event;
    $places = $event ? ge_place_count($event) : 0;
    $price = $event ? ge_place_price($event) : null;
    $former = count(gass_former_exhibitors($gallery));
    $prospects = count(ga_prospects($slug));
    $security = gass_security_result((array) ($data['security']['answers'] ?? []));
    $responses = count(gass_survey_responses($slug));
    $groups = [];
    $add = static function (string $group, string $key, string $label, string $hint, bool $done, string $href = '', string $badge = '') use (&$groups): void {
        if (!isset($groups[$group])) $groups[$group] = ['label' => $group, 'items' => []];
        $groups[$group]['items'][] = ['key' => $key, 'label' => $label, 'hint' => $hint, 'done' => $done, 'href' => $href, 'badge' => $badge];
    };

    $add('Fiche et vitrine', 'fiche', 'Compléter la fiche de la galerie', 'Nom, slogan et description : ce que voit le visiteur en premier.', $gallery['name'] !== '' && ($gallery['tagline'] !== '' || $gallery['description'] !== ''), gass_portal_link('galeries.php', $slug));
    $add('Fiche et vitrine', 'publication', 'Publier la page publique', 'La galerie doit être visible sans le lien d’aperçu.', !empty($gallery['published']), gass_portal_link('galeries.php', $slug));
    $add('Fiche et vitrine', 'membres', 'Afficher les premiers membres', 'Commerces, marchands ou exposants : la page a besoin de contenu.', count($gallery['members']) > 0 || $places > 0, gass_portal_link('galerie-exposants.php', $slug));
    if ($isOrg) {
        $booking = gb_booking($gallery);
        $add('Événement et emplacements', 'event', 'Renseigner la date et le lieu', 'Date, horaires, adresse et consignes aux exposants.', !empty($event['date']), gass_portal_link('galerie-evenement.php', $slug));
        $add('Événement et emplacements', 'places', 'Définir les places', 'Nombre de places ou mètres linéaires, numérotation automatique.', $places > 0, gass_portal_link('galerie-evenement.php', $slug));
        $add('Événement et emplacements', 'tarifs', 'Fixer le tarif d’une place', 'Tarif particulier et tarif professionnel.', $price !== null, gass_portal_link('galerie-evenement.php', $slug));
        $add('Événement et emplacements', 'reservations', 'Ouvrir les réservations en ligne', 'Les exposants choisissent une place et vous validez.', !empty($booking['enabled']), gass_portal_link('galerie-evenement.php', $slug));
        if (gt_feature($gallery, 'plan')) {
            $slots = count(array_filter((array) ($gallery['map']['items'] ?? []), static fn ($i) => ($i['role'] ?? '') === 'slot'));
            $add('Événement et emplacements', 'plan', 'Vérifier le plan ou la carte', 'Emplacements et zones cohérents avec le nombre de places.', $slots > 0 || $places > 0, gass_portal_link('galerie-plan.php', $slug));
        }
    }
    $add('Contacts et communication', 'contacts', 'Réunir les anciens exposants et prospects', 'Au moins cinq contacts pour lancer les relances et le sondage.', ($former + $prospects) >= 5, gass_portal_link('galerie-prospects.php', $slug), (string) ($former + $prospects));
    $add('Contacts et communication', 'survey', 'Créer le sondage', 'Date, budget, produits et services attendus.', !empty($data['survey']['enabled']) && !empty($data['survey']['questions']), gass_assistant_link($slug, 'contacts'), $responses > 0 ? (string) $responses : '');
    $add('Sécurité et administratif', 'securite', 'Évaluer les besoins de sécurité', 'Jauge, secours, évacuation, extincteurs, circulation.', $security['answered'] === $security['total'], gass_assistant_link($slug, 'securite'), $security['answered'] . '/' . $security['total']);
    $add('Sécurité et administratif', 'documents', 'Préparer les documents', 'Autorisation, notice sécurité, règlement, convention.', count($data['docs']) >= 2, gass_assistant_link($slug, 'documents'), (string) count($data['docs']));
    $add('Sécurité et administratif', 'planning', 'Valider le rétroplanning', 'Fixez la date de travail : l’assistant calcule J-120 à J+7.', gass_target_date($gallery, $data) !== '', gass_assistant_link($slug, 'planning'));
    if (in_array((string) $gallery['type'], ['puces', 'marchande', 'quartier'], true)) {
        $aid = gass_aid_plan($data);
        $add('Financement', 'aide', 'Construire le dossier de numérisation', 'Budget, cofinancements et courriers à la commune et à la région.', $aid['total'] > 0 && $aid['requested'] > 0, gass_assistant_link($slug, 'aides'));
    }

    $total = 0;
    $done = 0;
    foreach ($groups as $g) {
        foreach ($g['items'] as $item) {
            $total++;
            if ($item['done']) $done++;
        }
    }
    return ['groups' => $groups, 'done' => $done, 'total' => $total, 'percent' => $total > 0 ? (int) round($done / $total * 100) : 0];
}

// ── Rétroplanning ──────────────────────────────────────────────────────────

function gass_planning(array $gallery, array $data): array
{
    $target = gass_target_date($gallery, $data);
    if ($target === '') return [];
    $slug = (string) $gallery['slug'];
    $milestones = [
        [120, 'date', 'Fixer la date et le lieu', 'Vérifier les disponibilités de la salle, de la voirie et des bénévoles.', gass_assistant_link($slug, 'date')],
        [90, 'mairie', 'Déposer la demande en mairie', 'Autorisation d’occupation, arrêté municipal, redevance et assurance.', gass_assistant_link($slug, 'documents')],
        [75, 'securite', 'Évaluer la sécurité', 'Jauge, issues, secours, extincteurs et coordination avec les pompiers.', gass_assistant_link($slug, 'securite')],
        [60, 'inscriptions', 'Ouvrir les inscriptions', 'Publier l’événement et activer les réservations en ligne.', gass_portal_link('galerie-evenement.php', $slug)],
        [60, 'anciens', 'Contacter les anciens exposants', 'Message personnalisé, lien du sondage et relance téléphonique.', gass_portal_link('galerie-prospects.php', $slug)],
        [45, 'sondage', 'Dépouiller le sondage', 'Ajuster la date, le tarif et les services selon les réponses.', gass_assistant_link($slug, 'contacts')],
        [30, 'documents', 'Boucler les documents', 'Notice de sécurité, règlement exposants, convention et registre.', gass_assistant_link($slug, 'documents')],
        [21, 'communication', 'Lancer la communication', 'Affiche, presse locale, réseaux sociaux et panneaux.', gass_assistant_link($slug, 'plus')],
        [14, 'benevoles', 'Briefing des bénévoles', 'Missions, horaires, consignes de sécurité et contacts.', gass_assistant_link($slug, 'documents')],
        [7, 'meteo', 'Point météo et plan B', 'Décider du repli, prévenir les exposants et vérifier le matériel.', gass_assistant_link($slug, 'plus')],
        [1, 'installation', 'Installation et dernière vérification', 'Fléchage, issues, extincteurs, poste de secours et registre.', gass_assistant_link($slug, 'securite')],
        [0, 'jour-j', 'Jour J', 'Accueil des exposants, gestion des flux et incidents.', gass_assistant_link($slug, 'securite')],
        [-1, 'demontage', 'Démontage et remerciements', 'Rangement, objets trouvés, message de remerciement.', gass_assistant_link($slug, 'contacts')],
        [-7, 'bilan', 'Bilan et questionnaire', 'Chiffres, incidents, satisfaction et décisions pour l’an prochain.', gass_assistant_link($slug, 'plus')],
    ];
    $today = strtotime('today');
    $out = [];
    foreach ($milestones as [$offset, $key, $title, $detail, $href]) {
        $ts = strtotime($target . ' 12:00:00') - $offset * 86400;
        $date = date('Y-m-d', $ts);
        $done = isset($data['tasks']['plan-' . $key]);
        $status = $done ? 'done' : ($ts < $today ? 'late' : (($ts - $today) <= 7 * 86400 ? 'soon' : 'upcoming'));
        $out[] = ['key' => 'plan-' . $key, 'offset' => $offset, 'date' => $date, 'date_text' => date('d/m/Y', $ts),
            'when' => $offset > 0 ? 'J-' . $offset : ($offset === 0 ? 'Jour J' : 'J+' . abs($offset)),
            'title' => $title, 'detail' => $detail, 'href' => $href, 'done' => $done, 'status' => $status];
    }
    usort($out, static fn ($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
}

// ── Anciens exposants ──────────────────────────────────────────────────────

function gass_former_exhibitors(array $gallery): array
{
    $out = [];
    $seen = [];
    $add = static function (array $row) use (&$out, &$seen): void {
        $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
        $phone = preg_replace('/[^0-9+]/', '', (string) ($row['phone'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $key = $email !== '' ? 'e:' . $email : ($phone !== '' ? 'p:' . $phone : ($name !== '' ? 'n:' . mb_strtolower($name) : ''));
        if ($key === '' || isset($seen[$key])) return;
        $seen[$key] = true;
        $row['key'] = $key;
        $out[] = $row;
    };
    foreach (gb_rows($gallery) as $r) {
        if (($r['status'] ?? '') !== 'taken') continue;
        $add(['name' => (string) ($r['name'] ?? ''), 'email' => (string) ($r['email'] ?? ''), 'phone' => (string) ($r['phone'] ?? ''),
            'slot' => (string) ($r['slot'] ?? ''), 'source' => 'Édition précédente', 'status' => 'Attribué']);
    }
    foreach (ga_prospects((string) $gallery['slug']) as $p) {
        $add(['name' => (string) ($p['name'] ?? ''), 'email' => (string) ($p['email'] ?? ''), 'phone' => (string) ($p['phone'] ?? ''),
            'slot' => '', 'source' => 'Prospect', 'status' => GA_PROSPECT_STATUS[$p['status'] ?? ''] ?? 'À contacter']);
    }
    usort($out, static fn ($a, $b) => strcasecmp($a['name'] ?: $a['email'], $b['name'] ?: $b['email']));
    return array_slice($out, 0, 2000);
}

// ── Sondage : stockage et dépouillement ────────────────────────────────────

function gass_survey_questions(array $data): array
{
    $dateOptions = [];
    foreach ((array) ($data['dates']['candidates'] ?? []) as $c) {
        $dateOptions[(string) $c['id']] = gass_date_fr((string) $c['date']) . ($c['label'] !== '' ? ' — ' . $c['label'] : '');
    }
    $questions = [
        ['key' => 'presence', 'label' => 'Comptez-vous participer à cette édition ?', 'type' => 'radio',
            'options' => ['oui' => 'Oui, avec plaisir', 'peut-etre' => 'Peut-être', 'non' => 'Non']],
        ['key' => 'frequency', 'label' => 'Combien de fois par an exposez-vous ?', 'type' => 'radio',
            'options' => ['une' => 'Une fois', 'deux-trois' => 'Deux à trois fois', 'quatre-plus' => 'Quatre fois ou plus']],
    ];
    if ($dateOptions) {
        $questions[] = ['key' => 'dates', 'label' => 'Quel(s) week-end(s) vous conviendrai(en)t ?', 'type' => 'checkbox', 'options' => $dateOptions];
    }
    $questions[] = ['key' => 'budget', 'label' => 'Quel budget d’emplacement vous paraît juste ?', 'type' => 'radio',
        'options' => ['moins-10' => 'Moins de 10 €', '10-20' => 'De 10 à 20 €', '20-35' => 'De 20 à 35 €', 'plus-35' => 'Plus de 35 €']];
    $questions[] = ['key' => 'products', 'label' => 'Que proposerez-vous ?', 'type' => 'checkbox',
        'options' => ['brocante' => 'Brocante et objets anciens', 'artisanat' => 'Artisanat', 'livres' => 'Livres et disques',
            'mobilier' => 'Mobilier', 'vetements' => 'Vêtements et accessoires', 'autre' => 'Autre']];
    $questions[] = ['key' => 'services', 'label' => 'Quels services vous seraient utiles ?', 'type' => 'checkbox',
        'options' => ['parking' => 'Parking', 'restauration' => 'Restauration', 'toilettes' => 'Toilettes',
            'electricite' => 'Électricité', 'dechargement' => 'Aide au déchargement', 'paiement' => 'Paiement en ligne']];
    $questions[] = ['key' => 'comment', 'label' => 'Un commentaire, une idée, une contrainte ?', 'type' => 'textarea', 'options' => []];
    return $questions;
}

function gass_survey_store(string $slug): array
{
    $d = ga_read($slug, 'sondage', []);
    return ['responses' => is_array($d['responses'] ?? null) ? $d['responses'] : [],
        'hits' => is_array($d['hits'] ?? null) ? $d['hits'] : []];
}

function gass_survey_responses(string $slug): array
{
    return gass_survey_store($slug)['responses'];
}

/** Enregistre une réponse publique. -> message d'erreur ou null. */
function gass_survey_response_add(string $slug, array $post): ?string
{
    $gallery = gallery_load($slug);
    if (!$gallery) return 'Galerie introuvable.';
    $data = gass_read($slug);
    $survey = $data['survey'];
    if (!$survey['enabled']) return 'Le sondage n’est pas ouvert.';
    if (!$survey['open']) return 'Le sondage est fermé. Merci de votre intérêt.';
    if (trim((string) ($post['website'] ?? '')) !== '') return null;   // champ piège : on ne dit rien au robot
    $questions = $survey['questions'] ?: gass_survey_questions($data);
    if (!$questions) return 'Le sondage n’est pas encore configuré.';

    $store = gass_survey_store($slug);
    $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $slug);
    $recent = array_values(array_filter((array) ($store['hits'][$ip] ?? []), static fn ($t) => is_numeric($t) && $t > time() - 3600));
    if (count($recent) >= GASS_RESPONSES_PER_HOUR) return 'Trop de réponses envoyées depuis cette connexion : réessayez dans une heure.';
    if (count($store['responses']) >= GASS_MAX_RESPONSES) return 'Le sondage a reçu assez de réponses : il est momentanément fermé.';

    $answers = [];
    $raw = is_array($post['q'] ?? null) ? $post['q'] : [];
    foreach ($questions as $q) {
        $key = (string) $q['key'];
        if ($q['type'] === 'textarea') {
            $v = gass_multiline($raw[$key] ?? '', 1000);
            if ($v !== '') $answers[$key] = $v;
            continue;
        }
        if ($q['type'] === 'checkbox') {
            $vals = array_values(array_filter((array) ($raw[$key] ?? []), static fn ($v) => is_scalar($v)));
            $vals = array_values(array_intersect(array_map('strval', $vals), array_keys((array) $q['options'])));
            if ($vals) $answers[$key] = array_values(array_unique($vals));
            continue;
        }
        $v = (string) ($raw[$key] ?? '');
        if (isset($q['options'][$v])) $answers[$key] = $v;
    }
    if (empty($answers['presence'])) return 'Indiquez si vous comptez participer.';
    $email = mb_strtolower(gass_text($post['email'] ?? '', 160));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'L’adresse e-mail n’est pas valide.';
    if ($email !== '' && empty($post['consent'])) return 'Pour être recontacté, cochez la case d’accord.';

    $store['responses'][] = ['id' => bin2hex(random_bytes(6)), 'created' => time(), 'answers' => $answers,
        'email' => $email, 'consent' => $email !== '' && !empty($post['consent'])];
    $recent[] = time();
    $store['hits'][$ip] = $recent;
    foreach ($store['hits'] as $k => $hits) {
        $keep = array_values(array_filter((array) $hits, static fn ($t) => is_numeric($t) && $t > time() - 3600));
        if ($keep) $store['hits'][$k] = $keep; else unset($store['hits'][$k]);
    }
    return ga_write($slug, 'sondage', $store) ? null : 'Impossible d’enregistrer la réponse.';
}

function gass_survey_results(string $slug, array $data): array
{
    $responses = gass_survey_responses($slug);
    $questions = $data['survey']['questions'] ?: gass_survey_questions($data);
    $out = ['total' => count($responses), 'emails' => 0, 'questions' => [], 'latest' => []];
    $counts = [];
    foreach ($questions as $q) $counts[$q['key']] = [];
    foreach ($responses as $r) {
        if (!empty($r['email'])) $out['emails']++;
        foreach ((array) ($r['answers'] ?? []) as $key => $value) {
            if (!isset($counts[$key])) continue;
            foreach (is_array($value) ? $value : [$value] as $v) {
                if (is_scalar($v)) $counts[$key][(string) $v] = ($counts[$key][(string) $v] ?? 0) + 1;
            }
        }
    }
    foreach ($questions as $q) {
        if ($q['type'] === 'textarea') {
            $comments = [];
            foreach ($responses as $r) {
                $txt = trim((string) ($r['answers'][$q['key']] ?? ''));
                if ($txt !== '') $comments[] = ['text' => $txt, 'created' => (int) ($r['created'] ?? 0)];
            }
            $out['questions'][] = ['key' => $q['key'], 'label' => $q['label'], 'type' => 'textarea', 'comments' => array_slice(array_reverse($comments), 0, 30)];
            continue;
        }
        $opts = [];
        foreach ((array) $q['options'] as $ok => $ol) {
            $opts[] = ['key' => $ok, 'label' => $ol, 'count' => (int) ($counts[$q['key']][$ok] ?? 0)];
        }
        $out['questions'][] = ['key' => $q['key'], 'label' => $q['label'], 'type' => $q['type'], 'options' => $opts];
    }
    return $out;
}

// ── Budget et aides à la numérisation ─────────────────────────────────────

function gass_aid_plan(array $data): array
{
    $budget = (array) ($data['aid']['budget'] ?? []);
    $linesTotal = array_sum(array_map(static fn ($l) => (int) ($l['amount_cents'] ?? 0), $budget));
    $requested = (int) ($data['aid']['requested_cents'] ?? 0);
    $own = (int) ($data['aid']['own_cents'] ?? 0);
    $other = (int) ($data['aid']['other_cents'] ?? 0);
    $committed = $requested + $own + $other;
    $total = max($linesTotal, $committed);
    $gap = max(0, $total - $committed);
    return ['lines' => $budget, 'lines_total' => $linesTotal, 'total' => $total, 'requested' => $requested,
        'own' => $own, 'other' => $other, 'committed' => $committed, 'gap' => $gap,
        'requested_pct' => $total > 0 ? (int) round($requested / $total * 100) : 0];
}
