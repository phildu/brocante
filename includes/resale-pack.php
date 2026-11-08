<?php

// Pack d'annonce pour la revente manuelle (Vinted, Leboncoin) depuis le Studio :
// les VRAIES photos du shooting (jamais les images générées, trompeuses sur du d'occasion)
// et un texte prêt à coller, calé sur les limites et le vocabulaire de chaque plateforme.

/** Plateformes proposées : titre et description au plus (caractères), états acceptés. */
function resale_pack_platforms(): array
{
    return [
        'vinted' => ['label' => 'Vinted', 'title_max' => 100, 'desc_max' => 2000],
        'leboncoin' => ['label' => 'Leboncoin', 'title_max' => 70, 'desc_max' => 4000],
    ];
}

function resale_pack_platform(?string $key): string
{
    return array_key_exists((string) $key, resale_pack_platforms()) ? (string) $key : 'vinted';
}

/** Coupe $text à $max caractères, à la fin d'un mot si possible. */
function resale_pack_cut(string $text, int $max): string
{
    $text = trim($text);
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $max * 0.6) $cut = mb_substr($cut, 0, $space);
    return rtrim($cut, " ,;:-–—.");
}

/** État de la pièce dans le vocabulaire de la plateforme ('' si l'état n'est pas renseigné). */
function resale_pack_condition(?string $etat, string $platform): string
{
    $etat = product_condition_key($etat);
    if ($etat === '') return '';
    $vinted = match ($etat) {
        'neuf_etiquette' => 'Neuf avec étiquette',
        'neuf_emballe', 'neuf_boite', 'neuf' => 'Neuf sans étiquette',
        'comme_neuf', 'presque_neuf', 'excellent', 'tres_bon' => 'Très bon état',
        'bon', 'bon_age', 'patine' => 'Bon état',
        default => 'Satisfaisant',
    };
    if ($platform === 'vinted') return $vinted;
    return match ($etat) {
        'neuf_etiquette', 'neuf_emballe', 'neuf_boite', 'neuf' => 'Neuf',
        'comme_neuf', 'presque_neuf', 'excellent', 'tres_bon' => 'Très bon état',
        'bon', 'bon_age', 'patine' => 'Bon état',
        'restaurer', 'pieces' => 'Pour pièces',
        default => 'État satisfaisant',
    };
}

/** Prix en euros à saisir sur la plateforme (« 25 », « 25,50 »), ou '' si la fiche n'a pas un montant unique (« sur devis », fourchette). */
function resale_pack_price(?string $price): string
{
    $value = price_input_value($price);
    return $value === '' ? '' : str_replace('.', ',', $value);
}

/** Fiche enregistrée, surchargée par les champs de la relecture envoyés (non enregistrés encore) : l'annonce reflète ce que le vendeur voit à l'écran. */
function resale_pack_product(array $product, array $posted): array
{
    foreach (['name', 'price', 'cat', 'description', 'materials', 'colors', 'brand', 'etat', 'size_text'] as $key) {
        if (isset($posted[$key]) && is_string($posted[$key])) $product[$key] = trim($posted[$key]);
    }
    if (isset($posted['weight_grams'])) $product['weight_grams'] = max(0, (int) $posted['weight_grams']);
    return $product;
}

/**
 * Texte de l'annonce pour une plateforme. $product : ligne de `products` (ou valeurs du formulaire de relecture).
 * Retourne ['title', 'description', 'price', 'condition', 'category', 'fields' => [libellé => valeur]].
 */
