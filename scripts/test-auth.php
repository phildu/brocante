<?php
// Tests des comptes, rôles et droits fins (includes/auth.php), sur une base SQLite jetable.
//   php scripts/test-auth.php
// N'écrit jamais dans brocante.db : la base de test vit dans le dossier
// temporaire système et est supprimée à la fin.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$tmp = sys_get_temp_dir() . '/brocante-test-auth-' . getmypid() . '.db';
putenv('BROCANTE_DB=' . $tmp);
register_shutdown_function(fn() => is_file($tmp) && unlink($tmp));

// Mot de passe principal de test : défini AVANT config.php (qui le redéfinit en prod — avertissement attendu et ignoré).
define('ADMIN_PASSWORD', 'legacy-test-pass');
set_error_handler(fn($no, $str) => str_contains($str, 'already defined'));
require_once __DIR__ . '/../includes/auth.php';
restore_error_handler();

$failures = 0;
$checks = 0;
function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo "  ✗ $label\n      attendu : " . var_export($expected, true) . "\n      obtenu  : " . var_export($actual, true) . "\n";
    } else {
        echo "  ✓ $label\n";
    }
}
function throws(callable $fn): bool
{
    try { $fn(); } catch (LogicException $e) { return true; }
    return false;
}
function login(string $email, string $password): ?string
{
    $_SESSION = [];
    auth_user(true);
    return auth_login($email, $password);
}

auth_boot();

/* ------------------------------------------------------------------ */
echo "Catalogue des droits\n";
$keys = auth_permission_keys();
check('les clés sont uniques', count($keys), count(array_unique($keys)));
check('trois niveaux : portail, galerie, boutique', array_keys(auth_catalog()), ['portail', 'galerie', 'boutique']);
$allHaveLire = true;
foreach (auth_catalog() as $l => $level) {
    foreach ($level['resources'] as $r => $res) {
        if (!isset($res['actions']['lire'])) $allHaveLire = false;
    }
}
check('chaque ressource a un droit « lire »', $allHaveLire, true);
check('clé valide', auth_permission_valid('boutique.produits.supprimer'), true);
check('clé inconnue refusée', auth_permission_valid('boutique.produits.exploser'), false);
check('auth_perm() fabrique une clé valide', auth_perm('galerie.hero', 'creer'), 'galerie.hero.creer');
check('auth_perm() lève une erreur sur une clé inconnue', throws(fn() => auth_perm('galerie.hero', 'voler')), true);
check('normalisation : « lire » ajouté, inconnus et doublons retirés',
    auth_normalize_permissions(['boutique.produits.supprimer', 'boutique.produits.supprimer', 'n.importe.quoi', 42]),
    ['boutique.produits.lire', 'boutique.produits.supprimer']);
check('normalisation : ordre du catalogue', auth_normalize_permissions(['boutique.commandes.modifier', 'portail.reglages.lire']),
    ['portail.reglages.lire', 'boutique.commandes.lire', 'boutique.commandes.modifier']);

// Chaque clé « niveau.ressource.action » écrite dans le code doit exister : une faute de frappe
// ferait lever une exception en production (refus bruyant) — mieux vaut l'attraper ici.
$unknown = [];
$files = array_merge(glob(__DIR__ . '/../admin/*.php'), glob(__DIR__ . '/../admin/buvette/*.php'), glob(__DIR__ . '/../includes/*.php'), glob(__DIR__ . '/../*.php'));
foreach ($files as $f) {
    if (basename($f) === 'auth.php') continue; // le catalogue lui-même est validé ci-dessus
    preg_match_all("/['\"]((?:portail|galerie|boutique)\.[a-z_]+\.[a-z_]+)['\"]/", file_get_contents($f), $m);
    foreach ($m[1] as $k) {
        if (!auth_permission_valid($k)) $unknown[] = basename($f) . ' : ' . $k;
    }
}
check('toutes les clés de droit écrites dans les pages existent', $unknown, []);
$mapUnknown = [];
foreach (auth_action_map() as $endpoint => $actions) {
    foreach ($actions as $action => $req) {
        foreach ((array) $req as $k) if (!auth_permission_valid($k)) $mapUnknown[] = "$endpoint/$action : $k";
    }
}
check('toute la table des actions pointe vers des droits existants', $mapUnknown, []);
$navUnknown = [];
foreach (auth_nav_items() as $key => [, , $perms]) {
    foreach ($perms as $k) if (!auth_permission_valid($k)) $navUnknown[] = "$key : $k";
}
check('tout le menu pointe vers des droits existants', $navUnknown, []);

