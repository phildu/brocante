<?php
// Rendez-vous : consultation de disponibilités type Doodle / Framadate.
// Le commerçant propose des dates et des horaires, partage un lien public ; chaque visiteur indique ses disponibilités
// (oui / peut-être / non), puis le commerçant confirme le créneau retenu et prévient les participants par e-mail.
// Données : base de la boutique (tables rdv_*), page publique /rendez-vous.php, écran d'administration pages/index.php.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/share.php'; // shop_abs_url()

const RDV_STATUSES = ['open' => 'Ouverte', 'closed' => 'Close', 'confirmed' => 'Confirmée', 'cancelled' => 'Annulée'];
const RDV_CHOICES = [2 => 'Oui', 1 => 'Peut-être', 0 => 'Non'];
const RDV_MAX_SLOTS = 80;
const RDV_MAX_ANSWERS = 500;
const RDV_MAX_MAILS = 200;

// ── Dates, horaires et affichage ───────────────────────────────────────────

function rdv_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Europe/Paris');
}

function rdv_today(): string
{
    return (new DateTimeImmutable('now', rdv_tz()))->format('Y-m-d');
}

function rdv_status_label(string $status): string
{
    return RDV_STATUSES[$status] ?? $status;
}

/** Date « 2026-11-10 » ou « 10/11/2026 » → « 2026-11-10 », ou null. */
function rdv_parse_date(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
    } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
        $y = (int) $m[3]; $mo = (int) $m[2]; $d = (int) $m[1];
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** Heure « 9 », « 9:00 », « 09h30 » → « 09:00 » / « 09:30 », ou null. */
function rdv_parse_time(string $value): ?string
{
    if (!preg_match('/^(\d{1,2})\s*(?:[:hH]\s*(\d{1,2})|[hH])?$/', trim($value), $m)) return null;
    $h = (int) $m[1];
    $min = (int) ($m[2] ?? 0);
    if ($h > 23 || $min > 59) return null;
    return sprintf('%02d:%02d', $h, $min);
}

/**
 * Lit la liste de créneaux saisie dans l'administration : une ligne par créneau.
 * Formats acceptés : « 2026-11-10 », « 10/11/2026 », « 2026-11-10 09:00-12:00 », « 10/11/2026 9h-12h »
 * et un libellé facultatif après « | » (« 2026-11-10 | Matin »).
 * -> ['slots' => [...], 'errors' => [...]] ; chaque créneau : slot_date, start_time, end_time, label.
 */
function rdv_parse_slots(string $text): array
{
    $slots = [];
    $errors = [];
    $seen = [];
    $lines = preg_split('/\R/u', $text) ?: [];
    foreach ($lines as $index => $line) {
        $line = trim(preg_replace('/^\s*[-•*]\s*/u', '', $line));
        if ($line === '') continue;
        $number = $index + 1;
        [$main, $label] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        $label = mb_substr($label, 0, 80);
        $date = null;
        $rest = '';
        if (preg_match('/^(\d{4}-\d{1,2}-\d{1,2})(?:[ T]+(.*))?$/u', $main, $m)) {
            $date = rdv_parse_date($m[1]);
            $rest = trim((string) ($m[2] ?? ''));
        } elseif (preg_match('#^(\d{1,2}/\d{1,2}/\d{4})(?:\s+(.*))?$#u', $main, $m)) {
            $date = rdv_parse_date($m[1]);
            $rest = trim((string) ($m[2] ?? ''));
        }
        if ($date === null) {
            $errors[] = 'Ligne ' . $number . ' : date illisible (« ' . mb_substr($main, 0, 60) . ' »).';
            continue;
        }
        $start = '';
        $end = '';
        if ($rest !== '') {
            $rest = str_replace(['–', '—', 'à', "\u{00a0}"], ['-', '-', '-', ' '], $rest);
            $rest = trim((string) preg_replace('/\s+/u', ' ', $rest));
            $timeRe = '\d{1,2}(?:\s*[:hH]\s*\d{1,2}|\s*[hH])?';
            // « 09:00 12:00 » : un espace entre deux heures vaut un tiret.
            $rest = (string) preg_replace('/^(' . $timeRe . ')\s+(' . $timeRe . ')$/u', '$1-$2', $rest);
            if (!preg_match('/^(' . $timeRe . ')\s*(?:-\s*(' . $timeRe . '))?$/u', $rest, $m)) {
                $errors[] = 'Ligne ' . $number . ' : horaires illisibles (« ' . mb_substr($rest, 0, 40) . ' »).';
                continue;
            }
            $start = rdv_parse_time($m[1]) ?? '';
            $end = isset($m[2]) && $m[2] !== '' ? (rdv_parse_time($m[2]) ?? '') : '';
            if ($start === '' || (isset($m[2]) && $m[2] !== '' && $end === '')) {
                $errors[] = 'Ligne ' . $number . ' : heure invalide.';
                continue;
            }
            if ($end !== '' && $end <= $start) {
                $errors[] = 'Ligne ' . $number . ' : la fin doit suivre le début.';
                continue;
            }
        }
        $key = $date . '|' . $start . '|' . $end;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $slots[] = ['slot_date' => $date, 'start_time' => $start, 'end_time' => $end, 'label' => $label];
    }
    usort($slots, static function (array $a, array $b): int {
        return [$a['slot_date'], $a['start_time']] <=> [$b['slot_date'], $b['start_time']];
    });
    return ['slots' => $slots, 'errors' => $errors];
}

