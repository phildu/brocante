<?php
// Test de connexion des clés d'API de la boutique (Gemini, fal.ai, SiliconFlow, Modal, Stripe).
// Le dernier résultat est gardé dans SECRETS_DIR/keycheck.json avec l'empreinte de la clé testée : si la clé change, l'ancien
// résultat est ignoré (« non testée »). Le tableau de bord signale les clés enregistrées dont le dernier test a échoué.

const KEYCHECK_STALE_SECONDS = 86400;

/** Services testables : id => [libellé, clé présente, valeur servant d'empreinte, page de réglage]. */
function keycheck_services(): array
{
    $modal = function_exists('modal_video_available') && modal_video_available();
    $stripe = defined('STRIPE_SECRET_KEY') ? (string) STRIPE_SECRET_KEY : '';
    return [
        'gemini' => ['Gemini (IA images et textes)', GEMINI_API_KEY !== '', GEMINI_API_KEY, '/admin/social.php#gemini-settings'],
        'fal' => ['fal.ai (détourage)', FAL_API_KEY !== '', FAL_API_KEY, '/admin/social.php#fal-settings'],
        'siliconflow' => ['SiliconFlow (vidéo IA)', defined('SILICONFLOW_API_KEY') && SILICONFLOW_API_KEY !== '', defined('SILICONFLOW_API_KEY') ? SILICONFLOW_API_KEY : '', '/admin/social.php#siliconflow-settings'],
        'modal' => ['Modal (vidéo IA, détourage)', $modal, $modal ? MODAL_VIDEO_URL . '|' . MODAL_VIDEO_TOKEN : '', '/admin/social.php#modal-video-settings'],
        'stripe' => ['Stripe (paiement)', $stripe !== '', $stripe, '/admin/index.php?s=paiement#stripe-settings'],
    ];
}

function keycheck_file(): string
{
    return SECRETS_DIR . '/keycheck.json';
}

function keycheck_store(): array
{
    $d = is_file(keycheck_file()) ? json_decode((string) file_get_contents(keycheck_file()), true) : null;
    return is_array($d) ? $d : [];
}

function keycheck_save(string $id, bool $ok, string $message, string $fp): void
{
    $store = keycheck_store();
    $store[$id] = ['ok' => $ok, 'message' => $message, 'at' => time(), 'fp' => sha1($fp)];
    if (!is_dir(SECRETS_DIR)) mkdir(SECRETS_DIR, 0700, true);
    file_put_contents(keycheck_file(), json_encode($store), LOCK_EX);
    @chmod(keycheck_file(), 0600);
}

/** Appel HTTP de test : [code, corps décodé]. Code 0 = pas de réponse (réseau). */
function keycheck_http(string $method, string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode((string) $raw, true) ?: []];
}