/* ------------------------------------------------------------------ */
echo "\nCouverture des pages et actions (refus par défaut)\n";
$unguarded = [];
foreach (glob(__DIR__ . '/../admin/*.php') as $f) {
    $name = basename($f);
    if (in_array($name, ['login.php', 'logout.php'], true)) continue; // seules pages publiques de l'admin
    $src = file_get_contents($f);
    if (!preg_match('/require_permission\(|require_permission_json\(|auth_require_action\(|auth_require_login\(|require_owner\(|require_admin\(|bv_admin_init\(/', $src)) {
        $unguarded[] = $name;
    }
}
foreach (['buvette' => 'bv_admin_init(', 'comptage' => 'cp_admin_init(|require_permission(', 'impression' => 'pr_admin_init(', 'notifications' => 'nt_admin_init('] as $dir => $guard) {
    foreach (glob(__DIR__ . '/../admin/' . $dir . '/*.php') as $f) {
        if (!preg_match('/' . preg_quote(explode('|', $guard)[0], '/') . '|require_permission\(/', file_get_contents($f))) $unguarded[] = $dir . '/' . basename($f);
    }
}
check('aucune page /admin/ sans contrôle d\'accès', $unguarded, []);

$flat = ['gallery-action.php', 'media-action.php', 'hero-action.php', 'slideshow-action.php', 'banners-action.php', 'shipping-action.php', 'bulk-action.php', 'batch-import-action.php'];
$missing = [];
foreach ($flat as $name) {
    preg_match_all("/^    case '([a-z_]+)':/m", file_get_contents(__DIR__ . '/../admin/' . $name), $m);
    foreach ($m[1] as $case) {
        if (auth_action_requirement($name, $case) === null) $missing[] = "$name : $case";
    }
}
check('chaque action des scripts à switch est répertoriée dans la table des droits', $missing, []);
// Modules à actions nommées (clé = chemin relatif à /admin/) : toute action `case` doit avoir son droit.
$modMissing = [];
foreach (['comptage/evenements.php', 'comptage/index.php', 'comptage/acces.php', 'impression/index.php', 'impression/nouvelle.php', 'impression/commande.php', 'impression/catalogue.php', 'impression/fournisseurs.php', 'notifications/index.php', 'notifications/utilisateurs.php', 'notifications/envoyer.php', 'notifications/historique.php', 'notifications/rubriques.php', 'notifications/canaux.php', 'connectors-action.php'] as $rel) {
    $src = file_get_contents(__DIR__ . '/../admin/' . $rel);
    preg_match_all("/^\s+case '([a-z_]+)':/m", $src, $m1);
    preg_match_all("/(?:post_action\(\)|\\$action) === '([a-z_]+)'/", $src, $m2);
    foreach (array_unique(array_merge($m1[1], $m2[1])) as $a) {
        if (auth_action_requirement($rel, $a) === null) $modMissing[] = "$rel : $a";
    }
}
check('chaque action des modules Comptage, Impression, Notifications et Boutiques connectées est répertoriée', $modMissing, []);
$dashMissing = [];
preg_match_all("/\\\$action === '([a-z_]+)'/", file_get_contents(__DIR__ . '/../admin/dashboard-action.php'), $m);
foreach ($m[1] as $a) if (auth_action_requirement('dashboard-action.php', $a) === null) $dashMissing[] = $a;
check('actions du tableau de bord répertoriées', $dashMissing, []);
$invMissing = [];
preg_match_all("/^    case '([a-z_]+)':/m", file_get_contents(__DIR__ . '/../admin/invendus-action.php'), $m);
foreach ($m[1] as $a) {
    // les sous-actions du lot (confirm, to_point…) ne sont pas des actions de premier niveau
    if (preg_match('/^(event|offer|tour|donation|recipient)_/', $a) && auth_action_requirement('invendus-action.php', $a) === null) $invMissing[] = $a;
}
check('actions Invendus & dons répertoriées', $invMissing, []);
check('action inconnue : aucun droit trouvé (donc refus)', auth_action_requirement('gallery-action.php', 'format_disque'), null);
check('endpoint inconnu : aucun droit trouvé (donc refus)', auth_action_requirement('nouveau-script.php', 'x'), null);
check('« * » couvre toute action', auth_action_requirement('delete-product.php', 'peu-importe'), ['boutique.produits.supprimer']);

