import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import { cn } from '@/lib/utils'
import api from '@/lib/api'

/**
 * #671 (EINV-UX2): e-invoice readiness before Post. The backend dry-runs the
 * country pack's own submission rules (GET /invoices/{id}/einvoice-readiness)
 * and returns each gap with the record that fixes it; customer gaps are fixed
 * here in place, the others link to the page that owns the field.
 */

export type ReadinessFixType = 'customer' | 'product' | 'legal_entity' | 'einvoice_settings' | 'invoice'

export interface ReadinessIssue {
  country_code: string
  code: string
  severity: 'blocking' | 'warning'
  message: string
  fix: {
    type: ReadinessFixType
    id: number | null
    fields: string[]
    address_id?: number | null
  } | null
}

export interface EInvoiceReadiness {
  applicable: boolean
  ready: boolean
  countries: string[]
  summary: { blocking: number; warnings: number }
  issues: ReadinessIssue[]
}

const einvoiceReadinessQueryKey = (invoiceId: string | number) => ['invoice-einvoice-readiness', String(invoiceId)]

const SYSTEM_LABELS: Record<string, string> = {
  MY: 'MyInvois',
  SG: 'InvoiceNow',
}

// The buyer types the customer endpoint accepts (customers.buyer_type).
const BUYER_TYPES = [
  { value: 'business', label: 'Business' },
  { value: 'individual', label: 'Individual' },
  { value: 'government', label: 'Government' },
  { value: 'foreign', label: 'Foreign' },
]

function fixLink(issue: ReadinessIssue, invoiceId: string | number): { to: string; label: string } | null {
  const fix = issue.fix
  if (!fix) return null

  switch (fix.type) {
    case 'product':
      return fix.id ? { to: `/products/${fix.id}/edit`, label: 'Open product' } : null
    case 'legal_entity':
      return { to: '/accounting/configuration/company?tab=entities', label: 'Open legal entity' }
    case 'einvoice_settings':
      return issue.country_code === 'MY'
        ? { to: '/accounting/configuration/myinvois', label: 'Open MyInvois settings' }
        : null
    case 'invoice':
      return { to: `/invoices/${invoiceId}/edit`, label: 'Edit invoice' }
    case 'customer':
      return fix.id ? { to: `/customers/${fix.id}`, label: 'Open customer' } : null
    default:
      return null
  }
}

interface CustomerFixDialogProps {
  issue: ReadinessIssue
  invoiceId: string | number
  onClose: () => void
}

