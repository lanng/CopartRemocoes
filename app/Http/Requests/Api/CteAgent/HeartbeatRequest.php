<?php

namespace App\Http\Requests\Api\CteAgent;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'api_version' => ['required', 'string', 'in:1'],
            'agent_version' => ['required', 'string', 'max:50'],
            'hostname' => ['required', 'string', 'max:255'],
            'dry_run' => ['required', 'boolean'],
            'capabilities' => ['present', 'array'],
            'capabilities.*' => ['string', 'max:50'],
        ];
    }
}
