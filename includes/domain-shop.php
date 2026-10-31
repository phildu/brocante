<?php

// Achat d'un nom de domaine depuis l'administration d'une boutique.
//
// Parallèle au parcours d'inscription (includes/branding-services.php, étape 2) :
// même API OVH et même compte Stripe de la plateforme, mais l'état de la commande
// appartient à la boutique et vit dans data/domaines/<identifiant>.json (jamais
// envoyé par le déploiement). Rien n'est commandé chez OVH avant la confirmation
// du paiement Stripe ; une session payée n'est appliquée qu'une seule fois.

require_once __DIR__ . '/branding-services.php';
require_once __DIR__ . '/legal.php';
require_once __DIR__ . '/share.php';

const DOMAIN_SHOP_STATES = [
    'quoted' => 'Devis prêt',
    'awaiting_payment' => 'En attente de paiement',
    'ordering' => 'Commande en cours',
    'ordered' => 'Domaine commandé',
    'paid_needs_review' => 'Vérification en cours',
];

const DOMAIN_SHOP_LEGAL_FORMS = [
    'individual' => 'Personne physique',
    'personalcorporation' => 'Entreprise individuelle',
    'corporation' => 'Société',
    'association' => 'Association',
    'administration' => 'Administration',
    'other' => 'Autre',
];

function domain_shop_slug_valid(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $slug);
}

function domain_shop_dir(): string
{
    return defined('DOMAIN_SHOP_DATA_DIR') ? (string) DOMAIN_SHOP_DATA_DIR : dirname(__DIR__) . '/data/domaines';
}

function domain_shop_file(string $slug): string
{
    if (!domain_shop_slug_valid($slug)) throw new InvalidArgumentException('Identifiant de boutique invalide.');
    return domain_shop_dir() . '/' . $slug . '.json';
}

/** État vide d'une boutique : aucune recherche, aucune commande. */
function domain_shop_empty(): array
{
    return ['search' => null, 'purchase' => null];
}

function domain_shop_load(string $slug): array
{
    $file = domain_shop_file($slug);
    if (!is_file($file)) return domain_shop_empty();
    try {
        $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $ex) {
        error_log('domain_shop_load: ' . $ex->getMessage());
        return domain_shop_empty();
    }
    return is_array($data) ? $data + domain_shop_empty() : domain_shop_empty();
}

/**
 * Modifie l'état d'une boutique sous verrou exclusif puis l'écrit de façon atomique.
 * Le callback reçoit l'état courant et rend le nouvel état (une exception annule tout).
 */
