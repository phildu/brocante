<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fv_redirect('prospects.php');
}
fv_csrf_check();

$action = (string) ($_POST['action'] ?? '');
$pdo = fv_db();
$back = fv_back('prospects.php');

try {
    switch ($action) {
        case 'creer':
            $data = fv_prospect_from_input($_POST);
            if ($duplicate = fv_prospect_duplicate($data)) {
                fv_flash('Ce commerce ou ce contact est déjà dans le carnet : « ' . ($duplicate['business'] !== '' ? $duplicate['business'] : $duplicate['name']) . ' ».', 'error');
                fv_redirect(fv_can_access_prospect($duplicate) ? 'prospects.php?id=' . (int) $duplicate['id'] : $back);
            }
            if ($data['owner_id'] === 0 && !fv_can_manage()) {
                $data['owner_id'] = fv_effective_member_id();
            }
            $id = fv_prospect_save($data);
            fv_flash('Prospect ajouté au carnet. Prochaine étape : un premier appel ou une visite.');
            fv_redirect('prospects.php?id=' . $id);

        case 'modifier':
            $id = (int) ($_POST['id'] ?? 0);
            $existing = $id > 0 ? fv_prospect_get($id) : null;
            if (!$existing) {
                fv_flash('Ce prospect n’existe pas ou ne vous est pas attribué.', 'error');
                fv_redirect($back);
            }
            $data = fv_prospect_from_input($_POST, $existing);
            if ($duplicate = fv_prospect_duplicate($data, $id)) {
                fv_flash('Une autre fiche utilise déjà ces coordonnées : « ' . ($duplicate['business'] !== '' ? $duplicate['business'] : $duplicate['name']) . ' ».', 'error');
                fv_redirect('prospects.php?editer=' . $id);
            }
            fv_prospect_save($data, $existing);
            fv_flash('Fiche mise à jour.');
            fv_redirect('prospects.php?id=' . $id);

        case 'supprimer':
            $id = (int) ($_POST['id'] ?? 0);
            $prospect = $id > 0 ? fv_prospect_get($id) : null;
            if (!$prospect) {
                fv_flash('Ce prospect n’existe pas ou ne vous est pas attribué.', 'error');
                fv_redirect($back);
            }
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM activities WHERE prospect_id = ?')->execute([$id]);
            // Le prospect disparaît, mais les conversions déjà récompensées restent comptabilisées.
            $pdo->prepare('UPDATE redemptions SET prospect_id = 0 WHERE prospect_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE survey_responses SET prospect_id = 0 WHERE prospect_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM prospects WHERE id = ?')->execute([$id]);
            $pdo->commit();
            fv_flash('Prospect supprimé avec son historique.');
            fv_redirect($back);

        case 'envoyer':      // message de recrutement d'une galerie (relances encadrées, désinscription obligatoire)
            if (!fv_is_gallery()) {
                fv_flash('Action inconnue.', 'error');
                fv_redirect($back);
            }
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id || !fv_prospect_get($id)) {
                fv_flash('Ce prospect n’existe pas.', 'error');
                fv_redirect($back);
            }
            $err = vs_gallery_send($pdo, fv_scope()['gallery'], $id, (string) ($_POST['subject'] ?? ''), (string) ($_POST['body'] ?? ''));
            fv_flash($err ?? 'Message envoyé.', $err ? 'error' : 'ok');
            fv_redirect($back);

        case 'importer':
            $raw = (string) ($_POST['csv'] ?? '');
            $lines = preg_split('/\R/', $raw) ?: [];
            $owner = fv_can_manage() ? (int) ($_POST['owner_id'] ?? 0) : fv_effective_member_id();
            $imported = 0;
            $skipped = 0;
            $errors = 0;
            $seen = [];
            foreach (array_slice($lines, 0, 500) as $index => $line) {
                $line = trim($line);
                if ($line === '') continue;
                $cols = array_map('trim', explode(';', $line));
                if ($index === 0 && preg_match('/^commerce$/iu', $cols[0] ?? '')) continue;
                $row = [
                    'business' => $cols[0] ?? '',
                    'name' => $cols[1] ?? '',
                    'phone' => $cols[2] ?? '',
                    'email' => $cols[3] ?? '',
                    'address' => $cols[4] ?? '',
                    'postal' => $cols[5] ?? '',
                    'city' => $cols[6] ?? '',
                    'zone' => $cols[7] ?? '',
                    'notes' => $cols[8] ?? '',
                    'source' => 'terrain',
                    'owner_id' => $owner,
                ];
                if ($row['business'] === '' && $row['name'] === '') {
                    $skipped++;
                    continue;
                }
                try {
                    $data = fv_prospect_from_input($row);
                } catch (InvalidArgumentException $e) {
                    $errors++;
                    continue;
                }
                $key = mb_strtolower(implode('|', [$data['phone'], $data['email'], $data['business'] . '|' . $data['city']]));
                if (isset($seen[$key]) || fv_prospect_duplicate($data)) {
                    $skipped++;
                    continue;
                }
                $seen[$key] = true;
                fv_prospect_save($data);
                $imported++;
            }
            $message = $imported . ' fiche' . ($imported > 1 ? 's' : '') . ' importée' . ($imported > 1 ? 's' : '');
            if ($skipped > 0) $message .= ' · ' . $skipped . ' ignorée' . ($skipped > 1 ? 's' : '') . ' (vide ou déjà connue)';
            if ($errors > 0) $message .= ' · ' . $errors . ' en erreur';
            fv_flash($message . '.', $imported > 0 ? 'ok' : 'error');
            fv_redirect($back);

        default:
            fv_flash('Action inconnue.', 'error');
            fv_redirect($back);
    }
} catch (InvalidArgumentException $e) {
    fv_flash($e->getMessage(), 'error');
    fv_redirect($back);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('vente/prospect-action : ' . $e->getMessage());
    fv_flash('L’enregistrement a échoué. Réessayez dans un instant.', 'error');
    fv_redirect($back);
}
