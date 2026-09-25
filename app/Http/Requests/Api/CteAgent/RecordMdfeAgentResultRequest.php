<?php

namespace App\Http\Requests\Api\CteAgent;

use Illuminate\Foundation\Http\FormRequest;

class RecordMdfeAgentResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'claim_token' => ['required', 'string', 'size:64'],
            'outcome' => ['required', 'string', 'in:authorized,rejected,failed_before_authorization,reconciliation_required,dry_run_completed'],
            'occurred_at' => ['required', 'date'],
            'mdfe' => ['nullable', 'array'],
            'mdfe.number' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:20'],
            'mdfe.access_key' => ['required_if:outcome,authorized', 'nullable', 'regex:/^\d{44}$/'],
            'mdfe.series' => ['nullable', 'string', 'max:10'],
            'mdfe.protocol' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:30'],
            'mdfe.issued_at' => ['nullable', 'date'],
            'mdfe.authorized_at' => ['required_if:outcome,authorized', 'nullable', 'date'],
            'mdfe.status_code' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:10'],
            'mdfe.status_message' => ['nullable', 'string', 'max:500'],
            'evidence' => ['required_if:outcome,authorized', 'nullable', 'array'],
            'evidence.xml_sha256' => ['required_if:outcome,authorized', 'nullable', 'regex:/^[a-f0-9]{64}$/i'],
            'evidence.xml_filename' => ['required_if:outcome,authorized', 'nullable', 'string', 'max:255'],
            'evidence.cte_access_keys' => ['required_if:outcome,authorized', 'nullable', 'array', 'min:1'],
            'evidence.cte_access_keys.*' => ['required_if:outcome,authorized', 'nullable', 'regex:/^\d{44}$/'],
            'evidence.q_cte' => ['required_if:outcome,authorized', 'nullable', 'integer', 'min:1'],
            'error' => ['nullable', 'array'],
            'error.stage' => ['required_if:outcome,failed_before_authorization,reconciliation_required', 'nullable', 'string', 'max:50'],
            'error.code' => ['required_if:outcome,failed_before_authorization,reconciliation_required', 'nullable', 'string', 'max:100'],
            'error.message' => ['required_if:outcome,failed_before_authorization,reconciliation_required', 'nullable', 'string', 'max:2000'],
            'error.retryable' => ['nullable', 'boolean'],
            'validation' => ['nullable', 'array'],
        ];
    }
}
