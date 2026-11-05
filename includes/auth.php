<?php
/**
 * Comptes collaborateurs, rôles et droits fins du back-office.
 *
 * Modèle
 *  - Un DROIT est une clé « niveau.ressource.action », ex. « boutique.produits.supprimer ».
 *    Trois niveaux : portail (administration générale), galerie (visuels),
 *    boutique (catalogue, commandes, livraison). Le catalogue des droits
 *    (auth_catalog) est la source unique : l'écran des rôles, le menu, les
 *    contrôles côté serveur et les tests en dérivent tous.
 *  - Un RÔLE est un ensemble nommé de droits, créé et modifié par le
 *    propriétaire (ou par un collaborateur autorisé). Un collaborateur a UN rôle.
 *  - Le rôle « Propriétaire » (is_owner) a tous les droits, y compris ceux
 *    ajoutés plus tard, et n'est pas modifiable.
 *  - Le mot de passe unique historique (ADMIN_PASSWORD) reste valable : il
 *    ouvre une session « propriétaire » sans compte nommé. Rien à migrer.
 *
 * Principes de sécurité
 *  - Refus par défaut : un droit inconnu lève une exception (une faute de
 *    frappe ne doit jamais ouvrir un accès au propriétaire ni le fermer en
 *    silence), une action non répertoriée est refusée.
 *  - Les droits sont relus en base à CHAQUE requête : modifier un rôle ou
 *    désactiver un compte prend effet immédiatement, sans attendre la
 *    déconnexion de la personne.
 *  - Pas d'escalade : on ne peut accorder (à un rôle ou via l'affectation
 *    d'un rôle) que des droits qu'on possède soi-même, ni modifier un compte
 *    ou un rôle qui nous dépasse, ni toucher à son propre rôle.
 *  - Les masquages de boutons côté page ne sont que du confort : c'est le
 *    contrôle serveur (require_permission / auth_require_action) qui protège.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/csrf.php';

const AUTH_LOGIN_MAX_ATTEMPTS = 8;
const AUTH_LOGIN_WINDOW_SECONDS = 900;
const AUTH_PASSWORD_MIN_LENGTH = 10;

/* ====================================================================== */
/* Catalogue des droits                                                    */
/* ====================================================================== */

/** Libellés des actions « standard » (colonnes de la matrice des rôles). */
function auth_standard_actions(): array
{
    return [
        'lire' => 'Consulter',
        'creer' => 'Créer',
        'modifier' => 'Modifier',
        'supprimer' => 'Supprimer',
    ];
}

/**
 * Niveaux → ressources → actions (clé => libellé précis).
 * « lire » est implicite pour toute ressource dont un autre droit est accordé
 * (voir auth_normalize_permissions) : on ne modifie pas ce qu'on ne peut pas voir.
 */
function auth_catalog(): array
{
    return [
        'portail' => [
            'label' => 'Portail',
            'hint' => 'Administration générale du site : tableau de bord, réglages, équipe, modules d\'événement.',
            'resources' => [
                'tableau_bord' => [
                    'label' => 'Tableau de bord',
                    'hint' => 'Fréquentation, chiffre d\'affaires, frais et rentabilité (données financières).',
                    'actions' => [
                        'lire' => 'Consulter le tableau de bord',
                        'creer' => 'Ajouter des frais ou une journée de fréquentation',
                        'modifier' => 'Modifier les frais, la fréquentation et les objectifs',
                        'supprimer' => 'Supprimer des frais ou des journées',
                    ],
                ],
                'reglages' => [
                    'label' => 'Réglages du site',
                    'hint' => 'Identité, page d\'accueil, histoire, coordonnées, libellés de livraison.',
                    'actions' => [
                        'lire' => 'Consulter les réglages',
                        'modifier' => 'Modifier les textes et photos du site',
                    ],
                ],
                'cles_api' => [
                    'label' => 'Clés API',
                    'hint' => 'Stripe (paiement), Gemini et fal.ai (IA). Très sensible : à réserver aux responsables.',
                    'actions' => [
                        'lire' => 'Voir l\'état des clés',
                        'modifier' => 'Enregistrer / remplacer les clés',
                    ],
                ],
                'utilisateurs' => [
                    'label' => 'Collaborateurs',
                    'hint' => 'Comptes de l\'équipe et affectation des rôles.',
                    'actions' => [
                        'lire' => 'Voir la liste des collaborateurs',
                        'creer' => 'Inviter un collaborateur',
                        'modifier' => 'Modifier, désactiver, réinitialiser un mot de passe',
                        'supprimer' => 'Supprimer un collaborateur',
                    ],
                ],
                'roles' => [
                    'label' => 'Rôles et droits',
                    'hint' => 'Définir ce que chaque rôle peut faire. On ne peut accorder que les droits qu\'on possède.',
                    'actions' => [
                        'lire' => 'Voir les rôles',
                        'creer' => 'Créer un rôle',
                        'modifier' => 'Modifier les droits d\'un rôle',
                        'supprimer' => 'Supprimer un rôle',
                    ],
                ],
                'buvette' => [
                    'label' => 'Module Buvette',
                    'hint' => 'Carte, recettes, fournitures, planning des bénévoles, check-list.',
                    'actions' => [
                        'lire' => 'Consulter la buvette',
                        'creer' => 'Ajouter (articles, créneaux, bénévoles, événements…)',
                        'modifier' => 'Modifier (y compris cocher la check-list, code bénévoles)',
                        'supprimer' => 'Supprimer',
                    ],
                ],
                'invendus' => [
                    'label' => 'Module Invendus & dons',
                    'hint' => 'Éditions, propositions de dons, tournées, structures bénéficiaires.',
                    'actions' => [
                        'lire' => 'Consulter les invendus et dons',
                        'creer' => 'Créer (éditions, propositions, tournées, structures, dons)',
                        'modifier' => 'Modifier et faire avancer les statuts',
                        'supprimer' => 'Supprimer',
                        'exporter' => 'Exporter et imprimer (bordereaux, CSV)',
                    ],
                ],
            ],
        ],
        'galerie' => [
            'label' => 'Galerie',
            'hint' => 'Tous les visuels : photos des pièces, médiathèque, diaporamas, bandeaux.',
            'resources' => [
                'photos_produits' => [
                    'label' => 'Photos des pièces',
                    'hint' => 'Galerie photo de chaque pièce (ajout, ordre, recadrage, masquage).',
                    'actions' => [
                        'lire' => 'Consulter les galeries',
                        'creer' => 'Ajouter des photos',
                        'modifier' => 'Renommer, remplacer, réordonner, recadrer, masquer',
                        'supprimer' => 'Retirer des photos',
                        'ia' => 'Générer avec l\'IA (consomme des crédits payants)',
                    ],
                ],
                'mediatheque' => [
                    'label' => 'Médiathèque',
                    'hint' => 'Photos et vidéos réutilisables.',
                    'actions' => [
                        'lire' => 'Consulter la médiathèque',
                        'creer' => 'Ajouter des fichiers',
                        'modifier' => 'Modifier titres et étiquettes',
                        'supprimer' => 'Supprimer des fichiers',
                        'ia' => 'Suggestions IA de titres (crédits payants)',
                    ],
                ],
                'hero' => [
                    'label' => 'Diaporama d\'accueil',
                    'hint' => 'Pile de polaroïds en haut de la page d\'accueil.',
                    'actions' => [
                        'lire' => 'Consulter',
                        'creer' => 'Ajouter des photos',
                        'modifier' => 'Légendes et ordre',
                        'supprimer' => 'Retirer des photos',
                    ],
                ],
                'slideshow' => [
                    'label' => 'Diaporama boutique',
                    'hint' => 'Diaporama plein écran pour l\'atelier / le stand.',
                    'actions' => [
                        'lire' => 'Consulter',
                        'creer' => 'Ajouter des diapositives',
                        'modifier' => 'Modifier durées, légendes, ordre, orientation',
                        'supprimer' => 'Retirer des diapositives',
                    ],
                ],
                'bandeaux' => [
                    'label' => 'Bandeaux de page',
                    'hint' => 'Photos d\'en-tête des pages du site.',
                    'actions' => [
                        'lire' => 'Consulter',
                        'modifier' => 'Définir ou changer un bandeau',
                        'supprimer' => 'Retirer un bandeau',
                    ],
                ],
            ],
        ],
        'boutique' => [
            'label' => 'Boutique',
            'hint' => 'Catalogue, commandes et livraison.',
            'resources' => [
                'produits' => [
                    'label' => 'Catalogue (pièces)',
                    'hint' => 'Fiches produit, prix, stock, mise en avant.',
                    'actions' => [
                        'lire' => 'Consulter le catalogue',
                        'creer' => 'Ajouter une pièce',
                        'modifier' => 'Modifier fiches, prix, stock, promos',
                        'supprimer' => 'Supprimer des pièces',
                        'publier' => 'Masquer / réafficher en boutique',
                        'cout' => 'Voir et modifier le prix d\'achat (marge)',
                    ],
                ],
                'import_lot' => [
                    'label' => 'Import par lot',
                    'hint' => 'Crée des fiches pièces à partir de photos en vrac.',
                    'actions' => [
                        'lire' => 'Ouvrir la page d\'import',
                        'creer' => 'Importer et créer les fiches',
                        'ia' => 'Regroupement et choix de photo par IA (crédits payants)',
                    ],
                ],
                'commandes' => [
                    'label' => 'Commandes',
                    'hint' => 'Contient les adresses et e-mails des clients.',
                    'actions' => [
                        'lire' => 'Consulter les commandes',
                        'modifier' => 'Mettre à jour la préparation et le suivi',
                    ],
                ],
                'livraison' => [
                    'label' => 'Transporteurs et frais de port',
                    'hint' => 'Grilles tarifaires par poids et par zone.',
                    'actions' => [
                        'lire' => 'Consulter',
                        'creer' => 'Ajouter transporteurs et paliers, charger un barème',
                        'modifier' => 'Activer / désactiver un transporteur',
                        'supprimer' => 'Supprimer transporteurs et paliers',
                    ],
                ],
            ],
        ],
    ];
}

