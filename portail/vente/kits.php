<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
$member = $ctx['member'];
$referral = $member ? fv_referral_code((int) $member['id']) : null;

$defaults = [
    'nom' => $member ? (string) $member['name'] : 'l’équipe ' . fv_setting('marque_nom', 'Brocs'),
    'tel' => fv_setting('contact_tel', ''),
    'ville' => $member && $member['city'] !== '' ? (string) $member['city'] : '',
    'code' => $referral ? (string) $referral['code'] : '',
    'lien' => rtrim(fv_setting('site_url', ''), '/'),
];
$brand = fv_setting('marque_nom', 'Brocs');
$remise = fv_setting_int('remise_defaut', 20);
$offer = fv_setting('offre_pitch', '');
$prices = fv_setting('offre_prix', '');

fv_layout_start('Kits & visuels', [
    'eyebrow' => 'Communication de proximité',
    'desc' => 'Affiche, flyer, post social et messages prêts à envoyer. Personnalisez une fois, imprimez ou copiez.',
    'wide' => true,
    'actions' => '<a class="fv-btn fv-btn-sm" href="pitch.php">' . fv_icon('presentation', 14) . ' Pitch & slides</a>'
        . '<a class="fv-btn fv-btn-sm" href="codes.php">' . fv_icon('tag', 14) . ' Mes codes</a>',
]);
?>

<section class="fv-card fv-no-print">
  <div class="fv-card-head">
    <div><h2>Personnaliser les supports</h2><p>Les champs restent sur cet appareil. Les visuels se mettent à jour en direct.</p></div>
  </div>
  <div class="fv-card-body">
    <div class="fv-kit-toolbar">
      <label class="fv-field" style="flex:1 1 190px;">Votre nom
        <input data-kit-field="nom" value="<?= e($defaults['nom']) ?>" maxlength="80">
      </label>
      <label class="fv-field" style="flex:1 1 150px;">Téléphone
        <input data-kit-field="tel" value="<?= e($defaults['tel']) ?>" maxlength="40" inputmode="tel" placeholder="06 12 34 56 78">
      </label>
      <label class="fv-field" style="flex:1 1 130px;">Ville
        <input data-kit-field="ville" value="<?= e($defaults['ville']) ?>" maxlength="60" placeholder="Paris">
      </label>
      <label class="fv-field" style="flex:1 1 130px;">Code filleul
        <input data-kit-field="code" value="<?= e($defaults['code']) ?>" maxlength="30" placeholder="VOTRE-CODE">
      </label>
      <label class="fv-field" style="flex:2 1 220px;">Adresse du site
        <input data-kit-field="lien" value="<?= e($defaults['lien']) ?>" maxlength="190">
      </label>
    </div>
    <div class="fv-actions" style="margin-top:12px;">
      <button class="fv-btn fv-btn-sm" type="button" data-print="affiche"><?= fv_icon('print', 14) ?> Imprimer l’affiche A4</button>
      <button class="fv-btn fv-btn-sm" type="button" data-print="flyer"><?= fv_icon('print', 14) ?> Imprimer le flyer A5</button>
      <button class="fv-btn fv-btn-sm" type="button" data-print="post"><?= fv_icon('print', 14) ?> Imprimer le post</button>
      <button class="fv-btn fv-btn-sm" type="button" data-print="message"><?= fv_icon('print', 14) ?> Imprimer les messages</button>
    </div>
    <p class="fv-hint" style="margin-top:8px;">Astuce : dans la fenêtre d’impression, choisissez « Enregistrer au format PDF » pour envoyer le visuel par e-mail.</p>
  </div>
</section>

