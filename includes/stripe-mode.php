<?php

// Bascule Stripe test / réel d'un commerce. Les clés des deux modes sont
// conservées côte à côte (stripe_test_secret.key, stripe_live_secret.key…)
// et le mode actif recopie la paire choisie dans les fichiers historiques
// (stripe_secret.key, stripe_publishable.key, stripe_webhook.key) : tout le
// reste du code (paiement, webhooks, packs) continue de lire ces fichiers.
// Une installation d'avant la bascule n'a qu'une paire : son mode est déduit
// du préfixe (sk_test_ / sk_live_ / rk_test_ / rk_live_) tant qu'aucun mode
// n'a été enregistré. Les clés restreintes (rk_) sont acceptées : Stripe les
// délivre désormais avec des permissions choisies, et le mode y figure aussi.

const STRIPE_MODES = ['test' => 'Test', 'live' => 'Réel'];

function stripe_secrets_dir(?string $dir = null): string
{
    if ($dir !== null) {
        return $dir === '' ? '' : rtrim($dir, '/');
    }
    return defined('SECRETS_DIR') ? rtrim((string) SECRETS_DIR, '/') : rtrim(dirname(__DIR__) . '/.secrets', '/');
}

function stripe_read_secret(string $file): string
{
    return is_file($file) ? trim((string) file_get_contents($file)) : '';
}

/** Mode d'une clé Stripe : 'live', 'test', ou '' si le préfixe n'est pas reconnu. */
function stripe_key_mode(string $key): string
{
    if (preg_match('/^(?:sk|rk)_live_[A-Za-z0-9]+$/', $key)) return 'live';
    if (preg_match('/^(?:sk|rk)_test_[A-Za-z0-9]+$/', $key)) return 'test';
    return '';
}

function stripe_mode_file(?string $dir = null): string
{
    return stripe_secrets_dir($dir) . '/stripe_mode';
}

/** Mode enregistré explicitement ('test' ou 'live'), ou '' si aucun. */
function stripe_mode_stored(?string $dir = null): string
{
    $mode = trim(stripe_read_secret(stripe_mode_file($dir)));
    return isset(STRIPE_MODES[$mode]) ? $mode : '';
}

/** Mode actif : mode enregistré, sinon préfixe de la paire historique, sinon test. */
function stripe_mode_current(?string $dir = null): string
{
    $dir = stripe_secrets_dir($dir);
    $stored = stripe_mode_stored($dir);
    if ($stored !== '') return $stored;
    $mode = stripe_key_mode(stripe_read_secret($dir . '/stripe_secret.key'));
    if ($mode === '') $mode = stripe_key_mode(stripe_read_secret($dir . '/stripe_publishable.key'));
    return $mode !== '' ? $mode : 'test';
}

/** Mode de la paire historique, pour rattacher ses clés à test ou réel. */
function stripe_legacy_mode(?string $dir = null): string
{
    $dir = stripe_secrets_dir($dir);
    $mode = stripe_key_mode(stripe_read_secret($dir . '/stripe_secret.key'));
    if ($mode === '') $mode = stripe_key_mode(stripe_read_secret($dir . '/stripe_publishable.key'));
    return $mode !== '' ? $mode : 'test';
}

function stripe_mode_key_file(string $mode, string $kind, ?string $dir = null): string
{
    return stripe_secrets_dir($dir) . '/stripe_' . $mode . '_' . $kind . '.key';
}

/**
 * Clés enregistrées pour un mode : secret, publishable, webhook.
 * Tant qu'aucun mode n'a été enregistré, la paire historique appartient au
 * mode de ses préfixes et sert de source pour ce mode.
 */
function stripe_mode_keys(string $mode, ?string $dir = null): array
{
    $dir = stripe_secrets_dir($dir);
    $keys = [];
    foreach (['secret', 'publishable', 'webhook'] as $kind) {
        $keys[$kind] = stripe_read_secret(stripe_mode_key_file($mode, $kind, $dir));
    }
    if (stripe_mode_stored($dir) === '' && $mode === stripe_legacy_mode($dir)) {
        foreach (['secret', 'publishable', 'webhook'] as $kind) {
            if ($keys[$kind] === '') $keys[$kind] = stripe_read_secret($dir . '/stripe_' . $kind . '.key');
        }
    }
    return $keys;
}

/** Le mode a-t-il une clé secrète utilisable ? */
function stripe_mode_ready(string $mode, ?string $dir = null): bool
{
    return isset(STRIPE_MODES[$mode]) && stripe_mode_keys($mode, $dir)['secret'] !== '';
}

/** Clé tronquée pour l'affichage (jamais la clé entière). */
function stripe_key_mask(string $key): string
{
    if ($key === '') return '';
    if (strlen($key) <= 14) return substr($key, 0, 4) . '…';
    return substr($key, 0, 7) . '…' . substr($key, -4);
}

/** Fichier de secret en 0600, dossier créé en 0700 si besoin. */
function stripe_write_private(string $file, string $value): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    if (@file_put_contents($file, $value) === false) return false;
    @chmod($file, 0600);
    return true;
}