/** Table plate « clé de droit => [niveau, ressource, action, libellé] », en mémoire. */
function auth_permission_index(): array
{
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (auth_catalog() as $levelKey => $level) {
            foreach ($level['resources'] as $resKey => $res) {
                foreach ($res['actions'] as $action => $label) {
                    $index["$levelKey.$resKey.$action"] = [$levelKey, $resKey, $action, $label];
                }
            }
        }
    }
    return $index;
}

function auth_permission_keys(): array
{
    return array_keys(auth_permission_index());
}

function auth_permission_valid(string $perm): bool
{
    return isset(auth_permission_index()[$perm]);
}

/** Construit une clé de droit en vérifiant qu'elle existe (erreur de code sinon). */
function auth_perm(string $resource, string $action): string
{
    $key = $resource . '.' . $action;
    if (!auth_permission_valid($key)) {
        throw new LogicException("Droit inconnu : $key");
    }
    return $key;
}

/**
 * Ne garde que les droits connus, ajoute « lire » à toute ressource dont un
 * autre droit est accordé, dédoublonne et remet dans l'ordre du catalogue.
 */
function auth_normalize_permissions(array $perms): array
{
    $index = auth_permission_index();
    $wanted = [];
    foreach ($perms as $p) {
        if (is_string($p) && isset($index[$p])) $wanted[$p] = true;
    }
    foreach (array_keys($wanted) as $p) {
        [$level, $res] = $index[$p];
        $lire = "$level.$res.lire";
        if (isset($index[$lire])) $wanted[$lire] = true;
    }
    return array_values(array_filter(array_keys($index), fn($k) => isset($wanted[$k])));
}

/** Rôles proposés à la première installation (modifiables et supprimables, sauf Propriétaire). */
function auth_default_roles(): array
{
    $all = auth_permission_keys();
    $readOnly = array_values(array_filter($all, function (string $k): bool {
        if (!str_ends_with($k, '.lire')) return false;
        foreach (['portail.cles_api.', 'portail.utilisateurs.', 'portail.roles.', 'boutique.commandes.'] as $secret) {
            if (str_starts_with($k, $secret)) return false;
        }
        return true;
    }));
    $level = fn(string $prefix) => array_values(array_filter($all, fn($k) => str_starts_with($k, $prefix)));

    return [
        [
            'name' => 'Gestionnaire de boutique',
            'description' => 'Gère le catalogue, les commandes et les photos des pièces. Pas de clés API, pas d\'équipe, pas de prix d\'achat.',
            'perms' => array_merge(
                array_values(array_filter($level('boutique.'), fn($k) => $k !== 'boutique.produits.cout' && !str_starts_with($k, 'boutique.livraison.') && !str_starts_with($k, 'boutique.import_lot.ia'))),
                ['boutique.livraison.lire'],
                $level('galerie.photos_produits.'),
                ['galerie.photos_produits.lire', 'galerie.mediatheque.lire', 'galerie.mediatheque.creer', 'portail.tableau_bord.lire']
            ),
        ],
        [
            'name' => 'Préparateur de commandes',
            'description' => 'Voit les commandes et met à jour leur préparation et le suivi colis.',
            'perms' => ['boutique.commandes.lire', 'boutique.commandes.modifier', 'boutique.livraison.lire', 'boutique.produits.lire'],
        ],
        [
            'name' => 'Photographe / visuels',
            'description' => 'Gère toute la galerie (photos, médiathèque, diaporamas, bandeaux), en lecture seule sur le catalogue.',
            'perms' => array_merge($level('galerie.'), ['boutique.produits.lire']),
        ],
        [
            'name' => 'Bénévole d\'événement',
            'description' => 'Gère la buvette et les invendus & dons du vide-grenier, sans suppression.',
            'perms' => ['portail.buvette.lire', 'portail.buvette.creer', 'portail.buvette.modifier', 'portail.invendus.lire', 'portail.invendus.creer', 'portail.invendus.modifier'],
        ],
        [
            'name' => 'Lecture seule',
            'description' => 'Consulte tout, ne modifie rien. Hors clés API, équipe et commandes (données clients).',
            'perms' => $readOnly,
        ],
    ];
}

