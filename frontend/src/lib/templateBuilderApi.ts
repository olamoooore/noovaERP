import api from './api'
import type {
  DocumentTemplate,
  DocumentType,
  EligibilityResult,
  FormSubmission,
  LayoutConfig,
  MergeFieldGroup,
  Starter,
  SurveyResponse,
  TemplateChannel,
  TemplateRegistration,
  TemplateVersion,
} from '@/types/template-builder'

const BASE = '/template-builder'

export interface ListParams {
  document_type?: string
  status?: string
  search?: string
  per_page?: number
}

export interface PreviewPayload {
  layout_config?: LayoutConfig | null
  html?: string
  css?: string
  mode?: string
  sample_data?: Record<string, unknown>
  document_type?: string
  metadata?: Record<string, unknown> | null
  /** Issue #620: render the draft against this real document instead of sample data. */
  document_id?: string
}

/** Issue #620: a recent real document the editor preview can render against. */
export interface PreviewDocumentOption {
  id: string
  number: string
  date: string | null
  label: string
}

export const templateBuilderApi = {
  list: (params?: ListParams) =>
    api
      .get<{ data: DocumentTemplate[]; meta: { current_page: number; last_page: number; total: number } }>(
        `${BASE}/templates`,
        { params },
      )
      .then((r) => r.data),

  get: (id: string) =>
    api.get<{ data: DocumentTemplate }>(`${BASE}/templates/${id}`).then((r) => r.data.data),

  create: (payload: Partial<DocumentTemplate>) =>
    api.post<{ data: DocumentTemplate }>(`${BASE}/templates`, payload).then((r) => r.data.data),

  update: (id: string, payload: Partial<DocumentTemplate>) =>
    api.put<{ data: DocumentTemplate }>(`${BASE}/templates/${id}`, payload).then((r) => r.data.data),

  archive: (id: string) =>
    api.post<{ data: DocumentTemplate }>(`${BASE}/templates/${id}/archive`).then((r) => r.data),

  delete: (id: string) =>
    api.delete<{ message: string }>(`${BASE}/templates/${id}`).then((r) => r.data),

  duplicate: (id: string, name?: string) =>
    api.post<{ data: DocumentTemplate }>(`${BASE}/templates/${id}/duplicate`, { name }).then((r) => r.data.data),

  publish: (id: string, changeNote?: string) =>
    api
      .post<{ data: DocumentTemplate }>(`${BASE}/templates/${id}/publish`, { change_note: changeNote })
      .then((r) => r.data.data),

  setDefault: (id: string, scope?: { scope_type?: string; scope_value?: string }) =>
    api.post<{ message: string }>(`${BASE}/templates/${id}/default`, scope ?? {}).then((r) => r.data),

  versions: (id: string) =>
    api.get<{ data: TemplateVersion[] }>(`${BASE}/templates/${id}/versions`).then((r) => r.data.data),

  preview: (id: string, payload: PreviewPayload) =>
    api.post<{ html: string; warnings: string[] }>(`${BASE}/templates/${id}/preview`, payload).then((r) => r.data),

  /** Issue #620: recent documents of the template's type the user may preview against. */
  previewDocuments: (id: string) =>
    api
      .get<{ data: PreviewDocumentOption[]; document_type: string }>(`${BASE}/templates/${id}/preview-documents`)
      .then((r) => r.data.data),

  validate: (def: { mode: string; layout_config?: unknown; html?: string }) =>
    api.post<{ valid: boolean; errors: string[] }>(`${BASE}/templates/validate`, def).then((r) => r.data),

  starters: () =>
    api.get<{ data: Starter[] }>(`${BASE}/starters`).then((r) => r.data.data),

  createFromStarter: (docType: DocumentType | string) =>
    api.post<{ data: DocumentTemplate }>(`${BASE}/templates/from-starter/${docType}`).then((r) => r.data.data),

  activeDefaults: () =>
    api.get<{ data: Record<string, string | null> }>(`${BASE}/active-defaults`).then((r) => r.data.data),

  /** Public URL that streams a sample PDF for the template (opens in a new tab). */
  samplePdfUrl: (id: string) => `${BASE}/templates/${id}/sample-pdf`,

  mergeFields: (docType: DocumentType | string) =>
    api
      .get<{ document_type: string; data: MergeFieldGroup[] }>(`${BASE}/merge-fields/${docType}`)
      .then((r) => r.data.data),

  sampleData: (docType: DocumentType | string) =>
    api
      .get<{ document_type: string; data: Record<string, unknown> }>(`${BASE}/sample-data/${docType}`)
      .then((r) => r.data.data),

  /** Builds the public PDF/HTML URL for a real document. */
  documentPdfUrl: (docType: DocumentType | 'invoice', documentId: string | number, opts?: { template?: string; as?: string }) => {
    const map: Record<string, string> = {
      invoice: 'invoices',
      quotation: 'invoices',
      purchase_order: 'purchase-orders',
      receipt: 'payments',
    }
    const base = map[docType] ?? 'invoices'
    const params = new URLSearchParams()
    if (opts?.template) params.set('template', opts.template)
    if (opts?.as) params.set('as', opts.as)
    const qs = params.toString()
    return `${base}/${documentId}/pdf${qs ? `?${qs}` : ''}`
  },

  // ─── ADR-004 §9 — Editorial lifecycle ────────────────────────────────────
  lifecycle: {
    submitForReview: (id: string) =>
      api.post<{ data: DocumentTemplate }>(`${BASE}/templates/${id}/submit-for-review`).then((r) => r.data.data),
    approve: (id: string, reviewerId: number, changeNote?: string, notes?: string) =>
      api
        .post<{ data: DocumentTemplate }>(`${BASE}/templates/${id}/approve`, {
          reviewer_id: reviewerId,
          change_note: changeNote,
          notes,
        })
        .then((r) => r.data.data),
    reject: (id: string, reviewerId: number, notes?: string) =>
      api.post<{ data: DocumentTemplate }>(`${BASE}/templates/${id}/reject`, { reviewer_id: reviewerId, notes }).then((r) => r.data.data),
    archive: (id: string) => api.post<{ data: DocumentTemplate }>(`${BASE}/templates/${id}/archive`).then((r) => r.data.data),
    restore: (id: string) => api.post<{ data: DocumentTemplate }>(`${BASE}/templates/${id}/restore`).then((r) => r.data.data),
  },

  // ─── ADR-004 §7 — Universal Registry ─────────────────────────────────────
  registry: {
    list: (params?: { domain?: string; interaction_type?: string }) =>
      api.get<{ data: TemplateRegistration[] }>(`${BASE}/registry`, { params }).then((r) => r.data.data),
    get: (typeCode: string) =>
      api.get<{ data: TemplateRegistration }>(`${BASE}/registry/${typeCode}`).then((r) => r.data.data),
    register: (payload: Partial<TemplateRegistration>) =>
      api.post<{ data: TemplateRegistration }>(`${BASE}/registry`, payload).then((r) => r.data.data),
    seed: () => api.post<{ message: string; seeded: number }>(`${BASE}/registry/seed`).then((r) => r.data),
    activate: (typeCode: string) =>
      api.post<{ data: TemplateRegistration }>(`${BASE}/registry/${typeCode}/activate`).then((r) => r.data.data),
    deactivate: (typeCode: string) =>
      api.post<{ data: TemplateRegistration }>(`${BASE}/registry/${typeCode}/deactivate`).then((r) => r.data.data),
  },

  // ─── ADR-004 §10 — Eligibility evaluation ────────────────────────────────
  evaluateEligibility: (payload: {
    template_type_code: string
    interaction_type?: string
    entity_id?: string
    branch_id?: string
    language?: string
    currency?: string
    country?: string
    jurisdiction?: string
    customer_group?: string
    channel?: TemplateChannel | string
  }) => api.post<{ data: EligibilityResult }>(`${BASE}/eligibility`, payload).then((r) => r.data.data),

  // ─── ADR-004 §8.2 / §12 — Forms runtime ──────────────────────────────────
  forms: {
    submit: (templateId: string, answers: Record<string, unknown>, extra?: Record<string, unknown>) =>
      api.post<{ data: FormSubmission }>(`${BASE}/forms/${templateId}/submit`, { answers, ...extra }).then((r) => r.data.data),
    submissions: (templateId: string, page = 1) =>
      api
        .get<{ data: FormSubmission[] }>(`${BASE}/forms/${templateId}/submissions`, { params: { page } })
        .then((r) => r.data.data),
    submission: (templateId: string, submissionId: string) =>
      api.get<{ data: FormSubmission }>(`${BASE}/forms/${templateId}/submissions/${submissionId}`).then((r) => r.data.data),
  },

  // ─── ADR-004 §12 / §13 — Survey runtime ──────────────────────────────────
  surveys: {
    respond: (templateId: string, answers: Record<string, unknown>, extra?: Record<string, unknown>) =>
      api.post<{ data: SurveyResponse }>(`${BASE}/surveys/${templateId}/respond`, { answers, ...extra }).then((r) => r.data.data),
    responses: (templateId: string, page = 1) =>
      api
        .get<{ data: SurveyResponse[] }>(`${BASE}/surveys/${templateId}/responses`, { params: { page } })
        .then((r) => r.data.data),
    response: (templateId: string, responseId: string) =>
      api.get<{ data: SurveyResponse }>(`${BASE}/surveys/${templateId}/responses/${responseId}`).then((r) => r.data.data),
  },

  // ─── ADR-000 #169 / #176 — Public signed tokens ──────────────────────────
  publicTokens: {
    issue: (payload: {
      template_id?: string
      interaction_type: 'DATA_ENTRY_FORM' | 'SURVEY_ASSESSMENT'
      capability?: 'respond' | 'view'
      expires_in_minutes?: number
      issued_to_email?: string
      rate_limit_per_hour?: number
      max_uses?: number
    }) => api.post<{ data: { token: string; token_id: string; expires_at: string; capability: string } }>(`${BASE}/public-tokens`, payload).then((r) => r.data.data),
    revoke: (tokenId: string, reason?: string) =>
      api.post<{ data: unknown }>(`${BASE}/public-tokens/revoke`, { token_id: tokenId, reason }).then((r) => r.data.data),
  },
}
