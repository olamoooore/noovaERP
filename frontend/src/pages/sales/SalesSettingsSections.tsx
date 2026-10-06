import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { cn } from '@/lib/utils'
import { validateNumberFormat, validateResetPeriod, type ResetPeriod } from './numberingFormat'
import {
  SettingsLoadingCard,
  SettingsRow,
  SettingsSaveBar,
  SettingsSectionHeader,
} from '@/components/settings/settings-ui'

export interface ReviewerConfig {
  reviewer_user_ids?: number[]
  reviewer_roles?: string[]
}

export interface SalesSettings {
  // Issue #333: tenant cash rounding (Odoo-style). Default OFF.
  cash_rounding: {
    enabled: boolean
    method: 'nearest' | 'up' | 'down'
    precision: number
    account_id: number | null
    methods?: string[]
  }
  invoice: {
    allow_custom_number: boolean
    field_profile: 'general' | 'medical' | 'trading' | 'services'
    default_requires_approval: boolean
    approval_expiry_days: number
    auto_send_to_customer_on_post: boolean
    allow_partial_payments: boolean
    default_payment_terms_days: number
    default_notes: string
    default_terms: string
    reviewer_user_ids?: number[]
    reviewer_roles?: string[]
    available_reviewers?: Array<{ id: number; name: string; email: string }>
    // Issue #544: server-reported posting-approval state (never assumed).
    approval_enforcement?: {
      mode: 'all_invoices' | 'per_document'
      policy_enabled: boolean
      drafts_without_approval: number
    }
  }
  portal: {
    acceptance_enabled: boolean
    require_signature: boolean
    acceptance_link_expiry_days: number
    payment_link_expiry_days: number
  }
  tax: {
    default_tax_rate: number
    prices_include_tax: boolean
    default_tax_treatment: string
  }
  delivery: {
    attach_pdf: boolean
    cc_internal_email: string
    document_type_on_send: string
  }
  delivery_order: {
    default_requires_approval: boolean
  } & ReviewerConfig
  // Issue #645: per-request-type reviewer lists. Each falls back to the
  // invoice reviewers when left empty.
  quotation: ReviewerConfig
  sales_order: ReviewerConfig
  invoice_threshold: ReviewerConfig
  // Issue #553: creation defaults for new sales documents.
  defaults: {
    salesperson_mode: 'none' | 'current_user'
    team_by_user: Record<string, string>
  }
  // Issue #553: line presentation on rendered sales documents.
  pricelist: {
    display: 'show_discount' | 'net_price'
  }
}

export const emptySalesSettings: SalesSettings = {
  cash_rounding: {
    enabled: false,
    method: 'nearest',
    precision: 0.05,
    account_id: null,
    methods: ['nearest', 'up', 'down'],
  },
  invoice: {
    allow_custom_number: true,
    field_profile: 'medical',
    default_requires_approval: false,
    approval_expiry_days: 7,
    auto_send_to_customer_on_post: true,
    allow_partial_payments: true,
    default_payment_terms_days: 30,
    default_notes: '',
    default_terms: 'Payment due within 30 days.',
  },
  portal: {
    acceptance_enabled: true,
    require_signature: true,
    acceptance_link_expiry_days: 30,
    payment_link_expiry_days: 14,
  },
  tax: {
    default_tax_rate: 0,
    prices_include_tax: false,
    default_tax_treatment: 'standard',
  },
  delivery: {
    attach_pdf: true,
    cc_internal_email: '',
    document_type_on_send: 'final_invoice',
  },
  delivery_order: {
    default_requires_approval: false,
  },
  quotation: {
    reviewer_user_ids: [],
    reviewer_roles: [],
  },
  sales_order: {
    reviewer_user_ids: [],
    reviewer_roles: [],
  },
  invoice_threshold: {
    reviewer_user_ids: [],
    reviewer_roles: [],
  },
  defaults: {
    salesperson_mode: 'none',
    team_by_user: {},
  },
  pricelist: {
    display: 'show_discount',
  },
}

function useSalesSettings() {
  return useQuery({
    queryKey: ['sales-settings'],
    queryFn: async () => {
      const res = await api.get('/settings/sales')
      return (res.data?.data as SalesSettings) ?? emptySalesSettings
    },
  })
}

function useSalesSettingsMutation<T extends Record<string, unknown>>(
  group: keyof SalesSettings,
  successMessage: string,
  onSaved: () => void,
) {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  return useMutation({
    mutationFn: async (patch: T) => {
      const res = await api.put('/settings/sales', { [group]: patch })
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-settings'] })
      toast({ title: successMessage })
      onSaved()
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Save failed',
        description: error.response?.data?.message || 'Please try again.',
      })
    },
  })
}

// ─── Document Numbering ─────────────────────────────────────────────────────

// #548 (SET-UX4): per document — format with token chips, the NEXT number
// previewed by the server (the same generator that issues real numbers),
// an explicit reset period and the next sequence value (forward only).

export interface DocumentFormatInfo {
  current: string
  default: string
  example?: string
  preview?: string
  reset_period?: ResetPeriod
  reset_period_explicit?: boolean
  next_sequence?: number
}

type NumberingDocType = 'invoice' | 'quotation' | 'sales_order' | 'delivery_order' | 'credit_note' | 'payment'

const NUMBERING_DOCS: Array<{ type: NumberingDocType; label: string; description: string; fallback: string }> = [
  { type: 'invoice', label: 'Invoice number', description: 'Applied to new sales invoices', fallback: 'INV-{YYYY}-{SEQ:4}' },
  { type: 'quotation', label: 'Quotation number', description: 'Applied to new sales quotations', fallback: 'QUO-{YYYY}-{SEQ:4}' },
  { type: 'delivery_order', label: 'Delivery order number', description: 'Applied to new delivery orders', fallback: 'DO-{YYYY}-{SEQ:4}' },
  { type: 'sales_order', label: 'Sales order number', description: 'Applied to new sales orders', fallback: 'SO-{YYYY}-{SEQ:4}' },
  { type: 'credit_note', label: 'Credit note number', description: 'Applied to new customer credit notes', fallback: 'CN-{YYYY}-{SEQ:4}' },
  { type: 'payment', label: 'Payment number', description: 'Applied to new customer payments', fallback: 'PAY/{YYYY}/{MM}/{SEQ:3}' },
]

