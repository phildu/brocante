<?php
// Actions de la page « Nom de domaine » de la boutique : rechercher, préparer
// un devis, ouvrir le paiement Stripe, annuler un devis en attente, et choisir
// une adresse en sous-domaine (nom.domaine) à prix fixe.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/domain-shop.php';
require_once __DIR__ . '/../includes/domain-sub.php';
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

    if ($action === 'sub_pay') {
        $email = (string) tenant('admin_email', '');
        $result = domain_sub_checkout($slug, (string) ($_POST['label'] ?? ''), $email, shop_abs_url('/admin/domaine.php'));
        if (!empty($result['free'])) {
            domain_back('Votre boutique répond maintenant à ' . domain_sub_url((string) $result['label']) . '/.', 'ok');
        }
        $url = (string) ($result['url'] ?? '');
        if (!preg_match('#^https?://#', $url)) domain_back('Stripe n’a pas renvoyé d’adresse de paiement exploitable.');
        header('Location: ' . $url, true, 303);
        exit;
    }

    if ($action === 'sub_rename') {
        $email = (string) tenant('admin_email', '');
        $result = domain_sub_rename($slug, (string) ($_POST['label'] ?? ''), $email, shop_abs_url('/admin/domaine.php'));
        if (!empty($result['free'])) {
            domain_back('Votre boutique répond maintenant à ' . domain_sub_url((string) $result['label']) . '/.', 'ok');
        }
        $url = (string) ($result['url'] ?? '');
        if (!preg_match('#^https?://#', $url)) domain_back('Stripe n’a pas renvoyé d’adresse de paiement exploitable.');
        header('Location: ' . $url, true, 303);
        exit;
    }

    if ($action === 'sub_cancel') {
        $wasRename = domain_sub_active_label($slug) !== '';
        domain_sub_cancel($slug);
        domain_back($wasRename
            ? 'Changement de nom annulé : votre adresse actuelle reste en service.'
            : 'Paiement de l’adresse annulé. Vous pouvez choisir un autre nom.', 'ok');
    }

    if ($action === 'custom_attach') {
        $record = domain_custom_attach($slug, (string) ($_POST['domain'] ?? ''), tenant_base_host());
        domain_back('Domaine ' . $record['domain'] . ' enregistré. Ajoutez l’enregistrement TXT indiqué chez votre fournisseur, puis cliquez sur « Vérifier maintenant ».', 'ok');
    }

    if ($action === 'custom_check') {
        $record = domain_custom_check($slug);
        $state = (string) ($record['state'] ?? '');
        if ($state === 'dns_ok') {
            domain_back('Domaine vérifié : la propriété et le pointage vers le serveur sont confirmés. Vous pouvez demander la mise en service.', 'ok');
        }
        if ($state === 'verified') {
            $ips = domain_custom_expected_ips();
            domain_back('Propriété du domaine confirmée. Il reste à pointer l’enregistrement A vers '
                . ($ips ? implode(' ou ', $ips) : 'l’adresse indiquée par l’exploitant') . ', puis à revérifier.', 'ok');
        }
        if ($state === 'ready') {
            domain_back('Contrôle enregistré. La demande de mise en service est chez l’exploitant de la plateforme.', 'ok');
        }
        $observed = (array) ($record['observed']['txt'] ?? []);
        domain_back('La preuve DNS n’est pas encore visible'
            . ($observed ? ' (vu : ' . implode(' ; ', array_slice($observed, 0, 3)) . ')' : '')
            . '. Vérifiez le nom et la valeur de l’enregistrement TXT, puis réessayez après la propagation.', 'error');
    }

    if ($action === 'custom_request') {
        $record = domain_custom_request_activation($slug);
        domain_back('Mise en service demandée pour ' . (string) ($record['domain'] ?? 'le domaine') . '. L’exploitant de la plateforme installe le certificat HTTPS puis active l’adresse.', 'ok');
    }

    if ($action === 'custom_remove') {
        domain_custom_remove($slug);
        domain_back('Domaine détaché. Vous pouvez en rattacher un autre à tout moment.', 'ok');
    }

    domain_back('Action inconnue.');
} catch (InvalidArgumentException | RuntimeException $ex) {
    domain_back($ex->getMessage());
} catch (Throwable $ex) {
    error_log('domaine-action: ' . $ex->getMessage());
    domain_back('L’opération n’a pas abouti : réessayez dans un instant. Si le problème persiste, contactez l’exploitant de la plateforme.');
}
