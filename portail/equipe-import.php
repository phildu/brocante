<?php
// Import de contacts dans l'équipe du projet : fichier CSV / Excel / vCard ou
// lecture d'un CRM, avec la même mécanique que l'import des prospects de galerie
// (includes/contacts-import-ui.php). Chaque ligne crée une fiche membre ;
// la catégorie choisie à l'import s'applique à tout le lot, les domaines restent
// à compléter dans la fiche (marquée « profil à compléter »).
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/contacts-import-ui.php';
require_once PORTAIL_ROOT . '/includes/equipe.php';

$self = '/portail/equipe-import.php';
$teamUrl = '/portail/equipe.php?vue=membres';
$find = static function (array $c): ?array {
    return eq_member_find((string) $c['email'], (string) $c['phone']);
};

$body = ci_page([
    'url' => $self,
    'back_url' => $teamUrl,
    'back_label' => 'Voir l’équipe',
    'csrf_html' => '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">',
    'csrf_check' => static function (): void { csrf_check(); },
    'noun' => 'l’équipe du projet',
    'roles' => ['conseiller' => 'Conseiller', 'testeur' => 'Testeur', 'partenaire' => 'Partenaire financier'],
    'role_label' => 'Catégorie de tout le lot',
    'require_email' => false,
    'allow_custom_fields' => true,
    'exists' => $find,
    'create' => static function (array $c, array $o): bool {
        $lines = [];
        if ($c['company'] !== '' && $c['note'] !== '') $lines[] = $c['company'] . ' · ' . $c['note'];
        elseif ($c['company'] !== '') $lines[] = $c['company'];
        elseif ($c['note'] !== '') $lines[] = $c['note'];
        foreach ((array) ($c['custom_fields'] ?? []) as $label => $value) $lines[] = $label . ' : ' . $value;
        $lines[] = '[' . $o['source'] . ', ' . date('d/m/Y') . ']';
        [$member, $errors] = eq_member_from_input([
            'name' => $c['name'],
            'email' => $c['email'],
            'phone' => $c['phone'],
            'category' => $o['role'] !== '' ? $o['role'] : 'conseiller',
            'status' => 'actif',
            'source' => 'import',
            'note' => implode("\n", $lines),
        ]);
        if ($errors) return false;
        $saved = eq_member_save($member);
        if ($saved) eq_event('team_import', ['email' => $saved['email'], 'note' => $saved['name']]);
        return $saved !== null;
    },
    'complete' => static function (array $existing, array $c): bool {
        $update = $existing;
        $changed = false;
        if ($existing['phone'] === '' && $c['phone'] !== '') { $update['phone'] = $c['phone']; $changed = true; }
        if ($existing['name'] === '' && $c['name'] !== '') { $update['name'] = $c['name']; $changed = true; }
        $extra = trim(($c['company'] !== '' ? $c['company'] . ' · ' : '') . $c['note']);
        foreach ((array) ($c['custom_fields'] ?? []) as $label => $value) $extra .= ($extra !== '' ? "\n" : '') . $label . ' : ' . $value;
        if ($extra !== '' && $existing['note'] === '') { $update['note'] = $extra; $changed = true; }
        return $changed && eq_member_save($update) !== null;
    },
]);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Importer des contacts — Portail des commerces</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Importer des contacts',
    'eyebrow' => 'Équipe du projet',
    'desc' => 'Carnet d’adresses, export CSV ou CRM : chaque contact devient une fiche membre. La catégorie choisie s’applique au lot ; les domaines se complètent ensuite dans la fiche.',
    'actions' => '<a class="btn" href="/portail/equipe.php?vue=membres">← Retour à l’équipe</a>',
]); ?>
  <?= $body ?>
</div>
</body>
</html>
