import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { PrintSendMenu } from '@/components/documents/PrintSendMenu'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
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

/** Current year and the four before it — enough for retrospective statements. */
const yearsFor = (current: number) => Array.from({ length: 5 }, (_, i) => current - i)

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

/** Local calendar date (YYYY-MM-DD) — never via toISOString (timezone shift). */
function toIsoDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/** First and last day of the selected month — the month picker is a preset. */
function monthWindow(year: number, monthIndex: number) {
  return {
    start: toIsoDate(new Date(year, monthIndex, 1)),
    end: toIsoDate(new Date(year, monthIndex + 1, 0)),
  }
}

export const CustomerStatements = () => {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { toast } = useToast()
  const [customerId, setCustomerId] = useState(searchParams.get('customer_id') || '')
  const now = new Date()
  const initialWindow = monthWindow(now.getFullYear(), now.getMonth())
  const [month, setMonth] = useState(String(now.getMonth()))
  const [year, setYear] = useState(String(now.getFullYear()))
  // #1018: the statement period is a custom From/To range; the month picker is
  // only a quick preset for it.
  const [startDate, setStartDate] = useState(initialWindow.start)
  const [endDate, setEndDate] = useState(initialWindow.end)
  const years = useMemo(() => yearsFor(new Date().getFullYear()), [])

  useEffect(() => {
    setCustomerId(searchParams.get('customer_id') || '')
  }, [searchParams])

  const range = useMemo(() => ({ start: startDate, end: endDate }), [startDate, endDate])
  const rangeInvalid = Boolean(startDate && endDate && endDate < startDate)

  const applyMonthPreset = (nextMonth: string, nextYear: string) => {
    const window = monthWindow(Number(nextYear), Number(nextMonth))
    setStartDate(window.start)
    setEndDate(window.end)
  }

  const transactionsQuery = useQuery({
    queryKey: ['customer-account-statement-transactions', customerId, range.start, range.end],
    queryFn: async () => {
      const response = await api.get('/accounting/customers/transactions', {
        params: {
          customer_id: customerId,
          transaction_type: 'invoice',
          start_date: range.start,
          end_date: range.end,
          per_page: 1000,
        },
      })
      return ((response.data?.data || []) as CustomerTransaction[]).filter(
        (transaction) => transaction.status === 'posted' || transaction.status === 'paid'
      )
    },
    enabled: Boolean(customerId) && !rangeInvalid,
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

  const selectedCustomer = selectedCustomerQuery.data

  // #618: the shared print/send menu renders through the existing statement
  // endpoint (which persists the statement record) and resolves its default
  // template from the selected customer's own context (#612 dimensions).
  const statementPdfPath = `/accounting/customers/${customerId}/statement?start_date=${range.start}&end_date=${range.end}`
  const statementResolutionPath = useMemo(() => {
    const params = new URLSearchParams()
    if (selectedCustomer?.legal_entity_id != null) params.set('entity_id', String(selectedCustomer.legal_entity_id))
    if (selectedCustomer?.customer_group) params.set('customer_group', String(selectedCustomer.customer_group))
    if (selectedCustomer?.language) params.set('language', String(selectedCustomer.language))
    const query = params.toString()
    return `/documents/customer_statement/resolution${query ? `?${query}` : ''}`
  }, [selectedCustomer])

  // Reuses the existing statement generation + mail service (`send_email`).
  const emailStatement = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/accounting/customers/${customerId}/statement`, {
        start_date: range.start,
        end_date: range.end,
        send_email: true,
      })
      return response.data
    },
    onSuccess: () => toast({ title: 'Statement emailed to the customer' }),
    onError: (error) => toast({
      title: 'Failed to email statement',
      description: getApiErrorMessage(error, 'The customer statement could not be sent.'),
      variant: 'destructive',
    }),
  })

  return (
    <div className="space-y-6 p-6">
      {/* One action row: customer, the statement period, and the action. The
          period is always visible (it drives both the list and the PDF) instead
          of hiding behind a dialog. */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Customer Account Statement</h1>
          <p className="mt-1 text-muted-foreground">
            Pick a customer and a date range to review their transactions and generate a statement PDF.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="w-full sm:w-60">
            <CustomerSearchCombobox
              value={customerId}
              onValueChange={setCustomerId}
              placeholder="Search customer name or code..."
              compact
            />
          </div>
          <Select value={month} onValueChange={(value) => { setMonth(value); applyMonthPreset(value, year) }}>
            <SelectTrigger className="h-8 w-[128px] rounded-full text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {MONTHS.map((label, index) => (
                <SelectItem key={label} value={String(index)}>{label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={year} onValueChange={(value) => { setYear(value); applyMonthPreset(month, value) }}>
            <SelectTrigger className="h-8 w-[96px] rounded-full text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {years.map((option) => (
                <SelectItem key={option} value={String(option)}>{option}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Input
            type="date"
            aria-label="From date"
            value={startDate}
            max={endDate || undefined}
            onChange={(event) => setStartDate(event.target.value)}
            className="h-8 w-[150px] rounded-full text-xs"
          />
          <span className="text-xs text-muted-foreground">to</span>
          <Input
            type="date"
            aria-label="To date"
            value={endDate}
            min={startDate || undefined}
            onChange={(event) => setEndDate(event.target.value)}
            className="h-8 w-[150px] rounded-full text-xs"
          />
          {customerId && !rangeInvalid && (
            <PrintSendMenu
              documentType="customer_statement"
              pdfPath={statementPdfPath}
              filename={`SOA_${selectedCustomer?.code || customerId || 'UNKNOWN'}_${range.end.split('-').join('')}.pdf`}
              title="Statement of Account"
              resolutionPath={statementResolutionPath}
              onEmail={() => {
                if (!selectedCustomer?.email) {
                  toast({
                    variant: 'destructive',
                    title: 'No email on file',
                    description: 'Add an email address to the customer before sending the statement.',
                  })
                  return
                }
                emailStatement.mutate()
              }}
            />
          )}
          {rangeInvalid && (
            <p className="basis-full text-xs text-destructive">
              The To date must be on or after the From date.
            </p>
          )}
        </div>
      </div>

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
              <CardTitle>
                Transactions
                <span className="ml-2 text-sm font-normal text-muted-foreground">
                  {formatDate(startDate)} – {formatDate(endDate)}
                </span>
              </CardTitle>
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
                      <TableRow
                        key={`${transaction.transaction_type}-${transaction.transaction_id}`}
                        role="link"
                        tabIndex={0}
                        className="cursor-pointer"
                        aria-label={`View invoice ${transaction.document_number}`}
                        onClick={() => navigate(`/accounting/customers/bills/${transaction.transaction_id}`)}
                        onKeyDown={(event) => {
                          if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault()
                            navigate(`/accounting/customers/bills/${transaction.transaction_id}`)
                          }
                        }}
                      >
                        <TableCell>{formatDate(transaction.transaction_date)}</TableCell>
                        <TableCell className="capitalize">{transaction.transaction_type.split('_').join(' ')}</TableCell>
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

    </div>
  )
}

export default CustomerStatements
