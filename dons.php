<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/invendus-layout.php';
unsold_public_enter();   // galerie d'après l'adresse (/galerie/<galerie>/dons/…), extension active

$events = unsold_public_events();
$requested = (int) unsold_param($_GET, 'event');
$event = null;
foreach ($events as $e) {
    if ((int) $e['id'] === $requested) $event = $e;
}
$event ??= unsold_pick_default_event($events);

$phase = $event ? unsold_event_phase($event) : null;
$cat = unsold_param($_GET, 'cat');
$q = unsold_line(unsold_param($_GET, 'q'), 60);
$stats = $event ? unsold_event_stats((int) $event['id']) : null;
$available = ($event && $phase === 'collecting') ? unsold_available_offers((int) $event['id'], $cat, $q) : [];
$canPropose = $event && in_array($phase, ['proposals', 'collecting'], true);

unsold_public_head('Dons & invendus du vide-grenier');
?>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Zéro gaspillage</p>
    <h1 style="font-size:2.1rem;margin:12px 0 12px;">Les invendus du vide-grenier ont droit à une seconde vie</h1>
    <p class="lede">
      Vous tenez un stand ? Proposez ce que vous ne voulez pas remporter chez vous. En fin de journée,
      un point de collecte ouvre : les bénévoles ramassent les objets (à vélo cargo), chacun peut se servir
      gratuitement, et ce qui reste part vers une donnerie.
    </p>

    <ol class="don-steps">
      <li class="don-step"><div class="don-step-n">1</div><h3>Je propose</h3><p>Sur le site, avant ou pendant le vide-grenier : ce que je donnerai s'il reste invendu.</p></li>
      <li class="don-step"><div class="don-step-n">2</div><h3>Le point ouvre</h3><p>À l'heure annoncée, l'organisation ouvre le point de collecte.</p></li>
      <li class="don-step"><div class="don-step-n">3</div><h3>On vient chercher</h3><p>J'apporte mes objets, ou un bénévole passe les prendre à mon stand en vélo cargo.</p></li>
      <li class="don-step"><div class="don-step-n">4</div><h3>Tout le monde se sert</h3><p>Les visiteurs prennent gratuitement ; le reliquat file à une donnerie.</p></li>
    </ol>
  </div>
</section>

<section class="tight" style="padding-top:0;">
  <div class="wrap">
<?php if (!$event): ?>
    <p class="empty-state">Aucun vide-grenier n'est ouvert aux dons pour le moment. Revenez bientôt&nbsp;!</p>
<?php else: ?>

<?php if (count($events) > 1): ?>
    <div class="don-filters" aria-label="Choisir l'édition">
      <?php foreach ($events as $e): ?>
        <a class="pill" href="/dons.php?event=<?= (int) $e['id'] ?>"<?= (int) $e['id'] === (int) $event['id'] ? ' aria-pressed="true"' : '' ?>><?= h($e['name']) ?></a>
      <?php endforeach; ?>
    </div>