require_once __DIR__ . '/../includes/buvette-layout.php';
check('buvette : delete_* -> supprimer', bv_action_verb('delete_item'), 'supprimer');
check('buvette : add_* / create_* / load_* / duplicate_* -> créer', [bv_action_verb('add_item'), bv_action_verb('create_event'), bv_action_verb('load_example'), bv_action_verb('duplicate_event')], ['creer', 'creer', 'creer', 'creer']);
check('buvette : le reste -> modifier (refus le plus prudent)', [bv_action_verb('toggle'), bv_action_verb('set_code'), bv_action_verb('whatever')], ['modifier', 'modifier', 'modifier']);

$schemaSql = file_get_contents(__DIR__ . '/../schema.sql');
check('schema.sql contient exactement le schéma des droits', str_contains($schemaSql, auth_schema_sql()), true);

/* ------------------------------------------------------------------ */
echo "\nRôles de départ\n";
$roles = auth_roles();
$byName = array_column($roles, null, 'name');
check('le rôle Propriétaire existe, en premier', $roles[0]['name'] . '/' . $roles[0]['is_owner'], 'Propriétaire/1');
check('des rôles d\'exemple sont proposés', count($roles) >= 5, true);
$prep = auth_role_get($byName['Préparateur de commandes']['id']);
check('Préparateur : peut modifier les commandes', in_array('boutique.commandes.modifier', $prep['perms'], true), true);
check('Préparateur : ne peut pas supprimer de pièce', in_array('boutique.produits.supprimer', $prep['perms'], true), false);
$ro = auth_role_get($byName['Lecture seule']['id']);
check('Lecture seule : aucun droit d\'écriture',
    array_values(array_filter($ro['perms'], fn($p) => !str_ends_with($p, '.lire'))), []);
check('Lecture seule : ni clés API, ni équipe, ni commandes',
    array_values(array_filter($ro['perms'], fn($p) => preg_match('/cles_api|utilisateurs|roles|commandes/', $p))), []);
$gest = auth_role_get($byName['Gestionnaire de boutique']['id']);
check('Gestionnaire : pas de prix d\'achat', in_array('boutique.produits.cout', $gest['perms'], true), false);
check('amorçage idempotent (pas de doublon après un second boot)', (function () { auth_boot(); return count(auth_roles()); })(), count($roles));

/* ------------------------------------------------------------------ */
echo "\nConnexion\n";
check('mot de passe principal + e-mail vide : OK', login('', 'legacy-test-pass'), null);
$legacy = auth_user();
check('… session propriétaire (id 0)', [$legacy['id'], $legacy['owner'], $legacy['legacy']], [0, true, true]);
check('… a tous les droits', auth_can('boutique.produits.supprimer') && auth_can('portail.cles_api.modifier'), true);
check('mauvais mot de passe principal', login('', 'nope'), 'Identifiants incorrects.');
check('mot de passe vide refusé', login('', ''), 'Identifiants incorrects.');
check('droit inconnu : exception même pour le propriétaire', throws(fn() => auth_can('boutique.produits.exploser')), true);
$_SESSION = [];
auth_user(true);
check('sans session : personne', auth_user(), null);
check('sans session : aucun droit', auth_can('portail.tableau_bord.lire'), false);
$_SESSION = ['is_admin' => true]; // session ouverte avant l'arrivée des comptes
auth_user(true);
check('ancienne session is_admin sans admin_uid = propriétaire', auth_user()['owner'], true);