/** Recopie la paire d'un mode dans les fichiers historiques lus par le paiement et les webhooks. */
function stripe_mode_write_active(array $keys, ?string $dir = null): bool
{
    $dir = stripe_secrets_dir($dir);
    foreach (['secret' => 'stripe_secret.key', 'publishable' => 'stripe_publishable.key', 'webhook' => 'stripe_webhook.key'] as $kind => $name) {
        $file = $dir . '/' . $name;
        $value = (string) ($keys[$kind] ?? '');
        if ($value !== '') {
            if (!stripe_write_private($file, $value)) return false;
        } elseif (is_file($file)) {
            @unlink($file);
        }
    }
    return true;
}

/** Enregistre les clés d'un mode (les champs vides gardent la clé existante). Retourne [bool, message]. */
function stripe_mode_save(string $mode, array $keys, ?string $dir = null): array
{
    $dir = stripe_secrets_dir($dir);
    if (!isset(STRIPE_MODES[$mode])) return [false, 'Mode Stripe inconnu.'];
    $clean = [];
    foreach (['secret', 'publishable', 'webhook'] as $kind) {
        $clean[$kind] = trim((string) ($keys[$kind] ?? ''));
    }
    if ($clean['secret'] === '' && $clean['publishable'] === '' && $clean['webhook'] === '') {
        return [false, 'Aucune clé fournie — rien n\'a été changé.'];
    }

    $wanted = $mode === 'live' ? 'live' : 'test';
    if ($clean['secret'] !== '') {
        if (!preg_match('/^(?:sk|rk)_(test|live)_[A-Za-z0-9]+$/', $clean['secret'])) {
            return [false, 'Clé secrète non valide : elle commence par sk_test_, rk_test_, sk_live_ ou rk_live_.'];
        }
        if (stripe_key_mode($clean['secret']) !== $wanted) {
            return [false, 'Cette clé secrète n\'est pas une clé de ' . strtolower(STRIPE_MODES[$wanted]) . ' : utilisez une clé sk_' . $wanted . '_… ou rk_' . $wanted . '_…'];
        }
    }
    if ($clean['publishable'] !== '') {
        if (!preg_match('/^pk_(test|live)_[A-Za-z0-9]+$/', $clean['publishable'])) {
            return [false, 'Clé publiable non valide : elle commence par pk_test_ ou pk_live_.'];
        }
        if (stripe_key_mode(str_replace('pk_', 'sk_', $clean['publishable'])) !== $wanted) {
            return [false, 'Cette clé publiable n\'est pas une clé de ' . strtolower(STRIPE_MODES[$wanted]) . ' : utilisez une clé pk_' . $wanted . '_…'];
        }
    }
    if ($clean['webhook'] !== '' && !preg_match('/^whsec_[A-Za-z0-9]+$/', $clean['webhook'])) {
        return [false, 'Le secret de webhook doit commencer par whsec_.'];
    }

    foreach (['secret', 'publishable', 'webhook'] as $kind) {
        if ($clean[$kind] === '') continue;
        if (!stripe_write_private(stripe_mode_key_file($mode, $kind, $dir), $clean[$kind])) {
            return [false, 'Impossible d\'écrire les clés (droits du dossier des secrets).'];
        }
    }
    // Mode déjà actif : la nouvelle paire entre immédiatement en service.
    if (stripe_mode_current($dir) === $mode && !stripe_mode_write_active(stripe_mode_keys($mode, $dir), $dir)) {
        return [false, 'Clés enregistrées, mais impossible de les activer (droits du dossier des secrets).'];
    }
    return [true, 'Clés du mode ' . STRIPE_MODES[$mode] . ' enregistrées.'];
}

/** Bascule le commerce sur un mode. Retourne [bool, message]. */
function stripe_mode_activate(string $mode, ?string $dir = null): array
{
    $dir = stripe_secrets_dir($dir);
    if (!isset(STRIPE_MODES[$mode])) return [false, 'Mode Stripe inconnu.'];
    $keys = stripe_mode_keys($mode, $dir);
    if ($keys['secret'] === '') {
        return [false, 'Aucune clé secrète enregistrée pour le mode ' . STRIPE_MODES[$mode] . ' : renseignez-la d\'abord.'];
    }
    if (!stripe_mode_write_active($keys, $dir)) {
        return [false, 'Impossible d\'écrire les clés (droits du dossier des secrets).'];
    }
    if (!stripe_write_private(stripe_mode_file($dir), $mode)) {
        return [false, 'Impossible d\'enregistrer le mode Stripe (droits du dossier des secrets).'];
    }
    return [true, 'Stripe est maintenant en mode ' . STRIPE_MODES[$mode] . '.'];
}

/** État des deux modes pour l'affichage : clés présentes et clé secrète masquée. */
function stripe_modes_status(?string $dir = null): array
{
    $dir = stripe_secrets_dir($dir);
    $modes = [];
    foreach (array_keys(STRIPE_MODES) as $mode) {
        $keys = stripe_mode_keys($mode, $dir);
        $modes[$mode] = [
            'secret' => $keys['secret'] !== '',
            'publishable' => $keys['publishable'] !== '',
            'webhook' => $keys['webhook'] !== '',
            'secret_mask' => stripe_key_mask($keys['secret']),
        ];
    }
    return ['mode' => stripe_mode_current($dir), 'modes' => $modes];
}
