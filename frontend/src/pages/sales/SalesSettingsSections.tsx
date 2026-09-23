import { useState, type ReactNode } from 'react'
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

export interface SalesSettings {
  invoice: {
    allow_custom_number: boolean
    default_requires_approval: boolean
    approval_expiry_days: number
    auto_send_to_customer_on_post: boolean
    allow_partial_payments: boolean
    default_payment_terms_days: number
    default_notes: string
    default_terms: string
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
}

export const emptySalesSettings: SalesSettings = {
  invoice: {
    allow_custom_number: true,
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

function LoadingCard() {
  return (
    <Card>
      <CardContent className="flex items-center justify-center py-24">
        <div className="flex flex-col items-center gap-3 text-muted-foreground">
          <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
          <span className="text-sm">Loading settings…</span>
        </div>
      </CardContent>
    </Card>
  )
}

function SectionHeader({
  icon,
  title,
  description,
}: {
  icon: string
  title: string
  description: string
}) {
  return (
    <CardHeader className="border-b bg-muted/20">
      <div className="flex items-start gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
          <Icon icon={icon} className="h-5 w-5 text-primary" />
        </div>
        <div className="space-y-1">
          <CardTitle className="text-lg leading-none">{title}</CardTitle>
          <CardDescription className="text-sm leading-relaxed">{description}</CardDescription>
        </div>
      </div>
    </CardHeader>
  )
}

function SettingRow({
  title,
  description,
  children,
}: {
  title: string
  description: string
  children: ReactNode
}) {
  return (
    <div className="flex items-start justify-between gap-8 py-4">
      <div className="flex-1 space-y-1">
        <div className="text-sm font-medium leading-none">{title}</div>
        <p className="text-sm text-muted-foreground leading-relaxed">{description}</p>
      </div>
      <div className="shrink-0 pt-0.5">{children}</div>
    </div>
  )
}

function SaveBar({
  hasChanges,
  isPending,
  onSave,
  onCancel,
  label,
}: {
  hasChanges: boolean
  isPending: boolean
  onSave: () => void
  onCancel: () => void
  label: string
}) {
  if (!hasChanges) return null
  return (
    <div className="flex items-center justify-between gap-4 border-t bg-muted/20 px-6 py-3">
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Icon icon="solar:info-circle-bold-duotone" className="h-4 w-4 text-amber-500" />
        Unsaved changes
      </div>
      <div className="flex items-center gap-2">
        <Button variant="ghost" size="sm" onClick={onCancel} disabled={isPending}>
          Discard
        </Button>
        <Button size="sm" onClick={onSave} disabled={isPending}>
          {isPending ? (
            <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
          ) : (
            <Icon icon="solar:diskette-bold-duotone" className="mr-2 h-4 w-4" />
          )}
          {label}
        </Button>
      </div>
    </div>
  )
}

function NumberingField({
  label,
  description,
  value,
  onChange,
  defaultValue,
}: {
  label: string
  description: string
  value: string
  onChange: (v: string) => void
  defaultValue: string
}) {
  const now = new Date()
  const preview =
    (value || defaultValue)
      .replace('{YYYY}', String(now.getFullYear()))
      .replace('{YY}', String(now.getFullYear()).slice(-2))
      .replace('{MM}', String(now.getMonth() + 1).padStart(2, '0'))
      .replace(/\{SEQ:(\d+)\}/g, (_, n) => '1'.padStart(parseInt(n, 10), '0')) || '—'

  return (
    <div className="space-y-3 rounded-lg border bg-card p-4 transition-colors hover:border-primary/30">
      <div className="flex items-start justify-between gap-4">
        <div className="space-y-0.5">
          <Label className="text-sm font-medium">{label}</Label>
          <p className="text-xs text-muted-foreground">{description}</p>
        </div>
        <Badge variant="outline" className="font-mono text-xs tabular-nums">
          {preview}
        </Badge>
      </div>
      <Input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={defaultValue}
        className="font-mono text-sm"
      />
    </div>
  )
}

// ─── Document Numbering ─────────────────────────────────────────────────────

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
      return res.data as Record<string, { current: string; default: string; example: string }> & {
        date_format: string
      }
    },
  })

  const [local, setLocal] = useState<Record<string, string>>({})
  const [initialized, setInitialized] = useState(false)
  const [savedDefaults, setSavedDefaults] = useState<Record<string, string>>({})

  if (formats && !initialized) {
    const snapshot = {
      invoice: formats.invoice?.current ?? 'INV-{YYYY}-{SEQ:4}',
      quotation: formats.quotation?.current ?? 'QUO-{YYYY}-{SEQ:4}',
      delivery_order: formats.delivery_order?.current ?? 'DO-{YYYY}-{SEQ:4}',
      date_format: formats.date_format ?? 'Y-m-d',
    }
    setSavedDefaults(snapshot)
    setInitialized(true)
  }

  const current = { ...savedDefaults, ...local }
  const hasChanges = Object.keys(local).length > 0

  const updateMutation = useMutation({
    mutationFn: async () => {
      const res = await api.put('/settings/document-formats', current)
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['document-formats'] })
      setSavedDefaults(current)
      setLocal({})
      toast({ title: 'Numbering settings saved' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Save failed',
        description: error.response?.data?.message || 'Please try again.',
      })
    },
  })

  if (isLoading || !formats || !initialized) return <LoadingCard />

  return (
    <Card className="overflow-hidden">
      <SectionHeader
        icon="solar:hashtag-circle-bold-duotone"
        title="Document Numbering"
        description="Control how invoice, quotation, and delivery order numbers are generated. Use tokens to build the format."
      />
      <CardContent className="space-y-6 pt-6">
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-dashed bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
          <span className="font-medium text-foreground">Available tokens:</span>
          {['{YYYY}', '{YY}', '{MM}', '{SEQ:4}'].map((token) => (
            <code key={token} className="rounded bg-background px-1.5 py-0.5 font-mono ring-1 ring-border">
              {token}
            </code>
          ))}
          <span className="ml-1">— year, short year, month, zero-padded sequence.</span>
        </div>

        <div className="grid gap-4">
          <NumberingField
            label="Invoice number"
            description="Applied to new sales invoices"
            value={current.invoice ?? ''}
            onChange={(v) => setLocal({ ...local, invoice: v })}
            defaultValue="INV-{YYYY}-{SEQ:4}"
          />
          <NumberingField
            label="Quotation number"
            description="Applied to new sales quotations"
            value={current.quotation ?? ''}
            onChange={(v) => setLocal({ ...local, quotation: v })}
            defaultValue="QUO-{YYYY}-{SEQ:4}"
          />
          <NumberingField
            label="Delivery order number"
            description="Applied to new delivery orders"
            value={current.delivery_order ?? ''}
            onChange={(v) => setLocal({ ...local, delivery_order: v })}
            defaultValue="DO-{YYYY}-{SEQ:4}"
          />
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
      <SaveBar
        hasChanges={hasChanges}
        isPending={updateMutation.isPending}
        onSave={() => updateMutation.mutate()}
        onCancel={() => setLocal({})}
        label="Save numbering"
      />
    </Card>
  )
}

