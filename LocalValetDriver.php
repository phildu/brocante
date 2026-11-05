<?php

use Valet\Drivers\BasicValetDriver;

/**
 * Pilote Herd / Valet de la plateforme (développement local, jamais chargé par Apache en ligne).
 *
 * En ligne (Apache / OVH), le .htaccess réécrit les adresses et protège les dossiers sensibles. Herd tourne sous nginx et ne lit pas
 * le .htaccess : sans ce pilote, /galerie/<identifiant>/ et /<commerce>/… ne mènent nulle part, et la base SQLite, les clés
 * (.secrets/) et la configuration des commerces seraient téléchargeables en clair. Ce pilote reprend les règles du .htaccess :
 *
 *   /                          → la page d'accueil de la plateforme (sauf sur un sous-domaine de commerce ou sur le domaine
 *                                propre qu'un tenant.php déclare en site_url, ex. brocante.arrimage.com : sa boutique) ;
 *                                /annuaire/, /inscription/ et /portail/ sont des dossiers servis tels quels
 *   /galerie/, /galerie/<id>/  → galerie/index.php (galeries commerciales)
 *   /galerie/<id>/admin        → galerie/admin.php (connexion à l'administration de cette galerie)
 *   /<commerce>/…              → la boutique tenants/<commerce>/ servie sous le domaine du portail
 *   /api/…, /<commerce>/api/…  → plugin-agent.php (agents externes des plugins, authentifiés par le plugin)
 *   data/, tenants/, includes/, views/, var/, scripts/, db-backup/, fichiers cachés, *.db, *.sql, *.log, *.bak → 403
 *
 * Même logique que router.php (serveur intégré : php -S localhost:8000 router.php).
 */
class LocalValetDriver extends BasicValetDriver
{
    public function serves(string $sitePath, string $siteName, string $uri): bool
    {
        return true;
    }

    /** Chemins qui ne doivent jamais sortir (comme le .htaccess : bases, configuration, clés, journaux). */
    private function isSensitive(string $uri): bool
    {
        $uri = rawurldecode($uri);
        // Plugins : rien de packs/ n'est servi directement, sauf packs/<id>/assets/ (comme packs/.htaccess), y compris sous /<commerce>/.
        $packs = preg_replace('#^/[a-z0-9][a-z0-9_-]*(?=/packs(?:/|$))#', '', $uri);
        return (bool) (
            preg_match('#^/(?:data|tenants|scripts|includes|views|var|db-backup)(?:/|$)#', $uri)
            || preg_match('#(?:^|/)\.(?!well-known)#', $uri)
            || preg_match('#\.(?:db|sqlite|sqlite3|sql|log|bak)(?:$|[./-])#i', $uri)
            || preg_match('#(?:^|/)uploads/.*\.(?:php\d?|phtml|phar|pl|py|cgi|sh)$#i', $uri) // fichiers envoyés : jamais exécutés
            || (preg_match('#^/packs(?:/|$)#', $packs)
                && !preg_match('#^/packs/[a-z0-9][a-z0-9-]*/assets/(?:[a-z0-9_-]+/)*[a-z0-9_.-]+\.(?:js|css|html|svg|png|jpe?g|webp|gif|woff2?)$#', $packs))
        );
    }

    /** Commerce désigné par le premier segment de l'adresse (/naty/…), '' sinon ; un vrai dossier ou fichier de même nom a priorité. */
    private function tenantInPath(string $sitePath, string $uri): string
    {
        if (!preg_match('#^/([a-z0-9][a-z0-9_-]*)(?:/|$)#', $uri, $m)) return '';
        return is_file($sitePath . '/tenants/' . $m[1] . '/tenant.php') && !file_exists($sitePath . '/' . $m[1]) ? $m[1] : '';
    }

    /** Le domaine demandé porte-t-il un commerce (sous-domaine naty.brocenstock.test ou site_url d'un tenant.php) ? */
    private function hostHasTenant(string $sitePath): bool
    {
        require_once $sitePath . '/includes/tenant.php';
        return tenant_from_host() !== '' || tenant_from_domain() !== '';
    }

