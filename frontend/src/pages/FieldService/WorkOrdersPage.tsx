import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { WorkOrderCard } from '@/components/field-service/WorkOrderCard'
import fieldServiceApi, { WorkOrderStatus, WorkOrderPriority, type WorkOrderFilters } from '@/lib/fieldServiceApi'

const STATUS_OPTIONS: { value: WorkOrderStatus | 'all'; label: string }[] = [
  { value: 'all',         label: 'All Statuses' },
  { value: 'draft',       label: 'Draft' },
  { value: 'scheduled',   label: 'Scheduled' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'on_hold',     label: 'On Hold' },
  { value: 'completed',   label: 'Completed' },
  { value: 'invoiced',    label: 'Invoiced' },
  { value: 'cancelled',   label: 'Cancelled' },
]

const PRIORITY_OPTIONS: { value: WorkOrderPriority | 'all'; label: string }[] = [
  { value: 'all',    label: 'All Priorities' },
  { value: 'low',    label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high',   label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

export function WorkOrdersPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<WorkOrderStatus | 'all'>('all')
  const [priority, setPriority] = useState<WorkOrderPriority | 'all'>('all')
  const [page, setPage] = useState(1)

  const filters: WorkOrderFilters = {
    search: search || undefined,
    status: status === 'all' ? undefined : status,
    priority: priority === 'all' ? undefined : priority,
    page,
    per_page: 20,
  }

  const { data, isLoading } = useQuery({
    queryKey: ['work-orders', filters],
    queryFn: () => fieldServiceApi.getWorkOrders(filters).then(r => r.data),
  })

  const workOrders = data?.data ?? []
  const lastPage = data?.last_page ?? 1

  return (
    <div className="p-6 space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between gap-2 flex-wrap">
        <h1 className="text-xl font-bold">Work Orders</h1>
        <Button asChild>
          <Link to="/field-service/work-orders/new">
            <Icon icon="solar:add-circle-bold-duotone" width={18} className="mr-1.5" />
            New Work Order
          </Link>
        </Button>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-2">
        <Input
          placeholder="Search work orders..."
          value={search}
          onChange={e => { setSearch(e.target.value); setPage(1) }}
          className="max-w-xs"
        />
        <Select value={status} onValueChange={v => { setStatus(v as any); setPage(1) }}>
          <SelectTrigger className="w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {STATUS_OPTIONS.map(o => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
          </SelectContent>
        </Select>
        <Select value={priority} onValueChange={v => { setPriority(v as any); setPage(1) }}>
          <SelectTrigger className="w-36">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PRIORITY_OPTIONS.map(o => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
          </SelectContent>
        </Select>
      </div>

      {/* Results */}
      {isLoading && (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="h-36 rounded-lg bg-muted animate-pulse" />
          ))}
        </div>
      )}

      {!isLoading && workOrders.length === 0 && (
        <div className="text-center py-16">
          <Icon icon="solar:clipboard-list-bold-duotone" width={48} className="mx-auto text-muted-foreground/40 mb-3" />
          <p className="text-muted-foreground">No work orders found.</p>
          <Button asChild variant="outline" className="mt-4">
            <Link to="/field-service/work-orders/new">Create the first one</Link>
          </Button>
        </div>
      )}

      {!isLoading && workOrders.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          {workOrders.map((wo: any) => <WorkOrderCard key={wo.id} workOrder={wo} />)}
        </div>
      )}

      {/* Pagination */}
      {lastPage > 1 && (
        <div className="flex items-center justify-center gap-2 mt-4">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
            <Icon icon="solar:arrow-left-bold" width={16} />
          </Button>
          <span className="text-sm text-muted-foreground">Page {page} of {lastPage}</span>
          <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage(p => p + 1)}>
            <Icon icon="solar:arrow-right-bold" width={16} />
          </Button>
        </div>
      )}
    </div>
  )
}
