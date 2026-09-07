<?php

namespace App\Accounting;

use App\Accounting\Enums\ReasonCode;

/**
 * ACC-REG-001 — Accounting Capability and Ownership Registry.
 *
 * DOCUMENTATION-ONLY (P1-7, #50): This registry defines canonical bounded
 * capabilities that together form NoovaERP Accounting. It establishes
 * intended authority for posting, revision, workflow, negative-state
 * semantics, correction behaviour, and AI boundaries.
 *
 * This class has NO runtime enforcement effect. It is NOT consulted by
 * any middleware, service, or controller at runtime. It serves as a
 * single source of truth for architecture documentation and compliance
 * test assertions only. Cross-boundary violations are NOT prevented by
 * this class — they are caught only by explicit tests or code review.
 *
 * The constant `ENFORCEMENT_MODE === 'documentation-only'` is a stable,
 * testable marker for CI/architecture tests that this registry is in
 * documentation mode. If runtime enforcement is desired in the future,
 * wire this registry into a CI architecture test or a runtime boundary
 * guard, and update ENFORCEMENT_MODE accordingly.
 *
 * ACC-REG-001 §3 — Canonical Capability Registry.
 * ACC-REG-001 §4 — Mandatory Capability Metadata.
 *
 * ACC-DEC-060 Track A (issue #69): InitializePostingPeriod has been
 * retired as a separate component. Its fiscal-period initialization
 * responsibility is absorbed into FiscalCalendar / AccountingPeriodDefinition
 * configuration. The 5-state period model (OPEN / SOFT_CLOSE /
 * CLOSE_IN_PROGRESS / CLOSED / REOPENED) has been rescoped to 3 states
 * (OPEN / CLOSED / LOCKED) matching the DB schema. SOFT_CLOSE and
 * CLOSE_IN_PROGRESS belong to ACC-011 Financial Close workflow state.
 */
