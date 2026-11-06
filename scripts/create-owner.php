<?php
// Crée (ou réinitialise) un compte PROPRIÉTAIRE — l'amorçage et le dépannage de
// l'espace d'administration, en ligne de commande uniquement :
//
//   php scripts/create-owner.php vous@exemple.fr ["Votre nom"] [mot-de-passe]
//
// Sans mot de passe fourni, un mot de passe provisoire est généré et affiché une
// seule fois ; il devra être changé à la première connexion. Si le compte existe
// déjà, son mot de passe est réinitialisé et le compte réactivé (récupération
// d'accès). Utile quand .secrets/admin_password.txt est absent ou perdu.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

require_once __DIR__ . '/../includes/auth.php';

$email = auth_normalize_email((string) ($argv[1] ?? ''));
$name = trim((string) ($argv[2] ?? ''));
$password = (string) ($argv[3] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage : php scripts/create-owner.php vous@exemple.fr [\"Nom\"] [mot-de-passe]\n");
    exit(1);
}

auth_boot();
$generated = $password === '';
if ($generated) $password = auth_generate_password();
if ($err = auth_password_error($password, $email)) {
    fwrite(STDERR, $err . "\n");
    exit(1);
}

$ownerRoleId = (int) db()->query('SELECT id FROM admin_roles WHERE is_owner = 1 ORDER BY id LIMIT 1')->fetchColumn();
$existing = db()->prepare('SELECT id FROM admin_users WHERE email = ?');
$existing->execute([$email]);
$id = (int) $existing->fetchColumn();

if ($id) {
    db()->prepare('UPDATE admin_users SET password_hash = ?, role_id = ?, is_active = 1, must_change_password = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $ownerRoleId, $generated ? 1 : 0, $id]);
    echo "Compte existant réinitialisé : $email (propriétaire, actif).\n";
} else {
    db()->prepare('INSERT INTO admin_users (email, name, password_hash, role_id, must_change_password) VALUES (?, ?, ?, ?, ?)')
        ->execute([$email, $name !== '' ? $name : strstr($email, '@', true), password_hash($password, PASSWORD_DEFAULT), $ownerRoleId, $generated ? 1 : 0]);
    echo "Compte propriétaire créé : $email\n";
}
if ($generated) {
    echo "Mot de passe provisoire (affiché une seule fois) : $password\n";
    echo "Il sera à changer à la première connexion.\n";
}
