<?php
/**
 * Avatar du vendeur : un portrait importé depuis « Mon profil » est transformé
 * en illustration par IA, puis peut devenir le logo de la boutique (les trois
 * déclinaisons sont composées localement, sans nouveau crédit IA).
 *
 * Les avatars sont rangés dans les réglages de la boutique (clé « avatars »),
 * par compte : « main » pour le compte principal (tenant.php), « a<id> » pour
 * un compte de l'équipe. Chaque membre a ainsi son avatar sans toucher à la
 * table accounts, qui ne stocke pas d'image.
 */
require_once __DIR__ . '/appearance.php';
require_once __DIR__ . '/omniroute.php';

const VENDOR_AVATAR_MAX_UPLOAD = 8 * 1024 * 1024;
const VENDOR_AVATAR_MAX_DIM = 1600;

/** Clé de rangement de l'avatar du compte connecté. */
function vendor_avatar_key(?array $session): string
{
    $id = $session['id'] ?? null;
    return $id === null ? 'main' : 'a' . (int) $id;
}

function vendor_avatar_all(): array
{
    try {
        appearance_table();
        $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'avatars'");
        $stmt->execute();
        $data = json_decode((string) $stmt->fetchColumn(), true);
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        return [];
    }
}

function vendor_avatar_for(?array $session): ?array
{
    $data = vendor_avatar_all()[vendor_avatar_key($session)] ?? null;
    if (!is_array($data) || empty($data['avatar'])) {
        return null;
    }
    // Reprise : un avatar créé avant la médiathèque y entre à la première visite.
    vendor_avatar_register_media($data);
    return $data;
}

function vendor_avatar_abs(string $rel): string
{
    return dirname(__DIR__) . '/' . ltrim($rel, '/');
}

