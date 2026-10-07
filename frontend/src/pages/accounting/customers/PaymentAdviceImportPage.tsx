import { useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { ImportPageHeader } from '@/components/import/ImportPageKit'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { formatCurrency, formatDate, getApiErrorMessage } from '@/lib/utils'

/**
 * #1001 (VC-009): customer payment advice reconciliation. Upload a bank
 * payment advice (PDF / screenshot), review the extracted lines and their
 * invoice matches, then confirm the allocations. Confirmation only creates
 * DRAFT payments — they are posted from the Payments page.
 */

export const PAYMENTS_PATH = '/accounting/customers/payments'
export const ADVICES_PATH = `${PAYMENTS_PATH}/advices`

type Money = number | string | null

export interface PaymentAdviceLine {
  invoice_number: string | null
  amount: Money
  date?: string | null
  description?: string | null
  status: string
  note?: string | null
  invoice_id: number | null
  invoice_balance: Money
  confirmed_invoice_id?: number
  confirmed_amount?: number
  payment_id?: number
}

export interface PaymentAdviceSuggestion {
  invoice_id: number
  invoice_number: string
  invoice_date: string | null
  balance: Money
  suggested_amount: Money
}

export interface PaymentAdvicePayment {
  id: number
  invoice_id: number
  payment_number: string
  amount: Money
  payment_status: string
}

export interface PaymentAdviceImport {
  id: number
  original_name: string
  bank_code: string
  extractor: string
  status: 'draft' | 'confirmed' | string
  customer_id: number | null
  customer?: { id: number; name: string } | null
  payer_name: string | null
  value_date: string | null
  currency_code: string | null
  total_amount: Money
  extracted_total: Money
  variance: Money
  bank_reference: string | null
  lines: PaymentAdviceLine[]
  suggestions: PaymentAdviceSuggestion[] | null
  warnings: string[] | null
  payments?: PaymentAdvicePayment[]
  created_at?: string
  confirmed_at?: string | null
}

interface AllocationRow {
  include: boolean
  amount: string
}

const num = (value: Money | undefined): number => {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

const isPresent = (value: Money | undefined): boolean => value !== null && value !== undefined && value !== ''

const WARNING_LABELS: Record<string, string> = {
  line_total_mismatch: 'Line total ≠ remittance',
  unknown_format: 'Unknown advice format',
  no_reference: 'No invoice reference',
  no_invoice_references: 'No invoice references',
  no_invoices_matched: 'No invoices matched',
}

const LINE_STATUS: Record<string, { label: string; className: string }> = {
  matched: { label: 'Matched', className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' },
  confirmed: { label: 'Confirmed', className: 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300' },
  unmatched: { label: 'Unmatched', className: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' },
  not_open: { label: 'Not open', className: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' },
  already_paid: { label: 'Already paid', className: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' },
  no_reference: { label: 'No reference', className: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' },
}

function LineStatusBadge({ status }: { status: string }) {
  const meta = LINE_STATUS[status] ?? { label: status, className: 'bg-muted text-muted-foreground' }
  return <Badge variant="outline" className={`border-transparent ${meta.className}`}>{meta.label}</Badge>
}

function AdviceStatusBadge({ status }: { status: string }) {
  return status === 'confirmed'
    ? <Badge variant="outline" className="border-transparent bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300">Confirmed</Badge>
    : <Badge variant="outline" className="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">Draft — review</Badge>
}

function initialLineRows(advice: PaymentAdviceImport): AllocationRow[] {
  return (advice.lines ?? []).map((line) => {
    const canAllocate = line.status === 'matched' && line.invoice_id !== null
    const amount = canAllocate && isPresent(line.invoice_balance)
      ? Math.min(num(line.amount), num(line.invoice_balance))
      : num(line.amount)
    return { include: canAllocate, amount: amount > 0 ? amount.toFixed(2) : '' }
  })
}

function initialSuggestionRows(advice: PaymentAdviceImport): AllocationRow[] {
  return (advice.suggestions ?? []).map((suggestion) => ({
    include: false,
    amount: num(suggestion.suggested_amount ?? suggestion.balance).toFixed(2),
  }))
}

function UploadCard() {
  const { toast } = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const inputRef = useRef<HTMLInputElement | null>(null)
  const [file, setFile] = useState<File | null>(null)

  const upload = useMutation({
    mutationFn: async (selected: File) => {
      const fd = new FormData()
      fd.append('file', selected)
      const response = await api.post('/payment-advices', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data.data as PaymentAdviceImport
    },
    onSuccess: (advice) => {
      queryClient.setQueryData(['payment-advice', String(advice.id)], advice)
      queryClient.invalidateQueries({ queryKey: ['payment-advices'] })
      toast({ title: 'Payment advice parsed', description: 'Review the matches before confirming.' })
      navigate(`${ADVICES_PATH}/${advice.id}`)
    },
    onError: (error) => {
      toast({
        title: 'Upload failed',
        description: getApiErrorMessage(error, 'The payment advice could not be processed.'),
        variant: 'destructive',
      })
    },
  })

  const accept = (selected?: File | null) => {
    if (!selected) return
    if (!/\.(pdf|jpe?g|png)$/i.test(selected.name)) {
      toast({ title: 'Invalid file type', description: 'Upload a PDF, JPG or PNG payment advice.', variant: 'destructive' })
      return
    }
    setFile(selected)
  }

  return (
    <Card className="rounded-xl">
      <CardHeader className="border-b">
        <CardTitle className="text-base">Upload payment advice</CardTitle>
        <p className="text-sm text-muted-foreground">
          PDF advices use their embedded text; screenshots and photos (JPG/PNG) go through OCR. Max 10 MB.
        </p>
      </CardHeader>
      <CardContent className="flex flex-wrap items-center gap-3 p-4">
        <input
          ref={inputRef}
          type="file"
          accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
          className="hidden"
          data-testid="payment-advice-file"
          onChange={(event) => {
            accept(event.target.files?.[0])
            event.currentTarget.value = ''
          }}
        />
        <HeaderActionButton variant="outline" icon="solar:folder-open-bold-duotone" onClick={() => inputRef.current?.click()}>
          {file ? 'Change file' : 'Choose file'}
        </HeaderActionButton>
        {file && <span className="text-sm">{file.name} <span className="text-muted-foreground">({(file.size / 1024).toFixed(1)} KB)</span></span>}
        <HeaderActionButton
          icon="solar:upload-bold-duotone"
          disabled={!file || upload.isPending}
          loading={upload.isPending}
          onClick={() => file && upload.mutate(file)}
        >
          Upload &amp; parse
        </HeaderActionButton>
      </CardContent>
    </Card>
  )
}

function PreviousImports() {
  const navigate = useNavigate()
  const { data, isLoading } = useQuery({
    queryKey: ['payment-advices'],
    queryFn: async () => {
      const response = await api.get('/payment-advices')
      return (response.data?.data ?? []) as PaymentAdviceImport[]
    },
  })

  return (
    <Card className="rounded-xl">
      <CardHeader className="border-b">
        <CardTitle className="text-base">Previous imports</CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        {isLoading ? (
          <div className="py-8 text-center text-muted-foreground">Loading…</div>
        ) : !data || data.length === 0 ? (
          <div className="py-8 text-center text-muted-foreground">No payment advices imported yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>#</TableHead>
                  <TableHead>File</TableHead>
                  <TableHead>Bank</TableHead>
                  <TableHead>Payer / customer</TableHead>
                  <TableHead>Value date</TableHead>
                  <TableHead className="text-right">Remitted</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((advice) => (
                  <TableRow key={advice.id} className="cursor-pointer" onClick={() => navigate(`${ADVICES_PATH}/${advice.id}`)}>
                    <TableCell>{advice.id}</TableCell>
                    <TableCell className="max-w-[220px] truncate">{advice.original_name}</TableCell>
                    <TableCell className="uppercase">{advice.bank_code}</TableCell>
                    <TableCell>{advice.customer?.name ?? advice.payer_name ?? '-'}</TableCell>
                    <TableCell>{formatDate(advice.value_date)}</TableCell>
                    <TableCell className="text-right">
                      {isPresent(advice.total_amount) ? formatCurrency(num(advice.total_amount), advice.currency_code ?? undefined) : '-'}
                    </TableCell>
                    <TableCell><AdviceStatusBadge status={advice.status} /></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="text-sm font-medium">{children}</p>
    </div>
  )
}

export function PaymentAdviceReview({ advice }: { advice: PaymentAdviceImport }) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const currency = advice.currency_code ?? undefined
  const money = (value: Money | undefined) => (isPresent(value) ? formatCurrency(num(value), currency) : '-')
  const isConfirmed = advice.status === 'confirmed'
  const lines = useMemo(() => advice.lines ?? [], [advice.lines])
  const suggestions = useMemo(() => advice.suggestions ?? [], [advice.suggestions])

  const [lineRows, setLineRows] = useState<AllocationRow[]>(() => initialLineRows(advice))
  const [suggestionRows, setSuggestionRows] = useState<AllocationRow[]>(() => initialSuggestionRows(advice))

  const allocations = useMemo(() => {
    const fromLines = lines.flatMap((line, index) => {
      const row = lineRows[index]
      if (!row?.include || line.invoice_id === null) return []
      return [{ line_index: index, invoice_id: line.invoice_id, amount: Number(row.amount) }]
    })
    // A no-reference advice has no lines: picked suggestions allocate
    // against line 0 (the advice as a whole).
    const fromSuggestions = lines.length === 0
      ? suggestions.flatMap((suggestion, index) => {
        const row = suggestionRows[index]
        if (!row?.include) return []
        return [{ line_index: 0, invoice_id: suggestion.invoice_id, amount: Number(row.amount) }]
      })
      : []
    return [...fromLines, ...fromSuggestions]
  }, [lines, suggestions, lineRows, suggestionRows])

  const allocatedTotal = allocations.reduce((sum, allocation) => sum + (Number.isFinite(allocation.amount) ? allocation.amount : 0), 0)
  const hasInvalidAmount = allocations.some((allocation) => !Number.isFinite(allocation.amount) || allocation.amount <= 0)
  const overTotal = isPresent(advice.total_amount) && allocatedTotal > num(advice.total_amount) + 0.005
  const variance = num(advice.variance)

  const confirm = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/payment-advices/${advice.id}/confirm`, { allocations })
      return response.data.data as PaymentAdviceImport
    },
    onSuccess: (updated) => {
      queryClient.setQueryData(['payment-advice', String(advice.id)], updated)
      queryClient.invalidateQueries({ queryKey: ['payment-advices'] })
      queryClient.invalidateQueries({ queryKey: ['customer-payments'] })
      toast({ title: 'Allocations confirmed', description: 'Draft payments created — post them from the Payments page.' })
    },
    onError: (error) => {
      toast({
        title: 'Could not confirm',
        description: getApiErrorMessage(error, 'The allocations were not confirmed.'),
        variant: 'destructive',
      })
    },
  })

  const updateLine = (index: number, patch: Partial<AllocationRow>) =>
    setLineRows((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  const updateSuggestion = (index: number, patch: Partial<AllocationRow>) =>
    setSuggestionRows((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)))

  return (
    <div className="space-y-6">
      <Card className="rounded-xl">
        <CardHeader className="border-b">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <CardTitle className="text-base">{advice.original_name}</CardTitle>
            <AdviceStatusBadge status={advice.status} />
          </div>
        </CardHeader>
        <CardContent className="space-y-4 p-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field label="Payer">{advice.payer_name ?? '-'}</Field>
            <Field label="Customer">{advice.customer?.name ?? 'Not resolved'}</Field>
            <Field label="Value date">{formatDate(advice.value_date)}</Field>
            <Field label="Bank reference">{advice.bank_reference ?? '-'}</Field>
            <Field label="Bank / extractor"><span className="uppercase">{advice.bank_code}</span> · {advice.extractor}</Field>
            <Field label="Remittance total">{money(advice.total_amount)}</Field>
            <Field label="Extracted line total">{money(advice.extracted_total)}</Field>
            <Field label="Variance">
              <span className={isPresent(advice.variance) && Math.abs(variance) > 0.005 ? 'text-destructive' : ''}>
                {money(advice.variance)}
              </span>
            </Field>
          </div>
          {(advice.warnings ?? []).length > 0 && (
            <div className="flex flex-wrap gap-2" aria-label="Warnings">
              {(advice.warnings ?? []).map((warning) => (
                <Badge key={warning} variant="outline" className="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                  <Icon icon="solar:danger-triangle-bold-duotone" className="mr-1 h-3.5 w-3.5" />
                  {WARNING_LABELS[warning] ?? warning}
                </Badge>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {lines.length > 0 && (
        <Card className="rounded-xl">
          <CardHeader className="border-b">
            <CardTitle className="text-base">Advice lines</CardTitle>
            <p className="text-sm text-muted-foreground">Only matched lines can be allocated; unmatched lines stay open.</p>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    {!isConfirmed && <TableHead className="w-10">Include</TableHead>}
                    <TableHead>#</TableHead>
                    <TableHead>Invoice ref.</TableHead>
                    <TableHead className="text-right">Advice amount</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Invoice balance</TableHead>
                    <TableHead className="text-right">Allocate</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {lines.map((line, index) => {
                    const row = lineRows[index]
                    const canAllocate = line.status === 'matched' && line.invoice_id !== null
                    return (
                      <TableRow key={index}>
                        {!isConfirmed && (
                          <TableCell>
                            <Checkbox
                              aria-label={`Include line ${index + 1}`}
                              checked={!!row?.include}
                              disabled={!canAllocate}
                              onCheckedChange={(checked) => updateLine(index, { include: checked === true })}
                            />
                          </TableCell>
                        )}
                        <TableCell>{index + 1}</TableCell>
                        <TableCell>
                          {line.invoice_id ? (
                            <Link className="text-primary hover:underline" to={`/invoices/${line.invoice_id}`}>{line.invoice_number}</Link>
                          ) : (line.invoice_number ?? '-')}
                          {line.note && <p className="text-xs text-muted-foreground">{line.note}</p>}
                        </TableCell>
                        <TableCell className="text-right">{money(line.amount)}</TableCell>
                        <TableCell><LineStatusBadge status={line.status} /></TableCell>
                        <TableCell className="text-right">{money(line.invoice_balance)}</TableCell>
                        <TableCell className="text-right">
                          {isConfirmed ? money(line.confirmed_amount ?? null) : (
                            <Input
                              aria-label={`Allocation amount line ${index + 1}`}
                              type="number"
                              step="0.01"
                              min="0"
                              className="ml-auto h-8 w-32 text-right"
                              value={row?.amount ?? ''}
                              disabled={!canAllocate || !row?.include}
                              onChange={(event) => updateLine(index, { amount: event.target.value })}
                            />
                          )}
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>
      )}

      {lines.length === 0 && (
        <Card className="rounded-xl">
          <CardHeader className="border-b">
            <CardTitle className="text-base">Suggested invoices</CardTitle>
            <p className="text-sm text-muted-foreground">
              This advice carries no invoice references. Pick the invoice(s) it pays — suggestions are open invoices of the resolved customer matching the remitted amount.
            </p>
          </CardHeader>
          <CardContent className="p-0">
            {suggestions.length === 0 ? (
              <div className="p-4 text-sm text-muted-foreground">
                No open invoice matches this customer and amount. Record the payment manually from the Payments page.
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    {!isConfirmed && <TableHead className="w-10">Use</TableHead>}
                    <TableHead>Invoice</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead className="text-right">Balance</TableHead>
                    <TableHead className="text-right">Allocate</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {suggestions.map((suggestion, index) => {
                    const row = suggestionRows[index]
                    return (
                      <TableRow key={suggestion.invoice_id}>
                        {!isConfirmed && (
                          <TableCell>
                            <Checkbox
                              aria-label={`Use invoice ${suggestion.invoice_number}`}
                              checked={!!row?.include}
                              onCheckedChange={(checked) => updateSuggestion(index, { include: checked === true })}
                            />
                          </TableCell>
                        )}
                        <TableCell>
                          <Link className="text-primary hover:underline" to={`/invoices/${suggestion.invoice_id}`}>{suggestion.invoice_number}</Link>
                        </TableCell>
                        <TableCell>{formatDate(suggestion.invoice_date)}</TableCell>
                        <TableCell className="text-right">{money(suggestion.balance)}</TableCell>
                        <TableCell className="text-right">
                          {isConfirmed ? '-' : (
                            <Input
                              aria-label={`Allocation amount ${suggestion.invoice_number}`}
                              type="number"
                              step="0.01"
                              min="0"
                              className="ml-auto h-8 w-32 text-right"
                              value={row?.amount ?? ''}
                              disabled={!row?.include}
                              onChange={(event) => updateSuggestion(index, { amount: event.target.value })}
                            />
                          )}
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      )}

      {isConfirmed ? (
        <Card className="rounded-xl">
          <CardHeader className="border-b">
            <CardTitle className="text-base">Draft payments created</CardTitle>
            <p className="text-sm text-muted-foreground">These payments are drafts — nothing is posted to the ledger until you post them.</p>
          </CardHeader>
          <CardContent className="space-y-4 p-4">
            {(advice.payments ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">No payments are linked to this advice.</p>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Payment</TableHead>
                    <TableHead>Invoice</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(advice.payments ?? []).map((payment) => (
                    <TableRow key={payment.id}>
                      <TableCell className="font-medium">{payment.payment_number}</TableCell>
                      <TableCell>
                        <Link className="text-primary hover:underline" to={`/invoices/${payment.invoice_id}`}>Invoice #{payment.invoice_id}</Link>
                      </TableCell>
                      <TableCell className="text-right">{money(payment.amount)}</TableCell>
                      <TableCell className="capitalize">{payment.payment_status === 'recorded' ? 'Draft' : payment.payment_status}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
            <HeaderActionButton icon="solar:card-bold-duotone" asChild>
              <Link to={`${PAYMENTS_PATH}?payment_status=recorded`}>Go to Payments to post them</Link>
            </HeaderActionButton>
          </CardContent>
        </Card>
      ) : (
        <Card className="rounded-xl">
          <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
            <div className="text-sm">
              <span className="text-muted-foreground">Allocating </span>
              <span className="font-semibold" data-testid="allocated-total">{formatCurrency(allocatedTotal, currency)}</span>
              {isPresent(advice.total_amount) && (
                <span className="text-muted-foreground"> of {money(advice.total_amount)} remitted</span>
              )}
              <span className="text-muted-foreground"> · {allocations.length} allocation(s)</span>
            </div>
            <HeaderActionButton
              icon="solar:check-circle-bold-duotone"
              disabled={allocations.length === 0 || hasInvalidAmount || overTotal || confirm.isPending}
              loading={confirm.isPending}
              onClick={() => confirm.mutate()}
            >
              Confirm allocations
            </HeaderActionButton>
            {overTotal && (
              <Alert variant="destructive" className="w-full">
                <AlertTitle>Over-allocated</AlertTitle>
                <AlertDescription>The allocations exceed the amount remitted on this advice.</AlertDescription>
              </Alert>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}

function AdviceDetail({ id }: { id: string }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['payment-advice', id],
    queryFn: async () => {
      const response = await api.get(`/payment-advices/${id}`)
      return response.data.data as PaymentAdviceImport
    },
  })

  if (isLoading) return <div className="py-8 text-center text-muted-foreground">Loading payment advice…</div>
  if (error || !data) {
    return (
      <Alert variant="destructive">
        <AlertTitle>Could not load the payment advice</AlertTitle>
        <AlertDescription>{getApiErrorMessage(error, 'Not found.')}</AlertDescription>
      </Alert>
    )
  }

  // Re-key on status so the editable rows reset after confirmation, but not
  // on every background refetch of the same draft.
  return <PaymentAdviceReview key={`${data.id}-${data.status}`} advice={data} />
}

export function PaymentAdviceImportPage() {
  const { id } = useParams<{ id?: string }>()

  return (
    <div className="mx-auto max-w-6xl space-y-6 p-6">
      <ImportPageHeader
        title={id ? `Payment advice #${id}` : 'Import payment advice'}
        subtitle="Upload a customer's bank payment advice, review the invoice matches, then confirm. Confirmation creates draft payments only."
        backTo={id ? ADVICES_PATH : PAYMENTS_PATH}
        backLabel={id ? 'All advices' : 'Back to Payments'}
      />
      {id ? (
        <AdviceDetail id={id} />
      ) : (
        <>
          <UploadCard />
          <PreviousImports />
        </>
      )}
    </div>
  )
}
