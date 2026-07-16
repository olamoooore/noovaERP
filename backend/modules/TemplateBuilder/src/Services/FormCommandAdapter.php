<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Modules\TemplateBuilder\Models\FormSubmission;

/**
 * ADR-004 §6 / §8.2 — idempotent command adapter contract.
 *
 * Domain modules (CRM, Accounting, Sales) register one adapter per template
 * type they own. The Form runtime delegates authoritative record creation
 * to this adapter; it NEVER writes domain records directly.
 *
 * Implementations MUST be idempotent: replaying the same submission yields
 * the same target record (no duplicate), per ADR-004 §19 negative path.
 */
interface FormCommandAdapter
{
    /**
     * The template_type_code this adapter serves (e.g. crm_lead_capture).
     */
    public static function templateType(): string;

    public function execute(FormSubmission $submission): CommandAdapterResult;
}
