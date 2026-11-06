<?php
/**
 * Module « Impression » : commander l'impression d'affiches, cartes de visite,
 * flyers, plans ou goodies auprès de fournisseurs, par API quand ils en ont une.
 *
 * Principe :
 *   1. on crée une commande d'impression (produit, quantité, fichier prêt à
 *      imprimer, adresse de livraison) ;
 *   2. « Comparer les fournisseurs » demande un devis à chaque fournisseur
 *      connecté (Gelato, Prodigi) qui a une référence pour ce produit ;
 *   3. on choisit une offre → la commande part chez le fournisseur ;
 *   4. le statut et le suivi colis se rafraîchissent depuis l'API.
 *
 * Les fournisseurs sans API (imprimeur du coin, devis par e-mail) sont gérés à
 * la main : le module prépare le brief, on saisit le prix reçu, on suit la
 * commande à la main.
 *
 * SÉCURITÉ FINANCIÈRE : commander par API engage de l'argent réel. Le module
 * démarre en mode TEST (Gelato : commande « brouillon » non produite ; Prodigi :
 * sandbox gratuit). Le mode LIVE se choisit explicitement, et un plafond par
 * commande (150 € par défaut) bloque les erreurs de saisie.
 *
 * Tables préfixées pr_, créées à la volée par pr_boot() (le déploiement
 * n'envoie jamais brocante.db). Recopiées en commentaire dans schema.sql.
 * PHP 8.0+, SQLite, cURL (comme le reste du site).
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

/** Taille maximum d'un fichier à imprimer (octets). */
const PR_MAX_FILE_BYTES = 50 * 1024 * 1024;
/** Dossier (relatif à la racine du site) des fichiers à imprimer. */
const PR_UPLOAD_DIR = 'uploads/impression';
/** Plafond par défaut d'une commande, en centimes (modifiable dans le module). */
const PR_DEFAULT_MAX_ORDER_CENTS = 15000;

/* ======================================================================
 * Schéma
 * ==================================================================== */

const PR_SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS pr_settings (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS pr_suppliers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'devis',       -- api (connecteur fourni) | api_externe (API existe, pas de connecteur) | devis (à la main)
  adapter TEXT NOT NULL DEFAULT '',         -- gelato | prodigi (uniquement si kind = api)
  website TEXT NOT NULL DEFAULT '',
  docs_url TEXT NOT NULL DEFAULT '',
  catalog_url TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',           -- pour la demande de devis
  phone TEXT NOT NULL DEFAULT '',
  country TEXT NOT NULL DEFAULT '',
  specialties TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  is_builtin INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pr_products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'autre',   -- affiche | carte | flyer | plan | goodies | autre
  width_mm INTEGER,                         -- format fini (sert au contrôle de résolution)
  height_mm INTEGER,
  default_qty INTEGER NOT NULL DEFAULT 1,
  description TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  sort INTEGER NOT NULL DEFAULT 0
);

