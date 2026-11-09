<?php
// Vente express en caisse : une pièce qui n'est pas au catalogue, au prix convenu sur place.
//   photo prise en caisse → fiche masquée créée avec nom et description rédigés par l'IA → prix saisi → encaissement
//   → la pièce passe « vendue sur le stand », la vente entre au journal des ventes et le gérant est prévenu
//   quand la vente vient d'un autre vendeur que lui.

const POS_EXPRESS_BADGE = 'Vente express';

/** Prix unique du catalogue en centimes, ou null s'il n'y en a pas (vide, 0 €, fourchette, « dès », « sur devis ») : le prix se négocie alors sur place. */
function pos_catalog_cents(array $p): ?int
{
    $price = (string) effective_price($p);
    if (!is_fixed_price($price) || preg_match('/\d\s*[-–—]\s*\d/u', $price)) return null;
    $cents = price_to_cents($price);
    return $cents !== null && $cents > 0 ? $cents : null;
}

/** Mode d'encaissement d'une commande : 'especes', 'carte' (caisse : QR Stripe ou terminal) ou 'en_ligne'. */
function pos_pay_mode(array $order): string
{
    $method = mb_strtolower((string) ($order['shipping_method'] ?? ''));
    if (!str_starts_with($method, 'vente en boutique')) return 'en_ligne';
    return str_contains($method, 'espèces') || str_contains($method, 'especes') ? 'especes' : 'carte';
}

function pos_pay_mode_label(string $mode): string
{
    return ['especes' => 'Espèces', 'carte' => 'Carte (caisse)', 'en_ligne' => 'En ligne'][$mode] ?? 'En ligne';
}

/** Vendeur connecté à la caisse → [nom, identifiant du compte ou null, 1 si le gérant doit être prévenu]. Le compte administrateur est le gérant. */
function pos_seller_fields(?array $account): array
{
    if (!$account) return [null, null, 0];
    $name = mb_substr(trim((string) ($account['name'] ?? '')), 0, 80);
    if (($account['id'] ?? null) === null) return [$name !== '' ? $name : 'Gérant', null, 0];
    return [$name !== '' ? $name : 'Vendeur', (int) $account['id'], ($account['role'] ?? '') === 'admin' ? 0 : 1];
}

// ── Fiche de la pièce ───────────────────────────────────────────────────────

/**
 * Photo prise en caisse → fiche masquée (badge « Vente express ») avec nom et description proposés par l'IA.
 * -> ['ok' => true, 'ref', 'name', 'description', 'photo', 'ai' => bool] ou ['ok' => false, 'error'].
 * Sans IA disponible (clé absente, quota épuisé, échec), la fiche est créée quand même : le vendeur saisit le nom.
 */
