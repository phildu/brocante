<?php
// Endpoint de l'assistant de shooting (admin/shooting-assistant.php).
//   analyze  → mesure la photo, compte les objets et reconnaît la nature (Gemini + mesures GD)
//   objects  → objets distincts d'une photo, pour en isoler un (assets/object-picker.js)
//   add_type → ajoute un type de produit à la liste (autodécouverte)
//   enhance  → retouche IA de l'exposition, du contraste et de la netteté (Gemini image)
//   validate → corrections dictées de la fiche générée (couleurs, état, prix du neuf, prix, nom, poids…)
// La création et la génération de la fiche passent ensuite par admin/quick-add-action.php.
// Réponses JSON : {ok: true, ...} ou {ok: false, error: "..."}.

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shooting-assistant.php';

header('Content-Type: application/json; charset=utf-8');

function sa_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) {
    sa_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sa_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}
@set_time_limit(120);

$root = realpath(__DIR__ . '/..');
$action = (string) ($_POST['action'] ?? '');

/**
 * Teste une vraie génération (l'admin « Tester la clé » ne vérifie que la
 * liste des modèles, qui répond même quand le crédit de génération est épuisé).
 * Retourne [statut HTTP, raison lisible].
 */
function sa_gemini_probe(): array
{
    $model = function_exists('gemini_text_model') ? gemini_text_model() : 'gemini-3.8-flash';
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['parts' => [['text' => 'ping']]]]]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status === 200) return [200, 'la génération répond'];
    $reason = match (true) {
        $status === 402 || ($body !== false && str_contains((string) $body, 'prepayment')) => 'crédit de génération épuisé',
        $status === 429 => 'quota de génération atteint',
        $status === 403 => 'clé refusée pour la génération',
        $status === 0 => 'pas de réponse réseau',
        default => 'erreur HTTP ' . $status,
    };
    return [$status, $reason];
}

/** Fiche renvoyée à la validation vocale : les champs de « get » utiles aux questions, plus l'état lisible. */
function sa_product_payload(array $product): array
{
    $condition = product_condition((string) ($product['etat'] ?? ''));
    return [
        'ref' => $product['ref'],
        'name' => $product['name'],
        'price' => $product['price'],
        'new_price' => (string) ($product['new_price'] ?? ''),
        'cat' => $product['cat'],
        'description' => $product['description'],
        'materials' => (string) $product['materials'],
        'colors' => (string) ($product['colors'] ?? ''),
        'brand' => (string) ($product['brand'] ?? ''),
        'distributor' => (string) ($product['distributor'] ?? ''),
        'etat' => (string) ($product['etat'] ?? ''),
        'etat_label' => $condition[0] ?? '',
        'nature' => (string) ($product['nature'] ?? ''),
        'sous_categorie' => (string) ($product['sous_categorie'] ?? ''),
        'badge' => $product['badge'],
        'weight_grams' => (int) $product['weight_grams'],
        'size_text' => (string) ($product['size_text'] ?? ''),
        'barcode' => (string) ($product['barcode'] ?? ''),
        'hidden' => (bool) $product['is_hidden'],
    ];
}

/** Photo reçue, validée comme image lisible. */
function sa_photo(): array
{
    $file = $_FILES['photo'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || !@getimagesize($file['tmp_name'])) {
        sa_reply(['ok' => false, 'error' => 'Photo non reçue ou illisible.'], 400);
    }
    if ($file['size'] > 12 * 1048576) {
        sa_reply(['ok' => false, 'error' => 'Photo trop lourde (12 Mo au plus).'], 413);
    }
    return $file;
}

