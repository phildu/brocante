<?php

// Modules (plugins) partagés des galeries : une fonctionnalité est proposée par type de galerie, puis activée galerie par galerie.
// Trois modules livrés :
//   carte         Carte interactive : plan IGN, zones, emplacements numérotés, choix de l'emplacement par les exposants ;
//   organisateur  Espace organisateur : événement (date, lieu, places ou mètres), numérotation automatique des places, tableau de bord
//                 des inscriptions et registre de police (Excel / PDF) ;
//   attente       File d'attente des exposants et alertes de voisinage : prévenir les inscrits dès qu'une place se libère, proposer
//                 une place vide à côté d'un stand à prix réduit pour l'agrandir (voir includes/gallery-waitlist.php).
//
// Un type règle chaque module : inclus dans son offre, option payante, ou non proposé (data/saas/galeries-types.json, clé « modules »).
// L'état d'un module pour une galerie est conservé dans la clé « modules » de data/galeries/<id>.json ; une option payante s'active par
// un essai gratuit ou un abonnement Stripe (metadata type=gallery_module). Les prix et durées d'essai par défaut sont dans
// data/saas/galeries-modules.json, surchargeables depuis le portail, et retombent sur les valeurs ci-dessous.
//
// Ce fichier ne dépend d'aucun autre include à son chargement (hors vocabulary.php, lui-même autonome) : il est appelé par gallery_normalize() (includes/galleries.php),
// gallery-types.php et les pages du portail.

/** Libellés des trois modes d'un module (selon le vocabulaire des extensions : « Option payante », « Plugin payant »…). */
function gm_mode_labels(): array
{
    return ['included' => ext_t("{?Inclus|Incluse} dans l'offre"), 'option' => ext_t('{Ext} payant{e}'), 'off' => ext_t('{?Non proposé|Non proposée}')];
}
require_once __DIR__ . '/vocabulary.php';

const GM_STATUS_LABELS = [
    'included' => 'Inclus', 'trial' => 'Essai en cours', 'active' => 'Abonné', 'complimentary' => 'Accès offert',
    'canceled' => 'Résilié', 'expired' => 'Essai terminé', 'off' => 'Non proposé',
];
const GM_STATUSES = ['included', 'trial', 'active', 'complimentary', 'canceled', 'expired'];
const GM_MODES = ['included', 'option', 'off'];

