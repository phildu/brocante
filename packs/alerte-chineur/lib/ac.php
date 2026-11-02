<?php
// Alerte chineur : inscriptions publiques, confirmation, désinscription et envoi des alertes.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

const AC_MODULE = 'alerte-chineur';
const AC_MAX_PER_EMAIL = 5;       // alertes actives ou en attente par adresse
const AC_MAX_PER_IP_HOUR = 6;     // inscriptions par visiteur et par heure
const AC_MAX_MAILS_PER_SCAN = 20;
const AC_SCAN_EVERY = 300;        // secondes entre deux vérifications déclenchées par les visites

function ac_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false && $t !== '') $s = $t;
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
}

function ac_setting(string $k, string $d = ''): string
{
    $st = db()->prepare('SELECT value FROM ac_settings WHERE key = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $d : (string) $v;
}

function ac_setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO ac_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$k, $v]);
}

function ac_url(string $path): string
{
    return shop_abs_url('/api/chineur/' . ltrim($path, '/'));
}

function ac_mail(string $to, string $subject, string $body): bool
{
    require_once dirname(__DIR__, 3) . '/includes/password-reset.php';
    return password_reset_mail($to, $subject, $body, 'alerte-chineur');
}

/** Inscription d'un visiteur. -> [message, erreur]. */
function ac_subscribe(string $email, string $keywords, string $maxPrice, string $ip): array
{
    $email = mb_strtolower(trim($email));
    $keywords = mb_substr(trim(preg_replace('/\s+/', ' ', $keywords)), 0, 120);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) return [null, 'Adresse e-mail invalide.'];
    if (mb_strlen($keywords) < 3) return [null, 'Indiquez ce que vous cherchez (3 caractères minimum).'];
    $cents = 0;
    if (trim($maxPrice) !== '') {
        $cents = (int) round(((float) str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $maxPrice))) * 100);
        if ($cents < 0 || $cents > 10000000) $cents = 0;
    }
    $hash = hash('sha256', $ip . '|' . (string) (tenant_slug()));
    db()->prepare('DELETE FROM ac_hits WHERE at < ?')->execute([time() - 3600]);
    $n = db()->prepare('SELECT COUNT(*) FROM ac_hits WHERE ip_hash = ? AND at > ?');
    $n->execute([$hash, time() - 3600]);
    if ((int) $n->fetchColumn() >= AC_MAX_PER_IP_HOUR) return [null, 'Trop de demandes depuis votre connexion : réessayez dans une heure.'];
    db()->prepare('INSERT INTO ac_hits (ip_hash, at) VALUES (?, ?)')->execute([$hash, time()]);

    $c = db()->prepare("SELECT COUNT(*) FROM ac_alerts WHERE email = ? AND status <> 'stopped'");
    $c->execute([$email]);
    if ((int) $c->fetchColumn() >= AC_MAX_PER_EMAIL) return [null, 'Vous avez déjà ' . AC_MAX_PER_EMAIL . ' alertes : supprimez-en une avec le lien de désinscription d\'un e-mail reçu.'];
    $dup = db()->prepare("SELECT 1 FROM ac_alerts WHERE email = ? AND keywords = ? AND max_price_cents = ? AND status <> 'stopped'");
    $dup->execute([$email, $keywords, $cents]);
    $msg = 'Presque fini : un e-mail de confirmation vient de vous être envoyé.';
    if ($dup->fetchColumn()) return [$msg, null];   // même réponse : on ne révèle pas ce qui existe déjà

    $token = bin2hex(random_bytes(16));
    db()->prepare("INSERT INTO ac_alerts (email, keywords, max_price_cents, token, status) VALUES (?, ?, ?, ?, 'pending')")->execute([$email, $keywords, $cents, $token]);
    $site = (string) (get_content()['site_name'] ?? tenant('name'));
    ac_mail($email, 'Confirmez votre alerte — ' . $site,
        "Bonjour,\n\nVous avez demandé à être prévenu(e) dès qu'une pièce « $keywords »" . ($cents ? ' à ' . number_format($cents / 100, 2, ',', ' ') . ' € maximum' : '') . " est mise en ligne chez $site.\n\n"
        . "Pour activer l'alerte, cliquez ici :\n" . ac_url('confirmer/' . $token) . "\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : rien ne sera envoyé.\n");
    return [$msg, null];
}

/** Pièces visibles, en stock, mises en ligne après la création de l'alerte et qui correspondent à ses critères. */
function ac_matches(array $alert): array
{
    $st = db()->prepare('SELECT * FROM products WHERE is_hidden = 0 AND stock > 0 AND created_at > ? AND ref NOT IN (SELECT product_ref FROM ac_sent WHERE alert_id = ?) ORDER BY created_at DESC LIMIT 60');
    $st->execute([$alert['created_at'], $alert['id']]);
    $tokens = array_filter(explode(' ', ac_norm($alert['keywords'])), static fn ($t) => strlen($t) >= 2);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $labels = function_exists('product_nature_labels') ? (array) product_nature_labels($p['nature'] ?? null, $p['sous_categorie'] ?? null) : [];
        $hay = ' ' . ac_norm(implode(' ', [$p['name'], $p['description'], $p['brand'], $p['materials'], $p['colors'], $p['cat'], implode(' ', array_map('strval', $labels))])) . ' ';
        $ok = $tokens !== [];
        foreach ($tokens as $t) if (!str_contains($hay, $t)) { $ok = false; break; }
        if (!$ok) continue;
        if ((int) $alert['max_price_cents'] > 0) {
            $cents = price_to_cents(effective_price($p));
            if ($cents !== null && $cents > (int) $alert['max_price_cents']) continue;
        }
        $out[] = $p;
        if (count($out) >= 10) break;
    }
    return $out;
}

/** Envoie les alertes dues. -> nombre d'e-mails envoyés. */
function ac_scan(bool $force = false): int
{
    if (!addon_is_entitled(AC_MODULE)) return 0;
    if (!$force && time() - (int) ac_setting('last_scan', '0') < AC_SCAN_EVERY) return 0;
    ac_setting_set('last_scan', (string) time());
    $site = (string) (get_content()['site_name'] ?? tenant('name'));
    $sent = 0;
    foreach (db()->query("SELECT * FROM ac_alerts WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if ($sent >= AC_MAX_MAILS_PER_SCAN) break;
        $m = ac_matches($a);
        if (!$m) continue;
        $lines = [];
        foreach ($m as $p) $lines[] = '- ' . $p['name'] . ' — ' . effective_price($p) . "\n  " . shop_abs_url('/produit.php?ref=' . rawurlencode($p['ref']));
        $ok = ac_mail($a['email'], count($m) > 1 ? count($m) . ' nouvelles pièces pour vous — ' . $site : 'Nouvelle pièce pour vous — ' . $site,
            "Bonjour,\n\nDu nouveau chez $site qui correspond à votre alerte « {$a['keywords']} » :\n\n" . implode("\n", $lines)
            . "\n\nPièces uniques : premier arrivé, premier servi.\n\nSe désinscrire : " . ac_url('stop/' . $a['token']) . "\n");
        if (!$ok) continue;
        $ins = db()->prepare('INSERT OR IGNORE INTO ac_sent (alert_id, product_ref) VALUES (?, ?)');
        foreach ($m as $p) $ins->execute([$a['id'], $p['ref']]);
        db()->prepare('UPDATE ac_alerts SET last_notified_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$a['id']]);
        $sent++;
    }
    return $sent;
}
