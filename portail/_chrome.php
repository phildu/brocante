<?php
// Coquille d'administration commune aux pages du portail : navigation groupée,
// barre supérieure, en-tête de page et recherche globale (⌘K).
// Appelée à la place de l'ancien <header class="topbar"> de chaque page.

require_once __DIR__ . '/_bootstrap.php';

/** Pictogrammes de l'administration (tracés SVG 24×24). */
function portail_chrome_icons(): array
{
    return [
        'gauge' => '<path d="M3.5 18a8.5 8.5 0 1 1 17 0"/><path d="m12 14 4-4"/><circle cx="12" cy="15" r="1.5"/>',
        'activity' => '<path d="M3 12h4l2-7 4 14 2-7h6"/>',
        'inbox' => '<path d="M3 13h5l2 3h4l2-3h5"/><path d="M5 4h14l2 9v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/>',
        'puzzle' => '<path d="M10 3h4v2.5a1.5 1.5 0 0 0 3 0V3h4v7h-2.5a1.5 1.5 0 0 0 0 3H21v8h-7v-2.5a1.5 1.5 0 0 0-3 0V21H3v-8h2.5a1.5 1.5 0 0 0 0-3H3V3z"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'gallery' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 16 9 5 9-5"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z"/>',
        'megaphone' => '<path d="M3 11v2a1 1 0 0 0 1 1h2l4 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M14 8a5 5 0 0 1 0 8M17 5a9 9 0 0 1 0 14"/>',
        'tag' => '<path d="M20 12 12 20 4 12V4h8z"/><circle cx="8.5" cy="8.5" r="1.2"/>',
        'percent' => '<path d="M19 5 5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
        'sparkles' => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 16l.9 2.1L22 19l-2.1.9L19 22l-.9-2.1L16 19l2.1-.9z"/>',
        'plug' => '<path d="M9 3v5M15 3v5"/><path d="M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v4"/>',
        'receipt' => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 4l4 4M14 6l4 4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'store' => '<path d="M4 9h16l-1-4H5z"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    ];
}

function portail_icon(string $name, int $size = 18): string
{
    $icons = portail_chrome_icons();
    $path = $icons[$name] ?? $icons['puzzle'];
    return '<svg class="pico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

/** Nombre de demandes d'inscription encore à traiter (affiché en pastille). */
function portail_pending_inscriptions(): int
{
    static $count = null;
    if ($count !== null) return $count;
    $count = 0;
    try {
        if (!function_exists('saas_requests')) require_once PORTAIL_ROOT . '/includes/saas.php';
        foreach (saas_requests() as $request) {
            if (($request['status'] ?? '') === 'pending') $count++;
        }
    } catch (Throwable $error) {
        $count = 0;
    }
    return $count;
}

/** Navigation principale groupée. */
function portail_nav_groups(): array
{
    return [
        ['label' => 'Pilotage', 'items' => [
            ['href' => '/portail/', 'label' => "Vue d'ensemble", 'icon' => 'gauge', 'match' => ['index.php']],
            ['href' => '/portail/activite.php', 'label' => 'Activité', 'icon' => 'activity'],
            ['href' => '/portail/inscriptions.php', 'label' => 'Inscriptions', 'icon' => 'inbox', 'badge' => portail_pending_inscriptions()],
        ]],
        ['label' => 'Boutiques', 'items' => [
            ['href' => '/portail/plugins.php', 'label' => 'Plugins', 'icon' => 'puzzle'],
            ['href' => '/portail/menu.php', 'label' => 'Menu latéral', 'icon' => 'menu'],
            ['href' => '/portail/galeries.php', 'label' => 'Galeries', 'icon' => 'gallery', 'match' => ['galeries.php', 'galerie-*.php']],
            ['href' => '/portail/offres-galerie.php', 'label' => 'Types de galerie', 'icon' => 'layers'],
            ['href' => '/portail/offres.php', 'label' => 'Vitrine & annuaire', 'icon' => 'globe'],
            ['href' => '/portail/promos.php', 'label' => 'Mises en avant', 'icon' => 'megaphone'],
        ]],
        ['label' => 'Revenus', 'items' => [
            ['href' => '/portail/tarifs.php', 'label' => 'Tarifs', 'icon' => 'tag'],
            ['href' => '/portail/commissions.php', 'label' => 'Commissions', 'icon' => 'percent'],
            ['href' => '/portail/ia.php', 'label' => 'Coûts IA', 'icon' => 'sparkles'],
            ['href' => '/portail/ia-fournisseurs.php', 'label' => 'Fournisseurs IA', 'icon' => 'plug'],
            ['href' => '/portail/addons.php', 'label' => 'Tarifs des plugins', 'icon' => 'receipt'],
        ]],
        ['label' => 'Plateforme', 'items' => [
            ['href' => '/portail/api.php', 'label' => 'API & réseaux', 'icon' => 'key'],
            ['href' => '/portail/nouveau.php', 'label' => 'Nouveau commerce', 'icon' => 'plus'],
        ]],
    ];
}

/** Pages proposées à la recherche globale (hors boutiques). */
function portail_palette_pages(): array
{
    $out = [];
    foreach (portail_nav_groups() as $group) {
        foreach ($group['items'] as $item) {
            $out[] = [
                'group' => $group['label'],
                'label' => $item['label'],
                'hint' => $group['label'],
                'href' => $item['href'],
                'icon' => $item['icon'],
            ];
        }
    }
    return $out;
}

/** Entrées de la recherche globale : pages utiles + boutiques. */
function portail_palette_data(): array
{
    $items = portail_palette_pages();
    try {
        foreach (tenant_list() as $slug => $shop) {
            $items[] = [
                'group' => 'Boutiques',
                'label' => (string) ($shop['name'] ?? $slug),
                'hint' => portail_shop_label($slug, $shop) . ' · ' . $slug,
                'href' => '/portail/?voir=' . rawurlencode($slug),
                'icon' => 'store',
            ];
        }
    } catch (Throwable $error) {
        // Liste des boutiques indisponible : la recherche reste utilisable.
    }
    return $items;
}

function portail_current_script(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
}

/** L'élément de navigation correspond-il à la page courante ? */
function portail_nav_active(array $item, string $current): bool
{
    $patterns = $item['match'] ?? [basename((string) parse_url($item['href'], PHP_URL_PATH))];
    foreach ($patterns as $pattern) {
        if (fnmatch($pattern, $current)) return true;
    }
    return false;
}

/** Libellé de section pour le fil d'Ariane. */
function portail_nav_section(string $current, array $groups): string
{
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            if (portail_nav_active($item, $current)) return $group['label'];
        }
    }
    return 'Portail';
}

