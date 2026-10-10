<?php
// Connexion par les réseaux sociaux (Google, Facebook, Microsoft, Apple) : clés OAuth de la plateforme, communes à tous les commerces.
// Enregistrées dans .secrets/oauth_plateforme.json (jamais déployé, jamais affiché en clair) ; voir includes/oauth.php pour le principe.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';
require_once PORTAIL_ROOT . '/includes/oauth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $cfg = oauth_config();
    // En développement seulement, on peut pointer vers un simulateur de fournisseur (dev_base) ; sans effet en ligne.
    if ($action === 'save') {
        $cfg['callback_origin'] = rtrim(trim((string) ($_POST['callback_origin'] ?? '')), '/');
        $cfg['return_hosts'] = array_values(array_filter(array_map(static fn ($h) => strtolower(preg_replace('#^https?://|/.*$#i', '', trim($h))), preg_split('/[\s,]+/', (string) ($_POST['return_hosts'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))));
        if (oauth_is_dev()) $cfg['dev_base'] = rtrim(trim((string) ($_POST['dev_base'] ?? '')), '/');
        $bad = '';
        if ($cfg['callback_origin'] !== '' && !preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?$#', $cfg['callback_origin'])) $bad = "Adresse centrale non valide : un domaine complet sans chemin, par exemple https://brocs.arrimage.com";
        foreach (OAUTH_PROVIDERS as $k => $_) {
            $p = &$cfg['providers'][$k];
            $p['enabled'] = !empty($_POST['enabled'][$k]);
            $p['client_id'] = trim((string) ($_POST['client_id'][$k] ?? ''));
            foreach (['client_secret', 'team_id', 'key_id', 'private_key'] as $f) {
                $v = trim((string) ($_POST[$f][$k] ?? ''));
                if ($f === 'team_id' || $f === 'key_id') $p[$f] = $v !== '' ? $v : $p[$f];
                elseif ($v !== '') $p[$f] = $v;   // un secret laissé vide est conservé
            }
            unset($p);
        }
        if ($bad !== '') { portail_flash($bad, 'error'); header('Location: /portail/social.php'); exit; }
        $ok = oauth_config_save($cfg);
        portail_flash($ok ? 'Réglages enregistrés.' : "Impossible d'écrire .secrets/oauth_plateforme.json (droits du dossier .secrets).", $ok ? 'ok' : 'error');
    } elseif (str_starts_with($action, 'clear_') && isset(OAUTH_PROVIDERS[$k = substr($action, 6)])) {
        $cfg['providers'][$k] = oauth_config_defaults()['providers'][$k];
        oauth_config_save($cfg);
        portail_flash(OAUTH_PROVIDERS[$k]['label'] . ' : clés retirées.');
    } elseif ($action === 'rotate') {
        $cfg['signing_key'] = bin2hex(random_bytes(32));
        oauth_config_save($cfg);
        portail_flash('Nouvelle clé de signature générée : les connexions en cours doivent être recommencées.');
    }
    saas_audit_flash('Connexion sociale');
    header('Location: /portail/social.php');
    exit;
}

$flash = portail_flash();
$cfg = oauth_config();
$callback = oauth_callback_url();
$ready = oauth_enabled_providers();
$inherited = oauth_config_read_path() !== null && oauth_config_read_path() !== oauth_config_path();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connexion sociale — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .owrap { display: grid; gap: 16px; max-width: 920px; margin: 0 auto; padding: 0 16px 48px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .ocard h2 { margin: 0; font-size: 1.05rem; }
  .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
  .ocard label.f { display: grid; gap: 4px; font-size: .8rem; font-weight: 600; flex: 1; min-width: 200px; }
  .ocard input[type=text], .ocard input[type=password], .ocard input[type=url], .ocard textarea { padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; }
  .ocard textarea { min-height: 84px; font-family: 'IBM Plex Mono', monospace; font-size: .78rem; }
  .prov { border: 1px solid var(--line); border-radius: 8px; padding: 12px; display: grid; gap: 8px; }
  .prov h3 { margin: 0; font-size: .98rem; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .copy { word-break: break-all; }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand"><h1>Connexion sociale</h1><p>« Continuer avec Google, Facebook, Microsoft, Apple » : inscription, administration des boutiques et comptes clients</p></div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn" href="/portail/">Portail des commerces</a></span>
  </header>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="owrap">
    <section class="ocard">
      <h2>Comment ça marche</h2>
      <p class="meta">Un fournisseur n'accepte que des adresses de retour déclarées à l'avance. Pour que toutes les boutiques (une adresse chacune) partagent les mêmes clés, le retour se fait toujours sur <strong>une seule adresse centrale</strong>, qui renvoie ensuite la personne sur sa boutique avec un justificatif signé de 2 minutes. Vous créez donc UNE application chez chaque fournisseur, et vous y déclarez UNE adresse de retour :</p>
      <p class="copy"><code><?= e($callback) ?></code></p>
      <p><span class="pill <?= $ready ? 'done' : 'sent' ?>"><?= $ready ? 'Actifs : ' . e(implode(', ', $ready)) : 'Aucun fournisseur actif' ?></span>
        <?php if ($inherited): ?><span class="meta">— réglages lus dans le déploiement voisin (<code>.shared-secrets-from</code>) ; ils se modifient depuis le portail de ce déploiement.</span><?php endif; ?></p>
      <p class="meta">Où ça apparaît : bouton sur <code>/inscription/</code> (création de boutique, sans mot de passe à créer), sur la page de connexion de l'administration de chaque boutique (un compte de l'équipe, ou le compte principal, dont l'e-mail correspond à une adresse <em>vérifiée</em> par le fournisseur) et sur <code>/compte.php</code> (compte client avec suivi des commandes). Les liens « Mon compte » n'apparaissent dans une boutique que si au moins un fournisseur est actif.</p>
    </section>

    <form class="ocard" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
      <h2>Fournisseurs</h2>
      <?php foreach (OAUTH_PROVIDERS as $k => $def): $p = $cfg['providers'][$k]; $set = static fn (string $v): string => $v !== '' ? 'déjà enregistré — laisser vide pour le garder' : ''; ?>
        <div class="prov">
          <h3><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="enabled[<?= e($k) ?>]" value="1"<?= $p['enabled'] ? ' checked' : '' ?>> <?= e($def['label']) ?></label>
            <span class="pill <?= oauth_provider_ready($k) ? 'done' : 'sent' ?>"><?= oauth_provider_ready($k) ? 'Actif' : ($p['enabled'] ? 'Incomplet' : 'Désactivé') ?></span>
            <a class="meta" href="<?= e($def['console']) ?>" target="_blank" rel="noopener">Ouvrir la console ↗</a></h3>
          <p class="meta"><?= e($def['help']) ?></p>
          <div class="row">
            <label class="f"><span><?= $k === 'apple' ? 'ID client (Services ID)' : 'ID client' ?></span><input type="text" name="client_id[<?= e($k) ?>]" value="<?= e($p['client_id']) ?>" spellcheck="false"></label>
            <?php if ($k !== 'apple'): ?>
              <label class="f"><span>Secret client</span><input type="password" name="client_secret[<?= e($k) ?>]" placeholder="<?= e($set($p['client_secret'])) ?>"></label>
            <?php else: ?>
              <label class="f"><span>Team ID</span><input type="text" name="team_id[<?= e($k) ?>]" value="<?= e($p['team_id']) ?>" spellcheck="false"></label>
              <label class="f"><span>Identifiant de la clé (Key ID)</span><input type="text" name="key_id[<?= e($k) ?>]" value="<?= e($p['key_id']) ?>" spellcheck="false"></label>
            <?php endif; ?>
          </div>
          <?php if ($k === 'apple'): ?>
            <label class="f"><span>Clé privée (contenu du fichier .p8)</span><textarea name="private_key[<?= e($k) ?>]" placeholder="<?= e($p['private_key'] !== '' ? 'déjà enregistrée — laisser vide pour la garder' : "-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----") ?>"></textarea></label>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <h2 style="margin-top:8px">Adresses</h2>
      <label class="f"><span>Adresse centrale de retour (facultatif)</span><input type="text" name="callback_origin" value="<?= e($cfg['callback_origin']) ?>" placeholder="<?= e(oauth_central_origin()) ?>"></label>
      <p class="meta">Laissez vide pour utiliser le domaine du portail. À renseigner pour un déploiement qui doit renvoyer vers une autre adresse centrale (domaine complet, sans chemin).</p>
      <label class="f"><span>Autres domaines de boutiques autorisés (un par ligne)</span><textarea name="return_hosts" placeholder="brocante.arrimage.com"><?= e(implode("\n", $cfg['return_hosts'])) ?></textarea></label>
      <p class="meta">Les sous-domaines du domaine central et les domaines (<code>site_url</code>) des commerces de ce serveur sont déjà acceptés. Ajoutez ici le domaine d'une boutique hébergée ailleurs pour qu'elle puisse utiliser ces clés.</p>
      <?php if (oauth_is_dev()): ?>
        <label class="f"><span>Simulateur de fournisseur (développement seulement)</span><input type="text" name="dev_base" value="<?= e($cfg['dev_base']) ?>" placeholder="http://localhost:8096"></label>
      <?php endif; ?>
      <p><button class="btn btn-primary" type="submit">Enregistrer</button></p>
    </form>

    <section class="ocard">
      <h2>Retirer des clés</h2>
      <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php foreach (OAUTH_PROVIDERS as $k => $def): if ($cfg['providers'][$k]['client_id'] === '') continue; ?>
          <button class="btn small danger" type="submit" name="action" value="clear_<?= e($k) ?>" onclick="return confirm('Retirer les clés <?= e($def['label']) ?> ?');">Retirer <?= e($def['label']) ?></button>
        <?php endforeach; ?>
        <button class="btn small" type="submit" name="action" value="rotate" onclick="return confirm('Changer la clé de signature ? Les connexions en cours seront à recommencer.');">Changer la clé de signature</button>
      </form>
      <p class="meta">Clés enregistrées dans <code>.secrets/oauth_plateforme.json</code> (jamais envoyé par le script de déploiement ni servi en HTTP). Sur un site en ligne, pensez à passer l'application de chaque fournisseur en production (Google : « Publier l'application » ; Facebook : mode Live) avec une politique de confidentialité en ligne.</p>
    </section>
  </div>
</div>
</body>
</html>
