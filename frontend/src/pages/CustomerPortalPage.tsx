import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

/**
 * Issue #337: public customer portal (no login) rendered at
 * /customer-portal/:token. The token is a signed, expiring, revocable
 * per-customer link issued by staff. Endpoints:
 *
 *   GET  /api/v1/customer-portal/{token}
 *   GET  /api/v1/customer-portal/{token}/statement
 *   GET  /api/v1/customer-portal/{token}/invoices/{id}/pdf
 *   GET  /api/v1/customer-portal/{token}/quotations/{id}/pdf
 *   GET  /api/v1/customer-portal/{token}/orders/{id}/pdf   (order confirmation)
 *   POST /api/v1/customer-portal/{token}/invoices/{id}/pay
 *
 * Open quotations link to the existing /quotation-accept/:token page.
 */

interface PortalInvoice {
  id: number
  invoice_number: string
  invoice_date: string | null
  due_date: string | null
  status: string
  currency_code: string | null
  total: number
  paid_amount: number
  balance: number
  can_pay: boolean
}

interface PortalQuotation {
  id: number
  quotation_number: string
  quotation_date: string | null
  valid_until: string | null
  status: string
  currency_code: string | null
  total: number
  accepted_at: string | null
  acceptance_token: string | null
}

interface PortalOrder {
  id: number
  sales_order_number: string
  order_date: string | null
  commitment_date: string | null
  status: string
  currency_code: string | null
  total: number
  customer_reference: string | null
  invoice_status: string | null
  delivery_status: string | null
}

interface PortalPayload {
  customer: { name: string; code?: string | null }
  company?: { name?: string; logo_url?: string }
  expires_at: string | null
  invoices: PortalInvoice[]
  quotations: PortalQuotation[]
  orders?: PortalOrder[]
}

interface StatementPayload {
  as_of: string
  currency: string | null
  closing_balance: number
  transactions: Array<{
    date: string
    type: string
    document_number: string
    debit: number
    credit: number
    outstanding?: number
  }>
}

const money = (n: number, code?: string | null) =>
  new Intl.NumberFormat('en-US', { style: 'currency', currency: code || 'USD', minimumFractionDigits: 2 }).format(
    Number(n) || 0,
  )

const ORDER_STATUS_LABELS: Record<string, string> = {
  confirmed: 'Confirmed',
  allocated: 'In preparation',
  fulfilled: 'Fulfilled',
}

const DELIVERY_STATUS_LABELS: Record<string, string> = {
  not_delivered: 'Not delivered',
  partially_delivered: 'Partially delivered',
  delivered: 'Delivered',
}

const INVOICE_STATUS_LABELS: Record<string, string> = {
  nothing_to_invoice: 'Nothing to invoice',
  to_invoice: 'Not invoiced',
  partially_invoiced: 'Partially invoiced',
  fully_invoiced: 'Invoiced',
}

const isHttpUrl = (url: unknown): url is string => typeof url === 'string' && /^https?:\/\//i.test(url)

async function downloadPdf(path: string, filename: string) {
  const res = await api.get(path, { responseType: 'blob' })
  const blobUrl = URL.createObjectURL(res.data as Blob)
  const a = document.createElement('a')
  a.href = blobUrl
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(blobUrl)
}

function errorStatus(error: unknown): number | undefined {
  return (error as { response?: { status?: number } } | null)?.response?.status
}

