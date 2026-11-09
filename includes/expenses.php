<?php

// Journal des dépenses d'une boutique : achats et tickets, factures fournisseurs (à payer / payées) et notes de frais des
// collaborateurs (brouillon → soumise → validée → remboursée). Page : admin/depenses.php ; actions : admin/depenses-action.php.
//
// Règles de calcul (à garder en tête avant de toucher aux totaux) :
//   - montants en centimes, toujours TTC ; la TVA est extraite du TTC (taux saisi), le HT en découle ;
//   - une ligne de note de frais ne compte qu'une fois sa note VALIDÉE (ou remboursée) : brouillon, soumise et refusée sont ignorées ;
//   - une facture compte à sa date de facture, qu'elle soit payée ou non ; le reste à payer est suivi à part ;
//   - ce journal ne remplace pas les frais récurrents de « Rentabilité » (shop_costs) : il garde la trace réelle des pièces comptables.
// Les justificatifs (photo ou PDF) vivent dans data/depenses/<commerce>/ (jamais servi directement) : admin/depense-fichier.php les relit.

const EXPENSE_MAX_CENTS = 100000000;          // 1 000 000,00 € : garde-fou contre une faute de frappe
const EXPENSE_MAX_RECEIPT = 8 * 1024 * 1024;  // justificatif : 8 Mo

function expense_ensure_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS expense_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  person TEXT NOT NULL,
  title TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'brouillon',   -- brouillon | soumise | validee | refusee | remboursee
  note TEXT NOT NULL DEFAULT '',
  decision_note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at TEXT,
  decided_at TEXT,
  reimbursed_at TEXT
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS expenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL DEFAULT 'depense',       -- depense (ticket, achat) | facture (fournisseur) | frais (ligne d'une note de frais)
  spent_on TEXT NOT NULL,                     -- AAAA-MM-JJ : date de la dépense ou de la facture
  label TEXT NOT NULL,
  supplier TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT 'autre',
  amount_cents INTEGER NOT NULL DEFAULT 0,    -- TTC
  vat_rate REAL NOT NULL DEFAULT 0,           -- % : 0, 2.1, 5.5, 10, 20
  vat_cents INTEGER NOT NULL DEFAULT 0,
  payment_method TEXT NOT NULL DEFAULT '',
  invoice_no TEXT NOT NULL DEFAULT '',
  due_on TEXT,                                -- échéance d'une facture
  paid_on TEXT,                               -- NULL = pas encore payée (factures)
  report_id INTEGER,                          -- note de frais d'appartenance (kind = frais)
  receipt TEXT NOT NULL DEFAULT '',           -- nom du justificatif dans data/depenses/<commerce>/
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_expenses_spent_on ON expenses(spent_on)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_expenses_report ON expenses(report_id)');
}

/* ------------------------------------------------------------------ */
/* Listes de choix                                                     */
/* ------------------------------------------------------------------ */

function expense_categories(): array
{
    return [
        'marchandises' => 'Achat de pièces (marchandises)',
        'fournitures' => 'Fournitures & emballages',
        'expedition' => 'Expédition & affranchissement',
        'hebergement' => 'Hébergement & domaine',
        'logiciel' => 'Logiciels & abonnements',
        'ia' => 'IA & outils photo',
        'paiement' => 'Frais de paiement',
        'marketing' => 'Communication & marketing',
        'local' => 'Local, stand, emplacement',
        'deplacement' => 'Déplacements & carburant',
        'repas' => 'Repas & réceptions',
        'honoraires' => 'Honoraires & comptabilité',
        'autre' => 'Autre',
    ];
}

function expense_kinds(): array
{
    return ['depense' => 'Dépense (ticket, achat)', 'facture' => 'Facture fournisseur', 'frais' => 'Note de frais'];
}

function expense_methods(): array
{
    return ['' => 'Non précisé', 'carte' => 'Carte bancaire', 'virement' => 'Virement', 'prelevement' => 'Prélèvement', 'especes' => 'Espèces', 'cheque' => 'Chèque', 'perso' => 'Avancé par un collaborateur'];
}

