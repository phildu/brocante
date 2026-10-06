<?php

// Comptes d'un commerce (table accounts de SA base) : équipe qui se connecte à
// l'administration (administrateurs, community managers) et contacts de la
// boutique (clients, prospects). Une seule fiche par adresse e-mail : un
// prospect qui commande devient client, il n'est pas dupliqué.
//
// Le compte principal du commerce (admin_user / admin_password de son
// tenant.php, ou ADMIN_USER / ADMIN_PASSWORD) n'est pas dans cette table : il
// reste toujours valable, ce qui évite de pouvoir se verrouiller dehors.

const ACCOUNT_ROLES = [
    'admin' => 'Administrateur',
    'community_manager' => 'Community manager',
    'client' => 'Client',
    'prospect' => 'Prospect',
];

/** Rôles qui se connectent à l'administration (les autres sont des contacts, sans accès). */
const ACCOUNT_STAFF_ROLES = ['admin', 'community_manager'];

const ACCOUNT_SOURCES = ['manuel' => 'Ajouté à la main', 'commande' => 'Commande', 'newsletter' => 'Newsletter'];

const ACCOUNT_PASSWORD_MIN = 8;

/** Crée la table si elle manque (bases créées avant cette fonction, ou nouvelle base). */
function accounts_ensure_schema(PDO $pdo): void
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'accounts'")->fetchColumn();
    if ($exists) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      role TEXT NOT NULL DEFAULT 'prospect',
      name TEXT NOT NULL DEFAULT '',
      email TEXT NOT NULL,
      username TEXT,
      phone TEXT NOT NULL DEFAULT '',
      password_hash TEXT,
      is_active INTEGER NOT NULL DEFAULT 1,
      source TEXT NOT NULL DEFAULT 'manuel',
      notes TEXT NOT NULL DEFAULT '',
      last_login_at TEXT,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_accounts_email ON accounts(email COLLATE NOCASE)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_accounts_username ON accounts(username COLLATE NOCASE) WHERE username IS NOT NULL');
}

function account_is_staff(string $role): bool
{
    return in_array($role, ACCOUNT_STAFF_ROLES, true);
}

function account_role_label(string $role): string
{
    return ACCOUNT_ROLES[$role] ?? $role;
}

/**
 * Pages de l'administration ouvertes à un rôle (nom de fichier). Un
 * administrateur voit tout ; un community manager ne gère que les visuels et
 * contenus de communication ; toute nouvelle page est réservée aux
 * administrateurs tant qu'elle n'est pas ajoutée ici.
 */
const ACCOUNT_COMMUNITY_MANAGER_PAGES = [
    'hero.php', 'hero-action.php', 'slideshow.php', 'slideshow-action.php',
    'banners.php', 'banners-action.php', 'media.php', 'media-action.php', 'media-describe.php',
    'enhance-image.php', 'gallery.php', 'gallery-action.php', 'video-action.php', 'mobile-variants.php',
    'profile.php', 'profile-action.php', // son propre profil
    'capture-session.php', // code QR pour envoyer des photos/vidéos depuis un téléphone
    'prompts-action.php', // prompts enregistrés de la génération IA (galerie)
];

function admin_role_can_access(string $role, string $script): bool
{
    if ($role === 'admin') {
        return true;
    }
    return $role === 'community_manager' && in_array(basename($script), ACCOUNT_COMMUNITY_MANAGER_PAGES, true);
}

/** Première page d'administration ouverte à ce rôle (page d'arrivée après connexion). */
function admin_home_for_role(string $role): string
{
    return $role === 'community_manager' ? '/admin/media.php' : '/admin/catalog.php';
}

// ── Lecture ────────────────────────────────────────────────────────────────

function account_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function account_find_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM accounts WHERE email = ? COLLATE NOCASE');
    $stmt->execute([trim($email)]);
    return $stmt->fetch() ?: null;
}

/** Compte de l'équipe actif correspondant à un identifiant de connexion (e-mail ou identifiant). */
function account_staff_by_login(string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM accounts WHERE (email = ? COLLATE NOCASE OR username = ? COLLATE NOCASE)
        AND role IN ('admin', 'community_manager') AND is_active = 1 AND password_hash IS NOT NULL LIMIT 1");
    $stmt->execute([$login, $login]);
    return $stmt->fetch() ?: null;
}

