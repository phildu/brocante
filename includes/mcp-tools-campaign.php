<?php

// Outils MCP de campagne promotionnelle : annoncer un ou plusieurs articles par SMS, e-mail, WhatsApp, Discord, Telegram ou notification
// navigateur, via le module Notifications (includes/notifications.php). On ne contourne rien :
//  - seules les personnes ABONNÉES à la rubrique marketing choisie (opt-in explicite, avec preuve) sont visées ; ce MCP ne peut pas abonner
//    quelqu'un (le consentement vient de la personne ou de l'administration, jamais d'un assistant) ;
//  - lien de désinscription ajouté à chaque message, SMS / WhatsApp différés hors des heures calmes, canal coupé ou STOP respectés ;
//  - envoi en deux temps : aperçu (message, nombre de destinataires, segments SMS) puis confirmation avec un jeton à usage unique ;
//  - plafond de destinataires, pas de renvoi du même message pendant 24 h, articles masqués ou épuisés refusés par défaut.
// Les listes de contacts (noms, numéros, adresses) ne sont jamais renvoyées à l'assistant : seulement des comptes et quelques exemples masqués.

require_once __DIR__ . '/mcp-tools-extra.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/sms.php';

const MCP_CAMPAIGN_MAX_PRODUCTS = 10;
const MCP_CAMPAIGN_MAX_RECIPIENTS = 500;

/** Rubrique marketing ciblée : celle demandée (doit être marketing) ou la première rubrique marketing. */
function mcp_campaign_topic(string $key): array
{
    $topics = nt_topics();
    if ($key !== '') {
        if (!isset($topics[$key]) || !$topics[$key]['is_marketing']) throw new McpToolError('Rubrique inconnue ou non marketing. Rubriques marketing : ' . implode(', ', array_keys(array_filter($topics, static fn ($t) => $t['is_marketing']))) . '.');
        return $topics[$key];
    }
    foreach ($topics as $t) if ($t['is_marketing']) return $t;
    throw new McpToolError('Aucune rubrique marketing n\'existe (Administration → Notifications → Rubriques).');
}

/** @return array{products: array, warnings: array} */
function mcp_campaign_products($refs): array
{
    if (!is_array($refs) || !$refs || count($refs) > MCP_CAMPAIGN_MAX_PRODUCTS) throw new McpToolError('refs : 1 à ' . MCP_CAMPAIGN_MAX_PRODUCTS . ' références de produits attendues.');
    $products = $warnings = [];
    foreach (array_values(array_unique(array_map(static fn ($r) => mcp_str($r, 40), $refs))) as $ref) {
        $p = get_product($ref);
        if (!$p) throw new McpToolError("Produit introuvable : $ref.");
        if ((int) $p['is_hidden'] === 1) $warnings[] = "Le produit $ref est masqué : le lien ne mènerait à rien.";
        if ((int) $p['stock'] <= 0) $warnings[] = "Le produit $ref est épuisé (stock 0).";
        $products[] = $p;
    }
    return ['products' => $products, 'warnings' => $warnings];
}

function mcp_campaign_product_url(array $p): string
{
    return rtrim(tenant_site_url(), '/') . '/produit.php?ref=' . rawurlencode((string) $p['ref']);
}

/** « 5 € (au lieu de 7,50 €) » ou « 5 € ». */
function mcp_campaign_price_text(array $p): string
{
    $promo = trim((string) ($p['promo_price'] ?? ''));
    return $promo !== '' ? $promo . ' (au lieu de ' . $p['price'] . ')' : (string) $p['price'];
}

/** Message proposé : objet, texte complet (e-mail…) et version courte (SMS, WhatsApp…). Variables : {prenom} {site}. */
function mcp_campaign_draft(array $products): array
{
    $site = '{site}';
    if (count($products) === 1) {
        $p = $products[0];
        $url = mcp_campaign_product_url($p);
        $subject = (trim((string) ($p['promo_price'] ?? '')) !== '' ? 'Promo : ' : 'À découvrir : ') . $p['name'];
        $body = "Bonjour {prenom},\n\n" . $p['name'] . ' : ' . mcp_campaign_price_text($p) . ".\n" . $url . "\n\nÀ bientôt chez $site.";
        $short = "$site : " . $p['name'] . ' ' . mcp_campaign_price_text($p) . ' ' . $url;
    } else {
        $lines = []; $shorts = [];
        foreach ($products as $p) {
            $lines[] = '- ' . $p['name'] . ' : ' . mcp_campaign_price_text($p) . "\n  " . mcp_campaign_product_url($p);
            $shorts[] = $p['name'] . ' ' . (trim((string) ($p['promo_price'] ?? '')) !== '' ? $p['promo_price'] : $p['price']);
        }
        $subject = 'Nos promos du moment';
        $body = "Bonjour {prenom},\n\nVoici nos offres :\n\n" . implode("\n", $lines) . "\n\nÀ bientôt chez $site.";
        $short = "$site : " . implode(', ', $shorts) . ' ' . rtrim(tenant_site_url(), '/') . '/';
    }
    return ['subject' => $subject, 'body' => $body, 'short_body' => $short];
}

