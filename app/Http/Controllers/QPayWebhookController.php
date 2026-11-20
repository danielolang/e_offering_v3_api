<?php

namespace App\Http\Controllers;

use App\Services\BidService;
use App\Services\PlatformFeeService;
use App\Services\QPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class QPayWebhookController extends Controller
{
    private PlatformFeeService $fees;

    private QPay $qpay;

    public function __construct(PlatformFeeService $fees, QPay $qpay)
    {
        $this->fees = $fees;
        $this->qpay = $qpay;
    }

    /**
     * Handles QPay webhook notifications (server-to-server) and browser returns.
     * Accepts GET and POST, json, form-data or encrypted odrum token.
     */
    public function handle(Request $request): Response
    {
        // Heartbeat / health check ping
        if ($request->isMethod('GET') && empty($request->query())) {
            return response()->json([
                'status' => 'ok',
                'message' => 'QPay webhook endpoint is active.',
            ]);
        }

        $payload = $request->all();

        // Check if an encrypted token was passed
        $raw = $request->input('payload') ?? $request->input('token') ?? $request->input('data');
        if (is_string($raw) && strlen($raw) > 20) {
            $decrypted = $this->qpay->decrypt($raw);
            if ($decrypted) {
                $payload = array_merge($payload, $decrypted);
            }
        }

        // QPay sends newStatus / newRef (or status / reference / ref)
        $status = $payload['newStatus'] ?? $payload['status'] ?? $payload['Status'] ?? $payload['payment_status'] ?? $request->query('newStatus') ?? $request->query('status');
        $reference = $payload['newRef'] ?? $payload['reference'] ?? $payload['ref'] ?? $payload['tx_ref'] ?? $payload['gateway_reference'] ?? $payload['invoice'] ?? $request->query('newRef') ?? $request->query('ref');
        $email = $payload['email'] ?? $payload['em'] ?? null;

        Log::info('QPay webhook received', ['payload' => $payload, 'reference' => $reference, 'status' => $status]);

        // A bid payment (its own service code) is matched first, and only against bids that were
        // sent to QPay, so the platform-fee fallbacks below never pick up a subscription payment.
        $bid = app(BidService::class)->processWebhookPayment(
            $reference ? (string) $reference : null,
            $status ? (string) $status : null,
            $payload,
            $email ? (string) $email : null
        );
        if ($bid) {
            $isBrowser = ! $request->expectsJson() && ($request->isMethod('GET') || str_contains((string) $request->header('Accept'), 'text/html'));
            if ($isBrowser) {
                return redirect(frontendUrl('/investor/bids/'.$bid->id));
            }

            return response()->json(['status' => 'success', 'message' => 'Bid payment recorded.', 'bid' => $bid->reference]);
        }

        $invoice = $this->fees->processWebhookPayment(
            $reference ? (string) $reference : null,
            $status ? (string) $status : null,
            $payload,
            $email ? (string) $email : null
        );

        // If the payer's browser was sent to this webhook endpoint, redirect back to the app UI
        $isBrowser = ! $request->expectsJson() && ($request->isMethod('GET') || str_contains((string) $request->header('Accept'), 'text/html'));

        if (! $invoice) {
            if ($isBrowser) {
                return redirect(frontendUrl('/tsp/registration?payment=failed'));
            }

            return response()->json([
                'status' => 'ignored',
                'message' => 'Invoice not found or payment status is not successful.',
            ], 200);
        }

        if ($isBrowser) {
            return redirect(frontendUrl('/tsp/registration?payment=success&ref=' . urlencode($invoice->reference)));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment received and confirmed.',
            'invoice' => $invoice->reference,
            'reference' => $reference,
        ]);
    }
}