/**
 * Ouvre la page : sidebar, barre supérieure, en-tête, recherche globale.
 * Options : title, eyebrow, desc, desc_html, actions (HTML), tabs (tableau ou HTML),
 *           nav_html (HTML, remplace la navigation), section (fil d'Ariane).
 */
function portail_page_start(array $opts = []): void
{
    $title = (string) ($opts['title'] ?? 'Administration');
    $eyebrow = (string) ($opts['eyebrow'] ?? '');
    $desc = (string) ($opts['desc'] ?? '');
    $descHtml = (string) ($opts['desc_html'] ?? '');
    $actions = (string) ($opts['actions'] ?? '');
    $tabs = $opts['tabs'] ?? null;
    $navHtml = (string) ($opts['nav_html'] ?? '');
    $limited = defined('GA_LIMITED');
    $current = portail_current_script();
    $groups = $limited ? [] : portail_nav_groups();
    $section = (string) ($opts['section'] ?? ($limited ? 'Galerie' : portail_nav_section($current, $groups)));

    global $portailLocal;
    $isLocal = !empty($portailLocal);
    $user = (string) ($_SESSION['portail_user'] ?? '');
    ?>
<script>(function(){document.body.classList.add('has-admin-shell');try{var t=localStorage.getItem('portail-theme');if(t){document.documentElement.dataset.theme=t;}}catch(e){}})();</script>
<aside class="pnav" id="pnav" aria-label="Navigation de l'administration">
  <a class="pnav-brand" href="/portail/">
    <span class="pnav-mark"><?= portail_icon($limited ? 'gallery' : 'store', 20) ?></span>
    <span class="pnav-brand-text"><b>brocs</b><small><?= $limited ? 'Espace galerie' : 'Administration' ?></small></span>
  </a>

  <div class="pnav-scroll">
    <?php if ($navHtml !== ''): ?>
      <div class="pnav-custom"><?= $navHtml ?></div>
    <?php else: ?>
      <nav aria-label="Navigation principale">
        <?php foreach ($groups as $group): ?>
          <div class="pnav-group">
            <p class="pnav-label"><?= e($group['label']) ?></p>
            <?php foreach ($group['items'] as $item):
                $active = portail_nav_active($item, $current);
                $badge = (int) ($item['badge'] ?? 0); ?>
              <a class="pnav-link<?= $active ? ' is-active' : '' ?>" href="<?= e($item['href']) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                <?= portail_icon((string) $item['icon']) ?>
                <span><?= e($item['label']) ?></span>
                <?php if ($badge > 0): ?><span class="pnav-badge" title="<?= $badge ?> en attente"><?= $badge ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </div>

  <div class="pnav-foot">
    <span class="pnav-env<?= $isLocal ? ' is-local' : '' ?>"><i aria-hidden="true"></i><?= $isLocal ? 'Local' : 'En ligne' ?></span>
    <div class="pnav-tools">
      <button type="button" class="pnav-tool" data-theme-toggle aria-label="Changer de thème" title="Thème clair / sombre"><?= portail_icon('moon', 16) ?></button>
      <a class="pnav-tool" href="/" target="_blank" rel="noopener" aria-label="Voir le site" title="Voir le site"><?= portail_icon('globe', 16) ?></a>
      <?php if ($user !== ''): ?>
        <a class="pnav-tool" href="/portail/logout.php" aria-label="Se déconnecter" title="Se déconnecter"><?= portail_icon('logout', 16) ?></a>
      <?php endif; ?>
    </div>
  </div>
</aside>
<div class="pnav-veil" data-nav-close hidden></div>

<header class="pbar">
  <button type="button" class="pbar-burger" data-nav-open aria-label="Ouvrir le menu"><?= portail_icon('menu', 20) ?></button>
  <p class="pbar-crumb"><span><?= e($section) ?></span><i aria-hidden="true">/</i><b><?= e($title) ?></b></p>
  <?php if (!$limited): ?>
    <button type="button" class="psearch" data-palette-open aria-label="Rechercher (Ctrl ou Cmd + K)">
      <?= portail_icon('search', 16) ?><span>Rechercher</span><kbd>⌘K</kbd>
    </button>
  <?php endif; ?>
  <div class="pbar-tools">
    <span class="penv<?= $isLocal ? ' is-local' : '' ?>"><?= $isLocal ? 'Local' : 'En ligne' ?></span>
    <button type="button" class="picon" data-theme-toggle aria-label="Changer de thème" title="Thème clair / sombre"><?= portail_icon('moon', 18) ?></button>
    <a class="picon" href="/" target="_blank" rel="noopener" aria-label="Voir le site" title="Voir le site"><?= portail_icon('globe', 18) ?></a>
    <?php if ($user !== ''): ?>
      <span class="puser" title="Connecté en tant que <?= e($user) ?>"><?= e(mb_strtoupper(mb_substr($user, 0, 1))) ?></span>
      <a class="picon" href="/portail/logout.php" aria-label="Se déconnecter" title="Se déconnecter"><?= portail_icon('logout', 18) ?></a>
    <?php endif; ?>
  </div>
</header>

<div class="phead">
  <div class="phead-text">
    <?php if ($eyebrow !== ''): ?><p class="phead-eyebrow"><?= e($eyebrow) ?></p><?php endif; ?>
    <h1><?= e($title) ?></h1>
    <?php if ($descHtml !== ''): ?><p class="phead-desc"><?= $descHtml ?></p>
    <?php elseif ($desc !== ''): ?><p class="phead-desc"><?= e($desc) ?></p><?php endif; ?>
  </div>
  <?php if ($actions !== ''): ?><div class="phead-actions"><?= $actions ?></div><?php endif; ?>
</div>

<?php if (is_array($tabs) && $tabs): ?>
  <nav class="ptabs" aria-label="Sections de la page">
    <?php foreach ($tabs as $tab): ?>
      <a class="btn<?= !empty($tab['active']) ? ' btn-primary' : '' ?>" href="<?= e((string) $tab['href']) ?>"<?= !empty($tab['active']) ? ' aria-current="page"' : '' ?>><?= e((string) $tab['label']) ?></a>
    <?php endforeach; ?>
  </nav>
<?php elseif (is_string($tabs) && $tabs !== ''): ?>
  <div class="ptabs"><?= $tabs ?></div>
<?php endif; ?>

<?php if (!$limited): ?>
<dialog class="palette" id="psearch" aria-label="Recherche globale">
  <div class="palette-inner">
    <div class="palette-search">
      <?= portail_icon('search', 18) ?>
      <input id="psearch-input" type="search" placeholder="Rechercher une page, une boutique…" autocomplete="off" spellcheck="false" aria-label="Recherche">
      <kbd>Échap</kbd>
    </div>
    <div class="palette-results" id="psearch-results"></div>
    <div class="palette-foot"><span>↑ ↓ naviguer</span><span>↵ ouvrir</span><span>Échap fermer</span></div>
  </div>
</dialog>
<script type="application/json" id="psearch-data"><?= json_encode(portail_palette_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<script src="/portail/admin.js?v=1" defer></script>
<?php
}
