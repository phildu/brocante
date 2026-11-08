<?php
// Import de prospects dans le carnet de la force de vente : fichier Excel (.xls/.xlsx), CSV, vCard ou base SQLite (.db, ex. annuaire de commerces),
// ou lecture d'un CRM. Même mécanique que l'import des prospects de galerie (includes/contacts-import-ui.php) : colonnes reconnues d'après leurs
// en-têtes (commerce, contact, téléphone, e-mail, site, adresse, ville, catégorie, latitude/longitude…), aperçu, puis confirmation.
require __DIR__ . '/_bootstrap.php';
fv_require_auth();
require_once FV_ROOT . '/includes/contacts-import-ui.php';

$ctx = fv_current();
$members = $ctx['manager'] ? fv_members_list(true) : [];
$roles = [];
if ($ctx['manager']) {
    $roles['0'] = 'Direction';
    foreach ($members as $m) $roles[(string) (int) $m['id']] = (string) $m['name'];
}

$norm = static fn (string $v): string => ci_norm_header($v);
$street = static fn (string $v): string => $norm(preg_replace('/[,.]/', ' ', $v) ?? $v);

// Carnet existant lu une fois : détection des doublons sans interroger la base pour chaque ligne.
$byEmail = [];
$byPhone = [];
$byPlace = [];
foreach (fv_db()->query('SELECT id, business, name, phone, email, address, city FROM prospects')->fetchAll() as $p) {
    $biz = $norm((string) ($p['business'] !== '' ? $p['business'] : $p['name']));
    if ($p['email'] !== '') $byEmail[mb_strtolower($p['email'])] = $p;
    $ph = ci_phone((string) $p['phone']);
    if ($ph !== '') $byPhone[$ph][] = [$biz, $p];
    if ($biz !== '') $byPlace[$biz . '|' . ($p['address'] !== '' ? $street((string) $p['address']) : $norm((string) $p['city']))] = $p;
}

/** Valeurs d'un contact importé rangées dans les champs d'un prospect (les champs personnalisés reconnus par leur intitulé). */
$shape = static function (array $c, array $post = []): array {
    $custom = (array) ($c['custom_fields'] ?? []);
    $take = static function (string $label) use (&$custom): string {
        $v = (string) ($custom[$label] ?? '');
        unset($custom[$label]);
        return $v;
    };
    $lat = str_replace(',', '.', $take('Latitude'));
    $lng = str_replace(',', '.', $take('Longitude'));
    $data = [
        'business' => $c['company'], 'name' => $c['name'], 'kind' => $take('Catégorie'), 'address' => $take('Adresse'),
        'postal' => $take('Code postal'), 'city' => $take('Ville'), 'zone' => $take('Zone'), 'website' => $take('Site web'),
        'phone' => $c['phone'], 'email' => $c['email'], 'lat' => null, 'lng' => null,
    ];
    if ($data['name'] === '' && $data['business'] === '') $data['business'] = $data['email'] !== '' ? explode('@', $data['email'])[0] : $data['phone'];
    if (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180 && ((float) $lat !== 0.0 || (float) $lng !== 0.0)) {
        $data['lat'] = (float) $lat;
        $data['lng'] = (float) $lng;
    }
    if ($data['city'] === '') $data['city'] = (string) ($post['default_city'] ?? '');
    if ($data['postal'] === '') $data['postal'] = (string) ($post['default_postal'] ?? '');
    if ($data['zone'] === '') $data['zone'] = (string) ($post['default_zone'] ?? '');
    $lines = $c['note'] !== '' ? [$c['note']] : [];
    foreach ($custom as $label => $value) $lines[] = $label . ' : ' . $value;
    $data['notes'] = implode("\n", $lines);
    return $data;
};

/** Format lisible d'un numéro français (+33 4 78 28 33 87 -> 04 78 28 33 87) ; les autres restent au format international. */
$phoneLabel = static function (string $p): string {
    if (preg_match('/^\+33(\d)(\d{2})(\d{2})(\d{2})(\d{2})$/', $p, $m)) return '0' . $m[1] . ' ' . $m[2] . ' ' . $m[3] . ' ' . $m[4] . ' ' . $m[5];
    return $p;
};

$find = static function (array $c) use (&$byEmail, &$byPhone, &$byPlace, $norm, $street, $shape): ?array {
    $d = $shape($c);
    $biz = $norm((string) ($d['business'] !== '' ? $d['business'] : $d['name']));
    if ($c['email'] !== '' && isset($byEmail[$c['email']])) return $byEmail[$c['email']];
    // Un même numéro (standard d'un marché) peut servir plusieurs commerces : on ne l'applique qu'au commerce du même nom.
    if ($c['phone'] !== '') foreach ($byPhone[$c['phone']] ?? [] as [$otherBiz, $row]) if ($biz === '' || $otherBiz === '' || $otherBiz === $biz) return $row;
    $key = $biz . '|' . ($d['address'] !== '' ? $street($d['address']) : $norm($d['city']));
    return $biz !== '' && ($d['address'] !== '' || $d['city'] !== '') && isset($byPlace[$key]) ? $byPlace[$key] : null;
};

