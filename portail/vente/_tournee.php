<?php
// Choix des arrêts d'une tournée : filtres communs à la modale (assets/tournee-arrets.js) et à « Toute la base » (tournees.php).
// Les règles de filtrage sont écrites ici ET dans tournee-arrets.js : toute modification se fait des deux côtés
// (le test VenteTourneeTest fixe le comportement côté PHP).

/** Nombre maximal d'arrêts choisis à la main (ils voyagent dans l'adresse de la page). */
const FV_TOURNEE_MAX_IDS = 500;
/** Nombre maximal d'arrêts de « Toute la base ». */
const FV_TOURNEE_MAX_ALL = 2000;

/** Minuscules sans accents, pour comparer « Évry », « evry » et « ÉVRY ». Même résultat que fold() dans tournee-arrets.js. */
function fv_fold(string $s): string
{
    static $tr = null;
    if ($tr === null) {
        $tr = class_exists('Transliterator') ? (Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower') ?: false) : false;
    }
    if ($tr) return trim((string) $tr->transliterate($s));
    return trim(strtr(mb_strtolower($s), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n',
    ]));
}

/**
 * La rue d'une adresse, sans le numéro : « 12 bis, rue des Lilas » → « rue des Lilas ».
 * Ne garde que ce qui précède la première virgule (résidence, étage…) et retire un code postal traînant.
 */
function fv_street_of(string $address): string
{
    $a = trim((string) preg_replace('/\s+/u', ' ', $address));
    $a = (string) preg_replace('/^(?:n\s*[°o]\s*)?\d+(?:\s*(?:[-\/&]|à)\s*\d+)?\s*(?:bis|ter|quater|quinquies|[a-d](?=[\s,]|$))?\s*[,.]?\s*/iu', '', $a);
    $a = (string) preg_replace('/\s+\d{5}\b.*$/u', '', $a);
    $head = trim(explode(',', $a)[0]);
    return $head !== '' ? $head : trim($a, " ,-");
}

/** Les réglages de filtre lus dans l'adresse de la page (mêmes noms que la modale). */
function fv_tournee_filters(array $src): array
{
    $text = static fn (string $key): string => trim((string) ($src[$key] ?? ''));
    $due = $text('due');
    $gps = $text('gps');
    return [
        'q' => $text('q'),
        'ville' => $text('ville'),
        'rue' => $text('rue'),
        'statut' => $text('statut'),
        'zone' => $text('zone'),
        'cat' => $text('cat'),
        'prio' => max(0, min(3, (int) ($src['prio'] ?? 0))),
        'owner' => max(0, (int) ($src['owner'] ?? 0)),
        'due' => in_array($due, ['today', 'week', 'none'], true) ? $due : '',
        'gps' => in_array($gps, ['oui', 'non'], true) ? $gps : '',
        'tous' => isset($src['tous']),
        'ouvert' => in_array($open = $text('ouvert'), ['now', '1', '2', '3', '4', '5', '6', '7'], true) ? $open : '',   // « now » ou le jour de la semaine (1 = lundi)
        'heure' => preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $text('heure')) ? $text('heure') : '',
        'hinc' => isset($src['hinc']),         // garder les commerces dont les horaires sont inconnus
    ];
}

/** Y a-t-il un filtre actif (hors « fiches closes ») ? */
function fv_tournee_filtered(array $f): bool
{
    foreach (['q', 'ville', 'rue', 'statut', 'zone', 'cat', 'prio', 'owner', 'due', 'gps', 'ouvert'] as $k) {
        if ($f[$k] !== '' && $f[$k] !== 0) return true;
    }
    return false;
}

/** Les filtres actifs, sous forme de paramètres d'adresse (pour « Toute la base filtrée »). */
function fv_tournee_filter_params(array $f): array
{
    $out = [];
    foreach (['q', 'ville', 'rue', 'statut', 'zone', 'cat', 'prio', 'owner', 'due', 'gps'] as $k) {
        if ($f[$k] !== '' && $f[$k] !== 0) $out[$k] = $f[$k];
    }
    if ($f['tous']) $out['tous'] = 1;
    if ($f['ouvert'] !== '') {
        $out['ouvert'] = $f['ouvert'];
        if ($f['heure'] !== '' && $f['ouvert'] !== 'now') $out['heure'] = $f['heure'];
        if ($f['hinc']) $out['hinc'] = 1;
    }
    return $out;
}