function domain_shop_update(string $slug, callable $update): array
{
    $file = domain_shop_file($slug);
    $dir = dirname($file);
    if (is_link($dir)) throw new RuntimeException('Le dossier des domaines ne peut pas être un lien symbolique.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de préparer le dossier des domaines.');
    }
    $lock = fopen($file . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Impossible de verrouiller la commande du domaine.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Impossible de verrouiller la commande du domaine.');
        $data = $update(domain_shop_load($slug));
        $tmp = tempnam($dir, '.domaine-');
        if ($tmp === false) throw new RuntimeException('Impossible de préparer l’enregistrement du domaine.');
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($tmp, $json, LOCK_EX) === false || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
                throw new RuntimeException('Impossible d’enregistrer la commande du domaine.');
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

/** Titulaire du domaine tel que saisi dans le formulaire (avant validation). */
function domain_shop_owner_from_post(array $post): array
{
    return [
        'first_name' => mb_substr(trim((string) ($post['owner_first_name'] ?? '')), 0, 60),
        'last_name' => mb_substr(trim((string) ($post['owner_last_name'] ?? '')), 0, 60),
        'organization' => mb_substr(trim((string) ($post['owner_organization'] ?? '')), 0, 100),
        'legal_form' => trim((string) ($post['owner_legal_form'] ?? 'individual')),
        'address' => mb_substr(trim((string) ($post['owner_address'] ?? '')), 0, 160),
        'postal_code' => mb_substr(trim((string) ($post['owner_postal_code'] ?? '')), 0, 20),
        'city' => mb_substr(trim((string) ($post['owner_city'] ?? '')), 0, 80),
        'country' => strtoupper(trim((string) ($post['owner_country'] ?? 'FR'))),
        'phone' => mb_substr(trim((string) ($post['owner_phone'] ?? '')), 0, 30),
        'email' => strtolower(trim((string) ($post['owner_email'] ?? ''))),
    ];
}

/** Vérifie le titulaire et rend le téléphone normalisé au format OVH. */
function domain_shop_owner_validate(array $owner): array
{
    foreach ([
        'first_name' => 'Indiquez le prénom du titulaire.',
        'last_name' => 'Indiquez le nom du titulaire.',
        'address' => 'Indiquez l’adresse du titulaire.',
        'postal_code' => 'Indiquez le code postal du titulaire.',
        'city' => 'Indiquez la ville du titulaire.',
        'phone' => 'Indiquez un numéro de téléphone.',
        'email' => 'Indiquez l’adresse e-mail du titulaire.',
    ] as $field => $message) {
        if (trim((string) ($owner[$field] ?? '')) === '') throw new InvalidArgumentException($message);
    }
    if (!filter_var((string) $owner['email'], FILTER_VALIDATE_EMAIL) || strlen((string) $owner['email']) > 254) {
        throw new InvalidArgumentException('L’adresse e-mail du titulaire est invalide.');
    }
    if (!preg_match('/^[A-Z]{2}$/', (string) $owner['country'])) {
        throw new InvalidArgumentException('Le pays doit être un code ISO à deux lettres (par exemple FR).');
    }
    if (!isset(DOMAIN_SHOP_LEGAL_FORMS[(string) $owner['legal_form']])) {
        throw new InvalidArgumentException('Choisissez la forme juridique du titulaire.');
    }
    if ($owner['legal_form'] !== 'individual' && trim((string) $owner['organization']) === '') {
        throw new InvalidArgumentException('Indiquez le nom de l’entreprise ou de l’organisation titulaire.');
    }
    $owner['phone'] = branding_normalize_owner_phone((string) $owner['phone'], (string) $owner['country']);
    return $owner;
}

function domain_shop_state_label(string $state): string
{
    return DOMAIN_SHOP_STATES[$state] ?? 'État inconnu';
}

/**
 * Recherche des domaines : un nom de boutique (le nom exact et des variantes)
 * ou une adresse complète saisie avec son extension. Les disponibilités et les
 * prix viennent d'OVH et sont enregistrés pour la page suivante.
 */
function domain_shop_search(string $slug, string $input, string $location = ''): array
{
    $input = trim((string) preg_replace('/\s+/u', ' ', $input));
    if ($input === '' || mb_strlen($input) > 120) {
        throw new InvalidArgumentException('Indiquez un nom de boutique ou un domaine (120 caractères maximum).');
    }

    $previous = (array) (domain_shop_load($slug)['search'] ?? []);
    $previousPrices = [];
    foreach ((array) ($previous['domains'] ?? []) as $candidate) {
        if (!empty($candidate['available']) && isset($candidate['price_cents'])
            && time() - (int) ($candidate['price_at'] ?? 0) < 86400) {
            $previousPrices[(string) ($candidate['domain'] ?? '')] = $candidate;
        }
    }

    if (str_contains($input, '.')) {
        $domain = branding_domain_normalize($input);
        $candidate = ['domain' => $domain, 'kind' => 'exact'];
        try {
            $quote = branding_ovh_quote($domain);
            $candidate += ['available' => true, 'price_cents' => (int) $quote['amount_cents'], 'price_at' => (int) $quote['quoted_at']];
        } catch (RuntimeException $ex) {
            if (!str_contains($ex->getMessage(), 'pas disponible')) throw $ex;
            $candidate += ['available' => false];
        }
        $search = ['searched_at' => time(), 'primary' => $domain, 'query' => $input, 'domains' => [$candidate]];
    } else {
        $search = branding_ovh_search_domains($input, $location);
        $search['query'] = $input;
        $quoted = 0;
        $priceErrors = [];
        foreach ($search['domains'] as &$candidate) {
            if (empty($candidate['available']) || $quoted >= 12) continue;
            $cached = $previousPrices[(string) $candidate['domain']] ?? null;
            if (is_array($cached)) {
                $candidate['price_cents'] = (int) $cached['price_cents'];
                $candidate['price_at'] = (int) $cached['price_at'];
                continue;
            }
            $quoted++;
            try {
                $quote = branding_ovh_quote((string) $candidate['domain']);
                $candidate['price_cents'] = (int) $quote['amount_cents'];
                $candidate['price_at'] = (int) $quote['quoted_at'];
            } catch (RuntimeException $ex) {
                $candidate['price_error'] = $ex->getMessage();
                $priceErrors[] = (string) $candidate['domain'] . ' : ' . $ex->getMessage();
            }
        }
        unset($candidate);
        if ($priceErrors) $search['price_errors'] = $priceErrors;
    }

    domain_shop_update($slug, static fn (array $data): array => ['search' => $search] + $data);
    return $search;
}

/** Devis OVH d'un domaine choisi, avec le titulaire ; remplace un devis précédent non payé. */
function domain_shop_quote(string $slug, string $domain, array $owner): array
{
    $data = domain_shop_load($slug);
    $state = (string) ($data['purchase']['state'] ?? '');
    if (in_array($state, ['awaiting_payment', 'ordering', 'ordered', 'paid_needs_review'], true)) {
        throw new RuntimeException('Une commande de domaine est déjà en cours. Terminez-la ou annulez-la avant d’en préparer une autre.');
    }
    $owner = domain_shop_owner_validate($owner);
    $quote = branding_ovh_quote($domain);
    $purchase = [
        'state' => 'quoted',
        'quote' => $quote,
        'owner' => $owner,
        'created_at' => time(),
    ];
    domain_shop_update($slug, static fn (array $d): array => ['purchase' => $purchase] + $d);
    return $purchase;
}

/**
 * Ouvre le paiement Stripe de la plateforme pour le devis en cours. Le titulaire
 * est déclaré chez OVH AVANT le paiement (le panier est validé), comme à
 * l'inscription, pour ne jamais encaisser un domaine impossible à commander.
 */
function domain_shop_checkout(string $slug): array
{
    $data = domain_shop_load($slug);
    $purchase = (array) ($data['purchase'] ?? []);
    if (($purchase['state'] ?? '') !== 'quoted') {
        throw new RuntimeException('Aucun devis en attente : vérifiez d’abord le domaine et son titulaire.');
    }
    if (time() - (int) ($purchase['created_at'] ?? 0) > 86400) {
        throw new RuntimeException('Le devis a expiré (24 heures). Vérifiez à nouveau le domaine.');
    }
    if (!saas_stripe_configured()) {
        throw new RuntimeException('Le paiement en ligne n’est pas configuré sur ce serveur. Contactez l’exploitant de la plateforme.');
    }
    $quote = (array) ($purchase['quote'] ?? []);
    $owner = (array) ($purchase['owner'] ?? []);
    $email = (string) ($owner['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('L’adresse e-mail du titulaire est invalide.');

    $contactId = (string) ($purchase['ovh_contact_id'] ?? '');
    if ($contactId === '') {
        $contactId = branding_ovh_prepare_domain(['email' => $email], $purchase);
        domain_shop_update($slug, static function (array $d) use ($contactId): array {
            $d['purchase']['ovh_contact_id'] = $contactId;
            return $d;
        });
    }

    $domain = (string) ($quote['domain'] ?? '');
    $session = saas_stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'locale' => 'fr',
        'customer_email' => $email,
        'client_reference_id' => 'domaine:' . $slug,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => (int) ($quote['amount_cents'] ?? 0),
                'product_data' => [
                    'name' => 'Nom de domaine ' . $domain,
                    'description' => 'Enregistrement 1 an — ' . $domain . ' via OVH',
                ],
            ],
        ]],
        'metadata' => [
            'type' => 'shop_domain_purchase',
            'shop' => $slug,
            'domain' => $domain,
            'cart_id' => (string) ($quote['cart_id'] ?? ''),
        ],
        'success_url' => shop_abs_url('/admin/domaine.php') . '?s={CHECKOUT_SESSION_ID}',
        'cancel_url' => shop_abs_url('/admin/domaine.php'),
    ]);
    $sessionId = (string) ($session['id'] ?? '');
    $sessionUrl = (string) ($session['url'] ?? '');
    if ($sessionId === '' || $sessionUrl === '') throw new RuntimeException('Stripe n’a pas renvoyé de lien de paiement exploitable.');

    domain_shop_update($slug, static function (array $d) use ($sessionId, $sessionUrl): array {
        $d['purchase']['state'] = 'awaiting_payment';
        $d['purchase']['stripe_session'] = $sessionId;
        $d['purchase']['stripe_url'] = $sessionUrl;
        return $d;
    });
    return ['url' => $sessionUrl, 'session' => $sessionId];
}

