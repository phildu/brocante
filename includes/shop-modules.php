<?php

/**
 * Modules payants pour vide-greniers : Buvette, Invendus & dons, Comptage de foule.
 *
 * Ce sont des plugins comme les autres (packs/<id>/) du point de vue du portail : activés boutique par boutique (portail → Plugins),
 * vendus avec prix, essai et modalités (portail → Plugins → Tarifs et modalités, includes/addons.php : addon_defaults()), et réservés
 * par défaut aux types de galerie « vide-grenier » (réglable : « Types de galerie », ou le ciblage du module).
 *
 * Leur code, lui, reste celui de leurs pages d'administration propres (admin/buvette/, admin/invendus*.php, admin/comptage/) et de
 * leurs pages publiques (buvette/, dons*.php, comptage/) : un plugin « fin » (packs/<id>/) porte seulement l'entrée du menu et le
 * panneau d'activation (essai, abonnement). Cette garde fait le lien : une boutique n'ouvre ces pages que si le plugin est activé
 * ET si l'accès est payé, essayé, offert ou inclus par sa galerie.
 */

require_once __DIR__ . '/plugins.php';
require_once __DIR__ . '/addons.php';

/** id du plugin => [nom, page d'accueil du module dans l'administration, texte du bouton]. */
const SHOP_MODULES = [
    'buvette' => ['Buvette', '/admin/buvette/index.php', 'Ouvrir la buvette'],
    'invendus-dons' => ['Invendus & dons', '/admin/invendus.php', 'Ouvrir Invendus & dons'],
    'comptage-foule' => ['Comptage de foule', '/admin/comptage/index.php', 'Ouvrir le comptage de foule'],
];

/** Ce module est-il utilisable dans cette boutique : plugin activé et à jour, et accès payé, en essai, offert ou inclus ? */
function shop_module_active(string $id): bool
{
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    try {
        return $cache[$id] = plugin_shop_status($id) === 'ready' && addon_is_entitled($id);
    } catch (Throwable $e) {
        return $cache[$id] = false;   // base sans table plugins / addons : le module n'a jamais été activé
    }
}

/**
 * Page d'administration d'un module : sans accès, renvoie sur la fiche du plugin (activation, essai, abonnement) au lieu
 * d'ouvrir le module. À appeler après require_admin().
 */
function shop_module_require(string $id): void
{
    if (shop_module_active($id)) return;
    header('Location: ' . plugin_page_url($id, 'index'), true, 302);
    exit;
}

/** Page publique d'un module (lien des bénévoles, page des dons…) : introuvable si la boutique n'a pas le module. */
function shop_module_require_public(string $id): void
{
    if (shop_module_active($id)) return;
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!DOCTYPE html><meta charset="utf-8"><title>Page introuvable</title><p style="font-family:sans-serif;padding:40px;">Cette page n\'existe pas.</p>');
}

/**
 * Fiche d'un module dans l'administration (page du plugin : /admin/plugin.php?p=<id>) : description, panneau d'activation
 * (essai gratuit, abonnement), puis accès au module quand il est utilisable. $plugin, $pluginApi et $pluginCsrf viennent de admin/plugin.php.
 */
function shop_module_page(array $plugin, callable $pluginApi, string $pluginCsrf): void
{
    $id = (string) $plugin['id'];
    [$name, $home, $cta] = SHOP_MODULES[$id] ?? [(string) $plugin['name'], '/admin/', 'Ouvrir'];
    $ent = addon_entitlement($id);
    ?>
  <p class="eyebrow">{Ext} · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;"><?= h($name) ?></h1>
  <?= addon_panel_html($id, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html($id) ?>
  <?php else: ?>
    <p class="hint" style="max-width:70ch;"><?= h((string) $plugin['description']) ?></p>
    <p><a class="btn btn-primary" href="<?= h($home) ?>"><?= h($cta) ?> →</a></p>
  <?php endif; ?>
<?php
}
