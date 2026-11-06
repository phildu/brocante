<?php
// Actions de « Noms de domaine » (portail) : rechercher, préparer un devis,
// ouvrir le paiement Stripe, annuler, archiver une commande terminée.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/domain-shop.php';
require_once PORTAIL_ROOT . '/includes/domain-custom.php';

if (defined('GA_LIMITED')) { header('Location: /portail/galerie-tableau.php'); exit; }

$slug = 'plateforme';
$self = '/portail/domaines.php';

function domain_portal_back(string $message, string $kind = 'error'): never
{
    portail_flash($message, $kind);
    header('Location: /portail/domaines.php', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') domain_portal_back('Méthode non autorisée.');
csrf_check();

$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'search') {
        $search = domain_shop_search($slug, (string) ($_POST['q'] ?? ''));
        $count = count(array_filter((array) $search['domains'], static fn ($c): bool => !empty($c['available'])));
        domain_portal_back(
            $count > 0
                ? $count . ' adresse' . ($count > 1 ? 's' : '') . ' disponible' . ($count > 1 ? 's' : '') . ' chez OVH. Choisissez celle de la plateforme.'
                : 'Aucune adresse disponible trouvée pour ce nom. Essayez un autre nom.',
            $count > 0 ? 'ok' : 'error'
        );
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
            domain_portal_back('Cette adresse ne figure plus dans la dernière recherche (ou elle a expiré). Vérifiez à nouveau sa disponibilité.');
        }
        if (empty($_POST['owner_legal_age']) || empty($_POST['domain_terms']) || empty($_POST['waive_retraction'])) {
            domain_portal_back('Confirmez l’éligibilité du titulaire, les conditions d’enregistrement et le renoncement à la rétractation.');
        }
        $owner = domain_shop_owner_validate(domain_shop_owner_from_post($_POST));
        $purchase = domain_shop_quote($slug, $domain, $owner);
        $amount = (int) ($purchase['quote']['amount_cents'] ?? 0);
        domain_portal_back('Devis prêt pour ' . $domain . ' : ' . number_format($amount / 100, 2, ',', ' ') . ' € TTC. Vérifiez le titulaire puis payez.', 'ok');
    }

    if ($action === 'pay') {
        $result = domain_shop_checkout($slug, saas_origin() . $self);
        $url = (string) ($result['url'] ?? '');
        if (!preg_match('#^https?://#', $url)) domain_portal_back('Stripe n’a pas renvoyé d’adresse de paiement exploitable.');
        header('Location: ' . $url, true, 303);
        exit;
    }

    if ($action === 'cancel') {
        domain_shop_cancel($slug);
        domain_portal_back('Devis, paiement en attente ou paiement de test effacé. Vous pouvez choisir un autre domaine.', 'ok');
    }

    if ($action === 'archive') {
        domain_shop_archive_purchase($slug);
        domain_portal_back('Commande archivée : vous pouvez préparer un autre domaine.', 'ok');
    }

    if ($action === 'save_custom_ip') {
        $ip = trim((string) ($_POST['domain_ip'] ?? ''));
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            domain_portal_back('Indiquez une adresse IP valide (par exemple 213.186.33.3), ou laissez le champ vide.');
        }
        $cfg = saas_config();
        $cfg['domain_ip'] = $ip;
        if (!saas_config_save($cfg)) domain_portal_back('Impossible d’enregistrer l’adresse IP.');
        domain_portal_back($ip !== '' ? 'Adresse IP du serveur enregistrée : les consignes DNS des boutiques l’utilisent.' : 'Adresse IP effacée : l’adresse du serveur qui répond sera proposée.', 'ok');
    }

    if ($action === 'custom_activate' || $action === 'custom_remove') {
        $shop = strtolower(trim((string) ($_POST['shop'] ?? '')));
        if (!domain_custom_slug_valid($shop) || !is_file(tenant_dir($shop) . '/tenant.php')) {
            domain_portal_back('Boutique inconnue.');
        }
        if ($action === 'custom_activate') {
            $record = domain_custom_activate($shop);
            domain_portal_back('Adresse ' . (string) ($record['domain'] ?? '') . ' mise en service pour ' . $shop . ' : la boutique répond maintenant sur ce domaine.', 'ok');
        }
        domain_custom_remove($shop);
        domain_portal_back('Adresse détachée de ' . $shop . '. Pensez à retirer l’alias d’hébergement et le certificat si le domaine ne doit plus arriver ici.', 'ok');
    }

    domain_portal_back('Action inconnue.');
} catch (InvalidArgumentException | RuntimeException $ex) {
    domain_portal_back($ex->getMessage());
} catch (Throwable $ex) {
    error_log('portail domaines-action: ' . $ex->getMessage());
    domain_portal_back('L’opération n’a pas abouti : réessayez dans un instant.');
}
