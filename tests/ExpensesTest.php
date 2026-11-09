<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/expenses.php';

final class ExpensesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        expense_ensure_schema($this->pdo);
    }

    private function line(array $over = []): array
    {
        return array_merge(['kind' => 'depense', 'spent_on' => '2026-11-03', 'label' => 'Cartons', 'supplier' => 'Brico', 'category' => 'fournitures',
            'amount' => '24,00', 'vat_rate' => '20'], $over);
    }

    public function testAmountToCents(): void
    {
        self::assertSame(1250, expense_amount_to_cents('12,5'));
        self::assertSame(125050, expense_amount_to_cents('1 250,50 €'));
        self::assertSame(125050, expense_amount_to_cents("1\xc2\xa0250.50"));
        self::assertNull(expense_amount_to_cents(''));
        self::assertNull(expense_amount_to_cents('-5'));
        self::assertNull(expense_amount_to_cents('12,345'));
        self::assertNull(expense_amount_to_cents('abc'));
        self::assertNull(expense_amount_to_cents('99999999'));
    }

    public function testVatExtractedFromTtc(): void
    {
        self::assertSame(400, expense_vat_from_ttc(2400, 20.0));
        self::assertSame(218, expense_vat_from_ttc(2400, 10.0));
        self::assertSame(0, expense_vat_from_ttc(2400, 0.0));
        self::assertSame(0, expense_vat_from_ttc(0, 20.0));
        self::assertSame(20.0, expense_normalize_vat_rate('20'));
        self::assertSame(5.5, expense_normalize_vat_rate('5,5'));
        self::assertSame(0.0, expense_normalize_vat_rate('19'));
    }

    public function testPeriod(): void
    {
        $now = new DateTimeImmutable('2026-11-09');
        self::assertSame(['2026-11-01', '2026-12-01', 'novembre 2026'], expense_period('2026-11', $now));
        self::assertSame(['2026-12-01', '2027-01-01', 'décembre 2026'], expense_period('2026-12', $now));
        self::assertSame(['2026-01-01', '2027-01-01', '2026'], expense_period('2026', $now));
        self::assertSame(['', '', 'Depuis le début'], expense_period('tout', $now));
        self::assertSame(['2026-11-01', '2026-12-01', 'novembre 2026'], expense_period('n\'importe quoi', $now));
    }

    public function testSaveTicketIsPaidTheSameDay(): void
    {
        $r = expense_save($this->pdo, $this->line(['paid_on' => '2030-01-01', 'invoice_no' => 'X']));
        self::assertTrue($r['ok']);
        $row = expense_find($this->pdo, $r['id']);
        self::assertSame('2026-11-03', $row['paid_on']);
        self::assertSame('', $row['invoice_no']);
        self::assertSame(2400, (int) $row['amount_cents']);
        self::assertSame(400, (int) $row['vat_cents']);
    }

    public function testSaveValidation(): void
    {
        self::assertFalse(expense_save($this->pdo, $this->line(['label' => ' ']))['ok']);
        self::assertFalse(expense_save($this->pdo, $this->line(['amount' => '0']))['ok']);
        self::assertFalse(expense_save($this->pdo, $this->line(['spent_on' => '2026-02-31']))['ok']);
        self::assertFalse(expense_save($this->pdo, $this->line(['kind' => 'facture', 'due_on' => '2026-10-01']))['ok'], "échéance avant la facture");
        self::assertFalse(expense_save($this->pdo, $this->line(['kind' => 'frais', 'report_id' => 99]))['ok'], 'note inconnue');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn());
    }

    public function testInvoiceOverdueAndPaid(): void
    {
        $id = expense_save($this->pdo, $this->line(['kind' => 'facture', 'invoice_no' => 'F-12', 'due_on' => '2026-11-05']))['id'];
        $row = expense_find($this->pdo, $id);
        self::assertNull($row['paid_on']);
        self::assertTrue(expense_is_overdue($row, '2026-11-09'));
        self::assertFalse(expense_is_overdue($row, '2026-11-05'));

        $s = expense_summary($this->pdo, '2026-11-01', '2026-12-01', '2026-11-09');
        self::assertSame(1, $s['to_pay']['count']);
        self::assertSame(1, $s['to_pay']['overdue_count']);
        self::assertSame(2400, $s['ttc'], 'une facture compte à sa date, payée ou non');

        self::assertTrue(expense_set_paid($this->pdo, $id, '2026-11-08'));
        $s = expense_summary($this->pdo, '2026-11-01', '2026-12-01', '2026-11-09');
        self::assertSame(0, $s['to_pay']['count']);
        self::assertTrue(expense_set_paid($this->pdo, $id, null));
        self::assertNull(expense_find($this->pdo, $id)['paid_on']);
    }

    public function testDuplicateInvoiceDetection(): void
    {
        $id = expense_save($this->pdo, $this->line(['kind' => 'facture', 'invoice_no' => 'F-12']))['id'];
        self::assertNotNull(expense_find_duplicate($this->pdo, 'brico', 'f-12'));
        self::assertNull(expense_find_duplicate($this->pdo, 'brico', 'f-12', $id));
        self::assertNull(expense_find_duplicate($this->pdo, 'Autre', 'F-12'));
        self::assertNull(expense_find_duplicate($this->pdo, 'Brico', ''));
    }

    public function testReportWorkflow(): void
    {
        $rid = expense_report_create($this->pdo, 'Léa', 'Salon de Lille')['id'];
        self::assertFalse(expense_report_transition($this->pdo, $rid, 'soumise', '2026-11-09 10:00:00')['ok'], 'note vide');

        $lid = expense_save($this->pdo, $this->line(['kind' => 'frais', 'report_id' => $rid, 'category' => 'deplacement', 'amount' => '60']))['id'];
        self::assertSame('perso', expense_find($this->pdo, $lid)['payment_method']);

        // Brouillon et soumise ne comptent pas.
        self::assertSame(0, expense_summary($this->pdo, '2026-11-01', '2026-12-01', '2026-11-09')['ttc']);
        self::assertTrue(expense_report_transition($this->pdo, $rid, 'soumise', '2026-11-09 10:00:00')['ok']);
        self::assertSame(0, expense_summary($this->pdo, '2026-11-01', '2026-12-01', '2026-11-09')['ttc']);
        self::assertSame(1, expense_summary($this->pdo, '2026-11-01', '2026-12-01', '2026-11-09')['reports']['to_review']);

        // Figée une fois soumise.
        self::assertFalse(expense_save($this->pdo, $this->line(['kind' => 'frais', 'report_id' => $rid]), $lid)['ok']);
        self::assertFalse(expense_delete($this->pdo, $lid)['ok']);
        self::assertFalse(expense_report_delete($this->pdo, $rid)['ok']);

        self::assertFalse(expense_report_transition($this->pdo, $rid, 'remboursee', '2026-11-10 10:00:00')['ok'], 'pas de saut de validation');
        self::assertTrue(expense_report_transition($this->pdo, $rid, 'validee', '2026-11-10 10:00:00')['ok']);
        $s = expense_summary($this->pdo, '2026-11-01', '2026-12-01', '2026-11-09');
        self::assertSame(6000, $s['ttc']);
        self::assertSame(1, $s['reports']['to_reimburse']);
        self::assertSame(6000, $s['reports']['to_reimburse_cents']);

        self::assertTrue(expense_report_transition($this->pdo, $rid, 'remboursee', '2026-11-12 10:00:00')['ok']);
        self::assertSame(0, expense_summary($this->pdo, '2026-11-01', '2026-12-01', '2026-11-09')['reports']['to_reimburse']);
        self::assertSame(6000, expense_summary($this->pdo, '2026-11-01', '2026-12-01', '2026-11-09')['ttc']);
        self::assertSame([], expense_report_transitions()['remboursee']);
    }

    public function testRefusalNeedsReasonAndReturnsToDraft(): void
    {
        $rid = expense_report_create($this->pdo, 'Léa', 'Péage')['id'];
        expense_save($this->pdo, $this->line(['kind' => 'frais', 'report_id' => $rid]));
        expense_report_transition($this->pdo, $rid, 'soumise', '2026-11-09 10:00:00');
        self::assertFalse(expense_report_transition($this->pdo, $rid, 'refusee', '2026-11-09 11:00:00', ' ')['ok']);
        self::assertTrue(expense_report_transition($this->pdo, $rid, 'refusee', '2026-11-09 11:00:00', 'Ticket illisible')['ok']);
        self::assertSame('Ticket illisible', expense_report_find($this->pdo, $rid)['decision_note']);
        self::assertTrue(expense_report_transition($this->pdo, $rid, 'brouillon', '2026-11-09 12:00:00')['ok']);
        self::assertNull(expense_report_find($this->pdo, $rid)['submitted_at']);
        self::assertTrue(expense_report_delete($this->pdo, $rid)['ok']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn());
    }

    public function testSummaryByCategoryAndMonth(): void
    {
        expense_save($this->pdo, $this->line(['amount' => '10', 'category' => 'fournitures']));
        expense_save($this->pdo, $this->line(['amount' => '30', 'category' => 'marchandises', 'spent_on' => '2026-11-20', 'vat_rate' => '0']));
        expense_save($this->pdo, $this->line(['amount' => '5', 'spent_on' => '2026-10-31']));
        $s = expense_summary($this->pdo, '2026-11-01', '2026-12-01', '2026-11-09');
        self::assertSame(4000, $s['ttc']);
        self::assertSame(['marchandises' => 3000, 'fournitures' => 1000], $s['by_category']);
        self::assertSame(['2026-11' => 4000], $s['by_month']);
        $all = expense_summary($this->pdo, '', '', '2026-11-09');
        self::assertSame(4500, $all['ttc']);
    }

    public function testListSearchEscapesWildcards(): void
    {
        expense_save($this->pdo, $this->line(['label' => 'Remise 50% cartons']));
        expense_save($this->pdo, $this->line(['label' => 'Ruban adhésif']));
        self::assertCount(1, expense_list($this->pdo, ['q' => '50%']));
        self::assertCount(2, expense_list($this->pdo, ['q' => 'brico']));
        self::assertCount(1, expense_list($this->pdo, ['q' => '%']), 'le « % » est cherché tel quel, pas comme joker');
        self::assertCount(0, expense_list($this->pdo, ['q' => '_']));
    }

    public function testCsvNeutralisesFormulasAndKeepsAccents(): void
    {
        expense_save($this->pdo, $this->line(['label' => '=HYPERLINK("http://x")', 'supplier' => 'Café "Chez Léa"']));
        $csv = expense_csv(expense_list($this->pdo));
        self::assertStringStartsWith("\xEF\xBB\xBF\"Date\";", $csv);
        self::assertStringContainsString('"\'=HYPERLINK(""http://x"")"', $csv);
        self::assertStringContainsString('"Café ""Chez Léa"""', $csv);
        self::assertStringContainsString('"20,00";"4,00";"24,00";"20"', $csv);
    }

    public function testReceiptNameIsStrict(): void
    {
        self::assertSame('', expense_receipt_path('../../etc/passwd'));
        self::assertSame('', expense_receipt_path('d0123456789ab.php'));
        self::assertNotSame('', expense_receipt_path('d0123456789ab.pdf'));
        self::assertSame('', expense_receipt_path('d0123456789ab.pdf/../x'));
    }

    public function testReceiptTypeFromContent(): void
    {
        $dir = sys_get_temp_dir() . '/exp-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/a.jpg", '<?php echo 1;');
        file_put_contents("$dir/b.bin", "%PDF-1.4\n%fake");
        self::assertNull(expense_receipt_ext("$dir/a.jpg"), 'le nom ne fait pas foi');
        self::assertSame('pdf', expense_receipt_ext("$dir/b.bin"));
        unlink("$dir/a.jpg");
        unlink("$dir/b.bin");
        rmdir($dir);
    }
}
