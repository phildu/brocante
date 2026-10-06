<?php

// Clés API PARTAGÉES pendant la période de test : une même clé (Gemini, fal.ai, SiliconFlow, Modal) sert à tous les commerces d'un même
// déploiement qui n'ont pas la leur. Elles vivent dans .secrets/_partage/ (jamais envoyé par le script de déploiement), se gèrent
// depuis le portail (portail/cles.php, réservé à l'exploitant : un commerçant ne peut pas les modifier) et s'effacent d'un seul
// geste à la mise en production (« Supprimer toutes les clés partagées »). Une clé propre à un commerce (saisie dans ses réglages)
// reste prioritaire. Les clés Stripe ne sont JAMAIS partagées : chaque commerce encaisse sur son propre compte.

/** Fichiers pouvant être partagés : nom → libellé. */
const SHARED_SECRET_FILES = [
    'gemini.key' => 'Gemini (images, textes, vision)',
    'fal.key' => 'fal.ai (détourage)',
    'siliconflow.key' => 'SiliconFlow (vidéo IA Wan 2.2)',
    'modal-video.json' => 'Modal (vidéo IA et détourage haute précision)',
];

function shared_secrets_dir(): string
{
    return dirname(__DIR__) . '/.secrets/_partage';
}

/** Chemin à lire pour une clé : celle du commerce si elle existe, sinon la clé partagée si elle existe, sinon celle du commerce. */
function secret_path(string $name): string
{
    $own = (defined('SECRETS_DIR') ? SECRETS_DIR : dirname(__DIR__) . '/.secrets') . '/' . $name;
    if (is_file($own)) return $own;
    $shared = shared_secrets_dir() . '/' . $name;
    return isset(SHARED_SECRET_FILES[$name]) && is_file($shared) ? $shared : $own;
}

/** Ce commerce utilise-t-il la clé partagée (parce qu'il n'a pas la sienne) ? */
function secret_is_shared(string $name): bool
{
    $own = (defined('SECRETS_DIR') ? SECRETS_DIR : dirname(__DIR__) . '/.secrets') . '/' . $name;
    return !is_file($own) && isset(SHARED_SECRET_FILES[$name]) && is_file(shared_secrets_dir() . '/' . $name);
}

function shared_secret_exists(string $name): bool
{
    return isset(SHARED_SECRET_FILES[$name]) && is_file(shared_secrets_dir() . '/' . $name);
}

/** Enregistre une clé partagée (lisible par le seul propriétaire). */
function shared_secret_save(string $name, string $content): bool
{
    if (!isset(SHARED_SECRET_FILES[$name]) || trim($content) === '') return false;
    $dir = shared_secrets_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    $file = $dir . '/' . $name;
    if (@file_put_contents($file, $content) === false) return false;
    @chmod($file, 0600);
    return true;
}

function shared_secret_delete(string $name): void
{
    $file = shared_secrets_dir() . '/' . $name;
    if (isset(SHARED_SECRET_FILES[$name]) && is_file($file)) @unlink($file);
}

/** Mise en production : efface toutes les clés partagées ; retourne leur nombre. */
function shared_secrets_clear(): int
{
    $n = 0;
    foreach (array_keys(SHARED_SECRET_FILES) as $name) {
        if (shared_secret_exists($name)) { shared_secret_delete($name); $n++; }
    }
    $dir = shared_secrets_dir();
    if (is_dir($dir) && !glob($dir . '/*')) @rmdir($dir);
    return $n;
}

/** Aperçu masqué d'une clé (pour l'affichage) : « AIza…x9Zq ». Pour Modal : l'adresse. */
function secret_preview(string $file): string
{
    $raw = trim((string) @file_get_contents($file));
    if (str_ends_with($file, '.json')) {
        $data = json_decode($raw, true) ?: [];
        return (string) ($data['url'] ?? '') !== '' ? (string) $data['url'] : '(vide)';
    }
    return strlen($raw) > 10 ? substr($raw, 0, 4) . '…' . substr($raw, -4) : ($raw !== '' ? '…' : '(vide)');
}
