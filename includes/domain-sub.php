<?php

// Adresse en sous-domaine : bricbrok.com/<boutique>/ devient <nom>.bricbrok.com,
// pour un montant fixe réglé dans le portail (saas.json : subdomain_price_cents).
// Le marchand choisit le nom ; le paiement passe par le compte Stripe de la
// plateforme. Rien n'est activé avant la confirmation du paiement.
//
// État : data/domaines/sub/<boutique>.json (0600), un fichier par boutique, jamais
// envoyé par le déploiement. Les noms actifs servent à tenant_from_host()
// (includes/tenant.php). Prérequis côté hébergement : un DNS « *.<domaine> » vers
// le serveur et un alias d'hébergement générique (avec son certificat).

const DOMAIN_SUB_STATES = [
    'awaiting_payment' => 'En attente de paiement',
    'active' => 'En service',
];

/** Prix par défaut (centimes) tant que le portail n'en a pas enregistré un. */
const DOMAIN_SUB_DEFAULT_PRICE_CENTS = 500;

/** Prix par défaut (centimes) d'un changement de nom, tant que le portail n'en a pas enregistré un. */
const DOMAIN_SUB_DEFAULT_RENAME_PRICE_CENTS = 200;

/** Noms techniques qu'un marchand ne peut jamais prendre. */
const DOMAIN_SUB_RESERVED = [
    'www', 'admin', 'portail', 'api', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'webmail', 'ns', 'ns1', 'ns2',
    'cdn', 'static', 'assets', 'app', 'studio', 'vente', 'test', 'dev', 'staging', 'demo', 'cpanel',
    'autoconfig', 'autodiscover', 'status', 'support', 'blog', 'shop', 'boutique', 'plateforme', 'brocs',
];

function domain_sub_slug_valid(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $slug);
}

function domain_sub_dir(): string
{
    if (defined('DOMAIN_SUB_DATA_DIR')) return rtrim((string) DOMAIN_SUB_DATA_DIR, '/');
    if (defined('DOMAIN_SHOP_DATA_DIR')) return rtrim((string) DOMAIN_SHOP_DATA_DIR, '/') . '/sub';
    return dirname(__DIR__) . '/data/domaines/sub';
}

function domain_sub_file(string $slug): string
{
    if (!domain_sub_slug_valid($slug)) throw new InvalidArgumentException('Identifiant de boutique invalide.');
    return domain_sub_dir() . '/' . $slug . '.json';
}

function domain_sub_state_label(string $state): string
{
    return DOMAIN_SUB_STATES[$state] ?? 'État inconnu';
}

/** Prix fixe de l'adresse en sous-domaine, en centimes (0 = gratuit). */
function domain_sub_price_cents(): int
{
    if (!function_exists('saas_config')) return DOMAIN_SUB_DEFAULT_PRICE_CENTS;
    $cfg = saas_config();
    return array_key_exists('subdomain_price_cents', $cfg) ? max(0, (int) $cfg['subdomain_price_cents']) : DOMAIN_SUB_DEFAULT_PRICE_CENTS;
}

/** Domaine de la plateforme sans « www » ni sous-domaine de boutique (bricbrok.com). */
function domain_sub_base_host(?string $host = null): string
{
    $host = $host ?? (function_exists('tenant_base_host') ? tenant_base_host() : strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
    return (string) preg_replace('/^www\./', '', strtolower($host));
}

/** Adresse publique d'un nom : https://nom.bricbrok.com (http en local). Sans barre finale. */
function domain_sub_url(string $label, ?string $baseHost = null): string
{
    $base = domain_sub_base_host($baseHost);
    $bare = (string) preg_replace('/:\d+$/', '', $base);
    $local = $bare === 'localhost' || str_ends_with($bare, '.test') || (bool) filter_var($bare, FILTER_VALIDATE_IP);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $local ? ($https ? 'https' : 'http') : 'https';
    return $scheme . '://' . $label . '.' . $base;
}

/** Nom saisi nettoyé : minuscules, sans accent, sans schéma ni domaine, tirets à la place des séparateurs. */
function domain_sub_label_normalize(string $input, string $baseHost = ''): string
{
    $s = mb_strtolower(trim($input));
    $s = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $s);
    $s = (string) preg_replace('#[/?\#].*$#', '', $s);
    $baseHost = domain_sub_base_host($baseHost);
    $bare = (string) preg_replace('/:\d+$/', '', $baseHost);
    $s = (string) preg_replace('/:\d+$/', '', $s);
    if ($bare !== '' && str_ends_with($s, '.' . $bare)) $s = substr($s, 0, -strlen($bare) - 1);
    $s = strtr($s, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ]);
    $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/** Message d'erreur si le nom est inutilisable (forme, longueur, nom technique) ; chaîne vide s'il est correct. */
