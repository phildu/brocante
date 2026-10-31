<?php

// Bourse d'échanges d'une galerie commerciale : les visiteurs proposent une pièce à la vente (particulier → exposants / galerie) et
// l'administrateur de la galerie publie ses recherches d'achat (« je recherche des céramiques »). Une réponse à une recherche est
// enregistrée comme une proposition rattachée (parent_id), pour que tout arrive dans la même boîte de réception du portail.
// Données : data/galeries/<identifiant>.bourse.json (jamais envoyé par le déploiement).
// Page publique : galerie/bourse.php ; administration : portail/galerie-bourse.php. Voir aussi la bourse d'une boutique (plugin packs/bourse).

require_once __DIR__ . '/gallery-admin.php';

const GBO_KINDS = ['offre' => 'Proposition de vente', 'recherche' => 'Recherche d\'achat'];

const GBO_STATUS_LABELS = [
    'new' => 'À traiter',
    'discussion' => 'En discussion',
    'accepted' => 'Acceptée',
    'published' => 'Publiée',
    'refused' => 'Écartée',
    'closed' => 'Close',
];

const GBO_CATEGORIES = [
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

const GBO_CONDITIONS = [
    'Neuf' => 'Neuf',
    'Très bon état' => 'Très bon état',
    'Bon état' => 'Bon état',
    'Traces d\'usage' => 'Traces d\'usage',
    'À restaurer' => 'À restaurer',
];

// ── Fichiers de données ─────────────────────────────────────────────────────

function gbo_load(string $slug): array
{
    $data = ga_read($slug, 'bourse', []);
    $items = [];
    foreach ((array) ($data['items'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $item = gbo_normalize($row);
        if ($item) $items[] = $item;
    }
    $nextId = max(1, (int) ($data['next_id'] ?? 1));
    foreach ($items as $item) $nextId = max($nextId, $item['id'] + 1);
    return ['next_id' => $nextId, 'items' => $items];
}

function gbo_normalize(array $row): ?array
{
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) return null;
    $kind = isset(GBO_KINDS[(string) ($row['kind'] ?? '')]) ? (string) $row['kind'] : 'offre';
    $status = isset(GBO_STATUS_LABELS[(string) ($row['status'] ?? '')]) ? (string) $row['status'] : 'new';
    $created = (string) ($row['created_at'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created)) $created = gmdate('Y-m-d H:i:s');
    $updated = (string) ($row['updated_at'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updated)) $updated = $created;
    $price = isset($row['price_cents']) && (int) $row['price_cents'] > 0 ? (int) $row['price_cents'] : null;
    return [
        'id' => $id,
        'kind' => $kind,
        'status' => $status,
        'parent_id' => (int) ($row['parent_id'] ?? 0) ?: null,
        'title' => mb_substr(trim((string) ($row['title'] ?? '')), 0, 140),
        'category' => mb_substr(trim((string) ($row['category'] ?? '')), 0, 60),
        'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 1600),
        'condition' => mb_substr(trim((string) ($row['condition'] ?? '')), 0, 40),
        'price_cents' => $price,
        'contact_name' => mb_substr(trim((string) ($row['contact_name'] ?? '')), 0, 80),
        'email' => mb_substr(mb_strtolower(trim((string) ($row['email'] ?? ''))), 0, 120),
        'phone' => mb_substr(trim((string) ($row['phone'] ?? '')), 0, 40),
        'location' => mb_substr(trim((string) ($row['location'] ?? '')), 0, 80),
        'photo' => preg_match('#^uploads/[A-Za-z0-9._-]+$#', (string) ($row['photo'] ?? '')) ? (string) $row['photo'] : null,
        'notes' => mb_substr(trim((string) ($row['notes'] ?? '')), 0, 2000),
        'created_at' => $created,
        'updated_at' => $updated,
    ];
}

function gbo_save(string $slug, array $data): bool
{
    return ga_write($slug, 'bourse', ['next_id' => (int) ($data['next_id'] ?? 1), 'items' => array_values((array) ($data['items'] ?? []))]);
}

// ── Libellés et affichage ─────────────────────────────────────────────────

function gbo_kind_label(string $kind): string
{
    return GBO_KINDS[$kind] ?? GBO_KINDS['offre'];
}

function gbo_status_label(string $status): string
{
    return GBO_STATUS_LABELS[$status] ?? $status;
}

function gbo_money(?int $cents): string
{
    if ($cents === null || $cents <= 0) return '';
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function gbo_date(string $sqlDate): string
{
    $ts = strtotime($sqlDate . ' UTC');
    return $ts ? date('d/m/Y', $ts) : '';
}

// ── Jeton de formulaire (sans session : la page publique d'une galerie n'appartient à aucun commerce) ──

function gbo_salt(): string
{
    $file = galleries_dir() . '/.bourse-salt';
    $salt = is_file($file) ? (string) @file_get_contents($file) : '';
    if (strlen($salt) < 32) {
        $salt = bin2hex(random_bytes(32));
        if (!is_dir(galleries_dir())) @mkdir(galleries_dir(), 0755, true);
        @file_put_contents($file, $salt, LOCK_EX);
    }
    return $salt;
}

/** Champs cachés `ts` et `sig` : un formulaire laissé ouvert plus de six heures est refusé. */
function gbo_form_fields(string $slug): string
{
    $ts = time();
    $sig = substr(hash_hmac('sha256', $slug . '|' . $ts, gbo_salt()), 0, 24);
    return '<input type="hidden" name="ts" value="' . $ts . '"><input type="hidden" name="sig" value="' . $sig . '">';
}

function gbo_form_valid(string $slug, array $post): bool
{
    $ts = (int) ($post['ts'] ?? 0);
    $sig = (string) ($post['sig'] ?? '');
    if ($ts <= 0 || $ts > time() + 300 || time() - $ts > 6 * 3600) return false;
    return hash_equals(substr(hash_hmac('sha256', $slug . '|' . $ts, gbo_salt()), 0, 24), $sig);
}

// ── Freins et photos ───────────────────────────────────────────────────────

function gbo_rate_file(string $slug): string
{
    return galleries_dir() . '/' . $slug . '.bourse-rate.json';
}

/** Huit demandes par heure et par adresse (léger journal fichier, la page publique n'ouvre pas de session). */
function gbo_rate_check(string $slug): bool
{
    if (!gallery_slug_valid($slug)) return false;
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $key = substr(hash_hmac('sha256', $ip, gbo_salt()), 0, 16);
    $data = is_file(gbo_rate_file($slug)) ? json_decode((string) @file_get_contents(gbo_rate_file($slug)), true) : null;
    $times = array_values(array_filter((array) ((is_array($data) ? $data : [])[$key] ?? []), static fn ($t) => time() - (int) $t < 3600));
    return count($times) < 8;
}

function gbo_rate_note(string $slug): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $key = substr(hash_hmac('sha256', $ip, gbo_salt()), 0, 16);
    $data = is_file(gbo_rate_file($slug)) ? json_decode((string) @file_get_contents(gbo_rate_file($slug)), true) : null;
    $map = [];
    foreach ((array) (is_array($data) ? $data : []) as $k => $times) {
        $keep = array_values(array_filter((array) $times, static fn ($t) => time() - (int) $t < 3600));
        if ($keep) $map[(string) $k] = $keep;
    }
    $map[$key] = array_merge($map[$key] ?? [], [time()]);
    @file_put_contents(gbo_rate_file($slug), json_encode($map), LOCK_EX);
}

/** Enregistre une photo jointe (JPEG, PNG, WebP ou GIF) en JPEG 1400 px, sous uploads/. */
function gbo_store_photo(string $slug, array $file): ?string
{
    if (empty($file['tmp_name']) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    $info = @getimagesize((string) $file['tmp_name']);
    if (!$info) return null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg((string) $file['tmp_name']); break;
        case IMAGETYPE_PNG: $src = imagecreatefrompng((string) $file['tmp_name']); break;
        case IMAGETYPE_WEBP: $src = imagecreatefromwebp((string) $file['tmp_name']); break;
        case IMAGETYPE_GIF: $src = imagecreatefromgif((string) $file['tmp_name']); break;
        default: return null;
    }
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, 1400 / max(1, max($w, $h)));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'galerie-bourse-' . $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
    $ok = imagejpeg($dst, $dir . '/' . $name, 82);
    return $ok ? 'uploads/' . $name : null;
}

// ── Lecture et écriture des demandes ───────────────────────────────────────

function gbo_get(string $slug, int $id): ?array
{
    foreach (gbo_load($slug)['items'] as $item) if ($item['id'] === $id) return $item;
    return null;
}

/** Liste pour le portail : filtres type et état, « à traiter » (new + discussion) par défaut. */
function gbo_list(string $slug, string $kind = '', string $status = '', bool $all = false): array
{
    $out = [];
    foreach (gbo_load($slug)['items'] as $item) {
        if (isset(GBO_KINDS[$kind]) && $item['kind'] !== $kind) continue;
        if (isset(GBO_STATUS_LABELS[$status])) {
            if ($item['status'] !== $status) continue;
        } elseif (!$all && !in_array($item['status'], ['new', 'discussion'], true)) {
            continue;
        }
        $out[] = $item;
    }
    usort($out, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
    return array_slice($out, 0, 300);
}

function gbo_public_searches(string $slug): array
{
    return array_values(array_filter(gbo_load($slug)['items'], static fn (array $i): bool => $i['kind'] === 'recherche' && $i['status'] === 'published'));
}

/** Propositions acceptées que la galerie choisit de montrer (« pièces retenues »). */
function gbo_public_offers(string $slug): array
{
    return array_values(array_filter(gbo_load($slug)['items'], static fn (array $i): bool => $i['kind'] === 'offre' && $i['status'] === 'published'));
}

function gbo_counts(string $slug): array
{
    $out = ['new' => 0, 'discussion' => 0, 'accepted' => 0, 'published' => 0, 'refused' => 0, 'closed' => 0, 'total' => 0];
    foreach (gbo_load($slug)['items'] as $item) {
        $out[$item['status']] = ($out[$item['status']] ?? 0) + 1;
        $out['total']++;
    }
    return $out;
}

/** Enregistre une proposition (ou une recherche créée dans le portail). -> [identifiant, erreur]. */
function gbo_add(string $slug, array $input, ?array $photoFile = null, bool $fromAdmin = false): array
{
    $kind = (string) ($input['kind'] ?? 'offre');
    if (!isset(GBO_KINDS[$kind])) return [null, 'Type de demande inconnu.'];

    $data = gbo_load($slug);
    $parentId = (int) ($input['parent_id'] ?? 0) ?: null;
    $parent = null;
    if ($parentId) {
        foreach ($data['items'] as $item) if ($item['id'] === $parentId) $parent = $item;
        if (!$parent || $parent['kind'] !== 'recherche') return [null, 'Cette recherche n\'existe plus.'];
    }

    $title = trim((string) ($input['title'] ?? ''));
    if ($parent && $title === '') $title = 'Réponse à « ' . $parent['title'] . ' »';
    $description = trim((string) ($input['description'] ?? ''));
    if (mb_strlen($title) < 3) return [null, 'Donnez un titre (3 caractères au moins).'];
    if (mb_strlen($description) < 10) return [null, 'Décrivez la pièce en quelques mots (10 caractères au moins).'];

    $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
    $name = mb_substr(trim((string) ($input['contact_name'] ?? $input['name'] ?? '')), 0, 80);
    if (!$fromAdmin) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) return [null, 'Indiquez une adresse e-mail valide pour recevoir la réponse.'];
        if (mb_strlen($name) < 2) return [null, 'Indiquez votre nom.'];
        if (empty($input['consent'])) return [null, 'Cochez la case pour autoriser la transmission de vos coordonnées.'];
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [null, 'Adresse e-mail invalide.'];
    }

    $price = null;
    $priceRaw = trim((string) ($input['price'] ?? ''));
    if ($priceRaw !== '') {
        $clean = str_replace([' ', "\u{00a0}", '€'], '', $priceRaw);
        $clean = str_replace(',', '.', $clean);
        if (!is_numeric($clean) || (float) $clean < 0 || (float) $clean > 1000000) return [null, 'Indiquez un prix valide (en euros).'];
        $price = (int) round((float) $clean * 100);
        if ($price === 0) $price = null;
    }

    $category = trim((string) ($input['category'] ?? ''));
    if ($category !== '' && !isset(GBO_CATEGORIES[$category])) $category = 'Autre';
    $condition = $kind === 'offre' ? trim((string) ($input['condition'] ?? '')) : '';
    if ($condition !== '' && !isset(GBO_CONDITIONS[$condition])) $condition = '';

    if (!$fromAdmin) {
        // Pas deux fois la même demande en dix minutes (double clic, envoi répété).
        foreach ($data['items'] as $item) {
            if ($item['kind'] === $kind && $item['email'] === $email && $item['title'] === $title
                && strtotime($item['created_at'] . ' UTC') > time() - 600) {
                return [null, 'Votre demande vient d\'être envoyée : nous l\'avons bien reçue.'];
            }
        }
    }

    $photo = $photoFile ? gbo_store_photo($slug, $photoFile) : null;
    $now = gmdate('Y-m-d H:i:s');
    $id = (int) $data['next_id'];
    $data['items'][] = [
        'id' => $id,
        'kind' => $kind,
        'status' => $fromAdmin && $kind === 'recherche' ? 'published' : 'new',
        'parent_id' => $parentId,
        'title' => mb_substr($title, 0, 140),
        'category' => mb_substr($category, 0, 60),
        'description' => mb_substr($description, 0, 1600),
        'condition' => mb_substr($condition, 0, 40),
        'price_cents' => $price,
        'contact_name' => $name,
        'email' => $email,
        'phone' => mb_substr(trim((string) ($input['phone'] ?? '')), 0, 40),
        'location' => mb_substr(trim((string) ($input['location'] ?? '')), 0, 80),
        'photo' => $photo,
        'notes' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $data['next_id'] = $id + 1;
    if (!gbo_save($slug, $data)) return [null, 'Enregistrement impossible pour le moment : réessayez plus tard.'];
    if (!$fromAdmin) gbo_notify($slug, $id);
    return [$id, null];
}

/** Change l'état d'une demande ; les états possibles dépendent du type. */
function gbo_set_status(string $slug, int $id, string $status): ?string
{
    $data = gbo_load($slug);
    foreach ($data['items'] as &$item) {
        if ($item['id'] !== $id) continue;
        $allowed = $item['kind'] === 'recherche'
            ? ['new', 'published', 'closed', 'refused']
            : ['new', 'discussion', 'accepted', 'published', 'refused', 'closed'];
        if (!in_array($status, $allowed, true)) return 'État impossible pour cette demande.';
        $item['status'] = $status;
        $item['updated_at'] = gmdate('Y-m-d H:i:s');
        return gbo_save($slug, $data) ? null : 'Enregistrement impossible.';
    }
    return 'Demande introuvable.';
}

function gbo_set_note(string $slug, int $id, string $note): bool
{
    $data = gbo_load($slug);
    foreach ($data['items'] as &$item) {
        if ($item['id'] !== $id) continue;
        $item['notes'] = mb_substr(trim($note), 0, 2000);
        $item['updated_at'] = gmdate('Y-m-d H:i:s');
        return gbo_save($slug, $data);
    }
    return false;
}

function gbo_delete(string $slug, int $id): bool
{
    $data = gbo_load($slug);
    $before = count($data['items']);
    $data['items'] = array_values(array_filter($data['items'], static fn (array $i): bool => $i['id'] !== $id));
    return count($data['items']) < $before && gbo_save($slug, $data);
}

// ── Notification des administrateurs de la galerie ─────────────────────────

function gbo_notify(string $slug, int $id): void
{
    $gallery = gallery_load($slug);
    $item = gbo_get($slug, $id);
    if (!$gallery || !$item) return;
    $subject = 'Bourse d\'échanges — ' . $item['title'];
    $body = "Nouvelle demande dans la bourse d'échanges de « " . $gallery['name'] . " » :\n\n"
        . gbo_kind_label($item['kind']) . ' : « ' . $item['title'] . " »\n"
        . ($item['category'] !== '' ? 'Catégorie : ' . $item['category'] . "\n" : '')
        . ($item['price_cents'] ? ($item['kind'] === 'recherche' ? 'Budget : ' : 'Prix souhaité : ') . gbo_money($item['price_cents']) . "\n" : '')
        . 'De : ' . ($item['contact_name'] !== '' ? $item['contact_name'] . ' ' : '') . '<' . $item['email'] . '>'
        . ($item['phone'] !== '' ? ' · ' . $item['phone'] : '') . "\n"
        . ($item['location'] !== '' ? 'Lieu : ' . $item['location'] . "\n" : '')
        . "\nDescription :\n" . $item['description'] . "\n"
        . "\nRépondez directement à l'expéditeur, ou retrouvez la demande dans le portail : Bourse d'échanges.\n";
    foreach ((array) ($gallery['admins'] ?? []) as $admin) {
        $to = (string) ($admin['email'] ?? '');
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) ga_mail($to, $subject, $body);
    }
}
