import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { useToast } from '@/hooks/use-toast'
import { getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'

/**
 * Issue #441: merge two customers. The survivor keeps its record; the other
 * customer's documents, contacts, tags and chatter move to it and the other
 * customer is archived. For each master field that differs, the user picks
 * which record's value the survivor keeps (`field_choices`).
 *
 * Backed by POST /customers/{survivor}/merge (customers.write).
 */

/** Mirrors CustomerMergeService::FIELD_CHOICE_FIELDS (labels for display). */
const MERGE_FIELDS: { key: string; label: string }[] = [
  { key: 'name', label: 'Name' },
  { key: 'type', label: 'Type' },
  { key: 'buyer_type', label: 'Buyer type' },
  { key: 'email', label: 'Email' },
  { key: 'phone', label: 'Phone' },
  { key: 'mobile', label: 'Mobile' },
  { key: 'company', label: 'Company' },
  { key: 'tax_number', label: 'Tax number' },
  { key: 'company_registration_number', label: 'Registration number' },
  { key: 'website', label: 'Website' },
  { key: 'language', label: 'Language' },
  { key: 'billing_address', label: 'Billing address' },
  { key: 'shipping_address', label: 'Shipping address' },
  { key: 'credit_limit', label: 'Credit limit' },
  { key: 'customer_group', label: 'Customer group' },
  { key: 'payment_terms', label: 'Payment terms (days)' },
  { key: 'payment_term_id', label: 'Payment term' },
  { key: 'price_list_id', label: 'Price list' },
  { key: 'salesperson_id', label: 'Salesperson' },
  { key: 'date_of_birth', label: 'Date of birth' },
  { key: 'gender', label: 'Gender' },
  { key: 'preferred_contact', label: 'Preferred contact' },
  { key: 'marketing_opt_in', label: 'Marketing opt-in' },
  { key: 'notes', label: 'Notes' },
]

type FieldSource = 'survivor' | 'merged'

type MergeCustomer = Record<string, unknown> & { id: number; name?: string; display_name?: string; code?: string }

function display(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function sameValue(a: unknown, b: unknown): boolean {
  const empty = (v: unknown) => v === null || v === undefined || v === ''
  if (empty(a) && empty(b)) return true
  return display(a) === display(b)
}

interface CustomerMergeDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The customer that survives the merge. */
  survivorId: number | string
  /** The customer merged away; when omitted the user picks it. */
  mergedId?: number | string | null
  onMerged?: (survivorId: number) => void
}

export function CustomerMergeDialog({ open, onOpenChange, survivorId, mergedId, onMerged }: CustomerMergeDialogProps) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [pickedId, setPickedId] = useState<string>('')
  const [choices, setChoices] = useState<Record<string, FieldSource>>({})
  const [reason, setReason] = useState('')

  const otherId = mergedId != null && mergedId !== '' ? String(mergedId) : pickedId

  useEffect(() => {
    if (!open) {
      setPickedId('')
      setChoices({})
      setReason('')
    }
  }, [open])

  useEffect(() => {
    setChoices({})
  }, [otherId])

  const survivor = useQuery<MergeCustomer>({
    queryKey: ['customer-merge', String(survivorId)],
    queryFn: async () => (await api.get(`/customers/${survivorId}`)).data,
    enabled: open && !!survivorId,
  })

  const other = useQuery<MergeCustomer>({
    queryKey: ['customer-merge', otherId],
    queryFn: async () => (await api.get(`/customers/${otherId}`)).data,
    enabled: open && !!otherId,
  })

  const differing = useMemo(() => {
    if (!survivor.data || !other.data) return []
    return MERGE_FIELDS.filter(({ key }) => !sameValue(survivor.data[key], other.data[key]))
  }, [survivor.data, other.data])

  const sameCustomer = !!otherId && String(otherId) === String(survivorId)

  const mergeMutation = useMutation({
    mutationFn: async () => {
      const fieldChoices: Record<string, FieldSource> = {}
      for (const { key } of differing) fieldChoices[key] = choices[key] ?? 'survivor'
      const payload: Record<string, unknown> = { merged_customer_id: Number(otherId) }
      if (reason.trim() !== '') payload.reason = reason.trim()
      if (Object.keys(fieldChoices).length > 0) payload.field_choices = fieldChoices
      return (await api.post(`/customers/${survivorId}/merge`, payload)).data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      queryClient.invalidateQueries({ queryKey: ['customer'] })
      toast({ title: 'Customers merged' })
      onOpenChange(false)
      onMerged?.(Number(survivorId))
    },
    onError: (error: unknown) => {
      toast({ variant: 'destructive', title: 'Merge failed', description: getApiErrorMessage(error) })
    },
  })

  const label = (c?: MergeCustomer) => (c ? `${c.display_name || c.name || `#${c.id}`}${c.code ? ` (${c.code})` : ''}` : '…')

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Merge customers</DialogTitle>
          <DialogDescription>
            {label(survivor.data)} is kept. The other customer's documents, contacts, addresses, tags and notes move to it,
            and the other customer is archived.
          </DialogDescription>
        </DialogHeader>

        {mergedId == null || mergedId === '' ? (
          <div className="space-y-2">
            <Label>Customer to merge into this one</Label>
            <CustomerSearchCombobox value={pickedId} onValueChange={(value) => setPickedId(value)} placeholder="Search the duplicate customer…" />
          </div>
        ) : (
          <p className="text-sm">
            Merging <span className="font-medium">{label(other.data)}</span> into <span className="font-medium">{label(survivor.data)}</span>.
          </p>
        )}

        {sameCustomer && <p className="text-sm text-destructive">A customer cannot be merged into itself.</p>}

        {otherId && !sameCustomer && survivor.data && other.data && (
          differing.length === 0 ? (
            <p className="text-sm text-muted-foreground">The two customers have the same master details.</p>
          ) : (
            <div className="space-y-2">
              <p className="text-sm text-muted-foreground">Choose the value to keep for each field that differs.</p>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Field</TableHead>
                    <TableHead>
                      Keep <Badge variant="secondary" className="ml-1">survivor</Badge>
                    </TableHead>
                    <TableHead>
                      Merged customer
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {differing.map(({ key, label: fieldLabel }) => {
                    const source = choices[key] ?? 'survivor'
                    return (
                      <TableRow key={key}>
                        <TableCell className="font-medium">{fieldLabel}</TableCell>
                        {(['survivor', 'merged'] as FieldSource[]).map((side) => (
                          <TableCell key={side}>
                            <label className="flex items-start gap-2 cursor-pointer">
                              <input
                                type="radio"
                                name={`merge-field-${key}`}
                                aria-label={`${fieldLabel} from ${side}`}
                                checked={source === side}
                                onChange={() => setChoices((prev) => ({ ...prev, [key]: side }))}
                              />
                              <span className="break-all">{display((side === 'survivor' ? survivor.data : other.data)[key])}</span>
                            </label>
                          </TableCell>
                        ))}
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
          )
        )}

        <div className="space-y-2">
          <Label htmlFor="customer-merge-reason">Reason</Label>
          <Textarea id="customer-merge-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why these records are the same customer" />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button
            variant="destructive"
            disabled={!otherId || sameCustomer || !survivor.data || !other.data || mergeMutation.isPending}
            onClick={() => mergeMutation.mutate()}
          >
            {mergeMutation.isPending ? 'Merging…' : 'Merge'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
