<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';
require_admin();

$content = get_content();
$flash = flash_get();
$csrf = admin_csrf_token();
$shop = oauth_shop_config();
$callback = oauth_return_base() . '/oauth/callback.php';
$mask = static fn (string $v): string => $v !== '' ? 'Déjà enregistré — laisser vide pour le garder' : '';
// Source des clés réellement utilisées pour chaque fournisseur (en ignorant les interrupteurs, pour expliquer l'état).
$source = static fn (string $k): string => oauth_own_creds($k) ? 'own' : (oauth_platform_creds($k) ? 'platform' : 'none');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion sociale — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .social-form { max-width: 760px; }
  .social-form input[type="text"], .social-form input[type="password"], .social-form textarea { width: 100%; box-sizing: border-box; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 10px 12px; font-family: var(--font-body); font-size: 0.92rem; }
  .social-form textarea { min-height: 90px; font-family: var(--font-mono); font-size: 0.78rem; }
  .social-form fieldset { border: 1px solid var(--line); padding: 14px 16px 10px; margin: 16px 0; }
  .social-form legend { padding: 0 8px; font-weight: 600; font-size: 0.92rem; }
  .social-form .tick { display: flex; gap: 8px; align-items: center; margin: 6px 0; }
  .social-form .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  @media (max-width: 640px) { .social-form .cols { grid-template-columns: 1fr; } }
  .src { font-size: .78rem; padding: 2px 8px; border: 1px solid currentColor; border-radius: 999px; margin-left: 8px; white-space: nowrap; }
  .copyline { font-family: var(--font-mono); font-size: .82rem; word-break: break-all; background: var(--bg); border: 1px dashed var(--line); padding: 8px 10px; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'social'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Réglages</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Connexion sociale</h1>
    <p class="lede" style="max-width:720px;">Proposez « Continuer avec Google / Facebook / Microsoft / Apple » sur la page de connexion de l'administration et sur les comptes clients. Sans rien saisir, ce commerce peut utiliser les clés de la plateforme ; vous pouvez aussi enregistrer <strong>vos propres clés</strong> (votre application chez le fournisseur), qui sont alors prioritaires.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <form class="admin-block social-form" method="post" action="/admin/social-action.php" autocomplete="off" style="margin-top:14px;">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save">

      <fieldset>
        <legend>Où proposer la connexion sociale ?</legend>
        <label class="tick"><input type="checkbox" name="admin" value="1"<?= $shop['admin'] ? ' checked' : '' ?>> Connexion à l'administration <small>(l'e-mail du compte doit correspondre à un compte de l'équipe)</small></label>
        <label class="tick"><input type="checkbox" name="customer" value="1"<?= $shop['customer'] ? ' checked' : '' ?>> Comptes clients <small>(page « Mon compte » : suivi des commandes)</small></label>
      </fieldset>

      <?php foreach (OAUTH_PROVIDERS as $k => $def): $p = $shop['providers'][$k]; $src = $source($k); ?>
        <fieldset>
          <legend><?= h($def['label']) ?>
            <?php if ($src === 'own'): ?><span class="src" style="color:var(--sage);">vos clés</span>
            <?php elseif ($src === 'platform'): ?><span class="src" style="color:var(--ink-soft);">clés de la plateforme</span>
            <?php else: ?><span class="src" style="color:var(--accent);">non configuré</span><?php endif; ?></legend>
          <label class="tick"><input type="checkbox" name="offer[]" value="<?= h($k) ?>"<?= in_array($k, $shop['off'], true) ? '' : ' checked' ?>> Proposer « Continuer avec <?= h($def['label']) ?> » sur ce commerce</label>
          <details<?= $p['client_id'] !== '' ? ' open' : '' ?> style="margin-top:8px;">
            <summary style="cursor:pointer;font-weight:600;font-size:.88rem;">Utiliser mes propres clés <?= h($def['label']) ?></summary>
            <p class="hint"><?= h($def['help']) ?> <a href="<?= h($def['console']) ?>" target="_blank" rel="noopener">Ouvrir la console ↗</a></p>
            <div class="cols">
              <div class="field"><label><?= $k === 'apple' ? 'ID client (Services ID)' : 'ID client' ?></label><input type="text" name="client_id[<?= h($k) ?>]" value="<?= h($p['client_id']) ?>" spellcheck="false"></div>
              <?php if ($k !== 'apple'): ?>
                <div class="field"><label>Secret client</label><input type="password" name="client_secret[<?= h($k) ?>]" placeholder="<?= h($mask($p['client_secret'])) ?>"></div>
              <?php else: ?>
                <div class="field"><label>Team ID</label><input type="text" name="team_id[<?= h($k) ?>]" value="<?= h($p['team_id']) ?>" spellcheck="false"></div>
                <div class="field"><label>Identifiant de la clé (Key ID)</label><input type="text" name="key_id[<?= h($k) ?>]" value="<?= h($p['key_id']) ?>" spellcheck="false"></div>
                <div class="field" style="grid-column:1/-1"><label>Clé privée (contenu du fichier .p8)</label><textarea name="private_key[<?= h($k) ?>]" placeholder="<?= h($p['private_key'] !== '' ? 'Déjà enregistrée — laisser vide pour la garder' : "-----BEGIN PRIVATE KEY-----\n…") ?>"></textarea></div>
              <?php endif; ?>
            </div>
            <?php if ($p['client_id'] !== '' && !oauth_fields_complete($k, $p)): ?><p class="publish-status" data-kind="error">Clés incomplètes : elles ne sont pas utilisées.</p><?php endif; ?>
            <?php if (oauth_fields_complete($k, $p)): ?>
              <button type="submit" class="btn-small" name="action" value="clear_<?= h($k) ?>" formnovalidate onclick="return confirm('Retirer vos clés <?= h($def['label']) ?> ?');">Retirer mes clés <?= h($def['label']) ?></button>
            <?php endif; ?>
          </details>
        </fieldset>
      <?php endforeach; ?>

      <fieldset>
        <legend>Adresse de retour à déclarer chez le fournisseur</legend>
        <p class="hint" style="margin-top:0;">Seulement si vous utilisez <strong>vos propres clés</strong> : collez cette adresse exacte dans les « URI de redirection autorisées » de votre application (Google, Facebook…). Avec les clés de la plateforme, rien à déclarer.</p>
        <p class="copyline"><?= h($callback) ?></p>
        <?php if (!str_starts_with($callback, 'https://') && !preg_match('#^http://localhost(:\d+)?/#', $callback)): ?>
          <p class="hint" style="color:var(--accent);">⚠ Cette adresse n'est pas en HTTPS : la plupart des fournisseurs la refusent hors <code>localhost</code>.</p>
        <?php endif; ?>
      </fieldset>

      <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
  </div>
</section>
</main>
</body>
</html>
