<?php
// Import de contacts (clients, prospects) dans les comptes de la boutique : fichier CSV / vCard ou lecture d'un CRM. Voir includes/contacts-import-ui.php.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/contacts-import-ui.php';
require_admin(); // réservée aux administrateurs

require_once __DIR__ . '/../includes/vente-scope.php';
$content = get_content();
$csrf = admin_csrf_token();
$venteOn = vs_boutique_enabled();      // les prospects ont leur carnet dans l'extension « Force de vente » ; ici, seulement les clients
$body = ci_page([
    'url' => '/admin/contacts-import.php', 'back_url' => '/admin/accounts.php', 'back_label' => 'Voir les comptes',
    'csrf_html' => '<input type="hidden" name="csrf" value="' . h($csrf) . '">', 'csrf_check' => 'admin_csrf_check',
    'noun' => 'les comptes de la boutique', 'require_email' => true, 'allow_custom_fields' => true,
    'roles' => ['client' => 'Clients'], 'role_label' => 'Ajouter ces contacts comme',
    'exists' => static fn (array $c): ?array => account_find_by_email($c['email']),
    'create' => static function (array $c, array $o): bool {
        try {
            $notes = trim(($c['company'] !== '' ? $c['company'] . ' · ' : '') . $c['note']);
            account_create(['role' => $o['role'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'],
                'notes' => mb_substr($notes . ($notes !== '' ? ' · ' : '') . $o['source'] . ', ' . date('d/m/Y'), 0, 500),
                'custom_fields' => $c['custom_fields'] ?? []], '', 'import');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    },
    'complete' => static function (array $ex, array $c): bool {      // ne renseigne que ce qui manque ; le rôle d'une fiche existante ne change jamais
        $name = $ex['name'] === '' ? $c['name'] : $ex['name'];
        $phone = $ex['phone'] === '' ? $c['phone'] : $ex['phone'];
        $custom = account_custom_fields_decode($ex['custom_fields'] ?? []);
        $originalCustomJson = account_custom_fields_json($custom);
        foreach ((array) ($c['custom_fields'] ?? []) as $label => $value) {
            $key = account_custom_fields_normalize([$label => $value]);
            foreach ($key as $fieldKey => $field) {
                if (empty($custom[$fieldKey]['value'])) $custom[$fieldKey] = $field;
            }
        }
        $customJson = account_custom_fields_json($custom);
        if ($name === $ex['name'] && $phone === $ex['phone'] && $customJson === $originalCustomJson) return false;
        db()->prepare('UPDATE accounts SET name = ?, phone = ?, custom_fields = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$name, $phone, $customJson, $ex['id']]);
        return true;
    },
]);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Importer des contacts — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'comptes'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Équipe et contacts</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Importer des contacts</h1>
    <p><a href="/admin/accounts.php">← Retour aux comptes</a></p>
    <p class="hint" style="max-width:70ch;">Cet import ajoute des <strong>clients</strong>. Vos <strong>prospects</strong> ont leur propre carnet dans l'extension <strong>Force de vente</strong> :
      <?php if ($venteOn): ?><a href="/admin/vente/prospects-import.php">importer des prospects</a> (Excel, CSV, base SQLite…).<?php else: ?><a href="/admin/plugin.php?p=force-de-vente">activer l'extension</a> pour les importer.<?php endif; ?></p>
    <?= $body ?>
  </div>
</section>
</main>
</body>
</html>
