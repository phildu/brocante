<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/key-check.php';
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
<title>API &amp; réseaux — <?= h($content['site_name']) ?></title>
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
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">API &amp; réseaux</h1>
    <h2 style="margin:0 0 4px;font-size:1.3rem;">Connexion sociale</h2>
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

    <h2 style="margin:40px 0 4px;font-size:1.3rem;">Clés d'API et services IA</h2>
    <p class="lede" style="max-width:720px;">Les clés des services externes utilisés par la boutique. Un champ laissé vide conserve la clé déjà enregistrée. La clé Stripe (paiement) se règle dans « Réglages → Paiement &amp; livraison ».</p>
    <form method="post" action="/admin/key-check.php" style="margin:0 0 14px;"><input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"><button type="submit" class="btn btn-primary" name="service" value="all">Tester toutes les clés</button></form>
    <div class="admin-shell">
      <form class="admin-block" method="post" action="/admin/save-gemini-key.php" id="gemini-settings">
        <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
        <h2>Réglages IA (Gemini)</h2>
        <p class="hint">Clé depuis <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> — utilisée pour les générations de photos (angle, ambiance, netteté...) et les suggestions dans la médiathèque. Un champ laissé vide conserve la clé déjà enregistrée.</p>
        <div class="field">
          <label>Clé API Gemini</label>
          <input type="password" name="gemini_key" autocomplete="off" placeholder="<?= GEMINI_API_KEY ? 'Déjà enregistrée — ' . h(substr(GEMINI_API_KEY, 0, 6)) . '…' . h(substr(GEMINI_API_KEY, -4)) : 'AIza...' ?>">
        </div>
        <p class="publish-status" data-kind="<?= GEMINI_API_KEY ? '' : 'error' ?>" style="margin-top:10px;">
          <?= GEMINI_API_KEY ? 'Clé Gemini configurée — les générations IA sont actives.' : 'Aucune clé enregistrée — les générations et suggestions IA sont désactivées.' ?>
        </p>
        <?php if (secret_is_shared('gemini.key')): ?>
          <p class="hint" style="margin-top:6px;"><strong>Clé partagée (période de test) :</strong> ce commerce n'a pas la sienne, il utilise celle de l'exploitant. Saisissez la vôtre ci-dessus pour la remplacer ; la clé partagée sera supprimée à la mise en production.</p>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer la clé</button>
              <?php if (GEMINI_API_KEY): ?>
        <?= keycheck_status_html('gemini') ?>
        <button type="submit" class="btn" style="margin-top:6px;" formaction="/admin/key-check.php" name="service" value="gemini" formnovalidate>Tester la clé</button>
        <?php endif; ?>
      </form>

      <form class="admin-block" method="post" action="/admin/save-fal-key.php" id="fal-settings">
        <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
        <h2>Réglages IA (fal.ai — détourage)</h2>
        <p class="hint">Clé depuis <a href="https://fal.ai/dashboard/keys" target="_blank" rel="noopener">fal.ai/dashboard/keys</a> — utilisée pour le détourage à vrai fond transparent dans la galerie photo, quand rembg local n'est pas disponible (hébergement mutualisé). Un champ laissé vide conserve la clé déjà enregistrée.</p>
        <div class="field">
          <label>Clé API fal.ai</label>
          <input type="password" name="fal_key" autocomplete="off" placeholder="<?= FAL_API_KEY ? 'Déjà enregistrée — ' . h(substr(FAL_API_KEY, 0, 6)) . '…' . h(substr(FAL_API_KEY, -4)) : 'key_...' ?>">
        </div>
        <p class="publish-status" data-kind="<?= FAL_API_KEY ? '' : 'error' ?>" style="margin-top:10px;">
          <?= FAL_API_KEY ? 'Clé fal.ai configurée — le détourage à fond transparent est actif.' : 'Aucune clé enregistrée — le détourage utilisera un repli fond blanc (Gemini) si disponible, sinon sera désactivé.' ?>
        </p>
        <?php if (secret_is_shared('fal.key')): ?>
          <p class="hint" style="margin-top:6px;"><strong>Clé partagée (période de test) :</strong> ce commerce n'a pas la sienne, il utilise celle de l'exploitant. Saisissez la vôtre ci-dessus pour la remplacer ; la clé partagée sera supprimée à la mise en production.</p>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer la clé</button>
              <?php if (FAL_API_KEY): ?>
        <?= keycheck_status_html('fal') ?>
        <button type="submit" class="btn" style="margin-top:6px;" formaction="/admin/key-check.php" name="service" value="fal" formnovalidate>Tester la clé</button>
        <?php endif; ?>
      </form>

      <form class="admin-block" method="post" action="/admin/save-modal-video.php" id="modal-video-settings">
        <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
        <h2>Réglages IA (Modal — vidéo IA Wan 2.2 et détourage BiRefNet)</h2>
        <p class="hint">Service (vidéo IA Wan 2.2 dans le crédit gratuit Modal, et détourage haute précision à fond transparent) que vous déployez sur <a href="https://modal.com" target="_blank" rel="noopener">votre compte Modal</a> (30 $ de crédit gratuit par mois, facturation à la seconde de GPU) : <code>pip install modal</code>, <code>modal setup</code>, <code>modal secret create boutique-video-token AUTH_TOKEN=…</code> puis <code>modal deploy modal/ltx_video_app.py</code>. La dernière commande affiche l'adresse du service ; saisissez-la ici avec le même jeton. Un champ laissé vide conserve la valeur enregistrée.</p>
        <div class="field">
          <label>Adresse du service</label>
          <input type="text" name="modal_url" autocomplete="off" placeholder="<?= MODAL_VIDEO_URL !== '' ? h(MODAL_VIDEO_URL) : 'https://votre-compte--boutique-ltx-video-web.modal.run' ?>">
        </div>
        <div class="field">
          <label>Jeton (le même que dans « modal secret create »)</label>
          <input type="password" name="modal_token" autocomplete="off" placeholder="<?= MODAL_VIDEO_TOKEN !== '' ? 'Déjà enregistré — ' . h(substr(MODAL_VIDEO_TOKEN, 0, 3)) . '…' . h(substr(MODAL_VIDEO_TOKEN, -3)) : 'jeton' ?>">
        </div>
        <p class="publish-status" data-kind="<?= modal_video_available() ? '' : 'error' ?>" style="margin-top:10px;">
          <?= modal_video_available() ? 'Service Modal configuré — Wan 2.2 (vidéo) et le détourage haute précision sont disponibles dans la galerie. La connexion est testée à chaque enregistrement.' : 'Non configuré — LTX-Video est désactivé (les autres vidéos restent disponibles).' ?>
        </p>
        <?php if (secret_is_shared('modal-video.json')): ?>
          <p class="hint" style="margin-top:6px;"><strong>Clé partagée (période de test) :</strong> ce commerce n'a pas la sienne, il utilise celle de l'exploitant. Saisissez la vôtre ci-dessus pour la remplacer ; la clé partagée sera supprimée à la mise en production.</p>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer et tester</button>
        <?php if (modal_video_available()): ?>
          <button type="submit" name="remove" value="1" class="btn" style="margin-top:6px;" onclick="return confirm('Retirer la configuration du service Modal ?');">Retirer</button>
        <?php endif; ?>
              <?php if (modal_video_available()): ?>
        <?= keycheck_status_html('modal') ?>
        <button type="submit" class="btn" style="margin-top:6px;" formaction="/admin/key-check.php" name="service" value="modal" formnovalidate>Tester la clé</button>
        <?php endif; ?>
      </form>

      <form class="admin-block" method="post" action="/admin/save-siliconflow-key.php" id="siliconflow-settings">
        <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
        <h2>Réglages IA (SiliconFlow — vidéo IA économique)</h2>
        <p class="hint">Clé depuis <a href="https://cloud.siliconflow.com/account/ak" target="_blank" rel="noopener">cloud.siliconflow.com</a> (rubrique clés API) — utilisée pour la « Vidéo IA — Wan 2.2 » de la galerie photo, une alternative bon marché à Google Veo (environ 0,29 $ par vidéo ; un petit crédit de départ est offert aux nouveaux comptes, à vérifier chez eux). Un champ laissé vide conserve la clé déjà enregistrée.</p>
        <div class="field">
          <label>Clé API SiliconFlow</label>
          <input type="password" name="siliconflow_key" autocomplete="off" placeholder="<?= SILICONFLOW_API_KEY ? 'Déjà enregistrée — ' . h(substr(SILICONFLOW_API_KEY, 0, 4)) . '…' . h(substr(SILICONFLOW_API_KEY, -4)) : 'sk-...' ?>">
        </div>
        <p class="publish-status" data-kind="<?= SILICONFLOW_API_KEY ? '' : 'error' ?>" style="margin-top:10px;">
          <?= SILICONFLOW_API_KEY ? 'Clé SiliconFlow configurée — la vidéo IA Wan 2.2 est disponible dans la galerie.' : 'Aucune clé enregistrée — la vidéo IA Wan 2.2 est désactivée (Google Veo et la vidéo zoom/travelling restent disponibles).' ?>
        </p>
        <?php if (secret_is_shared('siliconflow.key')): ?>
          <p class="hint" style="margin-top:6px;"><strong>Clé partagée (période de test) :</strong> ce commerce n'a pas la sienne, il utilise celle de l'exploitant. Saisissez la vôtre ci-dessus pour la remplacer ; la clé partagée sera supprimée à la mise en production.</p>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer la clé</button>
        <?php if (SILICONFLOW_API_KEY): ?>
          <button type="submit" name="remove" value="1" class="btn" style="margin-top:6px;" onclick="return confirm('Retirer la clé SiliconFlow de ce commerce ?');">Retirer la clé</button>
        <?php endif; ?>
              <?php if (SILICONFLOW_API_KEY): ?>
        <?= keycheck_status_html('siliconflow') ?>
        <button type="submit" class="btn" style="margin-top:6px;" formaction="/admin/key-check.php" name="service" value="siliconflow" formnovalidate>Tester la clé</button>
        <?php endif; ?>
      </form>

    </div>
  </div>
</section>
</main>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
