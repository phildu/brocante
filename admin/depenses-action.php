<?php
// Actions du journal des dépenses (POST) : ligne (ajout, modification, suppression), facture payée, notes de frais.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/expenses.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/depenses.php');
    exit;
}
shop_require_csrf();
$pdo = db();
expense_ensure_schema($pdo);

// Retour sur la même vue (onglet, période, filtres) : seule une adresse de cette page est acceptée.
$back = (string) ($_POST['return'] ?? '');
if (!preg_match('#^/admin/depenses\.php(\?[A-Za-z0-9=&_%.-]*)?(\#[A-Za-z0-9_-]+)?$#D', $back)) $back = '/admin/depenses.php';

function exp_redirect(string $back, string $anchor = ''): never
{
    header('Location: ' . preg_replace('/#.*$/', '', $back) . ($anchor !== '' ? '#' . $anchor : ''));
    exit;
}

function exp_result(array $r, string $okMessage): void
{
    flash_set($r['ok'] ? $okMessage : (string) $r['error'], $r['ok'] ? 'ok' : 'error');
}

$action = (string) ($_POST['action'] ?? '');
$id = (int) ($_POST['id'] ?? 0);
$now = (new DateTimeImmutable('now', dash_tz()))->format('Y-m-d H:i:s');

switch ($action) {
    case 'save': {
        $in = [];
        foreach (['kind', 'spent_on', 'label', 'supplier', 'category', 'amount', 'vat_rate', 'payment_method', 'invoice_no', 'due_on', 'paid_on', 'report_id', 'note'] as $k) {
            $in[$k] = (string) ($_POST[$k] ?? '');
        }
        $previous = $id > 0 ? expense_find($pdo, $id) : null;
        if ($id > 0 && !$previous) {
            flash_set('Ligne introuvable.', 'error');
            break;
        }
        $newReceipt = null;
        try {
            $stored = expense_receipt_store($_FILES['receipt'] ?? []);
            if ($stored !== '') $newReceipt = $stored;
        } catch (InvalidArgumentException | RuntimeException $e) {
            flash_set($e->getMessage(), 'error');
            break;
        }
        if ($newReceipt === null && $previous && !empty($_POST['remove_receipt'])) $newReceipt = '';
        $in['receipt'] = $newReceipt;

        $result = expense_save($pdo, $in, $id);
        if (!$result['ok']) {
            if ($newReceipt) expense_receipt_delete($newReceipt);   // pas de fichier orphelin quand la saisie est refusée
            flash_set((string) $result['error'], 'error');
            break;
        }
        if ($previous && $newReceipt !== null && $previous['receipt'] !== '') expense_receipt_delete((string) $previous['receipt']);

        $message = $id > 0 ? 'Ligne enregistrée.' : 'Ligne ajoutée au journal.';
        if (($in['kind'] ?? '') === 'facture' && ($dup = expense_find_duplicate($pdo, (string) $in['supplier'], (string) $in['invoice_no'], (int) $result['id']))) {
            $message .= ' Attention : la facture n° ' . $dup['invoice_no'] . ' de ' . ($dup['supplier'] !== '' ? $dup['supplier'] : 'ce fournisseur') . ' existe déjà (' . $dup['spent_on'] . ') : doublon possible.';
        }
        flash_set($message);
        break;
    }

    case 'delete':
        exp_result(expense_delete($pdo, $id), 'Ligne supprimée.');
        break;

    case 'paid': {
        $paid = (string) ($_POST['paid'] ?? '1') === '0' ? null : (expense_valid_date($_POST['paid_on'] ?? null) ?? (new DateTimeImmutable('now', dash_tz()))->format('Y-m-d'));
        $ok = expense_set_paid($pdo, $id, $paid);
        flash_set($ok ? ($paid === null ? 'Facture remise à payer.' : 'Facture marquée payée.') : 'Facture introuvable.', $ok ? 'ok' : 'error');
        break;
    }

    case 'report_create': {
        $session = admin_session();
        $person = trim((string) ($_POST['person'] ?? '')) ?: (string) ($session['name'] ?? '');
        $r = expense_report_create($pdo, $person, (string) ($_POST['title'] ?? ''));
        exp_result($r, 'Note de frais créée : ajoutez-y vos lignes.');
        if ($r['ok']) exp_redirect($back, 'note-' . (int) $r['id']);
        break;
    }

    case 'report_transition': {
        $to = (string) ($_POST['to'] ?? '');
        $labels = ['soumise' => 'Note soumise pour validation.', 'validee' => 'Note validée : elle compte maintenant dans les dépenses.', 'refusee' => 'Note refusée.',
            'remboursee' => 'Note marquée remboursée.', 'brouillon' => 'Note repassée en brouillon.'];
        exp_result(expense_report_transition($pdo, $id, $to, $now, (string) ($_POST['decision_note'] ?? '')), $labels[$to] ?? 'Note mise à jour.');
        exp_redirect($back, 'note-' . $id);
    }

    case 'report_delete':
        exp_result(expense_report_delete($pdo, $id), 'Note de frais supprimée.');
        break;
}

exp_redirect($back);
