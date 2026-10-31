<?php
// Actions de la page « Nom de domaine » de la boutique : rechercher, préparer
// un devis, ouvrir le paiement Stripe, annuler un devis en attente.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/domain-shop.php';
require_admin();

function domain_back(string $message, string $kind = 'error', ?string $url = null): never
{
    flash_set($message, $kind);
    header('Location: ' . ($url ?? '/admin/domaine.php'), true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') domain_back('Méthode non autorisée.');
if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) domain_back('Page expirée : rechargez-la et recommencez.');

$slug = tenant_slug();
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'search') {
        $search = domain_shop_search($slug, (string) ($_POST['q'] ?? ''));
        $count = count(array_filter((array) $search['domains'], static fn ($c): bool => !empty($c['available'])));
        domain_back($count > 0
            ? $count . ' adresse' . ($count > 1 ? 's' : '') . ' disponible' . ($count > 1 ? 's' : '') . ' chez OVH. Choisissez celle de votre boutique.'
            : 'Aucune adresse disponible trouvée pour ce nom. Essayez un autre nom.', $count > 0 ? 'ok' : 'error');
    }

    if ($action === 'quote') {
        $domain = (string) ($_POST['domain'] ?? '');
        $search = (array) (domain_shop_load($slug)['search'] ?? []);
        $allowed = false;
        foreach ((array) ($search['domains'] ?? []) as $candidate) {
            if ((string) ($candidate['domain'] ?? '') === $domain && !empty($candidate['available'])) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed || time() - (int) ($search['searched_at'] ?? 0) > 86400) {
            domain_back('Cette adresse ne figure plus dans la dernière recherche (ou elle a expiré). Vérifiez à nouveau sa disponibilité.');
        }
        if (empty($_POST['owner_legal_age']) || empty($_POST['domain_terms']) || empty($_POST['waive_retraction'])) {
            domain_back('Confirmez l’éligibilité du titulaire, les conditions d’enregistrement et le renoncement à la rétractation.');
        }
        $owner = domain_shop_owner_validate(domain_shop_owner_from_post($_POST));
        $purchase = domain_shop_quote($slug, $domain, $owner);
        $amount = (int) ($purchase['quote']['amount_cents'] ?? 0);
        domain_back('Devis prêt pour ' . $domain . ' : ' . number_format($amount / 100, 2, ',', ' ') . ' € TTC. Vérifiez le titulaire puis payez.', 'ok');
    }

    if ($action === 'pay') {
        $result = domain_shop_checkout($slug);
        $url = (string) ($result['url'] ?? '');
        if (!preg_match('#^https?://#', $url)) domain_back('Stripe n’a pas renvoyé d’adresse de paiement exploitable.');
        header('Location: ' . $url, true, 303);
        exit;
    }

    if ($action === 'cancel') {
        domain_shop_cancel($slug);
        domain_back('Devis ou paiement en attente annulé. Vous pouvez choisir un autre domaine.', 'ok');
    }

    domain_back('Action inconnue.');
} catch (InvalidArgumentException | RuntimeException $ex) {
    domain_back($ex->getMessage());
} catch (Throwable $ex) {
    error_log('domaine-action: ' . $ex->getMessage());
    domain_back('L’opération n’a pas abouti : réessayez dans un instant. Si le problème persiste, contactez l’exploitant de la plateforme.');
}
