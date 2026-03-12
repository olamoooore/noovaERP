import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { WorkOrderCard } from '@/components/field-service/WorkOrderCard'
import fieldServiceApi from '@/lib/fieldServiceApi'

interface Kpis {
  open: number
  in_progress: number
  completed_today: number
  mtd_revenue: number
}

export function FieldServiceDashboard() {
  const { data, isLoading } = useQuery({
    queryKey: ['fs-dashboard'],
    queryFn: () => fieldServiceApi.getDashboard().then(r => r.data),
  })

  const kpis: Kpis = data?.kpis ?? { open: 0, in_progress: 0, completed_today: 0, mtd_revenue: 0 }
  const recentWorkOrders = data?.recent_work_orders ?? []

  const kpiCards = [
    { title: 'Open Work Orders',  value: kpis.open,            icon: 'solar:clipboard-list-bold-duotone',      color: 'text-blue-600',   bg: 'bg-blue-50' },
    { title: 'In Progress',       value: kpis.in_progress,     icon: 'solar:running-bold-duotone',             color: 'text-amber-600',  bg: 'bg-amber-50' },
    { title: 'Completed Today',   value: kpis.completed_today,icon: 'solar:check-circle-bold-duotone',         color: 'text-green-600',  bg: 'bg-green-50' },
    { title: 'MTD Revenue',       value: `$${Number(kpis.mtd_revenue).toLocaleString('en-US', { minimumFractionDigits: 2 })}`, icon: 'solar:dollar-bold-duotone', color: 'text-purple-600', bg: 'bg-purple-50' },
  ]

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Field Service</h1>
          <p className="text-muted-foreground text-sm mt-0.5">Manage work orders, technicians, and field operations.</p>
        </div>
        <Button asChild>
          <Link to="/field-service/work-orders/new">
            <Icon icon="solar:add-circle-bold-duotone" width={18} className="mr-1.5" />
            New Work Order
          </Link>
        </Button>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        {kpiCards.map(card => (
          <Card key={card.title} className="border border-border/60">
            <CardContent className="p-5 flex items-center gap-4">
              <div className={`${card.bg} ${card.color} rounded-xl p-3 flex-shrink-0`}>
                <Icon icon={card.icon} width={24} height={24} />
              </div>
              <div>
                <p className="text-xs text-muted-foreground uppercase tracking-wide">{card.title}</p>
                <p className="text-2xl font-bold mt-0.5">{isLoading ? '…' : card.value}</p>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Status breakdown + recent WOs */}
      <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
        {/* Status breakdown */}
        <Card className="border border-border/60">
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Status Breakdown</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {Object.entries(data?.status_breakdown ?? {}).map(([status, count]) => (
              <div key={status} className="flex items-center justify-between text-sm">
                <span className="capitalize text-muted-foreground">{status.replace('_', ' ')}</span>
                <span className="font-semibold">{String(count)}</span>
              </div>
            ))}
            {!data?.status_breakdown && isLoading && (
              <p className="text-sm text-muted-foreground">Loading…</p>
            )}
          </CardContent>
        </Card>

        {/* Recent work orders */}
        <div className="xl:col-span-2 space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-sm">Recent Work Orders</h2>
            <Button variant="ghost" size="sm" asChild>
              <Link to="/field-service/work-orders">View all</Link>
            </Button>
          </div>
          {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
          {recentWorkOrders.slice(0, 6).map((wo: any) => (
            <WorkOrderCard key={wo.id} workOrder={wo} />
          ))}
        </div>
      </div>

      {/* Quick links */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {[
          { label: 'Work Orders', href: '/field-service/work-orders', icon: 'solar:clipboard-list-bold-duotone' },
          { label: 'Schedule',    href: '/field-service/schedule',    icon: 'solar:calendar-bold-duotone' },
          { label: 'Technicians', href: '/field-service/technicians', icon: 'solar:users-group-two-rounded-bold-duotone' },
          { label: 'Teams',       href: '/field-service/teams',       icon: 'solar:people-nearby-bold-duotone' },
        ].map(item => (
          <Button key={item.href} variant="outline" asChild className="h-auto py-4 flex-col gap-2">
            <Link to={item.href}>
              <Icon icon={item.icon} width={24} className="text-primary" />
              <span className="text-sm">{item.label}</span>
            </Link>
          </Button>
        ))}
      </div>
    </div>
  )
}