switch ($action) {
    case 'objects': {
        $file = sa_photo();
        if (!GEMINI_API_KEY) sa_reply(['ok' => true, 'objects' => [], 'ai' => false]);
        sa_reply(['ok' => true, 'objects' => studio_detect_objects($file['tmp_name']), 'ai' => true]);
    }

    case 'analyze': {
        $file = sa_photo();
        $manual = (string) ($_POST['kind'] ?? 'auto');
        if (!array_key_exists($manual, shooting_manual_program_map()) && $manual !== 'auto') $manual = 'auto';

        $metrics = shooting_photo_metrics($file['tmp_name']);
        $ai = null;
        $aiError = '';
        $rawText = '';
        $gate = GEMINI_API_KEY ? ai_gate() : null;
        if (!GEMINI_API_KEY) {
            $aiError = 'Clé Gemini non configurée : analyse locale seulement.';
        } elseif ($gate !== null) {
            $aiError = $gate;
        } else {
            $small = downscale_for_ai($file['tmp_name'], 1024, 82);
            if ($small) {
                $text = gemini_describe_image($small, shooting_analysis_prompt(), 1);
                if ($small !== $file['tmp_name']) @unlink($small);
                if ($text) {
                    $rawText = $text;
                    $ai = shooting_parse_analysis($text);
                }
            }
            if ($ai === null) {
                if ($rawText === '') {
                    $probe = sa_gemini_probe();
                    $probeNote = $probe[0] === 200 ? '' : ' — Gemini : ' . $probe[1];
                    $aiError = "L'analyse IA n'a pas répondu" . $probeNote . '. Les mesures locales sont affichées.';
                } else {
                    $aiError = "La réponse de l'IA n'a pas pu être lue : relancez l'analyse.";
                }
            }
        }

        $analysis = shooting_merge_analysis($ai, $metrics, $manual);
        $program = shooting_program_for($analysis['subject']['nature'], $manual);

        // Trace locale (var/log, jamais servi) : réponse brute et nature retenue,
        // pour diagnostiquer une reconnaissance refusée sans exposer la clé.
        $logDir = $root . '/var/log';
        if ((!is_dir($logDir) && @mkdir($logDir, 0755, true)) || is_dir($logDir)) {
            @file_put_contents($logDir . '/assistant-' . gmdate('Y-m-d') . '.log',
                gmdate('c') . ' analyze ai=' . ($ai !== null ? 'ok' : 'none')
                . ' count=' . $analysis['count'] . ' objects=' . count($analysis['objects'])
                . ' nature=' . ($analysis['subject']['nature'] ?: '-')
                . '/' . ($analysis['subject']['sous_categorie'] ?: '-')
                . ' raw_nature=' . ($analysis['subject']['nature_raw'] ?: '-')
                . ' err=' . ($aiError ?: '-')
                . ' raw=' . mb_substr(preg_replace('/\s+/', ' ', $rawText), 0, 300) . PHP_EOL,
                FILE_APPEND | LOCK_EX);
        }

        sa_reply([
            'ok' => true,
            'ai' => $ai !== null,
            'ai_error' => $aiError,
            'metrics' => $metrics,
            'analysis' => $analysis,
            'program' => $program,
            'manual' => $manual,
        ]);
    }

    // Autodécouverte : le vendeur accepte d'ajouter un type absent de la liste.
    case 'add_type': {
        $label = trim((string) ($_POST['label'] ?? ''));
        if (mb_strlen($label) < 2) sa_reply(['ok' => false, 'error' => 'Nom de type trop court.'], 422);
        [$key, $name] = shooting_custom_type_add($label);
        sa_reply(['ok' => true, 'key' => $key, 'label' => $name, 'types' => shooting_type_choices()]);
    }

    case 'enhance': {
        $file = sa_photo();
        if (!GEMINI_API_KEY) sa_reply(['ok' => false, 'error' => 'Clé Gemini non configurée : utilisez la correction automatique.'], 503);
        if ($gate = ai_gate()) sa_reply(['ok' => false, 'quota' => true, 'error' => $gate]);

        $small = downscale_for_ai($file['tmp_name'], 1600, 90);
        $source = $small ?: $file['tmp_name'];
        $bytes = gemini_generate_image($source, "Retouche cette photo de produit pour une boutique de seconde main. "
            . "Corrige UNIQUEMENT l'exposition, le contraste, la balance des blancs et la netteté. "
            . "Ne change ni la forme, ni la couleur, ni la matière, ni les défauts de l'objet. "
            . "Garde exactement le même cadrage et le même fond. Rends une photo naturelle, nette et prête à la vente.", 1);
        if ($small && $small !== $file['tmp_name']) @unlink($small);
        if (!$bytes) sa_reply(['ok' => false, 'error' => "L'amélioration IA a échoué. Essayez la correction automatique, ou reprenez la photo."]);

        $img = @imagecreatefromstring($bytes);
        if (!$img) sa_reply(['ok' => false, 'error' => "L'image retouchée n'a pas pu être lue."]);
        $uploadsDir = $root . '/uploads';
        if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
        $name = 'shooting-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jpg';
        if (!@imagejpeg($img, $uploadsDir . '/' . $name, 90)) {
            imagedestroy($img);
            sa_reply(['ok' => false, 'error' => "L'image retouchée n'a pas pu être enregistrée."]);
        }
        imagedestroy($img);
        sa_reply(['ok' => true, 'path' => 'uploads/' . $name, 'url' => '/uploads/' . $name]);
    }

    // Validation vocale de la fiche générée : seules les réponses corrigées arrivent ici (« oui » n'envoie
    // rien). Chaque champ garde le normaliseur de l'enregistrement (admin/quick-add-action.php), et une
    // valeur vide efface un champ facultatif que le vendeur a refusé (couleurs, état, prix du neuf).
    case 'validate': {
        $ref = (string) ($_POST['ref'] ?? '');
        $product = $ref !== '' ? get_product($ref) : null;
        if (!$product) sa_reply(['ok' => false, 'error' => 'Fiche introuvable.'], 404);

        $set = [];
        $params = [];
        $changes = [];

        if (isset($_POST['name'])) {
            $name = mb_substr(trim((string) $_POST['name']), 0, 120);
            if ($name !== '' && $name !== (string) $product['name']) {
                $set[] = 'name = ?'; $params[] = $name; $changes[] = 'name';
            }
        }
        if (isset($_POST['description'])) {
            $description = mb_substr(trim((string) $_POST['description']), 0, 1200);
            if ($description !== '' && $description !== (string) $product['description']) {
                $set[] = 'description = ?'; $params[] = $description; $changes[] = 'description';
            }
        }
        if (isset($_POST['colors'])) {
            $colors = product_colors_clean((string) $_POST['colors']);
            if ($colors !== (string) ($product['colors'] ?? '')) {
                $set[] = 'colors = ?'; $params[] = $colors; $changes[] = 'colors';
            }
        }
        if (isset($_POST['etat'])) {
            $posted = trim((string) $_POST['etat']);
            $etat = product_condition_key($posted);
            if ($etat === '' && $posted !== '') sa_reply(['ok' => false, 'error' => 'État non reconnu.'], 422);
            if ($etat !== (string) ($product['etat'] ?? '')) {
                $set[] = 'etat = ?'; $params[] = $etat !== '' ? $etat : null; $changes[] = 'etat';
            }
        }
        if (isset($_POST['new_price'])) {
            $newPrice = price_from_input((string) $_POST['new_price']) ?: null;
            if ($newPrice !== ($product['new_price'] ?? null)) {
                $set[] = 'new_price = ?'; $params[] = $newPrice; $changes[] = 'new_price';
            }
        }
        // Le prix affiché n'est jamais recalculé : une correction reste la décision du vendeur.
        if (isset($_POST['price']) && trim((string) $_POST['price']) !== '') {
            $price = price_from_input((string) $_POST['price'], (string) $product['price']);
            if ($price !== (string) $product['price']) {
                $set[] = 'price = ?'; $params[] = $price; $changes[] = 'price';
            }
        }
        if (isset($_POST['weight_grams'])) {
            $grams = max(0, (int) $_POST['weight_grams']);
            if ($grams !== (int) $product['weight_grams']) {
                $set[] = 'weight_grams = ?'; $params[] = $grams;
                $set[] = 'weight_text = ?'; $params[] = product_weight_text($grams);
                $changes[] = 'weight_grams';
            }
        }

        if ($set) {
            $params[] = (string) $product['ref'];
            db()->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE ref = ?')->execute($params);
            $product = get_product($product['ref']) ?: $product;
        }
        sa_reply(['ok' => true, 'changes' => $changes, 'product' => sa_product_payload($product)]);
    }
}

sa_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
