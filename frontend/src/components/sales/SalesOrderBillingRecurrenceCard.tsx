import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/hooks/use-toast'
import { formatDate } from '@/lib/utils'
import api from '@/lib/api'

export interface SalesOrderRecurrence {
  id: number
  sales_order_id: number
  interval: 'monthly' | 'quarterly' | 'annual' | string
  status: 'active' | 'paused' | 'cancelled' | 'completed' | string
  start_date?: string | null
  end_date?: string | null
  next_run_date?: string | null
  max_cycles?: number | null
  cycles_generated?: number | null
}

const INTERVAL_LABELS: Record<string, string> = {
  monthly: 'Monthly',
  quarterly: 'Quarterly',
  annual: 'Annual',
}

const RECURRENCE_STATUS_CLASS: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  paused: 'bg-amber-100 text-amber-800',
  cancelled: 'bg-gray-100 text-gray-700',
  completed: 'bg-blue-100 text-blue-800',
}

type RecurrenceAction = 'pause' | 'resume' | 'cancel'

/**
 * Issue #376 (O7): order-detail entry points for two existing backend
 * features that had no UI:
 *  - bill by delivered quantity (POST /sales/orders/{id}/bill-by-delivered),
 *    changeable only until the order's first invoice;
 *  - recurring orders (#151): POST /sales/orders/{id}/recurrence plus the
 *    /sales/recurrences/{id}/pause|resume|cancel lifecycle.
 * Writes are shown only to users holding sales.write (the routes' gate).
 */