    public function isStaticFile(string $sitePath, string $siteName, string $uri)
    {
        if ($this->isSensitive($uri)) {
            return false; // traité (403) par frontControllerPath
        }
        // /<commerce>/assets/x.css → fichier partagé à la racine (assets/, uploads/ ne sont pas préfixés par commerce).
        $tenant = $this->tenantInPath($sitePath, $uri);
        if ($tenant !== '') {
            $rest = substr($uri, strlen($tenant) + 1);
            if ($rest !== '' && $rest !== '/' && !str_ends_with($rest, '.php')) {
                $file = $sitePath . $rest;
                if ($this->isActualFile($file)) return $file;
            }
            return false;
        }
        return parent::isStaticFile($sitePath, $siteName, $uri);
    }

    public function frontControllerPath(string $sitePath, string $siteName, string $uri): ?string
    {
        if ($this->isSensitive($uri)) {
            http_response_code(403);
            exit('403 — accès refusé.');
        }
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');

        // Racine du domaine : la page d'accueil de la plateforme (déploiement portail), sauf sur le domaine d'un commerce.
        if ($uri === '/' && is_file($sitePath . '/portail/index.php') && !$this->hostHasTenant($sitePath) && !getenv('TENANT')) {
            return $this->run($sitePath, '/accueil/index.php', $query);
        }

        // Connexion à l'administration d'une galerie : /galerie/<identifiant>/admin
        if (preg_match('#^/galerie/([A-Za-z0-9][A-Za-z0-9-]*)/admin/?$#', $uri, $m)) {
            $_GET['g'] = strtolower($m[1]);
            return $this->run($sitePath, '/galerie/admin.php', 'g=' . $_GET['g'] . ($query !== '' ? '&' . $query : ''));
        }

        // Galeries commerciales : /galerie/ et /galerie/<identifiant>/
        if (preg_match('#^/galerie(?:/([A-Za-z0-9][A-Za-z0-9-]*))?/?$#', $uri, $m)) {
            $_GET['g'] = strtolower($m[1] ?? '');
            return $this->run($sitePath, '/galerie/index.php', $_GET['g'] !== '' ? 'g=' . $_GET['g'] . ($query !== '' ? '&' . $query : '') : $query);
        }

        // Agents externes des plugins : /api/… et /<commerce>/api/… → plugin-agent.php (même règle que le .htaccess et router.php).
        if (preg_match('#^(?:/([a-z0-9][a-z0-9_-]*))?/api/([a-z0-9/-]+)$#', $uri, $m)
            && ($m[1] === '' || $this->tenantInPath($sitePath, '/' . $m[1] . '/') !== '')) {
            $_GET['agent_path'] = $m[2];
            return $this->run($sitePath, '/plugin-agent.php', 'agent_path=' . $m[2] . ($query !== '' ? '&' . $query : ''), $uri);
        }

        // Commerce sous le domaine du portail : /<commerce>/… (même règle que le .htaccess et router.php).
        $tenant = $this->tenantInPath($sitePath, $uri);
        if ($tenant !== '') {
            $rest = substr($uri, strlen($tenant) + 1);
            if ($rest === '') {
                header('Location: /' . $tenant . '/' . ($query !== '' ? '?' . $query : ''), true, 301);
                exit;
            }
            $target = $rest === '/' ? '/index.php' : $rest;
            if (str_ends_with($target, '.php') && is_file($sitePath . $target)) {
                return $this->run($sitePath, $target, $query, $uri);
            }
            return null; // introuvable : 404 de Herd
        }

        return parent::frontControllerPath($sitePath, $siteName, $uri);
    }

    /** Prépare l'environnement PHP pour servir $script (relatif à la racine) et renvoie son chemin. */
    private function run(string $sitePath, string $script, string $query, ?string $requestUri = null): string
    {
        $_SERVER['SCRIPT_FILENAME'] = $sitePath . $script;
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['PHP_SELF'] = $script;
        $_SERVER['DOCUMENT_ROOT'] = $sitePath;
        $_SERVER['QUERY_STRING'] = $query;
        if ($requestUri !== null) $_SERVER['REQUEST_URI'] = $requestUri . ($query !== '' ? '?' . $query : '');
        return $sitePath . $script;
    }
}
