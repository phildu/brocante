<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
$member = $ctx['member'];
$pdo = fv_db();
$memberId = fv_effective_member_id();

$queue = fv_prospects_search([
    'active_only' => true,
    'has_phone' => true,
], 80);

$focusId = (int) ($_GET['id'] ?? 0);
$focus = $focusId > 0 ? fv_prospect_get($focusId) : null;
if (!$focus && $queue) $focus = $queue[0];
if ($focus && $focus['phone'] === '') $focus = null;

$history = $focus ? fv_prospect_activity($focus) : [];
$last = $history[0] ?? null;
$queue = array_values(array_filter($queue, static fn (array $p): bool => !$focus || (int) $p['id'] !== (int) $focus['id']));

$todaySql = "SELECT
    COUNT(*) AS calls,
    SUM(CASE WHEN outcome IN ('interesse', 'rdv') THEN 1 ELSE 0 END) AS qualified,
    SUM(CASE WHEN outcome = 'rdv' THEN 1 ELSE 0 END) AS rdv,
    SUM(points) AS points
    FROM activities WHERE kind = 'appel' AND date(occurred_at) = date('now', 'localtime')";
$params = [];
if ($memberId > 0) {
    $todaySql .= ' AND member_id = ?';
    $params[] = $memberId;
}
$st = $pdo->prepare($todaySql);
$st->execute($params);
$today = $st->fetch() ?: ['calls' => 0, 'qualified' => 0, 'rdv' => 0, 'points' => 0];

$returnUrl = 'appels.php' . ($focus ? '?id=' . (int) $focus['id'] : '');

fv_layout_start('Appels', [
    'eyebrow' => 'Prospection téléphonique',
    'desc' => 'Une fiche à la fois, un script sous les yeux, un résultat en un clic. Chaque appel alimente le reporting et le classement.',
    'wide' => true,
    'head' => '<link rel="stylesheet" href="assets/appel-ia.css?v=1">',
    'actions' => '<a class="fv-btn fv-btn-sm" href="prospects.php?nouveau=1">' . fv_icon('plus', 14) . ' Nouveau prospect</a>'
        . '<a class="fv-btn fv-btn-sm" href="pitch.php">' . fv_icon('presentation', 14) . ' Pitch</a>',
]);
?>

<div class="fv-kpis fv-kpis-4" style="margin-bottom:16px;">
  <div class="fv-kpi"><span class="n"><?= (int) $today['calls'] ?></span><span class="l">appels aujourd’hui</span></div>
  <div class="fv-kpi"><span class="n"><?= (int) $today['qualified'] ?></span><span class="l">intéressés ou RDV</span></div>
  <div class="fv-kpi"><span class="n"><?= (int) $today['rdv'] ?></span><span class="l">RDV obtenus</span></div>
  <div class="fv-kpi"><span class="n">+<?= (int) $today['points'] ?></span><span class="l">points du jour</span></div>
</div>

<?php if (!$focus): ?>
  <section class="fv-card">
    <div class="fv-empty"><b>Personne à appeler pour l’instant</b>Ajoutez des fiches avec un téléphone depuis <a href="prospects.php">les prospects</a>, ou importez une liste.</div>
  </section>
