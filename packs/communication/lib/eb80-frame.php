<?php
// Cadre qui affiche l'admin d'electroboy80 portée (assets/eb80/admin.html) dans une page du plugin, avec la configuration que lit
// eb80-shim.js : API portée (route eb80), jeton CSRF, dossier d'envoi de la boutique, page de départ, pages ouvertes, et ce que
// eb80-after.js reprend de la boutique (nom, adresse du site) pour les textes par défaut de l'éditeur.
defined('PLUGIN_RUNTIME') || exit;

function com_eb80_frame(array $plugin, string $csrf, string $role, string $start, string $title): void
{
    $site = (string) tenant('site_url');
    $config = [
        'api' => plugin_api_url($plugin['id'], 'eb80'),
        'csrf' => $csrf,
        'uploads' => '/uploads/' . tenant_slug() . '/',
        'start' => $start,
        'allowed' => ['media', 'editor'],
        'role' => $role,
        'shop' => (string) get_content()['site_name'],
        'site' => preg_replace('#^https?://#', '', rtrim($site, '/')),
    ];
    $dir = $plugin['dir'] . '/assets/eb80/';
    $version = max(array_map(static fn (string $f): int => (int) @filemtime($dir . $f), ['admin.html', 'eb80-shim.js', 'eb80-after.js', 'eb80-theme.css']));
    $frame = '/packs/' . $plugin['id'] . '/assets/eb80/admin.html?v=' . $version;
    ?>
<style>
  .wrap:has(> #eb80-frame) { max-width: none; padding: 0; }
  section.tight:has(#eb80-frame) { padding: 0; }
  #eb80-frame { display: block; width: 100%; border: 0; min-height: 480px; background: var(--bg); }
</style>
<script>window.COM_EB80 = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<iframe id="eb80-frame" src="<?= h($frame) ?>" title="<?= h($title) ?>"></iframe>
<script>
  // Le cadre occupe la hauteur restante de la fenêtre (l'outil d'electroboy80 défile à l'intérieur).
  (function () {
    var f = document.getElementById('eb80-frame');
    function fit() { f.style.height = Math.max(480, window.innerHeight - f.getBoundingClientRect().top) + 'px'; }
    window.addEventListener('resize', fit);
    fit();
  })();
</script>
    <?php
}
