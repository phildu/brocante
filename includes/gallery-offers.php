<?php

// Offres « galerie commerciale » : la création d'une galerie est un produit vendu par la plateforme.
// Trois offres par défaut, toutes modifiables dans le portail (Offres galerie) : un TEST gratuit (durée limitée), puis deux abonnements
// qui se distinguent par le nombre de commerces que la galerie peut regrouper. Réglages : data/saas/galeries-offres.json.
// L'offre d'une galerie est dans sa clé « plan » (data/galeries/<id>.json) ; une galerie sans plan (créée à la main par l'exploitant)
// reste active et sans limite. Paiement : abonnement Stripe de la plateforme (compte du portail), comme les boutiques.

require_once __DIR__ . '/gallery-admin.php';
require_once __DIR__ . '/saas.php';
require_once __DIR__ . '/gallery-types.php';

const GO_PLAN_STATUS = ['trial' => 'Essai gratuit', 'active' => 'Abonnée', 'expired' => 'Essai terminé', 'canceled' => 'Résiliée'];

function go_defaults(): array
{
    return [
        ['key' => 'test', 'name' => 'Test gratuit', 'tagline' => 'Pour essayer la galerie sans engagement', 'month_cents' => 0, 'year_cents' => 0, 'max_members' => 3, 'trial_days' => 14, 'active' => true],
        ['key' => 'essentiel', 'name' => 'Essentiel', 'tagline' => 'Pour une rue, un village, un groupe d\'amis', 'month_cents' => 2900, 'year_cents' => 29000, 'max_members' => 10, 'trial_days' => 0, 'active' => true],
        ['key' => 'pro', 'name' => 'Pro', 'tagline' => 'Pour une grande galerie de commerçants', 'month_cents' => 7900, 'year_cents' => 79000, 'max_members' => 30, 'trial_days' => 0, 'active' => true],
    ];
}

function go_file(): string
{
    return saas_data_dir() . '/saas/galeries-offres.json';
}

function go_normalize(array $o, array $fallback): array
{
    $int = static fn ($v, int $min, int $max, int $d) => is_numeric($v) ? max($min, min($max, (int) $v)) : $d;
    return [
        'key' => $fallback['key'],
        'name' => mb_substr(trim((string) ($o['name'] ?? $fallback['name'])), 0, 40) ?: $fallback['name'],
        'tagline' => mb_substr(trim((string) ($o['tagline'] ?? $fallback['tagline'])), 0, 120),
        'month_cents' => $int($o['month_cents'] ?? null, 0, 1000000, $fallback['month_cents']),
        'year_cents' => $int($o['year_cents'] ?? null, 0, 10000000, $fallback['year_cents']),
        'max_members' => $int($o['max_members'] ?? null, 1, 1000, $fallback['max_members']),
        'trial_days' => $int($o['trial_days'] ?? null, 0, 365, $fallback['trial_days']),
        'active' => !array_key_exists('active', $o) ? $fallback['active'] : !empty($o['active']),
    ];
}

/** Les trois offres d'un type de galerie (voir includes/gallery-types.php ; la galerie marchande par défaut). Une offre gratuite (0 €) est un test : durée limitée. */
function go_offers(?string $type = null): array
{
    return gt_type($type)['offers'];
}

function go_offer(string $key, ?string $type = null): ?array
{
    return go_offers($type)[$key] ?? null;
}

function go_price_text(int $cents, string $billing = 'month'): string
{
    if ($cents <= 0) return 'Gratuit';
    return rtrim(rtrim(number_format($cents / 100, 2, ',', ' '), '0'), ',') . ' € / ' . ($billing === 'year' ? 'an' : 'mois');
}

// ── Plan d'une galerie ──────────────────────────────────────────────────────

/** Geste d'accès en cours (gratuité totale ou jusqu'à une date) : il maintient la galerie ouverte malgré l'offre. */
function go_gesture_active(array $gallery): bool
{
    $g = $gallery['gesture'] ?? null;
    if (!is_array($g) || empty($g['kind'])) return false;
    return $g['kind'] === 'forever' || ($g['kind'] === 'until' && (int) ($g['until'] ?? 0) > time());
}

