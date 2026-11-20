<?php

namespace App\Services;

use RuntimeException;

/**
 * FMDQ QPay hosted checkout. The payer is sent to QPAY_URL followed by an encrypted JSON
 * payload; QPay looks up the service code ("scode") to decide which account is credited and
 * where the browser is returned afterwards. That return address is registered with the QPay
 * team per service code; it cannot be passed in the request.
 *
 * QPay has no verification API, so a returned "successful" status is only the payer's claim:
 * the invoice goes to FMDQ Admin review like every other payment.
 *
 * The encryption matches the other FMDQ platforms on QPay (e.g. FMRR) byte for byte.
 */
class QPay
{
    private const CIPHER = 'AES-128-CTR';

    /**
     * @param  string|null  $scode  the service code to charge under; defaults to the platform-fee
     *                              code. Bid payments use their own (services.qpay.bid_scode),
     *                              which QPay maps to its own credited account and return URL.
     */
    public function checkoutUrl(string $email, string $name, string $amount, ?string $phone, ?string $scode = null): string
    {
        $base = (string) config('services.qpay.url');
        $scode = (string) ($scode ?? config('services.qpay.scode'));
        if ($base === '' || $scode === '') {
            throw new RuntimeException('QPay is not configured: set QPAY_URL and QPAY_SCODE.');
        }

        $trimmedName = trim($name);
        $parts = $trimmedName !== '' ? explode(' ', $trimmedName, 2) : ['User'];
        $firstName = $parts[0] !== '' ? $parts[0] : 'User';
        $lastName = !empty($parts[1]) ? $parts[1] : $firstName;

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

    /** Whether the status QPay sends back on the return URL means the payer was debited. */
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
