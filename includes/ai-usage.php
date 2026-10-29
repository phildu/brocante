<?php

// Consommation de l'IA : chaque appel payant (génération d'image, texte et vision, recherche web, détourage) est
// consigné avec son coût ESTIMÉ ; la page « Consommation IA » en fait le total et le solde d'un crédit prépayé.
// Les tarifs sont ceux des offres publiques à la date de cette version : modifiables dans la page, à vérifier chez
// Google (ai.google.dev/pricing) et fal.ai. Le solde réel d'un compte n'est lisible par aucune API : ce qui est affiché
// est une estimation, pas une facture.
//
// Les fournisseurs d'IA et l'interrupteur « IA proposée » vivent dans includes/ai-providers.php (réglés au portail).

require_once __DIR__ . '/ai-providers.php';

/** Tarifs par défaut : image = $ par image générée ; text_in / text_out = $ par million de tokens ; search = $ par requête avec recherche web ; cutout = $ par détourage fal.ai ; veo_* = $ par seconde de vidéo Veo 3.1 (Lite, Fast, Standard ; 720p) ; wan22 = $ par vidéo Wan 2.2 (SiliconFlow, durée fixée par le service) ; modal_video = $ estimé par vidéo Wan 2.2 5B sur Modal (≈ 22 s de GPU + chargement du modèle, prélevé sur le crédit Modal). */
const AI_PRICING_DEFAULTS = ['usd_eur' => 0.92, 'image' => 0.039, 'text_in' => 0.30, 'text_out' => 2.50, 'search' => 0.035, 'cutout' => 0.001, 'veo_lite' => 0.05, 'veo_fast' => 0.10, 'veo_std' => 0.40, 'wan22' => 0.29, 'modal_video' => 0.07, 'claude_in' => 5.00, 'claude_out' => 25.00];
const AI_KINDS = ['image' => 'Images générées', 'text' => 'Texte et vision', 'search' => 'Recherches web', 'cutout' => 'Détourages (fal.ai, Modal)', 'video' => 'Vidéos IA (Veo, Wan)'];

function ai_usage_ensure_schema(PDO $pdo): void
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ai_usage'")->fetchColumn();
    if ($exists) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_usage (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      kind TEXT NOT NULL,
      model TEXT NOT NULL DEFAULT '',
      tokens_in INTEGER NOT NULL DEFAULT 0,
      tokens_out INTEGER NOT NULL DEFAULT 0,
      cost_usd REAL NOT NULL DEFAULT 0,
      script TEXT NOT NULL DEFAULT '',
      ref TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_usage_at ON ai_usage(at)');
}

/** Tarifs en vigueur : ceux enregistrés pour le commerce, complétés par les valeurs par défaut. */
function ai_pricing(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $saved = [];
    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'ai_pricing'");
        $stmt->execute();
        $saved = json_decode((string) $stmt->fetchColumn(), true) ?: [];
    } catch (Throwable $e) {
    }
    $cache = array_merge(AI_PRICING_DEFAULTS, array_intersect_key(array_map('floatval', $saved), AI_PRICING_DEFAULTS));
    // Une boutique dont l'IA est comptée (quota de sa formule) est mesurée au barème de la plateforme : elle ne peut pas baisser ses propres tarifs.
    if (ai_policy()) {
        require_once __DIR__ . '/ai-plans.php';
        $cache = array_merge($cache, array_intersect_key(saas_ai_pricing(), AI_PRICING_DEFAULTS));
    }
    return $cache;
}

function ai_pricing_save(array $values): void
{
    $clean = [];
    foreach (AI_PRICING_DEFAULTS as $key => $default) {
        $v = (float) str_replace(',', '.', (string) ($values[$key] ?? $default));
        $clean[$key] = $v >= 0 && $v < 1000 ? $v : $default;
    }
    db()->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES ('ai_pricing', ?)")->execute([json_encode($clean)]);
}

/** Pièce à laquelle rattacher les appels qui suivent (pour le classement « par pièce »). */
function ai_usage_context(?string $ref = null): ?string
{
    static $current = null;
    if (func_num_args() > 0) $current = $ref;
    return $current;
}

