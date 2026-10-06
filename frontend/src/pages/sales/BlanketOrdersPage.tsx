import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
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
import { Pagination } from '@/components/ui/Pagination'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import { useToast } from '@/hooks/use-toast'
import { formatDate } from '@/lib/utils'
import { useAuthStore } from '@/store/authStore'
import api from '@/lib/api'
import { BLANKET_STATUS_CLASS } from '@/lib/blanketOrderStatus'

interface BlanketRow {
  id: number
  blanket_number: string
  name: string
  status: string
  valid_from?: string | null
  valid_to?: string | null
  currency?: string | null
  customer?: { id: number; name: string } | null
  lines?: unknown[]
}

interface Paginated<T> {
  data: T[]
  current_page?: number
  last_page?: number
  from?: number | null
  to?: number | null
  total?: number
}

interface DraftLine {
  key: number
  product_id: string
  description: string
  committed_qty: string
  unit_price: string
}

let lineKey = 0
const emptyLine = (): DraftLine => ({ key: ++lineKey, product_id: '', description: '', committed_qty: '', unit_price: '' })

/**
 * Issue #376 (O7) / #152: the Sales menu page for blanket orders (call-off
 * agreements): GET /sales/blanket-orders and POST /sales/blanket-orders.
 * Creation is shown only to users holding sales.write (the route's gate).
 */
