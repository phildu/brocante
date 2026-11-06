<?php

const BUSINESS_PRESENCE_MODES = [
    'physical' => 'Physique',
    'online' => 'En ligne',
    'both' => 'Physique et en ligne',
];

function business_presence_mode(mixed $value, string $default = 'online'): string
{
    if (is_array($value)) {
        $physical = in_array('physical', $value, true);
        $online = in_array('online', $value, true);
        if ($physical && $online) return 'both';
        if ($physical) return 'physical';
        if ($online) return 'online';
        return isset(BUSINESS_PRESENCE_MODES[$default]) ? $default : 'online';
    }
    $value = (string) $value;
    return isset(BUSINESS_PRESENCE_MODES[$value]) ? $value : (isset(BUSINESS_PRESENCE_MODES[$default]) ? $default : 'online');
}

function business_presence_label(mixed $value): string
{
    return BUSINESS_PRESENCE_MODES[business_presence_mode($value)] ?? BUSINESS_PRESENCE_MODES['online'];
}

function shop_presence_mode(): string
{
    $saved = function_exists('shop_setting') ? shop_setting('presence_mode') : '';
    return business_presence_mode($saved !== '' ? $saved : tenant('presence_mode', 'online'));
}

/** Lit le réglage d'une boutique depuis le portail sans modifier sa base. */
function portail_presence_mode(array $shop): string
{
    $fallback = business_presence_mode($shop['presence_mode'] ?? 'online');
    try {
        $path = (string) ($shop['db_file'] ?? '');
        $path = str_starts_with($path, '/') ? $path : dirname(__DIR__) . '/' . $path;
        if (!is_file($path)) return $fallback;
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'presence_mode'");
        $stmt->execute();
        $saved = $stmt->fetchColumn();
        return $saved === false ? $fallback : business_presence_mode($saved, $fallback);
    } catch (PDOException) {
        return $fallback;
    }
}
