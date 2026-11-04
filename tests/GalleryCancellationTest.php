<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/gallery-cancellation.php';

/**
 * Annulation d'un événement et remboursement des exposants : conditions de
 * vente, calcul du montant, normalize de l'annulation et textes d'alerte.
 * Aucun fichier de données n'est écrit.
 */
final class GalleryCancellationTest extends TestCase
{
    private function rules(array $over = []): array
    {
        return gcn_rules($over);
    }

    private function event(array $cancel = []): array
    {
        return gallery_event_normalize([
            'title' => 'Vide-grenier de juin',
            'date' => '2026-06-14',
            'place' => 'Place Bellecour',
            'city' => 'Lyon',
            'price_cents' => 2000,
            'published' => true,
            'cancelled' => $cancel + [
                'at' => strtotime('2026-05-20 10:00:00'),
                'reason' => 'Orage violent annoncé',
                'cause' => 'meteo',
                'refund_pct' => 100,
                'refund_delay_days' => 30,
                'refund_method' => 'Virement',
            ],
        ]);
    }

    private function gallery(): array
    {
        return ['slug' => 'test-annulation', 'name' => 'Galerie Test', 'admins' => [['email' => 'orga@example.test']]];
    }

    private function row(string $status = 'taken', int $price = 2000, array $refund = []): array
    {
        return [
            'slot_id' => 'ev3', 'slot' => 'Place 3', 'name' => 'Jean Test', 'email' => 'jean@example.test',
            'status' => $status, 'price_cents' => $price, 'token' => str_repeat('a', 32),
            'refund' => $refund,
        ];
    }

    public function testRulesClampAndFallBackToDefaults(): void
    {
        $r = gcn_rules([
            'organizer_pct' => 150, 'force_majeure_pct' => -5, 'full_days' => 500, 'partial_days' => 45,
            'partial_pct' => 120, 'late_pct' => -3, 'refund_delay_days' => 999, 'refund_method' => '   ', 'custom' => '  ',
        ]);
        self::assertSame(100, $r['organizer_pct']);
        self::assertSame(0, $r['force_majeure_pct']);
        self::assertSame(180, $r['full_days']);
        self::assertSame(45, $r['partial_days']);
        self::assertSame(100, $r['partial_pct']);
        self::assertSame(0, $r['late_pct']);
        self::assertSame(180, $r['refund_delay_days']);
        self::assertSame('Virement bancaire ou chèque', $r['refund_method']);
        self::assertSame('', $r['custom']);

        self::assertSame(gcn_default_rules(), gcn_rules([]));
    }

    public function testPartialThresholdNeverExceedsFullThreshold(): void
    {
        $r = gcn_rules(['full_days' => 10, 'partial_days' => 20]);
        self::assertSame(10, $r['full_days']);
        self::assertSame(10, $r['partial_days']);
        self::assertSame(100, gcn_withdrawal_pct($r, 10));
        self::assertSame(0, gcn_withdrawal_pct($r, 9));
    }

    public function testCausePercentage(): void
    {
        $r = $this->rules(['organizer_pct' => 100, 'force_majeure_pct' => 60]);
        self::assertSame(60, gcn_cause_pct($r, 'meteo'));
        self::assertSame(60, gcn_cause_pct($r, 'force_majeure'));
        self::assertSame(100, gcn_cause_pct($r, 'organisateur'));
        self::assertSame(100, gcn_cause_pct($r, 'autre'));
        self::assertSame(100, gcn_cause_pct($r, 'inconnue'));
    }

    public function testWithdrawalPercentageAtBoundaries(): void
    {
        $r = $this->rules(['full_days' => 30, 'partial_days' => 7, 'partial_pct' => 50, 'late_pct' => 0]);
        self::assertSame(100, gcn_withdrawal_pct($r, 31));
        self::assertSame(100, gcn_withdrawal_pct($r, 30));
        self::assertSame(50, gcn_withdrawal_pct($r, 29));
        self::assertSame(50, gcn_withdrawal_pct($r, 7));
        self::assertSame(0, gcn_withdrawal_pct($r, 6));
        self::assertSame(0, gcn_withdrawal_pct($r, 0));
    }

