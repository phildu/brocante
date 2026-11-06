<?php
/**
 * Gabarits HTML du module pour les pages hors du thème boutique :
 *  - pages admin (même squelette que les autres pages /admin/) ;
 *  - pages « terrain » des bénévoles (tournée, point de collecte), pensées pour
 *    un téléphone tenu à une main : gros boutons, peu de chrome.
 */

require_once __DIR__ . '/invendus.php';

function unsold_html_head(string $title, string $bodyClass = '', bool $noindex = false): void
{
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($noindex): ?><meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<title><?= h($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/dons.css">
</head>
<body<?= $bodyClass !== '' ? ' class="' . h($bodyClass) . '"' : '' ?>>
<?php
}

function unsold_flash_html(): void
{
    $flash = flash_get();
    if ($flash) {
        echo '<p class="publish-status" role="status" data-kind="' . h($flash['kind']) . '" style="margin:16px 0;">' . h($flash['message']) . '</p>';
    }
}

/** En-tête d'une page admin du module (nav + ouverture de <main>). */
function unsold_admin_head(string $title): void
{
    $content = get_content();
    $adminNav = 'invendus';
    unsold_html_head($title . ' — ' . ($content['site_name'] ?? ''));
    include __DIR__ . '/icons.php';
    include __DIR__ . '/admin-nav.php';
    echo "<main>\n";
}

function unsold_admin_foot(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/** En-tête d'une page terrain (bénévoles) : sans navigation du site, jamais indexée. */
function unsold_field_head(string $title): void
{
    unsold_html_head($title, 'don-field', true);
    include __DIR__ . '/icons.php';
}

function unsold_field_foot(): void
{
    echo "</body>\n</html>\n";
}

/** Réponse 404 minimale pour un lien secret inconnu (ne révèle rien). */
function unsold_field_not_found(string $what): void
{
    http_response_code(404);
    unsold_field_head('Lien introuvable');
    echo '<div class="don-wrap don-narrow" style="padding-top:40px;"><h1 style="font-size:1.6rem;">Lien introuvable</h1>'
        . '<p class="lede">' . h($what) . '</p></div>';
    unsold_field_foot();
    exit;
}

/** Champ caché + jeton CSRF commun aux formulaires admin du module. */
function unsold_admin_form_open(string $action, string $return, string $extraAttrs = ''): string
{
    return '<form method="post" action="/admin/invendus-action.php" ' . $extraAttrs . '>'
        . shop_csrf_input()
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="return" value="' . h($return) . '">';
}

/** Formulaire de création/modification d'une édition. */
function unsold_render_event_form(?array $e, string $return): void
{
    $v = fn(string $k, string $d = '') => $e[$k] ?? $d;
    $startT = $e ? substr($e['collect_start'], 11, 5) : '16:00';
    $endT = $e ? substr($e['collect_end'], 11, 5) : '18:30';
    echo unsold_admin_form_open('event_save', $return, 'class="don-form"');
    if ($e) echo '<input type="hidden" name="id" value="' . (int) $e['id'] . '">';
    ?>
    <div class="field-row-2">
      <div class="field"><label for="ev-name">Nom de l'édition *</label>
        <input type="text" id="ev-name" name="name" value="<?= h($v('name')) ?>" maxlength="120" required placeholder="ex : Vide-grenier de la Saint-Martin"></div>
      <div class="field"><label for="ev-date">Date du vide-grenier *</label>
        <input type="date" id="ev-date" name="event_date" value="<?= h($v('event_date')) ?>" required></div>
    </div>
    <div class="field"><label for="ev-loc">Lieu du vide-grenier</label>
      <input type="text" id="ev-loc" name="location" value="<?= h($v('location')) ?>" maxlength="160" placeholder="ex : Place de l'église, Saint-Martin"></div>
    <div class="field-row-2">
      <div class="field"><label for="ev-pname">Nom du point de collecte</label>
        <input type="text" id="ev-pname" name="point_name" value="<?= h($v('point_name', 'Point de collecte')) ?>" maxlength="80"></div>
      <div class="field-row-2" style="margin:0;">
        <div class="field"><label for="ev-start">Ouvre à *</label>
          <input type="time" id="ev-start" name="collect_start_time" value="<?= h($startT) ?>" required></div>
        <div class="field"><label for="ev-end">Ferme à *</label>
          <input type="time" id="ev-end" name="collect_end_time" value="<?= h($endT) ?>" required></div>
      </div>
    </div>
    <div class="field"><label for="ev-pdet">Où se trouve le point, comment le repérer</label>
      <textarea id="ev-pdet" name="point_details" maxlength="400" rows="2" placeholder="ex : sous le préau, à côté de la buvette — table avec nappe verte"><?= h($v('point_details')) ?></textarea></div>
    <div class="field-row-2">
      <div class="field"><label for="ev-status">Visibilité</label>
        <select id="ev-status" name="status">
          <?php foreach (['draft' => 'Brouillon (invisible du public)', 'published' => 'Publiée (propositions ouvertes)', 'closed' => 'Clôturée'] as $k => $l): ?>
            <option value="<?= $k ?>"<?= $v('status', 'draft') === $k ? ' selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="ev-notes">Notes internes</label>
        <textarea id="ev-notes" name="notes" maxlength="1000" rows="2"><?= h($v('notes')) ?></textarea></div>
    </div>
    <button type="submit" class="btn btn-primary"><?= $e ? 'Enregistrer' : 'Créer l\'édition' ?></button>
    </form>
    <?php
}

/** Champ en lecture seule avec bouton « Copier » (liens secrets à transmettre). */
function unsold_copy_field(string $label, string $url, string $hint = ''): void
{
    $id = 'cp' . substr(md5($url), 0, 8);
    echo '<div class="field"><label for="' . $id . '">' . h($label) . '</label>'
        . '<div class="don-link"><input type="text" id="' . $id . '" readonly value="' . h($url) . '">'
        . '<button type="button" class="btn-small" data-copy="' . $id . '">Copier</button></div>'
        . ($hint !== '' ? '<small class="don-muted">' . h($hint) . '</small>' : '') . '</div>';
}

/** Script des boutons « Copier » et de la case « tout cocher » (à inclure une fois par page). */
function unsold_admin_scripts(): void
{
    ?>
<script>
(function () {
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-copy'));
      if (!input) return;
      input.select();
      var done = function () { btn.textContent = 'Copié ✓'; setTimeout(function () { btn.textContent = 'Copier'; }, 2000); };
      if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); }); }
      else { document.execCommand('copy'); done(); }
    });
  });
  document.querySelectorAll('[data-check-all]').forEach(function (master) {
    master.addEventListener('change', function () {
      var scope = document.getElementById(master.getAttribute('data-check-all'));
      if (!scope) return;
      scope.querySelectorAll('input[type="checkbox"][name="ids[]"]').forEach(function (c) { c.checked = master.checked; });
    });
  });
})();
</script>
    <?php
}