function domain_sub_label_problem(string $label): string
{
    if ($label === '') return 'Indiquez le nom de votre adresse (lettres, chiffres et tirets).';
    if (strlen($label) < 3) return 'Le nom doit comporter au moins 3 caractères.';
    if (strlen($label) > 40) return 'Le nom ne peut pas dépasser 40 caractères.';
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])$/', $label)) return 'Le nom ne peut contenir que des lettres, des chiffres et des tirets, sans tiret au début ni à la fin.';
    if (str_contains($label, '--')) return 'Évitez deux tirets de suite dans le nom.';
    if (in_array($label, DOMAIN_SUB_RESERVED, true)) return 'Ce nom est réservé par la plateforme : choisissez-en un autre.';
    return '';
}

function domain_sub_load(string $slug): array
{
    $file = domain_sub_file($slug);
    if (!is_file($file)) return [];
    try {
        $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $ex) {
        error_log('domain_sub_load: ' . $ex->getMessage());
        return [];
    }
    return is_array($data) ? $data : [];
}

/** Modifie l'état sous verrou exclusif puis l'écrit de façon atomique ; le callback rend le nouvel état. */
function domain_sub_update(string $slug, callable $update): array
{
    $file = domain_sub_file($slug);
    $dir = dirname($file);
    if (is_link($dir)) throw new RuntimeException('Le dossier des adresses ne peut pas être un lien symbolique.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de préparer le dossier des adresses.');
    }
    $lock = fopen($file . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Impossible de verrouiller cette adresse.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Impossible de verrouiller cette adresse.');
        $data = $update(domain_sub_load($slug));
        $tmp = tempnam($dir, '.sub-');
        if ($tmp === false) throw new RuntimeException('Impossible de préparer l’enregistrement de l’adresse.');
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($tmp, $json, LOCK_EX) === false || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
                throw new RuntimeException('Impossible d’enregistrer cette adresse.');
            }
        } catch (Throwable $ex) {
            if (is_file($tmp)) @unlink($tmp);
            throw $ex;
        }
        @chmod($file, 0600);
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Toutes les adresses enregistrées (une entrée par boutique), pour le portail. */
function domain_sub_list(): array
{
    $list = [];
    foreach (glob(domain_sub_dir() . '/*.json') ?: [] as $file) {
        $slug = basename($file, '.json');
        if (!domain_sub_slug_valid($slug)) continue;
        $record = domain_sub_load($slug);
        if (!$record || (string) ($record['label'] ?? '') === '') continue;
        $list[] = $record + ['slug' => $slug];
    }
    usort($list, static fn (array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));
    return $list;
}

/** Noms en service : nom → boutique. Consulté à chaque requête par la résolution des commerces. */
function domain_sub_hosts(): array
{
    $hosts = [];
    foreach (domain_sub_list() as $record) {
        if (($record['state'] ?? '') !== 'active') continue;
        $label = (string) ($record['label'] ?? '');
        if ($label !== '' && domain_sub_label_problem($label) === '') $hosts[$label] = (string) $record['slug'];
    }
    return $hosts;
}

function domain_sub_active_label(string $slug): string
{
    if (!domain_sub_slug_valid($slug)) return '';
    $record = domain_sub_load($slug);
    return ($record['state'] ?? '') === 'active' ? (string) ($record['label'] ?? '') : '';
}

