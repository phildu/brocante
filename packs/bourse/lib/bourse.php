<?php

// Bourse d'échanges de la boutique (plugin) : les visiteurs proposent une pièce à la vente (particulier → commerçant) et le
// commerçant publie ses recherches d'achat. Une réponse à une recherche est enregistrée comme une proposition rattachée
// (parent_id), pour que tout arrive dans la même boîte de réception.
// Table bourse_items (base de la boutique, créée par migrations/001-bourse-items.sql) ; page publique /bourse.php (front/bourse_app.php) ;
// écran d'administration pages/index.php.
// La bourse d'une galerie est un module distinct du socle (includes/gallery-bourse.php, galerie/bourse.php).
defined('PLUGIN_RUNTIME') || exit;

const BOURSE_KINDS = ['offre' => 'Proposition de vente', 'recherche' => 'Recherche d\'achat'];

const BOURSE_STATUS_LABELS = [
    'new' => 'À traiter',
    'discussion' => 'En discussion',
    'accepted' => 'Acceptée',
    'published' => 'Publiée',
    'refused' => 'Écartée',
    'closed' => 'Close',
];

const BOURSE_CATEGORIES = [
    'Mobilier' => 'Mobilier',
    'Céramiques & verrerie' => 'Céramiques & verrerie',
    'Argenterie & métal' => 'Argenterie & métal',
    'Tableaux & cadres' => 'Tableaux & cadres',
    'Objets de vitrine' => 'Objets de vitrine',
    'Luminaires' => 'Luminaires',
    'Textiles & linge' => 'Textiles & linge',
    'Livres & papiers' => 'Livres & papiers',
    'Bijoux & horlogerie' => 'Bijoux & horlogerie',
    'Art de la table' => 'Art de la table',
    'Jouets & jeux' => 'Jouets & jeux',
    'Autre' => 'Autre',
];

// ── Jeton CSRF des formulaires publics ─────────────────────────────────────

function bourse_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) require_once dirname(__DIR__, 3) . '/includes/session.php';
    if (empty($_SESSION['bourse_csrf'])) $_SESSION['bourse_csrf'] = bin2hex(random_bytes(16));
    return (string) $_SESSION['bourse_csrf'];
}

function bourse_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(bourse_csrf_token()) . '">';
}

function bourse_csrf_valid(): bool
{
    return hash_equals(bourse_csrf_token(), (string) ($_POST['csrf'] ?? ''));
}

// ── Libellés et affichage ─────────────────────────────────────────────────

function bourse_kind_label(string $kind): string
{
    return BOURSE_KINDS[$kind] ?? BOURSE_KINDS['offre'];
}

function bourse_status_label(string $status): string
{
    return BOURSE_STATUS_LABELS[$status] ?? $status;
}

