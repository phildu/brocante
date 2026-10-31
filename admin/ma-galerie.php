<?php
// Mes galeries : galeries commerciales dont cette boutique est membre, et localisation de sa marchandise.
// Cette adresse alimente le champ « adresse du bien » des plateformes de vente ; elle se règle aussi, pour tous les membres,
// dans l'espace de la galerie du portail (/portail/galeries.php). Chaque côté écrit la même donnée : map.places[<boutique>].
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/galleries.php';
require_admin();

$shopSlug = tenant_slug();
$shopKey = $shopSlug;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $gaSlug = strtolower(trim((string) ($_POST['gallery'] ?? '')));
    $gallery = $gaSlug !== '' ? gallery_load($gaSlug) : null;
    $isMember = $gallery && in_array($shopKey, array_map('gallery_member_key', array_filter($gallery['members'], static fn ($m) => $m['type'] === 'local')), true);
    if (!$isMember) {
        flash_set('Galerie introuvable ou boutique non membre.', 'error');
    } else {
        $pl = (array) ($_POST['place'] ?? []);
        $entry = [];
        foreach (['street' => 120, 'postcode' => 10, 'city' => 60, 'stand' => 12, 'note' => 200] as $field => $max) {
            $entry[$field] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($pl[$field] ?? ''))), 0, $max);
        }
        $places = (array) ($gallery['map']['places'] ?? []);
        if (array_filter($entry)) $places[$shopKey] = $entry; else unset($places[$shopKey]);
        $gallery['map'] = gallery_map_normalize(['places' => $places] + (array) ($gallery['map'] ?? []), array_map('gallery_member_key', $gallery['members']), gt_locations($gallery));
        $ok = gallery_save($gallery);
        flash_set($ok ? 'Adresse de votre marchandise enregistrée.' : "Impossible d'enregistrer l'adresse (droits du dossier data/galeries).", $ok ? 'ok' : 'error');
    }
    header('Location: /admin/ma-galerie.php');
    exit;
}

