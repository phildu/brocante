<?php

// Espace visiteur et fidélité DE LA GALERIE : indépendant des boutiques et du plugin « Espace visiteur » (qui reste le programme propre à chaque
// boutique). Les visiteurs gagnent des points en scannant le QR code d'un lieu (commerce, stand, entrée…) et les échangent contre des récompenses
// que l'organisateur remet. Données dans data/galeries/<id>.fidelite.json (jamais déployé) :
// ['settings' => [...], 'members' => [<e-mail> => membre], 'rewards' => [récompense…], 'hits' => [<empreinte IP> => [horodatages]]].

require_once __DIR__ . '/gallery-admin.php';
require_once __DIR__ . '/gallery-types.php';
require_once __DIR__ . '/gallery-offers.php';

const GL_MAX_TIERS = 5;
const GL_MAX_STANDS = 60;
const GL_MAX_PER_IP_HOUR = 8;
const GL_MAX_SCANS_PER_DAY = 25;

function gl_file(string $slug): string
{
    return galleries_dir() . '/' . $slug . '.fidelite.json';
}

function gl_blank(): array
{
    return ['settings' => [], 'members' => [], 'rewards' => [], 'hits' => []];
}

function gl_read(string $slug): array
{
    $d = json_decode((string) @file_get_contents(gl_file($slug)), true);
    return is_array($d) ? array_replace(gl_blank(), array_intersect_key($d, gl_blank())) : gl_blank();
}

/** Lit, modifie et réécrit le fichier sous verrou. $fn reçoit les données par référence. */
function gl_update(string $slug, callable $fn): mixed
{
    $dir = galleries_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Dossier des galeries inaccessible.');
    $h = fopen(gl_file($slug), 'c+');
    if (!$h) throw new RuntimeException('Fichier de fidélité inaccessible.');
    flock($h, LOCK_EX);
    $d = json_decode((string) stream_get_contents($h), true);
    $d = is_array($d) ? array_replace(gl_blank(), array_intersect_key($d, gl_blank())) : gl_blank();
    try {
        $r = $fn($d);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($h);
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
    return $r;
}

/** Réglages nettoyés (valeurs par défaut comprises). Les lieux de scan : [['id' => 6 caractères, 'name' => …]]. */
function gl_settings_normalize(array $in, array $old = []): array
{
    $tiers = [];
    foreach (array_slice((array) ($in['tiers'] ?? []), 0, GL_MAX_TIERS) as $t) {
        $pts = (int) ($t['pts'] ?? 0);
        $label = mb_substr(trim((string) ($t['label'] ?? '')), 0, 60);
        if ($pts >= 1 && $pts <= 100000 && $label !== '') $tiers[] = ['pts' => $pts, 'label' => $label];
    }
    usort($tiers, static fn ($a, $b) => $a['pts'] <=> $b['pts']);
    $stands = [];
    $known = [];
    foreach ((array) ($old['stands'] ?? []) as $s) $known[(string) ($s['name'] ?? '')] = (string) ($s['id'] ?? '');
    foreach (array_slice((array) ($in['stands'] ?? []), 0, GL_MAX_STANDS) as $name) {
        $name = mb_substr(trim(is_array($name) ? (string) ($name['name'] ?? '') : (string) $name), 0, 60);
        if ($name === '' || isset($stands[$name])) continue;
        $stands[$name] = ['id' => $known[$name] ?? substr(bin2hex(random_bytes(4)), 0, 6), 'name' => $name];
    }
    return [
        'enabled' => !empty($in['enabled']),
        'program_name' => mb_substr(trim((string) ($in['program_name'] ?? '')), 0, 40) ?: 'Fidélité',
        'welcome' => max(0, min(1000, (int) ($in['welcome'] ?? 0))),
        'per_scan' => max(1, min(100, (int) ($in['per_scan'] ?? 10))),
        'intro' => mb_substr(trim((string) ($in['intro'] ?? '')), 0, 300),
        'tiers' => $tiers,
        'stands' => array_values($stands),
    ];
}

function gl_settings(array $gallery): array
{
    $s = gl_read($gallery['slug'])['settings'];
    return $s ? gl_settings_normalize($s, $s) : gl_settings_normalize([]);
}

/** L'espace visiteur de la galerie est-il ouvert au public ? */
function gl_open(array $gallery): bool
{
    return gt_feature($gallery, 'fidelite') && go_is_live($gallery) && !empty(gl_settings($gallery)['enabled']);
}

/** Code secret d'un lieu de scan (dans son QR code) : impossible à deviner, propre à l'installation. */
function gl_stand_code(string $slug, string $standId): string
{
    return substr(ga_token($slug, 'stand', $standId), 0, 16);
}

function gl_scan_url(string $slug, array $stand): string
{
    return ga_public_url('/galerie/visiteur.php?g=' . rawurlencode($slug) . '&s=' . rawurlencode($stand['id'] . '.' . gl_stand_code($slug, $stand['id'])));
}

function gl_app_url(string $slug, string $token = ''): string
{
    return ga_public_url('/galerie/visiteur.php?g=' . rawurlencode($slug) . ($token !== '' ? '&t=' . $token : ''));
}

function gl_member_by_token(array $d, string $token): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    foreach ($d['members'] as $email => $m) if (hash_equals((string) ($m['token'] ?? ''), $token)) return (string) $email;
    return null;
}

