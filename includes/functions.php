<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/capture.php';
require_once __DIR__ . '/studio.php';
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/universes.php';
require_once __DIR__ . '/comparables.php';
require_once __DIR__ . '/price-research.php';
require_once __DIR__ . '/ai-usage.php';

function h($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Catégories du commerce actif (tenants/<slug>/tenant.php → categories). */
function category_list(): array
{
    // Univers adaptés depuis l'administration (page Univers) ; sinon ceux du fichier du commerce.
    return universes_saved() ?? tenant('categories');
}

/** Catégorie par défaut du commerce (« curiosites » pour la brocante, sinon la première). */
function default_category_key(): string
{
    $keys = array_column(category_list(), 'key');
    return in_array('curiosites', $keys, true) ? 'curiosites' : ($keys[0] ?? 'divers');
}

function category_label(string $key): string
{
    foreach (category_list() as $c) {
        if ($c['key'] === $key) return $c['label'];
    }
    return $key;
}

function category_icon(string $key): string
{
    foreach (category_list() as $c) {
        if ($c['key'] === $key) return $c['icon'];
    }
    return 'ic-vase';
}

function get_content(): array
{
    $row = db()->query('SELECT * FROM content WHERE id = 1')->fetch();
    return $row ?: [];
}

/**
 * @param bool $includeHidden Pass true only for admin screens — the public
 *  site (boutique, accueil) must never list a product marked masqué ou
 *  vendue (stock épuisé) : la plupart des pièces sont uniques (stock=1),
 *  une vente les retire donc immédiatement de la boutique.
 */
function get_products(?string $cat = null, bool $includeHidden = false, string $search = ''): array
{
    $where = [];
    $params = [];
    if ($cat && $cat !== 'tous') { $where[] = 'cat = ?'; $params[] = $cat; }
    if (!$includeHidden) { $where[] = 'is_hidden = 0'; $where[] = 'stock > 0'; }
    if ($search !== '') {
        $where[] = '(name LIKE ? OR description LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql = 'SELECT * FROM products' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sort_order, ref';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_product(string $ref): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE ref = ?');
    $stmt->execute([$ref]);
    return $stmt->fetch() ?: null;
}

function in_stock(array $p): bool
{
    return (int) ($p['stock'] ?? 0) > 0;
}

/**
 * Décrémente le stock des pièces d'une commande une fois le paiement
 * confirmé — appelé une seule fois par commande (checkout-success.php et
 * la vérification manuelle admin ne l'exécutent que lors du passage
 * pending → paid, jamais si la commande est déjà payée).
 */
function decrement_stock_for_order(array $items): void
{
    $stmt = db()->prepare('UPDATE products SET stock = MAX(stock - ?, 0) WHERE ref = ?');
    foreach ($items as $item) {
        $stmt->execute([(int) $item['qty'], $item['ref']]);
    }
}

function featured_products(): array
{
    return db()->query('SELECT * FROM products WHERE featured = 1 AND is_hidden = 0 AND stock > 0 ORDER BY sort_order, ref LIMIT 3')->fetchAll();
}

function promo_products(): array
{
    return db()->query("SELECT * FROM products WHERE badge = 'Promo' AND is_hidden = 0 AND stock > 0 ORDER BY sort_order, ref")->fetchAll();
}

/**
 * Une pièce n'a un prix promo affiché/facturé que si le badge "Promo" est
 * actif ET qu'un prix réduit fixe a été renseigné — un simple badge "Promo"
 * sans prix précisé reste un repère visuel, pas une remise réelle.
 */
function has_promo_price(array $p): bool
{
    return ($p['badge'] ?? '') === 'Promo' && !empty($p['promo_price']) && is_fixed_price($p['promo_price']);
}

/** Prix réellement facturé (promo si actif, sinon le prix normal). */
function effective_price(array $p): string
{
    return has_promo_price($p) ? $p['promo_price'] : $p['price'];
}

/* ---------- Diaporama du hero (page d'accueil) ---------- */

function hero_slides_list(): array
{
    return db()->query('SELECT * FROM hero_slides ORDER BY sort_order, id')->fetchAll();
}

function next_hero_sort_order(): int
{
    return (int) db()->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM hero_slides')->fetchColumn();
}

function add_hero_slide(string $path, string $caption = ''): int
{
    $stmt = db()->prepare('INSERT INTO hero_slides (path, caption, sort_order) VALUES (?, ?, ?)');
    $stmt->execute([$path, $caption, next_hero_sort_order()]);
    return (int) db()->lastInsertId();
}

/* ---------- Diaporama boutique (écran en magasin) ---------- */

function slideshow_slides_list(): array
{
    return db()->query('SELECT * FROM slideshow_slides ORDER BY sort_order, id')->fetchAll();
}

function next_slideshow_sort_order(): int
{
    return (int) db()->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM slideshow_slides')->fetchColumn();
}

function add_slideshow_slide(string $kind, ?string $path, ?string $productRef, string $caption, int $duration): int
{
    $stmt = db()->prepare('INSERT INTO slideshow_slides (kind, path, product_ref, caption, duration_seconds, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$kind, $path, $productRef, $caption, $duration, next_slideshow_sort_order()]);
    return (int) db()->lastInsertId();
}

/** Slide prête à afficher : résout la fiche produit pour les slides de type 'product'. */
function slideshow_render_data(array $slide): ?array
{
    if ($slide['kind'] === 'product') {
        $p = get_product((string) $slide['product_ref']);
        if (!$p || !empty($p['is_hidden']) || (int) $p['stock'] <= 0) return null;
        return ['slide' => $slide, 'product' => $p];
    }
    if (!$slide['path']) return null;
    return ['slide' => $slide, 'product' => null];
}

/* ---------- Bandeaux photo/vidéo en haut de page ---------- */

/** Clés de page disponibles pour un bandeau, avec leur libellé admin. */
function page_banner_targets(): array
{
    return [
        'accueil'  => 'Accueil (derrière le hero)',
        'boutique' => 'Boutique (titre)',
        'histoire' => 'Notre histoire (titre)',
        'contact'  => 'Contact (titre)',
    ];
}

/** Tous les bandeaux configurés, indexés par page_key. */
function get_page_banners(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM page_banners')->fetchAll() as $r) {
        $out[$r['page_key']] = $r;
    }
    return $out;
}

function get_page_banner(string $pageKey): ?array
{
    $stmt = db()->prepare('SELECT * FROM page_banners WHERE page_key = ?');
    $stmt->execute([$pageKey]);
    return $stmt->fetch() ?: null;
}

function set_page_banner(string $pageKey, string $kind, string $path, int $overlay): void
{
    $overlay = max(0, min(100, $overlay));
    $stmt = db()->prepare(
        'INSERT INTO page_banners (page_key, kind, path, overlay) VALUES (?, ?, ?, ?)
         ON CONFLICT(page_key) DO UPDATE SET kind = excluded.kind, path = excluded.path,
           overlay = excluded.overlay, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$pageKey, $kind, $path, $overlay]);
}

function set_page_banner_overlay(string $pageKey, int $overlay): void
{
    $overlay = max(0, min(100, $overlay));
    db()->prepare('UPDATE page_banners SET overlay = ? WHERE page_key = ?')->execute([$overlay, $pageKey]);
}

function delete_page_banner(string $pageKey): void
{
    db()->prepare('DELETE FROM page_banners WHERE page_key = ?')->execute([$pageKey]);
}

/**
 * Affiche le calque photo/vidéo de fond d'un bandeau, s'il est configuré —
 * à appeler comme premier enfant d'un conteneur qui portera la classe
 * "has-page-banner" (voir assets/style.css). Ne fait rien si aucun média
 * n'est défini pour cette page : zéro impact visuel par défaut. L'opacité
 * du voile (0-100 %) est appliquée en style inline pour rester par-bandeau
 * sans multiplier les classes CSS.
 */
function render_page_banner(string $pageKey): void
{
    $b = get_page_banner($pageKey);
    if (!$b || !$b['path']) return;
    echo '<div class="page-banner-media">';
    if ($b['kind'] === 'video') {
        echo '<video src="/' . h($b['path']) . '" autoplay muted loop playsinline></video>';
    } else {
        echo '<img src="/' . h($b['path']) . '" alt="">';
    }
    $overlay = (int) $b['overlay'];
    if ($overlay > 0) {
        echo '<span class="page-banner-overlay" style="opacity:' . ($overlay / 100) . '"></span>';
    }
    echo '</div>';
}

function next_ref(): string
{
    $max = (int) db()->query('SELECT MAX(CAST(ref AS INTEGER)) FROM products')->fetchColumn();
    $n = $max + 1;
    return $n < 100 ? str_pad((string) $n, 3, '0', STR_PAD_LEFT) : (string) $n;
}

/**
 * Card/catalogue thumbnail. When a product has more than one visible gallery
 * photo, renders a stack of images that the shared front-end script cycles
 * through automatically (autoplay diaporama on the thumbnail) — a single-photo
 * product just gets a plain <img>, no extra markup or script needed for it.
 */
function product_media_html(array $p, ?array $gallery = null): string
{
    $gallery ??= product_gallery($p);
    // Vignettes au format ordinateur : sans les visuels réservés au smartphone.
    $cardGallery = array_values(array_filter($gallery, fn($g) => $g['only'] !== 'mobile')) ?: $gallery;
    if (count($cardGallery) === 1 && $cardGallery[0]['type'] !== 'video') {
        return '<img src="/' . h($cardGallery[0]['src']) . '" alt="' . h($p['name']) . '">';
    }
    $gallery = $cardGallery;
    if (count($gallery) > 1) {
        $slides = '';
        foreach ($gallery as $i => $g) {
            $active = $i === 0 ? ' is-active' : '';
            if ($g['type'] === 'video') {
                // autoplay+muted+loop : la vidéo tourne en continu dès son
                // chargement, indépendamment du cycle qui bascule laquelle
                // est visible (.is-active) — pas besoin de la piloter en JS.
                $slides .= '<video src="/' . h($g['src']) . '" class="thumb-slide' . $active . '" autoplay muted loop playsinline></video>';
            } else {
                $slides .= '<img src="/' . h($g['src']) . '" alt="' . h($p['name']) . '" class="thumb-slide' . $active . '">';
            }
        }
        return '<div class="thumb-autoplay">' . $slides . '</div>';
    }
    if (!empty($p['photo'])) {
        return '<img src="/' . h($p['photo']) . '" alt="' . h($p['name']) . '">';
    }
    $icon = $p['icon'] ?: category_icon($p['cat']);
    return '<svg viewBox="0 0 64 64"><use href="#' . h($icon) . '"/></svg>';
}

/**
 * Raw gallery rows for a product, ordered for display/management (admin gallery
 * manager and the public diaporama both read this — it is the single source of
 * truth for a product's photos, replacing the old fixed photo_* columns).
 */
function product_photos_list(string $ref): array
{
    $stmt = db()->prepare('SELECT * FROM product_photos WHERE product_ref = ? ORDER BY sort_order, id');
    $stmt->execute([$ref]);
    return $stmt->fetchAll();
}

/**
 * Keeps products.photo (the fast "cover" column used by grid cards) in sync with
 * the first image in the product's gallery. Call after any gallery change.
 */
function sync_cover_photo(string $ref): void
{
    // A real, non-hidden photo is always preferred as the cover shown on grid
    // cards, even if an AI-generated illustration was reordered or added ahead
    // of it — the catalogue thumbnail should never default to a staged shot,
    // nor to a photo the shop owner has chosen to hide.
    $stmt = db()->prepare("SELECT path FROM product_photos WHERE product_ref = ? AND is_hidden = 0 AND type = 'photo' ORDER BY is_illustration ASC, sort_order ASC, id ASC LIMIT 1");
    $stmt->execute([$ref]);
    $cover = $stmt->fetchColumn();
    $upd = db()->prepare('UPDATE products SET photo = ? WHERE ref = ?');
    $upd->execute([$cover ?: null, $ref]);
}

/**
 * Gallery for display, in the shape the diaporama expects. Each entry:
 * ['id' => row id, 'src' => relative path, 'label' => French label, 'illustration' => bool].
 * The angle/ambiance shots are AI-generated from the real photo, never a photo of
 * that exact object from that exact angle/place — always flagged as such.
 */
function product_gallery(array $p): array
{
    $rows = array_values(array_filter(product_photos_list($p['ref']), fn($r) => !$r['is_hidden']));
    $gallery = array_map(function ($r) {
        $isVideo = ($r['type'] ?? 'photo') === 'video';
        $kind = $isVideo ? null : image_format_kind(__DIR__ . '/../' . $r['path']);
        // Règle d'affichage : 3:2 sur ordinateur, 9:16 sur smartphone.
        // Un visuel qui n'existe qu'en 3:2 n'est montré que sur ordinateur,
        // qu'en 9:16 que sur smartphone ; les autres formats (photos réelles
        // 4:3, carrées…) restent visibles partout, en entier.
        $mobile = $r['path_mobile'] ?: ($kind === 'mobile' ? $r['path'] : null);
        $only = match (true) {
            $kind === 'mobile' && !$r['path_mobile'] => 'mobile',
            $kind === 'desktop' && !$r['path_mobile'] => 'desktop',
            default => null,
        };
        return [
            'id' => (int) $r['id'],
            'src' => $r['path'],
            'src_mobile' => $mobile !== $r['path'] ? $mobile : null,
            'only' => $only,
            'label' => $r['label'],
            'illustration' => (bool) $r['is_illustration'],
            'type' => $r['type'] ?? 'photo',
        ];
    }, $rows);
    // Jamais d'écran sans image : si un type d'écran n'a aucun visuel à son
    // format, on y montre quand même les autres (en entier).
    foreach (['desktop' => 'mobile', 'mobile' => 'desktop'] as $screen => $other) {
        if ($gallery && !array_filter($gallery, fn($g) => $g['only'] !== $other)) {
            foreach ($gallery as &$g) $g['only'] = null;
            unset($g);
        }
    }
    return $gallery;
}

function product_card_html(array $p, int $i = 0): string
{
    $tones = ['accent', 'sage', 'blue', ''];
    $tone = $tones[$i % 4];

    if (is_fixed_price(effective_price($p))) {
        $action = '
          <form method="post" action="/cart-add.php" style="display:contents;">
            <input type="hidden" name="ref" value="' . h($p['ref']) . '">
            <input type="hidden" name="redirect" value="' . h($_SERVER['REQUEST_URI'] ?? '/boutique.php') . '">
            <button class="card-cta" type="submit">Ajouter</button>
            <button class="card-cta card-cta-buy" type="submit" name="redirect" value="/cart.php">Commander</button>
          </form>';
    } else {
        $action = '<a class="card-cta" href="/index.php#contact">Nous contacter</a>';
    }

    $href = '/produit.php?ref=' . urlencode($p['ref']);
    $gallery = product_gallery($p);
    $preview = $gallery
        // Aperçu au survol (ordinateur) : sans les visuels réservés au smartphone.
        ? ' data-quick-gallery="' . h(json_encode(array_map(fn($g) => ['src' => $g['src'], 'type' => $g['type']], array_values(array_filter($gallery, fn($g) => $g['only'] !== 'mobile')) ?: $gallery))) . '" data-quick-name="' . h($p['name']) . '"'
        : '';

    $priceHtml = has_promo_price($p)
        ? '<span class="price-old">' . h($p['price']) . '</span> <span class="price price-promo">' . h($p['promo_price']) . '</span>'
        : '<span class="price">' . h($p['price']) . '</span>';

    return '
      <article class="card ' . $tone . '" data-cat="' . h($p['cat']) . '">
        <a href="' . h($href) . '" class="card-icon"' . $preview . '>' . product_media_html($p, $gallery) . '</a>
        <p class="ref">Réf. N°' . h($p['ref']) . ' — ' . h(category_label($p['cat'])) . '</p>
        <h3><a href="' . h($href) . '" style="text-decoration:none;color:inherit;">' . h($p['name']) . '</a></h3>
        <p class="desc">' . h($p['description']) . '</p>
        <div class="card-foot">
          <div class="card-price-line">
            <span class="badge">' . h($p['badge']) . '</span>
            <span class="price-group">' . $priceHtml . '</span>
          </div>
          <div class="card-actions">
            <a class="card-details" href="' . h($href) . '">Détails</a>
            ' . $action . '
          </div>
        </div>
      </article>';
}

/**
 * Resize/compress an uploaded image and store it under uploads/, returning
 * the relative path to save in the database. Returns null if no file given.
 */
function store_uploaded_photo(string $fieldName, string $baseName, int $maxDim = 1000, int $quality = 78): ?string
{
    if (empty($_FILES[$fieldName]['tmp_name']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = $_FILES[$fieldName]['tmp_name'];
    $info = @getimagesize($tmp);
    if (!$info) return null;

    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($tmp); break;
        case IMAGETYPE_PNG:  $src = imagecreatefrompng($tmp); break;
        case IMAGETYPE_WEBP: $src = imagecreatefromwebp($tmp); break;
        case IMAGETYPE_GIF:  $src = imagecreatefromgif($tmp); break;
        default: return null;
    }
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxDim / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $uploadsDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

    $filename = $baseName . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
    imagejpeg($dst, $uploadsDir . '/' . $filename, $quality);

    return 'uploads/' . $filename;
}

/**
 * Copie/redimensionne une image depuis un chemin arbitraire (ex : une photo
 * extraite d'un zip sous uploads/batch-import/, un dossier temporaire) vers
 * le stockage permanent uploads/ — mêmes réglages que store_uploaded_photo(),
 * mais à partir d'un chemin sur disque plutôt que de $_FILES. Utilisé par
 * l'import par lot pour que les photos originales d'une fiche produit créée
 * ne dépendent plus du dossier d'extraction temporaire, qui est supprimé une
 * fois l'import terminé (voir admin/batch-import-action.php).
 */
function copy_photo_into_uploads(string $srcAbsPath, string $baseName, int $maxDim = 1400, int $quality = 82): ?string
{
    $info = @getimagesize($srcAbsPath);
    if (!$info) return null;

    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($srcAbsPath); break;
        case IMAGETYPE_PNG:  $src = imagecreatefrompng($srcAbsPath); break;
        case IMAGETYPE_WEBP: $src = imagecreatefromwebp($srcAbsPath); break;
        case IMAGETYPE_GIF:  $src = imagecreatefromgif($srcAbsPath); break;
        default: return null;
    }
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxDim / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $uploadsDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

    $filename = $baseName . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
    imagejpeg($dst, $uploadsDir . '/' . $filename, $quality);

    return 'uploads/' . $filename;
}

/**
 * Saves raw image bytes (from Gemini, a crop, etc.) under uploads/ and returns
 * the relative path. JPEG is re-encoded/resized via GD; PNG (transparent cutouts)
 * is stored as-is since the source is already sized by the caller.
 */
function save_binary_photo(string $binary, string $baseName, string $ext = 'jpg', int $maxDim = 1400, int $quality = 85): ?string
{
    $uploadsDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
    $filename = $baseName . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $path = $uploadsDir . '/' . $filename;

    if ($ext === 'png') {
        file_put_contents($path, $binary);
        return 'uploads/' . $filename;
    }

    $src = @imagecreatefromstring($binary);
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxDim / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($dst, $path, $quality);

    return 'uploads/' . $filename;
}

/**
 * Tourne une photo de 90° dans le sens horaire, en écrasant le fichier en
 * place (même chemin — pas de nouvelle ligne product_photos à créer).
 * Le transparent des PNG (détourages) est préservé.
 */
function rotate_photo_clockwise(string $absPath): bool
{
    $info = @getimagesize($absPath);
    if (!$info) return false;
    $isPng = $info[2] === IMAGETYPE_PNG;
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($absPath),
        IMAGETYPE_PNG => @imagecreatefrompng($absPath),
        IMAGETYPE_WEBP => @imagecreatefromwebp($absPath),
        default => null,
    };
    if (!$src) return false;

    $bg = 0;
    if ($isPng) {
        imagealphablending($src, false);
        imagesavealpha($src, true);
        $bg = imagecolorallocatealpha($src, 0, 0, 0, 127);
    }

    // imagerotate() tourne dans le sens anti-horaire pour un angle positif,
    // donc -90 donne visuellement une rotation horaire.
    $rotated = imagerotate($src, -90, $bg);
    if (!$rotated) return false;

    if ($isPng) {
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        imagepng($rotated, $absPath);
    } else {
        imagejpeg($rotated, $absPath, 90);
    }
    return true;
}

