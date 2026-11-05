<?php
/**
 * Module « Invendus & dons » d'un vide-grenier.
 *
 * Principe : avant/pendant le vide-grenier, les exposants proposent les objets
 * qu'ils donneront s'ils restent invendus. À partir d'une heure fixée par
 * l'organisation, un point de collecte ouvre : les objets y arrivent (apportés
 * par leur propriétaire ou ramassés à vélo cargo lors d'une tournée), les
 * visiteurs se servent gratuitement, et le reliquat part vers une donnerie
 * (recyclerie, Emmaüs…) avec un bordereau de remise.
 *
 * Il n'y a pas de comptes utilisateurs sur ce site : les exposants, bénévoles
 * de tournée et bénévoles du point accèdent à leur page par un lien secret
 * (jeton aléatoire). L'organisation passe, elle, par l'espace admin.
 *
 * Les tables sont créées à la volée (CREATE TABLE IF NOT EXISTS) : le script de
 * déploiement n'envoie jamais brocante.db ni les *.sql, donc le serveur n'a pas
 * d'autre moyen de recevoir ce schéma. Il est aussi recopié dans schema.sql
 * pour les installations neuves — garder les deux synchronisés.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

/** Garde-fou anti-spam : propositions publiques acceptées par heure et par édition. */
const UNSOLD_MAX_PUBLIC_OFFERS_PER_HOUR = 60;
/** Propositions maximum qu'une même session peut déposer par heure. */
const UNSOLD_MAX_OFFERS_PER_SESSION_HOUR = 15;
/** Taille maximum d'une photo envoyée (octets). */
const UNSOLD_MAX_PHOTO_BYTES = 8 * 1024 * 1024;

/* ======================================================================
 * Schéma
 * ==================================================================== */

function unsold_schema_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS unsold_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  event_date TEXT NOT NULL,                       -- AAAA-MM-JJ
  location TEXT NOT NULL DEFAULT '',              -- lieu du vide-grenier
  point_name TEXT NOT NULL DEFAULT 'Point de collecte',
  point_details TEXT NOT NULL DEFAULT '',         -- où se trouve le point, comment le repérer
  collect_start TEXT NOT NULL,                    -- AAAA-MM-JJ HH:MM (heure de Paris)
  collect_end TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft',           -- draft | published | closed
  desk_token TEXT NOT NULL UNIQUE,                -- lien secret des bénévoles du point
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS unsold_recipients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  address TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  accepts TEXT NOT NULL DEFAULT '',               -- ce qu'ils acceptent (texte libre)
  hours TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS unsold_tours (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  volunteer_name TEXT NOT NULL,
  volunteer_phone TEXT NOT NULL DEFAULT '',
  vehicle TEXT NOT NULL DEFAULT 'Vélo cargo',
  capacity INTEGER NOT NULL DEFAULT 12,           -- en « unités de volume » (petit=1, moyen=2, encombrant=4)
  token TEXT NOT NULL UNIQUE,                     -- lien secret du bénévole
  status TEXT NOT NULL DEFAULT 'planned',         -- planned | in_progress | done
  started_at TEXT,
  finished_at TEXT,
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  FOREIGN KEY (event_id) REFERENCES unsold_events(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_unsold_tours_event ON unsold_tours(event_id);

CREATE TABLE IF NOT EXISTS unsold_donations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  recipient_id INTEGER NOT NULL,
  handed_by TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  FOREIGN KEY (event_id) REFERENCES unsold_events(id) ON DELETE CASCADE,
  FOREIGN KEY (recipient_id) REFERENCES unsold_recipients(id)
);
CREATE INDEX IF NOT EXISTS idx_unsold_donations_event ON unsold_donations(event_id);

CREATE TABLE IF NOT EXISTS unsold_offers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  token TEXT NOT NULL,                            -- lien de suivi du donateur (partagé par ses propositions)
  donor_name TEXT NOT NULL,
  donor_email TEXT NOT NULL DEFAULT '',
  donor_phone TEXT NOT NULL DEFAULT '',
  stand_ref TEXT NOT NULL DEFAULT '',             -- emplacement dans le vide-grenier
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT 'autre',
  quantity INTEGER NOT NULL DEFAULT 1,
  size TEXT NOT NULL DEFAULT 'M',                 -- S | M | L : volume de l'ensemble
  photo TEXT,
  handover TEXT NOT NULL DEFAULT 'self',          -- self (j'apporte) | pickup (à récupérer à mon stand)
  pickup_note TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'proposed',        -- proposed | picked | at_point | taken | donated | withdrawn
  confirmed_at TEXT,                              -- le donateur a confirmé « c'est bien un invendu »
  tour_id INTEGER,
  donation_id INTEGER,
  admin_note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY (event_id) REFERENCES unsold_events(id) ON DELETE CASCADE,
  FOREIGN KEY (tour_id) REFERENCES unsold_tours(id) ON DELETE SET NULL,
  FOREIGN KEY (donation_id) REFERENCES unsold_donations(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_unsold_offers_event_status ON unsold_offers(event_id, status);
CREATE INDEX IF NOT EXISTS idx_unsold_offers_token ON unsold_offers(token);
CREATE INDEX IF NOT EXISTS idx_unsold_offers_tour ON unsold_offers(tour_id);

CREATE TABLE IF NOT EXISTS unsold_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  offer_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  at TEXT NOT NULL,
  actor TEXT NOT NULL,                            -- admin | tour | desk | donor
  from_status TEXT,
  to_status TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  FOREIGN KEY (offer_id) REFERENCES unsold_offers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_unsold_log_offer ON unsold_log(offer_id);
SQL;
}

function unsold_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    // Test préalable en lecture seule : évite de prendre un verrou d'écriture
    // SQLite à chaque requête une fois le schéma en place.
    $exists = db()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'unsold_log'")->fetchColumn();
    if (!$exists) {
        db()->exec(unsold_schema_sql());
    }
    $done = true;
}

unsold_ensure_schema();

/* ======================================================================
 * Temps, saisie, affichage
 * ==================================================================== */

function unsold_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Europe/Paris');
}

function unsold_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', unsold_tz());
}

/** Horodatage stocké en base (heure de Paris, à la seconde). */
function unsold_now_str(): string
{
    return unsold_now()->format('Y-m-d H:i:s');
}

