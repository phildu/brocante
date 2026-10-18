<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — Configuration & Fonctions communes
 * Backend PHP pour API REST
 */

// CORS headers

// Handle preflight requests

// JSON responses

// Database configuration
defined('EB80_DB_PATH') || define('EB80_DB_PATH', __DIR__ . '/../electroboy80.db');
defined('JWT_SECRET') || define('JWT_SECRET', 'eb80_secret_change_in_prod');
defined('JWT_EXPIRES') || define('JWT_EXPIRES', 86400 * 7); // 7 days

// Upload configuration
defined('UPLOAD_DIR') || define('UPLOAD_DIR', __DIR__ . '/../uploads/');
defined('MAX_FILE_SIZE') || define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500MB (vidéos YouTube)

// Détection du driver : MySQL si db.config.php présent (preprod/prod), SQLite sinon (local)
defined('DB_DRIVER') || define('DB_DRIVER', file_exists(__DIR__ . '/db.config.php') ? 'mysql' : 'sqlite');

// Connect to database (SQLite en local, MySQL en preprod/prod)
function getDb() {
    static $db = null;
    if ($db === null) {
        try {
            if (DB_DRIVER === 'mysql') {
                $cfg = include __DIR__ . '/db.config.php';
                $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4";
                $db  = new PDO($dsn, $cfg['user'], $cfg['pass']);
            } else {
                $db = new PDO('sqlite:' . EB80_DB_PATH);
            }
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            foreach (getCreateTablesSQL() as $sql) { $db->exec($sql); }
            runMigrations($db);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }
    return $db;
}

// Retourne un tableau de CREATE TABLE (un par entrée) pour le driver courant
function getCreateTablesSQL(): array {
    return DB_DRIVER === 'mysql' ? _tablesMySQL() : [_tablesSQLite()];
}

function _tablesSQLite(): string { return <<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        role TEXT DEFAULT 'fan',
        partner_type TEXT,
        partner_access TEXT DEFAULT 'stats',
        alerts TEXT DEFAULT '[]',
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS content (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        type TEXT NOT NULL,
        body TEXT,
        audience TEXT DEFAULT 'all',
        networks TEXT DEFAULT '[]',
        media TEXT DEFAULT '[]',
        status TEXT DEFAULT 'draft',
        scheduled_at TEXT,
        author_id INTEGER,
        published_at TEXT,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY(author_id) REFERENCES users(id)
    );

    CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        date TEXT NOT NULL,
        time TEXT DEFAULT '21:00',
        venue TEXT,
        city TEXT,
        country TEXT DEFAULT 'FR',
        ticket_url TEXT,
        poster TEXT,
        description TEXT,
        networks TEXT DEFAULT '[]',
        status TEXT DEFAULT 'upcoming',
        created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS reactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        content_id INTEGER,
        user_id INTEGER,
        type TEXT DEFAULT 'like',
        created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        from_name TEXT,
        from_email TEXT NOT NULL,
        type TEXT DEFAULT 'contact',
        subject TEXT,
        body TEXT NOT NULL,
        read INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT,
        label TEXT,
        section TEXT,
        type TEXT
    );

    CREATE TABLE IF NOT EXISTS media_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        url TEXT NOT NULL,
        title TEXT,
        platform TEXT DEFAULT 'direct',
        thumb TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS media_states (
        filename TEXT PRIMARY KEY,
        type TEXT,
        state_json TEXT,
        updated_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS social_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        content_id INTEGER,
        network TEXT,
        status TEXT,
        published_at TEXT
    );

    CREATE TABLE IF NOT EXISTS scheduled_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        content_id INTEGER,
        networks TEXT,
        scheduled_at TEXT,
        status TEXT DEFAULT 'pending'
    );

    CREATE TABLE IF NOT EXISTS video_channel (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        source_type TEXT NOT NULL,
        filename    TEXT,
        link_id     INTEGER,
        title       TEXT,
        description TEXT,
        position    INTEGER DEFAULT 0,
        in_gallery  INTEGER DEFAULT 0,
        created_at  TEXT DEFAULT (datetime('now')),
        updated_at  TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS invitations (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        email         TEXT,
        phone         TEXT,
        name          TEXT,
        role          TEXT DEFAULT 'fan',        -- 'fan' | 'partner'
        partner_type  TEXT,
        token         TEXT UNIQUE NOT NULL,
        channel       TEXT,                       -- 'email' | 'sms' | 'whatsapp' | 'link'
        group_label   TEXT,                       -- regroupe les invitations d'un même envoi
        status        TEXT DEFAULT 'pending',     -- 'pending' | 'accepted' | 'revoked' | 'expired'
        invited_by    INTEGER,
        accepted_user_id INTEGER,
        created_at    TEXT DEFAULT (datetime('now')),
        expires_at    TEXT,
        accepted_at   TEXT
    );

    CREATE TABLE IF NOT EXISTS consent_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER,
        email      TEXT,
        action     TEXT NOT NULL,   -- 'consent_given' | 'marketing_optin' | 'marketing_optout' | 'data_export' | 'account_deleted' | ...
        detail     TEXT,
        ip         TEXT,
        user_agent TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS availability (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        date       TEXT NOT NULL UNIQUE,  -- YYYY-MM-DD
        status     TEXT DEFAULT 'available',  -- available | busy | hold | tentative
        note       TEXT DEFAULT '',
        source     TEXT DEFAULT 'manual',     -- manual | ical
        ical_uid   TEXT,
        updated_at TEXT DEFAULT (datetime('now')),
        created_at TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS merch (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        name              TEXT NOT NULL,
        slug              TEXT UNIQUE,
        category          TEXT DEFAULT '',
        size              TEXT DEFAULT '',
        description       TEXT,
        price             INTEGER NOT NULL DEFAULT 0,
        currency          TEXT DEFAULT 'eur',
        image             TEXT,
        images            TEXT DEFAULT '[]',
        image_type        TEXT DEFAULT 'image',
        video_url         TEXT DEFAULT '',
        schedule          TEXT DEFAULT '{}',
        effect            TEXT DEFAULT '{}',
        stock             INTEGER DEFAULT -1,
        payment_link      TEXT,
        stripe_product_id TEXT,
        stripe_price_id   TEXT,
        active            INTEGER DEFAULT 1,
        position          INTEGER DEFAULT 0,
        created_at        TEXT DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS merch_orders (
        id                     INTEGER PRIMARY KEY AUTOINCREMENT,
        merch_id               INTEGER,
        stripe_session_id      TEXT UNIQUE,
        stripe_payment_intent  TEXT,
        customer_email         TEXT,
        customer_name          TEXT,
        quantity               INTEGER DEFAULT 1,
        amount                 INTEGER DEFAULT 0,
        currency               TEXT DEFAULT 'eur',
        status                 TEXT DEFAULT 'pending',
        shipping_address       TEXT,
        created_at             TEXT DEFAULT (datetime('now')),
        updated_at             TEXT DEFAULT (datetime('now')),
        FOREIGN KEY(merch_id) REFERENCES merch(id)
    );
SQL;
}

function _tablesMySQL(): array {
    $e = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS users (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'fan',
            partner_type VARCHAR(100),
            partner_access VARCHAR(50) DEFAULT 'stats',
            alerts TEXT,
            status VARCHAR(50) DEFAULT 'active',
            email_verified TINYINT DEFAULT 0,
            verify_token VARCHAR(255),
            verify_expires DATETIME,
            reset_token VARCHAR(255),
            reset_expires DATETIME,
            provider VARCHAR(50) DEFAULT 'local',
            provider_id VARCHAR(255),
            avatar VARCHAR(500),
            phone VARCHAR(50),
            last_login DATETIME,
            invited_by INT,
            consent_rgpd TINYINT DEFAULT 0,
            consent_at DATETIME,
            consent_ip VARCHAR(45),
            marketing_consent TINYINT DEFAULT 0,
            permissions TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS content (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title TEXT NOT NULL,
            type VARCHAR(50) NOT NULL,
            body LONGTEXT,
            audience VARCHAR(50) DEFAULT 'all',
            networks TEXT,
            media TEXT,
            status VARCHAR(50) DEFAULT 'draft',
            scheduled_at DATETIME,
            author_id INT,
            published_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(author_id) REFERENCES users(id)
        ) $e",

        "CREATE TABLE IF NOT EXISTS events (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            date VARCHAR(10) NOT NULL,
            time VARCHAR(10) DEFAULT '21:00',
            venue VARCHAR(255),
            city VARCHAR(100),
            country VARCHAR(10) DEFAULT 'FR',
            ticket_url VARCHAR(500),
            poster VARCHAR(500),
            description TEXT,
            networks TEXT,
            status VARCHAR(50) DEFAULT 'upcoming',
            ext_id VARCHAR(255),
            ext_source VARCHAR(100),
            partners TEXT,
            rs_visuals TEXT,
            venue_partner_id VARCHAR(255),
            street VARCHAR(255),
            gallery TEXT,
            kit_files TEXT,
            cp VARCHAR(20),
            access_info TEXT,
            doors_time VARCHAR(10),
            entry_type VARCHAR(50) DEFAULT 'free',
            entry_price VARCHAR(100),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS reactions (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            content_id INT,
            user_id INT,
            type VARCHAR(50) DEFAULT 'like',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS messages (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            from_name VARCHAR(255),
            from_email VARCHAR(255) NOT NULL,
            type VARCHAR(50) DEFAULT 'contact',
            subject VARCHAR(500),
            body TEXT NOT NULL,
            `read` TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(255) NOT NULL PRIMARY KEY,
            value LONGTEXT,
            label VARCHAR(500),
            section VARCHAR(100),
            type VARCHAR(50)
        ) $e",

        "CREATE TABLE IF NOT EXISTS media_links (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            url VARCHAR(1000) NOT NULL,
            title VARCHAR(500),
            platform VARCHAR(50) DEFAULT 'direct',
            thumb VARCHAR(1000),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS media_states (
            filename VARCHAR(500) NOT NULL PRIMARY KEY,
            type VARCHAR(50),
            state_json LONGTEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS social_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            content_id INT,
            network VARCHAR(50),
            status VARCHAR(50),
            published_at DATETIME
        ) $e",

        "CREATE TABLE IF NOT EXISTS scheduled_posts (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            content_id INT,
            networks TEXT,
            scheduled_at DATETIME,
            status VARCHAR(50) DEFAULT 'pending'
        ) $e",

        "CREATE TABLE IF NOT EXISTS video_channel (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            source_type VARCHAR(50) NOT NULL,
            filename VARCHAR(500),
            link_id INT,
            title VARCHAR(500),
            description TEXT,
            position INT DEFAULT 0,
            in_gallery TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS invitations (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255),
            phone VARCHAR(50),
            name VARCHAR(255),
            role VARCHAR(50) DEFAULT 'fan',
            partner_type VARCHAR(100),
            token VARCHAR(255) UNIQUE NOT NULL,
            channel VARCHAR(50),
            group_label VARCHAR(255),
            status VARCHAR(50) DEFAULT 'pending',
            invited_by INT,
            accepted_user_id INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            accepted_at DATETIME
        ) $e",

        "CREATE TABLE IF NOT EXISTS consent_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            email VARCHAR(255),
            action VARCHAR(100) NOT NULL,
            detail TEXT,
            ip VARCHAR(45),
            user_agent VARCHAR(500),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS availability (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            date VARCHAR(10) NOT NULL UNIQUE,
            status VARCHAR(50) DEFAULT 'available',
            note TEXT,
            source VARCHAR(50) DEFAULT 'manual',
            ical_uid VARCHAR(255),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS merch (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) UNIQUE,
            category VARCHAR(100) DEFAULT '',
            size VARCHAR(200) DEFAULT '',
            description TEXT,
            price INT NOT NULL DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'eur',
            image VARCHAR(500),
            images TEXT,
            image_type VARCHAR(10) DEFAULT 'image',
            video_url VARCHAR(1000) DEFAULT '',
            schedule TEXT,
            effect TEXT,
            stock INT DEFAULT -1,
            payment_link VARCHAR(1000),
            stripe_product_id VARCHAR(255),
            stripe_price_id VARCHAR(255),
            active TINYINT DEFAULT 1,
            position INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $e",

        "CREATE TABLE IF NOT EXISTS merch_orders (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            merch_id INT,
            stripe_session_id VARCHAR(255) UNIQUE,
            stripe_payment_intent VARCHAR(255),
            customer_email VARCHAR(255),
            customer_name VARCHAR(255),
            quantity INT DEFAULT 1,
            amount INT DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'eur',
            status VARCHAR(50) DEFAULT 'pending',
            shipping_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY(merch_id) REFERENCES merch(id)
        ) $e",
    ];
}

// ── Migrations idempotentes (ajout de colonnes aux tables existantes) ──────────
function runMigrations($db) {
    $cols = [
        // users : vérification email, reset MDP, OAuth, RGPD, invitations
        "ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0",
        "ALTER TABLE users ADD COLUMN verify_token TEXT",
        "ALTER TABLE users ADD COLUMN verify_expires TEXT",
        "ALTER TABLE users ADD COLUMN reset_token TEXT",
        "ALTER TABLE users ADD COLUMN reset_expires TEXT",
        "ALTER TABLE users ADD COLUMN provider TEXT DEFAULT 'local'",
        "ALTER TABLE users ADD COLUMN provider_id TEXT",
        "ALTER TABLE users ADD COLUMN avatar TEXT",
        "ALTER TABLE users ADD COLUMN phone TEXT",
        "ALTER TABLE users ADD COLUMN last_login TEXT",
        "ALTER TABLE users ADD COLUMN invited_by INTEGER",
        "ALTER TABLE users ADD COLUMN consent_rgpd INTEGER DEFAULT 0",
        "ALTER TABLE users ADD COLUMN consent_at TEXT",
        "ALTER TABLE users ADD COLUMN consent_ip TEXT",
        "ALTER TABLE users ADD COLUMN marketing_consent INTEGER DEFAULT 0",
        "ALTER TABLE users ADD COLUMN permissions TEXT DEFAULT '[]'",
        // events : id externe (ex. Billetweb) pour la synchro
        "ALTER TABLE events ADD COLUMN ext_id TEXT",
        "ALTER TABLE events ADD COLUMN ext_source TEXT",
        // events : champs enrichis gérés par l'admin
        "ALTER TABLE events ADD COLUMN partners TEXT",
        "ALTER TABLE events ADD COLUMN rs_visuals TEXT",
        "ALTER TABLE events ADD COLUMN venue_partner_id TEXT",
        "ALTER TABLE events ADD COLUMN street TEXT",
        "ALTER TABLE events ADD COLUMN gallery TEXT",
        "ALTER TABLE events ADD COLUMN kit_files TEXT",
        // events : infos pratiques (accès, portes, conditions d'entrée)
        "ALTER TABLE events ADD COLUMN cp TEXT",
        "ALTER TABLE events ADD COLUMN access_info TEXT",
        "ALTER TABLE events ADD COLUMN doors_time TEXT",
        "ALTER TABLE events ADD COLUMN entry_type TEXT DEFAULT 'free'",
        "ALTER TABLE events ADD COLUMN entry_price TEXT",
        // events : titre formaté (HTML) et sous-titre
        "ALTER TABLE events ADD COLUMN name_html TEXT DEFAULT ''",
        "ALTER TABLE events ADD COLUMN subtitle TEXT DEFAULT ''",
        // events : médias digitaux (hero/vignette/page) par variante desk/mob
        "ALTER TABLE events ADD COLUMN media_slots TEXT DEFAULT '{}'",
        // merch : catégorie, média type, vidéo, calendrier
        "ALTER TABLE merch ADD COLUMN category TEXT DEFAULT ''",
        "ALTER TABLE merch ADD COLUMN size TEXT DEFAULT ''",
        "ALTER TABLE merch ADD COLUMN image_type TEXT DEFAULT 'image'",
        "ALTER TABLE merch ADD COLUMN video_url TEXT DEFAULT ''",
        "ALTER TABLE merch ADD COLUMN schedule TEXT DEFAULT '{}'",
        "ALTER TABLE merch ADD COLUMN effect TEXT DEFAULT '{}'",
    ];
    foreach ($cols as $sql) {
        try { $db->exec($sql); } catch (\Throwable $e) { /* colonne déjà présente : on ignore */ }
    }
}

// JSON response helper
function _eb80_orig_jsonResponse($data, $status = 200, int $cacheTtl = 0) {
    http_response_code($status);
    if (!headers_sent()) {
        if ($cacheTtl > 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
            header("Cache-Control: public, max-age=$cacheTtl");
        } else {
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
    }
    echo json_encode($data);
    exit;
}

// Error response helper
function errorResponse($message, $status = 400) {
    jsonResponse(['error' => $message], $status);
}

// Get request method
function getMethod() {
    return $_SERVER['REQUEST_METHOD'];
}

// Get request URI path (without query string)
function getPath() {
    // Priorité : chemin calculé par index.php avant tout rewrite Apache
    if (!empty($_SERVER['EB80_API_PATH'])) return $_SERVER['EB80_API_PATH'];
    $uri = parse_url($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    return preg_replace('#^/api#', '', $uri);
}

// Get JSON body input (also handles form data)
function getJsonInput() {
    $data = [];
    
    // Try JSON body first
    $input = file_get_contents('php://input');
    $jsonData = json_decode($input, true);
    if (is_array($jsonData)) {
        return $jsonData;
    }
    
    // Try standard POST data
    if (!empty($_POST)) {
        return $_POST;
    }
    
    // Try parsing raw input as form data
    if (!empty($input)) {
        parse_str($input, $parsedData);
        if (!empty($parsedData)) {
            return $parsedData;
        }
    }
    
    return $data;
}

// JWT Functions
function generateToken($payload) {
    $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRES;
    $payload = base64_encode(json_encode($payload));
    $signature = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$signature";
}

function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    
    $header = json_decode(base64_decode($parts[0]), true);
    $payload = json_decode(base64_decode($parts[1]), true);
    $signature = $parts[2];
    
    $expectedSig = base64_encode(hash_hmac('sha256', "$parts[0].$parts[1]", JWT_SECRET, true));
    
    if (!hash_equals($expectedSig, $signature)) return false;
    if ($payload['exp'] < time()) return false;
    
    return $payload;
}

// Get Authorization token from header
// Sur Apache + PHP-CGI/FPM (OVH), $_SERVER['HTTP_AUTHORIZATION'] est souvent vide :
// on tente toutes les sources possibles de l'en-tête Authorization.
function getToken() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (!$authHeader && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
        }
    }
    if (!$authHeader && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
        }
    }
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

