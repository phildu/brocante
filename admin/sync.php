<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sync.php';
require_admin();

$content = get_content();
$flash = flash_get();
$newKey = $_SESSION['sync_new_key'] ?? null;
unset($_SESSION['sync_new_key']);
$target = sync_target();

$products = db()->query('SELECT * FROM products ORDER BY created_at DESC, ref DESC')->fetchAll();
$thumbs = [];
foreach (db()->query("SELECT product_ref, path FROM product_photos WHERE type = 'photo' AND is_hidden = 0 ORDER BY sort_order DESC, id DESC")->fetchAll() as $r) {
    $thumbs[$r['product_ref']] = $r['path']; // la dernière écrite = la première de la galerie
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Envoyer en préprod — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .sync-form { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end; }
  .sync-form label { display: flex; flex-direction: column; gap: 6px; font-size: 0.82rem; color: var(--ink-soft); }
  .sync-form input { background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 10px 12px; font: inherit; font-size: 0.9rem; }
  @media (max-width: 780px) { .sync-form { grid-template-columns: 1fr; } }
  .sync-remote { margin-top: 16px; font-size: 0.9rem; }
  .sync-remote[data-kind="ok"] { color: var(--sage, var(--accent)); }
  .sync-remote[data-kind="error"] { color: var(--accent); }
  .sync-toolbar { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; margin-bottom: 14px; font-size: 0.88rem; }
  .sync-toolbar label { display: inline-flex; gap: 6px; align-items: center; }
  .sync-list { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
  .sync-list td, .sync-list th { padding: 8px 10px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; }
  .sync-list th { color: var(--ink-soft); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; }
  .sync-list img { width: 54px; height: 36px; object-fit: contain; background: var(--surface-2); display: block; }
  .sync-list select { background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 4px 6px; font: inherit; font-size: 0.8rem; max-width: 100%; }
  .sync-list .ref { font-family: var(--font-mono); font-size: 0.78rem; color: var(--ink-soft); }
  .sync-list .muted { color: var(--ink-soft); font-size: 0.78rem; }
  .sync-state { font-size: 0.8rem; }
  .sync-state[data-kind="new"] { color: var(--sage, var(--accent-2)); }
  .sync-state[data-kind="conflict"] { color: var(--accent); font-weight: 600; }
  .sync-result { font-size: 0.8rem; }
  .sync-result[data-kind="ok"] { color: var(--sage, var(--accent-2)); }
  .sync-result[data-kind="error"] { color: var(--accent); }
  @media (max-width: 780px) { .sync-list .col-thumb, .sync-list .col-visibility { display: none; } }
  .sync-key { font-family: var(--font-mono); font-size: 0.85rem; padding: 12px; background: var(--bg); border: 1px dashed var(--accent); word-break: break-all; user-select: all; margin: 12px 0; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'sync'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap admin-shell">
    <div>
      <p class="eyebrow">Synchronisation</p>
      <h1 style="font-size:1.8rem;margin:12px 0 12px;">Envoyer des pièces en préprod</h1>
      <p class="hint">
        Envoie les pièces créées ici (sur votre Mac) vers un autre site — la préprod en général — avec leurs photos,
        visuels et vidéos. Seules les fiches choisies sont touchées : le reste du site distant (autres pièces,
        commandes, réglages) ne change pas, et les fichiers déjà présents là-bas ne sont pas renvoyés.
      </p>
      <?php if ($flash): ?>
        <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0 0;"><?= h($flash['message']) ?></p>
      <?php endif; ?>
    </div>

    <div class="admin-block">
      <h2>1. Site de destination</h2>
      <p class="hint">Adresse de la préprod et sa <strong>clé de réception</strong>, à générer dans la même page de l'administration de la préprod (section 3 ci-dessous, mais là-bas).</p>
      <form class="sync-form" method="post" action="/admin/sync-action.php">
        <input type="hidden" name="action" value="target">
        <label>Adresse du site distant
          <input type="url" name="url" required placeholder="https://preprod.mon-site.fr" value="<?= h($target['url'] ?? '') ?>">
        </label>
        <label>Clé de réception
          <input type="password" name="key" autocomplete="off" placeholder="<?= $target ? 'enregistrée — laisser vide pour la garder' : 'bsync_…' ?>" <?= $target ? '' : 'required' ?>>
        </label>
        <button class="btn btn-primary" type="submit">Enregistrer</button>
      </form>
      <p class="sync-remote" id="sync-remote"<?= $target ? '' : ' hidden' ?>>Connexion au site distant…</p>
    </div>

    <div class="admin-block">
      <h2>2. Pièces à envoyer</h2>
      <p class="hint">
        Une pièce déjà présente en préprod sous le même numéro y est <strong>mise à jour</strong> (fiche et galerie remplacées).
        Si ce numéro y désigne une <strong>autre</strong> pièce, elle est envoyée sous un nouveau numéro, sauf choix contraire.
        Les pièces gardent leur état publié / masqué.
      </p>
      <?php if (!$products): ?>
        <p class="hint">Aucune pièce dans le catalogue.</p>
      <?php else: ?>
        <div class="sync-toolbar">
          <label><input type="checkbox" id="sync-all"> Tout cocher</label>
          <button class="btn btn-primary" type="button" id="sync-send" disabled>Envoyer la sélection</button>
          <span id="sync-progress" class="muted"></span>
        </div>
        <div style="overflow-x:auto;">
          <table class="sync-list">
            <thead><tr><th></th><th class="col-thumb"></th><th>Pièce</th><th class="col-visibility">Ici</th><th>En préprod</th><th>Envoi</th></tr></thead>
            <tbody>
              <?php foreach ($products as $p): $thumb = $thumbs[$p['ref']] ?? $p['photo']; ?>
                <tr data-ref="<?= h($p['ref']) ?>" data-name="<?= h($p['name']) ?>">
                  <td><input type="checkbox" class="sync-pick" aria-label="Envoyer <?= h($p['name']) ?>" disabled></td>
                  <td class="col-thumb"><?php if ($thumb): ?><img src="/<?= h($thumb) ?>" alt="" loading="lazy"><?php endif; ?></td>
                  <td><span class="ref">n° <?= h($p['ref']) ?></span><br><?= h($p['name']) ?></td>
                  <td class="col-visibility muted"><?= $p['is_hidden'] ? 'masquée' : 'publiée' ?></td>
                  <td><span class="sync-state">…</span></td>
                  <td><span class="sync-result"></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="admin-block" id="recevoir">
      <h2>3. Recevoir des pièces sur ce site</h2>
      <p class="hint">
        À faire <strong>sur la préprod</strong> : générez une clé, puis collez-la sur votre Mac dans la section 1.
        Elle n'est affichée qu'une fois ; en générer une nouvelle invalide l'ancienne.
      </p>
      <?php if ($newKey): ?>
        <div class="sync-key" id="sync-new-key"><?= h($newKey) ?></div>
        <button class="btn btn-ghost" type="button" onclick="navigator.clipboard.writeText(document.getElementById('sync-new-key').textContent).then(() => this.textContent = 'Copiée ✓')">Copier la clé</button>
      <?php endif; ?>
      <p class="hint" style="margin:14px 0;">État : <strong><?= sync_receive_enabled() ? 'réception activée' : 'réception désactivée' ?></strong></p>
      <form method="post" action="/admin/sync-action.php" style="display:flex;gap:10px;flex-wrap:wrap;">
        <input type="hidden" name="action" value="receive">
        <button class="btn btn-primary" type="submit"><?= sync_receive_enabled() ? 'Générer une nouvelle clé' : 'Générer une clé' ?></button>
        <?php if (sync_receive_enabled()): ?>
          <button class="btn btn-ghost" type="submit" name="revoke" value="1">Désactiver la réception</button>
        <?php endif; ?>
      </form>
    </div>
  </div>
</section>
</main>

<?php if ($target && $products): ?>
<script>
(() => {
  const remoteEl = document.getElementById('sync-remote');
  const refMap = <?= json_encode((object) sync_ref_map($target), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  const rows = [...document.querySelectorAll('.sync-list tbody tr')];
  const sendBtn = document.getElementById('sync-send');
  const allBox = document.getElementById('sync-all');
  const progress = document.getElementById('sync-progress');

  const post = async (data) => {
    const body = new FormData();
    Object.entries(data).forEach(([k, v]) => body.append(k, v));
    try {
      const res = await fetch('/admin/sync-action.php', { method: 'POST', body, credentials: 'same-origin' });
      return await res.json();
    } catch (e) {
      return { ok: false, error: 'Pas de réponse (' + e.message + ').' };
    }
  };
  const refreshButton = () => {
    const n = rows.filter(r => r.querySelector('.sync-pick').checked).length;
    sendBtn.disabled = n === 0;
    sendBtn.textContent = n ? `Envoyer la sélection (${n})` : 'Envoyer la sélection';
  };

  // État de chaque pièce en préprod : absente, présente (même nom), déjà envoyée
  // sous un autre numéro, ou numéro pris par une autre pièce.
  const setState = (row, remote) => {
    const state = row.querySelector('.sync-state');
    const mapped = refMap[row.dataset.ref];
    if (mapped && remote[mapped] !== undefined) {
      state.dataset.kind = 'same';
      state.textContent = `présente sous le n° ${mapped} — sera mise à jour`;
      row.dataset.mode = 'update';
      return;
    }
    const remoteName = remote[row.dataset.ref];
    if (remoteName === undefined) {
      state.dataset.kind = 'new';
      state.textContent = 'absente';
      row.dataset.mode = 'update';
    } else if (remoteName === row.dataset.name) {
      state.dataset.kind = 'same';
      state.textContent = 'présente — sera mise à jour';
      row.dataset.mode = 'update';
    } else {
      state.dataset.kind = 'conflict';
      state.innerHTML = '';
      state.append(`n° pris par « ${remoteName} »`);
      const select = document.createElement('select');
      select.innerHTML = '<option value="new">envoyer sous un nouveau n°</option><option value="update">remplacer cette pièce</option>';
      select.addEventListener('change', () => { row.dataset.mode = select.value; });
      state.append(document.createElement('br'), select);
      row.dataset.mode = 'new';
    }
  };

  const loadStatus = async () => {
    const r = await post({ action: 'status' });
    if (!r.ok) {
      remoteEl.dataset.kind = 'error';
      remoteEl.textContent = '✕ ' + r.error;
      rows.forEach(row => row.querySelector('.sync-state').textContent = '?');
      return;
    }
    const remote = r.products || {};
    remoteEl.dataset.kind = 'ok';
    remoteEl.textContent = `✓ Connecté à « ${r.site} » — ${Object.keys(remote).length} pièce(s) en ligne, fichiers jusqu'à ${r.upload_max}.`;
    rows.forEach(row => {
      setState(row, remote);
      row.querySelector('.sync-pick').disabled = false;
    });
  };

  allBox.addEventListener('change', () => {
    rows.forEach(r => { const c = r.querySelector('.sync-pick'); if (!c.disabled) c.checked = allBox.checked; });
    refreshButton();
  });
  rows.forEach(r => r.querySelector('.sync-pick').addEventListener('change', refreshButton));

  sendBtn.addEventListener('click', async () => {
    const picked = rows.filter(r => r.querySelector('.sync-pick').checked);
    if (!picked.length) return;
    sendBtn.disabled = true;
    let done = 0, failed = 0;
    for (const row of picked) {
      const result = row.querySelector('.sync-result');
      result.dataset.kind = '';
      result.textContent = 'envoi…';
      progress.textContent = `${done + failed + 1} / ${picked.length}`;
      const r = await post({ action: 'push', ref: row.dataset.ref, mode: row.dataset.mode || 'update' });
      if (r.ok) {
        done++;
        result.dataset.kind = 'ok';
        const files = r.files_sent ? `, ${r.files_sent} fichier(s)` : '';
        result.textContent = (r.created ? '✓ créée' : '✓ mise à jour') + (r.ref !== row.dataset.ref ? ` sous le n° ${r.ref}` : '') + files;
        row.querySelector('.sync-pick').checked = false;
        if (r.ref === row.dataset.ref) delete refMap[row.dataset.ref]; else refMap[row.dataset.ref] = r.ref;
        setState(row, { [r.ref]: row.dataset.name });
      } else {
        failed++;
        result.dataset.kind = 'error';
        result.textContent = '✕ ' + (r.error || 'échec');
      }
    }
    progress.textContent = `Terminé : ${done} envoyée(s)` + (failed ? `, ${failed} en erreur` : '') + '.';
    refreshButton();
  });

  loadStatus();
})();
</script>
<?php endif; ?>
</body>
</html>
