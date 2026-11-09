<?php

// Mailer du portail : les messages de la plateforme (inscriptions, reçus, équipe, retours) partent
// de contact@bricbrok.com par le serveur SMTP authentifié d'OVH, au lieu de la fonction mail() de
// l'hébergement (mal authentifiée : SPF/DKIM, classement en courrier indésirable).
//
// - Le mot de passe de la boîte vit dans .secrets/mailer.json (0600, jamais déployé, jamais réaffiché).
// - Le mailer ne s'applique qu'aux messages dont l'expéditeur est du domaine du portail
//   (bricbrok.com) : les boutiques qui écrivent depuis leur propre domaine restent sur mail().
// - Si l'envoi SMTP échoue, l'appelant retombe sur mail() : un incident OVH ne bloque aucun parcours.
// - La boîte contact@ se crée par l'API OVH (offre « MX Plan », routes /email/domain/*) avec les
//   identifiants déjà saisis pour les domaines (.secrets/ovh.json) ; voir portail_mailer_ovh_*.

require_once __DIR__ . '/notifications.php';

const PORTAL_MAILER_ACCOUNT_RE = '/^[a-z0-9]([a-z0-9._-]{0,28}[a-z0-9])?$/';

function portal_mailer_file(): string
{
    return defined('PORTAL_MAILER_FILE') ? (string) PORTAL_MAILER_FILE : dirname(__DIR__) . '/.secrets/mailer.json';
}

/** Contenu brut du fichier de réglages ([] s'il n'existe pas). */
function portal_mailer_saved(): array
{
    $file = portal_mailer_file();
    if (!is_file($file)) return [];
    try {
        $saved = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Le fichier de réglages de la messagerie est illisible. Corrigez-le depuis le portail.');
    }
    if (!is_array($saved)) throw new RuntimeException('Le fichier de réglages de la messagerie est invalide.');
    return $saved;
}

/**
 * Réglages effectifs : variable d'environnement MAILER_<CLÉ> > fichier > valeur par défaut.
 * @return array{domain:string,account:string,from_name:string,host:string,port:int,secure:string,pass:string,enabled:bool,from:string,user:string}
 */
function portal_mailer_config(): array
{
    $saved = portal_mailer_saved();
    $value = static function (string $key, string $default = '') use ($saved): string {
        $env = trim((string) getenv('MAILER_' . strtoupper($key)));
        $stored = $saved[$key] ?? $default;
        return $env !== '' ? $env : (is_scalar($stored) ? trim((string) $stored) : $default);
    };
    $domain = strtolower($value('domain', 'bricbrok.com'));
    $account = strtolower($value('account', 'contact'));
    $secure = $value('secure', 'ssl');
    $port = (int) $value('port', $secure === 'tls' ? '587' : '465');
    return [
        'domain' => $domain,
        'account' => $account,
        'from' => $account . '@' . $domain,
        'user' => $account . '@' . $domain,
        'from_name' => $value('from_name', 'Bricbrok'),
        'host' => $value('host', 'ssl0.ovh.net'),
        'port' => $port > 0 ? $port : 465,
        'secure' => in_array($secure, ['ssl', 'tls'], true) ? $secure : 'ssl',
        'pass' => $value('pass'),
        'enabled' => $value('enabled', '1') !== '0',
    ];
}

/** Vrai si le mailer peut envoyer : mot de passe présent, adresse valide, pas désactivé. */
function portal_mailer_ready(?array $config = null): bool
{
    try {
        $c = $config ?? portal_mailer_config();
    } catch (RuntimeException $e) {
        return false;
    }
    return $c['enabled'] && $c['pass'] !== '' && $c['host'] !== ''
        && filter_var($c['from'], FILTER_VALIDATE_EMAIL) !== false;
}

