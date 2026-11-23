<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\PlatformFeeInvoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class QPayPaymentTest extends TestCase
{
    use BuildsPlatform;
    use RefreshDatabase;

    private const KEY = 'test-qpay-key';

    private const IV = 'test-qpay-iv';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json']);
        Notification::fake();
        config([
            'services.qpay.url' => 'https://qpay.test/qpay/odrum/',
            'services.qpay.scode' => '0401',
            'services.qpay.encryption_key' => self::KEY,
            'services.qpay.encryption_iv' => self::IV,
        ]);
        Setting::put('platform_fee_enabled', true);
        Setting::put('platform_fee_amount', 250000);
    }

    /** @return array{0: User, 1: PlatformFeeInvoice} */
    private function tspWithInvoice(): array
    {
        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $this->actingAs($inputter)->get(route('tsp.registration.show'))->assertOk();

        return [$inputter, $tsp->feeInvoices()->firstOrFail()];
    }

    /** Reverses App\Services\QPay::encrypt, as QPay does. */
    private function decrypt(string $token): array
    {
        $plain = openssl_decrypt(base64_decode($token), 'AES-128-CTR', hash('sha256', self::KEY), 0, substr(hash('sha256', self::IV), 0, 16));

        return json_decode($plain, true);
    }

    public function test_paying_with_qpay_redirects_to_qpay_with_the_encrypted_invoice(): void
    {
        [$inputter, $invoice] = $this->tspWithInvoice();

        $url = $this->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful()->json('data.redirect');

        $this->assertStringStartsWith('https://qpay.test/qpay/odrum/', $url);
        $payload = $this->decrypt(substr($url, strlen('https://qpay.test/qpay/odrum/')));
        $this->assertSame($inputter->email, $payload['em']);
        $this->assertSame('0401', $payload['scode']);
        $this->assertEquals(250000, (float) $payload['am']);
        $this->assertSame('qpay', $invoice->fresh()->method);
    }

    public function test_a_successful_qpay_return_marks_the_invoice_as_paid_and_unlocks_access(): void
    {
        [, $invoice] = $this->tspWithInvoice();
        $this->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();

        $this->post(route('tsp.registration.qpay'), ['gateway' => 'qpay', 'status' => 'successful', 'ref' => 'Q123'])->assertSuccessful();

        $invoice->refresh();
        $this->assertSame(PlatformFeeInvoice::PAID, $invoice->status);
        $this->assertSame(['status' => 'successful', 'ref' => 'Q123'], $invoice->gateway_response);
        $this->assertNotNull($invoice->paid_at);
        $this->assertNotNull($invoice->institution->fresh()->fee_paid_until);

        // Returning again (e.g. a reload) is idempotent and returns success.
        $this->post(route('tsp.registration.qpay'), ['gateway' => 'qpay', 'status' => 'successful'])->assertSuccessful();
    }

    public function test_qpay_return_with_payment_success_and_ref_marks_invoice_paid(): void
    {
        [, $invoice] = $this->tspWithInvoice();
        $this->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();

        $this->post(route('tsp.registration.qpay'), ['payment' => 'success', 'ref' => $invoice->reference])->assertSuccessful();

        $invoice->refresh();
        $this->assertSame(PlatformFeeInvoice::PAID, $invoice->status);
        $this->assertNotNull($invoice->institution->fresh()->fee_paid_until);
    }

    public function test_a_failed_qpay_return_leaves_the_invoice_payable(): void
    {
        [, $invoice] = $this->tspWithInvoice();
        $this->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();

        $this->post(route('tsp.registration.qpay'), ['gateway' => 'qpay', 'status' => 'failed'])->assertStatus(422);

        $this->assertSame(PlatformFeeInvoice::UNPAID, $invoice->fresh()->status);
    }

    public function test_a_qpay_return_without_a_started_qpay_payment_changes_nothing(): void
    {
        [, $invoice] = $this->tspWithInvoice();

        $this->post(route('tsp.registration.qpay'), ['gateway' => 'qpay', 'status' => 'successful'])->assertStatus(422);

        $this->assertSame(PlatformFeeInvoice::UNPAID, $invoice->fresh()->status);
    }

    public function test_a_qpay_return_only_touches_the_payers_own_institution(): void
    {
        [, $invoice] = $this->tspWithInvoice();
        $this->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();

        $other = $this->tspUser($this->tspInstitution(), User::INPUTTER);
        $this->actingAs($other)->post(route('tsp.registration.qpay'), ['gateway' => 'qpay', 'status' => 'successful'])->assertStatus(422);

        $this->assertSame(PlatformFeeInvoice::UNPAID, $invoice->fresh()->status);
    }

    public function test_qpay_without_configuration_gives_a_clear_message(): void
    {
        config(['services.qpay.scode' => null]);
        [, $invoice] = $this->tspWithInvoice();

        $this->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])
            ->assertStatus(422)->assertJsonFragment(['QPay is not available at the moment. Try another payment method.']);
        $this->assertNull($invoice->fresh()->method);
    }

    public function test_paystack_and_flutterwave_are_not_accepted(): void
    {
        [, $invoice] = $this->tspWithInvoice();

        $this->post(route('tsp.registration.pay', $invoice), ['method' => 'paystack'])->assertStatus(422);
        $this->post(route('tsp.registration.pay', $invoice), ['method' => 'flutterwave'])->assertStatus(422);
    }

    public function test_qpay_webhook_health_check(): void
    {
        $this->get(route('webhooks.qpay'))->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_qpay_webhook_updates_invoice_to_paid(): void
    {
        [$inputter, $invoice] = $this->tspWithInvoice();
        $this->actingAs($inputter)->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();
        $ref = $invoice->fresh()->gateway_reference;

        // Webhook arrives unauthenticated (server-to-server)
        $response = $this->post(route('webhooks.qpay'), [
            'status' => 'successful',
            'reference' => $ref,
            'amount' => (string) $invoice->amount,
        ])->assertOk();

        $response->assertJson(['status' => 'success']);
        $this->assertSame(PlatformFeeInvoice::PAID, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
        $this->assertNotNull($invoice->institution->fresh()->fee_paid_until);
    }

    public function test_qpay_webhook_handles_failed_status(): void
    {
        [$inputter, $invoice] = $this->tspWithInvoice();
        $this->actingAs($inputter)->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();

        $this->post(route('webhooks.qpay'), [
            'status' => 'failed',
            'reference' => $invoice->fresh()->gateway_reference,
        ])->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(PlatformFeeInvoice::UNPAID, $invoice->fresh()->status);
    }

    public function test_qpay_webhook_with_new_status_and_new_ref_format(): void
    {
        [$inputter, $invoice] = $this->tspWithInvoice();
        $this->actingAs($inputter)->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();

        // QPay sends newStatus and newRef (e.g. EOFF1_4444_1794821012)
        $qpayRef = 'EOFF' . $invoice->id . '_4444_' . time();
        $response = $this->post(route('webhooks.qpay'), [
            'newStatus' => 'successful',
            'newRef' => $qpayRef,
        ])->assertOk();

        $response->assertJson(['status' => 'success', 'invoice' => $invoice->reference]);
        $this->assertSame(PlatformFeeInvoice::PAID, $invoice->fresh()->status);
        $this->assertSame($qpayRef, $invoice->fresh()->gateway_reference);
    }

    public function test_qpay_webhook_redirects_browser_request_to_frontend(): void
    {
        [$inputter, $invoice] = $this->tspWithInvoice();
        $this->actingAs($inputter)->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();

        $qpayRef = 'EOFF' . $invoice->id . '_4444_' . time();
        $response = $this->withHeaders(['Accept' => 'text/html'])
            ->get(route('webhooks.qpay', ['newStatus' => 'successful', 'newRef' => $qpayRef]));

        $response->assertRedirect();
        $this->assertStringContainsString('/tsp/registration?payment=success', $response->headers->get('Location'));
        $this->assertSame(PlatformFeeInvoice::PAID, $invoice->fresh()->status);
    }

    public function test_paying_with_qpay_api_sends_credentials_headers_and_payload_and_redirects_to_checkout_url(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.app_id' => '30',
            'services.qpay.username' => 'fmrr_app_user',
            'services.qpay.password' => 'securepassword123',
            'services.qpay.initiate_url' => 'http://localhost/qpay/api/payment/initiate',
            'services.qpay.base_url' => 'http://localhost/qpay/api',
            'services.qpay.url' => 'http://localhost/qpay/api/payment/initiate',
        ]);

        Http::fake([
            'http://localhost/qpay/api/payment/initiate' => function ($request) {
                $this->assertSame('30', $request->header('ID')[0] ?? null);
                $this->assertSame('fmrr_app_user', $request->header('Username')[0] ?? null);
                $this->assertSame('securepassword123', $request->header('Password')[0] ?? null);
                $this->assertStringContainsString('application/json', $request->header('Accept')[0] ?? '');

                $data = $request->data();
                $this->assertSame('30', $data['appID']);
                $this->assertEquals(250000, $data['amount']);
                $this->assertSame('NGN', $data['currency']);
                $this->assertNotEmpty($data['email']);
                $this->assertNotEmpty($data['first_name']);
                $this->assertNotEmpty($data['last_name']);
                $this->assertSame('platform_fee', $data['metadata']['type']);

                return Http::response([
                    'status' => 'success',
                    'message' => 'Transaction initialized. Redirect user to checkout_url.',
                    'data' => [
                        'checkout_url' => 'http://localhost/qpay/checkout/PAY-73829102',
                        'reference' => 'PAY-73829102',
                        'invoice_url' => 'http://localhost/qpay/invoices/invoice-PAY-73829102.pdf',
                    ],
                ], 200);
            },
        ]);

        [$inputter, $invoice] = $this->tspWithInvoice();

        $response = $this->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();
        $this->assertSame('http://localhost/qpay/checkout/PAY-73829102', $response->json('data.redirect'));

        $invoice->refresh();
        $this->assertSame('qpay', $invoice->method);
        $this->assertSame('PAY-73829102', $invoice->gateway_reference);
    }

    public function test_qpay_api_return_with_reference_verifies_server_to_server_and_marks_invoice_paid(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.app_id' => '30',
            'services.qpay.username' => 'fmrr_app_user',
            'services.qpay.password' => 'securepassword123',
            'services.qpay.initiate_url' => 'http://localhost/qpay/api/payment/initiate',
            'services.qpay.base_url' => 'http://localhost/qpay/api',
            'services.qpay.url' => 'http://localhost/qpay/api/payment/initiate',
        ]);

        Http::fake([
            'http://localhost/qpay/api/payment/initiate' => Http::response([
                'status' => 'success',
                'message' => 'Transaction initialized. Redirect user to checkout_url.',
                'data' => [
                    'checkout_url' => 'http://localhost/qpay/checkout/PAY-73829102',
                    'reference' => 'PAY-73829102',
                ],
            ], 200),
            'http://localhost/qpay/api/payment/verify/PAY-73829102' => function ($request) {
                $this->assertSame('30', $request->header('ID')[0] ?? null);
                $this->assertSame('fmrr_app_user', $request->header('Username')[0] ?? null);
                $this->assertSame('securepassword123', $request->header('Password')[0] ?? null);

                return Http::response([
                    'status' => 'success',
                    'message' => 'Transaction verified.',
                    'data' => [
                        'reference' => 'PAY-73829102',
                        'amount' => '250000.00',
                        'currency' => 'NGN',
                        'status' => 'successful',
                        'receipt_url' => 'http://localhost/qpay/receipts/receipt-PAY-73829102.pdf',
                    ],
                ], 200);
            },
        ]);

        [$inputter, $invoice] = $this->tspWithInvoice();
        $this->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])->assertSuccessful();

        $this->post(route('tsp.registration.qpay'), [
            'gateway' => 'qpay',
            'status' => 'successful',
            'reference' => 'PAY-73829102',
        ])->assertSuccessful();

        $invoice->refresh();
        $this->assertSame(PlatformFeeInvoice::PAID, $invoice->status);
        $this->assertSame('PAY-73829102', $invoice->gateway_reference);
        $this->assertNotNull($invoice->paid_at);
        $this->assertNotNull($invoice->institution->fresh()->fee_paid_until);
    }

    public function test_qpay_api_handles_gateway_failure_response(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.app_id' => '30',
            'services.qpay.username' => 'fmrr_app_user',
            'services.qpay.password' => 'securepassword123',
            'services.qpay.initiate_url' => 'http://localhost/qpay/api/payment/initiate',
            'services.qpay.base_url' => 'http://localhost/qpay/api',
            'services.qpay.url' => 'http://localhost/qpay/api/payment/initiate',
        ]);

        Http::fake([
            'http://localhost/qpay/api/payment/initiate' => Http::response([
                'status' => 'error',
                'message' => 'The selected appID is invalid.',
            ], 422),
        ]);

        [, $invoice] = $this->tspWithInvoice();

        $this->post(route('tsp.registration.pay', $invoice), ['method' => 'qpay'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');

        $this->assertNull($invoice->fresh()->method);
    }

    public function test_bid_payment_with_qpay_api_initiates_and_stores_qpay_reference(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.app_id' => '30',
            'services.qpay.username' => 'fmrr_app_user',
            'services.qpay.password' => 'securepassword123',
            'services.qpay.initiate_url' => 'http://localhost/qpay/api/payment/initiate',
            'services.qpay.base_url' => 'http://localhost/qpay/api',
            'services.qpay.url' => 'http://localhost/qpay/api/payment/initiate',
            'services.qpay.bid_scode' => '05019999',
        ]);

        Http::fake([
            'http://localhost/qpay/api/payment/initiate' => function ($request) {
                $this->assertSame('30', $request->header('ID')[0] ?? null);
                $this->assertSame('fmrr_app_user', $request->header('Username')[0] ?? null);
                $this->assertSame('securepassword123', $request->header('Password')[0] ?? null);

                $data = $request->data();
                $this->assertSame('30', $data['appID']);
                $this->assertEquals(100000, $data['amount']);
                $this->assertSame('bid_payment', $data['metadata']['type']);

                return Http::response([
                    'status' => 'success',
                    'message' => 'Transaction initialized.',
                    'data' => [
                        'checkout_url' => 'http://localhost/qpay/checkout/PAY-BID-999',
                        'reference' => 'PAY-BID-999',
                    ],
                ], 200);
            },
            'http://localhost/qpay/api/payment/verify/PAY-BID-999' => Http::response([
                'status' => 'success',
                'data' => [
                    'reference' => 'PAY-BID-999',
                    'status' => 'successful',
                ],
            ], 200),
        ]);

        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $offer = \App\Models\Offer::forceCreate([
            'institution_id' => $tsp->id,
            'created_by' => $inputter->id,
            'status' => \App\Models\Offer::LIVE,
            'security_type' => 'commercial_papers',
            'open_at' => now()->subDay(),
            'close_at' => now()->addDays(5),
            'offer_size' => 1000000,
            'issuer_name' => 'Acme Plc',
            'issue_description' => 'Acme CP',
            'issue_series' => 'Series 1',
            'issuer_type' => 'Corporate',
            'day_count_fraction' => 'Actual/365',
            'investor_universe' => json_encode(['retail', 'qii']),
            'price_discovery_method' => \App\Models\Offer::COMPETITIVE,
            'tenor' => '270 days',
            'maturity_date' => now()->addDays(280)->toDateString(),
            'allotment_date' => now()->addDays(7)->toDateString(),
            'reference' => 'OFFER-TEST-1',
        ]);

        $investor = $this->retailInvestor();
        $bid = \App\Models\Bid::forceCreate([
            'offer_id' => $offer->id,
            'user_id' => $investor->id,
            'reference' => 'BID-TEST-1',
            'investor_category' => 'retail',
            'investor_type' => 'individual',
            'investor_name' => 'Investor User',
            'amount' => 100000,
            'rate' => 15.0,
            'csd_name' => 'CSCS',
            'csd_account_number' => 'C123',
            'bank_name' => 'Demo Bank',
            'bank_account_name' => 'Investor',
            'bank_account_number' => '1234567890',
            'payee_type' => 'sponsor',
            'payee_name' => 'Lead Securities Ltd',
            'status' => \App\Models\Bid::ACTIVE,
            'payment_status' => \App\Models\Bid::PAYMENT_PENDING,
        ]);

        $url = app(\App\Services\BidService::class)->startQPay($bid, $investor);
        $this->assertSame('http://localhost/qpay/checkout/PAY-BID-999', $url);
        $this->assertSame('PAY-BID-999', $bid->fresh()->payment_gateway_reference);

        // Confirm return
        $confirmed = app(\App\Services\BidService::class)->confirmQPayReturn($investor, 'successful', [], 'PAY-BID-999');
        $this->assertNotNull($confirmed);
        $this->assertSame(\App\Models\Bid::PAYMENT_PAID, $confirmed->payment_status);
    }

    public function test_get_invoice_metadata_from_qpay_api(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.invoice_url' => 'http://localhost/qpay/api/payment/invoice',
        ]);

        Http::fake([
            'http://localhost/qpay/api/payment/invoice/PAY-73829102' => function ($request) {
                $this->assertSame('application/json', $request->header('Accept')[0] ?? '');
                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'reference' => 'PAY-73829102',
                        'invoice_url' => 'http://localhost/qpay/invoices/invoice-PAY-73829102.pdf',
                    ],
                ], 200);
            },
        ]);

        $qpay = app(\App\Services\QPay::class);
        $invoiceData = $qpay->getInvoice('PAY-73829102');

        $this->assertNotNull($invoiceData);
        $this->assertSame('PAY-73829102', $invoiceData['reference']);
        $this->assertSame('http://localhost/qpay/invoices/invoice-PAY-73829102.pdf', $invoiceData['invoice_url']);
    }

    public function test_stream_invoice_pdf_from_qpay_api(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.invoice_url' => 'http://localhost/qpay/api/payment/invoice',
        ]);

        $fakePdfContent = "%PDF-1.7\n1 0 obj\n<< /Title (Payment Invoice) >>\nendobj\ntrailer\n<< /Size 1 >>\n%%EOF";

        Http::fake([
            'http://localhost/qpay/api/payment/invoice/PAY-73829102' => function ($request) use ($fakePdfContent) {
                return Http::response($fakePdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="invoice-PAY-73829102.pdf"',
                ]);
            },
        ]);

        $qpay = app(\App\Services\QPay::class);
        $response = $qpay->streamInvoicePdf('PAY-73829102');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline; filename="invoice-PAY-73829102.pdf"', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($fakePdfContent, $response->getContent());
    }

    public function test_tsp_can_download_invoice_pdf_via_route(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.invoice_url' => 'http://localhost/qpay/api/payment/invoice',
        ]);

        $fakePdfContent = "%PDF-1.7 mock invoice binary";
        Http::fake([
            'http://localhost/qpay/api/payment/invoice/PAY-FEE-123' => Http::response($fakePdfContent, 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        [$inputter, $invoice] = $this->tspWithInvoice();
        $invoice->update(['gateway_reference' => 'PAY-FEE-123']);

        $response = $this->actingAs($inputter)->get(route('tsp.registration.invoice-pdf', $invoice));
        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame($fakePdfContent, $response->getContent());
    }

    public function test_investor_can_download_bid_invoice_pdf_via_route(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.invoice_url' => 'http://localhost/qpay/api/payment/invoice',
        ]);

        $fakePdfContent = "%PDF-1.7 mock bid invoice";
        Http::fake([
            'http://localhost/qpay/api/payment/invoice/PAY-BID-456' => Http::response($fakePdfContent, 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $offer = \App\Models\Offer::forceCreate([
            'institution_id' => $tsp->id,
            'created_by' => $inputter->id,
            'status' => \App\Models\Offer::LIVE,
            'security_type' => 'commercial_papers',
            'open_at' => now()->subDay(),
            'close_at' => now()->addDays(5),
            'offer_size' => 1000000,
            'issuer_name' => 'Acme Plc',
            'issue_description' => 'Acme CP',
            'issue_series' => 'Series 1',
            'issuer_type' => 'Corporate',
            'day_count_fraction' => 'Actual/365',
            'investor_universe' => json_encode(['retail', 'qii']),
            'price_discovery_method' => \App\Models\Offer::COMPETITIVE,
            'tenor' => '270 days',
            'maturity_date' => now()->addDays(280)->toDateString(),
            'allotment_date' => now()->addDays(7)->toDateString(),
            'reference' => 'OFFER-TEST-PDF',
        ]);

        $investor = $this->retailInvestor();
        $bid = \App\Models\Bid::forceCreate([
            'offer_id' => $offer->id,
            'user_id' => $investor->id,
            'reference' => 'BID-PDF-1',
            'investor_category' => 'retail',
            'investor_type' => 'individual',
            'investor_name' => 'Investor User',
            'amount' => 50000,
            'rate' => 15.0,
            'payee_type' => 'sponsor',
            'payee_name' => 'Lead Securities Ltd',
            'status' => \App\Models\Bid::ACTIVE,
            'payment_status' => \App\Models\Bid::PAYMENT_PENDING,
            'payment_gateway_reference' => 'PAY-BID-456',
        ]);

        $response = $this->actingAs($investor)->get(route('investor.bids.invoice-pdf', $bid));
        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame($fakePdfContent, $response->getContent());
    }

    public function test_get_receipt_metadata_from_qpay_api(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.receipt_url' => 'http://localhost/qpay/api/payment/receipt',
        ]);

        Http::fake([
            'http://localhost/qpay/api/payment/receipt/PAY-73829102' => function ($request) {
                $this->assertSame('application/json', $request->header('Accept')[0] ?? '');
                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'reference' => 'PAY-73829102',
                        'receipt_url' => 'http://localhost/qpay/receipts/receipt-PAY-73829102.pdf',
                    ],
                ], 200);
            },
        ]);

        $qpay = app(\App\Services\QPay::class);
        $receiptData = $qpay->getReceipt('PAY-73829102');

        $this->assertNotNull($receiptData);
        $this->assertSame('PAY-73829102', $receiptData['reference']);
        $this->assertSame('http://localhost/qpay/receipts/receipt-PAY-73829102.pdf', $receiptData['receipt_url']);
    }

    public function test_stream_receipt_pdf_from_qpay_api(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.receipt_url' => 'http://localhost/qpay/api/payment/receipt',
        ]);

        $fakePdfContent = "%PDF-1.7\n1 0 obj\n<< /Title (Payment Receipt) >>\nendobj\ntrailer\n<< /Size 1 >>\n%%EOF";

        Http::fake([
            'http://localhost/qpay/api/payment/receipt/PAY-73829102' => function ($request) use ($fakePdfContent) {
                return Http::response($fakePdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="receipt-PAY-73829102.pdf"',
                ]);
            },
        ]);

        $qpay = app(\App\Services\QPay::class);
        $response = $qpay->streamReceiptPdf('PAY-73829102');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline; filename="receipt-PAY-73829102.pdf"', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($fakePdfContent, $response->getContent());
    }

    public function test_tsp_can_download_paid_receipt_pdf_via_route(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.receipt_url' => 'http://localhost/qpay/api/payment/receipt',
        ]);

        $fakePdfContent = "%PDF-1.7 mock receipt binary";
        Http::fake([
            'http://localhost/qpay/api/payment/receipt/PAY-FEE-PAID' => Http::response($fakePdfContent, 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        [$inputter, $invoice] = $this->tspWithInvoice();
        $invoice->update([
            'gateway_reference' => 'PAY-FEE-PAID',
            'status' => \App\Models\PlatformFeeInvoice::PAID,
        ]);

        $response = $this->actingAs($inputter)->get(route('tsp.registration.receipt-pdf', $invoice));
        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame($fakePdfContent, $response->getContent());
    }

    public function test_investor_can_download_paid_bid_receipt_pdf_via_route(): void
    {
        config([
            'services.qpay.driver' => 'api',
            'services.qpay.receipt_url' => 'http://localhost/qpay/api/payment/receipt',
        ]);

        $fakePdfContent = "%PDF-1.7 mock bid receipt";
        Http::fake([
            'http://localhost/qpay/api/payment/receipt/PAY-BID-PAID' => Http::response($fakePdfContent, 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $offer = \App\Models\Offer::forceCreate([
            'institution_id' => $tsp->id,
            'created_by' => $inputter->id,
            'status' => \App\Models\Offer::LIVE,
            'security_type' => 'commercial_papers',
            'open_at' => now()->subDay(),
            'close_at' => now()->addDays(5),
            'offer_size' => 1000000,
            'issuer_name' => 'Acme Plc',
            'issue_description' => 'Acme CP',
            'issue_series' => 'Series 1',
            'issuer_type' => 'Corporate',
            'day_count_fraction' => 'Actual/365',
            'investor_universe' => json_encode(['retail', 'qii']),
            'price_discovery_method' => \App\Models\Offer::COMPETITIVE,
            'tenor' => '270 days',
            'maturity_date' => now()->addDays(280)->toDateString(),
            'allotment_date' => now()->addDays(7)->toDateString(),
            'reference' => 'OFFER-TEST-RECEIPT',
        ]);

        $investor = $this->retailInvestor();
        $bid = \App\Models\Bid::forceCreate([
            'offer_id' => $offer->id,
            'user_id' => $investor->id,
            'reference' => 'BID-RECEIPT-1',
            'investor_category' => 'retail',
            'investor_type' => 'individual',
            'investor_name' => 'Investor User',
            'amount' => 50000,
            'rate' => 15.0,
            'payee_type' => 'sponsor',
            'payee_name' => 'Lead Securities Ltd',
            'status' => \App\Models\Bid::ACTIVE,
            'payment_status' => \App\Models\Bid::PAYMENT_PAID,
            'payment_gateway_reference' => 'PAY-BID-PAID',
        ]);

        $response = $this->actingAs($investor)->get(route('investor.bids.receipt-pdf', $bid));
        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame($fakePdfContent, $response->getContent());
    }
}
