<?php
// Charte et fiche d'engagement du bénévole occasionnel : page A4 à imprimer, une par bénévole non adhérent.
// Elle prouve sa présence et rappelle que l'assurance de l'association le couvre (à confirmer auprès de l'assureur).
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

$event = bv_active_event();
$assoc = bv_site_name();
$date = $event && $event['event_date'] ? bv_fr_date($event['event_date']) : '';
$blank = static fn (int $n = 40): string => '<span class="bv-charte-line" style="min-width:' . $n . 'ch"></span>';

bv_admin_header('Charte du bénévole occasionnel', 'charte');
?>
<style>
  .bv-charte { max-width: 760px; margin: 0 auto; padding: 8px 4px 24px; line-height: 1.9; }
  .bv-charte h2 { font-size: 1.05rem; letter-spacing: 0.08em; text-transform: uppercase; margin: 22px 0 6px; padding-bottom: 4px; border-bottom: 1px solid currentColor; }
  .bv-charte h3 { font-size: 1rem; margin: 12px 0 2px; }
  .bv-charte ol { margin: 0 0 0 1.2em; padding: 0; line-height: 1.6; }
  .bv-charte .bv-charte-line { display: inline-block; border-bottom: 1px solid currentColor; height: 1em; vertical-align: baseline; }
  .bv-charte .bv-charte-sign { display: flex; gap: 24px; margin-top: 12px; }
  .bv-charte .bv-charte-sign > div { flex: 1; min-height: 90px; border: 1px solid currentColor; padding: 6px 10px; }
  @media print { .bv-charte { max-width: none; font-size: 11.5pt; line-height: 1.8; } .bv-charte h2 { break-after: avoid; } }
</style>

<div class="bv-actions bv-noprint" style="margin:0 0 16px;">
  <button class="btn btn-primary" type="button" onclick="window.print()">Imprimer la charte</button>
  <a class="btn btn-ghost" href="/admin/buvette/benevoles.php">← Bénévoles &amp; planning</a>
</div>
<p class="bv-hint bv-noprint">À faire remplir et signer par chaque bénévole qui n'est pas adhérent, avant l'événement : elle atteste de sa présence et de sa couverture en responsabilité civile. Confirmez auprès de votre assureur que les bénévoles occasionnels sont bien couverts.</p>

<article class="bv-charte">
  <h1 style="text-align:center;font-size:1.3rem;margin:0 0 10px;">Charte et fiche d'engagement du bénévole occasionnel</h1>

  <p>Association : <?= $assoc !== '' ? '<strong>' . h($assoc) . '</strong>' : $blank(45) ?><br>
  Manifestation : <?= $event ? '<strong>' . h($event['name']) . '</strong>' : 'Vide-grenier du ' . $blank(20) ?><br>
  Date : <?= $date !== '' ? '<strong>' . h($date) . '</strong>' : $blank(8) . ' / ' . $blank(4) . ' / 20' . $blank(3) ?><?php if ($event && $event['place']): ?> · Lieu : <strong><?= h($event['place']) ?></strong><?php endif; ?></p>

  <h2>Le bénévole</h2>
  <p>Nom : <?= $blank(40) ?> Prénom : <?= $blank(30) ?><br>
  Date de naissance : <?= $blank(4) ?> / <?= $blank(4) ?> / <?= $blank(8) ?><br>
  Adresse : <?= $blank(70) ?><br>
  Téléphone : <?= $blank(24) ?> E-mail : <?= $blank(36) ?><br>
  Personne à prévenir en cas d'urgence : <?= $blank(30) ?> Tél : <?= $blank(20) ?></p>

  <h2>Déroulement de la mission</h2>
  <p>Créneau(x) de présence :<br>
  ☐ Matin (installation / accueil) : <?= $blank(3) ?> h <?= $blank(3) ?> à <?= $blank(3) ?> h <?= $blank(3) ?><br>
  ☐ Après-midi (buvette / passage) : <?= $blank(3) ?> h <?= $blank(3) ?> à <?= $blank(3) ?> h <?= $blank(3) ?><br>
  ☐ Fin de journée (rangement / nettoyage) : <?= $blank(3) ?> h <?= $blank(3) ?> à <?= $blank(3) ?> h <?= $blank(3) ?><br>
  Missions confiées (accueil des exposants, buvette, circulation, rangement…) :<br>
  <?= $blank(78) ?></p>

  <h2>Engagements mutuels</h2>
  <h3>L'association s'engage à :</h3>
  <ol>
    <li>informer le bénévole des consignes de sécurité propres à la manifestation ;</li>
    <li>couvrir le bénévole en responsabilité civile pendant le temps de son intervention ;</li>
    <li>garantir un cadre de bénévolat convivial et respectueux.</li>
  </ol>
  <h3>Le bénévole s'engage à :</h3>
  <ol>
    <li>réaliser la mission à titre gratuit et volontaire (aucun lien de subordination juridique) ;</li>
    <li>respecter les consignes d'organisation et de sécurité données par les responsables de l'association ;</li>
    <li>prévenir l'organisation en cas d'empêchement ou d'abandon de mission.</li>
  </ol>

  <h2>Signatures</h2>
  <p>Fait à <?= $blank(28) ?>, le <?= $blank(4) ?> / <?= $blank(4) ?> / 20<?= $blank(3) ?></p>
  <div class="bv-charte-sign">
    <div>Signature du bénévole</div>
    <div>Signature du responsable de l'association</div>
  </div>
</article>
<?php
bv_admin_footer();
