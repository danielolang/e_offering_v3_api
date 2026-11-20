<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\PlatformFeeInvoice;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Annual TSP/RM platform fee, decoupled from offer creation (CR s3.2 steps 7-12).
 * Switched off for the first year of launch (setting "platform_fee_enabled").
 */
class PlatformFeeService
{
    private AuditLogger $audit;

    private Notifier $notifier;

    private DocumentStore $documents;

    private QPay $qpay;

    public function __construct(AuditLogger $audit, Notifier $notifier, DocumentStore $documents, QPay $qpay)
    {
        $this->audit = $audit;
        $this->notifier = $notifier;
        $this->documents = $documents;
        $this->qpay = $qpay;
    }

    public function enabled(): bool
    {
        return (bool) Setting::get('platform_fee_enabled');
    }

    /**
     * Full access to the Transaction Sponsor modules (s3.2 step 10): registration done and,
     * while the fee is enabled, a paid year that has not lapsed.
     */
    public function hasFullAccess(Institution $institution): bool
    {
        if ($institution->isSuspended() || ! $institution->registration_completed_at) {
            return false;
        }
        if (! $this->enabled()) {
            return true;
        }

        return $institution->fee_paid_until !== null && $institution->fee_paid_until->endOfDay()->isFuture();
    }

    /**
     * Called when the fee is switched on. TSP/RMs that completed registration while it was
     * off already have full access and never had a paid period, so without this they would
     * be locked out and sent back to the payment step. Their free period counts as a covered
     * year from registration; it always runs at least the usual notice period from today,
     * so the first invoice arrives as a normal renewal instead of an immediate lock-out.
     *
     * @return int how many institutions were covered
     */
    public function coverFreePeriod(?User $admin = null): int
    {
        $notice = (int) config('eoffering.platform_fee.invoice_days_before_due', 30);
        $earliest = Carbon::today()->addDays($notice);
        $covered = 0;

        Institution::where('type', Institution::TYPE_TSP)
            ->whereNotNull('registration_completed_at')
            ->whereNull('fee_paid_until')
            ->whereNull('suspended_at')
            ->get()
            ->each(function (Institution $institution) use ($earliest, $admin, &$covered) {
                $until = $institution->registration_completed_at->copy()->startOfDay()->addYear()->subDay();
                $until = $until->lessThan($earliest) ? $earliest->copy() : $until;

                $institution->forceFill(['fee_paid_until' => $until])->save();
                $this->audit->log('fee.free_period_covered', 'Free registration period for '.$institution->name.' covered until '.$until->toDateString(), $institution, [], $admin);
                $covered++;
            });

        return $covered;
    }

    /** The invoice the TSP should pay now, created on demand. */
    public function currentInvoice(Institution $institution): ?PlatformFeeInvoice
    {
        if (! $this->enabled()) {
            return null;
        }

        $open = $institution->feeInvoices()->whereIn('status', [PlatformFeeInvoice::UNPAID, PlatformFeeInvoice::REJECTED, PlatformFeeInvoice::PENDING_REVIEW])
            ->latest('id')->first();
        if ($open) {
            return $open;
        }
        if ($institution->fee_paid_until && $institution->fee_paid_until->copy()->subDays((int) config('eoffering.platform_fee.invoice_days_before_due', 30))->isFuture()) {
            return null;
        }

        return $this->createInvoice($institution);
    }

    public function createInvoice(Institution $institution): PlatformFeeInvoice
    {
        $start = $institution->fee_paid_until ? $institution->fee_paid_until->copy()->addDay() : Carbon::today();

        return $institution->feeInvoices()->create([
            'period_start' => $start,
            'period_end' => $start->copy()->addYear()->subDay(),
            'due_date' => $institution->fee_paid_until ? $institution->fee_paid_until->copy() : Carbon::today()->addDays(30),
            'amount' => (string) Setting::get('platform_fee_amount'),
            'status' => PlatformFeeInvoice::UNPAID,
        ]);
    }