/** Consigne un appel. Ne casse jamais l'appel de l'IA : toute erreur d'écriture est ignorée. */
function ai_usage_record(string $kind, float $costUsd, string $model = '', int $tokensIn = 0, int $tokensOut = 0): void
{
    try {
        db()->prepare('INSERT INTO ai_usage (kind, model, tokens_in, tokens_out, cost_usd, script, ref) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$kind, $model, $tokensIn, $tokensOut, round($costUsd, 6), basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'cli')), ai_usage_context()]);
        ai_check_low_balance_alert(str_starts_with($model, 'fal') ? 'fal' : 'gemini');
    } catch (Throwable $e) {
    }
}

/** Coût en dollars d'un appel de texte d'après usageMetadata : entrée, puis sortie y compris la réflexion du modèle. */
function ai_usage_text_cost(array $usage): array
{
    $in = (int) ($usage['promptTokenCount'] ?? 0);
    $out = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
    $p = ai_pricing();
    return [$in * $p['text_in'] / 1e6 + $out * $p['text_out'] / 1e6, $in, $out];
}

function ai_usage_log_text(array $usage, string $model = 'gemini-2.5-flash'): void
{
    [$cost, $in, $out] = ai_usage_text_cost($usage);
    ai_usage_record('text', $cost, $model, $in, $out);
}

/** Appel de Claude (scénario de la Vidéo IA du pack Communication) : tokens d'entrée et de sortie au barème claude_in / claude_out. */
function ai_usage_log_claude(array $usage, string $model = 'claude'): void
{
    $p = ai_pricing();
    $in = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0);
    $out = (int) ($usage['output_tokens'] ?? 0);
    ai_usage_record('text', $in * $p['claude_in'] / 1e6 + $out * $p['claude_out'] / 1e6, $model, $in, $out);
}

function ai_usage_log_image(array $usage = []): void
{
    ai_usage_record('image', ai_pricing()['image'], 'gemini-2.5-flash-image', (int) ($usage['promptTokenCount'] ?? 0), (int) ($usage['candidatesTokenCount'] ?? 0));
}

/** Recherche web : le tarif d'une requête avec recherche, plus les tokens. */
function ai_usage_log_search(array $usage, bool $grounded): void
{
    [$cost, $in, $out] = ai_usage_text_cost($usage);
    ai_usage_record('search', $cost + ($grounded ? ai_pricing()['search'] : 0), 'gemini-2.5-flash + Google Search', $in, $out);
}

/** Vidéo IA terminée (Veo : facturé à la seconde ; Wan 2.2 : au prix d'une vidéo). */
function ai_usage_log_video(string $modelKey, int $seconds): void
{
    if (str_starts_with($modelKey, 'sf:')) {
        $provider = ai_provider_video_by_key($modelKey);
        ai_usage_record('video', veo_cost_usd($modelKey, $seconds), $provider ? (($provider['model'] ?: $provider['label'])) : 'Wan 2.2');
        return;
    }
    if ($modelKey === SF_MODEL_KEY) {
        ai_usage_record('video', veo_cost_usd($modelKey, $seconds), SF_VIDEO_MODEL);
        return;
    }
    if ($modelKey === MODAL_MODEL_KEY) {
        ai_usage_record('video', veo_cost_usd($modelKey, $seconds), 'Wan 2.2 5B (Modal)');
        return;
    }
    $model = VEO_MODELS[$modelKey] ?? VEO_MODELS['fast'];
    ai_usage_record('video', veo_cost_usd($modelKey, $seconds), $model['id'] . " ({$seconds} s)");
}

function ai_usage_log_cutout(string $model = 'fal-ai/imageutils/rembg'): void
{
    ai_usage_record('cutout', ai_pricing()['cutout'], $model);
}

// ── Estimations affichées avant de générer ──