class CapabilityRegistry
{
    /**
     * Stable marker for #50 compliance: when this constant equals
     * 'documentation-only', the registry makes no runtime enforcement
     * claims and CI architecture tests must reflect that fact.
     */
    public const ENFORCEMENT_MODE = 'documentation-only';
    /**
     * @var array<string, array{capability_id: string, capability_code: string, capability_name: string, business_purpose: string, classification: string, owned_entities: array<string>, prohibited_responsibilities: array<string>}>
     */
    private static array $capabilities = [
        'ACC-001' => [
            'capability_id' => 'ACC-001',
            'capability_code' => 'ACCOUNTING_FOUNDATION',
            'capability_name' => 'Accounting Foundation',
            'business_purpose' => 'Configuration and policy for fiscal calendar, accounting periods, posting policies, control accounts, and accounting defaults.',
            'classification' => 'Core',
            'owned_entities' => [
                'AccountingBook',
                'FiscalCalendar',
                'AccountingPeriodDefinition',
                'AccountingPolicy',
                'PostingPolicy',
                'AccountingDimensionDefinition',
                'AccountingControlAccountPolicy',
                'AccountingEntityFinanceProfile',
                'AccountingDefaults',
                'OpeningBalanceSetup',
                'AccountingRoundingPolicy',
                'DocumentRevisionPolicyReference',
            ],
            'prohibited_responsibilities' => [
                'Insert LedgerEntry directly',
                'Mutate posted LedgerEntry or JournalLine',
                'Bypass Posting Engine controls',
            ],
        ],
        'ACC-002' => [
            'capability_id' => 'ACC-002',
            'capability_code' => 'GENERAL_LEDGER',
            'capability_name' => 'General Ledger, Journals & Posting',
            'business_purpose' => 'Authoritative posted accounting records, chart of accounts, journals, and posting engine.',
            'classification' => 'Core',
            'owned_entities' => [
                'LedgerAccount',
                'ChartOfAccounts',
                'Journal',
                'JournalLine',
                'PostingBatch',
                'DomainPostingRequestRecord',
                'JournalPostingRequestRecord',
                'LedgerEntry',
                'LedgerEntryLine',
                'PostingReference',
                'ReversalRelationship',
                'AccountBalanceProjection',
                'PostingFailureRecord',
            ],
            'prohibited_responsibilities' => [
                'Own workflow engine',
                'Override Tax Engine',
                'Approve without domain validation',
            ],
        ],
        'ACC-003' => [
            'capability_id' => 'ACC-003',
            'capability_code' => 'ACCOUNTS_RECEIVABLE',
            'capability_name' => 'Accounts Receivable',
            'business_purpose' => 'Receivable accounting lifecycle including customer accounting profiles, receivable records, and ageing projections.',
            'classification' => 'Core',
            'owned_entities' => [
                'CustomerAccountingProfile',
                'ReceivableAccountingRecord',
                'ReceivableObligation',
                'ARAdjustment',
                'ARAllocation',
                'CustomerStatementAccount',
                'CollectionStatus',
                'ARAgeingProjection',
            ],
            'prohibited_responsibilities' => [
                'Own Customer identity',
                'Write GL directly',
            ],
        ],
        'ACC-004' => [
            'capability_id' => 'ACC-004',
            'capability_code' => 'ACCOUNTS_PAYABLE',
            'capability_name' => 'Accounts Payable',
            'business_purpose' => 'Payable accounting lifecycle including supplier accounting profiles, payable records, and payment schedules.',
            'classification' => 'Core',
            'owned_entities' => [
                'SupplierAccountingProfile',
                'PayableAccountingRecord',
                'PayableObligation',
                'APAdjustment',
                'APAllocation',
                'PaymentSchedule',
                'APAgeingProjection',
            ],
            'prohibited_responsibilities' => [
                'Own Supplier identity',
                'Own Purchase Order',
                'Own Goods Receipt',
                'Own employee expense workflow',
            ],
        ],
        'ACC-005' => [
            'capability_id' => 'ACC-005',
            'capability_code' => 'PAYMENTS_SETTLEMENT',
            'capability_name' => 'Payments & Settlement',
            'business_purpose' => 'Payment execution and allocation, settlement lifecycle.',
            'classification' => 'Core',
            'owned_entities' => [
                'PaymentExecutionRecord',
                'SettlementAllocationRecord',
                'PaymentScheduleEntry',
            ],
            'prohibited_responsibilities' => [
                'Own bank account identity',
                'Override posting authority',
            ],
        ],
        'ACC-006' => [
            'capability_id' => 'ACC-006',
            'capability_code' => 'CASH_BANKING',
            'capability_name' => 'Cash & Banking',
            'business_purpose' => 'Bank/cash accounting and reconciliation.',
            'classification' => 'Core',
            'owned_entities' => [
                'BankAccount',
                'CashAccount',
                'BankReconciliationRecord',
                'BankStatementImport',
                'CashPositionProjection',
            ],
            'prohibited_responsibilities' => [
                'Override GL authority',
                'Silently mutate posting state',
            ],
        ],
        'ACC-007' => [
            'capability_id' => 'ACC-007',
            'capability_code' => 'FIXED_ASSET_ACCOUNTING',
            'capability_name' => 'Fixed Asset Accounting',
            'business_purpose' => 'Asset financial lifecycle including capitalisation, depreciation, and disposal.',
            'classification' => 'Optional',
            'owned_entities' => [
                'AccountingAsset',
                'AssetBook',
                'CapitalisationRecord',
                'DepreciationSchedule',
                'DepreciationRun',
                'ImpairmentRecord',
                'AssetTransferAccountingRecord',
                'DisposalAccountingRecord',
                'AssetFinancialHistory',
            ],
            'prohibited_responsibilities' => [
                'Own operational asset',
            ],
        ],
        'ACC-008' => [
            'capability_id' => 'ACC-008',
            'capability_code' => 'CURRENCY_FX',
            'capability_name' => 'Currency & FX Accounting',
            'business_purpose' => 'Finance FX configuration, exchange rates, and revaluation.',
            'classification' => 'Shared finance',
            'owned_entities' => [
                'FinanceCurrencyConfiguration',
                'AccountingRate',
                'ExchangeRateSet',
                'FXRevaluationRun',
                'RealisedFXRecord',
                'UnrealisedFXRecord',
            ],
            'prohibited_responsibilities' => [
                'Own global currency reference',
            ],
        ],
        'ACC-009' => [
            'capability_id' => 'ACC-009',
            'capability_code' => 'TAX_REGULATORY',
            'capability_name' => 'Tax & Regulatory Accounting',
            'business_purpose' => 'Tax determination, compliance, and regulatory accounting.',
            'classification' => 'Compliance',
            'owned_entities' => [
                'TaxPolicy',
                'TaxCode',
                'TaxDeterminationResult',
                'TaxComplianceRecord',
                'RegulatorySubmissionRecord',
            ],
            'prohibited_responsibilities' => [
                'Override posting authority',
                'Silently change posting state',
            ],
        ],
        'ACC-010' => [
            'capability_id' => 'ACC-010',
            'capability_code' => 'ASSURANCE_CONTROL',
            'capability_name' => 'Accounting Assurance & Control',
            'business_purpose' => 'Findings, continuous control, and assurance assessments.',
            'classification' => 'Control',
            'owned_entities' => [
                'AccountingFinding',
                'FindingDetectionRecord',
                'FindingEvidenceReference',
                'FindingAssignment',
                'FindingResolution',
                'FindingOccurrence',
                'FindingSuppression',
                'RiskAcceptanceRecord',
                'ControlRule',
                'ControlRuleExecution',
                'AssuranceAssessment',
                'ControlException',
            ],
            'prohibited_responsibilities' => [
                'Override GL authority',
                'Approve without domain validation',
            ],
        ],
        'ACC-011' => [
            'capability_id' => 'ACC-011',
            'capability_code' => 'FINANCIAL_CLOSE',
            'capability_name' => 'Financial Close',
            'business_purpose' => 'Close orchestration, close decision, and reopen workflow.',
            'classification' => 'Control',
            'owned_entities' => [
                'CloseCycle',
                'CloseChecklist',
                'CloseDecision',
                'ReopenRequest',
            ],
            'prohibited_responsibilities' => [
                'Own subledger calculations',
                'Silently mutate posting state',
            ],
        ],
        'ACC-012' => [
            'capability_id' => 'ACC-012',
            'capability_code' => 'INGESTION_OCR',
            'capability_name' => 'Accounting Ingestion & OCR',
            'business_purpose' => 'Intake, classification, and promotion of accounting documents.',
            'classification' => 'Automation',
            'owned_entities' => [
                'IngestionBatch',
                'IngestionDocument',
                'ClassificationResult',
                'PromotionRequest',
            ],
            'prohibited_responsibilities' => [
                'Post directly',
                'Bypass owning-domain controls',
            ],
        ],
        'ACC-013' => [
            'capability_id' => 'ACC-013',
            'capability_code' => 'FINANCIAL_REPORTING',
            'capability_name' => 'Financial Reporting & Analytics',
            'business_purpose' => 'Authorised read models, financial reports, and analytics.',
            'classification' => 'Read',
            'owned_entities' => [
                'FinancialReport',
                'ReportSnapshot',
                'ReportExport',
            ],
            'prohibited_responsibilities' => [
                'Mutate accounting records',
                'Override GL authority',
            ],
        ],
        'ACC-014' => [
            'capability_id' => 'ACC-014',
            'capability_code' => 'AUDITOR_WORKSPACE',
            'capability_name' => 'Auditor Workspace',
            'business_purpose' => 'Audit workflow with independent confidentiality controls.',
            'classification' => 'Assurance',
            'owned_entities' => [
                'AuditEngagement',
                'AuditWorkingPaper',
                'AuditFinding',
                'AuditReport',
            ],
            'prohibited_responsibilities' => [
                'Override accounting administrator authority',
                'Mutate accounting records',
            ],
        ],
        'ACC-015' => [
            'capability_id' => 'ACC-015',
            'capability_code' => 'FINANCE_AI',
            'capability_name' => 'Finance AI Applications',
            'business_purpose' => 'Finance-specific AI applications including accounting assistant, close assistant, auditor assistant, tax advisor, compliance advisor, and risk assistant.',
            'classification' => 'Intelligence',
            'owned_entities' => [
                'AI-ACC-01 Accounting Assistant',
                'AI-ACC-02 Close Assistant',
                'AI-AUD-01 Auditor Assistant',
                'AI-TAX-01 Tax Advisor',
                'AI-COM-01 Finance Compliance Advisor',
                'AI-RSK-01 Transaction Risk & Anomaly Assistant',
            ],
            'prohibited_responsibilities' => [
                'Approve autonomously',
                'Post autonomously',
                'Reverse autonomously',
                'Close autonomously',
                'Issue revision autonomously',
                'Override Tax Engine',
                'Accept risk autonomously',
                'Replace canonical reason',
            ],
        ],
    ];

