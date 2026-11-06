<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

// L'inscription enregistre un prospect dans les comptes de ce commerce (Administration → Comptes).
// Le champ « website » est masqué aux visiteurs : un robot qui le remplit est ignoré sans réponse différente.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string) ($_POST['website'] ?? '')) === '') {
    account_upsert_contact((string) ($_POST['email'] ?? ''), '', 'prospect', 'newsletter');
}
header('Location: /index.php?inscrit=1#contact');
exit;