export function SalesOrderBillingRecurrenceCard({
  orderId,
  billByDelivered,
  hasInvoices,
  canWrite,
  canRecur,
}: {
  orderId: number
  billByDelivered: boolean
  hasInvoices: boolean
  canWrite: boolean
  canRecur: boolean
}) {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [recurrenceOpen, setRecurrenceOpen] = useState(false)
  const [interval, setRecurrenceInterval] = useState<'monthly' | 'quarterly' | 'annual'>('monthly')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [maxCycles, setMaxCycles] = useState('')

  const recurrencesKey = ['sales-order-recurrences', String(orderId)]
  const { data: recurrences = [], isError: recurrencesFailed } = useQuery({
    queryKey: recurrencesKey,
    queryFn: async () => {
      const response = await api.get('/sales/recurrences', { params: { sales_order_id: String(orderId) } })
      return (response.data?.data ?? []) as SalesOrderRecurrence[]
    },
  })

  const showError = (title: string, error: any, fallback: string) =>
    toast({ title, description: error.response?.data?.message || fallback, variant: 'destructive' })

  const billingMutation = useMutation({
    mutationFn: async (value: boolean) => {
      const response = await api.post(`/sales/orders/${orderId}/bill-by-delivered`, { bill_by_delivered_qty: value })
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['sales-order', String(orderId)] })
      toast({ title: 'Billing policy updated', description: data?.message })
    },
    onError: (error: any) => showError('Billing policy not changed', error, 'Failed to update the billing policy'),
  })

  const createRecurrence = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/sales/orders/${orderId}/recurrence`, {
        interval,
        start_date: startDate,
        end_date: endDate || null,
        max_cycles: maxCycles ? Number(maxCycles) : null,
      })
      return response.data
    },
    onSuccess: (data) => {
      setRecurrenceOpen(false)
      queryClient.invalidateQueries({ queryKey: recurrencesKey })
      queryClient.invalidateQueries({ queryKey: ['sales-recurrences'] })
      toast({ title: 'Recurring schedule created', description: data?.message })
    },
    onError: (error: any) => showError('Schedule not created', error, 'Failed to create the recurring schedule'),
  })

  const transition = useMutation({
    mutationFn: async ({ id, action }: { id: number; action: RecurrenceAction }) => {
      const response = await api.post(`/sales/recurrences/${id}/${action}`)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: recurrencesKey })
      queryClient.invalidateQueries({ queryKey: ['sales-recurrences'] })
      toast({ title: 'Schedule updated', description: data?.message })
    },
    onError: (error: any) => showError('Schedule not updated', error, 'Failed to update the recurring schedule'),
  })

  const openRecurrenceDialog = () => {
    setRecurrenceInterval('monthly')
    setStartDate('')
    setEndDate('')
    setMaxCycles('')
    setRecurrenceOpen(true)
  }

  const billingLocked = hasInvoices || !canWrite

  return (
    <Card data-testid="sales-order-billing-recurrence">
      <CardHeader>
        <CardTitle className="text-base">Billing &amp; Recurrence</CardTitle>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="flex items-start justify-between gap-4">
          <div className="space-y-1">
            <Label htmlFor="bill-by-delivered" className="font-medium">
              Bill by delivered quantity
            </Label>
            <p className="text-sm text-muted-foreground">
              {hasInvoices
                ? 'Locked: this order already has an invoice, so its billing policy can no longer change.'
                : 'Invoice only what has been delivered instead of the full ordered quantity. Can be changed until the first invoice.'}
            </p>
          </div>
          <Switch
            id="bill-by-delivered"
            aria-label="Bill by delivered quantity"
            checked={billByDelivered}
            disabled={billingLocked || billingMutation.isPending}
            onCheckedChange={(value) => billingMutation.mutate(value)}
          />
        </div>

        <div className="space-y-3">
          <div className="flex items-center justify-between gap-4">
            <div>
              <div className="font-medium">Recurring schedule</div>
              <p className="text-sm text-muted-foreground">
                Generate a new order from this one every month, quarter or year.
              </p>
            </div>
            {canWrite && canRecur && (
              <Button variant="outline" size="sm" onClick={openRecurrenceDialog}>
                Make recurring
              </Button>
            )}
          </div>
          {recurrencesFailed && (
            <p className="text-sm text-destructive">The recurring schedules could not be loaded.</p>
          )}
          {recurrences.length === 0 && !recurrencesFailed && (
            <p className="text-sm text-muted-foreground">This order has no recurring schedule.</p>
          )}
          {recurrences.map((recurrence) => {
            const status = String(recurrence.status)
            const live = status === 'active' || status === 'paused'
            return (
              <div
                key={recurrence.id}
                data-testid={`sales-order-recurrence-${recurrence.id}`}
                className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{INTERVAL_LABELS[recurrence.interval] ?? recurrence.interval}</span>
                  <Badge variant="secondary" className={RECURRENCE_STATUS_CLASS[status] ?? ''}>
                    {status.charAt(0).toUpperCase() + status.slice(1)}
                  </Badge>
                  {recurrence.next_run_date && live && (
                    <span className="text-muted-foreground">Next order {formatDate(recurrence.next_run_date)}</span>
                  )}
                  <span className="text-muted-foreground">
                    {recurrence.cycles_generated ?? 0}
                    {recurrence.max_cycles ? ` of ${recurrence.max_cycles}` : ''} generated
                  </span>
                </div>
                {canWrite && live && (
                  <div className="flex gap-2">
                    {status === 'active' ? (
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={transition.isPending}
                        onClick={() => transition.mutate({ id: recurrence.id, action: 'pause' })}
                      >
                        Pause
                      </Button>
                    ) : (
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={transition.isPending}
                        onClick={() => transition.mutate({ id: recurrence.id, action: 'resume' })}
                      >
                        Resume
                      </Button>
                    )}
                    <Button
                      variant="outline"
                      size="sm"
                      className="text-destructive"
                      disabled={transition.isPending}
                      onClick={() => transition.mutate({ id: recurrence.id, action: 'cancel' })}
                    >
                      Cancel schedule
                    </Button>
                  </div>
                )}
              </div>
            )
          })}
        </div>
      </CardContent>

      <Dialog open={recurrenceOpen} onOpenChange={setRecurrenceOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Make this order recurring</DialogTitle>
            <DialogDescription>
              A new draft order is generated from this order on each run date until the end date or cycle count is
              reached.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="recurrence-interval">Interval</Label>
              <select
                id="recurrence-interval"
                value={interval}
                onChange={(event) => setRecurrenceInterval(event.target.value as 'monthly' | 'quarterly' | 'annual')}
                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              >
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="annual">Annual</option>
              </select>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="recurrence-start">Start date</Label>
                <Input
                  id="recurrence-start"
                  type="date"
                  value={startDate}
                  onChange={(event) => setStartDate(event.target.value)}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="recurrence-end">End date (optional)</Label>
                <Input
                  id="recurrence-end"
                  type="date"
                  value={endDate}
                  min={startDate || undefined}
                  onChange={(event) => setEndDate(event.target.value)}
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="recurrence-cycles">Number of cycles (optional)</Label>
              <Input
                id="recurrence-cycles"
                type="number"
                min={1}
                step={1}
                value={maxCycles}
                onChange={(event) => setMaxCycles(event.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRecurrenceOpen(false)}>
              Back
            </Button>
            <Button disabled={!startDate || createRecurrence.isPending} onClick={() => createRecurrence.mutate()}>
              {createRecurrence.isPending ? 'Creating...' : 'Create schedule'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
