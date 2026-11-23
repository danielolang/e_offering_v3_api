<?php

namespace App\Http\Controllers;

use App\Models\PlatformFeeInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlatformFeeService;
use App\Services\QPay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** The "Registration" module a TSP/RM lands on after approval (CR s3.2 steps 5-12). */
class RegistrationController extends Controller
{
    private PlatformFeeService $fees;
    private QPay $qpay;

    public function __construct(PlatformFeeService $fees, QPay $qpay)
    {
        $this->fees = $fees;
        $this->qpay = $qpay;
    }

    public function show(Request $request): JsonResponse
    {
        $institution = $request->user()->institution;
        $openInvoice = $institution->registration_completed_at ? $this->fees->currentInvoice($institution) : null;
        $invoice = $openInvoice ?? $institution->feeInvoices()->latest('id')->first();

        $invoiceData = $invoice ? array_merge($invoice->toArray(), [
            'invoice_pdf_url' => $invoice->gateway_reference ? signedFileUrl('tsp.registration.invoice-pdf', $invoice) : null,
            'receipt_pdf_url' => ($invoice->gateway_reference && $invoice->status === PlatformFeeInvoice::PAID) ? signedFileUrl('tsp.registration.receipt-pdf', $invoice) : null,
        ]) : null;

        $invoicesData = $institution->feeInvoices()->latest('id')->get()->map(function (PlatformFeeInvoice $inv) {
            return array_merge($inv->toArray(), [
                'invoice_pdf_url' => $inv->gateway_reference ? signedFileUrl('tsp.registration.invoice-pdf', $inv) : null,
                'receipt_pdf_url' => ($inv->gateway_reference && $inv->status === PlatformFeeInvoice::PAID) ? signedFileUrl('tsp.registration.receipt-pdf', $inv) : null,
            ]);
        });

        return successResponse('Registration fetched.', [
            'institution' => $institution->only([
                'id', 'name', 'reference', 'email', 'registered_name', 'registered_address', 'contact_name', 'contact_role',
                'contact_phone', 'registration_completed_at', 'bank_name', 'bank_account_name', 'bank_account_number',
                'fee_paid_until', 'suspended_at', 'status',
            ]),
            'feeEnabled' => $this->fees->enabled(),
            'fullAccess' => $this->fees->hasFullAccess($institution),
            'invoice' => $invoiceData,
            'invoices' => $invoicesData,
            'bank' => [
                'bank_name' => Setting::get('platform_fee_bank_name'),
                'account_name' => Setting::get('platform_fee_account_name'),
                'account_number' => Setting::get('platform_fee_account_number'),
            ],
            'gateways' => config('eoffering.platform_fee.gateways'),
        ]);
    }

    /** Registered details and contact person (s3.2 step 6). */
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        // Once submitted and fully active, the registration details are final.
        $current = $request->user()->institution;
        abort_if(
            $current->registration_completed_at && $this->fees->hasFullAccess($current),
            403,
            'Your registration is complete, so these details can no longer be edited. Contact FMDQ if anything needs to change.'
        );

        $data = $request->validate([
            'registered_name' => ['required', 'string', 'max:191'],
            'registered_address' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:191'],
            'contact_role' => ['required', 'string', 'max:100'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_account_name' => ['required', 'string', 'max:191'],
            'bank_account_number' => ['required', 'digits:10'],
        ], [
            'bank_account_number.digits' => 'The account number must be exactly 10 digits.',
            'bank_account_number.required' => 'The account number is required.',
        ]);

        $institution = $request->user()->institution;
        $first = $institution->registration_completed_at === null;
        $institution->fill($data);
        if ($first) {
            $institution->registration_completed_at = now();
        }
        $institution->save();
        $audit->log('tsp.registration', ($first ? 'Completed' : 'Updated') . ' TSP/RM registration details', $institution, [], $request->user());

        if ($this->fees->hasFullAccess($institution)) {
            return $this->unlocked('Registration complete', 'Your registration is complete and you now have full access to the Transaction Sponsor modules.');
        }

        return successResponse('Registration saved. Pay the annual platform fee below to unlock the Transaction Sponsor modules.');
    }

    /** Full access was just unlocked: the app opens the dashboard and shows a success pop-up. */
    private function unlocked(string $title, string $message): JsonResponse
    {
        return successResponse($message, [
            'redirect' => '/tsp',
            'popup' => ['title' => $title, 'text' => $message],
        ]);
    }

    public function payOnline(Request $request, PlatformFeeInvoice $invoice): JsonResponse
    {
        $this->own($request, $invoice);
        $data = $request->validate(['method' => ['required', Rule::in(['qpay'])]]);

        $url = $this->fees->startGatewayPayment($invoice, $data['method'], $request->user(), frontendUrl('/tsp/registration?gateway=qpay&invoice=' . $invoice->id));

        return successResponse('Continue to payment.', ['redirect' => $url]);
    }