function pos_express_create(array $file, string $notes = ''): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !@getimagesize((string) ($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'Photo non reçue ou format non reconnu.'];
    }
    if (!shop_can_add_items()) return ['ok' => false, 'error' => shop_limit_message()];

    $insert = db()->prepare('INSERT INTO products (ref, name, cat, description, price, badge, is_hidden, featured, sort_order, negotiable)
                             VALUES (:ref, :name, :cat, :description, :price, :badge, 1, 0, :sort_order, 1)');
    $ref = '';
    for ($try = 0; $try < 3 && $ref === ''; $try++) {
        $candidate = next_ref();
        try {
            $insert->execute([
                'ref' => $candidate, 'name' => 'Pièce vendue en caisse', 'cat' => default_category_key(),
                'description' => 'Description à compléter.', 'price' => '0 €', 'badge' => POS_EXPRESS_BADGE,
                'sort_order' => (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn(),
            ]);
            $ref = $candidate;
        } catch (PDOException $e) {
            // Référence prise entre-temps par un autre vendeur : on retente avec la suivante.
        }
    }
    if ($ref === '') return ['ok' => false, 'error' => 'Création de la fiche impossible, réessayez.'];

    $path = copy_photo_into_uploads((string) $file['tmp_name'], 'product-' . $ref);
    if (!$path) {
        db()->prepare('DELETE FROM products WHERE ref = ?')->execute([$ref]);
        return ['ok' => false, 'error' => 'La photo n\'a pas pu être enregistrée (format non reconnu ?).'];
    }
    add_product_photo($ref, $path, 'Photo principale', false);

    $out = ['ok' => true, 'ref' => $ref, 'name' => '', 'description' => '', 'photo' => '/' . ltrim($path, '/'), 'ai' => false, 'hint' => ''];
    $sheet = pos_express_sheet($ref, dirname(__DIR__) . '/' . ltrim($path, '/'), $notes);
    if ($sheet) {
        db()->prepare('UPDATE products SET name = ?, description = ?, cat = ?, materials = ?, colors = ?, brand = ?, etat = ?, nature = ?, sous_categorie = ? WHERE ref = ?')
            ->execute([$sheet['name'], $sheet['description'] ?: 'Description à compléter.', $sheet['category'], $sheet['materials'], $sheet['colors'] ?? '', $sheet['brand'] ?? '',
                $sheet['etat'] ?: null, $sheet['nature'] ?: null, $sheet['sous_categorie'] ?: null, $ref]);
        $out['name'] = $sheet['name'];
        $out['description'] = $sheet['description'];
        $out['hint'] = (string) ($sheet['price_hint'] ?? '');   // fourchette indicative de l'IA, montrée à l'expert (jamais enregistrée comme prix)
        $out['ai'] = true;
    }
    return $out;
}

/** Nom, description et catégorie rédigés par l'IA d'après la photo, ou null (IA indisponible ou réponse inexploitable). */
function pos_express_sheet(string $ref, string $absPath, string $notes = ''): ?array
{
    try {
        if (ai_gate() !== null) return null;
        ai_usage_context($ref);
        $small = downscale_for_ai($absPath, 900) ?? $absPath;
        $hint = trim('Pièce photographiée en boutique au moment de la vente, le prix est négocié sur place : n\'en donne pas. ' . $notes);
        $text = gemini_describe_image($small, build_product_sheet_prompt(1, $hint), 1);
        if ($small !== $absPath && str_starts_with($small, sys_get_temp_dir())) @unlink($small);
        return $text ? parse_product_sheet_response($text) : null;
    } catch (Throwable $e) {
        error_log('Vente express, description par l\'IA : ' . $e->getMessage());
        return null;
    }
}

/** Une pièce créée par la vente express, encore invendue et masquée ? (seules celles-là se modifient ou se jettent depuis la caisse) */
function pos_express_product(string $ref): ?array
{
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', $ref);
    $p = $ref !== '' ? get_product($ref) : null;
    return $p && ($p['badge'] ?? '') === POS_EXPRESS_BADGE && (int) $p['is_hidden'] === 1 && (int) $p['stock'] > 0 ? $p : null;
}

/** Nom et description corrigés par le vendeur avant l'encaissement. */
function pos_express_save(string $ref, string $name, string $description): ?string
{
    $p = pos_express_product($ref);
    if (!$p) return 'Pièce introuvable ou déjà vendue.';
    $name = mb_substr(trim($name), 0, 120);
    if ($name === '') return 'Donnez un nom à la pièce.';
    $description = mb_substr(trim($description), 0, 2000);
    db()->prepare('UPDATE products SET name = ?, description = ? WHERE ref = ?')->execute([$name, $description !== '' ? $description : 'Description à compléter.', $p['ref']]);
    return null;
}

/** Vente abandonnée : la fiche express et ses photos sont supprimées (jamais une pièce du catalogue, ni une pièce déjà dans une commande). */
function pos_express_discard(string $ref): bool
{
    $p = pos_express_product($ref);
    if (!$p) return false;
    $used = db()->prepare("SELECT 1 FROM orders WHERE items LIKE ? LIMIT 1");
    $used->execute(['%"ref":' . json_encode((string) $p['ref']) . '%']);
    if ($used->fetchColumn()) return false;
    try {
        db()->prepare('DELETE FROM product_photos WHERE product_ref = ?')->execute([$p['ref']]);
    } catch (Throwable $e) {
        // Table ou colonne différente selon l'ancienneté de la base : la fiche part quand même.
    }
    db()->prepare('DELETE FROM products WHERE ref = ?')->execute([$p['ref']]);
    return true;
}

/** Vente encaissée : le prix convenu devient le prix de la fiche express (elle reste masquée, vendue sur le stand). */
function pos_express_sold(array $order): void
{
    foreach (json_decode((string) $order['items'], true) ?: [] as $l) {
        if (empty($l['express'])) continue;
        $euros = ((int) ($l['unit_cents'] ?? 0)) / 100;
        db()->prepare('UPDATE products SET price = ? WHERE ref = ? AND badge = ?')->execute([price_from_input((string) $euros, '0 €'), $l['ref'], POS_EXPRESS_BADGE]);
    }
}

// ── Avis de l'expert ────────────────────────────────────────────────────────
// Un vendeur occasionnel qui ne sait pas évaluer une pièce l'envoie à l'expert (le gérant) : photo, nom, suggestion de l'IA. Le gérant répond
// par un prix et un mot depuis le lien reçu ; le vendeur voit la réponse dans sa caisse et conclut la vente (il peut encore négocier).

function pos_appraisal_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS pos_appraisals (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      ref TEXT NOT NULL,
      token TEXT NOT NULL UNIQUE,
      seller_name TEXT NOT NULL DEFAULT '',
      seller_id INTEGER,
      note TEXT NOT NULL DEFAULT '',
      ai_hint TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL DEFAULT 'pending',
      answer_cents INTEGER,
      answer_note TEXT NOT NULL DEFAULT '',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      answered_at TEXT
    )");
    db()->exec('CREATE INDEX IF NOT EXISTS idx_pos_appraisals_ref ON pos_appraisals(ref)');
    $done = true;
}