/** Part maximale du cadre occupée par la photo dans les exports multi-formats (le reste = fond ajouté). */
const EXPORT_SUBJECT_FILL = 0.9;

/**
 * Photo mise au ratio cible (largeur/hauteur) SANS recadrage, enregistrée
 * dans uploads/ : elle est gardée en entier, centrée, et du fond est ajouté
 * autour (voir pad_image_to_ratio). Une photo détourée reste un PNG à fond
 * transparent (marge transparente). Retourne le chemin relatif, ou null.
 */
function fit_ratio_file(string $srcAbsPath, float $ratio, string $baseName, int $maxDim = 1800, int $quality = 88, float $fill = EXPORT_SUBJECT_FILL, bool $shadow = false): ?string
{
    $src = @imagecreatefromstring((string) @file_get_contents($srcAbsPath));
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    // Taille de toile nécessaire pour garder la photo à sa résolution, plafonnée à $maxDim.
    [$cw, $ch] = ($w / $h > $ratio) ? [$w / $fill, $w / $fill / $ratio] : [$h / $fill * $ratio, $h / $fill];
    $transparent = image_has_transparent_edge($src);
    // Photo déjà au bon format : gardée telle quelle, sans marge ajoutée.
    if (!$transparent && abs(($w / $h) / $ratio - 1) <= 0.015) {
        $fill = 1.0;
        [$cw, $ch] = [$w, $h];
    }
    $canvas = pad_image_to_ratio($src, $ratio, $fill, (int) min($maxDim, round(max($cw, $ch))), $transparent);
    if ($shadow && $transparent) {
        $canvas = add_drop_shadow($canvas);
    } elseif ($shadow && ($bg = image_uniform_edge_color($src))) {
        $canvas = add_drop_shadow_on_color($canvas, $bg);
    }
    ob_start();
    $transparent ? imagepng($canvas, null, 6) : imagejpeg($canvas, null, $quality);
    $bytes = (string) ob_get_clean();
    return $bytes !== '' ? save_binary_photo($bytes, $baseName, $transparent ? 'png' : 'jpg', $maxDim, $quality) : null;
}

/**
 * Formats d'export proposés par pièce, chacun lié à un usage concret :
 * catalogue, fiche produit, diaporama en boutique, archive non recadrée,
 * et les deux formats standard des réseaux sociaux (dimensions réelles
 * Instagram/Facebook : post carré 1080×1080, story verticale 1080×1920).
 * Aucun format ne recadre : la photo est gardée en entière et du fond est
 * ajouté autour. ratio = null → export au format d'origine, à bonne résolution.
 */
function media_export_variants(): array
{
    return [
        'vignette_catalogue' => ['label' => 'Vignette catalogue (3:2)', 'ratio' => 3 / 2, 'maxDim' => 480],
        'produit_horizontal' => ['label' => 'Fiche produit (horizontal 3:2)', 'ratio' => 3 / 2, 'maxDim' => 1800],
        'diaporama_vente' => ['label' => 'Diaporama plein écran (point de vente)', 'ratio' => 16 / 9, 'maxDim' => 2400],
        'format_reel' => ['label' => 'Format réel (non recadré)', 'ratio' => null, 'maxDim' => 2000],
        'reseaux_post' => ['label' => 'Réseaux sociaux — post (1080×1080)', 'ratio' => 1.0, 'maxDim' => 1080],
        'reseaux_story' => ['label' => 'Réseaux sociaux — story (1080×1920)', 'ratio' => 9 / 16, 'maxDim' => 1920],
    ];
}

/**
 * Génère les formats ci-dessus pour une photo et les dépose dans la
 * médiathèque, prêts à réutiliser sans encombrer le diaporama de la fiche
 * produit avec des quasi-doublons de la même image.
 */
function generate_media_export_variants(string $srcAbsPath, string $baseLabel, ?string $originRef = null, ?string $originName = null): int
{
    $count = 0;
    foreach (media_export_variants() as $key => $v) {
        if ($v['ratio'] === null) {
            $bytes = @file_get_contents($srcAbsPath);
            $isPng = strtolower(pathinfo($srcAbsPath, PATHINFO_EXTENSION)) === 'png';
            $path = $bytes ? save_binary_photo($bytes, 'export-' . $key, $isPng ? 'png' : 'jpg', $v['maxDim'], 88) : null;
        } else {
            $path = fit_ratio_file($srcAbsPath, $v['ratio'], 'export-' . $key, $v['maxDim']);
        }
        if (!$path) continue;
        add_media_item('photo', $path, "$baseLabel — {$v['label']}", 'Export multi-formats', 'export,' . $key, $originRef, $originName);
        $count++;
    }
    return $count;
}

/**
 * Calls Gemini (image-to-image) with a source photo + prompt, returns the
 * generated image's raw bytes, or null on failure. Retries a couple of times
 * with a short backoff since rate limiting (429) and empty responses from
 * this API are common and usually transient.
 */
/**
 * @param string|null $aspectRatio Format de l'image produite (« 3:2 », « 9:16 »…),
 *  demandé au modèle : l'image est composée pour ce cadre, sans recadrage après coup.
 */
function gemini_generate_image(string $srcAbsPath, string $prompt, int $retries = 3, ?string $aspectRatio = null): ?string
{
    if (!GEMINI_API_KEY) return null;

    $imgData = @file_get_contents($srcAbsPath);
    if ($imgData === false) return null;
    $mime = @getimagesize($srcAbsPath)['mime'] ?? 'image/jpeg';

    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($imgData)]],
            ],
        ]],
    ];
    if ($aspectRatio !== null) {
        $payload['generationConfig'] = ['imageConfig' => ['aspectRatio' => $aspectRatio]];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key=' . GEMINI_API_KEY;

    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            // Sous le délai habituel des serveurs web (60 s), pour ne pas finir en 504.
            CURLOPT_TIMEOUT => 55,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body !== false && $status < 400) {
            $data = json_decode($body, true);
            // L'image n'est pas toujours la première partie (le modèle peut
            // la faire précéder d'un court texte).
            $b64 = null;
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (!empty($part['inlineData']['data'])) { $b64 = $part['inlineData']['data']; break; }
            }
            if ($b64) {
                ai_usage_log_image($data['usageMetadata'] ?? []);
                return base64_decode($b64);
            }
            $reason = $data['candidates'][0]['finishReason'] ?? $data['promptFeedback']['blockReason'] ?? 'no image in response';
            error_log("gemini_generate_image attempt $attempt: HTTP $status but no image ($reason)");
        } else {
            error_log("gemini_generate_image attempt $attempt: HTTP $status, body: " . substr((string) $body, 0, 500));
        }

        if ($attempt < $retries) sleep(4 + $attempt * 4);
    }

    return null;
}

/** Formats des visuels générés : ordinateur (3:2) et smartphone (9:16). */
const GENERATED_IMAGE_FORMATS = ['desktop' => '3:2', 'mobile' => '9:16'];

/** Part maximale du cadre occupée par la photo source posée sur la toile (le reste = marge). */
const GENERATED_IMAGE_SUBJECT_FILL = 0.82;

/**
 * Consigne de cadrage ajoutée aux prompts de génération : l'objet reste
 * entier, le fond est prolongé pour remplir le format — jamais de recadrage.
 */
function generated_framing_prompt(string $aspectRatio, bool $directed = false): string
{
    $orientation = $aspectRatio === '9:16' ? 'tall vertical (portrait)' : 'wide horizontal (landscape)';
    // Scène imposée par le vendeur : on ne parle plus de mur ni de pièce, ce serait la contredire.
    $fill = $directed
        ? "Fill all the remaining space by extending the scene naturally, coherently with the setting requested in the owner's "
            . "direction — any flat or blurred area around the photo is empty canvas to replace."
        : "Fill all the remaining space by extending the background / scene naturally (wall, floor, surface, room) — "
            . "any flat or blurred area around the photo is empty canvas to replace with a coherent background.";
    // Cadrage demandé par le vendeur (gros plan, plan large, angle…) : il prime sur « objet entier avec marge ».
    $unless = $directed
        ? "Unless the owner's direction explicitly asks for a different framing (e.g. close-up, wide shot, low or high angle, "
            . "off-center composition), in which case follow it, keep this framing: "
        : "Keep this framing: ";
    return " FRAMING (mandatory): the output is a $aspectRatio $orientation image. The input image has "
        . "already been placed on a canvas of that exact format, with the object fully visible and margin "
        . "around it. " . $unless . "the ENTIRE object must stay visible, never cut by any edge of the "
        . "frame, with clear empty space on every side (at least 8% of the frame). Do not zoom in, do not crop, "
        . "do not enlarge the object to fill the frame. " . $fill;
}

/** Ratio « 3:2 » → largeur / hauteur. */
function aspect_ratio_value(string $aspectRatio): float
{
    [$w, $h] = array_map('floatval', explode(':', $aspectRatio)) + [1, 1];
    return $h > 0 ? $w / $h : 1.0;
}

/**
 * Pose une image, en entier et centrée, sur une toile au ratio demandé
 * ($fill = part maximale du cadre qu'elle occupe) — jamais de recadrage.
 * Fond : gris clair neutre si l'image est détourée (transparence), la couleur
 * du pourtour s'il est uni (fond studio), sinon la photo agrandie et très floutée.
 */
function pad_image_to_ratio(GdImage $src, float $ratio, float $fill = 1.0, int $longSide = 1536, bool $keepTransparency = false): GdImage
{
    if (!imageistruecolor($src)) imagepalettetotruecolor($src);
    $w = imagesx($src);
    $h = imagesy($src);
    [$cw, $ch] = $ratio >= 1 ? [$longSide, (int) round($longSide / $ratio)] : [(int) round($longSide * $ratio), $longSide];
    $canvas = imagecreatetruecolor($cw, $ch);

    $transparent = image_has_transparent_edge($src);
    if ($transparent && $keepTransparency) {
        // Détourage : marge transparente, l'objet garde son fond transparent.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        $scale = min($cw * $fill / $w, $ch * $fill / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        imagecopyresampled($canvas, $src, intdiv($cw - $nw, 2), intdiv($ch - $nh, 2), 0, 0, $nw, $nh, $w, $h);
        return $canvas;
    }
    $edge = $transparent ? null : image_uniform_edge_color($src);
    if ($transparent) {
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 236, 234, 230));
    } elseif ($edge) {
        // Fond uni (studio, mur) : prolongé à l'identique, sans raccord visible.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$edge));
    } else {
        // Flou : réduction à quelques pixels puis agrandissement (lissage bilinéaire).
        $tiny = imagecreatetruecolor(16, max(1, (int) round(16 * $ch / $cw)));
        $cover = max($cw / $w, $ch / $h);
        $sw = $cw / $cover; $sh = $ch / $cover;
        imagecopyresampled($tiny, $src, 0, 0, (int) (($w - $sw) / 2), (int) (($h - $sh) / 2), imagesx($tiny), imagesy($tiny), (int) $sw, (int) $sh);
        // Palier intermédiaire flouté, sinon l'agrandissement laisse des pavés.
        $mid = imagecreatetruecolor(max(1, intdiv($cw, 8)), max(1, intdiv($ch, 8)));
        imagecopyresampled($mid, $tiny, 0, 0, 0, 0, imagesx($mid), imagesy($mid), imagesx($tiny), imagesy($tiny));
        for ($i = 0; $i < 6; $i++) imagefilter($mid, IMG_FILTER_GAUSSIAN_BLUR);
        imagecopyresampled($canvas, $mid, 0, 0, 0, 0, $cw, $ch, imagesx($mid), imagesy($mid));
        imagefilter($canvas, IMG_FILTER_GAUSSIAN_BLUR);
        imagedestroy($tiny);
        imagedestroy($mid);
    }

    $scale = min($cw * $fill / $w, $ch * $fill / $h);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $src, intdiv($cw - $nw, 2), intdiv($ch - $nh, 2), 0, 0, $nw, $nh, $w, $h);
    return $canvas;
}

/**
 * Ombre portée sous un objet détouré (toile transparente, voir
 * pad_image_to_ratio) : silhouette décalée vers le bas et floutée, plus une
 * ombre de contact elliptique au pied de l'objet. Calculée à ¼ de la
 * résolution (rapide), puis agrandie : une ombre floue n'a pas besoin de détail.
 * Le résultat reste transparent autour (ombre semi-transparente).
 */
function add_drop_shadow(GdImage $canvas, float $opacity = 0.38, float $contact = 0.5): GdImage
{
    $w = imagesx($canvas);
    $h = imagesy($canvas);
    $sw = max(8, intdiv($w, 4));
    $sh = max(8, intdiv($h, 4));

    $small = imagecreatetruecolor($sw, $sh);
    imagealphablending($small, false);
    imagesavealpha($small, true);
    imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
    imagecopyresampled($small, $canvas, 0, 0, 0, 0, $sw, $sh, $w, $h);

    // Couverture de l'objet (0 = vide, 1 = plein) et son cadre.
    $cover = [];
    $minX = $sw; $maxX = -1; $maxY = -1; $minY = $sh;
    for ($y = 0; $y < $sh; $y++) {
        for ($x = 0; $x < $sw; $x++) {
            $c = 1 - ((imagecolorat($small, $x, $y) >> 24) & 0x7F) / 127;
            $cover[$y * $sw + $x] = $c;
            if ($c > 0.5) {
                $minX = min($minX, $x); $maxX = max($maxX, $x);
                $minY = min($minY, $y); $maxY = max($maxY, $y);
            }
        }
    }
    if ($maxX < 0) return $canvas; // rien de visible

    // Ombre en niveaux de gris (blanc = pas d'ombre) : silhouette décalée…
    $gray = imagecreatetruecolor($sw, $sh);
    imagefill($gray, 0, 0, imagecolorallocate($gray, 255, 255, 255));
    $dy = max(1, (int) round(($maxY - $minY) * 0.03));
    for ($y = $dy; $y < $sh; $y++) {
        for ($x = 0; $x < $sw; $x++) {
            $c = $cover[($y - $dy) * $sw + $x];
            if ($c > 0) {
                $v = (int) round(255 * (1 - $c * $opacity));
                imagesetpixel($gray, $x, $y, imagecolorallocate($gray, $v, $v, $v));
            }
        }
    }
    // … et ombre de contact au pied de l'objet.
    $v = (int) round(255 * (1 - $contact));
    imagefilledellipse($gray, intdiv($minX + $maxX, 2), $maxY, (int) round(($maxX - $minX) * 0.8), max(2, (int) round(($maxY - $minY) * 0.06)), imagecolorallocate($gray, $v, $v, $v));
    for ($i = 0; $i < 10; $i++) imagefilter($gray, IMG_FILTER_GAUSSIAN_BLUR);

    // Niveaux de gris → calque brun très sombre semi-transparent.
    $shadowSmall = imagecreatetruecolor($sw, $sh);
    imagealphablending($shadowSmall, false);
    imagesavealpha($shadowSmall, true);
    for ($y = 0; $y < $sh; $y++) {
        for ($x = 0; $x < $sw; $x++) {
            $darkness = (255 - (imagecolorat($gray, $x, $y) & 0xFF)) / 255;
            imagesetpixel($shadowSmall, $x, $y, imagecolorallocatealpha($shadowSmall, 30, 24, 18, 127 - (int) round($darkness * 127)));
        }
    }

    // Ombre agrandie, puis l'objet par-dessus.
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $shadowSmall, 0, 0, 0, 0, $w, $h, $sw, $sh);
    imagealphablending($out, true);
    imagecopy($out, $canvas, 0, 0, 0, 0, $w, $h);
    imagesavealpha($out, true);
    foreach ([$small, $gray, $shadowSmall, $canvas] as $im) imagedestroy($im);
    return $out;
}

/**
 * Ombre portée pour un objet détouré sur fond uni (repli IA « fond blanc ») :
 * l'objet est isolé par écart de couleur avec le fond, l'ombre calculée comme
 * pour un PNG transparent (add_drop_shadow), puis le tout reposé sur le fond.
 */
