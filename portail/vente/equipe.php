<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();

if (isset($_GET['quitter'])) {
    unset($_SESSION['fv_view']);
    fv_redirect('index.php');
}
if (!$ctx['real_manager']) {
    fv_flash('L’équipe de vente se gère depuis le portail exploitant.', 'error');
    fv_redirect('index.php');
}

$pdo = fv_db();
$canManage = $ctx['manager'];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/portail/vente/equipe.php'))), '/');
$loginUrl = $scheme . '://' . $host . $base . '/connexion.php';
$brand = fv_setting('marque_nom', 'Brocs');

if (isset($_GET['code']) && $_GET['code'] === 'effacer') {
    unset($_SESSION['fv_access_once']);
    fv_redirect('equipe.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fv_csrf_check();
    if (!$canManage) {
        fv_flash('Action réservée à la direction.', 'error');
        fv_redirect('equipe.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'creer' || $action === 'modifier') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = fv_text($_POST['name'] ?? '', 120);
        $email = mb_strtolower(fv_text($_POST['email'] ?? '', 190));
        $role = fv_choice($_POST['role'] ?? '', fv_roles(), 'commercial');
        $phone = fv_text($_POST['phone'] ?? '', 40);
        $city = fv_text($_POST['city'] ?? '', 90);
        $zone = fv_text($_POST['zone'] ?? '', 90);
        $shopSlug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', fv_text($_POST['shop_slug'] ?? '', 60)) ?? '');
        $shopSlug = trim($shopSlug, '-');
        $goal = max(0, min(999, (int) ($_POST['goal_month'] ?? 0)));
        $back = $action === 'modifier' ? 'equipe.php?editer=' . $id : 'equipe.php';

        if ($name === '') {
            fv_flash('Le nom du membre est obligatoire.', 'error');
            fv_redirect($back);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fv_flash('Indiquez une adresse e-mail valide : elle sert d’identifiant de connexion.', 'error');
            fv_redirect($back);
        }
        $existing = fv_member_by_email($email);
        if ($existing && ($action === 'creer' || (int) $existing['id'] !== $id)) {
            fv_flash('Cette adresse e-mail est déjà utilisée par ' . $existing['name'] . '.', 'error');
            fv_redirect($back);
        }

        if ($action === 'creer') {
            $code = fv_access_code_new();
            $hash = password_hash(fv_code_normalize($code), PASSWORD_DEFAULT);
            $st = $pdo->prepare('INSERT INTO members (name, role, email, phone, city, zone, shop_slug, access_hash, active, goal_month, created_at) VALUES (?,?,?,?,?,?,?,?,1,?,?)');
            $st->execute([$name, $role, $email, $phone, $city, $zone, $shopSlug, $hash, $goal, fv_now()]);
            $newId = (int) $pdo->lastInsertId();
            fv_referral_code($newId);
            $_SESSION['fv_access_once'] = ['id' => $newId, 'name' => $name, 'email' => $email, 'code' => $code];
            fv_flash($name . ' rejoint l’équipe. Le code d’accès s’affiche une seule fois : notez-le ou envoyez l’invitation.');
            fv_redirect('equipe.php');
        }

        $member = fv_member_get($id);
        if (!$member) {
            fv_flash('Ce membre n’existe plus.', 'error');
            fv_redirect('equipe.php');
        }
        $pdo->prepare('UPDATE members SET name=?, role=?, email=?, phone=?, city=?, zone=?, shop_slug=?, goal_month=? WHERE id=?')
            ->execute([$name, $role, $email, $phone, $city, $zone, $shopSlug, $goal, $id]);
        fv_flash('La fiche de ' . $name . ' est à jour.');
        fv_redirect('equipe.php');
    }

    if ($action === 'regenerer') {
        $member = fv_member_get((int) ($_POST['id'] ?? 0));
        if (!$member) {
            fv_flash('Ce membre n’existe plus.', 'error');
            fv_redirect('equipe.php');
        }
        $code = fv_access_code_new();
        $pdo->prepare('UPDATE members SET access_hash = ? WHERE id = ?')
            ->execute([password_hash(fv_code_normalize($code), PASSWORD_DEFAULT), $member['id']]);
        $_SESSION['fv_access_once'] = ['id' => (int) $member['id'], 'name' => $member['name'], 'email' => $member['email'], 'code' => $code];
        fv_flash('Nouveau code d’accès pour ' . $member['name'] . '. L’ancien ne fonctionne plus.');
        fv_redirect('equipe.php');
    }

    if ($action === 'basculer') {
        $member = fv_member_get((int) ($_POST['id'] ?? 0));
        if (!$member) {
            fv_flash('Ce membre n’existe plus.', 'error');
            fv_redirect('equipe.php');
        }
        $pdo->prepare('UPDATE members SET active = ? WHERE id = ?')->execute([$member['active'] ? 0 : 1, $member['id']]);
        fv_flash($member['active'] ? $member['name'] . ' est désactivé : plus de connexion possible, l’historique reste.' : $member['name'] . ' peut de nouveau se connecter.');
        fv_redirect('equipe.php');
    }

    if ($action === 'supprimer') {
        $member = fv_member_get((int) ($_POST['id'] ?? 0));
        if (!$member) {
            fv_flash('Ce membre n’existe plus.', 'error');
            fv_redirect('equipe.php');
        }
        $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM prospects WHERE owner_id = ?) + (SELECT COUNT(*) FROM activities WHERE member_id = ?) + (SELECT COUNT(*) FROM redemptions WHERE member_id = ?) + (SELECT COUNT(*) FROM mailings WHERE member_id = ?) + (SELECT COUNT(*) FROM survey_responses WHERE member_id = ?) AS n');
        $st->execute([$member['id'], $member['id'], $member['id'], $member['id'], $member['id']]);
        if ((int) $st->fetchColumn() > 0) {
            fv_flash('Impossible de supprimer ' . $member['name'] . ' : son historique est lié à des prospects, conversions ou envois. Désactivez le compte pour couper l’accès sans rien perdre.', 'error');
            fv_redirect('equipe.php');
        }
        $pdo->prepare('DELETE FROM codes WHERE member_id = ?')->execute([$member['id']]);
        $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$member['id']]);
        fv_flash($member['name'] . ' a été supprimé. Aucun historique n’était rattaché.');
        fv_redirect('equipe.php');
    }

    if ($action === 'voir') {
        $member = fv_member_get((int) ($_POST['id'] ?? 0));
        if (!$member || !$member['active']) {
            fv_flash('Ce membre est introuvable ou désactivé.', 'error');
            fv_redirect('equipe.php');
        }
        $_SESSION['fv_view'] = (int) $member['id'];
        fv_redirect('index.php');
    }

    fv_flash('Action inconnue.', 'error');
    fv_redirect('equipe.php');
}