export function BlanketOrdersPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const canWrite = useAuthStore((state) => state.hasPermission('sales.write'))
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [createOpen, setCreateOpen] = useState(false)
  const [customerId, setCustomerId] = useState('')
  const [name, setName] = useState('')
  const [validFrom, setValidFrom] = useState('')
  const [validTo, setValidTo] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sales-blanket-orders', status, page],
    queryFn: async () => {
      const params: Record<string, string | number> = { page, per_page: 25 }
      if (status) params.status = status
      const response = await api.get('/sales/blanket-orders', { params })
      return response.data as Paginated<BlanketRow>
    },
  })

  const createMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/sales/blanket-orders', {
        customer_id: Number(customerId),
        name: name.trim(),
        valid_from: validFrom,
        valid_to: validTo || null,
        notes: notes.trim() || null,
        lines: lines.map((line) => ({
          product_id: Number(line.product_id),
          description: line.description || null,
          committed_qty: Number(line.committed_qty),
          unit_price: Number(line.unit_price),
        })),
      })
      return response.data
    },
    onSuccess: (result) => {
      setCreateOpen(false)
      queryClient.invalidateQueries({ queryKey: ['sales-blanket-orders'] })
      toast({ title: 'Blanket order created', description: result?.message })
      if (result?.data?.id) navigate(`/sales/blanket-orders/${result.data.id}`)
    },
    onError: (error: any) => {
      const errors = error.response?.data?.errors as Record<string, string[]> | undefined
      toast({
        title: 'Blanket order not created',
        description: (errors && Object.values(errors)[0]?.[0]) || error.response?.data?.message || 'Failed to create the blanket order',
        variant: 'destructive',
      })
    },
  })

  const openCreate = () => {
    setCustomerId('')
    setName('')
    setValidFrom('')
    setValidTo('')
    setNotes('')
    setLines([emptyLine()])
    setCreateOpen(true)
  }

  const updateLine = (key: number, patch: Partial<DraftLine>) =>
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))

  const canSubmit =
    Boolean(customerId && name.trim() && validFrom) &&
    lines.length > 0 &&
    lines.every((line) => line.product_id && Number(line.committed_qty) > 0 && line.unit_price !== '' && Number(line.unit_price) >= 0)

  const rows = data?.data ?? []

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Blanket Orders</h1>
          <p className="mt-1 text-muted-foreground">
            Call-off agreements: a committed quantity and price per product, released as sales orders over time.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <div className="space-y-1">
            <Label htmlFor="blanket-status-filter">Status</Label>
            <select
              id="blanket-status-filter"
              value={status}
              onChange={(event) => {
                setStatus(event.target.value)
                setPage(1)
              }}
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
              <option value="">All</option>
              <option value="active">Active</option>
              <option value="expired">Expired</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          {canWrite && <Button onClick={openCreate}>New blanket order</Button>}
        </div>
      </div>

      <Card>
        <CardContent className="overflow-x-auto pt-6">
          {isError && <p className="text-sm text-destructive">The blanket orders could not be loaded.</p>}
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Number</TableHead>
                <TableHead>Agreement</TableHead>
                <TableHead>Customer</TableHead>
                <TableHead>Valid</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading && (
                <TableRow>
                  <TableCell colSpan={5} className="text-muted-foreground">
                    Loading...
                  </TableCell>
                </TableRow>
              )}
              {!isLoading && rows.length === 0 && (
                <TableRow>
                  <TableCell colSpan={5} className="text-muted-foreground">
                    No blanket orders.
                  </TableCell>
                </TableRow>
              )}
              {rows.map((row) => (
                <TableRow
                  key={row.id}
                  className="cursor-pointer"
                  onClick={() => navigate(`/sales/blanket-orders/${row.id}`)}
                >
                  <TableCell className="font-medium text-primary">{row.blanket_number}</TableCell>
                  <TableCell>{row.name}</TableCell>
                  <TableCell>{row.customer?.name ?? ''}</TableCell>
                  <TableCell>
                    {row.valid_from ? formatDate(row.valid_from) : ''}
                    {row.valid_to ? ` - ${formatDate(row.valid_to)}` : ' onwards'}
                  </TableCell>
                  <TableCell>
                    <Badge variant="secondary" className={BLANKET_STATUS_CLASS[row.status] ?? ''}>
                      {row.status}
                    </Badge>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          {(data?.last_page ?? 1) > 1 && (
            <Pagination
              page={data?.current_page ?? page}
              totalPages={data?.last_page ?? 1}
              onPageChange={setPage}
              from={data?.from}
              to={data?.to}
              total={data?.total}
            />
          )}
        </CardContent>
      </Card>

      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>New blanket order</DialogTitle>
            <DialogDescription>Commit a customer to quantities and prices; release sales orders against it later.</DialogDescription>
          </DialogHeader>
          <div className="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>Customer</Label>
                <CustomerSearchCombobox value={customerId} onValueChange={(value) => setCustomerId(value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="blanket-name">Agreement name</Label>
                <Input id="blanket-name" value={name} onChange={(e) => setName(e.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="blanket-valid-from">Valid from</Label>
                <Input id="blanket-valid-from" type="date" value={validFrom} onChange={(e) => setValidFrom(e.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="blanket-valid-to">Valid to (optional)</Label>
                <Input
                  id="blanket-valid-to"
                  type="date"
                  min={validFrom || undefined}
                  value={validTo}
                  onChange={(e) => setValidTo(e.target.value)}
                />
              </div>
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label>Lines</Label>
                <Button variant="outline" size="sm" onClick={() => setLines((current) => [...current, emptyLine()])}>
                  Add line
                </Button>
              </div>
              {lines.map((line, index) => (
                <div key={line.key} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
                  <div className="space-y-1">
                    <span className="text-xs text-muted-foreground">Product</span>
                    <ProductSearchCombobox
                      value={line.product_id}
                      onValueChange={(value, product) =>
                        updateLine(line.key, {
                          product_id: value,
                          description: product?.name ?? line.description,
                          unit_price: line.unit_price || (product?.price != null ? String(product.price) : ''),
                        })
                      }
                    />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor={`blanket-qty-${line.key}`} className="text-xs text-muted-foreground">
                      Committed quantity
                    </Label>
                    <Input
                      id={`blanket-qty-${line.key}`}
                      type="number"
                      min={0}
                      step="any"
                      value={line.committed_qty}
                      onChange={(e) => updateLine(line.key, { committed_qty: e.target.value })}
                    />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor={`blanket-price-${line.key}`} className="text-xs text-muted-foreground">
                      Unit price
                    </Label>
                    <Input
                      id={`blanket-price-${line.key}`}
                      type="number"
                      min={0}
                      step="any"
                      value={line.unit_price}
                      onChange={(e) => updateLine(line.key, { unit_price: e.target.value })}
                    />
                  </div>
                  <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Remove line ${index + 1}`}
                    disabled={lines.length === 1}
                    onClick={() => setLines((current) => current.filter((candidate) => candidate.key !== line.key))}
                  >
                    Remove
                  </Button>
                </div>
              ))}
            </div>

            <div className="space-y-2">
              <Label htmlFor="blanket-notes">Notes</Label>
              <Textarea id="blanket-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCreateOpen(false)}>
              Back
            </Button>
            <Button disabled={!canSubmit || createMutation.isPending} onClick={() => createMutation.mutate()}>
              {createMutation.isPending ? 'Creating...' : 'Create agreement'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

export default BlanketOrdersPage
