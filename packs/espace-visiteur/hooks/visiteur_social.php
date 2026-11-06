<?php
// Connexion de l'espace visiteur par un réseau social (oauth/finish.php) : l'adresse e-mail vient d'être vérifiée par le fournisseur ; on crée le
// membre au besoin (l'accord a été coché avant le clic) et on renvoie l'adresse de son espace avec son lien personnel. $value = '', $ctx = email, name.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ev.php';

if (!addon_is_entitled(EV_MODULE)) return null;
$email = mb_strtolower(trim((string) ($ctx['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
$member = ev_member_by_email($email);
if (!$member) {
    db()->prepare('INSERT INTO ev_members (email, name, token) VALUES (?, ?, ?)')->execute([$email, mb_substr(trim((string) ($ctx['name'] ?? '')), 0, 60), bin2hex(random_bytes(16))]);
    $member = ev_member_by_email($email);
}
return $member ? ev_app_url($member['token']) : null;