$editing = null;
$editId = (int) ($_GET['editer'] ?? 0);
if ($editId > 0) {
    $editing = fv_member_get($editId);
    if (!$editing) {
        fv_flash('Ce membre n’existe plus.', 'error');
        fv_redirect('equipe.php');
    }
}

$accessOnce = $_SESSION['fv_access_once'] ?? null;
if (is_array($accessOnce) && ($accessOnce['code'] ?? '') !== ''):
    $invite = "Bonjour " . $accessOnce['name'] . ",\n\n"
        . "Votre accès à l’espace Forces de vente " . $brand . " est prêt.\n\n"
        . "Adresse de connexion : " . $loginUrl . "\n"
        . "E-mail : " . $accessOnce['email'] . "\n"
        . "Code d’accès : " . $accessOnce['code'] . "\n\n"
        . "Conservez ce code : il vaut votre signature sur le terrain.";
endif;

$members = fv_members_list(false);
$rows = [];
foreach ($members as $m) {
    $id = (int) $m['id'];
    $points = fv_member_points($id);
    $st = $pdo->prepare('SELECT COUNT(*) FROM prospects WHERE owner_id = ?');
    $st->execute([$id]);
    $prospects = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM prospects WHERE owner_id = ? AND status NOT IN ("boutique_creee", "perdu")');
    $st->execute([$id]);
    $pipeline = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM activities WHERE member_id = ? AND kind = "creation"');
    $st->execute([$id]);
    $shops = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT MAX(occurred_at) FROM activities WHERE member_id = ?');
    $st->execute([$id]);
    $lastAt = $st->fetchColumn() ?: null;
    $st = $pdo->prepare('SELECT code FROM codes WHERE member_id = ? AND is_referral = 1 ORDER BY active DESC, id DESC LIMIT 1');
    $st->execute([$id]);
    $code = (string) ($st->fetchColumn() ?: '');
    if ($code === '' && $m['active']) {
        $referral = fv_referral_code($id);
        $code = $referral ? (string) $referral['code'] : '';
    }
    $rows[] = [
        'member' => $m,
        'points' => $points,
        'tier' => fv_tier($points),
        'prospects' => $prospects,
        'pipeline' => $pipeline,
        'shops' => $shops,
        'last_at' => $lastAt,
        'code' => $code,
    ];
}
usort($rows, static function (array $a, array $b): int {
    if ((int) $a['member']['active'] !== (int) $b['member']['active']) {
        return (int) $b['member']['active'] <=> (int) $a['member']['active'];
    }
    return strcasecmp($a['member']['name'], $b['member']['name']);
});