/** Un paiement ouvert réserve le nom pendant 24 h (durée de vie d'une session Stripe). */
const DOMAIN_SUB_HOLD_SECONDS = 86400;

/** Raison pour laquelle le nom est pris (autre boutique, dossier du site…), ou chaîne vide s'il est libre pour $slug. */
function domain_sub_conflict(string $label, string $slug): string
{
    if (is_dir(dirname(__DIR__) . '/' . $label) || is_file(dirname(__DIR__) . '/' . $label) || is_file(dirname(__DIR__) . '/' . $label . '.php')) {
        return 'Ce nom est réservé par la plateforme : choisissez-en un autre.';
    }
    if ($label !== $slug && is_file(dirname(__DIR__) . '/tenants/' . $label . '/tenant.php')) {
        return 'Ce nom est déjà utilisé par une autre boutique.';
    }
    foreach (domain_sub_list() as $record) {
        if ((string) $record['slug'] === $slug) continue;
        $held = ($record['state'] ?? '') === 'active'
            || time() - (int) ($record['created_at'] ?? 0) < DOMAIN_SUB_HOLD_SECONDS;
        if ((string) ($record['label'] ?? '') === $label && $held) return 'Ce nom est déjà pris : choisissez-en un autre.';
        // Changement de nom en cours de paiement chez une autre boutique : le nouveau nom est réservé.
        $rename = (array) ($record['rename'] ?? []);
        if ((string) ($rename['label'] ?? '') === $label && time() - (int) ($rename['created_at'] ?? 0) < DOMAIN_SUB_HOLD_SECONDS) {
            return 'Ce nom est déjà pris : choisissez-en un autre.';
        }
    }
    return '';
}

/** Prix fixe d'un changement de nom d'une adresse déjà payée, en centimes (0 = gratuit). */
function domain_sub_rename_price_cents(): int
{
    if (!function_exists('saas_config')) return DOMAIN_SUB_DEFAULT_RENAME_PRICE_CENTS;
    $cfg = saas_config();
    return array_key_exists('subdomain_rename_price_cents', $cfg) ? max(0, (int) $cfg['subdomain_rename_price_cents']) : DOMAIN_SUB_DEFAULT_RENAME_PRICE_CENTS;
}

/** Changement de nom en attente de paiement (adresse en service) : ['label', 'amount_cents', 'stripe_url', …] ou []. */
function domain_sub_pending_rename(string $slug): array
{
    $record = domain_sub_load($slug);
    $rename = (array) ($record['rename'] ?? []);
    if (($record['state'] ?? '') !== 'active' || (string) ($rename['label'] ?? '') === ''
        || time() - (int) ($rename['created_at'] ?? 0) >= DOMAIN_SUB_HOLD_SECONDS) {
        return [];
    }
    return $rename;
}

/**
 * Nom prêt à payer : normalisé, valide, libre. Lève InvalidArgumentException avec
 * un message lisible sinon. Rend le nom nettoyé.
 */
function domain_sub_check(string $slug, string $input): string
{
    $label = domain_sub_label_normalize($input);
    if (($problem = domain_sub_label_problem($label)) !== '') throw new InvalidArgumentException($problem);
    if (($why = domain_sub_conflict($label, $slug)) !== '') throw new InvalidArgumentException($why);
    return $label;
}

/** Active le nom d'une boutique (paiement confirmé, ou adresse gratuite) et prévient le marchand. */
function domain_sub_activate(string $slug, array $extra = []): array
{
    $data = domain_sub_update($slug, static function (array $d) use ($extra): array {
        $d = $extra + $d;
        $d['state'] = 'active';
        $d['activated_at'] = time();
        return $d;
    });
    $label = (string) ($data['label'] ?? '');
    if (function_exists('saas_event')) {
        saas_event('subdomain_activated', [
            'slug' => $slug,
            'email' => (string) ($data['email'] ?? ''),
            'amount' => (int) ($data['amount_cents'] ?? 0),
            'note' => $label . ' — ' . domain_sub_url($label),
            'actor' => 'stripe',
        ]);
    }
    $email = (string) ($data['email'] ?? '');
    if (function_exists('saas_mail') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        saas_mail($email, 'Votre adresse ' . $label . ' est en service',
            "Bonjour,\n\nVotre boutique répond maintenant à l’adresse " . domain_sub_url($label) . "/ .\n"
            . "L’adresse historique de la plateforme continue de fonctionner.\n");
    }
    return $data;
}