const FORMAT_TOKENS = [
  { token: '{YYYY}', hint: 'Year' },
  { token: '{YY}', hint: 'Short year' },
  { token: '{MM}', hint: 'Month' },
  { token: '{SEQ:4}', hint: 'Sequence' },
]

const RESET_PERIODS: Array<{ value: ResetPeriod; label: string }> = [
  { value: 'never', label: 'Never' },
  { value: 'yearly', label: 'Yearly' },
  { value: 'monthly', label: 'Monthly' },
]

function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(id)
  }, [value, delay])
  return debounced
}

function NumberingDocRow({
  type,
  label,
  description,
  info,
  format,
  resetPeriod,
  nextSequence,
  onFormatChange,
  onResetChange,
  onNextSequenceChange,
}: {
  type: NumberingDocType
  label: string
  description: string
  info?: DocumentFormatInfo
  format: string
  resetPeriod: ResetPeriod
  nextSequence: string
  onFormatChange: (v: string) => void
  onResetChange: (v: ResetPeriod) => void
  onNextSequenceChange: (v: string) => void
}) {
  const inputRef = useRef<HTMLInputElement>(null)
  const formatErrors = validateNumberFormat(format)
  const resetError = formatErrors.length === 0 ? validateResetPeriod(format, resetPeriod) : null
  const minSequence = info?.next_sequence ?? 1
  const parsedNext = nextSequence === '' ? null : Number(nextSequence)
  const sequenceError =
    parsedNext !== null && (!Number.isInteger(parsedNext) || parsedNext < minSequence)
      ? `Can only move forward: at least ${minSequence}.`
      : null

  const savedFormat = info?.current ?? format
  const savedReset = info?.reset_period ?? resetPeriod
  const edited = format !== savedFormat || resetPeriod !== savedReset
  const debouncedFormat = useDebouncedValue(format)
  const debouncedReset = useDebouncedValue(resetPeriod)
  const canPreview = edited && formatErrors.length === 0 && resetError === null

  // The live preview comes from the server's numbering service (read-only).
  const { data: livePreview, isFetching } = useQuery({
    queryKey: ['document-format-preview', type, debouncedFormat, debouncedReset],
    enabled: canPreview && debouncedFormat === format && debouncedReset === resetPeriod,
    queryFn: async () => {
      const res = await api.get('/settings/document-formats/preview', {
        params: { type, format: debouncedFormat, reset_period: debouncedReset },
      })
      return res.data as { preview?: string | null; errors?: string[] }
    },
    retry: false,
  })

  const preview = !edited ? info?.preview : canPreview ? livePreview?.preview : undefined
  const errors = [...formatErrors, ...(resetError ? [resetError] : [])]

  const insertToken = (token: string) => {
    const el = inputRef.current
    const start = el?.selectionStart ?? format.length
    const end = el?.selectionEnd ?? format.length
    onFormatChange(format.slice(0, start) + token + format.slice(end))
  }

  return (
    <div
      className="space-y-3 rounded-lg border bg-card p-4 transition-colors hover:border-primary/30"
      data-testid={`numbering-row-${type}`}
    >
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-0.5">
          <Label className="text-sm font-medium" htmlFor={`numbering-format-${type}`}>
            {label}
          </Label>
          <p className="text-xs text-muted-foreground">{description}</p>
        </div>
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <span>Next number</span>
          <Badge variant="outline" className="font-mono text-xs tabular-nums" data-testid={`numbering-preview-${type}`}>
            {preview ?? (isFetching ? '…' : '—')}
          </Badge>
        </div>
      </div>
      <Input
        id={`numbering-format-${type}`}
        ref={inputRef}
        value={format}
        onChange={(e) => onFormatChange(e.target.value)}
        placeholder={info?.default}
        className={cn('font-mono text-sm', errors.length > 0 && 'border-destructive')}
        aria-invalid={errors.length > 0}
      />
      <div className="flex flex-wrap items-center gap-1.5">
        {FORMAT_TOKENS.map(({ token, hint }) => (
          <button
            key={token}
            type="button"
            title={hint}
            onClick={() => insertToken(token)}
            className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs ring-1 ring-border hover:bg-primary/10"
          >
            {token}
          </button>
        ))}
      </div>
      {errors.length > 0 && (
        <ul className="space-y-0.5 text-xs text-destructive" role="alert">
          {errors.map((e) => (
            <li key={e}>{e}</li>
          ))}
        </ul>
      )}
      <div className="flex flex-wrap items-end gap-4">
        <div className="space-y-1">
          <Label className="text-xs text-muted-foreground">Reset sequence</Label>
          <Select value={resetPeriod} onValueChange={(v) => onResetChange(v as ResetPeriod)}>
            <SelectTrigger className="w-36" aria-label={`${label} reset period`}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {RESET_PERIODS.map((p) => (
                <SelectItem key={p.value} value={p.value}>
                  {p.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1">
          <Label className="text-xs text-muted-foreground" htmlFor={`numbering-next-${type}`}>
            Next sequence value
          </Label>
          <Input
            id={`numbering-next-${type}`}
            type="number"
            min={minSequence}
            step={1}
            value={nextSequence}
            placeholder={String(minSequence)}
            onChange={(e) => onNextSequenceChange(e.target.value)}
            className={cn('w-36 tabular-nums', sequenceError && 'border-destructive')}
            aria-invalid={sequenceError !== null}
          />
        </div>
      </div>
      {sequenceError && (
        <p className="text-xs text-destructive" role="alert">
          {sequenceError}
        </p>
      )}
    </div>
  )
}

const DATE_FORMATS = [
  { value: 'Y-m-d', label: 'YYYY-MM-DD', example: '2026-09-22' },
  { value: 'd/m/Y', label: 'DD/MM/YYYY', example: '22/09/2026' },
  { value: 'm/d/Y', label: 'MM/DD/YYYY', example: '09/22/2026' },
  { value: 'dd/mm/yy', label: 'DD/MM/YY', example: '22/09/26' },
  { value: 'mm/dd/yy', label: 'MM/DD/YY', example: '09/22/26' },
]

export function NumberingSettings() {
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const { data: formats, isLoading } = useQuery({
    queryKey: ['document-formats'],
    queryFn: async () => {
      const res = await api.get('/settings/document-formats')
      return res.data as Partial<Record<NumberingDocType, DocumentFormatInfo>> & { date_format?: string }
    },
  })

  const [local, setLocal] = useState<Record<string, string>>({})
  const [localResets, setLocalResets] = useState<Partial<Record<NumberingDocType, ResetPeriod>>>({})
  const [localNext, setLocalNext] = useState<Partial<Record<NumberingDocType, string>>>({})
  const [initialized, setInitialized] = useState(false)
  const [savedDefaults, setSavedDefaults] = useState<Record<string, string>>({})

  if (formats && !initialized) {
    // #542 (ST5): all six sales-document formats are editable here.
    const snapshot: Record<string, string> = { date_format: formats.date_format ?? 'Y-m-d' }
    for (const doc of NUMBERING_DOCS) snapshot[doc.type] = formats[doc.type]?.current ?? doc.fallback
    setSavedDefaults(snapshot)
    setInitialized(true)
  }

  const current = { ...savedDefaults, ...local }
  const resetFor = (type: NumberingDocType): ResetPeriod =>
    localResets[type] ?? formats?.[type]?.reset_period ?? 'yearly'
  const hasChanges =
    Object.keys(local).length > 0 || Object.keys(localResets).length > 0 || Object.keys(localNext).length > 0

  const hasErrors = NUMBERING_DOCS.some(({ type }) => {
    const format = current[type] ?? ''
    if (validateNumberFormat(format).length > 0) return true
    if (validateResetPeriod(format, resetFor(type))) return true
    const next = localNext[type]
    if (next !== undefined && next !== '') {
      const n = Number(next)
      if (!Number.isInteger(n) || n < (formats?.[type]?.next_sequence ?? 1)) return true
    }
    return false
  })

  const clearLocal = () => {
    setLocal({})
    setLocalResets({})
    setLocalNext({})
  }

  const updateMutation = useMutation({
    mutationFn: async () => {
      const nextSequences: Record<string, number> = {}
      for (const [type, value] of Object.entries(localNext)) {
        if (value !== undefined && value !== '') nextSequences[type] = Number(value)
      }
      const res = await api.put('/settings/document-formats', {
        ...current,
        ...(Object.keys(localResets).length > 0 ? { reset_periods: localResets } : {}),
        ...(Object.keys(nextSequences).length > 0 ? { next_sequences: nextSequences } : {}),
      })
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['document-formats'] })
      setSavedDefaults(current)
      clearLocal()
      toast({ title: 'Numbering settings saved' })
    },
    onError: (error: any) => {
      // A rejected format answers 422 with per-field errors (#542).
      const fieldErrors = error.response?.data?.errors
      const firstFieldError = fieldErrors ? Object.values(fieldErrors)[0] : null
      const description = Array.isArray(firstFieldError)
        ? firstFieldError[0]
        : error.response?.data?.message || 'Please try again.'

      toast({
        variant: 'destructive',
        title: 'Save failed',
        description,
      })
    },
  })

  if (isLoading || !formats || !initialized) return <SettingsLoadingCard />

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:hashtag-circle-bold-duotone"
        title="Document Numbering"
        description="Control how invoice, quotation, sales order, delivery order, credit note and payment numbers are generated. Use tokens to build the format."
      />
      <CardContent className="space-y-6 pt-6">
        <div className="rounded-lg border border-dashed bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
          Click a token to insert it: <code className="font-mono">{'{YYYY}'}</code> year,{' '}
          <code className="font-mono">{'{YY}'}</code> short year, <code className="font-mono">{'{MM}'}</code> month,{' '}
          <code className="font-mono">{'{SEQ:n}'}</code> sequence padded to n digits (3–8). The next number shown is the one
          the next document will receive.
        </div>

        <div className="grid gap-4">
          {NUMBERING_DOCS.map((doc) => (
            <NumberingDocRow
              key={doc.type}
              type={doc.type}
              label={doc.label}
              description={doc.description}
              info={formats[doc.type]}
              format={current[doc.type] ?? ''}
              resetPeriod={resetFor(doc.type)}
              nextSequence={localNext[doc.type] ?? ''}
              onFormatChange={(v) => setLocal({ ...local, [doc.type]: v })}
              onResetChange={(v) => setLocalResets({ ...localResets, [doc.type]: v })}
              onNextSequenceChange={(v) => setLocalNext({ ...localNext, [doc.type]: v })}
            />
          ))}
        </div>

        <Separator />

        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="space-y-0.5">
            <Label className="text-sm font-medium">Date display format</Label>
            <p className="text-xs text-muted-foreground">Used across printed documents and lists.</p>
          </div>
          <Select
            value={current.date_format ?? 'Y-m-d'}
            onValueChange={(v) => setLocal({ ...local, date_format: v })}
          >
            <SelectTrigger className="w-64">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {DATE_FORMATS.map((df) => (
                <SelectItem key={df.value} value={df.value}>
                  <div className="flex items-center gap-3">
                    <span className="font-medium">{df.label}</span>
                    <span className="text-xs text-muted-foreground">{df.example}</span>
                  </div>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </CardContent>
      <SettingsSaveBar
        hasChanges={hasChanges}
        isPending={updateMutation.isPending}
        onSave={() => {
          if (hasErrors) {
            toast({ variant: 'destructive', title: 'Fix the highlighted numbering fields first' })
            return
          }
          updateMutation.mutate()
        }}
        onCancel={clearLocal}
        label="Save numbering"
      />
    </Card>
  )
}

// ─── Approval Workflow ──────────────────────────────────────────────────────

const REVIEWER_ROLES = [
  { value: 'owner', label: 'Owner', icon: 'solar:crown-bold-duotone' },
  { value: 'admin', label: 'Admin', icon: 'solar:shield-user-bold-duotone' },
  { value: 'manager', label: 'Manager', icon: 'solar:user-check-bold-duotone' },
  { value: 'accountant', label: 'Accountant', icon: 'solar:calculator-minimalistic-bold-duotone' },
  { value: 'sales', label: 'Sales', icon: 'solar:medal-star-bold-duotone' },
]

type ReviewerDocType = 'invoice' | 'quotation' | 'sales_order' | 'invoice_threshold' | 'delivery_order'

const REVIEWER_DOC_TYPES: Array<{ value: ReviewerDocType; label: string; description: string; icon: string }> = [
  { value: 'invoice', label: 'Invoices', description: 'Invoices flagged “requires approval” before posting.', icon: 'solar:document-text-bold-duotone' },
  { value: 'quotation', label: 'Quotations', description: 'Quotations over the approval threshold.', icon: 'solar:document-add-bold-duotone' },
  { value: 'sales_order', label: 'Sales orders', description: 'Sales orders over the approval threshold.', icon: 'solar:cart-large-2-bold-duotone' },
  { value: 'invoice_threshold', label: 'Invoice threshold', description: 'Invoices auto-flagged by the approval threshold.', icon: 'solar:danger-triangle-bold-duotone' },
  { value: 'delivery_order', label: 'Delivery orders', description: 'Delivery orders that require approval.', icon: 'solar:delivery-bold-duotone' },
]

// Issue #544: the badge shows only what the server reports. Posting is
// blocked per invoice (requires_approval), so "all invoices" is claimed only
// when the tenant policy is on and no draft has approval switched off.
function ApprovalEnforcementBadge({ state }: { state?: SalesSettings['invoice']['approval_enforcement'] }) {
  if (!state) return null

  if (state.mode === 'all_invoices') {
    return (
      <Badge variant="outline" className="gap-1 border-green-300 text-green-700 dark:border-green-800 dark:text-green-400">
        <Icon icon="solar:shield-check-bold-duotone" className="h-3.5 w-3.5" />
        Enforced for all invoices
      </Badge>
    )
  }

  const count = state.drafts_without_approval
  const label =
    count > 0
      ? `Per document — ${count} ${count === 1 ? 'draft has' : 'drafts have'} approval switched off`
      : 'Per document — only invoices flagged “requires approval”'

  return (
    <Badge variant="outline" className="gap-1 border-amber-300 text-amber-700 dark:border-amber-800 dark:text-amber-400">
      <Icon icon="solar:shield-warning-bold-duotone" className="h-3.5 w-3.5" />
      {label}
    </Badge>
  )
}

type ApprovalPatch = ReviewerConfig & {
  default_requires_approval?: boolean
  approval_expiry_days?: number
}

export function ApprovalSettings() {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const { data, isLoading } = useSalesSettings()
  // Approval reviewers are edited for one OR MORE document types at once.
  const [reviewerTypes, setReviewerTypes] = useState<ReviewerDocType[]>(['invoice'])
  // Dirty approval fields per request type — saved together by the save bar.
  const [dirty, setDirty] = useState<Partial<Record<ReviewerDocType, ApprovalPatch>>>({})

  const mutation = useMutation({
    mutationFn: async () => {
      for (const [group, patch] of Object.entries(dirty)) {
        if (patch && Object.keys(patch).length > 0) {
          await api.put('/settings/sales', { [group]: patch })
        }
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-settings'] })
      toast({ title: 'Approval settings saved' })
      setDirty({})
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Save failed',
        description: error.response?.data?.message || 'Please try again.',
      })
    },
  })

  if (isLoading || !data) return <SettingsLoadingCard />

  const patchGroup = (group: ReviewerDocType, patch: ApprovalPatch) => {
    setDirty((current) => ({
      ...current,
      [group]: { ...(current[group] ?? {}), ...patch },
    }))
  }

  const groups: Record<ReviewerDocType, ApprovalPatch> = {
    invoice: { ...data.invoice, ...(dirty.invoice ?? {}) },
    quotation: { ...(data.quotation ?? {}), ...(dirty.quotation ?? {}) },
    sales_order: { ...(data.sales_order ?? {}), ...(dirty.sales_order ?? {}) },
    invoice_threshold: { ...(data.invoice_threshold ?? {}), ...(dirty.invoice_threshold ?? {}) },
    delivery_order: {
      ...(data.delivery_order ?? { default_requires_approval: false }),
      ...(dirty.delivery_order ?? {}),
    },
  }

  const invoice = groups.invoice
  const deliveryOrder = groups.delivery_order
  const availableReviewers = data.invoice.available_reviewers ?? []
  const selectedTypes = REVIEWER_DOC_TYPES.filter((t) => reviewerTypes.includes(t.value))
  const reviewerTypeMeta = selectedTypes[0] ?? REVIEWER_DOC_TYPES[0]
  const multiType = selectedTypes.length > 1

  const toggleReviewerType = (type: ReviewerDocType) => {
    setReviewerTypes((current) => {
      if (current.includes(type)) {
        // Always keep at least one type selected — reviewers need a target.
        return current.length === 1 ? current : current.filter((t) => t !== type)
      }

      // Keep the canonical display order.
      return REVIEWER_DOC_TYPES.map((t) => t.value).filter((v) => current.includes(v) || v === type)
    })
  }

  /** Does every selected type carry this reviewer value? */
  const hasEverywhere = (field: 'reviewer_roles' | 'reviewer_user_ids', value: string | number) =>
    reviewerTypes.every((t) => (groups[t][field] ?? []).includes(value as never))

  /** Does at least one selected type carry it (but not all)? */
  const hasSomewhere = (field: 'reviewer_roles' | 'reviewer_user_ids', value: string | number) =>
    !hasEverywhere(field, value) && reviewerTypes.some((t) => (groups[t][field] ?? []).includes(value as never))

  /** Toggle a reviewer for EVERY selected document type at once. */
  const applyToSelected = (
    field: 'reviewer_roles' | 'reviewer_user_ids',
    value: string | number,
    remove: boolean,
  ) => {
    setDirty((current) => {
      const next = { ...current }
      for (const type of reviewerTypes) {
        const existing = (groups[type][field] ?? []) as Array<string | number>
        const updated = remove
          ? existing.filter((v) => v !== value)
          : existing.includes(value)
            ? existing
            : [...existing, value]
        next[type] = { ...(next[type] ?? {}), [field]: updated }
      }
      return next
    })
  }

  const toggleReviewerRole = (role: string) => applyToSelected('reviewer_roles', role, hasEverywhere('reviewer_roles', role))

  const toggleReviewerPerson = (personId: number) =>
    applyToSelected('reviewer_user_ids', personId, hasEverywhere('reviewer_user_ids', personId))

  const reviewerCount = selectedTypes.reduce(
    (count, type) =>
      count +
      (groups[type.value].reviewer_user_ids ?? []).length +
      (groups[type.value].reviewer_roles ?? []).length,
    0,
  )

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:checklist-bold-duotone"
        title="Approval Workflow"
        description="Decide which sales documents need review before posting and how long approvals remain valid."
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Require approval by default"
          description="New invoices start with the approval requirement already enabled."
        >
          <Switch
            checked={invoice.default_requires_approval}
            onCheckedChange={(v) => patchGroup('invoice', { default_requires_approval: v })}
          />
        </SettingsRow>

        <SettingsRow
          title="Approval expiry"
          description="Pending requests automatically expire after this many days without a decision."
        >
          <div className="flex items-center gap-2">
            <Input
              type="number"
              min={1}
              max={90}
              value={invoice.approval_expiry_days}
              onChange={(e) =>
                patchGroup('invoice', { approval_expiry_days: parseInt(e.target.value, 10) || 7 })
              }
              className="w-20 text-center"
            />
            <span className="text-sm text-muted-foreground">days</span>
          </div>
        </SettingsRow>

        <SettingsRow
          title="Block posting without approval"
          description="Invoices that require approval cannot be posted to the general ledger until approved. Reflects the saved policy and current drafts."
        >
          <ApprovalEnforcementBadge state={data.invoice.approval_enforcement} />
        </SettingsRow>

        <SettingsRow
          title="Delivery orders require approval by default"
          description="New delivery orders start with the approval requirement already enabled."
        >
          <Switch
            checked={deliveryOrder.default_requires_approval}
            onCheckedChange={(v) => patchGroup('delivery_order', { default_requires_approval: v })}
          />
        </SettingsRow>

        {/* Reviewers */}
        <div className="space-y-4 py-4">
          <div className="space-y-1">
            <div className="text-sm font-medium leading-none">Approval reviewers</div>
            <p className="text-sm leading-relaxed text-muted-foreground">
              Who gets notified and can approve each document type. Pick specific people, roles, or both. A type
              with no reviewers of its own falls back to the invoice list.
            </p>
          </div>

          <div className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="space-y-0.5">
                <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Document types</div>
                <p className="text-xs text-muted-foreground">
                  {multiType
                    ? `Reviewer choices below apply to all ${selectedTypes.length} selected document types at once.`
                    : reviewerTypeMeta.description}
                </p>
              </div>
              <span className="text-xs text-muted-foreground">
                {selectedTypes.length} of {REVIEWER_DOC_TYPES.length} selected
              </span>
            </div>
            <div className="flex flex-wrap gap-2">
              {REVIEWER_DOC_TYPES.map((type) => {
                const active = reviewerTypes.includes(type.value)
                return (
                  <button
                    key={type.value}
                    type="button"
                    aria-pressed={active}
                    onClick={() => toggleReviewerType(type.value)}
                    className={cn(
                      'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-all',
                      active
                        ? 'border-primary/40 bg-primary/10 text-primary'
                        : 'border-border text-muted-foreground hover:border-primary/30 hover:bg-muted/60 hover:text-foreground',
                    )}
                  >
                    <Icon icon={type.icon} className="h-3.5 w-3.5" />
                    {type.label}
                    {active && <Icon icon="solar:check-circle-bold" className="h-3.5 w-3.5" />}
                  </button>
                )
              })}
            </div>
          </div>

          {/* Roles */}
          <div className="space-y-2">
            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">By role</div>
            <div className="flex flex-wrap gap-2">
              {REVIEWER_ROLES.map((role) => {
                const active = hasEverywhere('reviewer_roles', role.value)
                const partial = hasSomewhere('reviewer_roles', role.value)
                return (
                  <button
                    key={role.value}
                    type="button"
                    onClick={() => toggleReviewerRole(role.value)}
                    className={cn(
                      'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-all',
                      active
                        ? 'border-primary/40 bg-primary/10 text-primary'
                        : partial
                          ? 'border-primary/30 border-dashed bg-primary/5 text-primary/80'
                          : 'border-border text-muted-foreground hover:border-primary/30 hover:bg-muted/60 hover:text-foreground',
                    )}
                  >
                    <Icon icon={role.icon} className="h-3.5 w-3.5" />
                    {role.label}
                    {active && <Icon icon="solar:check-circle-bold" className="h-3.5 w-3.5" />}
                    {partial && <Icon icon="solar:minus-circle-bold" className="h-3.5 w-3.5" />}
                  </button>
                )
              })}
            </div>
          </div>

          {/* People */}
          <div className="space-y-2">
            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Specific people
            </div>
            {availableReviewers.length === 0 ? (
              <p className="text-xs text-muted-foreground">No team members found.</p>
            ) : (
              <div className="grid max-h-60 gap-1 overflow-y-auto rounded-lg border p-2 sm:grid-cols-2">
                {availableReviewers.map((person) => {
                  const active = hasEverywhere('reviewer_user_ids', person.id)
                  const partial = hasSomewhere('reviewer_user_ids', person.id)
                  return (
                    <button
                      key={person.id}
                      type="button"
                      onClick={() => toggleReviewerPerson(person.id)}
                      className={cn(
                        'flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors',
                        active ? 'bg-primary/10' : partial ? 'bg-primary/5' : 'hover:bg-muted/60',
                      )}
                    >
                      <span
                        className={cn(
                          'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold',
                          active
                            ? 'bg-primary text-primary-foreground'
                            : partial
                              ? 'bg-primary/20 text-primary'
                              : 'bg-muted text-muted-foreground',
                        )}
                      >
                        {active ? (
                          <Icon icon="solar:check-circle-bold" className="h-4 w-4" />
                        ) : partial ? (
                          <Icon icon="solar:minus-circle-bold" className="h-4 w-4" />
                        ) : (
                          person.name.slice(0, 2).toUpperCase()
                        )}
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium leading-tight">
                          {person.name}
                        </span>
                        <span className="block truncate text-xs leading-tight text-muted-foreground">
                          {person.email}
                        </span>
                      </span>
                    </button>
                  )
                })}
              </div>
            )}
          </div>

          {reviewerCount > 0 ? (
            <div className="flex items-center gap-2 rounded-lg border border-dashed bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
              <Icon icon="solar:info-circle-bold-duotone" className="h-3.5 w-3.5 shrink-0 text-primary" />
              {multiType
                ? `Reviewer selections apply to ${selectedTypes.map((t) => t.label).join(', ')}.`
                : `${reviewerCount} reviewer source${reviewerCount === 1 ? '' : 's'} selected for ${reviewerTypeMeta.label.toLowerCase()}.`}{' '}
              The submitter is always excluded from approving their own document.
            </div>
          ) : (
            <div className="flex items-center gap-2 rounded-lg border border-dashed bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
              <Icon icon="solar:info-circle-bold-duotone" className="h-3.5 w-3.5 shrink-0 text-primary" />
              No reviewers configured for{' '}
              {multiType ? 'the selected document types' : reviewerTypeMeta.label.toLowerCase()} — a type with no
              reviewers of its own falls back to the invoice list, or the invoice-approval permission for invoices.
            </div>
          )}
        </div>
      </CardContent>
      <SettingsSaveBar
        hasChanges={Object.keys(dirty).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate()}
        onCancel={() => setDirty({})}
        label="Save approval settings"
      />
    </Card>
  )
}

// ─── Invoice Defaults ───────────────────────────────────────────────────────

export function InvoiceDefaultsSettings() {
  const { data, isLoading } = useSalesSettings()
  const [local, setLocal] = useState<Partial<SalesSettings['invoice']>>({})
  const mutation = useSalesSettingsMutation('invoice', 'Invoice defaults saved', () => setLocal({}))

  if (isLoading || !data) return <SettingsLoadingCard />

  const invoice = { ...data.invoice, ...local }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:document-add-bold-duotone"
        title="Invoice Defaults"
        description="Pre-fill new invoices with your preferred terms, notes, and posting behaviour."
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Industry field profile"
          description="Which extra fields the invoice entry screen shows. Medical keeps case, patient and doctor fields; Trading shows PO and delivery date; Services shows project reference. Other fields stay hidden."
        >
          <Select
            value={invoice.field_profile ?? 'medical'}
            onValueChange={(v) => setLocal({ ...local, field_profile: v as SalesSettings['invoice']['field_profile'] })}
          >
            <SelectTrigger className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="medical">Medical — case & patient</SelectItem>
              <SelectItem value="trading">Trading — PO & delivery</SelectItem>
              <SelectItem value="services">Services — project</SelectItem>
              <SelectItem value="general">General — no extra fields</SelectItem>
            </SelectContent>
          </Select>
        </SettingsRow>

        <SettingsRow
          title="Allow custom invoice numbers"
          description="Users may override the auto-generated number on a per-invoice basis."
        >
          <Switch
            checked={invoice.allow_custom_number}
            onCheckedChange={(v) => setLocal({ ...local, allow_custom_number: v })}
          />
        </SettingsRow>

        <SettingsRow
          title="Default payment terms"
          description="Applied to the due date when no customer-specific terms exist."
        >
          <div className="flex items-center gap-2">
            <Input
              type="number"
              min={0}
              max={365}
              value={invoice.default_payment_terms_days}
              onChange={(e) =>
                setLocal({ ...local, default_payment_terms_days: parseInt(e.target.value, 10) || 0 })
              }
              className="w-20 text-center"
            />
            <span className="text-sm text-muted-foreground">days</span>
          </div>
        </SettingsRow>

        <SettingsRow
          title="Allow partial payments"
          description="Customers and staff can record payments smaller than the full invoice balance."
        >
          <Switch
            checked={invoice.allow_partial_payments}
            onCheckedChange={(v) => setLocal({ ...local, allow_partial_payments: v })}
          />
        </SettingsRow>

        <SettingsRow
          title="Auto-send on post"
          description="When an invoice is posted, automatically email the customer the PDF and acceptance link."
        >
          <Switch
            checked={invoice.auto_send_to_customer_on_post}
            onCheckedChange={(v) => setLocal({ ...local, auto_send_to_customer_on_post: v })}
          />
        </SettingsRow>

        <div className="grid gap-6 py-4 md:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="default-notes" className="text-sm font-medium">
              Default notes
            </Label>
            <textarea
              id="default-notes"
              className="flex min-h-[100px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
              placeholder="e.g. Thank you for your business."
              value={invoice.default_notes}
              onChange={(e) => setLocal({ ...local, default_notes: e.target.value })}
              maxLength={1000}
            />
            <p className="text-xs text-muted-foreground">Printed on every invoice below the totals.</p>
          </div>
          <div className="space-y-2">
            <Label htmlFor="default-terms" className="text-sm font-medium">
              Default terms
            </Label>
            <textarea
              id="default-terms"
              className="flex min-h-[100px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
              placeholder="Payment due within 30 days."
              value={invoice.default_terms}
              onChange={(e) => setLocal({ ...local, default_terms: e.target.value })}
              maxLength={1000}
            />
            <p className="text-xs text-muted-foreground">Payment terms and conditions shown on the PDF.</p>
          </div>
        </div>
      </CardContent>
      <SettingsSaveBar
        hasChanges={Object.keys(local).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate(local)}
        onCancel={() => setLocal({})}
        label="Save defaults"
      />
    </Card>
  )
}

// ─── Sales Defaults (Issue #553) ────────────────────────────────────────────

type SalespersonMode = SalesSettings['defaults']['salesperson_mode']
type PricelistDisplay = SalesSettings['pricelist']['display']

export function SalesDefaultsSettings() {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const { data, isLoading } = useSalesSettings()
  const [mode, setMode] = useState<SalespersonMode | null>(null)
  const [display, setDisplay] = useState<PricelistDisplay | null>(null)
  const [teams, setTeams] = useState<Record<string, string> | null>(null)

  const mutation = useMutation({
    mutationFn: async (patch: {
      defaults: SalesSettings['defaults']
      pricelist: SalesSettings['pricelist']
    }) => {
      const res = await api.put('/settings/sales', patch)
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-settings'] })
      toast({ title: 'Sales defaults saved' })
      setMode(null)
      setDisplay(null)
      setTeams(null)
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Save failed',
        description: error.response?.data?.message || 'Please try again.',
      })
    },
  })

  if (isLoading || !data) return <SettingsLoadingCard />

  const currentMode = mode ?? data.defaults?.salesperson_mode ?? 'none'
  const currentDisplay = display ?? data.pricelist?.display ?? 'show_discount'
  const currentTeams = teams ?? data.defaults?.team_by_user ?? {}
  const users = data.invoice?.available_reviewers ?? []
  const hasChanges = mode !== null || display !== null || teams !== null

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:user-hand-up-bold-duotone"
        title="Sales Defaults"
        description="Pre-fill new quotations and sales orders with a salesperson and team, and choose how pricelist discounts appear on documents."
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Default salesperson"
          description="When enabled, new quotations and sales orders start with the creating user as the salesperson. Leave it blank to have no default."
        >
          <Select value={currentMode} onValueChange={(v) => setMode(v as SalespersonMode)}>
            <SelectTrigger className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="none">No default (leave blank)</SelectItem>
              <SelectItem value="current_user">Current user</SelectItem>
            </SelectContent>
          </Select>
        </SettingsRow>

        <SettingsRow
          title="Pricelist display"
          description="Show discount on lines keeps the list unit price and prints the discount separately; Net price only prints the unit price after the line discount."
        >
          <Select value={currentDisplay} onValueChange={(v) => setDisplay(v as PricelistDisplay)}>
            <SelectTrigger className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="show_discount">Show discount on lines</SelectItem>
              <SelectItem value="net_price">Net price only</SelectItem>
            </SelectContent>
          </Select>
        </SettingsRow>

        <div className="space-y-3 py-4">
          <div className="space-y-1">
            <div className="text-sm font-medium leading-none">Default sales team per user</div>
            <p className="text-sm leading-relaxed text-muted-foreground">
              The team stamped on documents a user creates. Leave a person blank to create their documents
              without a team.
            </p>
          </div>

          {users.length === 0 ? (
            <p className="text-xs text-muted-foreground">No team members found.</p>
          ) : (
            <div className="grid gap-2 sm:grid-cols-2">
              {users.map((person) => (
                <div
                  key={person.id}
                  className="flex items-center gap-3 rounded-lg border bg-card p-2.5 transition-colors hover:border-primary/30"
                >
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium leading-tight">{person.name}</div>
                    <div className="truncate text-xs leading-tight text-muted-foreground">{person.email}</div>
                  </div>
                  <Input
                    aria-label={`Default team for ${person.name}`}
                    value={currentTeams[String(person.id)] ?? ''}
                    onChange={(e) => setTeams({ ...currentTeams, [String(person.id)]: e.target.value })}
                    placeholder="No team"
                    className="w-36"
                    maxLength={255}
                  />
                </div>
              ))}
            </div>
          )}
        </div>
      </CardContent>
      <SettingsSaveBar
        hasChanges={hasChanges}
        isPending={mutation.isPending}
        onSave={() =>
          mutation.mutate({
            defaults: { salesperson_mode: currentMode, team_by_user: currentTeams },
            pricelist: { display: currentDisplay },
          })
        }
        onCancel={() => {
          setMode(null)
          setDisplay(null)
          setTeams(null)
        }}
        label="Save defaults"
      />
    </Card>
  )
}

