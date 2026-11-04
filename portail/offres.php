<?php
// Vitrine de la plateforme : nom, slogan, contact, couleur, formules (affichées sur l'accueil et à l'inscription) et annuaire public
// (commerces visibles ou masqués, commerces d'ailleurs). Réglages enregistrés dans data/saas.json (includes/saas.php).
// Les limites écrites dans une formule (« jusqu'à 20 pièces »…) sont du texte affiché : elles ne sont pas appliquées par la plateforme.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/banner-ai.php';

$shops = tenant_list();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $cfg = saas_config();

    if ($action === 'save') {
        $visible = (array) ($_POST['listed'] ?? []);
        $cfg = array_merge($cfg, [
            'name' => trim((string) ($_POST['name'] ?? '')), 'tagline' => trim((string) ($_POST['tagline'] ?? '')),
            'contact' => trim((string) ($_POST['contact'] ?? '')), 'accent' => (string) ($_POST['accent'] ?? ''),
            'free_daily_cap' => max(0, min(1000, (int) ($_POST['free_daily_cap'] ?? 10))),
            'hidden' => array_values(array_diff(array_keys($shops), $visible)),
        ]);
        $ok = saas_config_save($cfg);
        portail_flash($ok ? 'Réglages enregistrés.' : "Impossible d'écrire data/saas.json (droits du dossier data).", $ok ? 'ok' : 'error');
    } elseif ($action === 'home_save') {
        $home = $cfg['home'];
        foreach (['eyebrow' => 120, 'title' => 140, 'lede' => 400] as $key => $max) $home[$key] = mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
        $home['overlay'] = max(0, min(80, (int) ($_POST['overlay'] ?? 35)));
        $home['slideshow'] = !empty($_POST['slideshow']);
        $images = (array) $home['images'];
        $remove = array_map('intval', (array) ($_POST['remove_image'] ?? []));
        foreach ($images as $i => $path) {
            if (!in_array($i, $remove, true)) continue;
            saas_banner_delete((string) $path);
            unset($images[$i]);
        }
        $images = array_values($images);
        $added = $errors = [];
        $files = $_FILES['images'] ?? null;
        if (is_array($files) && is_array($files['name'] ?? null)) {
            for ($i = 0; $i < count($files['name']) && count($images) < 6; $i++) {
                if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                try {
                    $images[] = saas_banner_store(['error' => $files['error'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i]]);
                    $added[] = end($images);
                } catch (InvalidArgumentException $ex) {
                    $errors[] = 'Bannière : ' . $ex->getMessage();
                }
            }
        }
        $home['images'] = array_slice($images, 0, 6);
        $cfg['home'] = $home;
        $ok = saas_config_save($cfg);
        if (!$ok) foreach ($added as $path) saas_banner_delete($path);
        portail_flash($errors ? implode(' ', $errors) . ($ok ? ' Le reste est enregistré.' : '') : ($ok ? "Page d'accueil enregistrée : elle s'applique immédiatement." : "Impossible d'écrire data/saas.json (droits du dossier data)."), $errors || !$ok ? 'error' : 'ok');
    } elseif ($action === 'home_ai') {
        $images = (array) $cfg['home']['images'];
        $room = 6 - count($images);
        $count = max(1, min(3, (int) ($_POST['ai_count'] ?? 1)));
        $hint = trim((string) ($_POST['ai_hint'] ?? ''));
        if ($room <= 0) {
            portail_flash('Six images au maximum : retirez-en une pour en générer une autre.', 'error');
        } else {
            $count = min($count, $room);
            $subject = '« ' . $cfg['name'] . ' », plateforme française de boutiques en ligne, galeries marchandes, marchés aux puces et vides-greniers'
                . ($cfg['tagline'] !== '' ? '. ' . $cfg['tagline'] : '');
            $prompts = [];
            for ($i = 0; $i < $count; $i++) {
                $prompts[] = banner_ai_prompt($subject . ($i > 0 ? ' (variation ' . ($i + 1) . ', composition différente)' : ''), $hint);
            }
            try {
                $bytes = banner_ai_generate_many($prompts);
                banner_ai_usage_log('accueil', 'accueil', count($bytes));
                $paths = [];
                foreach ($bytes as $image) $paths[] = banner_ai_save($image, 'uploads/saas', 'accueil', 2000);
                $cfg['home']['images'] = array_slice(array_merge($images, $paths), 0, 6);
                if (!saas_config_save($cfg)) {
                    foreach ($paths as $path) saas_banner_delete($path);
                    throw new RuntimeException("Impossible d'écrire data/saas.json (droits du dossier data).");
                }
                portail_flash(count($paths) . ' image' . (count($paths) > 1 ? 's' : '') . " générée" . (count($paths) > 1 ? 's' : '') . " par l'IA : relisez-les avant de publier.");
            } catch (Throwable $ex) {
                portail_flash($ex->getMessage(), 'error');
            }
        }
    } elseif ($action === 'add_remote') {
        $url = rtrim(trim((string) ($_POST['url'] ?? '')), '/');
        if (!gallery_remote_url_valid($url)) { portail_flash('Adresse non valide : une adresse http(s) publique, par exemple https://brocante.arrimage.com', 'error'); }
        elseif (in_array($url, array_column($cfg['remote'], 'url'), true)) { portail_flash('Ce commerce est déjà dans l\'annuaire.', 'error'); }
        elseif (!($feed = catalogue_remote($url))) { portail_flash("Cette adresse ne répond pas avec un catalogue ($url/catalogue.php) : le commerce doit être à jour de la plateforme.", 'error'); }
        else {
            $cfg['remote'][] = ['name' => trim((string) ($_POST['name'] ?? '')) ?: (string) ($feed['shop']['name'] ?? $url), 'url' => $url];
            saas_config_save($cfg);
            portail_flash('« ' . ($feed['shop']['name'] ?? $url) . ' » ajouté à l\'annuaire (' . count($feed['products']) . ' pièce(s) en ligne).');
        }
    } elseif ($action === 'remove_remote') {
        $i = (int) ($_POST['index'] ?? -1);
        unset($cfg['remote'][$i]);
        $cfg['remote'] = array_values($cfg['remote']);
        saas_config_save($cfg);
        portail_flash('Commerce retiré de l\'annuaire.');
    } elseif ($action === 'stripe_save') {
        $mode = (string) ($_POST['stripe_mode'] ?? saas_stripe_mode());
        $sk = trim((string) ($_POST['stripe_secret'] ?? ''));
        $wh = trim((string) ($_POST['stripe_webhook'] ?? ''));
        if (!isset(STRIPE_MODES[$mode])) portail_flash('Mode Stripe inconnu.', 'error');
        elseif ($sk === '' && $wh === '') portail_flash('Rien à enregistrer.');
        elseif ($sk !== '' && !preg_match('/^(?:sk|rk)_' . $mode . '_[A-Za-z0-9]+$/', $sk)) portail_flash('Clé secrète non valide : pour le mode ' . STRIPE_MODES[$mode] . ', elle commence par sk_' . $mode . '_ ou rk_' . $mode . '_.', 'error');
        elseif ($wh !== '' && !preg_match('/^whsec_[A-Za-z0-9]+$/', $wh)) portail_flash('Secret de signature non valide : il commence par whsec_.', 'error');
        elseif (!saas_stripe_save_mode($mode, $sk, $wh)) portail_flash("Impossible d'écrire la clé (droits du dossier .secrets).", 'error');
        else portail_flash('Clés du mode ' . STRIPE_MODES[$mode] . ' enregistrées. Cliquez sur « Tester la connexion » pour vérifier la clé active.');
    } elseif ($action === 'stripe_mode') {
        [$ok, $message] = saas_stripe_mode_set((string) ($_POST['mode'] ?? ''));
        portail_flash($message, $ok ? 'ok' : 'error');
    } elseif ($action === 'stripe_test') {
        try {
            saas_stripe_request('GET', '/balance');
            portail_flash('Connexion à Stripe réussie (' . (saas_stripe_live() ? 'mode RÉEL : les paiements sont de vrais paiements' : 'mode test : aucun vrai paiement') . ').');
        } catch (Throwable $e) {
            portail_flash('Stripe répond : ' . $e->getMessage(), 'error');
        }
    } elseif ($action === 'stripe_clear') {
        @unlink(saas_stripe_file());
        portail_flash('Clés Stripe retirées : les formules payantes passent par validation manuelle.');
    } elseif ($action === 'refresh') {
        @unlink(saas_data_dir() . '/saas/cache.json');
        portail_flash('Annuaire actualisé : il relit les commerces à la prochaine visite.');
    }
    saas_audit_flash('Offres et annuaire');
    header('Location: /portail/offres.php');
    exit;
}