/** Une fiche répond-elle aux filtres ? $today et $week sont des dates AAAA-MM-JJ ; $now = ['d' => jour 1-7, 'm' => minutes depuis minuit] pour « ouvert maintenant ». */
function fv_tournee_match(array $p, array $f, string $today, string $week, ?array $now = null): bool
{
    $status = (string) $p['status'];
    if ($f['statut'] !== '') {
        if ($status !== $f['statut']) return false;
    } elseif (!$f['tous'] && in_array($status, ['perdu', 'boutique_creee'], true)) {
        return false;      // fiches closes : écartées, sauf demande ou statut choisi
    }
    if ($f['q'] !== '') {
        $hay = fv_fold(implode(' ', [$p['business'], $p['name'], $p['kind'], $p['address'], $p['postal'], $p['city']]));
        foreach (preg_split('/\s+/u', fv_fold($f['q'])) ?: [] as $word) {
            if ($word !== '' && !str_contains($hay, $word)) return false;
        }
    }
    if ($f['ville'] !== '' && !str_contains(fv_fold((string) $p['city']), fv_fold($f['ville']))) return false;
    if ($f['rue'] !== '' && !str_contains(fv_fold(fv_street_of((string) $p['address'])), fv_fold($f['rue']))) return false;
    if ($f['zone'] !== '' && (string) $p['zone'] !== $f['zone']) return false;
    if ($f['cat'] !== '') {
        $kind = fv_fold((string) $p['kind']);
        if ($f['cat'] === '~vide' ? $kind !== '' : $kind !== fv_fold($f['cat'])) return false;
    }
    if ($f['prio'] > 0 && (int) $p['priority'] !== $f['prio']) return false;
    if ($f['owner'] > 0 && (int) $p['owner_id'] !== $f['owner']) return false;
    $next = (string) ($p['next_action_at'] ?? '');
    if ($f['due'] === 'today' && ($next === '' || $next > $today)) return false;
    if ($f['due'] === 'week' && ($next === '' || $next > $week)) return false;
    if ($f['due'] === 'none' && $next !== '') return false;
    if ($f['gps'] === 'oui' && !fv_geo_has($p)) return false;
    if ($f['gps'] === 'non' && fv_geo_has($p)) return false;
    if ($f['ouvert'] !== '') {
        $schedule = fv_hours_schedule($p);
        if ($schedule === null) return $f['hinc'];      // horaires absents ou illisibles : gardés seulement si demandé
        $now ??= fv_hours_now();
        if ($f['ouvert'] === 'now') return fv_hours_open($schedule, $now['d'], $now['m']);
        return fv_hours_open($schedule, (int) $f['ouvert'], $f['heure'] !== '' ? fv_hours_minutes($f['heure']) : null);
    }
    return true;
}

/** Les fiches avec adresse du carnet (périmètre du membre connecté), non limitées en nombre. */
function fv_tournee_candidates(): array
{
    return fv_prospects_search(['has_address' => true], null);
}

/** Version compacte d'une fiche pour la modale (clés courtes : plus de 2 000 lignes voyagent en JSON). */
function fv_tournee_row(array $p): array
{
    $hours = fv_hours_text($p);
    $line = trim(trim((string) $p['address']) . ((string) $p['address'] !== '' && ((string) $p['postal'] !== '' || (string) $p['city'] !== '') ? ', ' : '') . trim(trim((string) $p['postal']) . ' ' . trim((string) $p['city'])));
    return [
        'id' => (int) $p['id'],
        'b' => (string) ($p['business'] !== '' ? $p['business'] : $p['name']),
        'n' => (string) ($p['business'] !== '' ? $p['name'] : ''),
        'k' => (string) $p['kind'],
        'a' => (string) $p['address'],
        'l' => $line,
        's' => fv_street_of((string) $p['address']),
        'c' => (string) $p['city'],
        'st' => (string) $p['status'],
        'z' => (string) $p['zone'],
        'pr' => (int) $p['priority'],
        'o' => (int) $p['owner_id'],
        'd' => (string) ($p['next_action_at'] ?? ''),
        'g' => fv_geo_has($p) ? 1 : 0,
    ] + ($hours !== '' ? ['h' => mb_substr($hours, 0, 90), 'w' => fv_hours_parse($hours)] : []);     // w : créneaux par jour, ou null si le texte est illisible
}

