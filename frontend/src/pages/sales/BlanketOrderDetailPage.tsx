import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, formatDate } from '@/lib/utils'
import { useAuthStore } from '@/store/authStore'
import api from '@/lib/api'
import { BLANKET_STATUS_CLASS } from '@/lib/blanketOrderStatus'

interface BlanketSummary {
  blanket: {
    id: number
    blanket_number: string
    customer_id: number
    customer_name?: string | null
    status: string
    valid_from?: string | null
    valid_to?: string | null
    currency?: string | null
  }
  lines: {
    blanket_line_id: number
    product_id: number
    product_name?: string | null
    sku?: string | null
    committed_qty: number
    released_qty: number
    remaining_qty: number
    unit_price: number
    committed_value: number
    released_value: number
    remaining_value: number
  }[]
  totals: { committed_value: number; released_value: number; remaining_value: number }
}

const qty = (value: number) => Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 })

/**
 * Issue #376 (O7) / #152: blanket order detail (GET /sales/blanket-orders/{id})
 * with the call-off release (POST /sales/blanket-orders/{id}/release). The
 * backend validates remaining quantity, validity and price; this page only
 * collects the quantities and opens the created sales order.
 */
export function BlanketOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const canWrite = useAuthStore((state) => state.hasPermission('sales.write'))
  const [releaseOpen, setReleaseOpen] = useState(false)
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [notes, setNotes] = useState('')

  const { data: summary, isLoading, isError } = useQuery({
    queryKey: ['sales-blanket-order', id],
    queryFn: async () => {
      const response = await api.get(`/sales/blanket-orders/${id}`)
      return response.data?.data as BlanketSummary
    },
    enabled: !!id,
  })

  const releaseLines = Object.entries(quantities)
    .filter(([, value]) => Number(value) > 0)
    .map(([lineId, value]) => ({ blanket_line_id: Number(lineId), quantity: Number(value) }))

  const releaseMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/sales/blanket-orders/${id}/release`, {
        lines: releaseLines,
        notes: notes.trim() || null,
      })
      return response.data
    },
    onSuccess: (result) => {
      setReleaseOpen(false)
      queryClient.invalidateQueries({ queryKey: ['sales-blanket-order', id] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast({ title: 'Release order created', description: result?.sales_order?.sales_order_number || result?.message })
      if (result?.sales_order?.id) navigate(`/sales/orders/${result.sales_order.id}`)
    },
    onError: (error: any) =>
      toast({
        title: 'Release rejected',
        description: error.response?.data?.message || 'Failed to release the order',
        variant: 'destructive',
      }),
  })

  if (isLoading) {
    return <p className="text-sm text-muted-foreground">Loading blanket order...</p>
  }

  if (isError || !summary) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-destructive">The blanket order could not be loaded.</p>
        <Button variant="outline" onClick={() => navigate('/sales/blanket-orders')}>
          Back to blanket orders
        </Button>
      </div>
    )
  }

  const { blanket, lines, totals } = summary
  const currency = blanket.currency || undefined
  const money = (value: number) => formatCurrency(Number(value ?? 0), currency)
  const releasable = blanket.status === 'active' && lines.some((line) => line.remaining_qty > 0)
  const overRemaining = lines.some((line) => Number(quantities[line.blanket_line_id] ?? 0) > line.remaining_qty)

  return (
    <div className="space-y-6">
      <Button
        variant="ghost"
        size="sm"
        className="-ml-2 text-muted-foreground"
        onClick={() => navigate('/sales/blanket-orders')}
      >
        <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
        Blanket orders
      </Button>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Blanket Order {blanket.blanket_number}</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {blanket.customer_name ?? 'Unknown customer'} · valid{' '}
            {blanket.valid_from ? formatDate(blanket.valid_from) : ''}
            {blanket.valid_to ? ` - ${formatDate(blanket.valid_to)}` : ' onwards'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Badge variant="secondary" className={BLANKET_STATUS_CLASS[blanket.status] ?? ''}>
            {blanket.status}
          </Badge>
          {canWrite && releasable && (
            <Button
              onClick={() => {
                setQuantities({})
                setNotes('')
                setReleaseOpen(true)
              }}
            >
              Release order
            </Button>
          )}
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <CardContent className="pt-6">
            <div className="text-sm text-muted-foreground">Committed</div>
            <div className="text-xl font-semibold">{money(totals.committed_value)}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-sm text-muted-foreground">Released</div>
            <div className="text-xl font-semibold">{money(totals.released_value)}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-sm text-muted-foreground">Remaining</div>
            <div className="text-xl font-semibold">{money(totals.remaining_value)}</div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Lines</CardTitle>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Product</TableHead>
                <TableHead className="text-right">Committed</TableHead>
                <TableHead className="text-right">Released</TableHead>
                <TableHead className="text-right">Remaining</TableHead>
                <TableHead className="text-right">Unit price</TableHead>
                <TableHead className="text-right">Remaining value</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {lines.map((line) => (
                <TableRow key={line.blanket_line_id} data-testid={`blanket-line-${line.blanket_line_id}`}>
                  <TableCell>
                    <div className="font-medium">{line.product_name ?? `Product #${line.product_id}`}</div>
                    {line.sku && <div className="text-xs text-muted-foreground">{line.sku}</div>}
                  </TableCell>
                  <TableCell className="text-right">{qty(line.committed_qty)}</TableCell>
                  <TableCell className="text-right">{qty(line.released_qty)}</TableCell>
                  <TableCell className="text-right">{qty(line.remaining_qty)}</TableCell>
                  <TableCell className="text-right">{money(line.unit_price)}</TableCell>
                  <TableCell className="text-right">{money(line.remaining_value)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={releaseOpen} onOpenChange={setReleaseOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Release a sales order</DialogTitle>
            <DialogDescription>
              Enter the quantity to call off per line. A sales order is created at the agreed prices.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            {lines
              .filter((line) => line.remaining_qty > 0)
              .map((line) => {
                const label = line.product_name ?? `Product #${line.product_id}`
                return (
                  <div key={line.blanket_line_id} className="grid items-end gap-2 sm:grid-cols-[2fr_1fr]">
                    <div className="text-sm">
                      <div className="font-medium">{label}</div>
                      <div className="text-xs text-muted-foreground">
                        {qty(line.remaining_qty)} remaining at {money(line.unit_price)}
                      </div>
                    </div>
                    <Input
                      type="number"
                      min={0}
                      max={line.remaining_qty}
                      step="any"
                      aria-label={`Release quantity for ${label}`}
                      value={quantities[line.blanket_line_id] ?? ''}
                      onChange={(e) =>
                        setQuantities((current) => ({ ...current, [line.blanket_line_id]: e.target.value }))
                      }
                    />
                  </div>
                )
              })}
            {overRemaining && (
              <p className="text-sm text-destructive">A quantity is above what remains on the agreement.</p>
            )}
            <div className="space-y-2">
              <Label htmlFor="release-notes">Notes</Label>
              <Textarea id="release-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setReleaseOpen(false)}>
              Back
            </Button>
            <Button
              disabled={releaseLines.length === 0 || overRemaining || releaseMutation.isPending}
              onClick={() => releaseMutation.mutate()}
            >
              {releaseMutation.isPending ? 'Releasing...' : 'Create release order'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

export default BlanketOrderDetailPage
