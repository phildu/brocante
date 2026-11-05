<?php
require_once __DIR__ . '/../../includes/comptage-layout.php';
cp_admin_init();

if (cp_post_action() === 'set_code') {
    $code = trim((string) ($_POST['code'] ?? ''));
    if ($code !== '' && mb_strlen($code) < 4) {
        cp_redirect('/admin/comptage/acces.php', 'Le code doit faire au moins 4 caractères.', 'error');
    }
    cp_set_setting('volunteer_code', mb_substr($code, 0, 40));
    cp_redirect('/admin/comptage/acces.php', $code === '' ? 'Comptage fermé (plus de code).' : 'Code enregistré : communiquez-le aux bénévoles avec le lien.');
}

$code = cp_volunteer_code();
$url = cp_volunteer_space_url();
$active = cp_active_event();

cp_admin_header('Accès des bénévoles', 'acces');
?>
<div class="bv-card">
  <h2>Lien et code</h2>
  <p class="bv-hint">Les bénévoles n'ont pas de compte : il leur suffit du lien et d'un code commun. Ils ne voient que le compteur de l'événement en cours — aucun autre chiffre de l'association.</p>
  <p style="margin:0 0 12px;">Lien à partager :
    <a href="<?= h($url) ?>" target="_blank" style="color:var(--accent);font-weight:600;"><?= h($url) ?></a>
    <?php if ($code === ''): ?><span class="bv-badge warn">fermé : pas de code</span><?php else: ?><span class="bv-badge ok">ouvert</span><?php endif; ?>
  </p>
  <?php if ($code !== ''): ?><p style="margin:0 0 14px;">Code actuel : <span class="cp-code"><?= h($code) ?></span></p><?php endif; ?>
  <?= cp_form_open('set_code') ?>
    <div class="bv-form-grid">
      <div class="field"><label>Code d'accès (4 caractères minimum)</label><input type="text" name="code" value="<?= h($code) ?>" autocomplete="off" maxlength="40" placeholder="ex : compteur2026"></div>
      <div class="field"><button class="btn-small" type="submit">Enregistrer</button></div>
    </div>
    <p class="bv-hint" style="margin-top:8px;">Laissez vide pour fermer le comptage. Changez le code d'une édition à l'autre ; après 5 essais ratés, l'accès est bloqué 15 minutes.</p>
  </form>
  <?php if (!$active): ?><p class="bv-callout">Aucun événement actif : les bénévoles verront « Pas d'événement à compter ». <a href="/admin/comptage/evenements.php">Préparer un événement</a>.</p><?php endif; ?>
</div>

<div class="bv-card">
  <h2>Mode d'emploi pour les bénévoles</h2>
  <ol class="cp-steps">
    <li><strong>Avant le jour J, avec du réseau :</strong> ouvrir le lien, saisir le code, puis « Ajouter à l'écran d'accueil » (menu du navigateur). Le compteur s'ouvrira ensuite comme une application, même sans réseau.</li>
    <li><strong>À l'entrée :</strong> choisir son poste (s'il y en a plusieurs), puis appuyer sur le gros bouton <em>+1</em> à chaque personne qui entre. Pour un groupe ou une famille, choisir <em>×3</em>, <em>×5</em>… avant d'appuyer.</li>
    <li><strong>Une erreur ?</strong> « Annuler » retire le dernier appui, autant de fois que nécessaire.</li>
    <li><strong>Plus de réseau ?</strong> Continuer normalement : les appuis restent sur le téléphone (pastille « en attente ») et partent tout seuls dès que la connexion revient. Ne pas vider les données du navigateur d'ici là.</li>
    <li>Brancher le téléphone si possible : l'écran reste allumé pendant le comptage.</li>
  </ol>
  <p class="bv-hint">Aucune donnée personnelle n'est enregistrée : le comptage ne mémorise que des nombres et des heures. Ne photographiez ni ne filmez les visiteurs.</p>
</div>
<?php cp_admin_footer();
