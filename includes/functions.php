<?php

require_once __DIR__ . '/../config.php';

function h($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Catégories du commerce actif (tenants/<slug>/tenant.php → categories). */
function category_list(): array
{
    return tenant('categories');
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
    return array_map(fn($r) => [
        'id' => (int) $r['id'],
        'src' => $r['path'],
        'src_mobile' => $r['path_mobile'] ?? null,
        'label' => $r['label'],
        'illustration' => (bool) $r['is_illustration'],
        'type' => $r['type'] ?? 'photo',
    ], $rows);
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
          </form>';
    } else {
        $action = '<a class="card-cta" href="/index.php#contact">Nous contacter</a>';
    }

    $href = '/produit.php?ref=' . urlencode($p['ref']);
    $gallery = product_gallery($p);
    $preview = $gallery
        ? ' data-quick-gallery="' . h(json_encode(array_map(fn($g) => ['src' => $g['src'], 'type' => $g['type']], $gallery))) . '" data-quick-name="' . h($p['name']) . '"'
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

/**
 * Recadrage centré vers un ratio cible (largeur/hauteur), en conservant le
 * maximum de la photo d'origine plutôt qu'en l'étirant. Retourne les octets
 * JPEG du recadrage, ou null en cas d'échec.
 */
function center_crop_bytes(string $srcAbsPath, float $ratio, int $maxDim = 1400, int $quality = 85): ?string
{
    $info = @getimagesize($srcAbsPath);
    if (!$info) return null;
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($srcAbsPath),
        IMAGETYPE_PNG => @imagecreatefrompng($srcAbsPath),
        IMAGETYPE_WEBP => @imagecreatefromwebp($srcAbsPath),
        default => null,
    };
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);
    if (($w / $h) > $ratio) {
        $cropH = $h;
        $cropW = (int) round($h * $ratio);
    } else {
        $cropW = $w;
        $cropH = (int) round($w / $ratio);
    }
    $srcX = (int) round(($w - $cropW) / 2);
    $srcY = (int) round(($h - $cropH) / 2);

    $scale = min(1, $maxDim / max($cropW, $cropH));
    $outW = max(1, (int) round($cropW * $scale));
    $outH = max(1, (int) round($cropH * $scale));

    $dst = imagecreatetruecolor($outW, $outH);
    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $outW, $outH, $cropW, $cropH);
    ob_start();
    imagejpeg($dst, null, $quality);
    return ob_get_clean() ?: null;
}

/**
 * Génère les 3 formats standards (carré, horizontal 4:3, vertical 3:4) d'une
 * photo, centrés automatiquement, et les dépose dans la médiathèque — prêts à
 * réutiliser (réseaux sociaux, bannières...) sans surcharger le diaporama de
 * la fiche produit avec des quasi-doublons de la même photo.
 */
/**
 * Formats d'export proposés par pièce, chacun lié à un usage concret :
 * catalogue, fiche produit, diaporama en boutique, archive non recadrée,
 * et les deux formats standard des réseaux sociaux (dimensions réelles
 * Instagram/Facebook : post carré 1080×1080, story verticale 1080×1920).
 * ratio = null → pas de recadrage, juste un export à bonne résolution.
 */
function media_export_variants(): array
{
    return [
        'vignette_catalogue' => ['label' => 'Vignette catalogue', 'ratio' => 4 / 3, 'maxDim' => 480],
        'produit_horizontal' => ['label' => 'Fiche produit (horizontal)', 'ratio' => 4 / 3, 'maxDim' => 1600],
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
        $bytes = $v['ratio'] === null
            ? @file_get_contents($srcAbsPath)
            : center_crop_bytes($srcAbsPath, $v['ratio'], $v['maxDim']);
        if (!$bytes) continue;
        $path = save_binary_photo($bytes, 'export-' . $key, 'jpg', $v['maxDim'], 88);
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
            CURLOPT_TIMEOUT => 90,
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
            if ($b64) return base64_decode($b64);
            $reason = $data['candidates'][0]['finishReason'] ?? $data['promptFeedback']['blockReason'] ?? 'no image in response';
            error_log("gemini_generate_image attempt $attempt: HTTP $status but no image ($reason)");
        } else {
            error_log("gemini_generate_image attempt $attempt: HTTP $status, body: " . substr((string) $body, 0, 500));
        }

        if ($attempt < $retries) sleep(4 + $attempt * 4);
    }

    return null;
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
/** Formats des visuels générés : ordinateur (3:2) et smartphone (9:16). */
const GENERATED_IMAGE_FORMATS = ['desktop' => '3:2', 'mobile' => '9:16'];

/**
 * Génère un visuel dans les deux formats : ['desktop' => chemin, 'mobile' => chemin|null],
 * ou null si la version ordinateur a échoué (la version smartphone est facultative :
 * sans elle, la version ordinateur s'affiche en entier sur mobile).
 */
