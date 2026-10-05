<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue #913: dry-run payload. Either supply a sample event `payload`, or let
 * the endpoint use the latest emitted event of the rule's type/entity.
 */
class DryRunAutomationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'payload' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
