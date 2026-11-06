<?php
// Équipe du projet : conseillers, testeurs (réels ou virtuels) et partenaires
// financiers, par domaine (design front-end, back-end, développement, marketing,
// commercial). Page de gestion : fiches, candidatures reçues par le parcours
// d'inscription, conditions des partenaires, liens d'invitation et import de
// contacts (equipe-import.php). Les écritures passent par equipe-action.php.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/equipe.php';

const EQ_PAGE_TITLE = 'Équipe du projet';

/** Adresse absolue du portail (liens d'inscription à partager). */
function equipe_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function equipe_invite_url(string $code): string
{
    return equipe_origin() . '/portail/equipe-inscription.php?invitation=' . rawurlencode($code);
}

function equipe_initials(array $member): string
{
    $name = $member['name'] !== '' ? $member['name'] : ($member['email'] !== '' ? $member['email'] : '?');
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $first = mb_substr((string) ($parts[0] ?? ''), 0, 1);
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last) ?: '?';
}

$vue = in_array($_GET['vue'] ?? '', ['membres', 'candidatures', 'partenaires', 'invitations'], true) ? (string) $_GET['vue'] : 'membres';
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'category' => isset(EQ_CATEGORIES[(string) ($_GET['cat'] ?? '')]) ? (string) $_GET['cat'] : '',
    'domain' => isset(EQ_DOMAINS[(string) ($_GET['domaine'] ?? '')]) ? (string) $_GET['domaine'] : '',
    'status' => isset(EQ_STATUSES[(string) ($_GET['statut'] ?? '')]) ? (string) $_GET['statut'] : '',
];
$members = eq_members($filters);
$allMembers = eq_members();
$stats = eq_stats();
$applications = eq_applications();
$pendingApplications = array_values(array_filter($applications, static fn (array $a): bool => $a['status'] === 'nouvelle'));
$decidedApplications = array_values(array_filter($applications, static fn (array $a): bool => $a['status'] !== 'nouvelle'));
$partners = array_values(array_filter($allMembers, static fn (array $m): bool => $m['category'] === 'partenaire'));
$settings = eq_settings();
$invitations = eq_invitations();

// Export CSV de l'équipe filtrée.
if (($_GET['export'] ?? '') === 'csv') {
    $rows = eq_export_rows($members);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="equipe-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($rows as $row) fputcsv($out, $row, ';', '"', '');
    fclose($out);
    exit;
}

