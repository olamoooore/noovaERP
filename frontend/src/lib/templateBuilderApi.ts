import api from './api'
import type {
  DocumentTemplate,
  DocumentType,
  LayoutConfig,
  MergeFieldGroup,
  Starter,
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
}
