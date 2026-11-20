<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Requests\BidRequest;
use App\Models\Allotment;
use App\Models\Bid;
use App\Models\Offer;
use App\Services\BidService;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Commitment form: submit, amend, revoke, and upload evidence of payment (CR s6.1-6.2).
 * No OTP at submission: the login OTP replaces it (s6.1).
 */
class BidController extends Controller
{
    private BidService $bids;

    public function __construct(BidService $bids)
    {
        $this->bids = $bids;
    }

    public function create(Request $request, Offer $offer): JsonResponse
    {
        $user = $request->user();
        Access::abortUnless(Access::investorCanSee($user, $offer));
        abort_unless($offer->isOpenForBidding(), 403, 'This offer is not open for bidding.');

        return successResponse('Fetched.', $this->formProps($request, $offer, null));
    }

    public function store(BidRequest $request, Offer $offer): JsonResponse
    {
        // Checked before the bid is placed, so a bid is never saved behind an unavailable checkout.
        if ($request->input('pay_with') === 'qpay' && ! BidService::qpayEnabled()) {
            throw ValidationException::withMessages(['pay_with' => 'Online payment with QPay is not available. Choose bank transfer.']);
        }

        $bid = $this->bids->place($offer, $request->user(), $request->validated(), $request->file('evidence'), $this->signatureFiles($request));

        if ($request->input('pay_with') === 'qpay') {
            return $this->toQPay($request, $bid, 'Your bid has been received. Continue to QPay to pay for it.');
        }

        // s6.1: a bid received does not guarantee allotment.
        return successResponse('Your bid has been received. Please note that submitting a bid does not guarantee allocation or allotment.', ['redirect' => screenPath(route('investor.bids.show', $bid))]);
    }

    /** "Pay with QPay" on the bid page, for a bid placed earlier and not yet paid. */
    public function payQPay(Request $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);