/**
 * Ouvre le paiement Stripe de l'adresse (ou l'active tout de suite si le prix est
 * nul). $returnUrl : adresse absolue où Stripe ramène le payeur.
 * Rend ['url' => lien Stripe] ou ['free' => true].
 */
function domain_sub_checkout(string $slug, string $input, string $email, string $returnUrl): array
{
    if (domain_sub_active_label($slug) !== '') {
        throw new RuntimeException('Une adresse en sous-domaine est déjà en service pour cette boutique.');
    }
    $label = domain_sub_check($slug, $input);
    $amount = domain_sub_price_cents();
    if (!preg_match('#^https?://#i', $returnUrl)) throw new InvalidArgumentException('Adresse de retour du paiement invalide.');
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

    // Un paiement resté ouvert pour un autre nom est fermé : un seul à la fois.
    domain_sub_expire_pending($slug);

    if ($amount === 0) {
        domain_sub_update($slug, static fn (array $d): array => [
            'label' => $label, 'state' => 'awaiting_payment', 'amount_cents' => 0, 'email' => $email, 'created_at' => time(),
        ]);
        domain_sub_activate($slug, ['free' => true]);
        return ['free' => true, 'label' => $label];
    }

    if (!function_exists('saas_stripe_configured') || !saas_stripe_configured()) {
        throw new RuntimeException('Le paiement en ligne n’est pas configuré sur ce serveur. Contactez l’exploitant de la plateforme.');
    }
    if ($amount < 50 || $amount > 1000000) throw new RuntimeException('Le prix de l’adresse est invalide : contactez l’exploitant de la plateforme.');

    $params = [
        'mode' => 'payment',
        'locale' => 'fr',
        'client_reference_id' => 'sousdomaine:' . $slug,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => $amount,
                'product_data' => [
                    'name' => 'Adresse ' . $label . '.' . domain_sub_base_host(),
                    'description' => 'Adresse en sous-domaine pour votre boutique',
                ],
            ],
        ]],
        'metadata' => ['type' => 'shop_subdomain', 'shop' => $slug, 'label' => $label],
        'success_url' => $returnUrl . '?s={CHECKOUT_SESSION_ID}',
        'cancel_url' => $returnUrl,
    ];
    if ($email !== '') $params['customer_email'] = $email;
    $session = saas_stripe_request('POST', '/checkout/sessions', $params);
    $sessionId = (string) ($session['id'] ?? '');
    $sessionUrl = (string) ($session['url'] ?? '');
    if ($sessionId === '' || $sessionUrl === '') throw new RuntimeException('Stripe n’a pas renvoyé de lien de paiement exploitable.');

    domain_sub_update($slug, static fn (array $d): array => [
        'label' => $label,
        'state' => 'awaiting_payment',
        'amount_cents' => $amount,
        'email' => $email,
        'stripe_session' => $sessionId,
        'stripe_url' => $sessionUrl,
        'created_at' => time(),
    ]);
    return ['url' => $sessionUrl, 'session' => $sessionId, 'label' => $label];
}

/** Ferme le paiement en attente d'une boutique (session Stripe expirée, fichier effacé) ; une adresse en service n'est jamais touchée. */
function domain_sub_expire_pending(string $slug): void
{
    $record = domain_sub_load($slug);
    if (!$record || ($record['state'] ?? '') !== 'awaiting_payment') return;
    $sessionId = (string) ($record['stripe_session'] ?? '');
    if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) && function_exists('saas_stripe_request')) {
        try {
            saas_stripe_request('POST', '/checkout/sessions/' . rawurlencode($sessionId) . '/expire');
        } catch (Throwable $ex) {
            error_log('domain_sub_expire_pending stripe: ' . $ex->getMessage());
        }
    }
    domain_sub_remove($slug);
}