/* ====================================================================== */
/* Schéma                                                                  */
/* ====================================================================== */

/**
 * Copie dans schema.sql : ces tables sont aussi créées à la volée (auth_boot),
 * comme celles du tableau de bord et de la buvette — rien à migrer à la main.
 */
function auth_schema_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS admin_roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL COLLATE NOCASE UNIQUE,
  description TEXT NOT NULL DEFAULT '',
  is_owner INTEGER NOT NULL DEFAULT 0,            -- 1 = Propriétaire : tous les droits, non modifiable
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_role_permissions (
  role_id INTEGER NOT NULL,
  perm TEXT NOT NULL,                             -- « niveau.ressource.action »
  PRIMARY KEY (role_id, perm),
  FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admin_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL COLLATE NOCASE UNIQUE,
  name TEXT NOT NULL DEFAULT '',
  password_hash TEXT NOT NULL,
  role_id INTEGER NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  must_change_password INTEGER NOT NULL DEFAULT 0,
  last_login_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES admin_roles(id)
);
CREATE INDEX IF NOT EXISTS idx_admin_users_role ON admin_users(role_id);

CREATE TABLE IF NOT EXISTS admin_login_attempts (
  ip TEXT NOT NULL,
  at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_admin_login_attempts ON admin_login_attempts(ip, at);
SQL;
}

/** Crée les tables si besoin, garantit le rôle Propriétaire et pose les rôles d'exemple au premier démarrage. */
function auth_boot(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    $pdo->exec(auth_schema_sql());

    $hasRoles = (int) $pdo->query('SELECT COUNT(*) FROM admin_roles')->fetchColumn() > 0;
    $hasOwner = (int) $pdo->query('SELECT COUNT(*) FROM admin_roles WHERE is_owner = 1')->fetchColumn() > 0;
    if (!$hasOwner) {
        $pdo->prepare('INSERT OR IGNORE INTO admin_roles (name, description, is_owner) VALUES (?, ?, 1)')
            ->execute(['Propriétaire', 'Tous les droits, y compris ceux ajoutés plus tard. Non modifiable.']);
    }
    if (!$hasRoles) {
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT OR IGNORE INTO admin_roles (name, description) VALUES (?, ?)');
            $perm = $pdo->prepare('INSERT OR IGNORE INTO admin_role_permissions (role_id, perm) VALUES (?, ?)');
            foreach (auth_default_roles() as $r) {
                $ins->execute([$r['name'], $r['description']]);
                if ($ins->rowCount() === 0) continue; // nom déjà pris (démarrage concurrent) : ne pas réutiliser un id périmé
                $id = (int) $pdo->lastInsertId();
                foreach (auth_normalize_permissions($r['perms']) as $p) $perm->execute([$id, $p]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    $done = true;
}

/* ====================================================================== */
/* Session, utilisateur courant, droits                                    */
/* ====================================================================== */

function auth_legacy_password(): string
{
    return defined('ADMIN_PASSWORD') ? (string) ADMIN_PASSWORD : '';
}

/**
 * Utilisateur connecté à l'administration, ou null.
 * Forme : id (0 = mot de passe historique), email, name, role_id, role_name,
 *         owner (bool), perms (liste), perm_set (clé => true), must_change (bool).
 * Le résultat est mémorisé pour la requête ; $refresh = true le recalcule.
 */
function auth_user(bool $refresh = false): ?array
{
    static $cache = false;
    if ($refresh) $cache = false;
    if ($cache !== false) return $cache;

    $legacy = [
        'id' => 0, 'email' => '', 'name' => 'Propriétaire', 'role_id' => null,
        'role_name' => 'Propriétaire', 'owner' => true, 'perms' => [], 'perm_set' => [],
        'must_change' => false, 'legacy' => true,
    ];

    // Session ouverte avant l'introduction des comptes : seule l'ancienne connexion
    // par mot de passe unique posait is_admin sans admin_uid.
    if (!array_key_exists('admin_uid', $_SESSION ?? [])) {
        return $cache = !empty($_SESSION['is_admin']) ? $legacy : null;
    }

    $uid = (int) $_SESSION['admin_uid'];
    if ($uid === 0) {
        return $cache = !empty($_SESSION['is_admin']) ? $legacy : null;
    }

    auth_boot();
    $stmt = db()->prepare('SELECT u.id, u.email, u.name, u.role_id, u.is_active, u.must_change_password,
                                  r.name AS role_name, r.is_owner
                           FROM admin_users u JOIN admin_roles r ON r.id = u.role_id
                           WHERE u.id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row || !(int) $row['is_active']) {
        // Compte supprimé ou désactivé pendant la session : accès coupé immédiatement.
        unset($_SESSION['is_admin'], $_SESSION['admin_uid']);
        return $cache = null;
    }

    $owner = (bool) (int) $row['is_owner'];
    $perms = $owner ? [] : auth_role_permissions((int) $row['role_id']);
    return $cache = [
        'id' => (int) $row['id'], 'email' => $row['email'], 'name' => $row['name'] !== '' ? $row['name'] : $row['email'],
        'role_id' => (int) $row['role_id'], 'role_name' => $row['role_name'], 'owner' => $owner,
        'perms' => $perms, 'perm_set' => array_fill_keys($perms, true),
        'must_change' => (bool) (int) $row['must_change_password'], 'legacy' => false,
    ];
}

function auth_is_owner(): bool
{
    $u = auth_user();
    return $u !== null && $u['owner'];
}

/** Droit exact ? Le propriétaire a tout. Lève une exception pour une clé inconnue. */
function auth_can(string $perm): bool
{
    if (!auth_permission_valid($perm)) {
        throw new LogicException("Droit inconnu : $perm");
    }
    $u = auth_user();
    if ($u === null) return false;
    return $u['owner'] || isset($u['perm_set'][$perm]);
}

/** Au moins un des droits ? */
function auth_can_any(string ...$perms): bool
{
    foreach ($perms as $p) {
        if (auth_can($p)) return true;
    }
    return false;
}

/** Droits effectifs d'un utilisateur (liste complète pour le propriétaire). */
function auth_effective_permissions(array $user): array
{
    return $user['owner'] ? auth_permission_keys() : $user['perms'];
}

/* ====================================================================== */
/* Garde des pages                                                         */
/* ====================================================================== */

/** Entrées du menu d'administration : clé => [url, libellé, droits (au moins un)]. */
function auth_nav_items(): array
{
    return [
        'dashboard' => ['/admin/dashboard.php', 'Tableau de bord', ['portail.tableau_bord.lire']],
        'catalogue' => ['/admin/catalog.php', 'Catalogue', ['boutique.produits.lire']],
        'batch' => ['/admin/batch-import.php', 'Import par lot', ['boutique.import_lot.lire']],
        'commandes' => ['/admin/orders.php', 'Commandes', ['boutique.commandes.lire']],
        'buvette' => ['/admin/buvette/index.php', 'Buvette', ['portail.buvette.lire']],
        'invendus' => ['/admin/invendus.php', 'Invendus & dons', ['portail.invendus.lire']],
        'hero' => ['/admin/hero.php', 'Diaporama hero', ['galerie.hero.lire']],
        'slideshow' => ['/admin/slideshow.php', 'Diaporama boutique', ['galerie.slideshow.lire']],
        'banners' => ['/admin/banners.php', 'Bandeaux de page', ['galerie.bandeaux.lire']],
        'media' => ['/admin/media.php', 'Médiathèque', ['galerie.mediatheque.lire']],
        'shipping' => ['/admin/shipping.php', 'Frais de port', ['boutique.livraison.lire']],
        'equipe' => ['/admin/users.php', 'Équipe', ['portail.utilisateurs.lire', 'portail.roles.lire']],
        'reglages' => ['/admin/index.php', 'Réglages du site', ['portail.reglages.lire', 'portail.cles_api.lire']],
    ];
}

/** Première page d'administration accessible à la personne connectée. */
function auth_home_url(): string
{
    foreach (auth_nav_items() as [$href, , $perms]) {
        if (auth_can_any(...$perms)) return $href;
    }
    return '/admin/account.php';
}

function auth_wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

/** Adresse de retour sûre (même site) pour renvoyer l'utilisateur après un refus. */
function auth_safe_back(string $fallback): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') return $fallback;
    $p = parse_url($ref);
    if (!$p || empty($p['path']) || (isset($p['host']) && !hash_equals(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), strtolower($p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''))))) {
        return $fallback;
    }
    if (!str_starts_with($p['path'], '/') || str_starts_with($p['path'], '//')) return $fallback;
    return $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
}