/** Arguments normalisés, communs à l'aperçu et à l'envoi (le jeton de confirmation en dépend). */
function mcp_campaign_args(array $a): array
{
    $pr = mcp_campaign_products($a['refs'] ?? null);
    $topic = mcp_campaign_topic(mcp_str($a['topic'] ?? '', 40));
    $channels = null;
    if (isset($a['channels'])) {
        if (!is_array($a['channels']) || !$a['channels']) throw new McpToolError('channels : liste non vide (' . implode(', ', array_keys(nt_channels())) . ').');
        $channels = array_values(array_unique(array_map(static fn ($c) => mcp_str($c, 20), $a['channels'])));
        foreach ($channels as $c) if (!isset(nt_channels()[$c])) throw new McpToolError("Canal inconnu : $c. Canaux : " . implode(', ', array_keys(nt_channels())) . '.');
    }
    $strategy = ($a['strategy'] ?? 'all') === 'fallback' ? 'fallback' : 'all';
    $draft = mcp_campaign_draft($pr['products']);
    $subject = array_key_exists('subject', $a) ? mcp_str($a['subject'], 200) : $draft['subject'];
    $body = array_key_exists('message', $a) ? mcp_str($a['message'], 5000) : $draft['body'];
    $short = array_key_exists('short_message', $a) ? mcp_str($a['short_message'], 1000) : $draft['short_body'];
    foreach ([$subject, $body, $short] as $txt) {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $txt)) throw new McpToolError('Caractères de contrôle interdits dans le message.');
    }
    if (trim($body) === '' && trim($short) === '') throw new McpToolError('Le message est vide.');
    return ['refs' => array_column($pr['products'], 'ref'), 'warnings' => $pr['warnings'], 'topic' => $topic, 'channels' => $channels, 'strategy' => $strategy,
        'subject' => $subject, 'body' => $body, 'short_body' => $short];
}

function mcp_campaign_key(array $n): string
{
    return hash('sha256', json_encode([$n['refs'], $n['topic']['key'], $n['channels'], $n['strategy'], $n['subject'], $n['body'], $n['short_body']], JSON_UNESCAPED_UNICODE));
}

/** Simulation : destinataires, canaux, exclusions, segments SMS, report des heures calmes — sans aucune donnée personnelle. */
function mcp_campaign_plan(array $n): array
{
    nt_boot();
    $opts = ['strategy' => $n['strategy']] + ($n['channels'] !== null ? ['channels' => $n['channels']] : []);
    $plan = nt_plan($n['topic']['key'], null, $opts);
    $reasons = [];
    $userIds = [];
    $sample = [];
    foreach ($plan['users'] as $uid => $p) {
        if ($p['targets']) {
            $userIds[] = $uid;
            if (count($sample) < 3) $sample[] = ['prenom' => nt_user_first_name($p['user']), 'canaux' => array_map(static fn ($t) => nt_channel_label($t['channel']) . ' ' . nt_mask_address($t['channel'], $t['address']), $p['targets'])];
        }
        foreach ($p['skipped'] as [$ch, $why]) $reasons[$why] = ($reasons[$why] ?? 0) + 1;
    }
    $counts = array_filter($plan['counts']);
    // Longueur réelle d'un message court (SMS) pour un destinataire type.
    $smsSegments = null;
    if (!empty($counts['sms']) && $userIds) {
        $first = $plan['users'][$userIds[0]]['user'];
        $r = nt_render(['subject' => $n['subject'], 'body' => $n['body'], 'short_body' => $n['short_body']], $first, $n['topic'], 'sms');
        $smsSegments = sms_segments($r['text']);
    }
    $deferred = null;
    if ((!empty($counts['sms']) || !empty($counts['whatsapp'])) && ($when = nt_quiet_adjust(nt_now())) > nt_now()) $deferred = nt_fmt($when);
    return ['plan' => $plan, 'user_ids' => $userIds, 'recipients' => $plan['recipients'], 'by_channel' => $counts, 'excluded' => $reasons, 'sample' => $sample,
        'sms_segments_each' => $smsSegments, 'sms_segments_total' => $smsSegments !== null ? $smsSegments * (int) ($counts['sms'] ?? 0) : null, 'deferred_until' => $deferred];
}