/**
 * Annule le paiement en attente : celui d'une première adresse, ou celui d'un
 * changement de nom (l'adresse en service reste alors inchangée). Refuse d'annuler
 * une adresse déjà payée et en service.
 */
function domain_sub_cancel(string $slug): void
{
    if (domain_sub_active_label($slug) !== '') {
        if (!domain_sub_pending_rename($slug)) {
            throw new RuntimeException('Cette adresse est déjà payée et en service : elle ne peut plus être annulée ici.');
        }
        domain_sub_expire_rename($slug);
        return;
    }
    domain_sub_expire_pending($slug);
}

/** Ferme le paiement d'un changement de nom en attente (session Stripe expirée) sans toucher à l'adresse en service. */
function domain_sub_expire_rename(string $slug): void
{
    $rename = (array) (domain_sub_load($slug)['rename'] ?? []);
    if (!$rename) return;
    $sessionId = (string) ($rename['stripe_session'] ?? '');
    if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) && function_exists('saas_stripe_request')) {
        try {
            saas_stripe_request('POST', '/checkout/sessions/' . rawurlencode($sessionId) . '/expire');
        } catch (Throwable $ex) {
            error_log('domain_sub_expire_rename stripe: ' . $ex->getMessage());
        }
    }
    domain_sub_update($slug, static function (array $d): array {
        unset($d['rename']);
        return $d;
    });
}

/** Remplace le nom de l'adresse en service par $label et garde la trace du changement. */
function domain_sub_swap_label(string $slug, string $label, int $amountCents, string $sessionId = ''): array
{
    $data = domain_sub_update($slug, static function (array $d) use ($label, $amountCents, $sessionId): array {
        $d['renames'] = array_values(array_merge((array) ($d['renames'] ?? []), [[
            'from' => (string) ($d['label'] ?? ''),
            'to' => $label,
            'amount_cents' => $amountCents,
            'stripe_session' => $sessionId,
            'at' => time(),
        ]]));
        $d['label'] = $label;
        unset($d['rename']);
        return $d;
    });
    if (function_exists('saas_event')) {
        $last = (array) end($data['renames']);
        saas_event('subdomain_activated', [
            'slug' => $slug,
            'email' => (string) ($data['email'] ?? ''),
            'amount' => $amountCents,
            'note' => 'Changement de nom : ' . (string) ($last['from'] ?? '') . ' → ' . $label . ' — ' . domain_sub_url($label),
            'actor' => 'stripe',
        ]);
    }
    $email = (string) ($data['email'] ?? '');
    if (function_exists('saas_mail') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        saas_mail($email, 'Votre adresse est devenue ' . $label,
            "Bonjour,\n\nVotre boutique répond maintenant à l’adresse " . domain_sub_url($label) . "/ .\n"
            . "L’ancien nom en sous-domaine ne fonctionne plus.\n");
    }
    return $data;
}

/**
 * Changement de nom d'une adresse déjà en service, au prix fixe de changement
 * (portail). L'ancien nom reste servi jusqu'à la confirmation du paiement.
 * Rend ['url' => lien Stripe] ou ['free' => true, 'label' => …].
 */
