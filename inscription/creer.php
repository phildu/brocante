<?php
// Création de boutique — ÉTAPE 3 : nom, adresse, design et compte de la boutique. On y arrive
//   - après un paiement confirmé (retour de Stripe par merci.php, ou lien reçu par e-mail) ;
//   - directement après l'étape 1 pour une formule gratuite (ou un paiement à régler à part).
// Dans tous les cas la boutique est GÉNÉRÉE dès la validation du formulaire, sans validation de l'exploitant, et le client est conduit sur sa boutique.
// La page n'est accessible qu'avec le lien personnel (r = demande, k = jeton secret). Le mot de passe n'est conservé que haché.
require_once __DIR__ . '/../includes/saas.php';

session_start();
if (empty($_SESSION['saas_csrf'])) $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));

$cfg = saas_config();
$origin = saas_origin();
$galleries = array_filter(gallery_list(), static fn (array $g): bool => $g['published']);
$planName = '';
$req = saas_request_by_link((string) ($_REQUEST['r'] ?? ''), (string) ($_REQUEST['k'] ?? ''));
if ($req) $planName = array_column($cfg['plans'], 'name', 'key')[$req['plan']] ?? (string) $req['plan'];

$errors = [];
$view = 'form';           // form | done | sent | invalid | waiting | closed
$generated = null;
$v = ['shop_name' => '', 'slug' => '', 'first_name' => '', 'last_name' => '', 'phone' => '', 'about' => '', 'gallery' => '', 'template' => SHOP_TEMPLATE_DEFAULT];

if (!$req) {
    $view = 'invalid';
} else {
    $status = (string) $req['status'];
    foreach ($v as $k => $_) $v[$k] = trim((string) ($_POST[$k] ?? ($req[$k] ?? $v[$k])));
    if ($status === 'approved') {
        $view = 'done';
    } elseif ($status === 'pending') {
        $view = 'sent';
    } elseif ($status === 'awaiting_payment') {
        $view = 'waiting';
    } elseif (!in_array($status, ['draft', 'paid'], true) || ($status === 'draft' && !saas_request_active($req))) {
        $view = 'closed';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals((string) $_SESSION['saas_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            $errors['form'] = 'Le formulaire a expiré : rechargez la page et recommencez.';
        } else {
            $res = saas_request_complete($req, $_POST);
            if (!$res['ok']) {
                $errors = $res['errors'];
            } else {
                $req = $res['request'];
                $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));
                // Aucune validation de l'exploitant : la boutique est générée tout de suite (formule payante payée, gratuite, ou paiement à régler à part),
                // et le client arrive sur sa boutique.
                try {
                    $generated = saas_generate_shop($req, $origin);
                    $req = array_merge($req, ['status' => 'approved', 'approved' => time(), 'shop_url' => $generated['shop_url'], 'login' => $generated['login'], 'gallery' => $generated['gallery'], 'error' => '']);
                    saas_request_save($req);
                    $manual = ($req['payment']['state'] ?? '') === 'manual';
                    if ($cfg['contact'] !== '') {
                        saas_mail($cfg['contact'], 'Nouvelle boutique créée : ' . $req['shop_name'],
                            "Une boutique vient d'être créée.\n\nBoutique : {$req['shop_name']} ({$generated['shop_url']})\nContact : {$req['first_name']} {$req['last_name']} — {$req['email']} {$req['phone']}\nFormule : $planName"
                            . ($status === 'paid' ? ' — payée' : ($manual ? ' — ⚠ paiement à régler avec le client (' . ($req['payment']['note'] ?? '') . ')' : ' — gratuite'))
                            . "\nDesign : " . (SHOP_TEMPLATES[$req['template']]['label'] ?? $req['template']) . "\n" . ($req['gallery'] !== '' ? "Galerie : {$req['gallery']}\n" : '') . "\n$origin/portail/inscriptions.php\n");
                    }
                    header('Location: ' . $generated['shop_url'], true, 303);   // on se retrouve sur la boutique
                    exit;
                } catch (Throwable $e) {
                    // La demande est enregistrée mais la boutique n'a pas pu être créée (adresse prise entre-temps, droits d'écriture…).
                    $req['error'] = 'La boutique n\'a pas pu être créée : ' . $e->getMessage();
                    saas_request_save($req);
                    if ($status === 'paid' && $cfg['contact'] !== '') saas_mail($cfg['contact'], 'Paiement reçu, boutique à finaliser : ' . $req['shop_name'], $req['error'] . "\n\n$origin/portail/inscriptions.php\n");
                    $errors[str_contains($e->getMessage(), 'déjà prise') ? 'slug' : 'form'] = $status === 'paid'
                        ? 'Votre paiement est bien enregistré, mais la création a rencontré un problème (' . $e->getMessage() . '). Vous pouvez corriger l\'adresse puis réessayer ; sinon nous finalisons votre boutique et vous écrivons.'
                        : $e->getMessage() . ' Vous pouvez corriger l\'adresse puis réessayer.';
                }
            }
        }
    }
}

