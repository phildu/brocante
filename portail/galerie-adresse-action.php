<?php
// Actions de « Adresse de la galerie » : voir portail/galerie-adresse.php. La logique est
// commune avec la boutique (includes/domain-actions.php).
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/domain-actions.php';

$q = $limited ? '' : '?g=' . urlencode($gaSlug);
$self = '/portail/galerie-adresse.php' . $q;

function galerie_adresse_back(string $message, string $kind, string $url): never
{
    portail_flash($message, $kind);
    header('Location: ' . $url, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') galerie_adresse_back('Méthode non autorisée.', 'error', $self);
csrf_check();

$key = domain_owner_gallery_key($gaSlug);
$email = domain_owner_email($key);
if ($email === '' && filter_var((string) ($_SESSION['gallery_admin']['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
    $email = (string) $_SESSION['gallery_admin']['email'];
}

try {
    $result = domain_panel_action($key, (string) ($_POST['action'] ?? ''), $_POST, [
        'return_url' => saas_origin() . '/portail/galerie-adresse.php' . $q,
        'email' => $email,
        'platform_host' => preg_replace('#^https?://#', '', saas_origin()),
    ]);
    if (isset($result['go'])) {
        header('Location: ' . $result['go'], true, 303);
        exit;
    }
    galerie_adresse_back($result['flash'][0], $result['flash'][1], $self);
} catch (InvalidArgumentException | RuntimeException $ex) {
    galerie_adresse_back($ex->getMessage(), 'error', $self);
} catch (Throwable $ex) {
    error_log('galerie-adresse-action: ' . $ex->getMessage());
    galerie_adresse_back('L’opération n’a pas abouti : réessayez dans un instant. Si le problème persiste, contactez l’exploitant de la plateforme.', 'error', $self);
}