function unsold_parse_dt(string $s): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($s, 0, 16), unsold_tz());
    return $d ?: null;
}

function unsold_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Paramètre GET/POST sous forme de chaîne ('' si absent ou si c'est un tableau). */
function unsold_param(array $src, string $key, string $default = ''): string
{
    return isset($src[$key]) && is_scalar($src[$key]) ? (string) $src[$key] : $default;
}

/**
 * Écarte des entrées POST/GET les valeurs non scalaires (« title[]=x ») : les
 * helpers de saisie attendent des chaînes, et un tableau déclencherait des
 * avertissements « Array to string conversion ». $arrayKeys : clés qui doivent
 * rester des tableaux.
 */
function unsold_scalar_post(array $arrayKeys = []): void
{
    foreach ($_POST as $k => $v) {
        if (is_scalar($v)) continue;
        // Seules les clés explicitement attendues en tableau (ex : « ids » = cases cochées) survivent.
        if (is_array($v) && in_array($k, $arrayKeys, true)) {
            $_POST[$k] = array_values(array_filter($v, 'is_scalar'));
            continue;
        }
        unset($_POST[$k]);
    }
}

/** Texte sur une seule ligne : caractères de contrôle retirés, espaces tassés, longueur bornée. */
function unsold_line($s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($s) ? (string) $s : '') ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

/** Texte multi-lignes : sauts de ligne conservés, caractères de contrôle retirés. */
function unsold_text($s, int $max): string
{
    $s = str_replace(["\r\n", "\r"], "\n", is_scalar($s) ? (string) $s : '');
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

function unsold_fr_date(string $ymd): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), unsold_tz());
    if (!$d) return $ymd;
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $days[(int) $d->format('w')] . ' ' . $d->format('j') . ' ' . $months[(int) $d->format('n')] . ' ' . $d->format('Y');
}

/** « 2026-11-08 16:00 » ou « 16:00 » → « 16h00 ». */
function unsold_fr_time(string $s): string
{
    if (preg_match('/(\d{2}):(\d{2})(?::\d{2})?$/', trim($s), $m)) {
        return $m[1] . 'h' . $m[2];
    }
    return $s;
}

/** Horodatage de journal/suivi : « 08/11 16h42 ». */
function unsold_fr_stamp(?string $s): string
{
    if (!$s || !preg_match('/^\d{4}-(\d{2})-(\d{2}) (\d{2}):(\d{2})/', $s, $m)) return '';
    return $m[2] . '/' . $m[1] . ' ' . $m[3] . 'h' . $m[4];
}

/* ======================================================================
 * Référentiels
 * ==================================================================== */

function unsold_categories(): array
{
    return [
        'vetements' => 'Vêtements & accessoires',
        'livres' => 'Livres, BD & disques',
        'jouets' => 'Jouets & jeux',
        'vaisselle' => 'Vaisselle & cuisine',
        'deco' => 'Déco & maison',
        'mobilier' => 'Mobilier',
        'electro' => 'Électro & high-tech',
        'sport' => 'Sport & loisirs',
        'puericulture' => 'Puériculture',
        'autre' => 'Autre',
    ];
}

function unsold_category_label(string $key): string
{
    return unsold_categories()[$key] ?? 'Autre';
}

/** Volume de l'ensemble proposé — sert à remplir le vélo cargo sans le surcharger. */
function unsold_sizes(): array
{
    return [
        'S' => ['label' => 'Petit', 'hint' => 'tient dans un sac', 'units' => 1],
        'M' => ['label' => 'Moyen', 'hint' => 'un carton, un cabas', 'units' => 2],
        'L' => ['label' => 'Encombrant', 'hint' => 'meuble, gros carton', 'units' => 4],
    ];
}

function unsold_offer_units(array $offer): int
{
    return unsold_sizes()[$offer['size']]['units'] ?? 2;
}

function unsold_statuses(): array
{
    return [
        'proposed' => 'Proposé',
        'picked' => 'Récupéré (en route)',
        'at_point' => 'Au point de collecte',
        'taken' => 'Pris par un visiteur',
        'donated' => 'Remis à une donnerie',
        'withdrawn' => 'Retiré',
    ];
}

function unsold_status_badge(string $status): string
{
    $label = unsold_statuses()[$status] ?? $status;
    return '<span class="don-badge don-badge--' . h($status) . '">' . h($label) . '</span>';
}

function unsold_phase_labels(): array
{
    return [
        'draft' => 'Brouillon',
        'proposals' => 'Propositions ouvertes',
        'collecting' => 'Point de collecte ouvert',
        'ended' => 'Collecte terminée',
        'closed' => 'Clôturée',
    ];
}

function unsold_phase_badge(string $phase): string
{
    return '<span class="don-badge don-phase--' . h($phase) . '">' . h(unsold_phase_labels()[$phase] ?? $phase) . '</span>';
}

function unsold_handover_label(string $h): string
{
    return $h === 'pickup' ? 'À récupérer à son stand' : 'Apporté au point par son propriétaire';
}

/* ======================================================================
 * Sécurité des pages
 * ==================================================================== */

/** Pages à lien secret : ni indexées, ni mises en cache, ni jeton divulgué par le Referer. */
function unsold_private_headers(): void
{
    if (headers_sent()) return;
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
}

/** N'accepte comme retour d'une action admin qu'une page admin du module. */
function unsold_safe_return($r, string $default): string
{
    $r = (string) $r;
    if (preg_match('#^/admin/invendus[a-z\-]*\.php(\?[A-Za-z0-9=&_%.+\-]*)?$#', $r)) {
        return $r;
    }
    return $default;
}