    public function testRefundAmountRoundsAndClamps(): void
    {
        self::assertSame(2000, gcn_refund_amount(2000, 100));
        self::assertSame(498, gcn_refund_amount(1990, 25));
        self::assertSame(498, gcn_refund_amount(995, 50));
        self::assertSame(1000, gcn_refund_amount(1000, 120));
        self::assertSame(0, gcn_refund_amount(2000, 0));
        self::assertSame(0, gcn_refund_amount(null, 50));
        self::assertSame(0, gcn_refund_amount(-100, 50));
    }

    public function testRulesLinesReadable(): void
    {
        $lines = gcn_rules_lines($this->rules());
        self::assertSame('Remboursement intégral (100 %)', $lines['Annulation par l’organisateur']);
        self::assertSame('Remboursement intégral (100 %)', $lines['Annulation pour météo ou force majeure']);
        self::assertSame('Remboursement intégral', $lines['Désistement de l’exposant à plus de 30 jours']);
        self::assertSame('Remboursement partiel (50 %)', $lines['Désistement entre 7 et 30 jours']);
        self::assertSame('Aucun remboursement', $lines['Désistement à moins de 7 jours']);
        self::assertSame('30 jours après l’annonce', $lines['Délai de remboursement']);
        self::assertSame('Virement bancaire ou chèque', $lines['Moyen de remboursement']);
        self::assertArrayNotHasKey('Condition particulière', $lines);

        $custom = gcn_rules_lines($this->rules(['custom' => 'Chèque à l’ordre de la mairie']));
        self::assertSame('Chèque à l’ordre de la mairie', $custom['Condition particulière']);
    }

    public function testRefundRowNormalization(): void
    {
        $empty = gcn_row_refund(['price_cents' => 1500]);
        self::assertSame('none', $empty['status']);
        self::assertSame(0, $empty['pct']);
        self::assertSame(0, $empty['amount_cents']);
        self::assertSame(1500, $empty['price_cents']);

        $paid = gcn_row_refund(['price_cents' => 1500, 'refund' => [
            'status' => 'paid', 'pct' => 250, 'amount_cents' => -10, 'price_cents' => 2000, 'paid_at' => 5, 'note' => 'VIR-42',
        ]]);
        self::assertSame('paid', $paid['status']);
        self::assertSame(100, $paid['pct']);
        self::assertSame(0, $paid['amount_cents']);
        self::assertSame(2000, $paid['price_cents']);
        self::assertSame(5, $paid['paid_at']);
        self::assertSame('VIR-42', $paid['note']);

        self::assertSame('none', gcn_row_refund(['refund' => ['status' => 'inconnu']])['status']);
    }

    public function testRefundLabels(): void
    {
        self::assertSame('À rembourser', gcn_refund_label(['status' => 'due']));
        self::assertSame('Remboursé', gcn_refund_label(['status' => 'paid']));
        self::assertSame('Sans remboursement', gcn_refund_label(['status' => 'waived']));
        self::assertSame('Rien à rembourser', gcn_refund_label(['status' => 'none']));
        self::assertSame('Rien à rembourser', gcn_refund_label(null));
    }

