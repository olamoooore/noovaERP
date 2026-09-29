import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import api from '@/lib/api'

interface OrderDetail {
  id: number
  order_number: string
  email: string
  phone: string | null
  status: string
  payment_status: string
  currency: string | null
  channel: string
  totals: Record<string, string>
  shipping_address: Record<string, string | null> | null
  billing_address: Record<string, string | null> | null
  tracking_numbers: string[]
  placed_at: string | null
  paid_at: string | null
  items: Array<Record<string, unknown>>
  history: Array<{ from: string | null; to: string; actor_type: string; note: string | null; at: string | null }>
  payment_attempts: Array<Record<string, unknown>>
  allowed_transitions: string[]
}

export function StorefrontOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const [note, setNote] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['storefront-order', id],
    queryFn: async () => {
      const { data: resp } = await api.get(`/admin/storefront/orders/${id}`)
      return resp.data as OrderDetail
    },
    enabled: Boolean(id),
  })

  const transition = useMutation({
    mutationFn: async (to: string) => {
      const { data: resp } = await api.post(`/admin/storefront/orders/${id}/status`, { status: to, note: note || undefined })
      return resp
    },
    onSuccess: () => {
      setNote('')
      queryClient.invalidateQueries({ queryKey: ['storefront-order', id] })
      queryClient.invalidateQueries({ queryKey: ['storefront-orders'] })
    },
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

  if (error || !data) {
    return (
      <div className="space-y-4">
        <h1 className="text-3xl font-bold">Order not found</h1>
        <Link className="text-sm hover:underline" to="/storefront/orders">← Back to orders</Link>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <Link className="text-sm text-muted-foreground hover:underline" to="/storefront/orders">← Storefront orders</Link>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h1 className="text-3xl font-bold">{data.order_number}</h1>
          <div className="flex items-center gap-2">
            <span className="rounded-full bg-muted px-2 py-0.5 text-xs">{data.status}</span>
            <span className="rounded-full bg-muted px-2 py-0.5 text-xs">payment: {data.payment_status}</span>
          </div>
        </div>
        <p className="text-muted-foreground">{data.email}{data.phone ? ` · ${data.phone}` : ''}</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Advance status</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-wrap items-center gap-2">
          {data.allowed_transitions.length === 0 && (
            <p className="text-sm text-muted-foreground">This order is in a terminal state.</p>
          )}
          {data.allowed_transitions.map((to) => (
            <Button key={to} size="sm" variant="outline" disabled={transition.isPending} onClick={() => transition.mutate(to)}>
              → {to}
            </Button>
          ))}
          <Input className="w-64" placeholder="Note for history (optional)" value={note} onChange={(e) => setNote(e.target.value)} />
        </CardContent>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>Items & totals</CardTitle></CardHeader>
          <CardContent>
            <table className="w-full text-sm">
              <tbody>
                {data.items.map((item, i) => (
                  <tr key={i} className="border-b">
                    <td className="py-2">{String(item.name ?? '')}</td>
                    <td className="py-2 text-right whitespace-nowrap">{String(item.quantity ?? '')} × {String(item.unit_price ?? '')}</td>
                    <td className="py-2 pl-4 text-right whitespace-nowrap">{String(item.line_total ?? '')}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                {Object.entries(data.totals).map(([k, v]) => (
                  <tr key={k} className={k === 'total' ? 'font-semibold' : ''}>
                    <td className="py-1 text-muted-foreground">{k.replace(/_/g, ' ')}</td>
                    <td className="py-1 text-right" colSpan={2}>{v} {data.currency}</td>
                  </tr>
                ))}
              </tfoot>
            </table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Status history</CardTitle></CardHeader>
          <CardContent>
            <ul className="space-y-2 text-sm">
              {data.history.map((h, i) => (
                <li key={i} className="flex items-center justify-between gap-3 border-b pb-1">
                  <span>{h.from ?? 'created'} → <strong>{h.to}</strong></span>
                  <span className="text-xs text-muted-foreground">
                    {h.actor_type}{h.at ? ` · ${h.at.slice(0, 16).replace('T', ' ')}` : ''}
                  </span>
                </li>
              ))}
            </ul>
            {data.tracking_numbers.length > 0 && (
              <p className="mt-3 text-sm">Tracking: {data.tracking_numbers.join(', ')}</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Shipping address</CardTitle></CardHeader>
          <CardContent className="text-sm whitespace-pre-line">
            {data.shipping_address
              ? [data.shipping_address.name, data.shipping_address.address1, data.shipping_address.address2,
                  data.shipping_address.city, data.shipping_address.state, data.shipping_address.postal_code,
                  data.shipping_address.country_code].filter(Boolean).join('\n')
              : 'Not provided.'}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Payment attempts</CardTitle></CardHeader>
          <CardContent className="text-sm">
            {data.payment_attempts.length === 0 ? 'None recorded.' : (
              <ul className="space-y-1">
                {data.payment_attempts.map((a, i) => (
                  <li key={i}>
                    #{String(a.attempt_no)} · {String(a.gateway)} · {String(a.status)}
                    {a.error ? ` — ${String(a.error)}` : ''}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

export default StorefrontOrderDetailPage