/** URL absolue à communiquer (liens secrets à copier-coller). */
function unsold_abs_url(string $path): string
{
    if (defined('SITE_URL') && SITE_URL !== '') {
        return rtrim(SITE_URL, '/') . $path;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https://' : 'http://') . $host . $path;
}

/* ======================================================================
 * Éditions (un vide-grenier = une édition = un point de collecte)
 * ==================================================================== */

function unsold_event_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM unsold_events WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function unsold_event_by_desk_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $stmt = db()->prepare('SELECT * FROM unsold_events WHERE desk_token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function unsold_events_list(): array
{
    return db()->query('SELECT * FROM unsold_events ORDER BY event_date DESC, id DESC')->fetchAll();
}

/**
 * État dans lequel se trouve l'édition, déduit du statut choisi par l'admin et
 * de l'heure : draft → proposals → collecting → ended (→ closed si archivée).
 */
function unsold_event_phase(array $e, ?DateTimeImmutable $now = null): string
{
    if ($e['status'] === 'draft') return 'draft';
    if ($e['status'] === 'closed') return 'closed';
    $now ??= unsold_now();
    $start = unsold_parse_dt($e['collect_start']);
    $end = unsold_parse_dt($e['collect_end']);
    if (!$start || !$end || $now < $start) return 'proposals';
    if ($now < $end) return 'collecting';
    return 'ended';
}

/** Éditions visibles du public : publiées et pas trop anciennes. */
function unsold_public_events(): array
{
    $since = unsold_now()->modify('-2 days')->format('Y-m-d');
    $stmt = db()->prepare("SELECT * FROM unsold_events WHERE status = 'published' AND event_date >= ? ORDER BY event_date ASC, id ASC");
    $stmt->execute([$since]);
    return $stmt->fetchAll();
}

/** L'édition à mettre en avant : celle dont le point est ouvert, sinon la prochaine. */
function unsold_pick_default_event(array $events): ?array
{
    foreach ($events as $e) {
        if (unsold_event_phase($e) === 'collecting') return $e;
    }
    foreach ($events as $e) {
        if (unsold_event_phase($e) === 'proposals') return $e;
    }
    return $events[0] ?? null;
}

/** @return array{0: array, 1: string[]} [données propres, erreurs] */
function unsold_event_from_post(array $p): array
{
    $errors = [];
    $d = [
        'name' => unsold_line($p['name'] ?? '', 120),
        'event_date' => trim((string) ($p['event_date'] ?? '')),
        'location' => unsold_line($p['location'] ?? '', 160),
        'point_name' => unsold_line($p['point_name'] ?? '', 80) ?: 'Point de collecte',
        'point_details' => unsold_text($p['point_details'] ?? '', 400),
        'status' => (string) ($p['status'] ?? 'draft'),
        'notes' => unsold_text($p['notes'] ?? '', 1000),
    ];
    if ($d['name'] === '') $errors[] = 'Le nom de l\'édition est obligatoire.';

    $dateOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d['event_date'], $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    if (!$dateOk) $errors[] = 'La date du vide-grenier est invalide.';

    if (!in_array($d['status'], ['draft', 'published', 'closed'], true)) $d['status'] = 'draft';

    $timeRe = '/^([01]\d|2[0-3]):[0-5]\d$/';
    $startT = trim((string) ($p['collect_start_time'] ?? ''));
    $endT = trim((string) ($p['collect_end_time'] ?? ''));
    if (!preg_match($timeRe, $startT) || !preg_match($timeRe, $endT)) {
        $errors[] = 'Les heures d\'ouverture et de fermeture du point de collecte sont invalides.';
        $d['collect_start'] = $d['collect_end'] = '';
    } else {
        $d['collect_start'] = $d['event_date'] . ' ' . $startT;
        $d['collect_end'] = $d['event_date'] . ' ' . $endT;
        if ($dateOk && $d['collect_end'] <= $d['collect_start']) {
            $errors[] = 'Le point de collecte doit fermer après son heure d\'ouverture.';
        }
    }
    return [$d, $errors];
}

function unsold_event_save(?int $id, array $d): int
{
    if ($id) {
        $stmt = db()->prepare('UPDATE unsold_events SET name = :name, event_date = :event_date, location = :location,
            point_name = :point_name, point_details = :point_details, collect_start = :collect_start,
            collect_end = :collect_end, status = :status, notes = :notes WHERE id = :id');
        $stmt->execute([
            'name' => $d['name'], 'event_date' => $d['event_date'], 'location' => $d['location'],
            'point_name' => $d['point_name'], 'point_details' => $d['point_details'],
            'collect_start' => $d['collect_start'], 'collect_end' => $d['collect_end'],
            'status' => $d['status'], 'notes' => $d['notes'], 'id' => $id,
        ]);
        return $id;
    }
    $stmt = db()->prepare('INSERT INTO unsold_events (name, event_date, location, point_name, point_details,
        collect_start, collect_end, status, desk_token, notes, created_at)
        VALUES (:name, :event_date, :location, :point_name, :point_details, :collect_start, :collect_end,
        :status, :desk_token, :notes, :created_at)');
    $stmt->execute([
        'name' => $d['name'], 'event_date' => $d['event_date'], 'location' => $d['location'],
        'point_name' => $d['point_name'], 'point_details' => $d['point_details'],
        'collect_start' => $d['collect_start'], 'collect_end' => $d['collect_end'],
        'status' => $d['status'], 'desk_token' => unsold_token(), 'notes' => $d['notes'],
        'created_at' => unsold_now_str(),
    ]);
    return (int) db()->lastInsertId();
}

/** Ouvre le point tout de suite (le vide-grenier se termine plus tôt que prévu). */
function unsold_event_open_now(int $id): void
{
    $e = unsold_event_get($id);
    if (!$e) return;
    $now = unsold_now();
    $start = $now->format('Y-m-d H:i');
    $end = $e['collect_end'];
    if ($end <= $start) {
        $end = $now->modify('+3 hours')->format('Y-m-d H:i');
    }
    db()->prepare("UPDATE unsold_events SET collect_start = ?, collect_end = ?, status = 'published' WHERE id = ?")
        ->execute([$start, $end, $id]);
}

/** Ferme le point tout de suite : les visiteurs ne se servent plus, le reliquat part. */
function unsold_event_end_now(int $id): void
{
    $e = unsold_event_get($id);
    if (!$e) return;
    $now = unsold_now()->format('Y-m-d H:i');
    $start = $e['collect_start'] > $now ? $now : $e['collect_start'];
    db()->prepare("UPDATE unsold_events SET collect_start = ?, collect_end = ? WHERE id = ?")
        ->execute([$start, $now, $id]);
}

function unsold_event_delete(int $id): void
{
    $stmt = db()->prepare('SELECT photo FROM unsold_offers WHERE event_id = ? AND photo IS NOT NULL');
    $stmt->execute([$id]);
    $photos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    db()->prepare('DELETE FROM unsold_events WHERE id = ?')->execute([$id]);
    foreach ($photos as $photo) {
        unsold_delete_photo($photo);
    }
}

/* ======================================================================
 * Photos
 * ==================================================================== */

/**
 * Stocke la photo d'une proposition (champ « photo »). Retourne le chemin
 * relatif, null si aucune photo, ou lève une exception avec un message
 * lisible si le fichier est inutilisable.
 */
function unsold_store_photo(string $field = 'photo'): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('La photo n\'a pas pu être envoyée (fichier trop lourd ?).');
    }
    if ($f['size'] > UNSOLD_MAX_PHOTO_BYTES) {
        throw new RuntimeException('La photo dépasse 8 Mo.');
    }
    if (!is_uploaded_file($f['tmp_name']) && !(defined('UNSOLD_TESTING') && UNSOLD_TESTING)) {
        throw new RuntimeException('Fichier invalide.');
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        throw new RuntimeException('La photo doit être une image JPEG, PNG, WebP ou GIF.');
    }
    // Garde-fou mémoire : une image de 12 000 × 12 000 px se décompresse en plusieurs Go.
    if ($info[0] * $info[1] > 40_000_000) {
        throw new RuntimeException('La photo est trop grande (plus de 40 mégapixels).');
    }
    $path = store_uploaded_photo($field, 'don', 900, 75);
    if ($path === null) {
        throw new RuntimeException('La photo n\'a pas pu être traitée.');
    }
    return $path;
}

function unsold_delete_photo(?string $path): void
{
    // Ne supprime que les fichiers créés par ce module.
    if ($path && preg_match('#^uploads/don-[A-Za-z0-9]+\.jpg$#', $path)) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }
}