function CustomerFixDialog({ issue, invoiceId, onClose }: CustomerFixDialogProps) {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const fix = issue.fix!
  const editsTin = fix.fields.includes('tax_number')
  const editsState = fix.fields.includes('state')

  const [taxNumber, setTaxNumber] = useState('')
  const [buyerType, setBuyerType] = useState('business')
  const [state, setState] = useState('')

  const save = useMutation({
    mutationFn: async () => {
      if (editsTin) {
        // Either a TIN, or the buyer is reclassified (e.g. individual).
        const payload: Record<string, string> = { buyer_type: buyerType }
        if (taxNumber.trim() !== '') payload.tax_number = taxNumber.trim()
        await api.patch(`/customers/${fix.id}`, payload)
      }
      if (editsState) {
        if (fix.address_id) {
          await api.put(`/customers/${fix.id}/addresses/${fix.address_id}`, { state: state.trim() })
        } else {
          await api.post(`/customers/${fix.id}/addresses`, { type: 'billing', state: state.trim(), is_default: true })
        }
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: einvoiceReadinessQueryKey(invoiceId) })
      queryClient.invalidateQueries({ queryKey: ['invoice-preflight', String(invoiceId)] })
      queryClient.invalidateQueries({ queryKey: ['invoice', String(invoiceId)] })
      toast({ title: 'Customer updated' })
      onClose()
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not update the customer',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const canSave = editsTin
    ? taxNumber.trim() !== '' || buyerType === 'individual'
    : state.trim() !== ''

  return (
    <Dialog open onOpenChange={(open) => { if (!open) onClose() }}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Fix customer for e-invoice</DialogTitle>
          <DialogDescription>{issue.message}</DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(event) => {
            event.preventDefault()
            if (canSave) save.mutate()
          }}
        >
          {editsTin && (
            <>
              <div className="space-y-1.5">
                <Label htmlFor="einv-fix-tin">TIN / Tax Number</Label>
                <Input id="einv-fix-tin" value={taxNumber} onChange={(e) => setTaxNumber(e.target.value)} />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="einv-fix-buyer-type">Buyer type</Label>
                <select
                  id="einv-fix-buyer-type"
                  value={buyerType}
                  onChange={(e) => setBuyerType(e.target.value)}
                  className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                >
                  {BUYER_TYPES.map((type) => (
                    <option key={type.value} value={type.value}>{type.label}</option>
                  ))}
                </select>
              </div>
            </>
          )}
          {editsState && (
            <div className="space-y-1.5">
              <Label htmlFor="einv-fix-state">State</Label>
              <Input id="einv-fix-state" value={state} onChange={(e) => setState(e.target.value)} />
            </div>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={!canSave || save.isPending}>
              {save.isPending ? 'Saving…' : 'Save'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

interface EInvoiceReadinessPanelProps {
  invoiceId: string | number
  enabled: boolean
  canEditCustomer?: boolean
}

export function EInvoiceReadinessPanel({ invoiceId, enabled, canEditCustomer = true }: EInvoiceReadinessPanelProps) {
  const [fixing, setFixing] = useState<ReadinessIssue | null>(null)

  const { data } = useQuery<EInvoiceReadiness>({
    queryKey: einvoiceReadinessQueryKey(invoiceId),
    queryFn: async () => (await api.get(`/invoices/${invoiceId}/einvoice-readiness`)).data,
    enabled,
  })

  if (!enabled || !data || !data.applicable) return null

  const systems = data.countries.map((code) => SYSTEM_LABELS[code] ?? code).join(', ')

  if (data.issues.length === 0) {
    return (
      <div
        data-testid="einvoice-readiness"
        className="print:hidden flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300"
      >
        <Icon icon="solar:check-circle-bold" className="h-4 w-4" />
        E-invoice ready — no gaps found for {systems}.
      </div>
    )
  }

  const blocking = data.summary.blocking

  return (
    <div
      data-testid="einvoice-readiness"
      className={cn(
        'print:hidden space-y-3 rounded-lg border p-4',
        blocking > 0
          ? 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40'
          : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40',
      )}
    >
      <div className="flex flex-wrap items-center gap-2">
        <Icon
          icon={blocking > 0 ? 'solar:close-circle-bold-duotone' : 'solar:danger-triangle-bold-duotone'}
          className={cn('h-5 w-5', blocking > 0 ? 'text-red-600' : 'text-amber-600')}
        />
        <span className="text-sm font-semibold">E-invoice readiness ({systems})</span>
        <span className="text-xs text-muted-foreground">
          {blocking > 0 && `${blocking} blocking`}
          {blocking > 0 && data.summary.warnings > 0 && ' · '}
          {data.summary.warnings > 0 && `${data.summary.warnings} warning${data.summary.warnings === 1 ? '' : 's'}`}
        </span>
      </div>
      <ul className="space-y-1.5 text-sm">
        {data.issues.map((issue, index) => {
          const link = fixLink(issue, invoiceId)
          const inlineFix = issue.fix?.type === 'customer' && issue.fix.id && canEditCustomer

          return (
            <li key={`${issue.code}-${issue.fix?.id ?? index}-${index}`} className="flex flex-wrap items-start gap-2">
              <Icon
                icon={issue.severity === 'blocking' ? 'solar:close-circle-bold' : 'solar:danger-triangle-bold'}
                className={cn('mt-0.5 h-4 w-4 shrink-0', issue.severity === 'blocking' ? 'text-red-600' : 'text-amber-600')}
                aria-label={issue.severity}
              />
              <span className="min-w-0 flex-1">{issue.message}</span>
              {inlineFix ? (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className="h-7 rounded-full px-3 text-xs"
                  onClick={() => setFixing(issue)}
                >
                  Fix
                </Button>
              ) : link ? (
                <Link to={link.to} className="text-xs font-medium text-primary underline-offset-2 hover:underline">
                  {link.label}
                </Link>
              ) : null}
            </li>
          )
        })}
      </ul>
      {fixing && <CustomerFixDialog issue={fixing} invoiceId={invoiceId} onClose={() => setFixing(null)} />}
    </div>
  )
}

export default EInvoiceReadinessPanel