function expense_vat_rates(): array
{
    // Clés en texte : une clé flottante serait tronquée par PHP (5.5 deviendrait 5).
    return ['0' => 'Sans TVA', '2.1' => '2,1 %', '5.5' => '5,5 %', '10' => '10 %', '20' => '20 %'];
}

function expense_report_statuses(): array
{
    return ['brouillon' => 'Brouillon', 'soumise' => 'À valider', 'validee' => 'Validée, à rembourser', 'refusee' => 'Refusée', 'remboursee' => 'Remboursée'];
}

/* ------------------------------------------------------------------ */
/* Saisie : conversions et calculs                                     */
/* ------------------------------------------------------------------ */

/** « 12,5 » / « 1 250,50 € » → 1250 ; null si vide, négatif, invalide ou au-delà du garde-fou. */
function expense_amount_to_cents(?string $s): ?int
{
    $s = str_replace([' ', "\xc2\xa0", "\xe2\x80\xaf", '€'], '', trim((string) $s));
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
    $cents = (int) round((float) $s * 100);
    return $cents > EXPENSE_MAX_CENTS ? null : $cents;
}

/** Date AAAA-MM-JJ réelle, sinon null. */
function expense_valid_date(?string $d): ?string
{
    $d = trim((string) $d);
    if ($d === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

/** TVA contenue dans un montant TTC, en centimes (arrondie au centime). */
function expense_vat_from_ttc(int $ttcCents, float $ratePct): int
{
    if ($ratePct <= 0 || $ttcCents <= 0) return 0;
    return (int) round($ttcCents - $ttcCents / (1 + $ratePct / 100));
}

function expense_normalize_vat_rate($rate): float
{
    $rate = (float) str_replace(',', '.', (string) $rate);
    foreach (array_keys(expense_vat_rates()) as $allowed) {
        if (abs($rate - (float) $allowed) < 0.001) return (float) $allowed;
    }
    return 0.0;
}

/** Facture non payée dont l'échéance est dépassée. */
function expense_is_overdue(array $row, string $today): bool
{
    return ($row['kind'] ?? '') === 'facture' && empty($row['paid_on']) && !empty($row['due_on']) && (string) $row['due_on'] < $today;
}

/**
 * Période de consultation : « AAAA-MM » (un mois), « AAAA » (une année) ou « tout ».
 * Renvoie [début inclus, fin exclue, libellé] (dates AAAA-MM-JJ ; début et fin vides pour « tout »), ou le mois courant si la valeur est invalide.
 */
function expense_period(string $p, DateTimeImmutable $now): array
{
    $months = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    if ($p === 'tout') return ['', '', 'Depuis le début'];
    if (preg_match('/^(\d{4})$/', $p, $m) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100) {
        return [$m[1] . '-01-01', ((int) $m[1] + 1) . '-01-01', $m[1]];
    }
    if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $p, $m) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100) {
        $start = new DateTimeImmutable($m[1] . '-' . $m[2] . '-01');
        return [$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d'), $months[(int) $m[2]] . ' ' . $m[1]];
    }
    return expense_period($now->format('Y-m'), $now);
}

/* ------------------------------------------------------------------ */
/* Justificatifs                                                       */
/* ------------------------------------------------------------------ */

function expense_receipt_dir(): string
{
    $slug = function_exists('tenant_slug') ? tenant_slug() : 'local';
    return dirname(__DIR__) . '/data/depenses/' . preg_replace('/[^a-z0-9_-]/i', '', $slug);
}

/** Chemin sûr d'un justificatif (nom strictement contrôlé), ou '' si le nom est invalide. */
function expense_receipt_path(string $name): string
{
    if (!preg_match('/^d[0-9a-f]{12}\.(?:jpg|png|webp|pdf)$/', $name)) return '';
    return expense_receipt_dir() . '/' . $name;
}

/** Type d'un fichier d'après son contenu (jamais d'après son nom) : extension ou null. */
function expense_receipt_ext(string $tmp): ?string
{
    $head = (string) @file_get_contents($tmp, false, null, 0, 8);
    if (str_starts_with($head, '%PDF')) return 'pdf';
    $info = @getimagesize($tmp);
    return $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null) : null;
}