function rdv_fr_date(string $date, bool $short = false): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, rdv_tz());
    if (!$d) return $date;
    $days = $short
        ? ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.']
        : ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = $short
        ? ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
        : ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $days[(int) $d->format('w')] . ' ' . (int) $d->format('j') . ' ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
}

function rdv_time_label(string $time): string
{
    if ($time === '') return '';
    [$h, $m] = array_map('intval', explode(':', $time));
    return $h . ' h' . ($m > 0 ? ' ' . sprintf('%02d', $m) : '');
}

/** Libellé d'un créneau : « mardi 10 novembre 2026 · 9 h – 12 h » (+ son libellé libre). */
function rdv_slot_label(array $slot, bool $short = false): string
{
    $text = rdv_fr_date((string) $slot['slot_date'], $short);
    if ((string) ($slot['start_time'] ?? '') !== '') {
        $text .= ' · ' . rdv_time_label((string) $slot['start_time']);
        if ((string) ($slot['end_time'] ?? '') !== '') $text .= ' – ' . rdv_time_label((string) $slot['end_time']);
    }
    if ((string) ($slot['label'] ?? '') !== '') $text = $slot['label'] . ' · ' . $text;
    return $text;
}

/** « mardi 10 novembre 2026 » ou « du mardi 10 au jeudi 12 novembre 2026 ». */
function rdv_poll_range(array $slots, bool $short = false): string
{
    if (!$slots) return '';
    $first = (string) $slots[0]['slot_date'];
    $last = (string) $slots[count($slots) - 1]['slot_date'];
    if ($first === $last) return rdv_fr_date($first, $short);
    return 'du ' . rdv_fr_date($first, $short) . ' au ' . rdv_fr_date($last, $short);
}

// ── CSRF, limitation et adresses ───────────────────────────────────────────

function rdv_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) require_once dirname(__DIR__, 3) . '/includes/session.php';
    if (empty($_SESSION['rdv_csrf'])) $_SESSION['rdv_csrf'] = bin2hex(random_bytes(16));
    return (string) $_SESSION['rdv_csrf'];
}

function rdv_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(rdv_csrf_token()) . '">';
}

function rdv_csrf_valid(): bool
{
    return hash_equals(rdv_csrf_token(), (string) ($_POST['csrf'] ?? ''));
}

function rdv_ip_hash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return hash('sha256', $ip . '|rdv|' . (function_exists('tenant_slug') ? tenant_slug() : ''));
}

/** Au plus 15 réponses par heure et par connexion (toutes consultations confondues). */
function rdv_rate_ok(): bool
{
    $now = time();
    $_SESSION['rdv_sent'] = array_values(array_filter((array) ($_SESSION['rdv_sent'] ?? []), static fn ($t) => $now - (int) $t < 3600));
    if (count($_SESSION['rdv_sent']) >= 15) return false;
    try {
        db()->prepare('DELETE FROM rdv_hits WHERE at < ?')->execute([$now - 7200]);
        $st = db()->prepare('SELECT COUNT(*) FROM rdv_hits WHERE ip_hash = ? AND at > ?');
        $st->execute([rdv_ip_hash(), $now - 3600]);
        return (int) $st->fetchColumn() < 15;
    } catch (Throwable $e) {
        return true;
    }
}