/** Définition d'origine des modules livrés. */
function gm_defaults(): array
{
    return [
        'carte' => [
            'name' => 'Carte interactive', 'emoji' => '🗺️', 'active' => true, 'order' => 1,
            'description' => 'Plan interactif sur fond IGN : zones, emplacements numérotés et choix de l\'emplacement par les exposants.',
            'types' => [],                       // tous les types
            'default_mode' => 'option',          // option payante ; les galeries déjà équipées d'un plan gardent leur accès
            'month_cents' => 900, 'year_cents' => 9000, 'trial_days' => 14,
        ],
        'organisateur' => [
            'name' => 'Espace organisateur', 'emoji' => '📋', 'active' => true, 'order' => 2,
            'description' => 'Événement (date, lieu, places ou mètres), numérotation automatique des places, tableau de bord des inscriptions et registre de police.',
            'types' => ['vide-grenier', 'puces', 'marche-de-noel', 'marche-producteurs'],
            'default_mode' => 'included',
            'month_cents' => 1900, 'year_cents' => 19000, 'trial_days' => 14,
        ],
        'attente' => [
            'name' => "File d'attente et places voisines", 'emoji' => '🕒', 'active' => true, 'order' => 3,
            'description' => "File d'attente des exposants, prévenus par e-mail dès qu'une place se libère (désistement, absence le jour même), et alerte aux voisins : une place vide à côté d'un stand est proposée à prix réduit pour l'agrandir.",
            'types' => ['vide-grenier', 'puces', 'marche-de-noel', 'marche-producteurs'],
            'default_mode' => 'included',
            'month_cents' => 0, 'year_cents' => 0, 'trial_days' => 0,
        ],
        'buvette' => [
            'name' => 'Buvette', 'emoji' => '☕', 'active' => true, 'order' => 4,
            'description' => "La buvette du vide-grenier : la carte et ses prix, les recettes et leur coût de revient, les fournitures et la liste de courses, le planning des bénévoles (avec un lien d'accès pour eux), la check-list du jour, le prévisionnel et le bilan.",
            'types' => ['vide-grenier'],         // proposée d'office aux vide-greniers ; les autres types la règlent dans « Types de galerie »
            'default_mode' => 'option',          // option payante : essai gratuit puis abonnement
            'month_cents' => 0, 'year_cents' => 0, 'trial_days' => 14,   // aucun prix imposé : à fixer dans le portail (Tarifs des extensions de galerie)
        ],
        'invendus' => [
            'name' => 'Invendus & dons', 'emoji' => '🎁', 'active' => true, 'order' => 5,
            'description' => "Zéro gaspillage : les exposants proposent ce qu'ils ne veulent pas remporter, un point de collecte (et des tournées à vélo cargo) réunit les objets, les visiteurs se servent gratuitement, puis le reliquat part vers une donnerie avec un bordereau de remise.",
            'types' => ['vide-grenier'],
            'default_mode' => 'option',
            'month_cents' => 0, 'year_cents' => 0, 'trial_days' => 14,
        ],
        'comptage' => [
            'name' => 'Comptage de foule', 'emoji' => '👥', 'active' => true, 'order' => 6,
            'description' => "Comptez les visiteurs aux entrées avec un téléphone (appuis ou détection par caméra), même hors ligne : un poste par entrée, la fréquentation en direct, le pic de présence, la jauge de capacité, la courbe de la journée et l'export CSV. Les bénévoles n'ont besoin que d'un lien et d'un code.",
            'types' => ['vide-grenier'],
            'default_mode' => 'option',
            'month_cents' => 0, 'year_cents' => 0, 'trial_days' => 14,
        ],
        'vente' => [
            'name' => 'Force de vente', 'emoji' => '🧭', 'active' => true, 'order' => 8,
            'description' => "Votre carnet de prospects : fiches et champs personnalisés, import d'un fichier Excel, CSV ou base SQLite, tournée sur carte, journal des échanges et messages de recrutement avec désinscription. Un carnet à vous, séparé des autres galeries.",
            'types' => [],                       // tous les types
            'default_mode' => 'option',          // option payante ; les galeries qui ont déjà des prospects gardent leur accès
            'month_cents' => 0, 'year_cents' => 0, 'trial_days' => 14,   // aucun prix imposé : à fixer dans le portail (Tarifs des extensions de galerie)
        ],
        'annulation' => [
            'name' => 'Annulation & remboursements', 'emoji' => '🌧️', 'active' => true, 'order' => 7,
            'description' => "Annulez l'événement (vide-grenier, puces, marché de Noël, marché de producteurs ; météo, force majeure, décision de l'organisateur) : demandes fermées, annonce publique, exposants prévenus par e-mail et remboursés en totalité ou en partie, avec un registre des virements, un montant ajustable par exposant et l'export CSV.",
            'types' => ['vide-grenier', 'puces', 'marche-de-noel', 'marche-producteurs'],
            'default_mode' => 'option',
            'month_cents' => 0, 'year_cents' => 0, 'trial_days' => 14,
        ],
        'sponsors' => [
            'name' => 'Sponsors & partenaires', 'emoji' => '🤝', 'active' => true, 'order' => 8,
            'description' => "Présentez ceux qui soutiennent votre événement (sponsor principal, sponsors, partenaires, soutiens institutionnels, médias) : logo, lien et mot de présentation, rangés par niveau sur la page publique de la galerie. Vous masquez ou réordonnez une fiche en un clic.",
            'types' => [],                       // tous les types de galerie
            'default_mode' => 'included',
            'month_cents' => 0, 'year_cents' => 0, 'trial_days' => 0,
        ],
    ];
}

function gm_file(): string
{
    // BROCANTE_GM_FILE : fichier de réglages jetable pour les tests (scripts/test-buvette-galerie.php).
    return getenv('BROCANTE_GM_FILE') ?: dirname(__DIR__) . '/data/saas/galeries-modules.json';
}