<?php else: ?>
<div class="fv-grid fv-grid-2-1">
  <div class="fv-grid" style="gap:14px;">
    <section class="fv-card fv-callcard-wrap">
      <div class="fv-callcard">
        <div class="fv-callcard-main">
          <span class="fv-eyebrow">Appel en cours · <?= e($focus['city'] !== '' ? $focus['city'] : 'sans ville') ?></span>
          <h2><?= e($focus['business'] !== '' ? $focus['business'] : $focus['name']) ?></h2>
          <p class="fv-callmeta">
            <?= e($focus['name'] !== '' ? $focus['name'] : 'Contact à identifier') ?>
            <?= $focus['kind'] !== '' ? ' · ' . e($focus['kind']) : '' ?>
            · <span class="fv-badge" data-s="<?= e($focus['status']) ?>"><?= e(fv_status_label($focus['status'])) ?></span>
            <?= ($focus['owner_name'] ?? '') ? ' · ' . e($focus['owner_name']) : '' ?>
          </p>
          <?php if ($focus['phone'] !== ''): ?>
            <a class="fv-callphone" href="tel:<?= e($focus['phone']) ?>"><?= fv_icon('phone', 18) ?> <?= e($focus['phone']) ?></a>
          <?php endif; ?>
          <?php if ($focus['notes'] !== ''): ?><div class="fv-note"><?= nl2br(e($focus['notes'])) ?></div><?php endif; ?>
          <?php if ($last): ?>
            <p class="fv-hint" style="margin-top:12px;">Dernier échange : <?= e(fv_kind_label($last['kind'])) ?><?= $last['outcome'] !== '' ? ' · ' . e(fv_outcome_label($last['kind'], $last['outcome'])) : '' ?> le <?= e(fv_date($last['occurred_at'])) ?><?= $last['notes'] !== '' ? ' — ' . e(mb_substr($last['notes'], 0, 120)) : '' ?></p>
          <?php endif; ?>

          <div class="fv-callcard-side" style="border:1px solid var(--fv-line);border-radius:10px;margin-top:16px;padding:14px;" data-call-timer>
            <div class="fv-inline" style="justify-content:space-between;width:100%;">
              <span style="font-weight:600;font-size:.86rem;">Durée de l’appel</span>
              <output class="fv-mono" style="font-size:1.3rem;">00:00</output>
            </div>
            <div class="fv-actions" style="margin-top:10px;">
              <button class="fv-btn fv-btn-sm" type="button" data-timer-start>Démarrer</button>
              <button class="fv-btn fv-btn-sm fv-btn-ghost" type="button" data-timer-stop>Remettre à zéro</button>
            </div>
          </div>
        </div>

        <div class="fv-callcard-side">
          <div class="fv-ia" data-fv-ia data-endpoint="appel-ia.php" data-csrf="<?= e(fv_csrf()) ?>" data-prospect="<?= (int) $focus['id'] ?>">
            <div class="fv-ia-head">
              <h3>Résumé d’appel par IA</h3>
              <p>Enregistrez l’échange, ou dictez-le juste après : la transcription et le résumé remplissent vos notes. Prévenez votre interlocuteur avant d’enregistrer ; l’audio n’est pas conservé.</p>
            </div>
            <div class="fv-ia-actions">
              <button class="fv-btn fv-btn-sm fv-btn-primary" type="button" data-ia-record>Enregistrer l’appel</button>
              <button class="fv-btn fv-btn-sm fv-btn-danger" type="button" data-ia-stop hidden>Arrêter</button>
              <output class="fv-mono fv-ia-time" data-ia-time hidden>00:00</output>
            </div>
            <p class="fv-ia-status" data-ia-status role="status" aria-live="polite"></p>
            <details class="fv-ia-paste" data-ia-paste>
              <summary>Pas de micro ? Coller le texte de l’échange</summary>
              <textarea data-ia-text rows="4" maxlength="6000" placeholder="Collez le verbatim ou vos notes brutes…"></textarea>
              <button class="fv-btn fv-btn-sm" type="button" data-ia-send-text style="margin-top:8px;">Résumer ce texte</button>
            </details>
            <div class="fv-ia-result" data-ia-result hidden>
              <label for="fv-ia-summary">Résumé (modifiable avant insertion)</label>
              <textarea id="fv-ia-summary" data-ia-summary rows="5" maxlength="1200"></textarea>
              <ul class="fv-ia-chips" data-ia-chips></ul>
              <div class="fv-actions" style="margin-top:8px;">
                <button class="fv-btn fv-btn-sm fv-btn-primary" type="button" data-ia-insert>Mettre dans les notes</button>
                <button class="fv-btn fv-btn-sm fv-btn-ghost" type="button" data-ia-append>Ajouter aux notes</button>
                <button class="fv-btn fv-btn-sm fv-btn-ghost" type="button" data-ia-apply-outcome hidden></button>
              </div>
              <details style="margin-top:8px;">
                <summary class="fv-hint">Verbatim de l’IA</summary>
                <pre data-ia-transcript></pre>
                <button class="fv-btn fv-btn-sm fv-btn-ghost" type="button" data-copy-target="[data-ia-transcript]">Copier le verbatim</button>
              </details>
            </div>
          </div>
          <h3 style="font-size:.92rem;margin-bottom:8px;">Que s’est-il dit ?</h3>
          <form class="fv-form" method="post" action="activite-action.php">
            <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
            <input type="hidden" name="prospect_id" value="<?= (int) $focus['id'] ?>">
            <input type="hidden" name="kind" value="appel">
            <input type="hidden" name="retour" value="<?= e($returnUrl) ?>">
            <div class="fv-form-grid">
              <label class="fv-field">Notes
                <textarea name="notes" rows="3" placeholder="Interlocuteur, besoin, objection, angle retenu…"></textarea>
              </label>
              <label class="fv-field">Rappeler le
                <input type="date" name="next_at" value="<?= e($focus['next_action_at'] ?? '') ?>">
              </label>
              <label class="fv-field">Durée (min)
                <input type="number" name="duration" min="0" max="600" step="1" placeholder="10" data-timer-input>
              </label>
            </div>
            <div class="fv-outcomes">
              <?php foreach (fv_outcomes()['appel'] as $value => $label): if ($value === '') continue; ?>
                <button type="submit" name="outcome" value="<?= e($value) ?>" data-o="<?= e($value) ?>"><?= e($label) ?></button>
              <?php endforeach; ?>
            </div>
            <button class="fv-btn fv-btn-sm fv-btn-ghost" type="submit" name="outcome" value="" style="margin-top:8px;">Enregistrer sans résultat</button>
          </form>
        </div>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Script d’appel</h2><p>Trois phrases, puis des questions. Adaptez, ne récitez pas.</p></div></div>
      <div class="fv-card-body fv-script">
        <details open><summary>1 · Ouvrir</summary><p>« Bonjour, je suis <?= e($member ? $member['name'] : 'de la part de ' . fv_setting('marque_nom', 'Brocs')) ?>. Je vous appelle au sujet des commerces de <?= e($focus['city'] !== '' ? $focus['city'] : 'votre quartier') ?> : avez-vous deux minutes ? »</p></details>
        <details><summary>2 · Le constat</summary><p>« Beaucoup de boutiques perdent des ventes parce qu’elles ne sont pas trouvables en ligne, ou dépendent d’une place de marché qui prend une commission. »</p></details>
        <details><summary>3 · La proposition</summary><p><?= e(fv_setting('offre_pitch', 'Votre boutique en ligne prête en 48 h, sans commission sur vos ventes.')) ?></p><p><?= e(fv_setting('offre_prix', '')) ?></p></details>
        <details><summary>4 · Qualifier</summary><p>« Aujourd’hui, comment vos clients vous trouvent-ils ? Vendez-vous déjà en ligne ? Qui s’occuperait de la boutique au quotidien ? »</p></details>
        <details><summary>5 · Conclure</summary><p>« Je vous montre la boutique d’exemple en 15 minutes, sur place ou en visio. Mardi 10 h ou jeudi 15 h, qu’est-ce qui vous arrange ? »</p></details>
        <details><summary>Objections fréquentes</summary>
          <p><b>« C’est trop cher. »</b> L’offre de départ est gratuite, et il n’y a pas de commission sur les ventes. Vous payez seulement si la boutique vous rapporte.</p>
          <p><b>« Je n’ai pas le temps. »</b> On remplit la boutique ensemble : 10 minutes pour les informations, 48 h pour la mise en ligne. Vous validez, on s’occupe du reste.</p>
          <p><b>« J’ai déjà Instagram. »</b> Gardez Instagram, la boutique sert de vitrine et de panier. Un lien dans la bio, et les ventes sont centralisées.</p>
          <p><b>« Je ne suis pas à l’aise avec l’informatique. »</b> Tout se fait par formulaire, avec des photos. On vous accompagne pas à pas, et vous pouvez appeler quand vous voulez.</p>
          <p><b>« Je dois en parler. »</b> Bien sûr. Je vous laisse la fiche et le lien de démonstration ; on se rappelle jeudi pour répondre aux questions.</p>
        </details>
        <div class="fv-actions" style="margin-top:10px;">
          <a class="fv-btn fv-btn-sm" href="prospects.php?id=<?= (int) $focus['id'] ?>">Ouvrir la fiche</a>
          <a class="fv-btn fv-btn-sm" href="kits.php">Kit à envoyer</a>
          <a class="fv-btn fv-btn-sm" href="pitch.php">Pitch 2 min</a>
        </div>
      </div>
    </section>
  </div>

  <aside class="fv-grid" style="gap:14px;">
    <section class="fv-card">
      <div class="fv-card-head"><div><h2>File d’appels</h2><p>Échéances d’abord, puis priorité.</p></div><span class="fv-badge"><?= count($queue) ?></span></div>
      <div class="fv-queue">
        <?php if (!$queue): ?>
          <div class="fv-empty"><b>File vide</b>Vous avez appelé toutes les fiches en cours.</div>
        <?php else: ?>
          <div class="fv-list">
            <?php foreach (array_slice($queue, 0, 30) as $p): ?>
              <a class="fv-list-item" href="appels.php?id=<?= (int) $p['id'] ?>">
                <span class="fv-avatar"><?= e(fv_initials($p['business'] !== '' ? $p['business'] : $p['name'])) ?></span>
                <span style="min-width:0;flex:1;">
                  <b><?= e($p['business'] !== '' ? $p['business'] : $p['name']) ?></b>
                  <small><?= e(trim(($p['phone'] !== '' ? $p['phone'] . ' · ' : '') . $p['city'])) ?></small>
                </span>
                <span class="fv-badge" data-s="<?= e($p['status']) ?>"><?= e(fv_status_label($p['status'])) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Repères</h2></div></div>
      <div class="fv-card-body fv-hint">
        <p><b>Objectif réaliste :</b> 20 appels, 5 conversations utiles, 2 rendez-vous par demi-journée.</p>
        <p>Un appel sans réponse compte quand même : +<?= (int) fv_setting_int('points_appel', 2) ?> pts, et la fiche revient dans la file avec une échéance.</p>
        <p>Un intéressé ou un RDV rapporte davantage : +<?= (int) fv_setting_int('points_appel_qualifie', 5) ?> et +<?= (int) fv_setting_int('points_rdv', 15) ?> pts.</p>
      </div>
    </section>
  </aside>
</div>
<?php endif; ?>

<script src="assets/appel-ia.js?v=1" defer></script>
<?php fv_layout_end(); ?>
