<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Issue #913: create an automation rule. The legal entity must be supplied in
 * the body or resolved from the request context (X-Entity-Id / entity_id).
 */
class StoreAutomationRuleRequest extends AutomationRuleRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('legal_entity_id')) {
            $entityId = $this->attributes->get('legal_entity_id');

            if ($entityId) {
                $this->merge(['legal_entity_id' => (int) $entityId]);
            }
        }
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'legal_entity_id' => [
                'required',
                'integer',
                Rule::exists('legal_entities', 'id'),
            ],
        ]);
    }

    public function messages(): array
    {
        return [
            'legal_entity_id.required' => 'A legal entity is required: send legal_entity_id or X-Entity-Id.',
        ];
    }
}