/** Le geste est-il encore en cours (accès ou avantage de facturation) ? */
function go_gesture_current(array $gallery): bool
{
    $g = $gallery['gesture'] ?? null;
    if (!is_array($g) || empty($g['kind'])) return false;
    if ($g['kind'] === 'forever') return true;
    $until = (int) ($g['until'] ?? 0);
    return $until <= 0 || $until > time();
}

/** Le geste porte-t-il sur la facturation (avoir ou remise) plutôt que sur l'accès ? */
function go_gesture_is_billing(array $gallery): bool
{
    return in_array((string) ($gallery['gesture']['kind'] ?? ''), ['credit', 'discount'], true);
}

/** Période d'un avoir ou d'une remise, ou '' si aucune date. */
function go_gesture_period(int $until): string
{
    if ($until <= 0) return '';
    return ($until > time() ? ' — valable jusqu’au ' : ' — expiré le ') . date('d/m/Y', $until);
}

/** Libellé du geste : « Gratuité totale », « Avoir commercial de 19,90 € », « Remise commerciale de 20 % », ou ''. */
function go_gesture_label(array $gallery): string
{
    $g = $gallery['gesture'] ?? null;
    if (!is_array($g) || empty($g['kind'])) return '';
    if ($g['kind'] === 'forever') return 'Gratuité totale';
    if ($g['kind'] === 'until') {
        $until = (int) ($g['until'] ?? 0);
        if ($until <= 0) return '';
        return ($until > time() ? 'Gratuit jusqu’au ' : 'Gratuité terminée le ') . date('d/m/Y', $until);
    }
    if ($g['kind'] === 'credit') {
        $amount = number_format(((int) ($g['amount_cents'] ?? 0)) / 100, 2, ',', ' ');
        return 'Avoir commercial de ' . $amount . ' €' . go_gesture_period((int) ($g['until'] ?? 0));
    }
    if ($g['kind'] === 'discount') {
        return 'Remise commerciale de ' . (int) ($g['percent'] ?? 0) . ' %' . go_gesture_period((int) ($g['until'] ?? 0));
    }
    return '';
}

/** Statut du plan : 'manual' (galerie sans plan : créée à la main, active sans limite), trial, active, expired, canceled. */
function go_status(array $gallery): string
{
    $p = $gallery['plan'] ?? null;
    if (!$p || empty($p['offer'])) return 'manual';
    if (go_gesture_active($gallery)) return 'active';
    if ($p['status'] === 'trial' && (int) $p['trial_until'] <= time()) return 'expired';
    return (string) $p['status'];
}

/** La galerie peut-elle être visible du public ? (Hors aperçu.) */
function go_is_live(array $gallery): bool
{
    return empty($gallery['paused']) && !empty($gallery['published']) && in_array(go_status($gallery), ['manual', 'trial', 'active'], true);
}

/** Nombre maximal de commerces, ou null (aucune limite). */
function go_member_limit(array $gallery): ?int
{
    $p = $gallery['plan'] ?? null;
    return $p && !empty($p['offer']) ? max(1, (int) ($p['max_members'] ?? 1)) : null;
}

/** Adresse d'une nouvelle galerie : identifiant libre dérivé du nom (-2, -3… si pris). */
function go_free_slug(string $name): string
{
    $base = substr(gallery_slugify($name), 0, 34);
    if (!gallery_slug_valid($base)) $base = 'galerie';
    $slug = $base;
    for ($i = 2; $i < 100 && (gallery_load($slug) || in_array($slug, ['index', 'admin', 'assets', 'portail', 'contact', 'clic', 'creer'], true)); $i++) $slug = $base . '-' . $i;
    return $slug;
}

