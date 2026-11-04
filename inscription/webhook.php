<?php
// Webhook Stripe de la plateforme (à déclarer dans le tableau de bord Stripe : <domaine>/inscription/webhook.php, événements
// checkout.session.completed, checkout.session.async_payment_succeeded, customer.subscription.deleted, et pour le suivi de l'activité : invoice.paid, invoice.payment_failed, charge.refunded). La signature est vérifiée avec le « secret de signature » (whsec_…) enregistré
// dans le portail → Offres et annuaire. Sert de filet : la boutique est générée même si le client ferme la page de retour.
require_once __DIR__ . '/../includes/saas.php';

http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');

$payload = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$signed = false;
foreach (saas_stripe_webhook_secrets() as $secret) {
    if (saas_stripe_signature_valid($payload, $signature, $secret)) { $signed = true; break; }
}
if (!$signed) exit('signature invalide');
$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) exit('événement illisible');

$obj = $event['data']['object'] ?? [];

// Commande d'une boutique payée en Connect (l'événement appartient au compte
// relié) : retrouve le commerce, bascule sur sa base et applique le parcours
// commande (paiement, remboursement, litige).
if (!empty($event['account'])) {
    $shopMeta = (string) (($obj['metadata']['shop'] ?? '') ?: '');
    $isSessionEvent = in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed', 'checkout.session.expired'], true) && (($obj['metadata']['type'] ?? '') === 'shop_order');
    $isChargeEvent = in_array($event['type'] ?? '', ['charge.refunded', 'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed'], true);
    if ($isSessionEvent || $isChargeEvent) {
        require_once dirname(__DIR__) . '/includes/functions.php';
        $slug = $shopMeta !== '' ? $shopMeta : order_shop_for_account((string) $event['account']);
        if ($slug !== '') {
            try {
                $res = order_apply_connect_event($slug, $event);
                error_log('webhook stripe connect ' . $slug . ' ' . $event['type'] . ' → ' . $res);
            } catch (Throwable $ex) {
                error_log('webhook stripe connect ' . $slug . ' : ' . $ex->getMessage());
                http_response_code(500);
                exit('commande à vérifier');
            }
            http_response_code(200);
            exit('ok');
        }
    }
}

$origin = saas_origin();
saas_event('webhook', ['note' => (string) $event['type'], 'actor' => 'stripe']);

/** Demande dont l'abonnement (ou le client) Stripe est celui de l'objet reçu. */
$requestOf = static function (array $o): ?array {
    $sub = (string) ($o['subscription'] ?? '');
    $cus = (string) ($o['customer'] ?? '');
    foreach (saas_requests() as $r) {
        $p = $r['payment'] ?? [];
        if (($sub !== '' && ($p['subscription'] ?? '') === $sub) || ($sub === '' && $cus !== '' && ($p['customer'] ?? '') === $cus)) return $r;
    }
    return null;
};

