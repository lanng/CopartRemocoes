<?php

namespace App\Http\Requests\Api\CteAgent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCteDocumentProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'claim_token' => ['required', 'string', 'size:64'],
            'stage' => ['required', 'string', 'in:filling,validating,ready_to_authorize,authorizing,waiting_for_xml'],
            'occurred_at' => ['required', 'date'],
            'details' => ['present', 'array'],
        ];
    }
}
