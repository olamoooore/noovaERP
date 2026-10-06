import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Pagination } from '@/components/ui/Pagination'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatDate } from '@/lib/utils'
import { useAuthStore } from '@/store/authStore'
import api from '@/lib/api'

interface RecurrenceRow {
  id: number
  sales_order_id: number
  sales_order?: { id: number; sales_order_number: string } | null
  interval: string
  status: string
  start_date?: string | null
  end_date?: string | null
  next_run_date?: string | null
  max_cycles?: number | null
  cycles_generated?: number | null
}

interface Paginated<T> {
  data: T[]
  current_page?: number
  last_page?: number
  from?: number | null
  to?: number | null
  total?: number
}

const INTERVAL_LABELS: Record<string, string> = { monthly: 'Monthly', quarterly: 'Quarterly', annual: 'Annual' }
const STATUS_CLASS: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  paused: 'bg-amber-100 text-amber-800',
  cancelled: 'bg-gray-100 text-gray-700',
  completed: 'bg-blue-100 text-blue-800',
}

/**
 * Issue #376 (O7) / #151: the Sales menu page for recurring order schedules
 * (GET /sales/recurrences, POST /sales/recurrences/{id}/pause|resume|cancel).
 * Schedules are created from a sales order's detail page.
 */
export function RecurringOrdersPage() {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const canWrite = useAuthStore((state) => state.hasPermission('sales.write'))
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sales-recurrences', status, page],
    queryFn: async () => {
      const params: Record<string, string | number> = { page, per_page: 25 }
      if (status) params.status = status
      const response = await api.get('/sales/recurrences', { params })
      return response.data as Paginated<RecurrenceRow>
    },
  })

  const transition = useMutation({
    mutationFn: async ({ id, action }: { id: number; action: 'pause' | 'resume' | 'cancel' }) => {
      const response = await api.post(`/sales/recurrences/${id}/${action}`)
      return response.data
    },
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['sales-recurrences'] })
      queryClient.invalidateQueries({ queryKey: ['sales-order-recurrences'] })
      toast({ title: 'Schedule updated', description: result?.message })
    },
    onError: (error: any) =>
      toast({
        title: 'Schedule not updated',
        description: error.response?.data?.message || 'Failed to update the recurring schedule',
        variant: 'destructive',
      }),
  })

  const rows = data?.data ?? []

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Recurring Orders</h1>
          <p className="mt-1 text-muted-foreground">
            Schedules that generate a new sales order every month, quarter or year. Set one up from a sales order.
          </p>
        </div>
        <div className="space-y-1">
          <Label htmlFor="recurrence-status-filter">Status</Label>
          <select
            id="recurrence-status-filter"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="paused">Paused</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
      </div>

      <Card>
        <CardContent className="overflow-x-auto pt-6">
          {isError && <p className="text-sm text-destructive">The recurring schedules could not be loaded.</p>}
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Source order</TableHead>
                <TableHead>Interval</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Next order</TableHead>
                <TableHead>Generated</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading && (
                <TableRow>
                  <TableCell colSpan={6} className="text-muted-foreground">
                    Loading...
                  </TableCell>
                </TableRow>
              )}
              {!isLoading && rows.length === 0 && (
                <TableRow>
                  <TableCell colSpan={6} className="text-muted-foreground">
                    No recurring schedules.
                  </TableCell>
                </TableRow>
              )}
              {rows.map((row) => {
                const live = row.status === 'active' || row.status === 'paused'
                return (
                  <TableRow key={row.id} data-testid={`recurrence-row-${row.id}`}>
                    <TableCell>
                      <Link className="text-primary hover:underline" to={`/sales/orders/${row.sales_order_id}`}>
                        {row.sales_order?.sales_order_number ?? `#${row.sales_order_id}`}
                      </Link>
                    </TableCell>
                    <TableCell>{INTERVAL_LABELS[row.interval] ?? row.interval}</TableCell>
                    <TableCell>
                      <Badge variant="secondary" className={STATUS_CLASS[row.status] ?? ''}>
                        {row.status.charAt(0).toUpperCase() + row.status.slice(1)}
                      </Badge>
                    </TableCell>
                    <TableCell>{live && row.next_run_date ? formatDate(row.next_run_date) : '-'}</TableCell>
                    <TableCell>
                      {row.cycles_generated ?? 0}
                      {row.max_cycles ? ` of ${row.max_cycles}` : ''}
                    </TableCell>
                    <TableCell className="text-right">
                      {canWrite && live && (
                        <div className="flex justify-end gap-2">
                          {row.status === 'active' ? (
                            <Button
                              variant="outline"
                              size="sm"
                              disabled={transition.isPending}
                              onClick={() => transition.mutate({ id: row.id, action: 'pause' })}
                            >
                              Pause
                            </Button>
                          ) : (
                            <Button
                              variant="outline"
                              size="sm"
                              disabled={transition.isPending}
                              onClick={() => transition.mutate({ id: row.id, action: 'resume' })}
                            >
                              Resume
                            </Button>
                          )}
                          <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive"
                            disabled={transition.isPending}
                            onClick={() => transition.mutate({ id: row.id, action: 'cancel' })}
                          >
                            Cancel schedule
                          </Button>
                        </div>
                      )}
                    </TableCell>
                  </TableRow>
                )
              })}
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
    </div>
  )
}

export default RecurringOrdersPage
