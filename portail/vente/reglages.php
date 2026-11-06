<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
if (!$ctx['manager']) {
    fv_flash('Les réglages sont réservés à la direction.', 'error');
    fv_redirect('index.php');
}

$pdo = fv_db();

$parseEuro = static function ($value): int {
    $value = str_replace([' ', "\u{202f}", ','], ['', '', '.'], trim((string) $value));
    return max(0, (int) round(((float) $value) * 100));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fv_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action !== 'sauver') {
        fv_flash('Action inconnue.', 'error');
        fv_redirect('reglages.php');
    }

    $brand = fv_text($_POST['marque_nom'] ?? '', 80);
    $tel = fv_text($_POST['contact_tel'] ?? '', 40);
    $email = mb_strtolower(fv_text($_POST['contact_email'] ?? '', 190));
    $siteUrl = trim((string) ($_POST['site_url'] ?? ''));
    $pitch = fv_paragraph($_POST['offre_pitch'] ?? '', 600);
    $prices = fv_paragraph($_POST['offre_prix'] ?? '', 600);
    $ptsAppel = max(0, min(9999, (int) ($_POST['points_appel'] ?? 2)));
    $ptsQualifie = max(0, min(9999, (int) ($_POST['points_appel_qualifie'] ?? 5)));
    $ptsRdv = max(0, min(9999, (int) ($_POST['points_rdv'] ?? 15)));
    $ptsBoutique = max(0, min(99999, (int) ($_POST['points_boutique'] ?? 100)));
    $commCommercial = $parseEuro($_POST['commission_commercial'] ?? '');
    $commAmbassadeur = $parseEuro($_POST['commission_ambassadeur'] ?? '');
    $taux = max(0, min(100, (int) ($_POST['commission_taux'] ?? 0)));
    $tierArgent = max(1, (int) ($_POST['tier_argent'] ?? 100));
    $tierOr = max(2, (int) ($_POST['tier_or'] ?? 300));
    $tierPlatine = max(3, (int) ($_POST['tier_platine'] ?? 700));
    $remise = max(0, min(100, (int) ($_POST['remise_defaut'] ?? 20)));
    $catalog = fv_paragraph($_POST['recompenses_catalogue'] ?? '', 4000);

    if ($brand === '') {
        fv_flash('Le nom de la marque est obligatoire.', 'error');
        fv_redirect('reglages.php');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fv_flash('L’adresse e-mail de contact n’est pas valide.', 'error');
        fv_redirect('reglages.php');
    }
    if ($siteUrl !== '' && !filter_var($siteUrl, FILTER_VALIDATE_URL)) {
        fv_flash('L’adresse du site doit être une URL complète (https://…).', 'error');
        fv_redirect('reglages.php');
    }
    if ($tierOr <= $tierArgent || $tierPlatine <= $tierOr) {
        fv_flash('Les paliers doivent être croissants : Argent < Or < Platine.', 'error');
        fv_redirect('reglages.php');
    }

    fv_setting_save('marque_nom', $brand);
    fv_setting_save('contact_tel', $tel);
    fv_setting_save('contact_email', $email);
    fv_setting_save('site_url', rtrim($siteUrl, '/'));
    fv_setting_save('offre_pitch', $pitch);
    fv_setting_save('offre_prix', $prices);
    fv_setting_save('points_appel', (string) $ptsAppel);
    fv_setting_save('points_appel_qualifie', (string) $ptsQualifie);
    fv_setting_save('points_rdv', (string) $ptsRdv);
    fv_setting_save('points_boutique', (string) $ptsBoutique);
    fv_setting_save('commission_commercial', (string) $commCommercial);
    fv_setting_save('commission_ambassadeur', (string) $commAmbassadeur);
    fv_setting_save('commission_taux', (string) $taux);
    fv_setting_save('tier_argent', (string) $tierArgent);
    fv_setting_save('tier_or', (string) $tierOr);
    fv_setting_save('tier_platine', (string) $tierPlatine);
    fv_setting_save('remise_defaut', (string) $remise);
    fv_setting_save('recompenses_catalogue', $catalog);

    fv_flash('Réglages enregistrés. Les nouveaux barèmes s’appliquent immédiatement aux calculs.');
    fv_redirect('reglages.php');
}

$thresholds = fv_tier_thresholds();
$labels = fv_tier_labels();
$multipliers = fv_tier_multipliers();
$euro = static function (int $cents): string {
    return $cents % 100 === 0 ? (string) (int) ($cents / 100) : number_format($cents / 100, 2, '.', '');
};

