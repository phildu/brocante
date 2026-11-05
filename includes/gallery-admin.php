<?php

// Administrateurs d'une galerie commerciale (voir includes/galleries.php) : comptes, connexion, activité, prospects et abonnés.
// Un administrateur de galerie ne voit que SA galerie : présentation, commerces membres, publication, tableau de bord, prospects.
// Les comptes sont dans data/galeries/<identifiant>.json (clé « admins », mots de passe hachés) ; l'activité, les prospects et les
// abonnés dans data/galeries/<identifiant>.{stats,prospects,subs}.json (jamais envoyés par le déploiement).

require_once __DIR__ . '/galleries.php';

const GA_MAX_LOGINS = 8;          // essais de connexion par adresse et par quart d'heure
const GA_RELANCE_DELAY = 72 * 3600;
const GA_RELANCE_MAX = 3;
const GA_STATS_KEEP_DAYS = 400;

// ── Fichiers de données ─────────────────────────────────────────────────────

function ga_file(string $slug, string $kind): string
{
    return galleries_dir() . '/' . $slug . '.' . $kind . '.json';
}

function ga_read(string $slug, string $kind, array $default = []): array
{
    if (!gallery_slug_valid($slug)) return $default;
    $f = ga_file($slug, $kind);
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : $default;
}

