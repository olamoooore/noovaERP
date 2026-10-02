import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { todayDateOnly } from '@/lib/dateOnly' // #484
import DepositAccountSelect from '@/components/payment/DepositAccountSelect'
import { defaultDepositAccountId, readDepositMemory, rememberDeposit, type DepositAccountOption } from '@/lib/paymentDepositAccount'
import { useAuthStore } from '@/store/authStore'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { formatCurrency, formatDate } from '@/lib/utils'

interface Invoice {
  id: number
  invoice_number: string
  customer_id: number
  customer?: {
    id: number
    name: string
  }
  due_date: string
  total: number
  paid_amount: number
  balance: number
}

export function InvoicePaymentPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { toast } = useToast()

  const [formData, setFormData] = useState({
    amount: '',
    payment_date: todayDateOnly(), // #484: local business date, never UTC
    method: 'bank_transfer',
    reference: '',
    notes: '',
    bank_account_id: '',
  })

  const { user: authUser } = useAuthStore()
  const { data: depositOptions, isLoading: depositLoading } = useQuery<DepositAccountOption[]>({
    queryKey: ['bank-accounts'],
    queryFn: async () => {
      const response = await api.get('/accounting/bank-accounts')
      return response.data.data as DepositAccountOption[]
    },
  })

  // #485: default the deposit account per method, remembered per user.
  useEffect(() => {
    if (formData.bank_account_id || !depositOptions?.length) return
    const next = defaultDepositAccountId(
      formData.method,
      depositOptions,
      authUser ? readDepositMemory(authUser.id) : {},
    )
    if (next != null) setFormData((prev) => ({ ...prev, bank_account_id: String(next) }))
  }, [formData.bank_account_id, formData.method, depositOptions, authUser])

  const { data: invoice, isLoading } = useQuery({
    queryKey: ['invoice', id],
    queryFn: async () => {
      const response = await api.get(`/invoices/${id}`)
      return response.data as Invoice
    },
    enabled: !!id,
  })

  useEffect(() => {
    if (invoice) {
      setFormData((prev) => ({
        ...prev,
        amount: invoice.balance?.toString() || invoice.total?.toString(),
      }))
    }
  }, [invoice])

  const recordPaymentMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post('/payments', {
        ...data,
        invoice_id: parseInt(id!),
        customer_id: invoice?.customer_id,
      })
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Draft payment recorded',
        description: 'Review and post it from Accounting → Customer Payments to hit the ledger.',
      })
      navigate(`/invoices/${id}`)
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to record payment',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleMethodChange = (value: string) => {
    const next = defaultDepositAccountId(
      value,
      depositOptions ?? [],
      authUser ? readDepositMemory(authUser.id) : {},
    )
    setFormData({
      ...formData,
      method: value,
      bank_account_id: next != null ? String(next) : '',
    })
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (authUser && formData.bank_account_id) {
      rememberDeposit(authUser.id, formData.method, Number(formData.bank_account_id))
    }
    recordPaymentMutation.mutate({
      ...formData,
      bank_account_id: formData.bank_account_id ? Number(formData.bank_account_id) : null,
    })
  }

  if (isLoading) {
    return (
      <div className="container mx-auto py-6 space-y-6">
        <div className="flex items-center justify-center h-64">
          <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
        </div>
      </div>
    )
  }

  if (!invoice) {
    return (
      <div className="container mx-auto py-6 space-y-6">
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Icon icon="material-symbols:receipt-long-off" className="h-16 w-16 text-muted-foreground mb-4" />
            <h2 className="text-xl font-semibold mb-2">Invoice Not Found</h2>
            <p className="text-muted-foreground mb-4">The invoice you're looking for doesn't exist.</p>
            <Button onClick={() => navigate('/invoices')}>
              <Icon icon="material-symbols:arrow-back" className="mr-2 h-4 w-4" />
              Back to Invoices
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="outline" onClick={() => navigate(`/invoices/${id}`)}>
            <Icon icon="material-symbols:arrow-back" className="mr-2 h-4 w-4" />
            Back to Invoice
          </Button>
          <div>
            <h1 className="text-2xl font-bold">Record Payment</h1>
            <p className="text-muted-foreground text-sm">
              Invoice {invoice.invoice_number}
            </p>
          </div>
        </div>
      </div>

      {/* Invoice Summary */}
      <Card>
        <CardHeader>
          <CardTitle>Invoice Summary</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-3 gap-4">
            <div>
              <p className="text-sm text-muted-foreground">Customer</p>
              <p className="font-semibold">{invoice.customer?.name || 'N/A'}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Due Date</p>
              <p className="font-semibold">{formatDate(invoice.due_date)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Balance Due</p>
              <p className="font-semibold text-red-600">{formatCurrency(invoice.balance)}</p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Payment Form */}
      <Card>
        <CardHeader>
          <CardTitle>Payment Details</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="grid grid-cols-2 gap-6">
              <div className="space-y-2">
                <Label htmlFor="amount">Payment Amount</Label>
                <Input
                  id="amount"
                  type="number"
                  step="0.01"
                  value={formData.amount}
                  onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
                  required
                />
                <p className="text-xs text-muted-foreground">
                  Invoice balance: {formatCurrency(invoice.balance)}
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="payment_date">Payment Date</Label>
                <Input
                  id="payment_date"
                  type="date"
                  value={formData.payment_date}
                  onChange={(e) => setFormData({ ...formData, payment_date: e.target.value })}
                  required
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-6">
              <div className="space-y-2">
                <Label htmlFor="method">Payment Method</Label>
                <Select
                  value={formData.method}
                  onValueChange={handleMethodChange}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="cash">Cash</SelectItem>
                    <SelectItem value="bank_transfer">Bank Transfer</SelectItem>
                    <SelectItem value="card">Credit/Debit Card</SelectItem>
                    <SelectItem value="check">Check</SelectItem>
                    <SelectItem value="other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="reference">Reference Number</Label>
                <Input
                  id="reference"
                  value={formData.reference}
                  onChange={(e) => setFormData({ ...formData, reference: e.target.value })}
                  placeholder="Transaction ID, check number, etc."
                />
              </div>
            </div>

            <DepositAccountSelect
              id="deposit_account_id"
              value={formData.bank_account_id}
              onChange={(value) => {
                if (authUser && value) rememberDeposit(authUser.id, formData.method, Number(value))
                setFormData({ ...formData, bank_account_id: value })
              }}
              options={depositOptions}
              isLoading={depositLoading}
            />

            <div className="space-y-2">
              <Label htmlFor="notes">Notes (Optional)</Label>
              <Input
                id="notes"
                value={formData.notes}
                onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                placeholder="Additional notes..."
              />
            </div>

            <div className="flex justify-end gap-2">
              <Button variant="outline" type="button" onClick={() => navigate(`/invoices/${id}`)}>
                Cancel
              </Button>
              <Button type="submit" disabled={recordPaymentMutation.isPending}>
                {recordPaymentMutation.isPending ? 'Recording...' : 'Record Payment'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}