/** Tous les modules, réglages du portail par-dessus les valeurs d'origine. cle => module. */
function gm_modules(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $saved = is_file(gm_file()) ? (json_decode((string) file_get_contents(gm_file()), true) ?: []) : [];
    $out = [];
    foreach (gm_defaults() as $key => $d) {
        $s = is_array($saved[$key] ?? null) ? $saved[$key] : [];
        $text = static fn (string $k, int $max): string => mb_substr(trim((string) ($s[$k] ?? $d[$k])), 0, $max);
        $int = static fn (string $k, int $min, int $max): int => is_numeric($s[$k] ?? null) ? max($min, min($max, (int) $s[$k])) : (int) $d[$k];
        $types = $s['types'] ?? null;
        $types = is_array($types) ? array_values(array_filter(array_map('strval', $types), static fn ($t) => preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $t))) : $d['types'];
        $out[$key] = [
            'key' => $key,
            'name' => $text('name', 40) ?: $d['name'],
            'emoji' => $text('emoji', 8) ?: $d['emoji'],
            'description' => $text('description', 300) ?: $d['description'],
            'active' => !array_key_exists('active', $s) ? !empty($d['active']) : !empty($s['active']),
            'order' => $int('order', 1, 99),
            'types' => $types,
            'default_mode' => in_array($s['default_mode'] ?? '', GM_MODES, true) ? (string) $s['default_mode'] : $d['default_mode'],
            'month_cents' => $int('month_cents', 0, 1000000),
            'year_cents' => $int('year_cents', 0, 10000000),
            'trial_days' => $int('trial_days', 0, 365),
        ];
    }
    uasort($out, static fn ($a, $b) => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);
    return $cache = $out;
}

function gm_module(string $key): ?array
{
    return gm_modules()[$key] ?? null;
}

/** Prix saisi en euros (« 9,90 », « 9.9 », vide = 0) -> centimes, ou null si invalide. */
function gm_cents_from_input(mixed $value): ?int
{
    $v = str_replace([' ', "\u{00a0}", '€'], '', trim((string) $value));
    $v = str_replace(',', '.', $v);
    if ($v === '') return 0;
    return is_numeric($v) && (float) $v >= 0 && (float) $v <= 10000 ? (int) round((float) $v * 100) : null;
}

/**
 * Enregistre les réglages d'un module depuis le portail (data/saas/galeries-modules.json) : proposé ou non, mode d'origine, prix mensuel et
 * annuel (en euros), jours d'essai, types de galerie concernés (aucun = tous). Chaque type peut ensuite régler le mode de son côté
 * (Types de galerie). -> message d'erreur ou null.
 */
function gm_save_settings(string $key, array $in): ?string
{
    if (!isset(gm_defaults()[$key])) return ext_t('{Ext} inconnu{e}.');
    $month = gm_cents_from_input($in['month'] ?? '');
    $year = gm_cents_from_input($in['year'] ?? '');
    if ($month === null || $year === null) return 'Prix invalide : saisissez un montant en euros (ex. 9,90), de 0 à 10 000.';
    $trial = filter_var($in['trial_days'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 365]]);
    if ($trial === false) return "Durée d'essai invalide (0 à 365 jours).";
    $mode = in_array($in['default_mode'] ?? '', GM_MODES, true) ? (string) $in['default_mode'] : 'option';
    $types = array_values(array_unique(array_filter(array_map('strval', (array) ($in['types'] ?? [])), static fn ($t) => preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $t) === 1)));
    $file = gm_file();
    $all = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $all[$key] = array_merge(is_array($all[$key] ?? null) ? $all[$key] : [], [
        'active' => !empty($in['active']), 'default_mode' => $mode, 'month_cents' => $month, 'year_cents' => $year, 'trial_days' => $trial, 'types' => $types,
    ]);
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return "Impossible de créer le dossier $dir.";
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        return "Impossible d'écrire " . basename($file) . ' (droits du dossier data/saas).';
    }
    return null;
}

/** Mode d'un module pour un type, quand le type ne le règle pas encore. */
function gm_default_type_mode(string $typeKey, string $moduleKey): string
{
    $m = gm_module($moduleKey);
    if (!$m || !$m['active']) return 'off';
    if ($m['types'] && !in_array($typeKey, $m['types'], true)) return 'off';
    return in_array($m['default_mode'], GM_MODES, true) ? $m['default_mode'] : 'off';
}