function rdv_rate_note(): void
{
    $_SESSION['rdv_sent'][] = time();
    try {
        db()->prepare('INSERT INTO rdv_hits (ip_hash, at) VALUES (?, ?)')->execute([rdv_ip_hash(), time()]);
    } catch (Throwable $e) {
    }
}

function rdv_public_path(string $token): string
{
    return '/rendez-vous.php?p=' . rawurlencode($token);
}

function rdv_public_url(string $token): string
{
    return shop_abs_url(rdv_public_path($token));
}

function rdv_admin_url(int $pollId, string $page = 'gerer'): string
{
    return '/admin/plugin.php?' . http_build_query(['p' => 'rendez-vous', 'v' => $page, 'id' => $pollId]);
}

function rdv_admin_absolute_url(int $pollId): string
{
    return shop_abs_url(rdv_admin_url($pollId));
}

function rdv_edit_path(string $pollToken, string $answerToken): string
{
    return rdv_public_path($pollToken) . '&r=' . rawurlencode($answerToken);
}

function rdv_shop_name(): string
{
    try {
        if (function_exists('get_content')) {
            $name = trim((string) (get_content()['site_name'] ?? ''));
            if ($name !== '') return $name;
        }
    } catch (Throwable $e) {
    }
    return function_exists('tenant') ? (string) tenant('name', 'La boutique') : 'La boutique';
}

// ── Lecture ────────────────────────────────────────────────────────────────