    /**
     * Get all registered capabilities.
     *
     * @return array<string, array{capability_id: string, capability_code: string, capability_name: string, business_purpose: string, classification: string, owned_entities: array<string>, prohibited_responsibilities: array<string>}>
     */
    public static function all(): array
    {
        return self::$capabilities;
    }

    /**
     * Get a capability by ID.
     */
    public static function get(string $capabilityId): ?array
    {
        return self::$capabilities[$capabilityId] ?? null;
    }

    /**
     * Get all capability IDs.
     * @return array<int, string>
     */
    public static function ids(): array
    {
        return array_keys(self::$capabilities);
    }

    /**
     * Check if a capability ID is registered.
     */
    public static function has(string $capabilityId): bool
    {
        return isset(self::$capabilities[$capabilityId]);
    }

    /**
     * Get all owned entities across all capabilities.
     * @return array<string, string> entity_name => capability_id
     */
    public static function entityOwnershipMap(): array
    {
        $map = [];
        foreach (self::$capabilities as $cap) {
            foreach ($cap['owned_entities'] as $entity) {
                $map[$entity] = $cap['capability_id'];
            }
        }
        return $map;
    }

    /**
     * ACC-REG-001 §54 — Prohibited Coupling Matrix.
     * Returns prohibited cross-capability write operations.
     *
     * @return array<int, array{prohibited_action: string, violation: string, capability_id: string}>
     */
    public static function prohibitedCouplings(): array
    {
        return [
            ['prohibited_action' => 'AP writes GL directly', 'violation' => 'GL authority', 'capability_id' => 'ACC-004'],
            ['prohibited_action' => 'Operational module submits unrestricted journal lines', 'violation' => 'Posting-policy violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'OCR posts directly', 'violation' => 'Domain-boundary violation', 'capability_id' => 'ACC-012'],
            ['prohibited_action' => 'Manual journal bypasses control account', 'violation' => 'Subledger-integrity violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'Issued revision overwritten', 'violation' => 'Historical-integrity violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'New revision automatically reverses GL', 'violation' => 'Cross-state coupling', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'Regulatory cancellation automatically changes posting state', 'violation' => 'Cross-state coupling', 'capability_id' => 'ACC-009'],
            ['prohibited_action' => 'One successful approval bypasses Tax/Period/Posting block', 'violation' => 'Authority-conjunction violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'CURRENT mixed into issue-state enum', 'violation' => 'Lifecycle violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'Effective revision uses MAX(sequence) only', 'violation' => 'Revision-resolution violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'Revision generation uses unsafe MAX+1', 'violation' => 'Concurrency violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'Workflow recalculates tax/risk/accounting', 'violation' => 'Domain-authority violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'No Workflow match auto-approves', 'violation' => 'Workflow safety violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'Workflow changes mid-instance silently', 'violation' => 'Workflow-history violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'AI replaces canonical reason', 'violation' => 'AI-governance violation', 'capability_id' => 'ACC-015'],
            ['prohibited_action' => 'Technical failure represented as regulatory rejection', 'violation' => 'Negative-state violation', 'capability_id' => 'ACC-009'],
            ['prohibited_action' => 'Partial correction reported completed', 'violation' => 'Correction-orchestration violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'Correction case BLOCKED used to mean technical operation failure', 'violation' => 'State-semantics violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'Correction case FAILED despite defined remediation path', 'violation' => 'State-rollup violation', 'capability_id' => 'ACC-002'],
            ['prohibited_action' => 'Template Platform decides revision eligibility', 'violation' => 'Ownership violation', 'capability_id' => 'ACC-002'],
        ];
    }

    /**
     * ACC-REG-001 §56 — Architecture Acceptance Gates.
     * Returns all acceptance gates with descriptions.
     *
     * @return array<int, array{gate_id: string, description: string, category: string}>
     */
    public static function acceptanceGates(): array
    {
        return [
            ['gate_id' => 'G1', 'description' => 'One authoritative owner per finance entity.', 'category' => 'Capability ownership'],
            ['gate_id' => 'G2', 'description' => 'No duplicate shared engines.', 'category' => 'Shared Platform ownership'],
            ['gate_id' => 'G3', 'description' => 'All authoritative posting passes ACC-002.', 'category' => 'GL authority'],
            ['gate_id' => 'G4', 'description' => 'Operational modules cannot independently construct unrestricted accounting truth.', 'category' => 'Posting policy'],
            ['gate_id' => 'G5', 'description' => 'Protected accounts cannot be bypassed.', 'category' => 'Control-account integrity'],
            ['gate_id' => 'G6', 'description' => 'Subledger/control balances are continuously reconcilable.', 'category' => 'Subledger reconciliation'],
            ['gate_id' => 'G7', 'description' => 'Every posting traces to source/version.', 'category' => 'Source traceability'],
            ['gate_id' => 'G8', 'description' => 'Historical posted and issued records remain immutable.', 'category' => 'Immutability'],
            ['gate_id' => 'G9', 'description' => 'Same-number revision is policy-controlled.', 'category' => 'Revision policy'],
            ['gate_id' => 'G10', 'description' => 'Business, Numbering and Template authorities are separated.', 'category' => 'Revision ownership'],
            ['gate_id' => 'G11', 'description' => 'Issue and lineage state remain distinct.', 'category' => 'Revision-state separation'],
            ['gate_id' => 'G12', 'description' => 'Current revision resolves deterministically.', 'category' => 'Effective revision'],
            ['gate_id' => 'G13', 'description' => 'Negative states include canonical reason and remediation.', 'category' => 'Negative-state semantics'],
            ['gate_id' => 'G14', 'description' => 'Material transitions remain append-only.', 'category' => 'Transition history'],
            ['gate_id' => 'G15', 'description' => 'Every mandatory gate must permit an action before execution.', 'category' => 'Authority conjunction'],
            ['gate_id' => 'G16', 'description' => 'Cross-capability state changes require owning-domain validation.', 'category' => 'No silent propagation'],
            ['gate_id' => 'G17', 'description' => 'BLOCKED, FAILED and REMEDIATION_REQUIRED are distinct.', 'category' => 'Correction-state semantics'],
            ['gate_id' => 'G18', 'description' => 'Partial or failed correction cannot be reported as completed.', 'category' => 'Correction completion'],
            ['gate_id' => 'G19', 'description' => 'Workflow consumes domain facts but does not recalculate them.', 'category' => 'Workflow authority'],
            ['gate_id' => 'G20', 'description' => 'No-match and ambiguity fail safely.', 'category' => 'Workflow deterministic resolution'],
            ['gate_id' => 'G21', 'description' => 'Active instances remain version-bound.', 'category' => 'Workflow versioning'],
            ['gate_id' => 'G22', 'description' => 'Self-approval and conflicting duties can be prevented.', 'category' => 'SoD'],
            ['gate_id' => 'G23', 'description' => 'AI cannot override deterministic authority or canonical reason.', 'category' => 'AI governance'],
            ['gate_id' => 'G24', 'description' => 'State and required event publication remain reliable.', 'category' => 'Event reliability'],
            ['gate_id' => 'G25', 'description' => 'Finance/revision/workflow/correction operations are race-safe.', 'category' => 'Concurrency'],
        ];
    }

    /**
     * ACC-REG-001 §24 — Canonical Negative-State Metadata.
     * Returns the required metadata fields for negative states.
     *
     * @return array<int, string>
     */
    public static function negativeStateMetadata(): array
    {
        return [
            'state',
            'reason_code',
            'reason_category',
            'safe_user_message',
            'remediation_action',
            'blocking_reference',
            'occurred_at',
            'resolved_at',
        ];
    }

    /**
     * ACC-REG-001 §12 — Document Identity Model.
     * Returns the required fields for revision-controlled documents.
     *
     * @return array<int, string>
     */
    public static function documentIdentityFields(): array
    {
        return [
            'document_uuid',
            'base_document_number',
            'document_revision_uuid',
            'revision_sequence',
            'revision_code',
            'revision_display_value',
            'revision_scheme_id',
            'revision_scheme_version',
            'record_version',
        ];
    }

    /**
     * ACC-REG-001 §48 — Event Envelope.
     * Returns the required fields for finance events.
     *
     * @return array<int, string>
     */
    public static function eventEnvelopeFields(): array
    {
        return [
            'event_id',
            'event_type',
            'event_version',
            'tenant_id',
            'entity_id',
            'source_capability',
            'aggregate_type',
            'aggregate_id',
            'aggregate_version',
            'document_revision_uuid',
            'correction_case_id',
            'occurred_at',
            'published_at',
            'correlation_id',
            'causation_id',
            'actor_id',
            'idempotency_key',
            'payload',
        ];
    }

    /**
     * ACC-REG-001 §16 — Issue State.
     * Returns the canonical document issue states.
     *
     * @return array<int, string>
     */
    public static function documentIssueStates(): array
    {
        return [
            'DRAFT',
            'IN_REVIEW',
            'APPROVED_FOR_ISSUE',
            'ISSUED',
            'CANCEL_PENDING',
            'CANCELLED',
            'VOIDED',
            'REJECTED',
        ];
    }

    /**
     * ACC-REG-001 §16 — Revision Lineage State.
     * Returns the canonical revision lineage states.
     *
     * @return array<int, string>
     */
    public static function revisionLineageStates(): array
    {
        return [
            'CURRENT',
            'SUPERSEDED',
            'WITHDRAWN',
        ];
    }

    /**
     * ACC-REG-001 §6 — Canonical period states for ACC-001 posting authority.
     *
     * ACC-DEC-060 Track A (issue #69): rescoped from 5 states to 3,
     * matching the DB schema (fiscal_periods.status). SOFT_CLOSE,
     * CLOSE_IN_PROGRESS and REOPENED are retired from ACC-001 —
     * SOFT_CLOSE and CLOSE_IN_PROGRESS belong to ACC-011 Financial
     * Close workflow; REOPENED is not a distinct DB state (reopen
     * sets status back to 'open').
     *
     * @return array<int, string>
     */
    public static function periodStates(): array
    {
        return [
            'OPEN',
            'CLOSED',
            'LOCKED',
        ];
    }

    /**
     * ACC-REG-001 §22 — Settlement States.
     * Returns the canonical settlement states.
     *
     * @return array<int, string>
     */
    public static function settlementStates(): array
    {
        return [
            'UNRECOGNISED',
            'UNALLOCATED',
            'PARTIALLY_ALLOCATED',
            'FULLY_ALLOCATED',
            'DISPUTED',
            'REVIEW_REQUIRED',
            'REVERSED',
        ];
    }

    /**
     * ACC-REG-001 §44 — CorrectionCase States.
     * Returns the canonical correction case states.
     *
     * @return array<int, string>
     */
    public static function correctionCaseStates(): array
    {
        return [
            'OPEN',
            'IN_PROGRESS',
            'PARTIALLY_COMPLETED',
            'BLOCKED',
            'REMEDIATION_REQUIRED',
            'FAILED',
            'COMPLETED',
            'CANCELLED',
        ];
    }
}