function domain_sub_rename(string $slug, string $input, string $email, string $returnUrl): array
{
    $current = domain_sub_active_label($slug);
    if ($current === '') throw new RuntimeException('Aucune adresse en service à renommer : choisissez d’abord un nom.');
    $label = domain_sub_check($slug, $input);
    if ($label === $current) throw new InvalidArgumentException('C’est déjà le nom de votre adresse : saisissez-en un autre.');
    if (!preg_match('#^https?://#i', $returnUrl)) throw new InvalidArgumentException('Adresse de retour du paiement invalide.');
    $amount = domain_sub_rename_price_cents();
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

    domain_sub_expire_rename($slug); // un seul changement en attente à la fois

    if ($amount === 0) {
        domain_sub_swap_label($slug, $label, 0);
        return ['free' => true, 'label' => $label];
    }
    if (!function_exists('saas_stripe_configured') || !saas_stripe_configured()) {
        throw new RuntimeException('Le paiement en ligne n’est pas configuré sur ce serveur. Contactez l’exploitant de la plateforme.');
    }
    if ($amount < 50 || $amount > 1000000) throw new RuntimeException('Le prix du changement de nom est invalide : contactez l’exploitant de la plateforme.');

    $params = [
        'mode' => 'payment',
        'locale' => 'fr',
        'client_reference_id' => 'sousdomaine:' . $slug,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => $amount,
                'product_data' => [
                    'name' => 'Changement d’adresse : ' . $label . '.' . domain_sub_base_host(),
                    'description' => 'Nouveau nom pour votre adresse en sous-domaine',
                ],
            ],
        ]],
        'metadata' => ['type' => 'shop_subdomain', 'shop' => $slug, 'label' => $label, 'rename' => '1'],
        'success_url' => $returnUrl . '?s={CHECKOUT_SESSION_ID}',
        'cancel_url' => $returnUrl,
    ];
    if ($email !== '') $params['customer_email'] = $email;
    $session = saas_stripe_request('POST', '/checkout/sessions', $params);
    $sessionId = (string) ($session['id'] ?? '');
    $sessionUrl = (string) ($session['url'] ?? '');
    if ($sessionId === '' || $sessionUrl === '') throw new RuntimeException('Stripe n’a pas renvoyé de lien de paiement exploitable.');

    domain_sub_update($slug, static function (array $d) use ($label, $amount, $sessionId, $sessionUrl): array {
        $d['rename'] = [
            'label' => $label,
            'amount_cents' => $amount,
            'stripe_session' => $sessionId,
            'stripe_url' => $sessionUrl,
            'created_at' => time(),
        ];
        return $d;
    });
    return ['url' => $sessionUrl, 'session' => $sessionId, 'label' => $label];
}

/**
 * Applique la session Stripe payée d'un changement de nom (retour OU webhook), une seule fois.
 * Rend ['state' => renamed|already|unpaid|invalid, …].
 */
function domain_sub_apply_rename(array $session): array
{
    $meta = (array) ($session['metadata'] ?? []);
    $slug = (string) ($meta['shop'] ?? '');
    if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid', 'slug' => $slug];

    $already = false;
    try {
        $data = domain_sub_update($slug, static function (array $d) use ($session, $meta, $slug, &$already): array {
            $sessionId = (string) ($session['id'] ?? '');
            foreach ((array) ($d['renames'] ?? []) as $done) {
                if ($sessionId !== '' && (string) ($done['stripe_session'] ?? '') === $sessionId) {
                    $already = true;
                    return $d;
                }
            }
            $rename = (array) ($d['rename'] ?? []);
            if (($d['state'] ?? '') !== 'active' || !$rename) throw new RuntimeException('Changement de nom introuvable pour cette boutique.');
            if ($sessionId === '' || $sessionId !== (string) ($rename['stripe_session'] ?? '')) {
                throw new RuntimeException('Le paiement ne correspond pas à la session enregistrée pour ce changement de nom.');
            }
            if ((string) ($session['client_reference_id'] ?? '') !== 'sousdomaine:' . $slug) {
                throw new RuntimeException('Le paiement ne référence pas cette boutique.');
            }
            if ((string) ($meta['label'] ?? '') !== (string) ($rename['label'] ?? '')
                || (int) ($session['amount_total'] ?? 0) !== (int) ($rename['amount_cents'] ?? -1)
                || strtolower((string) ($session['currency'] ?? '')) !== 'eur') {
                throw new RuntimeException('Le paiement Stripe ne correspond pas au changement de nom enregistré.');
            }
            return $d;
        });
    } catch (RuntimeException $ex) {
        error_log('domain_sub_apply_rename: ' . $ex->getMessage());
        return ['state' => 'invalid', 'slug' => $slug, 'message' => $ex->getMessage()];
    }
    if ($already) return ['state' => 'already', 'slug' => $slug, 'record' => $data];

    $rename = (array) $data['rename'];
    if (($why = domain_sub_conflict((string) $rename['label'], $slug)) !== '') {
        return ['state' => 'invalid', 'slug' => $slug, 'record' => $data,
            'message' => $why . ' Contactez l’exploitant de la plateforme : le paiement est confirmé.'];
    }
    return ['state' => 'renamed', 'slug' => $slug,
        'record' => domain_sub_swap_label($slug, (string) $rename['label'], (int) $rename['amount_cents'], (string) $session['id'])];
}

