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
    ];
}

/** Y a-t-il un filtre actif (hors « fiches closes ») ? */
function fv_tournee_filtered(array $f): bool
{
    foreach (['q', 'ville', 'rue', 'statut', 'zone', 'cat', 'prio', 'owner', 'due', 'gps'] as $k) {
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
    return $out;
}

/** Une fiche répond-elle aux filtres ? $today et $week sont des dates AAAA-MM-JJ. */
function fv_tournee_match(array $p, array $f, string $today, string $week): bool
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
    ];
}
