<?php

// Recto / verso automatique d'une pièce : chaque angle photographié est détouré, puis TOUS les détourages sont envoyés
// ensemble à l'IA pour produire deux visuels de studio, le recto et le verso de l'objet. Chaque étape est une requête à
// part (admin/recto-verso-action.php), pour rester sous la durée maximale d'une requête sur un hébergement mutualisé.

/** Angles (photos de départ) pris en compte, la photo principale d'abord. */
const RECTO_VERSO_MAX_ANGLES = 6;
/** Détourages réellement envoyés à l'IA (la photo principale + 3 autres, comme les autres générations multi-photos). */
const RECTO_VERSO_MAX_SENT = 4;

/**
 * Les gros plans (étiquette, texture, détail) restent de simples photos de la fiche : ce ne sont pas des angles de l'objet,
 * ils ne sont ni détourés ni envoyés à l'IA pour fabriquer le recto et le verso (mais elle les lit pour rédiger la fiche).
 */
function recto_verso_is_closeup(array $photo): bool
{
    return (bool) preg_match('/^(étiquette|etiquette|texture|détail|detail)/iu', trim((string) $photo['label']));
}

function recto_verso_sources(string $ref): array
{
    $angles = array_values(array_filter(product_source_photos($ref), static fn (array $p): bool => !recto_verso_is_closeup($p)));
    return array_slice($angles, 0, RECTO_VERSO_MAX_ANGLES);
}

/** Détourage déjà fait pour une photo de départ (le plus récent), ou null. */
function recto_verso_cutout_of(string $ref, int $sourceId): ?array
{
    $stmt = db()->prepare('SELECT * FROM product_photos WHERE product_ref = ? AND cutout_of = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$ref, $sourceId]);
    $row = $stmt->fetch();
    return $row && is_file(__DIR__ . '/../' . $row['path']) ? $row : null;
}

/**
 * Images de tous les angles de la pièce, dans l'ordre des photos de départ : le détourage de chaque angle s'il existe, sinon
 * la photo elle-même (l'IA refuse parfois de détourer une photo, p. ex. avec une personne : le recto / verso se fait alors
 * quand même avec elle). [[source, image, détouré ?], …]
 */
function recto_verso_inputs(string $ref): array
{
    $inputs = [];
    foreach (recto_verso_sources($ref) as $source) {
        $cut = recto_verso_cutout_of($ref, (int) $source['id']);
        if ($cut) $inputs[] = [$source, $cut, true];
        elseif (is_file(__DIR__ . '/../' . $source['path'])) $inputs[] = [$source, $source, false];
    }
    return $inputs;
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
        ? "These $count images show the SAME real secondhand/vintage object, each photographed from a different angle (the first one is the main photo; some are cut-outs on a plain background). "
            . "Study ALL of them to understand its true shape, colors, patterns, materials, labels and small details. "
        : "This image shows a real secondhand/vintage object. ";
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

/**
 * Génère le recto ou le verso à partir de tous les angles (détourage de chacun s'il existe, sinon sa photo) ;
 * ['ok' => true, 'path', 'label', 'sent'] ou ['ok' => false, 'error']. Si l'IA refuse l'ensemble (une photo avec une
 * personne, p. ex.), elle est redemandée avec les seuls détourages, ou avec la photo principale seule.
 */
function recto_verso_generate(string $ref, string $side, string $keywords = ''): array
{
    if (!GEMINI_API_KEY) return ['ok' => false, 'error' => 'Clé Gemini manquante (réglages du site).'];
    $inputs = array_slice(recto_verso_inputs($ref), 0, RECTO_VERSO_MAX_SENT);
    if (!$inputs) return ['ok' => false, 'error' => "Aucune photo à envoyer à l'IA."];
    $sets = [$inputs];
    if (count($inputs) > 1) {
        $cutsOnly = array_values(array_filter($inputs, static fn (array $i): bool => $i[2]));
        $sets[] = $cutsOnly && count($cutsOnly) < count($inputs) ? $cutsOnly : [$inputs[0]];
    }
    $label = $side === 'verso' ? 'Verso (IA)' : 'Recto (IA)';
    foreach ($sets as $n => $set) {
        $temps = [];
        foreach ($set as $i => [, $img]) {
            $tmp = recto_verso_flatten(__DIR__ . '/../' . $img['path'], $i === 0 ? 1280 : 1024);
            if ($tmp) $temps[] = $tmp;
        }
        if (!$temps) continue;
        $count = count($temps);
        $main = array_shift($temps);
        // Un seul essai par jeu d'images tant qu'il en reste un autre à tenter (durée d'une requête limitée).
        $desktop = generate_desktop_image($main, build_recto_verso_prompt($side, $count, $keywords) . multi_photo_prompt($count),
            'product-' . $ref . '-' . $side, $n === count($sets) - 1 ? 1 : 0, false, $temps);
        @unlink($main);
        foreach ($temps as $t) @unlink($t);
        if (!$desktop) continue;
        $photoId = add_product_photo($ref, $desktop, $label, true);
        queue_mobile_variant($photoId, $desktop);
        return ['ok' => true, 'path' => $desktop, 'label' => $label, 'sent' => $count];
    }
    return ['ok' => false, 'error' => 'La génération du ' . ($side === 'verso' ? 'verso' : 'recto') . " a échoué (service IA indisponible ou image refusée) — réessayez."];
}
