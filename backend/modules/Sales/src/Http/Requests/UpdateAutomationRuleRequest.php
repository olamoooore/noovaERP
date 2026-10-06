<?php

namespace Modules\Sales\Http\Requests;

/**
 * Issue #913: edit an automation rule. Writing bumps the rule version and
 * appends a snapshot; the owning legal entity is immutable.
 */
class UpdateAutomationRuleRequest extends AutomationRuleRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // The entity is fixed at creation; reject attempts to move a rule
            // between entities rather than silently ignoring them.
            'legal_entity_id' => ['sometimes', 'integer'],
        ]);
    }
}