/** Modes de tous les modules pour un type normalisé. */
function gm_type_modes(array $type): array
{
    $key = (string) ($type['key'] ?? '');
    $saved = (array) ($type['modules'] ?? []);
    $out = [];
    foreach (gm_modules() as $mk => $m) {
        $mode = (string) ($saved[$mk] ?? '');
        $out[$mk] = in_array($mode, GM_MODES, true) ? $mode : gm_default_type_mode($key, $mk);
    }
    return $out;
}

function gm_type_mode(array $type, string $key): string
{
    return gm_type_modes($type)[$key] ?? 'off';
}

/** État d'un module pour une galerie : mode du type, statut souscrit et droit d'usage. */
function gm_state(array $gallery, string $key): array
{
    $m = gm_module($key);
    if (!$m) return ['key' => $key, 'enabled' => false, 'status' => 'off', 'mode' => 'off', 'module' => null];
    $type = function_exists('gt_of') ? gt_of($gallery) : null;
    $mode = $type ? gm_type_mode($type, $key) : gm_default_type_mode((string) ($gallery['type'] ?? ''), $key);
    $entry = is_array($gallery['modules'][$key] ?? null) ? $gallery['modules'][$key] : [];
    $status = (string) ($entry['status'] ?? '');
    $enabled = false;
    if ($mode === 'included') {
        $status = 'included';
        $enabled = true;
    } elseif ($mode === 'option') {
        if ($status === 'trial') {
            if ((int) ($entry['until'] ?? 0) > time()) $enabled = true;
            else $status = 'expired';
        } elseif (in_array($status, ['active', 'complimentary'], true)) {
            $enabled = !empty($entry['enabled']);
            if (!$enabled) $status = 'canceled';
        } elseif ($key === 'carte' && !$entry && !empty($gallery['map']['items'])) {
            // Plan déjà dessiné avant le passage de la carte en option payante : on conserve l'accès.
            $status = 'complimentary';
            $enabled = true;
        } elseif ($key === 'vente' && !$entry && (require_once __DIR__ . '/vente-scope.php') && vs_gallery_legacy_count((string) ($gallery['slug'] ?? '')) > 0) {
            // Liste de prospects tenue avant l'extension « Force de vente » : la galerie la garde, avec l'accès offert.
            $status = 'complimentary';
            $enabled = true;
        } else {
            $status = $entry ? 'canceled' : 'off';
        }
    } else {
        $status = 'off';
    }
    return [
        'key' => $key, 'module' => $m, 'mode' => $mode, 'status' => $status, 'enabled' => $enabled,
        'until' => (int) ($entry['until'] ?? 0), 'trial_used' => !empty($entry['trial_used']),
        'price_cents' => (int) ($entry['price_cents'] ?? $m['month_cents']), 'billing' => (string) ($entry['billing'] ?? 'month'),
        'status_label' => GM_STATUS_LABELS[$status] ?? $status,
    ];
}

function gm_enabled(array $gallery, string $key): bool
{
    return gm_state($gallery, $key)['enabled'];
}

/** Normalise la clé « modules » d'une galerie (seuls les modules connus sont conservés). */
function gallery_modules_normalize(array $m): array
{
    $out = [];
    $token = static fn ($v): string => preg_match('/^[A-Za-z0-9_]{0,120}$/', (string) $v) ? (string) $v : '';
    foreach (gm_modules() as $key => $def) {
        $v = $m[$key] ?? null;
        if (!is_array($v)) continue;
        $status = in_array($v['status'] ?? '', GM_STATUSES, true) ? (string) $v['status'] : 'canceled';
        $out[$key] = [
            'enabled' => !empty($v['enabled']),
            'status' => $status,
            'since' => max(0, (int) ($v['since'] ?? 0)),
            'until' => max(0, (int) ($v['until'] ?? 0)),
            'trial_used' => !empty($v['trial_used']),
            'price_cents' => max(0, min(1000000, (int) ($v['price_cents'] ?? 0))),
            'billing' => ($v['billing'] ?? '') === 'year' ? 'year' : 'month',
            'stripe_subscription_id' => $token($v['stripe_subscription_id'] ?? ''),
            'stripe_customer_id' => $token($v['stripe_customer_id'] ?? ''),
            'stripe_session_id' => $token($v['stripe_session_id'] ?? ''),
        ];
    }
    return $out;
}