-- Référence du produit chez chaque fournisseur (productUid Gelato, SKU Prodigi…).
CREATE TABLE IF NOT EXISTS pr_product_map (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id INTEGER NOT NULL,
  supplier_id INTEGER NOT NULL,
  sku TEXT NOT NULL,
  options TEXT NOT NULL DEFAULT '',         -- JSON d'attributs (ex Prodigi : {"color":"black"})
  note TEXT NOT NULL DEFAULT '',
  UNIQUE (product_id, supplier_id),
  FOREIGN KEY (product_id) REFERENCES pr_products(id) ON DELETE CASCADE,
  FOREIGN KEY (supplier_id) REFERENCES pr_suppliers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pr_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL DEFAULT '',             -- PR-0001 (référence envoyée aux fournisseurs)
  idem_key TEXT NOT NULL,                   -- clé anti-doublon envoyée au fournisseur
  title TEXT NOT NULL,
  product_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 1,
  file_path TEXT NOT NULL DEFAULT '',
  file_name TEXT NOT NULL DEFAULT '',
  file_mime TEXT NOT NULL DEFAULT '',
  file_bytes INTEGER NOT NULL DEFAULT 0,
  file_w INTEGER,
  file_h INTEGER,
  rec_first TEXT NOT NULL DEFAULT '',
  rec_last TEXT NOT NULL DEFAULT '',
  rec_company TEXT NOT NULL DEFAULT '',
  rec_address1 TEXT NOT NULL DEFAULT '',
  rec_address2 TEXT NOT NULL DEFAULT '',
  rec_postcode TEXT NOT NULL DEFAULT '',
  rec_city TEXT NOT NULL DEFAULT '',
  rec_state TEXT NOT NULL DEFAULT '',
  rec_country TEXT NOT NULL DEFAULT 'FR',
  rec_email TEXT NOT NULL DEFAULT '',
  rec_phone TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'draft',     -- draft | quoted | quote_requested | ordering | ordered | in_production | shipped | delivered | cancelled | failed
  supplier_id INTEGER,
  quote_id INTEGER,
  option_key TEXT NOT NULL DEFAULT '',
  option_label TEXT NOT NULL DEFAULT '',
  estimate_cents INTEGER,                   -- prix du devis retenu
  charged_cents INTEGER,                    -- montant confirmé par le fournisseur, quand il le donne
  currency TEXT NOT NULL DEFAULT 'EUR',
  provider_order_id TEXT NOT NULL DEFAULT '',
  provider_status TEXT NOT NULL DEFAULT '',
  channel TEXT NOT NULL DEFAULT '',         -- api | manual
  tracking_url TEXT NOT NULL DEFAULT '',
  tracking_code TEXT NOT NULL DEFAULT '',
  test_mode INTEGER NOT NULL DEFAULT 1,
  error TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  ordered_at TEXT,
  FOREIGN KEY (product_id) REFERENCES pr_products(id),
  FOREIGN KEY (supplier_id) REFERENCES pr_suppliers(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_pr_jobs_status ON pr_jobs(status);

CREATE TABLE IF NOT EXISTS pr_quotes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_id INTEGER NOT NULL,
  supplier_id INTEGER NOT NULL,
  option_key TEXT NOT NULL DEFAULT '',      -- méthode de livraison chez le fournisseur
  label TEXT NOT NULL DEFAULT '',
  items_cents INTEGER,
  shipping_cents INTEGER,
  total_cents INTEGER,                      -- NULL = pas de prix (erreur)
  currency TEXT NOT NULL DEFAULT 'EUR',
  days_min INTEGER,
  days_max INTEGER,
  manual INTEGER NOT NULL DEFAULT 0,        -- 1 = prix saisi à la main (devis reçu par e-mail…)
  error TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  FOREIGN KEY (job_id) REFERENCES pr_jobs(id) ON DELETE CASCADE,
  FOREIGN KEY (supplier_id) REFERENCES pr_suppliers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_pr_quotes_job ON pr_quotes(job_id);

CREATE TABLE IF NOT EXISTS pr_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_id INTEGER NOT NULL,
  at TEXT NOT NULL,
  event TEXT NOT NULL,
  detail TEXT NOT NULL DEFAULT '',
  FOREIGN KEY (job_id) REFERENCES pr_jobs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_pr_log_job ON pr_log(job_id);
SQL;

/** Crée les tables (idempotent) et charge l'annuaire / catalogue d'exemple la première fois. */
function pr_boot(): void
{
    static $done = null;
    $dbKey = defined('DB_PATH') ? DB_PATH : '';
    if ($done === $dbKey) return;
    db()->exec(PR_SCHEMA);
    pr_seed_defaults();
    pr_migrate_suppliers();
    $done = $dbKey;
}

/* ======================================================================
 * Réglages, secrets, formats
 * ==================================================================== */

function pr_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function pr_setting_get(string $k, string $default = ''): string
{
    $st = db()->prepare('SELECT v FROM pr_settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function pr_setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO pr_settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

/** Mode courant : 'test' (par défaut, rien n'est produit ni facturé) ou 'live'. */
function pr_mode(): string
{
    return pr_setting_get('mode', 'test') === 'live' ? 'live' : 'test';
}

function pr_is_test(): bool
{
    return pr_mode() === 'test';
}

function pr_max_order_cents(): int
{
    $v = (int) pr_setting_get('max_order_cents', (string) PR_DEFAULT_MAX_ORDER_CENTS);
    return $v > 0 ? $v : PR_DEFAULT_MAX_ORDER_CENTS;
}

/** Noms de secrets autorisés (un fichier .secrets/impression_<nom>.key chacun). */
function pr_secret_names(): array
{
    return [
        'gelato' => 'Gelato — clé API',
        'prodigi_live' => 'Prodigi — clé API live',
        'prodigi_sandbox' => 'Prodigi — clé API sandbox',
        'cloudprinter_sandbox' => 'Cloudprinter — clé API sandbox',
        'cloudprinter_live' => 'Cloudprinter — clé API live',
    ];
}

function pr_secrets_dir(): string
{
    return defined('PR_SECRETS_DIR') ? PR_SECRETS_DIR : dirname(__DIR__) . '/.secrets';
}

/** Lit une clé API : variable d'environnement PR_SECRET_<NOM> d'abord, puis le fichier .secrets/. */
function pr_secret_get(string $name): string
{
    if (!isset(pr_secret_names()[$name])) return '';
    $env = getenv('PR_SECRET_' . strtoupper($name));
    if (is_string($env) && trim($env) !== '') return trim($env);
    $file = pr_secrets_dir() . '/impression_' . $name . '.key';
    return is_file($file) ? trim((string) file_get_contents($file)) : '';
}

function pr_secret_set(string $name, string $value): bool
{
    if (!isset(pr_secret_names()[$name])) return false;
    $dir = pr_secrets_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    $file = $dir . '/impression_' . $name . '.key';
    if (file_put_contents($file, trim($value)) === false) return false;
    @chmod($file, 0600);
    return true;
}

/** Montant décimal d'API ("12.47", 12.47, "12,47") → centimes. */
function pr_cents($amount): int
{
    if (is_string($amount)) $amount = str_replace([' ', ','], ['', '.'], $amount);
    return (int) round(((float) $amount) * 100);
}

function pr_eur(?int $cents, string $currency = 'EUR'): string
{
    if ($cents === null) return '—';
    $sym = $currency === 'EUR' ? "\u{00A0}€" : ' ' . $currency;
    return number_format($cents / 100, 2, ',', "\u{202F}") . $sym;
}

/** Taille de fichier lisible : « 340 Ko » ou « 12,4 Mo ». */
function pr_size_label(int $bytes): string
{
    return $bytes < 1048576 ? max(1, (int) round($bytes / 1024)) . "\u{00A0}Ko" : number_format($bytes / 1048576, 1, ',', '') . "\u{00A0}Mo";
}

function pr_fr_datetime(?string $utc): string
{
    if (!$utc) return '—';
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i');
    } catch (Exception $e) {
        return $utc;
    }
}

function pr_categories(): array
{
    return [
        'affiche' => 'Affiches', 'carte' => 'Cartes de visite', 'flyer' => 'Flyers & dépliants',
        'plan' => 'Plans & grands formats', 'goodies' => 'Goodies', 'autre' => 'Autre',
    ];
}

function pr_status_labels(): array
{
    return [
        'draft' => ['Brouillon', ''],
        'quoted' => ['Devis comparés', ''],
        'quote_requested' => ['Devis demandé', 'warn'],
        'ordering' => ['Envoi en cours…', 'warn'],
        'ordered' => ['Commandée', 'ok'],
        'in_production' => ['En production', 'ok'],
        'shipped' => ['Expédiée', 'ok'],
        'delivered' => ['Livrée', 'ok'],
        'cancelled' => ['Annulée', ''],
        'failed' => ['Échec', 'bad'],
    ];
}

function pr_status_badge(array $job): string
{
    [$label, $cls] = pr_status_labels()[$job['status']] ?? [$job['status'], ''];
    $html = '<span class="bv-badge ' . h($cls) . '">' . h($label) . '</span>';
    if ($job['status'] !== 'draft' && $job['status'] !== 'quoted' && $job['status'] !== 'quote_requested' && !empty($job['test_mode']) && ($job['channel'] ?? '') === 'api') {
        $html .= ' <span class="bv-badge warn" title="Commande passée en mode test : rien n\'est produit ni facturé">TEST</span>';
    }
    return $html;
}

/* ======================================================================
 * HTTP (un seul point d'entrée, remplaçable par les tests)
 * ==================================================================== */

/**
 * Appel HTTPS JSON. Retourne ['status' => int, 'body' => ?array, 'raw' => string, 'error' => string].
 * Les tests branchent $GLOBALS['PR_HTTP_HANDLER'] (callable) pour ne jamais sortir sur le réseau.
 */
function pr_http(string $method, string $url, array $headers = [], ?array $json = null, int $timeout = 25): array
{
    if (!str_starts_with($url, 'https://')) {
        return ['status' => 0, 'body' => null, 'raw' => '', 'error' => 'Seules les URL https:// sont autorisées.'];
    }
    $handler = $GLOBALS['PR_HTTP_HANDLER'] ?? null;
    if (is_callable($handler)) {
        return $handler($method, $url, $headers, $json);
    }
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => null, 'raw' => '', 'error' => 'L\'extension PHP cURL est absente du serveur.'];
    }
    $h = array_merge(['Accept: application/json'], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($json !== null || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $h[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }
    $opts[CURLOPT_HTTPHEADER] = $h;
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = $raw === false ? curl_error($ch) : '';
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = $raw !== false ? json_decode((string) $raw, true) : null;
    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $raw === false ? '' : (string) $raw, 'error' => $err];
}

/** Message d'erreur lisible pour une réponse d'API en échec ('' si succès). */
function pr_api_error(array $res): string
{
    if ($res['error'] !== '') return 'Réseau : ' . $res['error'];
    if ($res['status'] >= 200 && $res['status'] < 300) return '';
    if ($res['status'] === 401 || $res['status'] === 403) return 'Clé API refusée par le fournisseur (HTTP ' . $res['status'] . ').';
    $b = $res['body'] ?? [];
    $msg = $b['message'] ?? ($b['error']['message'] ?? ($b['error'] ?? ($b['statusText'] ?? '')));
    if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
    if (($msg === '' || $msg === null) && !empty($b['failures'])) $msg = json_encode($b['failures'], JSON_UNESCAPED_UNICODE);
    if (!empty($b['info']) && is_string($b['info']) && is_string($msg)) $msg .= ' — ' . $b['info'];
    if ($msg === '' || $msg === null) $msg = mb_substr(trim($res['raw']), 0, 300);
    return 'HTTP ' . $res['status'] . ($msg !== '' ? ' — ' . mb_substr((string) $msg, 0, 400) : '');
}

/* ======================================================================
 * Connecteurs fournisseurs
 * ==================================================================== */

abstract class PrProvider
{
    abstract public function adapter(): string;
    abstract public function label(): string;

    /** Le devis a-t-il expiré ? (seuls certains fournisseurs limitent la validité d'un devis) */
    public function quoteExpired(array $quote): bool
    {
        return false;
    }
    /** Nom du secret à lire selon le mode (voir pr_secret_names()). */
    abstract public function secretName(bool $test): string;

    public function isConfigured(bool $test): bool
    {
        return pr_secret_get($this->secretName($test)) !== '';
    }

    /** @return array{ok:bool,error:string,options:array} options : [key,label,items_cents,shipping_cents,total_cents,currency,days_min,days_max] */
    abstract public function quote(array $job, string $sku, array $attrs, bool $test): array;

    /** @return array{ok:bool,error:string,provider_order_id:string,status:string,raw_status:string,tracking_url:string,tracking_code:string,charged_cents:?int} */
    abstract public function order(array $job, string $sku, array $attrs, array $quote, bool $test): array;

    /** @return array{ok:bool,error:string,status:string,raw_status:string,tracking_url:string,tracking_code:string,charged_cents:?int} */
    abstract public function status(string $providerOrderId, bool $test): array;

    /** @return array{ok:bool,error:string} */
    abstract public function cancel(string $providerOrderId, bool $test): array;

    protected function fail(string $error): array
    {
        return [
            'ok' => false, 'error' => $error, 'options' => [], 'provider_order_id' => '', 'status' => '',
            'raw_status' => '', 'tracking_url' => '', 'tracking_code' => '', 'charged_cents' => null,
        ];
    }
}

/**
 * Gelato — https://dashboard.gelato.com/docs/ (API v4).
 * Une seule clé (en-tête X-API-KEY). Mode test = commande de type « draft » :
 * enregistrée chez Gelato mais jamais envoyée en production ni facturée.
 */
class PrGelato extends PrProvider
{
    private const ORDER_BASE = 'https://order.gelatoapis.com/v4';

    public function adapter(): string { return 'gelato'; }
    public function label(): string { return 'Gelato'; }
    public function secretName(bool $test): string { return 'gelato'; }

    private function headers(): array
    {
        return ['X-API-KEY: ' . pr_secret_get('gelato')];
    }

    private function address(array $job): array
    {
        $a = [
            'country' => $job['rec_country'], 'firstName' => $job['rec_first'], 'lastName' => $job['rec_last'],
            'addressLine1' => $job['rec_address1'], 'city' => $job['rec_city'], 'postCode' => $job['rec_postcode'],
            'email' => $job['rec_email'],
        ];
        foreach (['companyName' => 'rec_company', 'addressLine2' => 'rec_address2', 'state' => 'rec_state', 'phone' => 'rec_phone'] as $api => $col) {
            if (trim((string) $job[$col]) !== '') $a[$api] = $job[$col];
        }
        return $a;
    }

    private function files(array $job): array
    {
        $url = pr_job_file_url($job);
        return $url !== '' ? [['type' => 'default', 'url' => $url]] : [];
    }

    public function quote(array $job, string $sku, array $attrs, bool $test): array
    {
        $product = ['itemReferenceId' => 'item-1', 'productUid' => $sku, 'quantity' => (int) $job['quantity']];
        if ($files = $this->files($job)) $product['files'] = $files;
        $res = pr_http('POST', self::ORDER_BASE . '/orders:quote', $this->headers(), [
            'orderReferenceId' => $job['ref'], 'customerReferenceId' => $job['ref'], 'currency' => 'EUR',
            'allowMultipleQuotes' => false, 'recipient' => $this->address($job), 'products' => [$product],
        ]);
        if ($err = pr_api_error($res)) return $this->fail($err);

        $options = [];
        foreach (($res['body']['quotes'] ?? []) as $q) {
            // Prix des articles = somme des lignes du devis (price = prix de la ligne, quantité comprise).
            $items = 0;
            foreach (($q['products'] ?? []) as $p) $items += pr_cents($p['price'] ?? 0);
            foreach (($q['shipmentMethods'] ?? []) as $m) {
                $ship = pr_cents($m['price'] ?? 0);
                $options[] = [
                    'key' => (string) ($m['shipmentMethodUid'] ?? ''),
                    'label' => trim((string) ($m['name'] ?? '') . (isset($m['type']) ? ' (' . $m['type'] . ')' : '')),
                    'items_cents' => $items, 'shipping_cents' => $ship, 'total_cents' => $items + $ship,
                    'currency' => (string) ($m['currency'] ?? ($q['products'][0]['currency'] ?? 'EUR')),
                    'days_min' => isset($m['minDeliveryDays']) ? (int) $m['minDeliveryDays'] : null,
                    'days_max' => isset($m['maxDeliveryDays']) ? (int) $m['maxDeliveryDays'] : null,
                ];
            }
        }
        if (!$options) return $this->fail('Gelato n\'a proposé aucune livraison pour cette destination / ce produit.');
        return ['ok' => true, 'error' => '', 'options' => $options];
    }

    public function order(array $job, string $sku, array $attrs, array $quote, bool $test): array
    {
        $item = ['itemReferenceId' => 'item-1', 'productUid' => $sku, 'quantity' => (int) $job['quantity'], 'files' => $this->files($job)];
        $res = pr_http('POST', self::ORDER_BASE . '/orders', $this->headers(), [
            'orderType' => $test ? 'draft' : 'order',
            'orderReferenceId' => $job['ref'], 'customerReferenceId' => $job['ref'], 'currency' => 'EUR',
            'items' => [$item], 'shipmentMethodUid' => $quote['option_key'], 'shippingAddress' => $this->address($job),
        ]);
        if ($err = pr_api_error($res)) return $this->fail($err);
        $b = $res['body'] ?? [];
        if (empty($b['id'])) return $this->fail('Réponse Gelato inattendue (pas d\'identifiant de commande).');
        return ['ok' => true, 'error' => ''] + $this->parseOrder($b, $test) + ['provider_order_id' => (string) $b['id']];
    }

    public function status(string $providerOrderId, bool $test): array
    {
        $res = pr_http('GET', self::ORDER_BASE . '/orders/' . rawurlencode($providerOrderId), $this->headers());
        if ($err = pr_api_error($res)) return $this->fail($err);
        return ['ok' => true, 'error' => ''] + $this->parseOrder($res['body'] ?? [], $test);
    }

    public function cancel(string $providerOrderId, bool $test): array
    {
        $res = pr_http('POST', self::ORDER_BASE . '/orders/' . rawurlencode($providerOrderId) . ':cancel', $this->headers());
        if ($res['status'] === 409) return ['ok' => false, 'error' => 'Gelato refuse l\'annulation : l\'impression a déjà commencé.'];
        if ($err = pr_api_error($res)) return ['ok' => false, 'error' => $err];
        return ['ok' => true, 'error' => ''];
    }

    private function parseOrder(array $b, bool $test): array
    {
        $raw = (string) ($b['fulfillmentStatus'] ?? '');
        $track = ['url' => '', 'code' => ''];
        foreach (($b['shipment']['packages'] ?? []) as $pkg) {
            if (!empty($pkg['trackingUrl']) || !empty($pkg['trackingCode'])) {
                $track = ['url' => (string) ($pkg['trackingUrl'] ?? ''), 'code' => (string) ($pkg['trackingCode'] ?? '')];
                break;
            }
        }
        $charged = null;
        foreach (($b['receipts'] ?? []) as $r) {
            if (isset($r['total'])) { $charged = pr_cents($r['total']); break; }
        }
        return [
            'status' => self::mapStatus($raw), 'raw_status' => $raw,
            'tracking_url' => $track['url'], 'tracking_code' => $track['code'], 'charged_cents' => $charged,
        ];
    }

    /** Statuts Gelato (doc « How orders work ») → statuts du module. */
    public static function mapStatus(string $raw): string
    {
        return match ($raw) {
            'delivered' => 'delivered',
            'shipped', 'in_transit' => 'shipped',
            'printed', 'in_production' => 'in_production',
            'canceled', 'cancelled' => 'cancelled',
            'failed', 'returned' => 'failed',
            default => 'ordered', // created, uploading, passed, draft, pending_approval, on_hold…
        };
    }
}

/**
 * Prodigi — https://www.prodigi.com/print-api/docs/ (API v4).
 * Deux clés distinctes (en-tête X-API-Key) : sandbox (gratuit, rien n'est produit)
 * et live. Mode test = hôte sandbox + clé sandbox.
 */
class PrProdigi extends PrProvider
{
    public function adapter(): string { return 'prodigi'; }
    public function label(): string { return 'Prodigi'; }
    public function secretName(bool $test): string { return $test ? 'prodigi_sandbox' : 'prodigi_live'; }

    private function base(bool $test): string
    {
        return $test ? 'https://api.sandbox.prodigi.com/v4.0' : 'https://api.prodigi.com/v4.0';
    }

    private function headers(bool $test): array
    {
        return ['X-API-Key: ' . pr_secret_get($this->secretName($test))];
    }

    /** Méthodes de livraison interrogées pour comparer (Budget < Standard < Express). */
    public static function shippingMethods(): array
    {
        return ['Budget' => 'Économique', 'Standard' => 'Standard', 'Express' => 'Express'];
    }

    public function quote(array $job, string $sku, array $attrs, bool $test): array
    {
        $options = [];
        $lastError = '';
        foreach (self::shippingMethods() as $method => $label) {
            $res = pr_http('POST', $this->base($test) . '/quotes', $this->headers($test), [
                'shippingMethod' => $method, 'destinationCountryCode' => $job['rec_country'], 'currencyCode' => 'EUR',
                'items' => [[
                    'sku' => $sku, 'copies' => (int) $job['quantity'],
                    'attributes' => $attrs ? $attrs : new stdClass(),
                    'assets' => [['printArea' => 'default']],
                ]],
            ]);
            if ($err = pr_api_error($res)) {
                $lastError = $err;
                if ($res['status'] === 401 || $res['status'] === 403) break; // inutile d'insister avec une clé refusée
                continue;
            }
            foreach (($res['body']['quotes'] ?? []) as $q) {
                $items = pr_cents($q['costSummary']['items']['amount'] ?? 0);
                $ship = pr_cents($q['costSummary']['shipping']['amount'] ?? 0);
                $options[] = [
                    'key' => (string) ($q['shipmentMethod'] ?? $method), 'label' => $label,
                    'items_cents' => $items, 'shipping_cents' => $ship, 'total_cents' => $items + $ship,
                    'currency' => (string) ($q['costSummary']['items']['currency'] ?? 'EUR'),
                    'days_min' => null, 'days_max' => null,
                ];
            }
        }
        if (!$options) return $this->fail($lastError !== '' ? $lastError : 'Prodigi n\'a renvoyé aucun devis pour cette référence / destination.');
        return ['ok' => true, 'error' => '', 'options' => $options];
    }

    public function order(array $job, string $sku, array $attrs, array $quote, bool $test): array
    {
        $recipient = [
            'name' => trim($job['rec_first'] . ' ' . $job['rec_last']),
            'address' => [
                'line1' => $job['rec_address1'], 'line2' => $job['rec_address2'] !== '' ? $job['rec_address2'] : null,
                'postalOrZipCode' => $job['rec_postcode'], 'countryCode' => $job['rec_country'],
                'townOrCity' => $job['rec_city'], 'stateOrCounty' => $job['rec_state'] !== '' ? $job['rec_state'] : null,
            ],
        ];
        if ($job['rec_email'] !== '') $recipient['email'] = $job['rec_email'];
        if ($job['rec_phone'] !== '') $recipient['phoneNumber'] = $job['rec_phone'];

        $res = pr_http('POST', $this->base($test) . '/Orders', $this->headers($test), [
            'merchantReference' => $job['ref'],
            'idempotencyKey' => $job['ref'] . '-' . $job['idem_key'], // un renvoi du même ordre ne crée pas de doublon
            'shippingMethod' => $quote['option_key'],
            'recipient' => $recipient,
            'items' => [[
                'merchantReference' => $job['ref'] . '-1', 'sku' => $sku, 'copies' => (int) $job['quantity'],
                'sizing' => 'fillPrintArea', 'attributes' => $attrs ? $attrs : new stdClass(),
                'assets' => [['printArea' => 'default', 'url' => pr_job_file_url($job)]],
            ]],
        ]);
        if ($err = pr_api_error($res)) return $this->fail($err);
        $order = $res['body']['order'] ?? [];
        if (empty($order['id'])) {
            return $this->fail('Prodigi n\'a pas créé la commande : ' . mb_substr((string) ($res['body']['outcome'] ?? $res['raw']), 0, 300));
        }
        return ['ok' => true, 'error' => ''] + $this->parseOrder($order) + ['provider_order_id' => (string) $order['id']];
    }

    public function status(string $providerOrderId, bool $test): array
    {
        $res = pr_http('GET', $this->base($test) . '/Orders/' . rawurlencode($providerOrderId), $this->headers($test));
        if ($err = pr_api_error($res)) return $this->fail($err);
        return ['ok' => true, 'error' => ''] + $this->parseOrder($res['body']['order'] ?? []);
    }

    public function cancel(string $providerOrderId, bool $test): array
    {
        $res = pr_http('POST', $this->base($test) . '/Orders/' . rawurlencode($providerOrderId) . '/actions/cancel', $this->headers($test));
        if ($err = pr_api_error($res)) return ['ok' => false, 'error' => $err];
        if (($res['body']['outcome'] ?? 'Cancelled') !== 'Cancelled') {
            return ['ok' => false, 'error' => 'Prodigi refuse l\'annulation (' . (string) ($res['body']['outcome'] ?? '?') . ') — la production a sans doute commencé.'];
        }
        return ['ok' => true, 'error' => ''];
    }

    private function parseOrder(array $o): array
    {
        $track = ['url' => '', 'code' => ''];
        foreach (($o['shipments'] ?? []) as $s) {
            if (!empty($s['tracking'])) {
                $track = ['url' => (string) ($s['tracking']['url'] ?? ''), 'code' => (string) ($s['tracking']['number'] ?? '')];
                break;
            }
        }
        $charged = null;
        foreach (($o['charges'] ?? []) as $c) {
            if (isset($c['totalCost']['amount'])) { $charged = pr_cents($c['totalCost']['amount']); break; }
        }
        $stage = (string) ($o['status']['stage'] ?? '');
        return [
            'status' => self::mapStatus($o), 'raw_status' => $stage,
            'tracking_url' => $track['url'], 'tracking_code' => $track['code'], 'charged_cents' => $charged,
        ];
    }

    /** Statut Prodigi (stage + détails d'étape) → statut du module. */
    public static function mapStatus(array $order): string
    {
        $stage = (string) ($order['status']['stage'] ?? '');
        $d = $order['status']['details'] ?? [];
        if ($stage === 'Cancelled') return 'cancelled';
        if ($stage === 'Complete' || ($d['shipping'] ?? '') === 'Complete') return 'shipped';
        if (in_array($d['inProduction'] ?? '', ['InProgress', 'Complete'], true)) return 'in_production';
        return 'ordered';
    }
}

/**
 * Cloudprinter — https://docs.cloudprinter.com/client/cloudprinter-core-api-v1-0 (Core API 1.0).
 * Particularités : tous les appels sont des POST JSON ; la clé API voyage dans le CORPS
 * (champ « apikey »), pas en en-tête. Le mode test n'est pas un paramètre d'appel : il
 * dépend de l'« interface CloudCore API » créée dans leur tableau de bord (Sandbox =
 * commandes gratuites, jamais envoyées à un imprimeur ; Live = production réelle), donc
 * de la clé utilisée — d'où deux clés, comme pour Prodigi.
 * Les prix sont renvoyés HT avec la TVA à part : le module additionne les deux (TTC).
 * Un devis (hash « quote ») est valable 48 h et se passe tel quel dans la commande.
 */
class PrCloudprinter extends PrProvider
{
    private const BASE = 'https://api.cloudprinter.com/cloudcore/1.0';
    /** Validité d'un devis Cloudprinter, en secondes (48 h d'après la doc). */
    public const QUOTE_TTL = 172800;

    public function adapter(): string { return 'cloudprinter'; }
    public function label(): string { return 'Cloudprinter'; }
    public function secretName(bool $test): string { return $test ? 'cloudprinter_sandbox' : 'cloudprinter_live'; }

    /** POST JSON avec la clé dans le corps. */
    private function call(string $path, array $body, bool $test): array
    {
        return pr_http('POST', self::BASE . $path, [], ['apikey' => pr_secret_get($this->secretName($test))] + $body);
    }

    /** Référence de commande envoyée à Cloudprinter : unique sur tout le compte (sandbox et live compris). */
    public static function orderReference(array $job): string
    {
        return $job['ref'] . '-' . substr((string) $job['idem_key'], 0, 8);
    }

    /** Options du produit : {"type": quantité} dans la fiche de référence → [{type, count}]. */
    private function options(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $type => $count) {
            if (is_scalar($count)) $out[] = ['type' => (string) $type, 'count' => (string) $count];
        }
        return $out;
    }

    public function quoteExpired(array $quote): bool
    {
        $at = strtotime((string) ($quote['created_at'] ?? '') . ' UTC');
        return $at !== false && (time() - $at) > self::QUOTE_TTL;
    }

    public function quote(array $job, string $sku, array $attrs, bool $test): array
    {
        $item = ['reference' => 'item-1', 'product' => $sku, 'count' => (string) (int) $job['quantity']];
        if ($opts = $this->options($attrs)) $item['options'] = $opts;
        $body = ['currency' => 'EUR', 'country' => $job['rec_country'], 'items' => [$item]];
        if ($job['rec_state'] !== '') $body['state'] = $job['rec_state'];
        $res = $this->call('/orders/quote', $body, $test);
        if ($err = pr_api_error($res)) return $this->fail($err);

        $b = $res['body'] ?? [];
        $shipments = $b['shipments'] ?? [];
        if (count($shipments) !== 1) {
            return $this->fail(count($shipments) > 1
                ? 'Cloudprinter propose un envoi en plusieurs colis : non géré par ce module (1 ligne = 1 colis).'
                : 'Cloudprinter n\'a proposé aucune livraison pour cette destination / ce produit.');
        }
        $items = pr_cents($b['price'] ?? 0) + pr_cents($b['vat'] ?? 0);
        $expires = isset($b['expire_date']) ? strtotime((string) $b['expire_date']) : false;
        $options = [];
        foreach (($shipments[0]['quotes'] ?? []) as $q) {
            if (empty($q['quote'])) continue;
            $ship = pr_cents($q['price'] ?? 0) + pr_cents($q['vat'] ?? 0);
            $options[] = [
                'key' => (string) $q['quote'],
                'label' => trim((string) ($q['service'] ?? '') . (!empty($q['shipping_option']) ? ' — ' . $q['shipping_option'] : '')),
                'items_cents' => $items, 'shipping_cents' => $ship, 'total_cents' => $items + $ship,
                'currency' => (string) ($q['currency'] ?? $b['currency'] ?? 'EUR'),
                'days_min' => null, 'days_max' => null,
                'note' => $expires ? 'Devis valable jusqu\'au ' . pr_fr_datetime(gmdate('Y-m-d H:i:s', $expires)) . ' (TVA comprise)' : 'Devis valable 48 h (TVA comprise)',
            ];
        }
        if (!$options) return $this->fail('Cloudprinter n\'a proposé aucun mode de livraison.');
        return ['ok' => true, 'error' => '', 'options' => $options];
    }

    public function order(array $job, string $sku, array $attrs, array $quote, bool $test): array
    {
        $address = [
            'type' => 'delivery', 'firstname' => $job['rec_first'], 'lastname' => $job['rec_last'],
            'street1' => $job['rec_address1'], 'zip' => $job['rec_postcode'], 'city' => $job['rec_city'],
            'country' => $job['rec_country'], 'email' => $job['rec_email'],
        ];
        foreach (['company' => 'rec_company', 'street2' => 'rec_address2', 'state' => 'rec_state', 'phone' => 'rec_phone'] as $api => $col) {
            if (trim((string) $job[$col]) !== '') $address[$api] = $job[$col];
        }
        $file = ['type' => 'product', 'url' => pr_job_file_url($job)];
        $local = dirname(__DIR__) . '/' . $job['file_path'];
        if (is_file($local)) $file['md5sum'] = md5_file($local); // Cloudprinter vérifie l'intégrité du fichier téléchargé

        $item = [
            'reference' => 'item-1', 'product' => $sku, 'quote' => $quote['option_key'], // le hash de devis porte déjà le niveau de livraison
            'title' => mb_substr($job['title'], 0, 100), 'count' => (string) (int) $job['quantity'], 'files' => [$file],
        ];
        if ($opts = $this->options($attrs)) $item['options'] = $opts;

        $reference = self::orderReference($job);
        $res = $this->call('/orders/add', [
            'reference' => $reference, 'email' => $job['rec_email'], 'addresses' => [$address], 'items' => [$item],
        ], $test);
        if ($err = pr_api_error($res)) return $this->fail($err);
        return [
            'ok' => true, 'error' => '', 'provider_order_id' => $reference, 'status' => 'ordered', 'raw_status' => 'order_created',
            'tracking_url' => '', 'tracking_code' => '', 'charged_cents' => null,
        ];
    }

    public function status(string $providerOrderId, bool $test): array
    {
        $res = $this->call('/orders/info', ['reference' => $providerOrderId], $test);
        if ($res['status'] === 410) return $this->fail('Commande introuvable chez Cloudprinter (410) : vérifiez que la clé correspond au bon mode (sandbox / live).');
        if ($err = pr_api_error($res)) return $this->fail($err);
        $b = $res['body'] ?? [];
        $tracking = '';
        foreach (($b['items'] ?? []) as $it) {
            if (!empty($it['tracking']) && is_string($it['tracking'])) { $tracking = $it['tracking']; break; }
        }
        $state = (int) ($b['state'] ?? 0);
        return [
            'ok' => true, 'error' => '', 'status' => self::mapStatus($state),
            'raw_status' => (string) ($b['state_code'] ?? ('état ' . $state)),
            'tracking_url' => '', 'tracking_code' => $tracking, 'charged_cents' => null,
        ];
    }

    public function cancel(string $providerOrderId, bool $test): array
    {
        $res = $this->call('/orders/cancel', ['reference' => $providerOrderId], $test);
        if ($res['status'] === 409) return ['ok' => false, 'error' => 'Cloudprinter refuse l\'annulation : la commande n\'est plus dans un état annulable (production commencée).'];
        if ($res['status'] === 410) return ['ok' => false, 'error' => 'Commande introuvable chez Cloudprinter (410).'];
        if ($err = pr_api_error($res)) return ['ok' => false, 'error' => $err];
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Produits activés sur le compte (référence à copier dans « Catalogue & références »).
     * @return array{ok:bool,error:string,products:array}
     */
    public function listProducts(bool $test): array
    {
        $res = $this->call('/products', [], $test);
        if ($err = pr_api_error($res)) return ['ok' => false, 'error' => $err, 'products' => []];
        $out = [];
        foreach (($res['body'] ?? []) as $p) {
            if (!is_array($p) || empty($p['reference'])) continue;
            $out[] = [
                'reference' => (string) $p['reference'], 'name' => (string) ($p['name'] ?? ''), 'note' => (string) ($p['note'] ?? ''),
                'category' => (string) ($p['category'] ?? ''), 'from_price' => (string) ($p['from_price'] ?? ''), 'currency' => (string) ($p['currency'] ?? 'EUR'),
            ];
        }
        return ['ok' => true, 'error' => '', 'products' => $out];
    }

    /**
     * États Cloudprinter (« State definitions ») → statuts du module.
     * 1–45 : commande en cours de vérification / préparation / envoi des fichiers à l'imprimeur ;
     * 45 : fichiers remis à l'imprimeur ; 100 : toute la commande expédiée ou livrée
     * (Cloudprinter ne distingue pas « expédiée » de « livrée ») ; 500 : annulée ;
     * 501 : commande sandbox terminée ; 7, 11, 31, 35, 39, 41 : erreurs.
     */
    public static function mapStatus(int $state): string
    {
        if (in_array($state, [7, 11, 31, 35, 39, 41], true)) return 'failed';
        if ($state === 500) return 'cancelled';
        if ($state === 100 || $state === 501) return 'shipped';
        if ($state === 45) return 'in_production';
        return 'ordered';
    }
}

/** Connecteur d'un fournisseur (null si le fournisseur n'a pas de connecteur fourni). */
function pr_provider(string $adapter): ?PrProvider
{
    return match ($adapter) {
        'gelato' => new PrGelato(),
        'prodigi' => new PrProdigi(),
        'cloudprinter' => new PrCloudprinter(),
        default => null,
    };
}

/* ======================================================================
 * Annuaire des fournisseurs, catalogue, références
 * ==================================================================== */

function pr_supplier_kinds(): array
{
    return [
        'api' => 'API connectée (devis + commande automatiques)',
        'api_externe' => 'API existante, connecteur non fourni',
        'devis' => 'Sans API — commande ou devis à la main',
    ];
}

function pr_suppliers_list(bool $onlyActive = false): array
{
    $sql = 'SELECT * FROM pr_suppliers' . ($onlyActive ? ' WHERE is_active = 1' : '')
        . " ORDER BY CASE kind WHEN 'api' THEN 0 WHEN 'api_externe' THEN 1 ELSE 2 END, name COLLATE NOCASE";
    return db()->query($sql)->fetchAll();
}

function pr_supplier_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM pr_suppliers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function pr_slugify(string $s): string
{
    $s = function_exists('slugify') ? slugify($s, 40) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s));
    return trim($s, '-') ?: 'fournisseur';
}

