import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { formatCurrency, formatDate, getApiErrorMessage } from '@/lib/utils'

interface CustomerTransaction {
  transaction_type: string
  transaction_id: number
  document_number: string
  customer_id: number
  customer_name: string
  transaction_date: string
  due_date?: string | null
  debit: number
  credit: number
  balance: number
  status: string
}

function defaultPdfDates() {
  const to = new Date()
  const from = new Date(to.getFullYear(), to.getMonth() - 1, to.getDate())
  return {
    from: from.toISOString().split('T')[0],
    to: to.toISOString().split('T')[0],
  }
}

export const CustomerStatements = () => {
  const [searchParams] = useSearchParams()
  const { toast } = useToast()
  const [customerId, setCustomerId] = useState(searchParams.get('customer_id') || '')
  const [pdfDialogOpen, setPdfDialogOpen] = useState(false)
  const [pdfDates, setPdfDates] = useState(defaultPdfDates)

  useEffect(() => {
    setCustomerId(searchParams.get('customer_id') || '')
  }, [searchParams])

  const transactionsQuery = useQuery({
    queryKey: ['customer-account-statement-transactions', customerId],
    queryFn: async () => {
      const response = await api.get('/accounting/customers/transactions', {
        params: {
          customer_id: customerId,
          transaction_type: 'invoice',
          per_page: 1000,
        },
      })
      return ((response.data?.data || []) as CustomerTransaction[]).filter(
        (transaction) => transaction.status === 'posted' || transaction.status === 'paid'
      )
    },
    enabled: Boolean(customerId),
  })

  const selectedCustomerQuery = useQuery({
    queryKey: ['customer-statement-customer', customerId],
    queryFn: async () => {
      const response = await api.get(`/customers/${customerId}`)
      return response.data?.data ?? response.data
    },
    enabled: Boolean(customerId),
  })

  const transactions = transactionsQuery.data || []
  const totals = useMemo(() => transactions.reduce(
    (result, transaction) => ({
      debit: result.debit + Number(transaction.debit || 0),
      credit: result.credit + Number(transaction.credit || 0),
    }),
    { debit: 0, credit: 0 }
  ), [transactions])

  const generatePdf = useMutation({
    mutationFn: async () => {
      const response = await api.get(`/customers/${customerId}/statement`, {
        params: {
          start_date: pdfDates.from,
          end_date: pdfDates.to,
        },
        responseType: 'blob',
      })
      return response.data as BlobPart
    },
    onSuccess: (pdfBytes) => {
      const blob = new Blob([pdfBytes], { type: 'application/pdf' })
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      const customerCode = selectedCustomerQuery.data?.code || customerId || 'UNKNOWN'
      link.download = `SOA_${customerCode}_${pdfDates.to.replaceAll('-', '')}.pdf`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
      setPdfDialogOpen(false)
      toast({ title: 'Statement of Account generated successfully' })
    },
    onError: (error) => toast({
      title: 'Failed to generate PDF',
      description: getApiErrorMessage(error, 'The customer statement could not be generated.'),
      variant: 'destructive',
    }),
  })

  const openPdfDialog = () => {
    setPdfDates(defaultPdfDates())
    setPdfDialogOpen(true)
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Customer Account Statement</h1>
          <p className="mt-1 text-muted-foreground">
            Select a customer to review their transactions and generate a statement PDF.
          </p>
        </div>
        <Button onClick={openPdfDialog} disabled={!customerId || transactionsQuery.isLoading}>
          <Icon icon="solar:file-download-bold-duotone" className="mr-2 h-4 w-4" />
          Generate PDF
        </Button>
      </div>

      <Card>
        <CardHeader><CardTitle>Customer</CardTitle></CardHeader>
        <CardContent>
          <div className="max-w-xl">
            <CustomerSearchCombobox
              value={customerId}
              onValueChange={setCustomerId}
              placeholder="Search customer name or code..."
            />
          </div>
        </CardContent>
      </Card>

      {!customerId ? (
        <Card>
          <CardContent className="py-16 text-center text-muted-foreground">
            <Icon icon="solar:user-id-bold-duotone" className="mx-auto mb-3 h-12 w-12 opacity-50" />
            Select a customer to view their account transactions.
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardHeader>
            <div className="flex flex-wrap items-center justify-between gap-3">
              <CardTitle>Transactions</CardTitle>
              <div className="flex gap-4 text-sm">
                <span>Debit: <strong>{formatCurrency(totals.debit)}</strong></span>
                <span>Credit: <strong>{formatCurrency(totals.credit)}</strong></span>
                <span>Balance: <strong>{formatCurrency(totals.debit - totals.credit)}</strong></span>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            {transactionsQuery.isLoading ? (
              <div className="py-12 text-center text-muted-foreground">Loading transactions...</div>
            ) : transactionsQuery.isError ? (
              <div className="py-12 text-center text-destructive">Could not load customer transactions.</div>
            ) : transactions.length === 0 ? (
              <div className="py-12 text-center text-muted-foreground">No transactions found for this customer.</div>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Date</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Document</TableHead>
                      <TableHead>Due Date</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead className="text-right">Debit</TableHead>
                      <TableHead className="text-right">Credit</TableHead>
                      <TableHead className="text-right">Balance</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {transactions.map((transaction) => (
                      <TableRow key={`${transaction.transaction_type}-${transaction.transaction_id}`}>
                        <TableCell>{formatDate(transaction.transaction_date)}</TableCell>
                        <TableCell className="capitalize">{transaction.transaction_type.replaceAll('_', ' ')}</TableCell>
                        <TableCell className="font-mono">{transaction.document_number || '-'}</TableCell>
                        <TableCell>{formatDate(transaction.due_date)}</TableCell>
                        <TableCell><Badge variant="outline" className="capitalize">{transaction.status}</Badge></TableCell>
                        <TableCell className="text-right">{transaction.debit ? formatCurrency(transaction.debit) : '-'}</TableCell>
                        <TableCell className="text-right">{transaction.credit ? formatCurrency(transaction.credit) : '-'}</TableCell>
                        <TableCell className="text-right font-medium">{formatCurrency(transaction.balance)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      <Dialog open={pdfDialogOpen} onOpenChange={setPdfDialogOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Generate Customer Statement PDF</DialogTitle></DialogHeader>
          <div className="grid grid-cols-1 gap-4 py-2 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="statement-from-date">From Date</Label>
              <Input
                id="statement-from-date"
                type="date"
                value={pdfDates.from}
                max={pdfDates.to}
                onChange={(event) => setPdfDates({ ...pdfDates, from: event.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="statement-to-date">To Date</Label>
              <Input
                id="statement-to-date"
                type="date"
                value={pdfDates.to}
                min={pdfDates.from}
                onChange={(event) => setPdfDates({ ...pdfDates, to: event.target.value })}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setPdfDialogOpen(false)}>Cancel</Button>
            <Button
              onClick={() => generatePdf.mutate()}
              disabled={generatePdf.isPending || !pdfDates.from || !pdfDates.to || pdfDates.from > pdfDates.to}
            >
              {generatePdf.isPending ? 'Generating...' : 'Generate PDF'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

export default CustomerStatements