    public function testEventCancellationNormalization(): void
    {
        $e = $this->event([
            'at' => '1700000000', 'reason' => '  Orage violent  ', 'cause' => 'inconnue',
            'refund_pct' => 140, 'refund_delay_days' => 999, 'refund_method' => 'Virement', 'by' => ' Orga ', 'notified' => '123',
        ]);
        self::assertTrue(ge_cancelled($e));
        self::assertSame(1700000000, $e['cancelled']['at']);
        self::assertSame('Orage violent', $e['cancelled']['reason']);
        self::assertSame('organisateur', $e['cancelled']['cause']);
        self::assertSame(100, $e['cancelled']['refund_pct']);
        self::assertSame(180, $e['cancelled']['refund_delay_days']);
        self::assertSame('Orga', $e['cancelled']['by']);
        self::assertSame(123, $e['cancelled']['notified']);
        self::assertSame('Décision de l’organisateur — Orage violent', ge_cancel_reason($e));

        $live = gallery_event_normalize(['title' => 'Vide-grenier', 'date' => '2026-06-14', 'place' => 'Place X']);
        self::assertFalse(ge_cancelled($live));
        self::assertNull($live['cancelled']);
        self::assertFalse(ge_cancelled(null));
        self::assertSame('Annulation', ge_cancel_reason($live));
    }

    public function testNoticeBodyForDueRefundNamesAmountAndDelay(): void
    {
        $event = $this->event();
        $body = gcn_notice_body($this->gallery(), $event, $this->row('taken', 2000, [
            'status' => 'due', 'pct' => 100, 'amount_cents' => 2000, 'price_cents' => 2000,
        ]), $event['cancelled']);
        self::assertStringContainsString('annulation de Vide-grenier de juin', $body);
        self::assertStringContainsString('Motif : Météo — Orage violent annoncé.', $body);
        self::assertStringContainsString('Un remboursement de 20,00 € vous sera versé sous 30 jours, par Virement.', $body);
        self::assertStringContainsString('galerie/exposant.php?g=test-annulation&t=' . str_repeat('a', 32), $body);
        self::assertStringContainsString('Galerie Test', $body);
    }

    public function testNoticeBodyExplainsPartialRefund(): void
    {
        $event = $this->event();
        $body = gcn_notice_body($this->gallery(), $event, $this->row('taken', 2000, [
            'status' => 'due', 'pct' => 50, 'amount_cents' => 1000, 'price_cents' => 2000,
        ]), $event['cancelled']);
        self::assertStringContainsString('Un remboursement de 10,00 €', $body);
        self::assertStringContainsString('50 % du tarif de 20,00 €', $body);
    }

    public function testNoticeBodyForPaidRefund(): void
    {
        $event = $this->event();
        $body = gcn_notice_body($this->gallery(), $event, $this->row('taken', 2000, [
            'status' => 'paid', 'pct' => 100, 'amount_cents' => 2000, 'price_cents' => 2000,
            'paid_at' => strtotime('2026-05-25'), 'note' => 'Virement SEPA VIR-42',
        ]), $event['cancelled']);
        self::assertStringContainsString('Le remboursement de 20,00 € a été effectué le 25/05/2026.', $body);
        self::assertStringContainsString('Virement SEPA VIR-42', $body);
    }

    public function testNoticeBodyForPendingAndUnpaidRows(): void
    {
        $event = $this->event();
        $pending = gcn_notice_body($this->gallery(), $event, $this->row('pending', 2000, [
            'status' => 'none', 'pct' => 0, 'amount_cents' => 0, 'price_cents' => 2000,
        ]), $event['cancelled']);
        self::assertStringContainsString("aucun paiement n'a été enregistré", $pending);

        $free = gcn_notice_body($this->gallery(), $event, $this->row('taken', 0, [
            'status' => 'none', 'pct' => 0, 'amount_cents' => 0, 'price_cents' => 0,
        ]), $event['cancelled']);
        self::assertStringContainsString("Aucun tarif n'était enregistré", $free);

        $waived = gcn_notice_body($this->gallery(), $event, $this->row('taken', 2000, [
            'status' => 'waived', 'pct' => 0, 'amount_cents' => 0, 'price_cents' => 2000, 'note' => 'Geste commercial refusé.',
        ]), $event['cancelled']);
        self::assertStringContainsString("Aucun remboursement n'est prévu à ce titre.", $waived);
        self::assertStringContainsString('Geste commercial refusé.', $waived);
    }
}
