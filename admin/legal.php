<?php
// Pages légales (CGV, conditions d'utilisation, confidentialité, mentions légales) : identité du vendeur, texte de chaque page et validation.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/legal.php';
require_admin();

db();
$content = get_content();
$flash = flash_get();
$csrf = admin_csrf_token();
$id = legal_identity();
$status = legal_status();
$missing = legal_missing_fields();
$labels = array_map(static fn ($f) => LEGAL_FIELDS[$f][0], $missing);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pages légales — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .lg-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; }
  .lg-grid label { display: block; font-size: .8rem; color: var(--ink-soft); }
  .lg-grid input { width: 100%; margin-top: 4px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 10px; font: inherit; }
  .lg-grid small { display: block; margin-top: 3px; opacity: .8; }
  .lg-text { width: 100%; min-height: 340px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 12px; font: .92rem/1.55 var(--font-mono, monospace); resize: vertical; }
  .lg-state { padding: 14px 16px; border: 1px solid var(--line); margin: 14px 0 22px; }
  .lg-state.ok { background: #e6f2e8; color: #1c5a2c; } .lg-state.todo { background: #fff3df; color: #7a4a00; }
  .lg-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
  .lg-links { display: block; margin-top: 5px; line-height: 1.5; }
  .lg-links a { text-decoration: underline; }
  details.admin-block > summary { cursor: pointer; font-weight: 600; font-size: 1.05rem; padding: 4px 0; }
  .addr-wrap { position: relative; display: block; }
  .addr-suggestions { position: absolute; left: 0; right: 0; top: calc(100% + 2px); z-index: 40; display: block; background: var(--surface, #fff); border: 1px solid var(--line); box-shadow: 0 8px 20px rgba(0,0,0,.12); max-height: 240px; overflow: auto; }
  .addr-suggestions[hidden] { display: none; }
  .addr-suggestion { display: block; width: 100%; text-align: left; background: none; border: 0; border-bottom: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font: inherit; cursor: pointer; }
  .addr-suggestion:last-child { border-bottom: 0; }
  .addr-suggestion strong { display: block; font-weight: 600; }
  .addr-suggestion span { display: block; font-size: .8rem; opacity: .75; }
  .addr-suggestion:hover, .addr-suggestion.is-active { background: var(--surface-2, #eee9e0); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'legal'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Boutique</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Pages légales</h1>
    <p class="hint" style="max-width:75ch;">Conditions générales de vente, conditions d'utilisation, politique de confidentialité et mentions légales sont publiées en pied de page. Les clients <strong>doivent accepter</strong> les conditions de vente et la politique de confidentialité avant de payer (la version acceptée est conservée avec la commande). Les textes ci-dessous sont des <strong>modèles de départ</strong> remplis avec votre identité : relisez-les, adaptez-les (idéalement avec un juriste) puis validez.</p>

    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p><?php endif; ?>

    <?php if ($status['validated']): ?>
      <div class="lg-state ok" role="status"><strong>Textes validés</strong> le <?= h(date('d/m/Y à H:i', $status['at'])) ?>.</div>
    <?php else: ?>
      <div class="lg-state todo" role="status"><strong>À valider.</strong>
        <?= $status['changed'] ? 'Un texte ou une information a changé depuis la dernière validation.' : 'Ces textes n\'ont pas encore été validés.' ?>
        <?php if ($labels): ?> À compléter d'abord : <?= h(implode(', ', $labels)) ?>.<?php endif; ?></div>
    <?php endif; ?>

    <form class="admin-block" method="post" action="/admin/legal-action.php" id="legal-identity">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="identity">
      <h2>Identité du vendeur</h2>
      <p class="hint">Ces informations remplissent les modèles (et les mentions légales). Elles sont publiques. Elles se règlent aussi depuis l'administration du portail (Pages légales du commerce). L'hébergeur par défaut (OVH) est commun aux commerces et se règle dans le portail.</p>
      <div class="lg-grid">
        <?php foreach (LEGAL_FIELDS as $key => [$label, $help]): $value = legal_get($key) !== '' ? legal_get($key) : ($key === 'withdrawal' ? '' : ($id[$key] ?? '')); ?>
          <label><?= h($label) ?>
            <input type="<?= $key === 'withdrawal' ? 'number' : ($key === 'email' ? 'email' : 'text') ?>" name="<?= h($key) ?>" value="<?= h($value) ?>"<?= $key === 'address' ? ' data-address-autocomplete autocomplete="street-address"' : '' ?><?= $key === 'email' ? ' autocomplete="email" spellcheck="false"' : '' ?><?= $key === 'phone' ? ' inputmode="tel" autocomplete="tel"' : '' ?><?= $key === 'withdrawal' ? ' min="0" max="60" placeholder="14"' : '' ?> maxlength="300">
            <small><?= h($help) ?></small>
            <?php if ($key === 'mediator'): ?>
              <small class="lg-links">Souscrire : <?php foreach (LEGAL_MEDIATOR_LINKS as $i => $link): ?><?= $i ? ' · ' : '' ?><a href="<?= h($link['url']) ?>" target="_blank" rel="noopener"><?= h($link['label']) ?></a><?php endforeach; ?></small>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="lg-actions"><button type="submit" class="btn btn-primary">Enregistrer l'identité</button></p>
    </form>

    <?php foreach (LEGAL_PAGES as $key => $p): $custom = trim(shop_setting('legal_text_' . $key, '')) !== ''; ?>
      <details class="admin-block"<?= $key === 'cgv' ? ' open' : '' ?>>
        <summary><?= h($p['title']) ?> <span class="hint" style="font-weight:400;">— <?= $custom ? 'texte modifié' : 'modèle' ?> · <a href="<?= h(legal_url($key)) ?>" target="_blank" rel="noopener">voir la page ↗</a></span></summary>
        <form method="post" action="/admin/legal-action.php">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="page" value="<?= h($key) ?>">
          <p class="hint">Mise en forme : « ## Titre » pour un titre, une ligne vide entre deux paragraphes, « - » pour une liste. « [À COMPLÉTER] » signale une information manquante.</p>
          <textarea class="lg-text" name="text" maxlength="40000" aria-label="<?= h($p['title']) ?>"><?= h(legal_text($key)) ?></textarea>
          <div class="lg-actions">
            <button type="submit" name="action" value="text" class="btn btn-primary">Enregistrer ce texte</button>
            <button type="submit" name="action" value="reset" class="btn" onclick="return confirm('Rétablir le modèle ? Vos modifications de ce texte seront perdues.');">Rétablir le modèle</button>
          </div>
        </form>
      </details>
    <?php endforeach; ?>

    <form class="admin-block" method="post" action="/admin/legal-action.php" id="legal-validate">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="validate">
      <h2>Valider</h2>
      <label class="featured-check"><input type="checkbox" name="ok" value="1" required> J'ai relu ces textes, ils décrivent bien mon activité et je les assume.</label>
      <p class="lg-actions"><button type="submit" class="btn btn-primary">Valider les pages légales</button></p>
    </form>
  </div>
</section>
</main>
<script src="/assets/address-autocomplete.js?v=1" defer></script>
</body>
</html>