function generate_image_pair(string $srcAbsPath, string $prompt, string $baseName, int $retries = 1): ?array
{
    $desktop = gemini_generate_image($srcAbsPath, $prompt, $retries, GENERATED_IMAGE_FORMATS['desktop']);
    if (!$desktop) return null;
    $desktopPath = save_binary_photo($desktop, $baseName, 'jpg', 1800);
    if (!$desktopPath) return null;
    $mobile = gemini_generate_image($srcAbsPath, $prompt, $retries, GENERATED_IMAGE_FORMATS['mobile']);
    $mobilePath = $mobile ? save_binary_photo($mobile, $baseName . '-mobile', 'jpg', 1800) : null;
    return ['desktop' => $desktopPath, 'mobile' => $mobilePath];
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

function gemini_describe_image(string $srcAbsPath, string $prompt, int $retries = 1, array $extraAbsPaths = []): ?string
{
    if (!GEMINI_API_KEY) return null;

    $parts = [['text' => $prompt]];
    foreach (array_merge([$srcAbsPath], $extraAbsPaths) as $i => $path) {
        $imgData = @file_get_contents($path);
        if ($imgData === false) {
            if ($i === 0) return null;
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
            if ($text) return $text;
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
    $cats = implode(', ', array_column(category_list(), 'key'));
    $examples = tenant('ai.examples') ? ' (' . tenant('ai.examples') . ')' : '';
    $seen = $photoCount > 1
        ? "Tu regardes " . $photoCount . " photos du MÊME objet, sous différents angles : " . tenant('ai.item') . " pour " . tenant('ai.shop') . $examples . ". Sers-toi de tous les angles (marques, signatures, état, dessous). "
        : "Tu regardes la photo d'" . tenant('ai.item') . " pour " . tenant('ai.shop') . $examples . ". ";
    $notes = trim($notes);
    return $seen
        . ($notes !== '' ? "Indications du vendeur, à prendre en compte : « " . $notes . " ». " : '')
        . "Réponds UNIQUEMENT avec un objet JSON strict, "
        . "sans texte autour, sans markdown, de cette forme exacte : "
        . '{"name": "nom court et vendeur (4-8 mots)", "description": "description chaleureuse en 2-3 phrases, honnête sur l\'état visible", "category": "une valeur parmi : ' . $cats . '", "price_hint": "fourchette de prix indicative en euros, ex : 25-35 €"}. '
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
    $validCats = array_column(category_list(), 'key');
    $cat = in_array($data['category'] ?? '', $validCats, true) ? $data['category'] : default_category_key();
    return [
        'name' => trim((string) $data['name']),
        'description' => trim((string) ($data['description'] ?? '')),
        'category' => $cat,
        'price_hint' => trim((string) ($data['price_hint'] ?? '')),
    ];
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

function build_angle_prompt(string $anglePreset, string $keywords): string
{
    $angleText = angle_presets()[$anglePreset] ?? angle_presets()['auto'];
    $prompt = "This is a real secondhand/vintage product photo for an online antiques shop. "
        . "Generate a photo of the SAME exact object(s), photographed $angleText, "
        . "on a clean simple neutral light-grey studio background, soft natural lighting, "
        . "photorealistic, no text, no watermark, no people. "
        . "Respond with the generated image only, no text in your reply.";
    return $keywords !== '' ? $prompt . ' Additional direction from the shop owner: ' . $keywords . '.' : $prompt;
}

function build_ambiance_prompt(string $keywords): string
{
    $prompt = "This is a real secondhand/vintage product photo for an online antiques shop. "
        . "Generate a styled photo showing the SAME exact object placed naturally in a cozy "
        . "French home interior (a living room or kitchen with warm wood tones), as if staged "
        . "for a lifestyle product photo, photorealistic, natural daylight, no text, no watermark, no people. "
        . "Respond with the generated image only, no text in your reply.";
    return $keywords !== '' ? $prompt . ' Additional direction from the shop owner: ' . $keywords . '.' : $prompt;
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
    return $keywords !== '' ? $prompt . ' Additional direction from the shop owner: ' . $keywords . '.' : $prompt;
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
                if ($resultBytes !== false) return $resultBytes;
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
 * Dimensions réelles (largeur × hauteur) d'une ressource média, affichées au
 * survol de sa vignette dans la médiathèque — pour les vidéos, passe par
 * ffprobe puisque getimagesize() ne lit pas ce format.
 */
function media_item_dimensions(array $m): ?string
{
    $abs = __DIR__ . '/../' . $m['path'];
    if (!is_file($abs)) return null;

    if ($m['type'] === 'video') {
        if (!FFPROBE_BIN || !shell_exec_available()) return null;
        $cmd = escapeshellarg(FFPROBE_BIN) . ' -v error -select_streams v:0'
            . ' -show_entries stream=width,height -of csv=s=x:p=0 ' . escapeshellarg($abs);
        $out = trim((string) shell_exec($cmd));
        return $out !== '' ? str_replace('x', ' × ', $out) . ' px' : null;
    }

    $size = @getimagesize($abs);
    return $size ? "{$size[0]} × {$size[1]} px" : null;
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

function flash_set(string $message, string $kind = 'ok'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
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
    return false;
}

/** Connecté à l'administration de ce commerce ? (une connexion ne vaut que pour un commerce) */
function is_admin_logged_in(): bool
{
    return ($_SESSION['is_admin'] ?? null) === tenant_slug();
}

function require_admin(): void
{
    if (!is_admin_logged_in()) {
        header('Location: /admin/login.php');
        exit;
    }
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