$flash = flash_get();
$content = get_content();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$rows = [];
foreach (gallery_list() as $ga) {
    $member = null;
    foreach ($ga['members'] as $m) if ($m['type'] === 'local' && $m['slug'] === $shopKey) $member = $m;
    if (!$member) continue;
    // Repère du plan (ou centre de l'emplacement) : sert à retrouver l'adresse d'un clic.
    $spot = null;
    foreach (($ga['map']['items'] ?? []) as $it) {
        if ((string) ($it['shop'] ?? '') !== $shopKey || in_array($it['role'] ?? '', ['zone', 'territoire'], true)) continue;
        [$lat, $lng] = gallery_map_centroid($it['pts']);
        if ($spot === null || $it['kind'] === 'marker') $spot = [round($lat, 7), round($lng, 7)];
    }
    $rows[] = ['gallery' => $ga, 'place' => (array) ($ga['map']['places'][$shopKey] ?? []), 'spot' => $spot];
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mes galeries — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'galeries'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Rayonnement</p>
    <h1 style="font-size:1.8rem;margin:12px 0;">Mes galeries</h1>
    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>" role="status"><?= h($flash['message']) ?></p><?php endif; ?>
    <p class="hint">Où se trouve votre marchandise dans chaque galerie ? Cette adresse est reprise comme « adresse du bien » par les plateformes de vente. L'organisateur de la galerie peut aussi la renseigner ; la dernière valeur enregistrée s'applique.</p>

    <?php if (!$rows): ?>
      <div class="admin-block" style="max-width:720px;">
        <p>Votre boutique ne fait partie d'aucune galerie commerciale pour le moment.</p>
        <p class="hint">Une galerie regroupe les pièces de plusieurs commerces sur une page commune. Demandez à son organisateur de vous y ajouter.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($rows as $i => $row): $ga = $row['gallery']; $pl = $row['place']; $id = 'mg' . $i; ?>
      <div class="admin-block" style="max-width:720px;margin-top:18px;">
        <h2 style="font-size:1.2rem;margin:0 0 6px;"><?= h($ga['name']) ?></h2>
        <p class="hint" style="margin-top:0;"><a href="<?= h(gallery_public_url($ga, $origin)) ?>" target="_blank" rel="noopener">Voir la galerie publique</a></p>
        <form method="post" autocomplete="off" style="margin-top:14px;">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
          <input type="hidden" name="gallery" value="<?= h($ga['slug']) ?>">
          <div class="field">
            <label for="<?= $id ?>-street">Rue et numéro</label>
            <input type="text" id="<?= $id ?>-street" name="place[street]" maxlength="120" value="<?= h($pl['street'] ?? '') ?>" placeholder="12 rue des Fleurs">
          </div>
          <div class="field-row-2">
            <div class="field"><label for="<?= $id ?>-postcode">Code postal</label><input type="text" id="<?= $id ?>-postcode" name="place[postcode]" maxlength="10" inputmode="numeric" value="<?= h($pl['postcode'] ?? '') ?>" placeholder="75011"></div>
            <div class="field"><label for="<?= $id ?>-city">Ville</label><input type="text" id="<?= $id ?>-city" name="place[city]" maxlength="60" value="<?= h($pl['city'] ?? '') ?>" placeholder="Paris"></div>
          </div>
          <div class="field">
            <label for="<?= $id ?>-stand">Stand (facultatif)</label>
            <input type="text" id="<?= $id ?>-stand" name="place[stand]" maxlength="12" value="<?= h($pl['stand'] ?? '') ?>" placeholder="A12">
          </div>
          <div class="field">
            <label for="<?= $id ?>-note">Précision (facultatif)</label>
            <input type="text" id="<?= $id ?>-note" name="place[note]" maxlength="200" value="<?= h($pl['note'] ?? '') ?>" placeholder="Derrière la halle, entrée par la cour…">
          </div>
          <p style="margin-top:14px;">
            <button type="submit" class="btn btn-primary">Enregistrer l'adresse</button>
            <?php if ($row['spot']): ?>
              <button type="button" class="btn" data-place-from="<?= $id ?>" data-lat="<?= h((string) $row['spot'][0]) ?>" data-lng="<?= h((string) $row['spot'][1]) ?>">Reprendre l'adresse du repère sur le plan</button>
            <?php endif; ?>
          </p>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</section>
</main>
<script>
(function () {
  // Adresse à partir des coordonnées du plan : IGN (France), puis Photon en secours hors de France.
  function fill(id, p) {
    var num = p.housenumber ? String(p.housenumber) + ' ' : '';
    var street = p.street || p.name || p.locality || '';
    var s = document.getElementById(id + '-street'), pc = document.getElementById(id + '-postcode'), c = document.getElementById(id + '-city');
    if (s) s.value = (num + street).trim();
    if (pc) pc.value = p.postcode || '';
    if (c) c.value = p.city || p.town || p.village || p.municipality || '';
    return !!(street || pc.value || c.value);
  }
  document.querySelectorAll('[data-place-from]').forEach(function (b) {
    var label = b.textContent;
    function done(text) {
      b.textContent = text;
      b.disabled = false;
      if (text !== label) setTimeout(function () { b.textContent = label; }, 2500);
    }
    b.addEventListener('click', function () {
      var lat = encodeURIComponent(b.dataset.lat), lng = encodeURIComponent(b.dataset.lng), id = b.dataset.placeFrom;
      b.disabled = true; b.textContent = 'Recherche de l\'adresse…';
      fetch('https://data.geopf.fr/geocodage/reverse?index=address&limit=1&lat=' + lat + '&lon=' + lng)
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          var f = j && j.features && j.features[0];
          if (f && f.properties && fill(id, f.properties)) { done('Adresse reprise du repère'); return; }
          return fetch('https://photon.komoot.io/reverse?lang=fr&limit=1&lat=' + lat + '&lon=' + lng)
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (q) {
              var g = q && q.features && q.features[0];
              done(g && g.properties && fill(id, g.properties) ? 'Adresse reprise du repère' : 'Adresse introuvable : saisissez-la');
            });
        })
        .catch(function () { done('Recherche indisponible : saisissez l\'adresse'); });
    });
  });
})();
</script>
</body>
</html>