// ─── Payments & Portal ──────────────────────────────────────────────────────

export function PaymentPortalSettings() {
  const { data, isLoading } = useSalesSettings()
  const [local, setLocal] = useState<Partial<SalesSettings['portal']>>({})
  const mutation = useSalesSettingsMutation('portal', 'Payment & portal settings saved', () => setLocal({}))

  if (isLoading || !data) return <SettingsLoadingCard />

  const portal = { ...data.portal, ...local }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:card-bold-duotone"
        title="Payments & Customer Portal"
        description="Control how customers accept invoices, sign digitally, and pay online."
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Customer acceptance portal"
          description="Customers receive a secure link to review and accept invoices — no login required."
        >
          <Switch
            checked={portal.acceptance_enabled}
            onCheckedChange={(v) => setLocal({ ...local, acceptance_enabled: v })}
          />
        </SettingsRow>

        <SettingsRow
          title="Require digital signature"
          description="Customers must draw a signature before their acceptance is recorded."
        >
          <Switch
            checked={portal.require_signature}
            onCheckedChange={(v) => setLocal({ ...local, require_signature: v })}
          />
        </SettingsRow>

        <SettingsRow
          title="Acceptance link validity"
          description="How long the customer's acceptance link stays active after it is issued."
        >
          <div className="flex items-center gap-2">
            <Input
              type="number"
              min={1}
              max={365}
              value={portal.acceptance_link_expiry_days}
              onChange={(e) =>
                setLocal({ ...local, acceptance_link_expiry_days: parseInt(e.target.value, 10) || 30 })
              }
              className="w-20 text-center"
            />
            <span className="text-sm text-muted-foreground">days</span>
          </div>
        </SettingsRow>

        <SettingsRow
          title="Payment link validity"
          description="Expiry window for online payment links generated on invoices."
        >
          <div className="flex items-center gap-2">
            <Input
              type="number"
              min={1}
              max={365}
              value={portal.payment_link_expiry_days}
              onChange={(e) =>
                setLocal({ ...local, payment_link_expiry_days: parseInt(e.target.value, 10) || 14 })
              }
              className="w-20 text-center"
            />
            <span className="text-sm text-muted-foreground">days</span>
          </div>
        </SettingsRow>
      </CardContent>
      <SettingsSaveBar
        hasChanges={Object.keys(local).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate(local)}
        onCancel={() => setLocal({})}
        label="Save payment settings"
      />
    </Card>
  )
}

