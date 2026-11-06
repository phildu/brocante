<?php

/** Couleurs de l'application (fond, accent) : celles de l'apparence enregistrée, sinon celles du commerce. */
function studio_theme_colors(): array
{
    $colors = tenant('colors', []);
    $saved = function_exists('appearance_saved') ? appearance_saved() : null;
    if ($saved) {
        $colors = appearance_derive($saved['colors'] ?? [])['light'];
    }
    $hex = static fn ($v, $fallback) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $fallback;
    return ['bg' => $hex($colors['bg'] ?? null, '#f4eee1'), 'accent' => $hex($colors['accent'] ?? null, '#b0622b')];
}