/* ======================================================================
 * Propositions
 * ==================================================================== */

function unsold_offer_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM unsold_offers WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Toutes les propositions d'un donateur (lien de suivi), toutes éditions confondues. */
function unsold_offers_by_token(string $token): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return [];
    $stmt = db()->prepare('SELECT * FROM unsold_offers WHERE token = ? ORDER BY event_id, id');
    $stmt->execute([$token]);
    return $stmt->fetchAll();
}

/**
 * Valide la saisie d'une proposition. Côté admin (saisie au stand), consentement et
 * coordonnées peuvent être facultatifs ; côté public ils sont obligatoires.
 * @return array{0: array, 1: string[]} [données propres, erreurs]
 */
function unsold_offer_from_post(array $p, bool $requireConsent = true, bool $requireContact = true): array
{
    $errors = [];
    $d = [
        'donor_name' => unsold_line($p['donor_name'] ?? '', 80),
        'donor_email' => unsold_line($p['donor_email'] ?? '', 120),
        'donor_phone' => unsold_line($p['donor_phone'] ?? '', 20),
        'stand_ref' => unsold_line($p['stand_ref'] ?? '', 20),
        'title' => unsold_line($p['title'] ?? '', 100),
        'description' => unsold_text($p['description'] ?? '', 600),
        'category' => (string) ($p['category'] ?? 'autre'),
        'quantity' => (int) ($p['quantity'] ?? 1),
        'size' => (string) ($p['size'] ?? 'M'),
        'handover' => (string) ($p['handover'] ?? 'self'),
        'pickup_note' => unsold_line($p['pickup_note'] ?? '', 200),
    ];

    if (mb_strlen($d['donor_name']) < 2) $errors[] = 'Indiquez votre nom ou prénom.';
    if (mb_strlen($d['title']) < 3) $errors[] = 'Décrivez l\'objet en quelques mots (3 caractères minimum).';

    if ($d['donor_email'] !== '' && !filter_var($d['donor_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'L\'adresse e-mail n\'a pas l\'air valide.';
    }
    if ($d['donor_phone'] !== '' && !preg_match('/^[+0-9 ().\-]{6,20}$/', $d['donor_phone'])) {
        $errors[] = 'Le numéro de téléphone n\'a pas l\'air valide.';
    }
    if ($requireContact && $d['donor_email'] === '' && $d['donor_phone'] === '') {
        $errors[] = 'Laissez un téléphone ou un e-mail pour que les bénévoles puissent vous joindre.';
    }

    if (!isset(unsold_categories()[$d['category']])) $d['category'] = 'autre';
    if (!isset(unsold_sizes()[$d['size']])) $d['size'] = 'M';
    if (!in_array($d['handover'], ['self', 'pickup'], true)) $d['handover'] = 'self';
    $d['quantity'] = max(1, min(999, $d['quantity']));

    if ($d['handover'] === 'pickup' && $d['stand_ref'] === '') {
        $errors[] = 'Indiquez votre numéro d\'emplacement pour que les bénévoles vous trouvent.';
    }
    if ($requireConsent && empty($p['consent'])) {
        $errors[] = 'Cochez la case d\'accord pour valider votre proposition.';
    }
    return [$d, $errors];
}

/** @return array{id: int, token: string} */
function unsold_offer_create(int $eventId, array $d, ?string $token = null, ?string $photo = null, string $actor = 'donor'): array
{
    $token = ($token && preg_match('/^[a-f0-9]{32}$/', $token)) ? $token : unsold_token();
    $now = unsold_now_str();
    $stmt = db()->prepare('INSERT INTO unsold_offers (event_id, token, donor_name, donor_email, donor_phone, stand_ref,
        title, description, category, quantity, size, photo, handover, pickup_note, status, created_at, updated_at)
        VALUES (:event_id, :token, :donor_name, :donor_email, :donor_phone, :stand_ref, :title, :description,
        :category, :quantity, :size, :photo, :handover, :pickup_note, \'proposed\', :now, :now2)');
    $stmt->execute([
        'event_id' => $eventId, 'token' => $token,
        'donor_name' => $d['donor_name'], 'donor_email' => $d['donor_email'], 'donor_phone' => $d['donor_phone'],
        'stand_ref' => $d['stand_ref'], 'title' => $d['title'], 'description' => $d['description'],
        'category' => $d['category'], 'quantity' => $d['quantity'], 'size' => $d['size'], 'photo' => $photo,
        'handover' => $d['handover'], 'pickup_note' => $d['pickup_note'], 'now' => $now, 'now2' => $now,
    ]);
    $id = (int) db()->lastInsertId();
    unsold_log($id, $eventId, $actor, null, 'proposed', 'Proposition créée');
    return ['id' => $id, 'token' => $token];
}

/** Nombre de propositions reçues dans la dernière heure (disjoncteur anti-spam). */
function unsold_recent_offer_count(int $eventId): int
{
    $since = unsold_now()->modify('-1 hour')->format('Y-m-d H:i:s');
    $stmt = db()->prepare('SELECT COUNT(*) FROM unsold_offers WHERE event_id = ? AND created_at >= ?');
    $stmt->execute([$eventId, $since]);
    return (int) $stmt->fetchColumn();
}

/**
 * Liste filtrée pour l'admin. Filtres : status, handover, tour (« none » ou id),
 * category, confirmed, q (nom, objet, emplacement).
 */
function unsold_offers_list(int $eventId, array $f = []): array
{
    $where = ['o.event_id = :eid'];
    $p = ['eid' => $eventId];

    if (!empty($f['status']) && isset(unsold_statuses()[$f['status']])) {
        $where[] = 'o.status = :st';
        $p['st'] = $f['status'];
    }
    if (!empty($f['handover']) && in_array($f['handover'], ['self', 'pickup'], true)) {
        $where[] = 'o.handover = :ho';
        $p['ho'] = $f['handover'];
    }
    if (isset($f['tour']) && $f['tour'] !== '') {
        if ($f['tour'] === 'none') {
            $where[] = 'o.tour_id IS NULL';
        } elseif ((int) $f['tour'] > 0) {
            $where[] = 'o.tour_id = :tid';
            $p['tid'] = (int) $f['tour'];
        }
    }
    if (!empty($f['category']) && isset(unsold_categories()[$f['category']])) {
        $where[] = 'o.category = :cat';
        $p['cat'] = $f['category'];
    }
    if (!empty($f['confirmed'])) {
        $where[] = 'o.confirmed_at IS NOT NULL';
    }
    $q = unsold_line($f['q'] ?? '', 60);
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = "(o.title LIKE :q1 ESCAPE '\\' OR o.donor_name LIKE :q2 ESCAPE '\\' OR o.stand_ref LIKE :q3 ESCAPE '\\')";
        $p['q1'] = $p['q2'] = $p['q3'] = $like;
    }

    $sql = 'SELECT o.*, t.volunteer_name AS tour_name FROM unsold_offers o
            LEFT JOIN unsold_tours t ON t.id = o.tour_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY o.created_at DESC, o.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/** Tri « naturel » par emplacement (A2 avant A10), pour enchaîner les stands dans l'ordre. */
function unsold_sort_by_stand(array $rows): array
{
    usort($rows, function ($a, $b) {
        $c = strnatcasecmp((string) $a['stand_ref'], (string) $b['stand_ref']);
        return $c !== 0 ? $c : ((int) $a['id'] <=> (int) $b['id']);
    });
    return $rows;
}

/** Ce que voient les visiteurs : uniquement ce qui est réellement au point, sans donnée personnelle. */
function unsold_available_offers(int $eventId, string $category = '', string $q = ''): array
{
    $where = ["event_id = :eid", "status = 'at_point'"];
    $p = ['eid' => $eventId];
    if ($category !== '' && isset(unsold_categories()[$category])) {
        $where[] = 'category = :cat';
        $p['cat'] = $category;
    }
    $q = unsold_line($q, 60);
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = "(title LIKE :q1 ESCAPE '\\' OR description LIKE :q2 ESCAPE '\\')";
        $p['q1'] = $p['q2'] = $like;
    }
    $stmt = db()->prepare('SELECT id, title, description, category, quantity, size, photo, updated_at
        FROM unsold_offers WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC');
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/** @return array{counts: array<string,int>, total: int, active: int, saved: int, awaiting_pickup: int, confirmed: int, units_at_point: int} */
function unsold_event_stats(int $eventId): array
{
    $counts = array_fill_keys(array_keys(unsold_statuses()), 0);
    $stmt = db()->prepare('SELECT status, COUNT(*) FROM unsold_offers WHERE event_id = ? GROUP BY status');
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$status, $n]) {
        $counts[$status] = (int) $n;
    }
    $total = array_sum($counts);

    $stmt = db()->prepare("SELECT COUNT(*) FROM unsold_offers WHERE event_id = ? AND status = 'proposed' AND handover = 'pickup' AND tour_id IS NULL");
    $stmt->execute([$eventId]);
    $awaiting = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM unsold_offers WHERE event_id = ? AND status = 'proposed' AND confirmed_at IS NOT NULL");
    $stmt->execute([$eventId]);
    $confirmed = (int) $stmt->fetchColumn();

    $units = 0;
    $stmt = db()->prepare("SELECT size, COUNT(*) FROM unsold_offers WHERE event_id = ? AND status = 'at_point' GROUP BY size");
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$size, $n]) {
        $units += (unsold_sizes()[$size]['units'] ?? 2) * (int) $n;
    }

    return [
        'counts' => $counts,
        'total' => $total,
        // Encore à traiter : pas encore pris, donné ni retiré.
        'active' => $counts['proposed'] + $counts['picked'] + $counts['at_point'],
        // Objets sauvés du rebut : pris par un visiteur ou remis à une donnerie.
        'saved' => $counts['taken'] + $counts['donated'],
        'awaiting_pickup' => $awaiting,
        'confirmed' => $confirmed,
        'units_at_point' => $units,
    ];
}

