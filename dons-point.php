<?php
/**
 * Page des bénévoles du point de collecte, accessible par lien secret :
 *  - « À réceptionner » : un exposant apporte son objet (ou la tournée le dépose) ;
 *  - « Au point » : ce qui est en libre-service ; on marque « pris » quand un visiteur l'emporte ;
 *  - « Pris » : les derniers objets partis, avec annulation en cas de fausse manœuvre.
 */
session_start();
require_once __DIR__ . '/includes/invendus-layout.php';
unsold_private_headers();

$token = unsold_param($_POST, 't') ?: unsold_param($_GET, 't');
$event = unsold_event_by_desk_token($token);
if (!$event) {
    unsold_field_not_found('Ce lien du point de collecte n\'existe pas. Demandez-le à l\'organisation.');
}
$eid = (int) $event['id'];
$base = '/dons-point.php?t=' . $token;

$view = unsold_param($_GET, 'view');
if (!in_array($view, ['arrivals', 'stock', 'taken'], true)) $view = 'stock';
$q = unsold_line(unsold_param($_GET, 'q'), 60);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    unsold_scalar_post();
    $action = unsold_param($_POST, 'action');
    $offerId = (int) unsold_param($_POST, 'offer_id');
    $backView = unsold_param($_POST, 'view');
    if (!in_array($backView, ['arrivals', 'stock', 'taken'], true)) $backView = 'stock';
    $backQ = unsold_line(unsold_param($_POST, 'q'), 60);
    $dest = $base . '&view=' . $backView . ($backQ !== '' ? '&q=' . urlencode($backQ) : '');

    // Le bénévole n'agit que sur les objets de CETTE édition.
    $o = $offerId ? unsold_offer_get($offerId) : null;
    $mine = $o && (int) $o['event_id'] === $eid;

    $map = [
        'receive' => ['at_point', 'Reçu au point'],
        'take' => ['taken', 'Pris par un visiteur'],
        'untake' => ['at_point', 'Remis en libre-service (annulation)'],
        'withdraw' => ['withdrawn', 'Retiré au point (abîmé, repris…)'],
    ];
    if ($action === 'quick_add') {
        $id = unsold_offer_quick_add($eid, unsold_param($_POST, 'title'), unsold_param($_POST, 'category'), unsold_param($_POST, 'size'), unsold_param($_POST, 'donor_name'));
        flash_set($id ? 'Dépôt enregistré : il est tout de suite en libre-service.' : 'Décrivez l\'objet en quelques mots (3 caractères minimum).', $id ? 'ok' : 'error');
        $dest = $base . '&view=stock';
    } elseif ($mine && isset($map[$action])) {
        [$to, $note] = $map[$action];
        if (!unsold_transition($offerId, $to, 'desk', $note)) {
            flash_set('Déjà modifié par quelqu\'un d\'autre — la liste vient d\'être actualisée.', 'error');
        }
    }
    header('Location: ' . $dest);
    exit;
}

$stats = unsold_event_stats($eid);
$phase = unsold_event_phase($event);

// Contenu de l'onglet courant.
$rows = [];
if ($view === 'arrivals') {
    $all = array_merge(unsold_offers_list($eid, ['status' => 'picked', 'q' => $q]), unsold_offers_list($eid, ['status' => 'proposed', 'q' => $q]));
    // Les objets en route d'abord, puis les invendus confirmés, puis l'ordre des stands.
    usort($all, function ($a, $b) {
        $pa = ($a['status'] === 'picked' ? 0 : ($a['confirmed_at'] ? 1 : 2));
        $pb = ($b['status'] === 'picked' ? 0 : ($b['confirmed_at'] ? 1 : 2));
        return $pa <=> $pb ?: strnatcasecmp((string) $a['stand_ref'], (string) $b['stand_ref']);
    });
    $rows = $all;
} elseif ($view === 'stock') {
    $rows = unsold_offers_list($eid, ['status' => 'at_point', 'q' => $q]);
} else {
    $rows = array_slice(unsold_offers_list($eid, ['status' => 'taken', 'q' => $q]), 0, 60);
}
$shown = array_slice($rows, 0, 80);

unsold_field_head($event['point_name'] . ' — ' . $event['name']);
?>
<div class="don-field-bar">
  <div class="don-wrap">
    <div class="don-row">
      <div>
        <h1><?= h($event['point_name']) ?></h1>
        <span class="don-muted don-small"><?= h($event['name']) ?> · <?= h(unsold_fr_time($event['collect_start'])) ?>–<?= h(unsold_fr_time($event['collect_end'])) ?></span>
      </div>
      <span class="don-right"><?= unsold_phase_badge($phase) ?></span>
    </div>
  </div>
</div>