/** Vue d'un membre pour son application (sans le jeton). */
function gl_member_view(array $gallery, array $m): array
{
    $s = gl_settings($gallery);
    $rewards = array_values(array_filter(gl_read($gallery['slug'])['rewards'], static fn ($r) => ($r['email'] ?? '') === ($m['email'] ?? '')));
    usort($rewards, static fn ($a, $b) => $b['created'] <=> $a['created']);
    $next = null;
    foreach ($s['tiers'] as $t) if ($t['pts'] > $m['points']) { $next = $t; break; }
    return ['email' => $m['email'], 'name' => $m['name'], 'points' => (int) $m['points'], 'earned' => (int) $m['earned'], 'tiers' => $s['tiers'], 'next' => $next,
        'rewards' => array_map(static fn ($r) => ['id' => $r['id'], 'label' => $r['label'], 'code' => $r['code'], 'status' => $r['status'], 'created' => $r['created']], $rewards),
        'stands' => array_map(static fn ($st) => ['name' => $st['name'], 'done' => ($m['scans'][$st['id']] ?? '') === date('Y-m-d')], $s['stands'])];
}

/** Inscription / connexion par e-mail : envoie le lien personnel. -> erreur ou null (réponse volontairement identique pour un compte nouveau ou existant). */
function gl_login(array $gallery, array $in, string $ip): ?string
{
    $s = gl_settings($gallery);
    if (!gl_open($gallery)) return "L'espace visiteur n'est pas ouvert.";
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) return 'Adresse e-mail invalide.';
    if (empty($in['consent'])) return "Cochez la case : c'est votre accord à l'enregistrement de votre e-mail pour le programme.";
    $hash = hash('sha256', $ip . '|gl|' . $gallery['slug']);
    $res = gl_update($gallery['slug'], static function (array &$d) use ($email, $name, $hash, $s) {
        $now = time();
        foreach ($d['hits'] as $h => $times) {
            $d['hits'][$h] = array_values(array_filter((array) $times, static fn ($t) => $t > $now - 3600));
            if (!$d['hits'][$h]) unset($d['hits'][$h]);
        }
        if (count($d['hits'][$hash] ?? []) >= GL_MAX_PER_IP_HOUR) return 'rate';
        $d['hits'][$hash][] = $now;
        if (!isset($d['members'][$email])) {
            if (count($d['members']) >= 20000) return 'full';
            $d['members'][$email] = ['email' => $email, 'name' => $name, 'token' => bin2hex(random_bytes(16)), 'points' => $s['welcome'], 'earned' => $s['welcome'], 'created' => $now, 'scans' => [], 'day' => ['d' => '', 'n' => 0]];
        } elseif ($name !== '' && ($d['members'][$email]['name'] ?? '') === '') $d['members'][$email]['name'] = $name;
        return $d['members'][$email]['token'];
    });
    if ($res === 'rate') return 'Trop de demandes depuis votre connexion : réessayez dans une heure.';
    if ($res === 'full') return "Le programme n'accepte plus de nouveaux membres.";
    ga_mail($email, $s['program_name'] . ' — ' . $gallery['name'], "Bonjour" . ($name !== '' ? ' ' . $name : '') . ",\n\nVoici votre lien personnel pour ouvrir votre espace « {$s['program_name']} » de {$gallery['name']} :\n"
        . gl_app_url($gallery['slug'], $res) . "\n\nGardez-le : il vous reconnaît sans mot de passe (ne le partagez pas). Scannez les QR codes des lieux participants pour gagner des points.\n\n{$gallery['name']}\n");
    return null;
}

