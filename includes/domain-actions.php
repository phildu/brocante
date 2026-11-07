<?php

// Actions du panneau « Adresse » (includes/domain-panel.php), communes à la boutique
// (admin/domaine-action.php) et à la galerie (portail/galerie-adresse-action.php).
// Les erreurs prévisibles sont levées en InvalidArgumentException / RuntimeException
// avec un message lisible ; l'appelant les affiche.

require_once __DIR__ . '/domain-panel.php';

/**
 * Exécute une action pour le propriétaire $key. $ctx :
 *   return_url     adresse absolue de la page, où Stripe ramène le payeur
 *   email          e-mail du payeur (sous-domaine)
 *   platform_host  domaine de la plateforme (refus d'un domaine qui en fait partie)
 * Rend ['flash' => [message, 'ok'|'error']] ou ['go' => adresse de paiement Stripe].
 */
function domain_panel_action(string $key, string $action, array $post, array $ctx): array
{
    $noun = domain_owner_noun($key);
    $ok = static fn (string $message): array => ['flash' => [$message, 'ok']];
    $ko = static fn (string $message): array => ['flash' => [$message, 'error']];
    $go = static function (array $result) use ($ko): array {
        $url = (string) ($result['url'] ?? '');
        return preg_match('#^https?://#', $url) ? ['go' => $url] : $ko('Stripe n’a pas renvoyé d’adresse de paiement exploitable.');
    };

    if ($action === 'search') {
        $search = domain_shop_search($key, (string) ($post['q'] ?? ''));
        $count = count(array_filter((array) $search['domains'], static fn ($c): bool => !empty($c['available'])));
        return $count > 0
            ? $ok($count . ' adresse' . ($count > 1 ? 's' : '') . ' disponible' . ($count > 1 ? 's' : '') . ' chez OVH. Choisissez celle de votre ' . $noun . '.')
            : $ko('Aucune adresse disponible trouvée pour ce nom. Essayez un autre nom.');
    }

    if ($action === 'quote') {
        $domain = (string) ($post['domain'] ?? '');
        $search = (array) (domain_shop_load($key)['search'] ?? []);
        $allowed = false;
        foreach ((array) ($search['domains'] ?? []) as $candidate) {
            if ((string) ($candidate['domain'] ?? '') === $domain && !empty($candidate['available'])) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed || time() - (int) ($search['searched_at'] ?? 0) > 86400) {
            return $ko('Cette adresse ne figure plus dans la dernière recherche (ou elle a expiré). Vérifiez à nouveau sa disponibilité.');
        }
        if (empty($post['owner_legal_age']) || empty($post['domain_terms']) || empty($post['waive_retraction'])) {
            return $ko('Confirmez l’éligibilité du titulaire, les conditions d’enregistrement et le renoncement à la rétractation.');
        }
        $owner = domain_shop_owner_validate(domain_shop_owner_from_post($post));
        $purchase = domain_shop_quote($key, $domain, $owner);
        $amount = (int) ($purchase['quote']['amount_cents'] ?? 0);
        return $ok('Devis prêt pour ' . $domain . ' : ' . number_format($amount / 100, 2, ',', ' ') . ' € TTC. Vérifiez le titulaire puis payez.');
    }

    if ($action === 'pay') {
        return $go(domain_shop_checkout($key, (string) $ctx['return_url']));
    }

    if ($action === 'cancel') {
        domain_shop_cancel($key);
        return $ok('Devis ou paiement en attente annulé. Vous pouvez choisir un autre domaine.');
    }

    if ($action === 'sub_pay' || $action === 'sub_rename') {
        $label = (string) ($post['label'] ?? '');
        $result = $action === 'sub_pay'
            ? domain_sub_checkout($key, $label, (string) $ctx['email'], (string) $ctx['return_url'])
            : domain_sub_rename($key, $label, (string) $ctx['email'], (string) $ctx['return_url']);
        if (!empty($result['free'])) return $ok('Votre ' . $noun . ' répond maintenant à ' . domain_sub_url((string) $result['label']) . '/.');
        return $go($result);
    }

    if ($action === 'sub_cancel') {
        $wasRename = domain_sub_active_label($key) !== '';
        domain_sub_cancel($key);
        return $ok($wasRename
            ? 'Changement de nom annulé : votre adresse actuelle reste en service.'
            : 'Paiement de l’adresse annulé. Vous pouvez choisir un autre nom.');
    }

    if ($action === 'custom_attach') {
        $record = domain_custom_attach($key, (string) ($post['domain'] ?? ''), (string) $ctx['platform_host']);
        return $ok('Domaine ' . $record['domain'] . ' enregistré. Ajoutez l’enregistrement TXT indiqué chez votre fournisseur, puis cliquez sur « Vérifier maintenant ».');
    }

    if ($action === 'custom_check') {
        $record = domain_custom_check($key);
        $state = (string) ($record['state'] ?? '');
        if ($state === 'dns_ok') return $ok('Domaine vérifié : la propriété et le pointage vers le serveur sont confirmés. Vous pouvez demander la mise en service.');
        if ($state === 'verified') {
            $ips = domain_custom_expected_ips();
            return $ok('Propriété du domaine confirmée. Il reste à pointer l’enregistrement A vers '
                . ($ips ? implode(' ou ', $ips) : 'l’adresse indiquée par l’exploitant') . ', puis à revérifier.');
        }
        if ($state === 'ready') return $ok('Contrôle enregistré. La demande de mise en service est chez l’exploitant de la plateforme.');
        $observed = (array) ($record['observed']['txt'] ?? []);
        return $ko('La preuve DNS n’est pas encore visible'
            . ($observed ? ' (vu : ' . implode(' ; ', array_slice($observed, 0, 3)) . ')' : '')
            . '. Vérifiez le nom et la valeur de l’enregistrement TXT, puis réessayez après la propagation.');
    }

    if ($action === 'custom_request') {
        $record = domain_custom_request_activation($key);
        return $ok('Mise en service demandée pour ' . (string) ($record['domain'] ?? 'le domaine') . '. L’exploitant de la plateforme installe le certificat HTTPS puis active l’adresse.');
    }

    if ($action === 'custom_remove') {
        domain_custom_remove($key);
        return $ok('Domaine détaché. Vous pouvez en rattacher un autre à tout moment.');
    }

    return $ko('Action inconnue.');
}