function bourse_money(?int $cents): string
{
    if ($cents === null || $cents <= 0) return '';
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

/** Condition d'une pièce proposée (mêmes libellés que les fiches produits). */
function bourse_condition_options(): array
{
    return function_exists('product_condition_options') ? product_condition_options() : [];
}

function bourse_condition_label(string $key): string
{
    if ($key === '') return '';
    foreach (bourse_condition_options() as $options) {
        if (isset($options[$key])) return (string) ($options[$key][0] ?? $key);
    }
    return $key;
}

function bourse_date(string $sqlDate): string
{
    $ts = strtotime($sqlDate . ' UTC');
    return $ts ? date('d/m/Y', $ts) : '';
}

// ── Validation et enregistrement ──────────────────────────────────────────

/**
 * Enregistre une proposition ou une recherche envoyée depuis le site.
 * -> [identifiant ou null, message d'erreur ou null].
 */
function bourse_submit(array $post, array $files = []): array
{
    $kind = (string) ($post['kind'] ?? 'offre');
    if (!isset(BOURSE_KINDS[$kind])) return [null, 'Type de demande inconnu.'];
    $parent = (int) ($post['parent_id'] ?? 0);
    $parentItem = $parent > 0 ? bourse_get($parent) : null;
    if ($parent > 0 && (!$parentItem || $parentItem['kind'] !== 'recherche')) return [null, 'Cette recherche n\'existe plus.'];

    $title = trim((string) ($post['title'] ?? ''));
    if ($parentItem && $title === '') $title = 'Réponse à « ' . $parentItem['title'] . ' »';
    if (mb_strlen($title) < 3) return [null, 'Donnez un titre à votre ' . ($kind === 'recherche' ? 'recherche' : 'proposition') . ' (3 caractères au moins).'];
    $description = trim((string) ($post['description'] ?? ''));
    if (mb_strlen($description) < 10) return [null, 'Décrivez la pièce en quelques mots (10 caractères au moins).'];

    $email = mb_strtolower(trim((string) ($post['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) return [null, 'Indiquez une adresse e-mail valide pour recevoir la réponse.'];
    $name = mb_substr(trim((string) ($post['contact_name'] ?? $post['name'] ?? '')), 0, 80);
    if (mb_strlen($name) < 2) return [null, 'Indiquez votre nom.'];

    $priceRaw = trim((string) ($post['price'] ?? ''));
    $price = null;
    if ($priceRaw !== '') {
        $clean = str_replace([' ', "\u{00a0}", '€'], '', $priceRaw);
        $clean = str_replace(',', '.', $clean);
        if (!is_numeric($clean) || (float) $clean < 0 || (float) $clean > 1000000) return [null, 'Indiquez un prix valide (en euros).'];
        $price = (int) round((float) $clean * 100);
        if ($price === 0) $price = null;
    }
    if (empty($post['consent'])) return [null, 'Cochez la case pour autoriser la transmission de vos coordonnées.'];

    // Pas deux fois la même demande en dix minutes (double clic, envoi répété).
    $dup = db()->prepare("SELECT 1 FROM bourse_items WHERE kind = ? AND email = ? AND title = ? AND created_at >= datetime('now', '-10 minutes')");
    $dup->execute([$kind, $email, $title]);
    if ($dup->fetchColumn()) return [null, 'Votre demande vient d\'être envoyée : nous l\'avons bien reçue.'];

    $photo = null;
    if (!empty($files['photo']['tmp_name']) && (int) $files['photo']['error'] === UPLOAD_ERR_OK) {
        $photo = store_uploaded_photo('photo', 'bourse', 1400, 82);
    }

    $category = trim((string) ($post['category'] ?? ''));
    if ($category !== '' && !isset(BOURSE_CATEGORIES[$category])) $category = 'Autre';
    $condition = $kind === 'offre' ? trim((string) ($post['condition'] ?? '')) : '';

    db()->prepare('INSERT INTO bourse_items (kind, status, parent_id, title, category, description, condition, price_cents, contact_name, email, phone, location, photo, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $kind, 'new', $parentItem ? (int) $parentItem['id'] : null, mb_substr($title, 0, 140), mb_substr($category, 0, 60),
        mb_substr($description, 0, 1600), mb_substr($condition, 0, 40), $price, $name, $email,
        mb_substr(trim((string) ($post['phone'] ?? '')), 0, 40), mb_substr(trim((string) ($post['location'] ?? '')), 0, 80),
        $photo, 'site',
    ]);
    $id = (int) db()->lastInsertId();
    bourse_notify($id);
    return [$id, null];
}

/** Recherche publiée par le commerçant depuis l'administration (visible tout de suite sur la page publique). */
function bourse_create_search(array $post): array
{
    $title = trim((string) ($post['title'] ?? ''));
    if (mb_strlen($title) < 3) return [null, 'Donnez un titre à la recherche.'];
    $description = trim((string) ($post['description'] ?? ''));
    if (mb_strlen($description) < 10) return [null, 'Décrivez ce que vous recherchez (10 caractères au moins).'];
    $priceRaw = trim((string) ($post['price'] ?? ''));
    $price = null;
    if ($priceRaw !== '') {
        $clean = str_replace([' ', "\u{00a0}", '€'], '', $priceRaw);
        $clean = str_replace(',', '.', $clean);
        if (!is_numeric($clean) || (float) $clean < 0 || (float) $clean > 1000000) return [null, 'Indiquez un budget valide (en euros).'];
        $price = (int) round((float) $clean * 100);
        if ($price === 0) $price = null;
    }
    $category = trim((string) ($post['category'] ?? ''));
    if ($category !== '' && !isset(BOURSE_CATEGORIES[$category])) $category = 'Autre';
    db()->prepare("INSERT INTO bourse_items (kind, status, title, category, description, price_cents, contact_name, email, location, source)
        VALUES ('recherche', 'published', ?, ?, ?, ?, ?, ?, ?, 'admin')")->execute([
        mb_substr($title, 0, 140), mb_substr($category, 0, 60), mb_substr($description, 0, 1600), $price,
        mb_substr(trim((string) ($post['contact_name'] ?? '')), 0, 80), mb_substr(trim((string) ($post['email'] ?? '')), 0, 120),
        mb_substr(trim((string) ($post['location'] ?? '')), 0, 80),
    ]);
    return [(int) db()->lastInsertId(), null];
}

function bourse_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM bourse_items WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Pièces de la boîte de réception : filtres 'kind' (offre/recherche), 'status', 'tout'. */
function bourse_list(string $kind = '', string $status = '', bool $all = false): array
{
    $where = [];
    $params = [];
    if (isset(BOURSE_KINDS[$kind])) { $where[] = 'i.kind = ?'; $params[] = $kind; }
    if (isset(BOURSE_STATUS_LABELS[$status])) { $where[] = 'i.status = ?'; $params[] = $status; }
    if (!$all && !isset(BOURSE_STATUS_LABELS[$status])) {
        // Par défaut : ce qui reste à faire (propositions et recherches non publiées).
        $where[] = "i.status IN ('new', 'discussion')";
    }
    $sql = 'SELECT i.*, p.title AS parent_title FROM bourse_items i LEFT JOIN bourse_items p ON p.id = i.parent_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY i.id DESC LIMIT 300';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Recherches publiées, visibles par les visiteurs. */
function bourse_public_searches(int $limit = 40): array
{
    $stmt = db()->prepare("SELECT * FROM bourse_items WHERE kind = 'recherche' AND status = 'published' ORDER BY updated_at DESC, id DESC LIMIT ?");
    $stmt->bindValue(1, max(1, min(100, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function bourse_counts(): array
{
    $out = ['new' => 0, 'discussion' => 0, 'published' => 0, 'total' => 0];
    foreach (db()->query('SELECT status, COUNT(*) AS n FROM bourse_items GROUP BY status')->fetchAll() as $row) {
        $out[(string) $row['status']] = (int) $row['n'];
        $out['total'] += (int) $row['n'];
    }
    return $out;
}

/** Changement d'état depuis l'administration ; les états dépendent du type. */
function bourse_set_status(int $id, string $status): ?string
{
    $item = bourse_get($id);
    if (!$item) return 'Demande introuvable.';
    $allowed = $item['kind'] === 'recherche' ? ['new', 'published', 'closed', 'refused'] : ['new', 'discussion', 'accepted', 'refused', 'closed'];
    if (!in_array($status, $allowed, true)) return 'État impossible pour cette demande.';
    db()->prepare("UPDATE bourse_items SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$status, $id]);
    return null;
}

function bourse_set_note(int $id, string $note): void
{
    db()->prepare('UPDATE bourse_items SET notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([mb_substr(trim($note), 0, 2000), $id]);
}

function bourse_delete(int $id): void
{
    db()->prepare('DELETE FROM bourse_items WHERE id = ?')->execute([$id]);
}

// ── Freins et notifications ────────────────────────────────────────────────

/** Un visiteur ne peut pas envoyer plus de huit demandes par heure. */
function bourse_rate_ok(): bool
{
    $now = time();
    $_SESSION['bourse_sent'] = array_values(array_filter((array) ($_SESSION['bourse_sent'] ?? []), static fn ($t) => $now - (int) $t < 3600));
    return count($_SESSION['bourse_sent']) < 8;
}

function bourse_rate_note(): void
{
    $_SESSION['bourse_sent'][] = time();
}

function bourse_notify(int $id): void
{
    $item = bourse_get($id);
    if (!$item) return;
    if (!function_exists('pricing_admin_emails')) return;
    $site = (string) ((function_exists('get_content') ? (get_content()['site_name'] ?? '') : '') ?: (function_exists('tenant') ? tenant('name') : 'La boutique'));
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $from = 'noreply@' . (str_contains($host, '.') && !str_ends_with($host, '.test') ? $host : 'localhost.localdomain');
    $kind = bourse_kind_label((string) $item['kind']);
    $body = "Nouvelle demande dans la bourse d'échanges :\n\n"
        . $kind . ' : « ' . $item['title'] . " »\n"
        . ($item['category'] !== '' ? 'Catégorie : ' . $item['category'] . "\n" : '')
        . ($item['price_cents'] ? ($item['kind'] === 'recherche' ? 'Budget : ' : 'Prix souhaité : ') . bourse_money((int) $item['price_cents']) . "\n" : '')
        . 'De : ' . ($item['contact_name'] !== '' ? $item['contact_name'] . ' ' : '') . '<' . $item['email'] . '>'
        . ($item['phone'] !== '' ? ' · ' . $item['phone'] : '') . "\n"
        . ($item['location'] !== '' ? 'Lieu : ' . $item['location'] . "\n" : '')
        . "\nDescription :\n" . $item['description'] . "\n"
        . "\nRépondez directement à l'expéditeur, ou retrouvez la demande dans Administration → Bourse d'échanges.\n";
    $subject = '=?UTF-8?B?' . base64_encode('Bourse d\'échanges — ' . $item['title']) . '?=';
    foreach (pricing_admin_emails() as $to) {
        @mail($to, $subject, $body, "From: $site <$from>\r\nReply-To: " . $item['email'] . "\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0");
    }
}