function vendor_avatar_write(array $all): void
{
    appearance_table();
    db()->prepare("INSERT INTO settings (name, value) VALUES ('avatars', ?)
                   ON CONFLICT(name) DO UPDATE SET value = excluded.value")
        ->execute([json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

function vendor_avatar_save(string $key, array $data): void
{
    $all = vendor_avatar_all();
    $all[$key] = $data;
    vendor_avatar_write($all);
}

/** Coût estimé d'une génération d'avatar (affiché avant de confirmer). */
function vendor_avatar_cost_eur(): float
{
    return ai_eur(ai_pricing()['image']);
}

/** L'IA d'image est-elle disponible pour cette boutique ? Message à afficher, ou null. */
function vendor_avatar_blocked_reason(): ?string
{
    if (($gate = ai_gate()) !== null) {
        return $gate;
    }
    if (GEMINI_API_KEY === '' && !ai_provider_active('image') && !omniroute_image_ready()) {
        return "La génération d'images par IA n'est pas configurée pour cette boutique.";
    }
    return null;
}

/**
 * Range le portrait importé ($_FILES) en JPEG : orientation EXIF rétablie et
 * taille réduite. -> chemin relatif (uploads/portrait-…jpg).
 */
function vendor_avatar_store_portrait(array $file, string $key): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException("Choisissez d'abord un portrait (JPG, PNG ou WebP).");
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException("L'envoi du portrait a échoué, réessayez.");
    }
    if (($file['size'] ?? 0) > VENDOR_AVATAR_MAX_UPLOAD) {
        throw new InvalidArgumentException('Portrait trop lourd : 8 Mo maximum.');
    }
    $info = @getimagesize((string) ($file['tmp_name'] ?? ''));
    if (!$info || !in_array($info[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new InvalidArgumentException('Formats acceptés pour le portrait : JPG, PNG ou WebP.');
    }
    $img = @imagecreatefromstring((string) @file_get_contents((string) $file['tmp_name']));
    if (!$img) {
        throw new InvalidArgumentException("Ce portrait n'a pas pu être lu : réessayez avec une autre photo.");
    }

    // Une photo prise au téléphone porte souvent une orientation EXIF : sans ce
    // redressement, l'avatar serait couché.
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data((string) $file['tmp_name']);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle !== 0) {
            $rotated = @imagerotate($img, $angle, 0);
            if ($rotated) $img = $rotated;
        }
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, VENDOR_AVATAR_MAX_DIM / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'portrait-' . tenant_slug() . '-' . $key . '-' . bin2hex(random_bytes(4)) . '.jpg';
    if (!imagejpeg($dst, $dir . '/' . $name, 88)) {
        throw new RuntimeException("Impossible d'enregistrer le portrait dans uploads/.");
    }
    return 'uploads/' . $name;
}

/**
 * Portrait → avatar illustré (buste, fond blanc détouré en transparence).
 * -> chemin relatif (uploads/avatar-…png). À appeler après vendor_avatar_blocked_reason().
 */
function vendor_avatar_generate(string $key, string $portraitRel): string
{
    $portraitAbs = vendor_avatar_abs($portraitRel);
    if (!is_file($portraitAbs)) {
        throw new RuntimeException('Le portrait importé est introuvable : réimportez-le.');
    }
    $bytes = gemini_generate_image($portraitAbs,
        "Transforme ce portrait en avatar illustré pour une boutique. Garde la personne parfaitement reconnaissable : mêmes traits du visage, "
        . "même coiffure, lunettes, barbe, couleur de peau et tenue. Buste de face ou légèrement de trois-quarts, expression souriante et accueillante. "
        . "Style illustration vectorielle moderne, aplats propres, ombres douces, couleurs naturelles. Le sujet est centré et occupe environ 80 % du cadre, "
        . "avec une marge régulière. Fond blanc pur uni (#ffffff), sans décor, sans ombre portée, sans texte, sans logo, sans cadre. Ne rajoute personne d'autre. "
        . "Flat vector portrait avatar of this exact person, bust, warm smile, pure white background, no text, no frame, square composition.",
        2,
        '1:1');
    if ($bytes === null) {
        throw new RuntimeException("La génération de l'avatar a échoué (clé refusée, crédit IA épuisé ou quota) : rien n'a été débité. Réessayez avec un autre portrait.");
    }
    $png = logo_transparent_png_bytes($bytes, 1024);
    if ($png === null) {
        throw new RuntimeException("L'avatar généré n'a pas pu être détouré : réessayez avec un portrait sur fond uni.");
    }
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'avatar-' . tenant_slug() . '-' . $key . '-' . bin2hex(random_bytes(4)) . '.png';
    if (@file_put_contents($dir . '/' . $name, $png, LOCK_EX) === false) {
        throw new RuntimeException("Impossible d'enregistrer l'avatar dans uploads/.");
    }
    return 'uploads/' . $name;
}

/** Libellé de l'avatar dans la médiathèque. */
function vendor_avatar_label(array $avatar): string
{
    $name = trim((string) ($avatar['name'] ?? ''));
    return 'Avatar' . ($name !== '' ? ' — ' . $name : ' du vendeur');
}

/** Inscrit l'avatar généré dans la médiathèque (le portrait, lui, reste privé). */
function vendor_avatar_register_media(array $avatar): void
{
    $path = (string) ($avatar['avatar'] ?? '');
    if ($path === '') return;
    try {
        media_library_register($path, vendor_avatar_label($avatar), 'Avatar du vendeur', 'avatar, portrait');
    } catch (Throwable $e) {
        // Une médiathèque indisponible ne doit pas empêcher d'afficher son avatar.
    }
}

/** Supprime les fichiers d'un ancien avatar (portrait et PNG généré) et leurs fiches médiathèque. */
function vendor_avatar_cleanup_files(?array $avatar): void
{
    foreach (['portrait', 'avatar'] as $field) {
        $path = (string) ($avatar[$field] ?? '');
        if (preg_match('#^uploads/(portrait|avatar)-[A-Za-z0-9_.-]+\.(jpg|png|webp)$#', $path)) {
            @unlink(vendor_avatar_abs($path));
            try {
                db()->prepare('DELETE FROM media_library WHERE path = ?')->execute([$path]);
            } catch (Throwable $e) {
            }
        }
    }
}

/**
 * Génère l'avatar du compte depuis le portrait envoyé et remplace l'ancien.
 * -> fiche rangée en réglages. Lève une exception affichable en cas d'échec.
 */
function vendor_avatar_generate_from_upload(string $key, array $file, ?array $session): array
{
    if (($reason = vendor_avatar_blocked_reason()) !== null) {
        throw new RuntimeException($reason);
    }
    $old = vendor_avatar_all()[$key] ?? null;
    $portrait = vendor_avatar_store_portrait($file, $key);
    try {
        $avatar = vendor_avatar_generate($key, $portrait);
    } catch (Throwable $e) {
        @unlink(vendor_avatar_abs($portrait));
        throw $e;
    }
    $data = [
        'portrait' => $portrait,
        'avatar' => $avatar,
        'name' => (string) ($session['name'] ?? ''),
        'updated_at' => time(),
    ];
    vendor_avatar_save($key, $data);
    vendor_avatar_register_media($data);
    if (is_array($old)) vendor_avatar_cleanup_files($old);
    return $data;
}

/**
 * Utilise l'avatar comme logo de boutique : composition locale des trois
 * déclinaisons (en-tête, vertical, carré) avec le nom du commerce.
 * -> ['paths' => [...], 'failed' => [...]].
 */
function vendor_avatar_use_as_logo(array $avatar): array
{
    $abs = vendor_avatar_abs((string) $avatar['avatar']);
    if (!is_file($abs)) {
        throw new RuntimeException("Le fichier de l'avatar est introuvable : générez-le à nouveau.");
    }
    vendor_avatar_register_media($avatar);
    $composed = logo_compose_all($abs);
    if (!$composed['paths']) {
        throw new RuntimeException("Aucune déclinaison du logo n'a pu être composée : réessayez.");
    }
    $siteName = trim((string) (get_content()['site_name'] ?? ''));
    foreach ($composed['paths'] as $variant => $path) {
        $title = (string) (LOGO_VARIANTS[$variant][0] ?? $variant);
        media_library_register($path, 'Logo ' . mb_strtolower($title) . ($siteName !== '' ? ' — ' . $siteName : ''), 'Avatar du vendeur', 'logo, avatar');
    }
    return $composed;
}

/** Retire l'avatar du compte et supprime ses fichiers. */
function vendor_avatar_remove(string $key): void
{
    $all = vendor_avatar_all();
    $avatar = $all[$key] ?? null;
    if (!is_array($avatar)) return;
    unset($all[$key]);
    vendor_avatar_write($all);
    vendor_avatar_cleanup_files($avatar);
}