/** Teste un service et mémorise le résultat. -> ['ok' => bool, 'message' => texte]. */
function keycheck_run(string $id): array
{
    $svc = keycheck_services()[$id] ?? null;
    if (!$svc) return ['ok' => false, 'message' => 'Service inconnu.'];
    if (!$svc[1]) return ['ok' => false, 'message' => 'Aucune clé enregistrée.'];
    $bad = static fn (int $code, string $what): array => $code === 0
        ? ['ok' => false, 'message' => 'Aucune réponse du service : test impossible pour le moment.']
        : ['ok' => false, 'message' => $what . ' (HTTP ' . $code . ').'];
    $res = ['ok' => true, 'message' => 'Clé valide : le service répond.'];
    switch ($id) {
        case 'gemini':
            [$code, $d] = keycheck_http('GET', 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1', ['x-goog-api-key: ' . GEMINI_API_KEY]);
            if ($code !== 200) {
                if ($code === 402 || str_contains(json_encode($d), 'prepayment')) {
                    $res = ['ok' => false, 'message' => 'Crédits Google AI Studio épuisés (HTTP 402). Rechargez sur https://ai.studio/projects.'];
                    if (function_exists('ai_trigger_depleted_alert')) ai_trigger_depleted_alert('gemini', (string) ($d['error']['message'] ?? ''));
                } else {
                    $res = $bad($code, in_array($code, [400, 401, 403], true) ? 'Clé refusée par Google : ' . mb_substr((string) ($d['error']['message'] ?? 'clé invalide'), 0, 140) : 'Réponse inattendue de Google');
                }
                break;
            }
            // La liste des modèles répond même quand le crédit de génération est
            // épuisé : on vérifie donc un vrai appel de génération (texte minimal).
            $model = function_exists('gemini_text_model') ? gemini_text_model() : 'gemini-3.8-flash';
            [$gcode, $gd] = keycheck_http('POST', 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
                ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
                (string) json_encode(['contents' => [['parts' => [['text' => 'ping']]]]]));
            if ($gcode === 200) {
                $res = ['ok' => true, 'message' => 'Clé valide : génération IA opérationnelle.'];
            } elseif ($gcode === 402 || str_contains(json_encode($gd), 'prepayment')) {
                $res = ['ok' => false, 'message' => 'Clé listée, mais génération refusée : crédits Google AI Studio épuisés (HTTP 402). Rechargez sur https://ai.studio/projects.'];
                if (function_exists('ai_trigger_depleted_alert')) ai_trigger_depleted_alert('gemini', (string) ($gd['error']['message'] ?? ''));
            } elseif ($gcode === 429) {
                $res = ['ok' => false, 'message' => 'Clé listée, mais quota de génération atteint (HTTP 429). Réessayez plus tard ou activez la facturation Google AI Studio.'];
            } elseif ($gcode === 0) {
                $res = $bad(0, '');
            } else {
                $detail = trim((string) ($gd['error']['message'] ?? ''));
                if ($gcode === 404) {
                    // Le modèle est propre à la clé/projet : on liste ceux que cette
                    // clé expose réellement, pour savoir quoi corriger.
                    [$lc, $ld] = keycheck_http('GET', 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000', ['x-goog-api-key: ' . GEMINI_API_KEY]);
                    $flash = [];
                    foreach ((array) ($ld['models'] ?? []) as $m) {
                        $name = (string) ($m['name'] ?? '');
                        if (str_contains($name, 'flash') && in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) $flash[] = basename($name);
                    }
                    $res = ['ok' => false, 'message' => 'Clé listée, mais le modèle ' . $model . ' est introuvable (HTTP 404)'
                        . ($lc !== 200 ? ' — liste des modèles illisible (HTTP ' . $lc . ').'
                            : ($flash ? ' — modèles flash disponibles : ' . implode(', ', array_slice($flash, 0, 6)) . '.'
                                : ' — aucun modèle flash disponible pour cette clé.'))
                        . ($detail !== '' ? ' Google : ' . mb_substr($detail, 0, 160) : '')];
                } else {
                    $res = $bad($gcode, 'Clé listée, mais génération refusée par Google' . ($detail !== '' ? ' : ' . mb_substr($detail, 0, 160) : ''));
                }
            }
            break;
        case 'fal':
            // Statut d'une requête inexistante : 401/403 = clé refusée, 404 = clé acceptée (aucun coût).
            [$code] = keycheck_http('GET', 'https://queue.fal.run/fal-ai/birefnet/requests/00000000-0000-0000-0000-000000000000/status', ['Authorization: Key ' . FAL_API_KEY]);
            if ($code === 0 || in_array($code, [401, 403], true) || $code >= 500) $res = $bad($code, in_array($code, [401, 403], true) ? 'Clé refusée par fal.ai' : 'Réponse inattendue de fal.ai');
            break;
        case 'siliconflow':
            [$code, $d] = keycheck_http('GET', 'https://api.siliconflow.com/v1/models', ['Authorization: Bearer ' . SILICONFLOW_API_KEY]);
            if ($code !== 200) $res = $bad($code, in_array($code, [401, 403], true) ? 'Clé refusée par SiliconFlow' : 'Réponse inattendue de SiliconFlow');
            break;
        case 'modal':
            $res = modal_video_check();
            break;
        case 'stripe':
            [$code, $d] = keycheck_http('GET', 'https://api.stripe.com/v1/balance', ['Authorization: Bearer ' . STRIPE_SECRET_KEY]);
            // 403 = clé valide mais restreinte (sans lecture du solde) : le paiement fonctionne quand même.
            if ($code === 401) $res = $bad($code, 'Clé Stripe refusée : ' . mb_substr((string) ($d['error']['message'] ?? 'clé invalide'), 0, 140));
            elseif ($code !== 200 && $code !== 403) $res = $bad($code, 'Réponse inattendue de Stripe');
            elseif (str_starts_with(STRIPE_SECRET_KEY, 'sk_test_')) $res['message'] = 'Clé valide (mode test).';
            break;
    }
    keycheck_save($id, (bool) $res['ok'], (string) $res['message'], (string) $svc[2]);
    return $res;
}

/** Dernier résultat valable (même clé) : ['ok','message','at'] ou null si jamais testée / clé modifiée. */
function keycheck_last(string $id): ?array
{
    $svc = keycheck_services()[$id] ?? null;
    $r = keycheck_store()[$id] ?? null;
    if (!$svc || !$svc[1] || !is_array($r) || ($r['fp'] ?? '') !== sha1((string) $svc[2])) return null;
    return $r;
}

/** Clés enregistrées dont le dernier test a échoué : id => [libellé, message, page]. */
function keycheck_problems(): array
{
    $out = [];
    foreach (keycheck_services() as $id => $svc) {
        $r = keycheck_last($id);
        if ($r && empty($r['ok'])) $out[$id] = [$svc[0], (string) $r['message'], $svc[3]];
    }
    return $out;
}

/** Services à (re)tester : clé présente et jamais testée, ou dernier test vieux de plus de 24 h. */
function keycheck_stale(): array
{
    $out = [];
    foreach (keycheck_services() as $id => $svc) {
        if (!$svc[1]) continue;
        $r = keycheck_last($id);
        if (!$r || time() - (int) $r['at'] > KEYCHECK_STALE_SECONDS) $out[] = $id;
    }
    return $out;
}

/** Ligne d'état sous un formulaire de clé. */
function keycheck_status_html(string $id): string
{
    $r = keycheck_last($id);
    if (!$r) return '<p class="hint" style="margin-top:6px;">Clé non testée depuis son enregistrement.</p>';
    $when = date('d/m/Y H:i', (int) $r['at']);
    return '<p class="publish-status" data-kind="' . (!empty($r['ok']) ? '' : 'error') . '" style="margin-top:6px;">'
        . (!empty($r['ok']) ? '✓ ' : '✗ ') . h((string) $r['message']) . ' <small>(test du ' . h($when) . ')</small></p>';
}

/** Bandeau d'alerte du tableau de bord (vide s'il n'y a aucun problème). */
function keycheck_alert_html(): string
{
    $p = keycheck_problems();
    if (!$p) return '';
    $html = '<div class="keycheck-alert" role="alert" style="border:1px solid #b3261e;background:#fdecea;color:#7a1a14;padding:12px 16px;margin-bottom:20px;">'
        . '<strong>' . count($p) . ' clé' . (count($p) > 1 ? 's' : '') . ' d’API à vérifier</strong><ul style="margin:6px 0 0;padding-left:20px;">';
    foreach ($p as [$label, $msg, $url]) {
        $html .= '<li><a href="' . h($url) . '" style="color:inherit;font-weight:600;">' . h($label) . '</a> : ' . h($msg) . '</li>';
    }
    return $html . '</ul></div>';
}