function mcp_campaign_summary(array $n, array $p): array
{
    return [
        'rubrique' => $n['topic']['label'], 'produits' => $n['refs'], 'strategie' => $n['strategy'] === 'fallback' ? 'canal préféré puis secours' : 'tous les canaux de chaque personne',
        'message' => ['objet' => $n['subject'], 'texte' => $n['body'], 'version_courte_sms' => $n['short_body']],
        'destinataires' => $p['recipients'], 'par_canal' => $p['by_channel'], 'exemples' => $p['sample'],
        'non_atteints' => $p['excluded'],
        'sms' => $p['sms_segments_each'] === null ? null : ['segments_par_sms' => $p['sms_segments_each'], 'segments_total' => $p['sms_segments_total'], 'cout' => 'facturé par votre compte Twilio, selon votre tarif'],
        'envoi_differe_jusqua' => $p['deferred_until'],
    ];
}

function mcp_tools_campaign(): array
{
    $common = [
        'refs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Références des produits à annoncer (1 à ' . MCP_CAMPAIGN_MAX_PRODUCTS . ').'],
        'topic' => ['type' => 'string', 'description' => 'Clé d\'une rubrique marketing (défaut : la première).'],
        'channels' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(nt_channels())], 'description' => 'Restreindre à ces canaux (sms, email, whatsapp, discord, telegram, push). Défaut : tous ceux de chaque abonné.'],
        'strategy' => ['type' => 'string', 'enum' => ['all', 'fallback'], 'default' => 'all', 'description' => 'all = tous les canaux ; fallback = canal préféré puis secours.'],
        'subject' => ['type' => 'string'], 'message' => ['type' => 'string', 'description' => 'Texte complet. Variables : {prenom} {site}.'],
        'short_message' => ['type' => 'string', 'description' => 'Version courte pour SMS / WhatsApp / Telegram / Discord. Variables : {prenom} {site}.'],
    ];
    return [
        'campaign_audience' => [
            'write' => false,
            'description' => 'Combien de personnes peuvent recevoir une promo, par rubrique marketing et par canal (abonnés ayant donné leur accord, canaux configurés). Ne renvoie aucun nom ni numéro.',
            'schema' => ['type' => 'object', 'properties' => new stdClass()],
            'run' => static function (array $a): array {
                nt_boot();
                $out = [];
                foreach (nt_topics() as $key => $t) {
                    if (!$t['is_marketing']) continue;
                    $plan = nt_plan($key, null);
                    $out[] = ['rubrique' => $key, 'libelle' => $t['label'], 'abonnes_joignables' => $plan['recipients'], 'par_canal' => array_filter($plan['counts'])];
                }
                $channels = [];
                foreach (nt_channel_status() as $ch => $s) $channels[$ch] = $s['ready'] ? 'prêt' : ($s['webhook_only'] ? 'webhook seulement' : 'non configuré');
                $total = (int) db()->query('SELECT COUNT(*) FROM nt_users')->fetchColumn();
                return ['contacts_total' => $total, 'rubriques_marketing' => $out, 'canaux' => $channels,
                    'rappel' => 'Seules les personnes abonnées (opt-in) reçoivent une promo. Elles s\'abonnent depuis leur page de préférences ou sont inscrites par l\'administration avec preuve de consentement : ce MCP ne peut abonner personne.'];
            },
        ],
        'campaign_preview' => [
            'write' => false,
            'description' => 'APERÇU d\'une campagne de promo sur un ou plusieurs produits : message proposé (modifiable), nombre de destinataires par canal, segments SMS, report aux heures calmes, avertissements (produit masqué ou épuisé), et un confirm_token pour envoyer. N\'envoie rien. Pour que la promo soit visible, fixez d\'abord promo_price avec product_update.',
            'schema' => ['type' => 'object', 'properties' => $common, 'required' => ['refs']],
            'run' => static function (array $a): array {
                $n = mcp_campaign_args($a);
                $p = mcp_campaign_plan($n);
                $out = ['envoye' => false] + mcp_campaign_summary($n, $p);
                if ($n['warnings']) $out['avertissements'] = $n['warnings'];
                if ($p['recipients'] === 0) {
                    $out['aucun_destinataire'] = 'Personne n\'est joignable : aucun abonné à cette rubrique, ou canaux non configurés. Rien ne peut partir.';
                    return $out;
                }
                if ($p['recipients'] > MCP_CAMPAIGN_MAX_RECIPIENTS) $out['trop_de_destinataires'] = 'Plus de ' . MCP_CAMPAIGN_MAX_RECIPIENTS . ' destinataires : utilisez Administration → Notifications.';
                else $out += ['confirm_token' => mcp_confirm_issue('campaign_send', mcp_campaign_key($n)), 'valable_minutes' => MCP_CONFIRM_MINUTES,
                    'etape_suivante' => 'Montrez cet aperçu à l\'utilisateur ; après son accord explicite, appelez campaign_send avec les mêmes arguments et ce confirm_token.'];
                return $out;
            },
        ],
        'campaign_send' => [
            'write' => true,
            'description' => 'ENVOIE la campagne de promo (messages réels aux clients abonnés). À n\'utiliser qu\'après campaign_preview et l\'accord explicite de l\'utilisateur : mêmes arguments + confirm_token. Chaque message porte le lien de désinscription ; SMS et WhatsApp partent hors des heures calmes. allow_unavailable=true pour annoncer un produit masqué ou épuisé ; resend=true pour renvoyer un message identique envoyé dans les 24 h.',
            'schema' => ['type' => 'object', 'properties' => $common + ['confirm_token' => ['type' => 'string'], 'allow_unavailable' => ['type' => 'boolean'], 'resend' => ['type' => 'boolean']], 'required' => ['refs', 'confirm_token']],
            'run' => static function (array $a): array {
                $n = mcp_campaign_args($a);
                if ($n['warnings'] && empty($a['allow_unavailable'])) throw new McpToolError('Envoi refusé : ' . implode(' ', $n['warnings']) . ' (allow_unavailable=true pour passer outre).');
                $key = mcp_campaign_key($n);
                if (!mcp_confirm_consume('campaign_send', $key, isset($a['confirm_token']) ? (string) $a['confirm_token'] : null)) {
                    throw new McpToolError('Confirmation manquante, expirée ou déjà utilisée, ou message différent de l\'aperçu : relancez campaign_preview et faites valider.');
                }
                $p = mcp_campaign_plan($n);
                if ($p['recipients'] === 0) throw new McpToolError('Aucun destinataire joignable : rien envoyé.');
                if ($p['recipients'] > MCP_CAMPAIGN_MAX_RECIPIENTS) throw new McpToolError('Plus de ' . MCP_CAMPAIGN_MAX_RECIPIENTS . ' destinataires : utilisez Administration → Notifications.');
                if (empty($a['resend'])) {
                    $dup = db()->prepare('SELECT id FROM nt_messages WHERE topic = ? AND subject = ? AND body = ? AND created_at >= ? LIMIT 1');
                    $dup->execute([$n['topic']['key'], mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $n['subject'])), 0, 200), mb_substr(trim($n['body']), 0, 20000), nt_fmt(nt_now() - 86400)]);
                    if ($dup->fetchColumn()) throw new McpToolError('Ce message a déjà été envoyé dans les dernières 24 h (resend=true pour le renvoyer).');
                }
                mcp_backup_before_write();
                $res = nt_notify($n['topic']['key'], $p['user_ids'], $n['subject'], $n['body'],
                    ['strategy' => $n['strategy'], 'short_body' => $n['short_body'], 'created_by' => 'mcp', 'immediate' => true, 'immediate_limit' => 40]
                    + ($n['channels'] !== null ? ['channels' => $n['channels']] : []));
                if (!empty($res['error'])) throw new McpToolError('Envoi impossible : ' . $res['error']);
                $done = ['message_id' => $res['message_id'], 'destinataires' => $res['recipients'], 'envois_en_file' => $res['queued'], 'produits' => $n['refs'], 'rubrique' => $n['topic']['key']];
                return ['applique' => true, 'envoye' => true] + $done + [
                    'traitement_immediat' => $res['processed'],
                    'suite' => 'Les envois restants, les reprises et les SMS différés (heures calmes) partent par la file (cron toutes les 5 minutes, ou « Traiter la file » dans Notifications).',
                    '_audit' => [null, $done],
                ];
            },
        ],
        'campaign_history' => [
            'write' => false,
            'description' => 'Dernières campagnes envoyées (objet, rubrique, date, nombre d\'envois par statut). limit ≤ 20.',
            'schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'default' => 10]]],
            'run' => static function (array $a): array {
                nt_boot();
                $limit = isset($a['limit']) ? mcp_int($a['limit'], 1, 20, 'limit') : 10;
                $msgs = db()->query("SELECT id, topic, subject, created_by, created_at FROM nt_messages WHERE topic IN (SELECT key FROM nt_topics WHERE is_marketing = 1) ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($msgs as &$m) {
                    $st = db()->prepare('SELECT status, COUNT(*) n FROM nt_deliveries WHERE message_id = ? GROUP BY status'); $st->execute([(int) $m['id']]);
                    $m['envois'] = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'n', 'status');
                }
                return ['campagnes' => $msgs];
            },
        ],
    ];
}