function ga_write(string $slug, string $kind, array $data): bool
{
    if (!gallery_slug_valid($slug)) return false;
    $dir = galleries_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    return @file_put_contents(ga_file($slug, $kind), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

// ── Adresse de connexion ────────────────────────────────────────────────────

/**
 * Adresse de connexion de l'administration d'une galerie : /galerie/<identifiant>/admin (page de connexion aux couleurs de CETTE
 * galerie, qui ouvre ensuite son tableau de bord) ; /galerie/admin.php, sans galerie précise, quand l'identifiant est vide ou invalide.
 */
function ga_login_url(?string $slug = null): string
{
    $slug = strtolower(trim((string) $slug));
    return gallery_slug_valid($slug) ? '/galerie/' . $slug . '/admin' : '/galerie/admin.php';
}

// ── Comptes administrateurs ─────────────────────────────────────────────────

/** Mot de passe provisoire lisible (sans caractères ambigus). */
function ga_password_make(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 12; $i++) $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $p;
}

function ga_admin_find(array $gallery, string $email): ?array
{
    $email = mb_strtolower(trim($email));
    foreach ($gallery['admins'] ?? [] as $a) if ($a['email'] === $email) return $a;
    return null;
}

/**
 * Administrateur d'une galerie à partir de l'identifiant saisi : e-mail ou nom.
 * Le nom n'est accepté que s'il désigne un seul administrateur dans la galerie.
 */
function ga_admin_find_by_login(array $gallery, string $login): ?array
{
    $login = mb_strtolower(trim($login));
    if ($login === '') return null;
    $byEmail = ga_admin_find($gallery, $login);
    if ($byEmail) return $byEmail;
    $matches = [];
    foreach ($gallery['admins'] ?? [] as $a) {
        if (mb_strtolower(trim((string) ($a['name'] ?? ''))) === $login) $matches[] = $a;
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/** Ajoute un administrateur (ou lui renouvelle son mot de passe). -> [message d'erreur ou null, mot de passe provisoire]. */
function ga_admin_add(string $slug, string $email, string $name): array
{
    $gallery = gallery_load($slug);
    $email = mb_strtolower(trim($email));
    if (!$gallery) return ['Galerie introuvable.', ''];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) return ['Adresse e-mail invalide.', ''];
    $password = ga_password_make();
    $admin = ['email' => $email, 'name' => mb_substr(trim($name), 0, 80), 'hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change' => true, 'created' => time(), 'last_login' => 0];
    $admins = array_values(array_filter($gallery['admins'] ?? [], static fn ($a) => $a['email'] !== $email));
    if (count($admins) >= 10) return ['10 administrateurs au plus par galerie.', ''];
    $admins[] = $admin;
    $gallery['admins'] = $admins;
    return gallery_save($gallery) ? [null, $password] : ["Impossible d'écrire la galerie (droits du dossier data/galeries).", ''];
}

function ga_admin_remove(string $slug, string $email): bool
{
    $gallery = gallery_load($slug);
    if (!$gallery) return false;
    $email = mb_strtolower(trim($email));
    $gallery['admins'] = array_values(array_filter($gallery['admins'] ?? [], static fn ($a) => $a['email'] !== $email));
    return gallery_save($gallery);
}

/** Nouveau mot de passe choisi par l'administrateur. -> message d'erreur ou null. */
function ga_admin_set_password(string $slug, string $email, string $password): ?string
{
    if (mb_strlen($password) < 10) return 'Le mot de passe doit faire 10 caractères au moins.';
    $gallery = gallery_load($slug);
    if (!$gallery) return 'Galerie introuvable.';
    $found = false;
    foreach ($gallery['admins'] as &$a) {
        if ($a['email'] === mb_strtolower($email)) { $a['hash'] = password_hash($password, PASSWORD_DEFAULT); $a['must_change'] = false; $found = true; }
    }
    unset($a);
    return $found && gallery_save($gallery) ? null : "Impossible d'enregistrer le mot de passe.";
}

function ga_login_attempts_ok(): bool
{
    $f = galleries_dir() . '/.logins.json';
    $data = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|ga');
    $recent = array_values(array_filter($data[$ip] ?? [], static fn ($t) => $t > time() - 900));
    return count($recent) < GA_MAX_LOGINS;
}

function ga_login_attempt_note(): void
{
    $dir = galleries_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    $f = $dir . '/.logins.json';
    $data = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|ga');
    $data[$ip] = array_values(array_filter($data[$ip] ?? [], static fn ($t) => $t > time() - 900));
    $data[$ip][] = time();
    foreach ($data as $k => $v) if (!array_filter($v, static fn ($t) => $t > time() - 900)) unset($data[$k]);
    @file_put_contents($f, json_encode($data), LOCK_EX);
}

/** Connexion : -> [message d'erreur ou null]. La session retient les galeries de cette adresse (une adresse peut en administrer plusieurs). */
function ga_login(string $login, string $password): ?string
{
    $login = mb_strtolower(trim($login));
    if (!ga_login_attempts_ok()) return 'Trop d\'essais : réessayez dans un quart d\'heure.';
    $emails = [];   // slug => adresse de l'administrateur qui vient de s'authentifier
    foreach (gallery_list() as $g) {
        $a = ga_admin_find_by_login($g, $login);
        if ($a && password_verify($password, $a['hash'])) $emails[$g['slug']] = mb_strtolower((string) $a['email']);
    }
    if (!$emails) {
        ga_login_attempt_note();
        sleep(1);
        return 'Identifiant, nom ou e-mail incorrect.';
    }
    // La session est liée à une adresse : un même nom peut désigner des adresses
    // différentes selon les galeries, on ne garde que celles de la première.
    $email = $emails[array_key_first($emails)];
    $slugs = [];
    foreach ($emails as $s => $mail) if ($mail === $email) $slugs[] = $s;
    session_regenerate_id(true);
    $_SESSION['gallery_admin'] = ['email' => $email, 'slugs' => $slugs, 'slug' => $slugs[0], 'at' => time()];
    foreach ($slugs as $s) {
        $g = gallery_load($s);
        foreach ($g['admins'] as &$a) if (mb_strtolower((string) $a['email']) === $email) $a['last_login'] = time();
        unset($a);
        gallery_save($g);
    }
    return null;
}

/** Connexion d'un administrateur de galerie par un réseau social (adresse e-mail vérifiée par le fournisseur). -> erreur ou null. */
function ga_login_social(string $email): ?string
{
    $email = mb_strtolower(trim($email));
    $slugs = [];
    foreach (gallery_list() as $g) if (ga_admin_find($g, $email)) $slugs[] = $g['slug'];
    if (!$slugs) return "Aucune galerie n'a $email comme administrateur : demandez à l'exploitant de vous ajouter, ou créez votre galerie.";
    session_regenerate_id(true);
    $_SESSION['gallery_admin'] = ['email' => $email, 'slugs' => $slugs, 'slug' => $slugs[0], 'at' => time()];
    foreach ($slugs as $s) {
        $g = gallery_load($s);
        foreach ($g['admins'] as &$a) if ($a['email'] === $email) $a['last_login'] = time();
        unset($a);
        gallery_save($g);
    }
    return null;
}

/** Administrateur connecté : ['email', 'slugs', 'slug' (galerie active), 'must_change'] ou null. Revérifie que le compte existe toujours. */
function ga_current(): ?array
{
    $s = $_SESSION['gallery_admin'] ?? null;
    if (!is_array($s) || empty($s['email'])) return null;
    $slugs = [];
    $must = false;
    foreach ((array) ($s['slugs'] ?? []) as $slug) {
        $g = is_string($slug) ? gallery_load($slug) : null;
        if ($g && ($a = ga_admin_find($g, $s['email']))) { $slugs[] = $slug; $must = $must || !empty($a['must_change']); }
    }
    if (!$slugs) { unset($_SESSION['gallery_admin']); return null; }
    $active = in_array($s['slug'] ?? '', $slugs, true) ? $s['slug'] : $slugs[0];
    $_SESSION['gallery_admin']['slugs'] = $slugs;
    $_SESSION['gallery_admin']['slug'] = $active;
    return ['email' => $s['email'], 'slugs' => $slugs, 'slug' => $active, 'must_change' => $must];
}

// ── Mot de passe oublié (lien signé, sans stockage : il cesse de valoir dès que le mot de passe change) ───────────────────────

const GA_RESET_TTL = 3600;

/** Signature du lien : liée à l'adresse, à l'échéance et aux empreintes actuelles des mots de passe (usage unique). */
function ga_reset_sig(string $email, int $exp): string
{
    $state = '';
    foreach (gallery_list() as $g) if ($a = ga_admin_find($g, $email)) $state .= $g['slug'] . ':' . $a['hash'] . ';';
    gallery_preview_token(['slug' => 'reset', 'created' => 0]);   // crée le sel au besoin
    $salt = (string) @file_get_contents(galleries_dir() . '/.salt');
    return hash_hmac('sha256', 'ga-reset|' . $email . '|' . $exp . '|' . $state, $salt);
}

/** Envoie le lien si l'identifiant (e-mail, nom ou identifiant) administre une galerie ; ne révèle jamais si le compte existe. */
function ga_reset_request(string $login): void
{
    $login = mb_strtolower(trim($login));
    if ($login === '' || !ga_login_attempts_ok()) return;
    ga_login_attempt_note();
    $emails = [];
    foreach (gallery_list() as $g) {
        $a = ga_admin_find_by_login($g, $login);
        if ($a && filter_var($a['email'], FILTER_VALIDATE_EMAIL)) $emails[mb_strtolower((string) $a['email'])] = true;
    }
    foreach (array_keys($emails) as $email) {
        $exp = time() + GA_RESET_TTL;
        $url = ga_public_url('/galerie/mot-de-passe.php?' . http_build_query(['e' => $email, 'x' => $exp, 't' => ga_reset_sig($email, $exp)]));
        ga_mail($email, 'Réinitialisation de votre mot de passe (administration de galerie)',
            "Bonjour,\n\nPour choisir un nouveau mot de passe d'administrateur de galerie, ouvrez ce lien (valable 1 heure, à usage unique) :\n\n$url\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.\n");
    }
}

function ga_reset_valid(string $email, int $exp, string $sig): bool
{
    $email = mb_strtolower(trim($email));
    return $exp > time() && $exp <= time() + GA_RESET_TTL && $sig !== '' && hash_equals(ga_reset_sig($email, $exp), $sig);
}

/** Applique le nouveau mot de passe à toutes les galeries de cette adresse. -> message d'erreur ou null. */
function ga_reset_apply(string $email, int $exp, string $sig, string $password, string $confirm): ?string
{
    $email = mb_strtolower(trim($email));
    if (!ga_reset_valid($email, $exp, $sig)) return 'Ce lien a expiré ou a déjà servi.';
    if ($password !== $confirm) return 'Les deux mots de passe ne sont pas identiques.';
    if (mb_strlen($password) < 10) return 'Le mot de passe doit faire 10 caractères au moins.';
    foreach (gallery_list() as $g) {
        if (ga_admin_find($g, $email) && ($err = ga_admin_set_password($g['slug'], $email, $password))) return $err;
    }
    return null;
}

// ── Activité de la page publique (sans cookie : un visiteur n'est qu'une empreinte qui change chaque jour) ─────────────────────

function ga_track_view(string $slug): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|curl|wget|python|monitor/i', $ua)) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || isset($_SERVER['HTTP_PURPOSE'])) return;
    // Les visites d'un administrateur ou de l'exploitant ne comptent pas (on ne lit la session que si le visiteur en a déjà une : aucun cookie n'est posé).
    if (isset($_COOKIE[session_name()]) && session_status() === PHP_SESSION_NONE) {
        @session_start();
        $isStaff = !empty($_SESSION['gallery_admin']) || !empty($_SESSION['portail_user']);
        session_write_close();
        if ($isStaff) return;
    }
    $day = gmdate('Y-m-d');
    $stats = ga_read($slug, 'stats', ['days' => []]);
    $d = $stats['days'][$day] ?? ['v' => 0, 'u' => []];
    $d['v']++;
    $vid = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua . '|' . $day . '|' . $slug), 0, 10);
    if (count($d['u']) < 5000 && !in_array($vid, $d['u'], true)) $d['u'][] = $vid;
    $stats['days'][$day] = $d;
    $cut = gmdate('Y-m-d', time() - GA_STATS_KEEP_DAYS * 86400);
    foreach (array_keys($stats['days']) as $k) if ($k < $cut) unset($stats['days'][$k]);
    ga_write($slug, 'stats', $stats);
}