/** Valide et enregistre un fournisseur (création si $id = null). Retourne [id, erreur]. */
function pr_supplier_save(?int $id, array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') return [null, 'Le nom du fournisseur est obligatoire.'];
    $kind = (string) ($in['kind'] ?? 'devis');
    if (!isset(pr_supplier_kinds()[$kind])) return [null, 'Type de fournisseur invalide.'];
    $website = trim((string) ($in['website'] ?? ''));
    foreach (['website', 'docs_url', 'catalog_url'] as $f) {
        $u = trim((string) ($in[$f] ?? ''));
        if ($u !== '' && !preg_match('#^https?://#i', $u)) return [null, 'Les adresses web doivent commencer par http:// ou https://.'];
    }
    $email = trim((string) ($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Adresse e-mail du fournisseur invalide.'];

    $vals = [
        'name' => mb_substr($name, 0, 120), 'website' => mb_substr($website, 0, 300),
        'docs_url' => mb_substr(trim((string) ($in['docs_url'] ?? '')), 0, 300),
        'catalog_url' => mb_substr(trim((string) ($in['catalog_url'] ?? '')), 0, 300),
        'email' => $email, 'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40),
        'country' => mb_substr(trim((string) ($in['country'] ?? '')), 0, 60),
        'specialties' => mb_substr(trim((string) ($in['specialties'] ?? '')), 0, 400),
        'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000),
        'is_active' => empty($in['is_active']) ? 0 : 1,
    ];
    if ($id === null) {
        // Un fournisseur ajouté à la main n'a jamais de connecteur : 'api' est réservé aux fournisseurs livrés avec le module.
        if ($kind === 'api') $kind = 'api_externe';
        $base = pr_slugify($name);
        $slug = $base;
        for ($i = 2; db()->query("SELECT 1 FROM pr_suppliers WHERE slug = " . db()->quote($slug))->fetchColumn(); $i++) $slug = $base . '-' . $i;
        db()->prepare('INSERT INTO pr_suppliers (slug, name, kind, adapter, website, docs_url, catalog_url, email, phone, country, specialties, notes, is_active, is_builtin, created_at)
                       VALUES (?, ?, ?, \'\', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([$slug, $vals['name'], $kind, $vals['website'], $vals['docs_url'], $vals['catalog_url'], $vals['email'], $vals['phone'], $vals['country'], $vals['specialties'], $vals['notes'], $vals['is_active'], pr_now()]);
        return [(int) db()->lastInsertId(), null];
    }
    $cur = pr_supplier_get($id);
    if (!$cur) return [null, 'Fournisseur introuvable.'];
    if ($cur['adapter'] !== '') $kind = 'api'; // le type d'un fournisseur connecté ne change pas
    elseif ($kind === 'api') $kind = 'api_externe';
    db()->prepare('UPDATE pr_suppliers SET name = ?, kind = ?, website = ?, docs_url = ?, catalog_url = ?, email = ?, phone = ?, country = ?, specialties = ?, notes = ?, is_active = ? WHERE id = ?')
        ->execute([$vals['name'], $kind, $vals['website'], $vals['docs_url'], $vals['catalog_url'], $vals['email'], $vals['phone'], $vals['country'], $vals['specialties'], $vals['notes'], $vals['is_active'], $id]);
    return [$id, null];
}

function pr_supplier_delete(int $id): ?string
{
    $s = pr_supplier_get($id);
    if (!$s) return 'Fournisseur introuvable.';
    if ((int) $s['is_builtin'] === 1) return 'Les fournisseurs de l\'annuaire de base ne se suppriment pas — désactivez-les.';
    $n = db()->prepare('SELECT COUNT(*) FROM pr_jobs WHERE supplier_id = ?');
    $n->execute([$id]);
    if ((int) $n->fetchColumn() > 0) return 'Des commandes sont liées à ce fournisseur — désactivez-le plutôt.';
    db()->prepare('DELETE FROM pr_suppliers WHERE id = ?')->execute([$id]);
    return null;
}

function pr_products_list(bool $onlyActive = false): array
{
    $sql = 'SELECT * FROM pr_products' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY sort, label COLLATE NOCASE';
    return db()->query($sql)->fetchAll();
}

function pr_product_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM pr_products WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function pr_product_save(?int $id, array $in): array
{
    $label = trim((string) ($in['label'] ?? ''));
    if ($label === '') return [null, 'Le nom du produit est obligatoire.'];
    $cat = (string) ($in['category'] ?? 'autre');
    if (!isset(pr_categories()[$cat])) $cat = 'autre';
    $w = (int) ($in['width_mm'] ?? 0);
    $hh = (int) ($in['height_mm'] ?? 0);
    if ($w < 0 || $hh < 0 || $w > 5000 || $hh > 5000) return [null, 'Dimensions invalides (0 à 5000 mm).'];
    $qty = max(1, min(100000, (int) ($in['default_qty'] ?? 1)));
    $vals = [mb_substr($label, 0, 120), $cat, $w ?: null, $hh ?: null, $qty, mb_substr(trim((string) ($in['description'] ?? '')), 0, 400), empty($in['is_active']) ? 0 : 1];
    if ($id === null) {
        $base = pr_slugify($label);
        $slug = $base;
        for ($i = 2; db()->query("SELECT 1 FROM pr_products WHERE slug = " . db()->quote($slug))->fetchColumn(); $i++) $slug = $base . '-' . $i;
        $sort = (int) db()->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM pr_products')->fetchColumn();
        db()->prepare('INSERT INTO pr_products (slug, label, category, width_mm, height_mm, default_qty, description, is_active, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge([$slug], $vals, [$sort]));
        return [(int) db()->lastInsertId(), null];
    }
    if (!pr_product_get($id)) return [null, 'Produit introuvable.'];
    db()->prepare('UPDATE pr_products SET label = ?, category = ?, width_mm = ?, height_mm = ?, default_qty = ?, description = ?, is_active = ? WHERE id = ?')
        ->execute(array_merge($vals, [$id]));
    return [$id, null];
}

function pr_product_delete(int $id): ?string
{
    $n = db()->prepare('SELECT COUNT(*) FROM pr_jobs WHERE product_id = ?');
    $n->execute([$id]);
    if ((int) $n->fetchColumn() > 0) return 'Des commandes utilisent ce produit — désactivez-le plutôt.';
    db()->prepare('DELETE FROM pr_products WHERE id = ?')->execute([$id]);
    return null;
}

function pr_map_get(int $productId, int $supplierId): ?array
{
    $st = db()->prepare('SELECT * FROM pr_product_map WHERE product_id = ? AND supplier_id = ?');
    $st->execute([$productId, $supplierId]);
    return $st->fetch() ?: null;
}

function pr_maps_for_product(int $productId): array
{
    $st = db()->prepare('SELECT * FROM pr_product_map WHERE product_id = ?');
    $st->execute([$productId]);
    $out = [];
    foreach ($st->fetchAll() as $m) $out[(int) $m['supplier_id']] = $m;
    return $out;
}

/** Enregistre (ou, si $sku est vide, supprime) la référence d'un produit chez un fournisseur. */
function pr_map_save(int $productId, int $supplierId, string $sku, string $optionsJson = '', string $note = ''): ?string
{
    if (!pr_product_get($productId) || !pr_supplier_get($supplierId)) return 'Produit ou fournisseur introuvable.';
    $sku = trim($sku);
    if ($sku === '') {
        db()->prepare('DELETE FROM pr_product_map WHERE product_id = ? AND supplier_id = ?')->execute([$productId, $supplierId]);
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9._:\-\/ +]{1,200}$/', $sku)) return 'Référence invalide (lettres, chiffres et - _ . : / seulement).';
    $optionsJson = trim($optionsJson);
    if ($optionsJson !== '') {
        $d = json_decode($optionsJson, true);
        if (!is_array($d) || ($d !== [] && array_keys($d) === range(0, count($d) - 1))) return 'Les options doivent être un objet JSON, ex : {"color":"black"}.';
        $optionsJson = json_encode($d, JSON_UNESCAPED_UNICODE);
    }
    db()->prepare('INSERT INTO pr_product_map (product_id, supplier_id, sku, options, note) VALUES (?, ?, ?, ?, ?)
                   ON CONFLICT(product_id, supplier_id) DO UPDATE SET sku = excluded.sku, options = excluded.options, note = excluded.note')
        ->execute([$productId, $supplierId, $sku, $optionsJson, mb_substr(trim($note), 0, 300)]);
    return null;
}

function pr_map_attrs(array $map): array
{
    $d = json_decode((string) ($map['options'] ?? ''), true);
    return is_array($d) ? $d : [];
}

/** Fiches de l'annuaire de base : [slug, nom, type, connecteur, site, doc API, catalogue, e-mail, pays, spécialités, notes]. */
function pr_builtin_rows(): array
{
    return [
            ['gelato', 'Gelato', 'api', 'gelato', 'https://www.gelato.com', 'https://dashboard.gelato.com/docs/', 'https://dashboard.gelato.com/', '', 'International (production locale, plus de 30 pays)',
                'Affiches, cartes de visite, flyers, goodies (mugs, textile…)',
                'API v4 : devis, commande, suivi, annulation. Clé à créer dans le tableau de bord Gelato (section développeur). Mode test = commande « brouillon » : enregistrée chez Gelato mais jamais produite ni facturée. Les références produit (productUid) se prennent dans leur catalogue.'],
            ['prodigi', 'Prodigi', 'api', 'prodigi', 'https://www.prodigi.com', 'https://www.prodigi.com/print-api/docs/', 'https://www.prodigi.com/print-api/docs/', '', 'Royaume-Uni, UE, États-Unis (laboratoires partenaires)',
                'Affiches et tirages d\'art, cadres, photo-books, coques et autres goodies',
                'API v4 : devis, commande, suivi, annulation. Deux clés : sandbox (gratuite — aucune production ni coût) et live. Références = SKU (ex : GLOBAL-FAP-16x24).'],
            ['cloudprinter', 'Cloudprinter', 'api', 'cloudprinter', 'https://www.cloudprinter.com', 'https://docs.cloudprinter.com/client/cloudprinter-core-api-v1-0', 'https://www.cloudprinter.com/countries/local-printing-in-france-with-global-print-api', '', 'International — impression locale en France annoncée',
                'Livres, brochures et produits imprimés à la demande (catalogue activé compte par compte)',
                'API Core : devis, commande, suivi, annulation. Le mode test dépend de l\'interface API créée dans leur tableau de bord (Sandbox : commandes gratuites, jamais produites ; Live : réel) — d\'où deux clés. Compte gratuit, mais un abonnement mensuel démarre quand vous enregistrez votre carte pour passer en live. Prix HT + TVA à part (le module affiche le TTC). Devis valables 48 h. Catalogue surtout orienté livres et produits imprimés : vérifiez la présence d\'affiches / cartes de visite avec « Voir les produits » (Fournisseurs & clés API).'],
            ['print-com', 'Print.com', 'api_externe', '', 'https://www.print.com', 'https://www.print.com/fr/api/', 'https://www.print.com/fr/', '', 'Pays-Bas — livraison en Europe',
                'Cartes de visite, flyers, supports imprimés ; expédition en marque blanche (dropshipping)',
                'API pour connecter une boutique ou une plateforme et externaliser les commandes. Connecteur non fourni dans ce module.'],
            ['helloprint', 'Helloprint Connect', 'api_externe', '', 'https://connect.helloprint.fr/landing', 'https://connect.helloprint.fr/landing', 'https://connect.helloprint.fr/landing', '', 'Europe',
                'Plus de 10 000 produits : cartes de visite, flyers, brochures, signalétique, stickers, cadeaux promo, vêtements',
                'Plateforme réservée aux revendeurs print vérifiés ; commande via Connect ou via leur API. Inscription et validation nécessaires. Connecteur non fourni dans ce module.'],
            ['exaprint', 'Exaprint', 'api_externe', '', 'https://www.exaprint.fr', 'https://connect.exaprint.com/documentation/', 'https://www.exaprint.fr/exaconnect/', '', 'France',
                'Cartes de visite, flyers, dépliants, brochures, affiches, cartes postales',
                'API « ExaConnect » gratuite (jeton d\'accès, création de commande, envoi des fichiers, suivi, annulation) mais accès SUR DEMANDE : formulaire sur la page ExaConnect. Catalogue API limité aux produits les plus courants. Aucun devis dans les fonctions annoncées. Connecteur non fourni : en attendant, commande sur leur site et suivi à la main ici.'],
            ['pixartprinting', 'Pixartprinting', 'devis', '', 'https://www.pixartprinting.com', '', 'https://www.pixartprinting.com', '', 'Europe',
                'Cartes de visite, étiquettes, livres et magazines, grands formats',
                'Imprimeur en ligne orienté professionnels. Aucune API publique vérifiée : commande sur leur site, suivi à la main ici.'],
    ];
}

/**
 * Met à niveau les fiches de l'annuaire de base d'une installation existante
 * (Cloudprinter devient un connecteur fourni ; Exaprint a une API sur demande).
 * Ne modifie que les fiches de base, et ne remplace une note que si elle est
 * encore celle d'origine : un texte que vous avez édité n'est jamais écrasé.
 */
function pr_migrate_suppliers(): void
{
    $pdo = db();
    $rows = [];
    foreach ($pdo->query("SELECT * FROM pr_suppliers WHERE is_builtin = 1 AND slug IN ('cloudprinter', 'exaprint')")->fetchAll() as $r) $rows[$r['slug']] = $r;

    $oldNotes = [
        'cloudprinter' => 'API publique (Core API : devis, commande). Connecteur non fourni dans ce module. À étudier si vous voulez imprimer en France via une API.',
        'exaprint' => 'Imprimerie en ligne française. Aucune API publique vérifiée : commande directe sur leur site, puis suivi à la main ici.',
    ];
    $fresh = [];
    foreach (pr_builtin_rows() as $r) if (in_array($r[0], ['cloudprinter', 'exaprint'], true)) $fresh[$r[0]] = $r;

    if (isset($rows['cloudprinter']) && $rows['cloudprinter']['adapter'] === '') {
        $n = $rows['cloudprinter']['notes'] === $oldNotes['cloudprinter'] ? $fresh['cloudprinter'][10] : $rows['cloudprinter']['notes'];
        $pdo->prepare("UPDATE pr_suppliers SET kind = 'api', adapter = 'cloudprinter', notes = ? WHERE slug = 'cloudprinter'")->execute([$n]);
    }
    if (isset($rows['exaprint']) && $rows['exaprint']['kind'] === 'devis' && $rows['exaprint']['notes'] === $oldNotes['exaprint']) {
        $f = $fresh['exaprint'];
        $pdo->prepare("UPDATE pr_suppliers SET kind = 'api_externe', docs_url = ?, catalog_url = ?, notes = ? WHERE slug = 'exaprint'")->execute([$f[5], $f[6], $f[10]]);
    }
}

/**
 * Annuaire de départ + catalogue de produits courants. Uniquement des faits
 * vérifiés dans la documentation publique des fournisseurs (nov. 2026) ; tout
 * le reste est laissé à compléter (références produit, tarifs).
 */
function pr_seed_defaults(): void
{
    $pdo = db();
    if ((int) $pdo->query('SELECT COUNT(*) FROM pr_suppliers')->fetchColumn() === 0) {
        $rows = pr_builtin_rows();
        $st = $pdo->prepare('INSERT INTO pr_suppliers (slug, name, kind, adapter, website, docs_url, catalog_url, email, country, specialties, notes, is_active, is_builtin, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?)');
        foreach ($rows as $r) $st->execute(array_merge($r, [pr_now()]));
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM pr_products')->fetchColumn() === 0) {
        $rows = [
            ['carte-visite-85x55', 'Cartes de visite 85 × 55 mm', 'carte', 85, 55, 250, 'Format standard français, recto-verso.'],
            ['flyer-a6', 'Flyers A6 (105 × 148 mm)', 'flyer', 105, 148, 500, ''],
            ['flyer-a5', 'Flyers A5 (148 × 210 mm)', 'flyer', 148, 210, 250, ''],
            ['affiche-a3', 'Affiche A3 (297 × 420 mm)', 'affiche', 297, 420, 10, ''],
            ['affiche-a2', 'Affiche A2 (420 × 594 mm)', 'affiche', 420, 594, 5, ''],
            ['affiche-40x60', 'Affiche 40 × 60 cm', 'affiche', 400, 600, 1, 'Environ 16 × 24 pouces.'],
            ['affiche-a1', 'Affiche A1 (594 × 841 mm)', 'affiche', 594, 841, 2, ''],
            ['plan-a1', 'Plan A1 (594 × 841 mm)', 'plan', 594, 841, 1, 'Plan du vide-grenier, plan d\'implantation des stands.'],
            ['plan-a0', 'Plan A0 (841 × 1189 mm)', 'plan', 841, 1189, 1, ''],
            ['mug', 'Mug personnalisé', 'goodies', null, null, 12, ''],
            ['tote-bag', 'Tote bag personnalisé', 'goodies', null, null, 25, ''],
            ['autocollants', 'Autocollants / stickers', 'goodies', null, null, 100, ''],
        ];
        $st = $pdo->prepare('INSERT INTO pr_products (slug, label, category, width_mm, height_mm, default_qty, description, is_active, sort) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
        foreach ($rows as $i => $r) $st->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], ($i + 1) * 10]);

        // Seule référence confirmée dans la documentation publique : SKU Prodigi « Enhanced Matte Art Paper 200 gsm, 40×60 cm / 16×24" ».
        $p = $pdo->query("SELECT id FROM pr_products WHERE slug = 'affiche-40x60'")->fetchColumn();
        $s = $pdo->query("SELECT id FROM pr_suppliers WHERE slug = 'prodigi'")->fetchColumn();
        if ($p && $s) {
            $pdo->prepare('INSERT OR IGNORE INTO pr_product_map (product_id, supplier_id, sku, options, note) VALUES (?, ?, ?, \'\', ?)')
                ->execute([$p, $s, 'GLOBAL-FAP-16x24', 'Exemple de la documentation Prodigi (papier mat 200 g) — à confirmer dans leur catalogue.']);
        }
    }
}

/* ======================================================================
 * Fichiers à imprimer
 * ==================================================================== */

/** Contrôle un fichier reçu (sans le déplacer). Retourne ['ok','error','ext','mime','width','height']. */
function pr_file_inspect(string $tmpPath, string $origName, int $size): array
{
    $bad = fn(string $e) => ['ok' => false, 'error' => $e, 'ext' => '', 'mime' => '', 'width' => null, 'height' => null];
    if ($size <= 0 || !is_file($tmpPath)) return $bad('Aucun fichier reçu.');
    if ($size > PR_MAX_FILE_BYTES) return $bad('Fichier trop lourd (' . round($size / 1048576) . ' Mo) — maximum ' . (PR_MAX_FILE_BYTES / 1048576) . ' Mo.');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff'], true)) return $bad('Format non accepté : PDF, PNG, JPEG ou TIFF uniquement.');
    $head = (string) file_get_contents($tmpPath, false, null, 0, 8);
    $width = $height = null;
    if ($ext === 'pdf') {
        if (!str_starts_with($head, '%PDF-')) return $bad('Ce fichier n\'est pas un vrai PDF.');
        $mime = 'application/pdf';
    } else {
        $info = @getimagesize($tmpPath);
        if (!$info) return $bad('Ce fichier n\'est pas une image lisible.');
        $mime = (string) $info['mime'];
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/tiff'], true)) return $bad('Format d\'image non accepté (' . $mime . ').');
        [$width, $height] = [(int) $info[0], (int) $info[1]];
    }
    return ['ok' => true, 'error' => '', 'ext' => $ext === 'jpeg' ? 'jpg' : ($ext === 'tiff' ? 'tif' : $ext), 'mime' => $mime, 'width' => $width, 'height' => $height];
}

/**
 * Copie un fichier validé dans uploads/impression/ sous un nom aléatoire.
 * Le fichier doit être joignable publiquement (le fournisseur le télécharge par
 * URL) : le nom aléatoire de 32 caractères tient lieu de secret.
 */
function pr_file_store(string $tmpPath, array $inspect, bool $uploaded = true): ?string
{
    $dir = dirname(__DIR__) . '/' . PR_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) @file_put_contents($htaccess, "Options -Indexes\n");
    $name = bin2hex(random_bytes(16)) . '.' . $inspect['ext'];
    $ok = $uploaded ? move_uploaded_file($tmpPath, $dir . '/' . $name) : copy($tmpPath, $dir . '/' . $name);
    return $ok ? PR_UPLOAD_DIR . '/' . $name : null;
}

/** Le site est-il joignable depuis Internet ? (sinon un fournisseur ne peut pas télécharger le fichier) */
function pr_site_is_public(): bool
{
    $url = defined('SITE_URL') ? (string) SITE_URL : '';
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    $host = strtolower($p['host']);
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, '.lan')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return true;
}

/** URL publique du fichier d'une commande ('' si absente ou si le site n'est pas public). */
function pr_job_file_url(array $job): string
{
    if (($job['file_path'] ?? '') === '' || !pr_site_is_public()) return '';
    return rtrim((string) SITE_URL, '/') . '/' . ltrim($job['file_path'], '/');
}

/**
 * Résolution effective du fichier sur le format fini.
 * Retourne null (PDF / goodies : non évaluable) ou ['dpi' => int, 'level' => ok|warn|bad, 'text' => string].
 */
function pr_dpi_check(array $job, array $product): ?array
{
    if (empty($job['file_w']) || empty($job['file_h']) || empty($product['width_mm']) || empty($product['height_mm'])) return null;
    $pxLong = max($job['file_w'], $job['file_h']);
    $pxShort = min($job['file_w'], $job['file_h']);
    $mmLong = max($product['width_mm'], $product['height_mm']);
    $mmShort = min($product['width_mm'], $product['height_mm']);
    $dpi = (int) round(min($pxLong / ($mmLong / 25.4), $pxShort / ($mmShort / 25.4)));
    $need = in_array($product['category'], ['affiche', 'plan'], true) ? 150 : 250;
    if ($dpi >= $need) return ['dpi' => $dpi, 'level' => 'ok', 'text' => "$dpi dpi sur le format fini — suffisant."];
    if ($dpi >= (int) ($need * 0.6)) return ['dpi' => $dpi, 'level' => 'warn', 'text' => "$dpi dpi sur le format fini : un peu juste (visez $need dpi ou plus). L'impression sera moins nette."];
    return ['dpi' => $dpi, 'level' => 'bad', 'text' => "Seulement $dpi dpi sur le format fini (visez $need dpi ou plus) : l'impression sera floue. Fournissez un fichier de plus grande définition."];
}

/* ======================================================================
 * Commandes d'impression
 * ==================================================================== */

function pr_job_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM pr_jobs WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function pr_jobs_list(int $limit = 200): array
{
    $st = db()->prepare('SELECT j.*, p.label AS product_label, s.name AS supplier_name
                         FROM pr_jobs j JOIN pr_products p ON p.id = j.product_id LEFT JOIN pr_suppliers s ON s.id = j.supplier_id
                         ORDER BY j.id DESC LIMIT ?');
    $st->execute([$limit]);
    return $st->fetchAll();
}

function pr_log(int $jobId, string $event, string $detail = ''): void
{
    db()->prepare('INSERT INTO pr_log (job_id, at, event, detail) VALUES (?, ?, ?, ?)')
        ->execute([$jobId, pr_now(), $event, mb_substr($detail, 0, 1500)]);
}

function pr_job_log(int $jobId): array
{
    $st = db()->prepare('SELECT * FROM pr_log WHERE job_id = ? ORDER BY id DESC');
    $st->execute([$jobId]);
    return $st->fetchAll();
}

/** Valide le formulaire d'une commande. Retourne [valeurs nettoyées, liste d'erreurs]. */
function pr_job_input(array $in): array
{
    $err = [];
    $c = [];
    $c['title'] = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120);
    if ($c['title'] === '') $err[] = 'Donnez un titre à la commande (ex : « Affiches vide-grenier de printemps »).';
    $c['product_id'] = (int) ($in['product_id'] ?? 0);
    if (!pr_product_get($c['product_id'])) $err[] = 'Choisissez un produit.';
    $c['quantity'] = (int) ($in['quantity'] ?? 0);
    if ($c['quantity'] < 1 || $c['quantity'] > 100000) $err[] = 'Quantité invalide (1 à 100 000).';

    $c['rec_first'] = mb_substr(trim((string) ($in['rec_first'] ?? '')), 0, 60);
    $c['rec_last'] = mb_substr(trim((string) ($in['rec_last'] ?? '')), 0, 60);
    if ($c['rec_first'] === '' || $c['rec_last'] === '') $err[] = 'Prénom et nom du destinataire obligatoires.';
    $c['rec_company'] = mb_substr(trim((string) ($in['rec_company'] ?? '')), 0, 100);
    $c['rec_address1'] = mb_substr(trim((string) ($in['rec_address1'] ?? '')), 0, 120);
    $c['rec_address2'] = mb_substr(trim((string) ($in['rec_address2'] ?? '')), 0, 120);
    if ($c['rec_address1'] === '') $err[] = 'Adresse de livraison obligatoire.';
    $c['rec_postcode'] = mb_substr(trim((string) ($in['rec_postcode'] ?? '')), 0, 12);
    $c['rec_city'] = mb_substr(trim((string) ($in['rec_city'] ?? '')), 0, 80);
    if ($c['rec_postcode'] === '' || $c['rec_city'] === '') $err[] = 'Code postal et ville obligatoires.';
    $c['rec_state'] = mb_substr(trim((string) ($in['rec_state'] ?? '')), 0, 60);
    $c['rec_country'] = strtoupper(trim((string) ($in['rec_country'] ?? 'FR')));
    if (!preg_match('/^[A-Z]{2}$/', $c['rec_country'])) $err[] = 'Pays : code à 2 lettres (FR, BE, CH…).';
    $c['rec_email'] = trim((string) ($in['rec_email'] ?? ''));
    if (!filter_var($c['rec_email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Adresse e-mail du destinataire obligatoire (le transporteur l\'utilise).';
    $c['rec_phone'] = trim((string) ($in['rec_phone'] ?? ''));
    if ($c['rec_phone'] !== '' && !preg_match('/^[0-9 +().\-]{6,25}$/', $c['rec_phone'])) $err[] = 'Numéro de téléphone invalide.';
    $c['notes'] = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 1000);
    return [$c, $err];
}

/** Crée une commande en brouillon. $file = résultat de pr_file_inspect() + 'path','name','bytes'. Retourne l'id. */
function pr_job_create(array $clean, array $file): int
{
    $pdo = db();
    $pdo->prepare('INSERT INTO pr_jobs (idem_key, title, product_id, quantity, file_path, file_name, file_mime, file_bytes, file_w, file_h,
                     rec_first, rec_last, rec_company, rec_address1, rec_address2, rec_postcode, rec_city, rec_state, rec_country, rec_email, rec_phone,
                     notes, status, test_mode, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?, ?, ?)')
        ->execute([
            bin2hex(random_bytes(8)), $clean['title'], $clean['product_id'], $clean['quantity'],
            $file['path'], $file['name'], $file['mime'], $file['bytes'], $file['width'], $file['height'],
            $clean['rec_first'], $clean['rec_last'], $clean['rec_company'], $clean['rec_address1'], $clean['rec_address2'],
            $clean['rec_postcode'], $clean['rec_city'], $clean['rec_state'], $clean['rec_country'], $clean['rec_email'], $clean['rec_phone'],
            $clean['notes'], pr_is_test() ? 1 : 0, pr_now(), pr_now(),
        ]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE pr_jobs SET ref = ? WHERE id = ?')->execute([sprintf('PR-%04d', $id), $id]);
    pr_log($id, 'Commande créée', $clean['title'] . ' — ' . $clean['quantity'] . ' ex.');
    return $id;
}

function pr_job_touch(int $id, array $fields): void
{
    $fields['updated_at'] = pr_now();
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
    db()->prepare("UPDATE pr_jobs SET $sets WHERE id = ?")->execute(array_merge(array_values($fields), [$id]));
}

/** Une commande déjà passée chez un fournisseur ne se re-devise plus ni ne se re-commande. */
function pr_job_is_locked(array $job): bool
{
    return in_array($job['status'], ['ordering', 'ordered', 'in_production', 'shipped', 'delivered'], true);
}

function pr_quotes_for_job(int $jobId): array
{
    $st = db()->prepare('SELECT q.*, s.name AS supplier_name, s.adapter AS supplier_adapter, s.kind AS supplier_kind
                         FROM pr_quotes q JOIN pr_suppliers s ON s.id = q.supplier_id
                         WHERE q.job_id = ?
                         ORDER BY (q.total_cents IS NULL), q.total_cents, s.name');
    $st->execute([$jobId]);
    return $st->fetchAll();
}

function pr_quote_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM pr_quotes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Demande un devis à chaque fournisseur connecté (par API). Remplace les devis
 * automatiques précédents ; conserve ceux saisis à la main.
 * Retourne ['ok' => bool, 'error' => string, 'offers' => nb d'offres, 'failures' => nb de fournisseurs en échec].
 */
function pr_job_quote_all(int $jobId): array
{
    $job = pr_job_get($jobId);
    if (!$job) return ['ok' => false, 'error' => 'Commande introuvable.', 'offers' => 0, 'failures' => 0];
    if (pr_job_is_locked($job) || $job['status'] === 'cancelled') {
        return ['ok' => false, 'error' => 'Cette commande est déjà passée ou annulée : plus de nouveau devis.', 'offers' => 0, 'failures' => 0];
    }
    $test = pr_is_test();
    db()->prepare('DELETE FROM pr_quotes WHERE job_id = ? AND manual = 0')->execute([$jobId]);

    $offers = 0;
    $failures = 0;
    $ins = db()->prepare('INSERT INTO pr_quotes (job_id, supplier_id, option_key, label, items_cents, shipping_cents, total_cents, currency, days_min, days_max, manual, error, note, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)');
    $errRow = function (array $sup, string $msg) use ($ins, $jobId, &$failures) {
        $failures++;
        $ins->execute([$jobId, $sup['id'], '', '', null, null, null, 'EUR', null, null, $msg, '', pr_now()]);
    };
    foreach (pr_suppliers_list(true) as $sup) {
        $prov = $sup['adapter'] !== '' ? pr_provider($sup['adapter']) : null;
        if (!$prov) continue;
        if (!$prov->isConfigured($test)) {
            $errRow($sup, 'Clé API ' . ($test ? '(test) ' : '(live) ') . 'non renseignée — voir l\'onglet Fournisseurs.');
            continue;
        }
        $map = pr_map_get((int) $job['product_id'], (int) $sup['id']);
        if (!$map) {
            $errRow($sup, 'Aucune référence produit chez ce fournisseur — à renseigner dans « Catalogue & références ».');
            continue;
        }
        try {
            $res = $prov->quote($job, $map['sku'], pr_map_attrs($map), $test);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => 'Erreur inattendue : ' . $e->getMessage(), 'options' => []];
        }
        if (!$res['ok']) {
            $errRow($sup, $res['error']);
            continue;
        }
        foreach ($res['options'] as $o) {
            $ins->execute([$jobId, $sup['id'], $o['key'], $o['label'], $o['items_cents'], $o['shipping_cents'], $o['total_cents'], $o['currency'], $o['days_min'], $o['days_max'], '', $o['note'] ?? '', pr_now()]);
            $offers++;
        }
    }
    if ($job['status'] === 'draft' && $offers > 0) pr_job_touch($jobId, ['status' => 'quoted']);
    pr_log($jobId, 'Devis demandés (' . ($test ? 'mode test' : 'mode live') . ')', $offers . ' offre(s), ' . $failures . ' fournisseur(s) sans réponse');
    return ['ok' => true, 'error' => '', 'offers' => $offers, 'failures' => $failures];
}

/**
 * Passe la commande chez le fournisseur de l'offre choisie.
 * Garde-fous : commande non déjà passée, offre valide, clé configurée, plafond
 * de montant, fichier joignable (obligatoire en live), anti-double-clic atomique.
 * Retourne ['ok' => bool, 'error' => string].
 */
function pr_job_order(int $jobId, int $quoteId): array
{
    $job = pr_job_get($jobId);
    if (!$job) return ['ok' => false, 'error' => 'Commande introuvable.'];
    if (!in_array($job['status'], ['draft', 'quoted', 'quote_requested', 'failed'], true) || $job['provider_order_id'] !== '') {
        return ['ok' => false, 'error' => 'Cette commande est déjà passée (ou annulée) : elle ne peut pas être envoyée une seconde fois.'];
    }
    $quote = pr_quote_get($quoteId);
    if (!$quote || (int) $quote['job_id'] !== $jobId || $quote['total_cents'] === null || (int) $quote['manual'] === 1 || $quote['error'] !== '') {
        return ['ok' => false, 'error' => 'Offre invalide — relancez la comparaison.'];
    }
    $sup = pr_supplier_get((int) $quote['supplier_id']);
    $prov = $sup && $sup['adapter'] !== '' ? pr_provider($sup['adapter']) : null;
    if (!$sup || !$prov) return ['ok' => false, 'error' => 'Ce fournisseur n\'a pas de connecteur API.'];

    $test = pr_is_test();
    if (!$prov->isConfigured($test)) return ['ok' => false, 'error' => 'Clé API ' . ($test ? 'de test' : 'live') . ' de ' . $sup['name'] . ' non renseignée.'];
    $max = pr_max_order_cents();
    if ((int) $quote['total_cents'] > $max) {
        return ['ok' => false, 'error' => 'Montant ' . pr_eur((int) $quote['total_cents']) . ' au-dessus du plafond de sécurité (' . pr_eur($max) . '). Relevez le plafond dans le tableau de bord si c\'est voulu.'];
    }
    if ($job['file_path'] === '' || !is_file(dirname(__DIR__) . '/' . $job['file_path'])) {
        return ['ok' => false, 'error' => 'Le fichier à imprimer est introuvable sur le serveur.'];
    }
    if (!$test && !pr_site_is_public()) {
        return ['ok' => false, 'error' => 'SITE_URL n\'est pas une adresse publique en https : le fournisseur ne pourrait pas télécharger votre fichier. Passez par le site en ligne, ou restez en mode test.'];
    }
    $map = pr_map_get((int) $job['product_id'], (int) $sup['id']);
    if (!$map) return ['ok' => false, 'error' => 'Référence produit manquante chez ' . $sup['name'] . '.'];
    if ($prov->quoteExpired($quote)) {
        return ['ok' => false, 'error' => 'Ce devis ' . $sup['name'] . ' a expiré : relancez « Comparer les fournisseurs » pour obtenir un prix à jour.'];
    }

    // Verrou atomique : un seul envoi à la fois, même en cas de double clic.
    $lock = db()->prepare("UPDATE pr_jobs SET status = 'ordering', updated_at = ? WHERE id = ? AND status IN ('draft','quoted','quote_requested','failed') AND provider_order_id = ''");
    $lock->execute([pr_now(), $jobId]);
    if ($lock->rowCount() !== 1) return ['ok' => false, 'error' => 'Une commande est déjà en cours d\'envoi pour ce dossier.'];

    try {
        $res = $prov->order($job, $map['sku'], pr_map_attrs($map), $quote, $test);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'error' => 'Erreur inattendue : ' . $e->getMessage()];
    }
    if (!$res['ok']) {
        pr_job_touch($jobId, ['status' => 'failed', 'error' => $res['error']]);
        pr_log($jobId, 'Échec de l\'envoi à ' . $sup['name'], $res['error']);
        return ['ok' => false, 'error' => $res['error']];
    }
    pr_job_touch($jobId, [
        'status' => $res['status'], 'supplier_id' => $sup['id'], 'quote_id' => $quoteId,
        'option_key' => $quote['option_key'], 'option_label' => $quote['label'],
        'estimate_cents' => (int) $quote['total_cents'], 'charged_cents' => $res['charged_cents'], 'currency' => $quote['currency'],
        'provider_order_id' => $res['provider_order_id'], 'provider_status' => $res['raw_status'], 'channel' => 'api',
        'tracking_url' => $res['tracking_url'], 'tracking_code' => $res['tracking_code'],
        'test_mode' => $test ? 1 : 0, 'error' => '', 'ordered_at' => pr_now(),
    ]);
    pr_log($jobId, 'Commande envoyée à ' . $sup['name'] . ($test ? ' (MODE TEST)' : ''), 'n° ' . $res['provider_order_id'] . ' — ' . $quote['label'] . ' — ' . pr_eur((int) $quote['total_cents'], $quote['currency']));
    return ['ok' => true, 'error' => ''];
}

/** Rafraîchit statut et suivi colis depuis l'API du fournisseur. */
function pr_job_sync(int $jobId): array
{
    $job = pr_job_get($jobId);
    if (!$job || $job['provider_order_id'] === '' || $job['channel'] !== 'api') return ['ok' => false, 'error' => 'Rien à synchroniser pour cette commande.'];
    $sup = $job['supplier_id'] ? pr_supplier_get((int) $job['supplier_id']) : null;
    $prov = $sup && $sup['adapter'] !== '' ? pr_provider($sup['adapter']) : null;
    if (!$prov) return ['ok' => false, 'error' => 'Fournisseur sans connecteur API.'];
    $test = (bool) $job['test_mode'];
    if (!$prov->isConfigured($test)) return ['ok' => false, 'error' => 'Clé API non renseignée.'];

    $res = $prov->status($job['provider_order_id'], $test);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];
    $changes = [];
    if ($res['status'] !== $job['status']) {
        $changes['status'] = $res['status'];
        pr_log($jobId, 'Statut : ' . (pr_status_labels()[$res['status']][0] ?? $res['status']), 'Fournisseur : ' . $res['raw_status']);
    }
    if ($res['raw_status'] !== $job['provider_status']) $changes['provider_status'] = $res['raw_status'];
    if ($res['tracking_url'] !== '' && $res['tracking_url'] !== $job['tracking_url']) $changes['tracking_url'] = $res['tracking_url'];
    if ($res['tracking_code'] !== '' && $res['tracking_code'] !== $job['tracking_code']) $changes['tracking_code'] = $res['tracking_code'];
    if ($res['charged_cents'] !== null && (int) $res['charged_cents'] !== (int) $job['charged_cents']) $changes['charged_cents'] = $res['charged_cents'];
    if ($changes) pr_job_touch($jobId, $changes);
    return ['ok' => true, 'error' => ''];
}

/** Annule une commande (chez le fournisseur si elle est partie par API). */
function pr_job_cancel(int $jobId): array
{
    $job = pr_job_get($jobId);
    if (!$job) return ['ok' => false, 'error' => 'Commande introuvable.'];
    if (in_array($job['status'], ['cancelled', 'delivered'], true)) return ['ok' => false, 'error' => 'Cette commande ne peut plus être annulée.'];
    if ($job['status'] === 'ordering') return ['ok' => false, 'error' => 'Envoi en cours — réessayez dans un instant.'];

    if ($job['channel'] === 'api' && $job['provider_order_id'] !== '') {
        $sup = $job['supplier_id'] ? pr_supplier_get((int) $job['supplier_id']) : null;
        $prov = $sup && $sup['adapter'] !== '' ? pr_provider($sup['adapter']) : null;
        if (!$prov) return ['ok' => false, 'error' => 'Fournisseur sans connecteur API.'];
        $res = $prov->cancel($job['provider_order_id'], (bool) $job['test_mode']);
        if (!$res['ok']) {
            pr_log($jobId, 'Annulation refusée', $res['error']);
            return $res;
        }
    }
    pr_job_touch($jobId, ['status' => 'cancelled']);
    pr_log($jobId, 'Commande annulée');
    return ['ok' => true, 'error' => ''];
}

/* ---------- fournisseurs sans API ---------- */

/** Texte du brief à envoyer à un imprimeur pour obtenir un devis. */
function pr_job_brief(array $job, array $product): string
{
    $lines = [
        'Bonjour,',
        '',
        'Pourriez-vous me faire un devis pour l\'impression suivante ?',
        '',
        '• Produit : ' . $product['label'],
        '• Quantité : ' . (int) $job['quantity'],
    ];
    if ($product['width_mm'] && $product['height_mm']) $lines[] = '• Format fini : ' . $product['width_mm'] . ' × ' . $product['height_mm'] . ' mm';
    if ($product['description'] !== '') $lines[] = '• Précisions : ' . $product['description'];
    if ($job['notes'] !== '') $lines[] = '• Remarques : ' . $job['notes'];
    $lines[] = '• Fichier : ' . ($job['file_name'] !== '' ? $job['file_name'] : '(à joindre)');
    $url = pr_job_file_url($job);
    if ($url !== '') $lines[] = '  Téléchargement : ' . $url;
    $dest = trim($job['rec_first'] . ' ' . $job['rec_last'] . ($job['rec_company'] !== '' ? ' (' . $job['rec_company'] . ')' : ''));
    $lines[] = '• Livraison : ' . $dest . ', ' . $job['rec_address1'] . ($job['rec_address2'] !== '' ? ', ' . $job['rec_address2'] : '') . ', ' . $job['rec_postcode'] . ' ' . $job['rec_city'] . ' (' . $job['rec_country'] . ')';
    $lines[] = '';
    $lines[] = 'Merci de m\'indiquer le prix TTC (fabrication + livraison) et le délai.';
    $lines[] = '';
    $lines[] = 'Cordialement,';
    $lines[] = $job['rec_first'] . ' ' . $job['rec_last'];
    return implode("\n", $lines);
}

/** Note qu'un devis a été demandé à un imprimeur hors API. */
function pr_job_mark_quote_requested(int $jobId, int $supplierId): ?string
{
    $job = pr_job_get($jobId);
    $sup = pr_supplier_get($supplierId);
    if (!$job || !$sup) return 'Commande ou fournisseur introuvable.';
    if (pr_job_is_locked($job) || $job['status'] === 'cancelled') return 'Cette commande est déjà passée ou annulée.';
    pr_log($jobId, 'Devis demandé à ' . $sup['name'], 'par e-mail / téléphone');
    if (in_array($job['status'], ['draft', 'quoted'], true)) pr_job_touch($jobId, ['status' => 'quote_requested']);
    return null;
}

/** Enregistre le prix reçu d'un fournisseur (devis par e-mail, prix vu sur leur site…). */
function pr_manual_quote_add(int $jobId, int $supplierId, string $totalEuros, string $note = '', ?int $days = null): ?string
{
    $job = pr_job_get($jobId);
    $sup = pr_supplier_get($supplierId);
    if (!$job || !$sup) return 'Commande ou fournisseur introuvable.';
    if (pr_job_is_locked($job) || $job['status'] === 'cancelled') return 'Cette commande est déjà passée ou annulée.';
    $totalEuros = str_replace([' ', ','], ['', '.'], trim($totalEuros));
    if (!is_numeric($totalEuros) || (float) $totalEuros <= 0 || (float) $totalEuros > 100000) return 'Montant invalide.';
    $cents = pr_cents($totalEuros);
    db()->prepare('INSERT INTO pr_quotes (job_id, supplier_id, option_key, label, total_cents, currency, days_max, manual, note, created_at)
                   VALUES (?, ?, \'manual\', \'Devis reçu\', ?, \'EUR\', ?, 1, ?, ?)')
        ->execute([$jobId, $supplierId, $cents, $days && $days > 0 ? $days : null, mb_substr(trim($note), 0, 300), pr_now()]);
    pr_log($jobId, 'Prix saisi pour ' . $sup['name'], pr_eur($cents));
    if (in_array($job['status'], ['draft', 'quote_requested'], true)) pr_job_touch($jobId, ['status' => 'quoted']);
    return null;
}

/** Marque une commande comme passée hors API, avec un devis saisi à la main. */
function pr_job_mark_ordered_manually(int $jobId, int $quoteId, string $reference = ''): ?string
{
    $job = pr_job_get($jobId);
    $quote = pr_quote_get($quoteId);
    if (!$job || !$quote || (int) $quote['job_id'] !== $jobId || (int) $quote['manual'] !== 1) return 'Devis introuvable.';
    if (!in_array($job['status'], ['draft', 'quoted', 'quote_requested', 'failed'], true)) return 'Cette commande est déjà passée ou annulée.';
    $sup = pr_supplier_get((int) $quote['supplier_id']);
    pr_job_touch($jobId, [
        'status' => 'ordered', 'supplier_id' => $quote['supplier_id'], 'quote_id' => $quoteId, 'option_label' => 'Commande passée hors API',
        'estimate_cents' => (int) $quote['total_cents'], 'currency' => $quote['currency'], 'channel' => 'manual',
        'provider_order_id' => mb_substr(trim($reference), 0, 80), 'test_mode' => 0, 'error' => '', 'ordered_at' => pr_now(),
    ]);
    pr_log($jobId, 'Commande passée chez ' . ($sup['name'] ?? '?') . ' (hors API)', pr_eur((int) $quote['total_cents']) . ($reference !== '' ? ' — réf. ' . $reference : ''));
    return null;
}

/** Suivi à la main d'une commande passée hors API. */
function pr_job_set_status_manual(int $jobId, string $status, string $trackingUrl = ''): ?string
{
    $job = pr_job_get($jobId);
    if (!$job || $job['channel'] !== 'manual') return 'Seules les commandes passées hors API se suivent à la main.';
    if (!in_array($status, ['ordered', 'in_production', 'shipped', 'delivered'], true)) return 'Statut invalide.';
    $trackingUrl = trim($trackingUrl);
    if ($trackingUrl !== '' && !preg_match('#^https?://#i', $trackingUrl)) return 'Le lien de suivi doit commencer par http:// ou https://.';
    $fields = ['status' => $status];
    if ($trackingUrl !== '') $fields['tracking_url'] = mb_substr($trackingUrl, 0, 400);
    pr_job_touch($jobId, $fields);
    pr_log($jobId, 'Statut mis à jour à la main', pr_status_labels()[$status][0]);
    return null;
}

/** Une commande se supprime tant qu'elle n'est pas en cours chez un fournisseur (jamais envoyée, échouée ou annulée). */
function pr_job_can_delete(array $job): bool
{
    if (!in_array($job['status'], ['draft', 'quoted', 'quote_requested', 'cancelled', 'failed'], true)) return false;
    return !($job['channel'] === 'api' && $job['status'] !== 'cancelled');
}

/**
 * Débloque une commande restée en « Envoi en cours » (PHP interrompu pendant l'appel
 * au fournisseur). On ne sait pas si le fournisseur l'a reçue : on la passe en échec
 * avec un message qui impose de vérifier chez lui avant de recommander (pas de doublon).
 * Retourne true si la commande a été débloquée.
 */
function pr_job_recover_stuck(int $jobId, int $maxAgeSeconds = 300): bool
{
    $job = pr_job_get($jobId);
    if (!$job || $job['status'] !== 'ordering') return false;
    $age = time() - (int) strtotime($job['updated_at'] . ' UTC');
    if ($age < $maxAgeSeconds) return false;
    $msg = 'L\'envoi a été interrompu : le fournisseur a peut-être reçu la commande. Vérifiez dans son tableau de bord (référence ' . $job['ref'] . ') avant de recommander, pour éviter un doublon.';
    pr_job_touch($jobId, ['status' => 'failed', 'error' => $msg]);
    pr_log($jobId, 'Envoi interrompu', $msg);
    return true;
}

/** Supprime une commande jamais passée chez un fournisseur (et son fichier). */
function pr_job_delete(int $jobId): ?string
{
    $job = pr_job_get($jobId);
    if (!$job) return 'Commande introuvable.';
    if (!pr_job_can_delete($job)) {
        return 'Seules les commandes jamais envoyées (ou annulées) peuvent être supprimées.';
    }
    if ($job['file_path'] !== '' && str_starts_with($job['file_path'], PR_UPLOAD_DIR . '/')) {
        @unlink(dirname(__DIR__) . '/' . $job['file_path']);
    }
    db()->prepare('DELETE FROM pr_jobs WHERE id = ?')->execute([$jobId]);
    return null;
}

/** Chiffres du tableau de bord. */
function pr_stats(): array
{
    $row = db()->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status IN ('draft','quoted','quote_requested') THEN 1 ELSE 0 END) AS open,
        SUM(CASE WHEN status IN ('ordered','in_production') THEN 1 ELSE 0 END) AS running,
        SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) AS shipped,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
        COALESCE(SUM(CASE WHEN status IN ('ordered','in_production','shipped','delivered') AND test_mode = 0 THEN COALESCE(charged_cents, estimate_cents) END), 0) AS spent_cents
        FROM pr_jobs")->fetch();
    return array_map('intval', $row);
}