const AUTH_DENIED_MESSAGE = 'Vous n\'avez pas le droit d\'effectuer cette action. Demandez à un responsable d\'ajuster votre rôle.';

/** Refuse l'accès : JSON pour les appels AJAX, message + retour pour un POST, page 403 pour un GET. */
function auth_deny(bool $json = false): void
{
    $json = $json || auth_wants_json();
    http_response_code(403);
    header('Cache-Control: no-store');
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => AUTH_DENIED_MESSAGE], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (function_exists('flash_set') && session_status() === PHP_SESSION_ACTIVE) {
            flash_set(AUTH_DENIED_MESSAGE, 'error');
        }
        http_response_code(303);
        header('Location: ' . auth_safe_back(auth_home_url()));
        exit;
    }
    auth_render_forbidden();
    exit;
}

function auth_render_forbidden(): void
{
    $u = auth_user();
    $siteName = 'Administration';
    if (function_exists('get_content')) {
        try { $siteName = get_content()['site_name'] ?? $siteName; } catch (Throwable $e) { /* page d'erreur : on n'échoue pas deux fois */ }
    }
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Accès refusé — <?= $e($siteName) ?></title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main>
<section class="tight">
  <div class="wrap" style="max-width:560px;">
    <p class="eyebrow">Accès refusé</p>
    <h1 style="font-size:2rem;margin:12px 0 16px;">Cette page ne fait pas partie de vos droits</h1>
    <p>Si vous en avez besoin pour votre travail, demandez à un responsable d'ajuster votre rôle.</p>
    <p style="margin-top:22px;display:flex;gap:12px;flex-wrap:wrap;">
      <?php if ($u): ?>
        <a class="btn btn-primary" href="<?= $e(auth_home_url()) ?>">Retour à mon espace</a>
        <a class="btn btn-ghost" href="/admin/account.php">Mon compte et mes droits</a>
      <?php else: ?>
        <a class="btn btn-primary" href="/admin/login.php">Se connecter</a>
      <?php endif; ?>
    </p>
  </div>
</section>
</main>
</body>
</html>
<?php
}

/**
 * Exige une session d'administration (compte ou mot de passe historique).
 * Un compte à mot de passe provisoire est envoyé changer son mot de passe d'abord.
 */
function auth_require_login(bool $allowMustChange = false): array
{
    $u = auth_user();
    if ($u === null) {
        if (auth_wants_json()) auth_deny(true);
        header('Location: /admin/login.php');
        exit;
    }
    if ($u['must_change'] && !$allowMustChange) {
        header('Location: /admin/account.php?force=1');
        exit;
    }
    return $u;
}

/** Page ou action réservée à ceux qui ont AU MOINS UN des droits listés. */
function require_permission(string ...$perms): void
{
    if (!$perms) throw new LogicException('require_permission() sans droit : refus par défaut.');
    auth_require_login();
    if (!auth_can_any(...$perms)) auth_deny();
}

/** Comme require_permission, mais répond toujours en JSON (endpoints appelés en AJAX). */
function require_permission_json(string ...$perms): void
{
    if (!$perms) throw new LogicException('require_permission_json() sans droit : refus par défaut.');
    $u = auth_user();
    if ($u === null || $u['must_change'] || !auth_can_any(...$perms)) auth_deny(true);
}

