<?php
// Logo et favicon du portail : les mêmes trois déclinaisons que le logo d'une boutique (horizontal, vertical, carré) et l'icône d'onglet.
// Réglés ici, enregistrés dans data/saas.json (clés « logos » et « favicon », includes/saas.php) et déposés dans uploads/saas/.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/appearance.php';

const IDENTITE_KINDS = ['horizontal', 'vertical', 'square', 'favicon'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cfg = saas_config();
    $errors = [];
    $created = [];   // fichiers écrits par cet envoi : supprimés si l'enregistrement échoue
    $removed = [];   // anciens fichiers : supprimés une fois l'enregistrement réussi
    $changed = 0;
    foreach (IDENTITE_KINDS as $kind) {
        $current = $kind === 'favicon' ? (string) $cfg['favicon'] : (string) ($cfg['logos'][$kind] ?? '');
        $new = null;
        $file = $_FILES['img_' . $kind] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $new = saas_brand_store($kind, $file);
                $created[] = $new;
            } catch (Throwable $ex) {
                $errors[] = ucfirst($kind === 'favicon' ? 'favicon' : 'logo ' . mb_strtolower(LOGO_VARIANTS[$kind][0])) . ' : ' . $ex->getMessage();
                continue;
            }
        } elseif (!empty($_POST['reset_' . $kind])) {
            $new = '';
            if ($kind === 'horizontal' && (string) $cfg['logo'] !== '') {   // l'ancien logo unique valait « horizontal »
                $removed[] = (string) $cfg['logo'];
                $cfg['logo'] = '';
                $changed++;
            }
        } else {
            continue;
        }
        if ($current !== '') $removed[] = $current;
        if ($kind === 'favicon') {
            $cfg['favicon'] = $new;
        } elseif ($new === '') {
            unset($cfg['logos'][$kind]);
        } else {
            $cfg['logos'][$kind] = $new;
        }
        $changed++;
    }
    if ($changed > 0) {
        if (saas_config_save($cfg)) {
            foreach ($removed as $old) {
                if (!in_array($old, [$cfg['logo'], $cfg['favicon'], ...array_values($cfg['logos'])], true)) {
                    preg_match(SAAS_BRAND_PATH, $old) ? saas_brand_delete($old) : saas_banner_delete($old);
                }
            }
            portail_flash($errors ? implode(' ', $errors) . ' Le reste est enregistré.' : 'Logo et favicon enregistrés : ils s’appliquent immédiatement.', $errors ? 'error' : 'ok');
        } else {
            foreach ($created as $path) saas_brand_delete($path);
            portail_flash("Impossible d'écrire data/saas.json (droits du dossier data).", 'error');
        }
    } else {
        portail_flash($errors ? implode(' ', $errors) : 'Aucun changement : choisissez un fichier ou cochez « Revenir à l’image d’origine ».', $errors ? 'error' : 'ok');
    }
    header('Location: /portail/identite.php');
    exit;
}

