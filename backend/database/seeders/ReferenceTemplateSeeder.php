<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Modules\TemplateBuilder\Models\TemplateRegistration;

/**
 * ADR-004 §17 G2/G3 — Seed reference template instances for Sales, CRM
 * and Accounting domains.
 *
 * Each template is linked to its canonical registration, published with a
 * version snapshot, and assigned at tenant scope. Idempotent — skips
 * document_type + registration_id combinations that already exist.
 */
final class ReferenceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $def) {
            $reg = TemplateRegistration::where('template_type_code', $def['registration_type_code'])
                ->where('interaction_type', $def['interaction_type'])
                ->first();

            if ($reg === null) {
                $this->command?->warn("  [skip] Registration not found: {$def['registration_type_code']} ({$def['interaction_type']})");

                continue;
            }

            $exists = DocumentTemplate::where('registration_id', $reg->id)
                ->where('domain_module_code', $def['domain_module_code'])
                ->exists();

            if ($exists) {
                continue;
            }

            $template = DocumentTemplate::create([
                'id' => (string) Str::orderedUuid(),
                'document_type' => $def['document_type'],
                'code' => $def['code'],
                'name' => $def['name'],
                'description' => $def['description'] ?? null,
                'mode' => 'block',
                'format' => $def['format'] ?? 'A4',
                'orientation' => $def['orientation'] ?? 'portrait',
                'default_font' => 'DejaVu Sans',
                'default_locale' => 'en',
                'status' => DocumentTemplate::STATUS_PUBLISHED,
                'interaction_type' => $def['interaction_type'],
                'domain_module_code' => $def['domain_module_code'],
                'registration_id' => $reg->id,
                'is_system_default' => true,
                'published_version' => 1,
                'layout_config' => $def['layout_config'],
                'metadata' => ['seeded_by' => 'ReferenceTemplateSeeder', 'adr004' => true],
            ]);

            $template->versions()->create([
                'template_id' => $template->id,
                'version' => 1,
                'layout_config' => $template->layout_config,
                'change_note' => 'ADR-004 reference template seed',
                'is_published' => true,
            ]);

            DocumentTemplateAssignment::create([
                'document_type' => $template->document_type,
                'template_type_code' => $reg->template_type_code,
                'interaction_type' => $def['interaction_type'],
                'template_id' => $template->id,
                'scope_type' => 'tenant',
                'priority' => 10,
                'is_active' => true,
            ]);

            $this->command?->info("  [ok] Seeded: {$def['name']} ({$reg->template_type_code})");
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function definitions(): array
    {
        return array_merge(
            $this->salesTemplates(),
            $this->crmTemplates(),
            $this->accountingTemplates(),
        );
    }

    // ── G2: Sales ──────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function salesTemplates(): array
    {
        return [
            $this->quotationTemplate(),
        ];
    }