/** Réservé au propriétaire (refus par défaut pour tout ce qui n'a pas de droit dédié). */
function require_owner(): void
{
    auth_require_login();
    if (!auth_is_owner()) auth_deny();
}

/**
 * Table « endpoint => action => droit(s) » des scripts d'action (POST).
 * '*' = n'importe quelle action. Une valeur-liste = au moins un des droits.
 * Une action absente de la table est REFUSÉE. Les contrôles dépendant des
 * données (créer ou modifier selon la présence d'un id, suppression en lot…)
 * s'ajoutent dans le script lui-même, après ce contrôle de base.
 */
function auth_action_map(): array
{
    $dash = 'portail.tableau_bord.';
    $inv = 'portail.invendus.';
    $photos = 'galerie.photos_produits.';
    return [
        'gallery-action.php' => [
            'add' => $photos . 'creer', 'toggle_hidden' => $photos . 'modifier', 'rename' => $photos . 'modifier',
            'replace' => $photos . 'modifier', 'delete' => $photos . 'supprimer', 'move_up' => $photos . 'modifier',
            'move_down' => $photos . 'modifier', 'crop' => $photos . 'modifier', 'rotate' => $photos . 'modifier',
            'export_formats' => $photos . 'modifier', 'detail' => $photos . 'ia', 'generate' => $photos . 'ia',
        ],
        'media-action.php' => [
            'add' => 'galerie.mediatheque.creer', 'update' => 'galerie.mediatheque.modifier', 'delete' => 'galerie.mediatheque.supprimer',
        ],
        'hero-action.php' => [
            'add' => 'galerie.hero.creer', 'add_from_media' => 'galerie.hero.creer', 'caption' => 'galerie.hero.modifier',
            'delete' => 'galerie.hero.supprimer', 'move_up' => 'galerie.hero.modifier', 'move_down' => 'galerie.hero.modifier',
        ],
        'slideshow-action.php' => [
            'add_media' => 'galerie.slideshow.creer', 'add_from_media' => 'galerie.slideshow.creer', 'add_product' => 'galerie.slideshow.creer',
            'update' => 'galerie.slideshow.modifier', 'delete' => 'galerie.slideshow.supprimer', 'move_up' => 'galerie.slideshow.modifier',
            'move_down' => 'galerie.slideshow.modifier', 'set_orientation' => 'galerie.slideshow.modifier',
        ],
        'banners-action.php' => [
            'set' => 'galerie.bandeaux.modifier', 'set_from_media' => 'galerie.bandeaux.modifier', 'delete' => 'galerie.bandeaux.supprimer',
        ],
        'shipping-action.php' => [
            'add_carrier' => 'boutique.livraison.creer', 'toggle_carrier' => 'boutique.livraison.modifier',
            'delete_carrier' => 'boutique.livraison.supprimer', 'add_rate' => 'boutique.livraison.creer',
            'delete_rate' => 'boutique.livraison.supprimer', 'seed_chronopost' => 'boutique.livraison.creer',
            'seed_colissimo' => 'boutique.livraison.creer', 'seed_colissimo_international' => 'boutique.livraison.creer',
        ],
        'update-order.php' => [
            'fulfillment' => 'boutique.commandes.modifier', 'refresh_stripe' => 'boutique.commandes.modifier',
        ],
        'bulk-action.php' => [
            'delete' => 'boutique.produits.supprimer', 'hide' => 'boutique.produits.publier', 'unhide' => 'boutique.produits.publier',
            'promo' => 'boutique.produits.modifier', 'unpromo' => 'boutique.produits.modifier',
        ],
        'save-product.php' => ['*' => 'boutique.produits.modifier'],
        'add-product.php' => ['*' => 'boutique.produits.creer'],
        'delete-product.php' => ['*' => 'boutique.produits.supprimer'],
        'batch-import-action.php' => [
            'upload' => 'boutique.import_lot.creer', 'regroup' => 'boutique.import_lot.creer', 'confirm_grouping' => 'boutique.import_lot.creer',
            'choose_photo' => 'boutique.import_lot.creer', 'skip_group' => 'boutique.import_lot.creer', 'process_group' => 'boutique.import_lot.creer',
            'finish' => 'boutique.import_lot.creer', 'ai_regroup_chunk' => 'boutique.import_lot.ia', 'suggest_best_photo' => 'boutique.import_lot.ia',
        ],
        'dashboard-action.php' => [
            'cost_save' => [$dash . 'creer', $dash . 'modifier'], 'cost_delete' => $dash . 'supprimer', 'cost_toggle' => $dash . 'modifier',
            'physical_save' => [$dash . 'creer', $dash . 'modifier'], 'physical_inc' => $dash . 'modifier',
            'physical_delete' => $dash . 'supprimer', 'settings_save' => $dash . 'modifier',
        ],
        'save-content.php' => ['*' => 'portail.reglages.modifier'],
        'save-stripe-keys.php' => ['*' => 'portail.cles_api.modifier'],
        'save-gemini-key.php' => ['*' => 'portail.cles_api.modifier'],
        'save-fal-key.php' => ['*' => 'portail.cles_api.modifier'],
        'invendus-action.php' => [
            'event_save' => [$inv . 'creer', $inv . 'modifier'], 'event_open_now' => $inv . 'modifier', 'event_end_now' => $inv . 'modifier',
            'event_close' => $inv . 'modifier', 'event_reopen' => $inv . 'modifier', 'event_delete' => $inv . 'supprimer',
            'offer_add' => $inv . 'creer', 'offer_update' => $inv . 'modifier', 'offer_status' => $inv . 'modifier',
            'offer_delete' => $inv . 'supprimer', 'offer_bulk' => [$inv . 'modifier', $inv . 'supprimer'],
            'tour_save' => [$inv . 'creer', $inv . 'modifier'], 'tour_start' => $inv . 'modifier', 'tour_finish' => $inv . 'modifier',
            'tour_reopen' => $inv . 'modifier', 'tour_delete' => $inv . 'supprimer', 'donation_create' => $inv . 'creer',
            'recipient_save' => [$inv . 'creer', $inv . 'modifier'], 'recipient_toggle' => $inv . 'modifier', 'recipient_delete' => $inv . 'supprimer',
        ],
    ];
}

/** Droit(s) exigé(s) pour (endpoint, action) ; null si l'action n'est pas répertoriée. */
function auth_action_requirement(string $endpoint, string $action): ?array
{
    $map = auth_action_map()[$endpoint] ?? null;
    if ($map === null) return null;
    $req = $map[$action] ?? $map['*'] ?? null;
    return $req === null ? null : (array) $req;
}

