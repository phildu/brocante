<?php
// Rachat de crédit IA : ouvre le paiement Stripe (compte de la PLATEFORME, paiement unique) d'un pack, pour la boutique connectée.
//   POST pack=<clé du pack> & csrf → redirection vers Stripe Checkout ; retour sur admin/ai-credit-merci.php, qui crédite la boutique (le webhook le fait aussi).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/saas.php';
require_admin();

function ai_credit_back(string $message, string $kind = 'error'): never
{
    flash_set($message, $kind);
    header('Location: /admin/ai-usage.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ai_credit_back('Méthode non autorisée.');
if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) ai_credit_back('Page expirée : rechargez-la et recommencez.');
if (!ai_policy()) ai_credit_back("L'IA de votre boutique n'est pas limitée : il n'y a pas de crédit à racheter.");
$pack = saas_ai_pack((string) ($_POST['pack'] ?? ''));
if (!$pack) ai_credit_back('Pack introuvable.');
if (!saas_stripe_configured()) ai_credit_back("Le paiement n'est pas disponible sur ce serveur : contactez l'exploitant de la plateforme pour du crédit IA.");

$slug = tenant_slug();
$name = (string) (get_content()['site_name'] ?: tenant('name'));
try {
    $session = saas_stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment', 'locale' => 'fr', 'client_reference_id' => 'ai:' . $slug,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => ['currency' => 'eur', 'unit_amount' => (int) $pack['price_cents'],
                'product_data' => ['name' => 'Crédit IA — ' . $pack['name'] . ' (' . $name . ')', 'description' => 'Crédit d\'IA pour votre boutique : ' . saas_ai_estimate_text((float) $pack['credit_eur']) . '. Sans expiration, utilisé quand votre quota gratuit du mois est épuisé.']],
        ]],
        'metadata' => ['type' => 'ai_credit', 'shop' => $slug, 'pack' => $pack['key'], 'credit_eur' => number_format((float) $pack['credit_eur'], 2, '.', '')],
        'payment_intent_data' => ['metadata' => ['type' => 'ai_credit', 'shop' => $slug]],
        'success_url' => shop_abs_url('/admin/ai-credit-merci.php') . '?s={CHECKOUT_SESSION_ID}',
        'cancel_url' => shop_abs_url('/admin/ai-usage.php'),
    ]);
} catch (Throwable $e) {
    error_log('ai-credit-action: ' . $e->getMessage());
    ai_credit_back('Le paiement n\'a pas pu être ouvert : ' . $e->getMessage());
}
if (empty($session['url'])) ai_credit_back('Stripe n\'a pas renvoyé d\'adresse de paiement.');
header('Location: ' . $session['url'], true, 303);
exit;