/** Nombre de comptes par rôle ('all' = total). */
function accounts_counts(): array
{
    $counts = array_fill_keys(array_keys(ACCOUNT_ROLES), 0);
    foreach (db()->query('SELECT role, COUNT(*) AS n FROM accounts GROUP BY role') as $row) {
        $counts[$row['role']] = (int) $row['n'];
    }
    $counts['all'] = array_sum($counts);
    return $counts;
}

/**
 * Comptes filtrés par rôle et recherche (nom, e-mail, identifiant, téléphone),
 * avec le nombre et le total des commandes payées (rapprochées par e-mail).
 */
function accounts_list(?string $role = null, string $search = ''): array
{
    $where = [];
    $params = [];
    if ($role !== null && isset(ACCOUNT_ROLES[$role])) {
        $where[] = 'a.role = ?';
        $params[] = $role;
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = "(a.name LIKE ? ESCAPE '\\' OR a.email LIKE ? ESCAPE '\\' OR a.username LIKE ? ESCAPE '\\' OR a.phone LIKE ? ESCAPE '\\')";
        array_push($params, $like, $like, $like, $like);
    }
    $sql = "SELECT a.*,
        (SELECT COUNT(*) FROM orders o WHERE o.status = 'paid' AND o.email = a.email COLLATE NOCASE) AS orders_count,
        (SELECT COALESCE(SUM(o.amount_total), 0) FROM orders o WHERE o.status = 'paid' AND o.email = a.email COLLATE NOCASE) AS orders_total
        FROM accounts a" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY CASE a.role WHEN 'admin' THEN 0 WHEN 'community_manager' THEN 1 WHEN 'client' THEN 2 ELSE 3 END, a.name COLLATE NOCASE, a.email COLLATE NOCASE";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ── Règles ─────────────────────────────────────────────────────────────────

/** Vrai si le commerce garde un moyen de se connecter une fois ce compte d'équipe retiré (supprimé, désactivé, rétrogradé). */
function account_staff_login_remains_without(int $excludeId): bool
{
    foreach (admin_accounts() as [$user, $password]) {
        if ($user !== '' && $password !== '') {
            return true; // compte principal du tenant.php ou de la configuration
        }
    }
    $stmt = db()->prepare("SELECT 1 FROM accounts WHERE id <> ? AND role = 'admin' AND is_active = 1 AND password_hash IS NOT NULL");
    $stmt->execute([$excludeId]);
    return (bool) $stmt->fetchColumn();
}

function account_validate_password(string $password): void
{
    if (mb_strlen($password) < ACCOUNT_PASSWORD_MIN) {
        throw new InvalidArgumentException('Le mot de passe doit contenir au moins ' . ACCOUNT_PASSWORD_MIN . ' caractères.');
    }
}

/**
 * Valide et normalise les champs d'un formulaire de compte. $existing = fiche
 * actuelle en cas de modification (pour l'unicité et les règles de rôle).
 */
function account_normalize(array $in, ?array $existing = null): array
{
    $role = (string) ($in['role'] ?? ($existing['role'] ?? 'prospect'));
    if (!isset(ACCOUNT_ROLES[$role])) {
        throw new InvalidArgumentException('Rôle inconnu.');
    }
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
        throw new InvalidArgumentException("Adresse e-mail invalide.");
    }
    $other = account_find_by_email($email);
    if ($other && (int) $other['id'] !== (int) ($existing['id'] ?? 0)) {
        throw new InvalidArgumentException("Un compte existe déjà pour $email (" . account_role_label($other['role']) . ').');
    }

    $username = trim((string) ($in['username'] ?? ''));
    if (account_is_staff($role) && $username !== '') {
        if (!preg_match('/^[A-Za-z0-9._@-]{3,40}$/', $username)) {
            throw new InvalidArgumentException("Identifiant : 3 à 40 caractères parmi lettres, chiffres, point, tiret, tiret bas, @.");
        }
        $stmt = db()->prepare('SELECT id FROM accounts WHERE username = ? COLLATE NOCASE');
        $stmt->execute([$username]);
        $takenBy = $stmt->fetchColumn();
        if ($takenBy && (int) $takenBy !== (int) ($existing['id'] ?? 0)) {
            throw new InvalidArgumentException("L'identifiant « $username » est déjà pris.");
        }
    }
    return [
        'role' => $role,
        'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 80),
        'email' => $email,
        'username' => account_is_staff($role) && $username !== '' ? $username : null,
        'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30),
        'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 600),
    ];
}