/** Série quotidienne [['day', 'views', 'visitors']] sur $days jours + totaux. */
function ga_stats(string $slug, int $days): array
{
    $days = max(1, min(365, $days));
    $raw = ga_read($slug, 'stats', ['days' => []])['days'];
    $series = [];
    $views = $visitors = 0;
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = gmdate('Y-m-d', time() - $i * 86400);
        $v = (int) ($raw[$d]['v'] ?? 0);
        $u = count($raw[$d]['u'] ?? []);
        $series[] = ['day' => $d, 'views' => $v, 'visitors' => $u];
        $views += $v;
        $visitors += $u;
    }
    return ['series' => $series, 'views' => $views, 'visitors' => $visitors];
}

// ── Messages (relances, envois groupés) ─────────────────────────────────────

function ga_mail(string $to, string $subject, string $body): bool
{
    require_once __DIR__ . '/saas.php';
    return saas_mail($to, $subject, $body, 'galerie');
}

/** Jeton signé d'un lien de désinscription (propre à l'installation, impossible à deviner). */
function ga_token(string $slug, string $kind, string $key): string
{
    gallery_preview_token(['slug' => $slug, 'created' => 0]);   // crée le sel au besoin
    $salt = (string) @file_get_contents(galleries_dir() . '/.salt');
    return substr(hash_hmac('sha256', $slug . '|' . $kind . '|' . $key, $salt), 0, 32);
}