        return $this->toQPay($request, $bid, 'Continue to QPay to pay for your bid.');
    }

    /** QPay returns the investor's browser to /investor/bids/qpay-return, which posts here. */
    public function qpayReturn(Request $request): JsonResponse
    {
        $status = $request->input('newStatus') ?? $request->input('status') ?? $request->input('Status') ?? $request->input('payment_status') ?? $request->input('payment');
        $reference = $request->input('newRef') ?? $request->input('ref') ?? $request->input('reference');

        $bid = $this->bids->confirmQPayReturn(
            $request->user(),
            $status ? (string) $status : null,
            collect($request->except('gateway'))->map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 255) : null)->filter()->all(),
            $reference ? (string) $reference : null
        );

        if (! $bid) {
            return errorResponse(422, 'QPay did not confirm the payment. If you were debited, contact the sponsor with your QPay receipt, or upload it as evidence on your bid.');
        }

        return successResponse('Payment received.', [
            'redirect' => screenPath(route('investor.bids.show', $bid)),
            'popup' => ['title' => 'Payment Successful', 'text' => 'Your QPay payment for bid '.$bid->reference.' was received and recorded against your bid.'],
        ]);
    }

    private function toQPay(Request $request, Bid $bid, string $message): JsonResponse
    {
        try {
            return successResponse($message, ['redirect' => $this->bids->startQPay($bid->fresh(), $request->user())]);
        } catch (ValidationException $e) {
            // The bid itself was saved; say so, and offer the bank-transfer route on its page.
            return successResponse('Your bid was saved.', [
                'redirect' => screenPath(route('investor.bids.show', $bid)),
                'popup' => [
                    'icon' => 'warning',
                    'title' => 'Bid Saved, Payment Not Started',
                    'text' => collect($e->errors())->flatten()->first().' Your bid '.$bid->reference.' is saved; you can pay for it from the bid page.',
                ],
            ]);
        }
    }

    public function show(Request $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        $bid->load('offer.institution:id,name', 'offer.allotment', 'events.user:id,name', 'allotmentItem');
        $allotmentStatus = optional($bid->offer->allotment)->status;
        $visibleAllotment = in_array($allotmentStatus, [Allotment::APPROVED, Allotment::LODGED], true);

        return successResponse('Fetched.', [
            'bid' => array_merge($bid->toArray(), [
                'evidence_url' => $bid->payment_evidence_path ? signedFileUrl('bids.evidence', $bid) : null,
                'commitment_files' => self::commitmentFileUrls($bid),
            ]),
            'canChange' => $bid->canBeChangedByInvestor(),
            'canUploadEvidence' => $bid->isActive() && $bid->payment_status !== Bid::PAYMENT_PAID && ! $bid->offer->allotmentStarted(),
            'qpayEnabled' => BidService::qpayEnabled(),
            // Evidence is with the sponsor: paying again is disabled unless it is marked Invalid.
            'awaitingReview' => $bid->awaitingPaymentReview(),
            // The commitment form lists the offer's co-sponsors.
            'coSponsors' => $bid->offer->coSponsors()->with('institution:id,name')->get()->map(fn ($c) => optional($c->institution)->name)->filter()->values(),
            'allotment' => $visibleAllotment && $bid->allotmentItem ? $bid->allotmentItem->only(['allotted', 'units', 'released', 'filled_pct']) : null,
            'allotmentStatus' => $allotmentStatus,
        ]);
    }

    public function edit(Request $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        abort_unless($bid->canBeChangedByInvestor(), 403, 'Bids can only be changed while the offer is open.');

        return successResponse('Fetched.', $this->formProps($request, $bid->offer, $bid));
    }

    public function update(BidRequest $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        // Evidence already with the sponsor: no new evidence or online payment until it is marked Invalid.
        if ($request->file('evidence') && $bid->awaitingPaymentReview()) {
            throw ValidationException::withMessages(['evidence' => 'Your evidence of payment is with the sponsor for review. You can send new evidence only if it is marked Invalid.']);
        }
        $this->bids->amend($bid, $request->user(), $request->validated(), $this->signatureFiles($request));
        if ($request->file('evidence')) {
            $this->bids->uploadEvidence($bid, $request->user(), $request->file('evidence'), $request->input('payment_reference'));
        }
        $fresh = $bid->fresh();
        if ($request->input('pay_with') === 'qpay' && $fresh->payment_status !== Bid::PAYMENT_PAID && ! $fresh->awaitingPaymentReview()) {
            return $this->toQPay($request, $bid, 'Your bid was amended. Continue to QPay to pay for it.');
        }

        return successResponse('Your bid was amended.', ['redirect' => screenPath(route('investor.bids.show', $bid))]);
    }

    public function revoke(Request $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        $this->bids->revoke($bid, $request->user());

        return successResponse('Your bid was revoked.', ['redirect' => screenPath(route('investor.bids.show', $bid))]);
    }

    public function evidence(Request $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->bids->uploadEvidence($bid, $request->user(), $request->file('evidence'), $data['payment_reference'] ?? null);

        return successResponse('Evidence of payment uploaded. Payment status stays "Pending" until the sponsor confirms it.');
    }

    private function formProps(Request $request, Offer $offer, ?Bid $bid): array
    {
        $user = $request->user();
        $offer->load('receivingAgents', 'coSponsors.institution:id,name', 'institution:id,name');
        $profile = $user->investorProfile;

        return [
            'offer' => $offer,
            'bid' => $bid,
            'commitmentFiles' => $bid ? (object) self::commitmentFileUrls($bid) : (object) [],
            'payees' => $offer->payeeOptions(),
            'qpayEnabled' => BidService::qpayEnabled(),
            'investorTypes' => config('eoffering.investor_types'),
            // Pre-fills the legacy commitment form's participant details from the account.
            'participant' => [
                'surname' => $user->institution ? $user->institution->name : (string) \Illuminate\Support\Str::afterLast(trim($user->name), ' '),
                'first_name' => $user->institution ? '' : (string) \Illuminate\Support\Str::before(trim($user->name), ' '),
                'contact_person' => $user->institution ? $user->name : '',
                'address_line_1' => $user->institution ? (string) ($user->institution->address ?? '') : (string) (optional($profile)->address ?? ''),
                'phone' => (string) ($user->phone ?? ''),
                'email' => $user->email,
            ],
            'defaults' => [
                'investor_type' => $user->institution ? '' : 'individual',
                'csd_name' => optional($profile)->csd_name,
                'csd_account_number' => optional($profile)->csd_account_number,
                'bank_name' => optional($profile)->bank_name,
                'bank_account_name' => optional($profile)->bank_account_name ?: ($user->institution ? $user->institution->name : $user->name),
                'bank_account_number' => optional($profile)->bank_account_number,
            ],
        ];
    }

    /** @return array<string, \Illuminate\Http\UploadedFile|null> */
    private function signatureFiles(Request $request): array
    {
        return [
            'signature_1' => $request->file('signature_1'),
            'signature_2' => $request->file('signature_2'),
            'official_seal' => $request->file('official_seal'),
        ];
    }

    /** Signed links to the commitment form's signature and seal images, for the preview. */
    public static function commitmentFileUrls(Bid $bid): array
    {
        $urls = [];
        foreach ((array) ($bid->commitment['files'] ?? []) as $key => $path) {
            if ($path) {
                $urls[$key] = signedFileUrl('bids.commitment-file', [$bid->id, $key]);
            }
        }

        return $urls;
    }

    private function own(Request $request, Bid $bid): void
    {
        abort_unless($bid->user_id === $request->user()->id, 403);
    }
}
