<?php

use PHPUnit\Framework\TestCase;

/**
 * Helpers purs des fournisseurs de paiement (Monetico, PayPal) : signature,
 * montants, lecture des réponses. Aucun appel réseau, aucune clé réelle.
 */
final class PaymentProvidersTest extends TestCase
{
    private const MONETICO_KEY = '0123456789ABCDEF0123456789ABCDEF01234567';

    public function testPaymentProviderFallsBackToLegacyColumns(): void
    {
        self::assertSame('stripe', payment_provider_of(['stripe_session_id' => 'cs_test_123']));
        self::assertSame('monetico', payment_provider_of(['stripe_session_id' => 'monetico:B123']));
        self::assertSame('paypal', payment_provider_of(['stripe_session_id' => 'paypal:B123']));
        self::assertSame('paypal', payment_provider_of(['payment_provider' => 'paypal', 'stripe_session_id' => 'paypal:B123']));
        self::assertSame('PayPal', payment_provider_label('paypal'));
        self::assertSame('inconnu', payment_provider_label('inconnu'));
    }

    public function testPaymentAmountValueUsesDotAndTwoDecimals(): void
    {
        self::assertSame('0.00', payment_amount_value(0));
        self::assertSame('62.73', payment_amount_value(6273));
        self::assertSame('1250.50', payment_amount_value(125050));
        self::assertSame('0.00', payment_amount_value(-500));
    }

    public function testMoneticoKeyIsTwentyBytes(): void
    {
        self::assertSame(20, strlen(monetico_usable_key(self::MONETICO_KEY)));
        self::assertSame(20, strlen(monetico_usable_key(strtolower(self::MONETICO_KEY))));
    }

    public function testMoneticoKeyRejectsInvalidLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        monetico_usable_key('1234');
    }

    public function testMoneticoSealIsStableAndIgnoresMac(): void
    {
        $fields = ['TPE' => '1234567', 'montant' => '62.73EUR', 'reference' => 'BABC123'];
        $seal = monetico_seal($fields, self::MONETICO_KEY);

        self::assertMatchesRegularExpression('/^[0-9A-F]{40}$/', $seal);
        self::assertSame($seal, monetico_seal(array_reverse($fields, true), self::MONETICO_KEY));
        self::assertSame($seal, monetico_seal($fields + ['MAC' => 'INVALID'], self::MONETICO_KEY));
        self::assertNotSame($seal, monetico_seal(array_replace($fields, ['montant' => '62.74EUR']), self::MONETICO_KEY));
    }

    public function testMoneticoAmountRoundTrip(): void
    {
        self::assertSame('62.73EUR', monetico_amount(6273));
        self::assertSame(6273, monetico_amount_cents('62.73EUR'));
        self::assertSame(6200, monetico_amount_cents('62 EUR'));
        self::assertSame(6273, monetico_amount_cents('62,73 eur'));
        self::assertNull(monetico_amount_cents('62.73'));
        self::assertNull(monetico_amount_cents('abcEUR'));
    }

    public function testMoneticoReturnCodes(): void
    {
        self::assertTrue(monetico_return_is_paid('paiement'));
        self::assertTrue(monetico_return_is_paid('PAYETEST'));
        self::assertFalse(monetico_return_is_paid('Annulation'));
        self::assertTrue(monetico_return_is_cancelled('Annulation'));
        self::assertTrue(monetico_return_is_cancelled('annulation_par_le_client'));
    }

    public function testMoneticoParseAnswerHandlesOneLineAndLines(): void
    {
        self::assertSame(['cdr' => '1', 'lib' => 'OK'], monetico_parse_answer('cdr=1&lib=OK'));
        self::assertSame(['cdr' => '1', 'numero_dossier' => 'D42'], monetico_parse_answer("cdr=1\nnumero_dossier=D42"));
        self::assertSame([], monetico_parse_answer('   '));
    }

    public function testMoneticoPaymentFieldsCarrySignatureAndReturnUrls(): void
    {
        $config = ['tpe' => '1234567', 'key' => self::MONETICO_KEY, 'societe' => 'BROC01', 'mode' => 'test'];
        $fields = monetico_payment_fields(['id' => 7], $config, 6273, 'BABC123', 'client@example.fr', 'Ma boutique', 'https://exemple.fr');

        self::assertSame('1234567', $fields['TPE']);
        self::assertSame('62.73EUR', $fields['montant']);
        self::assertSame('BABC123', $fields['reference']);
        self::assertSame('https://exemple.fr/monetico-return.php?result=ok', $fields['url_retour_ok']);
        self::assertSame('https://exemple.fr/monetico-return.php?result=err', $fields['url_retour_err']);
        self::assertArrayHasKey('MAC', $fields);
        self::assertSame(monetico_seal($fields, self::MONETICO_KEY), $fields['MAC']);
        self::assertSame('https://p.monetico-services.com/test/paiement.cgi', monetico_payment_url($config));
        self::assertSame('https://p.monetico-services.com/paiement.cgi', monetico_payment_url(['mode' => 'production'] + $config));
    }

    public function testPaypalValueCents(): void
    {
        self::assertSame(6273, paypal_value_cents('62.73'));
        self::assertSame(6200, paypal_value_cents('62'));
        self::assertSame(6200, paypal_value_cents('62.0'));
        self::assertNull(paypal_value_cents('62.739'));
        self::assertNull(paypal_value_cents('-1.00'));
        self::assertNull(paypal_value_cents(''));
    }

    public function testPaypalCaptureDataAndPaidState(): void
    {
        $paid = [
            'status' => 'COMPLETED',
            'payer' => ['email_address' => 'client@example.fr', 'name' => ['given_name' => 'Ada', 'surname' => 'Lovelace']],
            'purchase_units' => [[
                'payments' => ['captures' => [[
                    'id' => 'CAP-1',
                    'status' => 'COMPLETED',
                    'amount' => ['value' => '62.73', 'currency_code' => 'eur'],
                ]]],
            ]],
        ];
        $data = paypal_capture_data($paid);
        self::assertSame('COMPLETED', $data['order_status']);
        self::assertSame('CAP-1', $data['capture_id']);
        self::assertSame(6273, $data['amount_cents']);
        self::assertSame('EUR', $data['currency']);
        self::assertSame('client@example.fr', $data['email']);
        self::assertSame('Ada Lovelace', $data['name']);
        self::assertTrue(paypal_is_paid($paid));

        $approvedOnly = ['status' => 'APPROVED', 'purchase_units' => [['amount' => ['value' => '62.73', 'currency_code' => 'EUR']]]];
        self::assertFalse(paypal_is_paid($approvedOnly));
        self::assertSame(6273, paypal_capture_data($approvedOnly)['amount_cents']);

        $pendingCapture = $paid;
        $pendingCapture['purchase_units'][0]['payments']['captures'][0]['status'] = 'PENDING';
        self::assertFalse(paypal_is_paid($pendingCapture));
    }

    public function testPaypalApproveUrlAcceptsApproveAndPayerAction(): void
    {
        self::assertSame('https://paypal.test/approve', paypal_approve_url(['links' => [
            ['rel' => 'self', 'href' => 'https://paypal.test/self'],
            ['rel' => 'approve', 'href' => 'https://paypal.test/approve'],
        ]]));
        self::assertSame('https://paypal.test/payer', paypal_approve_url(['links' => [
            ['rel' => 'payer-action', 'href' => 'https://paypal.test/payer'],
        ]]));
        self::assertSame('', paypal_approve_url(['links' => []]));
    }
}