/* ======================================================================
 * Cycle de vie d'une proposition
 * ==================================================================== */

/**
 * Transitions permises, et qui a le droit de les faire :
 *   admin = organisation, tour = bénévole de tournée, desk = bénévole du point,
 *   donor = l'exposant via son lien de suivi.
 * Les « retours en arrière » existent exprès : un doigt qui glisse sur un
 * téléphone, en plein vide-grenier, doit pouvoir se rattraper.
 */
function unsold_transition_rules(): array
{
    return [
        'proposed' => [
            'picked' => ['admin', 'tour'],
            'at_point' => ['admin', 'desk'],
            'withdrawn' => ['admin', 'tour', 'donor', 'desk'],
        ],
        'picked' => [
            'proposed' => ['admin', 'tour'],
            'at_point' => ['admin', 'desk', 'tour'],
            'withdrawn' => ['admin', 'tour'],
        ],
        'at_point' => [
            'taken' => ['admin', 'desk'],
            'donated' => ['admin'],
            'withdrawn' => ['admin', 'desk'],
        ],
        'taken' => ['at_point' => ['admin', 'desk']],
        'donated' => ['at_point' => ['admin']],
        'withdrawn' => ['proposed' => ['admin', 'tour']],
    ];
}

/**
 * Change le statut d'une proposition si la transition est permise à cet acteur.
 * La mise à jour est conditionnée à l'ancien statut : deux bénévoles qui
 * cliquent en même temps ne produisent pas deux transitions.
 * L'appelant vérifie lui-même que l'acteur est autorisé sur CETTE proposition
 * (bonne tournée, bon jeton…).
 */
