import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Link } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { formatCurrency } from '@/lib/utils'
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

export function InventoryDashboard() {
  const { data, isLoading } = useQuery<DashboardData>({
    queryKey: ['inventory-dashboard'],
    queryFn: async () => {
      const res = await api.get('/inventory/dashboard')
      return res.data
    },
  })

  const { data: lowStockData } = useQuery({
    queryKey: ['low-stock-preview'],
    queryFn: async () => {
      const res = await api.get('/stock/low-stock', { params: { limit: 5 } })
      return res.data
    },
  })

  const { data: recentMovements } = useQuery({
    queryKey: ['recent-movements-preview'],
    queryFn: async () => {
      const res = await api.get('/stock/movements', { params: { per_page: 5 } })
      return res.data?.data || []
    },
  })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="flex items-center gap-2 text-muted-foreground">
          <Icon icon="solar:refresh-bold" className="h-5 w-5 animate-spin" />
          Loading dashboard...
        </div>
      </div>
    )
  }

  const summary = data?.summary

  const getMovementTypeConfig = (type: string) => {
    const configs: Record<string, { color: string; icon: string; bg: string }> = {
      in: { color: 'text-primary', icon: 'solar:arrow-down-bold', bg: 'bg-primary/10' },
      out: { color: 'text-rose-600', icon: 'solar:arrow-up-bold', bg: 'bg-rose-500/10' },
      purchase: { color: 'text-blue-600', icon: 'solar:cart-large-2-bold', bg: 'bg-blue-500/10' },
      sale: { color: 'text-amber-600', icon: 'solar:bag-bold', bg: 'bg-amber-500/10' },
      adjustment: { color: 'text-violet-600', icon: 'solar:tuning-2-bold', bg: 'bg-violet-500/10' },
      transfer_in: { color: 'text-cyan-600', icon: 'solar:login-2-bold', bg: 'bg-cyan-500/10' },
      transfer_out: { color: 'text-orange-600', icon: 'solar:logout-2-bold', bg: 'bg-orange-500/10' },
    }
    return configs[type] || { color: 'text-gray-600', icon: 'solar:question-circle-bold', bg: 'bg-gray-500/10' }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Inventory Overview</h1>
          <p className="text-muted-foreground mt-1">Monitor your stock levels and inventory health</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" asChild>
            <Link to="/inventory/stock">
              <Icon icon="solar:box-bold" className="mr-2 h-4 w-4" />
              Stock Management
            </Link>
          </Button>
          <Button asChild>
            <Link to="/inventory/purchase-orders/new">
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              New Purchase Order
            </Link>
          </Button>
        </div>
      </div>

      {/* Main Stats Grid */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {/* Total Products */}
        <Card className="relative overflow-hidden group hover:shadow-lg transition-shadow">
          <div className="absolute right-0 top-0 h-32 w-32 translate-x-8 -translate-y-8">
            <div className="absolute h-full w-full rounded-full bg-gradient-to-br from-blue-500/20 to-blue-500/5" />
          </div>
          <CardHeader className="pb-2">
            <div className="flex items-center justify-between">
              <CardDescription className="font-medium">Total Products</CardDescription>
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/10">
                <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-blue-600" />
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <div className="text-3xl font-bold">{summary?.total_products || 0}</div>
            <p className="text-xs text-muted-foreground mt-1">Active inventory items</p>
          </CardContent>
        </Card>

        {/* Warehouses */}
        <Card className="relative overflow-hidden group hover:shadow-lg transition-shadow">
          <div className="absolute right-0 top-0 h-32 w-32 translate-x-8 -translate-y-8">
            <div className="absolute h-full w-full rounded-full bg-gradient-to-br from-violet-500/20 to-violet-500/5" />
          </div>
          <CardHeader className="pb-2">
            <div className="flex items-center justify-between">
              <CardDescription className="font-medium">Warehouses</CardDescription>
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10">
                <Icon icon="solar:buildings-bold-duotone" className="h-5 w-5 text-violet-600" />
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <div className="text-3xl font-bold">{summary?.total_warehouses || 0}</div>
            <Link to="/inventory/warehouses" className="text-xs text-primary hover:underline mt-1 inline-block">
              Manage warehouses →
            </Link>
          </CardContent>
        </Card>

        {/* Low Stock Alert */}
        <Card className={`relative overflow-hidden group hover:shadow-lg transition-shadow ${(summary?.low_stock_count || 0) > 0 ? 'border-amber-500/50' : ''}`}>
          <div className="absolute right-0 top-0 h-32 w-32 translate-x-8 -translate-y-8">
            <div className="absolute h-full w-full rounded-full bg-gradient-to-br from-amber-500/20 to-amber-500/5" />
          </div>
          <CardHeader className="pb-2">
            <div className="flex items-center justify-between">
              <CardDescription className="font-medium flex items-center gap-2">
                {(summary?.low_stock_count || 0) > 0 && (
                  <span className="relative flex h-2 w-2">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                    <span className="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                  </span>
                )}
                Low Stock
              </CardDescription>
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10">
                <Icon icon="solar:danger-triangle-bold-duotone" className="h-5 w-5 text-amber-600" />
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <div className={`text-3xl font-bold ${(summary?.low_stock_count || 0) > 0 ? 'text-amber-600' : ''}`}>
              {summary?.low_stock_count || 0}
            </div>
            <Link to="/inventory/stock" className="text-xs text-primary hover:underline mt-1 inline-block">
              View low stock items →
            </Link>
          </CardContent>
        </Card>

        {/* Out of Stock Alert */}
        <Card className={`relative overflow-hidden group hover:shadow-lg transition-shadow ${(summary?.out_of_stock_count || 0) > 0 ? 'border-rose-500/50' : ''}`}>
          <div className="absolute right-0 top-0 h-32 w-32 translate-x-8 -translate-y-8">
            <div className="absolute h-full w-full rounded-full bg-gradient-to-br from-rose-500/20 to-rose-500/5" />
          </div>
          <CardHeader className="pb-2">
            <div className="flex items-center justify-between">
              <CardDescription className="font-medium flex items-center gap-2">
                {(summary?.out_of_stock_count || 0) > 0 && (
                  <span className="relative flex h-2 w-2">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                    <span className="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                  </span>
                )}
                Out of Stock
              </CardDescription>
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-500/10">
                <Icon icon="solar:close-circle-bold-duotone" className="h-5 w-5 text-rose-600" />
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <div className={`text-3xl font-bold ${(summary?.out_of_stock_count || 0) > 0 ? 'text-rose-600' : ''}`}>
              {summary?.out_of_stock_count || 0}
            </div>
            <Link to="/inventory/stock" className="text-xs text-primary hover:underline mt-1 inline-block">
              View out of stock →
            </Link>
          </CardContent>
        </Card>
      </div>

      {/* Inventory Value Cards */}
      <div className="grid gap-4 md:grid-cols-3">
        <Card className="bg-gradient-to-br from-slate-900 to-slate-800 text-white">
          <CardHeader className="pb-2">
            <CardDescription className="text-slate-300">Stock Value (Cost)</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="text-3xl font-bold">{formatCurrency(summary?.total_stock_value || 0)}</div>
            <p className="text-xs text-slate-400 mt-1">Total inventory at cost price</p>
          </CardContent>
        </Card>

        <Card className="bg-gradient-to-br from-primary to-primary/80 text-white">
          <CardHeader className="pb-2">
            <CardDescription className="text-primary-foreground/80">Retail Value</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="text-3xl font-bold">{formatCurrency(summary?.total_retail_value || 0)}</div>
            <p className="text-xs text-primary-foreground/70 mt-1">Total inventory at selling price</p>
          </CardContent>
        </Card>

        <Card className="bg-gradient-to-br from-blue-600 to-blue-700 text-white">
          <CardHeader className="pb-2">
            <CardDescription className="text-blue-100">Potential Profit</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="text-3xl font-bold">{formatCurrency(summary?.potential_profit || 0)}</div>
            <p className="text-xs text-blue-200 mt-1">If all stock is sold</p>
          </CardContent>
        </Card>
      </div>

      {/* Quick Actions & Recent Activity */}
      <div className="grid gap-6 lg:grid-cols-2">
        {/* Quick Actions */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Icon icon="solar:bolt-bold-duotone" className="h-5 w-5 text-amber-500" />
              Quick Actions
            </CardTitle>
            <CardDescription>Common inventory operations</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-3 sm:grid-cols-2">
            <Link to="/inventory/stock" className="group">
              <div className="flex items-center gap-4 p-4 rounded-xl border bg-card hover:bg-primary/5 hover:border-primary/30 transition-all">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 group-hover:bg-primary/20 transition-colors">
                  <Icon icon="solar:add-circle-bold-duotone" className="h-6 w-6 text-primary" />
                </div>
                <div>
                  <p className="font-medium">Stock In</p>
                  <p className="text-xs text-muted-foreground">Add inventory</p>
                </div>
              </div>
            </Link>

            <Link to="/inventory/stock" className="group">
              <div className="flex items-center gap-4 p-4 rounded-xl border bg-card hover:bg-rose-500/5 hover:border-rose-500/30 transition-all">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-rose-500/10 group-hover:bg-rose-500/20 transition-colors">
                  <Icon icon="solar:minus-circle-bold-duotone" className="h-6 w-6 text-rose-600" />
                </div>
                <div>
                  <p className="font-medium">Stock Out</p>
                  <p className="text-xs text-muted-foreground">Remove inventory</p>
                </div>
              </div>
            </Link>

            <Link to="/inventory/transfers/new" className="group">
              <div className="flex items-center gap-4 p-4 rounded-xl border bg-card hover:bg-violet-500/5 hover:border-violet-500/30 transition-all">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-violet-500/10 group-hover:bg-violet-500/20 transition-colors">
                  <Icon icon="solar:transfer-horizontal-bold-duotone" className="h-6 w-6 text-violet-600" />
                </div>
                <div>
                  <p className="font-medium">Transfer Stock</p>
                  <p className="text-xs text-muted-foreground">Between warehouses</p>
                </div>
              </div>
            </Link>

            <Link to="/inventory/purchase-orders/new" className="group">
              <div className="flex items-center gap-4 p-4 rounded-xl border bg-card hover:bg-blue-500/5 hover:border-blue-500/30 transition-all">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-500/10 group-hover:bg-blue-500/20 transition-colors">
                  <Icon icon="solar:document-add-bold-duotone" className="h-6 w-6 text-blue-600" />
                </div>
                <div>
                  <p className="font-medium">Purchase Order</p>
                  <p className="text-xs text-muted-foreground">Order from supplier</p>
                </div>
              </div>
            </Link>
          </CardContent>
        </Card>

        {/* Low Stock Items Preview */}
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="flex items-center gap-2">
                  <Icon icon="solar:danger-triangle-bold-duotone" className="h-5 w-5 text-amber-500" />
                  Low Stock Alert
                </CardTitle>
                <CardDescription>Items that need restocking</CardDescription>
              </div>
              <Button variant="ghost" size="sm" asChild>
                <Link to="/inventory/stock">View all</Link>
              </Button>
            </div>
          </CardHeader>
          <CardContent>
            {!lowStockData || lowStockData.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 mb-3">
                  <Icon icon="solar:check-circle-bold" className="h-7 w-7 text-primary" />
                </div>
                <p className="font-medium">All Stock Healthy</p>
                <p className="text-sm text-muted-foreground">No items below threshold</p>
              </div>
            ) : (
              <div className="space-y-3">
                {lowStockData.slice(0, 4).map((item: any) => (
                  <div key={item.id} className="flex items-center justify-between p-3 rounded-lg bg-amber-500/5 border border-amber-500/20">
                    <div className="flex items-center gap-3">
                      <div className="h-10 w-10 rounded-lg bg-muted flex items-center justify-center overflow-hidden">
                        {item.image ? (
                          <img src={item.image} alt="" className="h-full w-full object-cover" />
                        ) : (
                          <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-muted-foreground" />
                        )}
                      </div>
                      <div>
                        <p className="font-medium text-sm">{item.name}</p>
                        <p className="text-xs text-muted-foreground">{item.sku}</p>
                      </div>
                    </div>
                    <div className="text-right">
                      <Badge variant="outline" className="bg-amber-500/10 text-amber-600 border-amber-500/20">
                        {item.stock_quantity} left
                      </Badge>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Recent Movements */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:history-bold-duotone" className="h-5 w-5 text-blue-500" />
                Recent Stock Movements
              </CardTitle>
              <CardDescription>Latest inventory changes</CardDescription>
            </div>
            <Button variant="ghost" size="sm" asChild>
              <Link to="/inventory/stock">View all</Link>
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {!recentMovements || recentMovements.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <div className="flex h-14 w-14 items-center justify-center rounded-full bg-muted mb-3">
                <Icon icon="solar:history-bold-duotone" className="h-7 w-7 text-muted-foreground" />
              </div>
              <p className="font-medium">No Recent Activity</p>
              <p className="text-sm text-muted-foreground">Stock movements will appear here</p>
            </div>
          ) : (
            <div className="space-y-3">
              {recentMovements.map((movement: any) => {
                const config = getMovementTypeConfig(movement.type)
                const isIncoming = ['in', 'purchase', 'transfer_in', 'return'].includes(movement.type)
                return (
                  <div key={movement.id} className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/50 transition-colors">
                    <div className="flex items-center gap-3">
                      <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${config.bg}`}>
                        <Icon icon={config.icon} className={`h-5 w-5 ${config.color}`} />
                      </div>
                      <div>
                        <p className="font-medium text-sm">{movement.product?.name}</p>
                        <p className="text-xs text-muted-foreground">
                          {movement.type.replace('_', ' ').toUpperCase()} • {new Date(movement.created_at).toLocaleDateString()}
                        </p>
                      </div>
                    </div>
                    <div className="text-right">
                      <span className={`font-semibold ${isIncoming ? 'text-primary' : 'text-rose-600'}`}>
                        {isIncoming ? '+' : '-'}{movement.quantity}
                      </span>
                      <p className="text-xs text-muted-foreground">Balance: {movement.balance_after}</p>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Module Navigation */}
      <div className="grid gap-4 md:grid-cols-4">
        <Link to="/inventory/suppliers" className="group">
          <Card className="hover:shadow-lg hover:border-blue-500/30 transition-all h-full">
            <CardContent className="flex items-center gap-4 pt-6">
              <div className="flex h-14 w-14 items-center justify-center rounded-xl bg-blue-500/10 group-hover:bg-blue-500/20 transition-colors">
                <Icon icon="solar:users-group-rounded-bold-duotone" className="h-7 w-7 text-blue-600" />
              </div>
              <div>
                <p className="font-semibold">Suppliers</p>
                <p className="text-sm text-muted-foreground">Manage vendors</p>
              </div>
            </CardContent>
          </Card>
        </Link>

        <Link to="/inventory/purchase-orders" className="group">
          <Card className="hover:shadow-lg hover:border-primary/30 transition-all h-full">
            <CardContent className="flex items-center gap-4 pt-6">
              <div className="flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10 group-hover:bg-primary/20 transition-colors">
                <Icon icon="solar:document-text-bold-duotone" className="h-7 w-7 text-primary" />
              </div>
              <div>
                <p className="font-semibold">Purchase Orders</p>
                <p className="text-sm text-muted-foreground">Track orders</p>
              </div>
            </CardContent>
          </Card>
        </Link>

        <Link to="/inventory/transfers" className="group">
          <Card className="hover:shadow-lg hover:border-violet-500/30 transition-all h-full">
            <CardContent className="flex items-center gap-4 pt-6">
              <div className="flex h-14 w-14 items-center justify-center rounded-xl bg-violet-500/10 group-hover:bg-violet-500/20 transition-colors">
                <Icon icon="solar:transfer-horizontal-bold-duotone" className="h-7 w-7 text-violet-600" />
              </div>
              <div>
                <p className="font-semibold">Transfers</p>
                <p className="text-sm text-muted-foreground">Move stock</p>
              </div>
            </CardContent>
          </Card>
        </Link>

        <Link to="/inventory/warehouses" className="group">
          <Card className="hover:shadow-lg hover:border-orange-500/30 transition-all h-full">
            <CardContent className="flex items-center gap-4 pt-6">
              <div className="flex h-14 w-14 items-center justify-center rounded-xl bg-orange-500/10 group-hover:bg-orange-500/20 transition-colors">
                <Icon icon="solar:buildings-bold-duotone" className="h-7 w-7 text-orange-600" />
              </div>
              <div>
                <p className="font-semibold">Warehouses</p>
                <p className="text-sm text-muted-foreground">Locations</p>
              </div>
            </CardContent>
          </Card>
        </Link>
      </div>
    </div>
  )
}