/** Contrôle de base d'un script d'action : l'action doit exister ET être permise. */
function auth_require_action(string $endpoint, string $action): void
{
    auth_require_login();
    $req = auth_action_requirement($endpoint, $action);
    if ($req === null) {
        // Simple visite (GET) d'un script d'action : le script redirige lui-même, rien n'est exécuté.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && isset(auth_action_map()[$endpoint])) return;
        auth_deny();
    }
    if (!auth_can_any(...$req)) auth_deny();
}

/**
 * Adapte les formulaires d'une page aux droits de la personne (confort uniquement :
 * le serveur refuse de toute façon). Un formulaire est repéré par son action cachée
 * « action » ou, à défaut, par l'URL de son script.
 *  - droit « modifier » manquant : formulaire en lecture seule (champs inactifs, boutons retirés),
 *    pour que la personne puisse quand même LIRE les valeurs ;
 *  - tout autre droit manquant (créer, supprimer, IA…) : formulaire masqué.
 */
function auth_ui_gate(string ...$endpoints): string
{
    $denied = [];
    foreach ($endpoints as $endpoint) {
        foreach (auth_action_map()[$endpoint] ?? [] as $action => $req) {
            $req = (array) $req;
            if (auth_can_any(...$req)) continue;
            $readOnly = count($req) === 1 && str_ends_with($req[0], '.modifier');
            $denied[] = [$endpoint, $action === '*' ? null : $action, $readOnly ? 'ro' : 'hide'];
        }
    }
    if (!$denied) return '';
    $json = json_encode($denied, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    return <<<HTML
<script>
(function () {
  var denied = $json;
  document.querySelectorAll('form').forEach(function (f) {
    var url = (f.getAttribute('action') || '').split('?')[0];
    var a = f.querySelector('input[name="action"]');
    var action = a ? a.value : '';
    for (var i = 0; i < denied.length; i++) {
      var d = denied[i];
      if (url.slice(-d[0].length) !== d[0] || (d[1] !== null && d[1] !== action)) continue;
      if (d[2] === 'ro') {
        f.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(function (el) { el.disabled = true; });
        f.querySelectorAll('button, input[type="submit"]').forEach(function (b) { b.style.display = 'none'; });
        f.setAttribute('data-denied', 'ro');
      } else {
        f.style.display = 'none';
        f.setAttribute('data-denied', 'hide');
      }
      break;
    }
  });
})();
</script>
HTML;
}

/* ====================================================================== */
/* Rôles                                                                   */
/* ====================================================================== */

function auth_role_permissions(int $roleId): array
{
    $stmt = db()->prepare('SELECT perm FROM admin_role_permissions WHERE role_id = ?');
    $stmt->execute([$roleId]);
    // Un droit retiré du catalogue (ou mal orthographié) ne doit jamais rester actif.
    return auth_normalize_permissions($stmt->fetchAll(PDO::FETCH_COLUMN));
}

function auth_role_get(int $id): ?array
{
    auth_boot();
    $stmt = db()->prepare('SELECT * FROM admin_roles WHERE id = ?');
    $stmt->execute([$id]);
    $role = $stmt->fetch();
    if (!$role) return null;
    $role['is_owner'] = (int) $role['is_owner'];
    $role['perms'] = $role['is_owner'] ? auth_permission_keys() : auth_role_permissions($id);
    return $role;
}

/** Tous les rôles, propriétaire en tête, avec effectifs. */
function auth_roles(): array
{
    auth_boot();
    $rows = db()->query('SELECT r.*, (SELECT COUNT(*) FROM admin_users u WHERE u.role_id = r.id) AS users_count,
                                (SELECT COUNT(*) FROM admin_role_permissions p WHERE p.role_id = r.id) AS perms_count
                         FROM admin_roles r ORDER BY r.is_owner DESC, r.name COLLATE NOCASE')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_owner'] = (int) $r['is_owner'];
        $r['users_count'] = (int) $r['users_count'];
        $r['perms_count'] = $r['is_owner'] ? count(auth_permission_keys()) : (int) $r['perms_count'];
    }
    return $rows;
}

/** Le rôle (droits $perms, propriétaire ou non) est-il à la portée de l'acteur ? */
function auth_actor_covers(array $actor, array $perms, bool $targetIsOwner = false): bool
{
    if ($actor['owner']) return true;
    if ($targetIsOwner) return false;
    foreach ($perms as $p) {
        if (!isset($actor['perm_set'][$p])) return false;
    }
    return true;
}

/** Rôles que l'acteur peut attribuer (jamais plus de droits que les siens). */
function auth_assignable_roles(array $actor): array
{
    return array_values(array_filter(auth_roles(), function (array $r) use ($actor): bool {
        $perms = $r['is_owner'] ? [] : auth_role_permissions($r['id']);
        return auth_actor_covers($actor, $perms, (bool) $r['is_owner']);
    }));
}

function auth_role_fields_error(string $name, string $description): ?string
{
    if ($name === '') return 'Donnez un nom au rôle.';
    if (mb_strlen($name) > 60) return 'Le nom du rôle est limité à 60 caractères.';
    if (mb_strlen($description) > 300) return 'La description est limitée à 300 caractères.';
    return null;
}

/** Nom déjà pris (comparaison Unicode : le NOCASE de SQLite ne replie pas « É » en « é ») ? */
function auth_role_name_taken(string $name, ?int $exceptId = null): bool
{
    $wanted = mb_strtolower($name);
    foreach (db()->query('SELECT id, name FROM admin_roles')->fetchAll() as $r) {
        if ((int) $r['id'] !== $exceptId && mb_strtolower($r['name']) === $wanted) return true;
    }
    return false;
}

/** @return array{0: ?int, 1: ?string} [id, erreur] */
function auth_role_create(array $actor, string $name, string $description, array $perms): array
{
    auth_boot();
    $name = trim($name);
    $description = trim($description);
    if ($err = auth_role_fields_error($name, $description)) return [null, $err];
    $perms = auth_normalize_permissions($perms);
    if (!auth_actor_covers($actor, $perms)) {
        return [null, 'Vous ne pouvez accorder que des droits que vous possédez vous-même.'];
    }
    if (auth_role_name_taken($name)) return [null, 'Un rôle porte déjà ce nom.'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO admin_roles (name, description) VALUES (?, ?)')->execute([$name, $description]);
        $id = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO admin_role_permissions (role_id, perm) VALUES (?, ?)');
        foreach ($perms as $p) $ins->execute([$id, $p]);
        $pdo->commit();
        return [$id, null];
    } catch (PDOException $e) {
        $pdo->rollBack();
        if (str_contains($e->getMessage(), 'UNIQUE')) return [null, 'Un rôle porte déjà ce nom.'];
        throw $e;
    }
}

