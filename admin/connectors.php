<?php
session_start();
require_once __DIR__ . '/../includes/connectors.php';
require_permission('boutique.connecteurs.lire');

connectors_ensure_schema();
$content = get_content();
$flash = flash_get();
$sources = connector_source_list();

$platforms = connector_platforms();
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? connector_source_get($editId) : null;
$newPlatform = (string) ($_GET['new'] ?? '');
if (!isset($platforms[$newPlatform])) $newPlatform = '';
$formPlatform = $editing['platform'] ?? $newPlatform;
$formSettings = $editing['settings'] ?? connector_default_settings();
$hasSecret = $editing ? array_keys(connector_secrets_load((int) $editing['id'])) : [];

function connectors_fmt_date(?string $utc): string
{
    if (!$utc) return 'jamais';
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i');
}

$siteBase = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$pushUrl = ($siteBase !== '' ? $siteBase : ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'votre-site'))) . '/api/catalog-push.php';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Boutiques connectées — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<style>
  .cn-card { background: var(--surface); border: 1px solid var(--line); padding: 18px 20px; margin-top: 16px; }
  .cn-card.is-off { opacity: 0.6; }
  .cn-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .cn-head h2 { font-size: 1.1rem; margin: 0; }
  .cn-meta { color: var(--ink-soft); font-size: 0.85rem; margin: 6px 0 0; }
  .cn-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; align-items: center; }
  .cn-actions form { margin: 0; }
  .cn-out { margin-top: 12px; font-size: 0.88rem; min-height: 1.2em; white-space: pre-wrap; }
  .cn-out[data-kind="error"] { color: var(--accent); }
  .cn-pick { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
  .cn-code { display: block; background: var(--bg); border: 1px solid var(--line); padding: 10px 12px; font-family: var(--font-mono); font-size: 0.75rem; overflow-x: auto; white-space: pre; margin-top: 10px; }
  .cn-token { word-break: break-all; font-family: var(--font-mono); }
  .field input[type="password"] { background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 10px 12px; font-family: var(--font-body); font-size: 0.94rem; width: 100%; }
  .cn-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
  @media (max-width: 760px) { .cn-row-3 { grid-template-columns: 1fr; gap: 0; } }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'connecteurs'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Catalogue</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Boutiques connectées</h1>
    <p class="hint">
      Alimentez le catalogue avec les produits d'une boutique déjà en ligne (Shopify, WooCommerce, PrestaShop),
      ou laissez une autre plateforme les envoyer elle-même. Les produits importés sont créés
      <strong>masqués</strong> par défaut, le temps de les relire ; une nouvelle synchronisation met ensuite à jour
      leurs prix et leur stock. La boutique d'origine reste la référence : rien n'y est modifié.
    </p>

    <?php if ($flash): ?>
      <p class="publish-status cn-token" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php foreach ($sources as $s):
        $pull = $platforms[$s['platform']]['pull'] ?? false; ?>
      <div class="cn-card<?= $s['is_active'] ? '' : ' is-off' ?>" data-source="<?= (int) $s['id'] ?>">
        <div class="cn-head">
          <h2><?= h($s['name']) ?> <span class="badge"><?= h(connector_platform_label($s['platform'])) ?></span></h2>
          <span class="hint" style="margin:0;" id="cn-count-<?= (int) $s['id'] ?>"><?= (int) $s['product_count'] ?> produit<?= $s['product_count'] > 1 ? 's' : '' ?> au catalogue</span>
        </div>
        <?php if ($pull): ?><p class="cn-meta"><?= h($s['base_url']) ?></p><?php endif; ?>
        <p class="cn-meta" id="cn-last-<?= (int) $s['id'] ?>">
          <?= $pull ? 'Dernière synchronisation' : 'Dernier envoi' ?> : <?= h(connectors_fmt_date($s['last_sync_at'])) ?>
          <?php if ($s['last_sync_summary']): ?> — <?= h($s['last_sync_summary']) ?><?php endif; ?>
        </p>

        <div class="cn-actions">
          <?php if ($pull): ?>
            <button type="button" class="btn btn-primary" data-sync="<?= (int) $s['id'] ?>"<?= $s['is_active'] ? '' : ' disabled' ?>>Synchroniser</button>
            <button type="button" class="btn-small" data-test="<?= (int) $s['id'] ?>">Tester la connexion</button>
          <?php else: ?>
            <form method="post" action="/admin/connectors-action.php" onsubmit="return confirm('Générer un nouveau jeton ? L\'ancien cessera de fonctionner immédiatement.');">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="new_token">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn-small">Nouveau jeton d'accès</button>
            </form>
          <?php endif; ?>
          <?php if ($s['product_count'] > 0): ?>
            <a class="btn-small" href="/admin/catalog.php?source=<?= (int) $s['id'] ?>">Voir les produits →</a>
          <?php endif; ?>
          <a class="btn-small" href="/admin/connectors.php?edit=<?= (int) $s['id'] ?>#formulaire">Modifier</a>
          <form method="post" action="/admin/connectors-action.php" onsubmit="return confirm('Supprimer cette connexion ? Les produits déjà importés restent dans le catalogue mais ne seront plus mis à jour.');">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button type="submit" class="admin-delete">Supprimer</button>
          </form>
        </div>
        <div class="cn-out" id="cn-out-<?= (int) $s['id'] ?>" role="status" aria-live="polite"></div>

        <?php if (!$pull): ?>
          <details style="margin-top:10px;">
            <summary class="hint" style="cursor:pointer;">Comment envoyer des produits</summary>
            <code class="cn-code">curl -X POST <?= h($pushUrl) ?> \
  -H "Authorization: Bearer VOTRE_JETON" \
  -H "Content-Type: application/json" \
  -d '{"products":[{"external_id":"SKU-001","name":"Vase en grès","description":"Vase des années 60.","price":"34,00","stock":1,"weight_grams":850,"images":["https://exemple.fr/photo.jpg"]}]}'</code>
            <p class="hint" style="margin-top:8px;">Détail des champs : voir CONNECTEURS.md.</p>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if (!$sources): ?>
      <p class="empty-state" style="margin-top:20px;">Aucune boutique connectée pour l'instant.</p>
    <?php endif; ?>

    <div class="admin-block" id="formulaire" style="margin-top:28px;">
      <?php if (!$formPlatform): ?>
        <h2 style="font-size:1.1rem;">Ajouter une boutique</h2>
        <p class="hint">Quelle plateforme utilise la boutique à importer ?</p>
        <div class="cn-pick">
          <?php foreach ($platforms as $key => $pf): ?>
            <a class="btn-small" href="/admin/connectors.php?new=<?= h($key) ?>#formulaire"><?= h($pf['label']) ?></a>
          <?php endforeach; ?>
        </div>
      <?php else: $pull = $platforms[$formPlatform]['pull']; ?>
        <h2 style="font-size:1.1rem;"><?= $editing ? 'Modifier' : 'Ajouter' ?> — <?= h(connector_platform_label($formPlatform)) ?></h2>

        <?php if ($formPlatform === 'shopify'): ?>
          <p class="hint">
            <strong>Sans clé</strong> : seul le catalogue public est lu (prix, photos, disponible oui/non ; certaines boutiques le désactivent).
            <strong>Avec un jeton d'accès</strong> : stocks exacts. Dans l'admin Shopify → Paramètres → Applications → Développer des applications →
            créer une application, droits <code>read_products</code> et <code>read_inventory</code>, puis copier le « jeton d'accès Admin API » (<code>shpat_…</code>).
          </p>
        <?php elseif ($formPlatform === 'woocommerce'): ?>
          <p class="hint">
            Dans WordPress → WooCommerce → Réglages → Avancé → API REST → « Ajouter une clé », droits <strong>Lecture</strong>.
            Copiez la clé (<code>ck_…</code>) et le secret (<code>cs_…</code>) — ils ne sont affichés qu'une fois par WooCommerce.
          </p>
        <?php elseif ($formPlatform === 'prestashop'): ?>
          <p class="hint">
            Dans PrestaShop → Paramètres avancés → Webservice : activer le webservice, ajouter une clé avec accès en
            <strong>lecture (GET)</strong> aux ressources <code>products</code>, <code>images</code> et <code>stock_availables</code>.
            L'API donne les prix <strong>hors taxes</strong> : indiquez la TVA à ajouter ci-dessous.
          </p>
        <?php else: ?>
          <p class="hint">
            Cette source ne se connecte à rien : c'est l'autre plateforme (ou un outil comme Zapier, Make…) qui envoie ses produits
            à l'adresse <code><?= h($pushUrl) ?></code> avec un jeton. Le jeton est généré à l'enregistrement et n'est affiché qu'une fois.
          </p>
        <?php endif; ?>

        <form method="post" action="/admin/connectors-action.php" style="margin-top:14px;">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : 0 ?>">
          <input type="hidden" name="platform" value="<?= h($formPlatform) ?>">

          <div class="field-row-2">
            <div class="field"><label>Nom (pour vous y retrouver)</label>
              <input type="text" name="name" maxlength="80" value="<?= h($editing['name'] ?? '') ?>" placeholder="ex : Ma boutique en ligne"></div>
            <?php if ($pull): ?>
              <div class="field"><label>Adresse de la boutique</label>
                <input type="text" name="base_url" required value="<?= h($editing['base_url'] ?? '') ?>"
                       placeholder="<?= $formPlatform === 'shopify' ? 'ma-boutique.myshopify.com' : 'https://www.ma-boutique.fr' ?>"></div>
            <?php endif; ?>
          </div>

          <?php if ($formPlatform === 'shopify'): ?>
            <div class="field"><label>Jeton d'accès Admin API (facultatif)</label>
              <input type="password" name="secret_token" autocomplete="off" placeholder="<?= in_array('token', $hasSecret, true) ? '•••• enregistré — laisser vide pour le conserver' : 'shpat_…' ?>"></div>
          <?php elseif ($formPlatform === 'woocommerce'): ?>
            <div class="field-row-2">
              <div class="field"><label>Clé client</label>
                <input type="password" name="secret_key" autocomplete="off" placeholder="<?= in_array('key', $hasSecret, true) ? '•••• enregistrée — laisser vide pour la conserver' : 'ck_…' ?>"></div>
              <div class="field"><label>Secret client</label>
                <input type="password" name="secret_secret" autocomplete="off" placeholder="<?= in_array('secret', $hasSecret, true) ? '•••• enregistré — laisser vide pour le conserver' : 'cs_…' ?>"></div>
            </div>
          <?php elseif ($formPlatform === 'prestashop'): ?>
            <div class="field-row-2">
              <div class="field"><label>Clé de webservice</label>
                <input type="password" name="secret_key" autocomplete="off" placeholder="<?= in_array('key', $hasSecret, true) ? '•••• enregistrée — laisser vide pour la conserver' : '32 caractères' ?>"></div>
              <div class="field"><label>TVA à ajouter aux prix hors taxes (%)</label>
                <input type="text" name="vat_rate" inputmode="decimal" value="<?= h(rtrim(rtrim(number_format((float) $formSettings['vat_rate'], 2, ',', ''), '0'), ',')) ?>"></div>
            </div>
          <?php endif; ?>

          <h3 style="font-size:0.95rem;margin:22px 0 8px;">À l'import</h3>
          <div class="cn-row-3">
            <div class="field"><label>Univers par défaut</label>
              <select name="default_cat">
                <?php foreach (category_list() as $c): ?>
                  <option value="<?= h($c['key']) ?>"<?= $c['key'] === $formSettings['default_cat'] ? ' selected' : '' ?>><?= h($c['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="hint" style="font-size:0.75rem;">Utilisé quand le type de produit ne permet pas de deviner l'univers.</span></div>
            <div class="field"><label>Mention affichée</label>
              <input type="text" name="badge" maxlength="30" value="<?= h($formSettings['badge']) ?>"></div>
            <div class="field"><label>Nouveaux produits</label>
              <select name="publish_mode">
                <option value="hidden"<?= $formSettings['publish_mode'] === 'hidden' ? ' selected' : '' ?>>Masqués (je les relis d'abord)</option>
                <option value="visible"<?= $formSettings['publish_mode'] === 'visible' ? ' selected' : '' ?>>Publiés directement</option>
              </select></div>
          </div>
          <div style="display:flex;gap:22px;flex-wrap:wrap;margin-top:6px;">
            <label class="featured-check"><input type="checkbox" name="sync_stock" value="1"<?= $formSettings['sync_stock'] ? ' checked' : '' ?>> Le stock de la boutique d'origine écrase le mien à chaque synchro</label>
            <label class="featured-check"><input type="checkbox" name="overwrite_content" value="1"<?= $formSettings['overwrite_content'] ? ' checked' : '' ?>> Réécrire aussi le nom et la description à chaque synchro</label>
            <?php if ($editing): ?>
              <label class="featured-check"><input type="checkbox" name="is_active" value="1"<?= $editing['is_active'] ? ' checked' : '' ?>> Connexion active</label>
            <?php endif; ?>
          </div>

          <div class="cn-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-primary">Enregistrer</button>
            <a class="btn-small" href="/admin/connectors.php">Annuler</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
</main>

<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>;

  function post(data) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('/admin/connectors-action.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Réponse illisible du serveur (délai dépassé ?). Relancez : les produits déjà traités sont conservés.' }; });
  }
  function show(id, text, kind) {
    var el = document.getElementById('cn-out-' + id);
    el.textContent = text;
    if (kind) el.setAttribute('data-kind', kind); else el.removeAttribute('data-kind');
  }

  document.querySelectorAll('[data-test]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-test');
      btn.disabled = true;
      show(id, 'Connexion en cours…');
      post({ action: 'test', id: id }).then(function (r) {
        show(id, r.ok ? r.message : r.error, r.ok ? '' : 'error');
        btn.disabled = false;
      });
    });
  });

  document.querySelectorAll('[data-sync]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var id = btn.getAttribute('data-sync');
      var t = { created: 0, updated: 0, skipped: 0, failed: 0 };
      var errors = [], cursor = '', run = '', pages = 0, complete = true, fatal = '';
      btn.disabled = true;
      show(id, 'Synchronisation en cours…');
      while (true) {
        var r = await post({ action: 'sync_page', id: id, cursor: cursor, run: run });
        if (!r.ok) { complete = false; fatal = r.error; break; }
        run = r.run; pages++;
        Object.keys(t).forEach(function (k) { t[k] += r[k] || 0; });
        (r.errors || []).forEach(function (e) { if (errors.length < 8) errors.push(e); });
        show(id, 'Lot ' + pages + ' traité — ' + t.created + ' créé(s), ' + t.updated + ' mis à jour, ' + t.failed + ' en échec…');
        if (!r.next) break;
        cursor = r.next;
      }
      if (run) {
        var f = await post({ action: 'sync_finish', id: id, run: run, complete: complete ? '1' : '0',
          created: t.created, updated: t.updated, skipped: t.skipped, failed: t.failed });
        var msg = fatal ? 'Interrompu : ' + fatal + '\n' : '';
        msg += f.ok ? f.source : (f.error || '');
        if (f.ok) {
          document.getElementById('cn-last-' + id).textContent = 'Dernière synchronisation : à l\'instant — ' + f.source;
          document.getElementById('cn-count-' + id).textContent = f.product_count + ' produit' + (f.product_count > 1 ? 's' : '') + ' au catalogue';
        }
        if (errors.length) msg += '\nProblèmes rencontrés :\n• ' + errors.join('\n• ');
        show(id, msg, fatal || t.failed ? 'error' : '');
      } else {
        show(id, 'Échec : ' + fatal, 'error');
      }
      btn.disabled = false;
    });
  });
})();
</script>
<?= auth_ui_gate('connectors-action.php') ?>
</body>
</html>
