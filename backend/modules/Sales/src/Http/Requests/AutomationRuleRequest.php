<?php

namespace Modules\Sales\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sales\Events\SalesEventCatalogue;
use Modules\Sales\Services\AutomationWebhookGuard;
use Modules\Sales\Support\AutomationCatalog;

/**
 * Shared validation for #913 automation rule writes. Conditions are checked
 * against the whitelisted fields for the selected catalogue event and the
 * whitelisted operators; actions are checked per action type. A webhook URL
 * is resolved through the SSRF guard at save time as well as at execution.
 */
abstract class AutomationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $eventName = (string) $this->input('event_name', '');
        $allowedFields = AutomationCatalog::supportsEvent($eventName)
            ? AutomationCatalog::conditionFieldsFor($eventName)
            : [];

        return [
            'name' => ['required', 'string', 'max:150'],
            'event_name' => ['required', 'string', Rule::in(SalesEventCatalogue::eventNames())],
            'is_active' => ['sometimes', 'boolean'],

            'conditions' => ['present', 'array'],
            'conditions.*.field' => ['required', 'string', Rule::in($allowedFields)],
            'conditions.*.operator' => ['required', 'string', Rule::in(AutomationCatalog::OPERATORS)],
            'conditions.*.value' => ['nullable'],

            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', 'string', Rule::in(AutomationCatalog::ACTION_TYPES)],

            'actions.*.subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'actions.*.message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'actions.*.respect_preferences' => ['sometimes', 'boolean'],

            'actions.*.user_ids' => ['sometimes', 'array'],
            'actions.*.user_ids.*' => ['integer'],
            'actions.*.roles' => ['sometimes', 'array'],
            'actions.*.roles.*' => ['string', Rule::in(UserRole::values())],
            'actions.*.account_owner' => ['sometimes', 'boolean'],

            'actions.*.summary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'actions.*.note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'actions.*.due_in_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'actions.*.assignee_user_id' => ['sometimes', 'nullable', 'integer'],

            'actions.*.reason' => ['sometimes', 'nullable', 'string', 'max:500'],

            'actions.*.url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateConditionValues($validator);
            $this->validateActions($validator);
        });
    }

    private function validateConditionValues(Validator $validator): void
    {
        foreach ((array) $this->input('conditions', []) as $index => $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $operator = (string) ($condition['operator'] ?? '');

            if ($operator === '' || in_array($operator, AutomationCatalog::VALUELESS_OPERATORS, true)) {
                continue;
            }

            if (! array_key_exists('value', $condition)) {
                $validator->errors()->add("conditions.{$index}.value", 'A value is required for this operator.');

                continue;
            }

            if (in_array($operator, [AutomationCatalog::OPERATOR_IN, AutomationCatalog::OPERATOR_NOT_IN], true)
                && ! is_array($condition['value'])) {
                $validator->errors()->add("conditions.{$index}.value", 'This operator expects a list of values.');
            }
        }
    }

    private function validateActions(Validator $validator): void
    {
        foreach ((array) $this->input('actions', []) as $index => $action) {
            if (! is_array($action)) {
                continue;
            }

            switch ((string) ($action['type'] ?? '')) {
                case AutomationCatalog::ACTION_NOTIFY:
                    $hasRecipient = ! empty($action['user_ids'])
                        || ! empty($action['roles'])
                        || ! empty($action['account_owner']);

                    if (! $hasRecipient) {
                        $validator->errors()->add("actions.{$index}", 'A notify action needs at least one of user_ids, roles or account_owner.');
                    }
                    break;

                case AutomationCatalog::ACTION_CREATE_TASK:
                    if (trim((string) ($action['summary'] ?? '')) === '') {
                        $validator->errors()->add("actions.{$index}.summary", 'A task summary is required.');
                    }
                    break;

                case AutomationCatalog::ACTION_WEBHOOK:
                    $url = (string) ($action['url'] ?? '');

                    if (trim($url) === '') {
                        $validator->errors()->add("actions.{$index}.url", 'A webhook URL is required.');
                        break;
                    }

                    try {
                        app(AutomationWebhookGuard::class)->resolve($url);
                    } catch (\Throwable $e) {
                        $validator->errors()->add("actions.{$index}.url", 'Webhook URL rejected: '.$e->getMessage());
                    }
                    break;
            }
        }
    }
}