function auth_role_update(array $actor, int $id, string $name, string $description, array $perms): ?string
{
    $role = auth_role_get($id);
    if (!$role) return 'Rôle introuvable.';
    if ($role['is_owner']) return 'Le rôle Propriétaire a tous les droits et ne peut pas être modifié.';
    $name = trim($name);
    $description = trim($description);
    if ($err = auth_role_fields_error($name, $description)) return $err;
    if (!$actor['owner'] && $actor['role_id'] === $id) {
        return 'Vous ne pouvez pas modifier votre propre rôle : demandez-le à un autre responsable.';
    }
    // On ne retire pas non plus des droits à un rôle qui nous dépasse.
    if (!auth_actor_covers($actor, $role['perms'])) {
        return 'Ce rôle contient des droits que vous ne possédez pas : vous ne pouvez pas le modifier.';
    }
    $perms = auth_normalize_permissions($perms);
    if (!auth_actor_covers($actor, $perms)) {
        return 'Vous ne pouvez accorder que des droits que vous possédez vous-même.';
    }
    if (auth_role_name_taken($name, $id)) return 'Un rôle porte déjà ce nom.';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE admin_roles SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $id]);
        $pdo->prepare('DELETE FROM admin_role_permissions WHERE role_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO admin_role_permissions (role_id, perm) VALUES (?, ?)');
        foreach ($perms as $p) $ins->execute([$id, $p]);
        $pdo->commit();
        return null;
    } catch (PDOException $e) {
        $pdo->rollBack();
        if (str_contains($e->getMessage(), 'UNIQUE')) return 'Un rôle porte déjà ce nom.';
        throw $e;
    }
}

function auth_role_delete(array $actor, int $id): ?string
{
    $role = auth_role_get($id);
    if (!$role) return 'Rôle introuvable.';
    if ($role['is_owner']) return 'Le rôle Propriétaire ne peut pas être supprimé.';
    if (!auth_actor_covers($actor, $role['perms'])) {
        return 'Ce rôle contient des droits que vous ne possédez pas : vous ne pouvez pas le supprimer.';
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM admin_users WHERE role_id = ?');
    $stmt->execute([$id]);
    $n = (int) $stmt->fetchColumn();
    if ($n > 0) {
        return $n === 1
            ? 'Un collaborateur a encore ce rôle : changez son rôle avant de le supprimer.'
            : "$n collaborateurs ont encore ce rôle : changez leur rôle avant de le supprimer.";
    }
    db()->prepare('DELETE FROM admin_roles WHERE id = ?')->execute([$id]);
    return null;
}

/* ====================================================================== */
/* Collaborateurs                                                          */
/* ====================================================================== */

function auth_normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function auth_password_error(string $password, string $email = ''): ?string
{
    if (mb_strlen($password) < AUTH_PASSWORD_MIN_LENGTH) {
        return 'Le mot de passe doit faire au moins ' . AUTH_PASSWORD_MIN_LENGTH . ' caractères.';
    }
    if (mb_strlen($password) > 200) return 'Le mot de passe est limité à 200 caractères.';
    if ($email !== '' && mb_strtolower($password) === mb_strtolower($email)) {
        return 'Le mot de passe ne doit pas être l\'adresse e-mail.';
    }
    return null;
}

/** Mot de passe provisoire lisible (sans caractères ambigus). */
function auth_generate_password(int $length = 14): string
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}