// ─── Approval Workflow ──────────────────────────────────────────────────────

export function ApprovalSettings() {
  const { data, isLoading } = useSalesSettings()
  const [local, setLocal] = useState<Partial<SalesSettings['invoice']>>({})
  const mutation = useSalesSettingsMutation('invoice', 'Approval settings saved', () => setLocal({}))

  if (isLoading || !data) return <LoadingCard />

  const invoice = { ...data.invoice, ...local }

  return (
    <Card className="overflow-hidden">
      <SectionHeader
        icon="solar:checklist-bold-duotone"
        title="Approval Workflow"
        description="Decide which invoices need review before posting and how long approvals remain valid."
      />
      <CardContent className="divide-y py-0">
        <SettingRow
          title="Require approval by default"
          description="New invoices start with the approval requirement already enabled."
        >
          <Switch
            checked={invoice.default_requires_approval}
            onCheckedChange={(v) => setLocal({ ...local, default_requires_approval: v })}
          />
        </SettingRow>

        <SettingRow
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
                setLocal({ ...local, approval_expiry_days: parseInt(e.target.value, 10) || 7 })
              }
              className="w-20 text-center"
            />
            <span className="text-sm text-muted-foreground">days</span>
          </div>
        </SettingRow>

        <SettingRow
          title="Block posting without approval"
          description="When enabled, unapproved invoices cannot be posted to the general ledger."
        >
          <Badge variant="outline" className="gap-1 border-green-300 text-green-700 dark:border-green-800 dark:text-green-400">
            <Icon icon="solar:shield-check-bold-duotone" className="h-3.5 w-3.5" />
            Enforced
          </Badge>
        </SettingRow>
      </CardContent>
      <SaveBar
        hasChanges={Object.keys(local).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate(local)}
        onCancel={() => setLocal({})}
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

  if (isLoading || !data) return <LoadingCard />

  const invoice = { ...data.invoice, ...local }

  return (
    <Card className="overflow-hidden">
      <SectionHeader
        icon="solar:document-add-bold-duotone"
        title="Invoice Defaults"
        description="Pre-fill new invoices with your preferred terms, notes, and posting behaviour."
      />
      <CardContent className="divide-y py-0">
        <SettingRow
          title="Allow custom invoice numbers"
          description="Users may override the auto-generated number on a per-invoice basis."
        >
          <Switch
            checked={invoice.allow_custom_number}
            onCheckedChange={(v) => setLocal({ ...local, allow_custom_number: v })}
          />
        </SettingRow>

        <SettingRow
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
        </SettingRow>

        <SettingRow
          title="Allow partial payments"
          description="Customers and staff can record payments smaller than the full invoice balance."
        >
          <Switch
            checked={invoice.allow_partial_payments}
            onCheckedChange={(v) => setLocal({ ...local, allow_partial_payments: v })}
          />
        </SettingRow>

        <SettingRow
          title="Auto-send on post"
          description="When an invoice is posted, automatically email the customer the PDF and acceptance link."
        >
          <Switch
            checked={invoice.auto_send_to_customer_on_post}
            onCheckedChange={(v) => setLocal({ ...local, auto_send_to_customer_on_post: v })}
          />
        </SettingRow>

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
      <SaveBar
        hasChanges={Object.keys(local).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate(local)}
        onCancel={() => setLocal({})}
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

  if (isLoading || !data) return <LoadingCard />

  const portal = { ...data.portal, ...local }

  return (
    <Card className="overflow-hidden">
      <SectionHeader
        icon="solar:card-bold-duotone"
        title="Payments & Customer Portal"
        description="Control how customers accept invoices, sign digitally, and pay online."
      />
      <CardContent className="divide-y py-0">
        <SettingRow
          title="Customer acceptance portal"
          description="Customers receive a secure link to review and accept invoices — no login required."
        >
          <Switch
            checked={portal.acceptance_enabled}
            onCheckedChange={(v) => setLocal({ ...local, acceptance_enabled: v })}
          />
        </SettingRow>

        <SettingRow
          title="Require digital signature"
          description="Customers must draw a signature before their acceptance is recorded."
        >
          <Switch
            checked={portal.require_signature}
            onCheckedChange={(v) => setLocal({ ...local, require_signature: v })}
          />
        </SettingRow>

        <SettingRow
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
        </SettingRow>

        <SettingRow
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
        </SettingRow>
      </CardContent>
      <SaveBar
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

  if (isLoading || !data) return <LoadingCard />

  const tax = { ...data.tax, ...local }

  return (
    <Card className="overflow-hidden">
      <SectionHeader
        icon="solar:sale-bold-duotone"
        title="Tax & Pricing"
        description="Default tax behaviour applied when new invoice lines are added."
      />
      <CardContent className="divide-y py-0">
        <SettingRow
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
        </SettingRow>

        <SettingRow
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
        </SettingRow>

        <SettingRow
          title="Prices include tax"
          description="When enabled, unit prices entered on invoice lines are treated as tax-inclusive."
        >
          <Switch
            checked={tax.prices_include_tax}
            onCheckedChange={(v) => setLocal({ ...local, prices_include_tax: v })}
          />
        </SettingRow>
      </CardContent>
      <SaveBar
        hasChanges={Object.keys(local).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate(local)}
        onCancel={() => setLocal({})}
        label="Save tax settings"
      />
    </Card>
  )
}

// ─── Delivery & Sending ─────────────────────────────────────────────────────

export function DeliverySettings() {
  const { data, isLoading } = useSalesSettings()
  const [local, setLocal] = useState<Partial<SalesSettings['delivery']>>({})
  const mutation = useSalesSettingsMutation('delivery', 'Delivery settings saved', () => setLocal({}))

  if (isLoading || !data) return <LoadingCard />

  const delivery = { ...data.delivery, ...local }

  return (
    <Card className="overflow-hidden">
      <SectionHeader
        icon="solar:plain-2-bold-duotone"
        title="Delivery & Sending"
        description="Control what happens when an invoice is sent to a customer."
      />
      <CardContent className="divide-y py-0">
        <SettingRow
          title="Attach PDF to email"
          description="Include the invoice PDF as an attachment on outgoing customer emails."
        >
          <Switch
            checked={delivery.attach_pdf}
            onCheckedChange={(v) => setLocal({ ...local, attach_pdf: v })}
          />
        </SettingRow>

        <SettingRow
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
        </SettingRow>

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
      <SaveBar
        hasChanges={Object.keys(local).length > 0}
        isPending={mutation.isPending}
        onSave={() => mutation.mutate(local)}
        onCancel={() => setLocal({})}
        label="Save delivery settings"
      />
    </Card>
  )
}
