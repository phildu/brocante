<?php
// Identité légale d'un commerce (mentions légales, CGV) réglée depuis le portail :
// nom du vendeur, SIRET, adresse (autocomplétion BAN), e-mail, téléphone, TVA,
// directeur de publication, hébergeur et médiateur de la consommation.
// Les valeurs sont enregistrées dans la table settings de la base du commerce
// (clés legal_*), donc reprises telles quelles par son administration.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/legal.php';

$shops = tenant_list();
$slug = (string) ($_POST['slug'] ?? $_GET['slug'] ?? '');
if (!isset($shops[$slug])) {
    portail_flash('Commerce introuvable.', 'error');
    header('Location: /portail/');
    exit;
}
$shop = $shops[$slug];
$dbFile = tenant_file($shop, 'db_file');
$back = '/portail/legal.php?slug=' . rawurlencode($slug);
$shopUrl = portail_shop_url($slug, $shop);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'default_host') {
        legal_default_host_save((string) ($_POST['default_host'] ?? ''));
        portail_flash('Hébergeur par défaut enregistré : il s\'applique aux commerces sans hébergeur propre.');
    } elseif ($action === 'identity') {
        if (!is_file($dbFile)) {
            portail_flash("La base de ce commerce n'existe pas encore : ouvrez-le d'abord depuis le portail.", 'error');
        } else {
            $values = [];
            foreach (array_keys(LEGAL_FIELDS) as $f) $values[$f] = legal_clean_field($f, (string) ($_POST[$f] ?? ''));
            if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                portail_flash('Adresse e-mail de contact invalide.', 'error');
                header('Location: ' . $back);
                exit;
            }
            // Même valeur que le défaut du portail : on l'efface pour suivre ce défaut s'il change.
            if ($values['host'] === legal_default_host()) $values['host'] = '';
            try {
                $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                legal_save_identity($pdo, $values);
                $pdo = null;
                portail_flash('Identité légale enregistrée pour « ' . $shop['name'] . ' ».');
            } catch (Throwable $ex) {
                portail_flash("Impossible d'enregistrer l'identité légale : " . $ex->getMessage(), 'error');
            }
        }
    } else {
        portail_flash('Action inconnue.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

$flash = portail_flash();
$identity = null;
if (is_file($dbFile)) {
    try {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $saved = legal_settings_from_pdo($pdo);
        $content = $pdo->query('SELECT * FROM content WHERE id = 1')->fetch() ?: [];
        $pdo = null;
        $site = trim((string) ($content['site_name'] ?? '')) ?: (string) $shop['name'];
        $identity = legal_identity_values($saved, $site, $content);
    } catch (Throwable $e) {
        $identity = null;
    }
}
$defaultHost = legal_default_host();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pages légales — <?= e($shop['name']) ?> — Portail</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=1">
<style>
  .legal-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 340px); gap: 16px; align-items: start; max-width: 1100px; }
  @media (max-width: 900px) { .legal-layout { grid-template-columns: 1fr; } }
  .legal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  @media (max-width: 620px) { .legal-grid { grid-template-columns: 1fr; } }
  .legal-grid .full { grid-column: 1 / -1; }
  .legal-links { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }
  .legal-links a { font-weight: 600; }
  .legal-links small { display: block; color: var(--muted); font-weight: 400; }
  .addr-wrap { position: relative; display: block; }
  .addr-suggestions { position: absolute; left: 0; right: 0; top: calc(100% + 2px); z-index: 40; display: block; background: var(--panel); border: 1px solid var(--line); box-shadow: 0 8px 20px rgba(0,0,0,.12); max-height: 240px; overflow: auto; }
  .addr-suggestions[hidden] { display: none; }
  .addr-suggestion { display: block; width: 100%; text-align: left; background: none; border: 0; border-bottom: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font: inherit; cursor: pointer; }
  .addr-suggestion:last-child { border-bottom: 0; }
  .addr-suggestion strong { display: block; font-weight: 600; }
  .addr-suggestion span { display: block; font-size: 0.8rem; opacity: 0.75; }
  .addr-suggestion:hover, .addr-suggestion.is-active { background: var(--panel-2); }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Pages légales — ' . $shop['name'],
    'eyebrow' => 'Boutique',
    'desc' => 'Identité du vendeur qui remplit les mentions légales, les CGV et la politique de confidentialité.',
    'section' => 'Boutiques',
    'actions' => '<a class="btn" href="/portail/?voir=' . e(rawurlencode($slug)) . '">Retour au portail</a>'
        . '<a class="btn" href="' . e($shopUrl) . '/admin/legal.php" target="_blank" rel="noopener">Administration ↗</a>'
        . '<a class="btn btn-primary" href="' . e($shopUrl) . '/legal.php?page=mentions" target="_blank" rel="noopener">Voir les mentions ↗</a>',
]); ?>

  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <?php if ($identity === null): ?>
    <p class="flash" data-kind="error" role="status">La base de ce commerce n'existe pas encore. <a href="/portail/?voir=<?= e(rawurlencode($slug)) ?>">Ouvrez-le une fois dans le portail</a> pour la créer, puis revenez ici.</p>
  <?php else: ?>
  <div class="legal-layout">
    <form class="send" method="post" action="/portail/legal.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="identity">
      <input type="hidden" name="slug" value="<?= e($slug) ?>">
      <h2>Identité du vendeur</h2>
      <p class="hint">Le nom du vendeur et l'adresse sont préremplis avec les informations du site. Tout reste modifiable : ces informations sont publiques et servent aussi de base aux textes légaux.</p>
      <div class="legal-grid">
        <?php foreach (LEGAL_FIELDS as $key => [$label, $help]): ?>
          <label class="field<?= in_array($key, ['company', 'address', 'host', 'mediator'], true) ? ' full' : '' ?>"><?= e($label) ?>
            <input type="<?= $key === 'withdrawal' ? 'number' : ($key === 'email' ? 'email' : 'text') ?>" name="<?= e($key) ?>" value="<?= e($identity[$key] ?? '') ?>"<?= $key === 'address' ? ' data-address-autocomplete autocomplete="street-address"' : '' ?><?= $key === 'email' ? ' autocomplete="email" spellcheck="false"' : '' ?><?= $key === 'phone' ? ' inputmode="tel" autocomplete="tel"' : '' ?><?= $key === 'withdrawal' ? ' min="0" max="60" placeholder="14"' : '' ?> maxlength="300">
            <small class="hint"><?= e($help) ?></small>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="send-actions">
        <button class="btn btn-primary" type="submit">Enregistrer l'identité</button>
        <span class="hint">Les textes eux-mêmes se modifient dans l'administration du commerce, page « Pages légales ».</span>
      </div>
    </form>

    <div style="display:flex;flex-direction:column;gap:12px;">
      <details class="box" open>
        <summary>Médiateur de la consommation <small>où en souscrire un</small></summary>
        <div class="box-body">
          <p class="hint">Pour vendre à des consommateurs, indiquez un médiateur auquel le commerce adhère (nom, site et coordonnées). Pour en choisir un et souscrire :</p>
          <ul class="legal-links">
            <?php foreach (LEGAL_MEDIATOR_LINKS as $link): ?>
              <li>
                <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener"><?= e($link['label']) ?> ↗</a>
                <small><?= e($link['note']) ?></small>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="hint">Une fois l'adhésion faite, collez le nom et les coordonnées du médiateur dans le champ « Médiateur de la consommation » ci-contre.</p>
        </div>
      </details>

      <form class="send" method="post" action="/portail/legal.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="default_host">
        <input type="hidden" name="slug" value="<?= e($slug) ?>">
        <h2>Hébergeur par défaut</h2>
        <p class="hint">Commun à tous les commerces. Un commerce peut le remplacer par son propre hébergeur dans son identité ci-contre.</p>
        <label class="field">Nom et adresse de l'hébergeur
          <input name="default_host" maxlength="300" value="<?= e($defaultHost) ?>">
        </label>
        <div class="send-actions"><button class="btn" type="submit">Enregistrer l'hébergeur par défaut</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<script src="/assets/address-autocomplete.js?v=1" defer></script>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
