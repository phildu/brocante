<?php
// Créations de boutiques par les commerçants (page publique /inscription/, voir includes/saas.php). Aucune validation n'est nécessaire : la boutique est
// générée dès que le client valide le formulaire. Cette page suit les créations, les paiements dont la boutique reste à créer, et les anciennes demandes
// « à valider » (valider crée la boutique (tenants/<adresse>/), y installe le compte choisi par le commerçant (mot de passe haché), l'ajoute à la
// galerie demandée et lui écrit. Refuser lui écrit aussi (avec un motif facultatif). Rien n'est créé avant cette validation.
require __DIR__ . '/_bootstrap.php';
ext_page();   // jetons du vocabulaire ({Exts}, {un ext}…) remplacés à l'envoi de la page
require_once PORTAIL_ROOT . '/includes/saas.php';

$cfg = saas_config();
$planNames = array_column($cfg['plans'], 'name', 'key');
$origin = saas_origin();

/** Adresse de la boutique et de son administration : en ligne <domaine>/<adresse>/, en local l'adresse de Herd. */
function inscription_shop_urls(string $slug, string $origin): array
{
    $config = tenant_load($slug);
    $shop = gallery_local_shop_url($slug, $config, $origin);
    return [rtrim($shop, '/') . '/', rtrim($shop, '/') . '/admin/'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $req = saas_request_load((string) ($_POST['id'] ?? ''));
    if (!$req) {
        portail_flash('Demande introuvable.', 'error');
    } elseif ($action === 'remind_domain') {
        $error = saas_domain_followup_remind((string) $req['id']);
        portail_flash($error ?? 'Relance de nom de domaine envoyée.', $error ? 'error' : 'ok');
    } elseif ($action === 'resend' && $req['status'] === 'paid') {
        $ok = saas_mail($req['email'], 'Créez votre boutique — ' . $cfg['name'],
            "Bonjour,\n\nVotre paiement est bien reçu. Il ne reste qu'à créer votre boutique (nom, design, mot de passe) :\n\n" . saas_creation_url($req, $origin) . "\n\n" . $cfg['name'] . "\n");
        saas_event('link_resent', ['ref' => $req['id'], 'email' => $req['email'], 'plan' => $req['plan'], 'note' => $ok ? '' : 'e-mail non parti']);
        portail_flash($ok ? 'Lien renvoyé à ' . $req['email'] . '.' : "L'e-mail n'a pas pu partir : copiez le lien de création et transmettez-le vous-même.", $ok ? 'ok' : 'error');
    } elseif ($action === 'approve' && in_array($req['status'], ['pending', 'paid'], true) && $req['shop_name'] !== '' && !empty($req['password_hash'])) {
        try {
            $gen = saas_generate_shop($req, $origin, (string) ($_POST['gallery'] ?? ''));
            saas_request_save(array_merge($req, ['status' => 'approved', 'approved' => time(), 'shop_url' => $gen['shop_url'], 'gallery' => $gen['gallery'], 'login' => $gen['login'], 'error' => '']));
            saas_event('shop_created_approved', ['slug' => $gen['slug'], 'ref' => $req['id'], 'email' => $req['email'], 'plan' => $req['plan']]);
            portail_flash('Boutique « ' . $req['shop_name'] . ' » créée' . ($gen['gallery_name'] !== '' ? " et ajoutée à la galerie « {$gen['gallery_name']} »" : '') . ". Identifiant du commerçant : {$gen['login']}."
                . ($gen['mailed'] ? ' Un e-mail lui a été envoyé.' : " L'e-mail n'a pas pu partir : transmettez-lui les liens vous-même."));
        } catch (Throwable $e) {
            portail_flash($e->getMessage(), 'error');
        }
    } elseif ($action === 'reject' && $req['status'] === 'pending') {
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 400);
        saas_request_save(array_merge($req, ['status' => 'rejected', 'rejected' => time(), 'reason' => $reason]));
        saas_mail($req['email'], 'Votre demande — ' . $cfg['name'],
            "Bonjour {$req['first_name']},\n\nNous ne pouvons pas donner suite à votre demande de boutique « {$req['shop_name']} » pour le moment."
            . ($reason !== '' ? "\n\n$reason" : '') . "\n\nN'hésitez pas à nous répondre pour en parler.\n" . $cfg['name'] . "\n");
        saas_event('request_rejected', ['ref' => $req['id'], 'email' => $req['email'], 'plan' => $req['plan'], 'note' => $reason]);
        portail_flash('Demande refusée. Le commerçant a été prévenu par e-mail.');
    } elseif ($action === 'forget' && in_array($req['status'], ['rejected', 'approved'], true)) {
        saas_event('request_forgotten', ['ref' => $req['id'], 'email' => $req['email'], 'slug' => $req['slug'] ?? '', 'plan' => $req['plan']]);
        @unlink(saas_requests_dir() . '/' . $req['id'] . '.json');
        portail_flash('Demande retirée de la liste (la boutique créée, elle, reste en place).');
    }
    header('Location: /portail/inscriptions.php');
    exit;
}