function rdv_polls(int $limit = 100): array
{
    $st = db()->prepare('SELECT * FROM rdv_polls ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function rdv_poll(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM rdv_polls WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rdv_poll_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $st = db()->prepare('SELECT * FROM rdv_polls WHERE token = ?');
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rdv_slots(int $pollId): array
{
    $st = db()->prepare('SELECT * FROM rdv_slots WHERE poll_id = ? ORDER BY slot_date, start_time, position, id');
    $st->execute([$pollId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function rdv_slot(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM rdv_slots WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rdv_answers(int $pollId): array
{
    $st = db()->prepare('SELECT * FROM rdv_answers WHERE poll_id = ? ORDER BY created_at, id');
    $st->execute([$pollId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function rdv_answer(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM rdv_answers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rdv_answer_by_token(int $pollId, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $st = db()->prepare('SELECT * FROM rdv_answers WHERE poll_id = ? AND token = ?');
    $st->execute([$pollId, $token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rdv_answer_by_email(int $pollId, string $email): ?array
{
    $st = db()->prepare('SELECT * FROM rdv_answers WHERE poll_id = ? AND email = ? COLLATE NOCASE');
    $st->execute([$pollId, mb_strtolower(trim($email))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Disponibilités de chaque réponse : [answer_id => [slot_id => 0/1/2]]. */
function rdv_choices_map(int $pollId): array
{
    $st = db()->prepare('SELECT c.answer_id, c.slot_id, c.value FROM rdv_choices c JOIN rdv_answers a ON a.id = c.answer_id WHERE a.poll_id = ?');
    $st->execute([$pollId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['answer_id']][(int) $row['slot_id']] = (int) $row['value'];
    return $out;
}

/** Comptes par créneau : [slot_id => ['yes' => n, 'maybe' => n, 'no' => n]]. */
function rdv_counts(int $pollId): array
{
    $out = [];
    foreach (rdv_slots($pollId) as $slot) $out[(int) $slot['id']] = ['yes' => 0, 'maybe' => 0, 'no' => 0];
    $st = db()->prepare('SELECT c.slot_id, c.value, COUNT(*) AS n FROM rdv_choices c JOIN rdv_answers a ON a.id = c.answer_id WHERE a.poll_id = ? GROUP BY c.slot_id, c.value');
    $st->execute([$pollId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($out[(int) $row['slot_id']])) continue;
        $key = match ((int) $row['value']) { 2 => 'yes', 1 => 'maybe', default => 'no' };
        $out[(int) $row['slot_id']][$key] = (int) $row['n'];
    }
    return $out;
}

/** Meilleur créneau (le plus de « oui », puis de « peut-être »), sinon null. */
function rdv_best_slot(array $slots, array $counts): ?int
{
    $best = null;
    $bestScore = -1;
    foreach ($slots as $slot) {
        $c = $counts[(int) $slot['id']] ?? ['yes' => 0, 'maybe' => 0];
        $score = $c['yes'] * 2 + $c['maybe'];
        if ($score <= 0) continue;
        if ($score > $bestScore) {
            $best = (int) $slot['id'];
            $bestScore = $score;
        }
    }
    return $best;
}

function rdv_poll_open(array $poll): bool
{
    if (($poll['status'] ?? '') !== 'open') return false;
    $closes = (string) ($poll['closes_on'] ?? '');
    return $closes === '' || rdv_today() <= $closes;
}

function rdv_poll_is_past(array $poll, array $slots): bool
{
    if (!$slots) return false;
    return (string) end($slots)['slot_date'] < rdv_today();
}

/** Consultations visibles publiquement : ouvertes (délai non passé) et confirmées à venir. */
function rdv_public_polls(): array
{
    $out = [];
    foreach (rdv_polls(200) as $poll) {
        $slots = rdv_slots((int) $poll['id']);
        $status = (string) $poll['status'];
        if ($status === 'open' && rdv_poll_open($poll)) {
            $poll['_slots'] = $slots;
            $out[] = $poll;
        } elseif ($status === 'confirmed' && !rdv_poll_is_past($poll, $slots)) {
            $poll['_slots'] = $slots;
            $out[] = $poll;
        }
    }
    usort($out, static function (array $a, array $b): int {
        $da = (string) ($a['_slots'][0]['slot_date'] ?? '9999-12-31');
        $db = (string) ($b['_slots'][0]['slot_date'] ?? '9999-12-31');
        return [$da, (int) $a['id']] <=> [$db, (int) $b['id']];
    });
    return $out;
}

// ── Validation et écriture ─────────────────────────────────────────────────

function rdv_parse_emails(mixed $value): array
{
    $out = [];
    foreach (preg_split('/[,;\s]+/', (string) $value) ?: [] as $email) {
        $email = mb_strtolower(trim($email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $out, true)) $out[] = $email;
    }
    return array_slice($out, 0, 5);
}

/** Champs communs d'une consultation (sans les créneaux). -> [données, erreurs]. */
function rdv_poll_input(array $in): array
{
    $errors = [];
    $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 140);
    if (mb_strlen($title) < 3) $errors[] = 'Donnez un titre à la consultation (3 caractères au moins).';
    $description = mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000);
    $place = mb_substr(trim((string) ($in['place'] ?? '')), 0, 160);
    $notifyRaw = trim((string) ($in['notify_email'] ?? ''));
    $notify = rdv_parse_emails($notifyRaw);
    if ($notifyRaw !== '' && !$notify) $errors[] = 'Indiquez au moins une adresse e-mail valide pour les notifications.';
    $closes = trim((string) ($in['closes_on'] ?? ''));
    if ($closes !== '') {
        $parsed = rdv_parse_date($closes);
        if ($parsed === null) $errors[] = 'La date limite de réponse est invalide.';
        else $closes = $parsed;
    }
    return [[
        'title' => $title,
        'description' => $description,
        'place' => $place,
        'notify_email' => implode(', ', $notify),
        'closes_on' => $closes,
        'results_public' => !empty($in['results_public']) ? 1 : 0,
        'names_public' => !empty($in['names_public']) ? 1 : 0,
        'allow_maybe' => !empty($in['allow_maybe']) ? 1 : 0,
    ], $errors];
}

/** Crée une consultation et ses créneaux. -> [identifiant|null, erreurs, consultation|null]. */
function rdv_create_poll(array $in): array
{
    [$data, $errors] = rdv_poll_input($in);
    $parsed = rdv_parse_slots((string) ($in['slots'] ?? ''));
    $errors = array_merge($errors, $parsed['errors']);
    if (!$parsed['slots']) $errors[] = 'Ajoutez au moins un créneau (une date par ligne).';
    if (count($parsed['slots']) > RDV_MAX_SLOTS) $errors[] = 'Une consultation ne peut pas dépasser ' . RDV_MAX_SLOTS . ' créneaux.';
    if ($errors) return [null, $errors, null];

    $token = bin2hex(random_bytes(16));
    db()->prepare('INSERT INTO rdv_polls (token, title, description, place, notify_email, results_public, names_public, allow_maybe, closes_on)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $token, $data['title'], $data['description'], $data['place'], $data['notify_email'],
        $data['results_public'], $data['names_public'], $data['allow_maybe'], $data['closes_on'],
    ]);
    $id = (int) db()->lastInsertId();
    rdv_insert_slots($id, $parsed['slots']);
    return [$id, [], rdv_poll($id)];
}

function rdv_update_poll(int $id, array $in): array
{
    $poll = rdv_poll($id);
    if (!$poll) return ['Cette consultation n\'existe plus.'];
    [$data, $errors] = rdv_poll_input($in);
    if ($errors) return $errors;
    db()->prepare('UPDATE rdv_polls SET title = ?, description = ?, place = ?, notify_email = ?, closes_on = ?, results_public = ?, names_public = ?, allow_maybe = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$data['title'], $data['description'], $data['place'], $data['notify_email'], $data['closes_on'],
            $data['results_public'], $data['names_public'], $data['allow_maybe'], $id]);
    return [];
}

function rdv_insert_slots(int $pollId, array $slots): int
{
    $position = 0;
    $st = db()->prepare('SELECT COALESCE(MAX(position), 0) FROM rdv_slots WHERE poll_id = ?');
    $st->execute([$pollId]);
    $position = (int) $st->fetchColumn();
    $ins = db()->prepare('INSERT INTO rdv_slots (poll_id, slot_date, start_time, end_time, label, position) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($slots as $slot) {
        $position++;
        $ins->execute([$pollId, $slot['slot_date'], $slot['start_time'], $slot['end_time'], $slot['label'], $position]);
    }
    db()->prepare('UPDATE rdv_polls SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$pollId]);
    return count($slots);
}

function rdv_add_slots(int $pollId, string $text): array
{
    if (!rdv_poll($pollId)) return [0, ['Cette consultation n\'existe plus.']];
    $parsed = rdv_parse_slots($text);
    $errors = $parsed['errors'];
    $existingKeys = [];
    foreach (rdv_slots($pollId) as $slot) {
        $existingKeys[$slot['slot_date'] . '|' . $slot['start_time'] . '|' . $slot['end_time']] = true;
    }
    $slots = array_values(array_filter($parsed['slots'], static function (array $slot) use ($existingKeys): bool {
        return !isset($existingKeys[$slot['slot_date'] . '|' . $slot['start_time'] . '|' . $slot['end_time']]);
    }));
    if (!$slots) {
        $errors[] = 'Aucun nouveau créneau : ils sont déjà tous proposés.';
        return [0, $errors];
    }
    $existing = count(rdv_slots($pollId));
    if ($existing + count($slots) > RDV_MAX_SLOTS) {
        $errors[] = 'Une consultation ne peut pas dépasser ' . RDV_MAX_SLOTS . ' créneaux.';
        return [0, $errors];
    }
    $added = rdv_insert_slots($pollId, $slots);
    return [$added, $errors];
}

function rdv_delete_slot(int $slotId): void
{
    $slot = rdv_slot($slotId);
    if (!$slot) return;
    db()->prepare('DELETE FROM rdv_choices WHERE slot_id = ?')->execute([$slotId]);
    db()->prepare('DELETE FROM rdv_slots WHERE id = ?')->execute([$slotId]);
    $pollId = (int) $slot['poll_id'];
    $poll = rdv_poll($pollId);
    if ($poll && (int) $poll['confirmed_slot_id'] === $slotId) {
        db()->prepare("UPDATE rdv_polls SET confirmed_slot_id = NULL, confirmed_at = '', notified_at = '', status = CASE WHEN status = 'confirmed' THEN 'open' ELSE status END WHERE id = ?")->execute([$pollId]);
    }
    db()->prepare('UPDATE rdv_polls SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$pollId]);
}

function rdv_delete_poll(int $pollId): void
{
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM rdv_choices WHERE answer_id IN (SELECT id FROM rdv_answers WHERE poll_id = ?)')->execute([$pollId]);
        db()->prepare('DELETE FROM rdv_answers WHERE poll_id = ?')->execute([$pollId]);
        db()->prepare('DELETE FROM rdv_slots WHERE poll_id = ?')->execute([$pollId]);
        db()->prepare('DELETE FROM rdv_polls WHERE id = ?')->execute([$pollId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function rdv_set_status(int $pollId, string $status): array
{
    if (!isset(RDV_STATUSES[$status])) return ['État inconnu.'];
    $poll = rdv_poll($pollId);
    if (!$poll) return ['Cette consultation n\'existe plus.'];
    if ($status === 'open') {
        db()->prepare("UPDATE rdv_polls SET status = 'open', confirmed_slot_id = NULL, confirmed_at = '', notified_at = '', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$pollId]);
    } else {
        db()->prepare('UPDATE rdv_polls SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$status, $pollId]);
    }
    return [];
}

function rdv_confirm_slot(int $pollId, int $slotId): array
{
    $poll = rdv_poll($pollId);
    if (!$poll) return ['Cette consultation n\'existe plus.'];
    $slot = rdv_slot($slotId);
    if (!$slot || (int) $slot['poll_id'] !== $pollId) return ['Ce créneau ne fait pas partie de la consultation.'];
    db()->prepare("UPDATE rdv_polls SET status = 'confirmed', confirmed_slot_id = ?, confirmed_at = datetime('now'), updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$slotId, $pollId]);
    return [];
}

function rdv_delete_answer(int $answerId): void
{
    $answer = rdv_answer($answerId);
    if (!$answer) return;
    db()->prepare('DELETE FROM rdv_choices WHERE answer_id = ?')->execute([$answerId]);
    db()->prepare('DELETE FROM rdv_answers WHERE id = ?')->execute([$answerId]);
    db()->prepare('UPDATE rdv_polls SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $answer['poll_id']]);
}

/** Enregistre ou modifie la réponse d'un visiteur. -> [réponse|null, erreurs, créée]. */
function rdv_submit_answer(array $poll, array $in, string $editToken = ''): array
{
    if (!rdv_poll_open($poll)) return [null, ['Les réponses à cette consultation sont closes.'], false];
    $errors = [];
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    if (mb_strlen($name) < 2) $errors[] = 'Indiquez votre nom.';
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) $errors[] = 'Indiquez une adresse e-mail valide.';
    $phone = mb_substr(trim((string) preg_replace('/[^0-9+ ().-]/', '', (string) ($in['phone'] ?? ''))), 0, 30);
    $comment = mb_substr(trim((string) ($in['comment'] ?? '')), 0, 1000);
    if (empty($in['consent'])) $errors[] = 'Cochez la case pour accepter d\'être contacté au sujet de ce rendez-vous.';

    $slots = rdv_slots((int) $poll['id']);
    if (!$slots) return [null, ['Cette consultation n\'a pas encore de créneau.'], false];
    $valid = [];
    foreach ($slots as $slot) $valid[(int) $slot['id']] = true;
    $picked = [];
    $available = 0;
    foreach ((array) ($in['slots'] ?? []) as $slotId => $value) {
        $slotId = (int) $slotId;
        if (!isset($valid[$slotId])) continue;
        $value = (int) $value;
        if ($value !== 0 && $value !== 1 && $value !== 2) $value = 0;
        if (empty($poll['allow_maybe']) && $value === 1) $value = 2;
        $picked[$slotId] = $value;
        if ($value > 0) $available++;
    }
    if ($available === 0) $errors[] = 'Indiquez au moins un créneau où vous êtes disponible (oui ou peut-être).';

    $edit = $editToken !== '' ? rdv_answer_by_token((int) $poll['id'], $editToken) : null;
    if ($editToken !== '' && !$edit) $errors[] = 'Ce lien de modification n\'est plus valable.';
    if (!$edit && $email !== '') {
        $exists = rdv_answer_by_email((int) $poll['id'], $email);
        if ($exists) $errors[] = 'Une réponse existe déjà avec cette adresse. Utilisez le lien reçu par e-mail pour la modifier.';
    }
    if ($edit && $email !== '' && $email !== mb_strtolower((string) $edit['email'])) {
        $exists = rdv_answer_by_email((int) $poll['id'], $email);
        if ($exists && (int) $exists['id'] !== (int) $edit['id']) $errors[] = 'Une autre réponse utilise déjà cette adresse.';
    }
    $count = (int) db()->query('SELECT COUNT(*) FROM rdv_answers WHERE poll_id = ' . (int) $poll['id'])->fetchColumn();
    if (!$edit && $count >= RDV_MAX_ANSWERS) $errors[] = 'Cette consultation a atteint son nombre maximum de réponses.';
    if ($errors) return [null, $errors, false];

    if ($edit) {
        db()->prepare('UPDATE rdv_answers SET name = ?, email = ?, phone = ?, comment = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$name, $email, $phone, $comment, (int) $edit['id']]);
        $answerId = (int) $edit['id'];
        db()->prepare('DELETE FROM rdv_choices WHERE answer_id = ?')->execute([$answerId]);
    } else {
        $token = bin2hex(random_bytes(16));
        db()->prepare('INSERT INTO rdv_answers (poll_id, token, name, email, phone, comment) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int) $poll['id'], $token, $name, $email, $phone, $comment]);
        $answerId = (int) db()->lastInsertId();
    }
    $ins = db()->prepare('INSERT INTO rdv_choices (answer_id, slot_id, value) VALUES (?, ?, ?)');
    foreach ($picked as $slotId => $value) $ins->execute([$answerId, $slotId, $value]);
    db()->prepare('UPDATE rdv_polls SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $poll['id']]);
    return [rdv_answer($answerId), [], !$edit];
}

/** Texte récapitulatif des disponibilités d'une réponse. */
function rdv_answer_summary(array $poll, array $answer): string
{
    $choices = rdv_choices_map((int) $poll['id'])[(int) $answer['id']] ?? [];
    $groups = [2 => [], 1 => [], 0 => []];
    foreach (rdv_slots((int) $poll['id']) as $slot) {
        $value = $choices[(int) $slot['id']] ?? 0;
        if ($value > 0) $groups[$value][] = rdv_slot_label($slot, true);
    }
    $lines = [];
    if ($groups[2]) $lines[] = 'Oui : ' . implode(' · ', $groups[2]);
    if ($groups[1]) $lines[] = 'Peut-être : ' . implode(' · ', $groups[1]);
    if (!$lines) $lines[] = 'Aucune disponibilité positive.';
    return implode("\n", $lines);
}

// ── E-mails ────────────────────────────────────────────────────────────────

function rdv_mail(string $to, string $subject, string $body, string $context = ''): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (!function_exists('password_reset_mail')) require_once dirname(__DIR__, 3) . '/includes/password-reset.php';
    try {
        return password_reset_mail($to, $subject, $body, 'rendez-vous', $context);
    } catch (Throwable $e) {
        error_log('Rendez-vous (e-mail) : ' . $e->getMessage());
        return false;
    }
}

function rdv_notify_emails(array $poll): array
{
    $emails = rdv_parse_emails((string) ($poll['notify_email'] ?? ''));
    if (!$emails && function_exists('pricing_admin_emails')) {
        foreach (pricing_admin_emails() as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[] = (string) $email;
        }
    }
    return array_values(array_unique($emails));
}

function rdv_notify_new_answer(array $poll, array $answer, bool $isNew): void
{
    $emails = rdv_notify_emails($poll);
    if (!$emails) return;
    $subject = '[' . rdv_shop_name() . '] ' . ($isNew ? 'Nouvelle réponse' : 'Réponse modifiée') . ' — ' . $poll['title'];
    $body = ($isNew ? 'Une nouvelle réponse' : 'Une réponse a été modifiée') . " pour « " . $poll['title'] . " ».\n\n"
        . 'Nom : ' . $answer['name'] . "\n"
        . 'E-mail : ' . $answer['email'] . "\n"
        . ($answer['phone'] !== '' ? 'Téléphone : ' . $answer['phone'] . "\n" : '')
        . "\nDisponibilités :\n" . rdv_answer_summary($poll, $answer) . "\n"
        . ($answer['comment'] !== '' ? "\nMessage :\n" . $answer['comment'] . "\n" : '')
        . "\nSuivre les réponses : " . rdv_admin_absolute_url((int) $poll['id']) . "\n";
    foreach ($emails as $to) rdv_mail($to, $subject, $body, 'rdv:' . (int) $poll['id'] . ':' . (int) $answer['id']);
}

function rdv_notify_participant(array $poll, array $answer): bool
{
    $edit = rdv_public_url((string) $poll['token']) . '&r=' . rawurlencode((string) $answer['token']);
    $body = 'Bonjour ' . $answer['name'] . ",\n\n"
        . 'Votre réponse à « ' . $poll['title'] . ' » est bien enregistrée.' . "\n\n"
        . rdv_answer_summary($poll, $answer) . "\n\n"
        . 'Vous pouvez modifier votre réponse à tout moment : ' . $edit . "\n\n"
        . rdv_shop_name();
    return rdv_mail((string) $answer['email'], 'Votre réponse — ' . $poll['title'], $body, 'rdv:' . (int) $poll['id'] . ':' . (int) $answer['id']);
}

/** Prévient les participants qui avaient indiqué « oui » ou « peut-être » pour le créneau retenu. */
function rdv_notify_confirmation(array $poll): array
{
    $slots = rdv_slots((int) $poll['id']);
    $slotId = (int) ($poll['confirmed_slot_id'] ?? 0);
    $slot = null;
    foreach ($slots as $candidate) if ((int) $candidate['id'] === $slotId) $slot = $candidate;
    if (!$slot) return ['error' => 'Aucun créneau confirmé.', 'sent' => 0, 'failed' => 0, 'total' => 0];

    $choices = rdv_choices_map((int) $poll['id']);
    $recipients = [];
    foreach (rdv_answers((int) $poll['id']) as $answer) {
        $value = $choices[(int) $answer['id']][$slotId] ?? 0;
        if ($value === 2 || $value === 1) $recipients[$answer['email']] = $answer;
    }
    if (!$recipients) {
        foreach (rdv_answers((int) $poll['id']) as $answer) $recipients[$answer['email']] = $answer;
    }
    $recipients = array_slice($recipients, 0, RDV_MAX_MAILS);
    $sent = 0;
    $failed = 0;
    $when = rdv_slot_label($slot);
    foreach ($recipients as $answer) {
        $body = 'Bonjour ' . $answer['name'] . ",\n\n"
            . 'Le rendez-vous « ' . $poll['title'] . ' » est confirmé.' . "\n\n"
            . 'Créneau retenu : ' . $when . "\n"
            . ($poll['place'] !== '' ? 'Lieu : ' . $poll['place'] . "\n" : '')
            . ($poll['description'] !== '' ? "\n" . $poll['description'] . "\n" : '')
            . "\nDétails et réponse : " . rdv_public_url((string) $poll['token']) . "\n\n"
            . rdv_shop_name();
        $ok = rdv_mail((string) $answer['email'], 'Rendez-vous confirmé — ' . $poll['title'], $body, 'rdv:' . (int) $poll['id'] . ':' . (int) $answer['id']);
        $ok ? $sent++ : $failed++;
    }
    if ($sent > 0) db()->prepare("UPDATE rdv_polls SET notified_at = datetime('now') WHERE id = ?")->execute([(int) $poll['id']]);
    return ['sent' => $sent, 'failed' => $failed, 'total' => count($recipients)];
}

// ── Messages de l'administration (survivent au rechargement) ───────────────

function rdv_flash_set(string $message, string $kind = 'ok'): void
{
    if (session_status() === PHP_SESSION_NONE) require_once dirname(__DIR__, 3) . '/includes/session.php';
    $_SESSION['rdv_flash'] = ['message' => $message, 'kind' => $kind];
}

function rdv_flash_take(): ?array
{
    if (empty($_SESSION['rdv_flash'])) return null;
    $flash = $_SESSION['rdv_flash'];
    unset($_SESSION['rdv_flash']);
    return is_array($flash) ? $flash : null;
}