function auth_user_get(int $id): ?array
{
    auth_boot();
    $stmt = db()->prepare('SELECT u.*, r.name AS role_name, r.is_owner FROM admin_users u JOIN admin_roles r ON r.id = u.role_id WHERE u.id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) return null;
    foreach (['id', 'role_id', 'is_active', 'must_change_password', 'is_owner'] as $k) $u[$k] = (int) $u[$k];
    $u['perms'] = $u['is_owner'] ? auth_permission_keys() : auth_role_permissions($u['role_id']);
    return $u;
}

function auth_users(): array
{
    auth_boot();
    $rows = db()->query('SELECT u.*, r.name AS role_name, r.is_owner FROM admin_users u JOIN admin_roles r ON r.id = u.role_id
                         ORDER BY u.is_active DESC, u.name COLLATE NOCASE, u.email')->fetchAll();
    foreach ($rows as &$u) {
        foreach (['id', 'role_id', 'is_active', 'must_change_password', 'is_owner'] as $k) $u[$k] = (int) $u[$k];
    }
    return $rows;
}

/** L'acteur peut-il agir sur ce collaborateur (qui ne le dépasse pas) ? */
function auth_actor_may_manage(array $actor, array $target): bool
{
    if ($actor['owner']) return true;
    return auth_actor_covers($actor, $target['perms'], (bool) $target['is_owner']);
}

/** Retirer ce compte (suppression, désactivation, rétrogradation) laisserait-il l'administration sans propriétaire ? */
function auth_would_orphan_owner(int $userId): bool
{
    if (auth_legacy_password() !== '') return false; // le mot de passe historique reste un accès propriétaire
    $stmt = db()->prepare('SELECT COUNT(*) FROM admin_users u JOIN admin_roles r ON r.id = u.role_id
                           WHERE r.is_owner = 1 AND u.is_active = 1 AND u.id <> ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() === 0;
}

function auth_role_assignment_error(array $actor, ?array $role): ?string
{
    if (!$role) return 'Choisissez un rôle.';
    if (!auth_actor_covers($actor, $role['is_owner'] ? [] : $role['perms'], (bool) $role['is_owner'])) {
        return 'Vous ne pouvez attribuer que des rôles dont les droits sont inclus dans les vôtres.';
    }
    return null;
}

/** @return array{0: ?int, 1: ?string} [id, erreur] */
function auth_user_create(array $actor, string $email, string $name, int $roleId, string $password, bool $mustChange = true): array
{
    auth_boot();
    $email = auth_normalize_email($email);
    $name = trim($name);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        return [null, 'Adresse e-mail invalide.'];
    }
    if (mb_strlen($name) > 80) return [null, 'Le nom est limité à 80 caractères.'];
    if ($err = auth_password_error($password, $email)) return [null, $err];
    if ($err = auth_role_assignment_error($actor, auth_role_get($roleId))) return [null, $err];
    try {
        db()->prepare('INSERT INTO admin_users (email, name, password_hash, role_id, must_change_password) VALUES (?, ?, ?, ?, ?)')
            ->execute([$email, $name !== '' ? $name : strstr($email, '@', true), password_hash($password, PASSWORD_DEFAULT), $roleId, $mustChange ? 1 : 0]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) return [null, 'Un compte existe déjà avec cette adresse e-mail.'];
        throw $e;
    }
    return [(int) db()->lastInsertId(), null];
}

function auth_user_update(array $actor, int $id, string $name, string $email, int $roleId, bool $active): ?string
{
    $target = auth_user_get($id);
    if (!$target) return 'Collaborateur introuvable.';
    if (!auth_actor_may_manage($actor, $target)) return 'Ce compte a plus de droits que vous : vous ne pouvez pas le modifier.';
    $email = auth_normalize_email($email);
    $name = trim($name);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) return 'Adresse e-mail invalide.';
    if (mb_strlen($name) > 80) return 'Le nom est limité à 80 caractères.';

    $self = $id === (int) $actor['id'] && !$actor['legacy'];
    $roleChanged = $roleId !== $target['role_id'];
    $activeChanged = $active !== (bool) $target['is_active'];
    if ($self && ($roleChanged || $activeChanged)) {
        return 'Vous ne pouvez pas modifier votre propre rôle ni vous désactiver vous-même.';
    }
    if ($roleChanged && ($err = auth_role_assignment_error($actor, auth_role_get($roleId)))) return $err;
    if (($roleChanged && $target['is_owner'] || $activeChanged && !$active && $target['is_owner']) && auth_would_orphan_owner($id)) {
        return 'Il doit rester au moins un propriétaire actif.';
    }
    try {
        db()->prepare('UPDATE admin_users SET name = ?, email = ?, role_id = ?, is_active = ? WHERE id = ?')
            ->execute([$name !== '' ? $name : strstr($email, '@', true), $email, $roleId, $active ? 1 : 0, $id]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) return 'Un compte existe déjà avec cette adresse e-mail.';
        throw $e;
    }
    return null;
}

/** Réinitialisation du mot de passe par un responsable (la personne devra le changer à sa prochaine connexion). */
function auth_user_set_password(array $actor, int $id, string $password, bool $mustChange = true): ?string
{
    $target = auth_user_get($id);
    if (!$target) return 'Collaborateur introuvable.';
    if (!auth_actor_may_manage($actor, $target)) return 'Ce compte a plus de droits que vous : vous ne pouvez pas le modifier.';
    if ($err = auth_password_error($password, $target['email'])) return $err;
    db()->prepare('UPDATE admin_users SET password_hash = ?, must_change_password = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $mustChange ? 1 : 0, $id]);
    return null;
}

function auth_user_delete(array $actor, int $id): ?string
{
    $target = auth_user_get($id);
    if (!$target) return 'Collaborateur introuvable.';
    if ($id === (int) $actor['id'] && !$actor['legacy']) return 'Vous ne pouvez pas supprimer votre propre compte.';
    if (!auth_actor_may_manage($actor, $target)) return 'Ce compte a plus de droits que vous : vous ne pouvez pas le supprimer.';
    if ($target['is_owner'] && auth_would_orphan_owner($id)) return 'Il doit rester au moins un propriétaire actif.';
    db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
    return null;
}

/** Changement de mot de passe par la personne elle-même. */
function auth_change_own_password(int $uid, string $current, string $new): ?string
{
    if ($uid <= 0) return 'Le mot de passe principal se change dans .secrets/admin_password.txt sur le serveur.';
    $stmt = db()->prepare('SELECT email, password_hash FROM admin_users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) return 'Le mot de passe actuel est incorrect.';
    if ($err = auth_password_error($new, $row['email'])) return $err;
    if (hash_equals($current, $new)) return 'Choisissez un mot de passe différent de l\'actuel.';
    db()->prepare('UPDATE admin_users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
    return null;
}

/* ====================================================================== */
/* Connexion                                                               */
/* ====================================================================== */

function auth_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function auth_login_blocked(): bool
{
    db()->prepare('DELETE FROM admin_login_attempts WHERE at < ?')->execute([time() - AUTH_LOGIN_WINDOW_SECONDS]);
    $stmt = db()->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE ip = ? AND at >= ?');
    $stmt->execute([auth_client_ip(), time() - AUTH_LOGIN_WINDOW_SECONDS]);
    return (int) $stmt->fetchColumn() >= AUTH_LOGIN_MAX_ATTEMPTS;
}

function auth_login_record_failure(): void
{
    db()->prepare('INSERT INTO admin_login_attempts (ip, at) VALUES (?, ?)')->execute([auth_client_ip(), time()]);
}

/** Aucun accès n'est possible : ni mot de passe principal, ni compte actif. */
function auth_no_access_configured(): bool
{
    auth_boot();
    if (auth_legacy_password() !== '') return false;
    return (int) db()->query('SELECT COUNT(*) FROM admin_users WHERE is_active = 1')->fetchColumn() === 0;
}

function auth_session_open(int $uid): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['is_admin'] = true; // « personnel connecté » : exclut ses visites des statistiques
    $_SESSION['admin_uid'] = $uid;
    auth_user(true);
}

/**
 * Connexion. E-mail vide = mot de passe principal historique (propriétaire).
 * Retourne null si OK, sinon le message d'erreur (volontairement vague).
 */
function auth_login(string $email, string $password): ?string
{
    auth_boot();
    if (auth_login_blocked()) return 'Trop d\'essais. Réessayez dans quelques minutes.';
    $email = auth_normalize_email($email);

    if ($email === '') {
        $legacy = auth_legacy_password();
        // Un mot de passe principal vide ne doit JAMAIS ouvrir l'accès.
        if ($legacy !== '' && $password !== '' && hash_equals($legacy, $password)) {
            db()->prepare('DELETE FROM admin_login_attempts WHERE ip = ?')->execute([auth_client_ip()]);
            auth_session_open(0);
            return null;
        }
    } else {
        $stmt = db()->prepare('SELECT id, password_hash, is_active FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        // Toujours une vérification de hash, même sans compte : pas de différence de durée.
        static $dummy = null;
        $dummy ??= password_hash('inexistant', PASSWORD_DEFAULT);
        $ok = password_verify($password, $row['password_hash'] ?? $dummy);
        if ($row && $ok && (int) $row['is_active'] === 1) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            }
            db()->prepare('UPDATE admin_users SET last_login_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $row['id']]);
            db()->prepare('DELETE FROM admin_login_attempts WHERE ip = ?')->execute([auth_client_ip()]);
            auth_session_open((int) $row['id']);
            return null;
        }
    }
    auth_login_record_failure();
    return 'Identifiants incorrects.';
}