$flash = portail_flash();
$requests = saas_requests();
$pending = array_values(array_filter($requests, static fn ($r) => $r['status'] === 'pending'));
$awaiting = array_values(array_filter($requests, static fn ($r) => $r['status'] === 'awaiting_payment'));
$paidOpen = array_values(array_filter($requests, static fn ($r) => $r['status'] === 'paid'));
$done = array_values(array_filter($requests, static fn ($r) => !in_array($r['status'], ['pending', 'awaiting_payment', 'paid', 'draft'], true)));
$domainFollowups = array_values(array_filter($requests, static fn ($r) =>
    ($r['branding']['domain_followup']['status'] ?? '') === 'pending'
    && !empty($r['branding']['domain_followup']['consented_at'])
    && !in_array(($r['status'] ?? ''), ['cancelled', 'rejected'], true)
));
$galleries = gallery_list();
$dt = static fn (int $t): string => date('d/m/Y à H:i', $t);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Inscriptions — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .iwrap { display: grid; gap: 16px; max-width: 920px; margin: 0 auto; padding: 0 16px 48px; }
  .icard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .icard h2 { margin: 0; font-size: 1.05rem; }
  .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  dl.d { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 0; font-size: .92rem; }
  dl.d dt { opacity: .65; } dl.d dd { margin: 0; word-break: break-word; }
  .icard select, .icard input[type=text] { padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; }
  .icard input[type=text] { flex: 1; min-width: 220px; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Créations de boutiques',
    'eyebrow' => 'Pilotage',
    'desc' => 'Boutiques créées par les commerçants depuis la page publique « Créer ma boutique ».',
    'actions' => '<a class="btn" href="' . e($origin) . '/inscription/" target="_blank" rel="noopener">Voir la page publique</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="iwrap">
    <p class="meta" style="margin:0">Les boutiques se créent <strong>toutes seules</strong> : le client choisit sa formule, paie si elle est payante, puis crée sa boutique et arrive dessus, sans validation de votre part. Cette page liste les créations récentes et les paiements dont la boutique reste à créer.</p>
    <?php if ($pending): ?><h2 style="margin:0"><?= count($pending) ?> demande<?= count($pending) > 1 ? 's' : '' ?> à valider (anciennes demandes)</h2><?php endif; ?>

    <?php if ($domainFollowups): ?>
      <section class="icard">
        <h2>Relances de nom de domaine</h2>
        <p class="meta" style="margin:0">Les clients ci-dessous ont demandé à choisir leur domaine plus tard et ont accepté une relance. Relance manuelle après 7 jours, au plus 3 fois.</p>
        <?php foreach ($domainFollowups as $r):
            $followup = (array) $r['branding']['domain_followup'];
            $reminders = array_values(array_filter((array) ($followup['reminders'] ?? []), 'is_numeric'));
            $last = $reminders ? max(array_map('intval', $reminders)) : (int) ($followup['requested_at'] ?? 0);
            $canRemind = count($reminders) < SAAS_DOMAIN_FOLLOWUP_MAX && time() - $last >= SAAS_DOMAIN_FOLLOWUP_DELAY;
            $suggestions = array_values(array_filter((array) ($followup['suggestions'] ?? []), 'is_string'));
            $domains = array_values(array_filter((array) ($followup['domains'] ?? []), static fn ($d) => is_array($d) && !empty($d['available'])));
            $domainNames = array_map(static fn (array $d): string => (string) ($d['domain'] ?? ''), array_slice($domains, 0, 8));
        ?>
          <div class="row" style="justify-content:space-between;border-top:1px solid var(--line);padding-top:10px">
            <div>
              <strong><?= e((string) ($r['preferred_shop_name'] ?? $r['shop_name'] ?? 'Boutique')) ?></strong>
              <small class="meta"><?= e((string) ($r['email'] ?? '')) ?> · <?= e(saas_offer_badge($r)) ?> · <?= count($reminders) ?>/<?= SAAS_DOMAIN_FOLLOWUP_MAX ?> relances</small>
              <?php if ($suggestions || $domains): ?><small class="meta">Idées : <?= e(implode(', ', array_slice($suggestions, 0, 6))) ?><?= $domainNames ? ' · Domaines : ' . e(implode(', ', $domainNames)) : '' ?></small><?php endif; ?>
              <?php if (!$canRemind): ?><small class="meta"><?= count($reminders) >= SAAS_DOMAIN_FOLLOWUP_MAX ? 'Relances terminées.' : 'Prochaine relance possible le ' . e(date('d/m/Y', $last + SAAS_DOMAIN_FOLLOWUP_DELAY)) ?></small><?php endif; ?>
            </div>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
              <input type="hidden" name="action" value="remind_domain">
              <button class="btn small" type="submit"<?= $canRemind ? '' : ' disabled' ?>>Relancer</button>
            </form>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <?php foreach ($pending as $r): ?>
      <section class="icard">
        <h2><?= e($r['shop_name']) ?> <span class="pill sent"><?= e(saas_offer_badge($r)) ?></span>
          <?php if (($r['payment']['state'] ?? '') === 'paid'): ?><span class="pill done">Payée</span><?php endif; ?> <small class="meta">reçue le <?= e($dt((int) $r['created'])) ?></small></h2>
        <?php if (!empty($r['error'])): ?><p class="flash" data-kind="error" style="margin:0"><?= e($r['error']) ?></p><?php endif; ?>
        <dl class="d">
          <dt>Adresse souhaitée</dt><dd><code><?= e($origin) ?>/<?= e($r['slug']) ?>/</code><?= is_dir(PORTAIL_ROOT . '/tenants/' . $r['slug']) ? ' <strong style="color:#b3261e">— déjà prise !</strong>' : '' ?></dd>
          <dt>Contact</dt><dd><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?> — <a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?= $r['phone'] !== '' ? ' — ' . e($r['phone']) : '' ?></dd>
          <?php if ($r['about'] !== ''): ?><dt>Vend</dt><dd><?= nl2br(e($r['about'])) ?></dd><?php endif; ?>
          <dt>Design</dt><dd><?= e(SHOP_TEMPLATES[$r['template'] ?? '']['label'] ?? 'Brocante') ?></dd>
          <dt>Identifiant admin</dt><dd><code><?= e(saas_admin_login($r)) ?></code></dd>
        </dl>
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="action" value="approve">
          <?php if ($galleries): ?>
            <label class="meta">Galerie :
              <select name="gallery"><option value="">Aucune</option>
                <?php foreach ($galleries as $g): ?><option value="<?= e($g['slug']) ?>"<?= $r['gallery'] === $g['slug'] ? ' selected' : '' ?>><?= e($g['name']) ?><?= $r['gallery'] === $g['slug'] ? ' (demandée)' : '' ?></option><?php endforeach; ?></select></label>
          <?php endif; ?>
          <button class="btn btn-primary" type="submit">Valider et créer la boutique</button>
        </form>
        <form method="post" class="row" autocomplete="off" onsubmit="return confirm('Refuser cette demande ? Le commerçant en sera informé par e-mail.');">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="action" value="reject">
          <input type="text" name="reason" maxlength="400" placeholder="Motif du refus (facultatif, transmis au commerçant)">
          <button class="btn danger" type="submit">Refuser</button>
        </form>
      </section>
    <?php endforeach; ?>

    <?php if ($paidOpen): ?>
      <h2 style="margin:18px 0 0"><?= count($paidOpen) ?> payée<?= count($paidOpen) > 1 ? 's' : '' ?> : boutique pas encore créée</h2>
      <p class="meta">Le client a payé et doit créer sa boutique avec le lien reçu par e-mail. Vous pouvez lui renvoyer ce lien ; si son formulaire est rempli mais la création a échoué, vous pouvez la lancer ici.</p>
      <?php foreach ($paidOpen as $r): $filled = $r['shop_name'] !== '' && !empty($r['password_hash']); ?>
        <section class="icard">
          <div><strong><?= e($r['shop_name'] !== '' ? $r['shop_name'] : '(nom pas encore choisi)') ?></strong> <span class="pill sent"><?= e(saas_offer_badge($r)) ?></span> <span class="pill done">Payée</span>
            <small class="meta"><?= e($r['email']) ?> · payée le <?= e($dt((int) ($r['payment']['paid_at'] ?? $r['created']))) ?></small></div>
          <?php if ($serviceQuoteLabels = saas_service_quote_names((array) ($r['service_quotes'] ?? []))): ?><p class="meta">Prestations demandées : <?= e(implode(', ', $serviceQuoteLabels)) ?> — à chiffrer</p><?php endif; ?>
          <?php if ($pluginNames = saas_plugin_interest_names((array) ($r['plugins'] ?? []))): ?><p class="meta">{Exts} {?cochés|cochées} à l'inscription : <?= e(implode(', ', $pluginNames)) ?> — à activer après la création</p><?php endif; ?>
          <?php if (!empty($r['error'])): ?><p class="flash" data-kind="error" style="margin:0"><?= e($r['error']) ?></p><?php endif; ?>
          <div class="row">
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="action" value="resend"><button class="btn small" type="submit">Renvoyer le lien de création</button></form>
            <?php if ($filled): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="action" value="approve"><button class="btn small btn-primary" type="submit">Créer la boutique maintenant</button></form><?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($awaiting): ?>
      <h2 style="margin:18px 0 0"><?= count($awaiting) ?> en attente de paiement</h2>
      <p class="meta">Le client est sur la page de paiement Stripe (ou l'a quittée). Dès que le paiement est confirmé, il reçoit le lien pour créer sa boutique ; sans paiement, la demande se libère au bout de 24 h.</p>
      <?php foreach ($awaiting as $r): ?>
        <div class="icard" style="padding:12px 16px"><div><strong><?= e($r['shop_name']) ?></strong> <span class="pill sent"><?= e(saas_offer_badge($r)) ?></span>
          <small class="meta"><?= e($r['email']) ?> · demande du <?= e($dt((int) $r['created'])) ?><?= saas_request_active($r) ? '' : ' · expirée' ?></small></div></div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($done): ?>
      <h2 style="margin:18px 0 0">Historique</h2>
      <?php foreach ($done as $r): ?>
        <div class="icard" style="padding:12px 16px">
          <div class="row" style="justify-content:space-between">
            <span><strong><?= e($r['shop_name']) ?></strong>
              <span class="pill <?= $r['status'] === 'approved' ? 'done' : 'sent' ?>"><?= $r['status'] === 'approved' ? 'Boutique créée' : 'Refusée' ?></span>
              <?php if (($r['payment']['state'] ?? '') === 'paid'): ?><span class="pill done">Payée</span><?php endif; ?>
              <?php if (($r['payment']['state'] ?? '') === 'canceled'): ?><span class="pill sent">Abonnement résilié</span><?php endif; ?>
              <small class="meta"><?= e($r['email']) ?> · <?= e(saas_offer_badge($r)) ?> · <?= e($dt((int) ($r['approved'] ?? $r['rejected'] ?? $r['created']))) ?></small>
              <?php if ($r['status'] === 'approved' && !empty($r['shop_url'])): ?> · <a href="<?= e($r['shop_url']) ?>" target="_blank" rel="noopener">ouvrir la boutique</a><?php endif; ?>
              <?php $serviceQuoteLabels = saas_service_quote_names((array) ($r['service_quotes'] ?? [])); ?>
              <?php if ($serviceQuoteLabels): ?><br><small class="meta">Prestations demandées : <?= e(implode(', ', $serviceQuoteLabels)) ?> — à chiffrer</small><?php endif; ?>
              <?php if ($pluginNames = saas_plugin_interest_names((array) ($r['plugins'] ?? []))): ?><br><small class="meta">{Exts} {?cochés|cochées} à l'inscription : <?= e(implode(', ', $pluginNames)) ?> — à activer</small><?php endif; ?>
              <?php if ($r['status'] === 'rejected' && !empty($r['reason'])): ?><br><small class="meta">Motif : <?= e($r['reason']) ?></small><?php endif; ?></span>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="action" value="forget"><button class="btn small" type="submit">Retirer de la liste</button></form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