$activeCount = 0;
$prospectCount = 0;
$shopCount = 0;
foreach ($rows as $row) {
    if ($row['member']['active']) $activeCount++;
    $prospectCount += $row['prospects'];
    $shopCount += $row['shops'];
}
$monthPoints = 0;
$st = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM activities WHERE occurred_at >= ?');
$st->execute([date('Y-m-01') . ' 00:00:00']);
$monthPoints = (int) $st->fetchColumn();

$form = [
    'name' => $editing['name'] ?? '',
    'role' => $editing['role'] ?? 'commercial',
    'email' => $editing['email'] ?? '',
    'phone' => $editing['phone'] ?? '',
    'city' => $editing['city'] ?? '',
    'zone' => $editing['zone'] ?? '',
    'shop_slug' => $editing['shop_slug'] ?? '',
    'goal_month' => (int) ($editing['goal_month'] ?? 2),
];

fv_layout_start('Équipe & rôles', [
    'eyebrow' => 'Administration · ' . count($rows) . ' membre' . (count($rows) > 1 ? 's' : ''),
    'desc' => 'Commerciaux et ambassadeurs : chacun son code d’accès, son périmètre, son code de parrainage et son objectif. La direction garde la main sur les comptes.',
    'wide' => true,
    'actions' => $editing
        ? '<a class="fv-btn fv-btn-sm" href="equipe.php">' . fv_icon('plus', 14) . ' Nouveau membre</a>'
        : '<a class="fv-btn fv-btn-sm" href="recompenses.php">' . fv_icon('star', 14) . ' Barème</a>',
]);
?>

<?php if (is_array($accessOnce)): ?>
<section class="fv-card" style="border-color:var(--fv-accent);box-shadow:0 0 0 2px var(--fv-accent-soft);">
  <div class="fv-card-head">
    <div><h2>Code d’accès de <?= e($accessOnce['name']) ?></h2><p>Il ne sera plus affiché après fermeture de ce bloc. Transmettez-le par un canal sûr.</p></div>
    <a class="fv-btn fv-btn-sm" href="equipe.php?code=effacer">J’ai noté le code</a>
  </div>
  <div class="fv-card-body">
    <p class="fv-bigcode" style="margin:0;"><?= e($accessOnce['code']) ?></p>
    <div class="fv-form-grid">
      <div><span class="fv-sub">Adresse de connexion</span><div class="fv-readonly"><?= e($loginUrl) ?></div></div>
      <div><span class="fv-sub">Identifiant</span><div class="fv-readonly"><?= e($accessOnce['email']) ?></div></div>
    </div>
    <div class="fv-actions">
      <button class="fv-btn fv-btn-sm" type="button" data-copy="<?= e($accessOnce['code']) ?>"><?= fv_icon('copy', 14) ?> Copier le code</button>
      <button class="fv-btn fv-btn-sm" type="button" data-copy="<?= e($invite ?? '') ?>">Copier le message d’invitation</button>
      <?php if ($accessOnce['email'] !== ''): ?><a class="fv-btn fv-btn-sm fv-btn-primary" href="mailto:<?= e($accessOnce['email']) ?>?subject=<?= e(rawurlencode('Votre accès à l’espace Forces de vente ' . $brand)) ?>&amp;body=<?= e(rawurlencode($invite ?? '')) ?>"><?= fv_icon('mail', 14) ?> Envoyer par e-mail</a><?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<div class="fv-kpis">
  <div class="fv-kpi"><span class="n"><?= $activeCount ?></span><span class="l">Membres actifs</span><span class="s"><?= count($rows) - $activeCount ?> désactivé<?= count($rows) - $activeCount > 1 ? 's' : '' ?></span></div>
  <div class="fv-kpi"><span class="n"><?= $prospectCount ?></span><span class="l">Prospects suivis</span><span class="s">carnets de l’équipe</span></div>
  <div class="fv-kpi"><span class="n"><?= $shopCount ?></span><span class="l">Boutiques créées</span><span class="s">depuis le début</span></div>
  <div class="fv-kpi is-accent"><span class="n"><?= $monthPoints ?></span><span class="l">Points ce mois</span><span class="s">toutes activités confondues</span></div>
