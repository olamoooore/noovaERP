import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { money, prettyLabel, fmtDate } from './format'

interface OrderItem {
  id: number; product_id: number | null; variant_id: number | null; name: string; sku: string | null
  unit_price: string; quantity: string | number; tax_rate: number | string; tax_amount: string
  discount_amount: string; line_total: string; fulfilled_quantity: string | number; returned_quantity: string | number
}
interface OrderDetail {
  id: number; order_number: string; email: string | null; phone: string | null
  status: string; payment_status: string; currency: string | null; channel: string | null
  legal_entity_id: number | null; account_id: number | null; customer_id: number | null
  invoice_id: number | null; sales_order_id: number | null
  totals: { subtotal: string; discount_amount: string; shipping: string; tax: string; total: string }
  shipping_address: Record<string, unknown> | null; billing_address: Record<string, unknown> | null
  shipping_method: Record<string, unknown> | null; tracking_numbers: unknown[]
  placed_at: string | null; paid_at: string | null; cancelled_at: string | null
  items: OrderItem[]
  history: { from: string; to: string; actor_type: string; actor_id: number | null; note: string | null; at: string | null }[]
  payment_attempts: { id: number; attempt_no: number; gateway: string; transaction_ref: string | null; status: string; error: string | null }[]
  refunds: { id: number; refund_number: string; amount: string; status: string; reason: string | null; requested_at: string | null; completed_at: string | null }[]
  returns: { id: number; status: string; reason: string | null; refund_amount: string }[]
  payment_proofs: { id: number; method: string; reference: string | null; file_name: string | null; status: string; review_note: string | null; file_url: string; created_at: string | null }[]
  allowed_transitions: string[]
}

const STATUS_TONE: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800', paid: 'bg-blue-100 text-blue-800', processing: 'bg-indigo-100 text-indigo-800',
  shipped: 'bg-purple-100 text-purple-800', delivered: 'bg-emerald-100 text-emerald-800', completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-neutral-200 text-neutral-700', refunded: 'bg-rose-100 text-rose-800',
}

