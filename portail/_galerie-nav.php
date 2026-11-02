<?php
// Boutons de navigation d'une galerie. Variables attendues : $limited (slug en mode restreint), $edit (galeries.php) ou $gaGallery (pages galerie-*).
$navSlug = $limited ?: (isset($gaGallery) ? $gaGallery['slug'] : (isset($edit) && $edit ? $edit['slug'] : ''));
$q = $limited ? '' : '?g=' . urlencode($navSlug);
$here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$navBtn = static function (string $file, string $label) use ($q, $here): void {
    echo '<a class="btn' . ($here === $file ? ' btn-primary' : '') . '" href="/portail/' . $file . $q . '">' . e($label) . '</a>';
};
$navBtn('galeries.php', 'Présentation et commerces');
$navG = $gaGallery ?? ($edit ?? null);
$navOn = static fn (string $f): bool => !$navG || gt_feature($navG, $f);        // fonctionnalités du type de galerie (Types de galerie, portail)
$navMod = static fn (string $m): bool => (bool) $navG && function_exists('gm_enabled') && gm_enabled($navG, $m);
// Toujours proposé quand le type de galerie comprend un plan : la page explique comment activer l'option si elle est en veille.
if ($navOn('plan')) $navBtn('galerie-plan.php', 'Plan');
if ($navOn('exposants')) $navBtn('galerie-exposants.php', $navG ? ucfirst(gt_word($navG, 'members')) : 'Exposants');
if ($navG && $navMod('organisateur') && $navOn('exposants')) {
    $navBtn('galerie-evenement.php', 'Événement');
    $navBtn('galerie-inscriptions.php', 'Inscriptions');
    $navBtn('galerie-registre.php', 'Registre');
}
if ($navG && $navMod('attente') && $navOn('exposants')) $navBtn('galerie-attente.php', "File d'attente");
if ($navOn('bourse')) $navBtn('galerie-bourse.php', 'Bourse d’échanges');
if ($navOn('tableau')) $navBtn('galerie-tableau.php', 'Tableau de bord');
if ($navOn('prospects')) $navBtn('galerie-prospects.php', 'Prospects');
if ($navOn('fidelite')) $navBtn('galerie-fidelite.php', 'Fidélité visiteurs');
$navBtn('galerie-assistant.php', 'Assistant');
$navBtn('galerie-offre.php', 'Mon offre');
if ($navG && function_exists('gm_modules')) $navBtn('galerie-modules.php', 'Options');
if ($limited) {
    $navBtn('galerie-compte.php', 'Mon compte');
    if (empty($_SESSION['ga_csrf'])) $_SESSION['ga_csrf'] = bin2hex(random_bytes(16));
    $me = ga_current();
    if ($me && count($me['slugs']) > 1) {
        echo '<form method="get" style="display:inline;"><select name="g" onchange="this.form.submit()" aria-label="Galerie">';
        foreach ($me['slugs'] as $s) echo '<option value="' . e($s) . '"' . ($s === $limited ? ' selected' : '') . '>' . e(gallery_load($s)['name'] ?? $s) . '</option>';
        echo '</select></form>';
    }
    echo '<form method="post" action="/galerie/admin.php" style="display:inline;"><input type="hidden" name="csrf" value="' . e($_SESSION['ga_csrf']) . '"><input type="hidden" name="action" value="logout"><button class="btn" type="submit">Se déconnecter</button></form>';
}