/** Retire l'adresse en service d'une boutique (exploitant) : la boutique ne répond plus à ce nom. */
function domain_sub_remove(string $slug): void
{
    $file = domain_sub_file($slug);
    if (is_file($file) && !@unlink($file) && is_file($file)) throw new RuntimeException('Impossible de retirer cette adresse.');
}

/**
 * Applique une session Stripe Checkout payée (page de retour OU webhook).
 * Vérifie le type, la boutique, le nom, le montant et la session attendue, puis
 * active l'adresse une seule fois.
 * Rend ['state' => activated|already|unpaid|invalid, 'slug' => …, 'record' => …, 'message' => …].
 */
function domain_sub_apply_session(array $session): array
{
    $meta = (array) ($session['metadata'] ?? []);
    $slug = (string) ($meta['shop'] ?? '');
    if (($meta['type'] ?? '') !== 'shop_subdomain' || !domain_sub_slug_valid($slug)) return ['state' => 'invalid'];
    if (($meta['rename'] ?? '') === '1') return domain_sub_apply_rename($session);
    if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid', 'slug' => $slug];

    $already = false;
    try {
        $data = domain_sub_update($slug, static function (array $d) use ($session, $meta, $slug, &$already): array {
            if (!$d) throw new RuntimeException('Commande d’adresse introuvable pour cette boutique.');
            $sessionId = (string) ($session['id'] ?? '');
            if ($sessionId === '' || $sessionId !== (string) ($d['stripe_session'] ?? '')) {
                throw new RuntimeException('Le paiement ne correspond pas à la session enregistrée pour cette adresse.');
            }
            if ((string) ($session['client_reference_id'] ?? '') !== 'sousdomaine:' . $slug) {
                throw new RuntimeException('Le paiement ne référence pas cette boutique.');
            }
            if ((string) ($meta['label'] ?? '') !== (string) ($d['label'] ?? '')
                || (int) ($session['amount_total'] ?? 0) !== (int) ($d['amount_cents'] ?? -1)
                || strtolower((string) ($session['currency'] ?? '')) !== 'eur') {
                throw new RuntimeException('Le paiement Stripe ne correspond pas à l’adresse enregistrée.');
            }
            if (($d['state'] ?? '') === 'active') {
                $already = true;
                return $d;
            }
            if (($d['state'] ?? '') !== 'awaiting_payment') throw new RuntimeException('Cette adresse ne peut pas être confirmée dans son état actuel.');
            $d['paid_at'] = time();
            $d['livemode'] = !empty($session['livemode']);
            return $d;
        });
    } catch (RuntimeException $ex) {
        error_log('domain_sub_apply_session: ' . $ex->getMessage());
        return ['state' => 'invalid', 'slug' => $slug, 'message' => $ex->getMessage()];
    }
    if ($already) return ['state' => 'already', 'slug' => $slug, 'record' => $data];

    // Le nom a pu être pris par une autre boutique pendant le paiement.
    if (($why = domain_sub_conflict((string) $data['label'], $slug)) !== '') {
        return ['state' => 'invalid', 'slug' => $slug, 'record' => $data,
            'message' => $why . ' Contactez l’exploitant de la plateforme : le paiement est confirmé.'];
    }
    return ['state' => 'activated', 'slug' => $slug, 'record' => domain_sub_activate($slug)];
}