function add_drop_shadow_on_color(GdImage $canvas, array $bg): GdImage
{
    $w = imagesx($canvas);
    $h = imagesy($canvas);
    $cut = imagecreatetruecolor($w, $h);
    imagealphablending($cut, false);
    imagesavealpha($cut, true);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($canvas, $x, $y);
            $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            $dist = abs($r - $bg[0]) + abs($g - $bg[1]) + abs($b - $bg[2]);
            // 0 → fond (transparent), ≥ 36 → objet (opaque), fondu entre les deux.
            $alpha = 127 - (int) round(min(1, max(0, ($dist - 12) / 24)) * 127);
            imagesetpixel($cut, $x, $y, imagecolorallocatealpha($cut, $r, $g, $b, $alpha));
        }
    }
    imagedestroy($canvas);
    $shadowed = add_drop_shadow($cut);
    $out = imagecreatetruecolor($w, $h);
    imagefill($out, 0, 0, imagecolorallocate($out, ...$bg));
    imagealphablending($out, true);
    imagecopy($out, $shadowed, 0, 0, 0, 0, $w, $h);
    imagedestroy($shadowed);
    return $out;
}

/** Image détourée : quelques points du bord totalement transparents suffisent à le savoir. */
function image_has_transparent_edge(GdImage $img): bool
{
    if (!imageistruecolor($img)) imagepalettetotruecolor($img);
    $w = imagesx($img);
    $h = imagesy($img);
    foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1], [intdiv($w, 2), 0], [0, intdiv($h, 2)], [intdiv($w, 2), $h - 1], [$w - 1, intdiv($h, 2)]] as [$x, $y]) {
        if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 100) return true;
    }
    return false;
}

/**
 * Couleur de fond du pourtour de l'image [r, g, b] quand ce pourtour est
 * quasi uni (fond studio blanc, mur…) — même si l'objet touche un bord par
 * endroits (70 % des points proches de la couleur médiane) —, sinon null.
 */
function image_uniform_edge_color(GdImage $img): ?array
{
    $w = imagesx($img);
    $h = imagesy($img);
    $samples = [];
    for ($i = 0; $i < 60; $i++) {
        $t = $i / 59;
        foreach ([[(int) ($t * ($w - 1)), 1], [(int) ($t * ($w - 1)), $h - 2], [1, (int) ($t * ($h - 1))], [$w - 2, (int) ($t * ($h - 1))]] as [$x, $y]) {
            $c = imagecolorat($img, max(0, min($w - 1, $x)), max(0, min($h - 1, $y)));
            $samples[] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
        }
    }
    $median = [];
    for ($k = 0; $k < 3; $k++) {
        $channel = array_column($samples, $k);
        sort($channel);
        $median[$k] = $channel[intdiv(count($channel), 2)];
    }
    // Couleur retenue : moyenne des points proches de la médiane (le fond).
    $close = array_filter($samples, static fn ($px) => abs($px[0] - $median[0]) + abs($px[1] - $median[1]) + abs($px[2] - $median[2]) <= 36);
    if (count($close) < 0.7 * count($samples)) return null;
    $mean = [0, 0, 0];
    foreach ($close as $px) for ($k = 0; $k < 3; $k++) $mean[$k] += $px[$k] / count($close);
    return array_map(static fn ($v) => (int) round($v), $mean);
}

/**
 * Source d'une génération : copie temporaire de la photo posée sur une toile
 * au format visé, objet entier avec marge. Chemin temporaire à supprimer, ou null.
 */
function prepare_generation_source(string $srcAbsPath, string $aspectRatio, float $fill = GENERATED_IMAGE_SUBJECT_FILL): ?string
{
    $src = @imagecreatefromstring((string) @file_get_contents($srcAbsPath));
    if (!$src) return null;
    $canvas = pad_image_to_ratio($src, aspect_ratio_value($aspectRatio), $fill);
    $tmp = tempnam(sys_get_temp_dir(), 'gen') . '.jpg';
    imagejpeg($canvas, $tmp, 90);
    imagedestroy($src);
    imagedestroy($canvas);
    return $tmp;
}

/**
 * Visuel renvoyé par Gemini ramené au ratio visé. Écart de moins de 1,5 % :
 * tel quel. Jusqu'à 8 % (le modèle rend p. ex. 768×1344 pour du 9:16) : léger
 * recadrage centré, qui ne touche pas à l'objet. Au-delà : du fond flouté est
 * ajouté sur les côtés — ou null si $allowPad est faux (on préfère alors
 * refuser l'image plutôt que de livrer des bandes floutées).
 */
function fit_generated_to_ratio(string $binary, string $aspectRatio, bool $allowPad = true): ?string
{
    $img = @imagecreatefromstring($binary);
    if (!$img) return $allowPad ? $binary : null;
    $target = aspect_ratio_value($aspectRatio);
    $w = imagesx($img);
    $h = imagesy($img);
    $gap = abs(($w / $h) / $target - 1);
    if ($gap <= 0.015) return $binary;
    if ($gap <= 0.08) {
        [$cw, $ch] = ($w / $h > $target) ? [(int) round($h * $target), $h] : [$w, (int) round($w / $target)];
        $cropped = imagecrop($img, ['x' => intdiv($w - $cw, 2), 'y' => intdiv($h - $ch, 2), 'width' => $cw, 'height' => $ch]);
        if ($cropped) {
            ob_start();
            imagejpeg($cropped, null, 92);
            return (string) ob_get_clean();
        }
    }
    if (!$allowPad) return null;
    $canvas = pad_image_to_ratio($img, $target, 1.0, max($w, $h));
    ob_start();
    imagejpeg($canvas, null, 92);
    return (string) ob_get_clean();
}

/**
 * Un visuel au format donné : source préparée (photo posée entière avec marge
 * sur une toile à ce format), consigne de cadrage, format vérifié.
 */
function generate_image_for_ratio(string $srcAbsPath, string $prompt, string $aspectRatio, int $retries, bool $directed = false): ?string
{
    $prepared = prepare_generation_source($srcAbsPath, $aspectRatio);
    $bytes = gemini_generate_image($prepared ?? $srcAbsPath, $prompt . generated_framing_prompt($aspectRatio, $directed), $retries, $aspectRatio);
    if ($prepared) @unlink($prepared);
    return $bytes ? fit_generated_to_ratio($bytes, $aspectRatio) : null;
}

/** Visuel au format ordinateur (3:2), enregistré dans uploads/ : chemin, ou null. */
function generate_desktop_image(string $srcAbsPath, string $prompt, string $baseName, int $retries = 1, bool $directed = false): ?string
{
    $bytes = generate_image_for_ratio($srcAbsPath, $prompt, GENERATED_IMAGE_FORMATS['desktop'], $retries, $directed);
    return $bytes ? save_binary_photo($bytes, $baseName, 'jpg', 1800) : null;
}

/**
 * Photo détourée (vrai PNG transparent, ou repli IA sur fond blanc) : ses
 * formats se font SANS IA (objet posé entier, marge, ombre portée) —
 * jamais par prolongement du décor, qui inventerait une mise en situation.
 */
function is_cutout_photo(array $row): bool
{
    return str_starts_with((string) $row['label'], 'Détourée')
        || (str_ends_with(strtolower((string) $row['path']), '.png')
            && ($img = @imagecreatefrompng(__DIR__ . '/../' . $row['path'])) && image_has_transparent_edge($img));
}

/**
 * Format d'une image existante : 'desktop' (≈ 3:2), 'mobile' (≈ 9:16) ou
 * 'other' (carré, 4:3…), à 3 % près. null si illisible.
 */
function image_format_kind(string $absPath): ?string
{
    $size = @getimagesize($absPath);
    if (!$size || !$size[1]) return null;
    $r = $size[0] / $size[1];
    foreach (GENERATED_IMAGE_FORMATS as $kind => $ratio) {
        if (abs($r / aspect_ratio_value($ratio) - 1) <= 0.03) return $kind;
    }
    return 'other';
}

/**
 * Consigne pour refaire un visuel fini dans un autre format : même scène, même
 * objet, même décor, simplement recadré (portrait ou paysage) — une vraie photo
 * à ce format, jamais l'ancienne image posée sur des bandes floutées.
 */
function build_recompose_prompt(string $aspectRatio): string
{
    [$shape, $more, $less] = aspect_ratio_value($aspectRatio) < 1
        ? ['vertical 9:16 portrait photo for a smartphone screen', 'above and below (wall, ceiling, shelf above; floor, table, surface below)', 'on the left and right']
        : ['horizontal 3:2 landscape photo for a computer screen', 'on the left and right (more of the room, wall, furniture, surface)', 'above and below'];
    return "This is a finished lifestyle product photo for an online antiques shop. Re-shoot the SAME scene as a $shape: "
        . "the same object (identical shape, colors, materials, details), the same room, decor, style, lighting and color grading. "
        . "Only the camera framing changes: show more of the scene $more and less $less if needed. "
        . "The object must stay entirely visible and well placed, never cut by an edge of the frame. "
        . "The whole image must be a single sharp, photorealistic photograph — no blurred bands, no borders, no letterboxing, "
        . "no text, no watermark, and do not add any person or object that is not in the original. "
        . "Respond with the generated image only, no text in your reply.";
}

/**
 * Programme l'autre format d'un visuel, produit ensuite par une requête à
 * part (admin/mobile-variants.php, lancée par toutes les pages de l'admin)
 * pour qu'aucune requête n'enchaîne deux générations et ne dépasse le délai
 * du serveur (erreur 504). Le visuel est refait dans le nouveau format (build_recompose_prompt).
 * $target : 'mobile' (fabriquer la 9:16 depuis la 3:2) ou 'desktop'
 * (fabriquer la 3:2 depuis une image 9:16 ou d'un autre format) ; $then :
 * format à fabriquer ensuite, depuis le résultat.
 */
function queue_format_job(int $photoId, string $srcRelPath, string $target, ?string $then = null, ?string $mode = null, ?array $regen = null): void
{
    db()->prepare('UPDATE product_photos SET mobile_pending = ? WHERE id = ?')
        ->execute([json_encode(['src' => $srcRelPath, 'target' => $target, 'then' => $then, 'mode' => $mode, 'regen' => $regen, 'attempts' => 0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $photoId]);
}

/**
 * Consigne d'ombre portée (IA) pour un objet détouré posé sur fond blanc :
 * l'objet reste identique, seule une ombre réaliste de studio est ajoutée.
 */
function build_shadow_prompt(string $aspectRatio): string
{
    return "This is a cut-out photo of a real secondhand/vintage object for an online antiques shop, placed on a plain "
        . "white $aspectRatio canvas. Add a realistic, soft, natural drop shadow and contact shadow beneath and slightly "
        . "behind the object, exactly like a professional e-commerce studio photo lit from above and slightly in front, "
        . "so the object looks naturally resting on a white seamless surface. Keep the object EXACTLY identical — shape, "
        . "colors, materials, details, size and position in the frame: do not redraw, restyle, move, crop or resize it. "
        . "Keep the background plain pure white, seamless, with no other objects, no texture, no visible floor line or "
        . "horizon. The whole object must stay entirely visible with its margins. No text, no watermark. "
        . "Respond with the generated image only, no text in your reply.";
}

/**
 * Version avec ombre portée générée par l'IA d'une photo détourée, au format
 * donné : l'objet est posé entier (avec marge) sur une toile blanche au bon
 * format, puis Gemini ajoute l'ombre. Chemin du JPEG enregistré, ou null.
 */
function generate_shadow_image(string $cutoutAbsPath, string $aspectRatio, string $baseName, int $retries = 1): ?string
{
    if (!GEMINI_API_KEY) return null;
    $src = @imagecreatefromstring((string) @file_get_contents($cutoutAbsPath));
    if (!$src) return null;
    $ratio = aspect_ratio_value($aspectRatio);
    $padded = pad_image_to_ratio($src, $ratio, GENERATED_IMAGE_SUBJECT_FILL, 1536, image_has_transparent_edge($src));
    // Toile blanche (un détourage PNG est transparent autour de l'objet).
    $canvas = imagecreatetruecolor(imagesx($padded), imagesy($padded));
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagealphablending($canvas, true);
    imagecopy($canvas, $padded, 0, 0, 0, 0, imagesx($padded), imagesy($padded));
    $tmp = tempnam(sys_get_temp_dir(), 'shd') . '.jpg';
    imagejpeg($canvas, $tmp, 92);
    foreach ([$src, $padded, $canvas] as $im) imagedestroy($im);

    $bytes = gemini_generate_image($tmp, build_shadow_prompt($aspectRatio), $retries, $aspectRatio);
    @unlink($tmp);
    return $bytes ? save_binary_photo(fit_generated_to_ratio($bytes, $aspectRatio), $baseName, 'jpg', 1800) : null;
}

/**
 * Version smartphone (9:16) d'un visuel 3:2 tout juste généré. $regenSrc / $keywords : photo de départ et
 * consigne du vendeur de la mise en situation, pour la regénérer directement en 9:16 quand le modèle refuse de
 * recadrer l'image finie (il le fait pour une photo réaliste de personne).
 */
function queue_mobile_variant(int $photoId, string $desktopRelPath, ?string $regenSrc = null, string $keywords = ''): void
{
    queue_format_job($photoId, $desktopRelPath, 'mobile', null, null, $regenSrc ? ['src' => $regenSrc, 'keywords' => $keywords] : null);
}

/**
 * Complète les formats d'un visuel existant selon SON format réel :
 * 3:2 → on fabrique la 9:16 ; 9:16 → on fabrique la 3:2 (l'image actuelle
 * devient la version smartphone) ; autre format → la 3:2, puis la 9:16.
 * Renvoie le format détecté, ou null si rien à faire / image illisible.
 */
function queue_format_completion(array $row): ?string
{
    if (!empty($row['path_mobile'])) return null;
    $kind = image_format_kind(__DIR__ . '/../' . $row['path']);
    match ($kind) {
        'desktop' => queue_format_job((int) $row['id'], $row['path'], 'mobile'),
        'mobile' => queue_format_job((int) $row['id'], $row['path'], 'desktop'),
        'other' => queue_format_job((int) $row['id'], $row['path'], 'desktop', 'mobile'),
        default => null,
    };
    return $kind;
}

/** Visuels dont un format reste à générer. */
function pending_mobile_variant_ids(): array
{
    return array_map('intval', db()->query('SELECT id FROM product_photos WHERE mobile_pending IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Exécute UNE tâche de format en attente : ['ok' => bool, 'path' => …,
 * 'next' => true si une autre étape suit pour ce visuel, 'error' => …].
 */
function run_mobile_variant(int $photoId): array
{
    $stmt = db()->prepare('SELECT * FROM product_photos WHERE id = ?');
    $stmt->execute([$photoId]);
    $row = $stmt->fetch();
    if (!$row || $row['mobile_pending'] === null) return ['ok' => true, 'done' => true];
    ai_usage_context($row['product_ref']);
    $job = json_decode((string) $row['mobile_pending'], true) ?: [];
    $target = ($job['target'] ?? 'mobile') === 'desktop' ? 'desktop' : 'mobile';
    $aspect = GENERATED_IMAGE_FORMATS[$target];
    // Toujours le visuel lui-même (jamais la photo d'origine) : les deux
    // formats montrent ainsi la même image — sauf l'ombre portée IA, qui
    // repart du détourage d'origine.
    $isShadow = ($job['mode'] ?? null) === 'shadow' && !empty($job['src']);
    $srcRel = $isShadow ? (string) $job['src'] : (string) $row['path'];
    $srcAbs = realpath(__DIR__ . '/../' . $srcRel);
    $baseName = preg_replace('/-(mobile|desktop)(-[0-9a-f]{8})?$/', '', pathinfo($srcRel, PATHINFO_FILENAME)) . '-' . $target;

    // Résultat enregistré : la 9:16 va dans path_mobile ; la 3:2 devient
    // l'image principale (l'ancienne, si elle était en 9:16, passe en
    // version smartphone), puis l'étape suivante éventuelle est programmée.
    $store = static function (?string $path) use ($row, $photoId, $target, $srcRel, $job): array {
        if (!$path) {
            db()->prepare('UPDATE product_photos SET mobile_pending = NULL WHERE id = ?')->execute([$photoId]);
            return ['ok' => false, 'error' => 'Format impossible à produire.'];
        }
        if ($target === 'mobile') {
            db()->prepare('UPDATE product_photos SET path_mobile = ?, mobile_pending = NULL WHERE id = ?')->execute([$path, $photoId]);
            return ['ok' => true, 'path' => $path];
        }
        $srcWasMobile = image_format_kind(__DIR__ . '/../' . $srcRel) === 'mobile';
        db()->prepare('UPDATE product_photos SET path = ?, path_mobile = ?, mobile_pending = NULL WHERE id = ?')
            ->execute([$path, $srcWasMobile ? $srcRel : null, $photoId]);
        sync_cover_photo($row['product_ref']);
        if (!$srcWasMobile && ($job['then'] ?? null) === 'mobile') {
            queue_format_job($photoId, $path, 'mobile');
            return ['ok' => true, 'path' => $path, 'next' => true];
        }
        return ['ok' => true, 'path' => $path];
    };
    // Repli sans IA, seulement pour l'ombre portée (fond uni, objet détouré) : jamais pour une scène,
    // dont les bandes floutées ne ressemblent pas à une vraie photo au bon format.
    $fallback = static fn (): array => $store($srcAbs && $isShadow
        ? fit_ratio_file($srcAbs, aspect_ratio_value($aspect), $baseName, shadow: true)
        : null) + ['fallback' => true];
    if (!$srcAbs || !is_file($srcAbs) || !GEMINI_API_KEY) return $isShadow ? $fallback() : $store(null);

    if ($isShadow) {
        $path = generate_shadow_image($srcAbs, $aspect, $baseName, 0);
    } else {
        // Le visuel fini, tel quel (sans bandes ajoutées), est refait dans l'autre format.
        $small = downscale_for_ai($srcAbs, 1280, 85) ?? $srcAbs;
        $bytes = gemini_generate_image($small, build_recompose_prompt($aspect), 0, $aspect);
        if ($small !== $srcAbs) @unlink($small);
        $bytes = $bytes ? fit_generated_to_ratio($bytes, $aspect, false) : null;
        // Refus de recadrer l'image finie (photo réaliste de personne) : la même mise en situation est regénérée
        // directement en 9:16 depuis la photo de départ, avec la même consigne — scène semblable, pas identique.
        $regenAbs = $target === 'mobile' && !empty($job['regen']['src']) ? realpath(__DIR__ . '/../' . $job['regen']['src']) : false;
        if (!$bytes && $regenAbs && is_file($regenAbs)) {
            $keywords = (string) ($job['regen']['keywords'] ?? '');
            $smallSrc = downscale_for_ai($regenAbs, 1280, 85) ?? $regenAbs;
            $bytes = generate_image_for_ratio($smallSrc, build_ambiance_prompt($keywords), $aspect, 0, $keywords !== '');
            if ($smallSrc !== $regenAbs) @unlink($smallSrc);
        }
        $path = $bytes ? save_binary_photo($bytes, $baseName, 'jpg', 1800) : null;
    }

    if (!$path) {
        // Trois essais au plus, répartis sur les visites suivantes de l'admin.
        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        if ($job['attempts'] >= 3) return $isShadow ? $fallback() : $store(null);
        db()->prepare('UPDATE product_photos SET mobile_pending = ? WHERE id = ?')->execute([json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $photoId]);
        return ['ok' => false, 'error' => 'Génération échouée, nouvel essai plus tard.'];
    }
    return $store($path);
}

/**
 * <img>, ou <picture> servant la version smartphone (9:16) aux écrans étroits
 * quand elle existe. Chemins relatifs à la racine web.
 */
function responsive_image_html(string $src, ?string $srcMobile, string $alt, string $class = ''): string
{
    $img = '<img src="/' . h($src) . '" alt="' . h($alt) . '"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . '>';
    if (!$srcMobile) return $img;
    return '<picture><source media="(max-width: 780px)" srcset="/' . h($srcMobile) . '">' . $img . '</picture>';
}

/**
 * Calls Gemini (vision → texte) pour décrire une image : renvoie le texte
 * brut de la réponse, ou null en cas d'échec. Même style de retry que
 * gemini_generate_image() mais sur le modèle texte (bien plus rapide, une
 * requête synchrone reste raisonnable côté admin).
 */
/**
 * @param string[] $extraAbsPaths Autres photos du même objet (autres angles),
 *  envoyées à la suite de la première dans la même requête.
 */
function gemini_describe_image(string $srcAbsPath, string $prompt, int $retries = 1, array $extraAbsPaths = []): ?string
{
    if (!GEMINI_API_KEY) return null;

    $parts = [['text' => $prompt]];
    // $srcAbsPath vide : consigne en texte seul (pas d'image).
    foreach (array_merge($srcAbsPath !== '' ? [$srcAbsPath] : [], $extraAbsPaths) as $i => $path) {
        $imgData = @file_get_contents($path);
        if ($imgData === false) {
            if ($i === 0 && $srcAbsPath !== '') return null;
            continue;
        }
        $mime = @getimagesize($path)['mime'] ?? 'image/jpeg';
        $parts[] = ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($imgData)]];
    }

    $payload = [
        'contents' => [[
            'parts' => $parts,
        ]],
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY;

    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 90,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body !== false && $status < 400) {
            $data = json_decode($body, true);
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if ($text) {
                ai_usage_log_text($data['usageMetadata'] ?? []);
                return $text;
            }
            error_log('gemini_describe_image attempt ' . $attempt . ': HTTP ' . $status . ' but no text in response');
        } else {
            error_log('gemini_describe_image attempt ' . $attempt . ': HTTP ' . $status . ', body: ' . substr((string) $body, 0, 500));
        }

        if ($attempt < $retries) sleep(2);
    }

    return null;
}

/** Prompt demandant un JSON strict {label, tags} décrivant honnêtement l'image. */
function build_media_describe_prompt(): string
{
    return "Tu regardes la photo d'un objet ou d'une scène pour la médiathèque d'" . tenant('ai.shop') . ". "
        . "Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour, sans markdown, de cette forme exacte : "
        . '{"label": "titre court et factuel en français (5-8 mots)", "tags": "4 à 6 mots-clés en français séparés par des virgules"}. '
        . "Décris uniquement ce que tu vois réellement (matière, forme, couleur, type d'objet ou de scène) — "
        . "n'invente ni marque, ni époque, ni origine que tu ne peux pas déterminer visuellement.";
}

/** Parse la réponse JSON (avec ou sans clôture ```) de build_media_describe_prompt(). */
function parse_media_describe_response(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
    $data = json_decode($text, true);
    if (!is_array($data) || empty($data['label'])) return null;
    return [
        'label' => trim((string) $data['label']),
        'tags' => trim((string) ($data['tags'] ?? '')),
    ];
}

/** Slug ASCII simple pour un nom de fichier lisible (accents retirés, minuscules, tirets). */
function slugify(string $text, int $maxLength = 60): string
{
    // Remplacement direct des accents français avant le TRANSLIT générique,
    // qui insère souvent une apostrophe parasite (ex : "métal" -> "m'etal").
    static $accents = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ];
    $text = strtr(mb_strtolower($text), $accents);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text !== '' ? substr($text, 0, $maxLength) : 'media';
}