    /** The gateway returns the browser to the app, which confirms the payment here. */
    public function confirm(Request $request, PlatformFeeInvoice $invoice): JsonResponse
    {
        $this->own($request, $invoice);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:191'],
            'transaction_id' => ['nullable', 'string', 'max:191'],
        ]);

        if (!$this->fees->confirmGatewayPayment($invoice, $data['reference'], $data['transaction_id'] ?? null)) {
            return errorResponse(422, 'We could not confirm the payment. If you were debited, contact FMDQ with reference ' . $data['reference'] . '.');
        }

        if ($this->fees->hasFullAccess($invoice->institution->fresh())) {
            return $this->unlocked('Payment successful', 'Your platform fee is paid and you now have full access to the Transaction Sponsor modules.');
        }

        return successResponse('Payment received. FMDQ will confirm it shortly.');
    }

    /** QPay returns the browser to /tsp/registration with payment status, which posts here. */
    public function qpayReturn(Request $request): JsonResponse
    {
        $status = $request->input('newStatus')
            ?? $request->input('status')
            ?? $request->input('Status')
            ?? $request->input('payment_status')
            ?? $request->input('payment');

        $reference = $request->input('ref')
            ?? $request->input('reference')
            ?? $request->input('newRef')
            ?? $request->input('gateway_reference');

        $invoice = $this->fees->confirmQPayReturn(
            $request->user()->institution,
            $status ? (string) $status : null,
            collect($request->except('gateway'))->map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 255) : null)->filter()->all(),
            $reference ? (string) $reference : null
        );

        if (!$invoice) {
            return errorResponse(422, 'QPay did not confirm the payment. If you were debited, contact FMDQ with your QPay receipt.');
        }

        if ($this->fees->hasFullAccess($request->user()->institution->fresh())) {
            return $this->unlocked('Payment successful', 'Your platform fee is paid and you now have full access to the Transaction Sponsor modules.');
        }

        return successResponse('Payment received. FMDQ will confirm it shortly.', [
            'invoice' => $invoice->reference,
            'status' => $invoice->status,
        ]);
    }

    public function bankTransfer(Request $request, PlatformFeeInvoice $invoice): JsonResponse
    {
        $this->own($request, $invoice);
        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $this->fees->submitBankTransfer($invoice, $request->file('evidence'), $data['reference'] ?? null);

        return successResponse('Evidence of payment uploaded. FMDQ will review it.');
    }

    /** Printable receipt (s3.2 step 9). */
    public function receipt(Request $request, PlatformFeeInvoice $invoice): JsonResponse
    {
        $this->own($request, $invoice);
        abort_unless($invoice->status === PlatformFeeInvoice::PAID, 404);

        $invoiceData = array_merge($invoice->load('institution', 'reviewer:id,name')->toArray(), [
            'invoice_pdf_url' => $invoice->gateway_reference ? signedFileUrl('tsp.registration.invoice-pdf', $invoice) : null,
            'receipt_pdf_url' => $invoice->gateway_reference ? signedFileUrl('tsp.registration.receipt-pdf', $invoice) : null,
        ]);

        return successResponse('Receipt fetched.', ['invoice' => $invoiceData]);
    }

    /** Download or stream official payment invoice PDF from QPay. */
    public function downloadInvoicePdf(Request $request, PlatformFeeInvoice $invoice): Response
    {
        $this->own($request, $invoice);

        $reference = $invoice->gateway_reference;
        if (empty($reference)) {
            abort(404, 'No QPay payment invoice is available for this invoice.');
        }

        $download = $request->boolean('download');
        return $this->qpay->streamInvoicePdf($reference, $download);
    }

    /** Download or stream official payment receipt PDF from QPay. */
    public function downloadReceiptPdf(Request $request, PlatformFeeInvoice $invoice): Response
    {
        $this->own($request, $invoice);
        abort_unless($invoice->status === PlatformFeeInvoice::PAID, 404, 'Receipt is only available for paid invoices.');

        $reference = $invoice->gateway_reference;
        if (empty($reference)) {
            abort(404, 'No QPay payment receipt is available for this invoice.');
        }

        $download = $request->boolean('download');
        return $this->qpay->streamReceiptPdf($reference, $download);
    }

    private function resolveViewer(Request $request): User
    {
        $user = $request->user() ?: User::find($request->query('uid'));
        abort_unless($user && $user->isActive(), 403);

        return $user;
    }

    private function own(Request $request, PlatformFeeInvoice $invoice): void
    {
        $user = $this->resolveViewer($request);
        abort_unless($user->isAdmin() || $invoice->institution_id === $user->institution_id, 403);
    }
}
