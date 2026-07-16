<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Platform;

/**
 * ADR-004 §3 — Template Interaction Type.
 *
 * Classifies what a template *produces*, NOT how it integrates (that is
 * RuntimeMode). Use this enum — never RuntimeMode — to distinguish
 * Document / Form / Survey (ADR-004 §21.6).
 */
enum InteractionType: string
{
    /** Business document output rendered to PDF/HTML/print (e.g. invoice, quotation). */
    case BusinessDocument = 'BUSINESS_DOCUMENT';

    /** Data-entry form that captures a structured submission (e.g. lead capture, expense request). */
    case DataEntryForm = 'DATA_ENTRY_FORM';

    /** Survey or assessment with branching/scoring (e.g. NPS, audit readiness checklist). */
    case SurveyAssessment = 'SURVEY_ASSESSMENT';

    public function label(): string
    {
        return match ($this) {
            self::BusinessDocument => 'Business Document',
            self::DataEntryForm => 'Data-Entry Form',
            self::SurveyAssessment => 'Survey / Assessment',
        };
    }

    public function studioMode(): string
    {
        return match ($this) {
            self::BusinessDocument => 'document',
            self::DataEntryForm => 'form',
            self::SurveyAssessment => 'survey',
        };
    }
}
