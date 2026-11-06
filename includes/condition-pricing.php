<?php

const PRODUCT_CONDITION_PRICE_DEFAULTS = [
    'neuf_etiquette' => 90,
    'neuf_emballe' => 90,
    'neuf_boite' => 85,
    'neuf' => 80,
    'comme_neuf' => 75,
    'presque_neuf' => 70,
    'excellent' => 65,
    'tres_bon' => 55,
    'bon' => 45,
    'bon_age' => 40,
    'patine' => 45,
    'correct' => 30,
    'usage' => 25,
    'defauts' => 20,
    'use' => 15,
    'restaurer' => 10,
    'pieces' => 5,
];

/** Pourcentages propres à la boutique, avec les valeurs proposées pour les états ajoutés ultérieurement. */
function product_condition_price_rates(): array
{
    $defaults = [];
    foreach (product_condition_options() as $items) {
        foreach ($items as $key => $_) $defaults[$key] = (float) (PRODUCT_CONDITION_PRICE_DEFAULTS[$key] ?? 50);
    }
    $stored = shop_setting('product_condition_price_rates');
    if ($stored === '') return $defaults;
    try {
        $saved = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return $defaults;
    }
    if (!is_array($saved)) return $defaults;
    foreach ($defaults as $key => $default) {
        $value = $saved[$key] ?? $default;
        if (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 300) {
            $defaults[$key] = round((float) $value, 2);
        }
    }
    return $defaults;
}

/** Suggestion à titre indicatif : prix du neuf × taux correspondant à l'état. */
function product_condition_price_suggestion(?string $condition, mixed $newPrice, ?array $rates = null): ?float
{
    $key = product_condition_key($condition);
    $basePrice = pricing_euros($newPrice);
    if ($key === '' || $basePrice === null) return null;
    $rates ??= product_condition_price_rates();
    if (!isset($rates[$key]) || !is_numeric($rates[$key])) return null;
    return round($basePrice * (float) $rates[$key] / 100, 2);
}