<div class="fv-grid fv-grid-2">
  <section class="fv-card fv-kit-block" id="kit-affiche">
    <div class="fv-card-head fv-no-print"><div><h2>Affiche A4</h2><p>Pour la vitrine, le comptoir ou la porte d’entrée.</p></div></div>
    <div class="fv-card-body">
      <article class="kit kit-a4">
        <div>
          <span class="kit-kicker"><?= e($brand) ?> · <span data-kit-var="ville" data-kit-empty="votre ville"><?= e($defaults['ville'] !== '' ? $defaults['ville'] : 'votre ville') ?></span></span>
          <h2 class="kit-title" style="margin-top:12px;">Votre vitrine mérite aussi d’être <em>en ligne</em>.</h2>
        </div>
        <div>
          <p class="kit-quote">« Une boutique ouverte en 48 h, sans commission sur vos ventes. »</p>
          <div class="kit-points" style="margin-top:14px;">
            <span>Vos articles, vos photos, vos horaires.</span>
            <span>Commande en ligne et retrait en boutique.</span>
            <span>Vous gardez la main sur vos prix et votre clientèle.</span>
          </div>
        </div>
        <div>
          <?php if ($offer !== ''): ?><p style="font-size:.76rem;margin-bottom:10px;"><?= e($offer) ?></p><?php endif; ?>
          <span class="kit-code"><b data-kit-var="code" data-kit-empty="VOTRE-CODE"><?= e($defaults['code'] !== '' ? $defaults['code'] : 'VOTRE-CODE') ?></b> · <?= $remise ?> % de remise parrainage</span>
          <div class="kit-foot" style="margin-top:12px;">
            <span><b data-kit-var="nom"><?= e($defaults['nom']) ?></b><br><span data-kit-var="tel" data-kit-empty="votre téléphone"><?= e($defaults['tel'] !== '' ? $defaults['tel'] : 'votre téléphone') ?></span></span>
            <span data-kit-var="lien" style="text-align:right;"><?= e($defaults['lien']) ?></span>
          </div>
        </div>
      </article>
    </div>
  </section>

  <section class="fv-card fv-kit-block" id="kit-flyer">
    <div class="fv-card-head fv-no-print"><div><h2>Flyer A5</h2><p>Pour laisser une trace après un passage.</p></div></div>
    <div class="fv-card-body">
      <article class="kit kit-a5">
        <div>
          <span class="kit-kicker"><?= e($brand) ?> · <span data-kit-var="ville" data-kit-empty="votre ville"><?= e($defaults['ville'] !== '' ? $defaults['ville'] : 'votre ville') ?></span></span>
          <h2 class="kit-title" style="margin-top:8px;">Votre boutique en ligne, <em>prête en 48 h</em>.</h2>
        </div>
        <div class="kit-points">
          <span>1 · On prépare ensemble fiches et photos.</span>
          <span>2 · Votre boutique est en ligne, à votre nom.</span>
          <span>3 · Vos clients commandent et retirent en boutique.</span>
        </div>
        <div>
          <span class="kit-code"><b data-kit-var="code" data-kit-empty="VOTRE-CODE"><?= e($defaults['code'] !== '' ? $defaults['code'] : 'VOTRE-CODE') ?></b></span>
          <div class="kit-foot" style="margin-top:8px;">
            <span data-kit-var="nom"><?= e($defaults['nom']) ?></span>
            <span><span data-kit-var="tel" data-kit-empty="votre téléphone"><?= e($defaults['tel'] !== '' ? $defaults['tel'] : 'votre téléphone') ?></span> · <span data-kit-var="lien"><?= e($defaults['lien']) ?></span></span>
          </div>
        </div>
      </article>
    </div>
  </section>

  <section class="fv-card fv-kit-block" id="kit-post">
    <div class="fv-card-head fv-no-print"><div><h2>Post social</h2><p>Format carré pour Instagram, Facebook ou LinkedIn.</p></div></div>
    <div class="fv-card-body">
      <article class="kit kit-post">
        <div>
          <span class="kit-kicker" style="opacity:.7;"><?= e($brand) ?> · commerces de <span data-kit-var="ville" data-kit-empty="votre ville"><?= e($defaults['ville'] !== '' ? $defaults['ville'] : 'votre ville') ?></span></span>
          <h2 class="kit-title" style="margin-top:10px;">Et si votre commerce se trouvait aussi <em>en ligne</em> ?</h2>
        </div>
        <p style="font-size:.9rem;color:#c9d7dc;">Boutique prête en 48 h. Sans commission sur vos ventes. Vous gardez vos clients, vos prix et vos horaires.</p>
        <div>
          <div class="kit-code" style="border-color:#f0a07d;color:#fffdf8;"><b data-kit-var="code" data-kit-empty="VOTRE-CODE" style="color:#f0a07d;"><?= e($defaults['code'] !== '' ? $defaults['code'] : 'VOTRE-CODE') ?></b> · <?= $remise ?> %</div>
          <div class="kit-foot" style="margin-top:10px;">
            <span data-kit-var="nom"><?= e($defaults['nom']) ?></span>
            <span data-kit-var="tel" data-kit-empty="votre téléphone"><?= e($defaults['tel'] !== '' ? $defaults['tel'] : 'votre téléphone') ?></span>
          </div>
        </div>
      </article>
    </div>
  </section>

  <section class="fv-card fv-kit-block" id="kit-messages">
    <div class="fv-card-head fv-no-print"><div><h2>Messages prêts à envoyer</h2><p>SMS, WhatsApp et e-mail. Copiez, ajustez, envoyez.</p></div></div>
    <div class="fv-card-body fv-grid" style="gap:12px;">
      <div class="fv-note">
        <p class="fv-eyebrow">SMS · 160 caractères</p>
        <p id="kit-sms" class="kit-message">Bonjour, <?= e($brand) ?> aide les commerces de <span data-kit-var="ville" data-kit-empty="votre ville"><?= e($defaults['ville'] !== '' ? $defaults['ville'] : 'votre ville') ?></span> à vendre en ligne sans commission. Boutique prête en 48 h. Je vous montre ? <span data-kit-var="nom"><?= e($defaults['nom']) ?></span> · <span data-kit-var="tel" data-kit-empty="votre téléphone"><?= e($defaults['tel'] !== '' ? $defaults['tel'] : 'votre téléphone') ?></span></p>
        <div class="fv-actions" style="margin-top:8px;"><button class="fv-btn fv-btn-sm" type="button" data-copy-target="#kit-sms">Copier le SMS</button></div>
      </div>

      <div class="fv-note">
        <p class="fv-eyebrow">WhatsApp</p>
        <p id="kit-wa" class="kit-message">Bonjour ! Je suis <span data-kit-var="nom"><?= e($defaults['nom']) ?></span>, je m’occupe des commerces de <span data-kit-var="ville" data-kit-empty="votre ville"><?= e($defaults['ville'] !== '' ? $defaults['ville'] : 'votre ville') ?></span> pour <?= e($brand) ?>.<br><br>Votre boutique en ligne peut être prête en 48 h, sans commission sur vos ventes. Je peux vous montrer un exemple en 15 minutes, sur place ou en visio.<br><br>Avec le code <b data-kit-var="code" data-kit-empty="VOTRE-CODE"><?= e($defaults['code'] !== '' ? $defaults['code'] : 'VOTRE-CODE') ?></b>, vous bénéficiez de <?= $remise ?> % de remise parrainage.<br><br><span data-kit-var="lien"><?= e($defaults['lien']) ?></span></p>
        <div class="fv-actions" style="margin-top:8px;"><button class="fv-btn fv-btn-sm" type="button" data-copy-target="#kit-wa">Copier le message</button></div>
      </div>

      <div class="fv-note">
        <p class="fv-eyebrow">E-mail</p>
        <p class="fv-mono" style="font-size:.75rem;">Objet : une boutique en ligne pour votre commerce, sans commission</p>
        <p id="kit-mail" class="kit-message">Bonjour,<br><br>Je suis <span data-kit-var="nom"><?= e($defaults['nom']) ?></span> et je travaille avec les commerces de <span data-kit-var="ville" data-kit-empty="votre ville"><?= e($defaults['ville'] !== '' ? $defaults['ville'] : 'votre ville') ?></span> pour <?= e($brand) ?>.<br><br><?= e($offer) ?><br><br>Avec le code <b data-kit-var="code" data-kit-empty="VOTRE-CODE"><?= e($defaults['code'] !== '' ? $defaults['code'] : 'VOTRE-CODE') ?></b>, vous bénéficiez de <?= $remise ?> % de remise parrainage. Je vous montre la boutique en 15 minutes, quand vous voulez.<br><br>Bien à vous,<br><span data-kit-var="nom"><?= e($defaults['nom']) ?></span><br><span data-kit-var="tel" data-kit-empty="votre téléphone"><?= e($defaults['tel'] !== '' ? $defaults['tel'] : 'votre téléphone') ?></span><br><span data-kit-var="lien"><?= e($defaults['lien']) ?></span></p>
        <div class="fv-actions" style="margin-top:8px;"><button class="fv-btn fv-btn-sm" type="button" data-copy-target="#kit-mail">Copier l’e-mail</button></div>
      </div>

      <?php if ($prices !== ''): ?><p class="fv-hint">Offre rappelée sur les supports : <?= e($prices) ?></p><?php endif; ?>
      <p class="fv-hint">Le code filleul est créé automatiquement pour chaque commercial ou ambassadeur. Il se retrouve aussi dans <a href="codes.php">Codes & filleuls</a>.</p>
    </div>
  </section>
</div>

<?php fv_layout_end(); ?>