/**
 * Applique une session Stripe Checkout payée (page de retour OU webhook).
 * Vérifie le type, la boutique, le domaine, le montant et la session attendue,
 * puis transmet la commande à OVH une seule fois.
 * Retourne ['state' => ordered|already|unpaid|review|invalid, ...].
 */
function domain_shop_apply_session(array $session): array
{
    $meta = (array) ($session['metadata'] ?? []);
    $slug = (string) ($meta['shop'] ?? '');
    if (($meta['type'] ?? '') !== 'shop_domain_purchase' || !domain_shop_slug_valid($slug)) {
        return ['state' => 'invalid'];
    }
    if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid', 'slug' => $slug];

    $already = false;
    try {
        $data = domain_shop_update($slug, static function (array $d) use ($session, $meta, &$already): array {
            $purchase = (array) ($d['purchase'] ?? []);
            $quote = (array) ($purchase['quote'] ?? []);
            $state = (string) ($purchase['state'] ?? '');
            if (!$purchase) throw new RuntimeException('Commande de domaine introuvable pour cette boutique.');
            if ((string) ($session['id'] ?? '') === '' || (string) ($session['id'] ?? '') !== (string) ($purchase['stripe_session'] ?? '')) {
                throw new RuntimeException('Le paiement ne correspond pas à la session enregistrée pour ce domaine.');
            }
            if ((string) ($session['client_reference_id'] ?? '') !== 'domaine:' . $slug) {
                throw new RuntimeException('Le paiement ne référence pas cette boutique.');
            }
            if ((string) ($meta['domain'] ?? '') !== (string) ($quote['domain'] ?? '')
                || (int) ($session['amount_total'] ?? 0) !== (int) ($quote['amount_cents'] ?? -1)
                || strtolower((string) ($session['currency'] ?? '')) !== 'eur') {
                throw new RuntimeException('Le paiement Stripe ne correspond pas au devis de domaine enregistré.');
            }
            if (in_array($state, ['ordered', 'ordering', 'paid_needs_review'], true)) {
                $already = true;
                return $d;
            }
            if ($state !== 'awaiting_payment') throw new RuntimeException('Cette commande de domaine ne peut pas être confirmée dans son état actuel.');
            $d['purchase']['state'] = 'ordering';
            $d['purchase']['paid_at'] = time();
            return $d;
        });
    } catch (RuntimeException $ex) {
        error_log('domain_shop_apply_session: ' . $ex->getMessage());
        return ['state' => 'invalid', 'slug' => $slug, 'message' => $ex->getMessage()];
    }
    if ($already) return ['state' => 'already', 'slug' => $slug, 'purchase' => (array) ($data['purchase'] ?? [])];

    $purchase = (array) ($data['purchase'] ?? []);
    try {
        $client = branding_ovh_client();
        $cartId = rawurlencode((string) ($purchase['quote']['cart_id'] ?? ''));
        $client->post('/order/cart/' . $cartId . '/validate');
        $order = $client->post('/order/cart/' . $cartId . '/checkout', [
            'autoPayWithPreferredPaymentMethod' => true,
            'waiveRetractationPeriod' => true,
        ]);
    } catch (\GuzzleHttp\Exception\GuzzleException $ex) {
        return domain_shop_mark_review($slug, 'OVH n’a pas confirmé la commande après le paiement ('
            . branding_ovh_api_error($ex, 'POST /order/cart/*/checkout')->getMessage() . ')');
    } catch (\Throwable $ex) {
        error_log('domain_shop OVH: ' . get_class($ex) . ' ' . $ex->getMessage());
        return domain_shop_mark_review($slug, 'OVH n’a pas confirmé la commande après le paiement.');
    }

    $orderId = (string) ($order['orderId'] ?? $order['id'] ?? '');
    if ($orderId === '') return domain_shop_mark_review($slug, 'Paiement reçu ; OVH ne confirme pas de numéro de commande.');

    $data = domain_shop_update($slug, static function (array $d) use ($orderId): array {
        $d['purchase']['state'] = 'ordered';
        $d['purchase']['ovh_order_id'] = $orderId;
        $d['purchase']['ordered_at'] = time();
        return $d;
    });
    $purchase = (array) ($data['purchase'] ?? []);
    $domain = (string) ($purchase['quote']['domain'] ?? '');
    saas_event('domain_ordered', [
        'slug' => $slug,
        'email' => (string) ($purchase['owner']['email'] ?? ''),
        'amount' => (int) ($purchase['quote']['amount_cents'] ?? 0),
        'note' => $domain . ' — commande OVH ' . $orderId,
        'actor' => 'stripe',
    ]);
    $ownerEmail = (string) ($purchase['owner']['email'] ?? '');
    if (filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        saas_mail($ownerEmail, 'Commande de domaine transmise à OVH',
            "Bonjour,\n\nLa commande du domaine $domain a été transmise à OVH (référence $orderId).\n"
            . "La mise en service dépend du délai de traitement d’OVH. La facturation du renouvellement annuel n’est pas incluse dans le paiement de la première année.\n");
    }
    return ['state' => 'ordered', 'slug' => $slug, 'purchase' => $purchase];
}