function resale_pack_text(array $product, string $platform): array
{
    $platform = resale_pack_platform($platform);
    $limits = resale_pack_platforms()[$platform];
    $name = trim((string) ($product['name'] ?? ''));
    $brand = trim((string) ($product['brand'] ?? ''));
    $size = trim((string) ($product['size_text'] ?? ''));

    // Titre : la marque d'abord sur Vinted (c'est ce que les acheteurs cherchent), absente du nom sinon ajoutée ; la taille courte suit sur Vinted.
    $title = $name;
    if ($brand !== '' && mb_stripos($title, $brand) === false) {
        $title = $platform === 'vinted' ? "$brand $title" : "$title $brand";
    }
    // Seulement une vraie taille de vêtement ou de chaussure (M, XL, 38, 38/40), pas des dimensions (« H 18 cm »).
    if ($platform === 'vinted' && preg_match('/^(?:X{0,3}[SL]|M|\d{2}(?:\/\d{2})?)$/i', $size) && mb_stripos($title, " $size") === false) {
        $title .= " - taille $size";
    }
    $title = resale_pack_cut($title, $limits['title_max']);

    $condition = resale_pack_condition($product['etat'] ?? '', $platform);
    $stateInfo = product_condition($product['etat'] ?? '');

    $fields = [];
    if ($brand !== '') $fields['Marque'] = $brand;
    if ($size !== '') $fields['Taille / dimensions'] = $size;
    $materials = trim((string) ($product['materials'] ?? ''));
    if ($materials !== '') $fields['Matières'] = $materials;
    $colors = trim((string) ($product['colors'] ?? ''));
    if ($colors !== '') $fields['Couleurs'] = $colors;
    if ($stateInfo) $fields['État'] = $stateInfo[0] . ' — ' . $stateInfo[1];
    $grams = (int) ($product['weight_grams'] ?? 0);
    if ($grams > 0) $fields['Poids'] = $grams >= 1000 ? rtrim(rtrim(number_format($grams / 1000, 2, ',', ''), '0'), ',') . ' kg' : "$grams g";

    $body = trim((string) ($product['description'] ?? ''));
    $lines = [];
    foreach ($fields as $label => $value) $lines[] = "$label : $value";
    $description = trim($body . ($lines ? "\n\n" . implode("\n", $lines) : ''));
    if (mb_strlen($description) > $limits['desc_max']) {
        // On sacrifie le texte libre, pas la fiche technique.
        $tail = $lines ? "\n\n" . implode("\n", $lines) : '';
        $description = resale_pack_cut($body, max(0, $limits['desc_max'] - mb_strlen($tail))) . $tail;
    }

    $category = trim((string) ($product['cat'] ?? '')) !== '' ? category_label((string) $product['cat']) : '';

    return [
        'title' => $title,
        'description' => $description,
        'price' => resale_pack_price($product['price'] ?? ''),
        'condition' => $condition,
        'category' => $category,
        'fields' => $fields,
    ];
}

/** Texte brut complet de l'annonce (titre, prix, état, description), pour le fichier du pack ou « Tout copier ». */
function resale_pack_plain(array $product, string $platform): string
{
    $platform = resale_pack_platform($platform);
    $t = resale_pack_text($product, $platform);
    $out = 'ANNONCE ' . mb_strtoupper(resale_pack_platforms()[$platform]['label']) . "\n\n";
    $out .= "Titre : {$t['title']}\n";
    $out .= 'Prix : ' . ($t['price'] !== '' ? $t['price'] . ' €' : 'à fixer') . "\n";
    if ($t['condition'] !== '') $out .= "État : {$t['condition']}\n";
    if ($t['category'] !== '') $out .= "Catégorie du site : {$t['category']}\n";
    return $out . "\nDescription :\n" . $t['description'] . "\n";
}

/**
 * Vraies photos de la pièce pour une annonce : celles du shooting (même masquées dans la boutique), photo principale d'abord,
 * sans détourage, mise en situation, recto / verso générés ni photo de mesure. Chaque entrée : ['path', 'label', 'file' (chemin disque)].
 */
function resale_pack_photos(string $ref): array
{
    $root = realpath(__DIR__ . '/..');
    $out = [];
    foreach (product_source_photos($ref) as $p) {
        if (!empty($p['cutout_of']) || preg_match('/^mesure/iu', trim((string) $p['label']))) continue;
        $file = $root . '/' . ltrim((string) $p['path'], '/');
        if (!is_file($file) || !preg_match('/\.(jpe?g|png|webp)$/i', $file)) continue;
        $out[] = ['path' => (string) $p['path'], 'label' => (string) $p['label'], 'file' => $file];
    }
    return $out;
}

/** Nom de fichier lisible pour une photo du pack : « 01-photo-principale.jpg ». */
function resale_pack_photo_name(int $index, string $label, string $path): string
{
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $label))), '-');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
    return sprintf('%02d-%s.%s', $index, $slug !== '' ? mb_substr($slug, 0, 40) : 'photo', $ext);
}

/**
 * Crée le .zip du pack (photos numérotées + annonce-vinted.txt + annonce-leboncoin.txt) dans $zipPath.
 * Retourne false si l'extension zip manque ou si la pièce n'a aucune photo.
 */
function resale_pack_build_zip(array $product, string $zipPath): bool
{
    if (!class_exists('ZipArchive')) return false;
    $photos = resale_pack_photos((string) $product['ref']);
    if (!$photos) return false;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    foreach ($photos as $i => $p) {
        $zip->addFile($p['file'], resale_pack_photo_name($i + 1, $p['label'], $p['path']));
    }
    foreach (array_keys(resale_pack_platforms()) as $platform) {
        $zip->addFromString("annonce-$platform.txt", resale_pack_plain($product, $platform));
    }
    return $zip->close();
}
