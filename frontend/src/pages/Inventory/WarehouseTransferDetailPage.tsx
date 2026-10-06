import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import api from '@/lib/api'

const statusStyles: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
  pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
  in_transit: 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300',
  completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
  cancelled: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
}

const formatDate = (value?: string | null) =>
  value ? new Date(value).toLocaleDateString() : '-'

export function WarehouseTransferDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [shipOpen, setShipOpen] = useState(false)
  const [receiveOpen, setReceiveOpen] = useState(false)
  const [quantities, setQuantities] = useState<Record<number, number>>({})

  const { data: transfer, isLoading } = useQuery({
    queryKey: ['warehouse-transfer', id],
    queryFn: async () => (await api.get(`/warehouse-transfers/${id}`)).data,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['warehouse-transfer', id] })
    queryClient.invalidateQueries({ queryKey: ['warehouse-transfers'] })
  }

  const action = useMutation({
    mutationFn: async ({ path, payload }: { path: 'submit' | 'ship' | 'receive' | 'cancel'; payload?: Record<string, unknown> }) =>
      (await api.post(`/warehouse-transfers/${id}/${path}`, payload ?? {})).data,
    onSuccess: (_data, variables) => {
      invalidate()
      setShipOpen(false)
      setReceiveOpen(false)
      const labels = { submit: 'submitted', ship: 'shipped — stock left the source warehouse', receive: 'received — stock arrived at the destination', cancel: 'cancelled' }
      toast({ title: `Transfer ${labels[variables.path]}` })
    },
    onError: (error: any) => {
      toast({
        title: 'Action failed',
        description: error.response?.data?.message || 'Please try again.',
        variant: 'destructive',
      })
    },
  })

  const remove = useMutation({
    mutationFn: () => api.delete(`/warehouse-transfers/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouse-transfers'] })
      toast({ title: 'Transfer deleted' })
      navigate('/inventory/transfers')
    },
    onError: (error: any) => {
      toast({
        title: 'Delete failed',
        description: error.response?.data?.message || 'Please try again.',
        variant: 'destructive',
      })
    },
  })

  if (isLoading) {
    return (
      <div className="flex items-center gap-2 p-8 text-muted-foreground">
        <Icon icon="eos-icons:loading" className="h-5 w-5 animate-spin text-primary" />
        Loading transfer…
      </div>
    )
  }

  if (!transfer) {
    return (
      <div className="p-8">
        <p className="text-muted-foreground">Transfer not found.</p>
        <Button variant="outline" className="mt-4" onClick={() => navigate('/inventory/transfers')}>
          Back to Warehouse Transfers
        </Button>
      </div>
    )
  }

  const items: any[] = transfer.items ?? []
  const isDraft = transfer.status === 'draft'
  const canCancel = !['completed', 'cancelled'].includes(transfer.status)
  const canDelete = ['draft', 'cancelled'].includes(transfer.status)

  const openShip = () => {
    setQuantities(Object.fromEntries(items.map((item) => [item.id, item.quantity_requested ?? 0])))
    setShipOpen(true)
  }

  const openReceive = () => {
    setQuantities(Object.fromEntries(items.map((item) => [item.id, item.quantity_sent ?? 0])))
    setReceiveOpen(true)
  }

  const submitShip = () =>
    action.mutate({
      path: 'ship',
      payload: { items: items.map((item) => ({ id: item.id, quantity_sent: quantities[item.id] ?? 0 })) },
    })

  const submitReceive = () =>
    action.mutate({
      path: 'receive',
      payload: { items: items.map((item) => ({ id: item.id, quantity_received: quantities[item.id] ?? 0 })) },
    })

  return (
    <div className="max-w-4xl space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate('/inventory/transfers')}
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-3xl font-bold">{transfer.transfer_number}</h1>
            <Badge variant="secondary" className={`capitalize ${statusStyles[transfer.status] || ''}`}>
              {String(transfer.status).replace('_', ' ')}
            </Badge>
          </div>
          <p className="mt-1 text-muted-foreground">
            {transfer.from_warehouse?.name || '-'} → {transfer.to_warehouse?.name || '-'}
          </p>
        </div>

        <div className="flex shrink-0 flex-wrap items-center gap-2 pt-8">
          {isDraft && (
            <HeaderActionButton
              variant="outline"
              icon="solar:pen-bold-duotone"
              onClick={() => navigate(`/inventory/transfers/${transfer.id}/edit`)}
            >
              Edit
            </HeaderActionButton>
          )}
          {isDraft && (
            <HeaderActionButton
              icon="solar:check-circle-bold-duotone"
              loading={action.isPending}
              disabled={action.isPending}
              onClick={() => action.mutate({ path: 'submit' })}
            >
              Submit
            </HeaderActionButton>
          )}
          {transfer.status === 'pending' && (
            <HeaderActionButton
              icon="solar:delivery-bold-duotone"
              disabled={action.isPending}
              onClick={openShip}
            >
              Ship
            </HeaderActionButton>
          )}
          {transfer.status === 'in_transit' && (
            <HeaderActionButton
              icon="solar:box-bold-duotone"
              disabled={action.isPending}
              onClick={openReceive}
            >
              Receive
            </HeaderActionButton>
          )}
          {canCancel && (
            <HeaderActionButton
              variant="outline"
              icon="solar:close-circle-bold-duotone"
              className="text-destructive hover:bg-destructive/10 hover:text-destructive"
              disabled={action.isPending}
              onClick={() => {
                if (confirm('Cancel this transfer?')) {
                  action.mutate({ path: 'cancel' })
                }
              }}
            >
              Cancel
            </HeaderActionButton>
          )}
          {canDelete && (
            <HeaderActionButton
              variant="outline"
              icon="solar:trash-bin-trash-bold-duotone"
              className="text-destructive hover:bg-destructive/10 hover:text-destructive"
              disabled={remove.isPending}
              onClick={() => {
                if (confirm('Delete this transfer? This cannot be undone.')) {
                  remove.mutate()
                }
              }}
            >
              Delete
            </HeaderActionButton>
          )}
        </div>
      </div>

      {/* Details */}
      <Card className="rounded-xl">
        <CardHeader>
          <CardTitle className="text-lg">Transfer Details</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">From</p>
              <p className="mt-0.5 font-medium">{transfer.from_warehouse?.name || '-'}</p>
            </div>
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">To</p>
              <p className="mt-0.5 font-medium">{transfer.to_warehouse?.name || '-'}</p>
            </div>
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Transfer date</p>
              <p className="mt-0.5 font-medium">{formatDate(transfer.transfer_date)}</p>
            </div>
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Expected</p>
              <p className="mt-0.5 font-medium">{formatDate(transfer.expected_date)}</p>
            </div>
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Completed</p>
              <p className="mt-0.5 font-medium">{formatDate(transfer.completed_date)}</p>
            </div>
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Created by</p>
              <p className="mt-0.5 font-medium">{transfer.created_by?.name || '-'}</p>
            </div>
          </div>
          {transfer.notes && (
            <div className="mt-5 border-t pt-4">
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Notes</p>
              <p className="mt-1 whitespace-pre-wrap text-sm">{transfer.notes}</p>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Items */}
      <Card className="rounded-xl">
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <CardTitle className="text-lg">Items</CardTitle>
          <span className="text-sm text-muted-foreground">{items.length} line{items.length === 1 ? '' : 's'}</span>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Product</TableHead>
                <TableHead>SKU</TableHead>
                <TableHead className="text-right">Requested</TableHead>
                <TableHead className="text-right">Sent</TableHead>
                <TableHead className="text-right">Received</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                    No items on this transfer.
                  </TableCell>
                </TableRow>
              ) : (
                items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="font-medium">{item.product?.name || '-'}</TableCell>
                    <TableCell className="font-mono text-xs text-muted-foreground">{item.product?.sku || '-'}</TableCell>
                    <TableCell className="text-right tabular-nums">{item.quantity_requested}</TableCell>
                    <TableCell className="text-right tabular-nums">{item.quantity_sent ?? 0}</TableCell>
                    <TableCell className="text-right tabular-nums">{item.quantity_received ?? 0}</TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {/* Ship dialog */}
      <Dialog open={shipOpen} onOpenChange={setShipOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Ship transfer</DialogTitle>
            <DialogDescription>
              Confirm the quantity leaving {transfer.from_warehouse?.name || 'the source warehouse'}.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            {items.map((item) => (
              <div key={item.id} className="flex items-center justify-between gap-4">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{item.product?.name}</p>
                  <p className="text-xs text-muted-foreground">Requested: {item.quantity_requested}</p>
                </div>
                <Input
                  type="number"
                  min={0}
                  max={item.quantity_requested}
                  value={quantities[item.id] ?? 0}
                  onChange={(e) => setQuantities({ ...quantities, [item.id]: Math.max(0, Math.min(item.quantity_requested, parseInt(e.target.value, 10) || 0)) })}
                  className="w-24 text-right"
                />
              </div>
            ))}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShipOpen(false)}>Cancel</Button>
            <HeaderActionButton loading={action.isPending} disabled={action.isPending} onClick={submitShip}>
              Confirm shipment
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Receive dialog */}
      <Dialog open={receiveOpen} onOpenChange={setReceiveOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Receive transfer</DialogTitle>
            <DialogDescription>
              Confirm the quantity arriving at {transfer.to_warehouse?.name || 'the destination warehouse'}.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            {items.map((item) => (
              <div key={item.id} className="flex items-center justify-between gap-4">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{item.product?.name}</p>
                  <p className="text-xs text-muted-foreground">Sent: {item.quantity_sent ?? 0}</p>
                </div>
                <Input
                  type="number"
                  min={0}
                  max={item.quantity_sent ?? 0}
                  value={quantities[item.id] ?? 0}
                  onChange={(e) => setQuantities({ ...quantities, [item.id]: Math.max(0, Math.min(item.quantity_sent ?? 0, parseInt(e.target.value, 10) || 0)) })}
                  className="w-24 text-right"
                />
              </div>
            ))}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setReceiveOpen(false)}>Cancel</Button>
            <HeaderActionButton loading={action.isPending} disabled={action.isPending} onClick={submitReceive}>
              Confirm receipt
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