function unsold_transition(int $offerId, string $to, string $actor, string $note = '', array $extra = []): bool
{
    $o = unsold_offer_get($offerId);
    if (!$o) return false;
    $from = $o['status'];
    $allowed = unsold_transition_rules()[$from][$to] ?? null;
    if ($allowed === null || !in_array($actor, $allowed, true)) return false;

    $sets = ['status = :to', 'updated_at = :now'];
    $p = ['to' => $to, 'now' => unsold_now_str(), 'id' => $offerId, 'from' => $from];
    // Restauré par l'organisation : l'objet repart dans le lot « à affecter ».
    // Restauré depuis une tournée (clic « plus dispo » par erreur) : il y reste.
    if ($from === 'withdrawn' && empty($extra['keep_tour'])) $sets[] = 'tour_id = NULL';
    if ($from === 'donated') $sets[] = 'donation_id = NULL';
    if ($to === 'donated') {
        $sets[] = 'donation_id = :did';
        $p['did'] = !empty($extra['donation_id']) ? (int) $extra['donation_id'] : null;
    }
    $stmt = db()->prepare('UPDATE unsold_offers SET ' . implode(', ', $sets) . ' WHERE id = :id AND status = :from');
    $stmt->execute($p);
    if ($stmt->rowCount() !== 1) return false;

    unsold_log($offerId, (int) $o['event_id'], $actor, $from, $to, $note);
    return true;
}

/** Applique une transition à plusieurs propositions d'une même édition ; retourne le nombre de réussites. */
function unsold_transition_many(array $offerIds, int $eventId, string $to, string $actor, string $note = ''): int
{
    $n = 0;
    foreach (array_unique(array_map('intval', $offerIds)) as $id) {
        $o = unsold_offer_get($id);
        if ($o && (int) $o['event_id'] === $eventId && unsold_transition($id, $to, $actor, $note)) $n++;
    }
    return $n;
}

/**
 * Dépôt spontané au point de collecte (quelqu'un arrive avec un sac sans avoir
 * rien proposé avant) : la proposition est créée et passe directement « au point ».
 * @return int|null id, ou null si la saisie est inutilisable
 */
function unsold_offer_quick_add(int $eventId, string $title, string $category, string $size, string $donorName = ''): ?int
{
    $title = unsold_line($title, 100);
    if (mb_strlen($title) < 3) return null;
    $d = [
        'donor_name' => unsold_line($donorName, 80) ?: 'Dépôt sur place',
        'donor_email' => '', 'donor_phone' => '', 'stand_ref' => '',
        'title' => $title, 'description' => '',
        'category' => isset(unsold_categories()[$category]) ? $category : 'autre',
        'quantity' => 1,
        'size' => isset(unsold_sizes()[$size]) ? $size : 'M',
        'handover' => 'self', 'pickup_note' => '',
    ];
    $created = unsold_offer_create($eventId, $d, null, null, 'desk');
    unsold_transition($created['id'], 'at_point', 'desk', 'Dépôt spontané au point');
    return $created['id'];
}

function unsold_confirm_offer(int $offerId, string $actor = 'donor'): bool
{
    $stmt = db()->prepare("UPDATE unsold_offers SET confirmed_at = ?, updated_at = ? WHERE id = ? AND status = 'proposed' AND confirmed_at IS NULL");
    $now = unsold_now_str();
    $stmt->execute([$now, $now, $offerId]);
    if ($stmt->rowCount() !== 1) return false;
    $o = unsold_offer_get($offerId);
    unsold_log($offerId, (int) $o['event_id'], $actor, 'proposed', 'proposed', 'Invendu confirmé');
    return true;
}

function unsold_unconfirm_offer(int $offerId): bool
{
    $stmt = db()->prepare("UPDATE unsold_offers SET confirmed_at = NULL, updated_at = ? WHERE id = ? AND status = 'proposed' AND confirmed_at IS NOT NULL");
    $stmt->execute([unsold_now_str(), $offerId]);
    return $stmt->rowCount() === 1;
}

function unsold_delete_offer(int $offerId): void
{
    $o = unsold_offer_get($offerId);
    if (!$o) return;
    db()->prepare('DELETE FROM unsold_offers WHERE id = ?')->execute([$offerId]);
    unsold_delete_photo($o['photo']);
}