/** Inscription ou connexion par un réseau social (e-mail vérifié par le fournisseur) : crée le membre au besoin. -> jeton du membre, ou null si le programme est fermé. */
function gl_social_login(array $gallery, string $email, string $name): ?string
{
    if (!gl_open($gallery)) return null;
    $s = gl_settings($gallery);
    $email = mb_strtolower(trim($email));
    $name = mb_substr(trim($name), 0, 60);
    return gl_update($gallery['slug'], static function (array &$d) use ($email, $name, $s) {
        if (!isset($d['members'][$email])) {
            if (count($d['members']) >= 20000) return null;
            $d['members'][$email] = ['email' => $email, 'name' => $name, 'token' => bin2hex(random_bytes(16)), 'points' => $s['welcome'], 'earned' => $s['welcome'], 'created' => time(), 'scans' => [], 'day' => ['d' => '', 'n' => 0]];
        } elseif ($name !== '' && ($d['members'][$email]['name'] ?? '') === '') $d['members'][$email]['name'] = $name;
        return $d['members'][$email]['token'];
    });
}

/** Points gagnés en scannant un lieu (une fois par lieu et par jour). -> [vue du membre, message, erreur]. */
function gl_scan(array $gallery, string $token, string $scan): array
{
    if (!gl_open($gallery)) return [null, null, "L'espace visiteur n'est pas ouvert."];
    $s = gl_settings($gallery);
    [$id, $code] = array_pad(explode('.', $scan, 2), 2, '');
    $stand = null;
    foreach ($s['stands'] as $st) if ($st['id'] === $id) $stand = $st;
    if (!$stand || !hash_equals(gl_stand_code($gallery['slug'], $stand['id']), $code)) return [null, null, "Ce QR code n'est pas valide."];
    $out = gl_update($gallery['slug'], static function (array &$d) use ($token, $stand, $s) {
        $email = gl_member_by_token($d, $token);
        if ($email === null) return ['auth'];
        $m = &$d['members'][$email];
        $today = date('Y-m-d');
        if (($m['day']['d'] ?? '') !== $today) $m['day'] = ['d' => $today, 'n' => 0];
        if (($m['scans'][$stand['id']] ?? '') === $today) return ['dup', $m];
        if ($m['day']['n'] >= GL_MAX_SCANS_PER_DAY) return ['many'];
        $m['scans'][$stand['id']] = $today;
        $m['day']['n']++;
        $m['points'] += $s['per_scan'];
        $m['earned'] += $s['per_scan'];
        return ['ok', $m];
    });
    return match ($out[0]) {
        'auth' => [null, null, 'Connectez-vous avec votre lien personnel.'],
        'many' => [null, null, "Beaucoup de scans aujourd'hui : revenez demain."],
        'dup' => [gl_member_view($gallery, $out[1]), "Vous avez déjà scanné « {$stand['name']} » aujourd'hui : revenez demain.", null],
        default => [gl_member_view($gallery, $out[1]), "+{$s['per_scan']} points : « {$stand['name']} » !", null],
    };
}