// Un mot de passe principal VIDE (fichier .secrets absent) ne doit jamais ouvrir l'accès.
$script = 'require ' . var_export(__DIR__ . '/../includes/auth.php', true) . '; $_SESSION = []; echo var_export(auth_login("", ""), true), "|", var_export(auth_user() === null, true), "|", var_export(auth_no_access_configured(), true);';
$out = shell_exec('BROCANTE_DB=' . escapeshellarg($tmp . '-vide') . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
@unlink($tmp . '-vide');
check('mot de passe principal vide : connexion refusée, aucun accès configuré signalé', trim((string) $out), "'Identifiants incorrects.'|true|true");

/* ------------------------------------------------------------------ */
echo "\nCollaborateurs\n";
login('', 'legacy-test-pass');
$owner = auth_user();
$managerRole = $byName['Gestionnaire de boutique']['id'];
$prepRole = $byName['Préparateur de commandes']['id'];
$roRole = $byName['Lecture seule']['id'];
$ownerRole = $roles[0]['id'];

[$idMarie, $err] = auth_user_create($owner, 'Marie@Exemple.fr', 'Marie', $managerRole, 'motdepasse-solide');
check('création d\'un collaborateur', [is_int($idMarie), $err], [true, null]);
check('e-mail normalisé en minuscules', auth_user_get($idMarie)['email'], 'marie@exemple.fr');
check('doublon d\'e-mail (casse ignorée)', auth_user_create($owner, 'MARIE@exemple.fr', 'x', $managerRole, 'motdepasse-solide')[1], 'Un compte existe déjà avec cette adresse e-mail.');
check('e-mail invalide', auth_user_create($owner, 'pas-un-mail', 'x', $managerRole, 'motdepasse-solide')[1], 'Adresse e-mail invalide.');
check('mot de passe trop court', auth_user_create($owner, 'a@b.fr', 'x', $managerRole, 'court')[1], 'Le mot de passe doit faire au moins ' . AUTH_PASSWORD_MIN_LENGTH . ' caractères.');
check('mot de passe = e-mail refusé', auth_user_create($owner, 'identique@exemple.fr', 'x', $managerRole, 'identique@exemple.fr')[1], 'Le mot de passe ne doit pas être l\'adresse e-mail.');
check('rôle inexistant', auth_user_create($owner, 'zz@exemple.fr', 'x', 9999, 'motdepasse-solide')[1], 'Choisissez un rôle.');
check('mot de passe jamais stocké en clair', str_contains((string) db()->query("SELECT password_hash FROM admin_users WHERE id = $idMarie")->fetchColumn(), 'motdepasse'), false);

check('connexion Marie (e-mail en capitales)', login('MARIE@exemple.fr', 'motdepasse-solide'), null);
$marie = auth_user();
check('… session non propriétaire, mot de passe provisoire à changer', [$marie['owner'], $marie['legacy'], $marie['must_change'], $marie['role_name']], [false, false, true, 'Gestionnaire de boutique']);
check('… peut supprimer une pièce', auth_can('boutique.produits.supprimer'), true);
check('… ne peut pas voir les clés API', auth_can('portail.cles_api.lire'), false);
check('… ne peut pas gérer l\'équipe', auth_can('portail.utilisateurs.creer') || auth_can('portail.roles.modifier'), false);
check('… ne peut pas voir le prix d\'achat', auth_can('boutique.produits.cout'), false);
check('mauvais mot de passe', login('marie@exemple.fr', 'faux-mot-de-passe'), 'Identifiants incorrects.');
check('compte inconnu : même message', login('inconnu@exemple.fr', 'motdepasse-solide'), 'Identifiants incorrects.');
check('dernière connexion enregistrée', auth_user_get($idMarie)['last_login_at'] !== null, true);

// changement de mot de passe par la personne
check('changement : mauvais mot de passe actuel', auth_change_own_password($idMarie, 'faux', 'nouveau-mot-de-passe'), 'Le mot de passe actuel est incorrect.');
check('changement : identique à l\'actuel refusé', auth_change_own_password($idMarie, 'motdepasse-solide', 'motdepasse-solide'), 'Choisissez un mot de passe différent de l\'actuel.');
check('changement : trop court', auth_change_own_password($idMarie, 'motdepasse-solide', 'court') !== null, true);
check('changement OK', auth_change_own_password($idMarie, 'motdepasse-solide', 'nouveau-mot-de-passe'), null);
check('… l\'obligation de changer est levée', auth_user_get($idMarie)['must_change_password'], 0);
check('… l\'ancien mot de passe ne marche plus', login('marie@exemple.fr', 'motdepasse-solide'), 'Identifiants incorrects.');
check('… le nouveau marche', login('marie@exemple.fr', 'nouveau-mot-de-passe'), null);
check('le mot de passe principal ne se change pas ici', auth_change_own_password(0, 'a', 'b') !== null, true);

// effet immédiat des changements
$_SESSION = [];
login('marie@exemple.fr', 'nouveau-mot-de-passe');
check('avant : Marie peut supprimer une pièce', auth_can('boutique.produits.supprimer'), true);
login('', 'legacy-test-pass');
$owner = auth_user();
$mgr = auth_role_get($managerRole);
$without = array_values(array_diff($mgr['perms'], ['boutique.produits.supprimer']));
check('le propriétaire retire « supprimer » au rôle', auth_role_update($owner, $managerRole, $mgr['name'], $mgr['description'], $without), null);
$_SESSION = ['is_admin' => true, 'admin_uid' => $idMarie]; // la session de Marie, déjà ouverte
auth_user(true);
check('après : le droit disparaît dans sa session déjà ouverte, sans reconnexion', auth_can('boutique.produits.supprimer'), false);
check('… mais ses autres droits restent', auth_can('boutique.produits.modifier'), true);
check('le propriétaire désactive Marie', auth_user_update($owner, $idMarie, 'Marie', 'marie@exemple.fr', $managerRole, false), null);
auth_user(true);
check('sa session ouverte est coupée immédiatement', auth_user(), null);
check('elle ne peut plus se connecter', login('marie@exemple.fr', 'nouveau-mot-de-passe'), 'Identifiants incorrects.');
login('', 'legacy-test-pass');
$owner = auth_user();
check('réactivation', auth_user_update($owner, $idMarie, 'Marie', 'marie@exemple.fr', $managerRole, true), null);
check('… elle peut se reconnecter', login('marie@exemple.fr', 'nouveau-mot-de-passe'), null);
login('', 'legacy-test-pass');
$owner = auth_user();
check('réinitialisation du mot de passe par un responsable', auth_user_set_password($owner, $idMarie, 'provisoire-12345', true), null);
check('… mot de passe provisoire à changer', auth_user_get($idMarie)['must_change_password'], 1);
check('… l\'ancien ne marche plus', login('marie@exemple.fr', 'nouveau-mot-de-passe'), 'Identifiants incorrects.');
login('', 'legacy-test-pass');
$owner = auth_user();
$_SESSION = ['is_admin' => true, 'admin_uid' => $idMarie];
auth_user(true);
check('un compte supprimé n\'a plus de session', (function () use ($owner, $idMarie) {
    $err = auth_user_delete($owner, $idMarie);
    auth_user(true);
    return [$err, auth_user()];
})(), [null, null]);

/* ------------------------------------------------------------------ */
echo "\nRôles : création, modification, suppression\n";
login('', 'legacy-test-pass');
$owner = auth_user();
[$rid, $err] = auth_role_create($owner, 'Étiquetage', 'Colle les étiquettes', ['boutique.produits.modifier']);
check('création d\'un rôle', [is_int($rid), $err], [true, null]);
check('« lire » ajouté automatiquement', auth_role_get($rid)['perms'], ['boutique.produits.lire', 'boutique.produits.modifier']);
check('nom en double (casse ignorée)', auth_role_create($owner, 'étiquetage', '', [])[1] ?? null, 'Un rôle porte déjà ce nom.');
check('nom vide', auth_role_create($owner, '  ', '', [])[1], 'Donnez un nom au rôle.');
check('droits inconnus ignorés', (function () use ($owner) {
    [$id] = auth_role_create($owner, 'Fantaisie', '', ['boutique.produits.lire', 'a.b.c', 'portail.utilisateurs.voler']);
    return auth_role_get($id)['perms'];
})(), ['boutique.produits.lire']);
check('modification des droits', auth_role_update($owner, $rid, 'Étiquetage', 'x', ['galerie.hero.creer']), null);
check('… remplacés, pas cumulés', auth_role_get($rid)['perms'], ['galerie.hero.lire', 'galerie.hero.creer']);
check('Propriétaire : non modifiable', auth_role_update($owner, $ownerRole, 'Propriétaire', '', []), 'Le rôle Propriétaire a tous les droits et ne peut pas être modifié.');
check('Propriétaire : non supprimable', auth_role_delete($owner, $ownerRole), 'Le rôle Propriétaire ne peut pas être supprimé.');
[$tmpUser] = auth_user_create($owner, 'tmp@exemple.fr', 'Tmp', $rid, 'motdepasse-solide');
check('suppression refusée tant qu\'un collaborateur a le rôle', auth_role_delete($owner, $rid), 'Un collaborateur a encore ce rôle : changez son rôle avant de le supprimer.');
auth_user_update($owner, $tmpUser, 'Tmp', 'tmp@exemple.fr', $roRole, true);
check('suppression OK une fois le rôle libéré', auth_role_delete($owner, $rid), null);
check('… rôle disparu', auth_role_get($rid), null);

/* ------------------------------------------------------------------ */
echo "\nAnti-escalade de privilèges\n";
// « Chef d'équipe » : peut gérer collaborateurs et rôles, mais n'a PAS tous les droits.
[$chefRole] = auth_role_create($owner, 'Chef d\'équipe', '', [
    'portail.utilisateurs.lire', 'portail.utilisateurs.creer', 'portail.utilisateurs.modifier', 'portail.utilisateurs.supprimer',
    'portail.roles.lire', 'portail.roles.creer', 'portail.roles.modifier', 'portail.roles.supprimer',
    'boutique.commandes.lire', 'boutique.commandes.modifier',
]);
[$chefId] = auth_user_create($owner, 'chef@exemple.fr', 'Chef', $chefRole, 'motdepasse-solide', false);
[$autreChefId] = auth_user_create($owner, 'chef2@exemple.fr', 'Chef 2', $chefRole, 'motdepasse-solide', false);
[$gestId] = auth_user_create($owner, 'gest@exemple.fr', 'Gest', $managerRole, 'motdepasse-solide', false);
check('le chef se connecte', login('chef@exemple.fr', 'motdepasse-solide'), null);
$chef = auth_user();
check('… n\'est pas propriétaire', $chef['owner'], false);

check('ne peut pas créer un rôle avec des droits qu\'il n\'a pas',
    auth_role_create($chef, 'Super', '', ['boutique.produits.supprimer'])[1], 'Vous ne pouvez accorder que des droits que vous possédez vous-même.');
check('ne peut pas se créer un rôle avec les clés API', auth_role_create($chef, 'Clés', '', ['portail.cles_api.modifier'])[1] !== null, true);
[$okRole, $err] = auth_role_create($chef, 'Préparation bis', '', ['boutique.commandes.modifier']);
check('peut créer un rôle limité à ses propres droits', [is_int($okRole), $err], [true, null]);
check('ne peut pas attribuer le rôle Propriétaire', auth_user_create($chef, 'x1@exemple.fr', 'x', $ownerRole, 'motdepasse-solide')[1], 'Vous ne pouvez attribuer que des rôles dont les droits sont inclus dans les vôtres.');
check('ne peut pas attribuer un rôle plus puissant que le sien', auth_user_create($chef, 'x2@exemple.fr', 'x', $managerRole, 'motdepasse-solide')[1], 'Vous ne pouvez attribuer que des rôles dont les droits sont inclus dans les vôtres.');
check('peut attribuer un rôle inclus dans le sien', auth_user_create($chef, 'x3@exemple.fr', 'x', $okRole, 'motdepasse-solide')[1], null);
check('les rôles proposés au chef sont filtrés', array_column(auth_assignable_roles($chef), 'name') !== array_column(auth_roles(), 'name'), true);
check('… sans Propriétaire ni Gestionnaire', array_intersect(array_column(auth_assignable_roles($chef), 'name'), ['Propriétaire', 'Gestionnaire de boutique']), []);

check('ne peut pas modifier un compte plus puissant (gestionnaire)', auth_user_update($chef, $gestId, 'Gest', 'gest@exemple.fr', $okRole, true), 'Ce compte a plus de droits que vous : vous ne pouvez pas le modifier.');
check('ne peut pas réinitialiser son mot de passe', auth_user_set_password($chef, $gestId, 'pirate-12345678', false), 'Ce compte a plus de droits que vous : vous ne pouvez pas le modifier.');
check('ne peut pas le supprimer', auth_user_delete($chef, $gestId), 'Ce compte a plus de droits que vous : vous ne pouvez pas le supprimer.');
check('ne peut pas toucher un propriétaire', auth_user_delete($chef, (int) db()->query("SELECT id FROM admin_users WHERE role_id = $ownerRole LIMIT 1")->fetchColumn() ?: 999999) !== null, true);
check('ne peut pas modifier un rôle plus puissant', auth_role_update($chef, $managerRole, 'Gestionnaire de boutique', '', []), 'Ce rôle contient des droits que vous ne possédez pas : vous ne pouvez pas le modifier.');
check('ne peut pas supprimer un rôle plus puissant', auth_role_delete($chef, $managerRole), 'Ce rôle contient des droits que vous ne possédez pas : vous ne pouvez pas le supprimer.');
check('ne peut pas modifier son propre rôle', auth_role_update($chef, $chefRole, 'Chef d\'équipe', '', ['portail.roles.lire']), 'Vous ne pouvez pas modifier votre propre rôle : demandez-le à un autre responsable.');
check('ne peut pas changer son propre rôle', auth_user_update($chef, $chefId, 'Chef', 'chef@exemple.fr', $okRole, true), 'Vous ne pouvez pas modifier votre propre rôle ni vous désactiver vous-même.');
check('ne peut pas se désactiver', auth_user_update($chef, $chefId, 'Chef', 'chef@exemple.fr', $chefRole, false), 'Vous ne pouvez pas modifier votre propre rôle ni vous désactiver vous-même.');
check('ne peut pas se supprimer', auth_user_delete($chef, $chefId), 'Vous ne pouvez pas supprimer votre propre compte.');
check('peut modifier un pair de même niveau (autre chef)', auth_user_update($chef, $autreChefId, 'Chef 2 bis', 'chef2@exemple.fr', $chefRole, true), null);
check('ne peut pas élever un pair vers un rôle plus fort', auth_user_update($chef, $autreChefId, 'Chef 2 bis', 'chef2@exemple.fr', $managerRole, true), 'Vous ne pouvez attribuer que des rôles dont les droits sont inclus dans les vôtres.');
check('un droit retiré au rôle du chef ne lui permet plus de l\'accorder', (function () use ($owner, $chefRole) {
    $r = auth_role_get($chefRole);
    auth_role_update($owner, $chefRole, $r['name'], '', array_diff($r['perms'], ['boutique.commandes.modifier']));
    login('chef@exemple.fr', 'motdepasse-solide');
    return auth_role_create(auth_user(), 'Retard', '', ['boutique.commandes.modifier'])[1];
})(), 'Vous ne pouvez accorder que des droits que vous possédez vous-même.');

/* ------------------------------------------------------------------ */
echo "\nGarde-fou « dernier propriétaire »\n";
// Le mot de passe principal est défini dans ce test : retirer le dernier compte propriétaire est permis.
login('', 'legacy-test-pass');
$owner = auth_user();
db()->exec("DELETE FROM admin_users WHERE role_id = $ownerRole");
[$o1] = auth_user_create($owner, 'o1@exemple.fr', 'O1', $ownerRole, 'motdepasse-solide', false);
check('avec mot de passe principal : on peut supprimer le dernier compte propriétaire', auth_would_orphan_owner($o1), false);

/* ------------------------------------------------------------------ */
echo "\nLimitation des essais de connexion\n";
db()->exec('DELETE FROM admin_login_attempts');
$last = null;
for ($i = 0; $i < AUTH_LOGIN_MAX_ATTEMPTS; $i++) $last = login('o1@exemple.fr', 'mauvais');
check('les ' . AUTH_LOGIN_MAX_ATTEMPTS . ' premiers essais ratés sont refusés normalement', $last, 'Identifiants incorrects.');
check('l\'essai suivant est bloqué, même avec le bon mot de passe', login('o1@exemple.fr', 'motdepasse-solide'), 'Trop d\'essais. Réessayez dans quelques minutes.');
check('… y compris pour le mot de passe principal', login('', 'legacy-test-pass'), 'Trop d\'essais. Réessayez dans quelques minutes.');
db()->exec('UPDATE admin_login_attempts SET at = at - ' . (AUTH_LOGIN_WINDOW_SECONDS + 5));
check('le blocage expire', login('o1@exemple.fr', 'motdepasse-solide'), null);
check('une connexion réussie efface les échecs', (int) db()->query('SELECT COUNT(*) FROM admin_login_attempts')->fetchColumn(), 0);

/* ------------------------------------------------------------------ */
echo "\nMenu, page d'accueil et masquage des formulaires\n";
login('', 'legacy-test-pass');
check('propriétaire : première page = tableau de bord', auth_home_url(), '/admin/dashboard.php');
check('propriétaire : aucun formulaire masqué', auth_ui_gate('save-product.php', 'gallery-action.php'), '');
[$prepId] = auth_user_create(auth_user(), 'prep@exemple.fr', 'Prép', $prepRole, 'motdepasse-solide', false);
login('prep@exemple.fr', 'motdepasse-solide');
check('préparateur : première page = catalogue (lecture)', auth_home_url(), '/admin/catalog.php');
$gate = auth_ui_gate('save-product.php', 'update-order.php');
check('préparateur : fiche pièce en lecture seule', str_contains($gate, '["save-product.php",null,"ro"]'), true);
check('préparateur : commandes modifiables (pas de blocage)', str_contains($gate, 'update-order.php'), false);
check('préparateur : création de pièce masquée', str_contains(auth_ui_gate('add-product.php'), '["add-product.php",null,"hide"]'), true);
login('', 'legacy-test-pass');
[$nobodyRole] = auth_role_create(auth_user(), 'Aucun droit', '', []);
[$nobodyId] = auth_user_create(auth_user(), 'rien@exemple.fr', 'Rien', $nobodyRole, 'motdepasse-solide', false);
login('rien@exemple.fr', 'motdepasse-solide');
check('sans aucun droit : retour sur « Mon compte »', auth_home_url(), '/admin/account.php');
check('sans aucun droit : tout est refusé', array_filter(auth_permission_keys(), 'auth_can'), []);

echo "\n";
echo $failures === 0 ? "✓ $checks vérifications réussies.\n" : "✗ $failures échec(s) sur $checks vérifications.\n";
exit($failures === 0 ? 0 : 1);