/** Enregistre des réglages (écriture atomique, droits 0600). Une valeur absente est conservée. */
function portal_mailer_save(array $changes): void
{
    $saved = portal_mailer_saved();
    foreach (['domain', 'account', 'from_name', 'host', 'port', 'secure', 'pass', 'enabled'] as $key) {
        if (!array_key_exists($key, $changes)) continue;
        $v = $changes[$key];
        if (!is_scalar($v)) throw new InvalidArgumentException('Réglage de messagerie invalide.');
        $v = trim((string) $v);
        if (preg_match('/[\r\n\x00]/', $v) || strlen($v) > 200) throw new InvalidArgumentException('Réglage de messagerie invalide.');
        if ($key === 'pass' && $v === '') continue; // champ secret vide = on garde
        if ($key === 'domain') {
            $v = strtolower($v);
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $v)) throw new InvalidArgumentException('Nom de domaine invalide.');
        } elseif ($key === 'account') {
            $v = strtolower($v);
            if (!preg_match(PORTAL_MAILER_ACCOUNT_RE, $v)) throw new InvalidArgumentException('Nom de boîte invalide (lettres, chiffres, point, tiret).');
        } elseif ($key === 'secure' && !in_array($v, ['ssl', 'tls'], true)) {
            throw new InvalidArgumentException('Sécurité SMTP : ssl ou tls.');
        } elseif ($key === 'port' && !(ctype_digit($v) && (int) $v > 0 && (int) $v < 65536)) {
            throw new InvalidArgumentException('Port SMTP invalide.');
        } elseif ($key === 'enabled') {
            $v = $v === '0' ? '0' : '1';
        } elseif ($key === 'host' && !preg_match('/^[a-z0-9.-]+$/i', $v)) {
            throw new InvalidArgumentException('Serveur SMTP invalide.');
        }
        $saved[$key] = $v;
    }

    $dir = dirname(portal_mailer_file());
    if (is_link($dir)) throw new RuntimeException('Le dossier .secrets ne peut pas être un lien symbolique.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Impossible de créer le dossier .secrets.');
    $tmp = tempnam($dir, '.mailer-');
    if ($tmp === false) throw new RuntimeException('Impossible de préparer le fichier de messagerie.');
    try {
        $json = json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !@chmod($tmp, 0600) || !@rename($tmp, portal_mailer_file())) {
            throw new RuntimeException('Impossible d’enregistrer la messagerie. Vérifiez les droits du dossier .secrets.');
        }
    } catch (Throwable $e) {
        if (is_file($tmp)) @unlink($tmp);
        throw $e;
    }
    @chmod(portal_mailer_file(), 0600);
}

/** Supprime les réglages du fichier (le mailer cesse d'être utilisé ; mail() reprend). */
function portal_mailer_delete(): void
{
    $file = portal_mailer_file();
    if (is_file($file) && !@unlink($file)) throw new RuntimeException('Impossible de supprimer les réglages de messagerie.');
}

/** Mot de passe de boîte conforme aux règles OVH : 24 caractères alphanumériques, majuscule, minuscule et chiffre. */
function portal_mailer_generate_password(): string
{
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789'];
    $all = implode('', $sets);
    $chars = [];
    foreach ($sets as $set) $chars[] = $set[random_int(0, strlen($set) - 1)];
    while (count($chars) < 24) $chars[] = $all[random_int(0, strlen($all) - 1)];
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/* ====================================================================== */
/* Envoi                                                                  */
/* ====================================================================== */

/** Adresse nue d'un en-tête From (« From: Nom <a@b.c> », « Nom <a@b.c> » ou « a@b.c »). */
function portal_mailer_address(string $fromHeader): string
{
    $h = preg_replace('/^\s*From:\s*/i', '', $fromHeader);
    if (preg_match('/<([^<>]+)>\s*$/', $h, $m)) return strtolower(trim($m[1]));
    return strtolower(trim($h));
}

/** Vrai si le mailer prend en charge ce message : prêt, et expéditeur du domaine du portail. */
function portal_mailer_applies(string $fromHeader, ?array $config = null): bool
{
    try {
        $c = $config ?? portal_mailer_config();
    } catch (RuntimeException $e) {
        return false;
    }
    if (!portal_mailer_ready($c)) return false;
    $addr = portal_mailer_address($fromHeader);
    return filter_var($addr, FILTER_VALIDATE_EMAIL) !== false && substr(strrchr($addr, '@') ?: '', 1) === $c['domain'];
}

/** Point d'injection des tests : remplace l'envoi SMTP réel. */
function portal_mailer_transport(?callable $set = null, bool $clear = false): ?callable
{
    static $transport = null;
    if ($clear) $transport = null;
    if ($set !== null) $transport = $set;
    return $transport;
}

/**
 * Envoie un message par le SMTP du portail.
 * Retourne null si le mailer ne s'applique pas (l'appelant utilise mail()), sinon true/false.
 * $headers : en-têtes sans From/To/Subject ; $subject : objet déjà encodé MIME ; $body : corps déjà encodé.
 * Le nom affiché de l'expéditeur est conservé, l'adresse devient celle de la boîte (OVH refuse un autre expéditeur).
 */
function portal_mailer_dispatch(string $fromHeader, string $to, string $subject, array $headers, string $body): ?bool
{
    try {
        $c = portal_mailer_config();
    } catch (RuntimeException $e) {
        error_log('Mailer portail : ' . $e->getMessage());
        return null;
    }
    if (!portal_mailer_applies($fromHeader, $c)) return null;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\x00-\x1F]/', $to)) return false;

    $h = preg_replace('/^\s*From:\s*/i', '', $fromHeader);
    $name = preg_match('/^(.*?)\s*<[^<>]+>\s*$/', $h, $m) ? trim($m[1], " \t\"") : '';
    if ($name === '') $name = $c['from_name'];
    $name = nt_header_clean($name);
    $display = preg_match('/^[\x20-\x7E]+$/', $name)
        ? '"' . addcslashes($name, '"\\') . '"'
        : mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n");
    $lines = ['From: ' . $display . ' <' . $c['from'] . '>', 'To: ' . $to, 'Subject: ' . $subject,
        'Date: ' . date('r'), 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $c['domain'] . '>'];
    foreach ($headers as $line) {
        $line = nt_header_clean((string) $line);
        if ($line !== '' && !preg_match('/^(From|To|Subject|Date|Message-ID):/i', $line)) $lines[] = $line;
    }
    $raw = implode("\r\n", $lines) . "\r\n\r\n" . $body;

    $smtp = ['host' => $c['host'], 'port' => $c['port'], 'secure' => $c['secure'], 'user' => $c['user'], 'pass' => $c['pass']];
    $send = portal_mailer_transport();
    $r = $send ? $send($c['from'], $to, $raw, $smtp) : nt_smtp_send($c['from'], $to, $raw, $smtp);
    if (!$r['ok']) error_log('Mailer portail : ' . $r['error']);
    return (bool) $r['ok'];
}

