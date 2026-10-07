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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

type DownPaymentMethod = 'percentage' | 'fixed'

/**
 * Issue #331: raise a down-payment invoice from a confirmed sales order —
 * a percentage of, or a fixed amount from, the order total (tax included).
 * The backend resolves tax at the invoice date and posts the invoice to the
 * Customer Deposit account; the final invoice deducts it automatically.
 */
export function SalesOrderDownPaymentAction({
  orderId,
  orderNumber,
  orderTotal,
  currency,
}: {
  orderId: number
  orderNumber: string
  orderTotal?: number | null
  currency?: string | null
}) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [open, setOpen] = useState(false)
  const [method, setMethod] = useState<DownPaymentMethod>('percentage')
  const [value, setValue] = useState('')

  const numeric = Number(value)
  const valid = value.trim() !== '' && Number.isFinite(numeric) && numeric > 0 && (method === 'fixed' || numeric <= 100)

  const mutation = useMutation({
    mutationFn: async () => {
      const payload = method === 'percentage' ? { method, percentage: numeric } : { method, amount: numeric }
      const response = await api.post(`/sales/orders/${orderId}/down-payment-invoice`, payload)
      return response.data
    },
    onSuccess: (data) => {
      setOpen(false)
      queryClient.invalidateQueries({ queryKey: ['sales-order', String(orderId)] })
      const invoice = data?.invoice
      toast({ title: 'Down-payment invoice created', description: invoice?.invoice_number || data?.message })
      if (invoice?.id) {
        navigate(`/invoices/${invoice.id}`)
      }
    },
    onError: (error: any) => {
      toast({
        title: 'Down payment not created',
        description: error.response?.data?.message || 'Failed to create the down-payment invoice',
        variant: 'destructive',
      })
    },
  })

  return (
    <>
      <HeaderActionButton
        variant="outline"
        icon="solar:wallet-money-bold-duotone"
        onClick={() => {
          setMethod('percentage')
          setValue('')
          setOpen(true)
        }}
      >
        Down Payment
      </HeaderActionButton>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Down payment for {orderNumber}</DialogTitle>
            <DialogDescription>
              A draft invoice is created for the down payment and, once posted, held in Customer Deposit. The final
              invoice of this order deducts it.
              {orderTotal != null && ` Order total: ${currency ? `${currency} ` : ''}${Number(orderTotal).toFixed(2)}.`}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="down-payment-method">Down payment as</Label>
              <select
                id="down-payment-method"
                value={method}
                onChange={(event) => setMethod(event.target.value as DownPaymentMethod)}
                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              >
                <option value="percentage">Percentage of the order total</option>
                <option value="fixed">Fixed amount (tax included)</option>
              </select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="down-payment-value">{method === 'percentage' ? 'Percentage (%)' : 'Amount'}</Label>
              <Input
                id="down-payment-value"
                type="number"
                min="0"
                step="0.01"
                max={method === 'percentage' ? '100' : undefined}
                value={value}
                onChange={(event) => setValue(event.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Back
            </Button>
            <Button disabled={!valid || mutation.isPending} onClick={() => mutation.mutate()}>
              {mutation.isPending ? 'Creating...' : 'Create down-payment invoice'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