/** Démarre l'essai gratuit (une fois par galerie et par module). -> erreur ou null. */
function gm_start_trial(array &$gallery, string $key): ?string
{
    $st = gm_state($gallery, $key);
    if (!$st['module']) return ext_t('{Ext} inconnu{e}.');
    if ($st['mode'] !== 'option') return $st['mode'] === 'included' ? ext_t('{Ce ext} est déjà {?inclus|incluse} dans votre offre.') : ext_t("{Ce ext} n'est pas {?proposé|proposée} pour ce type de galerie.");
    if ($st['enabled']) return ext_t('{Ce ext} est déjà {?actif|active}.');
    if ($st['module']['trial_days'] < 1) return ext_t("L'essai n'est pas disponible pour {ce ext}.");
    if ($st['trial_used']) return "L'essai gratuit a déjà été utilisé : souscrivez pour continuer.";
    $gallery['modules'][$key] = [
        'enabled' => true, 'status' => 'trial', 'since' => time(), 'until' => time() + $st['module']['trial_days'] * 86400,
        'trial_used' => true, 'price_cents' => $st['module']['month_cents'], 'billing' => 'month',
    ];
    return null;
}

/** Active un module (souscription Stripe, accès offert par l'exploitant). */
function gm_activate(array &$gallery, string $key, string $status = 'active', array $stripe = [], string $billing = 'month'): void
{
    if (!in_array($status, ['active', 'complimentary', 'trial'], true)) return;
    $st = gm_state($gallery, $key);
    $prev = $gallery['modules'][$key] ?? [];
    $gallery['modules'][$key] = [
        'enabled' => true, 'status' => $status, 'since' => (int) ($prev['since'] ?? 0) ?: time(),
        'until' => $status === 'trial' ? max(time(), (int) ($prev['until'] ?? 0)) : 0,
        'trial_used' => !empty($prev['trial_used']), 'price_cents' => (int) ($prev['price_cents'] ?? $st['module']['month_cents']),
        'billing' => $billing === 'year' ? 'year' : 'month',
        'stripe_subscription_id' => (string) ($stripe['subscription'] ?? ($prev['stripe_subscription_id'] ?? '')),
        'stripe_customer_id' => (string) ($stripe['customer'] ?? ($prev['stripe_customer_id'] ?? '')),
        'stripe_session_id' => (string) ($stripe['session'] ?? ($prev['stripe_session_id'] ?? '')),
    ];
}

function gm_deactivate(array &$gallery, string $key, string $status = 'canceled'): void
{
    if (!isset($gallery['modules'][$key]) || !in_array($status, ['canceled', 'expired'], true)) return;
    $gallery['modules'][$key]['enabled'] = false;
    $gallery['modules'][$key]['status'] = $status;
}

function gm_price_text(int $cents, string $billing = 'month'): string
{
    if ($cents <= 0) return 'Gratuit';
    return rtrim(rtrim(number_format($cents / 100, 2, ',', ' '), '0'), ',') . ' € / ' . ($billing === 'year' ? 'an' : 'mois');
}

// ── Demande de paiement et application d'une session Stripe ────────────────

function gm_requests_dir(): string
{
    return dirname(__DIR__) . '/data/galeries/.modules';
}

function gm_request_save(array $r): bool
{
    $dir = gm_requests_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    return @file_put_contents($dir . '/' . $r['id'] . '.json', json_encode($r, JSON_UNESCAPED_UNICODE)) !== false;
}

function gm_request_load(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
    $f = gm_requests_dir() . '/' . $id . '.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : null;
}