$counters = [];
foreach (['members' => 'Membres', 'prospects' => 'Prospects', 'activities' => 'Activités', 'codes' => 'Codes', 'redemptions' => 'Conversions', 'surveys' => 'Sondages'] as $table => $label) {
    $counters[$label] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
$dbFile = FV_ROOT . '/data/vente/vente.db';
$dbSize = is_file($dbFile) ? (int) filesize($dbFile) : 0;

fv_layout_start('Réglages', [
    'eyebrow' => 'Administration · configuration de l’espace vente',
    'desc' => 'Marque, offre, points, commissions, paliers et catalogue de récompenses. Ces valeurs alimentent le pitch, les kits, les mailings et les calculs de prime.',
    'wide' => true,
    'actions' => '<a class="fv-btn fv-btn-sm fv-btn-primary" href="#enregistrer">' . fv_icon('check', 14) . ' Enregistrer</a>',
]);
?>

<form class="fv-grid fv-grid-side" method="post" action="reglages.php" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
  <input type="hidden" name="action" value="sauver">

  <div class="fv-grid" style="gap:14px;">
    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Marque et contact</h2><p>Ces informations remplacent les variables des kits et des messages.</p></div></div>
      <div class="fv-card-body">
        <div class="fv-form-grid">
          <label class="fv-field">Nom de la marque
            <input name="marque_nom" maxlength="80" required value="<?= e(fv_setting('marque_nom', 'Brocs')) ?>">
          </label>
          <label class="fv-field">Adresse du site
            <input type="url" name="site_url" maxlength="190" value="<?= e(fv_setting('site_url', '')) ?>" placeholder="https://brocs.arrimage.com" autocapitalize="none">
          </label>
          <label class="fv-field">Téléphone de contact
            <input name="contact_tel" maxlength="40" value="<?= e(fv_setting('contact_tel', '')) ?>" inputmode="tel" placeholder="06 12 34 56 78">
          </label>
          <label class="fv-field">E-mail de contact
            <input type="email" name="contact_email" maxlength="190" value="<?= e(fv_setting('contact_email', '')) ?>" placeholder="contact@exemple.fr">
          </label>
        </div>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Proposition commerciale</h2><p>Utilisée dans le pitch, les kits et les messages de prospection.</p></div></div>
      <div class="fv-card-body">
        <label class="fv-field">L’offre en une phrase
          <textarea name="offre_pitch" rows="2" maxlength="600"><?= e(fv_setting('offre_pitch', '')) ?></textarea>
        </label>
        <label class="fv-field">Formules et prix
          <textarea name="offre_prix" rows="2" maxlength="600" placeholder="Découverte gratuite · Standard 19,90 €/mois · Premium 49,90 €/mois"><?= e(fv_setting('offre_prix', '')) ?></textarea>
        </label>
        <p class="fv-hint">Écrivez les prix en clair : ils apparaissent tels quels dans les supports, sans calcul automatique.</p>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Points</h2><p>Chaque échange consigné crédite le membre qui l’a réalisé.</p></div></div>
      <div class="fv-card-body">
        <div class="fv-form-grid">
          <label class="fv-field">Appel passé
            <input type="number" name="points_appel" min="0" max="9999" value="<?= fv_setting_int('points_appel', 2) ?>">
          </label>
          <label class="fv-field">Appel qualifié <span class="fv-hint">intéressé, RDV, refus, faux numéro</span>
            <input type="number" name="points_appel_qualifie" min="0" max="9999" value="<?= fv_setting_int('points_appel_qualifie', 5) ?>">
          </label>
          <label class="fv-field">Rendez-vous pris
            <input type="number" name="points_rdv" min="0" max="9999" value="<?= fv_setting_int('points_rdv', 15) ?>">
          </label>
          <label class="fv-field">Boutique créée
            <input type="number" name="points_boutique" min="0" max="99999" value="<?= fv_setting_int('points_boutique', 100) ?>">
          </label>
        </div>
        <p class="fv-hint">Une visite utilise le barème « appel ». Une note ne rapporte aucun point.</p>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Commissions</h2><p>Prime fixe par boutique, puis pourcentage du revenu mensuel apporté.</p></div></div>
      <div class="fv-card-body">
        <div class="fv-form-grid">
          <label class="fv-field">Prime commercial · par boutique (€)
            <input type="number" name="commission_commercial" min="0" max="10000" step="0.01" value="<?= e($euro(fv_setting_int('commission_commercial', 6000))) ?>">
          </label>
          <label class="fv-field">Prime ambassadeur · par boutique (€)
            <input type="number" name="commission_ambassadeur" min="0" max="10000" step="0.01" value="<?= e($euro(fv_setting_int('commission_ambassadeur', 4000))) ?>">
          </label>
          <label class="fv-field">Complément sur le revenu mensuel (%)
            <input type="number" name="commission_taux" min="0" max="100" value="<?= fv_setting_int('commission_taux', 0) ?>">
          </label>
          <label class="fv-field">Remise par défaut des codes (%)
            <input type="number" name="remise_defaut" min="0" max="100" value="<?= fv_setting_int('remise_defaut', 20) ?>">
          </label>
        </div>
        <p class="fv-hint">La commission finale est multipliée par le bonus du palier du membre. Le pourcentage s’applique au revenu mensuel total des boutiques filleules.</p>
      </div>
    </section>

    <section class="fv-card" id="enregistrer">
      <div class="fv-card-head"><div><h2>Paliers</h2><p>Seuils de points cumulés, strictement croissants.</p></div></div>
      <div class="fv-card-body">
        <div class="fv-form-grid">
          <?php foreach (['argent' => 'Argent', 'or' => 'Or', 'platine' => 'Platine'] as $key => $label): ?>
            <label class="fv-field"><?= e($label) ?> · points
              <input type="number" name="tier_<?= e($key) ?>" min="<?= $key === 'argent' ? 1 : ($key === 'or' ? 2 : 3) ?>" value="<?= (int) $thresholds[$key] ?>">
            </label>
          <?php endforeach; ?>
        </div>
        <div class="fv-actions">
          <button class="fv-btn fv-btn-primary" type="submit"><?= fv_icon('check', 15) ?> Enregistrer les réglages</button>
          <a class="fv-btn" href="recompenses.php">Voir le barème</a>
        </div>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Catalogue des récompenses</h2><p>Une ligne par palier, au format « Palier — récompense ».</p></div></div>
      <div class="fv-card-body">
        <label class="fv-field">Récompenses par palier
          <textarea name="recompenses_catalogue" rows="6" maxlength="4000" placeholder="Argent — goodies Brocs&#10;Or — invitation aux soirées Brocs&#10;Platine — un mois offert"><?= e(fv_setting('recompenses_catalogue', '')) ?></textarea>
        </label>
        <p class="fv-hint">Le titre avant le tiret est repris comme palier s’il correspond à Bronze, Argent, Or ou Platine.</p>
      </div>
    </section>
  </div>

  <aside class="fv-grid" style="gap:14px;">
    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Aperçu du barème</h2></div></div>
      <div class="fv-card-body" style="gap:8px;">
        <?php foreach ($thresholds as $key => $min): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--fv-line);">
            <span class="fv-badge" data-t="<?= e($key) ?>"><?= e($labels[$key]) ?></span>
            <span class="fv-mono" style="font-size:.78rem;"><?= (int) $min ?> pts</span>
            <b class="fv-mono" style="font-size:.78rem;">×<?= number_format($multipliers[$key], 2, ',', '') ?></b>
          </div>
        <?php endforeach; ?>
        <div class="fv-hint" style="margin-top:4px;">
          <p>Commercial : <?= e(fv_money(fv_setting_int('commission_commercial', 6000))) ?> / boutique.</p>
          <p>Ambassadeur : <?= e(fv_money(fv_setting_int('commission_ambassadeur', 4000))) ?> / boutique.</p>
          <p>Complément : <?= fv_setting_int('commission_taux', 0) ?> % du revenu mensuel.</p>
        </div>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>État de l’espace</h2></div></div>
      <div class="fv-card-body" style="gap:6px;">
        <?php foreach ($counters as $label => $count): ?>
          <div style="display:flex;justify-content:space-between;font-size:.82rem;"><span class="fv-sub"><?= e($label) ?></span><b class="fv-mono"><?= $count ?></b></div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;font-size:.82rem;border-top:1px solid var(--fv-line);padding-top:8px;"><span class="fv-sub">Base SQLite</span><b class="fv-mono"><?= e(number_format($dbSize / 1024, 1, ',', ' ')) ?> Ko</b></div>
        <div style="display:flex;justify-content:space-between;font-size:.82rem;"><span class="fv-sub">Schéma</span><b class="fv-mono">v<?= e(fv_setting('schema_version', (string) FV_SCHEMA)) ?></b></div>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Module autonome</h2></div></div>
      <div class="fv-card-body fv-hint">
        <p>Les données de vente vivent dans <code>data/vente/vente.db</code>. Elles ne touchent ni aux boutiques ni aux réglages du portail.</p>
        <p>Une sauvegarde de ce fichier suffit à conserver prospects, échanges, codes, conversions et sondages.</p>
      </div>
    </section>
  </aside>
</form>

<?php fv_layout_end(); ?>
