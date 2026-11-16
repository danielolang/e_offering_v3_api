<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\PlatformFeeInvoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
