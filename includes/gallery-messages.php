<?php

// Messagerie interne d'une galerie : conversations entre l'organisateur et les exposants (ou toute personne contactée
// depuis le portail). Les fils sont enregistrés dans data/galeries/<identifiant>.messages.json (jamais envoyé par le
// déploiement) : deux sortes de fils, « exposant » (ouvert depuis une demande d'emplacement, rangé avec le jeton de la
// demande) et « contact » (adresse saisie par l'organisateur, ouvert par un lien signé). Aucun compte à créer côté
// visiteur : le lien reçu par e-mail sert de clé, avec un jeton CSRF distinct pour répondre.

require_once __DIR__ . '/galleries.php';
require_once __DIR__ . '/gallery-admin.php';

const GMSG_MAX_BODY = 4000;              // caractères par message
const GMSG_MAX_THREADS = 1000;           // fils par galerie
const GMSG_MAX_MESSAGES = 300;           // messages par fil
const GMSG_MAX_PER_HOUR = 20;            // messages d'un visiteur (adresse IP) par heure

function gmsg_file(string $slug): string
{
    return galleries_dir() . '/' . $slug . '.messages.json';
}

function gmsg_read(string $slug): array
{
    if (!gallery_slug_valid($slug)) return ['threads' => [], 'hits' => []];
    $d = json_decode((string) @file_get_contents(gmsg_file($slug)), true);
    $d = is_array($d) ? $d : [];
    return ['threads' => is_array($d['threads'] ?? null) ? $d['threads'] : [], 'hits' => is_array($d['hits'] ?? null) ? $d['hits'] : []];
}