<div class="don-wrap" style="padding-bottom:40px;">
  <?php unsold_flash_html(); ?>

  <nav class="don-desk-nav" aria-label="Sections">
    <?php foreach ([
        'arrivals' => ['Arrivées', $stats['counts']['proposed'] + $stats['counts']['picked']],
        'stock' => ['Au point', $stats['counts']['at_point']],
        'taken' => ['Pris', $stats['counts']['taken']],
    ] as $key => [$label, $n]): ?>
      <a href="<?= h($base . '&view=' . $key) ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?> (<?= (int) $n ?>)</a>
    <?php endforeach; ?>
  </nav>

  <form method="get" action="/dons-point.php" class="don-inline don-row" style="margin:10px 0;">
    <input type="hidden" name="t" value="<?= h($token) ?>"><input type="hidden" name="view" value="<?= h($view) ?>">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Nom, objet, emplacement…" style="flex:1;" aria-label="Rechercher">
    <button class="btn-small" type="submit">Chercher</button>
    <?php if ($q !== ''): ?><a class="btn-small" href="<?= h($base . '&view=' . $view) ?>">Effacer</a><?php endif; ?>
  </form>

  <?php if (!$shown): ?>
    <p class="empty-state">
      <?= $q !== '' ? 'Aucun résultat.' : ['arrivals' => 'Rien à réceptionner pour l\'instant.', 'stock' => 'Le point est vide pour l\'instant.', 'taken' => 'Aucun objet pris pour l\'instant.'][$view] ?>
    </p>
  <?php endif; ?>

  <?php foreach ($shown as $o):
      $size = unsold_sizes()[$o['size']] ?? null;
  ?>
    <div class="don-stop">
      <div class="don-item">
        <div class="don-row">
          <?php if (!empty($o['photo'])): ?><img class="don-thumb" src="/<?= h($o['photo']) ?>" alt=""><?php endif; ?>
          <div>
            <h3><?= h($o['title']) ?><?= (int) $o['quantity'] > 1 ? ' <span class="don-qty">× ' . (int) $o['quantity'] . '</span>' : '' ?></h3>
            <span class="don-small don-muted">
              <?= h(unsold_category_label($o['category'])) ?><?= $size ? ' · ' . h($size['label']) : '' ?>
              <?php if ($view === 'arrivals'): ?>
                · <?= h($o['donor_name']) ?><?= $o['stand_ref'] !== '' ? ' (' . h($o['stand_ref']) . ')' : '' ?>
                <?php if ($o['donor_phone'] !== ''): ?> · <a href="tel:<?= h(preg_replace('/[^+0-9]/', '', $o['donor_phone'])) ?>"><?= h($o['donor_phone']) ?></a><?php endif; ?>
              <?php endif; ?>
            </span>
          </div>
          <span class="don-right"><?= unsold_status_badge($o['status']) ?></span>
        </div>
        <?php if ($view === 'arrivals'): ?>
          <div class="don-small don-muted">
            <?= $o['handover'] === 'pickup' ? 'Ramassage à vélo cargo' : 'Apporté par son propriétaire' ?>
            <?= $o['confirmed_at'] ? ' · <strong style="color:var(--sage);">invendu confirmé</strong>' : '' ?>
          </div>
        <?php endif; ?>

        <div class="don-item-actions">
          <?php
            $buttons = [];
            if ($view === 'arrivals') $buttons[] = ['receive', 'Reçu au point', 'don-big don-big--ok'];
            if ($view === 'stock') { $buttons[] = ['take', 'Pris', 'don-big don-big--ok']; $buttons[] = ['withdraw', 'Retirer', 'don-big don-big--no']; }
            if ($view === 'taken') $buttons[] = ['untake', 'Annuler', 'btn-small'];
          ?>
          <?php foreach ($buttons as [$act, $label, $cls]): ?>
            <form method="post" action="/dons-point.php" style="<?= $cls !== 'btn-small' ? 'flex:1;' : '' ?>"
                  <?= $act === 'withdraw' ? 'onsubmit="return confirm(\'Retirer cet objet du point (abîmé, repris par son propriétaire…) ?\');"' : '' ?>>
              <?= csrf_input() ?><input type="hidden" name="t" value="<?= h($token) ?>">
              <input type="hidden" name="view" value="<?= h($view) ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
              <button class="<?= $cls ?><?= $cls !== 'btn-small' ? ' don-big--wide' : '' ?>" type="submit"><?= $label ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (count($rows) > count($shown)): ?>
    <p class="don-muted don-small" style="margin-top:12px;"><?= count($rows) - count($shown) ?> autre(s) : utilisez la recherche pour les retrouver.</p>
  <?php endif; ?>

  <details style="margin-top:28px;" class="don-form">
    <summary style="cursor:pointer;font-weight:700;">Quelqu'un dépose un objet sans l'avoir proposé&nbsp;?</summary>
    <form method="post" action="/dons-point.php" style="margin-top:14px;">
      <?= csrf_input() ?><input type="hidden" name="t" value="<?= h($token) ?>"><input type="hidden" name="action" value="quick_add">
      <div class="field"><label for="qa-title">Objet *</label><input type="text" id="qa-title" name="title" maxlength="100" required placeholder="ex : Sac de vêtements enfant"></div>
      <div class="field-row-2">
        <div class="field"><label for="qa-cat">Catégorie</label>
          <select id="qa-cat" name="category"><?php foreach (unsold_categories() as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="qa-size">Volume</label>
          <select id="qa-size" name="size"><?php foreach (unsold_sizes() as $k => $s): ?><option value="<?= h($k) ?>"<?= $k === 'M' ? ' selected' : '' ?>><?= h($s['label']) ?> — <?= h($s['hint']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><label for="qa-name">Nom du donateur (facultatif)</label><input type="text" id="qa-name" name="donor_name" maxlength="80"></div>
      <button class="don-big don-big--ok don-big--wide" type="submit">Mettre en libre-service</button>
    </form>
  </details>

  <p class="don-muted don-small" style="margin-top:24px;">Page réservée aux bénévoles : ne partagez pas son adresse.</p>
</div>
<?php unsold_field_foot(); ?>