/** Crée la galerie d'un client avec son administrateur et envoie l'accès. -> [slug, erreur]. */
function go_create(array $offer, string $name, string $email, string $billing, array $stripe = [], string $type = GT_DEFAULT): array
{
    $type = gt_type($type)['key'];
    $name = mb_substr(trim($name), 0, 80);
    $email = mb_strtolower(trim($email));
    if ($name === '') return [null, 'Donnez un nom à votre galerie.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Adresse e-mail invalide.'];
    $slug = go_free_slug($name);
    $free = $offer['month_cents'] <= 0;
    $plan = ['offer' => $offer['key'], 'status' => $free ? 'trial' : 'active', 'billing' => $billing === 'year' ? 'year' : 'month', 'max_members' => $offer['max_members'],
        'trial_until' => $free ? time() + max(1, (int) $offer['trial_days']) * 86400 : 0, 'started' => time(),
        'stripe_subscription_id' => (string) ($stripe['subscription'] ?? ''), 'stripe_customer_id' => (string) ($stripe['customer'] ?? ''), 'stripe_session_id' => (string) ($stripe['session'] ?? '')];
    $gallery = ['slug' => $slug, 'name' => $name, 'tagline' => '', 'published' => false, 'created' => time(), 'members' => [], 'plan' => $plan, 'type' => $type];
    if (!gallery_save($gallery)) return [null, "Impossible d'enregistrer la galerie pour le moment."];
    [$err, $password] = ga_admin_add($slug, $email, '');
    if ($err) return [null, $err];
    $origin = saas_origin();
    $until = $free ? ' Votre essai gratuit dure ' . (int) $offer['trial_days'] . ' jours (jusqu\'au ' . date('d/m/Y', $plan['trial_until']) . ') et permet jusqu\'à ' . (int) $offer['max_members'] . ' ' . gt_word(gt_type($type), 'members') . '.' : ' Votre offre « ' . $offer['name'] . ' » permet jusqu\'à ' . (int) $offer['max_members'] . ' commerces.';
    $mailed = ga_mail($email, 'Votre ' . gt_word(gt_type($type), 'gallery') . ' « ' . $name . ' » est créé(e)',
        "Bonjour,\n\nVotre " . gt_word(gt_type($type), 'gallery') . " « $name » est créé(e).$until\n\nConnexion : $origin/galerie/$slug/admin\nIdentifiant : $email\nMot de passe provisoire : $password\n\nVous devrez le remplacer à votre première connexion. Vous pourrez ensuite choisir vos commerces, personnaliser la page et la publier : $origin/galerie/$slug/\n");
    saas_event('gallery_created', ['slug' => $slug, 'email' => $email, 'plan' => $offer['key'], 'note' => gt_type($type)['name'] . ' · ' . $offer['name'], 'amount' => $free ? 0 : ($billing === 'year' ? $offer['year_cents'] : $offer['month_cents']), 'actor' => $free ? 'client' : 'stripe']);
    return [$slug, null, $password, $mailed];
}

// ── Demandes en cours (paiement) ────────────────────────────────────────────

function go_requests_dir(): string
{
    return galleries_dir() . '/.demandes';
}

function go_request_save(array $r): bool
{
    $dir = go_requests_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    return @file_put_contents($dir . '/' . $r['id'] . '.json', json_encode($r, JSON_UNESCAPED_UNICODE)) !== false;
}

function go_request_load(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
    $f = go_requests_dir() . '/' . $id . '.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : null;
}

/** Page de paiement Stripe d'un abonnement galerie (nouvelle galerie : $slug vide ; changement d'offre d'une galerie existante : son identifiant). -> [url, erreur]. */
function go_checkout(array $offer, string $billing, string $email, string $name, string $slug, string $successPath, string $cancelUrl, string $type = GT_DEFAULT): array
{
    $type = gt_type($type)['key'];
    if (!saas_stripe_configured()) return [null, "Le paiement en ligne n'est pas encore disponible : choisissez le test gratuit ou contactez-nous."];
    $cents = $billing === 'year' ? (int) $offer['year_cents'] : (int) $offer['month_cents'];
    if ($cents <= 0) return [null, 'Cette offre est gratuite.'];
    $req = ['id' => bin2hex(random_bytes(8)), 'created' => time(), 'offer' => $offer['key'], 'type' => $type, 'billing' => $billing === 'year' ? 'year' : 'month', 'email' => mb_strtolower(trim($email)), 'name' => mb_substr(trim($name), 0, 80), 'slug' => $slug, 'done' => ''];
    $origin = saas_origin();
    $meta = ['type' => 'gallery_offer', 'request' => $req['id'], 'offer' => $offer['key'], 'gallery_type' => $type, 'slug' => $slug];
    try {
        $s = saas_stripe_request('POST', '/checkout/sessions', [
            'mode' => 'subscription', 'locale' => 'fr', 'customer_email' => $req['email'], 'client_reference_id' => 'gallery:' . $req['id'],
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => $cents, 'recurring' => ['interval' => $req['billing'] === 'year' ? 'year' : 'month'],
                'product_data' => ['name' => gt_type($type)['name'] . ' — ' . $offer['name'], 'description' => 'Jusqu\'à ' . (int) $offer['max_members'] . ' ' . gt_word(gt_type($type), 'members') . ' regroupés sur une page commune']]]],
            'metadata' => $meta, 'subscription_data' => ['metadata' => $meta],
            'success_url' => $origin . $successPath . (str_contains($successPath, '?') ? '&' : '?') . 'r=' . $req['id'] . '&s={CHECKOUT_SESSION_ID}', 'cancel_url' => $cancelUrl,
        ]);
    } catch (Throwable $e) {
        error_log('go_checkout : ' . $e->getMessage());
        return [null, "Impossible d'ouvrir le paiement : " . $e->getMessage()];
    }
    if (empty($s['url'])) return [null, "Stripe n'a pas renvoyé d'adresse de paiement."];
    go_request_save($req);
    return [$s['url'], null];
}

