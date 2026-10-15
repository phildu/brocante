<?php

// Bouton « Partager » des pages publiques de la boutique : réseaux sociaux, lien à copier, code QR (assets/share.js, assets/share.css), et balises de
// prévisualisation (Open Graph / Twitter) pour que le lien partagé s'affiche avec son titre et sa photo.

/** Échappement HTML (ce fichier sert aussi la page publique des galeries, qui ne charge pas functions.php). */
function share_h($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** « https://hôte » de la requête (sans chemin). */
function share_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Adresse absolue d'une page de la boutique ($path : « /produit.php?ref=12 »), avec le préfixe d'un commerce servi sous /<identifiant>/. */
function shop_abs_url(string $path = '/'): string
{
    return share_origin() . app_prefix($path);
}

/** Adresse absolue d'un fichier du site (photo, logo) : « uploads/x.jpg » → https://hôte/uploads/x.jpg ; une adresse complète est gardée. */
function share_media_url(string $path): string
{
    return preg_match('#^https?://#i', $path) ? $path : share_origin() . '/' . ltrim($path, '/');
}

/**
 * Bouton « Partager » + petit bouton « QR code » (la fenêtre s'ouvre sur le bon onglet). Sur téléphone, « Partager » ouvre le menu de partage
 * du système quand il existe. $image : photo pour Pinterest. $class : classes ajoutées (ex. 'share-link' pour un simple lien).
 */
function share_button_html(string $url, string $title, string $text = '', string $image = '', string $class = '', bool $qr = true): string
{
    $attrs = ' data-share-url="' . share_h($url) . '" data-share-title="' . share_h($title) . '" data-share-text="' . share_h($text) . '"' . ($image !== '' ? ' data-share-image="' . share_h($image) . '"' : '');
    $icon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="2.6"/><circle cx="6" cy="12" r="2.6"/><circle cx="18" cy="19" r="2.6"/><path d="m8.3 10.8 7.4-4.3M8.3 13.2l7.4 4.3"/></svg>';
    $qrIcon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h3M20 17v4"/></svg>';
    return '<span class="share-group ' . share_h($class) . '"><button type="button" class="share-btn" data-share-open="share"' . $attrs . '>' . $icon . '<span>Partager</span></button>'
        . ($qr ? '<button type="button" class="share-btn share-btn-qr" data-share-open="qr"' . $attrs . ' aria-label="Afficher le code QR de cette page" title="Code QR">' . $qrIcon . '<span class="share-qr-label">QR code</span></button>' : '') . '</span>';
}

/** Balises de prévisualisation (à mettre dans <head>). Les valeurs absentes viennent de la boutique. */
function share_og_html(string $title, string $description, string $url, string $image = '', string $type = 'website', string $siteName = ''): string
{
    $m = static fn (string $p, string $v): string => $v === '' ? '' : '<meta property="' . $p . '" content="' . share_h($v) . '">' . "\n";
    $n = static fn (string $p, string $v): string => $v === '' ? '' : '<meta name="' . $p . '" content="' . share_h($v) . '">' . "\n";
    return $m('og:type', $type) . $m('og:title', $title) . $m('og:description', mb_substr($description, 0, 300)) . $m('og:url', $url) . $m('og:site_name', $siteName)
        . $m('og:image', $image) . $m('og:locale', 'fr_FR')
        . $n('twitter:card', $image !== '' ? 'summary_large_image' : 'summary') . $n('twitter:title', $title) . $n('twitter:description', mb_substr($description, 0, 200)) . $n('twitter:image', $image)
        . ($url !== '' ? '<link rel="canonical" href="' . share_h($url) . '">' . "\n" : '');
}
