<?php

// Propriétaire d'une adresse (sous-domaine, domaine existant, domaine acheté) :
// une boutique, ou une galerie commerciale.
//
// Les trois modules (domain-sub, domain-custom, domain-shop) rangent leur état
// par « clé de propriétaire ». Pour une boutique, c'est son identifiant (naty).
// Pour une galerie, c'est « g~ » + son identifiant (g~vide-grenier) : le « ~ »
// n'existe dans aucun identifiant de boutique, donc aucune collision, et les
// fichiers d'état, les conflits de noms, les métadonnées Stripe et le webhook
// restent communs. Seule la résolution diffère :
//   - une boutique est servie par tenant_from_host() / tenant_from_domain()
//     (qui ignorent les clés de galerie) ;
//   - une galerie est servie par gallery_from_host() : l'adresse en service est
//     recopiée dans son champ « domain » (domain_owner_sync()).

const DOMAIN_OWNER_GALLERY_PREFIX = 'g~';

/** Clé d'une galerie ('g~' + identifiant). */
function domain_owner_gallery_key(string $gallerySlug): string
{
    return DOMAIN_OWNER_GALLERY_PREFIX . $gallerySlug;
}

function domain_owner_is_gallery(string $key): bool
{
    return str_starts_with($key, DOMAIN_OWNER_GALLERY_PREFIX);
}

/** Identifiant de la galerie derrière une clé (chaîne vide pour une boutique). */
function domain_owner_gallery_slug(string $key): string
{
    return domain_owner_is_gallery($key) ? substr($key, strlen(DOMAIN_OWNER_GALLERY_PREFIX)) : '';
}

/** Clé valide : identifiant de boutique, ou « g~ » + identifiant de galerie. */
function domain_owner_key_valid(string $key): bool
{
    if (domain_owner_is_gallery($key)) {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', domain_owner_gallery_slug($key));
    }
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $key);
}

/** « boutique » ou « galerie » : les deux mots sont féminins, les phrases s'accordent sans changement. */
function domain_owner_noun(string $key): string
{
    return domain_owner_is_gallery($key) ? 'galerie' : 'boutique';
}

function domain_owner_gallery(string $key): ?array
{
    if (!domain_owner_is_gallery($key)) return null;
    require_once __DIR__ . '/galleries.php';
    return gallery_load(domain_owner_gallery_slug($key));
}

/** Le propriétaire existe-t-il encore (boutique créée, galerie enregistrée) ? */
function domain_owner_exists(string $key): bool
{
    if (!domain_owner_key_valid($key)) return false;
    if (domain_owner_is_gallery($key)) return domain_owner_gallery($key) !== null;
    return function_exists('tenant_dir') && is_file(tenant_dir($key) . '/tenant.php');
}

/** Nom affiché (nom de la boutique ou de la galerie), à défaut la clé. */
function domain_owner_name(string $key): string
{
    try {
        if (domain_owner_is_gallery($key)) return (string) (domain_owner_gallery($key)['name'] ?? domain_owner_gallery_slug($key));
        if (domain_owner_exists($key) && function_exists('tenant_load')) return (string) (tenant_load($key)['name'] ?? $key);
    } catch (Throwable $ex) {
        error_log('domain_owner_name: ' . $ex->getMessage());
    }
    return $key;
}

/** E-mail à prévenir (administrateur de la boutique, premier administrateur de la galerie) ; chaîne vide si aucun. */
function domain_owner_email(string $key): string
{
    try {
        if (domain_owner_is_gallery($key)) {
            foreach ((array) (domain_owner_gallery($key)['admins'] ?? []) as $admin) {
                $email = strtolower(trim((string) ($admin['email'] ?? '')));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
            }
            return '';
        }
        if (domain_owner_exists($key) && function_exists('tenant_load')) {
            $email = strtolower(trim((string) (tenant_load($key)['admin_email'] ?? '')));
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        }
    } catch (Throwable $ex) {
        error_log('domain_owner_email: ' . $ex->getMessage());
    }
    return '';
}

/** Adresse historique de la plateforme pour ce propriétaire (sans barre finale oubliée) : /<boutique>/ ou /galerie/<id>/. */
function domain_owner_legacy_path(string $key): string
{
    return domain_owner_is_gallery($key) ? '/galerie/' . domain_owner_gallery_slug($key) . '/' : '/' . $key . '/';
}

/**
 * Adresse à servir pour une galerie d'après l'état enregistré : le domaine
 * rattaché et en service d'abord, sinon le sous-domaine en service. Chaîne vide
 * s'il n'y en a aucune. Boutiques : toujours vide (la résolution passe ailleurs).
 */
function domain_owner_effective_host(string $key): string
{
    if (!domain_owner_is_gallery($key)) return '';
    $custom = function_exists('domain_custom_active_host') ? domain_custom_active_host($key) : '';
    if ($custom !== '') return strtolower($custom);
    $sub = function_exists('domain_sub_active_host') ? domain_sub_active_host($key) : '';
    return strtolower($sub);
}

/**
 * Recopie l'adresse en service dans le champ « domain » de la galerie, seule
 * source de gallery_from_host(). $removedHost : adresse qui vient d'être
 * retirée ; elle n'est effacée de la galerie que si c'est bien elle qui y figure
 * (un domaine saisi à la main par l'exploitant reste intact). Sans effet pour
 * une boutique. Rend true si la galerie a été modifiée.
 */
function domain_owner_sync(string $key, string $removedHost = ''): bool
{
    if (!domain_owner_is_gallery($key)) return false;
    $gallery = domain_owner_gallery($key);
    if (!$gallery) return false;
    $current = (string) ($gallery['domain'] ?? '');
    $wanted = domain_owner_effective_host($key);
    if ($wanted !== '') {
        if ($current === $wanted) return false;
        if (gallery_domain_owner($wanted, (string) $gallery['slug']) !== null) {
            error_log('domain_owner_sync: ' . $wanted . ' est déjà attribué à une autre galerie.');
            return false;
        }
        $gallery['domain'] = $wanted;
    } elseif ($removedHost !== '' && $current === strtolower($removedHost)) {
        $gallery['domain'] = '';
    } else {
        return false;
    }
    if (!gallery_save($gallery)) {
        error_log('domain_owner_sync: impossible d’enregistrer la galerie ' . $gallery['slug']);
        return false;
    }
    return true;
}

/** Début d'un paramètre de requête : « ? » ou « & » selon que l'adresse porte déjà une requête. */
function domain_return_url(string $returnUrl): string
{
    return $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 's={CHECKOUT_SESSION_ID}';
}