$cfg = saas_config();
$flash = portail_flash();
$usage = [
    'horizontal' => "Réservé aux en-têtes larges (documents, e-mails). Sans fichier, l'ancien logo unique de « Vitrine & annuaire » sert de repli.",
    'vertical' => "Réservé aux écrans hauts (accueil mobile). Sans fichier, le carré est utilisé à sa place.",
    'square' => "Menu du portail, connexion et en-tête du site public. Sert aussi d'icône d'onglet tant qu'aucun favicon n'est envoyé.",
];
$state = static function (string $kind) use ($cfg): string {
    $custom = $kind === 'favicon' ? (string) $cfg['favicon'] : (string) ($cfg['logos'][$kind] ?? '');
    if ($custom !== '') return 'personnalisé';
    if ($kind === 'horizontal' && (string) $cfg['logo'] !== '') return 'ancien logo unique';
    if ($kind === 'vertical' && (string) ($cfg['logos']['square'] ?? '') !== '') return 'carré utilisé à la place';
    if ($kind === 'favicon' && (string) ($cfg['logos']['square'] ?? '') !== '') return 'logo carré utilisé';
    return 'image d’origine (étal bricbrok)';
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Logo &amp; favicon — Portail</title>
<?= saas_favicon_html($cfg) ?>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .id-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 14px; align-items: start; }
  @media (max-width: 760px) { .id-grid { grid-template-columns: 1fr 1fr; } .id-horizontal { grid-column: 1 / -1; } }
  .id-card { display: flex; flex-direction: column; gap: 6px; }
  .id-frame { display: grid; place-items: center; padding: 10px; border: 1px solid var(--line); border-radius: 10px;
    background: repeating-conic-gradient(#e6e1d6 0 25%, #f6f2ea 0 50%) 0 0 / 16px 16px; }
  .id-horizontal .id-frame { aspect-ratio: 4 / 1.4; } .id-vertical .id-frame { aspect-ratio: 3 / 4; } .id-square .id-frame { aspect-ratio: 1; }
  .id-frame img { max-width: 100%; max-height: 100%; object-fit: contain; }
  .id-card small { color: var(--muted); line-height: 1.35; }
  .id-card .id-state { font-family: var(--font-mono, monospace); font-size: .72rem; }
  .id-reset { display: flex; gap: 6px; align-items: center; font-size: .82rem; color: var(--muted); }
  .id-fav { display: flex; gap: 18px; align-items: center; flex-wrap: wrap; }
  .id-fav .id-frame { width: 96px; height: 96px; } .id-fav .id-small { display: flex; align-items: center; gap: 10px; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Logo & favicon',
    'eyebrow' => 'Plateforme',
    'desc' => 'Le logo de la plateforme en trois déclinaisons, comme celui d’une boutique, et l’icône de l’onglet du navigateur.',
    'section' => 'Plateforme',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <form class="send" method="post" action="/portail/identite.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <h2>Logo</h2>
    <p class="hint">PNG ou SVG sur fond transparent de préférence ; JPG ou WebP acceptés (3 Mo au maximum). Tant qu’aucune image n’est envoyée, l’étal bricbrok d’origine est utilisé.</p>
    <div class="id-grid">
      <?php foreach (LOGO_VARIANTS as $kind => [$title, , $format]): $custom = (string) ($cfg['logos'][$kind] ?? '') !== ''; ?>
        <div class="id-card id-<?= e($kind) ?>">
          <div class="id-frame"><img id="img-<?= e($kind) ?>" src="/<?= e(saas_brand_image($cfg, $kind)) ?>" alt="Logo <?= e(mb_strtolower($title)) ?> actuel"></div>
          <b><?= e($title) ?></b>
          <small><?= e($usage[$kind]) ?></small>
          <small class="id-state"><?= e($format) ?> · <?= e($state($kind)) ?></small>
          <input type="file" name="img_<?= e($kind) ?>" accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg" data-preview="img-<?= e($kind) ?>">
          <?php if ($custom || ($kind === 'horizontal' && (string) $cfg['logo'] !== '')): ?>
            <label class="id-reset"><input type="checkbox" name="reset_<?= e($kind) ?>" value="1"> Revenir à l’image d’origine</label>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <h2 style="margin-top:22px;">Favicon</h2>
    <p class="hint">L’icône de l’onglet du navigateur et des favoris. PNG (32 × 32 px ou plus, carré), SVG ou ICO. Sans favicon, le logo carré est utilisé.</p>
    <div class="id-fav">
      <div class="id-card">
        <div class="id-frame"><img id="img-favicon" src="/<?= e(saas_brand_image($cfg, 'favicon')) ?>" alt="Favicon actuel"></div>
        <small class="id-state"><?= e($state('favicon')) ?></small>
      </div>
      <div class="id-card">
        <div class="id-small"><img id="img-favicon-16" src="/<?= e(saas_brand_image($cfg, 'favicon')) ?>" width="16" height="16" alt=""><span>aperçu dans un onglet</span></div>
        <input type="file" name="img_favicon" accept="image/png,image/svg+xml,image/x-icon,.ico,.svg" data-preview="img-favicon" data-preview2="img-favicon-16">
        <?php if ((string) $cfg['favicon'] !== ''): ?><label class="id-reset"><input type="checkbox" name="reset_favicon" value="1"> Revenir à l’image d’origine</label><?php endif; ?>
      </div>
    </div>

    <div class="send-actions" style="margin-top:16px;">
      <button class="btn btn-primary" type="submit">Enregistrer</button>
      <span class="hint">Le favicon peut mettre un moment à changer dans le navigateur (il le garde en cache).</span>
    </div>
  </form>
</div>
<script>
(function () {
  document.querySelectorAll('input[type=file][data-preview]').forEach(function (input) {
    input.addEventListener('change', function () {
      var f = input.files[0];
      if (!f) return;
      var url = URL.createObjectURL(f);
      [input.dataset.preview, input.dataset.preview2].forEach(function (id) {
        var img = id && document.getElementById(id);
        if (img) img.src = url;
      });
    });
  });
})();
</script>
</body>
</html>