function ga_public_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
}

function ga_optout_url(string $slug, string $kind, string $key): string
{
    return ga_public_url('/galerie/contact.php?a=stop&g=' . rawurlencode($slug) . '&k=' . $kind . '&i=' . rawurlencode($key) . '&t=' . ga_token($slug, $kind, $key));
}

// ── Prospects 1/3 : commerçants en cours d'inscription (demandes faites pour cette galerie) ─────────────────────────────────

/** Demandes d'inscription à relancer : [['req', 'kind' (paid|draft|pending), 'can_remind', 'next_at']]. */
function ga_signups(string $slug): array
{
    require_once __DIR__ . '/saas.php';
    $out = [];
    foreach (saas_requests() as $r) {
        if (strtolower((string) ($r['gallery'] ?? '')) !== $slug) continue;
        $status = (string) ($r['status'] ?? '');
        if (!in_array($status, ['draft', 'awaiting_payment', 'paid', 'pending'], true)) continue;
        $reminders = (array) ($r['reminders'] ?? []);
        $last = $reminders ? max($reminders) : 0;
        $out[] = ['req' => $r, 'kind' => $status === 'awaiting_payment' ? 'draft' : $status, 'reminders' => count($reminders), 'last' => $last,
            'can_remind' => count($reminders) < GA_RELANCE_MAX && time() - $last >= GA_RELANCE_DELAY && !empty($r['email']), 'next_at' => $last + GA_RELANCE_DELAY];
    }
    return $out;
}