<?php endif; ?>

    <article class="don-event">
      <div class="don-row">
        <h2><?= h($event['name']) ?></h2>
        <span class="don-right"><?= unsold_phase_badge($phase) ?></span>
      </div>
      <dl>
        <dt>Quand</dt><dd><?= h(unsold_fr_date($event['event_date'])) ?></dd>
        <?php if ($event['location'] !== ''): ?><dt>Où</dt><dd><?= h($event['location']) ?></dd><?php endif; ?>
        <dt><?= h($event['point_name']) ?></dt>
        <dd>
          ouvert de <strong><?= h(unsold_fr_time($event['collect_start'])) ?></strong> à <strong><?= h(unsold_fr_time($event['collect_end'])) ?></strong>
          <?php if ($event['point_details'] !== ''): ?><br><span class="don-muted"><?= nl2br(h($event['point_details'])) ?></span><?php endif; ?>
        </dd>
      </dl>

      <?php if ($phase === 'proposals'): ?>
        <div class="don-alert">
          <strong><?= (int) ($stats['total'] - $stats['counts']['withdrawn']) ?> objet<?= ($stats['total'] - $stats['counts']['withdrawn']) > 1 ? 's' : '' ?> déjà annoncé<?= ($stats['total'] - $stats['counts']['withdrawn']) > 1 ? 's' : '' ?>.</strong>
          Le point de collecte ouvrira à <?= h(unsold_fr_time($event['collect_start'])) ?> : la liste de ce qui est vraiment disponible s'affichera ici à ce moment-là.
        </div>
      <?php elseif ($phase === 'collecting'): ?>
        <div class="don-alert">
          <strong>Le point de collecte est ouvert&nbsp;!</strong> Venez avec un sac ou un cabas : tout est gratuit, sans réservation, premier arrivé premier servi.
          <?php $incoming = $stats['counts']['picked']; if ($incoming > 0): ?>
            <br><span class="don-muted"><?= (int) $incoming ?> autre<?= $incoming > 1 ? 's' : '' ?> objet<?= $incoming > 1 ? 's' : '' ?> en route vers le point.</span>
          <?php endif; ?>
        </div>
      <?php elseif ($phase === 'ended'): ?>
        <div class="don-alert don-alert--warn">
          <strong>La collecte est terminée, merci à toutes et à tous&nbsp;!</strong>
          <?= (int) $stats['saved'] ?> objet<?= $stats['saved'] > 1 ? 's ont' : ' a' ?> trouvé une seconde vie ; ce qui restait est remis à une donnerie.
        </div>
      <?php endif; ?>

      <?php if ($canPropose): ?>
        <div><a class="btn btn-primary" href="/dons-proposer.php?event=<?= (int) $event['id'] ?>">Proposer un objet à donner</a></div>
      <?php endif; ?>
    </article>

<?php if ($phase === 'collecting'): ?>
    <h2 style="font-size:1.5rem;margin:36px 0 4px;">Disponible maintenant au point de collecte</h2>
    <p class="don-muted don-small" style="margin:0;">Liste mise à jour par les bénévoles — à <?= h(unsold_now()->format('H\hi')) ?>. <a href="<?= h('/dons.php?event=' . (int) $event['id'] . ($cat !== '' ? '&cat=' . urlencode($cat) : '') . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>" style="color:var(--accent);">Actualiser</a></p>

    <form class="don-filters don-inline" method="get" action="/dons.php">
      <input type="hidden" name="event" value="<?= (int) $event['id'] ?>">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Chercher un objet…" aria-label="Chercher un objet">
      <select name="cat" aria-label="Catégorie">
        <option value="">Toutes les catégories</option>
        <?php foreach (unsold_categories() as $key => $label): ?>
          <option value="<?= h($key) ?>"<?= $cat === $key ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn-small" type="submit">Filtrer</button>
    </form>

    <?php if (!$available): ?>
      <p class="empty-state"><?= ($q !== '' || $cat !== '') ? 'Rien ne correspond à cette recherche pour l\'instant.' : 'Les premiers objets arrivent au point de collecte — repassez dans quelques minutes.' ?></p>
    <?php else: ?>
      <div class="don-grid">
        <?php foreach ($available as $o): $size = unsold_sizes()[$o['size']] ?? null; ?>
          <article class="don-card">
            <div class="don-card-img">
              <?php if (!empty($o['photo'])): ?>
                <img src="/<?= h($o['photo']) ?>" alt="<?= h($o['title']) ?>" loading="lazy">
              <?php else: ?>
                <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ic-vase"/></svg>
              <?php endif; ?>
            </div>
            <div class="don-card-body">
              <h3><?= h($o['title']) ?></h3>
              <?php if ((int) $o['quantity'] > 1): ?><span class="don-qty">× <?= (int) $o['quantity'] ?></span><?php endif; ?>
              <p><?= h(unsold_category_label($o['category'])) ?><?= $size ? ' · ' . h($size['label']) : '' ?></p>
              <?php if ($o['description'] !== ''): ?><p><?= h(mb_strimwidth($o['description'], 0, 140, '…')) ?></p><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
<?php endif; ?>

<?php endif; ?>
  </div>
</section>
<?php unsold_public_foot(); ?>
