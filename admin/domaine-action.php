<?php
// Actions de la page « Nom de domaine » de la boutique : rechercher, préparer
// un devis, ouvrir le paiement Stripe, annuler un devis en attente, choisir une
// adresse en sous-domaine (nom.domaine) à prix fixe, rattacher un domaine existant.
// La logique est commune avec la galerie : includes/domain-actions.php.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/domain-actions.php';
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

try {
    $result = domain_panel_action($slug, (string) ($_POST['action'] ?? ''), $_POST, [
        'return_url' => shop_abs_url('/admin/domaine.php'),
        'email' => (string) tenant('admin_email', ''),
        'platform_host' => tenant_base_host(),
    ]);
    if (isset($result['go'])) {
        header('Location: ' . $result['go'], true, 303);
        exit;
    }
    domain_back($result['flash'][0], $result['flash'][1]);
} catch (InvalidArgumentException | RuntimeException $ex) {
    domain_back($ex->getMessage());
} catch (Throwable $ex) {
    error_log('domaine-action: ' . $ex->getMessage());
    domain_back('L’opération n’a pas abouti : réessayez dans un instant. Si le problème persiste, contactez l’exploitant de la plateforme.');
}