/** Envoi d'essai depuis la page « Messagerie » ; retourne ['ok'=>bool,'error'=>string]. */
function portal_mailer_test(string $to): array
{
    $c = portal_mailer_config();
    if (!portal_mailer_ready($c)) return ['ok' => false, 'error' => 'La messagerie n’est pas prête : mot de passe de la boîte manquant ou messagerie désactivée.'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Adresse de destination invalide.'];
    $body = quoted_printable_encode("Ceci est un message d'essai du portail Bricbrok.\r\nS'il vous parvient, l'envoi depuis " . $c['from'] . " fonctionne.");
    $r = portal_mailer_dispatch('From: ' . $c['from_name'] . ' <' . $c['from'] . '>', $to,
        mb_encode_mimeheader('Essai de la messagerie du portail', 'UTF-8', 'B', "\r\n"),
        ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: quoted-printable'], $body);
    return $r === true ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'Le serveur SMTP a refusé le message ; voir le journal PHP du serveur pour le détail.'];
}

/* ====================================================================== */
/* Création de la boîte chez OVH (offre « MX Plan » : /email/domain)       */
/* ====================================================================== */

/** Client OVH : celui des domaines (mêmes identifiants), ou un double injecté par les tests. */
function portal_mailer_ovh_client(?object $client = null): object
{
    if ($client !== null) return $client;
    require_once __DIR__ . '/branding-services.php';
    return branding_ovh_client();
}

/** Appel API OVH avec message d'erreur lisible en français. */
function portal_mailer_ovh_call(object $client, string $method, string $path, $content = null, string $stage = ''): array
{
    try {
        $r = $method === 'GET' ? $client->get($path, $content) : $client->post($path, $content);
    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
        require_once __DIR__ . '/branding-services.php';
        $err = branding_ovh_api_error($e, $stage !== '' ? $stage : $method . ' ' . $path);
        throw new RuntimeException($err->getMessage()
            . ' Le jeton doit autoriser GET et POST sur /email/domain/* (voir l’aide de la page Messagerie).', 0, $e);
    }
    return is_array($r) ? $r : [];
}

/**
 * État côté OVH : le domaine a-t-il l'offre MX Plan, la boîte existe-t-elle ?
 * @return array{offer:bool,exists:bool}
 */
function portal_mailer_ovh_status(?object $client = null): array
{
    $c = portal_mailer_config();
    $client = portal_mailer_ovh_client($client);
    $domainPath = '/email/domain/' . rawurlencode($c['domain']);
    try {
        $client->get($domainPath);
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) return ['offer' => false, 'exists' => false];
        require_once __DIR__ . '/branding-services.php';
        throw new RuntimeException(branding_ovh_api_error($e, 'GET ' . $domainPath)->getMessage()
            . ' Le jeton doit autoriser GET sur /email/domain/*.', 0, $e);
    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
        require_once __DIR__ . '/branding-services.php';
        throw branding_ovh_api_error($e, 'GET ' . $domainPath);
    }
    $found = portal_mailer_ovh_call($client, 'GET', $domainPath . '/account', ['accountName' => $c['account']], 'GET ' . $domainPath . '/account');
    return ['offer' => true, 'exists' => in_array($c['account'], array_map('strval', $found), true)];
}