/** Enregistre le justificatif envoyé. Renvoie son nom, '' si aucun fichier ; lève InvalidArgumentException sur un fichier refusé. */
function expense_receipt_store(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            ? 'Le justificatif est trop lourd (8 Mo maximum).' : "Le justificatif n'a pas pu être envoyé.");
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > EXPENSE_MAX_RECEIPT) throw new InvalidArgumentException('Le justificatif dépasse 8 Mo.');
    $tmp = (string) ($file['tmp_name'] ?? '');
    $ext = expense_receipt_ext($tmp);
    if ($ext === null) throw new InvalidArgumentException('Justificatif non reconnu : photo JPEG, PNG, WebP ou PDF.');
    $dir = expense_receipt_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new RuntimeException("Impossible d'enregistrer le justificatif.");
    $name = 'd' . bin2hex(random_bytes(6)) . '.' . $ext;
    $ok = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dir . '/' . $name) : @copy($tmp, $dir . '/' . $name);
    if (!$ok) throw new RuntimeException("Impossible d'enregistrer le justificatif.");
    @chmod($dir . '/' . $name, 0640);
    return $name;
}

function expense_receipt_delete(string $name): void
{
    $path = expense_receipt_path($name);
    if ($path !== '' && is_file($path)) @unlink($path);
}

/* ------------------------------------------------------------------ */
/* Dépenses et factures                                                */
/* ------------------------------------------------------------------ */

/**
 * Valide et enregistre une ligne du journal (création si $id = 0, sinon mise à jour).
 * $in : kind, spent_on, label, supplier, category, amount, vat_rate, payment_method, invoice_no, due_on, paid_on, report_id, note, receipt.
 * Renvoie ['ok' => true, 'id' => int] ou ['ok' => false, 'error' => message].
 */
