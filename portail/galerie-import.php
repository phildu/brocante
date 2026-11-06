<?php
// Import de contacts dans les prospects d'une galerie : fichier CSV / vCard ou lecture d'un CRM. Voir includes/contacts-import-ui.php.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/contacts-import-ui.php';

$self = '/portail/galerie-import.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$prospectsUrl = '/portail/galerie-prospects.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$find = static function (array $c) use ($gaSlug): ?array {
    foreach (ga_prospects($gaSlug) as $p) {
        if ($c['email'] !== '' && $p['email'] === $c['email']) return $p;
        if ($c['email'] === '' && $c['phone'] !== '' && ci_phone((string) ($p['phone'] ?? '')) === $c['phone']) return $p;
    }
    return null;
};
$body = ci_page([
    'url' => $self, 'back_url' => $prospectsUrl, 'back_label' => 'Voir les prospects',
    'csrf_html' => '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">'),
    'csrf_check' => static function (): void { csrf_check(); },
    'noun' => 'les prospects de la galerie', 'require_email' => false, 'allow_custom_fields' => true,
    'exists' => $find,
    'create' => static function (array $c, array $o) use ($gaSlug): bool {
        $name = $c['name'] !== '' ? $c['name'] : ($c['company'] !== '' ? $c['company'] : ($c['email'] !== '' ? explode('@', $c['email'])[0] : $c['phone']));
        $note = trim(($c['company'] !== '' && $c['name'] !== '' ? $c['company'] . ' · ' : '') . $c['note']) . ' [' . $o['source'] . ', ' . date('d/m/Y') . ']';
        return ga_prospect_add($gaSlug, $name, $c['email'], $note, $c['phone'], $c['custom_fields'] ?? []) === null;
    },
    'complete' => static function (array $ex, array $c) use ($gaSlug): bool {
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
]);
ga_head('Importer des contacts');
?>
    <section class="gcard">
      <h2>Importer des contacts dans « <?= e($gaGallery['name']) ?> »</h2>
      <p class="meta"><a href="<?= e($prospectsUrl) ?>">← Retour aux prospects</a></p>
      <?= $body ?>
    </section>
<?php ga_foot(); ?>