/** Page de paiement Stripe d'un module (abonnement mensuel ou annuel). -> [url, erreur]. */
function gm_checkout(array $gallery, string $key, string $successPath, string $cancelUrl, string $billing = 'month'): array
{
    $st = gm_state($gallery, $key);
    if (!$st['module']) return [null, ext_t('{Ext} inconnu{e}.')];
    if ($st['mode'] === 'included') return [null, ext_t('{Ce ext} est déjà {?inclus|incluse} dans votre offre.')];
    if ($st['mode'] !== 'option') return [null, ext_t("{Ce ext} n'est pas {?proposé|proposée} pour ce type de galerie.")];
    if ($st['enabled'] && $st['status'] === 'active') return [null, ext_t('{Ce ext} est déjà {?souscrit|souscrite}.')];
    $billing = $billing === 'year' ? 'year' : 'month';
    $cents = $billing === 'year' ? (int) $st['module']['year_cents'] : (int) $st['module']['month_cents'];
    if ($cents <= 0) return [null, ext_t('{Ce ext} est {?gratuit|gratuite} : activez-{?le|la} depuis la page {Exts}.')];
    require_once __DIR__ . '/saas.php';
    if (!saas_stripe_configured()) return [null, "Le paiement en ligne n'est pas encore disponible : démarrez l'essai gratuit ou contactez l'exploitant."];
    $req = ['id' => bin2hex(random_bytes(8)), 'created' => time(), 'module' => $key, 'slug' => $gallery['slug'], 'billing' => $billing,
        'email' => (string) ($gallery['admins'][0]['email'] ?? ''), 'done' => ''];
    $origin = saas_origin();
    $meta = ['type' => 'gallery_module', 'request' => $req['id'], 'module' => $key, 'slug' => $gallery['slug']];
    try {
        $s = saas_stripe_request('POST', '/checkout/sessions', [
            'mode' => 'subscription', 'locale' => 'fr', 'customer_email' => $req['email'], 'client_reference_id' => 'gmodule:' . $req['id'],
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => $cents,
                'recurring' => ['interval' => $billing === 'year' ? 'year' : 'month'],
                'product_data' => ['name' => $st['module']['name'] . ' — ' . $gallery['name'], 'description' => mb_substr($st['module']['description'], 0, 300)]]]],
            'metadata' => $meta, 'subscription_data' => ['metadata' => $meta],
            'success_url' => $origin . $successPath . (str_contains($successPath, '?') ? '&' : '?') . 'r=' . $req['id'] . '&s={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ]);
    } catch (Throwable $e) {
        error_log('gm_checkout ' . $key . ': ' . $e->getMessage());
        return [null, "Impossible d'ouvrir le paiement : " . $e->getMessage()];
    }
    if (empty($s['url'])) return [null, "Stripe n'a pas renvoyé d'adresse de paiement."];
    gm_request_save($req);
    return [$s['url'], null];
}

/** Session Checkout payée → module actif. Idempotent. -> ['state' => added|already|unpaid|invalid, 'slug', 'module']. */
function gm_apply_session(array $session): array
{
    $m = (array) ($session['metadata'] ?? []);
    if (($m['type'] ?? '') !== 'gallery_module') return ['state' => 'invalid'];
    if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid'];
    $req = gm_request_load((string) ($m['request'] ?? ''));
    if (!$req || ($req['slug'] ?? '') === '' || ($req['module'] ?? '') !== ($m['module'] ?? '')) return ['state' => 'invalid'];
    if (function_exists('gallery_load') === false) require_once __DIR__ . '/galleries.php';
    $g = gallery_load((string) $req['slug']);
    if (!$g || !gm_module((string) $req['module'])) return ['state' => 'invalid'];
    if (!empty($req['done'])) return ['state' => 'already', 'slug' => $g['slug'], 'module' => $req['module']];
    $stripe = ['subscription' => (string) ($session['subscription'] ?? ''), 'customer' => (string) ($session['customer'] ?? ''), 'session' => (string) ($session['id'] ?? '')];
    gm_activate($g, (string) $req['module'], 'active', $stripe, (string) ($req['billing'] ?? 'month'));
    if (!gallery_save($g)) return ['state' => 'invalid'];
    $req['done'] = (string) $req['slug'];
    gm_request_save($req);
    return ['state' => 'added', 'slug' => $g['slug'], 'module' => (string) $req['module']];
}

/** Abonnement résilié (webhook) : désactive le module de la galerie concernée. -> [slug, module] ou null. */
function gm_cancel_subscription(string $subscriptionId): ?array
{
    if ($subscriptionId === '') return null;
    if (!function_exists('gallery_list')) require_once __DIR__ . '/galleries.php';
    foreach (gallery_list() as $g) {
        foreach ((array) ($g['modules'] ?? []) as $key => $entry) {
            if (($entry['stripe_subscription_id'] ?? '') !== $subscriptionId) continue;
            gm_deactivate($g, (string) $key, 'canceled');
            if (gallery_save($g)) return [$g['slug'], (string) $key];
        }
    }
    return null;
}
