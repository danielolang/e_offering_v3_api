<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Commitment form (CR s6.1), carrying every field of the legacy Standardised Commitment Form.
 * Business rules (minimum amount, increments, rate range, investor universe, open window) are
 * enforced in BidService, for each of the up-to-three orders.
 */
class BidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['amount', 'order_2_amount', 'order_3_amount'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => str_replace([',', ' '], '', $this->input($key))]);
            }
        }

        // The legacy form has a CSCS box (CHN) and an FMDQ Depository box (client BPID); the
        // lodgement needs one CSD account, so it is taken from whichever box was filled.
        if (! $this->filled('csd_account_number')) {
            if ($this->filled('clearing_house_no')) {
                $this->merge(['csd_name' => 'CSCS', 'csd_account_number' => $this->input('clearing_house_no')]);
            } elseif ($this->filled('client_bpid_no')) {
                $this->merge(['csd_name' => 'FMDQ Depository', 'csd_account_number' => $this->input('client_bpid_no')]);
            }
        }
    }

    public function rules(): array
    {
        $creating = $this->route('bid') === null;
        $image = ['file', 'mimes:jpg,jpeg,png', 'max:2048'];

        return [
            // Participant type (legacy tick boxes) and Order 1.
            'investor_type' => ['required', Rule::in(array_keys(config('eoffering.investor_types')))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'yield' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            // Optional orders 2 and 3 (not aggregated on the form; their total is the bid amount).
            'order_2_amount' => ['nullable', 'numeric', 'gt:0'],
            'order_2_rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'order_3_amount' => ['nullable', 'numeric', 'gt:0'],
            'order_3_rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],

            // Participant details (individual / corporate / joint).
            'surname' => ['required', 'string', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:100'],
            'joint_first_name' => ['nullable', 'string', 'max:100'],
            'joint_other_names' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['required', 'string', 'max:191'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'address_line_3' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191'],

            // Allotment preference and the e-allotment boxes.
            'allotment_preference' => ['required', Rule::in(['certificate', 'book_entry'])],
            'cscs_account_no' => ['nullable', 'string', 'max:40'],
            'clearing_house_no' => ['nullable', 'string', 'max:40'],
            'stockbroking_firm_cscs' => ['nullable', 'string', 'max:191'],
            'fmdq_bpid_no' => ['nullable', 'string', 'max:40'],
            'client_bpid_no' => ['nullable', 'string', 'max:40'],
            'stockbroking_firm_fmdq' => ['nullable', 'string', 'max:191'],
            'csd_name' => ['required', 'string', 'max:60'],
            'csd_account_number' => ['required', 'string', 'max:40'],

            // Bank details for e-payments (refunds).
            'bank_name' => ['required', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['required', 'string', 'max:191'],
            'bank_account_number' => ['required', 'digits:10'],
            'bank_city_state' => ['nullable', 'string', 'max:100'],
            'security_dealer' => ['nullable', 'string', 'max:191'],

            // Sponsor / co-sponsor / receiving agent, and payment.
            'payee_type' => ['required', Rule::in(['sponsor', 'co_sponsor', 'receiving_agent'])],
            'payee_id' => ['nullable', 'integer'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            // "qpay": after saving, the investor is sent to QPay to pay online.
            'pay_with' => ['nullable', Rule::in(['qpay', 'bank_transfer'])],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],

            // Signatures and seal (images). The first signature is required on a new bid.
            'signature_1' => array_merge([$creating ? 'required' : 'nullable'], $image),
            'signature_2' => array_merge(['nullable'], $image),
            'official_seal' => array_merge(['nullable'], $image),

            'declaration' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'declaration.accepted' => 'Confirm that you have read the offer documents and that the details are correct.',
            'bank_account_number.digits' => 'Enter a 10-digit NUBAN account number.',
            'csd_account_number.required' => 'Enter your CHN (CSCS) or your Client BPID (FMDQ Depository) for the e-allotment.',
            'signature_1.required' => 'Upload the first signature.',
        ];
    }

    public function attributes(): array
    {
        return [
            'csd_name' => 'CSD',
            'csd_account_number' => 'CSD account number (CHN / BPID)',
            'payee_type' => 'payee',
            'surname' => 'surname / corporate name',
            'contact_person' => 'contact person / next of kin',
            'address_line_1' => 'address',
            'allotment_preference' => 'allotment preference',
            'order_2_amount' => 'order 2 amount',
            'order_3_amount' => 'order 3 amount',
            'signature_1' => 'first signature',
            'signature_2' => 'second signature',
            'official_seal' => 'official seal',
        ];
    }
}
