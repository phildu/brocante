<?php

// Stripe Connect côté boutique : commission prélevée à l'encaissement sur la caisse en ligne (panier → Stripe Checkout).
// Fichier volontairement autonome (aucune autre dépendance) : il lit seulement data/saas/connect-rates.json, que le portail écrit
// (includes/commissions.php) pour les boutiques dont le compte Stripe Connect est prêt. Une boutique absente de ce fichier, ou hébergée
// ailleurs que le portail (le Petit Chalet), garde son fonctionnement habituel : clés Stripe propres, aucune commission automatique.
//
// Principe (« destination charge ») : le paiement est créé sur le compte Stripe de la PLATEFORME ; Stripe verse le montant au compte du
// commerçant, moins `application_fee_amount` que la plateforme garde. La part de la galerie est conservée par la plateforme puis reversée
// (portail → Commissions → Verser). La commission porte sur le montant des pièces après remise, hors frais de port.

/** Clé secrète et adresse de l'API Stripe de la plateforme (portail), ou null. */
function connect_platform(): ?array
{
    $file = dirname(__DIR__) . '/.secrets/stripe_plateforme.json';
    $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($d) || !preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', (string) ($d['secret_key'] ?? ''))) return null;
    $base = 'https://api.stripe.com/v1';
    if (is_file(dirname(__DIR__) . '/config.local.php') && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?/v1$#', (string) ($d['api_base'] ?? ''))) $base = (string) $d['api_base'];
    return ['key' => (string) $d['secret_key'], 'base' => $base];
}

/** Commission applicable à cette boutique : ['account' => acct_…, 'pct' => %, 'platform_pct', 'gallery_pct', 'gallery'] ou null. */
function connect_shop(): ?array
{
    $file = dirname(__DIR__) . '/data/saas/connect-rates.json';
    $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $slug = function_exists('tenant_slug') ? tenant_slug() : '';
    $e = is_array($d) ? ($d[$slug] ?? null) : null;
    if (!is_array($e) || !preg_match('/^acct_[A-Za-z0-9]+$/', (string) ($e['account'] ?? '')) || (float) ($e['pct'] ?? 0) <= 0 || connect_platform() === null) return null;
    return $e;
}

/** Paiement par la plateforme pour un montant de pièces (centimes, remise déduite) : ['account', 'fee', 'pct', 'platform_cents', 'gallery_cents'] ou null (paiement habituel). */
function connect_plan(int $baseCents): ?array
{
    $s = connect_shop();
    if (!$s || $baseCents < 100) return null;
    $platform = (int) round($baseCents * (float) ($s['platform_pct'] ?? 0) / 100);
    $gallery = (int) round($baseCents * (float) ($s['gallery_pct'] ?? 0) / 100);
    $fee = $platform + $gallery;
    if ($fee < 1 || $fee >= $baseCents) return null;
    return ['account' => $s['account'], 'fee' => $fee, 'pct' => (float) $s['pct'], 'platform_cents' => $platform, 'gallery_cents' => $gallery, 'gallery' => (string) ($s['gallery'] ?? '')];
}
