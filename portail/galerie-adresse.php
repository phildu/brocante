<?php
// Adresse de la galerie : adresse en sous-domaine (nom.domaine) payante, changement de nom,
// domaine déjà possédé ou domaine acheté chez OVH. Même parcours que pour une boutique
// (admin/domaine.php) : le contenu vient de includes/domain-panel.php, l'état est rangé par
// clé « g~<galerie> » (includes/domain-owner.php) et l'adresse en service est recopiée dans
// le champ « domain » de la galerie, seule source de gallery_from_host().
// Accès : exploitant, ou administrateur de CETTE galerie (voir _galerie-layout.php).
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/domain-panel.php';

$q = $limited ? '' : '?g=' . urlencode($gaSlug);
$self = '/portail/galerie-adresse.php' . $q;
$key = domain_owner_gallery_key($gaSlug);

// Retour de Stripe : la session est relue chez Stripe, jamais crue sur parole.
if (isset($_GET['s'])) {
    [$message, $kind] = domain_panel_apply_return((string) $_GET['s']);
    portail_flash($message, $kind);
    header('Location: ' . $self, true, 303);
    exit;
}

$panel = domain_panel_context($key, [
    'address' => gallery_public_url($gaGallery, $gaOrigin),
    'name' => (string) $gaGallery['name'],
    'draft' => $gaSlug,
    'identity' => ['company' => (string) $gaGallery['name'], 'phone' => '', 'email' => domain_owner_email($key)],
    'owner_email' => domain_owner_email($key),
    'action' => '/portail/galerie-adresse-action.php',
    'self_path' => '/portail/galerie-adresse.php',
    'self_query' => $limited ? [] : ['g' => $gaSlug],
    'hidden' => $limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">',
    'csrf' => csrf_token(),
]);

$css = '<style>.ga-page{--surface:var(--panel);--ink-soft:var(--muted);--font-body:var(--font-ui)}'
    . dm_css()
    . '.dm-card .btn-small{display:inline-block;padding:3px 10px;border:1px solid var(--line);border-radius:6px;font-size:.8rem;color:var(--ink);text-decoration:none}'
    . '</style>';

ga_head('Adresse', '', $css);
?>
    <h1 style="font-size:1.4rem;margin:0 0 8px;">Adresse de la galerie</h1>
    <p class="hint">Donnez à votre galerie une adresse courte (<code>votre-nom.<?= e(domain_sub_base_host()) ?></code>), ou achetez / rattachez un nom de domaine à vous (par exemple <code>ma-galerie.fr</code>). L’adresse historique <code><?= e(domain_owner_legacy_path($key)) ?></code> continue de fonctionner. Rien n’est facturé avant la confirmation du paiement.</p>
<?php domain_panel_render($panel); ?>
<?php ga_foot(); ?>
