<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
$member = $ctx['member'];
$manager = $ctx['manager'];
$myId = fv_effective_member_id();
$pdo = fv_db();
$members = $manager && $myId === 0 ? fv_members_list(false) : [];
$referral = $member ? fv_referral_code((int) $member['id']) : null;
$tierPoints = $myId > 0 ? fv_member_points($myId) : 0;
$tier = fv_tier($tierPoints);

$parseMoney = static function ($value): int {
    $value = str_replace([' ', "\u{202f}", ','], ['', '', '.'], trim((string) $value));
    return max(0, (int) round(((float) $value) * 100));
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    fv_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'creer_code') {
        if (!$manager) {
            fv_flash('Seule la direction peut créer un code de campagne.', 'error');
            fv_redirect('codes.php');
        }
        $label = fv_text($_POST['label'] ?? '', 90);
        if ($label === '') {
            fv_flash('Donnez un nom au code (campagne, événement, commerce…).', 'error');
            fv_redirect('codes.php');
        }
        $kind = ($_POST['kind'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $value = $kind === 'percent'
            ? max(1, min(100, (int) ($_POST['value_percent'] ?? 20)))
            : $parseMoney($_POST['value_fixed'] ?? '');
        if ($kind === 'fixed' && $value < 100) {
            fv_flash('Indiquez un montant fixe d’au moins 1 €.', 'error');
            fv_redirect('codes.php');
        }
        $maxUses = max(0, min(10000, (int) ($_POST['max_uses'] ?? 0)));
        $expires = trim((string) ($_POST['expires_at'] ?? ''));
        if ($expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) $expires = '';
        $owner = $manager ? (int) ($_POST['member_id'] ?? 0) : $myId;
        if ($owner > 0 && !fv_member_get($owner)) $owner = 0;
        $code = fv_referral_code_new($label);
        $pdo->prepare('INSERT INTO codes (code, member_id, label, kind, value, max_uses, uses, is_referral, active, expires_at, created_at) VALUES (?,?,?,?,?,?,0,0,1,?,?)')
            ->execute([$code, $owner, $label, $kind, $value, $maxUses, $expires !== '' ? $expires : null, fv_now()]);
        fv_flash('Code créé : ' . $code . '. Il peut être partagé dès maintenant.');
        fv_redirect('codes.php');
    }

    if ($action === 'basculer_code' || $action === 'supprimer_code') {
        if (!$manager) {
            fv_flash('Seule la direction peut modifier un code.', 'error');
            fv_redirect('codes.php');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM codes WHERE id = ?');
        $st->execute([$id]);
        $code = $st->fetch();
        if (!$code) {
            fv_flash('Ce code n’existe plus.', 'error');
            fv_redirect('codes.php');
        }
        if ($action === 'supprimer_code') {
            $pdo->prepare('DELETE FROM codes WHERE id = ?')->execute([$id]);
            fv_flash('Code supprimé. Les conversions déjà enregistrées restent dans l’historique.');
        } else {
            $pdo->prepare('UPDATE codes SET active = ? WHERE id = ?')->execute([$code['active'] ? 0 : 1, $id]);
            fv_flash($code['active'] ? 'Code désactivé.' : 'Code réactivé.');
        }
        fv_redirect('codes.php');
    }

    if ($action === 'encaisser') {
        $codeText = mb_strtoupper(fv_text($_POST['code'] ?? '', 40));
        $prospectId = (int) ($_POST['prospect_id'] ?? 0);
        $shopSlug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', fv_text($_POST['shop_slug'] ?? '', 60)) ?? '');
        $shopSlug = trim($shopSlug, '-');
        $amount = $parseMoney($_POST['amount'] ?? '');
        $note = fv_paragraph($_POST['note'] ?? '', 600);
        $postedMember = $manager ? (int) ($_POST['member_id'] ?? 0) : $myId;

        $codeRow = null;
        if ($codeText !== '') {
            $st = $pdo->prepare('SELECT * FROM codes WHERE UPPER(code) = ? LIMIT 1');
            $st->execute([$codeText]);
            $codeRow = $st->fetch() ?: null;
            if (!$codeRow) {
                fv_flash('Ce code est inconnu. Vérifiez la saisie.', 'error');
                fv_redirect('codes.php');
            }
            [$status, $state] = fv_code_status($codeRow);
            if ($state !== 'on') {
                fv_flash('Ce code est ' . mb_strtolower($status) . ' : il ne peut plus être utilisé.', 'error');
                fv_redirect('codes.php');
            }
            if (!$manager && (int) $codeRow['member_id'] > 0 && (int) $codeRow['member_id'] !== $myId) {
                fv_flash('Ce code appartient à un autre membre de l’équipe.', 'error');
                fv_redirect('codes.php');
            }
        }

        $prospect = $prospectId > 0 ? fv_prospect_get($prospectId) : null;
        if (!$prospect && $prospectId > 0) {
            fv_flash('Ce prospect n’existe pas ou ne vous est pas attribué.', 'error');
            fv_redirect('codes.php');
        }
        if (!$prospect && $shopSlug === '') {
            fv_flash('Indiquez le prospect ou au moins l’identifiant de la boutique créée.', 'error');
            fv_redirect('codes.php');
        }
        if ($prospect && $shopSlug === '') $shopSlug = strtolower((string) $prospect['shop_slug']);
        if ($shopSlug !== '') {
            $st = $pdo->prepare('SELECT COUNT(*) FROM redemptions WHERE shop_slug = ? AND shop_slug <> ""');
            $st->execute([$shopSlug]);
            if ((int) $st->fetchColumn() > 0) {
                fv_flash('La boutique « ' . $shopSlug . ' » a déjà une conversion enregistrée.', 'error');
                fv_redirect('codes.php');
            }
        }

        $credit = $codeRow && (int) $codeRow['member_id'] > 0 ? (int) $codeRow['member_id'] : $postedMember;
        if ($credit > 0 && !fv_member_get($credit)) $credit = 0;

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO redemptions (code_id, code, member_id, prospect_id, shop_slug, amount_cents, note, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([
                $codeRow ? (int) $codeRow['id'] : 0,
                $codeRow ? (string) $codeRow['code'] : '',
                $credit,
                $prospect ? (int) $prospect['id'] : 0,
                $shopSlug,
                $amount,
                $note,
                fv_now(),
            ]);
        if ($codeRow) {
            $pdo->prepare('UPDATE codes SET uses = uses + 1 WHERE id = ?')->execute([(int) $codeRow['id']]);
        }
        if ($prospect) {
            $pdo->prepare('UPDATE prospects SET status = "boutique_creee", shop_slug = ?, next_action_at = NULL, updated_at = ? WHERE id = ?')
                ->execute([$shopSlug, fv_now(), (int) $prospect['id']]);
            $points = fv_points_for('creation');
            $pdo->prepare('INSERT INTO activities (prospect_id, member_id, kind, outcome, notes, points, duration_min, occurred_at) VALUES (?,?,?,?,?,?,0,?)')
                ->execute([(int) $prospect['id'], $credit, 'creation', '', $note !== '' ? $note : 'Boutique créée' . ($shopSlug !== '' ? ' : ' . $shopSlug : ''), $points, fv_now()]);
        }
        $pdo->commit();

        $message = 'Conversion enregistrée';
        if ($shopSlug !== '') $message .= ' pour ' . $shopSlug;
        if ($amount > 0) $message .= ' · ' . fv_money($amount) . '/mois';
        if ($credit > 0) {
            $creditMember = fv_member_get($credit);
            $message .= ' · créditée à ' . ($creditMember['name'] ?? 'l’équipe');
        }
        fv_flash($message . '.');
        fv_redirect('codes.php');
    }

    fv_flash('Action inconnue.', 'error');
    fv_redirect('codes.php');
}

$codes = fv_codes_list($myId);
$codeChoices = $codes;
if ($myId > 0) {
    $st = $pdo->prepare('SELECT c.*, m.name AS member_name FROM codes c LEFT JOIN members m ON m.id = c.member_id WHERE c.member_id = 0 OR c.member_id = ? ORDER BY c.is_referral DESC, c.created_at DESC');
    $st->execute([$myId]);
    $codeChoices = $st->fetchAll();
}
$redemptionSql = 'SELECT r.*, m.name AS member_name, p.business AS prospect_business FROM redemptions r LEFT JOIN members m ON m.id = r.member_id LEFT JOIN prospects p ON p.id = r.prospect_id';
$params = [];
if ($myId > 0) {
    $redemptionSql .= ' WHERE r.member_id = ?';
    $params[] = $myId;
}
$redemptionSql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT 40';
$st = $pdo->prepare($redemptionSql);
$st->execute($params);
$redemptions = $st->fetchAll();

$myRedemptions = 0;
$myRevenue = 0;
if ($myId > 0) {
    $st = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(amount_cents),0) FROM redemptions WHERE member_id = ?');
    $st->execute([$myId]);
    [$myRedemptions, $myRevenue] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
}
$commission = $member ? fv_member_commission($member, $myRedemptions, $myRevenue, $tierPoints) : 0;
$prospects = fv_prospects_search(['active_only' => true], 300);
$shareCode = $referral ? (string) $referral['code'] : '';
$shareText = $referral
    ? 'Avec le code ' . $shareCode . ', profitez de ' . fv_code_value_label($referral) . ' sur votre boutique ' . fv_setting('marque_nom', 'Brocs') . ' : ' . rtrim(fv_setting('site_url', ''), '/')
    : '';

fv_layout_start('Codes & filleuls', [
    'eyebrow' => 'Parrainage',
    'desc' => 'Un code par commercial, des codes de campagne, et chaque conversion enregistrée au bon endroit. Les points et la commission suivent automatiquement.',
    'wide' => true,
    'actions' => $manager && $myId === 0 ? '' : '<a class="fv-btn fv-btn-sm" href="recompenses.php">' . fv_icon('gift', 14) . ' Mes récompenses</a>',
]);
?>

<?php if ($referral): ?>
<section class="fv-card fv-code-hero">
  <div class="fv-code-hero-in">
    <div>
      <span class="fv-eyebrow">Votre code personnel</span>
      <p class="fv-bigcode" style="margin:8px 0 4px;"><?= e($referral['code']) ?></p>
      <p class="fv-hint"><?= e(fv_code_value_label($referral)) ?> de remise · utilisé <?= (int) $referral['uses'] ?> fois<?= (int) $referral['max_uses'] > 0 ? ' sur ' . (int) $referral['max_uses'] : '' ?>.</p>
      <div class="fv-actions" style="margin-top:10px;">
        <button class="fv-btn fv-btn-sm" type="button" data-copy="<?= e($referral['code']) ?>">Copier le code</button>
        <?php if ($shareText !== ''): ?><button class="fv-btn fv-btn-sm" type="button" data-copy="<?= e($shareText) ?>">Copier le message</button><?php endif; ?>
      </div>
    </div>
    <div class="fv-code-hero-stats">
      <div><b><?= (int) $myRedemptions ?></b><span>boutique<?= $myRedemptions > 1 ? 's' : '' ?> filleul<?= $myRedemptions > 1 ? 's' : '' ?></span></div>
      <div><b><?= e(fv_money($myRevenue)) ?></b><span>revenu mensuel apporté</span></div>
      <div><b><?= e(fv_money($commission)) ?></b><span>commission estimée</span></div>
      <div><b><?= e($tier['label']) ?></b><span><?= (int) $tierPoints ?> pts cumulés</span></div>
    </div>
  </div>
</section>
<?php endif; ?>

<div class="fv-grid fv-grid-2-1">
  <div class="fv-grid" style="gap:14px;">
    <section class="fv-card">
      <div class="fv-card-head">
        <div><h2>Enregistrer une conversion</h2><p>Une boutique créée, un code utilisé, un montant mensuel. Le prospect passe en « Boutique créée ».</p></div>
      </div>
      <div class="fv-card-body">
        <form class="fv-form" method="post" action="codes.php">
          <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
          <input type="hidden" name="action" value="encaisser">
          <div class="fv-form-grid">
            <label class="fv-field">Prospect
              <select name="prospect_id">
                <option value="0">— Boutique créée hors carnet —</option>
                <?php foreach ($prospects as $p): ?>
                  <option value="<?= (int) $p['id'] ?>"><?= e(($p['business'] !== '' ? $p['business'] : $p['name']) . ($p['city'] !== '' ? ' · ' . $p['city'] : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="fv-field">Code utilisé
              <select name="code">
                <option value="">Sans code (conversion directe)</option>
                <?php foreach ($codeChoices as $c): if (!(int) $c['active']) continue; ?>
                  <option value="<?= e($c['code']) ?>"><?= e($c['code']) ?><?= $c['member_name'] ? ' · ' . e($c['member_name']) : '' ?> (<?= e(fv_code_value_label($c)) ?>)</option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="fv-field">Identifiant de la boutique
              <input name="shop_slug" maxlength="60" placeholder="le-comptoir-des-halles" autocapitalize="none" spellcheck="false">
            </label>
            <label class="fv-field">Montant mensuel (€)
              <input name="amount" inputmode="decimal" placeholder="19,90">
            </label>
            <?php if ($manager && $myId === 0): ?>
              <label class="fv-field">Créditer
                <select name="member_id">
                  <option value="0">Direction (non attribué)</option>
                  <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?> · <?= e(fv_role_label($m['role'])) ?></option><?php endforeach; ?>
                </select>
              </label>
            <?php endif; ?>
            <label class="fv-field full">Note
              <input name="note" maxlength="600" placeholder="Formule choisie, contexte…">
            </label>
          </div>
          <p class="fv-hint">Le code prioritaire est celui du commercial ou de l’ambassadeur qui a apporté la boutique. Sans code, choisissez qui créditer.</p>
          <button class="fv-btn fv-btn-primary" type="submit"><?= fv_icon('check', 14) ?> Enregistrer la conversion</button>
        </form>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Conversions <small class="fv-sub"><?= count($redemptions) ?></small></h2><p><?= $myId > 0 ? 'Vos boutiques filleules, les plus récentes d’abord.' : 'Toutes les conversions de l’équipe.' ?></p></div></div>
      <div class="fv-table-wrap">
        <?php if (!$redemptions): ?>
          <div class="fv-empty"><b>Aucune conversion enregistrée</b>La première boutique créée apparaîtra ici avec son code et son montant.</div>
        <?php else: ?>
          <table class="fv-table">
            <thead><tr><th>Date</th><th>Boutique</th><th>Code</th><th>Commercial</th><th class="right">Revenu</th></tr></thead>
            <tbody>
              <?php foreach ($redemptions as $r): ?>
                <tr>
                  <td><span class="sub"><?= e(fv_date($r['created_at'])) ?></span></td>
                  <td>
                    <b><?= e(!empty($r['prospect_business']) ? $r['prospect_business'] : ($r['shop_slug'] !== '' ? $r['shop_slug'] : 'Boutique')) ?></b>
                    <?php if ($r['shop_slug'] !== ''): ?><span class="sub"><code><?= e($r['shop_slug']) ?></code></span><?php endif; ?>
                  </td>
                  <td><?= $r['code'] !== '' ? '<code>' . e($r['code']) . '</code>' : '<span class="sub">sans code</span>' ?></td>
                  <td><span class="sub"><?= e($r['member_name'] ?? 'Direction') ?></span></td>
                  <td class="right fv-mono"><?= e(fv_money((int) $r['amount_cents'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2><?= $myId > 0 ? 'Vos codes' : 'Tous les codes' ?> <small class="fv-sub"><?= count($codes) ?></small></h2><p><?= $myId > 0 ? 'Votre code de parrainage personnel et les campagnes qui vous sont attribuées.' : 'Un code personnel par membre, et les codes de campagne créés par la direction.' ?></p></div></div>
      <div class="fv-table-wrap">
        <table class="fv-table">
          <thead><tr><th>Code</th><th>Propriétaire</th><th>Valeur</th><th>Utilisations</th><th>État</th><?php if ($manager): ?><th class="right">Actions</th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($codes as $c): [$stateLabel, $stateKey] = fv_code_status($c); ?>
              <tr>
                <td><code><?= e($c['code']) ?></code><?= (int) $c['is_referral'] ? ' <span class="fv-badge" data-s="interesse">filleul</span>' : '' ?><span class="sub"><?= e($c['label']) ?></span></td>
                <td><span class="sub"><?= e($c['member_name'] ?? 'Direction') ?></span></td>
                <td><?= e(fv_code_value_label($c)) ?></td>
                <td><span class="fv-mono"><?= (int) $c['uses'] ?><?= (int) $c['max_uses'] > 0 ? '/' . (int) $c['max_uses'] : '' ?></span></td>
                <td><span class="fv-badge" data-s="<?= $stateKey === 'on' ? 'boutique_creee' : 'perdu' ?>"><?= e($stateLabel) ?></span><?php if (!empty($c['expires_at'])): ?><span class="sub">jusqu’au <?= e(fv_date($c['expires_at'])) ?></span><?php endif; ?></td>
                <?php if ($manager): ?>
                  <td>
                    <div class="actions">
                      <button class="fv-btn fv-btn-sm" type="button" data-copy="<?= e($c['code']) ?>">Copier</button>
                      <form method="post" action="codes.php" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
                        <input type="hidden" name="action" value="basculer_code">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button class="fv-btn fv-btn-sm fv-btn-ghost" type="submit"><?= (int) $c['active'] ? 'Désactiver' : 'Activer' ?></button>
                      </form>
                      <form method="post" action="codes.php" style="display:inline;" data-confirm="Supprimer ce code ? L’historique des conversions est conservé.">
                        <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
                        <input type="hidden" name="action" value="supprimer_code">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button class="fv-btn fv-btn-sm fv-btn-danger" type="submit">Suppr.</button>
                      </form>
                    </div>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <aside class="fv-grid" style="gap:14px;">
    <?php if ($manager): ?>
      <section class="fv-card">
        <div class="fv-card-head"><div><h2>Code de campagne</h2><p>Un événement, un partenaire, une opération locale.</p></div></div>
        <div class="fv-card-body">
          <form class="fv-form" method="post" action="codes.php">
            <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
            <input type="hidden" name="action" value="creer_code">
            <label class="fv-field">Nom de la campagne
              <input name="label" maxlength="90" required placeholder="Marché de Noël">
            </label>
            <div class="fv-form-grid">
              <label class="fv-field">Type
                <select name="kind">
                  <option value="percent">Pourcentage</option>
                  <option value="fixed">Montant fixe</option>
                </select>
              </label>
              <label class="fv-field">Valeur (%)
                <input name="value_percent" type="number" min="1" max="100" value="<?= (int) fv_setting_int('remise_defaut', 20) ?>">
              </label>
              <label class="fv-field">ou montant (€)
                <input name="value_fixed" inputmode="decimal" placeholder="10">
              </label>
              <label class="fv-field">Utilisations max
                <input name="max_uses" type="number" min="0" max="10000" placeholder="0 = illimité">
              </label>
              <label class="fv-field">Expire le
                <input type="date" name="expires_at">
              </label>
              <label class="fv-field">Attribuer à
                <select name="member_id">
                  <option value="0">Aucun membre (campagne)</option>
                  <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?>
                </select>
              </label>
            </div>
            <p class="fv-hint">Le montant fixe n’est utilisé que si le type « Montant fixe » est choisi.</p>
            <button class="fv-btn fv-btn-primary" type="submit">Créer le code</button>
          </form>
        </div>
      </section>
    <?php endif; ?>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Le dispositif</h2></div></div>
      <div class="fv-card-body fv-hint">
        <p><b>Commercial</b> — apporte des boutiques, suit ses prospects, touche une prime par boutique et un pourcentage du revenu mensuel.</p>
        <p><b>Ambassadeur · commerçant référent</b> — recommande la plateforme dans son réseau, avec un code dédié et une remise pour ses clients.</p>
        <p><b>Paliers</b> — <?= e(fv_tier_labels()['argent'] ?? 'Argent') ?> à <?= (int) fv_tier_thresholds()['argent'] ?> pts, <?= e(fv_tier_labels()['or'] ?? 'Or') ?> à <?= (int) fv_tier_thresholds()['or'] ?> pts, <?= e(fv_tier_labels()['platine'] ?? 'Platine') ?> à <?= (int) fv_tier_thresholds()['platine'] ?> pts. Chaque palier augmente la commission.</p>
        <p><a href="recompenses.php">Voir le barème complet</a></p>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Bon réflexe</h2></div></div>
      <div class="fv-card-body fv-hint">
        <p>Enregistrez la conversion <b>le jour de la mise en ligne</b>, pas en fin de mois. Le prospect passe à « Boutique créée », l’historique est propre, et la commission se calcule sur des données justes.</p>
        <p>Une boutique déjà enregistrée est refusée : vérifiez l’identifiant avant de valider.</p>
      </div>
    </section>
  </aside>
</div>

<?php fv_layout_end(); ?>