function expense_save(PDO $pdo, array $in, int $id = 0): array
{
    $kindIn = (string) ($in['kind'] ?? '');
    $categoryIn = (string) ($in['category'] ?? '');
    $methodIn = (string) ($in['payment_method'] ?? '');
    $kind = array_key_exists($kindIn, expense_kinds()) ? $kindIn : 'depense';
    $label = mb_substr(trim((string) ($in['label'] ?? '')), 0, 160);
    $supplier = mb_substr(trim((string) ($in['supplier'] ?? '')), 0, 120);
    $category = array_key_exists($categoryIn, expense_categories()) ? $categoryIn : 'autre';
    $method = array_key_exists($methodIn, expense_methods()) ? $methodIn : '';
    $cents = expense_amount_to_cents(isset($in['amount']) ? (string) $in['amount'] : null);
    $spentOn = expense_valid_date($in['spent_on'] ?? null);
    $dueOn = expense_valid_date($in['due_on'] ?? null);
    $paidOn = expense_valid_date($in['paid_on'] ?? null);
    $invoiceNo = mb_substr(trim((string) ($in['invoice_no'] ?? '')), 0, 60);
    $reportId = (int) ($in['report_id'] ?? 0);
    $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 400);
    $rate = expense_normalize_vat_rate($in['vat_rate'] ?? 0);

    if ($label === '') return ['ok' => false, 'error' => 'Indiquez un libellé (ce qui a été acheté).'];
    if ($cents === null || $cents < 1) return ['ok' => false, 'error' => 'Indiquez un montant TTC valide, supérieur à 0 € (ex : 12,50).'];
    if ($spentOn === null) return ['ok' => false, 'error' => 'Indiquez une date valide.'];
    if ($dueOn !== null && $dueOn < $spentOn) return ['ok' => false, 'error' => "L'échéance précède la date de la facture."];

    // Une ligne d'une note déjà soumise (validée, remboursée…) est figée : ni modifiée, ni déplacée.
    if ($id > 0 && ($current = expense_find($pdo, $id)) && $current['report_id']) {
        $stmt = $pdo->prepare('SELECT status FROM expense_reports WHERE id = ?');
        $stmt->execute([(int) $current['report_id']]);
        if (!in_array($stmt->fetchColumn(), ['brouillon', 'refusee', false], true)) {
            return ['ok' => false, 'error' => 'Cette ligne appartient à une note de frais déjà soumise : elle ne peut plus être modifiée.'];
        }
    }

    if ($kind === 'frais') {
        $stmt = $pdo->prepare('SELECT status FROM expense_reports WHERE id = ?');
        $stmt->execute([$reportId]);
        $status = $stmt->fetchColumn();
        if ($status === false) return ['ok' => false, 'error' => 'Choisissez la note de frais de cette ligne.'];
        if (!in_array($status, ['brouillon', 'refusee'], true)) return ['ok' => false, 'error' => 'Cette note de frais est déjà soumise : elle ne peut plus être modifiée.'];
        $method = $method === '' ? 'perso' : $method;
        $paidOn = null;
        $dueOn = null;
        $invoiceNo = '';
    } else {
        $reportId = 0;
        if ($kind === 'depense') {
            $paidOn = $spentOn;   // un ticket est réglé le jour même
            $dueOn = null;
            $invoiceNo = '';
        }
    }

    $vat = expense_vat_from_ttc($cents, $rate);
    $vals = [$kind, $spentOn, $label, $supplier, $category, $cents, $rate, $vat, $method, $invoiceNo, $dueOn, $paidOn, $reportId > 0 ? $reportId : null, $note];
    if ($id > 0) {
        $sql = 'UPDATE expenses SET kind=?, spent_on=?, label=?, supplier=?, category=?, amount_cents=?, vat_rate=?, vat_cents=?, payment_method=?, invoice_no=?, due_on=?, paid_on=?, report_id=?, note=?';
        if (isset($in['receipt']) && $in['receipt'] !== null) {
            $sql .= ', receipt=?';
            $vals[] = (string) $in['receipt'];
        }
        $vals[] = $id;
        $stmt = $pdo->prepare($sql . ' WHERE id=?');
        $stmt->execute($vals);
        return $stmt->rowCount() > 0 || expense_find($pdo, $id) ? ['ok' => true, 'id' => $id] : ['ok' => false, 'error' => 'Ligne introuvable.'];
    }
    $vals[] = (string) ($in['receipt'] ?? '');
    $pdo->prepare('INSERT INTO expenses (kind, spent_on, label, supplier, category, amount_cents, vat_rate, vat_cents, payment_method, invoice_no, due_on, paid_on, report_id, note, receipt) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

function expense_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Supprime une ligne (et son justificatif). Une ligne d'une note déjà soumise ne se supprime pas. */
function expense_delete(PDO $pdo, int $id): array
{
    $row = expense_find($pdo, $id);
    if (!$row) return ['ok' => false, 'error' => 'Ligne introuvable.'];
    if ($row['report_id']) {
        $stmt = $pdo->prepare('SELECT status FROM expense_reports WHERE id = ?');
        $stmt->execute([(int) $row['report_id']]);
        if (!in_array($stmt->fetchColumn(), ['brouillon', 'refusee', false], true)) {
            return ['ok' => false, 'error' => 'Cette ligne appartient à une note de frais déjà soumise.'];
        }
    }
    $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
    if ($row['receipt'] !== '') expense_receipt_delete((string) $row['receipt']);
    return ['ok' => true];
}

/** Marque une facture payée (à la date donnée) ou de nouveau à payer ($date = null). */
function expense_set_paid(PDO $pdo, int $id, ?string $date): bool
{
    $stmt = $pdo->prepare("UPDATE expenses SET paid_on = ? WHERE id = ? AND kind = 'facture'");
    $stmt->execute([$date !== null ? expense_valid_date($date) : null, $id]);
    return $stmt->rowCount() > 0;
}

/** Une facture du même fournisseur et du même numéro existe-t-elle déjà ? (alerte de doublon, hors la ligne $exceptId). */
function expense_find_duplicate(PDO $pdo, string $supplier, string $invoiceNo, int $exceptId = 0): ?array
{
    $supplier = trim($supplier);
    $invoiceNo = trim($invoiceNo);
    if ($invoiceNo === '') return null;
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE kind = 'facture' AND LOWER(supplier) = LOWER(?) AND LOWER(invoice_no) = LOWER(?) AND id <> ? LIMIT 1");
    $stmt->execute([$supplier, $invoiceNo, $exceptId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Lignes du journal sur une période, la plus récente d'abord.
 * $f : from, to (dates, fin exclue), kind, category, q (texte), unpaid (factures à payer seulement), counted (seulement ce qui compte).
 */
function expense_list(PDO $pdo, array $f = []): array
{
    $where = ['1=1'];
    $args = [];
    if (!empty($f['from'])) { $where[] = 'e.spent_on >= ?'; $args[] = $f['from']; }
    if (!empty($f['to'])) { $where[] = 'e.spent_on < ?'; $args[] = $f['to']; }
    if (!empty($f['kind']) && array_key_exists($f['kind'], expense_kinds())) { $where[] = 'e.kind = ?'; $args[] = $f['kind']; }
    if (!empty($f['category']) && array_key_exists($f['category'], expense_categories())) { $where[] = 'e.category = ?'; $args[] = $f['category']; }
    if (!empty($f['report_id'])) { $where[] = 'e.report_id = ?'; $args[] = (int) $f['report_id']; }
    if (!empty($f['unpaid'])) $where[] = "e.kind = 'facture' AND e.paid_on IS NULL";
    if (!empty($f['counted'])) $where[] = expense_counted_sql();
    if (!empty($f['q'])) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $f['q'])) . '%';
        $where[] = "(e.label LIKE ? ESCAPE '\\' OR e.supplier LIKE ? ESCAPE '\\' OR e.invoice_no LIKE ? ESCAPE '\\' OR e.note LIKE ? ESCAPE '\\')";
        array_push($args, $like, $like, $like, $like);
    }
    $stmt = $pdo->prepare('SELECT e.*, r.title AS report_title, r.person AS report_person, r.status AS report_status
        FROM expenses e LEFT JOIN expense_reports r ON r.id = e.report_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY e.spent_on DESC, e.id DESC');
    $stmt->execute($args);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Condition SQL « cette ligne compte dans les totaux » (jointure : expenses e LEFT JOIN expense_reports r). */
function expense_counted_sql(): string
{
    return "(e.report_id IS NULL OR r.status IN ('validee','remboursee'))";
}

/**
 * Synthèse d'une période : totaux TTC / HT / TVA des lignes qui comptent, par catégorie et par mois,
 * plus les suivis indépendants de la période (factures à payer, en retard, notes à valider et à rembourser).
 */
function expense_summary(PDO $pdo, string $from, string $to, string $today): array
{
    $rows = expense_list($pdo, ['from' => $from, 'to' => $to, 'counted' => true]);
    $sum = ['count' => count($rows), 'ttc' => 0, 'vat' => 0, 'ht' => 0, 'by_category' => [], 'by_month' => [], 'by_kind' => []];
    foreach ($rows as $r) {
        $c = (int) $r['amount_cents'];
        $v = (int) $r['vat_cents'];
        $sum['ttc'] += $c;
        $sum['vat'] += $v;
        $sum['ht'] += $c - $v;
        $sum['by_category'][$r['category']] = ($sum['by_category'][$r['category']] ?? 0) + $c;
        $sum['by_kind'][$r['kind']] = ($sum['by_kind'][$r['kind']] ?? 0) + $c;
        $month = substr((string) $r['spent_on'], 0, 7);
        $sum['by_month'][$month] = ($sum['by_month'][$month] ?? 0) + $c;
    }
    arsort($sum['by_category']);
    ksort($sum['by_month']);

    $unpaid = expense_list($pdo, ['unpaid' => true]);
    $sum['to_pay'] = ['count' => count($unpaid), 'cents' => 0, 'overdue_count' => 0, 'overdue_cents' => 0];
    foreach ($unpaid as $r) {
        $sum['to_pay']['cents'] += (int) $r['amount_cents'];
        if (expense_is_overdue($r, $today)) {
            $sum['to_pay']['overdue_count']++;
            $sum['to_pay']['overdue_cents'] += (int) $r['amount_cents'];
        }
    }
    $sum['reports'] = ['to_review' => 0, 'to_reimburse' => 0, 'to_reimburse_cents' => 0];
    foreach (expense_reports_list($pdo) as $rep) {
        if ($rep['status'] === 'soumise') $sum['reports']['to_review']++;
        if ($rep['status'] === 'validee') {
            $sum['reports']['to_reimburse']++;
            $sum['reports']['to_reimburse_cents'] += (int) $rep['total_cents'];
        }
    }
    return $sum;
}

/* ------------------------------------------------------------------ */
/* Notes de frais                                                      */
/* ------------------------------------------------------------------ */

/** Passages d'état autorisés d'une note de frais : état actuel => états possibles. */
function expense_report_transitions(): array
{
    return [
        'brouillon' => ['soumise'],
        'soumise' => ['validee', 'refusee', 'brouillon'],   // « brouillon » : la rappeler pour la corriger
        'validee' => ['remboursee'],
        'refusee' => ['brouillon'],
        'remboursee' => [],
    ];
}

function expense_report_can(string $from, string $to): bool
{
    return in_array($to, expense_report_transitions()[$from] ?? [], true);
}

function expense_report_create(PDO $pdo, string $person, string $title): array
{
    $person = mb_substr(trim($person), 0, 80);
    $title = mb_substr(trim($title), 0, 120);
    if ($person === '') return ['ok' => false, 'error' => 'Indiquez qui a avancé ces frais.'];
    if ($title === '') return ['ok' => false, 'error' => 'Donnez un titre à la note (ex : Salon de Lille, novembre).'];
    $pdo->prepare('INSERT INTO expense_reports (person, title) VALUES (?, ?)')->execute([$person, $title]);
    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

function expense_report_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT r.*, (SELECT COUNT(*) FROM expenses e WHERE e.report_id = r.id) AS lines,
        (SELECT COALESCE(SUM(e.amount_cents), 0) FROM expenses e WHERE e.report_id = r.id) AS total_cents
        FROM expense_reports r WHERE r.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Notes de frais avec nombre de lignes et total, les plus récentes d'abord. */
function expense_reports_list(PDO $pdo, string $status = ''): array
{
    $sql = 'SELECT r.*, (SELECT COUNT(*) FROM expenses e WHERE e.report_id = r.id) AS lines,
        (SELECT COALESCE(SUM(e.amount_cents), 0) FROM expenses e WHERE e.report_id = r.id) AS total_cents
        FROM expense_reports r';
    $args = [];
    if ($status !== '' && array_key_exists($status, expense_report_statuses())) {
        $sql .= ' WHERE r.status = ?';
        $args[] = $status;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY r.id DESC');
    $stmt->execute($args);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fait avancer une note. Une note vide ne se soumet pas ; un refus demande un motif.
 * $now : horodatage AAAA-MM-JJ HH:MM:SS (injecté pour les tests).
 */
function expense_report_transition(PDO $pdo, int $id, string $to, string $now, string $decisionNote = ''): array
{
    $rep = expense_report_find($pdo, $id);
    if (!$rep) return ['ok' => false, 'error' => 'Note de frais introuvable.'];
    if (!expense_report_can((string) $rep['status'], $to)) {
        return ['ok' => false, 'error' => 'Cette note ne peut pas passer de « ' . (expense_report_statuses()[$rep['status']] ?? $rep['status']) . ' » à « ' . (expense_report_statuses()[$to] ?? $to) . ' ».'];
    }
    $decisionNote = mb_substr(trim($decisionNote), 0, 300);
    if ($to === 'soumise' && (int) $rep['lines'] < 1) return ['ok' => false, 'error' => 'Ajoutez au moins une ligne avant de soumettre la note.'];
    if ($to === 'refusee' && $decisionNote === '') return ['ok' => false, 'error' => 'Indiquez le motif du refus.'];

    $set = ['status = ?'];
    $args = [$to];
    switch ($to) {
        case 'soumise': $set[] = 'submitted_at = ?'; $args[] = $now; $set[] = "decision_note = ''"; break;
        case 'validee': case 'refusee': $set[] = 'decided_at = ?'; $args[] = $now; $set[] = 'decision_note = ?'; $args[] = $decisionNote; break;
        case 'remboursee': $set[] = 'reimbursed_at = ?'; $args[] = $now; break;
        case 'brouillon': $set[] = 'submitted_at = NULL'; $set[] = 'decided_at = NULL'; break;
    }
    $args[] = $id;
    $pdo->prepare('UPDATE expense_reports SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    return ['ok' => true];
}

/** Supprime une note et ses lignes (justificatifs compris) : seulement en brouillon ou refusée. */
function expense_report_delete(PDO $pdo, int $id): array
{
    $rep = expense_report_find($pdo, $id);
    if (!$rep) return ['ok' => false, 'error' => 'Note de frais introuvable.'];
    if (!in_array($rep['status'], ['brouillon', 'refusee'], true)) return ['ok' => false, 'error' => 'Une note soumise, validée ou remboursée ne se supprime pas.'];
    $stmt = $pdo->prepare('SELECT receipt FROM expenses WHERE report_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $receipt) {
        if ($receipt !== '') expense_receipt_delete((string) $receipt);
    }
    $pdo->prepare('DELETE FROM expenses WHERE report_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM expense_reports WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* Export comptable                                                    */
/* ------------------------------------------------------------------ */

/** Cellule CSV : guillemets doublés, et une formule de tableur (=, +, -, @) neutralisée. */
function expense_csv_cell(string $v): string
{
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
    return '"' . str_replace('"', '""', $v) . '"';
}

/** CSV à « ; » (ouvert tel quel par Excel en français), avec BOM UTF-8, une ligne par ligne du journal. */
function expense_csv(array $rows): string
{
    $eur = static fn (int $c): string => number_format($c / 100, 2, ',', '');
    $head = ['Date', 'Type', 'Libellé', 'Fournisseur', 'Catégorie', 'N° de facture', 'Montant HT', 'TVA', 'Montant TTC', 'Taux TVA', 'Règlement', 'Échéance', 'Payée le', 'Note de frais', 'Personne', 'État de la note', 'Justificatif'];
    $out = "\xEF\xBB\xBF" . implode(';', array_map('expense_csv_cell', $head)) . "\r\n";
    foreach ($rows as $r) {
        $cells = [
            (string) $r['spent_on'], expense_kinds()[$r['kind']] ?? (string) $r['kind'], (string) $r['label'], (string) $r['supplier'],
            expense_categories()[$r['category']] ?? (string) $r['category'], (string) $r['invoice_no'],
            $eur((int) $r['amount_cents'] - (int) $r['vat_cents']), $eur((int) $r['vat_cents']), $eur((int) $r['amount_cents']),
            rtrim(rtrim(number_format((float) $r['vat_rate'], 1, ',', ''), '0'), ','),
            expense_methods()[$r['payment_method']] ?? (string) $r['payment_method'], (string) ($r['due_on'] ?? ''), (string) ($r['paid_on'] ?? ''),
            (string) ($r['report_title'] ?? ''), (string) ($r['report_person'] ?? ''),
            isset($r['report_status']) ? (expense_report_statuses()[$r['report_status']] ?? (string) $r['report_status']) : '',
            $r['receipt'] !== '' ? 'oui' : 'non',
        ];
        $out .= implode(';', array_map('expense_csv_cell', $cells)) . "\r\n";
    }
    return $out;
}