/** Relance d'une demande d'inscription. -> message d'erreur ou null. */
function ga_signup_remind(string $slug, string $requestId): ?string
{
    require_once __DIR__ . '/saas.php';
    $gallery = gallery_load($slug);
    $req = saas_request_load($requestId);
    if (!$gallery || !$req || strtolower((string) ($req['gallery'] ?? '')) !== $slug) return 'Demande introuvable pour cette galerie.';
    $row = null;
    foreach (ga_signups($slug) as $s) if ($s['req']['id'] === $requestId) $row = $s;
    if (!$row) return 'Cette inscription n\'est plus en cours.';
    if (!$row['can_remind']) return $row['reminders'] >= GA_RELANCE_MAX ? 'Déjà ' . GA_RELANCE_MAX . ' relances envoyées.' : 'Une relance a déjà été envoyée : patientez 3 jours.';
    $origin = saas_origin();
    $name = $gallery['name'];
    if ($row['kind'] === 'paid') {
        $link = saas_creation_url($req, $origin);
        $body = "Bonjour,\n\nVotre paiement pour rejoindre « $name » est bien reçu, mais votre boutique n'est pas encore créée. Il ne reste qu'à choisir son nom, son design et votre mot de passe :\n\n$link\n\n$name\n";
    } elseif ($row['kind'] === 'pending') {
        $body = "Bonjour,\n\nVotre demande pour rejoindre « $name » est bien enregistrée et en cours d'examen. Si vous avez des précisions à nous donner, répondez simplement à ce message.\n\n$name\n";
    } else {
        $link = $origin . '/inscription/?galerie=' . rawurlencode($slug) . '&email=' . rawurlencode((string) $req['email']);
        $body = "Bonjour,\n\nVous avez commencé votre inscription pour rejoindre « $name » sans la terminer. Vous pouvez la reprendre en un instant :\n\n$link\n\n$name\n";
    }
    if (!ga_mail((string) $req['email'], 'Votre inscription à ' . $name, $body)) return "L'e-mail n'a pas pu partir.";
    $req['reminders'] = array_merge((array) ($req['reminders'] ?? []), [time()]);
    saas_request_save($req);
    return null;
}

// ── Prospects 2/3 : commerces à recruter (liste tenue par l'administrateur) ────────────────────────────────────────────────

const GA_PROSPECT_STATUS = ['nouveau' => 'À contacter', 'contacte' => 'Contacté', 'relance' => 'Relancé', 'inscrit' => 'Inscrit', 'refus' => 'Refus'];

function ga_prospects(string $slug): array
{
    return ga_read($slug, 'prospects', []);
}

function ga_prospect_custom_fields_normalize(array $fields): array
{
    $out = [];
    foreach ($fields as $label => $field) {
        if (is_array($field)) {
            $label = $field['label'] ?? $label;
            $field = $field['value'] ?? '';
        }
        if (!is_scalar($label) || !is_scalar($field)) continue;
        $label = mb_substr(trim((string) $label), 0, 60);
        $value = mb_substr(trim((string) $field), 0, 500);
        if ($label !== '' && $value !== '') $out[$label] = $value;
        if (count($out) >= 30) break;
    }
    return $out;
}

function ga_prospect_add(string $slug, string $name, string $email, string $note, string $phone = '', array $customFields = []): ?string
{
    $email = mb_strtolower(trim($email));
    $name = mb_substr(trim($name), 0, 100);
    if ($name === '') return 'Indiquez le nom du commerce.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Adresse e-mail invalide.';
    $list = ga_prospects($slug);
    if ($email !== '') foreach ($list as $p) if ($p['email'] === $email) return 'Cette adresse est déjà dans la liste.';
    if (count($list) >= 2000) return '2 000 prospects au plus.';
    $list[] = ['id' => bin2hex(random_bytes(6)), 'name' => $name, 'email' => $email, 'phone' => mb_substr(preg_replace('/[^0-9+ ().-]/', '', $phone), 0, 30), 'note' => mb_substr(trim($note), 0, 400),
        'custom_fields' => ga_prospect_custom_fields_normalize($customFields), 'status' => 'nouveau', 'created' => time(), 'contacts' => [], 'optout' => false];
    return ga_write($slug, 'prospects', $list) ? null : "Impossible d'enregistrer la liste.";
}