    /** QPay: returns the hosted checkout URL. */
    public function startGatewayPayment(PlatformFeeInvoice $invoice, string $gateway, User $payer, string $callbackUrl): string
    {
        $this->assertPayable($invoice);
        $reference = $invoice->reference.'-'.time();

        if ($gateway === 'qpay') {
            // QPay returns the browser to the URL registered for our service code, so the
            // reference only marks this invoice as the one awaiting QPay's return.
            try {
                $name = $payer->name ?: ($invoice->institution->contact_name ?: $invoice->institution->name);
                $phone = $payer->phone ?: ($invoice->institution->contact_phone ?: null);
                $url = $this->qpay->checkoutUrl($payer->email, $name, (string) $invoice->amount, $phone);
            } catch (RuntimeException $e) {
                report($e);
                throw ValidationException::withMessages(['method' => 'QPay is not available at the moment. Try another payment method.']);
            }
            $invoice->forceFill(['method' => 'qpay', 'gateway_reference' => $reference])->save();

            return $url;
        }

        throw ValidationException::withMessages(['method' => 'Unknown payment method.']);
    }

    /** Verifies legacy gateway callbacks; online payment uses QPay via confirmQPayReturn. */
    public function confirmGatewayPayment(PlatformFeeInvoice $invoice, string $reference, ?string $transactionId = null): bool
    {
        return false;
    }

    /**
     * QPay returns the browser or sends a status callback. Successful status directly marks
     * the invoice as PAID, granting the institution full access immediately.
     *
     * @param  array<string, mixed>  $returned  the query parameters QPay returned with
     */
    public function confirmQPayReturn(Institution $institution, ?string $status, array $returned, ?string $reference = null): ?PlatformFeeInvoice
    {
        if (! $this->qpay->isSuccessStatus($status)) {
            return null;
        }

        $ref = $reference ?: ($returned['newRef'] ?? $returned['ref'] ?? $returned['reference'] ?? null);

        $invoice = null;

        // 1. If reference passed, match by reference, gateway_reference, or baseRef
        if ($ref) {
            $baseRef = preg_replace('/-\d+$/', '', $ref);
            $invoice = $institution->feeInvoices()
                ->where(function ($q) use ($ref, $baseRef) {
                    $q->where('gateway_reference', $ref)
                        ->orWhere('reference', $ref)
                        ->orWhere('reference', $baseRef);
                })
                ->latest('id')
                ->first();

            // Also try EOFF regex if ref is like EOFF1_...
            if (! $invoice && preg_match('/EOFF_?(\d+)/i', $ref, $m)) {
                $invoice = $institution->feeInvoices()->where('id', $m[1])->first();
            }
        }

        // 2. Fallback to latest invoice of the institution initiated with QPay
        if (! $invoice) {
            $invoice = $institution->feeInvoices()
                ->where('method', 'qpay')
                ->whereNotNull('gateway_reference')
                ->latest('updated_at')
                ->first();
        }

        if (! $invoice) {
            return null;
        }

        if ($invoice->status === PlatformFeeInvoice::PAID) {
            return $invoice;
        }

        $this->markAsPaid($invoice, $ref ?: $invoice->gateway_reference, $returned);

        return $invoice->fresh();
    }

