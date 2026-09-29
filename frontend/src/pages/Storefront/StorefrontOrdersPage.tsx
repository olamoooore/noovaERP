import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import api from '@/lib/api'

interface OrderRow {
  id: number
  order_number: string
  email: string
  status: string
  payment_status: string
  total: string
  currency: string | null
  placed_at: string | null
  created_at: string | null
}

const STATUS_TONES: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800',
  paid: 'bg-blue-100 text-blue-800',
  processing: 'bg-indigo-100 text-indigo-800',
  shipped: 'bg-purple-100 text-purple-800',
  delivered: 'bg-emerald-100 text-emerald-800',
  completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-neutral-200 text-neutral-700',
  refunded: 'bg-rose-100 text-rose-800',
}

export function StorefrontOrdersPage() {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['storefront-orders', page, status, search],
    queryFn: async () => {
      const params: Record<string, string | number> = { page, per_page: 20 }
      if (status) params.status = status
      if (search) params.search = search
      const { data: resp } = await api.get('/admin/storefront/orders', { params })
      return resp as { data: OrderRow[]; meta: { page: number; total: number; allowed_transitions: Record<string, string[]> } }
    },
  })

  const transition = useMutation({
    mutationFn: async ({ id, to }: { id: number; to: string }) => {
      const { data: resp } = await api.post(`/admin/storefront/orders/${id}/status`, { status: to })
      return resp
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['storefront-orders'] }),
    onError: (e: unknown) => {
      const message = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      window.alert(message ?? 'That transition was rejected.')
    },
  })

  if (isLoading) {
    return (
      <div className="flex h-[50vh] items-center justify-center">
        <Icon icon="solar:loading-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const orders = data?.data ?? []
  const allowed = data?.meta?.allowed_transitions ?? {}
  const total = data?.meta?.total ?? 0
  const pages = Math.max(1, Math.ceil(total / 20))

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-3xl font-bold">Storefront Orders</h1>
        <p className="text-muted-foreground">Status changes follow the shared order state machine — invalid moves are rejected.</p>
      </div>

      <Card>
        <CardContent className="flex flex-wrap items-center gap-3 pt-6">
          <Input
            className="w-64"
            placeholder="Search order number or email…"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1) }}
          />
          <select
            className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
            value={status}
            onChange={(e) => { setStatus(e.target.value); setPage(1) }}
          >
            <option value="">All statuses</option>
            {Object.keys(STATUS_TONES).map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>
          <span className="ml-auto text-sm text-muted-foreground">{total} orders</span>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="pt-6">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left">
                <th className="py-2 pr-4 font-medium">Order</th>
                <th className="py-2 pr-4 font-medium">Customer</th>
                <th className="py-2 pr-4 font-medium">Status</th>
                <th className="py-2 pr-4 font-medium">Payment</th>
                <th className="py-2 pr-4 font-medium">Total</th>
                <th className="py-2 pr-4 font-medium">Placed</th>
                <th className="py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {orders.length === 0 && (
                <tr><td colSpan={7} className="py-6 text-center text-muted-foreground">No orders match.</td></tr>
              )}
              {orders.map((o) => (
                <tr key={o.id} className="border-b">
                  <td className="py-2 pr-4">
                    <Link className="font-medium hover:underline" to={`/storefront/orders/${o.id}`}>{o.order_number}</Link>
                  </td>
                  <td className="py-2 pr-4">{o.email}</td>
                  <td className="py-2 pr-4">
                    <span className={`rounded-full px-2 py-0.5 text-xs ${STATUS_TONES[o.status] ?? 'bg-muted'}`}>{o.status}</span>
                  </td>
                  <td className="py-2 pr-4 text-xs">{o.payment_status}</td>
                  <td className="py-2 pr-4 whitespace-nowrap">{o.total} {o.currency}</td>
                  <td className="py-2 pr-4 whitespace-nowrap text-xs">{(o.placed_at ?? o.created_at ?? '').slice(0, 10)}</td>
                  <td className="py-2">
                    <div className="flex flex-wrap gap-1">
                      {(allowed[o.status] ?? []).map((next) => (
                        <Button
                          key={next}
                          size="sm"
                          variant="outline"
                          disabled={transition.isPending}
                          onClick={() => transition.mutate({ id: o.id, to: next })}
                        >
                          → {next}
                        </Button>
                      ))}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <div className="mt-4 flex items-center gap-2">
            <Button size="sm" variant="ghost" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
            <span className="text-sm text-muted-foreground">Page {page} / {pages}</span>
            <Button size="sm" variant="ghost" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>Next</Button>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

export default StorefrontOrdersPage