function ga_prospect_update(string $slug, string $id, array $in): ?string
{
    $list = ga_prospects($slug);
    foreach ($list as $k => $p) {
        if ($p['id'] !== $id) continue;
        if (isset($in['status']) && isset(GA_PROSPECT_STATUS[$in['status']])) $list[$k]['status'] = $in['status'];
        if (isset($in['note'])) $list[$k]['note'] = mb_substr(trim((string) $in['note']), 0, 400);
        if (isset($in['phone']) && empty($p['phone'])) $list[$k]['phone'] = mb_substr(preg_replace('/[^0-9+ ().-]/', '', (string) $in['phone']), 0, 30);   // complète seulement un numéro absent
        if (isset($in['custom_fields']) && is_array($in['custom_fields'])) $list[$k]['custom_fields'] = ga_prospect_custom_fields_normalize($in['custom_fields']);
        if (!empty($in['delete'])) unset($list[$k]);
        return ga_write($slug, 'prospects', array_values($list)) ? null : "Impossible d'enregistrer la liste.";
    }
    return 'Prospect introuvable.';
}

/** Modèle de message de recrutement (variables {nom}, {galerie}, {lien}, {desinscription}). */
function ga_prospect_template(array $gallery): array
{
    return [
        'subject' => 'Rejoignez « ' . $gallery['name'] . ' »',
        'body' => "Bonjour {nom},\n\nJe m'occupe de « {galerie} », une galerie qui réunit plusieurs commerces sur une page commune. Votre boutique y aurait toute sa place.\n\nPour en savoir plus et vous inscrire : {lien}\n\nBien cordialement,\n{galerie}\n\n—\nPour ne plus recevoir de message de notre part : {desinscription}\n",
    ];
}

/** Envoie le message de recrutement. -> message d'erreur ou null. Un prospect n'est pas relancé plus de 3 fois, ni deux fois en 3 jours, ni après désinscription. */
function ga_prospect_send(string $slug, string $id, string $subject, string $body): ?string
{
    $gallery = gallery_load($slug);
    $list = ga_prospects($slug);
    if (!$gallery) return 'Galerie introuvable.';
    $subject = mb_substr(trim($subject), 0, 150);
    $body = trim($body);
    if ($subject === '' || mb_strlen($body) < 20) return 'Rédigez un objet et un message.';
    if (!str_contains($body, '{desinscription}')) return 'Le message doit contenir {desinscription} : le lien pour ne plus être contacté est obligatoire.';
    foreach ($list as $k => $p) {
        if ($p['id'] !== $id) continue;
        if ($p['email'] === '') return 'Ce prospect n\'a pas d\'adresse e-mail.';
        if (!empty($p['optout'])) return 'Ce prospect a demandé à ne plus être contacté.';
        $sent = (array) ($p['contacts'] ?? []);
        if (count($sent) >= GA_RELANCE_MAX + 1) return 'Déjà ' . (GA_RELANCE_MAX + 1) . ' messages envoyés : il n\'est plus relancé.';
        if ($sent && time() - max($sent) < GA_RELANCE_DELAY) return 'Un message a déjà été envoyé il y a moins de 3 jours.';
        $text = strtr($body, ['{nom}' => $p['name'], '{galerie}' => $gallery['name'], '{lien}' => gallery_public_url($gallery), '{desinscription}' => ga_optout_url($slug, 'prospect', $p['id'])]);
        if (!ga_mail($p['email'], $subject, $text)) return "L'e-mail n'a pas pu partir.";
        $list[$k]['contacts'][] = time();
        if ($list[$k]['status'] === 'nouveau') $list[$k]['status'] = 'contacte'; elseif ($list[$k]['status'] === 'contacte') $list[$k]['status'] = 'relance';
        ga_write($slug, 'prospects', $list);
        return null;
    }
    return 'Prospect introuvable.';
}

// ── Prospects 3/3 : visiteurs abonnés à la galerie (double confirmation) ────────────────────────────────────────────────────

function ga_subs(string $slug): array
{
    return ga_read($slug, 'subs', []);
}