// ── Horaires d'ouverture ─────────────────────────────────────────────────────
// Lus dans le champ personnalisé « Horaires » des fiches (annuaire importé : format OpenStreetMap « Mo-Fr 09:00-19:00; Sa 09:00-12:00 »,
// et phrases françaises simples « du lundi au vendredi de 9h à 17h »). Ce qu'on ne comprend pas reste « inconnu » : jamais deviné.

const FV_HOURS_DAYS = [
    'mo' => 1, 'tu' => 2, 'we' => 3, 'th' => 4, 'fr' => 5, 'sa' => 6, 'su' => 7,
    'lun' => 1, 'lundi' => 1, 'mar' => 2, 'mardi' => 2, 'mer' => 3, 'mercredi' => 3, 'jeu' => 4, 'jeudi' => 4,
    'ven' => 5, 'vendredi' => 5, 'sam' => 6, 'samedi' => 6, 'dim' => 7, 'dimanche' => 7,
];

/** Le texte des horaires d'une fiche (champ personnalisé dont l'intitulé contient « horaire » ou « opening »). */
function fv_hours_text(array $p): string
{
    $raw = (string) ($p['custom_fields'] ?? '');
    if ($raw === '' || $raw === '{}') return '';
    $fields = json_decode($raw, true);
    foreach (is_array($fields) ? $fields : [] as $label => $value) {
        $l = fv_fold((string) $label);
        if (is_scalar($value) && (str_contains($l, 'horaire') || str_contains($l, 'opening'))) return trim((string) $value);
    }
    return '';
}

/** Créneaux d'une fiche, ou null si elle n'a pas d'horaires lisibles. */
function fv_hours_schedule(array $p): ?array
{
    $text = fv_hours_text($p);
    return $text === '' ? null : fv_hours_parse($text);
}

/** Jour (1 = lundi) et minutes depuis minuit, à l'heure de Paris. */
function fv_hours_now(): array
{
    $n = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
    return ['d' => (int) $n->format('N'), 'm' => (int) $n->format('G') * 60 + (int) $n->format('i')];
}