    /**
     * Handles an asynchronous webhook/notification directly from QPay's servers.
     * Successful payment directly marks the invoice as PAID without requiring admin review.
     *
     * @param  array<string, mixed>  $payload
     */
    public function processWebhookPayment(?string $reference, ?string $status, array $payload, ?string $email = null): ?PlatformFeeInvoice
    {
        if (! $this->qpay->isSuccessStatus($status)) {
            return null;
        }

        $invoice = null;

        // Check if this payment reference was already recorded as PAID (idempotent / retry safe)
        if ($reference) {
            $already = PlatformFeeInvoice::where('gateway_reference', $reference)
                ->where('status', PlatformFeeInvoice::PAID)
                ->first();
            if ($already) {
                return $already;
            }
        }

        // 1. Try matching by exact gateway_reference or reference
        if ($reference) {
            $baseRef = preg_replace('/-\d+$/', '', $reference);
            $invoice = PlatformFeeInvoice::whereIn('status', [PlatformFeeInvoice::UNPAID, PlatformFeeInvoice::REJECTED, PlatformFeeInvoice::PENDING_REVIEW])
                ->where(function ($q) use ($reference, $baseRef) {
                    $q->where('gateway_reference', $reference)
                        ->orWhere('reference', $reference)
                        ->orWhere('reference', $baseRef);
                })
                ->first();

            // 2. Try extracting invoice ID from QPay reference prefix like EOFF1_... or EOFF_1_...
            if (! $invoice && preg_match('/EOFF_?(\d+)/i', $reference, $m)) {
                $invoice = PlatformFeeInvoice::where('id', $m[1])
                    ->whereIn('status', [PlatformFeeInvoice::UNPAID, PlatformFeeInvoice::REJECTED, PlatformFeeInvoice::PENDING_REVIEW])
                    ->first();

                // Or matching by institution ID
                if (! $invoice) {
                    $invoice = PlatformFeeInvoice::where('institution_id', $m[1])
                        ->whereIn('status', [PlatformFeeInvoice::UNPAID, PlatformFeeInvoice::REJECTED, PlatformFeeInvoice::PENDING_REVIEW])
                        ->latest('id')
                        ->first();
                }
            }
        }

        // 3. Try matching by customer / institution email
        if (! $invoice && $email) {
            $invoice = PlatformFeeInvoice::whereHas('institution', function ($q) use ($email) {
                $q->where('email', $email)->orWhereHas('users', fn ($u) => $u->where('email', $email));
            })->whereIn('status', [PlatformFeeInvoice::UNPAID, PlatformFeeInvoice::REJECTED, PlatformFeeInvoice::PENDING_REVIEW])
                ->latest('updated_at')
                ->first();
        }

        // 4. Fallback to the latest unpaid/pending invoice initiated with QPay
        if (! $invoice) {
            $invoice = PlatformFeeInvoice::where('method', 'qpay')
                ->whereIn('status', [PlatformFeeInvoice::UNPAID, PlatformFeeInvoice::REJECTED, PlatformFeeInvoice::PENDING_REVIEW])
                ->latest('updated_at')
                ->first();
        }

        if (! $invoice) {
            return null;
        }

        if ($invoice->status === PlatformFeeInvoice::PAID) {
            return $invoice;
        }

        $this->markAsPaid($invoice, $reference ?: $invoice->gateway_reference, $payload);

        return $invoice->fresh();
    }

    public function submitBankTransfer(PlatformFeeInvoice $invoice, UploadedFile $evidence, ?string $reference): void
    {
        $this->assertPayable($invoice);
        $this->documents->delete($invoice->evidence_path);
        $invoice->forceFill([
            'method' => 'bank_transfer',
            'gateway_reference' => $reference,
            'evidence_path' => $this->documents->store($evidence, 'fees/'.$invoice->institution_id),
            'status' => PlatformFeeInvoice::PENDING_REVIEW,
            'paid_at' => now(),
            'review_comment' => null,
        ])->save();
        $this->submitted($invoice);
    }

    /**
     * Marks an invoice as PAID directly, updates the institution's fee_paid_until,
     * clears suspension, logs audit, and notifies the TSP.
     */
    public function markAsPaid(PlatformFeeInvoice $invoice, ?string $reference = null, ?array $gatewayResponse = null, ?User $admin = null): void
    {
        $invoice->forceFill([
            'status' => PlatformFeeInvoice::PAID,
            'gateway_reference' => $reference ?: $invoice->gateway_reference,
            'gateway_response' => $gatewayResponse ?: $invoice->gateway_response,
            'paid_at' => $invoice->paid_at ?: now(),
            'reviewed_by' => $admin?->id,
            'reviewed_at' => now(),
            'review_comment' => null,
        ])->save();

        $institution = $invoice->institution;
        $paidUntil = $institution->fee_paid_until && $institution->fee_paid_until->greaterThan($invoice->period_end)
            ? $institution->fee_paid_until : $invoice->period_end;

        $institution->forceFill([
            'fee_paid_until' => $paidUntil,
            'suspended_at' => null,
            'status' => $institution->status === Institution::STATUS_SUSPENDED ? Institution::STATUS_APPROVED : $institution->status,
        ])->save();

        $action = $admin ? 'fee.approved' : 'fee.paid';
        $desc = $admin
            ? 'Approved platform fee '.$invoice->reference.' for '.$institution->name
            // The display name ("QPay"), not the stored method key ("qpay").
            : 'Payment confirmed for platform fee '.$invoice->reference.' ('.(config('eoffering.platform_fee.gateways.'.($invoice->method ?? 'qpay')) ?? $invoice->method).')';

        $this->audit->log($action, $desc, $invoice, [], $admin);

        $this->notifier->send(
            $this->notifier->tspUsers($institution->id),
            'Platform fee payment confirmed',
            'Your platform fee payment '.$invoice->reference.' is confirmed. Your receipt is ready and you have full access until '.$paidUntil->format('F j, Y').'.',
            frontendUrl('/tsp/registration/fee/'.$invoice->id.'/receipt')
        );
    }