/** Inscription d'un visiteur aux nouveautés de la galerie. -> [message, erreur]. */
function ga_sub_subscribe(string $slug, string $email): array
{
    $gallery = gallery_load($slug);
    $email = mb_strtolower(trim($email));
    if (!$gallery || !$gallery['published'] || (function_exists('go_is_live') && !go_is_live($gallery))) return [null, 'Galerie introuvable.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) return [null, 'Adresse e-mail invalide.'];
    if (!ga_login_attempts_ok()) return [null, 'Trop de demandes : réessayez plus tard.'];
    ga_login_attempt_note();
    $subs = ga_subs($slug);
    $msg = 'Presque fini : un e-mail de confirmation vient de vous être envoyé.';
    foreach ($subs as $s) if ($s['email'] === $email) return [$msg, null];   // même réponse : on ne révèle pas ce qui existe
    if (count($subs) >= 5000) return [null, 'Les inscriptions sont momentanément closes.'];
    $id = bin2hex(random_bytes(6));
    $subs[] = ['id' => $id, 'email' => $email, 'confirmed' => 0, 'created' => time(), 'last_sent' => 0];
    ga_write($slug, 'subs', $subs);
    $url = ga_public_url('/galerie/contact.php?a=confirm&g=' . rawurlencode($slug) . '&i=' . $id . '&t=' . ga_token($slug, 'sub', $id));
    ga_mail($email, 'Confirmez votre inscription — ' . $gallery['name'], "Bonjour,\n\nVous avez demandé à recevoir les nouveautés de « {$gallery['name']} ». Pour confirmer, cliquez ici :\n\n$url\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : rien ne vous sera envoyé.\n");
    return [$msg, null];
}

function ga_sub_confirm(string $slug, string $id, string $token): bool
{
    if (!hash_equals(ga_token($slug, 'sub', $id), $token)) return false;
    $subs = ga_subs($slug);
    foreach ($subs as $k => $s) if ($s['id'] === $id) { $subs[$k]['confirmed'] = time(); return ga_write($slug, 'subs', $subs); }
    return false;
}

/** Désinscription : abonné (kind = sub) ou prospect (kind = prospect). */
function ga_stop(string $slug, string $kind, string $id, string $token): bool
{
    if (!in_array($kind, ['sub', 'prospect'], true) || !hash_equals(ga_token($slug, $kind, $id), $token)) return false;
    if ($kind === 'sub') {
        $subs = array_values(array_filter(ga_subs($slug), static fn ($s) => $s['id'] !== $id));
        return ga_write($slug, 'subs', $subs);
    }
    $list = ga_prospects($slug);
    foreach ($list as $k => $p) if ($p['id'] === $id) { $list[$k]['optout'] = true; return ga_write($slug, 'prospects', $list); }
    return false;
}

/** Envoi d'un message à tous les abonnés confirmés (une fois par jour au plus). -> [nombre envoyé, message d'erreur ou null]. */
function ga_sub_broadcast(string $slug, string $subject, string $body): array
{
    $gallery = gallery_load($slug);
    if (!$gallery) return [0, 'Galerie introuvable.'];
    $subject = mb_substr(trim($subject), 0, 150);
    $body = trim($body);
    if ($subject === '' || mb_strlen($body) < 20) return [0, 'Rédigez un objet et un message.'];
    $meta = ga_read($slug, 'subs-meta', ['last_broadcast' => 0]);
    if (time() - (int) $meta['last_broadcast'] < 86400) return [0, 'Un message a déjà été envoyé aujourd\'hui : un par jour au plus.'];
    $subs = ga_subs($slug);
    $sent = 0;
    foreach ($subs as $k => $s) {
        if (empty($s['confirmed'])) continue;
        $text = $body . "\n\n—\n« {$gallery['name']} » : " . gallery_public_url($gallery) . "\nSe désinscrire : " . ga_optout_url($slug, 'sub', $s['id']) . "\n";
        if (ga_mail($s['email'], $subject, $text)) { $sent++; $subs[$k]['last_sent'] = time(); }
        if ($sent >= 500) break;
    }
    if ($sent) { ga_write($slug, 'subs', $subs); ga_write($slug, 'subs-meta', ['last_broadcast' => time()]); }
    return [$sent, $sent ? null : 'Aucun abonné confirmé à qui écrire.'];
}

// ── Activité des commerces membres ──────────────────────────────────────────

/** Commandes payées et chiffre d'affaires d'un commerce de ce serveur depuis $fromDate (AAAA-MM-JJ) : [commandes, centimes] ou null (base illisible). */
function ga_shop_activity(string $shopSlug, string $fromDate): ?array
{
    if (!is_file(tenant_dir($shopSlug) . '/tenant.php')) return null;
    try {
        $file = tenant_file(tenant_load($shopSlug), 'db_file');
        if (!is_file($file)) return null;
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only = 1');
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount_total), 0) FROM orders WHERE status = 'paid' AND date(created_at) >= ?");
        $st->execute([$fromDate]);
        [$n, $sum] = $st->fetch(PDO::FETCH_NUM);
        return [(int) $n, (int) $sum];
    } catch (Throwable $e) {
        return null;
    }
}
