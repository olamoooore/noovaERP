import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Link, useNavigate } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { StatCard } from '@/components/ui/stat-card'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { formatCurrency, formatDate } from '@/lib/utils'
import api from '@/lib/api'

interface DashboardData {
  summary: {
    total_products: number
    total_warehouses: number
    total_stock_value: number
    total_retail_value: number
    potential_profit: number
    low_stock_count: number
    out_of_stock_count: number
  }
  recent_movements: any[]
  movement_summary: Record<string, { count: number; total_quantity: number }>
}

const formatQty = (value: unknown) =>
  Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 })

const LOW_STOCK_BADGE = 'border-transparent bg-amber-100 font-normal text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
const OUT_OF_STOCK_BADGE = 'border-transparent bg-red-100 font-normal text-red-700 dark:bg-red-950/60 dark:text-red-300'

export function InventoryDashboard() {
  const navigate = useNavigate()
  const dashboardQuery = useQuery<DashboardData>({
    queryKey: ['inventory-dashboard'],
    queryFn: async () => {
      const res = await api.get('/inventory/dashboard')
      return res.data
    },
  })

  const lowStockQuery = useQuery({
    queryKey: ['low-stock-preview'],
    queryFn: async () => {
      const res = await api.get('/stock/low-stock', { params: { limit: 5 } })
      return res.data
    },
  })

  const movementsQuery = useQuery({
    queryKey: ['recent-movements-preview'],
    queryFn: async () => {
      const res = await api.get('/stock/movements', { params: { per_page: 5 } })
      return res.data?.data || []
    },
  })

  const summary = dashboardQuery.data?.summary
  const isLoading = dashboardQuery.isLoading
  const lowStockItems = Array.isArray(lowStockQuery.data) ? lowStockQuery.data : lowStockQuery.data?.data || []
  const recentMovements = movementsQuery.data || []

  const getMovementTypeConfig = (type: string) => {
    const configs: Record<string, { badge: string; icon: string; label: string }> = {
      in: { badge: 'bg-primary/10 text-primary border-primary/20', icon: 'solar:arrow-down-bold-duotone', label: 'Stock In' },
      out: { badge: 'bg-rose-500/10 text-rose-600 border-rose-500/20 dark:text-rose-400', icon: 'solar:arrow-up-bold-duotone', label: 'Stock Out' },
      purchase: { badge: 'bg-blue-500/10 text-blue-600 border-blue-500/20 dark:text-blue-400', icon: 'solar:cart-large-2-bold-duotone', label: 'Purchase' },
      sale: { badge: 'bg-amber-500/10 text-amber-600 border-amber-500/20 dark:text-amber-400', icon: 'solar:bag-bold-duotone', label: 'Sale' },
      adjustment: { badge: 'bg-violet-500/10 text-violet-600 border-violet-500/20 dark:text-violet-400', icon: 'solar:tuning-2-bold-duotone', label: 'Adjustment' },
      transfer_in: { badge: 'bg-cyan-500/10 text-cyan-600 border-cyan-500/20 dark:text-cyan-400', icon: 'solar:login-2-bold-duotone', label: 'Transfer In' },
      transfer_out: { badge: 'bg-orange-500/10 text-orange-600 border-orange-500/20 dark:text-orange-400', icon: 'solar:logout-2-bold-duotone', label: 'Transfer Out' },
      return: { badge: 'bg-teal-500/10 text-teal-600 border-teal-500/20 dark:text-teal-400', icon: 'solar:undo-left-bold-duotone', label: 'Return' },
    }
    return configs[type] || { badge: 'bg-muted text-muted-foreground border-transparent', icon: 'solar:question-circle-bold-duotone', label: type }
  }

  const isEmpty = isLoading ? false : (summary?.total_products || 0) === 0

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Inventory Overview</h1>
          <p className="mt-1 text-muted-foreground">Monitor your stock levels and inventory health</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <HeaderActionButton variant="outline" icon="solar:box-bold-duotone" asChild>
            <Link to="/inventory/stock">Stock Management</Link>
          </HeaderActionButton>
          <HeaderActionButton icon="solar:add-circle-bold-duotone" asChild>
            <Link to="/inventory/purchase-orders/new">New Purchase Order</Link>
          </HeaderActionButton>
        </div>
      </div>

      {/* Primary metrics */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Total products"
          value={isLoading ? '—' : summary?.total_products || 0}
          hint="Active inventory items"
          icon="solar:box-bold-duotone"
          onClick={() => navigate('/inventory/stock')}
        />
        <StatCard
          label="Warehouses"
          value={isLoading ? '—' : summary?.total_warehouses || 0}
          hint="Storage locations"
          icon="solar:buildings-bold-duotone"
          tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
          onClick={() => navigate('/inventory/warehouses')}
        />
        <StatCard
          label="Low stock"
          value={isLoading ? '—' : summary?.low_stock_count || 0}
          hint="Items below threshold"
          icon="solar:danger-triangle-bold-duotone"
          tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
          className={(summary?.low_stock_count || 0) > 0 ? 'border-amber-300 dark:border-amber-900' : undefined}
          onClick={() => navigate('/inventory/stock')}
        />
        <StatCard
          label="Out of stock"
          value={isLoading ? '—' : summary?.out_of_stock_count || 0}
          hint="Items need restocking"
          icon="solar:close-circle-bold-duotone"
          tone="bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400"
          className={(summary?.out_of_stock_count || 0) > 0 ? 'border-red-300 dark:border-red-900' : undefined}
          onClick={() => navigate('/inventory/stock')}
        />
      </div>

      {/* Secondary metrics — one bordered strip instead of three loose cards */}
      <Card className="overflow-hidden rounded-xl">
        <div className="grid grid-cols-1 gap-px bg-border sm:grid-cols-3">
          {[
            { label: 'Stock value (cost)', value: formatCurrency(summary?.total_stock_value || 0), icon: 'solar:wallet-money-bold-duotone' },
            { label: 'Retail value', value: formatCurrency(summary?.total_retail_value || 0), icon: 'solar:tag-price-bold-duotone' },
            { label: 'Potential profit', value: formatCurrency(summary?.potential_profit || 0), icon: 'solar:graph-up-bold-duotone' },
          ].map((tile) => (
            <div key={tile.label} className="flex items-center justify-between gap-3 bg-card px-5 py-4">
              <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{tile.label}</p>
                <p className="mt-1 text-xl font-semibold tabular-nums">{isLoading ? '—' : tile.value}</p>
              </div>
              <Icon icon={tile.icon} className="h-4 w-4 shrink-0 text-muted-foreground/60" />
            </div>
          ))}
        </div>
      </Card>

      {isEmpty ? (
        <Card className="rounded-xl">
          <CardContent className="flex flex-col items-center justify-center gap-1 py-16 text-center">
            <Icon icon="solar:box-bold-duotone" className="h-10 w-10 text-muted-foreground/40" />
            <p className="mt-2 text-sm font-medium">No inventory yet</p>
            <p className="text-xs text-muted-foreground">Receive stock or create a purchase order to get started.</p>
            <HeaderActionButton className="mt-3" icon="solar:add-circle-bold-duotone" asChild>
              <Link to="/inventory/purchase-orders/new">New Purchase Order</Link>
            </HeaderActionButton>
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="grid gap-6 xl:grid-cols-3">
            {/* Recent movements */}
            <Card className="overflow-hidden rounded-xl xl:col-span-2">
              <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b">
                <div>
                  <CardTitle className="text-base">Recent stock movements</CardTitle>
                  <p className="mt-1 text-sm text-muted-foreground">Latest inventory changes</p>
                </div>
                <HeaderActionButton variant="outline" icon="solar:arrow-right-bold-duotone" asChild>
                  <Link to="/inventory/stock">View all</Link>
                </HeaderActionButton>
              </CardHeader>
              <CardContent className="p-0">
                {movementsQuery.isLoading ? (
                  <div className="py-12 text-center text-muted-foreground">
                    <Icon icon="eos-icons:loading" className="mx-auto h-6 w-6 animate-spin" />
                    <p className="mt-2 text-sm">Loading movements…</p>
                  </div>
                ) : recentMovements.length === 0 ? (
                  <div className="py-12 text-center text-muted-foreground">
                    <Icon icon="solar:history-bold-duotone" className="mx-auto h-8 w-8 opacity-40" />
                    <p className="mt-2 text-sm">No stock movements yet.</p>
                  </div>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Item</TableHead>
                        <TableHead>Movement</TableHead>
                        <TableHead className="text-right">Qty</TableHead>
                        <TableHead className="text-right">Balance</TableHead>
                        <TableHead>When</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {recentMovements.map((movement: any) => {
                        const config = getMovementTypeConfig(movement.type)
                        const isIncoming = ['in', 'purchase', 'transfer_in', 'return'].includes(movement.type)
                        return (
                          <TableRow key={movement.id} className="hover:bg-muted/50">
                            <TableCell>
                              <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
                                  {movement.product?.image ? (
                                    <img src={movement.product.image} alt="" className="h-full w-full object-cover" />
                                  ) : (
                                    <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-muted-foreground" />
                                  )}
                                </div>
                                <div className="min-w-0">
                                  <div className="truncate font-medium">{movement.product?.name}</div>
                                  <div className="text-xs text-muted-foreground">{movement.product?.sku}</div>
                                </div>
                              </div>
                            </TableCell>
                            <TableCell>
                              <Badge variant="outline" className={`gap-1 whitespace-nowrap ${config.badge}`}>
                                <Icon icon={config.icon} className="h-3 w-3" />
                                {config.label}
                              </Badge>
                            </TableCell>
                            <TableCell className="text-right">
                              <span className={`font-semibold tabular-nums ${isIncoming ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                                {isIncoming ? '+' : '-'}{formatQty(movement.quantity)}
                              </span>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{formatQty(movement.balance_after)}</TableCell>
                            <TableCell className="text-sm text-muted-foreground">{formatDate(movement.created_at)}</TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>

            {/* Stock attention */}
            <Card className="overflow-hidden rounded-xl">
              <CardHeader className="border-b">
                <div className="flex items-center justify-between gap-3">
                  <CardTitle className="text-base">Stock attention</CardTitle>
                  <div className="flex items-center gap-2">
                    {lowStockItems.length > 0 && (
                      <Badge variant="outline" className={LOW_STOCK_BADGE}>
                        {lowStockItems.length}
                      </Badge>
                    )}
                    <HeaderActionButton variant="outline" icon="solar:arrow-right-bold-duotone" asChild>
                      <Link to="/inventory/stock">View all</Link>
                    </HeaderActionButton>
                  </div>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">Items at or below their reorder level</p>
              </CardHeader>
              <CardContent className="p-2">
                {lowStockQuery.isLoading ? (
                  <div className="py-12 text-center text-muted-foreground">
                    <Icon icon="eos-icons:loading" className="mx-auto h-6 w-6 animate-spin" />
                  </div>
                ) : lowStockItems.length === 0 ? (
                  <div className="flex flex-col items-center justify-center gap-1 px-4 py-12 text-center">
                    <Icon icon="solar:check-circle-bold-duotone" className="h-10 w-10 text-emerald-500/60" />
                    <p className="text-sm font-medium">Stock levels look healthy</p>
                    <p className="text-xs text-muted-foreground">No items currently need attention.</p>
                  </div>
                ) : (
                  lowStockItems.slice(0, 6).map((item: any) => (
                    <Link
                      key={item.id}
                      to="/inventory/stock"
                      className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-muted/50"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{item.name}</p>
                        <p className="text-xs text-muted-foreground">{item.sku}</p>
                      </div>
                      <Badge
                        variant="outline"
                        className={Number(item.stock_quantity) <= 0 ? OUT_OF_STOCK_BADGE : LOW_STOCK_BADGE}
                      >
                        {formatQty(item.stock_quantity)} left
                      </Badge>
                    </Link>
                  ))
                )}
              </CardContent>
            </Card>
          </div>

          {/* Quick actions */}
          <Card className="overflow-hidden rounded-xl">
            <CardHeader className="border-b">
              <CardTitle className="text-base">Quick actions</CardTitle>
              <p className="mt-1 text-sm text-muted-foreground">Common inventory operations</p>
            </CardHeader>
            <CardContent className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
              {[
                { to: '/inventory/stock', icon: 'solar:add-circle-bold-duotone', title: 'Stock In', hint: 'Add inventory', tile: 'bg-primary/10 group-hover:bg-primary/20', glyph: 'text-primary', hover: 'hover:border-primary/30 hover:bg-primary/5' },
                { to: '/inventory/stock', icon: 'solar:minus-circle-bold-duotone', title: 'Stock Out', hint: 'Remove inventory', tile: 'bg-rose-500/10 group-hover:bg-rose-500/20', glyph: 'text-rose-600 dark:text-rose-400', hover: 'hover:border-rose-500/30 hover:bg-rose-500/5' },
                { to: '/inventory/transfers/new', icon: 'solar:transfer-horizontal-bold-duotone', title: 'Transfer Stock', hint: 'Between warehouses', tile: 'bg-violet-500/10 group-hover:bg-violet-500/20', glyph: 'text-violet-600 dark:text-violet-400', hover: 'hover:border-violet-500/30 hover:bg-violet-500/5' },
                { to: '/inventory/purchase-orders/new', icon: 'solar:document-add-bold-duotone', title: 'Purchase Order', hint: 'Order from supplier', tile: 'bg-blue-500/10 group-hover:bg-blue-500/20', glyph: 'text-blue-600 dark:text-blue-400', hover: 'hover:border-blue-500/30 hover:bg-blue-500/5' },
              ].map((action) => (
                <Link
                  key={action.title}
                  to={action.to}
                  className={`group flex items-center gap-3 rounded-xl border bg-card p-3 transition-all ${action.hover}`}
                >
                  <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg transition-colors ${action.tile}`}>
                    <Icon icon={action.icon} className={`h-5 w-5 ${action.glyph}`} />
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{action.title}</p>
                    <p className="text-xs text-muted-foreground">{action.hint}</p>
                  </div>
                </Link>
              ))}
            </CardContent>
          </Card>

        </>
      )}
    </div>
  )
}