function pos_appraisal_by_ref(string $ref): ?array
{
    pos_appraisal_ensure_schema();
    $st = db()->prepare('SELECT * FROM pos_appraisals WHERE ref = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$ref]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pos_appraisal_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    pos_appraisal_ensure_schema();
    $st = db()->prepare('SELECT * FROM pos_appraisals WHERE token = ?');
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Adresse de la page où l'expert répond. */
function pos_appraisal_url(array $a): string
{
    require_once __DIR__ . '/share.php';
    return shop_abs_url('/avis-expertise.php?t=' . $a['token']);
}

/** [objet, texte] du message à l'expert. */
function pos_appraisal_message(array $a, array $product, string $answerUrl, string $photoUrl): array
{
    $seller = trim((string) ($a['seller_name'] ?? '')) ?: 'Un vendeur';
    $name = trim((string) ($product['name'] ?? '')) ?: 'une pièce';
    $body = $seller . ' vous demande d\'estimer une pièce avant de la vendre.' . "\n\n"
        . 'Pièce : ' . $name . "\n"
        . (trim((string) ($product['description'] ?? '')) !== '' ? 'Description : ' . $product['description'] . "\n" : '')
        . (trim((string) ($a['ai_hint'] ?? '')) !== '' ? 'Suggestion automatique (à titre indicatif) : ' . $a['ai_hint'] . "\n" : '')
        . (trim((string) ($a['note'] ?? '')) !== '' ? 'Mot du vendeur : ' . $a['note'] . "\n" : '')
        . ($photoUrl !== '' ? "\nPhoto : " . $photoUrl . "\n" : '')
        . "\nRépondez avec votre prix depuis ce lien : " . $answerUrl . "\n"
        . "Le vendeur attend votre réponse pour conclure la vente.\n";
    return ['Avis demandé par ' . $seller . ' — ' . $name, $body];
}

/**
 * Le vendeur demande l'avis de l'expert sur une pièce express. -> ['ok' => true, 'sent' => bool] ou ['ok' => false, 'error'].
 * Une seule demande en attente par pièce : une nouvelle demande met à jour le mot du vendeur et ne renvoie pas de message.
 */
function pos_appraisal_request(string $ref, string $note, string $hint, ?array $account): array
{
    $p = pos_express_product($ref);
    if (!$p) return ['ok' => false, 'error' => 'Pièce introuvable ou déjà vendue.'];
    $emails = pos_manager_emails();
    if (!$emails) return ['ok' => false, 'error' => 'Aucune adresse de gérant : renseignez l\'e-mail d\'un administrateur.'];
    $note = mb_substr(trim($note), 0, 500);
    $hint = mb_substr(trim($hint), 0, 60);
    $existing = pos_appraisal_by_ref($p['ref']);
    if ($existing && $existing['status'] === 'pending') {
        db()->prepare('UPDATE pos_appraisals SET note = ? WHERE id = ?')->execute([$note, $existing['id']]);
        return ['ok' => true, 'sent' => false];
    }
    [$sellerName, $sellerId] = pos_seller_fields($account);
    db()->prepare('INSERT INTO pos_appraisals (ref, token, seller_name, seller_id, note, ai_hint) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$p['ref'], bin2hex(random_bytes(16)), (string) $sellerName, $sellerId, $note, $hint]);
    $a = pos_appraisal_by_ref($p['ref']);
    $sent = false;
    try {
        require_once __DIR__ . '/password-reset.php';
        require_once __DIR__ . '/share.php';
        $photo = !empty($p['photo']) ? shop_abs_url('/' . ltrim((string) $p['photo'], '/')) : '';
        [$subject, $body] = pos_appraisal_message($a, $p, pos_appraisal_url($a), $photo);
        foreach ($emails as $to) {
            if (password_reset_mail($to, $subject, $body, 'caisse', 'avis-' . $a['id'])) $sent = true;
        }
    } catch (Throwable $e) {
        error_log('Caisse, demande d\'avis : ' . $e->getMessage());
    }
    return ['ok' => true, 'sent' => $sent];
}

/** L'expert répond : prix en centimes et mot. -> message d'erreur, ou null. */
function pos_appraisal_answer(string $token, int $cents, string $comment): ?string
{
    $a = pos_appraisal_by_token($token);
    if (!$a) return 'Lien inconnu ou expiré.';
    if (!pos_express_product((string) $a['ref'])) return 'Cette pièce n\'est plus à vendre : la demande est close.';
    if ($cents <= 0 || $cents > POS_MAX_CENTS) return 'Indiquez un prix en euros.';
    db()->prepare("UPDATE pos_appraisals SET status = 'answered', answer_cents = ?, answer_note = ?, answered_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$cents, mb_substr(trim($comment), 0, 500), $a['id']]);
    return null;
}

/** État de la demande d'une pièce, pour la caisse du vendeur : none, pending ou answered (avec prix et mot). */
function pos_appraisal_status(string $ref): array
{
    $a = pos_appraisal_by_ref(preg_replace('/[^A-Za-z0-9_-]/', '', $ref));
    if (!$a) return ['status' => 'none'];
    if ($a['status'] !== 'answered') return ['status' => 'pending'];
    return ['status' => 'answered', 'price_cents' => (int) $a['answer_cents'], 'comment' => (string) $a['answer_note']];
}

// ── Avertissement du gérant ─────────────────────────────────────────────────

/** Adresses du gérant : administrateurs actifs et e-mail de contact de la boutique. */
function pos_manager_emails(): array
{
    $out = pricing_admin_emails();
    $contact = trim((string) tenant('admin_email', ''));
    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) $out[] = $contact;
    $seen = [];
    foreach ($out as $e) $seen[mb_strtolower($e)] = $e;
    return array_values($seen);
}

/** [objet, texte] du message au gérant pour une vente en caisse faite par un autre vendeur. */
function pos_manager_message(array $order, string $siteName = ''): array
{
    $eur = static fn (int $c): string => number_format($c / 100, 2, ',', ' ') . ' €';
    $seller = trim((string) ($order['sold_by'] ?? '')) ?: 'Un vendeur';
    $lines = json_decode((string) ($order['items'] ?? '[]'), true) ?: [];
    $total = (int) ($order['amount_total'] ?? 0);
    $first = (string) ($lines[0]['name'] ?? 'vente en caisse');
    $subject = 'Vente de ' . $eur($total) . ' par ' . $seller . ' — ' . $first . (count($lines) > 1 ? ' (+' . (count($lines) - 1) . ')' : '');
    $body = $seller . ' vient d\'enregistrer une vente en caisse' . ($siteName !== '' ? ' (' . $siteName . ')' : '') . ".\n\n";
    foreach ($lines as $l) {
        $tag = !empty($l['express']) ? ' · pièce hors catalogue' : '';
        $tag .= !empty($l['negotiated']) ? ' · prix convenu sur place' : '';
        $body .= '• ' . ($l['name'] ?? '') . ' — ' . $eur((int) ($l['unit_cents'] ?? 0)) . $tag . "\n";
    }
    $body .= "\nTotal : " . $eur($total) . ' · ' . mb_strtolower(pos_pay_mode_label(pos_pay_mode($order))) . "\n";
    $body .= 'Vente n° ' . (int) ($order['id'] ?? 0) . ' — elle figure au journal des ventes.' . "\n";
    return [$subject, $body];
}

/**
 * Prévient le gérant d'une vente faite par un autre vendeur (une seule fois par vente, même si plusieurs requêtes finalisent la commande).
 * -> true si un message est parti.
 */
function pos_notify_manager(int $orderId): bool
{
    $st = db()->prepare("SELECT * FROM orders WHERE id = ? AND status = 'paid' AND notify_manager = 1 AND manager_notified_at IS NULL");
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) return false;
    $claim = db()->prepare('UPDATE orders SET manager_notified_at = CURRENT_TIMESTAMP WHERE id = ? AND manager_notified_at IS NULL');
    $claim->execute([$orderId]);
    if (!$claim->rowCount()) return false;   // une autre requête s'en charge
    $sent = false;
    try {
        require_once __DIR__ . '/password-reset.php';
        [$subject, $body] = pos_manager_message($order, (string) (get_content()['site_name'] ?? tenant('name')));
        foreach (pos_manager_emails() as $to) {
            if (password_reset_mail($to, $subject, $body, 'caisse', 'vente-' . $orderId)) $sent = true;
        }
    } catch (Throwable $e) {
        error_log('Caisse, avertissement du gérant : ' . $e->getMessage());
    }
    if (!$sent) db()->prepare('UPDATE orders SET manager_notified_at = NULL WHERE id = ?')->execute([$orderId]);   // à retenter à la prochaine lecture de la vente
    return $sent;
}
