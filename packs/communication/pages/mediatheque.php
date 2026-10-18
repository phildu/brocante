<?php
// Médiathèque du pack Communication : celle d'electroboy80, portée telle quelle (assets/eb80/admin.html, recopiée par
// tools/extract-eb80.php) et affichée dans un cadre, sous le menu de la boutique. La page pose la configuration que lit
// eb80-shim.js : adresse de l'API portée (route eb80), jeton CSRF, dossier d'envoi de la boutique, pages autorisées.
defined('PLUGIN_RUNTIME') || exit;
$eb80 = [
    'api' => plugin_api_url($plugin['id'], 'eb80'),
    'csrf' => $pluginCsrf,
    'uploads' => '/uploads/' . tenant_slug() . '/',
    'start' => 'media',
    'allowed' => ['media'],
    'role' => $role,
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
<script>window.COM_EB80 = <?= json_encode($eb80, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<iframe id="eb80-frame" src="<?= h($frame) ?>" title="Médiathèque"></iframe>
<script>
  // Le cadre occupe la hauteur restante de la fenêtre (la médiathèque d'electroboy80 défile à l'intérieur).
  (function () {
    var f = document.getElementById('eb80-frame');
    function fit() { f.style.height = Math.max(480, window.innerHeight - f.getBoundingClientRect().top) + 'px'; }
    window.addEventListener('resize', fit);
    fit();
  })();
</script>