/** Coût moyen d'un appel de texte : la moyenne des derniers appels consignés, sinon une valeur par défaut. */
function ai_text_call_usd(): float
{
    try {
        $rows = db()->query("SELECT cost_usd FROM ai_usage WHERE kind = 'text' ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) >= 5) return array_sum($rows) / count($rows);
    } catch (Throwable $e) {
    }
    return 0.003;
}

/** Coût estimé en dollars de $counts = ['image' => n, 'text' => n, 'search' => n, 'cutout' => n, 'usd' => montant déjà calculé]. */
function ai_estimate_usd(array $counts): float
{
    $p = ai_pricing();
    return ($counts['image'] ?? 0) * $p['image'] + ($counts['text'] ?? 0) * ai_text_call_usd()
        + ($counts['search'] ?? 0) * ($p['search'] + ai_text_call_usd() * 2) + ($counts['cutout'] ?? 0) * $p['cutout'] + ($counts['usd'] ?? 0);
}

function ai_eur(float $usd): float
{
    return $usd * ai_pricing()['usd_eur'];
}

/** « 3,42 € », « < 0,01 € » : un montant en euros pour l'affichage. */
function ai_format_eur(float $eur): string
{
    if ($eur > 0 && $eur < 0.005) return '< 0,01 €';
    return number_format($eur, 2, ',', ' ') . ' €';
}

/** « ≈ 0,08 € » pour $counts. */
function ai_estimate_label(array $counts): string
{
    $label = ai_format_eur(ai_eur(ai_estimate_usd($counts)));
    return str_starts_with($label, '<') ? $label : '≈ ' . $label;
}

/** Détourage d'une pièce : fal.ai s'il est configuré, sinon le repli par image générée. */
function ai_cutout_counts(): array
{
    return FAL_API_KEY || modal_video_available() ? ['cutout' => 1] : ['image' => 1];
}

/** Traitement complet d'une pièce (Studio, lot) : détourage, mise en situation 3:2 et 9:16, fiche. */
function ai_estimate_piece_counts(): array
{
    $c = ai_cutout_counts();
    $c['image'] = ($c['image'] ?? 0) + 2;
    $c['text'] = 1;
    return $c;
}

// ── Totaux ──

/** Dépense estimée en euros depuis $since (horodatage UTC « Y-m-d H:i:s »), ou toute la période. */
function ai_usage_spent(?string $since = null): float
{
    try {
        $stmt = db()->prepare('SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage' . ($since ? ' WHERE at >= ?' : ''));
        $stmt->execute($since ? [$since] : []);
        return ai_eur((float) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Crédit prépayé déclaré : ['amount' => euros, 'since' => horodatage UTC] ou null. */
function ai_budget(): ?array
{
    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'ai_budget'");
        $stmt->execute();
        $b = json_decode((string) $stmt->fetchColumn(), true);
        return is_array($b) && isset($b['amount'], $b['since']) ? ['amount' => (float) $b['amount'], 'since' => (string) $b['since']] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Déclare un crédit : le solde repart de ce montant, les dépenses comptées à partir de maintenant. */
function ai_budget_save(float $amountEur): void
{
    db()->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES ('ai_budget', ?)")
        ->execute([json_encode(['amount' => max(0, round($amountEur, 2)), 'since' => gmdate('Y-m-d H:i:s')])]);
}

/** Solde estimé : crédit moins dépenses depuis sa déclaration (peut devenir négatif). */
function ai_balance(): ?array
{
    $b = ai_budget();
    if (!$b) return null;
    $spent = ai_usage_spent($b['since']);
    return ['amount' => $b['amount'], 'since' => $b['since'], 'spent' => $spent, 'remaining' => $b['amount'] - $spent];
}


// ── Quota d'IA gratuite et crédit racheté (voir includes/ai-plans.php) ──

/** Quota affecté à CETTE boutique par la plateforme (['quota_eur', 'since', 'source']) ou par son tenant.php (ai_quota_eur) ; null : IA non limitée. */
function ai_policy(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    try {
        require_once __DIR__ . '/ai-plans.php';
        $cache = saas_ai_policy(tenant_slug());
        if (!$cache) {
            $q = tenant('ai_quota_eur', null);
            if ($q !== null && $q !== '') $cache = ['quota_eur' => max(0.0, (float) $q), 'since' => gmdate('Y-m'), 'source' => 'tenant'];
        }
    } catch (Throwable $e) {
        $cache = null;
    }
    return $cache;
}

/**
 * État du quota : ['quota', 'month_spent', 'free_left', 'purchased', 'overflow', 'credit_left', 'available', 'blocked', 'month'] (montants en euros) ; null si l'IA de la boutique n'est pas limitée.
 * Le dépassement (consommation du mois au-delà du quota, cumulé depuis le début du décompte) est prélevé sur le crédit racheté.
 */
function ai_quota_state(): ?array
{
    $pol = ai_policy();
    if (!$pol) return null;
    $rate = ai_pricing()['usd_eur'];
    $month = gmdate('Y-m');
    $spent = [];
    try {
        $stmt = db()->prepare("SELECT strftime('%Y-%m', at) AS m, SUM(cost_usd) AS c FROM ai_usage WHERE at >= ? GROUP BY m");
        $stmt->execute([$pol['since'] . '-01 00:00:00']);
        foreach ($stmt->fetchAll() as $r) $spent[(string) $r['m']] = (float) $r['c'] * $rate;
    } catch (Throwable $e) {
    }
    $quota = $pol['quota_eur'];
    $monthSpent = $spent[$month] ?? 0.0;
    $overflow = 0.0;
    foreach ($spent as $v) $overflow += max(0.0, $v - $quota);
    $purchased = saas_ai_credits(tenant_slug())['purchased_eur'];
    $creditLeft = $purchased - $overflow;
    $freeLeft = max(0.0, $quota - $monthSpent);
    $available = $freeLeft + max(0.0, $creditLeft);
    return ['quota' => $quota, 'month_spent' => $monthSpent, 'free_left' => $freeLeft, 'purchased' => $purchased, 'overflow' => $overflow,
        'credit_left' => $creditLeft, 'available' => $available, 'blocked' => $available < 0.005, 'month' => $month];
}

/** Message à montrer quand les générations IA sont suspendues (IA coupée par l'exploitant, ou quota du mois épuisé et plus de crédit), ou null si l'IA est disponible. */
function ai_gate(): ?string
{
    if (!ai_shop_enabled()) {
        return "Les fonctions d'IA sont désactivées pour cette boutique. Contactez l'exploitant de la plateforme pour les réactiver.";
    }
    try {
        $s = ai_quota_state();
    } catch (Throwable $e) {
        return null;
    }
    if (!$s || !$s['blocked']) return null;
    return 'Le quota d\'IA gratuite de ce mois est épuisé (' . ai_format_eur($s['month_spent']) . ' consommés pour ' . ai_format_eur($s['quota']) . ' inclus)'
        . ($s['purchased'] > 0 ? ' et votre crédit racheté aussi' : '') . '. Rachetez du crédit IA depuis « Consommation IA », ou attendez le début du mois prochain.';
}

// ── Notifications et alertes de solde IA ─────────────────────────────────────

/**
 * Détermine le destinataire d'une alerte IA :
 * Si la boutique utilise sa propre clé -> le commerçant (comptes administrateurs ou admin_email).
 * Si la boutique utilise la clé partagée du portail -> l'administrateur du portail (contact SaaS ou compte portail).
 *
 * @return array{type: 'merchant'|'portal', emails: string[], is_shared: bool, label: string}
 */
function ai_alert_recipient(string $service = 'gemini'): array
{
    require_once __DIR__ . '/shared-secrets.php';
    require_once __DIR__ . '/saas.php';

    $keyFile = ($service === 'gemini') ? 'gemini.key' : $service . '.key';
    
    // Vérification si la boutique a sa propre clé dédiée dans son dossier secrets_dir
    $slug = function_exists('tenant_slug') ? tenant_slug() : '';
    $ownSecretsDir = defined('SECRETS_DIR') ? SECRETS_DIR : (function_exists('tenant') ? tenant_file(tenant(), 'secrets_dir') : '');
    $hasOwnKey = ($ownSecretsDir !== '' && is_file($ownSecretsDir . '/' . $keyFile));

    // Si pas de clé dédiée dans le dossier du commerce (ou si secret_is_shared indique partagé),
    // c'est la clé de la plateforme / du portail qui est utilisée.
    $defaultSlug = defined('TENANT_DEFAULT') ? TENANT_DEFAULT : 'petit-chalet';
    $isShared = (!$hasOwnKey && $slug !== $defaultSlug) || secret_is_shared($keyFile);

    if ($isShared) {
        $portalConfig = saas_config();
        $contact = trim((string) ($portalConfig['contact'] ?? ''));
        if ($contact === '') {
            $pAccount = dirname(__DIR__) . '/.secrets/portail.json';
            if (is_file($pAccount)) {
                $pData = json_decode((string) file_get_contents($pAccount), true);
                if (!empty($pData['user']) && filter_var($pData['user'], FILTER_VALIDATE_EMAIL)) {
                    $contact = $pData['user'];
                }
            }
        }
        $emails = $contact !== '' ? [$contact] : [];
        if (empty($emails)) {
            try {
                $mainDb = dirname(__DIR__) . '/brocante.db';
                if (!is_file($mainDb)) $mainDb = dirname(__DIR__) . '/data/brocante.db';
                if (is_file($mainDb)) {
                    $mpdo = new PDO('sqlite:' . $mainDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $fe = $mpdo->query("SELECT email FROM accounts WHERE role = 'admin' AND is_active = 1 LIMIT 1")->fetchColumn();
                    if ($fe && filter_var($fe, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = (string) $fe;
                    }
                }
            } catch (Throwable $e) {
            }
        }
        return [
            'type' => 'portal',
            'emails' => array_values(array_unique(array_filter($emails))),
            'is_shared' => true,
            'label' => "Administrateur du portail (" . ($portalConfig['name'] ?? 'Plateforme') . ")"
        ];
    }

    $emails = [];
    if (function_exists('pricing_admin_emails')) {
        $emails = pricing_admin_emails();
    }
    if (empty($emails)) {
        $adminEmail = trim((string) tenant('admin_email', ''));
        if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $adminEmail;
        } else {
            $adminUser = trim((string) tenant('admin_user', ''));
            if ($adminUser !== '' && filter_var($adminUser, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $adminUser;
            }
        }
    }
    return [
        'type' => 'merchant',
        'emails' => array_values(array_unique(array_filter($emails))),
        'is_shared' => false,
        'label' => "Commerçant (" . (tenant('name') ?: 'Boutique') . ")"
    ];
}

/**
 * Envoie une notification d'alerte solde IA au commerçant ou à l'administrateur du portail.
 * Évite les envois répétés (au maximum un e-mail par 24 h pour le même type d'alerte et service).
 */
function ai_send_balance_alert(string $alertType, string $subject, string $message, string $service = 'gemini'): bool
{
    $now = time();
    $sentKey = 'ai_alert_sent_' . preg_replace('/[^a-z0-9_-]/i', '_', $alertType . '_' . $service);

    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE name = ?");
        $stmt->execute([$sentKey]);
        $lastSent = (int) $stmt->fetchColumn();
        if ($lastSent > 0 && ($now - $lastSent) < 86400) {
            return false; // Alerte déjà envoyée il y a moins de 24 h
        }
    } catch (Throwable $e) {
    }

    $recipient = ai_alert_recipient($service);
    if (empty($recipient['emails'])) {
        error_log("ai_send_balance_alert [$alertType]: aucun e-mail de contact configuré pour " . $recipient['label']);
        return false;
    }

    $siteName = tenant('name') ?: 'Boutique';
    $fullBody = $message . "\n\n---\nNotification automatique générée par la plateforme Brocs pour : $siteName\nDate : " . date('d/m/Y H:i:s') . "\n";

    require_once __DIR__ . '/saas.php';
    $sent = false;
    foreach ($recipient['emails'] as $email) {
        if (saas_mail($email, $subject, $fullBody)) {
            $sent = true;
        }
    }

    try {
        db()->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES (?, ?)")
            ->execute([$sentKey, (string) $now]);
    } catch (Throwable $e) {
    }

    return $sent;
}

/**
 * Vérifie si le solde ou quota IA approche de la fin et déclenche une alerte préventive si nécessaire.
 * Seuil par défaut : moins de 2,00 € restant (ou moins de 15% du quota total).
 */
function ai_check_low_balance_alert(string $service = 'gemini'): void
{
    try {
        $quota = ai_quota_state();
        if ($quota && isset($quota['available'])) {
            $available = (float) $quota['available'];
            $totalQuota = (float) ($quota['quota'] ?? 0) + (float) ($quota['purchased'] ?? 0);
            $threshold = min(2.00, max(0.50, $totalQuota * 0.15));

            if ($available > 0.005 && $available <= $threshold) {
                $recipient = ai_alert_recipient($service);
                $amtStr = ai_format_eur($available);
                $shopName = tenant('name') ?: 'votre boutique';

                if ($recipient['is_shared']) {
                    $subject = "[Alerte Portefeuille IA] Solde partagé bientôt épuisé ($amtStr restant)";
                    $msg = "Bonjour,\n\nLe solde de crédit/quota IA partagé de la plateforme arrive bientôt à épuisement.\nIl reste actuellement environ $amtStr pour l'ensemble des commerces utilisant la clé partagée.\n\nPensez à réapprovisionner les crédits avant interruption des services IA.";
                } else {
                    $subject = "[Alerte Crédit IA] Solde presque épuisé sur $shopName ($amtStr restant)";
                    $msg = "Bonjour,\n\nLe solde de crédit IA disponible sur votre boutique « $shopName » est presque épuisé.\nIl vous reste actuellement environ $amtStr pour vos générations d'images, de textes et de fiches produits.\n\nVous pouvez recharger votre crédit IA directement depuis l'administration de votre boutique (rubrique Consommation IA) pour éviter toute interruption.";
                }

                ai_send_balance_alert('low_balance', $subject, $msg, $service);
            }
        } else {
            $bal = ai_balance();
            if ($bal && isset($bal['remaining'])) {
                $rem = (float) $bal['remaining'];
                $init = (float) $bal['amount'];
                $threshold = min(2.00, max(0.50, $init * 0.15));
                if ($rem > 0.005 && $rem <= $threshold) {
                    $recipient = ai_alert_recipient($service);
                    $amtStr = number_format($rem, 2, ',', ' ') . ' €';
                    $shopName = tenant('name') ?: 'votre boutique';
                    $subject = "[Alerte Budget IA] Solde bientôt épuisé ($amtStr restant)";
                    $msg = "Bonjour,\n\nLe budget estimé pour l'IA sur $shopName arrive à épuisement (reste $amtStr sur les " . number_format($init, 2, ',', ' ') . " € déclarés).\n\nPensez à vérifier vos comptes ou réapprovisionner vos crédits.";
                    ai_send_balance_alert('low_budget', $subject, $msg, $service);
                }
            }
        }
    } catch (Throwable $e) {
        error_log("ai_check_low_balance_alert: " . $e->getMessage());
    }
}

/**
 * Déclenche une alerte immédiate lors d'un épuisement avéré de crédits Google AI Studio (HTTP 402).
 */
function ai_trigger_depleted_alert(string $service = 'gemini', string $details = ''): void
{
    $recipient = ai_alert_recipient($service);
    $shopName = tenant('name') ?: 'votre boutique';

    if ($recipient['is_shared']) {
        $subject = "[Urgent - Alerte IA] Crédits Google AI Studio épuisés sur la clé partagée du portail";
        $msg = "Bonjour,\n\nLes crédits prépayés Google AI Studio associés à la clé partagée de la plateforme sont épuisés (erreur HTTP 402 / Prepayment credits depleted).\n\nConséquence : La génération d'images (bandeaux de page, photos produits) et de contenus par l'IA est actuellement BLOQUÉE pour tous les commerces utilisant la clé du portail (dont $shopName).\n\nPour réactiver les services, rechargez les crédits sur Google AI Studio :\nhttps://ai.studio/projects\n\nDétails de l'erreur API : " . ($details ?: 'HTTP 402 Resource Exhausted');
    } else {
        $subject = "[Urgent - Alerte IA] Vos crédits Google AI Studio sont épuisés ($shopName)";
        $msg = "Bonjour,\n\nLes crédits prépayés Google AI Studio associés à votre clé d'API personnelle pour la boutique « $shopName » sont épuisés (erreur HTTP 402 / Prepayment credits depleted).\n\nConséquence : La génération de bandeaux, photos et descriptions par l'IA ne peut plus fonctionner pour le moment.\n\nPour débloquer la génération, veuillez recharger votre compte Google AI Studio :\nhttps://ai.studio/projects\n\nDétails de l'erreur API : " . ($details ?: 'HTTP 402 Resource Exhausted');
    }

    ai_send_balance_alert('depleted_402', $subject, $msg, $service);
}