// ── Écriture ───────────────────────────────────────────────────────────────

function account_create(array $in, string $password = '', string $source = 'manuel'): int
{
    $data = account_normalize($in);
    $hash = null;
    if (account_is_staff($data['role'])) {
        account_validate_password($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
    }
    $stmt = db()->prepare('INSERT INTO accounts (role, name, email, username, phone, password_hash, source, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$data['role'], $data['name'], $data['email'], $data['username'], $data['phone'], $hash,
        isset(ACCOUNT_SOURCES[$source]) ? $source : 'manuel', $data['notes']]);
    return (int) db()->lastInsertId();
}

/**
 * Modifie un compte. $actingId = compte de l'équipe connecté (null pour le
 * compte principal) : on ne peut ni se désactiver, ni se rétrograder, et on ne
 * retire jamais le dernier moyen de se connecter. $password : nouveau mot de
 * passe, obligatoire en passant un contact à un rôle d'équipe.
 */
function account_update(int $id, array $in, string $password, ?int $actingId): void
{
    $account = account_find($id) ?? throw new InvalidArgumentException('Compte introuvable.');
    $data = account_normalize($in, $account);
    $isActive = array_key_exists('is_active', $in) ? (int) !empty($in['is_active']) : (int) $account['is_active'];
    $wasStaff = account_is_staff($account['role']);
    $willBeStaff = account_is_staff($data['role']);

    if ($actingId !== null && $actingId === $id && ($data['role'] !== $account['role'] || !$isActive)) {
        throw new InvalidArgumentException('Vous ne pouvez pas changer votre propre rôle ni désactiver votre propre compte.');
    }
    $losesAdmin = $account['role'] === 'admin' && (int) $account['is_active'] === 1 && ($data['role'] !== 'admin' || !$isActive);
    if ($losesAdmin && !account_staff_login_remains_without($id)) {
        throw new InvalidArgumentException("C'est le dernier compte administrateur : créez-en un autre avant de le modifier.");
    }

    $hash = $account['password_hash'];
    if ($willBeStaff) {
        if ($password !== '') {
            account_validate_password($password);
            $hash = password_hash($password, PASSWORD_DEFAULT);
        } elseif (!$wasStaff || $hash === null) {
            throw new InvalidArgumentException('Définissez un mot de passe pour donner un accès à l\'administration.');
        }
    } else {
        $hash = null; // un contact n'a pas d'accès
    }
    $stmt = db()->prepare('UPDATE accounts SET role = ?, name = ?, email = ?, username = ?, phone = ?, password_hash = ?, is_active = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$data['role'], $data['name'], $data['email'], $data['username'], $data['phone'], $hash, $isActive, $data['notes'], $id]);
}

/**
 * Un membre de l'équipe modifie SON profil. Nom et téléphone se changent
 * librement ; l'e-mail, l'identifiant et le mot de passe (ce qui permet de se
 * connecter) exigent le mot de passe actuel, pour qu'une session laissée
 * ouverte ne suffise pas à prendre le compte. Le rôle, l'état et les notes ne
 * se changent jamais ici (réservé aux administrateurs, page Comptes).
 */
function account_update_profile(int $id, array $in, string $currentPassword, string $newPassword): void
{
    $account = account_find($id);
    if (!$account || !account_is_staff($account['role']) || !(int) $account['is_active']) {
        throw new InvalidArgumentException('Compte introuvable.');
    }
    $data = account_normalize([
        'role' => $account['role'],
        'name' => $in['name'] ?? $account['name'],
        'email' => $in['email'] ?? $account['email'],
        'username' => $in['username'] ?? ($account['username'] ?? ''),
        'phone' => $in['phone'] ?? $account['phone'],
        'notes' => $account['notes'],
    ], $account);

    $changesLogin = mb_strtolower($data['email']) !== mb_strtolower($account['email'])
        || mb_strtolower((string) $data['username']) !== mb_strtolower((string) $account['username'])
        || $newPassword !== '';
    $hash = $account['password_hash'];
    if ($changesLogin) {
        if ($currentPassword === '' || !password_verify($currentPassword, (string) $hash)) {
            sleep(1); // freine les essais en série
            throw new InvalidArgumentException('Mot de passe actuel incorrect : e-mail, identifiant et mot de passe ne se changent qu\'avec lui.');
        }
        if ($newPassword !== '') {
            account_validate_password($newPassword);
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        }
    }
    $stmt = db()->prepare('UPDATE accounts SET name = ?, email = ?, username = ?, phone = ?, password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$data['name'], $data['email'], $data['username'], $data['phone'], $hash, $id]);
}

function account_set_active(int $id, bool $active, ?int $actingId): void
{
    $account = account_find($id) ?? throw new InvalidArgumentException('Compte introuvable.');
    if (!$active) {
        if ($actingId !== null && $actingId === $id) {
            throw new InvalidArgumentException('Vous ne pouvez pas désactiver votre propre compte.');
        }
        if ($account['role'] === 'admin' && (int) $account['is_active'] === 1 && !account_staff_login_remains_without($id)) {
            throw new InvalidArgumentException("C'est le dernier compte administrateur : créez-en un autre avant de le désactiver.");
        }
    }
    db()->prepare('UPDATE accounts SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$active ? 1 : 0, $id]);
}

function account_delete(int $id, ?int $actingId): void
{
    $account = account_find($id) ?? throw new InvalidArgumentException('Compte introuvable.');
    if ($actingId !== null && $actingId === $id) {
        throw new InvalidArgumentException('Vous ne pouvez pas supprimer votre propre compte.');
    }
    if ($account['role'] === 'admin' && (int) $account['is_active'] === 1 && !account_staff_login_remains_without($id)) {
        throw new InvalidArgumentException("C'est le dernier compte administrateur : créez-en un autre avant de le supprimer.");
    }
    db()->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
}

function account_touch_login(int $id): void
{
    db()->prepare('UPDATE accounts SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
}

/**
 * Enregistre un contact de la boutique (inscription newsletter, commande payée)
 * sans jamais perdre d'information : un contact existant garde son rôle (un
 * prospect devient client à la première commande, un membre de l'équipe ne
 * change jamais) ; un nom manquant est complété. Retourne l'identifiant, ou
 * null si l'adresse est invalide. Ne lève jamais d'exception : l'enregistrement
 * d'un contact ne doit pas faire échouer une commande ou une inscription.
 */
function account_upsert_contact(string $email, string $name, string $role, string $source): ?int
{
    try {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
            return null;
        }
        $name = mb_substr(trim($name), 0, 80);
        $existing = account_find_by_email($email);
        if (!$existing) {
            return account_create(['role' => $role, 'email' => $email, 'name' => $name], '', $source);
        }
        $newRole = ($existing['role'] === 'prospect' && $role === 'client') ? 'client' : $existing['role'];
        $newName = $existing['name'] !== '' ? $existing['name'] : $name;
        if ($newRole !== $existing['role'] || $newName !== $existing['name']) {
            db()->prepare('UPDATE accounts SET role = ?, name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$newRole, $newName, $existing['id']]);
        }
        return (int) $existing['id'];
    } catch (Throwable $e) {
        error_log('account_upsert_contact : ' . $e->getMessage());
        return null;
    }
}

/**
 * Crée une fiche client pour chaque adresse e-mail ayant une commande payée
 * (et passe en client les prospects concernés). Retourne [créés, mis à jour].
 */
function accounts_sync_clients_from_orders(): array
{
    $created = 0;
    $updated = 0;
    $rows = db()->query("SELECT DISTINCT lower(trim(email)) AS email FROM orders WHERE status = 'paid' AND email IS NOT NULL AND trim(email) <> ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $email) {
        $before = account_find_by_email($email);
        $id = account_upsert_contact($email, '', 'client', 'commande');
        if ($id === null) continue;
        if (!$before) $created++;
        elseif ($before['role'] === 'prospect') $updated++;
    }
    return [$created, $updated];
}

// ── Jeton CSRF des formulaires de gestion des comptes ──────────────────────

function admin_csrf_token(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['admin_csrf'];
}

function admin_csrf_check(): void
{
    if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Formulaire expiré : rechargez la page et recommencez.');
    }
}
