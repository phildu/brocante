<?php
// Import de contacts dans les prospects d'une galerie : fichier CSV / vCard ou lecture d'un CRM. Voir includes/contacts-import-ui.php.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/contacts-import-ui.php';

$self = '/portail/galerie-import.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$prospectsUrl = '/portail/galerie-prospects.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
// Une seule lecture et une seule écriture pour tout le lot (une base d'annuaire compte des milliers de lignes).
$known = ga_prospects($gaSlug);
$byEmail = [];
$byPhone = [];
foreach ($known as $p) {
    if (($p['email'] ?? '') !== '') $byEmail[$p['email']] = $p;
    $ph = ci_phone((string) ($p['phone'] ?? ''));
    if ($ph !== '') $byPhone[$ph] = $p;
}
$pending = [];
$find = static function (array $c) use (&$byEmail, &$byPhone): ?array {
    if ($c['email'] !== '' && isset($byEmail[$c['email']])) return $byEmail[$c['email']];
    if ($c['email'] === '' && $c['phone'] !== '' && isset($byPhone[$c['phone']])) return $byPhone[$c['phone']];
    return null;
};
$body = ci_page([
    'url' => $self, 'back_url' => $prospectsUrl, 'back_label' => 'Voir les prospects',
    'csrf_html' => '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">'),
    'csrf_check' => static function (): void { csrf_check(); },
    'noun' => 'les prospects de la galerie', 'require_email' => false, 'allow_custom_fields' => true, 'auto_custom' => true, 'allow_no_contact' => true,
    'exists' => $find,
    'create' => static function (array $c, array $o) use (&$pending, &$byEmail, &$byPhone, $known): bool {
        if (count($known) + count($pending) >= GA_PROSPECT_MAX) return false;
        $name = $c['name'] !== '' ? $c['name'] : ($c['company'] !== '' ? $c['company'] : ($c['email'] !== '' ? explode('@', $c['email'])[0] : $c['phone']));
        if ($name === '') return false;
        $note = trim(($c['company'] !== '' && $c['name'] !== '' ? $c['company'] . ' · ' : '') . $c['note']) . ' [' . $o['source'] . ', ' . date('d/m/Y') . ']';
        $pending[] = [$name, $c['email'], $note, $c['phone'], $c['custom_fields'] ?? []];
        $row = ['id' => '', 'email' => $c['email'], 'phone' => $c['phone']];      // compte pour les doublons du reste du lot
        if ($c['email'] !== '') $byEmail[$c['email']] = $row;
        if ($c['phone'] !== '') $byPhone[$c['phone']] = $row;
        return true;
    },
    'complete' => static function (array $ex, array $c) use ($gaSlug): bool {
        if (($ex['id'] ?? '') === '') return false;      // fiche créée dans ce même lot
        $update = [];
        if (empty($ex['phone']) && $c['phone'] !== '') $update['phone'] = $c['phone'];
        $custom = ga_prospect_custom_fields_normalize((array) ($ex['custom_fields'] ?? []));
        foreach ((array) ($c['custom_fields'] ?? []) as $label => $value) {
            if (empty($custom[$label])) $custom[$label] = (string) $value;
        }
        if ($custom !== ga_prospect_custom_fields_normalize((array) ($ex['custom_fields'] ?? []))) $update['custom_fields'] = $custom;
        if (!$update) return false;
        return ga_prospect_update($gaSlug, $ex['id'], $update) === null;
    },
    'finish' => static function () use ($gaSlug, &$pending): void {
        [, $err] = ga_prospects_add_many($gaSlug, $pending);
        if ($err !== null) throw new RuntimeException($err);
    },
]);
ga_head('Importer des contacts');
?>
    <section class="gcard">
      <h2>Importer des contacts dans « <?= e($gaGallery['name']) ?> »</h2>
      <p class="meta"><a href="<?= e($prospectsUrl) ?>">← Retour aux prospects</a></p>
      <?= $body ?>
    </section>
<?php ga_foot(); ?>