/** Lit, modifie et réécrit le fichier des conversations sous verrou (deux réponses simultanées ne s'écrasent pas). */
function gmsg_update(string $slug, callable $fn): mixed
{
    if (!gallery_slug_valid($slug)) throw new RuntimeException('Galerie invalide.');
    $dir = galleries_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Dossier des galeries inaccessible.');
    $h = fopen(gmsg_file($slug), 'c+');
    if (!$h) throw new RuntimeException('Fichier des messages inaccessible.');
    flock($h, LOCK_EX);
    $raw = stream_get_contents($h);
    $d = json_decode((string) $raw, true);
    $d = is_array($d) ? $d : [];
    $d = ['threads' => is_array($d['threads'] ?? null) ? $d['threads'] : [], 'hits' => is_array($d['hits'] ?? null) ? $d['hits'] : []];
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

function gmsg_id_valid(string $id): bool
{
    return (bool) preg_match('/^[a-z0-9]{16,40}$/', $id);
}

/** Identifiant stable du fil d'un exposant : lié à sa demande, sans révéler le jeton de celle-ci. */
function gmsg_booking_id(string $slug, string $token): string
{
    return preg_match('/^[a-f0-9]{32}$/', $token) ? 'x' . substr(hash('sha256', 'gmsg-booking|' . $slug . '|' . $token), 0, 23) : '';
}

/** Jeton d'accès au fil pour la personne contactée (lien reçu par e-mail). */
function gmsg_token(string $slug, string $id): string
{
    return ga_token($slug, 'msg', $id);
}

/** Jeton CSRF du formulaire de réponse d'un fil (distinct du jeton d'accès). */
function gmsg_csrf(string $slug, string $id): string
{
    return ga_token($slug, 'msgcsrf', $id);
}

function gmsg_access_ok(string $slug, string $id, string $token): bool
{
    return gmsg_id_valid($id) && $token !== '' && hash_equals(gmsg_token($slug, $id), $token);
}

function gmsg_csrf_ok(string $slug, string $id, string $csrf): bool
{
    return gmsg_id_valid($id) && $csrf !== '' && hash_equals(gmsg_csrf($slug, $id), $csrf);
}

/** Un fil rangé : messages valides, compteurs de non-lus et dernier message. */
function gmsg_thread_normalize(string $id, array $t): array
{
    $messages = [];
    foreach ((array) ($t['messages'] ?? []) as $m) {
        if (!is_array($m)) continue;
        $body = trim((string) ($m['body'] ?? ''));
        if ($body === '') continue;
        $messages[] = [
            'id' => (string) ($m['id'] ?? ''),
            'from' => ($m['from'] ?? '') === 'admin' ? 'admin' : 'guest',
            'body' => mb_substr($body, 0, GMSG_MAX_BODY),
            'at' => (int) ($m['at'] ?? 0),
            'by' => mb_substr(trim((string) ($m['by'] ?? '')), 0, 120),
        ];
    }
    $readAdmin = (int) ($t['read_admin'] ?? 0);
    $readGuest = (int) ($t['read_guest'] ?? 0);
    $unreadAdmin = $unreadGuest = 0;
    foreach ($messages as $m) {
        if ($m['from'] === 'guest' && $m['at'] > $readAdmin) $unreadAdmin++;
        if ($m['from'] === 'admin' && $m['at'] > $readGuest) $unreadGuest++;
    }
    $last = $messages ? $messages[count($messages) - 1] : null;
    return [
        'id' => $id,
        'kind' => ($t['kind'] ?? '') === 'exposant' ? 'exposant' : 'contact',
        'slot_id' => (string) ($t['slot_id'] ?? ''),
        'name' => mb_substr(trim((string) ($t['name'] ?? '')), 0, 120),
        'email' => mb_strtolower(mb_substr(trim((string) ($t['email'] ?? '')), 0, 160)),
        'subject' => mb_substr(trim((string) ($t['subject'] ?? '')), 0, 150),
        'created' => (int) ($t['created'] ?? 0),
        'updated' => max((int) ($t['updated'] ?? 0), (int) ($last['at'] ?? 0)),
        'read_admin' => $readAdmin,
        'read_guest' => $readGuest,
        'messages' => $messages,
        'last' => $last,
        'unread_admin' => $unreadAdmin,
        'unread_guest' => $unreadGuest,
    ];
}

function gmsg_threads(string $slug): array
{
    $out = [];
    foreach (gmsg_read($slug)['threads'] as $id => $t) {
        if (!gmsg_id_valid((string) $id) || !is_array($t)) continue;
        $out[] = gmsg_thread_normalize((string) $id, $t);
    }
    usort($out, static fn (array $a, array $b): int => ($b['updated'] <=> $a['updated']) ?: strcasecmp($a['name'], $b['name']));
    return $out;
}

function gmsg_thread(string $slug, string $id): ?array
{
    if (!gmsg_id_valid($id)) return null;
    $t = gmsg_read($slug)['threads'][$id] ?? null;
    return is_array($t) ? gmsg_thread_normalize($id, $t) : null;
}

/** Fil existant d'un exposant (aucune écriture : l'espace exposant peut rester vide). */
function gmsg_thread_for_booking(string $slug, string $token): ?array
{
    $id = gmsg_booking_id($slug, $token);
    return $id === '' ? null : gmsg_thread($slug, $id);
}

/** Libellé de l'emplacement d'une demande : la demande brute ne stocke pas son libellé, le plan le donne. */
function gmsg_slot_label(array $gallery, array $row): string
{
    $id = (string) ($row['slot_id'] ?? '');
    if ($id !== '' && function_exists('gb_slot')) {
        $slot = gb_slot($gallery, $id);
        if ($slot) return gb_slot_name($gallery, $slot);
    }
    return trim((string) ($row['slot'] ?? ''));
}

/** Ouvre (ou retrouve) le fil d'un exposant, avec son nom, son adresse et son emplacement à jour. */
function gmsg_open_exposant(array $gallery, array $row): ?string
{
    $slug = (string) $gallery['slug'];
    $token = (string) ($row['token'] ?? '');
    $id = gmsg_booking_id($slug, $token);
    if ($id === '') return null;
    $name = trim((string) ($row['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
    $slotLabel = gmsg_slot_label($gallery, $row);
    $subject = $slotLabel !== '' ? 'Emplacement ' . $slotLabel : 'Votre emplacement';
    return gmsg_update($slug, static function (array &$d) use ($id, $row, $name, $email, $subject): ?string {
        if (!isset($d['threads'][$id]) || !is_array($d['threads'][$id])) {
            if (count($d['threads']) >= GMSG_MAX_THREADS) return null;
            $now = time();
            $d['threads'][$id] = [
                'kind' => 'exposant', 'slot_id' => (string) ($row['slot_id'] ?? ''),
                'name' => $name, 'email' => $email, 'subject' => $subject,
                // read_admin à 0 : le premier message envoyé dans la même seconde doit compter comme non lu.
                'created' => $now, 'updated' => $now, 'read_admin' => 0, 'read_guest' => $now, 'messages' => [],
            ];
        } else {
            // Les coordonnées de la demande font foi (l'exposant a pu corriger sa fiche).
            $d['threads'][$id]['name'] = $name;
            $d['threads'][$id]['email'] = $email;
            $d['threads'][$id]['slot_id'] = (string) ($row['slot_id'] ?? '');
            if (trim((string) ($d['threads'][$id]['subject'] ?? '')) === '') $d['threads'][$id]['subject'] = $subject;
        }
        return $id;
    });
}

function gmsg_body_check(string $body): ?string
{
    $body = trim($body);
    if ($body === '') return 'Écrivez un message.';
    if (mb_strlen($body) > GMSG_MAX_BODY) return 'Message trop long : ' . GMSG_MAX_BODY . ' caractères au plus.';
    return null;
}

/** Limitation des envois d'un visiteur : 20 messages par heure et par adresse IP, toutes conversations confondues. */
function gmsg_guest_quota_ok(string $slug): bool
{
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|gmsg');
    $hits = array_values(array_filter((array) (gmsg_read($slug)['hits'][$ip] ?? []), static fn ($t) => (int) $t > time() - 3600));
    return count($hits) < GMSG_MAX_PER_HOUR;
}

function gmsg_guest_quota_note(string $slug): void
{
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|gmsg');
    gmsg_update($slug, static function (array &$d) use ($ip): void {
        $recent = array_values(array_filter((array) ($d['hits'][$ip] ?? []), static fn ($t) => (int) $t > time() - 3600));
        $recent[] = time();
        $d['hits'][$ip] = $recent;
        foreach (array_keys($d['hits']) as $k) {
            if (!array_filter((array) $d['hits'][$k], static fn ($t) => (int) $t > time() - 3600)) unset($d['hits'][$k]);
        }
    });
}

/**
 * Ajoute un message à un fil. -> message d'erreur ou null.
 * $meta : 'by' (nom affiché de l'auteur), 'guest_quota' (vrai pour un envoi venu d'un visiteur).
 */
function gmsg_post(string $slug, string $id, string $from, string $body, array $meta = []): ?string
{
    if (!gmsg_id_valid($id)) return 'Conversation introuvable.';
    if (!in_array($from, ['admin', 'guest'], true)) return 'Auteur inconnu.';
    if (($err = gmsg_body_check($body)) !== null) return $err;
    if ($from === 'guest' && !empty($meta['guest_quota'])) {
        if (!gmsg_guest_quota_ok($slug)) return 'Trop de messages envoyés : réessayez dans une heure.';
        gmsg_guest_quota_note($slug);
    }
    $body = trim($body);
    try {
        return gmsg_update($slug, static function (array &$d) use ($id, $from, $body, $meta): ?string {
            if (!isset($d['threads'][$id]) || !is_array($d['threads'][$id])) return 'Conversation introuvable.';
            $t = &$d['threads'][$id];
            $messages = is_array($t['messages'] ?? null) ? $t['messages'] : [];
            if (count($messages) >= GMSG_MAX_MESSAGES) return 'Conversation complète : ouvrez un nouveau message.';
            $now = time();
            $messages[] = [
                'id' => bin2hex(random_bytes(8)), 'from' => $from, 'body' => $body, 'at' => $now,
                'by' => mb_substr(trim((string) ($meta['by'] ?? '')), 0, 120),
            ];
            $t['messages'] = $messages;
            $t['updated'] = $now;
            if ($from === 'admin') $t['read_admin'] = $now;
            else $t['read_guest'] = $now;
            return null;
        });
    } catch (Throwable $e) {
        return "Le message n'a pas pu être enregistré.";
    }
}

function gmsg_mark_read(string $slug, string $id, string $who): void
{
    if (!gmsg_id_valid($id) || !in_array($who, ['admin', 'guest'], true)) return;
    gmsg_update($slug, static function (array &$d) use ($id, $who): void {
        if (!isset($d['threads'][$id])) return;
        $d['threads'][$id][$who === 'admin' ? 'read_admin' : 'read_guest'] = time();
    });
}

function gmsg_delete(string $slug, string $id): bool
{
    if (!gmsg_id_valid($id)) return false;
    return (bool) gmsg_update($slug, static function (array &$d) use ($id): bool {
        if (!isset($d['threads'][$id])) return false;
        unset($d['threads'][$id]);
        return true;
    });
}

function gmsg_unread_admin(string $slug): int
{
    $n = 0;
    foreach (gmsg_threads($slug) as $t) $n += $t['unread_admin'];
    return $n;
}

/** Adresse du fil côté visiteur : espace exposant pour une demande, page signée pour un contact. */
function gmsg_guest_url(array $gallery, array $thread): string
{
    $slug = (string) $gallery['slug'];
    if ($thread['kind'] === 'exposant' && $thread['slot_id'] !== '') {
        require_once __DIR__ . '/gallery-booking.php';
        $row = gb_row($gallery, $thread['slot_id']);
        $token = (string) ($row['token'] ?? '');
        if ($token !== '') return ga_public_url('/galerie/exposant.php?' . http_build_query(['g' => $slug, 't' => $token]) . '#messages');
    }
    return ga_public_url('/galerie/messages.php?' . http_build_query(['g' => $slug, 'i' => $thread['id'], 't' => gmsg_token($slug, $thread['id'])]));
}

/** Adresse du fil dans le portail (mode restreint : le slug est déjà dans la session). */
function gmsg_admin_url(string $slug, string $id): string
{
    return ga_public_url('/portail/galerie-messages.php?' . http_build_query(['g' => $slug, 'i' => $id]));
}

/** Prévient les administrateurs d'un nouveau message d'exposant ou de contact. Un e-mail par administrateur et par message. */
function gmsg_notify_admins(array $gallery, array $thread, string $body): void
{
    $slug = (string) $gallery['slug'];
    $link = gmsg_admin_url($slug, $thread['id']);
    $excerpt = mb_strimwidth(trim($body), 0, 600, '…');
    $subject = 'Nouveau message — ' . $gallery['name'];
    $text = "Bonjour,\n\n" . ($thread['name'] !== '' ? $thread['name'] : 'Un visiteur') . " vous a écrit"
        . ($thread['subject'] !== '' ? ' au sujet de « ' . $thread['subject'] . ' »' : '') . " :\n\n$excerpt\n\nRépondre : $link\n\n{$gallery['name']}\n";
    foreach ((array) ($gallery['admins'] ?? []) as $admin) {
        $email = mb_strtolower(trim((string) ($admin['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ga_mail($email, $subject, $text);
    }
}

/** Prévient la personne contactée qu'un message l'attend (lien signé vers la conversation). */
function gmsg_notify_guest(array $gallery, array $thread, string $body): void
{
    $email = (string) $thread['email'];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $link = gmsg_guest_url($gallery, $thread);
    $excerpt = mb_strimwidth(trim($body), 0, 600, '…');
    $subject = 'Message de ' . $gallery['name'];
    $text = "Bonjour" . ($thread['name'] !== '' ? ' ' . $thread['name'] : '') . ",\n\n"
        . "L'organisateur de « {$gallery['name']} » vous a écrit"
        . ($thread['subject'] !== '' ? ' au sujet de « ' . $thread['subject'] . ' »' : '') . " :\n\n$excerpt\n\n"
        . "Pour lire la conversation et répondre : $link\n\n"
        . "Ce lien personnel n'a pas besoin de mot de passe : ne le transférez pas.\n\n{$gallery['name']}\n";
    ga_mail($email, $subject, $text);
}

/**
 * Ouvre une conversation de contact (adresse saisie par l'organisateur) et y place le premier message.
 * -> [identifiant du fil ou null, message d'erreur ou null].
 */
function gmsg_contact_open(array $gallery, string $name, string $email, string $subject, string $body, string $actor = ''): array
{
    $slug = (string) $gallery['slug'];
    $name = mb_substr(trim($name), 0, 120);
    $email = mb_strtolower(mb_substr(trim($email), 0, 160));
    $subject = mb_substr(trim($subject), 0, 150);
    if ($name === '') return [null, 'Indiquez le nom de votre interlocuteur.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Adresse e-mail invalide.'];
    if ($subject === '') $subject = 'Message de ' . $gallery['name'];
    if (($err = gmsg_body_check($body)) !== null) return [null, $err];
    $id = bin2hex(random_bytes(12));
    try {
        $created = gmsg_update($slug, static function (array &$d) use ($id, $name, $email, $subject, $body, $actor): bool {
            if (count($d['threads']) >= GMSG_MAX_THREADS) return false;
            $now = time();
            $d['threads'][$id] = [
                'kind' => 'contact', 'slot_id' => '', 'name' => $name, 'email' => $email, 'subject' => $subject,
                'created' => $now, 'updated' => $now, 'read_admin' => $now, 'read_guest' => 0,
                'messages' => [['id' => bin2hex(random_bytes(8)), 'from' => 'admin', 'body' => trim($body), 'at' => $now, 'by' => mb_substr(trim($actor), 0, 120)]],
            ];
            return true;
        });
    } catch (Throwable $e) {
        return [null, "La conversation n'a pas pu être enregistrée."];
    }
    if (!$created) return [null, 'Trop de conversations : supprimez les anciennes avant d\'en ouvrir une nouvelle.'];
    return [$id, null];
}