$body = ci_page([
    'url' => 'prospects-import.php', 'back_url' => 'prospects.php', 'back_label' => 'Voir les prospects',
    'csrf_html' => '<input type="hidden" name="csrf" value="' . e(fv_csrf()) . '">',
    'csrf_check' => static function (): void { fv_csrf_check(); },
    'noun' => 'votre carnet de prospects', 'require_email' => false, 'allow_custom_fields' => true, 'auto_custom' => true, 'allow_no_contact' => true,
    'roles' => $roles, 'role_label' => 'Attribuer les fiches à',
    'import_html' => '<p class="hint">Valeurs appliquées aux lignes qui n’en ont pas (facultatif) :</p><div class="ci-cols" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">'
        . '<label>Ville par défaut<input type="text" name="default_city" maxlength="90" placeholder="ex. Lyon"></label>'
        . '<label>Code postal par défaut<input type="text" name="default_postal" maxlength="5" inputmode="numeric" placeholder="ex. 69001"></label>'
        . '<label>Zone / tournée<input type="text" name="default_zone" maxlength="90" placeholder="ex. Pentes de la Croix-Rousse"></label></div>',
    'exists' => $find,
    'create' => static function (array $c, array $o) use (&$byEmail, &$byPhone, &$byPlace, $shape, $phoneLabel, $norm, $street, $ctx): bool {
        $d = $shape($c, (array) ($o['post'] ?? []));
        $owner = $ctx['manager'] ? (int) ($o['role'] ?? 0) : fv_effective_member_id();
        $stamp = '[' . $o['source'] . ', ' . date('d/m/Y') . ']';
        try {
            $data = fv_prospect_from_input([
                'business' => $d['business'], 'name' => $d['name'], 'kind' => $d['kind'], 'address' => $d['address'], 'postal' => $d['postal'], 'city' => $d['city'],
                'zone' => $d['zone'], 'website' => $d['website'], 'phone' => $phoneLabel($d['phone']), 'email' => $d['email'], 'source' => 'autre',
                'notes' => trim($d['notes'] . "\n" . $stamp), 'owner_id' => $owner,
            ]);
            $id = fv_prospect_save($data);
        } catch (Throwable $e) {
            return false;
        }
        if ($d['lat'] !== null) fv_prospect_set_geo($id, $d['lat'], $d['lng']);      // coordonnées du fichier : pas besoin de géocoder l'adresse
        $row = ['id' => $id, 'business' => $data['business'], 'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'], 'address' => $data['address'], 'city' => $data['city']];
        $biz = $norm((string) ($data['business'] !== '' ? $data['business'] : $data['name']));
        if ($data['email'] !== '') $byEmail[$data['email']] = $row;
        if ($c['phone'] !== '') $byPhone[$c['phone']][] = [$biz, $row];
        $k = $shape($c);      // même clé que $find (sans les valeurs par défaut)
        if ($biz !== '' && ($k['address'] !== '' || $k['city'] !== '')) $byPlace[$biz . '|' . ($k['address'] !== '' ? $street($k['address']) : $norm($k['city']))] = $row;
        return true;
    },
    'complete' => static function (array $ex, array $c) use ($shape, $phoneLabel): bool {      // ne renseigne que ce qui manque, n'écrase rien
        $row = fv_db()->prepare('SELECT * FROM prospects WHERE id = ?');
        $row->execute([(int) $ex['id']]);
        $cur = $row->fetch();
        if (!$cur) return false;
        $d = $shape($c);
        $new = ['phone' => $phoneLabel($d['phone']), 'email' => $d['email'], 'website' => $d['website'], 'address' => $d['address'], 'postal' => $d['postal'], 'city' => $d['city'], 'kind' => $d['kind']];
        $set = [];
        $params = [];
        foreach ($new as $field => $value) {
            if ($value !== '' && trim((string) $cur[$field]) === '') { $set[] = $field . ' = ?'; $params[] = mb_substr($value, 0, 200); }
        }
        if (!$set) return false;
        $params[] = fv_now();
        $params[] = (int) $cur['id'];
        fv_db()->prepare('UPDATE prospects SET ' . implode(', ', $set) . ', updated_at = ? WHERE id = ?')->execute($params);
        if (in_array('address = ?', $set, true)) fv_db()->prepare('UPDATE prospects SET lat = NULL, lng = NULL, geo_at = NULL WHERE id = ?')->execute([(int) $cur['id']]);
        return true;
    },
]);

fv_layout_start('Importer des prospects', [
    'eyebrow' => 'Terrain · carnet de prospects',
    'desc' => 'Ajoutez des commerces au carnet à partir d’un classeur Excel, d’un CSV ou d’une base SQLite (annuaire de commerces). Les colonnes sont reconnues automatiquement et rien n’est écrit avant votre confirmation.',
    'actions' => '<a class="fv-btn fv-btn-sm" href="prospects.php">← Retour aux prospects</a>',
]);
?>
<style>
  .ci { --line: var(--fv-line); --panel: var(--fv-panel); --ink-soft: var(--fv-muted); }
  .ci .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid var(--fv-line); border-radius: 9px; background: var(--fv-panel); color: var(--fv-ink); font: inherit; font-weight: 600; font-size: .86rem; cursor: pointer; text-decoration: none; }
  .ci .btn:hover { border-color: var(--fv-accent); }
  .ci .btn-primary { background: var(--fv-accent); border-color: var(--fv-accent); color: var(--fv-accent-ink); }
  .ci .btn[disabled] { opacity: .5; cursor: not-allowed; }
</style>
<section class="fv-card fv-card-pad">
  <?= $body ?>
</section>
<?php fv_layout_end(); ?>
