<?php
// Fournisseurs d'IA de la plateforme : texte, image et vidéo. On choisit le service actif de chaque catégorie, on ajoute
// d'autres fournisseurs (compatibles OpenAI pour le texte et l'image, compatibles SiliconFlow pour la vidéo, ou Claude),
// et on décide si l'IA est proposée à chaque boutique. Voir includes/ai-providers.php.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/ai-providers.php';

$shops = tenant_list();

/** Fournisseur enregistré d'une catégorie, par identifiant. */
function iaf_provider(string $category, string $id): ?array
{
    foreach (ai_provider_rows($category) as $row) if ($row['id'] === $id) return $row;
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $category = (string) ($_POST['category'] ?? '');

    if ($action === 'settings') {
        $enabled = !empty($_POST['enabled']);
        $overrides = [];
        foreach ((array) ($_POST['shop'] ?? []) as $slug => $value) {
            if (!isset($shops[$slug]) || !in_array((string) $value, ['0', '1'], true)) continue;
            $overrides[$slug] = $value === '1';
        }
        $ok = ai_provider_update(static function (array $s) use ($enabled, $overrides): array {
            $s['enabled'] = $enabled;
            $s['shops'] = $overrides;
            return $s;
        });
        portail_flash($ok ? 'Réglage enregistré : les boutiques concernées appliquent aussitôt.' : "Impossible d'écrire data/saas/ai-providers.json (droits sur data/).", $ok ? 'ok' : 'error');
    } elseif ($action === 'save') {
        if (!isset(AI_PROVIDER_CATEGORIES[$category])) {
            portail_flash('Catégorie inconnue.', 'error');
        } else {
            $postedId = trim((string) ($_POST['id'] ?? ''));
            $label = trim((string) ($_POST['label'] ?? ''));
            if ($label === '') {
                portail_flash('Donnez un nom au fournisseur.', 'error');
                header('Location: /portail/ia-fournisseurs.php');
                exit;
            }
            $rows = ai_provider_rows($category);
            $builtins = [];
            foreach (ai_provider_builtins()[$category] as $b) $builtins[$b['id']] = $b;
            if ($postedId !== '' && !ai_provider_id_ok($postedId)) {
                portail_flash('Identifiant de fournisseur non valide.', 'error');
                header('Location: /portail/ia-fournisseurs.php');
                exit;
            }
            $id = $postedId !== '' ? $postedId : ai_provider_new_id($category, $label, array_map(static fn (array $r): string => (string) $r['id'], $rows));
            $builtin = isset($builtins[$id]);
            $driver = (string) ($_POST['driver'] ?? '');
            // Un fournisseur ajouté ne reprend pas un connecteur qui lit la clé partagée d'un fournisseur fourni au départ.
            $addDrivers = AI_PROVIDER_DRIVERS[$category];
            if ($category === 'video') unset($addDrivers['veo'], $addDrivers['modal']); else unset($addDrivers['gemini']);
            if (!$builtin && !isset($addDrivers[$driver])) $driver = (string) array_key_first($addDrivers);
            if (!isset(AI_PROVIDER_DRIVERS[$category][$driver])) $driver = array_key_first(AI_PROVIDER_DRIVERS[$category]);
            $row = [
                'id' => $id, 'label' => mb_substr($label, 0, 60), 'driver' => $driver,
                'base_url' => rtrim(trim((string) ($_POST['base_url'] ?? '')), '/'),
                'model' => trim((string) ($_POST['model'] ?? '')),
                'enabled' => !empty($_POST['enabled']),
            ];
            if ($builtin) $row['builtin'] = true;
            $key = trim((string) ($_POST['key'] ?? ''));
            if (!$builtin && $key !== '' && !ai_provider_secret_store(ai_provider_custom_secret($category, $id), $key)) {
                portail_flash("Clé non enregistrée : le dossier .secrets/_partage n'est pas accessible en écriture.", 'error');
                header('Location: /portail/ia-fournisseurs.php');
                exit;
            }
            $ok = ai_provider_update(static function (array $s) use ($category, $id, $row, $builtin): array {
                $current = $s['providers'][$category] ?? null;
                if (!is_array($current) || !$current) $current = ai_provider_builtins()[$category];
                $found = false;
                foreach ($current as &$r) {
                    if (is_array($r) && (string) ($r['id'] ?? '') === $id) { $r = $row; $found = true; break; }
                }
                unset($r);
                if (!$found) $current[] = $row;
                $s['providers'][$category] = array_values($current);
                if (empty($s['active'][$category]) && $row['enabled']) $s['active'][$category] = $id;
                return $s;
            });
            portail_flash($ok ? 'Fournisseur « ' . $row['label'] . ' » enregistré.' : "Impossible d'écrire data/saas/ai-providers.json.", $ok ? 'ok' : 'error');
        }
    } elseif ($action === 'activate') {
        $provider = iaf_provider($category, (string) ($_POST['id'] ?? ''));
        if (!$provider) {
            portail_flash('Fournisseur introuvable.', 'error');
        } elseif (!$provider['enabled']) {
            portail_flash('Ce fournisseur est désactivé : activez-le avant de l\'utiliser.', 'error');
        } else {
            ai_provider_update(static function (array $s) use ($category, $provider): array {
                $s['active'][$category] = (string) $provider['id'];
                return $s;
            });
            portail_flash('« ' . $provider['label'] . ' » est maintenant le fournisseur ' . mb_strtolower(AI_PROVIDER_CATEGORIES[$category]) . ' utilisé par les boutiques.');
        }
    } elseif ($action === 'test') {
        $provider = iaf_provider($category, (string) ($_POST['id'] ?? ''));
        if (!$provider) {
            portail_flash('Fournisseur introuvable.', 'error');
        } else {
            $res = ai_provider_test($provider);
            portail_flash(($res['ok'] ? 'Test réussi — ' : 'Test échoué — ') . $res['message'], $res['ok'] ? 'ok' : 'error');
        }
    } elseif ($action === 'delete') {
        $provider = iaf_provider($category, (string) ($_POST['id'] ?? ''));
        if (!$provider) {
            portail_flash('Fournisseur introuvable.', 'error');
        } elseif ($provider['builtin']) {
            portail_flash('Les fournisseurs fournis au départ ne se suppriment pas : décochez « Proposé » pour les retirer.', 'error');
        } else {
            ai_provider_secret_delete($provider);
            ai_provider_update(static function (array $s) use ($category, $provider): array {
                $current = $s['providers'][$category] ?? null;
                if (!is_array($current)) $current = ai_provider_builtins()[$category];
                $current = array_values(array_filter($current, static fn ($r): bool => !is_array($r) || (string) ($r['id'] ?? '') !== $provider['id']));
                $s['providers'][$category] = $current;
                if (($s['active'][$category] ?? '') === $provider['id']) unset($s['active'][$category]);
                return $s;
            });
            portail_flash('Fournisseur « ' . $provider['label'] . ' » supprimé (sa clé aussi).');
        }
    } elseif ($action === 'reset') {
        if (is_file(ai_provider_file())) @unlink(ai_provider_file());
        portail_flash('Réglages remis à ceux d\'origine : Gemini, Claude, Veo, SiliconFlow et Modal, IA proposée à toutes les boutiques.');
    }
    header('Location: /portail/ia-fournisseurs.php');
    exit;
}