$flash = portail_flash();
$editing = null;
if ($vue === 'membres' && !empty($_GET['edit'])) $editing = eq_member_load((string) $_GET['edit']);
$creating = $vue === 'membres' && isset($_GET['nouveau']);
$backUrl = static fn (): string => '/portail/equipe.php?vue=membres' . ($filters['q'] !== '' ? '&q=' . urlencode($filters['q']) : '');
$formUrl = '/portail/equipe-action.php';
$tabs = [
    ['label' => 'Membres (' . count($allMembers) . ')', 'href' => '/portail/equipe.php?vue=membres', 'active' => $vue === 'membres'],
    ['label' => 'Candidatures (' . count($pendingApplications) . ')', 'href' => '/portail/equipe.php?vue=candidatures', 'active' => $vue === 'candidatures'],
    ['label' => 'Partenaires (' . count($partners) . ')', 'href' => '/portail/equipe.php?vue=partenaires', 'active' => $vue === 'partenaires'],
    ['label' => 'Invitations', 'href' => '/portail/equipe.php?vue=invitations', 'active' => $vue === 'invitations'],
];
$csrf = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(EQ_PAGE_TITLE) ?> — Portail des commerces</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .eq-wrap { display: grid; gap: 16px; max-width: 1180px; }
  .eq-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; padding: 12px 16px; border: 1px solid var(--line); border-radius: 12px; background: var(--panel-2); }
  .eq-bar label { display: grid; gap: 4px; font-size: 0.74rem; font-weight: 700; color: var(--muted); }
  .eq-bar input[type="search"], .eq-bar select { min-width: 150px; }
  .eq-bar .eq-grow { flex: 1 1 220px; }
  .eq-bar .eq-grow input { width: 100%; }
  .eq-table { width: 100%; }
  .eq-member { display: flex; align-items: center; gap: 10px; min-width: 200px; }
  .eq-avatar { display: grid; place-items: center; width: 34px; height: 34px; flex: none; border-radius: 10px; background: var(--accent-soft); color: var(--accent); font-weight: 700; font-size: 0.8rem; }
  .eq-member b { display: block; font-size: 0.9rem; }
  .eq-member span { display: block; color: var(--muted); font-size: 0.76rem; }
  .eq-tags { display: flex; gap: 4px; flex-wrap: wrap; }
  .eq-tag { display: inline-block; padding: 1px 8px; border: 1px solid var(--line); border-radius: 999px; font-size: 0.72rem; font-weight: 600; color: var(--muted); }
  .eq-tag.cat { border-color: color-mix(in srgb, var(--accent) 30%, var(--line)); color: var(--accent); }
  .eq-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .eq-actions form { display: inline-flex; gap: 4px; align-items: center; margin: 0; }
  .eq-actions select { padding: 4px 6px; font-size: 0.78rem; }
  .eq-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
  .eq-grid label { display: grid; gap: 4px; font-size: 0.78rem; font-weight: 700; color: var(--muted); }
  .eq-grid label input, .eq-grid label select { width: 100%; }
  .eq-grid .eq-full { grid-column: 1 / -1; }
  .eq-grid textarea { width: 100%; min-height: 74px; resize: vertical; }
  .eq-checks { display: grid; gap: 6px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
  .eq-check { display: flex; gap: 7px; align-items: flex-start; font-size: 0.84rem; color: var(--ink); font-weight: 500; padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); cursor: pointer; }
  .eq-check input { margin-top: 2px; }
  .eq-check small { display: block; color: var(--muted); font-size: 0.72rem; font-weight: 400; }
  .eq-sub { display: grid; gap: 10px; padding: 12px 14px; border: 1px dashed var(--line-strong); border-radius: 10px; background: var(--panel-2); }
  .eq-sub h3 { margin: 0; font-size: 0.92rem; }
  .eq-form-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 4px; }
  .eq-card { display: grid; gap: 10px; padding: 14px 16px; border: 1px solid var(--line); border-radius: 12px; background: var(--panel); }
  .eq-card h3 { margin: 0; font-size: 1rem; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .eq-card p { margin: 0; }
  .eq-meta { color: var(--muted); font-size: 0.82rem; }
  .eq-msg { margin-top: 4px; padding: 9px 11px; border-left: 3px solid var(--line-strong); background: var(--panel-2); border-radius: 0 8px 8px 0; font-size: 0.86rem; white-space: pre-line; }
  .eq-invite { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .eq-invite code { font-family: var(--font-mono); font-size: 0.78rem; padding: 5px 8px; border: 1px solid var(--line); border-radius: 7px; background: var(--panel-2); word-break: break-all; }
  .eq-code { font-family: var(--font-mono); font-size: 1.25rem; letter-spacing: 0.14em; font-weight: 700; }
  .eq-warn { color: var(--danger); }
  .eq-ok { color: var(--ok); }
  @media (max-width: 760px) { .eq-table thead { display: none; } .eq-table, .eq-table tbody, .eq-table tr, .eq-table td { display: block; width: 100%; } .eq-table tr { border: 1px solid var(--line); border-radius: 10px; margin-bottom: 8px; } .eq-table td { border: 0; padding: 6px 10px; } }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => EQ_PAGE_TITLE,
    'eyebrow' => 'Collaborateurs',
    'desc' => 'Vos proches qui aident à finaliser le projet : conseils, tests et partenariat financier. Chaque personne a une catégorie, des domaines et, pour les partenaires, des conditions à cadrer.',
    'actions' => '<a class="btn btn-primary" href="/portail/equipe.php?vue=membres&amp;nouveau=1#fiche">' . portail_icon('plus', 16) . ' Ajouter un membre</a>'
        . '<a class="btn" href="/portail/equipe-import.php">' . portail_icon('upload', 16) . ' Importer des contacts</a>',
    'tabs' => $tabs,
]); ?>

  <?php if ($flash): ?>
    <p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p>
  <?php endif; ?>

  <div class="eq-wrap">
    <div class="kpis">
      <div class="kpi"><span class="n"><?= (int) $stats['actifs'] ?></span><span class="l">Membres actifs</span><span class="s"><?= (int) $stats['total'] ?> fiche<?= $stats['total'] > 1 ? 's' : '' ?> au total</span></div>
      <div class="kpi<?= $stats['attente'] ? ' bad' : '' ?>"><span class="n"><?= (int) $stats['attente'] ?></span><span class="l">Candidatures à traiter</span><span class="s"><a href="/portail/equipe.php?vue=candidatures"><?= $stats['attente'] ? 'Ouvrir la file' : 'File à jour' ?></a></span></div>
      <div class="kpi"><span class="n"><?= (int) $stats['par_categorie']['conseiller'] ?></span><span class="l">Conseillers</span><span class="s"><?= (int) $stats['par_categorie']['testeur'] ?> testeur<?= $stats['par_categorie']['testeur'] > 1 ? 's' : '' ?></span></div>
      <div class="kpi<?= $stats['partenaires_a_definir'] ? ' bad' : '' ?>"><span class="n"><?= (int) $stats['par_categorie']['partenaire'] ?></span><span class="l">Partenaires</span><span class="s"><?= $stats['partenaires_a_definir'] ? (int) $stats['partenaires_a_definir'] . ' condition(s) à définir' : 'Conditions cadrées' ?></span></div>
    </div>

    <?php if ($vue === 'membres'): ?>
      <?php if ($creating || $editing):
          $m = $editing ?? array_merge(eq_member_normalize([]), ['id' => '', 'status' => 'actif', 'source' => 'portail']);
          $isEdit = $editing !== null; ?>
        <section class="panel" id="fiche" aria-labelledby="fiche-title">
          <div class="panel-head">
            <div>
              <h2 id="fiche-title"><?= $isEdit ? 'Modifier ' . e($m['name'] !== '' ? $m['name'] : $m['email']) : 'Nouveau membre' ?></h2>
              <p class="hint">Une fiche suffit pour démarrer : nom et un moyen de contact. Les domaines servent aux filtres et à l’import.</p>
            </div>
            <a class="btn small" href="<?= e($backUrl()) ?>">Fermer</a>
          </div>
          <form method="post" action="<?= e($formUrl) ?>" class="eq-grid" autocomplete="off">
            <?= $csrf ?>
            <input type="hidden" name="action" value="member_save">
            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
            <label>Nom complet<input type="text" name="name" maxlength="120" required value="<?= e($m['name']) ?>" placeholder="Marie Dupont"></label>
            <label>E-mail<input type="email" name="email" maxlength="190" value="<?= e($m['email']) ?>" placeholder="marie@exemple.fr"></label>
            <label>Téléphone<input type="text" name="phone" maxlength="40" value="<?= e($m['phone']) ?>" placeholder="06 12 34 56 78"></label>
            <label>Ville<input type="text" name="city" maxlength="90" value="<?= e($m['city']) ?>"></label>
            <label>Catégorie<select name="category"><?php foreach (EQ_CATEGORIES as $key => $cat): ?><option value="<?= e($key) ?>"<?= $m['category'] === $key ? ' selected' : '' ?>><?= e($cat['label']) ?></option><?php endforeach; ?></select></label>
            <label>Statut<select name="status"><?php foreach (EQ_STATUSES as $key => $label): ?><option value="<?= e($key) ?>"<?= $m['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <div class="eq-full">
              <p class="eq-meta" style="margin:0 0 6px;">Domaines d’intervention</p>
              <div class="eq-checks">
                <?php foreach (EQ_DOMAINS as $key => $label): ?>
                  <label class="eq-check"><input type="checkbox" name="domains[]" value="<?= e($key) ?>"<?= in_array($key, $m['domains'], true) ? ' checked' : '' ?>> <span><?= e($label) ?></span></label>
                <?php endforeach; ?>
              </div>
            </div>
            <label>Disponibilité<select name="heures"><option value="">— non précisé —</option><?php foreach (EQ_HEURES as $heure): ?><option value="<?= e($heure) ?>"<?= $m['heures'] === $heure ? ' selected' : '' ?>><?= e($heure) ?></option><?php endforeach; ?></select></label>
            <label>Format<input type="text" name="dispo" maxlength="120" value="<?= e($m['dispo']) ?>" placeholder="Visio le soir, sur place le week-end…"></label>
            <label class="eq-full">Note<textarea name="note" maxlength="2000" placeholder="Comment cette personne aide le projet, ce qui a été convenu…"><?= e($m['note']) ?></textarea></label>

            <div class="eq-sub eq-full">
              <h3>Profil testeur</h3>
              <div class="eq-grid">
                <label>Type de testeur<select name="testeur[mode]"><?php foreach (EQ_TEST_MODES as $key => $label): ?><option value="<?= e($key) ?>"<?= $m['testeur']['mode'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label class="eq-full">Appareils, outils, scénarios<textarea name="testeur[outils]" maxlength="600" placeholder="iPhone + Chrome, parcourt la boutique sur mobile, teste les paiements…"><?= e($m['testeur']['outils']) ?></textarea></label>
              </div>
            </div>

            <div class="eq-sub eq-full">
              <h3>Conditions du partenariat <span class="eq-meta">— pour les partenaires financiers</span></h3>
              <div class="eq-grid">
                <label>Nature<select name="partenaire[nature]"><?php foreach (EQ_PARTNER_NATURES as $key => $label): ?><option value="<?= e($key) ?>"<?= $m['partenaire']['nature'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label>Montant<input type="text" name="partenaire[montant]" maxlength="60" value="<?= e($m['partenaire']['montant']) ?>" placeholder="ex. 3 000 € ou 10 %"></label>
                <label>Échéance<input type="text" name="partenaire[echeance]" maxlength="60" value="<?= e($m['partenaire']['echeance']) ?>" placeholder="ex. décembre 2026"></label>
                <label>État<select name="partenaire[statut]"><?php foreach (EQ_PARTNER_STATUSES as $key => $label): ?><option value="<?= e($key) ?>"<?= $m['partenaire']['statut'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label class="eq-full">Contreparties<textarea name="partenaire[contreparties]" maxlength="1200" placeholder="Visibilité, remboursement, part des revenus, accès aux boutiques…"><?= e($m['partenaire']['contreparties']) ?></textarea></label>
                <label class="eq-full">Notes internes<textarea name="partenaire[notes]" maxlength="1200"><?= e($m['partenaire']['notes']) ?></textarea></label>
              </div>
            </div>

            <div class="eq-form-actions eq-full">
              <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Enregistrer la fiche' : 'Créer la fiche' ?></button>
              <a class="btn" href="<?= e($backUrl()) ?>">Annuler</a>
              <?php if ($isEdit): ?><span class="eq-meta">Créée le <?= e(date('d/m/Y', $m['created'])) ?></span><?php endif; ?>
            </div>
          </form>
        </section>
      <?php endif; ?>

      <section class="panel" aria-labelledby="list-title">
        <div class="panel-head">
          <div>
            <h2 id="list-title">Membres <small><?= count($members) ?></small></h2>
            <p class="hint"><?= count($allMembers) ?> fiche<?= count($allMembers) > 1 ? 's' : '' ?> · filtrez par catégorie, domaine ou statut.</p>
          </div>
          <a class="btn small" href="/portail/equipe.php?vue=membres&amp;export=csv&amp;<?= e(http_build_query(array_filter(['q' => $filters['q'], 'cat' => $filters['category'], 'domaine' => $filters['domain'], 'statut' => $filters['status']]))) ?>">Exporter le CSV</a>
        </div>
        <form method="get" action="/portail/equipe.php" class="eq-bar">
          <input type="hidden" name="vue" value="membres">
          <label class="eq-grow">Rechercher<input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nom, e-mail, ville, note…"></label>
          <label>Catégorie<select name="cat"><option value="">Toutes</option><?php foreach (EQ_CATEGORIES as $key => $cat): ?><option value="<?= e($key) ?>"<?= $filters['category'] === $key ? ' selected' : '' ?>><?= e($cat['label']) ?></option><?php endforeach; ?></select></label>
          <label>Domaine<select name="domaine"><option value="">Tous</option><?php foreach (EQ_DOMAINS as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['domain'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label>Statut<select name="statut"><option value="">Tous</option><?php foreach (EQ_STATUSES as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <button class="btn small" type="submit">Filtrer</button>
          <?php if (array_filter($filters)): ?><a class="btn small" href="/portail/equipe.php?vue=membres">Réinitialiser</a><?php endif; ?>
        </form>

        <?php if (!$members): ?>
          <div class="empty-state">
            <b>Aucun membre<?= array_filter($filters) ? ' avec ces filtres' : ' pour le moment' ?></b>
            <?= array_filter($filters) ? 'Élargissez la recherche ou réinitialisez les filtres.' : 'Ajoutez une fiche ou partagez un lien d’inscription depuis l’onglet Invitations.' ?>
          </div>
        <?php else: ?>
          <div class="scroll">
            <table class="t eq-table">
              <thead><tr><th>Membre</th><th>Catégorie</th><th>Domaines</th><th>Profil</th><th>Statut</th><th>Depuis</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach ($members as $member):
                  $profile = '';
                  if ($member['category'] === 'testeur') $profile = $member['testeur']['mode'] === 'virtuel' ? 'Testeur virtuel' : 'Testeur réel';
                  elseif ($member['category'] === 'partenaire') $profile = EQ_PARTNER_STATUSES[$member['partenaire']['statut']] . ($member['partenaire']['montant'] !== '' ? ' · ' . $member['partenaire']['montant'] : '');
                  $incomplete = !$member['domains'] || ($member['email'] === '' && $member['phone'] === ''); ?>
                <tr>
                  <td>
                    <span class="eq-member">
                      <span class="eq-avatar" aria-hidden="true"><?= e(equipe_initials($member)) ?></span>
                      <span>
                        <b><?= e($member['name'] !== '' ? $member['name'] : '(sans nom)') ?></b>
                        <span><?= e($member['email'] !== '' ? $member['email'] : ($member['phone'] !== '' ? $member['phone'] : '—')) ?><?= $member['city'] !== '' ? ' · ' . e($member['city']) : '' ?></span>
                      </span>
                    </span>
                  </td>
                  <td><span class="eq-tag cat"><?= e(EQ_CATEGORIES[$member['category']]['label']) ?></span></td>
                  <td>
                    <span class="eq-tags">
                      <?php if (!$member['domains']): ?><span class="eq-tag eq-warn">à compléter</span><?php endif; ?>
                      <?php foreach ($member['domains'] as $domain): ?><span class="eq-tag"><?= e(EQ_DOMAINS[$domain]) ?></span><?php endforeach; ?>
                    </span>
                  </td>
                  <td class="eq-meta"><?= e($profile !== '' ? $profile : '—') ?><?= $member['heures'] !== '' ? '<br>' . e($member['heures']) : '' ?></td>
                  <td>
                    <span class="pill<?= $member['status'] === 'actif' ? ' done' : ($member['status'] === 'invite' ? ' sent' : '') ?>"><?= e(EQ_STATUSES[$member['status']]) ?></span>
                    <?php if ($incomplete): ?><br><span class="eq-tag eq-warn">profil à compléter</span><?php endif; ?>
                  </td>
                  <td class="eq-meta"><?= e(date('d/m/Y', $member['created'])) ?></td>
                  <td>
                    <div class="eq-actions">
                      <a class="btn small" href="/portail/equipe.php?vue=membres&amp;edit=<?= e($member['id']) ?>#fiche">Modifier</a>
                      <form method="post" action="<?= e($formUrl) ?>">
                        <?= $csrf ?><input type="hidden" name="action" value="member_status"><input type="hidden" name="id" value="<?= e($member['id']) ?>">
                        <select name="status" aria-label="Statut de <?= e($member['name']) ?>" onchange="this.form.submit()">
                          <?php foreach (EQ_STATUSES as $key => $label): ?><option value="<?= e($key) ?>"<?= $member['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                      </form>
                      <?php if ($member['category'] === 'partenaire'): ?><a class="btn small" href="/portail/equipe.php?vue=partenaires#p-<?= e($member['id']) ?>">Conditions</a><?php endif; ?>
                      <form method="post" action="<?= e($formUrl) ?>" onsubmit="return confirm('Supprimer définitivement la fiche de <?= e(addslashes($member['name'])) ?> ?');">
                        <?= $csrf ?><input type="hidden" name="action" value="member_delete"><input type="hidden" name="id" value="<?= e($member['id']) ?>">
                        <button class="btn small danger" type="submit">Supprimer</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    <?php elseif ($vue === 'candidatures'): ?>
      <section class="panel" aria-labelledby="applications-title">
        <div class="panel-head">
          <div>
            <h2 id="applications-title">Candidatures à traiter <small><?= count($pendingApplications) ?></small></h2>
            <p class="hint">Reçues par le parcours d’inscription (lien partagé, code d’accès ou inscription libre). Accepter crée la fiche membre.</p>
          </div>
          <a class="btn small" href="/portail/equipe.php?vue=invitations">Créer un lien d’inscription</a>
        </div>
        <?php if (!$pendingApplications): ?>
          <div class="empty-state"><b>Aucune candidature en attente</b>Partagez un lien d’inscription depuis l’onglet Invitations : les réponses arriveront ici.</div>
        <?php else: ?>
          <div class="eq-grid" style="grid-template-columns:1fr;">
            <?php foreach ($pendingApplications as $app): ?>
              <article class="eq-card">
                <h3><?= e($app['name'] !== '' ? $app['name'] : $app['email']) ?>
                  <span class="eq-tag cat"><?= e(EQ_CATEGORIES[$app['category']]['label']) ?></span>
                  <span class="eq-meta" style="font-weight:400;">reçue le <?= e(date('d/m/Y à H:i', $app['created'])) ?></span>
                </h3>
                <p class="eq-meta">
                  <?= e($app['email'] !== '' ? $app['email'] : '—') ?><?= $app['phone'] !== '' ? ' · ' . e($app['phone']) : '' ?><?= $app['city'] !== '' ? ' · ' . e($app['city']) : '' ?>
                  <?= $app['invitation'] !== '' ? ' · lien ' . e($app['invitation']) : '' ?>
                </p>
                <p class="eq-meta">
                  Domaines : <?= $app['domains'] ? e(implode(', ', array_map(static fn ($d) => EQ_DOMAINS[$d], $app['domains']))) : '—' ?>
                  <?php if ($app['category'] === 'testeur'): ?><br>Type : <?= e(EQ_TEST_MODES[$app['mode']]) ?><?= $app['outils'] !== '' ? ' · ' . e($app['outils']) : '' ?><?php endif; ?>
                  <?php if ($app['category'] === 'partenaire'): ?><br>Partenariat : <?= e(EQ_PARTNER_NATURES[$app['nature']]) ?><?= $app['montant'] !== '' ? ' · ' . e($app['montant']) : '' ?><?= $app['echeance'] !== '' ? ' · ' . e($app['echeance']) : '' ?><?php endif; ?>
                  <br>Disponibilité : <?= e($app['heures'] !== '' ? $app['heures'] : '—') ?><?= $app['dispo'] !== '' ? ' · ' . e($app['dispo']) : '' ?>
                </p>
                <?php if ($app['message'] !== ''): ?><p class="eq-msg"><?= e($app['message']) ?></p><?php endif; ?>
                <div class="eq-actions">
                  <form method="post" action="<?= e($formUrl) ?>">
                    <?= $csrf ?><input type="hidden" name="action" value="application_accept"><input type="hidden" name="id" value="<?= e($app['id']) ?>">
                    <select name="category" aria-label="Catégorie à l’acceptation"><?php foreach (EQ_CATEGORIES as $key => $cat): ?><option value="<?= e($key) ?>"<?= $app['category'] === $key ? ' selected' : '' ?>><?= e($cat['label']) ?></option><?php endforeach; ?></select>
                    <button class="btn small btn-primary" type="submit">Accepter et créer la fiche</button>
                  </form>
                  <form method="post" action="<?= e($formUrl) ?>" class="eq-invite">
                    <?= $csrf ?><input type="hidden" name="action" value="application_refuse"><input type="hidden" name="id" value="<?= e($app['id']) ?>">
                    <input type="text" name="reason" maxlength="400" placeholder="Motif (facultatif)" style="min-width:220px;">
                    <button class="btn small danger" type="submit">Refuser</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($decidedApplications): ?>
        <details class="panel">
          <summary style="cursor:pointer;font-weight:600;">Candidatures traitées (<?= count($decidedApplications) ?>)</summary>
          <div class="scroll" style="margin-top:10px;">
            <table class="t">
              <thead><tr><th>Nom</th><th>Catégorie</th><th>Décision</th><th>Date</th><th>Motif</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($decidedApplications as $app): ?>
                <tr>
                  <td><?= e($app['name']) ?><br><span class="eq-meta"><?= e($app['email']) ?></span></td>
                  <td><?= e(EQ_CATEGORIES[$app['category']]['label']) ?></td>
                  <td><span class="pill<?= $app['status'] === 'acceptee' ? ' done' : '' ?>"><?= e(EQ_APPLICATION_STATUSES[$app['status']]) ?></span></td>
                  <td class="eq-meta"><?= e(date('d/m/Y', $app['decided'] ?: $app['created'])) ?></td>
                  <td class="eq-meta"><?= e($app['reason'] !== '' ? $app['reason'] : '—') ?></td>
                  <td>
                    <?php if ($app['member_id'] !== ''): ?>
                      <a class="btn small" href="/portail/equipe.php?vue=membres&amp;edit=<?= e($app['member_id']) ?>#fiche">Ouvrir la fiche</a>
                    <?php else: ?>
                      <form method="post" action="<?= e($formUrl) ?>" onsubmit="return confirm('Retirer cette candidature de l’historique ?');">
                        <?= $csrf ?><input type="hidden" name="action" value="application_delete"><input type="hidden" name="id" value="<?= e($app['id']) ?>">
                        <button class="btn small" type="submit">Retirer</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>

    <?php elseif ($vue === 'partenaires'): ?>
      <section class="panel" aria-labelledby="partners-title">
        <div class="panel-head">
          <div>
            <h2 id="partners-title">Conditions des partenaires <small><?= count($partners) ?></small></h2>
            <p class="hint">Nature de l’apport, montant, échéance, contreparties et état de l’accord. « Conditions à définir » reste visible tant que rien n’est posé.</p>
          </div>
          <a class="btn small" href="/portail/equipe.php?vue=membres&amp;nouveau=1#fiche">Ajouter un partenaire</a>
        </div>
        <?php if (!$partners): ?>
          <div class="empty-state"><b>Aucun partenaire enregistré</b>Ajoutez une fiche avec la catégorie « Partenaire financier ».</div>
        <?php else: ?>
          <div class="eq-grid" style="grid-template-columns:1fr;">
            <?php foreach ($partners as $p):
                $open = eq_partner_open($p); ?>
              <article class="eq-card" id="p-<?= e($p['id']) ?>">
                <h3><?= e($p['name']) ?>
                  <span class="pill<?= $open ? '' : ' done' ?>"><?= e(EQ_PARTNER_STATUSES[$p['partenaire']['statut']]) ?></span>
                  <?php if ($open): ?><span class="eq-tag eq-warn">conditions à définir</span><?php endif; ?>
                  <span class="eq-meta" style="font-weight:400;"><?= e(EQ_PARTNER_NATURES[$p['partenaire']['nature']]) ?><?= $p['partenaire']['montant'] !== '' ? ' · ' . e($p['partenaire']['montant']) : '' ?><?= $p['partenaire']['echeance'] !== '' ? ' · ' . e($p['partenaire']['echeance']) : '' ?></span>
                </h3>
                <p class="eq-meta"><?= e($p['email'] !== '' ? $p['email'] : '—') ?><?= $p['phone'] !== '' ? ' · ' . e($p['phone']) : '' ?></p>
                <?php if ($p['partenaire']['contreparties'] !== ''): ?><p class="eq-msg"><?= e($p['partenaire']['contreparties']) ?></p><?php endif; ?>
                <details>
                  <summary style="cursor:pointer;font-weight:600;font-size:0.86rem;">Modifier les conditions</summary>
                  <form method="post" action="<?= e($formUrl) ?>" class="eq-grid" style="margin-top:10px;">
                    <?= $csrf ?><input type="hidden" name="action" value="partner_conditions"><input type="hidden" name="id" value="<?= e($p['id']) ?>">
                    <label>Nature<select name="nature"><?php foreach (EQ_PARTNER_NATURES as $key => $label): ?><option value="<?= e($key) ?>"<?= $p['partenaire']['nature'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    <label>Montant<input type="text" name="montant" maxlength="60" value="<?= e($p['partenaire']['montant']) ?>"></label>
                    <label>Échéance<input type="text" name="echeance" maxlength="60" value="<?= e($p['partenaire']['echeance']) ?>"></label>
                    <label>État<select name="statut"><?php foreach (EQ_PARTNER_STATUSES as $key => $label): ?><option value="<?= e($key) ?>"<?= $p['partenaire']['statut'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    <label class="eq-full">Contreparties<textarea name="contreparties" maxlength="1200"><?= e($p['partenaire']['contreparties']) ?></textarea></label>
                    <label class="eq-full">Notes internes<textarea name="notes" maxlength="1200"><?= e($p['partenaire']['notes']) ?></textarea></label>
                    <div class="eq-form-actions eq-full"><button class="btn btn-primary" type="submit">Enregistrer les conditions</button></div>
                  </form>
                </details>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    <?php else: ?>
      <section class="panel" aria-labelledby="invites-title">
        <div class="panel-head">
          <div>
            <h2 id="invites-title">Lien d’inscription</h2>
            <p class="hint">Partagez un lien : la personne remplit le parcours (5 étapes), sa candidature apparaît dans l’onglet Candidatures.</p>
          </div>
        </div>
        <div class="eq-grid" style="grid-template-columns:1fr;">
          <div class="eq-card">
            <h3>Code d’accès de l’équipe</h3>
            <p class="eq-meta">À communiquer avec le lien si vous ne créez pas d’invitation nominative. Il ouvre le parcours sans préselection.</p>
            <p class="eq-invite">
              <span class="eq-code"><?= e(implode('-', str_split($settings['code'], 4))) ?></span>
              <a class="btn small" href="/portail/equipe-inscription.php?code=<?= e($settings['code']) ?>" target="_blank" rel="noopener">Ouvrir le parcours ↗</a>
              <button class="btn small" type="button" data-copy="<?= e(equipe_origin() . '/portail/equipe-inscription.php?code=' . $settings['code']) ?>">Copier le lien</button>
            </p>
            <form method="post" action="<?= e($formUrl) ?>" class="eq-grid">
              <?= $csrf ?><input type="hidden" name="action" value="settings_save">
              <label class="eq-full">Recevoir les candidatures à cette adresse<input type="email" name="notify" maxlength="190" value="<?= e($settings['notify']) ?>" placeholder="vous@exemple.fr (facultatif)"></label>
              <label class="eq-check eq-full"><input type="checkbox" name="open" value="1"<?= $settings['open'] ? ' checked' : '' ?>> <span>Laisser l’inscription ouverte sans code ni invitation<small>Déconseillé en ligne : n’importe qui pourrait déposer une candidature.</small></span></label>
              <div class="eq-form-actions eq-full"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
            </form>
            <form method="post" action="<?= e($formUrl) ?>" onsubmit="return confirm('Générer un nouveau code ? Les liens qui utilisent l’ancien code cesseront de fonctionner (les invitations nominatives restent valables).');">
              <?= $csrf ?><input type="hidden" name="action" value="settings_code">
              <button class="btn small" type="submit">Générer un nouveau code</button>
            </form>
          </div>

          <div class="eq-card">
            <h3>Créer une invitation nominative</h3>
            <p class="eq-meta">Le lien préselectionne la catégorie et le domaine, et peut expirer ou être limité en nombre d’utilisations.</p>
            <form method="post" action="<?= e($formUrl) ?>" class="eq-grid">
              <?= $csrf ?><input type="hidden" name="action" value="invitation_create">
              <label class="eq-full">Intitulé (pour vous)<input type="text" name="label" maxlength="80" placeholder="ex. Dîner du 12 novembre — conseils"></label>
              <label>Catégorie<select name="category"><option value="">— laisser choisir —</option><?php foreach (EQ_CATEGORIES as $key => $cat): ?><option value="<?= e($key) ?>"><?= e($cat['label']) ?></option><?php endforeach; ?></select></label>
              <label>Domaine<select name="domain"><option value="">— laisser choisir —</option><?php foreach (EQ_DOMAINS as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
              <label>Expire dans (jours)<input type="number" name="days" min="0" max="365" value="0" placeholder="0 = jamais"></label>
              <label>Utilisations max<input type="number" name="max" min="0" max="500" value="0" placeholder="0 = illimité"></label>
              <div class="eq-form-actions eq-full"><button class="btn btn-primary" type="submit">Créer le lien</button></div>
            </form>
          </div>
        </div>
      </section>

      <section class="panel" aria-labelledby="invites-list-title">
        <div class="panel-head">
          <div><h2 id="invites-list-title">Invitations <small><?= count($invitations) ?></small></h2></div>
          <a class="btn small" href="/portail/equipe-import.php">Importer des contacts</a>
        </div>
        <?php if (!$invitations): ?>
          <div class="empty-state"><b>Aucune invitation nominative</b>Le code d’accès ci-dessus suffit pour inviter vos proches.</div>
        <?php else: ?>
          <div class="scroll">
            <table class="t">
              <thead><tr><th>Intitulé</th><th>Lien</th><th>Préselection</th><th>Utilisations</th><th>Expire</th><th>État</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($invitations as $inv):
                  $usable = eq_invitation_find($inv['code']);
                  $expired = $inv['expires'] > 0 && $inv['expires'] < time();
                  $state = !$inv['active'] ? 'Désactivée' : ($expired ? 'Expirée' : (($inv['max'] > 0 && $inv['uses'] >= $inv['max']) ? 'Quota atteint' : 'Active')); ?>
                <tr>
                  <td><?= e($inv['label'] !== '' ? $inv['label'] : 'Sans intitulé') ?><br><span class="eq-meta">créée le <?= e(date('d/m/Y', $inv['created'])) ?></span></td>
                  <td>
                    <div class="eq-invite">
                      <code><?= e(equipe_invite_url($inv['code'])) ?></code>
                      <button class="btn mini" type="button" data-copy="<?= e(equipe_invite_url($inv['code'])) ?>">Copier</button>
                    </div>
                  </td>
                  <td class="eq-meta"><?= e($inv['category'] !== '' ? EQ_CATEGORIES[$inv['category']]['label'] : '—') ?><?= $inv['domain'] !== '' ? ' · ' . e(EQ_DOMAINS[$inv['domain']]) : '' ?></td>
                  <td><?= (int) $inv['uses'] ?><?= $inv['max'] > 0 ? ' / ' . (int) $inv['max'] : '' ?></td>
                  <td class="eq-meta"><?= $inv['expires'] > 0 ? e(date('d/m/Y', $inv['expires'])) : 'jamais' ?></td>
                  <td><span class="pill<?= $usable ? ' done' : '' ?>"><?= e($state) ?></span></td>
                  <td>
                    <div class="eq-actions">
                      <form method="post" action="<?= e($formUrl) ?>">
                        <?= $csrf ?><input type="hidden" name="action" value="invitation_toggle"><input type="hidden" name="code" value="<?= e($inv['code']) ?>">
                        <button class="btn small" type="submit"><?= $inv['active'] ? 'Désactiver' : 'Réactiver' ?></button>
                      </form>
                      <form method="post" action="<?= e($formUrl) ?>" onsubmit="return confirm('Supprimer ce lien d’invitation ?');">
                        <?= $csrf ?><input type="hidden" name="action" value="invitation_delete"><input type="hidden" name="code" value="<?= e($inv['code']) ?>">
                        <button class="btn small danger" type="submit">Supprimer</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(function (button) {
  button.addEventListener('click', function () {
    var text = button.getAttribute('data-copy') || '';
    var done = function () { var was = button.textContent; button.textContent = 'Copié'; setTimeout(function () { button.textContent = was; }, 1400); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done).catch(function () {}); return; }
    var input = document.createElement('input'); input.value = text; document.body.appendChild(input); input.select();
    try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(input);
  });
});
</script>
</body>
</html>