</div>

<div class="fv-grid fv-grid-side">
  <div class="fv-grid" style="gap:14px;">
    <?php if (!$rows): ?>
      <section class="fv-card"><div class="fv-empty"><b>L’équipe est encore vide</b>Créez le premier commercial ou ambassadeur avec le formulaire à droite.</div></section>
    <?php else: ?>
      <div class="fv-grid fv-grid-2">
        <?php foreach ($rows as $row): $m = $row['member']; $tier = $row['tier']; ?>
          <section class="fv-card fv-tier-card" data-t="<?= e($tier['key']) ?>">
            <div class="fv-card-pad" style="display:flex;gap:11px;align-items:flex-start;">
              <span class="fv-avatar" aria-hidden="true"><?= e(fv_initials($m['name'])) ?></span>
              <div style="flex:1;min-width:0;">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                  <b style="font-size:.98rem;"><?= e($m['name']) ?></b>
                  <span class="fv-badge" data-s="<?= $m['active'] ? 'boutique_creee' : 'perdu' ?>"><?= $m['active'] ? 'Actif' : 'Désactivé' ?></span>
                  <span class="fv-badge" data-t="<?= e($tier['key']) ?>"><?= e($tier['label']) ?></span>
                </div>
                <span class="fv-sub" style="display:block;margin-top:3px;"><?= e(fv_role_label($m['role'])) ?></span>
                <span class="fv-sub" style="display:block;margin-top:2px;"><?= e($m['email']) ?><?= $m['phone'] !== '' ? ' · ' . e($m['phone']) : '' ?></span>
                <span class="fv-sub" style="display:block;"><?= e(trim(($m['city'] !== '' ? $m['city'] : 'Ville non précisée') . ($m['zone'] !== '' ? ' · ' . $m['zone'] : ''))) ?></span>
              </div>
            </div>
            <div class="fv-card-pad" style="border-top:1px solid var(--fv-line);display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;text-align:center;">
              <div><b class="fv-mono" style="display:block;"><?= (int) $row['points'] ?></b><span class="fv-sub">pts cumulés</span></div>
              <div><b class="fv-mono" style="display:block;"><?= (int) $row['pipeline'] ?>/<?= (int) $row['prospects'] ?></b><span class="fv-sub">prospects en cours</span></div>
              <div><b class="fv-mono" style="display:block;"><?= (int) $row['shops'] ?></b><span class="fv-sub">boutiques créées</span></div>
            </div>
            <div class="fv-card-pad" style="border-top:1px solid var(--fv-line);display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;">
              <div>
                <span class="fv-sub">Code de parrainage</span>
                <?php if ($row['code'] !== ''): ?>
                  <div class="fv-code" style="margin-top:4px;"><b><?= e($row['code']) ?></b><button class="fv-linkbtn" type="button" data-copy="<?= e($row['code']) ?>" title="Copier"><?= fv_icon('copy', 13) ?></button></div>
                <?php else: ?><div class="fv-sub">—</div><?php endif; ?>
              </div>
              <div class="fv-sub" style="text-align:right;">
                <?= $row['last_at'] ? 'Dernière activité<br>' . e(fv_datetime($row['last_at'])) : 'Aucune activité' ?><br>
                <?= $m['last_login_at'] ? 'Connexion : ' . e(fv_datetime($m['last_login_at'])) : 'Jamais connecté' ?>
              </div>
            </div>
            <div class="fv-card-pad" style="border-top:1px solid var(--fv-line);">
              <div class="actions" style="display:flex;gap:6px;justify-content:flex-start;flex-wrap:wrap;">
                <a class="fv-btn fv-btn-sm" href="equipe.php?editer=<?= (int) $m['id'] ?>">Modifier</a>
                <?php if ($m['active']): ?>
                  <form method="post" action="equipe.php" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
                    <input type="hidden" name="action" value="voir">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <button class="fv-btn fv-btn-sm" type="submit"><?= fv_icon('eye', 13) ?> Voir comme</button>
                  </form>
                <?php endif; ?>
                <form method="post" action="equipe.php" style="display:inline;" data-confirm="Générer un nouveau code ? Le code actuel de <?= e($m['name']) ?> sera invalidé.">
                  <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
                  <input type="hidden" name="action" value="regenerer">
                  <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                  <button class="fv-btn fv-btn-sm fv-btn-ghost" type="submit">Nouveau code</button>
                </form>
                <form method="post" action="equipe.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
                  <input type="hidden" name="action" value="basculer">
                  <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                  <button class="fv-btn fv-btn-sm fv-btn-ghost" type="submit"><?= $m['active'] ? 'Désactiver' : 'Réactiver' ?></button>
                </form>
                <form method="post" action="equipe.php" style="display:inline;" data-confirm="Supprimer définitivement <?= e($m['name']) ?> ? Cette action est refusée si un historique est rattaché.">
                  <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                  <button class="fv-btn fv-btn-sm fv-btn-danger" type="submit">Supprimer</button>
                </form>
              </div>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="fv-grid" style="gap:14px;">
    <section class="fv-card" id="fiche">
      <div class="fv-card-head">
        <div>
          <h2><?= $editing ? 'Modifier ' . e($editing['name']) : 'Ajouter un membre' ?></h2>
          <p><?= $editing ? 'Le code d’accès ne change pas : utilisez « Nouveau code » pour le réinitialiser.' : 'Un code d’accès personnel est généré automatiquement.' ?></p>
        </div>
        <?php if ($editing): ?><a class="fv-btn fv-btn-sm" href="equipe.php">Annuler</a><?php endif; ?>
      </div>
      <div class="fv-card-body">
        <form class="fv-form" method="post" action="equipe.php" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
          <input type="hidden" name="action" value="<?= $editing ? 'modifier' : 'creer' ?>">
          <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
          <label class="fv-field">Nom complet
            <input name="name" maxlength="120" required value="<?= e($form['name']) ?>" placeholder="Camille Martin"<?= $editing ? '' : ' autofocus' ?>>
          </label>
          <label class="fv-field">Rôle
            <select name="role">
              <?php foreach (fv_roles() as $key => $label): ?><option value="<?= e($key) ?>"<?= $form['role'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="fv-field">Adresse e-mail <span class="fv-hint">Identifiant de connexion</span>
            <input type="email" name="email" maxlength="190" required value="<?= e($form['email']) ?>" placeholder="camille@exemple.fr" autocapitalize="none">
          </label>
          <div class="fv-form-grid">
            <label class="fv-field">Téléphone
              <input name="phone" maxlength="40" value="<?= e($form['phone']) ?>" inputmode="tel">
            </label>
            <label class="fv-field">Ville
              <input name="city" maxlength="90" value="<?= e($form['city']) ?>">
            </label>
            <label class="fv-field">Zone de prospection
              <input name="zone" maxlength="90" value="<?= e($form['zone']) ?>" placeholder="Centre-ville, nord…">
            </label>
            <label class="fv-field">Boutique d’origine <span class="fv-hint">Ambassadeur</span>
              <input name="shop_slug" maxlength="60" value="<?= e($form['shop_slug']) ?>" placeholder="le-comptoir" autocapitalize="none" spellcheck="false">
            </label>
            <label class="fv-field">Objectif boutiques / mois
              <input type="number" name="goal_month" min="0" max="999" value="<?= (int) $form['goal_month'] ?>">
            </label>
          </div>
          <button class="fv-btn fv-btn-primary" type="submit"><?= $editing ? 'Enregistrer' : 'Créer le membre' ?></button>
        </form>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Comment ça marche</h2></div></div>
      <div class="fv-card-body fv-hint">
        <p><b>Commercial</b> — carnets de prospects, tournées, appels, conversions et prime par boutique.</p>
        <p><b>Ambassadeur · commerçant référent</b> — son propre code de parrainage, une remise pour son réseau, une prime par boutique créée.</p>
        <p>Chaque membre ne voit que ses prospects. La direction voit tout et peut se mettre à sa place avec « Voir comme ».</p>
        <p>Le code d’accès est haché en base : il n’est affiché qu’une fois, à la création ou à la réinitialisation.</p>
        <p>La page de connexion est <a href="connexion.php">connexion.php</a> ; partagez l’adresse complète affichée avec le code.</p>
      </div>
    </section>
  </div>
</div>

<?php fv_layout_end(); ?>