/* ---------- Import par lot (zip -> regroupement -> fiches produit) ---------- */

/**
 * Extrait un zip vers un dossier dédié et ne garde que les images lisibles
 * (JPEG/PNG/WEBP) — ignore dossiers, fichiers système (__MACOSX, .DS_Store),
 * et les formats illisibles par GD (HEIC/HEIF notamment, courant sur iPhone).
 * Retourne ['paths' => [chemins absolus extraits...], 'skipped' => [noms ignorés...]].
 */
/**
 * Supprime les dossiers d'extraction d'imports par lot abandonnés en cours
 * de route (session expirée, onglet fermé avant de cliquer "Terminer
 * l'import") — le seul autre nettoyage existant (nouvel upload dans la même
 * session, ou "Terminer l'import" explicite) ne s'exécute jamais dans ce
 * cas, laissant sinon les photos extraites s'accumuler indéfiniment sur le
 * disque. Appelé à chaque nouvel upload plutôt que via une tâche planifiée,
 * cet hébergement mutualisé ne permettant pas de cron/tâche de fond fiable.
 */
function batch_import_cleanup_stale(string $exceptDirAbs, int $maxAgeSeconds = 86400): void
{
    $base = __DIR__ . '/../uploads/batch-import';
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if ($dir === $exceptDirAbs) continue;
        if (time() - (@filemtime($dir) ?: 0) < $maxAgeSeconds) continue;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($dir);
    }
}

function batch_import_extract_zip(string $zipAbsPath, string $destDirAbs): array
{
    $result = ['paths' => [], 'skipped' => []];
    if (!class_exists('ZipArchive')) return $result;

    $zip = new ZipArchive();
    if ($zip->open($zipAbsPath) !== true) return $result;
    if (!is_dir($destDirAbs)) mkdir($destDirAbs, 0755, true);

    $validExt = ['jpg', 'jpeg', 'png', 'webp'];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $base = basename($name);
        if ($name === '' || str_ends_with($name, '/') || str_contains($name, '__MACOSX') || $base === '' || $base[0] === '.') {
            continue;
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (!in_array($ext, $validExt, true)) {
            $result['skipped'][] = $base;
            continue;
        }
        $data = $zip->getFromIndex($i);
        if ($data === false) { $result['skipped'][] = $base; continue; }

        $safeName = substr(bin2hex(random_bytes(4)), 0, 8) . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $base);
        $destPath = $destDirAbs . '/' . $safeName;
        file_put_contents($destPath, $data);

        if (!@getimagesize($destPath)) {
            @unlink($destPath);
            $result['skipped'][] = $base;
            continue;
        }

        // L'extraction écrit le fichier avec l'horodatage courant — sans ça,
        // le repli par date de fichier (quand une photo n'a pas d'EXIF
        // DateTimeOriginal) ne verrait plus aucun écart entre les photos et
        // les regrouperait toutes ensemble. Le zip conserve la date d'origine
        // de chaque entrée : on la réapplique après extraction.
        $stat = $zip->statIndex($i);
        if ($stat && !empty($stat['mtime'])) {
            @touch($destPath, (int) $stat['mtime']);
        }

        $result['paths'][] = $destPath;
    }
    $zip->close();
    return $result;
}

/**
 * Regroupe une liste de photos (chemins absolus) par produit probable, en se
 * basant sur l'heure de prise de vue (EXIF DateTimeOriginal, ou date de
 * fichier à défaut) : des photos prises à moins de $gapSeconds d'écart sont
 * supposées être le même objet photographié sous plusieurs angles ; un écart
 * plus grand marque le passage à l'objet suivant. Reste une estimation —
 * l'admin peut corriger le regroupement à la main avant de valider (voir
 * admin/batch-import.php).
 */
/**
 * Trie une liste de photos par heure de prise de vue estimée (EXIF
 * DateTimeOriginal, ou date du fichier à défaut) — factorisé pour être
 * réutilisé à la fois par le regroupement par heure et par le découpage en
 * lots du regroupement par IA (voir gemini_group_photos()), où grouper des
 * photos proches dans le temps dans le même lot maximise les chances qu'un
 * même objet ne se retrouve pas scindé entre deux appels séparés.
 */
function batch_import_sort_by_capture_time(array $absPaths): array
{
    $items = [];
    foreach ($absPaths as $path) {
        $ts = null;
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $raw = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
            if ($raw) {
                $dt = DateTime::createFromFormat('Y:m:d H:i:s', $raw);
                if ($dt) $ts = $dt->getTimestamp();
            }
        }
        $ts ??= @filemtime($path) ?: 0;
        $items[] = ['path' => $path, 'ts' => $ts];
    }
    usort($items, fn($a, $b) => $a['ts'] <=> $b['ts'] ?: strcmp($a['path'], $b['path']));
    return array_column($items, 'path');
}

// Nombre de photos comparées en un seul appel Gemini pour le regroupement —
// un lot réel peut compter plusieurs dizaines de photos, et toutes les
// envoyer d'un coup dépasse largement les 60s de fastcgi_read_timeout d'un
// hébergement mutualisé (nginx/Herd comme OVH) : le nombre d'appels
// séquentiels dans UNE requête PHP est ce qui compte, pas la taille de
// chaque appel individuellement. Chaque requête (upload puis, si besoin,
// "Analyser un lot par IA") ne traite donc jamais plus d'un lot de cette
// taille — voir gemini_group_photos_chunk() ci-dessous et
// admin/batch-import-action.php.
const BATCH_IMPORT_AI_CHUNK_SIZE = 10;

/**
 * Un seul appel Gemini regroupant les photos d'UN lot borné (voir la
 * constante BATCH_IMPORT_AI_CHUNK_SIZE ci-dessus).
 */
function gemini_group_photos_chunk(array $absPaths): ?array
{
    if (!GEMINI_API_KEY || !$absPaths) return [];

    $parts = [
        ['text' => "Voici " . count($absPaths) . " photos numérotées de 1 à " . count($absPaths) . ", dans l'ordre où elles apparaissent ci-dessous. "
            . "Certaines montrent le MÊME objet sous des angles ou un cadrage différents (photos d'une seule pièce pour " . tenant('ai.shop') . ") ; "
            . "d'autres montrent des objets différents. Regroupe les numéros de photos qui montrent le même objet. "
            . "Sois prudent : en cas de doute, considère que ce sont des objets différents plutôt que de les regrouper à tort. "
            . "Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour, sans markdown, de cette forme exacte : "
            . '{"groups": [[1,2],[3],[4,5,6]]} — chaque numéro de 1 à ' . count($absPaths) . ' doit apparaître exactement une fois, au total.'],
    ];

    $tmpFiles = [];
    foreach ($absPaths as $i => $path) {
        $small = downscale_for_ai($path, 640, 70) ?? $path;
        if ($small !== $path) $tmpFiles[] = $small;
        $imgData = @file_get_contents($small);
        if ($imgData === false) continue;
        $mime = @getimagesize($small)['mime'] ?? 'image/jpeg';
        $parts[] = ['text' => 'Photo n°' . ($i + 1) . ' :'];
        $parts[] = ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($imgData)]];
    }

    $payload = ['contents' => [['parts' => $parts]]];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        // Volontairement sous les 90s habituelles des autres appels Gemini :
        // le lot est déjà borné en taille pour tenir sous les 60s de
        // fastcgi_read_timeout, mieux vaut échouer proprement avant cette
        // limite qu'attendre jusqu'à la couper.
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    foreach ($tmpFiles as $f) @unlink($f);

    if ($body === false || $status >= 400) {
        error_log('gemini_group_photos_chunk: HTTP ' . $status . ', body: ' . substr((string) $body, 0, 500));
        return null;
    }
    $data = json_decode($body, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) return null;
    ai_usage_log_text($data['usageMetadata'] ?? []);

    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
    $parsed = json_decode($text, true);
    if (!is_array($parsed) || !isset($parsed['groups']) || !is_array($parsed['groups'])) return null;

    // Validation : chaque indice doit être un numéro de photo valide (1 à N),
    // utilisé une seule fois — tout indice absent de la réponse repart en
    // groupe individuel (repli prudent) plutôt que d'être perdu.
    $count = count($absPaths);
    $seen = [];
    $groups = [];
    foreach ($parsed['groups'] as $rawGroup) {
        if (!is_array($rawGroup)) continue;
        $groupPaths = [];
        foreach ($rawGroup as $num) {
            $num = (int) $num;
            if ($num < 1 || $num > $count || isset($seen[$num])) continue;
            $seen[$num] = true;
            $groupPaths[] = $absPaths[$num - 1];
        }
        if ($groupPaths) $groups[] = $groupPaths;
    }
    for ($num = 1; $num <= $count; $num++) {
        if (!isset($seen[$num])) $groups[] = [$absPaths[$num - 1]];
    }

    return $groups ?: null;
}

function batch_import_auto_group(array $absPaths, int $gapSeconds = 120): array
{
    $items = [];
    foreach ($absPaths as $path) {
        $ts = null;
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $raw = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
            if ($raw) {
                $dt = DateTime::createFromFormat('Y:m:d H:i:s', $raw);
                if ($dt) $ts = $dt->getTimestamp();
            }
        }
        $ts ??= @filemtime($path) ?: 0;
        $items[] = ['path' => $path, 'ts' => $ts];
    }
    usort($items, fn($a, $b) => $a['ts'] <=> $b['ts'] ?: strcmp($a['path'], $b['path']));

    $groups = [];
    $current = [];
    $lastTs = null;
    foreach ($items as $item) {
        if ($lastTs !== null && ($item['ts'] - $lastTs) > $gapSeconds) {
            $groups[] = $current;
            $current = [];
        }
        $current[] = $item['path'];
        $lastTs = $item['ts'];
    }
    if ($current) $groups[] = $current;
    return $groups;
}

/** Prompt demandant une note de 1 à 10 sur la simplicité de détourage d'une photo. */
function build_cutout_simplicity_prompt(): string
{
    return "Tu regardes une photo d'un objet destinée à un détourage (suppression du fond). "
        . "Évalue uniquement la SIMPLICITÉ du détourage sur une échelle de 1 (fond très complexe, "
        . "encombré, objet mal cadré ou partiellement caché) à 10 (fond uni ou très simple, objet "
        . "entièrement visible et bien détaché du fond). Réponds UNIQUEMENT avec le chiffre, sans "
        . "aucun autre texte.";
}

function gemini_score_cutout_simplicity(string $srcAbsPath): ?int
{
    $small = downscale_for_ai($srcAbsPath) ?? $srcAbsPath;
    $text = gemini_describe_image($small, build_cutout_simplicity_prompt());
    if ($small !== $srcAbsPath) @unlink($small);
    if (!$text) return null;
    return preg_match('/\d+/', $text, $m) ? max(1, min(10, (int) $m[0])) : null;
}

/** Prompt demandant un JSON strict {name, description, category, price_hint} pour une fiche produit. */
function build_product_sheet_prompt(int $photoCount = 1, string $notes = ''): string
{
    $cats = universes_prompt_list();
    $examples = ai_shop_examples() ? ' (' . ai_shop_examples() . ')' : '';
    $seen = $photoCount > 1
        ? "Tu regardes " . $photoCount . " photos du MÊME objet, sous différents angles : " . tenant('ai.item') . " pour " . tenant('ai.shop') . $examples . ". Sers-toi de tous les angles (marques, signatures, état, dessous). "
        : "Tu regardes la photo d'" . tenant('ai.item') . " pour " . tenant('ai.shop') . $examples . ". ";
    $notes = trim($notes);
    return $seen
        . ($notes !== '' ? "Indications du vendeur, à prendre en compte : « " . $notes . " ». " : '')
        . "Réponds UNIQUEMENT avec un objet JSON strict, "
        . "sans texte autour, sans markdown, de cette forme exacte : "
        . '{"name": "nom court et vendeur (4-8 mots)", "description": "description chaleureuse en 2-3 phrases, honnête sur l\'état visible", "category": "la clé exacte (avant le signe =) de l\'UNIVERS du produit, son grand domaine, parmi : ' . $cats . '", "materials": "matières visibles, séparées par des virgules (ex : grès émaillé, bois de chêne), vide si invisibles", "etat": "' . product_condition_prompt_list() . '", "size_text": "vêtement ou chaussure : taille de l\'étiquette, sinon taille probable écrite « M (probable) » ; objet : dimensions estimées ; très courte", "weight_grams": "poids estimé en grammes (entier), 0 si impossible", "nature": "clé de la nature de l\'objet (ce qu\'il EST) parmi : ' . implode(', ', array_keys(product_nature_options())) . '", "sous_categorie": "clé de sa sous-catégorie, appartenant à cette nature. Natures [sous-catégories] : ' . product_nature_prompt_list() . '", "price_hint": "fourchette de prix indicative en euros, ex : 25-35 €"}. '
        . "Décris uniquement ce que tu vois réellement — n'invente ni marque, ni époque, ni origine que "
        . "tu ne peux pas déterminer visuellement. Le prix est une simple estimation grossière à titre "
        . "indicatif, le vendeur l'ajustera.";
}