/**
 * Crée la boîte (ou, si elle existe déjà sans mot de passe connu / sur demande, change son mot de passe),
 * puis enregistre le mot de passe dans .secrets/mailer.json. Le mot de passe n'est jamais retourné.
 * @return array{action:string,message:string}  action : created | password_reset | unchanged
 */
function portal_mailer_ovh_ensure(bool $resetPassword = false, ?object $client = null): array
{
    $c = portal_mailer_config();
    if (!preg_match(PORTAL_MAILER_ACCOUNT_RE, $c['account'])) throw new RuntimeException('Nom de boîte invalide.');
    $client = portal_mailer_ovh_client($client);
    $st = portal_mailer_ovh_status($client);
    if (!$st['offer']) {
        throw new RuntimeException('Le domaine ' . $c['domain'] . ' n’a pas d’offre e-mail « MX Plan » chez OVH (Email Pro et Exchange ne se pilotent pas par ces routes). Créez la boîte dans l’espace client OVH puis saisissez son mot de passe ici.');
    }
    $base = '/email/domain/' . rawurlencode($c['domain']) . '/account';
    if (!$st['exists']) {
        $password = portal_mailer_generate_password();
        portal_mailer_ovh_call($client, 'POST', $base, ['accountName' => $c['account'], 'password' => $password,
            'description' => 'Portail Bricbrok — envoi automatique'], 'POST ' . $base);
        try {
            portal_mailer_save(['pass' => $password, 'enabled' => '1']);
        } catch (Throwable $e) {
            throw new RuntimeException('La boîte ' . $c['from'] . ' est créée, mais son mot de passe n’a pas pu être enregistré (' . $e->getMessage() . '). Utilisez « Changer le mot de passe » pour en générer un nouveau.', 0, $e);
        }
        return ['action' => 'created', 'message' => 'Boîte ' . $c['from'] . ' créée chez OVH ; elle sera utilisable dans quelques minutes.'];
    }
    if ($resetPassword || $c['pass'] === '') {
        $password = portal_mailer_generate_password();
        $path = $base . '/' . rawurlencode($c['account']) . '/changePassword';
        portal_mailer_ovh_call($client, 'POST', $path, ['password' => $password], 'POST ' . $path);
        portal_mailer_save(['pass' => $password, 'enabled' => '1']);
        return ['action' => 'password_reset', 'message' => 'La boîte ' . $c['from'] . ' existait : son mot de passe a été renouvelé (prise en compte par OVH sous quelques minutes).'];
    }
    return ['action' => 'unchanged', 'message' => 'La boîte ' . $c['from'] . ' existe déjà et le mot de passe est enregistré : rien à faire.'];
}

/**
 * Contrôle DNS indicatif du domaine : MX chez OVH, SPF autorisant OVH.
 * $resolver(string $host, string $type): array — par défaut dns_get_record().
 * @return array{mx:bool,spf:bool}
 */
function portal_mailer_dns_check(?string $domain = null, ?callable $resolver = null): array
{
    $domain = $domain ?? portal_mailer_config()['domain'];
    $resolver = $resolver ?? static function (string $host, string $type): array {
        $r = @dns_get_record($host, $type === 'MX' ? DNS_MX : DNS_TXT);
        return is_array($r) ? $r : [];
    };
    $mx = false;
    foreach ($resolver($domain, 'MX') as $rec) {
        if (str_ends_with(strtolower((string) ($rec['target'] ?? '')), '.mail.ovh.net')) $mx = true;
    }
    $spf = false;
    foreach ($resolver($domain, 'TXT') as $rec) {
        $txt = strtolower((string) ($rec['txt'] ?? ''));
        if (str_starts_with($txt, 'v=spf1') && (str_contains($txt, 'include:mx.ovh.com') || str_contains($txt, 'include:_spf.mail.ovh.net') || str_contains($txt, 'include:spf.mail.ovh.net'))) $spf = true;
    }
    return ['mx' => $mx, 'spf' => $spf];
}