    private function quotationTemplate(): array
    {
        return [
            'registration_type_code' => 'sales_quotation',
            'interaction_type' => 'BUSINESS_DOCUMENT',
            'domain_module_code' => 'sales',
            'document_type' => 'quotation',
            'code' => 'QT-ADR004-DEFAULT',
            'name' => 'ADR-004 Sales Quotation',
            'description' => 'Published sales quotation with line items, totals and terms (ADR-004 reference).',
            'format' => 'A4',
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">QUOTATION</div><div style="font-size:10px;color:#6b7280;">{{ company.email }} · {{ company.phone }}</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Bill To</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ customer.billing_address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.email }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Quote #:</b> {{ quotation.number }}<br/><b>Date:</b> {{ quotation.issue_date }}<br/><b>Valid Until:</b> {{ quotation.valid_until }}<br/><b>Reference:</b> {{ quotation.reference }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'table', 'source' => 'lines', 'paginate' => true, 'show_page_subtotal' => true, 'subtotal_label' => 'Page Subtotal', 'subtotal_field' => 'line_total', 'subtotal_format' => 'money', 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '6%', 'align' => 'center'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '46%'],
                        ['label' => 'Qty', 'field' => 'quantity', 'width' => '12%', 'align' => 'right'],
                        ['label' => 'Unit Price', 'field' => 'unit_price', 'width' => '18%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Amount', 'field' => 'line_total', 'width' => '18%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'totals', 'rows' => [
                        ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                        ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                        ['label' => 'Total', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'spacer', 'height' => 16],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => '{{ quotation.terms }}'],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }}'],
                    ]],
                ],
            ],
        ];
    }

    // ── G2: CRM ────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function crmTemplates(): array
    {
        return [
            $this->leadCaptureForm(),
            $this->customerSatisfactionSurvey(),
        ];
    }

    private function leadCaptureForm(): array
    {
        return [
            'registration_type_code' => 'crm_lead_capture',
            'interaction_type' => 'DATA_ENTRY_FORM',
            'domain_module_code' => 'crm',
            'document_type' => 'crm_form',
            'code' => 'CRM-LEAD-ADR004',
            'name' => 'ADR-004 Lead Capture Form',
            'description' => 'Multi-step lead capture with consent, conditional fields and duplicate check (ADR-004 reference).',
            'format' => 'A4',
            'layout_config' => [
                'fields' => [
                    [
                        'key' => 'full_name',
                        'label' => 'Full Name',
                        'type' => 'text',
                        'required' => true,
                        'placeholder' => 'John Doe',
                    ],
                    [
                        'key' => 'email',
                        'label' => 'Email Address',
                        'type' => 'email',
                        'required' => true,
                        'placeholder' => 'john@example.com',
                    ],
                    [
                        'key' => 'phone',
                        'label' => 'Phone Number',
                        'type' => 'text',
                        'required' => false,
                        'placeholder' => '+60 12-345 6789',
                    ],
                    [
                        'key' => 'company_name',
                        'label' => 'Company Name',
                        'type' => 'text',
                        'required' => false,
                    ],
                    [
                        'key' => 'source',
                        'label' => 'Lead Source',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['website', 'referral', 'social_media', 'cold_call', 'event', 'advertisement'],
                    ],
                    [
                        'key' => 'interest_product',
                        'label' => 'Product of Interest',
                        'type' => 'text',
                        'required' => false,
                    ],
                    [
                        'key' => 'budget_range',
                        'label' => 'Estimated Budget',
                        'type' => 'select',
                        'required' => false,
                        'options' => ['< 5,000', '5,000 - 20,000', '20,000 - 100,000', '> 100,000'],
                    ],
                    [
                        'key' => 'consent_marketing',
                        'label' => 'I consent to receiving marketing communications',
                        'type' => 'boolean',
                        'required' => true,
                    ],
                    [
                        'key' => 'notes',
                        'label' => 'Additional Notes',
                        'type' => 'textarea',
                        'required' => false,
                        'required_when' => [
                            'field' => 'source',
                            'equals' => 'referral',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function customerSatisfactionSurvey(): array
    {
        return [
            'registration_type_code' => 'crm_customer_satisfaction',
            'interaction_type' => 'SURVEY_ASSESSMENT',
            'domain_module_code' => 'crm',
            'document_type' => 'crm_survey',
            'code' => 'CRM-CSAT-ADR004',
            'name' => 'ADR-004 Customer Satisfaction Survey',
            'description' => 'NPS + satisfaction rating with branching and scoring bands (ADR-004 reference).',
            'format' => 'A4',
            'layout_config' => [
                'survey' => [
                    'scoring_version' => '1',
                    'requires_consent' => true,
                    'max_responses' => 1000,
                    'identity_policy' => 'authenticated',
                    'questions' => [
                        [
                            'key' => 'overall_satisfaction',
                            'label' => 'How satisfied are you with our service?',
                            'type' => 'rating',
                            'options' => [
                                ['value' => '1', 'label' => 'Very Dissatisfied', 'score' => 1],
                                ['value' => '2', 'label' => 'Dissatisfied', 'score' => 2],
                                ['value' => '3', 'label' => 'Neutral', 'score' => 3],
                                ['value' => '4', 'label' => 'Satisfied', 'score' => 4],
                                ['value' => '5', 'label' => 'Very Satisfied', 'score' => 5],
                            ],
                        ],
                        [
                            'key' => 'nps_score',
                            'label' => 'How likely are you to recommend us? (0-10)',
                            'type' => 'nps',
                            'options' => [
                                ['value' => '0', 'score' => 0],
                                ['value' => '1', 'score' => 1],
                                ['value' => '2', 'score' => 2],
                                ['value' => '3', 'score' => 3],
                                ['value' => '4', 'score' => 4],
                                ['value' => '5', 'score' => 5],
                                ['value' => '6', 'score' => 6],
                                ['value' => '7', 'score' => 7],
                                ['value' => '8', 'score' => 8],
                                ['value' => '9', 'score' => 9],
                                ['value' => '10', 'score' => 10],
                            ],
                        ],
                        [
                            'key' => 'detractor_reason',
                            'label' => 'What is the main reason for your low score?',
                            'type' => 'textarea',
                            'show_when' => ['field' => 'nps_score', 'equals' => '6'],
                        ],
                        [
                            'key' => 'detractor_reason_5',
                            'label' => 'What is the main reason for your low score?',
                            'type' => 'textarea',
                            'show_when' => ['field' => 'nps_score', 'equals' => '5'],
                        ],
                        [
                            'key' => 'detractor_reason_4',
                            'label' => 'What is the main reason for your low score?',
                            'type' => 'textarea',
                            'show_when' => ['field' => 'nps_score', 'equals' => '4'],
                        ],
                        [
                            'key' => 'detractor_reason_3',
                            'label' => 'What is the main reason for your low score?',
                            'type' => 'textarea',
                            'show_when' => ['field' => 'nps_score', 'equals' => '3'],
                        ],
                        [
                            'key' => 'detractor_reason_2',
                            'label' => 'What is the main reason for your low score?',
                            'type' => 'textarea',
                            'show_when' => ['field' => 'nps_score', 'equals' => '2'],
                        ],
                        [
                            'key' => 'detractor_reason_1',
                            'label' => 'What is the main reason for your low score?',
                            'type' => 'textarea',
                            'show_when' => ['field' => 'nps_score', 'equals' => '1'],
                        ],
                        [
                            'key' => 'detractor_reason_0',
                            'label' => 'What is the main reason for your low score?',
                            'type' => 'textarea',
                            'show_when' => ['field' => 'nps_score', 'equals' => '0'],
                        ],
                        [
                            'key' => 'promoter_feedback',
                            'label' => 'What do you love most about our service?',
                            'type' => 'textarea',
                            'show_when' => ['field' => 'nps_score', 'equals' => '9'],
                        ],
                        [
                            'key' => 'promoter_feedback_10',
                            'label' => 'What do you love most about our service?',
                            'type' => 'textarea',
                            'show_when' => ['field' => 'nps_score', 'equals' => '10'],
                        ],
                        [
                            'key' => 'improvement_areas',
                            'label' => 'Which areas need the most improvement?',
                            'type' => 'checkbox',
                            'options' => ['product_quality', 'customer_service', 'pricing', 'delivery', 'communication', 'documentation'],
                        ],
                        [
                            'key' => 'comments',
                            'label' => 'Any additional comments?',
                            'type' => 'textarea',
                        ],
                    ],
                    'bands' => [
                        ['label' => 'detractor', 'min' => 0, 'max' => 6],
                        ['label' => 'passive', 'min' => 7, 'max' => 8],
                        ['label' => 'promoter', 'min' => 9, 'max' => 10],
                    ],
                    'thresholds' => [
                        ['at' => 9, 'action' => 'flag_for_followup'],
                        ['at' => 5, 'action' => 'escalate_to_manager'],
                    ],
                ],
            ],
        ];
    }

    // ── G3: Accounting ─────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function accountingTemplates(): array
    {
        return [
            $this->invoiceTemplate(),
            $this->creditNoteTemplate(),
            $this->receiptTemplate(),
            $this->statementTemplate(),
        ];
    }

    private function invoiceTemplate(): array
    {
        return [
            'registration_type_code' => 'ar_invoice',
            'interaction_type' => 'BUSINESS_DOCUMENT',
            'domain_module_code' => 'accounting',
            'document_type' => 'invoice',
            'code' => 'INV-ADR004-DEFAULT',
            'name' => 'ADR-004 Invoice',
            'description' => 'Published sales invoice with line items, tax, discount and terms (ADR-004 reference).',
            'format' => 'A4',
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">INVOICE</div><div style="font-size:10px;color:#6b7280;">{{ company.email }} · {{ company.phone }}</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Bill To</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ invoice.billing_address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.email }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Invoice #:</b> {{ invoice.number }}<br/><b>Date:</b> {{ invoice.issue_date }}<br/><b>Due:</b> {{ invoice.due_date }}<br/><b>Reference:</b> {{ invoice.reference }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'table', 'source' => 'lines', 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '6%', 'align' => 'center'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '46%'],
                        ['label' => 'Qty', 'field' => 'quantity', 'width' => '12%', 'align' => 'right'],
                        ['label' => 'Unit Price', 'field' => 'unit_price', 'width' => '18%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Amount', 'field' => 'line_total', 'width' => '18%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'conditional', 'condition' => '{{ totals.discount }} > 0', 'children' => [
                        ['type' => 'totals', 'rows' => [
                            ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                            ['label' => 'Discount', 'field' => 'totals.discount', 'format' => 'money'],
                            ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                            ['label' => 'Total Due', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                        ]],
                    ]],
                    ['type' => 'conditional', 'condition' => '{{ totals.discount }} <= 0', 'children' => [
                        ['type' => 'totals', 'rows' => [
                            ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                            ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                            ['label' => 'Total Due', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                        ]],
                    ]],
                    ['type' => 'spacer', 'height' => 16],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => '{{ invoice.terms }}'],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }}'],
                    ]],
                ],
            ],
        ];
    }

    private function creditNoteTemplate(): array
    {
        return [
            'registration_type_code' => 'ar_credit_note',
            'interaction_type' => 'BUSINESS_DOCUMENT',
            'domain_module_code' => 'accounting',
            'document_type' => 'credit_note',
            'code' => 'CN-ADR004-DEFAULT',
            'name' => 'ADR-004 Credit Note',
            'description' => 'Published credit note referencing original invoice with approved values (ADR-004 reference).',
            'format' => 'A4',
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">CREDIT NOTE</div><div style="font-size:10px;color:#6b7280;">{{ company.email }} · {{ company.phone }}</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Credit To</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ credit_note.billing_address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.email }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Credit Note #:</b> {{ credit_note.number }}<br/><b>Date:</b> {{ credit_note.issue_date }}<br/><b>Original Invoice:</b> {{ credit_note.invoice_number }}<br/><b>Reason:</b> {{ credit_note.reason }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'table', 'source' => 'lines', 'columns' => [
                        ['label' => '#', 'field' => 'index', 'width' => '6%', 'align' => 'center'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '46%'],
                        ['label' => 'Qty', 'field' => 'quantity', 'width' => '12%', 'align' => 'right'],
                        ['label' => 'Unit Price', 'field' => 'unit_price', 'width' => '18%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Amount', 'field' => 'line_total', 'width' => '18%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'totals', 'rows' => [
                        ['label' => 'Subtotal', 'field' => 'totals.subtotal', 'format' => 'money'],
                        ['label' => 'Tax', 'field' => 'totals.tax', 'format' => 'money'],
                        ['label' => 'Credit Amount', 'field' => 'totals.total', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'spacer', 'height' => 16],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => '{{ credit_note.notes }}'],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }}'],
                    ]],
                ],
            ],
        ];
    }

    private function receiptTemplate(): array
    {
        return [
            'registration_type_code' => 'ar_official_receipt',
            'interaction_type' => 'BUSINESS_DOCUMENT',
            'domain_module_code' => 'accounting',
            'document_type' => 'receipt',
            'code' => 'RCT-ADR004-DEFAULT',
            'name' => 'ADR-004 Official Receipt',
            'description' => 'Published payment receipt with amount, method and invoice reference (ADR-004 reference).',
            'format' => 'A4',
            'layout_config' => [
                'blocks' => [
                    ['type' => 'text', 'align' => 'center', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div>'],
                    ['type' => 'divider'],
                    ['type' => 'text', 'align' => 'center', 'content' => '<div style="font-size:22px;font-weight:bold;">PAYMENT RECEIPT</div>'],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'text', 'content' => 'Received from: <b>{{ customer.name }}</b>'],
                    ['type' => 'text', 'content' => 'Receipt #: <b>{{ receipt.number }}</b> &nbsp; Date: {{ receipt.date }}'],
                    ['type' => 'text', 'content' => 'Method: {{ receipt.method }} &nbsp; Reference: {{ receipt.reference }}'],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'totals', 'rows' => [
                        ['label' => 'Amount Received', 'field' => 'totals.amount', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'text', 'size' => 9, 'color' => '#6b7280', 'content' => 'Applied to invoice {{ receipt.invoice_no }}. Thank you for your payment.'],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }}'],
                    ]],
                ],
            ],
        ];
    }

    private function statementTemplate(): array
    {
        return [
            'registration_type_code' => 'customer_statement',
            'interaction_type' => 'BUSINESS_DOCUMENT',
            'domain_module_code' => 'accounting',
            'document_type' => 'statement',
            'code' => 'STMT-ADR004-DEFAULT',
            'name' => 'ADR-004 Customer Statement',
            'description' => 'Published customer statement with ageing summary and transaction detail (ADR-004 reference).',
            'format' => 'A4',
            'layout_config' => [
                'blocks' => [
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<div style="font-size:20px;font-weight:bold;color:#111827;">{{ company.name }}</div><div style="font-size:10px;color:#6b7280;">{{ company.address }}</div>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<div style="font-size:24px;font-weight:bold;color:#111827;">CUSTOMER STATEMENT</div><div style="font-size:10px;color:#6b7280;">{{ company.email }} · {{ company.phone }}</div>'],
                    ]],
                    ['type' => 'divider'],
                    ['type' => 'columns', 'gap' => 8, 'children' => [
                        ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Statement For</b><br/><b>{{ customer.name }}</b><br/><span style="font-size:10px;color:#374151;">{{ customer.billing_address }}</span><br/><span style="font-size:10px;color:#6b7280;">{{ customer.email }}</span>'],
                        ['type' => 'text', 'align' => 'right', 'content' => '<b>Period:</b> {{ statement.period_start }} to {{ statement.period_end }}<br/><b>Generated:</b> {{ statement.generated_date }}<br/><b>Customer Code:</b> {{ customer.code }}'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Ageing Summary</b>'],
                    ['type' => 'table', 'source' => 'ageing', 'columns' => [
                        ['label' => 'Current', 'field' => 'current', 'width' => '20%', 'align' => 'right', 'format' => 'money'],
                        ['label' => '1-30 Days', 'field' => 'days_1_30', 'width' => '20%', 'align' => 'right', 'format' => 'money'],
                        ['label' => '31-60 Days', 'field' => 'days_31_60', 'width' => '20%', 'align' => 'right', 'format' => 'money'],
                        ['label' => '61-90 Days', 'field' => 'days_61_90', 'width' => '20%', 'align' => 'right', 'format' => 'money'],
                        ['label' => '90+ Days', 'field' => 'days_90_plus', 'width' => '20%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 12],
                    ['type' => 'text', 'content' => '<b style="color:#6b7280;font-size:9px;text-transform:uppercase;">Transactions</b>'],
                    ['type' => 'table', 'source' => 'transactions', 'columns' => [
                        ['label' => 'Date', 'field' => 'date', 'width' => '14%'],
                        ['label' => 'Reference', 'field' => 'reference', 'width' => '18%'],
                        ['label' => 'Description', 'field' => 'description', 'width' => '30%'],
                        ['label' => 'Debit', 'field' => 'debit', 'width' => '14%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Credit', 'field' => 'credit', 'width' => '14%', 'align' => 'right', 'format' => 'money'],
                        ['label' => 'Balance', 'field' => 'balance', 'width' => '10%', 'align' => 'right', 'format' => 'money'],
                    ]],
                    ['type' => 'spacer', 'height' => 10],
                    ['type' => 'totals', 'rows' => [
                        ['label' => 'Total Debit', 'field' => 'totals.debit', 'format' => 'money'],
                        ['label' => 'Total Credit', 'field' => 'totals.credit', 'format' => 'money'],
                        ['label' => 'Outstanding Balance', 'field' => 'totals.balance', 'format' => 'money', 'bold' => true],
                    ]],
                    ['type' => 'footer', 'children' => [
                        ['type' => 'divider'],
                        ['type' => 'text', 'align' => 'center', 'size' => 9, 'color' => '#9ca3af', 'content' => '{{ company.name }} · {{ company.email }} · {{ company.phone }}'],
                    ]],
                ],
            ],
        ];
    }
}