/** Parse la réponse JSON (avec ou sans clôture ```) de build_product_sheet_prompt(). */
function parse_product_sheet_response(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
    $data = json_decode($text, true);
    if (!is_array($data) || empty($data['name'])) return null;
    $natureKey = product_nature_resolve((string) ($data['nature'] ?? ''), (string) ($data['sous_categorie'] ?? ''))['nature'];
    // Univers : celui que l'IA donne (clé ou libellé) ; sinon celui qui correspond à la nature détectée (vêtements → Mode) ; sinon le défaut.
    $cat = universe_key_resolve((string) ($data['category'] ?? '')) ?: (universe_key_for_nature($natureKey) ?: default_category_key());
    return [
        'name' => trim((string) $data['name']),
        'description' => trim((string) ($data['description'] ?? '')),
        'category' => $cat,
        'materials' => mb_substr(trim((string) (is_array($data['materials'] ?? null) ? implode(', ', $data['materials']) : ($data['materials'] ?? ''))), 0, 200),
        'etat' => product_condition_key((string) ($data['etat'] ?? '')),
        'size_text' => mb_substr(trim((string) ($data['size_text'] ?? '')), 0, 60),
        'weight_grams' => max(0, min(300000, (int) preg_replace('/\D/', '', (string) ($data['weight_grams'] ?? '')))),
        'nature' => product_nature_resolve((string) ($data['nature'] ?? ''), (string) ($data['sous_categorie'] ?? ''))['nature'],
        'sous_categorie' => product_nature_resolve((string) ($data['nature'] ?? ''), (string) ($data['sous_categorie'] ?? ''))['sous_categorie'],
        'price_hint' => trim((string) ($data['price_hint'] ?? '')),
    ];
}

/**
 * Barème de l'« État » d'une pièce, du neuf à restaurer, par nuances : [groupe => [clé => [libellé, précision]]].
 * La clé est ce qui est enregistré (products.etat) ; libellé et précision s'affichent sur la fiche.
 */
function product_condition_options(): array
{
    return [
        'Neuf' => [
            'neuf_etiquette' => ['Neuf avec étiquette', "Jamais porté ni utilisé, étiquette d'origine encore attachée."],
            'neuf_emballe' => ['Neuf, emballé', "Dans son emballage d'origine fermé (blister, boîte scellée, sachet)."],
            'neuf_boite' => ['Neuf, avec sa boîte', "Jamais utilisé, avec sa boîte d'origine (ouverte)."],
            'neuf' => ['Neuf sans étiquette', 'Jamais porté ni utilisé, étiquette ou emballage absents.'],
        ],
        'Comme neuf' => [
            'comme_neuf' => ['Comme neuf', 'Porté ou utilisé une ou deux fois, aucune trace visible.'],
            'presque_neuf' => ['Presque neuf', "Très légères traces d'utilisation, à peine visibles."],
            'excellent' => ['Excellent état', 'Aucun défaut notable, entretenu avec soin.'],
        ],
        'Bon état' => [
            'tres_bon' => ['Très bon état', "Quelques légères traces de l'usage, rien de gênant."],
            'bon' => ['Bon état', "Signes d'usage normaux, pleinement fonctionnel."],
            'bon_age' => ['Bon état pour son âge', 'Pièce ancienne bien conservée, avec la patine du temps.'],
            'patine' => ['Belle patine', "Patine d'ancienneté recherchée (cuivre, bois, cuir…)."],
        ],
        'État correct' => [
            'correct' => ['État correct', "Traces d'usage visibles, sans incidence sur l'utilisation."],
            'usage' => ["Traces d'usage marquées", 'Usure, rayures ou décolorations visibles, montrées en photo.'],
            'defauts' => ['Petits défauts signalés', 'Éclat, tache, accroc ou léger manque (voir la description).'],
        ],
        'À remettre en état' => [
            'use' => ['Usé', 'Très marqué par l\'usage, encore utilisable.'],
            'restaurer' => ['À restaurer', 'Demande un nettoyage, une réparation ou une restauration.'],
            'pieces' => ['Pour pièces ou décoration', 'Ne fonctionne pas ou incomplet : à réparer, ou pour décorer.'],
        ],
    ];
}

/** [libellé, précision] d'une clé d'état, ou null si elle est inconnue / vide. */
function product_condition(?string $key): ?array
{
    foreach (product_condition_options() as $group) {
        if (isset($group[(string) $key])) return $group[(string) $key];
    }
    return null;
}

/** Clé d'état valide (la clé elle-même ou son libellé, sans tenir compte de la casse), sinon chaîne vide. */
function product_condition_key(?string $value): string
{
    $wanted = mb_strtolower(trim((string) $value));
    if ($wanted === '') return '';
    foreach (product_condition_options() as $group) {
        foreach ($group as $key => [$label]) {
            if ($wanted === $key || $wanted === mb_strtolower($label)) return $key;
        }
    }
    return '';
}