/** Session Checkout payée → galerie créée (ou offre mise à jour). Idempotent. -> ['state' => added|updated|already|unpaid|invalid, 'slug']. */
function go_apply_session(array $session): array
{
    $m = (array) ($session['metadata'] ?? []);
    if (($m['type'] ?? '') !== 'gallery_offer') return ['state' => 'invalid'];
    $req = go_request_load((string) ($m['request'] ?? ''));
    $existing = $req && ($req['slug'] ?? '') !== '' ? gallery_load((string) $req['slug']) : null;
    $type = $existing ? gt_of($existing)['key'] : gt_type((string) ($req['type'] ?? $m['gallery_type'] ?? GT_DEFAULT))['key'];
    $offer = go_offer((string) ($m['offer'] ?? ''), $type);
    if (!$req || !$offer || $req['offer'] !== $offer['key']) return ['state' => 'invalid'];
    if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid'];
    if (!empty($req['done'])) return ['state' => 'already', 'slug' => $req['done']];
    $stripe = ['subscription' => (string) ($session['subscription'] ?? ''), 'customer' => (string) ($session['customer'] ?? ''), 'session' => (string) ($session['id'] ?? '')];
    if ($req['slug'] !== '' && ($g = gallery_load($req['slug']))) {        // changement d'offre d'une galerie existante
        $oldSub = (string) ($g['plan']['stripe_subscription_id'] ?? '');
        $g['plan'] = ['offer' => $offer['key'], 'status' => 'active', 'billing' => $req['billing'], 'max_members' => $offer['max_members'], 'trial_until' => 0, 'started' => (int) ($g['plan']['started'] ?? time()),
            'stripe_subscription_id' => $stripe['subscription'], 'stripe_customer_id' => $stripe['customer'], 'stripe_session_id' => $stripe['session']];
        gallery_save($g);
        if ($oldSub !== '' && $oldSub !== $stripe['subscription']) {             // l'ancien abonnement est remplacé : on le ferme pour ne pas facturer deux fois
            try { saas_stripe_request('DELETE', '/subscriptions/' . rawurlencode($oldSub)); } catch (Throwable $e) { error_log('go: ancien abonnement non résilié : ' . $e->getMessage()); }
        }
        saas_event('gallery_plan_changed', ['slug' => $g['slug'], 'email' => $req['email'], 'plan' => $offer['key'], 'amount' => (int) ($session['amount_total'] ?? 0), 'actor' => 'stripe']);
        $state = 'updated';
        $slug = $g['slug'];
    } else {
        [$slug, $err] = go_create($offer, $req['name'], $req['email'], $req['billing'], $stripe, $type);
        if ($err) return ['state' => 'invalid', 'error' => $err];
        $state = 'added';
    }
    $req['done'] = $slug;
    go_request_save($req);
    return ['state' => $state, 'slug' => $slug];
}

/** Abonnement résilié (webhook) : la galerie passe en « résiliée » et n'est plus visible. -> identifiant de la galerie ou null. */
function go_cancel_subscription(string $subscriptionId): ?string
{
    if ($subscriptionId === '') return null;
    foreach (gallery_list() as $g) {
        if (($g['plan']['stripe_subscription_id'] ?? '') === $subscriptionId) {
            $g['plan']['status'] = 'canceled';
            $g['plan']['ended'] = time();
            gallery_save($g);
            saas_event('gallery_canceled', ['slug' => $g['slug'], 'plan' => $g['plan']['offer'], 'actor' => 'stripe']);
            return $g['slug'];
        }
    }
    return null;
}
