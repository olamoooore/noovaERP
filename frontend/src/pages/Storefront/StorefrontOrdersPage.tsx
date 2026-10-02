import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import api from '@/lib/api'
import { money, prettyLabel, fmtDate } from './format'

interface OrderRow {
  id: number
  order_number: string
  email: string | null
  status: string
  payment_status: string
  subtotal: string
  total: string
  currency: string | null
  channel: string | null
  placed_at: string | null
  created_at: string | null
}

interface OrdersResponse {
  data: OrderRow[]
  meta: { page: number; total: number; per_page: number; allowed_transitions: Record<string, string[]> }
}

const STATUS_TONE: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800',
  paid: 'bg-blue-100 text-blue-800',
  processing: 'bg-indigo-100 text-indigo-800',
  shipped: 'bg-purple-100 text-purple-800',
  delivered: 'bg-emerald-100 text-emerald-800',
  completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-neutral-200 text-neutral-700',
  refunded: 'bg-rose-100 text-rose-800',
}
const PAYMENT_TONE: Record<string, string> = {
  pending_payment: 'bg-amber-100 text-amber-800',
  paid: 'bg-green-100 text-green-800',
  failed: 'bg-rose-100 text-rose-800',
  partially_refunded: 'bg-orange-100 text-orange-800',
  refunded: 'bg-rose-100 text-rose-800',
}

const ORDER_STATUSES = ['pending', 'paid', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded']
const PAYMENT_STATUSES = ['pending_payment', 'paid', 'failed', 'partially_refunded', 'refunded']

export function StorefrontOrdersPage() {
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('all')
  const [paymentStatus, setPaymentStatus] = useState('all')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['storefront-orders', page, status, paymentStatus, search],
    queryFn: async () => {
      const params: Record<string, string | number> = { page, per_page: 20 }
      if (status !== 'all') params.status = status
      if (paymentStatus !== 'all') params.payment_status = paymentStatus
      if (search) params.search = search
      const { data: resp } = await api.get('/admin/storefront/orders', { params })
      return resp as OrdersResponse
    },
  })

  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const perPage = data?.meta.per_page ?? 20
  const lastPage = Math.max(1, Math.ceil(total / perPage))

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Orders</h1>
          <p className="text-muted-foreground">Storefront orders — payments, fulfilment, refunds and the invoice they posted to.</p>
        </div>
      </div>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Filters</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex flex-wrap items-end gap-3">
            <div className="w-48">
              <label className="mb-1 block text-xs font-medium text-muted-foreground">Status</label>
              <Select value={status} onValueChange={(v) => { setStatus(v); setPage(1) }}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All statuses</SelectItem>
                  {ORDER_STATUSES.map((s) => <SelectItem key={s} value={s}>{prettyLabel(s)}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="w-48">
              <label className="mb-1 block text-xs font-medium text-muted-foreground">Payment</label>
              <Select value={paymentStatus} onValueChange={(v) => { setPaymentStatus(v); setPage(1) }}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All payments</SelectItem>
                  {PAYMENT_STATUSES.map((s) => <SelectItem key={s} value={s}>{prettyLabel(s)}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="flex-1 min-w-[220px]">
              <label className="mb-1 block text-xs font-medium text-muted-foreground">Search</label>
              <div className="flex gap-2">
                <Input
                  placeholder="Order number or email"
                  value={searchInput}
                  onChange={(e) => setSearchInput(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { setSearch(searchInput); setPage(1) } }}
                />
                <Button variant="outline" onClick={() => { setSearch(searchInput); setPage(1) }}>
                  <Icon icon="solar:magnifer-linear" className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex h-48 items-center justify-center text-muted-foreground">
              <Icon icon="solar:loading-bold-duotone" className="h-6 w-6 animate-spin" />
            </div>
          ) : isError ? (
            <div className="p-6 text-sm text-destructive">Could not load orders.</div>
          ) : rows.length === 0 ? (
            <div className="p-10 text-center text-muted-foreground">
              <Icon icon="solar:bag-cross-line-duotone" className="mx-auto mb-2 h-8 w-8" />
              No orders match these filters.
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Order</TableHead>
                  <TableHead>Placed</TableHead>
                  <TableHead>Customer</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Payment</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((o) => (
                  <TableRow key={o.id} className="cursor-pointer" onClick={() => navigate(`/storefront/orders/${o.id}`)}>
                    <TableCell className="font-medium">{o.order_number}</TableCell>
                    <TableCell className="text-muted-foreground">{fmtDate(o.placed_at ?? o.created_at)}</TableCell>
                    <TableCell>{o.email ?? '—'}</TableCell>
                    <TableCell><Badge className={STATUS_TONE[o.status] ?? ''} variant="secondary">{prettyLabel(o.status)}</Badge></TableCell>
                    <TableCell><Badge className={PAYMENT_TONE[o.payment_status] ?? ''} variant="secondary">{prettyLabel(o.payment_status)}</Badge></TableCell>
                    <TableCell className="text-right font-medium">{money(o.total, o.currency)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <div className="flex items-center justify-between text-sm text-muted-foreground">
        <span>{total} order{total === 1 ? '' : 's'}</span>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
          <span>Page {page} of {lastPage}</span>
          <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>Next</Button>
        </div>
      </div>
    </div>
  )
}

export default StorefrontOrdersPage
