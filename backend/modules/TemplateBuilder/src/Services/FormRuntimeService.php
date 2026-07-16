<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Illuminate\Support\Facades\DB;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\FormSubmission;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Platform\TemplateErrorCode;

/**
 * ADR-004 §8.2 / §12 — Data-Entry Form runtime.
 *
 * Validates a submission against the template's form schema, enforces
 * idempotency (ADR-004 §19 negative path: "Form attempts duplicate replay"),
 * persists the immutable submission record, and dispatches an approved
 * idempotent command adapter (§8.2). The runtime NEVER writes domain
 * records directly — it delegates through {@see FormCommandAdapter}.
 */
final class FormRuntimeService
{
    /**
     * @param  array<string, FormCommandAdapter>  $adapters  keyed by template_type_code
     */
    public function __construct(
        private readonly array $adapters = [],
    ) {}

    /**
     * Submit a form payload.
     *
     * @param  array<string,mixed>  $answers
     * @param  array<string,mixed>  $submissionContext  submitter_user_id, submitter_email, submitter_token_id, consent, attachments
     *
     * @throws TemplatePlatformException on validation failure or duplicate replay
     */
    public function submit(DocumentTemplate $template, array $answers, array $submissionContext = []): FormSubmission
    {
        $this->guardFormTemplate($template);

        $schema = $this->extractSchema($template);
        $this->validate($schema, $answers);

        $idempotencyKey = $submissionContext['idempotency_key']
            ?? $this->deriveIdempotencyKey($template, $answers, $submissionContext);

        // Idempotency: if a submission already exists for this key, return it as-is.
        $existing = FormSubmission::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($template, $answers, $submissionContext, $idempotencyKey, $schema) {
            $submission = FormSubmission::create([
                'template_id' => $template->id,
                'template_version' => $template->published_version,
                'schema_hash' => hash('sha256', json_encode($schema) ?: ''),
                'data_contract_version' => $template->data_contract_version,
                'answers' => $answers,
                'attachments' => $submissionContext['attachments'] ?? null,
                'consent' => $submissionContext['consent'] ?? null,
                'submitter_user_id' => $submissionContext['submitter_user_id'] ?? null,
                'submitter_email' => $submissionContext['submitter_email'] ?? null,
                'submitter_token_id' => $submissionContext['submitter_token_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'status' => FormSubmission::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            $this->dispatchCommandAdapter($template, $submission);

            return $submission->fresh();
        });
    }

    /**
     * Validate answers against the form schema (ADR-004 §12 Forms: typed
     * fields, server validation, conditional requirement).
     *
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $answers
     *
     * @throws TemplatePlatformException
     */
    public function validate(array $schema, array $answers): void
    {
        $errors = [];

        foreach ($schema as $field) {
            $key = $field['key'] ?? null;
            if ($key === null) {
                continue;
            }

            $required = $this->isRequired($field, $answers);
            $value = $answers[$key] ?? null;

            if ($required && ($value === null || $value === '')) {
                $errors[$key] = ['The '.$key.' field is required.'];

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $typeError = $this->validateType($field, $value);
            if ($typeError !== null) {
                $errors[$key] = [$typeError];
            }
        }

        if (! empty($errors)) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::ValidationFailed,
                'Form validation failed.',
                ['field_errors' => $errors],
            );
        }
    }

    private function guardFormTemplate(DocumentTemplate $template): void
    {
        $interaction = $template->interactionType();
        if ($interaction !== InteractionType::DataEntryForm) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::TypeNotRegistered,
                'Template is not a data-entry form.',
                ['interaction_type' => $interaction->value],
            );
        }

        if (! $template->isResolvable()) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::NotPublished,
                'Only published forms accept submissions.',
                ['status' => $template->status],
            );
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractSchema(DocumentTemplate $template): array
    {
        $layout = is_array($template->layout_config) ? $template->layout_config : [];

        return is_array($layout['fields'] ?? null) ? $layout['fields'] : (is_array($layout['schema'] ?? null) ? $layout['schema'] : []);
    }

    /**
     * Conditional requirement (ADR-004 §12 Forms): a field may declare a
     * "required_when" rule referencing another field's value.
     *
     * @param  array<string,mixed>  $field
     * @param  array<string,mixed>  $answers
     */
    private function isRequired(array $field, array $answers): bool
    {
        if (($field['required'] ?? false) === true) {
            return true;
        }

        $when = $field['required_when'] ?? null;
        if (! is_array($when)) {
            return false;
        }

        $other = $when['field'] ?? null;
        $equals = $when['equals'] ?? null;

        return $other !== null && ($answers[$other] ?? null) === $equals;
    }

    /**
     * @param  array<string,mixed>  $field
     */
    private function validateType(array $field, mixed $value): ?string
    {
        $type = $field['type'] ?? 'text';

        return match ($type) {
            'number', 'currency' => is_numeric($value) ? null : 'Must be a number.',
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : 'Must be a valid email address.',
            'date' => strtotime((string) $value) !== false ? null : 'Must be a valid date.',
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1'], true) ? null : 'Must be true or false.',
            default => null,
        };
    }

    private function deriveIdempotencyKey(DocumentTemplate $template, array $answers, array $context): string
    {
        // Stable hash of template + canonical answers + submitter identity.
        $submitter = $context['submitter_user_id']
            ?? $context['submitter_email']
            ?? $context['submitter_token_id']
            ?? 'anonymous';

        return 'auto:'.$template->id.':'.hash('sha256', $submitter.'|'.(json_encode($answers) ?: ''));
    }

    /**
     * Dispatch the approved idempotent command adapter (ADR-004 §8.2 / §6).
     * The runtime never writes authoritative domain records directly.
     */
    private function dispatchCommandAdapter(DocumentTemplate $template, FormSubmission $submission): void
    {
        $typeCode = $template->registration?->template_type_code ?? $template->document_type;
        $adapter = $this->adapters[$typeCode] ?? null;

        if ($adapter === null) {
            // No adapter registered: submission is recorded but not processed.
            // Domain modules register adapters for the types they own.
            $submission->update(['workflow_outcome' => ['status' => 'pending_adapter', 'reason' => 'No command adapter registered for '.$typeCode.'.']]);

            return;
        }

        $outcome = $adapter->execute($submission);

        $submission->update([
            'status' => $outcome->success ? FormSubmission::STATUS_PROCESSED : FormSubmission::STATUS_REJECTED,
            'target_record_type' => $outcome->targetRecordType,
            'target_record_id' => $outcome->targetRecordId,
            'workflow_outcome' => $outcome->payload,
            'rejection_reason' => $outcome->success ? null : $outcome->message,
            'processed_at' => now(),
        ]);
    }
}