/** <option> de tous les états, regroupés par nuance, avec $selected présélectionné ; première option : « Non précisé ». */
function product_condition_select_html(?string $selected): string
{
    $html = '<option value="">Non précisé</option>';
    foreach (product_condition_options() as $group => $items) {
        $html .= '<optgroup label="' . h($group) . '">';
        foreach ($items as $key => [$label]) {
            $html .= '<option value="' . h($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

/**
 * Nature d'une pièce et sous-catégories : [clé de nature => ['label' => …, 'subs' => [clé => libellé]]]. Indépendant de
 * l'« univers » du commerce (rayons de la boutique) : c'est ce que l'objet EST, détecté par l'IA d'après les photos.
 * Les clés sont enregistrées (products.nature, products.sous_categorie) ; une sous-catégorie appartient à une seule nature.
 */
function product_nature_options(): array
{
    return [
        'vetements' => ['label' => 'Vêtements', 'subs' => [
            'hauts' => 'Hauts, t-shirts et chemises', 'pulls' => 'Pulls, gilets et sweats', 'vestes' => 'Vestes et manteaux',
            'robes' => 'Robes et jupes', 'pantalons' => 'Pantalons, jeans et shorts', 'costumes' => 'Costumes et tailleurs',
            'combinaisons' => 'Combinaisons et ensembles', 'lingerie' => 'Lingerie et pyjamas', 'maillots' => 'Maillots de bain',
            'sport' => 'Vêtements de sport', 'enfant' => 'Vêtements enfant et bébé', 'travail' => 'Vêtements de travail et uniformes',
            'traditionnels' => 'Costumes traditionnels et déguisements', 'autres_vetements' => 'Autres vêtements',
        ]],
        'chaussures' => ['label' => 'Chaussures', 'subs' => [
            'baskets' => 'Baskets', 'bottes' => 'Bottes et bottines', 'escarpins' => 'Escarpins et talons', 'sandales' => 'Sandales et tongs',
            'mocassins' => 'Mocassins et derbies', 'ballerines' => 'Ballerines', 'chaussons' => 'Chaussons et sabots', 'chaussures_enfant' => 'Chaussures enfant',
        ]],
        'accessoires' => ['label' => 'Accessoires de mode', 'subs' => [
            'sacs' => 'Sacs et sacoches', 'maroquinerie' => 'Maroquinerie et portefeuilles', 'ceintures' => 'Ceintures et bretelles',
            'foulards' => 'Écharpes, foulards et cravates', 'chapeaux' => 'Chapeaux, casquettes et bonnets', 'gants' => 'Gants et moufles',
            'lunettes' => 'Lunettes', 'parapluies' => 'Parapluies et éventails', 'autres_accessoires' => 'Autres accessoires',
        ]],
        'bijoux' => ['label' => 'Bijoux et montres', 'subs' => [
            'colliers' => 'Colliers et pendentifs', 'bracelets' => 'Bracelets', 'bagues' => 'Bagues', 'boucles' => "Boucles d'oreilles",
            'broches' => 'Broches et pins', 'montres' => 'Montres', 'parures' => 'Parures et coffrets', 'bijoux_fantaisie' => 'Bijoux fantaisie',
        ]],
        'linge' => ['label' => 'Linge de maison et textiles', 'subs' => [
            'draps' => 'Draps et housses', 'nappes' => 'Nappes et chemins de table', 'torchons' => 'Torchons et serviettes', 'rideaux' => 'Rideaux et voilages',
            'plaids' => 'Plaids, couvertures et couvre-lits', 'coussins' => 'Coussins et housses', 'broderies' => 'Broderies, dentelles et napperons',
            'tapis' => 'Tapis et tentures', 'tissus' => 'Tissus au mètre et passementerie',
        ]],
        'decoration' => ['label' => 'Décoration', 'subs' => [
            'vases' => 'Vases et cache-pots', 'cadres' => 'Cadres et miroirs', 'tableaux' => 'Tableaux, gravures et affiches', 'statuettes' => 'Statuettes et objets décoratifs',
            'bougeoirs' => 'Bougeoirs et photophores', 'horloges' => 'Horloges, pendules et réveils', 'boites' => 'Boîtes, coffrets et paniers',
            'globes' => 'Globes, cartes et objets scientifiques', 'religieux' => 'Objets religieux et ex-voto', 'plantes' => 'Pots, jardinières et décors de jardin',
            'autres_deco' => 'Autres objets de décoration',
        ]],
        'arts_table' => ['label' => 'Arts de la table et cuisine', 'subs' => [
            'assiettes' => 'Assiettes et plats', 'verres' => 'Verres, carafes et service à boire', 'tasses' => 'Tasses, bols et théières', 'pichets' => 'Pichets et soupières',
            'couverts' => 'Couverts et ménagères', 'casseroles' => 'Casseroles, cocottes et poêles', 'ustensiles' => 'Ustensiles et moules', 'plateaux' => 'Plateaux et dessous de plat',
            'boites_cuisine' => 'Boîtes, bocaux et pots à épices', 'electromenager' => 'Petit électroménager ancien',
        ]],
        'mobilier' => ['label' => 'Mobilier', 'subs' => [
            'chaises' => 'Chaises, fauteuils et tabourets', 'tables' => 'Tables et bureaux', 'rangements' => 'Buffets, commodes et armoires', 'etageres' => 'Étagères et bibliothèques',
            'canapes' => 'Canapés et banquettes', 'lits' => 'Lits et chevets', 'consoles' => 'Consoles, sellettes et guéridons', 'meubles_toilette' => 'Meubles de toilette et coiffeuses',
            'mobilier_exterieur' => 'Mobilier de jardin', 'mobilier_enfant' => 'Mobilier enfant',
        ]],
        'luminaires' => ['label' => 'Luminaires', 'subs' => [
            'lampes' => 'Lampes à poser', 'suspensions' => 'Suspensions et lustres', 'appliques' => 'Appliques', 'lampadaires' => 'Lampadaires', 'lampes_petrole' => 'Lampes à huile et à pétrole',
        ]],
        'livres_medias' => ['label' => 'Livres, disques et papeterie', 'subs' => [
            'livres' => 'Livres et romans', 'bd' => 'BD et mangas', 'livres_enfants' => 'Livres pour enfants', 'livres_cuisine' => 'Livres de cuisine et de loisirs', 'vinyles' => 'Vinyles et disques',
            'cassettes' => 'Cassettes, CD et DVD', 'cartes_postales' => 'Cartes postales et photos anciennes', 'papeterie' => 'Papeterie, plumes et carnets', 'affiches' => 'Affiches et publicités anciennes',
        ]],
        'jeux' => ['label' => 'Jeux et jouets', 'subs' => [
            'jouets' => 'Jouets anciens', 'poupees' => 'Poupées et peluches', 'jeux_societe' => 'Jeux de société et puzzles', 'jeux_video' => 'Jeux vidéo et consoles',
            'maquettes' => 'Maquettes et modèles réduits', 'jeux_plein_air' => 'Jeux de plein air',
        ]],
        'collection' => ['label' => 'Objets de collection et curiosités', 'subs' => [
            'monnaies' => 'Monnaies, médailles et timbres', 'militaria' => 'Militaria et souvenirs', 'publicitaire' => 'Objets publicitaires', 'sciences' => 'Instruments scientifiques et de mesure',
            'photographie' => 'Appareils photo et optique', 'curiosites' => 'Curiosités et objets insolites', 'orfevrerie' => 'Orfèvrerie et argenterie',
        ]],
        'musique_electro' => ['label' => 'Musique et électronique vintage', 'subs' => [
            'instruments' => 'Instruments de musique', 'radios' => 'Radios et tourne-disques', 'audio' => 'Hi-fi et enceintes', 'telephonie' => 'Téléphones et machines à écrire', 'tv_video' => 'Télévisions et caméras',
        ]],
        'outils_jardin' => ['label' => 'Outils, jardin et brocante utile', 'subs' => [
            'outils' => 'Outils à main', 'quincaillerie' => 'Quincaillerie et serrurerie', 'jardinage' => 'Jardinage', 'cuisine_campagne' => 'Objets de campagne et de ferme', 'velos' => 'Vélos et accessoires',
            'valises' => 'Valises et malles',
        ]],
        'sport' => ['label' => 'Sport et loisirs', 'subs' => [
            'velos_sport' => 'Vélos, trottinettes et accessoires', 'fitness' => 'Fitness et musculation', 'sports_balle' => 'Sports de balle et de ballon', 'raquettes' => 'Tennis, badminton et raquettes',
            'plein_air' => 'Randonnée, camping et plein air', 'glisse' => 'Ski, snowboard et glisse', 'nautique' => 'Sports nautiques, plongée et pêche', 'combat' => 'Sports de combat',
            'equitation' => 'Équitation', 'supporters' => 'Maillots, écharpes et objets de supporters', 'course' => 'Course, athlétisme et cyclisme (équipement)',
        ]],
        'alimentaire' => ['label' => 'Alimentaire', 'subs' => [
            'epicerie_salee' => 'Épicerie salée', 'epicerie_sucree' => 'Épicerie sucrée et confiseries', 'boissons' => 'Boissons, vins et spiritueux', 'boulangerie' => 'Pains, viennoiseries et pâtisseries',
            'frais' => 'Produits frais et charcuterie', 'terroir' => 'Produits du terroir et conserves', 'coffrets' => 'Coffrets et paniers garnis', 'bio' => 'Bio et diététique',
        ]],
        'tv_hifi' => ['label' => 'TV, hifi et audiovisuel', 'subs' => [
            'televisions' => 'Télévisions et écrans de salon', 'enceintes' => 'Enceintes et barres de son', 'casques' => 'Casques et écouteurs', 'amplis' => 'Amplificateurs et tuners',
            'platines' => 'Platines vinyles et lecteurs', 'videoprojecteurs' => 'Vidéoprojecteurs et home cinéma', 'cameras' => 'Caméras et appareils photo numériques', 'accessoires_av' => 'Câbles, télécommandes et accessoires audio-vidéo',
        ]],
        'informatique' => ['label' => 'Informatique', 'subs' => [
            'portables' => 'Ordinateurs portables', 'fixes' => 'Ordinateurs fixes et mini-PC', 'ecrans' => 'Écrans et moniteurs', 'composants' => 'Composants (processeurs, cartes, mémoire)',
            'peripheriques' => 'Claviers, souris et périphériques', 'reseau' => 'Réseau, box et routeurs', 'stockage' => 'Stockage (disques, clés, cartes)', 'imprimantes' => 'Imprimantes et scanners',
            'tablettes' => 'Tablettes et liseuses', 'cables_info' => 'Câbles, chargeurs et accessoires', 'logiciels_jeux' => 'Logiciels et accessoires gaming',
        ]],
        'telephonie' => ['label' => 'Téléphonie et objets connectés', 'subs' => [
            'smartphones' => 'Smartphones', 'telephones_fixes' => 'Téléphones fixes et sans fil', 'coques' => 'Coques, protections et supports', 'montres_connectees' => 'Montres et bracelets connectés',
            'chargeurs' => 'Chargeurs, batteries et câbles', 'domotique' => 'Domotique et objets connectés',
        ]],
        'electromenager' => ['label' => 'Électroménager', 'subs' => [
            'gros_electro' => 'Gros électroménager', 'cuisine_electro' => 'Petit électroménager de cuisine', 'entretien' => 'Aspirateurs et entretien', 'soin_electrique' => 'Soin et beauté électrique',
            'chauffage' => 'Chauffage, ventilation et climatisation',
        ]],
        'beaute' => ['label' => 'Beauté et bien-être', 'subs' => [
            'parfums' => 'Parfums', 'maquillage' => 'Maquillage', 'soins' => 'Soins du visage et du corps', 'cheveux' => 'Cheveux et coiffure', 'accessoires_beaute' => 'Trousses, miroirs et accessoires',
        ]],
        'bebe' => ['label' => 'Bébé et puériculture', 'subs' => [
            'poussettes' => 'Poussettes et porte-bébés', 'sieges' => 'Sièges auto et chaises hautes', 'puericulture' => 'Biberons, repas et soins', 'eveil' => 'Jouets d\'éveil et peluches', 'chambre_bebe' => 'Mobilier et déco de chambre',
        ]],
        'animaux' => ['label' => 'Animaux', 'subs' => [
            'chiens' => 'Accessoires pour chiens', 'chats' => 'Accessoires pour chats', 'aquariophilie' => 'Aquariophilie', 'rongeurs_oiseaux' => 'Rongeurs, oiseaux et autres animaux',
        ]],
        'auto_moto' => ['label' => 'Auto et moto', 'subs' => [
            'pieces_auto' => 'Pièces et entretien auto', 'accessoires_auto' => 'Accessoires et équipement auto', 'moto' => 'Moto, scooter et équipement du motard', 'outillage_auto' => 'Outillage et garage',
        ]],
        'autre' => ['label' => 'Autre', 'subs' => [
            'divers' => 'Divers', 'lots' => 'Lots et assortiments',
        ]],
    ];
}

/** [libellé de nature, libellé de sous-catégorie|null] ou null si la nature est inconnue. */
function product_nature_labels(?string $nature, ?string $sub = null): ?array
{
    $n = product_nature_options()[(string) $nature] ?? null;
    if (!$n) return null;
    return [$n['label'], $n['subs'][(string) $sub] ?? null];
}

/** « Vêtements › Pulls, gilets et sweats », ou chaîne vide. */
function product_nature_text(?string $nature, ?string $sub = null): string
{
    $l = product_nature_labels($nature, $sub);
    return $l ? $l[0] . ($l[1] ? ' › ' . $l[1] : '') : '';
}

/**
 * Nature et sous-catégorie valides à partir de clés ou de libellés (insensible à la casse) :
 * ['nature' => clé|'', 'sous_categorie' => clé|'']. Une sous-catégorie n'est gardée que si elle appartient à la
 * nature ; sans nature, elle sert à la retrouver.
 */
function product_nature_resolve(?string $nature, ?string $sub = null): array
{
    $norm = static fn (?string $v): string => mb_strtolower(trim((string) $v));
    $wantedN = $norm($nature);
    $wantedS = $norm($sub);
    $natureKey = '';
    foreach (product_nature_options() as $key => $n) {
        if ($wantedN !== '' && ($wantedN === $key || $wantedN === $norm($n['label']))) { $natureKey = $key; break; }
    }
    $subKey = '';
    foreach (product_nature_options() as $key => $n) {
        if ($natureKey !== '' && $key !== $natureKey) continue;
        foreach ($n['subs'] as $sk => $label) {
            if ($wantedS !== '' && ($wantedS === $sk || $wantedS === $norm($label))) { $subKey = $sk; $natureKey = $natureKey ?: $key; break 2; }
        }
    }
    return ['nature' => $natureKey, 'sous_categorie' => $subKey];
}

/** <option> des natures, $selected présélectionnée. */
function product_nature_select_html(?string $selected): string
{
    $html = '<option value="">Non précisée</option>';
    foreach (product_nature_options() as $key => $n) {
        $html .= '<option value="' . h($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . h($n['label']) . '</option>';
    }
    return $html;
}

/**
 * <option> des sous-catégories, chacune avec data-nature (assets/nature-select.js ne montre que celles de la nature
 * choisie).
 */
function product_subcategory_select_html(?string $selected): string
{
    $html = '<option value="">Non précisée</option>';
    foreach (product_nature_options() as $nKey => $n) {
        foreach ($n['subs'] as $key => $label) {
            $html .= '<option value="' . h($key) . '" data-nature="' . h($nKey) . '"' . ($key === $selected ? ' selected' : '') . '>' . h($label) . '</option>';
        }
    }
    return $html;
}

/** Liste des natures et sous-catégories (clés) donnée à l'IA pour qu'elle réponde avec des clés valides. */
function product_nature_prompt_list(): string
{
    $items = [];
    foreach (product_nature_options() as $key => $n) {
        $items[] = $key . ' = ' . $n['label'] . ' [' . implode(' ; ', array_map(static fn ($k, $l) => $k . ' = ' . $l, array_keys($n['subs']), $n['subs'])) . ']';
    }
    return implode(' | ', $items);
}

/** Champs d'une fiche que l'IA sait (re)générer, dans l'ordre du formulaire. */
const PRODUCT_AI_FIELDS = ['name', 'description', 'category', 'nature', 'materials', 'etat', 'size', 'weight', 'price'];

/**
 * Photos d'origine d'une pièce (hors détourage et illustrations IA), principale
 * en premier : ce que l'IA doit regarder pour décrire la pièce.
 */
function product_source_photos(string $ref): array
{
    $photos = array_values(array_filter(product_photos_list($ref), static fn ($p) => $p['type'] === 'photo' && !$p['is_illustration'] && !str_starts_with($p['label'], 'Détourée')));
    $isMain = static fn (array $p): bool => $p['label'] === 'Photo principale' || str_starts_with($p['label'], '★');
    usort($photos, static fn ($a, $b) => $isMain($b) <=> $isMain($a));
    return $photos;
}

/**
 * Consigne pour (re)générer certains champs d'une fiche. $fields : clés de
 * PRODUCT_AI_FIELDS à produire ; $current : valeurs actuelles du formulaire
 * (name, description, category, materials, price, size_text) — celles qu'on ne
 * régénère pas servent de contexte cohérent, celles qu'on régénère sont à varier.
 */
function build_product_fields_prompt(array $fields, array $current, int $photoCount, string $notes = '', array $comparables = []): string
{
    $cats = universes_prompt_list();
    $examples = ai_shop_examples() ? ' (' . ai_shop_examples() . ')' : '';
    $seen = match (true) {
        $photoCount > 1 => 'Tu regardes ' . $photoCount . ' photos du MÊME objet, sous différents angles : ' . tenant('ai.item') . ' pour ' . tenant('ai.shop') . $examples . '. ',
        $photoCount === 1 => "Tu regardes la photo d'" . tenant('ai.item') . ' pour ' . tenant('ai.shop') . $examples . '. ',
        default => "Tu n'as pas de photo : tu travailles d'après le texte déjà saisi, pour " . tenant('ai.shop') . $examples . '. ',
    };
    $spec = [
        'name' => '"name": "nom court et vendeur (4-8 mots)"',
        'description' => '"description": "description chaleureuse en 2-3 phrases, honnête sur l\'état visible"',
        'category' => '"category": "la clé exacte (avant le signe =) parmi : ' . $cats . ' ; si aucune ne convient vraiment à cet objet, laisse une chaîne vide"',
        'materials' => '"materials": "matières visibles, séparées par des virgules (ex : grès émaillé, bois de chêne) ; chaîne vide si elles ne se voient pas"',
        'nature' => '"nature": "la clé de la NATURE de l\'objet (ce qu\'il EST) et, dans le même objet JSON, "sous_categorie": "la clé de sa sous-catégorie, qui doit appartenir à cette nature. Natures [sous-catégories] : ' . product_nature_prompt_list() . '"',
        'etat' => '"etat": "' . product_condition_prompt_list() . '"',
        'size' => '"size_text": "taille ou dimensions, COURTES. VÊTEMENT, chaussure ou accessoire porté : donne TOUJOURS une taille — celle de l\'étiquette si elle est lisible (« M », « 38 », « 42 FR »), sinon la taille la plus probable d\'après la coupe et les proportions, écrite avec « (probable) » (ex : « M (probable) », « 40 (probable) »), plus la longueur ou la largeur approximatives si elles se devinent. OBJET : ses dimensions estimées (ex : « env. 20 × 15 × 30 cm », hauteur ou diamètre selon la forme). Chaîne vide seulement si la photo ne permet vraiment aucune estimation"',
        'weight' => '"weight_grams": "nombre entier : poids estimé en grammes de l\'objet seul, sans emballage, d\'après sa nature, ses matières et sa taille apparente (ordres de grandeur : sweat 600, robe 450, pichet en grès 900, chaise en bois 4500) ; 0 si impossible à estimer"',
        'price' => '"price": "prix de vente indicatif : UN SEUL montant en euros, ex : 30 €"',
    ];
    $labels = ['name' => 'Nom', 'description' => 'Description', 'category' => 'Catégorie', 'nature' => 'Nature', 'materials' => 'Matières', 'etat' => 'État', 'price' => 'Prix', 'size_text' => 'Taille', 'weight_text' => 'Poids'];
    $known = [];
    $vary = [];
    // Taille et poids se régénèrent par les champs « size » et « weight ».
    $fieldOf = ['size_text' => 'size', 'weight_text' => 'weight'];
    foreach ($labels as $field => $label) {
        $value = trim((string) ($current[$field] ?? ''));
        if ($value === '') continue;
        if (in_array($fieldOf[$field] ?? $field, $fields, true)) {
            if ($field !== 'category') $vary[] = $label . ' actuel(le) : « ' . $value . ' »';
        } else {
            $known[] = $label . ' : ' . $value;
        }
    }
    $notes = trim($notes);
    return $seen
        . ($notes !== '' ? 'Indications du vendeur, à prendre en compte : « ' . $notes . ' ». ' : '')
        . comparables_prompt_text($comparables)
        . ($known ? 'Informations déjà saisies, à respecter et rester cohérent avec : ' . implode(' ; ', $known) . '. ' : '')
        . ($vary ? 'Propose une version DIFFÉRENTE de ce qui existe déjà (' . implode(' ; ', $vary) . '). ' : '')
        . 'Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour, sans markdown, avec exactement ces clés : '
        . '{' . implode(', ', array_map(static fn ($f) => $spec[$f], $fields)) . '}. '
        . "Décris uniquement ce que tu vois réellement — n'invente ni marque, ni époque, ni origine que tu ne peux pas déterminer visuellement. "
        . 'Le prix est une simple estimation à titre indicatif, le vendeur l\'ajustera.';
}

/** « env. 600 g » / « env. 1,2 kg » : poids estimé en grammes, écrit pour la fiche. */
function product_weight_text(int $grams): string
{
    if ($grams <= 0) return '';
    if ($grams < 1000) return 'env. ' . (int) (round($grams / 10) * 10 ?: $grams) . ' g';
    return 'env. ' . str_replace('.', ',', rtrim(rtrim(number_format($grams / 1000, 1, '.', ''), '0'), '.')) . ' kg';
}

/** Consigne donnée à l'IA pour choisir un état : la liste des clés avec leur sens, et la prudence attendue. */
function product_condition_prompt_list(): string
{
    $items = [];
    foreach (product_condition_options() as $group) {
        foreach ($group as $key => [$label, $hint]) $items[] = $key . ' = ' . $label . ' (' . $hint . ')';
    }
    return "la clé exacte (avant le signe =) de l'état, d'après ce qui est VISIBLE, parmi : " . implode(' ; ', $items)
        . ". Sois prudent : n'indique un état « neuf », « emballé » ou « avec étiquette » que si l'emballage ou l'étiquette est visible ; "
        . "si l'état ne peut pas être jugé, laisse une chaîne vide";
}

/** Réponse JSON de build_product_fields_prompt() : uniquement les champs demandés et exploitables. */
function parse_product_fields_response(string $text, array $fields): array
{
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text));
    $data = json_decode($text, true);
    if (!is_array($data)) return [];
    $str = static fn ($v): string => trim((string) (is_array($v) ? implode(', ', $v) : $v));
    $out = [];
    foreach ($fields as $field) {
        $value = $str($data[$field] ?? '');
        switch ($field) {
            case 'name': $value = mb_substr($value, 0, 120); break;
            case 'description': $value = mb_substr($value, 0, 1200); break;
            case 'materials': $value = mb_substr($value, 0, 200); break;
            case 'etat': $value = product_condition_key($value); break;
            case 'size':
                $size = mb_substr($str($data['size_text'] ?? ''), 0, 60);
                if ($size !== '') $out['size_text'] = $size;
                continue 2;
            case 'weight':
                // Poids estimé en grammes (jamais au-delà de 300 kg) ; weight_text en est la version écrite pour la fiche.
                $grams = (int) preg_replace('/\D/', '', $str($data['weight_grams'] ?? ''));
                if ($grams > 0 && $grams <= 300000) { $out['weight_grams'] = $grams; $out['weight_text'] = product_weight_text($grams); }
                continue 2;
            case 'nature':
                // Deux clés d'un coup : la nature, et sa sous-catégorie (gardée seulement si elle lui appartient).
                $pair = product_nature_resolve($value, $str($data['sous_categorie'] ?? ''));
                if ($pair['nature'] !== '') $out['nature'] = $pair['nature'];
                if ($pair['sous_categorie'] !== '') $out['sous_categorie'] = $pair['sous_categorie'];
                continue 2;
            case 'category':
                // Le modèle répond parfois par le libellé (« Épicerie fine ») plutôt que par la clé : les deux sont acceptés.
                $wanted = mb_strtolower($value);
                $value = '';
                foreach (category_list() as $c) {
                    if ($wanted === mb_strtolower($c['key']) || $wanted === mb_strtolower($c['label'])) { $value = $c['key']; break; }
                }
                break;
            case 'price':
                // « 30 € », « 25-35 € » : on garde ce qui ressemble à un prix, sans phrase autour.
                $value = preg_match('/\d[\d\s.,]*(?:\s*[-–]\s*\d[\d\s.,]*)?\s*€?/u', $value, $m) ? trim($m[0]) : '';
                if ($value !== '' && !str_contains($value, '€')) $value .= ' €';
                $value = mb_substr($value, 0, 30);
                break;
        }
        if ($value !== '') $out[$field] = $value;
    }
    return $out;
}

/**
 * Réduit une image sur disque vers un fichier temporaire avant un appel
 * Gemini synchrone : une grosse photo (plusieurs Mo, résolution téléphone)
 * fait facilement dépasser 30s de réponse, ce qui plante la requête PHP —
 * un hébergement mutualisé désactivant exec()/shell_exec() (voir
 * shell_exec_available()) interdit aussi de déporter l'appel en tâche de
 * fond. Envoyer une version réduite suffit largement pour décrire le
 * contenu et répond en quelques secondes quelle que soit la photo d'origine.
 * Retourne null si le fichier n'est pas une image lisible par GD (l'appelant
 * doit alors retomber sur le chemin d'origine ou abandonner).
 */
function downscale_for_ai(string $srcAbsPath, int $maxDim = 1024, int $quality = 80): ?string
{
    $info = @getimagesize($srcAbsPath);
    if (!$info) return null;

    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($srcAbsPath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($srcAbsPath); break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($srcAbsPath); break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($srcAbsPath); break;
        default: return null;
    }
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxDim / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $tmpPath = sys_get_temp_dir() . '/ai-downscale-' . bin2hex(random_bytes(6)) . '.jpg';
    imagejpeg($dst, $tmpPath, $quality);
    return $tmpPath;
}

/**
 * Preset angle directions offered in the admin UI, mapped to the phrase
 * inserted into the Gemini prompt. Keep in sync with gallery.php's <select>.
 */
function angle_presets(): array
{
    return [
        'auto' => "from a different angle than the original",
        'dessus' => "from directly above, a top-down view",
        'dessous' => "from below, looking upward",
        'trois-quarts' => "from a three-quarter angle",
        'face' => "straight-on, facing the object",
        'profil' => "from the side, a profile view",
        'arriere' => "from behind the object",
    ];
}

/**
 * Nom d'une photo générée d'après la consigne du vendeur : « Ambiance — portée par une femme en mouvement ».
 * La consigne est raccourcie à un mot entier ; sans consigne, le nom de base seul.
 */
function generated_photo_label(string $baseLabel, string $keywords, int $maxKeywords = 50): string
{
    $kw = trim(preg_replace('/\s+/u', ' ', $keywords), " \t,;.:-");
    if ($kw === '') return $baseLabel;
    if (mb_strlen($kw) > $maxKeywords) {
        $cut = mb_substr($kw, 0, $maxKeywords);
        $space = mb_strrpos($cut, ' ');
        $kw = rtrim($space !== false && $space > 15 ? mb_substr($cut, 0, $space) : $cut, " ,;.:-") . '…';
    }
    return $baseLabel . ' — ' . $kw;
}

/** Radical du nom de fichier d'une photo générée : « product-001-ambiance » + la consigne en minuscules sans accents. */
function generated_photo_basename(string $prefix, string $keywords): string
{
    if (trim($keywords) === '') return $prefix; // slugify('') rendrait « media »
    $slug = trim(slugify(mb_substr($keywords, 0, 60), 40), '-');
    // Coupé à un mot entier, sans fragment au bout.
    if (mb_strlen($slug) >= 40 && ($cut = strrpos($slug, '-')) !== false && $cut > 15) $slug = substr($slug, 0, $cut);
    return $slug !== '' ? $prefix . '-' . $slug : $prefix;
}

/**
 * Consigne du vendeur (« Mots-clés / précisions »), ajoutée à un prompt de génération : elle PRIME sur les
 * réglages par défaut du prompt (décor, absence de personnes…) quand ils se contredisent — sans quoi le
 * modèle garde son décor habituel et ignore, par exemple, « femme dans la rue en mouvement ».
 */
function owner_direction_prompt(string $keywords): string
{
    return $keywords === '' ? '' : " OWNER'S DIRECTION (mandatory, it takes priority over any default above that contradicts it): « "
        . $keywords . " ». Follow it faithfully — setting, light, framing (close-up, wide shot, angle), action, and any person it mentions — while keeping the object itself identical.";
}

function build_angle_prompt(string $anglePreset, string $keywords): string
{
    $angleText = angle_presets()[$anglePreset] ?? angle_presets()['auto'];
    return "This is a real secondhand/vintage product photo for an online antiques shop. "
        . "Generate a photo of the SAME exact object(s), photographed $angleText, "
        . "on a clean simple neutral light-grey studio background, soft natural lighting, "
        . "photorealistic, no text, no watermark, no people. "
        . "Respond with the generated image only, no text in your reply."
        . owner_direction_prompt($keywords);
}

function build_ambiance_prompt(string $keywords): string
{
    if ($keywords === '') {
        return "This is a real secondhand/vintage product photo for an online antiques shop. "
            . "Generate a styled photo showing the SAME exact object placed naturally in a cozy "
            . "French home interior (a living room or kitchen with warm wood tones), as if staged "
            . "for a lifestyle product photo, photorealistic, natural daylight, no text, no watermark, no people. "
            . "Respond with the generated image only, no text in your reply.";
    }
    // Avec une consigne : une scène, une action ou une personne qu'elle décrit remplace le décor par défaut ;
    // une simple précision (époque, défaut, matière…) laisse le décor par défaut en place.
    return "This is a real secondhand/vintage product photo for an online antiques shop. "
        . "Generate a styled, photorealistic lifestyle photo showing the SAME exact object (identical shape, colors, "
        . "materials, patterns and details — never redesign it), as if staged for a lifestyle product photo, natural light, "
        . "no text, no watermark. Default setting, to use ONLY if the owner's direction below does not describe a setting, "
        . "an action or a person: a cozy French home interior (living room or kitchen with warm wood tones), no people. "
        . "If the direction does describe a scene, an action or a person, that scene replaces the default — for a garment or "
        . "accessory the item may then be worn or carried, wearing exactly this item."
        . owner_direction_prompt($keywords)
        . " Respond with the generated image only, no text in your reply.";
}

/**
 * Prompt used to sharpen/upscale an admin-cropped detail shot without altering
 * the object itself — distinct from build_angle_prompt/build_ambiance_prompt,
 * which fabricate an unseen view and are flagged is_illustration=1. A detail
 * crop stays a real photo (is_illustration=0) even after this enhancement.
 */
function build_detail_enhance_prompt(): string
{
    return "This is a real close-up detail crop from a genuine secondhand/vintage product photo "
        . "for an online antiques shop. Enhance this exact image: increase sharpness and clarity, "
        . "improve fine detail and focus, reduce blur/noise/compression artefacts, correct exposure "
        . "if needed — without changing the object's shape, materials, colors, proportions or "
        . "composition in any way. This must remain a faithful, accurate representation of the same "
        . "real photo, just clearer and higher quality. Photorealistic, no text, no watermark. "
        . "Respond with the generated image only, no text in your reply.";
}

/**
 * Prompt used to reconstruct the part of an object cut off by the photo's
 * framing (a lamp's base out of frame, a chair leg cropped at the bottom...).
 * The added portion is invented, never photographed — always saved as an
 * illustration (is_illustration=1), same honesty rule as angle/ambiance.
 */
function build_complete_prompt(string $keywords): string
{
    $prompt = "This is a real secondhand/vintage product photo for an online antiques shop, but the "
        . "object is cropped/cut off by the edge of the frame — part of it is missing from the image. "
        . "Generate the SAME exact object shown in full, extending/completing the parts that are cut "
        . "off in a way that is consistent with its visible style, materials, proportions and "
        . "construction, on a clean simple neutral light-grey studio background, soft natural lighting, "
        . "photorealistic, no text, no watermark, no people. "
        . "Respond with the generated image only, no text in your reply.";
    return $prompt . owner_direction_prompt($keywords);
}

/**
 * Repli quand le vrai détourage (rembg, fond réellement transparent) est
 * indisponible (exec() désactivé — hébergement mutualisé) : Gemini ne
 * renvoie jamais de canal alpha, seulement une image pleine — donc ceci
 * produit un fond blanc uni, pas une vraie transparence. Toujours étiqueté
 * différemment ("Détourée (fond blanc, approx. IA)") pour que ce ne soit
 * jamais confondu avec un export PNG transparent.
 */
function build_detoure_fallback_prompt(): string
{
    return "This is a real secondhand/vintage product photo for an online antiques shop. "
        . "Remove the background entirely and replace it with a clean, plain, pure white "
        . "background — keep the object exactly as it is (same shape, materials, colors, "
        . "proportions, angle, position in frame), no shadow, no reflection, no added props, "
        . "no text, no watermark. Photorealistic. "
        . "Respond with the generated image only, no text in your reply.";
}

/**
 * Détourage synchrone pour l'import par lot (voir admin/batch-import.php) —
 * contrairement au flux galerie classique (admin/gallery-action.php), ne
 * tente jamais le rembg local via exec()/nohup : un import par lot doit
 * rester déterministe et enchaîner immédiatement sur la mise en situation,
 * ce qu'une génération asynchrone ne permet pas. Retourne fal.ai (vrai fond
 * transparent) si configuré, sinon le repli Gemini fond blanc, sinon null si
 * aucun des deux n'est disponible.
 */
function detoure_photo_synchronous(string $srcAbsPath): ?array
{
    if (FAL_API_KEY) {
        $bytes = fal_remove_background($srcAbsPath);
        if ($bytes) {
            return ['bytes' => $bytes, 'ext' => 'png', 'label' => 'Détourée', 'illustration' => false];
        }
    }
    if (GEMINI_API_KEY) {
        $small = downscale_for_ai($srcAbsPath, 1280, 85) ?? $srcAbsPath;
        $bytes = gemini_generate_image($small, build_detoure_fallback_prompt(), 1);
        if ($small !== $srcAbsPath) @unlink($small);
        if ($bytes) {
            return ['bytes' => $bytes, 'ext' => 'jpg', 'label' => 'Détourée (fond blanc, approx. IA)', 'illustration' => true];
        }
    }
    return null;
}

/**
 * Vrai détourage (fond réellement transparent, canal alpha) via le modèle
 * rembg hébergé par fal.ai — le même modèle que celui utilisé en local via
 * Python, mais appelé en HTTP classique : fonctionne donc aussi sur un
 * hébergement mutualisé qui désactive exec()/shell_exec(). Retourne les
 * octets du PNG généré, ou null en cas d'échec.
 */
function fal_remove_background(string $srcAbsPath, int $retries = 1): ?string
{
    if (!FAL_API_KEY) return null;

    $imgData = @file_get_contents($srcAbsPath);
    if ($imgData === false) return null;
    $mime = @getimagesize($srcAbsPath)['mime'] ?? 'image/jpeg';
    $dataUri = 'data:' . $mime . ';base64,' . base64_encode($imgData);

    $payload = json_encode(['image_url' => $dataUri]);

    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $ch = curl_init('https://fal.run/fal-ai/imageutils/rembg');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Key ' . FAL_API_KEY, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body !== false && $status < 400) {
            $data = json_decode($body, true);
            $imageUrl = $data['image']['url'] ?? null;
            if ($imageUrl) {
                $resultCh = curl_init($imageUrl);
                curl_setopt_array($resultCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
                $resultBytes = curl_exec($resultCh);
                if ($resultBytes !== false) {
                    ai_usage_log_cutout();
                    return $resultBytes;
                }
                error_log('fal_remove_background attempt ' . $attempt . ': failed to download result image');
            } else {
                error_log('fal_remove_background attempt ' . $attempt . ': HTTP ' . $status . ' but no image in response');
            }
        } else {
            error_log('fal_remove_background attempt ' . $attempt . ': HTTP ' . $status . ', body: ' . substr((string) $body, 0, 500));
        }

        if ($attempt < $retries) sleep(2);
    }

    return null;
}

/**
 * Un hébergement mutualisé classique (OVH inclus) désactive souvent
 * exec()/shell_exec() par sécurité, et n'installe pas Python/ffmpeg. Toute
 * fonction qui shell-out (détourage, vidéo Ken Burns, dimensions vidéo) doit
 * vérifier ceci avant d'agir, pour échouer proprement au lieu de planter.
 */
function shell_exec_available(): bool
{
    if (!function_exists('exec') || !function_exists('shell_exec')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('exec', $disabled, true) && !in_array('shell_exec', $disabled, true);
}

/**
 * Runs the project's rembg script on one image via the local Python venv.
 * Returns the output PNG's raw bytes, or null on failure.
 */
function run_cutout(string $srcAbsPath): ?string
{
    if (!PYTHON_BIN || !shell_exec_available()) return null;

    $script = __DIR__ . '/../scripts/rembg_cutout.py';
    $tmpOut = sys_get_temp_dir() . '/cutout-' . bin2hex(random_bytes(6)) . '.png';

    $cmd = escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg($script) . ' '
        . escapeshellarg($srcAbsPath) . ' ' . escapeshellarg($tmpOut) . ' 2>&1';
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($tmpOut)) return null;
    $bytes = file_get_contents($tmpOut);
    @unlink($tmpOut);
    return $bytes ?: null;
}

/** Effets vidéo proposés dans l'admin — libellés FR ↔ clés utilisées ici et côté CLI. */
function video_effects(): array
{
    return [
        'zoom_in' => 'Zoom avant',
        'zoom_out' => 'Zoom arrière',
        'pan_left' => 'Travelling (droite → gauche)',
        'pan_right' => 'Travelling (gauche → droite)',
    ];
}

/**
 * Anime une photo existante en courte vidéo (effet Ken Burns via ffmpeg) —
 * zoom ou travelling, aucune IA/coût d'API : un simple mouvement de cadrage
 * déterministe sur une vraie photo. Retourne le chemin relatif sous uploads/,
 * ou null en cas d'échec.
 */
function run_ken_burns_video(string $srcAbsPath, string $effect): ?string
{
    if (!FFMPEG_BIN || !shell_exec_available()) return null;

    $fps = 25;
    $seconds = 3;
    $frames = $fps * $seconds;
    $size = 900;
    // zoompan calcule sa fenêtre de recadrage en pixels de l'image mise à
    // l'échelle juste avant lui — à la résolution de sortie (900), un zoom
    // lent ne déplace cette fenêtre que de quelques pixels par image, et
    // l'arrondi au pixel entier donne un tremblement visible. Une résolution
    // de travail bien plus grande (2160) donne assez de marge pour un
    // mouvement lisse, puis zoompan réduit lui-même à la taille de sortie.
    $workSize = 2160;
    $lastFrame = $frames - 1;

    switch ($effect) {
        case 'zoom_out':
            $z = "max(1.35-0.0016*on,1.02)";
            $x = 'iw/2-(iw/zoom/2)'; $y = 'ih/2-(ih/zoom/2)';
            break;
        case 'pan_left':
            $z = '1.15';
            $x = "(iw-iw/zoom)*(1-on/$lastFrame)"; $y = 'ih/2-(ih/zoom/2)';
            break;
        case 'pan_right':
            $z = '1.15';
            $x = "(iw-iw/zoom)*(on/$lastFrame)"; $y = 'ih/2-(ih/zoom/2)';
            break;
        case 'zoom_in':
        default:
            $z = "min(1.02+0.0016*on,1.35)";
            $x = 'iw/2-(iw/zoom/2)'; $y = 'ih/2-(ih/zoom/2)';
            break;
    }

    $uploadsDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
    $filename = 'video-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.mp4';
    $outPath = $uploadsDir . '/' . $filename;

    // in_range/out_range force une conversion correcte du plein spectre des
    // JPEG (pc) vers le spectre limité standard vidéo (tv) — sans ça, ffmpeg
    // encode en yuvj420p (plein spectre) qui plante ou coupe la lecture sur
    // certains décodeurs (Chrome/Safari selon les cas), silencieusement.
    $vf = "scale=$workSize:$workSize:force_original_aspect_ratio=increase:in_range=pc:out_range=tv,crop=$workSize:$workSize,"
        . "zoompan=z='$z':x='$x':y='$y':d=$frames:s={$size}x{$size}:fps=$fps,format=yuv420p";

    $cmd = escapeshellarg(FFMPEG_BIN) . ' -y -loop 1 -i ' . escapeshellarg($srcAbsPath)
        . ' -vf ' . escapeshellarg($vf) . ' -color_range tv -t ' . $seconds . ' -movflags +faststart '
        . escapeshellarg($outPath) . ' 2>&1';
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($outPath)) return null;
    return 'uploads/' . $filename;
}

/**
 * Looks at the most recent background generation log for this product (if any,
 * within the last 10 minutes) so the admin gallery page can show whether a
 * generation launched earlier is still running, finished, or failed — since
 * the launching request itself only ever says "started" and returns immediately.
 */
function recent_generation_status(string $ref): ?array
{
    $safeRef = preg_replace('/[^a-zA-Z0-9_-]/', '', $ref);
    $files = glob(__DIR__ . '/../var/log/generate-' . $safeRef . '-*.log');
    if (!$files) return null;

    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $latest = $files[0];
    $age = time() - filemtime($latest);
    if ($age > 600) return null;

    $kindLabels = ['detoure' => 'Le détourage', 'angle' => 'La vue sous un autre angle', 'ambiance' => 'La photo d\'ambiance', 'detail_enhance' => "L'amélioration du détail", 'complete' => "Le complément de l'objet"];
    $kind = 'generic';
    foreach (array_keys($kindLabels) as $k) {
        if (str_contains(basename($latest), "-$k-")) { $kind = $k; break; }
    }
    $label = $kindLabels[$kind] ?? 'La génération';
    $content = (string) file_get_contents($latest);
    $maxWait = $kind === 'detoure' ? 150 : 60;

    if (str_contains($content, 'échouée') || str_contains($content, 'échoué')) {
        return ['status' => 'failed', 'message' => "$label a échoué — l'API a répondu sans image après plusieurs tentatives. Relancez simplement la génération."];
    }
    if (preg_match('/OK -> /', $content)) {
        return ['status' => 'done', 'message' => "$label est terminée."];
    }
    if ($age > $maxWait) {
        return ['status' => 'failed', 'message' => "$label ne semble pas avoir abouti (aucune réponse après " . $age . "s). Relancez la génération."];
    }
    return ['status' => 'pending', 'message' => "$label est toujours en cours (~" . $age . "s) — actualisez la page dans quelques instants."];
}

/** Next sort_order value for a product's gallery (appends to the end). */
function next_photo_sort_order(string $ref): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_photos WHERE product_ref = ?');
    $stmt->execute([$ref]);
    return (int) $stmt->fetchColumn();
}