$flash = portail_flash();
$cfg = saas_config();
$origin = saas_origin();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Offres et annuaire — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .owrap { display: grid; gap: 16px; max-width: 920px; margin: 0 auto; padding: 0 16px 48px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .ocard h2 { margin: 0; font-size: 1.05rem; }
  .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
  .ocard label.f { display: grid; gap: 4px; font-size: .8rem; font-weight: 600; flex: 1; min-width: 180px; }
  .ocard input[type=text], .ocard input[type=email], .ocard input[type=url], .ocard textarea { padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; }
  .ocard textarea { min-height: 96px; }
  .plan-edit { border: 1px solid var(--line); border-radius: 8px; padding: 12px; display: grid; gap: 8px; }
  .member { display: flex; align-items: center; gap: 12px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; } .member .name { flex: 1; } .member small { opacity: .7; }
  .swatch-in { width: 44px; height: 34px; padding: 0; border: 1px solid var(--line); border-radius: 6px; background: none; }
  .home-shots { display: flex; flex-wrap: wrap; gap: 10px; }
  .home-shot { display: grid; gap: 4px; font-size: .78rem; }
  .home-shot .thumb { width: 148px; height: 83px; border-radius: 6px; border: 1px solid var(--line); background-size: cover; background-position: center; display: block; }
  .home-ai { border-top: 1px dashed var(--line); padding-top: 12px; display: grid; gap: 8px; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Offres et annuaire',
    'eyebrow' => 'Boutiques',
    'desc' => 'La vitrine publique de la plateforme : accueil, formules et annuaire des commerces.',
    'actions' => '<a class="btn" href="' . e($origin) . '/" target="_blank" rel="noopener">Voir l\'accueil</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="owrap">
    <form class="ocard" method="post" autocomplete="off" id="reglages">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
      <h2>La plateforme</h2>
      <div class="row">
        <label class="f">Nom<input type="text" name="name" required maxlength="40" value="<?= e($cfg['name']) ?>"></label>
        <label class="f">E-mail de contact (reçoit les demandes d'inscription)<input type="email" name="contact" maxlength="80" value="<?= e($cfg['contact']) ?>" placeholder="contact@votre-domaine.fr"></label>
        <label class="f" style="flex:none;min-width:0;">Couleur<input class="swatch-in" type="color" name="accent" value="<?= e($cfg['accent']) ?>"></label>
      </div>
      <label class="f">Slogan<input type="text" name="tagline" maxlength="160" value="<?= e($cfg['tagline']) ?>"></label>

      <label class="f" style="max-width:360px">Plafond de boutiques gratuites par 24 h (0 = aucun plafond)<input type="text" name="free_daily_cap" inputmode="numeric" pattern="[0-9]{1,4}" value="<?= (int) $cfg['free_daily_cap'] ?>"></label>
      <p class="meta" style="margin:-4px 0 0">Les boutiques se créent sans validation de votre part : ce plafond limite les créations gratuites en masse (par défaut 10 par jour). Les formules payantes ne sont pas concernées.</p>

      <h2 style="margin-top:8px">Tarifs</h2>
      <p class="meta">Les formules, les prix mensuels et annuels, les tranches par nombre d'articles et les tarifs propres à chaque galerie se règlent dans <a href="/portail/tarifs.php"><strong>Tarifs</strong></a>.</p>

      <h2 style="margin-top:8px">Annuaire : commerces de ce serveur</h2>
      <p class="meta">Cochez ceux qui apparaissent dans l'annuaire public et sur l'accueil.</p>
      <?php foreach ($shops as $slug => $shop): ?>
        <label class="member"><input type="checkbox" name="listed[]" value="<?= e($slug) ?>"<?= in_array($slug, $cfg['hidden'], true) ? '' : ' checked' ?>>
          <span class="name"><strong><?= e($shop['name']) ?></strong> <small>— <?= e($slug) ?></small></span></label>
      <?php endforeach; ?>
      <p><button class="btn btn-primary" type="submit">Enregistrer</button></p>
    </form>

    <?php $home = $cfg['home']; $homeBanners = saas_home_banners($cfg); ?>
    <form class="ocard" method="post" enctype="multipart/form-data" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="home_save">
      <h2>Page d'accueil publique</h2>
      <p class="meta">Le grand bandeau de <a href="<?= e($origin) ?>/" target="_blank" rel="noopener">l'accueil</a> : textes, images et diaporama. Un champ vide garde le texte par défaut ; <code>{nom}</code> est remplacé par le nom de la plateforme.</p>
      <label class="f">Surtitre<input type="text" name="eyebrow" maxlength="120" value="<?= e($home['eyebrow']) ?>" placeholder="Boutiques · galeries · marchés aux puces · vides-greniers"></label>
      <label class="f">Titre<input type="text" name="title" maxlength="140" value="<?= e($home['title']) ?>" placeholder="Vendez, réunissez, organisez avec {nom}"></label>
      <label class="f">Phrase d'introduction<textarea name="lede" maxlength="400" placeholder="Laissez vide pour le texte par défaut (slogan suivi de la présentation de la plateforme)."><?= e($home['lede']) ?></textarea></label>

      <h2 style="margin-top:6px">Images du bandeau</h2>
      <p class="meta">Jusqu'à six images, affichées en fondu enchaîné. La première sert d'image de partage sur les réseaux. Sans image, le bandeau reprend la couleur principale.</p>
      <?php if ($homeBanners): ?>
        <div class="home-shots">
          <?php foreach ($homeBanners as $i => $path): ?>
            <label class="home-shot"><span class="thumb" style="background-image:url('/<?= e($path) ?>')"></span>
              <span><input type="checkbox" name="remove_image[]" value="<?= (int) $i ?>"> Retirer<?= $i === 0 ? ' (1re image)' : '' ?></span></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="row">
        <label class="f">Ajouter des images <small>(JPEG, PNG ou WebP, 12 Mo max chacune, large de préférence)</small><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple></label>
        <label class="f">Assombrissement <output id="home-ov"><?= (int) $home['overlay'] ?> %</output><input type="range" name="overlay" min="0" max="80" step="5" value="<?= (int) $home['overlay'] ?>" oninput="document.getElementById('home-ov').value=this.value + ' %';document.getElementById('home-ov').textContent=this.value + ' %'"></label>
        <label class="row" style="gap:6px;align-self:end;"><input type="checkbox" name="slideshow" value="1"<?= $home['slideshow'] ? ' checked' : '' ?>> Diaporama automatique</label>
      </div>
      <p><button class="btn btn-primary" type="submit">Enregistrer la page d'accueil</button></p>

      <div class="home-ai">
        <?php $bannerAiOk = banner_ai_available(); ?>
        <h2>Une image par l'IA</h2>
        <p class="meta">Décrivez l'ambiance (marché, rue, atelier, lumière…) : l'IA ajoute une image panoramique 16:9, à relire avant publication. Coût estimé ≈ <?= e(ai_format_eur(ai_eur(AI_PRICING_DEFAULTS['image']))) ?> par image, sur la clé Gemini de la plateforme.</p>
        <?php if (!$bannerAiOk): ?><p class="flash" data-kind="error" role="status">Génération indisponible : activez le fournisseur d'images Gemini et vérifiez sa clé dans <a href="/portail/ia-fournisseurs.php">Fournisseurs IA</a>.</p><?php endif; ?>
        <?= banner_ai_usage_html() ?>
      </div>
    </form>
    <form class="ocard" method="post" autocomplete="off" style="margin-top:-16px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="home_ai">
      <div class="row">
        <label class="f">Ambiance souhaitée (facultatif)<input type="text" name="ai_hint" maxlength="200" placeholder="ex. marché de plein air au soleil, étals colorés"></label>
        <label class="f" style="flex:none;min-width:140px;">Images<select name="ai_count"><?php foreach ([1, 2, 3] as $n): ?><option value="<?= $n ?>"><?= $n ?> image<?= $n > 1 ? 's' : '' ?></option><?php endforeach; ?></select></label>
        <button class="btn" type="submit"<?= $bannerAiOk ? '' : ' disabled' ?>>Générer avec l'IA</button>
      </div>
    </form>

    <section class="ocard" id="stripe">
      <?php $ss = saas_stripe_modes_status(); $activeMode = $ss['mode']; ?>
      <h2>Paiement des formules (Stripe) <span class="pill <?= $ss['modes'][$activeMode]['configured'] ? 'done' : 'sent' ?>">Mode <?= e(STRIPE_MODES[$activeMode]) ?></span></h2>
      <p class="meta">Le client d'une formule payante règle par carte sur Stripe (abonnement mensuel) ; sa boutique est générée dès que le paiement est confirmé. Ce sont <strong>les clés Stripe de la plateforme</strong> (votre compte), sans rapport avec celles de chaque commerce. Chaque mode garde ses propres clés : <strong>basculer ne demande pas de recoller quoi que ce soit</strong>. Sans clé, les formules payantes passent par votre validation manuelle.</p>
      <p class="meta">Le mode actif s'applique aux formules, aux abonnements des galeries, aux crédits IA et aux noms de domaine. <strong>Mode test</strong> : cartes de test, aucun vrai paiement. <strong>Mode réel</strong> : les clients paient vraiment.</p>
      <div class="row" style="align-items:center;">
        <?php foreach (STRIPE_MODES as $mode => $label): $st = $ss['modes'][$mode]; ?>
          <span class="pill <?= $activeMode === $mode ? 'done' : '' ?>"><?= e($label) ?> : <?= $st['configured'] ? 'clé ' . e($st['secret_mask']) : 'sans clé' ?><?= $st['webhook'] ? ' · webhook ok' : '' ?></span>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="stripe_mode"><input type="hidden" name="mode" value="<?= e($mode) ?>">
            <button class="btn small<?= $activeMode === $mode ? ' btn-primary' : '' ?>" type="submit"<?= $activeMode === $mode || !$st['configured'] ? ' disabled' : '' ?>>Passer en mode <?= e(strtolower($label)) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
      <?php foreach (STRIPE_MODES as $mode => $label): $st = $ss['modes'][$mode]; ?>
        <details<?= $activeMode === $mode ? ' open' : '' ?> style="margin-top:12px;">
          <summary><strong>Clés du mode <?= e(strtolower($label)) ?></strong> — <?= $st['configured'] ? 'enregistrées' : 'à renseigner' ?></summary>
          <form method="post" autocomplete="off" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="stripe_save"><input type="hidden" name="stripe_mode" value="<?= e($mode) ?>">
            <label class="f"><span>Clé secrète (<code>sk_…</code> ou <code>rk_…</code>)</span><input type="password" name="stripe_secret" placeholder="<?= $st['configured'] ? 'déjà enregistrée — laisser vide pour la garder' : 'sk_' . e($mode) . '_… / rk_' . e($mode) . '_…' ?>"></label>
            <label class="f"><span>Secret de signature du webhook (<code>whsec_…</code>)</span><input type="password" name="stripe_webhook" placeholder="<?= $st['webhook'] ? 'déjà enregistré' : 'whsec_…' ?>"></label>
            <button class="btn btn-primary" type="submit">Enregistrer les clés <?= e(strtolower($label)) ?></button>
          </form>
        </details>
      <?php endforeach; ?>
      <p class="meta">Dans le tableau de bord Stripe → Développeurs → Webhooks, ajoutez le point de terminaison <code><?= e($origin) ?>/inscription/webhook.php</code> (un par mode) avec les événements <code>checkout.session.completed</code> et <code>customer.subscription.deleted</code>, puis collez son secret de signature. (Le webhook est un filet : la boutique se génère déjà au retour du paiement.)</p>
      <?php if ($ss['modes'][$activeMode]['configured']): ?>
        <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="btn small" type="submit" name="action" value="stripe_test">Tester la connexion (mode <?= e(strtolower(STRIPE_MODES[$activeMode])) ?>)</button>
          <button class="btn small danger" type="submit" name="action" value="stripe_clear" onclick="return confirm('Retirer toutes les clés Stripe de la plateforme (test et réel) ?');">Retirer les clés</button></form>
      <?php endif; ?>
    </section>

    <section class="ocard">
      <h2>Annuaire : commerces d'ailleurs</h2>
      <p class="meta">Des commerces hébergés ailleurs (par exemple le Petit Chalet) : leur catalogue est lu à <code>/catalogue.php</code>.</p>
      <?php foreach ($cfg['remote'] as $i => $r): ?>
        <form method="post" class="member"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_remote"><input type="hidden" name="index" value="<?= (int) $i ?>">
          <span class="name"><strong><?= e($r['name'] ?: $r['url']) ?></strong> <small><code><?= e($r['url']) ?></code></small></span><button class="btn small danger" type="submit">Retirer</button></form>
      <?php endforeach; ?>
      <form method="post" class="row" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_remote">
        <label class="f">Adresse de la boutique<input type="url" name="url" required placeholder="https://brocante.arrimage.com"></label>
        <label class="f">Nom (facultatif)<input type="text" name="name" maxlength="80"></label>
        <button class="btn" type="submit">Tester et ajouter</button>
      </form>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="refresh"><button class="btn small" type="submit">Actualiser l'annuaire maintenant</button> <span class="meta">relu toutes les 10 minutes</span></form>
    </section>
  </div>
</div>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
