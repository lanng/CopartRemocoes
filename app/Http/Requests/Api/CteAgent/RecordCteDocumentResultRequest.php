<?php

namespace App\Http\Requests\Api\CteAgent;

use Illuminate\Foundation\Http\FormRequest;

class RecordCteDocumentResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'claim_token' => ['required', 'string', 'size:64'],
            'outcome' => ['required', 'string', 'in:authorized,rejected,failed_before_authorization,reconciliation_required,dry_run_completed'],
            'occurred_at' => ['required', 'date'],
            'cte' => ['nullable', 'array'],
            'cte.number' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:20'],
            'cte.access_key' => ['required_if:outcome,authorized', 'nullable', 'regex:/^\d{44}$/'],
            'cte.series' => ['nullable', 'string', 'max:10'],
            'cte.protocol' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:30'],
            'cte.issued_at' => ['nullable', 'date'],
            'cte.authorized_at' => ['required_if:outcome,authorized', 'nullable', 'date'],
            'cte.status_code' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:10'],
            'cte.status_message' => ['nullable', 'string', 'max:500'],
            'evidence' => ['required_if:outcome,authorized', 'nullable', 'array'],
            'evidence.xml_sha256' => ['required_if:outcome,authorized', 'nullable', 'regex:/^[a-f0-9]{64}$/i'],
            'evidence.vehicle_plate' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:10'],
            'evidence.payment_code' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:100'],
            'evidence.xml_filename' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:255'],
            'error' => ['nullable', 'array'],
            'error.stage' => ['required_if:outcome,failed_before_authorization,reconciliation_required', 'nullable', 'string', 'max:50'],
            'error.code' => ['required_if:outcome,failed_before_authorization,reconciliation_required', 'nullable', 'string', 'max:100'],
            'error.message' => ['required_if:outcome,failed_before_authorization,reconciliation_required', 'nullable', 'string', 'max:2000'],
            'error.retryable' => ['nullable', 'boolean'],
            'validation' => ['nullable', 'array'],
        ];
    }
}