// Boutique de démonstration pour l'aperçu des modèles : la première de l'annuaire (la librairie de démonstration de préférence).
$previewBase = '';
if ($view === 'form') {
    $demoShop = null;
    foreach (array_keys(tenant_list()) as $s) {
        if (is_file(tenant_file(tenant_load($s), 'db_file')) && ($demoShop === null || $s === 'exemple-librairie')) $demoShop = $s;
    }
    $previewBase = $demoShop ? rtrim(gallery_local_shop_url($demoShop, tenant_load($demoShop), $origin), '/') . '/' : '';
}

$err = static fn (string $k): string => isset($errors[$k]) ? '<span class="err" role="alert">' . saas_e($errors[$k]) . '</span>' : '';
$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$paid = $req && ($req['status'] ?? '') === 'paid';
$social = $req && !empty($req['social']);
$link = $req ? '?r=' . saas_e($req['id']) . '&amp;k=' . saas_e($req['token']) : '';

saas_page_start('Ma boutique — ' . $cfg['name'], 'Dernière étape : le nom, le design et le compte de votre boutique.', 'inscription');
?>
<div class="wrap">
  <div class="page-title"><p class="eyebrow">Création de boutique</p><h1><?= $view === 'done' ? 'Votre boutique est prête' : 'Ma boutique' ?></h1>
    <ol class="stepper" aria-label="Étapes"><li class="is-done"><b>✓</b> Ma formule</li><li class="is-done"><b>✓</b> Paiement</li><li class="<?= $view === 'form' ? 'is-current' : 'is-done' ?>"<?= $view === 'form' ? ' aria-current="step"' : '' ?>><b><?= $view === 'form' ? '3' : '✓' ?></b> Ma boutique</li></ol>
    <?php if ($view === 'form'): ?><p class="lede"><?= $paid ? 'Votre paiement est confirmé. ' : '' ?>Dernière étape : le nom, le design et votre compte. Votre boutique est créée dès que vous validez, et vous arrivez directement dessus.</p><?php endif; ?></div>

  <?php if ($view === 'invalid'): ?>
    <div class="form-card"><div class="notice error" role="alert"><strong>Ce lien n'est pas valable.</strong> Il est peut-être incomplet ou a expiré.</div>
      <p><a class="btn" href="/inscription/">Recommencer</a></p></div>

  <?php elseif ($view === 'closed'): ?>
    <div class="form-card"><div class="notice error" role="alert"><strong>Cette demande n'est plus ouverte.</strong> Elle a expiré (24 heures sans suite) ou a été refusée.</div>
      <p><a class="btn" href="/inscription/">Refaire une demande</a></p></div>

  <?php elseif ($view === 'waiting'): ?>
    <div class="form-card"><div class="notice" role="status"><strong>Le paiement n'est pas encore confirmé.</strong> Terminez-le sur la page de Stripe, ou reprenez-le : cette page s'ouvrira ensuite.</div>
      <p><a class="btn" href="/inscription/?annule=<?= saas_e($req['id']) ?>">Reprendre le paiement</a></p></div>

  <?php elseif ($view === 'sent'): ?>
    <div class="form-card"><div class="notice ok" role="status"><strong>Demande reçue, merci !</strong></div>
      <p>Votre demande pour <strong><?= saas_e($req['shop_name']) ?></strong> est bien enregistrée. Nous vous écrivons à <?= saas_e($req['email']) ?> dès que votre boutique est prête.</p>
      <?php if (($req['payment']['state'] ?? '') === 'manual'): ?><div class="notice" role="status">Le paiement de votre formule se règle avec nous : nous vous écrivons à ce sujet.</div><?php endif; ?>
      <p style="margin-top:18px"><a class="btn" href="/">Retour à l'accueil</a></p></div>

  <?php elseif ($view === 'done'): $shopUrl = (string) ($req['shop_url'] ?? ''); ?>
    <div class="form-card"><div class="notice ok" role="status"><strong>Bravo, votre boutique est en ligne.</strong></div>
      <p><strong><?= saas_e($req['shop_name']) ?></strong> est prête.</p>
      <p><a class="btn" href="<?= saas_e($shopUrl) ?>">Voir ma boutique</a>
         <a class="btn ghost" style="margin-left:8px" href="<?= saas_e(rtrim($shopUrl, '/') . '/admin/') ?>">Ouvrir mon administration</a></p>
      <p class="lede" style="margin-top:16px">Identifiant : <strong><?= saas_e($req['login'] ?? $req['email']) ?></strong> — mot de passe : celui que vous venez de choisir. Nous vous avons aussi écrit ces liens.</p></div>

  <?php else: ?>
    <form class="form-card" method="post" action="/inscription/creer.php" novalidate>
      <?php if (isset($errors['form'])): ?><div class="notice error" role="alert"><?= saas_e($errors['form']) ?></div><?php elseif ($errors): ?><div class="notice error" role="alert"><strong>La création n'a pas pu aboutir :</strong><ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($errors as $m): ?><li><?= saas_e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <?php if (($req['payment']['state'] ?? '') === 'manual'): ?><div class="notice" role="status">Le paiement en ligne n'est pas disponible pour le moment : votre boutique est créée tout de suite et nous vous écrivons pour régler votre formule <strong><?= saas_e($planName) ?></strong>.</div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= saas_e($_SESSION['saas_csrf']) ?>"><input type="hidden" name="r" value="<?= saas_e($req['id']) ?>"><input type="hidden" name="k" value="<?= saas_e($req['token']) ?>">

      <div class="form-grid">
        <label class="field full<?= isset($errors['shop_name']) ? ' has-error' : '' ?>"><span>Nom de votre boutique</span>
          <input type="text" name="shop_name" value="<?= saas_e($v['shop_name']) ?>" required maxlength="60" autocomplete="organization" placeholder="La Brocante du Petit Chalet"><?= $err('shop_name') ?></label>
        <label class="field full<?= isset($errors['slug']) ? ' has-error' : '' ?>"><span>Adresse de votre boutique</span>
          <span class="slug-row"><span class="pre"><?= saas_e($host) ?>/</span><input type="text" name="slug" value="<?= saas_e($v['slug']) ?>" maxlength="40" pattern="[a-z0-9][a-z0-9\-]{2,39}" placeholder="laissez vide : tiré du nom" autocapitalize="none" spellcheck="false"></span>
          <small>Lettres minuscules, chiffres et tirets.</small><?= $err('slug') ?></label>

        <fieldset class="field full<?= isset($errors['template']) ? ' has-error' : '' ?>" style="border:0;padding:0;margin:0"><span style="font-size:.82rem;font-weight:600">Le design de votre boutique</span>
          <div class="tpl-pick">
            <?php foreach (SHOP_TEMPLATES as $key => $t): ?>
              <label>
                <input type="radio" name="template" value="<?= saas_e($key) ?>"<?= $v['template'] === $key ? ' checked' : '' ?>>
                <span class="tpl-card tpl-<?= saas_e($key) ?>" style="--p-bg:<?= saas_e($t['colors']['bg']) ?>;--p-ink:<?= saas_e($t['colors']['ink']) ?>;--p-accent:<?= saas_e($t['colors']['accent']) ?>;--p-accent2:<?= saas_e($t['colors']['accent-2']) ?>;--p-display:'<?= saas_e($t['fonts']['display']) ?>';--p-body:'<?= saas_e($t['fonts']['body']) ?>'">
                  <?php if ($wire = shop_template_wireframe($key)): ?>
                    <span class="tpl-mock tpl-mock-wire" aria-hidden="true"><?= $wire ?></span>
                  <?php else: ?>
                    <span class="tpl-mock" aria-hidden="true"><b class="tpl-title">Aa</b><i class="tpl-line"></i><i class="tpl-line short"></i><u class="tpl-btn"></u></span>
                  <?php endif; ?>
                  <strong><?= saas_e($t['label']) ?><?php if (!empty($t['sector'])): ?> <em class="tpl-sector"><?= saas_e($t['sector']) ?></em><?php endif; ?></strong>
                  <small><?= saas_e($t['tagline']) ?></small>
                  <span class="tpl-sw" aria-hidden="true"><?php foreach ($t['colors'] as $c): ?><i style="background:<?= saas_e($c) ?>"></i><?php endforeach; ?></span>
                </span>
                <?php if ($previewBase !== ''): ?><a class="tpl-preview" href="<?= saas_e($previewBase) ?>?modele=<?= saas_e($key) ?>" target="_blank" rel="noopener">Voir un aperçu ↗</a><?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div><?= $err('template') ?><small>Vous pourrez ajuster couleurs et polices ensuite dans l'administration.</small></fieldset>

        <label class="field<?= isset($errors['first_name']) ? ' has-error' : '' ?>"><span>Prénom</span><input type="text" name="first_name" value="<?= saas_e($v['first_name']) ?>" required maxlength="60" autocomplete="given-name"><?= $err('first_name') ?></label>
        <label class="field"><span>Nom</span><input type="text" name="last_name" value="<?= saas_e($v['last_name']) ?>" maxlength="60" autocomplete="family-name"></label>
        <label class="field"><span>E-mail (votre identifiant d'administration)</span><input type="email" value="<?= saas_e($req['email']) ?>" readonly></label>
        <label class="field"><span>Téléphone (facultatif)</span><input type="tel" name="phone" value="<?= saas_e($v['phone']) ?>" maxlength="30" autocomplete="tel"></label>
        <?php if ($social): ?>
          <p class="field full oauth-note" style="margin:0">Vous vous connecterez avec <strong><?= saas_e(OAUTH_PROVIDERS[$req['social']['provider']]['label'] ?? 'votre compte') ?></strong> : pas de mot de passe à retenir. Vous pouvez en ajouter un si vous le souhaitez (facultatif).</p>
        <?php endif; ?>
        <label class="field<?= isset($errors['password']) ? ' has-error' : '' ?>"><span>Mot de passe (<?= $social ? 'facultatif, 8 caractères au moins' : '8 caractères au moins' ?>)</span><input type="password" name="password"<?= $social ? '' : ' required' ?> minlength="8" autocomplete="new-password"><?= $err('password') ?></label>
        <label class="field<?= isset($errors['password2']) ? ' has-error' : '' ?>"><span>Confirmer le mot de passe</span><input type="password" name="password2"<?= $social ? '' : ' required' ?> minlength="8" autocomplete="new-password"><?= $err('password2') ?></label>
        <label class="field full"><span>Que vendez-vous ? (facultatif)</span><textarea name="about" maxlength="400" placeholder="Vêtements de seconde main, objets de brocante, livres anciens…"><?= saas_e($v['about']) ?></textarea></label>

        <?php if ($galleries): ?>
          <label class="field full"><span>Rejoindre une galerie commerciale (facultatif)</span>
            <select name="gallery"><option value="">Pas pour le moment</option>
              <?php foreach ($galleries as $g): ?><option value="<?= saas_e($g['slug']) ?>"<?= $v['gallery'] === $g['slug'] ? ' selected' : '' ?>><?= saas_e($g['name']) ?></option><?php endforeach; ?></select>
            <small>Votre boutique y sera ajoutée dès sa création.</small></label>
        <?php endif; ?>
      </div>
      <p style="margin:22px 0 0"><button class="btn" type="submit">Créer ma boutique</button></p>
    </form>
  <?php endif; ?>
</div>
<?php if ($errors): ?><script>(function(){var f=document.querySelector('.has-error input,.has-error textarea,.has-error select');if(f){f.scrollIntoView({block:'center'});f.focus({preventScroll:true});}})();</script><?php endif; ?>
<?php saas_page_end(); ?>
