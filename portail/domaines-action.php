<?php
// Actions de « Noms de domaine » (portail) : rechercher, préparer un devis,
// ouvrir le paiement Stripe, annuler, archiver une commande terminée.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/domain-shop.php';
require_once PORTAIL_ROOT . '/includes/domain-custom.php';
require_once PORTAIL_ROOT . '/includes/domain-sub.php';

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

    if ($action === 'save_subdomain_price') {
        $raw = str_replace(',', '.', trim((string) ($_POST['subdomain_price'] ?? '')));
        $euros = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($raw === '' || $euros === false || $euros < 0 || $euros > 1000) {
            domain_portal_back('Indiquez un prix entre 0 et 1000 € (0 = adresse offerte).');
        }
        $cents = (int) round($euros * 100);
        $rawRename = str_replace(',', '.', trim((string) ($_POST['subdomain_rename_price'] ?? '')));
        $eurosRename = filter_var($rawRename, FILTER_VALIDATE_FLOAT);
        if ($rawRename === '' || $eurosRename === false || $eurosRename < 0 || $eurosRename > 1000) {
            domain_portal_back('Indiquez un prix de changement de nom entre 0 et 1000 € (0 = changement offert).');
        }
        $renameCents = (int) round($eurosRename * 100);
        if (($cents > 0 && $cents < 50) || ($renameCents > 0 && $renameCents < 50)) {
            domain_portal_back('Stripe n’accepte pas de paiement inférieur à 0,50 €.');
        }
        $cfg = saas_config();
        $cfg['subdomain_price_cents'] = $cents;
        $cfg['subdomain_rename_price_cents'] = $renameCents;
        if (!saas_config_save($cfg)) domain_portal_back('Impossible d’enregistrer les prix.');
        domain_portal_back('Prix enregistrés : adresse en sous-domaine '
            . ($cents > 0 ? number_format($cents / 100, 2, ',', ' ') . ' €' : 'offerte')
            . ', changement de nom ' . ($renameCents > 0 ? number_format($renameCents / 100, 2, ',', ' ') . ' €' : 'offert') . '.', 'ok');
    }

    if ($action === 'sub_remove') {
        $shop = (string) ($_POST['shop'] ?? '');
        if (!domain_sub_slug_valid($shop)) domain_portal_back('Identifiant invalide.');
        domain_sub_remove($shop);
        domain_portal_back('Adresse en sous-domaine retirée de ' . (domain_owner_is_gallery($shop) ? 'la galerie ' . domain_owner_gallery_slug($shop) : $shop) . '.', 'ok');
    }

    if ($action === 'save_hosting') {
        $service = strtolower(trim((string) ($_POST['hosting_service'] ?? '')));
        $path = trim(trim((string) ($_POST['hosting_path'] ?? '')), '/');
        $max = trim((string) ($_POST['hosting_max_domains'] ?? ''));
        if ($service !== '' && !preg_match('/^[a-z0-9][a-z0-9.-]{2,79}$/', $service)) {
            domain_portal_back('Nom de service d’hébergement invalide (par exemple arrimage.com).');
        }
        if ($path !== '' && (!preg_match('#^[A-Za-z0-9._/-]{1,120}$#', $path) || str_contains($path, '..'))) {
            domain_portal_back('Dossier racine invalide (par exemple www/brocs).');
        }
        if (($service === '') !== ($path === '')) {
            domain_portal_back('Renseignez le service d’hébergement et le dossier racine ensemble, ou laissez les deux vides pour garder le rattachement manuel.');
        }
        if ($max !== '' && (!ctype_digit($max) || (int) $max > 1000)) {
            domain_portal_back('Le plafond de domaines doit être un nombre entre 0 et 1000.');
        }
        $cfg = saas_config();
        $cfg['hosting_service'] = $service;
        $cfg['hosting_path'] = $path;
        $cfg['hosting_max_domains'] = (int) $max;
        if (!saas_config_save($cfg)) domain_portal_back('Impossible d’enregistrer les réglages d’hébergement.');
        domain_portal_back($service !== ''
            ? 'Rattachement automatique réglé : les domaines demandés seront déclarés sur ' . $service . ' (dossier ' . $path . ').'
            : 'Rattachement automatique désactivé : les alias d’hébergement restent à créer à la main.', 'ok');
    }

    if ($action === 'custom_activate' || $action === 'custom_remove' || $action === 'custom_attach_hosting') {
        $shop = strtolower(trim((string) ($_POST['shop'] ?? '')));
        if (!domain_custom_slug_valid($shop) || !domain_owner_exists($shop)) {
            domain_portal_back('Boutique ou galerie inconnue.');
        }
        $shopLabel = domain_owner_is_gallery($shop) ? 'la galerie ' . domain_owner_gallery_slug($shop) : $shop;
        if ($action === 'custom_attach_hosting') {
            try {
                $record = domain_custom_hosting_attach($shop);
            } catch (InvalidArgumentException | RuntimeException $ex) {
                domain_custom_hosting_fail($shop, $ex->getMessage());
                throw $ex;
            }
            $names = implode(', ', (array) ($record['hosting']['domains'] ?? []));
            domain_portal_back('Alias d’hébergement créé chez OVH pour ' . $names . '. Le certificat HTTPS est émis en quelques minutes : vérifiez https://' . (string) ($record['domain'] ?? '') . '/ avant d’activer.'
                . (!empty($record['hosting']['www_error']) ? ' Le « www » n’a pas pu être ajouté : ' . (string) $record['hosting']['www_error'] : ''), 'ok');
        }
        if ($action === 'custom_activate') {
            $record = domain_custom_activate($shop);
            domain_portal_back('Adresse ' . (string) ($record['domain'] ?? '') . ' mise en service pour ' . $shopLabel . ' : la ' . domain_owner_noun($shop) . ' répond maintenant sur ce domaine.', 'ok');
        }
        $warning = domain_custom_remove($shop);
        if ($warning !== '') domain_portal_back($warning);
        domain_portal_back('Adresse détachée de ' . $shopLabel . (domain_custom_hosting_settings() ? ' ; les alias d’hébergement créés automatiquement ont été retirés chez OVH.' : '. Pensez à retirer l’alias d’hébergement et le certificat si le domaine ne doit plus arriver ici.'), 'ok');
    }

    domain_portal_back('Action inconnue.');
} catch (InvalidArgumentException | RuntimeException $ex) {
    domain_portal_back($ex->getMessage());
} catch (Throwable $ex) {
    error_log('portail domaines-action: ' . $ex->getMessage());
    domain_portal_back('L’opération n’a pas abouti : réessayez dans un instant.');
}