$settings = ai_provider_read();
$platformEnabled = !array_key_exists('enabled', $settings) || (bool) $settings['enabled'];
$flash = portail_flash();
$driverDefaults = [
    'text|gemini' => ['https://generativelanguage.googleapis.com/v1beta', 'gemini-3.8-flash'],
    'text|openai' => ['https://api.openai.com/v1', 'gpt-4o-mini'],
    'text|anthropic' => ['https://api.anthropic.com/v1', 'claude-sonnet-4-5'],
    'image|gemini' => ['https://generativelanguage.googleapis.com/v1beta', 'gemini-2.5-flash-image'],
    'image|openai' => ['https://api.openai.com/v1', 'gpt-image-1'],
    'video|veo' => ['https://generativelanguage.googleapis.com/v1beta', ''],
    'video|siliconflow' => ['https://api.siliconflow.com/v1', 'Wan-AI/Wan2.2-I2V-A14B'],
    'video|modal' => ['', ''],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Fournisseurs IA — Portail des commerces</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .ia { display: grid; gap: 18px; max-width: 1080px; margin: 0 auto; padding: 0 16px 56px; }
  .ia-card { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; min-width: 0; }
  .ia-card > h2 { margin: 0; font-size: 1.1rem; } .ia-card h2 small, .ia-card .hint { font-weight: 400; color: var(--muted); font-size: .84rem; }
  .hint { color: var(--muted); font-size: .84rem; margin: 0; }
  .prov { border: 1px solid var(--line); border-radius: 9px; padding: 12px; display: grid; gap: 10px; background: var(--bg); }
  .prov.is-used { border-color: var(--accent); }
  .prov-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .prov-head h3 { margin: 0; font-size: .98rem; }
  .pill { display: inline-block; padding: 1px 9px; border-radius: 999px; border: 1px solid var(--line); font-size: .74rem; font-weight: 700; white-space: nowrap; }
  .pill.used { color: var(--ok); border-color: currentColor; } .pill.off { color: var(--muted); } .pill.warn { color: #b3261e; border-color: currentColor; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
  .f { display: grid; gap: 4px; font-size: .8rem; font-weight: 600; } .f small { font-weight: 400; color: var(--muted); }
  input[type=text], input[type=password], select { padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; box-sizing: border-box; }
  .check { display: flex; gap: 8px; align-items: center; font-size: .84rem; font-weight: 600; }
  .actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
  .shops { display: grid; gap: 8px; } .shops .row { display: grid; grid-template-columns: minmax(180px, 1fr) 220px; gap: 10px; align-items: center; font-size: .88rem; }
  .key-state { font-family: 'IBM Plex Mono', monospace; font-size: .74rem; color: var(--muted); }
  @media (max-width: 560px) { .shops .row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Fournisseurs IA',
    'eyebrow' => 'Revenus',
    'desc' => "Texte, image et vidéo : service utilisé, clés, et boutiques pour qui l'IA reste invisible.",
    'actions' => '<a class="btn" href="/portail/ia.php">Coûts IA</a><a class="btn" href="/portail/api.php?s=cles">Clés API partagées</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="ia">
    <section class="ia-card">
      <h2>1. L'IA est-elle proposée ? <small>optionnelle, boutique par boutique</small></h2>
      <p class="hint">Désactivée, une boutique n'émet plus aucun appel payant et ne voit plus les écrans d'IA dans son administration (pastille de consommation, entrée de menu, onglet du tableau de bord, boutons de génération). Réglage appliqué immédiatement.</p>
      <form method="post" autocomplete="off" class="ia-card" style="border:0;padding:0;gap:12px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings">
        <label class="check"><input type="checkbox" name="enabled" value="1"<?= $platformEnabled ? ' checked' : '' ?>> Proposer les fonctions d'IA par défaut</label>
        <div class="shops">
          <?php foreach ($shops as $slug => $shop): $setting = ai_shop_setting($slug); ?>
            <div class="row">
              <span><strong><?= e($shop['name']) ?></strong> <span class="key-state"><?= e($slug) ?></span></span>
              <select name="shop[<?= e($slug) ?>]" aria-label="IA pour <?= e($shop['name']) ?>">
                <option value=""<?= $setting === null ? ' selected' : '' ?>>Comme la plateforme (<?= $platformEnabled ? 'proposée' : 'désactivée' ?>)</option>
                <option value="1"<?= $setting === true ? ' selected' : '' ?>>IA proposée</option>
                <option value="0"<?= $setting === false ? ' selected' : '' ?>>IA désactivée</option>
              </select>
            </div>
          <?php endforeach; ?>
          <?php if (!$shops): ?><p class="hint">Aucune boutique sur ce serveur pour le moment.</p><?php endif; ?>
        </div>
        <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
      </form>
    </section>

    <?php foreach (AI_PROVIDER_CATEGORIES as $category => $categoryLabel): $rows = ai_provider_rows($category); $selected = ai_provider_selected($category); ?>
      <section class="ia-card">
        <h2><?= e($categoryLabel) ?> <small>un fournisseur utilisé, d'autres disponibles</small></h2>
        <p class="hint"><?= $category === 'text' ? 'Rédaction des fiches, descriptions, analyses de photos.' : ($category === 'image' ? 'Mise en situation, visuels générés, détourage par image.' : 'Animation des photos de produit.') ?>
          Le fournisseur « utilisé » reçoit les demandes ; les autres restent prêts en cas de changement.</p>

        <?php foreach ($rows as $row): $used = $row['id'] === $selected; $hasKey = ai_provider_has_key($row); $isModal = ($row['driver'] ?? '') === 'modal'; ?>
          <section class="prov<?= $used ? ' is-used' : '' ?>">
            <div class="prov-head">
              <h3><?= e($row['label']) ?></h3>
              <?php if (!$row['enabled']): ?><span class="pill off">Désactivé</span><?php elseif ($used): ?><span class="pill used">Utilisé</span><?php else: ?><span class="pill">Disponible</span><?php endif; ?>
              <?php if (!$hasKey): ?><span class="pill warn">Clé absente</span><?php endif; ?>
              <span class="key-state"><?= e(AI_PROVIDER_DRIVERS[$category][$row['driver']] ?? $row['driver']) ?><?= $row['builtin'] ? ' · clé partagée' : ' · fournisseur ajouté' ?></span>
            </div>

            <form method="post" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
              <input type="hidden" name="category" value="<?= e($category) ?>"><input type="hidden" name="id" value="<?= e($row['id']) ?>">
              <div class="grid">
                <label class="f">Nom<input type="text" name="label" maxlength="60" value="<?= e($row['label']) ?>" required></label>
                <label class="f">Connecteur
                  <select name="driver"><?php foreach (AI_PROVIDER_DRIVERS[$category] as $driverKey => $driverLabel): ?><option value="<?= e($driverKey) ?>"<?= $driverKey === $row['driver'] ? ' selected' : '' ?>><?= e($driverLabel) ?></option><?php endforeach; ?></select>
                </label>
                <label class="f">Adresse de base<input type="text" name="base_url" value="<?= e($row['base_url']) ?>" placeholder="https://…/v1" spellcheck="false"><small>facultatif pour Modal</small></label>
                <label class="f">Modèle<input type="text" name="model" value="<?= e($row['model']) ?>" placeholder="<?= $row['driver'] === 'gemini' || $row['driver'] === 'veo' ? 'modèle par défaut' : 'identifiant du modèle' ?>" spellcheck="false"></label>
                <?php if (!$row['builtin']): ?>
                  <label class="f">Clé API<input type="password" name="key" autocomplete="off" placeholder="<?= $hasKey ? 'Déjà enregistrée — laisser vide pour la garder' : 'Coller la clé…' ?>"></label>
                <?php endif; ?>
              </div>
              <div class="actions" style="margin-top:10px">
                <label class="check"><input type="checkbox" name="enabled" value="1"<?= $row['enabled'] ? ' checked' : '' ?>> Proposé</label>
                <button class="btn btn-primary small" type="submit">Enregistrer</button>
                <?php if ($row['builtin']): ?><span class="key-state">Clé : <?= $hasKey ? 'présente' : 'à renseigner' ?> — <a href="/portail/api.php?s=cles">gérer les clés partagées</a></span><?php endif; ?>
              </div>
            </form>

            <div class="actions">
              <?php if (!$used): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="category" value="<?= e($category) ?>"><input type="hidden" name="id" value="<?= e($row['id']) ?>">
                  <button class="btn small" type="submit"<?= $row['enabled'] ? '' : ' disabled' ?>>Utiliser ce fournisseur</button></form>
              <?php endif; ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="test"><input type="hidden" name="category" value="<?= e($category) ?>"><input type="hidden" name="id" value="<?= e($row['id']) ?>">
                <button class="btn small" type="submit" formnovalidate>Tester la connexion</button></form>
              <?php if (!$row['builtin']): ?>
                <form method="post" onsubmit="return confirm('Supprimer ce fournisseur et sa clé ?');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="category" value="<?= e($category) ?>"><input type="hidden" name="id" value="<?= e($row['id']) ?>">
                  <button class="btn small danger" type="submit">Supprimer</button></form>
              <?php endif; ?>
            </div>
          </section>
        <?php endforeach; ?>

        <?php
          // Connecteurs proposés à l'ajout : ceux qui n'exigent pas la clé partagée d'un fournisseur fourni au départ.
          $addDrivers = AI_PROVIDER_DRIVERS[$category];
          if ($category === 'video') unset($addDrivers['veo'], $addDrivers['modal']); else unset($addDrivers['gemini']);
        ?>
        <form method="post" autocomplete="off" class="prov" data-add-form data-category="<?= e($category) ?>">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
          <input type="hidden" name="category" value="<?= e($category) ?>">
          <div class="prov-head"><h3>Ajouter un fournisseur <?= e(mb_strtolower($categoryLabel)) ?></h3></div>
          <div class="grid">
            <label class="f">Nom<input type="text" name="label" maxlength="60" placeholder="<?= $category === 'text' ? 'ex : Mistral, Groq, DeepSeek…' : ($category === 'image' ? 'ex : OpenAI, Together…' : 'ex : autre service SiliconFlow') ?>" required></label>
            <label class="f">Connecteur
              <select name="driver" data-driver><?php foreach ($addDrivers as $driverKey => $driverLabel): ?><option value="<?= e($driverKey) ?>"><?= e($driverLabel) ?></option><?php endforeach; ?></select>
            </label>
            <label class="f">Adresse de base<input type="text" name="base_url" placeholder="https://…/v1" spellcheck="false" data-base></label>
            <label class="f">Modèle<input type="text" name="model" placeholder="identifiant du modèle" spellcheck="false" data-model></label>
            <label class="f">Clé API<input type="password" name="key" autocomplete="off" placeholder="Coller la clé…"></label>
          </div>
          <div class="actions" style="margin-top:10px">
            <label class="check"><input type="checkbox" name="enabled" value="1" checked> Proposé</label>
            <button class="btn small btn-primary" type="submit">Ajouter</button>
            <span class="hint"><?= $category === 'video' ? 'La vidéo accepte les services compatibles SiliconFlow (/video/submit et /video/status).' : 'Texte et image acceptent les services compatibles OpenAI (/chat/completions, /images/edits).' ?></span>
          </div>
        </form>
      </section>
    <?php endforeach; ?>

    <section class="ia-card">
      <h2>Remettre les réglages d'origine</h2>
      <p class="hint">Revient à Gemini (texte et image), Claude, Veo, SiliconFlow et Modal, avec l'IA proposée à toutes les boutiques. Les clés partagées ne sont pas touchées.</p>
      <form method="post" onsubmit="return confirm('Remettre tous les fournisseurs et l\'interrupteur IA à leur état d\'origine ?');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset">
        <button class="btn small" type="submit">Réinitialiser</button>
      </form>
    </section>
  </div>
</div>
<script>
(function () {
  var defaults = <?= json_encode($driverDefaults, JSON_UNESCAPED_SLASHES) ?>;
  document.querySelectorAll('[data-add-form]').forEach(function (form) {
    var driver = form.querySelector('[data-driver]'), base = form.querySelector('[data-base]'), model = form.querySelector('[data-model]');
    function fill() {
      var d = defaults[form.dataset.category + '|' + driver.value] || ['', ''];
      if (base.value === '') base.value = d[0];
      if (model.value === '') model.value = d[1];
    }
    driver.addEventListener('change', function () { base.value = ''; model.value = ''; fill(); });
    fill();
  });
})();
</script>
</body>
</html>