function unsold_log(int $offerId, int $eventId, string $actor, ?string $from, string $to, string $note = ''): void
{
    db()->prepare('INSERT INTO unsold_log (offer_id, event_id, at, actor, from_status, to_status, note) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$offerId, $eventId, unsold_now_str(), $actor, $from, $to, mb_substr($note, 0, 200)]);
}

function unsold_offer_log(int $offerId): array
{
    $stmt = db()->prepare('SELECT * FROM unsold_log WHERE offer_id = ? ORDER BY id');
    $stmt->execute([$offerId]);
    return $stmt->fetchAll();
}

/* ======================================================================
 * Tournées (bénévoles à vélo cargo)
 * ==================================================================== */

function unsold_tour_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM unsold_tours WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function unsold_tour_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $stmt = db()->prepare('SELECT * FROM unsold_tours WHERE token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function unsold_tour_status_labels(): array
{
    return ['planned' => 'Prévue', 'in_progress' => 'En cours', 'done' => 'Terminée'];
}

/** Tournées d'une édition, avec leur charge (unités de volume affectées / déjà chargées). */
function unsold_tours_list(int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM unsold_tours WHERE event_id = ? ORDER BY id');
    $stmt->execute([$eventId]);
    $tours = $stmt->fetchAll();
    foreach ($tours as &$t) {
        $t = array_merge($t, unsold_tour_load((int) $t['id']));
    }
    return $tours;
}

/** @return array{stops: int, items: int, units_planned: int, units_picked: int} */
function unsold_tour_load(int $tourId): array
{
    $stmt = db()->prepare("SELECT stand_ref, size, status FROM unsold_offers WHERE tour_id = ? AND status IN ('proposed', 'picked', 'at_point')");
    $stmt->execute([$tourId]);
    $stands = [];
    $items = $planned = $picked = 0;
    foreach ($stmt->fetchAll() as $o) {
        $u = unsold_offer_units($o);
        $items++;
        $stands[mb_strtolower($o['stand_ref'])] = true;
        $planned += $u;
        if ($o['status'] !== 'proposed') $picked += $u;
    }
    return ['stops' => count($stands), 'items' => $items, 'units_planned' => $planned, 'units_picked' => $picked];
}

function unsold_tour_from_post(array $p): array
{
    $errors = [];
    $d = [
        'volunteer_name' => unsold_line($p['volunteer_name'] ?? '', 80),
        'volunteer_phone' => unsold_line($p['volunteer_phone'] ?? '', 20),
        'vehicle' => unsold_line($p['vehicle'] ?? '', 60) ?: 'Vélo cargo',
        'capacity' => (int) ($p['capacity'] ?? 12),
        'notes' => unsold_text($p['notes'] ?? '', 500),
    ];
    if (mb_strlen($d['volunteer_name']) < 2) $errors[] = 'Le nom du bénévole est obligatoire.';
    if ($d['volunteer_phone'] !== '' && !preg_match('/^[+0-9 ().\-]{6,20}$/', $d['volunteer_phone'])) {
        $errors[] = 'Le téléphone du bénévole n\'a pas l\'air valide.';
    }
    $d['capacity'] = max(1, min(200, $d['capacity']));
    return [$d, $errors];
}

function unsold_tour_create(int $eventId, array $d): int
{
    db()->prepare('INSERT INTO unsold_tours (event_id, volunteer_name, volunteer_phone, vehicle, capacity, token, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$eventId, $d['volunteer_name'], $d['volunteer_phone'], $d['vehicle'], $d['capacity'], unsold_token(), $d['notes'], unsold_now_str()]);
    return (int) db()->lastInsertId();
}

function unsold_tour_update(int $tourId, array $d): void
{
    db()->prepare('UPDATE unsold_tours SET volunteer_name = ?, volunteer_phone = ?, vehicle = ?, capacity = ?, notes = ? WHERE id = ?')
        ->execute([$d['volunteer_name'], $d['volunteer_phone'], $d['vehicle'], $d['capacity'], $d['notes'], $tourId]);
}

/** Supprime une tournée non terminée ; ses propositions retournent dans le lot « à affecter ». */
function unsold_tour_delete(int $tourId): bool
{
    $t = unsold_tour_get($tourId);
    if (!$t || $t['status'] === 'done') return false;
    // Une proposition déjà ramassée mais pas encore déposée ne doit pas rester coincée :
    // on la remet « proposée » avant de dissocier la tournée.
    $stmt = db()->prepare("SELECT id FROM unsold_offers WHERE tour_id = ? AND status = 'picked'");
    $stmt->execute([$tourId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        unsold_transition((int) $id, 'proposed', 'admin', 'Tournée supprimée');
    }
    db()->prepare('DELETE FROM unsold_tours WHERE id = ?')->execute([$tourId]);
    return true;
}

/** Affecte des propositions « proposées » de la même édition à une tournée. */
function unsold_tour_assign(int $tourId, array $offerIds): int
{
    $t = unsold_tour_get($tourId);
    if (!$t || $t['status'] === 'done') return 0;
    $n = 0;
    $upd = db()->prepare("UPDATE unsold_offers SET tour_id = ?, updated_at = ? WHERE id = ? AND event_id = ? AND status = 'proposed' AND (tour_id IS NULL OR tour_id != ?)");
    foreach (array_unique(array_map('intval', $offerIds)) as $id) {
        $upd->execute([$tourId, unsold_now_str(), $id, $t['event_id'], $tourId]);
        if ($upd->rowCount() === 1) {
            unsold_log($id, (int) $t['event_id'], 'admin', 'proposed', 'proposed', 'Affecté à la tournée de ' . $t['volunteer_name']);
            $n++;
        }
    }
    return $n;
}

/** Retire des propositions (encore « proposées ») de leur tournée. */
function unsold_tour_unassign(array $offerIds, int $eventId): int
{
    $n = 0;
    $upd = db()->prepare("UPDATE unsold_offers SET tour_id = NULL, updated_at = ? WHERE id = ? AND event_id = ? AND status = 'proposed' AND tour_id IS NOT NULL");
    foreach (array_unique(array_map('intval', $offerIds)) as $id) {
        $upd->execute([unsold_now_str(), $id, $eventId]);
        $n += $upd->rowCount();
    }
    return $n;
}

/** Propositions d'une tournée, triées par emplacement (ordre de passage). */
function unsold_tour_offers(int $tourId): array
{
    $stmt = db()->prepare('SELECT * FROM unsold_offers WHERE tour_id = ?');
    $stmt->execute([$tourId]);
    return unsold_sort_by_stand($stmt->fetchAll());
}

function unsold_tour_start(int $tourId): bool
{
    $stmt = db()->prepare("UPDATE unsold_tours SET status = 'in_progress', started_at = COALESCE(started_at, ?) WHERE id = ? AND status = 'planned'");
    $stmt->execute([unsold_now_str(), $tourId]);
    return $stmt->rowCount() === 1;
}

/**
 * Termine la tournée : tout ce qui a été ramassé est déposé au point de
 * collecte ; ce qui n'a pas été visité retourne dans le lot « à affecter ».
 * @return array{moved: int, released: int}|null null si déjà terminée
 */
function unsold_tour_finish(int $tourId, string $actor): ?array
{
    $t = unsold_tour_get($tourId);
    if (!$t || $t['status'] === 'done') return null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $moved = 0;
        $stmt = $pdo->prepare("SELECT id FROM unsold_offers WHERE tour_id = ? AND status = 'picked'");
        $stmt->execute([$tourId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (unsold_transition((int) $id, 'at_point', $actor, 'Tournée terminée, déposé au point')) $moved++;
        }
        $rel = $pdo->prepare("UPDATE unsold_offers SET tour_id = NULL, updated_at = ? WHERE tour_id = ? AND status = 'proposed'");
        $rel->execute([unsold_now_str(), $tourId]);
        $released = $rel->rowCount();
        $pdo->prepare("UPDATE unsold_tours SET status = 'done', started_at = COALESCE(started_at, ?), finished_at = ? WHERE id = ?")
            ->execute([unsold_now_str(), unsold_now_str(), $tourId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['moved' => $moved, 'released' => $released];
}

function unsold_tour_reopen(int $tourId): bool
{
    $stmt = db()->prepare("UPDATE unsold_tours SET status = 'in_progress', finished_at = NULL WHERE id = ? AND status = 'done'");
    $stmt->execute([$tourId]);
    return $stmt->rowCount() === 1;
}

/* ======================================================================
 * Donneries (structures qui reçoivent le reliquat) et remises
 * ==================================================================== */

function unsold_recipients_list(bool $activeOnly = false): array
{
    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    return db()->query("SELECT r.*, (SELECT COUNT(*) FROM unsold_donations d WHERE d.recipient_id = r.id) AS donations_count
        FROM unsold_recipients r $where ORDER BY is_active DESC, name COLLATE NOCASE")->fetchAll();
}

function unsold_recipient_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM unsold_recipients WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function unsold_recipient_from_post(array $p): array
{
    $errors = [];
    $d = [
        'name' => unsold_line($p['name'] ?? '', 120),
        'address' => unsold_line($p['address'] ?? '', 200),
        'phone' => unsold_line($p['phone'] ?? '', 20),
        'email' => unsold_line($p['email'] ?? '', 120),
        'accepts' => unsold_text($p['accepts'] ?? '', 400),
        'hours' => unsold_line($p['hours'] ?? '', 200),
    ];
    if (mb_strlen($d['name']) < 2) $errors[] = 'Le nom de la structure est obligatoire.';
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'L\'e-mail de la structure n\'est pas valide.';
    if ($d['phone'] !== '' && !preg_match('/^[+0-9 ().\-]{6,20}$/', $d['phone'])) $errors[] = 'Le téléphone de la structure n\'est pas valide.';
    return [$d, $errors];
}

function unsold_recipient_save(?int $id, array $d): int
{
    if ($id) {
        db()->prepare('UPDATE unsold_recipients SET name = ?, address = ?, phone = ?, email = ?, accepts = ?, hours = ? WHERE id = ?')
            ->execute([$d['name'], $d['address'], $d['phone'], $d['email'], $d['accepts'], $d['hours'], $id]);
        return $id;
    }
    db()->prepare('INSERT INTO unsold_recipients (name, address, phone, email, accepts, hours, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$d['name'], $d['address'], $d['phone'], $d['email'], $d['accepts'], $d['hours'], unsold_now_str()]);
    return (int) db()->lastInsertId();
}

/** Supprime une structure jamais utilisée ; sinon la désactive (l'historique des remises reste lisible). */
function unsold_recipient_delete(int $id): string
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM unsold_donations WHERE recipient_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        db()->prepare('UPDATE unsold_recipients SET is_active = 0 WHERE id = ?')->execute([$id]);
        return 'deactivated';
    }
    db()->prepare('DELETE FROM unsold_recipients WHERE id = ?')->execute([$id]);
    return 'deleted';
}

/**
 * Remet des objets présents au point (« at_point ») à une donnerie.
 * $offerIds = null → tout le reliquat de l'édition.
 * @return int|null id de la remise (bordereau), null si rien à remettre
 */
function unsold_donation_create(int $eventId, int $recipientId, ?array $offerIds, string $handedBy, string $note): ?int
{
    $recipient = unsold_recipient_get($recipientId);
    if (!$recipient) return null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($offerIds === null) {
            $stmt = $pdo->prepare("SELECT id FROM unsold_offers WHERE event_id = ? AND status = 'at_point'");
            $stmt->execute([$eventId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $ids = array_values(array_unique(array_map('intval', $offerIds)));
        }
        if (!$ids) {
            $pdo->rollBack();
            return null;
        }
        $pdo->prepare('INSERT INTO unsold_donations (event_id, recipient_id, handed_by, note, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$eventId, $recipientId, unsold_line($handedBy, 80), unsold_text($note, 400), unsold_now_str()]);
        $donationId = (int) $pdo->lastInsertId();

        $moved = 0;
        foreach ($ids as $id) {
            $o = unsold_offer_get($id);
            if ($o && (int) $o['event_id'] === $eventId
                && unsold_transition($id, 'donated', 'admin', 'Remis à ' . $recipient['name'], ['donation_id' => $donationId])) {
                $moved++;
            }
        }
        if ($moved === 0) {
            $pdo->rollBack();
            return null;
        }
        $pdo->commit();
        return $donationId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function unsold_donation_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT d.*, r.name AS recipient_name, r.address AS recipient_address, r.phone AS recipient_phone
        FROM unsold_donations d JOIN unsold_recipients r ON r.id = d.recipient_id WHERE d.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function unsold_donation_offers(int $donationId): array
{
    $stmt = db()->prepare('SELECT * FROM unsold_offers WHERE donation_id = ? ORDER BY category, title COLLATE NOCASE');
    $stmt->execute([$donationId]);
    return $stmt->fetchAll();
}

function unsold_donations_list(int $eventId): array
{
    $stmt = db()->prepare('SELECT d.*, r.name AS recipient_name,
            (SELECT COUNT(*) FROM unsold_offers o WHERE o.donation_id = d.id) AS offers_count
        FROM unsold_donations d JOIN unsold_recipients r ON r.id = d.recipient_id
        WHERE d.event_id = ? ORDER BY d.id DESC');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/* ======================================================================
 * Export
 * ==================================================================== */

/**
 * Neutralise l'injection de formules (=, +, -, @) dans une cellule CSV :
 * les textes viennent du public et seront ouverts dans Excel/LibreOffice.
 */
function unsold_csv_cell($v): string
{
    $s = (string) $v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
        $s = "'" . $s;
    }
    return $s;
}
