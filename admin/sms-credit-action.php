<?php
// Rachat de crédit SMS : ouvre le paiement Stripe (compte de la PLATEFORME, paiement
// unique) d'un pack, pour la boutique connectée. POST pack=<clé> & csrf → Stripe
// Checkout ; retour sur admin/sms-credit-merci.php, qui crédite (le webhook aussi).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/sms.php';
require_admin();

function sms_credit_back(string $message, string $kind = 'error'): never
{
    flash_set($message, $kind);
    header('Location: /admin/sms-credits.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sms_credit_back('Méthode non autorisée.');
if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) sms_credit_back('Page expirée : rechargez-la et recommencez.');
if (!sms_credit_account(tenant_slug())['enabled']) sms_credit_back("Votre boutique n'a pas de crédit SMS : ses envois ne sont pas décomptés, il n'y a rien à racheter.");
$pack = saas_sms_pack((string) ($_POST['pack'] ?? ''));
if (!$pack) sms_credit_back('Pack introuvable.');
if (!saas_stripe_configured()) sms_credit_back("Le paiement n'est pas disponible sur ce serveur : contactez l'exploitant de la plateforme pour du crédit SMS.");

$slug = tenant_slug();
$name = (string) (get_content()['site_name'] ?: tenant('name'));
try {
    $session = saas_stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment', 'locale' => 'fr', 'client_reference_id' => 'sms:' . $slug,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => ['currency' => 'eur', 'unit_amount' => (int) $pack['price_cents'],
                'product_data' => ['name' => 'Crédit SMS — ' . $pack['name'] . ' (' . $name . ')', 'description' => 'Crédit SMS pour votre boutique : ' . (int) $pack['sms'] . ' SMS. Sans expiration, décompté à chaque envoi.']],
        ]],
        'metadata' => ['type' => 'sms_credit', 'shop' => $slug, 'pack' => $pack['key'], 'sms' => (string) (int) $pack['sms']],
        'payment_intent_data' => ['metadata' => ['type' => 'sms_credit', 'shop' => $slug]],
        'success_url' => shop_abs_url('/admin/sms-credit-merci.php') . '?s={CHECKOUT_SESSION_ID}',
        'cancel_url' => shop_abs_url('/admin/sms-credits.php'),
    ]);
} catch (Throwable $e) {
    error_log('sms-credit-action: ' . $e->getMessage());
    sms_credit_back('Le paiement n\'a pas pu être ouvert : ' . $e->getMessage());
}
if (empty($session['url'])) sms_credit_back('Stripe n\'a pas renvoyé d\'adresse de paiement.');
header('Location: ' . $session['url'], true, 303);
exit;