function add_product_photo(string $ref, string $path, string $label, bool $illustration = false, string $type = 'photo', ?string $pathMobile = null): int
{
    $stmt = db()->prepare('INSERT INTO product_photos (product_ref, path, path_mobile, label, is_illustration, type, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$ref, $path, $pathMobile, $label, $illustration ? 1 : 0, $type, next_photo_sort_order($ref)]);
    $id = (int) db()->lastInsertId();
    sync_cover_photo($ref);
    return $id;
}

/**
 * Médiathèque : dépôt central de ressources (photos, vidéos) indépendant des
 * fiches produit — sert notamment à conserver les photos d'une pièce (ex :
 * une photo d'ambiance réussie) après suppression de sa fiche.
 */
function get_media_items(?string $type = null, ?string $q = null): array
{
    $where = [];
    $params = [];
    if ($type) { $where[] = 'type = ?'; $params[] = $type; }
    if ($q) {
        $where[] = '(label LIKE ? OR source LIKE ? OR tags LIKE ? OR origin_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT * FROM media_library' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC, id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Vue d'ensemble de la médiathèque : les ressources propres (media_library) ET les photos des fiches produit
 * (product_photos, plus la photo de couverture des fiches qui n'en ont pas dans la galerie), lues en place — rien
 * n'est dupliqué. Chaque élément : kind (« media » : modifiable ici ; « fiche » : se gère dans la galerie du produit),
 * type, path, label, source, tags, origin_ref, origin_name, created_at, live (la fiche existe encore), badges.
 * $product : '' (tous), '-' (sans produit) ou la référence d'un produit.
 */
function get_media_overview(?string $type = null, ?string $q = null, string $product = ''): array
{
    $items = [];
    foreach (get_media_items($type, $q) as $m) {
        $m['kind'] = 'media';
        $m['live'] = $m['origin_ref'] && get_product($m['origin_ref']);
        $m['badges'] = [];
        $items[] = $m;
    }

    $products = [];
    foreach (db()->query('SELECT ref, name, photo, is_hidden FROM products')->fetchAll() as $p) $products[$p['ref']] = $p;
    $fiche = static function (array $row, string $path, string $label, array $badges, string $createdAt) use ($products): array {
        $p = $products[$row['product_ref'] ?? $row['ref']];
        return [
            'kind' => 'fiche', 'id' => (int) ($row['id'] ?? 0), 'type' => $row['type'] ?? 'photo', 'path' => $path, 'label' => $label,
            'source' => 'Fiche produit', 'tags' => '', 'origin_ref' => $p['ref'], 'origin_name' => $p['name'], 'created_at' => $createdAt,
            'live' => true, 'badges' => array_values(array_filter(array_merge($badges, [$p['is_hidden'] ? 'Fiche masquée' : '']))),
        ];
    };
    $known = [];
    foreach (db()->query('SELECT * FROM product_photos ORDER BY id')->fetchAll() as $row) {
        if (!isset($products[$row['product_ref']])) continue;
        $known[$row['path']] = true;
        $badges = [];
        if (str_starts_with((string) $row['label'], 'Détourée')) $badges[] = 'Détourage';
        elseif ($row['is_illustration']) $badges[] = 'Visuel IA';
        if ($row['is_hidden']) $badges[] = 'Photo masquée';
        $items[] = $fiche($row, $row['path'], (string) $row['label'], $badges, (string) $row['created_at']);
        if (!empty($row['path_mobile'])) {
            $known[$row['path_mobile']] = true;
            $items[] = $fiche($row, $row['path_mobile'], $row['label'] . ' (9:16 smartphone)', array_merge($badges, ['9:16']), (string) $row['created_at']);
        }
    }
    foreach ($products as $p) {
        // Photo de couverture d'une fiche créée à la main : elle n'a pas de ligne dans la galerie.
        if ($p['photo'] && !isset($known[$p['photo']])) {
            $items[] = $fiche($p, $p['photo'], 'Photo de la fiche', [], date('Y-m-d H:i:s', @filemtime(__DIR__ . '/../' . $p['photo']) ?: time()));
        }
    }

    return array_values(array_filter($items, static function (array $m) use ($type, $q, $product): bool {
        if ($m['kind'] === 'fiche') {
            if ($type && $m['type'] !== $type) return false;
            if ($q) {
                $hay = mb_strtolower($m['label'] . ' ' . $m['source'] . ' ' . $m['origin_name'] . ' ' . $m['origin_ref'] . ' ' . implode(' ', $m['badges']));
                if (!str_contains($hay, mb_strtolower($q))) return false;
            }
        }
        if ($product === '-') return !$m['origin_ref'] && !$m['origin_name'];
        return $product === '' || $m['origin_ref'] === $product;
    }));
}

/**
 * Regroupe les éléments de get_media_overview() : par produit (une rubrique par fiche, puis « Anciens articles » et
 * « Sans produit »), par source, par type, ou par mois. Rend [[titre, lien galerie|null, éléments], …].
 */
function group_media_overview(array $items, string $by): array
{
    $dateOf = static fn (array $m): int => strtotime((string) $m['created_at']) ?: 0;
    usort($items, static fn ($a, $b) => $dateOf($b) <=> $dateOf($a));
    $groups = [];
    foreach ($items as $m) {
        switch ($by) {
            case 'produit':
                if ($m['live'] && $m['origin_ref']) { $key = 'p:' . $m['origin_ref']; $title = 'Réf. N°' . $m['origin_ref'] . ' — ' . $m['origin_name']; $link = $m['origin_ref']; $order = 0; }
                elseif ($m['origin_name']) { $key = 'x:' . $m['origin_name']; $title = 'Ancien article : ' . $m['origin_name']; $link = null; $order = 1; }
                else { $key = 'z'; $title = 'Sans produit'; $link = null; $order = 2; }
                break;
            case 'source':
                $src = trim((string) $m['source']) ?: 'Sans source'; $key = 's:' . $src; $title = $src; $link = null; $order = $m['kind'] === 'fiche' ? 0 : 1;
                break;
            case 'type':
                $key = 't:' . $m['type']; $title = $m['type'] === 'video' ? 'Vidéos' : 'Photos'; $link = null; $order = $m['type'] === 'video' ? 1 : 0;
                break;
            default:
                $month = $dateOf($m) ? strftime_fr($dateOf($m)) : 'Sans date'; $key = 'd:' . $month; $title = $month; $link = null; $order = 0;
        }
        $groups[$key] ??= ['title' => $title, 'link' => $link, 'items' => [], 'order' => $order];
        $groups[$key]['items'][] = $m;
    }
    if ($by === 'produit' || $by === 'source' || $by === 'type') {
        uasort($groups, static fn ($a, $b) => $a['order'] <=> $b['order'] ?: 0);
    }
    return array_values(array_map(static fn ($g) => [$g['title'], $g['link'], $g['items']], $groups));
}

/** « octobre 2026 » pour un horodatage (sans dépendre des locales du serveur). */
function strftime_fr(int $timestamp): string
{
    static $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $months[(int) date('n', $timestamp) - 1] . ' ' . date('Y', $timestamp);
}

function get_media_item(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM media_library WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function add_media_item(string $type, string $path, string $label, string $source = '', string $tags = '', ?string $originRef = null, ?string $originName = null): int
{
    $stmt = db()->prepare('INSERT INTO media_library (type, path, label, source, tags, origin_ref, origin_name) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$type, $path, $label, $source, $tags, $originRef, $originName]);
    return (int) db()->lastInsertId();
}

/**
 * Copie les photos de la fiche $ref vers la médiathèque avant sa suppression
 * (le fichier reste sur disque, seule la ligne product_photos disparaît via le
 * ON DELETE CASCADE — cet appel garantit qu'on garde une trace exploitable).
 * À appeler AVANT le DELETE FROM products.
 */
function move_product_photos_to_media(string $ref): int
{
    $product = get_product($ref);
    if (!$product) return 0;
    $moved = 0;
    foreach (product_photos_list($ref) as $p) {
        $source = $p['is_illustration'] ? 'Vue générée par IA — article supprimé' : 'Photo article — article supprimé';
        add_media_item('photo', $p['path'], $p['label'], $source, '', $ref, $product['name']);
        $moved++;
    }
    return $moved;
}

/**
 * Upload manuel dans la médiathèque : image (recompressée via GD, comme
 * store_uploaded_photo) ou vidéo (copiée telle quelle, GD ne sait pas la lire).
 * Retourne ['path' => ..., 'type' => 'photo'|'video'] ou null.
 */
function store_uploaded_media(string $fieldName, string $baseName): ?array
{
    if (empty($_FILES[$fieldName]['tmp_name']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = $_FILES[$fieldName]['tmp_name'];
    $origName = (string) ($_FILES[$fieldName]['name'] ?? '');

    if (@getimagesize($tmp)) {
        $path = store_uploaded_photo($fieldName, $baseName, 1400, 85);
        return $path ? ['path' => $path, 'type' => 'photo'] : null;
    }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'mov', 'webm', 'm4v'], true)) return null;

    $uploadsDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
    $filename = $baseName . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    if (!move_uploaded_file($tmp, $uploadsDir . '/' . $filename)) return null;

    return ['path' => 'uploads/' . $filename, 'type' => 'video'];
}

/** $link : bouton d'action ajouté au message, ['label' => 'Commander', 'url' => '/cart.php'] (affiché par le haut de page du site). */
function flash_set(string $message, string $kind = 'ok', ?array $link = null): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind, 'link' => $link];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Comptes admin acceptés, sous forme [identifiant, mot de passe] :
 *  - celui du commerce (tenant.php → admin_user / admin_password) ;
 *  - celui de la configuration (ADMIN_USER / ADMIN_PASSWORD : variables
 *    d'environnement, ou config.local.php en local, valable alors pour tous
 *    les commerces ; identifiant « admin » s'il n'est pas précisé).
 */
function admin_accounts(): array
{
    $configUser = defined('ADMIN_USER') ? ADMIN_USER : (getenv('ADMIN_USER') ?: 'admin');
    return [
        [(string) tenant('admin_user'), (string) tenant('admin_password')],
        [(string) $configUser, ADMIN_PASSWORD],
    ];
}

/**
 * Vérifie un couple identifiant / mot de passe. Le mot de passe peut être
 * stocké en clair ou haché (password_hash, comme le fait le portail local).
 */
function admin_login_matches(string $user, string $password): bool
{
    $user = mb_strtolower(trim($user));
    foreach (admin_accounts() as [$expectedUser, $expected]) {
        if ($expectedUser === '' || $expected === '' || !hash_equals(mb_strtolower($expectedUser), $user)) {
            continue;
        }
        $ok = password_get_info($expected)['algo'] !== null
            ? password_verify($password, $expected)
            : hash_equals($expected, $password);
        if ($ok) {
            return true;
        }
    }
    return false;
}

function admin_password_configured(): bool
{
    foreach (admin_accounts() as [$user, $password]) {
        if ($user !== '' && $password !== '') {
            return true;
        }
    }
    try {
        return (bool) db()->query("SELECT 1 FROM accounts WHERE role IN ('admin', 'community_manager') AND is_active = 1 AND password_hash IS NOT NULL")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Connecté à l'administration de ce commerce ? (une connexion ne vaut que pour un commerce) */
/**
 * Compte connecté à l'administration de ce commerce : ['role' => …, 'id' => id du
 * compte ou null pour le compte principal, 'name' => …], ou null. Pour un compte
 * de l'équipe, le rôle est relu en base à chaque requête : une désactivation ou un
 * changement de rôle prend effet immédiatement, sans attendre la déconnexion.
 */
function admin_session(): ?array
{
    if (($_SESSION['is_admin'] ?? null) !== tenant_slug()) {
        return null;
    }
    $id = $_SESSION['admin_account_id'] ?? null;
    if ($id === null) {
        return ['role' => 'admin', 'id' => null, 'name' => ''];
    }
    static $checked = [];
    if (!array_key_exists($id, $checked)) {
        $account = account_find((int) $id);
        $checked[$id] = $account && (int) $account['is_active'] === 1 && account_is_staff($account['role'])
            ? ['role' => $account['role'], 'id' => (int) $account['id'], 'name' => $account['name'] !== '' ? $account['name'] : $account['email']]
            : null;
    }
    return $checked[$id];
}

function is_admin_logged_in(): bool
{
    return admin_session() !== null;
}

function admin_role(): string
{
    return admin_session()['role'] ?? '';
}

/** Connecté ET autorisé à ouvrir la page courante (pour les réponses JSON qui n'appellent pas require_admin()). */
function admin_access_ok(): bool
{
    $session = admin_session();
    return $session !== null && admin_role_can_access($session['role'], $_SERVER['SCRIPT_NAME'] ?? '');
}

function require_admin(): void
{
    $session = admin_session();
    if ($session === null) {
        header('Location: /admin/login.php');
        exit;
    }
    if (!admin_role_can_access($session['role'], $_SERVER['SCRIPT_NAME'] ?? '')) {
        admin_forbidden($session['role']);
    }
}

/** Page d'accès refusé (compte connecté, mais rôle sans droit sur cette page). */
function admin_forbidden(string $role): never
{
    http_response_code(403);
    $content = get_content();
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Accès refusé — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main><section class="tight"><div class="wrap" style="max-width:560px;">
  <p class="eyebrow">Administration</p>
  <h1 style="font-size:1.6rem;margin:12px 0;">Accès refusé</h1>
  <p class="lede">Votre compte (<?= h(account_role_label($role)) ?>) n'a pas accès à cette page. Elle est réservée aux administrateurs.</p>
  <p style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
    <a class="btn btn-primary" href="<?= h(admin_home_for_role($role)) ?>">Retour à l'administration</a>
    <a class="btn" href="/admin/logout.php">Se déconnecter</a>
  </p>
</div></section></main>
</body>
</html>
<?php
    exit;
}

/**
 * Identifie un compte à partir de l'identifiant et du mot de passe saisis :
 * d'abord le compte principal du commerce (tenant.php / configuration), puis
 * les comptes de l'équipe en base. Retourne ['role' => …, 'id' => …] ou null.
 */
function admin_login(string $user, string $password): ?array
{
    if (admin_login_matches($user, $password)) {
        return ['role' => 'admin', 'id' => null];
    }
    $account = account_staff_by_login($user);
    // Vérification factice quand le compte n'existe pas : même durée de réponse, pas d'indice sur les identifiants valides.
    $hash = $account['password_hash'] ?? '$2y$12$z1UVcc1AoYyQPd3h9tNn8.gcPzUCtAceZH7qkzjYKYhBfeDzC4Y.S';
    if (password_verify($password, $hash) && $account) {
        account_touch_login((int) $account['id']);
        return ['role' => $account['role'], 'id' => (int) $account['id']];
    }
    return null;
}

/* ---------- prix & panier ---------- */

/**
 * Un prix est "fixe" (achetable directement) s'il ne contient qu'un seul
 * montant et pas d'indication de fourchette ("dès", "à partir de"...).
 */
function is_fixed_price(?string $price): bool
{
    if (!$price) return false;
    if (preg_match('/d[eè]s|à partir|environ|sur devis/iu', $price)) return false;
    return (bool) preg_match('/\d/', $price);
}

function price_to_cents(?string $price): ?int
{
    if (!$price) return null;
    if (!preg_match('/(\d[\d\s]*)(?:[,.](\d{1,2}))?/', $price, $m)) return null;
    $euros = (int) str_replace(' ', '', $m[1]);
    $cents = isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0;
    return $euros * 100 + $cents;
}

function format_cents(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function cart_get(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_add(string $ref, int $qty = 1): void
{
    $cart = cart_get();
    $cart[$ref] = ($cart[$ref] ?? 0) + $qty;
    $_SESSION['cart'] = $cart;
}

function cart_set_qty(string $ref, int $qty): void
{
    $cart = cart_get();
    if ($qty <= 0) {
        unset($cart[$ref]);
    } else {
        $cart[$ref] = $qty;
    }
    $_SESSION['cart'] = $cart;
}

function cart_remove(string $ref): void
{
    $cart = cart_get();
    unset($cart[$ref]);
    $_SESSION['cart'] = $cart;
}

function cart_clear(): void
{
    $_SESSION['cart'] = [];
}

function cart_count(): int
{
    return array_sum(cart_get());
}

/**
 * Lignes du panier jointes aux produits en base. Retire silencieusement les
 * références qui n'existent plus.
 */
function cart_lines(): array
{
    $cart = cart_get();
    if (!$cart) return [];
    $lines = [];
    foreach ($cart as $ref => $qty) {
        $p = get_product((string) $ref);
        // Retire silencieusement une pièce vendue entre-temps (stock épuisé
        // pendant qu'elle restait dans le panier d'un autre visiteur).
        if (!$p || !in_stock($p)) continue;
        $unitCents = price_to_cents(effective_price($p)) ?? 0;
        $lines[] = [
            'product' => $p,
            'qty' => $qty,
            'unit_cents' => $unitCents,
            'total_cents' => $unitCents * $qty,
        ];
    }
    return $lines;
}

function cart_total_cents(): int
{
    return array_sum(array_column(cart_lines(), 'total_cents'));
}

/* ---------- Transporteurs & tarifs de livraison ---------- */

function shipping_carriers_list(bool $activeOnly = false): array
{
    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    return db()->query("SELECT * FROM shipping_carriers $where ORDER BY sort_order, id")->fetchAll();
}

function next_carrier_sort_order(): int
{
    return (int) db()->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM shipping_carriers')->fetchColumn();
}

function add_shipping_carrier(string $name): int
{
    $stmt = db()->prepare('INSERT INTO shipping_carriers (name, sort_order) VALUES (?, ?)');
    $stmt->execute([$name, next_carrier_sort_order()]);
    return (int) db()->lastInsertId();
}

function shipping_rates_for_carrier(int $carrierId): array
{
    $stmt = db()->prepare('SELECT * FROM shipping_rates WHERE carrier_id = ? ORDER BY weight_min_g, id');
    $stmt->execute([$carrierId]);
    return $stmt->fetchAll();
}

function next_rate_sort_order(int $carrierId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM shipping_rates WHERE carrier_id = ?');
    $stmt->execute([$carrierId]);
    return (int) $stmt->fetchColumn();
}

function add_shipping_rate(int $carrierId, string $serviceName, int $weightMinG, int $weightMaxG, int $priceCents, string $deliveryDelay = '', string $zone = 'metropole'): int
{
    $stmt = db()->prepare('INSERT INTO shipping_rates (carrier_id, service_name, weight_min_g, weight_max_g, price_cents, delivery_delay, zone, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$carrierId, $serviceName, $weightMinG, $weightMaxG, $priceCents, $deliveryDelay, $zone, next_rate_sort_order($carrierId)]);
    return (int) db()->lastInsertId();
}

/**
 * Zones tarifaires — la Corse et l'outre-mer coûtent nettement plus cher à
 * livrer que la France métropolitaine chez la plupart des transporteurs,
 * d'où des paliers de tarif séparés plutôt qu'un tarif unique national.
 */
function shipping_zones(): array
{
    return [
        'metropole' => 'France métropolitaine',
        'corse' => 'Corse',
        'dom_tom' => 'Outre-mer (DOM-TOM)',
        'intl_a' => "International — zone A (UE, Suisse, Royaume-Uni)",
        'intl_b' => "International — zone B (Europe de l'Est)",
        'intl_c' => 'International — zone C (reste du monde)',
    ];
}

/**
 * Pays livrables hors de France, avec leur zone tarifaire — calquée sur les
 * zones Colissimo International (A/B/C). La Corse reste administrativement
 * en France (code pays FR, code postal en 20xxx, voir shipping_zone_for_address()) ;
 * les territoires ultramarins ont chacun leur propre code pays ISO côté
 * Stripe, distinct de FR. Liste volontairement resserrée aux destinations
 * les plus courantes — à étendre au besoin.
 */
function shipping_countries(): array
{
    return [
        // Outre-mer (DOM-TOM)
        'GP' => ['Guadeloupe', 'dom_tom'],
        'MQ' => ['Martinique', 'dom_tom'],
        'GF' => ['Guyane', 'dom_tom'],
        'RE' => ['Réunion', 'dom_tom'],
        'YT' => ['Mayotte', 'dom_tom'],
        'PF' => ['Polynésie française', 'dom_tom'],
        'NC' => ['Nouvelle-Calédonie', 'dom_tom'],
        // International zone A : UE, Suisse, Royaume-Uni
        'BE' => ['Belgique', 'intl_a'],
        'DE' => ['Allemagne', 'intl_a'],
        'ES' => ['Espagne', 'intl_a'],
        'IT' => ['Italie', 'intl_a'],
        'NL' => ['Pays-Bas', 'intl_a'],
        'PT' => ['Portugal', 'intl_a'],
        'LU' => ['Luxembourg', 'intl_a'],
        'IE' => ['Irlande', 'intl_a'],
        'AT' => ['Autriche', 'intl_a'],
        'CH' => ['Suisse', 'intl_a'],
        'GB' => ['Royaume-Uni', 'intl_a'],
        // International zone B : Europe de l'Est
        'PL' => ['Pologne', 'intl_b'],
        'CZ' => ['Tchéquie', 'intl_b'],
        'HU' => ['Hongrie', 'intl_b'],
        'RO' => ['Roumanie', 'intl_b'],
        'BG' => ['Bulgarie', 'intl_b'],
        // International zone C : reste du monde
        'US' => ['États-Unis', 'intl_c'],
        'CA' => ['Canada', 'intl_c'],
        'MA' => ['Maroc', 'intl_c'],
        'AE' => ['Émirats arabes unis', 'intl_c'],
        'AU' => ['Australie', 'intl_c'],
        'JP' => ['Japon', 'intl_c'],
    ];
}

function shipping_allowed_countries(): array
{
    $out = ['FR' => 'France (métropole & Corse)'];
    foreach (shipping_countries() as $code => [$label, $zone]) {
        $out[$code] = $label;
    }
    return $out;
}

function shipping_zone_for_address(string $country, string $postalCode): string
{
    $country = strtoupper(trim($country)) ?: 'FR';
    if ($country === 'FR') {
        return preg_match('/^20/', trim($postalCode)) ? 'corse' : 'metropole';
    }
    return shipping_countries()[$country][1] ?? 'intl_c';
}

/** Poids total du panier en grammes, à partir des lignes déjà résolues par cart_lines(). */
function cart_total_weight_g(array $lines): int
{
    $total = 0;
    foreach ($lines as $line) {
        $total += (int) ($line['product']['weight_grams'] ?? 0) * $line['qty'];
    }
    return $total;
}

/**
 * Tous les tarifs (transporteurs actifs) dont le palier de poids couvre
 * $weightG, du moins cher au plus cher — pour les proposer tous en options
 * de livraison Stripe plutôt que d'en imposer un seul. Retourne un tableau
 * vide si aucun ne correspond (panier trop lourd pour tous les paliers
 * définis, ou aucun transporteur configuré) : l'appelant doit alors retomber
 * sur le tarif fixe de secours (content.shipping_label / shipping_fee).
 */
function matching_shipping_rates(int $weightG, string $zone = 'metropole'): array
{
    $stmt = db()->prepare(
        'SELECT r.*, c.name AS carrier_name FROM shipping_rates r
         JOIN shipping_carriers c ON c.id = r.carrier_id
         WHERE c.is_active = 1 AND r.zone = ? AND r.weight_min_g <= ? AND r.weight_max_g >= ?
         ORDER BY r.price_cents ASC'
    );
    $stmt->execute([$zone, $weightG, $weightG]);
    return $stmt->fetchAll();
}

/* ---------- Stripe ---------- */

/**
 * Appelle l'API Stripe (REST, sans SDK) en POST/GET simple.
 * $params peut contenir des tableaux imbriqués (line_items, etc.), encodés
 * comme Stripe l'attend (clé[index][champ]=valeur).
 */
function stripe_request(string $method, string $path, array $params = []): array
{
    $ch = curl_init('https://api.stripe.com/v1' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => STRIPE_SECRET_KEY . ':',
        CURLOPT_TIMEOUT => 20,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&');
    } elseif ($params) {
        $opts[CURLOPT_URL] .= '?' . http_build_query($params, '', '&');
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erreur réseau Stripe : ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true) ?? [];
    if ($status >= 400) {
        $msg = $data['error']['message'] ?? ('Erreur Stripe HTTP ' . $status);
        throw new RuntimeException($msg);
    }
    return $data;
}

function stripe_configured(): bool
{
    return str_starts_with(STRIPE_SECRET_KEY, 'sk_');
}

function fulfillment_label(string $status): string
{
    return ['a_preparer' => 'À préparer', 'expediee' => 'Expédiée', 'livree' => 'Livrée'][$status] ?? $status;
}