if ($event['type'] === 'checkout.session.completed' && (($obj['metadata']['type'] ?? '') === 'ai_credit')) {
    // Rachat de crédit IA d'une boutique (admin/ai-credit-action.php) : crédit ajouté une seule fois, même si la page de retour est déjà passée.
    $res = saas_ai_credit_apply_session($obj);
    if ($res['state'] === 'added') saas_event('ai_credit_purchased', ['slug' => $res['slug'], 'amount' => $res['amount'] ?? 0, 'note' => 'Pack ' . ($res['pack'] ?? '') . ' : ' . number_format($res['credit_eur'], 2, ',', '') . ' € de crédit IA', 'actor' => 'stripe']);
    error_log('webhook stripe ai_credit ' . ($res['slug'] ?? '?') . ' → ' . $res['state']);
} elseif ($event['type'] === 'checkout.session.completed' && (($obj['metadata']['type'] ?? '') === 'gallery_offer')) {
    // Offre « galerie commerciale » (includes/gallery-offers.php) : crée la galerie et son administrateur, ou met son offre à jour.
    require_once dirname(__DIR__) . '/includes/gallery-offers.php';
    $res = go_apply_session($obj);
    error_log('webhook stripe gallery_offer ' . ($res['slug'] ?? '?') . ' → ' . ($res['state'] ?? '?'));
} elseif (in_array($event['type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && (($obj['metadata']['type'] ?? '') === 'module_addon')) {
    // Module payant d'un plugin (includes/addons.php) : active l'accès après paiement.
    require_once dirname(__DIR__) . '/includes/addons.php';
    $res = addon_apply_checkout_session($obj);
    if (($res['state'] ?? '') === 'added') {
        saas_event('addon_subscribed', ['slug' => $res['slug'] ?? '', 'amount' => (int) ($obj['amount_total'] ?? 0), 'note' => 'Module ' . ($res['module'] ?? ''), 'actor' => 'stripe']);
    }
    error_log('webhook stripe module_addon ' . ($res['slug'] ?? '?') . ' ' . ($res['module'] ?? '?') . ' → ' . ($res['state'] ?? '?'));
} elseif ($event['type'] === 'checkout.session.completed' && (($obj['metadata']['type'] ?? '') === 'marketplace_addon')) {
    // Option Annonces places de marché (packs/annonces-marche) : active l'abonnement de la boutique.
    require_once dirname(__DIR__) . '/packs/annonces-marche/lib/subscribe.php';
    $res = am_apply_checkout_session($obj);
    if (($res['state'] ?? '') === 'added') {
        saas_event('addon_subscribed', [
            'slug' => $res['slug'] ?? '',
            'amount' => (int) ($obj['amount_total'] ?? 0),
            'note' => 'Annonces Leboncoin & Vinted',
            'actor' => 'stripe',
        ]);
    }
    error_log('webhook stripe marketplace_addon ' . ($res['slug'] ?? '?') . ' → ' . ($res['state'] ?? '?'));
} elseif ($event['type'] === 'checkout.session.completed' && (($obj['metadata']['type'] ?? '') === 'gallery_module')) {
    // Option d'une galerie (includes/gallery-modules.php) : carte interactive, espace organisateur…
    require_once dirname(__DIR__) . '/includes/gallery-modules.php';
    $res = gm_apply_session($obj);
    if (($res['state'] ?? '') === 'added') {
        saas_event('gallery_module_subscribed', ['slug' => $res['slug'] ?? '', 'amount' => (int) ($obj['amount_total'] ?? 0),
            'note' => 'Option ' . (gm_module((string) ($res['module'] ?? ''))['name'] ?? (string) ($res['module'] ?? '')), 'actor' => 'stripe']);
    }
    error_log('webhook stripe gallery_module ' . ($res['slug'] ?? '?') . ' ' . ($res['module'] ?? '?') . ' → ' . ($res['state'] ?? '?'));
} elseif (in_array($event['type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
    && (($obj['metadata']['type'] ?? '') === 'shop_domain_purchase')) {
    // Nom de domaine acheté depuis l'administration d'une boutique (admin/domaine.php) :
    // le titulaire est déclaré et le panier OVH validé avant le paiement ; il ne reste qu'à transmettre la commande.
    if (($obj['payment_status'] ?? '') !== 'paid') {
        http_response_code(200);
        exit('paiement en attente');
    }
    require_once dirname(__DIR__) . '/includes/domain-shop.php';
    try {
        $purchase = domain_shop_apply_session($obj);
        error_log('webhook stripe shop_domain_purchase ' . ($obj['metadata']['shop'] ?? '?') . ' → ' . ($purchase['state'] ?? '?'));
    } catch (Throwable $ex) {
        error_log('webhook stripe shop_domain_purchase: ' . $ex->getMessage());
        http_response_code(500);
        exit('commande de domaine à vérifier');
    }
} elseif (in_array($event['type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
    && (($obj['metadata']['type'] ?? '') === 'domain_purchase')) {
    if (($obj['payment_status'] ?? '') !== 'paid') {
        http_response_code(200);
        exit('paiement en attente');
    }
    require_once dirname(__DIR__) . '/includes/branding-services.php';
    try {
        $purchase = branding_apply_domain_checkout($obj);
        error_log('webhook stripe domain_purchase ' . ($obj['metadata']['request_id'] ?? '?') . ' → ' . ($purchase['state'] ?? '?'));
    } catch (RuntimeException $ex) {
        error_log('webhook stripe domain_purchase: ' . $ex->getMessage());
        http_response_code(500);
        exit('commande de domaine à vérifier');
    }
} elseif ($event['type'] === 'checkout.session.completed') {
    $reqId = (string) ($obj['client_reference_id'] ?? '');
    if (preg_match('/^[a-f0-9]{16}$/', $reqId)) {
        // Enregistre le paiement et envoie au client le lien de création de sa boutique (la boutique, elle, se crée à l'étape 3).
        $res = saas_mark_paid($reqId, $obj, $origin);
        error_log('webhook stripe checkout.session.completed ' . $reqId . ' → ' . $res['state']);
        if ($res['state'] === 'error') {
            http_response_code(500);
            exit('paiement à finaliser');
        }
    }
} elseif ($event['type'] === 'customer.subscription.deleted') {
    // Abonnement résilié : on le note sur la demande (l'exploitant décide de la suite dans le portail).
    $sub = (string) ($obj['id'] ?? '');
    foreach (saas_requests() as $r) {
        if ($sub !== '' && ($r['payment']['subscription'] ?? '') === $sub) {
            $r['payment']['state'] = 'canceled';
            $r['payment']['canceled_at'] = time();
            saas_request_save($r);
            saas_event('subscription_canceled', ['ref' => $r['id'], 'slug' => $r['slug'] ?? '', 'email' => $r['email'], 'plan' => $r['plan'], 'actor' => 'stripe']);
        }
    }
    // Offres galerie (includes/gallery-offers.php).
    if ($sub !== '') {
        require_once dirname(__DIR__) . '/includes/gallery-offers.php';
        go_cancel_subscription($sub);
    }
    // Options d'une galerie (carte interactive, espace organisateur…).
    if ($sub !== '') {
        require_once dirname(__DIR__) . '/includes/gallery-modules.php';
        if ($gm = gm_cancel_subscription($sub)) {
            saas_event('gallery_module_canceled', ['slug' => $gm[0], 'note' => 'Option ' . $gm[1], 'actor' => 'stripe']);
        }
    }
    // Modules payants des plugins (includes/addons.php).
    if ($sub !== '') {
        require_once dirname(__DIR__) . '/includes/addons.php';
        if ($mod = addon_cancel_subscription($sub)) {
            saas_event('addon_canceled', ['slug' => $mod[0], 'note' => 'Module ' . $mod[1], 'actor' => 'stripe']);
        }
    }
    // Option Annonces (abonnement distinct de celui de la boutique).
    if ($sub !== '') {
        require_once dirname(__DIR__) . '/packs/annonces-marche/lib/subscribe.php';
        $addonSlug = am_cancel_subscription($sub);
        if ($addonSlug) {
            saas_event('addon_canceled', ['slug' => $addonSlug, 'note' => 'Annonces Leboncoin & Vinted', 'actor' => 'stripe']);
        }
    }
} elseif ($event['type'] === 'invoice.paid' && ($obj['billing_reason'] ?? '') === 'subscription_cycle') {
    // Renouvellement mensuel (le premier paiement, lui, est déjà compté par checkout.session.completed).
    if ($r = $requestOf($obj)) saas_event('payment_renewed', ['ref' => $r['id'], 'slug' => $r['slug'] ?? '', 'email' => $r['email'], 'plan' => $r['plan'], 'amount' => (int) ($obj['amount_paid'] ?? 0), 'actor' => 'stripe']);
} elseif ($event['type'] === 'invoice.payment_failed') {
    if ($r = $requestOf($obj)) saas_event('payment_failed', ['ref' => $r['id'], 'slug' => $r['slug'] ?? '', 'email' => $r['email'], 'plan' => $r['plan'], 'amount' => (int) ($obj['amount_due'] ?? 0), 'actor' => 'stripe']);
} elseif ($event['type'] === 'charge.refunded') {
    if ($r = $requestOf($obj)) saas_event('payment_refunded', ['ref' => $r['id'], 'slug' => $r['slug'] ?? '', 'email' => $r['email'], 'plan' => $r['plan'], 'amount' => (int) ($obj['amount_refunded'] ?? 0), 'actor' => 'stripe']);
}
http_response_code(200);
echo 'ok';
