<?php

// Recto / verso automatique d'une pièce : chaque angle photographié est détouré, puis TOUS les détourages sont envoyés
// ensemble à l'IA pour produire deux visuels de studio, le recto et le verso de l'objet. Chaque étape est une requête à
// part (admin/recto-verso-action.php), pour rester sous la durée maximale d'une requête sur un hébergement mutualisé.

/** Angles (photos de départ) pris en compte, la photo principale d'abord. */
const RECTO_VERSO_MAX_ANGLES = 6;
/** Détourages réellement envoyés à l'IA (la photo principale + 3 autres, comme les autres générations multi-photos). */
const RECTO_VERSO_MAX_SENT = 4;

function recto_verso_sources(string $ref): array
{
    return array_slice(product_source_photos($ref), 0, RECTO_VERSO_MAX_ANGLES);
}

/** Détourage déjà fait pour une photo de départ (le plus récent), ou null. */
function recto_verso_cutout_of(string $ref, int $sourceId): ?array
{
    $stmt = db()->prepare('SELECT * FROM product_photos WHERE product_ref = ? AND cutout_of = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$ref, $sourceId]);
    $row = $stmt->fetch();
    return $row && is_file(__DIR__ . '/../' . $row['path']) ? $row : null;
}

/** Détourages de tous les angles de la pièce, dans l'ordre des photos de départ : [[source, cutout], …]. */
function recto_verso_cutouts(string $ref): array
{
    $pairs = [];
    foreach (recto_verso_sources($ref) as $source) {
        if ($cut = recto_verso_cutout_of($ref, (int) $source['id'])) $pairs[] = [$source, $cut];
    }
    return $pairs;
}

/** Détourage d'une photo de départ, enregistré dans la galerie et rattaché à elle : ['ok', 'cutout' => ligne] ou ['ok' => false, 'error']. */
function recto_verso_make_cutout(string $ref, int $sourceId): array
{
    $stmt = db()->prepare('SELECT * FROM product_photos WHERE id = ? AND product_ref = ?');
    $stmt->execute([$sourceId, $ref]);
    $source = $stmt->fetch();
    if (!$source || ($source['type'] ?? 'photo') !== 'photo') return ['ok' => false, 'error' => 'Photo introuvable.'];
    if ($existing = recto_verso_cutout_of($ref, $sourceId)) return ['ok' => true, 'cutout' => $existing, 'already' => true];

    $abs = __DIR__ . '/../' . $source['path'];
    if (!is_file($abs)) return ['ok' => false, 'error' => 'Fichier de la photo introuvable.'];
    $det = detoure_photo_synchronous($abs);
    if (!$det) return ['ok' => false, 'error' => 'Détourage indisponible : configurez fal.ai ou Gemini dans les réglages du site.'];
    $path = save_binary_photo($det['bytes'], 'product-' . $ref . '-detoure', $det['ext']);
    if (!$path) return ['ok' => false, 'error' => "Échec de l'enregistrement du détourage."];
    $id = add_product_photo($ref, $path, $det['label'] . ' — ' . $source['label'], $det['illustration']);
    db()->prepare('UPDATE product_photos SET cutout_of = ? WHERE id = ?')->execute([$sourceId, $id]);
    $row = db()->query('SELECT * FROM product_photos WHERE id = ' . $id)->fetch();
    return ['ok' => true, 'cutout' => $row];
}

/** Image réduite sur fond blanc (un détourage transparent resterait noir à l'envoi), en JPEG temporaire ; null si illisible. */
function recto_verso_flatten(string $srcAbs, int $maxDim = 1280): ?string
{
    $info = @getimagesize($srcAbs);
    if (!$info) return null;
    $src = match ($info[2]) {
        IMAGETYPE_PNG => @imagecreatefrompng($srcAbs),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($srcAbs),
        IMAGETYPE_WEBP => @imagecreatefromwebp($srcAbs),
        default => false,
    };
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxDim / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $tmp = sys_get_temp_dir() . '/rv-' . bin2hex(random_bytes(6)) . '.jpg';
    imagejpeg($dst, $tmp, 88);
    return $tmp;
}

/** Prompt (anglais) du recto ou du verso, d'après $count détourages du même objet. */
function build_recto_verso_prompt(string $side, int $count, string $keywords = ''): string
{
    $views = $count > 1
        ? "These $count images are cut-outs of the SAME real secondhand/vintage object, each photographed from a different angle (the first one is the main photo). "
            . "Study ALL of them to understand its true shape, colors, patterns, materials, labels and small details. "
        : "This image is a cut-out of a real secondhand/vintage object. ";
    $face = $side === 'verso'
        ? "the BACK (verso) of this exact object, seen perfectly from behind, i.e. the face opposite to the main photo. Show it exactly as the photos reveal it; "
            . "if no photo shows the back, infer it conservatively from the visible edges, seams, fasteners and construction — plain and plausible — "
            . "and never invent logos, text, prints or decorations that the photos do not support"
        : "the FRONT (recto) of this exact object, seen perfectly face-on: its main, most representative face (the side with the main design, label or opening)";
    return $views
        . "Generate a clean studio product photo of $face. The object is centered and entirely visible, upright, "
        . "on a plain neutral light-grey studio background, soft natural lighting, photorealistic, no added text, no watermark, no people. "
        . "Identical shape, colors, materials and proportions to the real object — never redesign it."
        . owner_direction_prompt($keywords)
        . " Respond with the generated image only, no text in your reply.";
}

/** Génère le recto ou le verso à partir des détourages de tous les angles ; ['ok' => true, 'path', 'label', 'sent'] ou ['ok' => false, 'error']. */
function recto_verso_generate(string $ref, string $side, string $keywords = ''): array
{
    if (!GEMINI_API_KEY) return ['ok' => false, 'error' => 'Clé Gemini manquante (réglages du site).'];
    $pairs = array_slice(recto_verso_cutouts($ref), 0, RECTO_VERSO_MAX_SENT);
    if (!$pairs) return ['ok' => false, 'error' => "Aucun détourage : détourez d'abord les photos."];
    $temps = [];
    foreach ($pairs as $i => [, $cut]) {
        $tmp = recto_verso_flatten(__DIR__ . '/../' . $cut['path'], $i === 0 ? 1280 : 1024);
        if ($tmp) $temps[] = $tmp;
    }
    if (!$temps) return ['ok' => false, 'error' => 'Détourages illisibles.'];
    $main = array_shift($temps);
    $label = $side === 'verso' ? 'Verso (IA)' : 'Recto (IA)';
    $desktop = generate_desktop_image($main, build_recto_verso_prompt($side, count($temps) + 1, $keywords) . multi_photo_prompt(count($temps) + 1),
        'product-' . $ref . '-' . $side, 1, false, $temps);
    @unlink($main);
    foreach ($temps as $t) @unlink($t);
    if (!$desktop) return ['ok' => false, 'error' => 'La génération du ' . ($side === 'verso' ? 'verso' : 'recto') . " a échoué (service IA indisponible ou image refusée) — réessayez."];
    $photoId = add_product_photo($ref, $desktop, $label, true);
    queue_mobile_variant($photoId, $desktop);
    return ['ok' => true, 'path' => $desktop, 'label' => $label, 'sent' => count($temps) + 1];
}
