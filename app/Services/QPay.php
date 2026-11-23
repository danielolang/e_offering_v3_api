<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * FMDQ QPay payment integration.
 *
 * Supports the server-to-server Payment API (/api/payment/initiate and
 * /api/payment/verify/{reference}) with application credentials header validation,
 * as well as the legacy odrum URL encryption scheme for backwards compatibility.
 */
class QPay
{
    private const CIPHER = 'AES-128-CTR';

    /**
     * Checks if QPay server-to-server REST API credentials are configured.
     */
    public function isApiConfigured(): bool
    {
        if (config('services.qpay.driver') === 'odrum') {
            return false;
        }

        $mainUrl = (string) config('services.qpay.url');
        if (str_contains($mainUrl, 'odrum')) {
            return false;
        }

        $initUrl = (string) (config('services.qpay.initiate_url') ?: $mainUrl);

        return !empty(config('services.qpay.app_id'))
            && !empty(config('services.qpay.username'))
            && !empty(config('services.qpay.password'))
            && $initUrl !== ''
            && !str_contains($initUrl, 'odrum');
    }

    /**
     * Initiate payment transaction via QPay API (/api/payment/initiate).
     *
     * @param  array{
     *     amount: float|int|string,
     *     email: string,
     *     first_name: string,
     *     last_name: string,
     *     phone?: ?string,
     *     company?: ?string,
     *     callback_url?: ?string,
     *     currency?: ?string,
     *     metadata?: ?array,
     *     appID?: ?string
     * }  $params
     * @return array{
     *     checkout_url: string,
     *     reference: string,
     *     invoice_url: ?string,
     *     raw: array
     * }
     *
     * @throws RuntimeException
     */
    public function initiate(array $params): array
    {
        $appId = (string) ($params['appID'] ?? config('services.qpay.app_id'));
        $username = (string) config('services.qpay.username');
        $password = (string) config('services.qpay.password');
        $url = (string) (config('services.qpay.initiate_url') ?: config('services.qpay.url'));

        if ($appId === '' || $username === '' || $password === '' || $url === '') {
            throw new RuntimeException('QPay is not configured: set QPAY_APP_ID, QPAY_USERNAME, QPAY_PASSWORD, and QPAY_URL/QPAY_INITIATE_URL.');
        }

        $payload = [
            'appID' => $appId,
            'amount' => (float) $params['amount'],
            'currency' => $params['currency'] ?? config('services.qpay.currency', 'NGN'),
            'email' => $params['email'],
            'first_name' => $params['first_name'],
            'last_name' => $params['last_name'],
            'phone' => $params['phone'] ?? null,
            'company' => $params['company'] ?? config('services.qpay.company', 'FMDQ Group'),
            'callback_url' => $params['callback_url'] ?? null,
            'metadata' => $params['metadata'] ?? null,
        ];

        $headers = [
            'ID' => $appId,
            'Username' => $username,
            'Password' => $password,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::error('QPay API connection failed', ['error' => $e->getMessage(), 'url' => $url]);
            throw new RuntimeException('Unable to reach QPay payment gateway. Please try again.');
        }

        if (! $response->successful()) {
            Log::error('QPay payment initiation error', [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
                'payload' => $payload,
            ]);

            $message = $response->json('message') ?? 'Payment initialization failed on QPay gateway.';
            throw new RuntimeException($message);
        }

        $data = $response->json();
        if (($data['status'] ?? '') !== 'success' || empty($data['data']['checkout_url'])) {
            Log::error('QPay payment initialization returned unsuccessful status', ['response' => $data]);
            throw new RuntimeException($data['message'] ?? 'Could not initialize payment with QPay.');
        }

        $checkoutUrl = $data['data']['checkout_url'];
        $reference = $data['data']['reference'] ?? '';

        $frontendUrl = config('services.qpay.frontend_url');
        if (!empty($frontendUrl) && !empty($reference) && str_contains($checkoutUrl, ':5173/checkout/')) {
            $checkoutUrl = rtrim($frontendUrl, '/').'/checkout/'.urlencode($reference);
        }

        return [
            'checkout_url' => $checkoutUrl,
            'reference' => $reference,
            'invoice_url' => $data['data']['invoice_url'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Server-to-server transaction verification (/api/payment/verify/{reference}).
     */
    public function verify(string $reference): ?array
    {
        $appId = (string) config('services.qpay.app_id');
        $username = (string) config('services.qpay.username');
        $password = (string) config('services.qpay.password');
        $baseUrl = rtrim((string) (config('services.qpay.base_url') ?: dirname((string) (config('services.qpay.initiate_url') ?: config('services.qpay.url')))), '/');

        if ($appId === '' || $username === '' || $password === '' || $baseUrl === '') {
            return null;
        }

        $verifyUrl = "{$baseUrl}/payment/verify/" . urlencode($reference);

        try {
            $response = Http::withHeaders([
                'ID' => $appId,
                'Username' => $username,
                'Password' => $password,
                'Accept' => 'application/json',
            ])->timeout(15)->get($verifyUrl);

            if ($response->successful()) {
                $data = $response->json();
                if (($data['status'] ?? '') === 'success' && isset($data['data'])) {
                    return $data['data'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('QPay verification request error', ['reference' => $reference, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Build the URL for QPay invoice endpoint (/api/payment/invoice/{reference}).
     */
    public function invoiceUrl(string $reference): string
    {
        $custom = (string) config('services.qpay.invoice_url');
        if ($custom !== '') {
            return rtrim($custom, '/').'/'.urlencode($reference);
        }

        $initiateUrl = (string) (config('services.qpay.initiate_url') ?: config('services.qpay.url'));
        if ($initiateUrl !== '' && str_contains($initiateUrl, '/payment/initiate')) {
            return preg_replace('#/payment/initiate/?$#', '/payment/invoice/'.urlencode($reference), $initiateUrl);
        }

        $base = (string) (config('services.qpay.base_url') ?: config('services.qpay.url') ?: 'http://localhost/qpay');
        return rtrim($base, '/').'/api/payment/invoice/'.urlencode($reference);
    }

    /**
     * Query QPay for invoice metadata via GET /api/payment/invoice/{reference}.
     *
     * @return array{reference: string, invoice_url: ?string}|null
     */
    public function getInvoice(string $reference): ?array
    {
        $url = $this->invoiceUrl($reference);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->get($url);

            if ($response->successful()) {
                $data = $response->json('data') ?? [];
                return [
                    'reference' => $data['reference'] ?? $reference,
                    'invoice_url' => $data['invoice_url'] ?? null,
                ];
            }

            Log::warning('QPay getInvoice request unsuccessful', [
                'reference' => $reference,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('QPay getInvoice failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Stream or download the official Payment Invoice PDF generated during initiation.
     */
    public function streamInvoicePdf(string $reference, bool $attachment = false): \Symfony\Component\HttpFoundation\Response
    {
        $url = $this->invoiceUrl($reference);
        $filename = 'invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '', $reference).'.pdf';
        $disposition = $attachment ? 'attachment' : 'inline';

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/pdf, application/octet-stream',
            ])->timeout(30)->get($url);

            if ($response->successful()) {
                $contentType = (string) $response->header('Content-Type');

                if (str_contains($contentType, 'pdf') || str_starts_with($response->body(), '%PDF')) {
                    return response($response->body(), 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
                        'Cache-Control' => 'no-cache, private',
                    ]);
                }

                $json = $response->json();
                if (!empty($json['data']['invoice_url'])) {
                    $pdfRes = Http::timeout(30)->get($json['data']['invoice_url']);
                    if ($pdfRes->successful() && (str_contains((string) $pdfRes->header('Content-Type'), 'pdf') || str_starts_with($pdfRes->body(), '%PDF'))) {
                        return response($pdfRes->body(), 200, [
                            'Content-Type' => 'application/pdf',
                            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
                            'Cache-Control' => 'no-cache, private',
                        ]);
                    }
                    return redirect()->away($json['data']['invoice_url']);
                }
            }

            $meta = $this->getInvoice($reference);
            if (!empty($meta['invoice_url'])) {
                return redirect()->away($meta['invoice_url']);
            }
        } catch (\Throwable $e) {
            Log::error('QPay streamInvoicePdf failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }

        abort(404, 'Payment invoice PDF could not be retrieved from QPay.');
    }

    /**
     * Build the URL for QPay receipt endpoint (/api/payment/receipt/{reference}).
     */
    public function receiptUrl(string $reference): string
    {
        $custom = (string) config('services.qpay.receipt_url');
        if ($custom !== '') {
            return rtrim($custom, '/').'/'.urlencode($reference);
        }

        $initiateUrl = (string) (config('services.qpay.initiate_url') ?: config('services.qpay.url'));
        if ($initiateUrl !== '' && str_contains($initiateUrl, '/payment/initiate')) {
            return preg_replace('#/payment/initiate/?$#', '/payment/receipt/'.urlencode($reference), $initiateUrl);
        }

        $base = (string) (config('services.qpay.base_url') ?: config('services.qpay.url') ?: 'http://localhost/qpay');
        return rtrim($base, '/').'/api/payment/receipt/'.urlencode($reference);
    }

    /**
     * Query QPay for receipt metadata via GET /api/payment/receipt/{reference}.
     *
     * @return array{reference: string, receipt_url: ?string}|null
     */
    public function getReceipt(string $reference): ?array
    {
        $url = $this->receiptUrl($reference);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->get($url);

            if ($response->successful()) {
                $data = $response->json('data') ?? [];
                return [
                    'reference' => $data['reference'] ?? $reference,
                    'receipt_url' => $data['receipt_url'] ?? null,
                ];
            }

            Log::warning('QPay getReceipt request unsuccessful', [
                'reference' => $reference,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('QPay getReceipt failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Stream or download the official Payment Receipt PDF generated for confirmed successful transactions.
     */
    public function streamReceiptPdf(string $reference, bool $attachment = false): \Symfony\Component\HttpFoundation\Response
    {
        $url = $this->receiptUrl($reference);
        $filename = 'receipt-'.preg_replace('/[^A-Za-z0-9_-]/', '', $reference).'.pdf';
        $disposition = $attachment ? 'attachment' : 'inline';

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/pdf, application/octet-stream',
            ])->timeout(30)->get($url);

            if ($response->successful()) {
                $contentType = (string) $response->header('Content-Type');

                if (str_contains($contentType, 'pdf') || str_starts_with($response->body(), '%PDF')) {
                    return response($response->body(), 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
                        'Cache-Control' => 'no-cache, private',
                    ]);
                }

                $json = $response->json();
                if (!empty($json['data']['receipt_url'])) {
                    $pdfRes = Http::timeout(30)->get($json['data']['receipt_url']);
                    if ($pdfRes->successful() && (str_contains((string) $pdfRes->header('Content-Type'), 'pdf') || str_starts_with($pdfRes->body(), '%PDF'))) {
                        return response($pdfRes->body(), 200, [
                            'Content-Type' => 'application/pdf',
                            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
                            'Cache-Control' => 'no-cache, private',
                        ]);
                    }
                    return redirect()->away($json['data']['receipt_url']);
                }
            }

            $meta = $this->getReceipt($reference);
            if (!empty($meta['receipt_url'])) {
                return redirect()->away($meta['receipt_url']);
            }
        } catch (\Throwable $e) {
            Log::error('QPay streamReceiptPdf failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }

        abort(404, 'Payment receipt PDF could not be retrieved from QPay.');
    }

    /**
     * Convenience method returning the checkout URL.
     * Uses REST API if configured, or falls back to legacy odrum URL encryption.
     */
    public function checkoutUrl(
        string $email,
        string $name,
        string $amount,
        ?string $phone,
        ?string $scode = null,
        ?string $callbackUrl = null,
        ?array $metadata = null,
        ?string $company = null
    ): string {
        $trimmedName = trim($name);
        $parts = $trimmedName !== '' ? explode(' ', $trimmedName, 2) : ['User'];
        $firstName = $parts[0] !== '' ? $parts[0] : 'User';
        $lastName = !empty($parts[1]) ? $parts[1] : $firstName;

        if ($this->isApiConfigured()) {
            $result = $this->initiate([
                'amount' => $amount,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'company' => $company,
                'callback_url' => $callbackUrl,
                'metadata' => $metadata,
            ]);

            return $result['checkout_url'];
        }

        // Fallback to legacy odrum URL encryption
        $base = (string) config('services.qpay.url');
        $scode = (string) ($scode ?? config('services.qpay.scode'));
        if ($base === '' || $scode === '') {
            throw new RuntimeException('QPay is not configured: set QPAY_URL and QPAY_SCODE.');
        }

        $payload = json_encode([
            'em' => $email,
            'fn' => $firstName,
            'ln' => $lastName,
            'am' => (string) $amount,
            'pn' => (string) ($phone ?? ''),
            'scode' => $scode,
        ]);

        return rtrim($base, '/').'/'.$this->encrypt($payload);
    }

    /**
     * Whether the status QPay sends back on the return URL or verification means the payer was debited.
     */
    public function isSuccessStatus(?string $status): bool
    {
        return in_array(strtolower((string) $status), ['successful', 'success', '1', 'approved', 'ok'], true);
    }

    /** Reverses encrypt, decrypting a payload returned by QPay if present. */
    public function decrypt(string $token): ?array
    {
        $key = (string) config('services.qpay.encryption_key');
        $iv = (string) config('services.qpay.encryption_iv');
        if ($key === '' || $iv === '') {
            return null;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }

        $plain = openssl_decrypt($decoded, self::CIPHER, hash('sha256', $key), 0, substr(hash('sha256', $iv), 0, 16));
        if ($plain === false) {
            return null;
        }

        $data = json_decode($plain, true);

        return is_array($data) ? $data : null;
    }

    public function encrypt(string $data): string
    {
        $key = (string) config('services.qpay.encryption_key');
        $iv = (string) config('services.qpay.encryption_iv');
        if ($key === '' || $iv === '') {
            throw new RuntimeException('QPay is not configured: set QPAY_ENCRYPTION_KEY and QPAY_ENCRYPTION_IV.');
        }

        // openssl uses the first 16 bytes of the hex digest as the AES-128 key.
        $encrypted = openssl_encrypt($data, self::CIPHER, hash('sha256', $key), 0, substr(hash('sha256', $iv), 0, 16));

        return base64_encode($encrypted);
    }
}