// ─── Tax & Pricing ──────────────────────────────────────────────────────────

const TAX_TREATMENTS = [
  { value: 'standard', label: 'Standard rated' },
  { value: 'zero_rated', label: 'Zero rated' },
  { value: 'exempt', label: 'Exempt' },
  { value: 'out_of_scope', label: 'Out of scope' },
]

export function TaxPricingSettings() {
  const { data, isLoading } = useSalesSettings()
  const [local, setLocal] = useState<Partial<SalesSettings['tax']>>({})
  const mutation = useSalesSettingsMutation('tax', 'Tax settings saved', () => setLocal({}))

  if (isLoading || !data) return <SettingsLoadingCard />

  const tax = { ...data.tax, ...local }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:sale-bold-duotone"
        title="Tax & Pricing"
        description="Default tax behaviour applied when new invoice lines are added."
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Default tax rate"
          description="Pre-fills the tax rate field on new invoice lines. Set to 0 to start tax-free."
        >
          <div className="flex items-center gap-2">
            <Input
              type="number"
              min={0}
              max={100}
              step={0.01}
              value={tax.default_tax_rate}
              onChange={(e) =>
                setLocal({ ...local, default_tax_rate: parseFloat(e.target.value) || 0 })
              }
              className="w-24 text-center"
            />
            <span className="text-sm text-muted-foreground">%</span>
          </div>
        </SettingsRow>

        <SettingsRow
          title="Default tax treatment"
          description="Applied to invoice lines when the customer has no tax configuration."
        >
          <Select
            value={tax.default_tax_treatment}
            onValueChange={(v) => setLocal({ ...local, default_tax_treatment: v })}
          >
            <SelectTrigger className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {TAX_TREATMENTS.map((t) => (
                <SelectItem key={t.value} value={t.value}>
                  {t.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </SettingsRow>

        <SettingsRow
          title="Prices include tax"
          description="When enabled, unit prices entered on invoice lines are treated as tax-inclusive."
        >
          <Switch
            checked={tax.prices_include_tax}
            onCheckedChange={(v) => setLocal({ ...local, prices_include_tax: v })}
          />
        </SettingsRow>
      </CardContent>
      <SettingsSaveBar
        hasChanges={Object.keys(local).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate(local)}
        onCancel={() => setLocal({})}
        label="Save tax settings"
      />
    </Card>
  )
}

// ─── Cash Rounding ──────────────────────────────────────────────────────────

const ROUNDING_METHODS = [
  { value: 'nearest', label: 'Round to nearest step' },
  { value: 'up', label: 'Always round up' },
  { value: 'down', label: 'Always round down' },
]

export function CashRoundingSettings() {
  const { data, isLoading } = useSalesSettings()
  const [local, setLocal] = useState<Partial<SalesSettings['cash_rounding']>>({})
  const mutation = useSalesSettingsMutation('cash_rounding', 'Cash rounding settings saved', () => setLocal({}))

  const { data: accounts } = useQuery({
    queryKey: ['chart-of-accounts', 'cash-rounding'],
    queryFn: async () => {
      const res = await api.get('/chart-of-accounts', { params: { is_active: 1 } })
      return (res.data?.data ?? []) as Array<{ id: number; code: string; name: string; type: string }>
    },
  })

  if (isLoading || !data) return <SettingsLoadingCard />

  const rounding = { ...data.cash_rounding, ...local }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:calculator-minimalistic-bold-duotone"
        title="Cash Rounding"
        description="Round every invoice's final payable total to a cash step and book the difference to a rounding account."
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Enable cash rounding"
          description="Off by default. When enabled, invoice totals are rounded and the adjustment is posted as its own journal line."
        >
          <Switch
            checked={rounding.enabled}
            onCheckedChange={(v) => setLocal({ ...local, enabled: v })}
          />
        </SettingsRow>

        <SettingsRow
          title="Rounding method"
          description="How each invoice total is rounded to the step."
        >
          <Select
            value={rounding.method}
            onValueChange={(v) =>
              setLocal({ ...local, method: v as SalesSettings['cash_rounding']['method'] })
            }
            disabled={!rounding.enabled}
          >
            <SelectTrigger className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {ROUNDING_METHODS.map((m) => (
                <SelectItem key={m.value} value={m.value}>
                  {m.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </SettingsRow>

        <SettingsRow
          title="Rounding step"
          description="The smallest cash unit — e.g. 0.05 for MYR 5 sen."
        >
          <Input
            type="number"
            min={0.01}
            max={100}
            step={0.01}
            value={rounding.precision}
            onChange={(e) =>
              setLocal({ ...local, precision: parseFloat(e.target.value) || 0 })
            }
            disabled={!rounding.enabled}
            className="w-28 text-center"
          />
        </SettingsRow>

        <SettingsRow
          title="Rounding account"
          description="Ledger account that carries the rounding gain/loss. Required whenever rounding is enabled."
        >
          <Select
            value={rounding.account_id ? String(rounding.account_id) : undefined}
            onValueChange={(v) => setLocal({ ...local, account_id: parseInt(v, 10) })}
            disabled={!rounding.enabled}
          >
            <SelectTrigger className="w-72">
              <SelectValue placeholder="Select an account" />
            </SelectTrigger>
            <SelectContent>
              {(accounts ?? []).map((account) => (
                <SelectItem key={account.id} value={String(account.id)}>
                  {account.code} — {account.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </SettingsRow>
      </CardContent>
      <SettingsSaveBar
        hasChanges={Object.keys(local).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate(local)}
        onCancel={() => setLocal({})}
        label="Save cash rounding"
      />
    </Card>
  )
}

// ─── Delivery & Sending ─────────────────────────────────────────────────────

export function DeliverySettings() {
  const { data, isLoading } = useSalesSettings()
  const [local, setLocal] = useState<Partial<SalesSettings['delivery']>>({})
  const mutation = useSalesSettingsMutation('delivery', 'Delivery settings saved', () => setLocal({}))

  if (isLoading || !data) return <SettingsLoadingCard />

  const delivery = { ...data.delivery, ...local }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:plain-2-bold-duotone"
        title="Delivery & Sending"
        description="Control what happens when an invoice is sent to a customer."
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Attach PDF to email"
          description="Include the invoice PDF as an attachment on outgoing customer emails."
        >
          <Switch
            checked={delivery.attach_pdf}
            onCheckedChange={(v) => setLocal({ ...local, attach_pdf: v })}
          />
        </SettingsRow>

        <SettingsRow
          title="Document type on send"
          description="Which version of the document the customer receives."
        >
          <Select
            value={delivery.document_type_on_send}
            onValueChange={(v) => setLocal({ ...local, document_type_on_send: v })}
          >
            <SelectTrigger className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="final_invoice">Final invoice</SelectItem>
              <SelectItem value="acceptance_copy">Acceptance copy</SelectItem>
            </SelectContent>
          </Select>
        </SettingsRow>

        <div className="space-y-2 py-4">
          <Label htmlFor="cc-email" className="text-sm font-medium">
            CC internal email
          </Label>
          <Input
            id="cc-email"
            type="email"
            placeholder="accounting@yourcompany.com"
            value={delivery.cc_internal_email}
            onChange={(e) => setLocal({ ...local, cc_internal_email: e.target.value })}
            maxLength={255}
          />
          <p className="text-xs text-muted-foreground">
            Every invoice email is copied to this address for record keeping. Leave blank to disable.
          </p>
        </div>
      </CardContent>
      <SettingsSaveBar
        hasChanges={Object.keys(local).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate(local)}
        onCancel={() => setLocal({})}
        label="Save delivery settings"
      />
    </Card>
  )
}