export default function CustomerPortalPage() {
  const { token } = useParams<{ token: string }>()
  const { toast } = useToast()
  const [busy, setBusy] = useState<string | null>(null)
  const [showStatement, setShowStatement] = useState(false)

  const base = `/customer-portal/${token}`

  const { data, isLoading, error } = useQuery<PortalPayload>({
    queryKey: ['customer-portal', token],
    queryFn: async () => (await api.get(base)).data,
    enabled: !!token,
    retry: false,
  })

  const statement = useQuery<StatementPayload>({
    queryKey: ['customer-portal-statement', token],
    queryFn: async () => (await api.get(`${base}/statement`)).data,
    enabled: !!token && showStatement,
    retry: false,
  })

  useEffect(() => {
    document.title = data?.customer?.name ? `${data.customer.name} — documents` : 'Customer portal'
  }, [data?.customer?.name])

  const runDownload = async (key: string, path: string, filename: string) => {
    setBusy(key)
    try {
      await downloadPdf(path, filename)
    } catch {
      toast({ variant: 'destructive', title: 'Download failed', description: 'The document could not be downloaded.' })
    } finally {
      setBusy(null)
    }
  }

  const pay = async (invoice: PortalInvoice) => {
    setBusy(`pay-${invoice.id}`)
    try {
      const res = await api.post(`${base}/invoices/${invoice.id}/pay`)
      const url = res.data?.url
      if (!isHttpUrl(url)) {
        throw new Error('invalid payment url')
      }
      window.location.assign(url)
    } catch {
      toast({
        variant: 'destructive',
        title: 'Online payment unavailable',
        description: 'Please contact us to settle this invoice.',
      })
    } finally {
      setBusy(null)
    }
  }

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50">
        <Icon icon="eos-icons:loading" className="h-12 w-12 animate-spin text-primary" />
      </div>
    )
  }

  if (error || !data) {
    const expired = errorStatus(error) === 410
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50 p-6">
        <Card className="max-w-md w-full">
          <CardContent className="py-12 text-center">
            <Icon icon="solar:danger-triangle-bold-duotone" className="h-12 w-12 text-red-500 mx-auto mb-3" />
            <h2 className="text-lg font-semibold">{expired ? 'This link has expired' : 'Invalid or expired link'}</h2>
            <p className="text-sm text-muted-foreground mt-2">
              Please contact your vendor to request a new portal link.
            </p>
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <div className="bg-white border-b">
        <div className="max-w-5xl mx-auto px-4 sm:px-6 py-4 flex items-center gap-3">
          {data.company?.logo_url ? (
            <img
              src={data.company.logo_url}
              alt={data.company?.name || 'Company logo'}
              className="h-10 max-w-[160px] object-contain"
            />
          ) : null}
          <div>
            {data.company?.name ? (
              <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {data.company.name}
              </div>
            ) : null}
            <h1 className="text-lg font-semibold">{data.customer.name}</h1>
          </div>
        </div>
      </div>

      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-6 space-y-6">
        <Card>
          <CardHeader>
            <CardTitle>Invoices</CardTitle>
          </CardHeader>
          <CardContent>
            {data.invoices.length === 0 ? (
              <p className="text-sm text-muted-foreground">No invoices.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-muted-foreground">
                      <th className="py-2 pr-3">Invoice</th>
                      <th className="py-2 pr-3">Date</th>
                      <th className="py-2 pr-3">Due</th>
                      <th className="py-2 pr-3 text-right">Total</th>
                      <th className="py-2 pr-3 text-right">Balance</th>
                      <th className="py-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.invoices.map((inv) => (
                      <tr key={inv.id} className="border-t">
                        <td className="py-2 pr-3 font-medium">{inv.invoice_number}</td>
                        <td className="py-2 pr-3">{inv.invoice_date ?? '—'}</td>
                        <td className="py-2 pr-3">{inv.due_date ?? '—'}</td>
                        <td className="py-2 pr-3 text-right">{money(inv.total, inv.currency_code)}</td>
                        <td className="py-2 pr-3 text-right">{money(inv.balance, inv.currency_code)}</td>
                        <td className="py-2 text-right whitespace-nowrap space-x-2">
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={busy !== null}
                            onClick={() =>
                              runDownload(`inv-${inv.id}`, `${base}/invoices/${inv.id}/pdf`, `${inv.invoice_number}.pdf`)
                            }
                          >
                            Download
                          </Button>
                          {inv.can_pay ? (
                            <Button size="sm" disabled={busy !== null} onClick={() => pay(inv)}>
                              Pay
                            </Button>
                          ) : (
                            <Badge variant="secondary">Paid</Badge>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Quotations</CardTitle>
          </CardHeader>
          <CardContent>
            {data.quotations.length === 0 ? (
              <p className="text-sm text-muted-foreground">No quotations.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-muted-foreground">
                      <th className="py-2 pr-3">Quotation</th>
                      <th className="py-2 pr-3">Date</th>
                      <th className="py-2 pr-3">Valid until</th>
                      <th className="py-2 pr-3">Status</th>
                      <th className="py-2 pr-3 text-right">Total</th>
                      <th className="py-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.quotations.map((q) => (
                      <tr key={q.id} className="border-t">
                        <td className="py-2 pr-3 font-medium">{q.quotation_number}</td>
                        <td className="py-2 pr-3">{q.quotation_date ?? '—'}</td>
                        <td className="py-2 pr-3">{q.valid_until ?? '—'}</td>
                        <td className="py-2 pr-3">
                          <Badge variant="outline">{q.status === 'confirmed' ? 'Accepted' : 'Open'}</Badge>
                        </td>
                        <td className="py-2 pr-3 text-right">{money(q.total, q.currency_code)}</td>
                        <td className="py-2 text-right whitespace-nowrap space-x-2">
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={busy !== null}
                            onClick={() =>
                              runDownload(`q-${q.id}`, `${base}/quotations/${q.id}/pdf`, `${q.quotation_number}.pdf`)
                            }
                          >
                            Download
                          </Button>
                          {q.acceptance_token ? (
                            <Button size="sm" asChild>
                              <Link to={`/quotation-accept/${encodeURIComponent(q.acceptance_token)}`}>
                                Review &amp; accept
                              </Link>
                            </Button>
                          ) : null}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Orders</CardTitle>
          </CardHeader>
          <CardContent>
            {(data.orders ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">No open orders.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm" aria-label="Open orders">
                  <thead>
                    <tr className="text-left text-muted-foreground">
                      <th className="py-2 pr-3">Order</th>
                      <th className="py-2 pr-3">Date</th>
                      <th className="py-2 pr-3">Status</th>
                      <th className="py-2 pr-3">Delivery</th>
                      <th className="py-2 pr-3">Invoicing</th>
                      <th className="py-2 pr-3 text-right">Total</th>
                      <th className="py-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {(data.orders ?? []).map((o) => (
                      <tr key={o.id} className="border-t">
                        <td className="py-2 pr-3 font-medium">
                          {o.sales_order_number}
                          {o.customer_reference ? (
                            <div className="text-xs text-muted-foreground">Your ref: {o.customer_reference}</div>
                          ) : null}
                        </td>
                        <td className="py-2 pr-3">{o.order_date ?? '—'}</td>
                        <td className="py-2 pr-3">
                          <Badge variant="outline">{ORDER_STATUS_LABELS[o.status] ?? o.status}</Badge>
                        </td>
                        <td className="py-2 pr-3">
                          {o.delivery_status ? (DELIVERY_STATUS_LABELS[o.delivery_status] ?? o.delivery_status) : '—'}
                        </td>
                        <td className="py-2 pr-3">
                          {o.invoice_status ? (INVOICE_STATUS_LABELS[o.invoice_status] ?? o.invoice_status) : '—'}
                        </td>
                        <td className="py-2 pr-3 text-right">{money(o.total, o.currency_code)}</td>
                        <td className="py-2 text-right whitespace-nowrap">
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={busy !== null}
                            onClick={() =>
                              runDownload(`so-${o.id}`, `${base}/orders/${o.id}/pdf`, `${o.sales_order_number}.pdf`)
                            }
                          >
                            Download
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Statement of account</CardTitle>
            {!showStatement ? (
              <Button size="sm" variant="outline" onClick={() => setShowStatement(true)}>
                Show statement
              </Button>
            ) : null}
          </CardHeader>
          {showStatement ? (
            <CardContent>
              {statement.isLoading ? (
                <p className="text-sm text-muted-foreground">Loading…</p>
              ) : statement.error || !statement.data ? (
                <p className="text-sm text-muted-foreground">The statement is not available on this link.</p>
              ) : (
                <div className="space-y-3">
                  <p className="text-sm">
                    Outstanding as of {statement.data.as_of}:{' '}
                    <span className="font-semibold">
                      {money(statement.data.closing_balance, statement.data.currency)}
                    </span>
                  </p>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="text-left text-muted-foreground">
                          <th className="py-2 pr-3">Date</th>
                          <th className="py-2 pr-3">Type</th>
                          <th className="py-2 pr-3">Document</th>
                          <th className="py-2 pr-3 text-right">Debit</th>
                          <th className="py-2 text-right">Credit</th>
                        </tr>
                      </thead>
                      <tbody>
                        {statement.data.transactions.map((t, i) => (
                          <tr key={`${t.document_number}-${i}`} className="border-t">
                            <td className="py-2 pr-3">{t.date}</td>
                            <td className="py-2 pr-3">{t.type}</td>
                            <td className="py-2 pr-3">{t.document_number}</td>
                            <td className="py-2 pr-3 text-right">{money(t.debit, statement.data?.currency)}</td>
                            <td className="py-2 text-right">{money(t.credit, statement.data?.currency)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </CardContent>
          ) : null}
        </Card>

        {data.expires_at ? (
          <p className="text-xs text-muted-foreground text-center">
            This link expires on {new Date(data.expires_at).toLocaleDateString()}.
          </p>
        ) : null}
      </div>
    </div>
  )
}