/** Paiement encaissé mais commande OVH non confirmée : état à vérifier, personne n'est remboursé automatiquement. */
function domain_shop_mark_review(string $slug, string $message): array
{
    $data = domain_shop_update($slug, static function (array $d) use ($message): array {
        $d['purchase']['state'] = 'paid_needs_review';
        $d['purchase']['message'] = $message;
        return $d;
    });
    $purchase = (array) ($data['purchase'] ?? []);
    $domain = (string) ($purchase['quote']['domain'] ?? 'inconnu');
    $body = "Le paiement de $domain est confirmé, mais OVH n’a pas confirmé la commande.\n\n"
        . "Boutique : $slug\nPanier OVH : " . (string) ($purchase['quote']['cart_id'] ?? '')
        . "\nSession Stripe : " . (string) ($purchase['stripe_session'] ?? '') . "\nMotif : " . $message . "\n";
    $contact = (string) (saas_config()['contact'] ?? '');
    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) saas_mail($contact, 'Commande de domaine à vérifier : ' . $domain, $body);
    $ownerEmail = (string) ($purchase['owner']['email'] ?? '');
    if (filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) saas_mail($ownerEmail, 'Vérification requise pour votre nom de domaine', $body);
    return ['state' => 'review', 'slug' => $slug, 'purchase' => $purchase];
}

/** Ferme un devis ou un paiement en attente (session Stripe expirée) ; une commande payée n'est jamais annulée ici. */
function domain_shop_cancel(string $slug): void
{
    $data = domain_shop_load($slug);
    $purchase = (array) ($data['purchase'] ?? []);
    $state = (string) ($purchase['state'] ?? '');
    if (in_array($state, ['ordering', 'ordered', 'paid_needs_review'], true)) {
        throw new RuntimeException('Ce domaine est déjà payé : une annulation depuis cette page n’est pas possible. Contactez l’exploitant de la plateforme.');
    }
    if ($state === 'awaiting_payment') {
        $sessionId = (string) ($purchase['stripe_session'] ?? '');
        if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
            try {
                saas_stripe_request('POST', '/checkout/sessions/' . rawurlencode($sessionId) . '/expire');
            } catch (Throwable $ex) {
                error_log('domain_shop_cancel stripe: ' . $ex->getMessage());
            }
        }
    }
    domain_shop_update($slug, static fn (array $d): array => ['purchase' => null] + $d);
}