// Require authentication
function _eb80_orig_requireAuth($roles = []) {
    $token = getToken();
    if (!$token) {
        errorResponse('Token requis', 401);
    }

    $payload = verifyToken($token);
    if (!$payload) {
        errorResponse('Token invalide ou expiré', 401);
    }

    if (!empty($roles) && !in_array($payload['role'], $roles)) {
        $raw   = $payload['permissions'] ?? '[]';
        $perms = is_array($raw) ? $raw : (json_decode($raw, true) ?: []);
        $allowed = array_intersect(['editor','community'], $roles);
        if (empty($allowed) || empty(array_intersect($perms, $allowed))) {
            errorResponse('Accès refusé', 403);
        }
    }

    return $payload;
}

// Vérifie qu'un collaborateur a la permission requise (ou que l'utilisateur est admin)
function _eb80_orig_requirePerm(string $perm) {
    $payload = requireAuth();
    if ($payload['role'] === 'admin') return $payload;
    $raw   = $payload['permissions'] ?? '[]';
    $perms = is_array($raw) ? $raw : (json_decode($raw, true) ?: []);
    if (!in_array($perm, $perms)) errorResponse('Accès refusé', 403);
    return $payload;
}

// Get current user from token
function _eb80_orig_getCurrentUser() {
    $token = getToken();
    if (!$token) return null;
    return verifyToken($token);
}

// Hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// Verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// ── Helpers comptes / RGPD / mail / OAuth ─────────────────────────────────────

// Lit un réglage depuis la table settings
function getSetting($key, $default = '') {
    try {
        $stmt = getDb()->prepare("SELECT value FROM settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : $v;
    } catch (\Throwable $e) { return $default; }
}

// Jeton aléatoire (vérif email, reset, invitation)
function randToken($len = 48) {
    return bin2hex(random_bytes((int)ceil($len / 2)));
}

// IP client (en tenant compte du reverse proxy OVH)
function clientIp() {
    foreach (['HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    }
    return '';
}

// URL de base du site (pour construire les liens des emails/invitations)
function appBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . $host;
}

// Journalise une action RGPD (consentement, export, suppression…)
function logConsent($action, $userId = null, $email = null, $detail = null) {
    try {
        $stmt = getDb()->prepare(
            "INSERT INTO consent_log (user_id, email, action, detail, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $email, $action, $detail, clientIp(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    } catch (\Throwable $e) { /* non bloquant */ }
}

// Configuration OAuth d'un provider (depuis settings). Renvoie null si non configuré.
function oauthConfig($provider) {
    $id     = getSetting("oauth_{$provider}_client_id");
    $secret = getSetting("oauth_{$provider}_client_secret");
    // Fallback : réseaux sociaux utilisent rs_facebook_app_id / rs_facebook_app_secret
    if ($provider === 'facebook') {
        if (!$id)     $id     = getSetting('rs_facebook_app_id');
        if (!$secret) $secret = getSetting('rs_facebook_app_secret');
    }
    if (!$id || !$secret) return null;
    return ['client_id' => $id, 'client_secret' => $secret];
}

/**
 * Envoi d'un email via SMTP (réglages dans settings : smtp_host, smtp_port,
 * smtp_user, smtp_pass, smtp_from, smtp_from_name, smtp_secure = ssl|tls).
 * Retourne true si envoyé, false sinon (l'appelant retombe alors sur un lien partageable).
 */
function sendMail($to, $subject, $htmlBody) {
    $host = getSetting('smtp_host');
    if (!$host) return false;

    $port   = (int)(getSetting('smtp_port', '465'));
    $user   = getSetting('smtp_user');
    $pass   = getSetting('smtp_pass');
    $from   = getSetting('smtp_from', $user);
    $fromNm = getSetting('smtp_from_name', 'ELECTROBOY 80');
    $secure = getSetting('smtp_secure', $port === 587 ? 'tls' : 'ssl');

    $eol = "\r\n";
    // TLS 1.2/1.3 avec vérification du certificat sur le nom du serveur (SNI).
    $tls = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
    $ctx = stream_context_create(['ssl' => [
        'peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true,
        'crypto_method' => $tls,
    ]]);
    $transport = ($secure === 'ssl') ? "ssl://$host" : "tcp://$host";
    error_clear_last();
    $fp = @stream_socket_client("$transport:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $detail = $errstr ?: (error_get_last()['message'] ?? '');
        return "Connexion SMTP impossible : " . smtpErreurTls($detail) . " ($errno) — host=$host port=$port secure=$secure";
    }
    stream_set_timeout($fp, 20);

    $read = function() use ($fp) { $d = ''; while ($line = fgets($fp, 515)) { $d .= $line; if (substr($line, 3, 1) === ' ') break; } return $d; };
    $cmd  = function($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };

    try {
        $read();
        $cmd("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($secure === 'tls') {
            $r = $cmd("STARTTLS");
            if (strpos($r, '220') !== 0) { fclose($fp); return "STARTTLS refusé par le serveur : " . trim($r) . " — host=$host port=$port"; }
            error_clear_last();
            if (!@stream_socket_enable_crypto($fp, true, $tls)) {
                $detail = error_get_last()['message'] ?? '';
                fclose($fp);
                return "STARTTLS échoué : " . smtpErreurTls($detail) . " — host=$host port=$port";
            }
            $cmd("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        if ($user) {
            $cmd("AUTH LOGIN");
            $cmd(base64_encode($user));
            $r = $cmd(base64_encode($pass));
            if (strpos($r, '235') !== 0) { fclose($fp); return "AUTH LOGIN refusé : " . trim($r); }
        }
        $cmd("MAIL FROM:<$from>");
        $cmd("RCPT TO:<$to>");
        $r = $cmd("DATA");
        if (strpos($r, '354') !== 0) { fclose($fp); return "DATA refusé : " . trim($r); }
        $headers = "From: " . mb_encode_mimeheader($fromNm) . " <$from>$eol"
            . "To: <$to>$eol"
            . "Subject: " . mb_encode_mimeheader($subject) . "$eol"
            . "MIME-Version: 1.0$eol"
            . "Content-Type: text/html; charset=UTF-8$eol"
            . "Date: " . date('r') . "$eol$eol";
        $body = preg_replace('/^\./m', '..', $htmlBody);
        $r = $cmd($headers . $body . "$eol.");
        $cmd("QUIT");
        fclose($fp);
        if (strpos($r, '250') === 0) return true;
        return "Envoi refusé : " . trim($r);
    } catch (\Throwable $e) {
        @fclose($fp);
        return "Exception : " . $e->getMessage();
    }
}

/** Explique une erreur TLS d'OpenSSL en français (cause la plus probable + piste). */
function smtpErreurTls(string $detail): string {
    $d = preg_replace('/^stream_socket_\w+\(\): /', '', trim($detail));
    if (stripos($d, 'did not match expected CN') !== false || stripos($d, 'peer_name') !== false) {
        return "le certificat du serveur ne correspond pas au nom saisi ($d). Indiquez dans « Serveur SMTP » "
            . "le nom exact du serveur de votre hébergeur (par ex. ssl0.ovh.net chez OVH) plutôt qu'un alias de votre domaine";
    }
    if (stripos($d, 'certificate verify failed') !== false) {
        return "certificat du serveur non reconnu ($d). Vérifiez le nom du serveur SMTP ou essayez le port 465 en SSL";
    }
    if (stripos($d, 'wrong version number') !== false || stripos($d, 'unknown protocol') !== false
        || stripos($d, 'Unable to connect to ssl://') !== false) {
        return "mauvais couple port / chiffrement ($d) : 465 = SSL, 587 = TLS (STARTTLS)";
    }
    return $d !== '' ? $d : 'négociation TLS refusée (aucun détail fourni par PHP)';
}

// Upload file
/**
 * Transforme un nom de fichier en slug SEO-friendly.
 * "Photo Électroboy 80 — Scène live.jpg" → "photo-electroboy-80-scene-live"
 */
function slugifyFilename(string $name): string {
    // Translittération des caractères accentués
    $map = [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','î'=>'i','ï'=>'i','í'=>'i',
        'ò'=>'o','ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o','ø'=>'o',
        'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
        'ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y',
        'À'=>'a','Â'=>'a','Ä'=>'a','Á'=>'a','Ã'=>'a','Å'=>'a',
        'È'=>'e','É'=>'e','Ê'=>'e','Ë'=>'e',
        'Ì'=>'i','Î'=>'i','Ï'=>'i','Í'=>'i',
        'Ò'=>'o','Ô'=>'o','Ö'=>'o','Ó'=>'o','Õ'=>'o','Ø'=>'o',
        'Ù'=>'u','Û'=>'u','Ü'=>'u','Ú'=>'u',
        'Ç'=>'c','Ñ'=>'n','Ý'=>'y',
        'œ'=>'oe','æ'=>'ae','Œ'=>'oe','Æ'=>'ae','ß'=>'ss',
    ];
    $slug = strtr($name, $map);
    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug); // tout ce qui n'est pas alphanum → tiret
    $slug = trim($slug, '-');
    $slug = preg_replace('/-{2,}/', '-', $slug);       // tirets multiples → un seul

    // Si le slug est vide ou trop générique (IMG_1234, DSC_...) on préfixe la date
    if (empty($slug) || preg_match('/^(img|dsc|dscn|p\d{4}|photo|image|file|upload)-?\d*$/', $slug)) {
        $slug = 'media-' . date('Ymd') . ($slug ? '-' . $slug : '');
    }

    // Suffixe court anti-collision (4 caractères hex)
    $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);

    return $slug;
}

function _eb80_orig_uploadFile($fieldName = 'file') {
    if (!isset($_FILES[$fieldName])) {
        return ['error' => 'Aucun fichier'];
    }

    $file = $_FILES[$fieldName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Erreur upload: ' . $file['error']];
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return ['error' => 'Fichier trop volumineux (max 50MB)'];
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $basename = slugifyFilename(pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = $basename . '.' . $ext;
    $filepath = UPLOAD_DIR . $filename;
    // Évite les collisions si le fichier existe déjà
    if (file_exists($filepath)) {
        $filename = $basename . '-' . substr(uniqid(), -4) . '.' . $ext;
        $filepath = UPLOAD_DIR . $filename;
        $basename = pathinfo($filename, PATHINFO_FILENAME);
    }

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['error' => 'Erreur déplacement fichier'];
    }

    $urlWeb = null;
    if (in_array($ext, ['jpg','jpeg','png','gif','webp']) && function_exists('imagecreatefromjpeg')) {
        $webFile = generateWebImage($filepath, $basename);
        if ($webFile) {
            $urlWeb = '/uploads/' . basename($webFile);
        }
    }

    return [
        'url'      => '/uploads/' . $filename,
        'url_web'  => $urlWeb,
        'filename' => $filename,
        'name'     => $file['name'],
        'size'     => $file['size'],
        'mimetype' => $file['type']
    ];
}

/**
 * Génère une version web optimisée (max 1200px, JPEG 82%) d'une image.
 * Retourne le chemin du fichier généré, ou null en cas d'échec.
 */
function generateWebImage($srcPath, $basename, $maxPx = 1200, $quality = 82) {
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));

    $im = match($ext) {
        'jpg','jpeg' => @imagecreatefromjpeg($srcPath),
        'png'        => @imagecreatefrompng($srcPath),
        'gif'        => @imagecreatefromgif($srcPath),
        'webp'       => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
        default      => false,
    };
    if (!$im) return null;

    $srcW = imagesx($im);
    $srcH = imagesy($im);

    if ($srcW <= $maxPx && $srcH <= $maxPx && $ext === 'jpg') {
        // Already small enough and right format — skip generating duplicate

        return null;
    }

    $ratio  = min($maxPx / $srcW, $maxPx / $srcH, 1.0);
    $dstW   = (int)round($srcW * $ratio);
    $dstH   = (int)round($srcH * $ratio);

    $dst = imagecreatetruecolor($dstW, $dstH);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);


    $webPath = UPLOAD_DIR . $basename . '_web.jpg';
    $ok = imagejpeg($dst, $webPath, $quality);


    return $ok ? $webPath : null;
}

// Seed default data
function seedDefaultData() {
    $db = getDb();
    
    // Check if admin exists
    $stmt = $db->query("SELECT id FROM users WHERE email = 'admin@electroboy80.fr'");
    if (!$stmt->fetch()) {
        $password = hashPassword('admin1234');
        $db->exec("INSERT INTO users (name, email, password, role) VALUES ('Admin EB80', 'admin@electroboy80.fr', '$password', 'admin')");
    }
    
    // Seed settings
    $settings = [
        ['bio_title', 'Un homme. Un Mac. Une guitare.', 'Titre bio', 'bio', 'text'],
        ['bio_body', 'Seul sur scène, ELECTROBOY 80 revisite les tubes new wave des années 80...', 'Texte bio complet', 'bio', 'textarea'],
        ['img_logo',        '',           'Logo principal (image)',    'images', 'url'],
        ['logo_text',       '',           'Texte du logo navbar',      'images', 'text'],
        ['logo_show_text',  '1',          'Afficher le texte du logo', 'images', 'text'],
        ['logo_show_image', '1',          'Afficher image du logo',    'images', 'text'],
        ['logo_layout',     'image-only', 'Disposition logo',          'images', 'text'],
        ['img_hero', 'photo3.jpg', 'Photo Hero', 'images', 'url'],
        ['color_pink', '#FF006E', 'Couleur principale', 'colors', 'color'],
        ['color_yellow', '#FFFF00', 'Couleur accent', 'colors', 'color'],
        ['color_blue', '#00B4FF', 'Couleur secondaire', 'colors', 'color'],
    ];
    
    $insertIgnore = (DB_DRIVER === 'mysql')
        ? "INSERT IGNORE INTO settings (`key`, value, label, section, type) VALUES (?, ?, ?, ?, ?)"
        : "INSERT OR IGNORE INTO settings (key, value, label, section, type) VALUES (?, ?, ?, ?, ?)";
    $stmt = $db->prepare($insertIgnore);
    foreach ($settings as $setting) {
        $stmt->execute($setting);
    }
}

// ── PDO helpers (évitent le chaînage execute()->fetch() invalide) ─────────────
function dbFetch(string $sql, array $params = []): array|false {
    $stmt = getDb()->prepare($sql); $stmt->execute($params); return $stmt->fetch();
}
function dbFetchAll(string $sql, array $params = [], int $mode = PDO::FETCH_ASSOC): array {
    $stmt = getDb()->prepare($sql); $stmt->execute($params); return $stmt->fetchAll($mode);
}
function dbFetchColumn(string $sql, array $params = []): mixed {
    $stmt = getDb()->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn();
}
function dbExec(string $sql, array $params = []): void {
    $stmt = getDb()->prepare($sql); $stmt->execute($params);
}

// Initialize database on first load
seedDefaultData();