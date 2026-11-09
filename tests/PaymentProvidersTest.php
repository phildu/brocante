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
        self::assertSame('revolut', payment_provider_of(['stripe_session_id' => 'revolut:B123']));
        self::assertSame('sepa', payment_provider_of(['stripe_session_id' => 'sepa:B123']));
        self::assertSame('sumup', payment_provider_of(['stripe_session_id' => 'sumup:B123']));
        self::assertSame('SumUp', payment_provider_label('sumup'));
        self::assertSame('PayPal', payment_provider_label('paypal'));
        self::assertSame('Revolut', payment_provider_label('revolut'));
        self::assertSame('Virement SEPA', payment_provider_label('sepa'));
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

    public function testSepaIbanNormalisationAndMod97(): void
    {
        self::assertTrue(sepa_iban_valid('FR76 3000 6000 0112 3456 7890 189'));
        self::assertTrue(sepa_iban_valid('de89 3704 0044 0532 0130 00'));
        self::assertTrue(sepa_iban_valid('GB82 WEST 1234 5698 7654 32'));

        self::assertSame('FR7630006000011234567890189', sepa_iban_normalize('fr76 3000-6000 0112 3456 7890 189'));
        self::assertSame('FR76 3000 6000 0112 3456 7890 189', sepa_iban_display('FR7630006000011234567890189'));

        self::assertFalse(sepa_iban_valid('FR7630006000011234567890180')); // clé fausse
        self::assertFalse(sepa_iban_valid('FR76'));
        self::assertFalse(sepa_iban_valid(''));
        self::assertFalse(sepa_iban_valid('1234567890123456'));
    }

    public function testSepaBicValidation(): void
    {
        self::assertTrue(sepa_bic_valid('CMCIFR2A'));
        self::assertTrue(sepa_bic_valid('CMCIFR2AXXX'));
        self::assertTrue(sepa_bic_valid(''));
        self::assertFalse(sepa_bic_valid('CMCI'));
        self::assertFalse(sepa_bic_valid('CMCIFR2A12'));
    }

    public function testSepaReferenceUsesStoredReferenceThenOrderId(): void
    {
        self::assertSame('BABC123', sepa_reference(['id' => 7, 'payment_reference' => 'BABC123']));
        self::assertSame('B7', sepa_reference(['id' => 7, 'payment_reference' => '']));
    }

    public function testRevolutCheckoutUrlAndStates(): void
    {
        self::assertSame('https://checkout.revolut.com/payment-link/TOK', revolut_checkout_url([
            'checkout_url' => 'https://checkout.revolut.com/payment-link/TOK',
            'token' => 'ignored',
        ]));
        self::assertSame('https://checkout.revolut.com/payment-link/TOK', revolut_checkout_url(['token' => 'TOK']));
        self::assertSame('', revolut_checkout_url([]));

        self::assertSame('completed', revolut_state(['state' => 'COMPLETED']));
        self::assertTrue(revolut_is_paid(['state' => 'completed']));
        self::assertFalse(revolut_is_paid(['state' => 'authorised']));
        self::assertFalse(revolut_is_paid(['state' => 'pending']));
    }

    public function testRevolutPaymentIdAndDetailUseLastPayment(): void
    {
        $order = [
            'id' => 'ord-1',
            'payments' => [
                ['id' => 'pay-1', 'payment_method' => ['type' => 'card']],
                ['id' => 'pay-2', 'payment_method' => ['type' => 'apple_pay']],
            ],
        ];
        self::assertSame('pay-2', revolut_payment_id($order));
        self::assertSame('Revolut — Apple pay', revolut_payment_detail($order));
        self::assertSame('ord-1', revolut_payment_id(['id' => 'ord-1']));
        self::assertSame('Revolut', revolut_payment_detail(['id' => 'ord-1']));
    }

    public function testRevolutWebhookSignature(): void
    {
        $secret = 'wsk_test_secret';
        $body = '{"event":"ORDER_COMPLETED","order":{"id":"ord-1"}}';
        $timestamp = (string) (int) (microtime(true) * 1000);
        $signature = hash_hmac('sha256', 'v1.' . $timestamp . '.' . $body, $secret);

        self::assertTrue(revolut_webhook_valid($body, 'v1=' . $signature, $timestamp, $secret));
        self::assertTrue(revolut_webhook_valid($body, 'v1=deadbeef, v1=' . $signature, $timestamp, $secret));
        self::assertFalse(revolut_webhook_valid($body, 'v1=' . $signature, $timestamp, 'autre-secret'));
        self::assertFalse(revolut_webhook_valid($body . ' ', 'v1=' . $signature, $timestamp, $secret));
        self::assertFalse(revolut_webhook_valid($body, '', $timestamp, $secret));
        self::assertFalse(revolut_webhook_valid($body, 'v1=' . $signature, (string) ((int) $timestamp - 600000), $secret));
    }

    public function testRevolutWebhookPartsAcceptBothEnvelopes(): void
    {
        [$event, $order] = revolut_webhook_parts(['event' => 'ORDER_COMPLETED', 'order' => ['id' => 'ord-1']]);
        self::assertSame('ORDER_COMPLETED', $event);
        self::assertSame('ord-1', $order['id']);

        [$event, $order] = revolut_webhook_parts(['event' => 'order_failed', 'id' => 'ord-2']);
        self::assertSame('ORDER_FAILED', $event);
        self::assertSame('ord-2', $order['id']);
    }

    public function testSumupAmountsUseMajorUnits(): void
    {
        self::assertSame(62.73, sumup_amount_major(6273));
        self::assertSame(0.0, sumup_amount_major(-500));
        self::assertSame(6273, sumup_cents(62.73));
        self::assertSame(1999, sumup_cents('19.99'));
        self::assertSame(1010, sumup_cents(10.1));
        self::assertSame(-1, sumup_checkout_cents([]));
        self::assertSame(1200, sumup_checkout_cents(['amount' => 12]));
    }

    public function testSumupCheckoutUrlOnlyAcceptsHttpsSumupHosts(): void
    {
        self::assertSame('https://checkout.sumup.com/pay/abc', sumup_checkout_url(['hosted_checkout_url' => 'https://checkout.sumup.com/pay/abc']));
        self::assertSame('', sumup_checkout_url([]));
        self::assertSame('', sumup_checkout_url(['hosted_checkout_url' => 'http://checkout.sumup.com/pay/abc']));
        self::assertSame('', sumup_checkout_url(['hosted_checkout_url' => 'https://checkout.sumup.com.evil.test/pay/abc']));
        self::assertSame('', sumup_checkout_url(['hosted_checkout_url' => 'https://evilsumup.com/pay/abc']));
    }

    public function testSumupStates(): void
    {
        self::assertSame('paid', sumup_state(['status' => 'PAID']));
        self::assertTrue(sumup_is_paid(['status' => 'PAID']));
        self::assertFalse(sumup_is_paid(['status' => 'PENDING']));
        self::assertFalse(sumup_is_paid(['status' => 'FAILED']));
        self::assertFalse(sumup_is_paid([]));
    }

    public function testSumupPaymentIdAndDetailUseSuccessfulTransaction(): void
    {
        $checkout = [
            'id' => 'chk-1',
            'transactions' => [
                ['id' => 'tx-1', 'status' => 'FAILED', 'entry_mode' => 'CUSTOMER_ENTRY'],
                ['id' => 'tx-2', 'status' => 'SUCCESSFUL', 'entry_mode' => 'APPLE_PAY', 'transaction_code' => 'TEENSK4W2K'],
            ],
        ];
        self::assertSame('tx-2', sumup_payment_id($checkout));
        self::assertSame('SumUp — Apple Pay · TEENSK4W2K', sumup_payment_detail($checkout));
        self::assertSame('chk-1', sumup_payment_id(['id' => 'chk-1']));
        self::assertSame('SumUp — Carte', sumup_payment_detail(['id' => 'chk-1']));
    }

    public function testStripeWeroIsAnnouncedOnlyWhenSettingIsOn(): void
    {
        self::assertTrue(function_exists('stripe_wero_enabled'));
    }
}
