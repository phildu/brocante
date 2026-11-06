<?php
/**
 * Gabarits HTML du module Impression (administration uniquement).
 * Réutilise les composants génériques de assets/buvette.css (cartes, tableaux,
 * badges, formulaires) + quelques styles propres dans assets/impression.css.
 */

require_once __DIR__ . '/impression.php';

/** Session + droits admin + schéma : à appeler en tête de chaque page admin/impression/. */
function pr_admin_init(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_admin();
    pr_boot();
}

function pr_admin_sections(): array
{
    return [
        'commandes' => ['/admin/impression/index.php', 'Commandes'],
        'nouvelle' => ['/admin/impression/nouvelle.php', 'Nouvelle commande'],
        'catalogue' => ['/admin/impression/catalogue.php', 'Catalogue & références'],
        'fournisseurs' => ['/admin/impression/fournisseurs.php', 'Fournisseurs & clés API'],
    ];
}

function pr_asset_url(string $path): string
{
    $file = dirname(__DIR__) . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function pr_admin_header(string $title, string $section): void
{
    $content = get_content();
    $flash = flash_get();
    $adminNav = 'impression';
    $siteName = $content['site_name'] ?? '';
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — Impression — <?= h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="<?= h(pr_asset_url('/assets/buvette.css')) ?>">
<link rel="stylesheet" href="<?= h(pr_asset_url('/assets/impression.css')) ?>">
</head>
<body class="bv-admin pr-admin">
<?php include __DIR__ . '/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <nav class="bv-subnav" aria-label="Sections du module Impression">
      <?php foreach (pr_admin_sections() as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
      <span class="pr-mode <?= pr_is_test() ? 'is-test' : 'is-live' ?>" title="<?= pr_is_test() ? 'Mode test : rien n\'est produit ni facturé' : 'Mode live : les commandes sont réellement produites et facturées' ?>">
        <?= pr_is_test() ? 'MODE TEST' : 'MODE LIVE' ?>
      </span>
    </nav>

    <h1 class="bv-title"><?= h($title) ?></h1>

    <?php if ($flash): ?>
      <p class="publish-status bv-flash" role="status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p>
    <?php endif; ?>
<?php
}

function pr_admin_footer(): void
{
    ?>
  </div>
</section>
</main>
<script>
// Confirmation avant les actions engageantes : <form data-confirm="…">
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});
</script>
</body>
</html>
<?php
}

/** Redirection après POST (PRG) avec message flash. */
function pr_redirect(string $url, ?string $message = null, string $kind = 'ok'): void
{
    if ($message !== null) flash_set($message, $kind);
    header('Location: ' . $url);
    exit;
}

/** Ouverture d'un formulaire POST protégé par CSRF. */
function pr_form_open(string $action, array $hidden = [], string $confirm = '', string $class = '', bool $multipart = false): string
{
    $html = '<form method="post"' . ($multipart ? ' enctype="multipart/form-data"' : '')
        . ($class !== '' ? ' class="' . h($class) . '"' : '')
        . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . csrf_input()
        . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    return $html;
}

/** Début de traitement d'un POST : CSRF + action demandée ('' si GET). */
function pr_post_action(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';
    require_csrf();
    return (string) ($_POST['action'] ?? '');
}

/** Avertissements de configuration affichés en haut du tableau de bord. */
function pr_config_warnings(): array
{
    $w = [];
    $test = pr_is_test();
    $connected = array_filter(pr_suppliers_list(true), fn($s) => $s['adapter'] !== '' && ($p = pr_provider($s['adapter'])) && $p->isConfigured($test));
    if (!$connected) {
        $w[] = 'Aucune clé API ' . ($test ? 'de test' : 'live') . ' renseignée : la comparaison automatique de devis est impossible. <a href="/admin/impression/fournisseurs.php#cles">Renseigner une clé</a>.';
    }
    if (!pr_site_is_public()) {
        $w[] = 'SITE_URL (' . h(defined('SITE_URL') ? SITE_URL : '?') . ') n\'est pas une adresse publique en https : les fournisseurs ne pourront pas télécharger vos fichiers. En local, vous pouvez comparer des devis ; pour commander, utilisez le site en ligne.';
    }
    return $w;
}

/** Lien http(s) sûr pour un href ('' sinon) : jamais de javascript: ni data: venant d'une API. */
function pr_safe_url(?string $u): string
{
    $u = trim((string) $u);
    return preg_match('#^https?://[^\s<>"\']+$#i', $u) ? $u : '';
}
