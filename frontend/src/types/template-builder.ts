// Template Builder — frontend type model.
// Mirrors the backend layout_config block tree consumed by BlockRenderer.

export type BlockType =
  | 'text'
  | 'image'
  | 'columns'
  | 'table'
  | 'totals'
  | 'spacer'
  | 'divider'
  | 'header'
  | 'footer'
  | 'conditional'

export type Align = 'left' | 'center' | 'right'

export interface BlockBase {
  id: string
  type: BlockType
}

export interface TextBlock extends BlockBase {
  type: 'text'
  content: string
  align?: Align
  size?: number
  color?: string
  bold?: boolean
}

export interface ImageBlock extends BlockBase {
  type: 'image'
  url: string
  width?: number
  align?: Align
}

export interface ColumnsBlock extends BlockBase {
  type: 'columns'
  gap?: number
  children: Block[]
}

export interface TableColumn {
  label: string
  field: string
  width?: string
  align?: Align
  format?: string
}

export interface TableBlock extends BlockBase {
  type: 'table'
  source: string
  columns: TableColumn[]
}

export interface TotalsRow {
  label: string
  field: string
  format?: string
  bold?: boolean
}

export interface TotalsBlock extends BlockBase {
  type: 'totals'
  rows: TotalsRow[]
}

export interface SpacerBlock extends BlockBase {
  type: 'spacer'
  height?: number
}

export interface DividerBlock extends BlockBase {
  type: 'divider'
}

export interface ContainerBlock extends BlockBase {
  type: 'header' | 'footer' | 'conditional'
  children: Block[]
  condition?: string
}

export type Block =
  | TextBlock
  | ImageBlock
  | ColumnsBlock
  | TableBlock
  | TotalsBlock
  | SpacerBlock
  | DividerBlock
  | ContainerBlock

export interface LayoutConfig {
  blocks: Block[]
}

export type DocumentType = 'invoice' | 'quotation' | 'purchase_order' | 'receipt'
export type TemplateMode = 'block' | 'code' | 'blade'

// ADR-004 §9 — 5-state editorial lifecycle (supersedes the legacy 3-state).
export type TemplateStatus =
  | 'draft'
  | 'in_review'
  | 'published'
  | 'superseded'
  | 'archived'

// ADR-004 §3 — Template Interaction Type.
export type InteractionType = 'BUSINESS_DOCUMENT' | 'DATA_ENTRY_FORM' | 'SURVEY_ASSESSMENT'

// ADR-004 §7 — Runtime Mode.
export type RuntimeMode = 'COUPLED' | 'DECOUPLED' | 'EXTERNAL_INTEGRATION'

// ADR-004 §11 — Assignment resolution outcome.
export type AssignmentOutcome =
  | 'RESOLVED'
  | 'DEFAULT_APPLIED'
  | 'TEMPLATE_NOT_ASSIGNED'
  | 'TEMPLATE_RESOLUTION_AMBIGUOUS'
  | 'INCOMPATIBLE_FALLBACK'

export type TemplateChannel =
  | 'html'
  | 'pdf'
  | 'print'
  | 'email'
  | 'portal'
  | 'authenticated_form'
  | 'public_link'
  | 'sms'
  | 'letter'

export const INTERACTION_TYPE_LABELS: Record<InteractionType, string> = {
  BUSINESS_DOCUMENT: 'Business Document',
  DATA_ENTRY_FORM: 'Data-Entry Form',
  SURVEY_ASSESSMENT: 'Survey / Assessment',
}

export const STATUS_LABELS: Record<TemplateStatus, string> = {
  draft: 'Draft',
  in_review: 'In Review',
  published: 'Published',
  superseded: 'Superseded',
  archived: 'Archived',
}

export const STATUS_COLORS: Record<TemplateStatus, string> = {
  draft: 'gray',
  in_review: 'amber',
  published: 'green',
  superseded: 'blue',
  archived: 'red',
}

export interface DocumentTemplate {
  id: string
  document_type: DocumentType
  code: string | null
  name: string
  description: string | null
  mode: TemplateMode
  format: string
  orientation: 'portrait' | 'landscape'
  margin: Record<string, number> | null
  default_font: string | null
  default_locale: string | null
  layout_config: LayoutConfig | null
  html: string | null
  css: string | null
  status: TemplateStatus
  interaction_type: InteractionType
  domain_module_code: string | null
  registration_id: string | null
  data_contract_version: string | null
  published_version: number
  is_system_default: boolean
  metadata: Record<string, unknown> | null
  review_status: string | null
  reviewed_by: number | null
  reviewed_at: string | null
  review_decision: string | null
  review_notes: string | null
  superseded_by: string | null
  created_at: string | null
  updated_at: string | null
}

export interface MergeField {
  token: string
  label: string
}

export interface MergeFieldGroup {
  group: string
  fields: MergeField[]
}

export interface TemplateVersion {
  id: string
  version: number
  change_note: string | null
  is_published: boolean
  created_at: string | null
}

export interface Starter {
  document_type: DocumentType
  name: string
  mode: TemplateMode
  format: string
  orientation: 'portrait' | 'landscape'
  description: string
}

export const DOCUMENT_TYPE_LABELS: Record<DocumentType, string> = {
  invoice: 'Invoice',
  quotation: 'Quotation',
  purchase_order: 'Purchase Order',
  receipt: 'Receipt',
}

// ─── ADR-004 §7 — Universal Registry ────────────────────────────────────────
export interface TemplateRegistration {
  id: string
  domain_module_code: string
  template_type_code: string
  interaction_type: InteractionType
  runtime_mode: RuntimeMode
  name: string
  description: string | null
  required_platform_services: string[] | null
  entitlement_codes: string[] | null
  channels: TemplateChannel[] | null
  component_profile: Record<string, unknown> | null
  scope_jurisdiction: Record<string, unknown> | null
  security_retention: Record<string, unknown> | null
  is_system: boolean
  is_active: boolean
  acceptance_reference: string | null
}

// ─── ADR-004 §13 — Form / Survey historical records ────────────────────────
export type FormSubmissionStatus = 'draft' | 'submitted' | 'processed' | 'rejected'

export interface FormSubmission {
  id: string
  template_id: string
  template_version: number
  schema_hash: string
  answers: Record<string, unknown> | null
  consent: Record<string, unknown> | null
  submitter_email: string | null
  status: FormSubmissionStatus
  target_record_type: string | null
  target_record_id: string | null
  workflow_outcome: Record<string, unknown> | null
  submitted_at: string | null
  processed_at: string | null
}

export type SurveyResponseStatus = 'submitted' | 'closed' | 'excluded'

export interface SurveyResponse {
  id: string
  template_id: string
  template_version: number
  scoring_version: string
  answers: Record<string, unknown> | null
  identity_policy: 'anonymous' | 'identified' | 'known_customer'
  calculated_result: {
    score: number
    bands: string[]
    thresholds_crossed: unknown[]
    answer_count: number
  } | null
  status: SurveyResponseStatus
  responded_at: string | null
}

// ─── ADR-004 §10 — Eligibility evaluation result ───────────────────────────
export interface EligibilityCheck {
  step: number
  name: string
  passed: boolean
  detail: string | null
}

export interface EligibilityResult {
  resolved: boolean
  outcome: AssignmentOutcome
  template: DocumentTemplate | null
  explanation: Array<{ step: string; message: string }>
  checks: EligibilityCheck[]
}

// ─── ADR-004 §11 — Assignment resolution result ────────────────────────────
export interface ResolutionResult {
  outcome: AssignmentOutcome
  template_id: string | null
  explanation: Array<{ step: string; message: string }>
}
