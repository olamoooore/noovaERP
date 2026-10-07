import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export interface CreditableInvoice {
  id: number
  invoice_number: string
  status: string
}

/**
 * Issue #376 (O7) / #154: the order-detail entry point for
 * POST /sales/orders/{id}/credit-note. The backend builds the draft credit
 * note from the chosen invoice's remaining creditable lines (#372); this only
 * picks the invoice and reason, then opens the created draft.
 */
export function SalesOrderCreditNoteAction({
  orderId,
  orderNumber,
  invoices,
}: {
  orderId: number
  orderNumber: string
  invoices: CreditableInvoice[]
}) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const creditable = invoices.filter((invoice) => !['cancelled', 'draft'].includes(String(invoice.status).toLowerCase()))
  const [open, setOpen] = useState(false)
  // The backend defaults to the latest linked invoice; mirror that default.
  const latest = [...creditable].sort((a, b) => b.id - a.id)[0]
  const [invoiceId, setInvoiceId] = useState<string>(latest ? String(latest.id) : '')
  const [reason, setReason] = useState('')

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = { invoice_id: Number(invoiceId) }
      if (reason.trim() !== '') payload.reason = reason.trim()
      const response = await api.post(`/sales/orders/${orderId}/credit-note`, payload)
      return response.data
    },
    onSuccess: (data) => {
      setOpen(false)
      queryClient.invalidateQueries({ queryKey: ['sales-order', String(orderId)] })
      const creditNote = data?.credit_note
      toast({
        title: 'Credit note draft created',
        description: creditNote?.credit_note_number || data?.message,
      })
      if (creditNote?.id) {
        navigate(`/accounting/customers/credit-notes/${creditNote.id}`)
      }
    },
    onError: (error: any) => {
      toast({
        title: 'Credit note not created',
        description: error.response?.data?.error || error.response?.data?.message || 'Failed to create credit note',
        variant: 'destructive',
      })
    },
  })

  if (creditable.length === 0) return null

  return (
    <>
      <HeaderActionButton
        variant="outline"
        icon="solar:bill-cross-bold-duotone"
        onClick={() => {
          setInvoiceId(latest ? String(latest.id) : '')
          setReason('')
          setOpen(true)
        }}
      >
        Credit Note
      </HeaderActionButton>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Credit note for {orderNumber}</DialogTitle>
            <DialogDescription>
              A draft credit note is created from the selected invoice's lines that have not been credited yet. You
              can review and edit it before posting.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="credit-note-invoice">Invoice to credit</Label>
              <select
                id="credit-note-invoice"
                value={invoiceId}
                onChange={(event) => setInvoiceId(event.target.value)}
                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              >
                {creditable.map((invoice) => (
                  <option key={invoice.id} value={String(invoice.id)}>
                    {invoice.invoice_number} ({invoice.status})
                  </option>
                ))}
              </select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="credit-note-reason">Reason</Label>
              <Textarea
                id="credit-note-reason"
                value={reason}
                placeholder={`Credit note for sales order ${orderNumber}`}
                onChange={(event) => setReason(event.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Back
            </Button>
            <Button disabled={!invoiceId || mutation.isPending} onClick={() => mutation.mutate()}>
              {mutation.isPending ? 'Creating...' : 'Create credit note'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
