<?php

// Mode vitrine : après un temps d'inactivité sur l'appareil du magasin, la
// boutique laisse place au diaporama (promos en cours puis sélection du
// catalogue). Le déclenchement est propre à l'appareil : il ne s'active
// qu'après le lien « ?vitrine=1 » (ou une fois le réglage posé), pour ne
// jamais surgir chez un visiteur qui consulte la boutique en ligne.

require_once __DIR__ . '/functions.php';

/** Réglages du mode vitrine, ou null si désactivé, page de commande ou diaporama vide. */
function slideshow_idle_config(): ?array
{
    if (shop_setting('slideshow_idle_enabled', '0') !== '1') return null;

    $script = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $excluded = [
        'cart.php', 'checkout.php', 'checkout-success.php', 'payer.php', 'sepa-instructions.php',
        'reprise-commande.php', 'compte.php', 'connexion.php', 'mot-de-passe-oublie.php',
        'demande.php', 'disponibilite.php', 'suivi.php',
    ];
    if (in_array($script, $excluded, true)) return null;

    if (!slideshow_public_slides()) return null;

    return [
        'minutes' => max(1, min(120, (int) shop_setting('slideshow_idle_minutes', '3'))),
        'url' => app_prefix('/slideshow.php') . '?embed=1',
    ];
}

/** Calque plein écran + script de veille, à inclure dans le pied de page public. */
function slideshow_idle_html(): string
{
    $config = slideshow_idle_config();
    if ($config === null) return '';

    $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $scriptUrl = '/assets/slideshow-idle.js?v=' . (int) @filemtime(__DIR__ . '/../assets/slideshow-idle.js');
    ob_start();
    ?>
<style>
  .shop-kiosk { position: fixed; inset: 0; z-index: 2147483000; background: #000; }
  .shop-kiosk[hidden] { display: none; }
  .shop-kiosk-frame { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; background: #000; }
  .shop-kiosk-close {
    position: absolute; top: 16px; left: 16px; z-index: 2;
    padding: 10px 16px; border: 1px solid rgba(255,255,255,0.45); border-radius: 999px;
    background: rgba(0,0,0,0.55); color: #fff; font: 600 0.86rem/1 system-ui, sans-serif; cursor: pointer;
  }
  .shop-kiosk-close:hover { background: rgba(0,0,0,0.78); }
  .shop-kiosk-note {
    position: fixed; left: 50%; bottom: 22px; transform: translateX(-50%); z-index: 2147483001;
    max-width: calc(100vw - 32px); padding: 10px 16px; border-radius: 999px;
    background: rgba(24,20,14,0.92); color: #fff; font: 500 0.86rem/1.35 system-ui, sans-serif; text-align: center;
  }
</style>
<div class="shop-kiosk" id="shop-kiosk" role="dialog" aria-modal="true" aria-label="Diaporama boutique" hidden>
  <iframe class="shop-kiosk-frame" id="shop-kiosk-frame" title="Diaporama boutique" allow="autoplay; fullscreen"></iframe>
  <button type="button" class="shop-kiosk-close" id="shop-kiosk-close">Revenir à la boutique</button>
</div>
<script>window.__brocsKiosk = <?= $configJson ?>;</script>
<script src="<?= h($scriptUrl) ?>" defer></script>
    <?php
    return (string) ob_get_clean();
}