    /** s3.2 steps 8-10. */
    public function approve(PlatformFeeInvoice $invoice, User $admin): void
    {
        $this->markAsPaid($invoice, null, null, $admin);
    }

    public function reject(PlatformFeeInvoice $invoice, User $admin, string $comment): void
    {
        $invoice->forceFill([
            'status' => PlatformFeeInvoice::REJECTED, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_comment' => $comment,
        ])->save();
        $this->audit->log('fee.rejected', 'Rejected platform fee '.$invoice->reference, $invoice, ['comment' => $comment], $admin);
        $this->notifier->send(
            $this->notifier->tspUsers($invoice->institution_id),
            'Platform fee payment not confirmed',
            'FMDQ could not confirm payment '.$invoice->reference.'. Comment: '.$comment,
            frontendUrl('/tsp/registration')
        );
    }

    /**
     * Scheduled daily (s3.2 steps 11-12): renewal invoices ahead of the due date and
     * suspension of TSPs whose fee lapsed; payment approval reinstates them.
     *
     * @return array{invoiced: int, suspended: int}
     */
    public function runRenewals(): array
    {
        $invoiced = 0;
        $suspended = 0;
        if (! $this->enabled()) {
            return compact('invoiced', 'suspended');
        }

        $leadDays = (int) config('eoffering.platform_fee.invoice_days_before_due', 30);
        $tsps = Institution::where('type', Institution::TYPE_TSP)
            ->whereIn('status', [Institution::STATUS_APPROVED, Institution::STATUS_SUSPENDED])
            ->whereNotNull('fee_paid_until')->get();

        foreach ($tsps as $institution) {
            $hasOpen = $institution->feeInvoices()->whereIn('status', [PlatformFeeInvoice::UNPAID, PlatformFeeInvoice::PENDING_REVIEW, PlatformFeeInvoice::REJECTED])->exists();

            if (! $hasOpen && $institution->fee_paid_until->copy()->subDays($leadDays)->lessThanOrEqualTo(Carbon::today())) {
                $invoice = $this->createInvoice($institution);
                $invoiced++;
                $this->notifier->send(
                    $this->notifier->tspUsers($institution->id),
                    'Annual platform fee invoice '.$invoice->reference,
                    'Your e-Offering platform fee for '.$invoice->period_start->format('F j, Y').' to '.$invoice->period_end->format('F j, Y').' is due on '.$invoice->due_date->format('F j, Y').'.',
                    frontendUrl('/tsp/registration')
                );
            }

            if ($institution->fee_paid_until->endOfDay()->isPast() && ! $institution->suspended_at) {
                $institution->forceFill(['suspended_at' => now(), 'status' => Institution::STATUS_SUSPENDED])->save();
                $suspended++;
                $this->audit->log('fee.suspended', 'Suspended '.$institution->name.' for an unpaid platform fee', $institution, [], null);
                $this->notifier->send(
                    $this->notifier->tspUsers($institution->id),
                    'e-Offering access suspended',
                    'Your platform fee was not paid by the due date, so access to the Transaction Sponsor modules is suspended. Access is restored as soon as payment is confirmed.',
                    frontendUrl('/tsp/registration')
                );
            }
        }

        return compact('invoiced', 'suspended');
    }

    private function assertPayable(PlatformFeeInvoice $invoice): void
    {
        if (! $invoice->isOpen()) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is not awaiting payment.']);
        }
    }

    private function submitted(PlatformFeeInvoice $invoice): void
    {
        $this->audit->log('fee.submitted', 'Submitted payment for platform fee '.$invoice->reference.' ('.$invoice->method.')', $invoice);
        $this->notifier->send(
            $this->notifier->admins(),
            'Platform fee payment to review',
            optional($invoice->institution)->name.' submitted payment '.$invoice->reference.' by '.str_replace('_', ' ', (string) $invoice->method).'.',
            frontendUrl('/admin/fees')
        );
    }
}