/** Échange de points contre la récompense d'un palier. -> [vue, message, erreur]. */
function gl_redeem(array $gallery, string $token, int $tierIndex): array
{
    $s = gl_settings($gallery);
    $tier = $s['tiers'][$tierIndex] ?? null;
    if (!gl_open($gallery) || !$tier) return [null, null, 'Récompense introuvable.'];
    $out = gl_update($gallery['slug'], static function (array &$d) use ($token, $tier) {
        $email = gl_member_by_token($d, $token);
        if ($email === null) return ['auth'];
        $m = &$d['members'][$email];
        if ($m['points'] < $tier['pts']) return ['short'];
        $m['points'] -= $tier['pts'];
        $r = ['id' => substr(bin2hex(random_bytes(4)), 0, 8), 'email' => $email, 'label' => $tier['label'], 'pts' => $tier['pts'], 'code' => strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'status' => 'pending', 'created' => time(), 'used' => 0];
        $d['rewards'][] = $r;
        return ['ok', $m, $r];
    });
    return match ($out[0]) {
        'auth' => [null, null, 'Connectez-vous avec votre lien personnel.'],
        'short' => [null, null, "Il vous manque des points pour cette récompense."],
        default => [gl_member_view($gallery, $out[1]), "Récompense obtenue : « {$out[2]['label']} ». Présentez le code {$out[2]['code']} à l'organisateur.", null],
    };
}

/** Effacement des données d'un membre (à sa demande). */
function gl_forget(array $gallery, string $token): bool
{
    return (bool) gl_update($gallery['slug'], static function (array &$d) use ($token) {
        $email = gl_member_by_token($d, $token);
        if ($email === null) return false;
        unset($d['members'][$email]);
        $d['rewards'] = array_values(array_filter($d['rewards'], static fn ($r) => ($r['email'] ?? '') !== $email));
        return true;
    });
}

/** Actions de l'organisateur : adjust (points ± pour un e-mail), remove (membre), used / cancel (récompense remise / annulée et points rendus). -> erreur ou null. */
function gl_admin_action(array $gallery, string $action, array $in): ?string
{
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    $rid = (string) ($in['reward'] ?? '');
    return gl_update($gallery['slug'], static function (array &$d) use ($action, $email, $rid, $in) {
        if ($action === 'adjust') {
            if (!isset($d['members'][$email])) return 'Membre introuvable.';
            $delta = max(-100000, min(100000, (int) ($in['delta'] ?? 0)));
            $d['members'][$email]['points'] = max(0, $d['members'][$email]['points'] + $delta);
            if ($delta > 0) $d['members'][$email]['earned'] += $delta;
            return null;
        }
        if ($action === 'remove') {
            if (!isset($d['members'][$email])) return 'Membre introuvable.';
            unset($d['members'][$email]);
            $d['rewards'] = array_values(array_filter($d['rewards'], static fn ($r) => ($r['email'] ?? '') !== $email));
            return null;
        }
        foreach ($d['rewards'] as $i => $r) {
            if (($r['id'] ?? '') !== $rid || ($r['status'] ?? '') !== 'pending') continue;
            if ($action === 'used') { $d['rewards'][$i]['status'] = 'used'; $d['rewards'][$i]['used'] = time(); return null; }
            if ($action === 'cancel') {
                if (isset($d['members'][$r['email']])) $d['members'][$r['email']]['points'] += (int) $r['pts'];
                array_splice($d['rewards'], $i, 1);
                return null;
            }
        }
        return 'Récompense introuvable ou déjà traitée.';
    });
}