function fv_hours_minutes(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

/**
 * Lit un texte d'horaires. Retourne 7 listes de créneaux [début, fin] en minutes (lundi = index 0) ; une fin au-delà de 1440 déborde sur le lendemain.
 * Retourne null si le texte n'est pas compris en entier.
 */
function fv_hours_parse(string $text): ?array
{
    $t = fv_fold($text);
    $t = trim((string) preg_replace('/"[^"]*"/', ' ', $t));      // commentaires OSM entre guillemets
    if ($t === '') return null;
    if (preg_match('~^(24/7|24h/24( et 7j/7)?|24h/24, 7j/7)$~', $t)) return array_fill(0, 7, [[0, 1440]]);

    // Phrases françaises → forme OSM
    $t = (string) preg_replace_callback('/\b(\d{1,2})\s*h\s*(\d{2})?\b/', static fn (array $m): string => sprintf('%02d:%02d', (int) $m[1], (int) ($m[2] ?? 0)), $t);
    $t = (string) preg_replace('/\bdu (\w+) au (\w+)\b/', '$1-$2', $t);
    $t = (string) preg_replace('/(\d{2}:\d{2})\s*(?:a|-)\s*(\d{2}:\d{2})/', '$1-$2', $t);
    $t = (string) preg_replace('/\btous les jours\b|\b7j\/7\b/', 'mo-su', $t);
    $t = (string) preg_replace('/\b(ferme|fermee?s?|closed)\b/', 'off', $t);
    $t = (string) preg_replace('/\b(de|des|du|le|les|la|d|ouvert)\b/', ' ', $t);
    $t = (string) preg_replace('/\bet\b/', ',', $t);
    $t = trim((string) preg_replace('/\s+/', ' ', $t));

    $dayNames = array_keys(FV_HOURS_DAYS);
    usort($dayNames, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    $day = '(?:' . implode('|', $dayNames) . ')';
    $time = '(?:[01]?\d|2[0-4]):[0-5]\d';
    $days = array_fill(1, 7, []);

    foreach (explode(';', $t) as $rule) {
        $rule = trim($rule);
        if ($rule === '' || preg_match('/^\d{4}\b/', $rule)) continue;     // les exceptions datées (« 2026 Aug 19-22 off ») sont ignorées
        $pending = [];       // jours cités sans heures : « Mo,We 10:00-12:00 »
        $current = null;     // jours auxquels s'appliquent les créneaux qui suivent
        $touched = [];
        foreach (explode(',', $rule) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $rest = $part;
            $target = null;
            if (preg_match('/^(?:ph|sh)\b\s*(.*)$/', $part, $m)) {                       // jours fériés / vacances : ignorés
                $after = trim($m[1]);
                if (!$pending || $after === '' || $after === 'off') { $current = []; continue; }
                // « Su,PH 08:30-01:00 » : les horaires valent pour les jours cités, sans les fériés
                $target = array_values(array_unique($pending));
                $pending = [];
                $rest = $after;
            } elseif (preg_match('/^(' . $day . ')(?:\s*-\s*(' . $day . '))?(?:\s+(.*))?$/', $part, $m)) {
                $a = FV_HOURS_DAYS[$m[1]];
                $b = ($m[2] ?? '') !== '' ? FV_HOURS_DAYS[$m[2]] : $a;
                $span = [];
                for ($d = $a, $n = 0; $n < 7; $d = $d % 7 + 1, $n++) { $span[] = $d; if ($d === $b) break; }
                $rest = trim((string) ($m[3] ?? ''));
                if ($rest === '') { $pending = array_merge($pending, $span); continue; }
                $target = array_values(array_unique(array_merge($pending, $span)));
                $pending = [];
            } elseif ($pending) {
                return null;                 // des jours sans heures puis autre chose
            }
            if ($target === null) $target = $current ?? range(1, 7);
            $current = $target;
            foreach ($target as $d) {
                if (!isset($touched[$d])) { $days[$d] = []; $touched[$d] = true; }
            }
            if ($rest === 'off') continue;
            if (!preg_match_all('/(' . $time . ')\s*-\s*(' . $time . ')/', $rest, $ranges, PREG_SET_ORDER)) return null;
            if (trim((string) preg_replace('/' . $time . '\s*-\s*' . $time . '/', '', $rest)) !== '') return null;
            foreach ($ranges as $r) {
                $s = fv_hours_minutes($r[1]);
                $e = fv_hours_minutes($r[2]);
                if ($s > 1440 || $e > 1440) return null;
                if ($e <= $s) $e += 1440;     // « 18:00-03:00 » : jusqu'au lendemain
                foreach ($target as $d) $days[$d][] = [$s, $e];
            }
        }
        if ($pending) return null;
    }
    return array_values($days);
}

/** Ouvert le jour $d (1 = lundi) à la minute $m ? Sans minute : ouvert à un moment de la journée. */
function fv_hours_open(array $schedule, int $d, ?int $m): bool
{
    $today = $schedule[$d - 1] ?? [];
    $before = $schedule[($d + 5) % 7] ?? [];      // la veille : un créneau qui passe minuit
    if ($m === null) {
        foreach ($before as $iv) if ($iv[1] > 1440) return true;
        return $today !== [];
    }
    foreach ($today as $iv) if ($m >= $iv[0] && $m < $iv[1]) return true;
    foreach ($before as $iv) if ($iv[1] > 1440 && $m < $iv[1] - 1440) return true;
    return false;
}