function errMsg(e: unknown, fallback: string): string {
  return (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? fallback
}

function addr(a: Record<string, unknown> | null): string {
  if (!a) return '—'
  const parts = ['name', 'line1', 'line2', 'city', 'state', 'postcode', 'country']
    .map((k) => a[k]).filter(Boolean)
  return parts.length ? parts.join(', ') : '—'
}

export function StorefrontOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const [statusTo, setStatusTo] = useState('')
  const [statusNote, setStatusNote] = useState('')
  const [refundOpen, setRefundOpen] = useState(false)
  const [refundAmount, setRefundAmount] = useState('')
  const [refundReason, setRefundReason] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['storefront-order', id],
    queryFn: async () => {
      const { data: resp } = await api.get(`/admin/storefront/orders/${id}`)
      return (resp as { data: OrderDetail }).data
    },
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['storefront-order', id] })
    queryClient.invalidateQueries({ queryKey: ['storefront-orders'] })
  }

  const statusMutation = useMutation({
    mutationFn: async () => (await api.post(`/admin/storefront/orders/${id}/status`, { status: statusTo, note: statusNote || null })).data,
    onSuccess: () => { toast({ title: 'Order updated', description: `Status changed to ${prettyLabel(statusTo)}.` }); setStatusTo(''); setStatusNote(''); invalidate() },
    onError: (e) => toast({ title: 'Transition rejected', description: errMsg(e, 'That status change is not allowed.'), variant: 'destructive' }),
  })

  const refundMutation = useMutation({
    mutationFn: async () => (await api.post(`/admin/storefront/orders/${id}/refund`, {
      amount: refundAmount ? Number(refundAmount) : null, reason: refundReason,
    })).data,
    onSuccess: () => { toast({ title: 'Refund issued' }); setRefundOpen(false); setRefundAmount(''); setRefundReason(''); invalidate() },
    onError: (e) => toast({ title: 'Refund failed', description: errMsg(e, 'Could not issue the refund.'), variant: 'destructive' }),
  })

  const offlineMutation = useMutation({
    mutationFn: async ({ proofId, decision }: { proofId: number; decision: 'approve' | 'reject' }) =>
      (await api.post(`/admin/storefront/orders/${id}/offline-payment`, { proof_id: proofId, decision })).data,
    onSuccess: (_d, v) => { toast({ title: `Proof ${v.decision === 'approve' ? 'approved' : 'rejected'}` }); invalidate() },
    onError: (e) => toast({ title: 'Review failed', description: errMsg(e, 'Could not review the proof.'), variant: 'destructive' }),
  })

  if (isLoading) return <div className="flex h-[50vh] items-center justify-center"><Icon icon="solar:loading-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" /></div>
  if (isError || !data) return <div className="p-6"><Button variant="outline" onClick={() => navigate('/storefront/orders')}>Back to orders</Button><p className="mt-4 text-destructive">Order not found.</p></div>

  const canRefund = ['paid', 'partially_refunded'].includes(data.payment_status)
  const pendingProofs = data.payment_proofs.filter((p) => p.status === 'pending' || p.status === 'submitted')

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <button onClick={() => navigate('/storefront/orders')} className="mb-1 flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <Icon icon="solar:alt-arrow-left-linear" className="h-4 w-4" /> Orders
          </button>
          <h1 className="text-2xl font-bold">{data.order_number}</h1>
          <div className="mt-1 flex flex-wrap items-center gap-2">
            <Badge className={STATUS_TONE[data.status] ?? ''} variant="secondary">{prettyLabel(data.status)}</Badge>
            <Badge variant="outline">{prettyLabel(data.payment_status)}</Badge>
            {data.channel && <span className="text-xs text-muted-foreground">via {data.channel}</span>}
            <span className="text-xs text-muted-foreground">Placed {fmtDate(data.placed_at)}</span>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {data.invoice_id
            ? <Button asChild variant="default"><Link to={`/invoices/${data.invoice_id}`}><Icon icon="solar:bill-list-bold-duotone" className="mr-1 h-4 w-4" /> View invoice</Link></Button>
            : <Button variant="outline" disabled><Icon icon="solar:bill-list-line-duotone" className="mr-1 h-4 w-4" /> No invoice yet</Button>}
          {canRefund && (
            <Dialog open={refundOpen} onOpenChange={setRefundOpen}>
              <DialogTrigger asChild><Button variant="outline"><Icon icon="solar:money-bag-bold-duotone" className="mr-1 h-4 w-4" /> Refund</Button></DialogTrigger>
              <DialogContent>
                <DialogHeader><DialogTitle>Refund {data.order_number}</DialogTitle></DialogHeader>
                <div className="space-y-3">
                  <div><Label>Amount ({data.currency}) — leave blank for full remaining</Label>
                    <Input type="number" step="0.01" min="0.01" value={refundAmount} onChange={(e) => setRefundAmount(e.target.value)} placeholder={data.totals.total} /></div>
                  <div><Label>Reason *</Label>
                    <Textarea value={refundReason} onChange={(e) => setRefundReason(e.target.value)} maxLength={500} placeholder="Why is this being refunded?" /></div>
                </div>
                <DialogFooter>
                  <Button variant="outline" onClick={() => setRefundOpen(false)}>Cancel</Button>
                  <Button disabled={!refundReason || refundMutation.isPending} onClick={() => refundMutation.mutate()}>
                    {refundMutation.isPending ? 'Processing…' : 'Issue refund'}</Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>
          )}
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Left: items + totals */}
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-base">Items</CardTitle></CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader><TableRow>
                  <TableHead>Product</TableHead><TableHead className="text-right">Qty</TableHead>
                  <TableHead className="text-right">Unit</TableHead><TableHead className="text-right">Tax</TableHead>
                  <TableHead className="text-right">Line total</TableHead><TableHead className="text-right">Fulfilled</TableHead>
                </TableRow></TableHeader>
                <TableBody>
                  {data.items.map((it) => (
                    <TableRow key={it.id}>
                      <TableCell><div className="font-medium">{it.name}</div>{it.sku && <div className="text-xs text-muted-foreground">{it.sku}</div>}</TableCell>
                      <TableCell className="text-right">{it.quantity}</TableCell>
                      <TableCell className="text-right">{money(it.unit_price, data.currency)}</TableCell>
                      <TableCell className="text-right">{money(it.tax_amount, data.currency)}</TableCell>
                      <TableCell className="text-right font-medium">{money(it.line_total, data.currency)}</TableCell>
                      <TableCell className="text-right text-muted-foreground">{it.fulfilled_quantity}/{it.quantity}{Number(it.returned_quantity) > 0 ? ` · ${it.returned_quantity} ret` : ''}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="grid gap-1 p-4 text-sm sm:grid-cols-2">
              <div className="space-y-1">
                <Row label="Subtotal" value={money(data.totals.subtotal, data.currency)} />
                <Row label="Discount" value={money(data.totals.discount_amount, data.currency)} />
                <Row label="Shipping" value={money(data.totals.shipping, data.currency)} />
                <Row label="Tax" value={money(data.totals.tax, data.currency)} />
                <div className="mt-1 flex justify-between border-t pt-1 font-semibold"><span>Total</span><span>{money(data.totals.total, data.currency)}</span></div>
              </div>
              <div className="space-y-1 sm:border-l sm:pl-4">
                <Row label="Customer" value={data.email ?? '—'} />
                <Row label="Phone" value={data.phone ?? '—'} />
                <Row label="Shipping to" value={addr(data.shipping_address)} />
                <Row label="Billing to" value={addr(data.billing_address)} />
                <Row label="Sales order" value={data.sales_order_id ? `#${data.sales_order_id}` : '—'} />
              </div>
            </CardContent>
          </Card>

          {/* History */}
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-base">History</CardTitle></CardHeader>
            <CardContent>
              {data.history.length === 0 ? <p className="text-sm text-muted-foreground">No status changes recorded.</p> : (
                <ol className="space-y-2">
                  {data.history.map((h, i) => (
                    <li key={i} className="flex items-start gap-2 text-sm">
                      <Icon icon="solar:clock-circle-line-duotone" className="mt-0.5 h-4 w-4 text-muted-foreground" />
                      <div><span className="font-medium">{prettyLabel(h.from)} → {prettyLabel(h.to)}</span>
                        <span className="text-muted-foreground"> · {h.actor_type}{h.actor_id ? ` #${h.actor_id}` : ''} · {fmtDate(h.at)}</span>
                        {h.note && <div className="text-muted-foreground">{h.note}</div>}</div>
                    </li>
                  ))}
                </ol>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Right: actions + payment artefacts */}
        <div className="space-y-6">
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-base">Change status</CardTitle></CardHeader>
            <CardContent className="space-y-3">
              {data.allowed_transitions.length === 0 ? <p className="text-sm text-muted-foreground">This order is in a terminal state.</p> : (
                <>
                  <Select value={statusTo} onValueChange={setStatusTo}>
                    <SelectTrigger><SelectValue placeholder="Move to…" /></SelectTrigger>
                    <SelectContent>{data.allowed_transitions.map((s) => <SelectItem key={s} value={s}>{prettyLabel(s)}</SelectItem>)}</SelectContent>
                  </Select>
                  <Textarea placeholder="Note (optional)" value={statusNote} onChange={(e) => setStatusNote(e.target.value)} maxLength={500} />
                  <Button className="w-full" disabled={!statusTo || statusMutation.isPending} onClick={() => statusMutation.mutate()}>
                    {statusMutation.isPending ? 'Updating…' : 'Apply status'}</Button>
                </>
              )}
            </CardContent>
          </Card>

          {pendingProofs.length > 0 && (
            <Card>
              <CardHeader className="pb-2"><CardTitle className="text-base">Offline payment proofs</CardTitle></CardHeader>
              <CardContent className="space-y-3">
                {pendingProofs.map((p) => (
                  <div key={p.id} className="rounded-md border p-3 text-sm">
                    <div className="flex items-center justify-between">
                      <span className="font-medium">{prettyLabel(p.method)}</span>
                      <Badge variant="outline">{prettyLabel(p.status)}</Badge>
                    </div>
                    {p.reference && <div className="text-muted-foreground">Ref: {p.reference}</div>}
                    <a href={p.file_url} target="_blank" rel="noreferrer" className="mt-1 inline-flex items-center gap-1 text-primary hover:underline">
                      <Icon icon="solar:file-download-line-duotone" className="h-4 w-4" /> {p.file_name ?? 'Download proof'}</a>
                    <div className="mt-2 flex gap-2">
                      <Button size="sm" disabled={offlineMutation.isPending} onClick={() => offlineMutation.mutate({ proofId: p.id, decision: 'approve' })}>Approve</Button>
                      <Button size="sm" variant="outline" disabled={offlineMutation.isPending} onClick={() => offlineMutation.mutate({ proofId: p.id, decision: 'reject' })}>Reject</Button>
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>
          )}

          {data.refunds.length > 0 && (
            <Card>
              <CardHeader className="pb-2"><CardTitle className="text-base">Refunds</CardTitle></CardHeader>
              <CardContent className="space-y-2 text-sm">
                {data.refunds.map((r) => (
                  <div key={r.id} className="flex items-center justify-between">
                    <span>{r.refund_number} <Badge variant="outline" className="ml-1">{prettyLabel(r.status)}</Badge></span>
                    <span className="font-medium">{money(r.amount, data.currency)}</span>
                  </div>
                ))}
              </CardContent>
            </Card>
          )}

          {data.returns.length > 0 && (
            <Card>
              <CardHeader className="pb-2"><CardTitle className="text-base">Returns</CardTitle></CardHeader>
              <CardContent className="space-y-2 text-sm">
                {data.returns.map((r) => (
                  <Link key={r.id} to="/storefront/returns" className="flex items-center justify-between hover:underline">
                    <span>Return #{r.id} <Badge variant="outline" className="ml-1">{prettyLabel(r.status)}</Badge></span>
                    <span className="font-medium">{money(r.refund_amount, data.currency)}</span>
                  </Link>
                ))}
              </CardContent>
            </Card>
          )}

          {data.payment_attempts.length > 0 && (
            <Card>
              <CardHeader className="pb-2"><CardTitle className="text-base">Payment attempts</CardTitle></CardHeader>
              <CardContent className="space-y-2 text-sm">
                {data.payment_attempts.map((a) => (
                  <div key={a.id} className="flex items-center justify-between">
                    <span>#{a.attempt_no} {a.gateway}</span>
                    <Badge variant="outline">{prettyLabel(a.status)}</Badge>
                  </div>
                ))}
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return <div className="flex justify-between gap-4"><span className="text-muted-foreground">{label}</span><span className="text-right">{value}</span></div>
}

export default StorefrontOrderDetailPage
