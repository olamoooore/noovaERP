import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { formatCurrency } from '@/lib/utils'

// Simple chart component using divs for now (can be replaced with recharts/apexcharts)
function SimpleBarChart({ data, labelKey, valueKey, label }: {
  data: any[]
  labelKey: string
  valueKey: string
  label: string
}) {
  if (!data || data.length === 0) return null
  
  const maxValue = Math.max(...data.map(d => Number(d[valueKey]) || 0))
  
  return (
    <div className="space-y-2">
      <p className="text-sm font-medium text-muted-foreground">{label}</p>
      <div className="space-y-1">
        {data.map((item, idx) => {
          const value = Number(item[valueKey]) || 0
          const percentage = maxValue > 0 ? (value / maxValue) * 100 : 0
          return (
            <div key={idx} className="flex items-center gap-2">
              <span className="w-16 text-xs text-muted-foreground truncate">
                {item[labelKey]}
              </span>
              <div className="flex-1 h-6 bg-muted rounded overflow-hidden">
                <div
                  className="h-full bg-primary transition-all"
                  style={{ width: `${percentage}%` }}
                />
              </div>
              <span className="w-24 text-xs text-right">
                {formatCurrency(value)}
              </span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

export function POSReportsPage() {
  const [reportType, setReportType] = useState<'daily' | 'monthly'>('daily')
  const [startDate, setStartDate] = useState(() => {
    const date = new Date()
    date.setDate(date.getDate() - 30)
    return date.toISOString().split('T')[0]
  })
  const [endDate, setEndDate] = useState(() => new Date().toISOString().split('T')[0])

  // Fetch dashboard stats
  const { data: dashboard, isLoading: dashboardLoading } = useQuery({
    queryKey: ['pos-dashboard'],
    queryFn: async () => {
      const response = await api.get('/pos/dashboard')
      return response.data
    },
  })

  // Fetch reports data
  const { data: reports, isLoading: reportsLoading } = useQuery({
    queryKey: ['pos-reports', reportType, startDate, endDate],
    queryFn: async () => {
      const response = await api.get('/pos/reports', {
        params: {
          type: reportType,
          start_date: startDate,
          end_date: endDate,
        },
      })
      return response.data
    },
  })

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-NG', {
      month: 'short',
      day: 'numeric',
    })
  }

  const formatMonth = (year: number, month: number) => {
    return new Date(year, month - 1).toLocaleDateString('en-NG', {
      year: 'numeric',
      month: 'short',
    })
  }

  return (
    <div className="flex h-full flex-col gap-4 p-4 overflow-auto">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">POS Reports</h1>
          <p className="text-muted-foreground">Sales analytics and insights</p>
        </div>
        <Button variant="outline">
          <Icon icon="solar:download-bold" className="h-4 w-4 mr-2" />
          Export
        </Button>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Today's Sales</CardDescription>
            <CardTitle className="text-2xl">
              {dashboardLoading ? (
                <Icon icon="solar:refresh-linear" className="h-6 w-6 animate-spin" />
              ) : (
                formatCurrency(Number(dashboard?.today?.total || 0))
              )}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">
              <span className="font-medium">{dashboard?.today?.count || 0}</span> orders
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardDescription>This Month</CardDescription>
            <CardTitle className="text-2xl">
              {dashboardLoading ? (
                <Icon icon="solar:refresh-linear" className="h-6 w-6 animate-spin" />
              ) : (
                formatCurrency(Number(dashboard?.month?.total || 0))
              )}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">
              <span className="font-medium">{dashboard?.month?.count || 0}</span> orders
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Period Total</CardDescription>
            <CardTitle className="text-2xl">
              {reportsLoading ? (
                <Icon icon="solar:refresh-linear" className="h-6 w-6 animate-spin" />
              ) : (
                formatCurrency(Number(reports?.summary?.total_sales || 0))
              )}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">
              {startDate} to {endDate}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Average Order</CardDescription>
            <CardTitle className="text-2xl">
              {reportsLoading ? (
                <Icon icon="solar:refresh-linear" className="h-6 w-6 animate-spin" />
              ) : (
                formatCurrency(Number(reports?.summary?.average_order || 0))
              )}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">
              <span className="font-medium">{reports?.summary?.total_orders || 0}</span> total orders
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="pt-4">
          <div className="flex flex-wrap gap-4 items-end">
            <div className="space-y-2">
              <label className="text-sm font-medium">Report Type</label>
              <Select value={reportType} onValueChange={(v) => setReportType(v as 'daily' | 'monthly')}>
                <SelectTrigger className="w-[150px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="daily">Daily</SelectItem>
                  <SelectItem value="monthly">Monthly</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <label className="text-sm font-medium">Start Date</label>
              <Input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <label className="text-sm font-medium">End Date</label>
              <Input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
              />
            </div>
            <Button
              variant="outline"
              onClick={() => {
                const date = new Date()
                setEndDate(date.toISOString().split('T')[0])
                date.setDate(date.getDate() - 30)
                setStartDate(date.toISOString().split('T')[0])
              }}
            >
              Last 30 Days
            </Button>
            <Button
              variant="outline"
              onClick={() => {
                const date = new Date()
                setEndDate(date.toISOString().split('T')[0])
                date.setDate(date.getDate() - 90)
                setStartDate(date.toISOString().split('T')[0])
              }}
            >
              Last 90 Days
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Charts and Data */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Sales Chart */}
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Sales Trend</CardTitle>
            <CardDescription>
              {reportType === 'daily' ? 'Daily' : 'Monthly'} sales for the selected period
            </CardDescription>
          </CardHeader>
          <CardContent>
            {reportsLoading ? (
              <div className="flex items-center justify-center h-64">
                <Icon icon="solar:refresh-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
              </div>
            ) : reports?.data?.length === 0 ? (
              <div className="flex flex-col items-center justify-center h-64 text-muted-foreground">
                <Icon icon="solar:chart-bold-duotone" className="h-16 w-16 mb-4 opacity-50" />
                <p>No data for selected period</p>
              </div>
            ) : (
              <SimpleBarChart
                data={reports?.data || []}
                labelKey={reportType === 'daily' ? 'date' : 'month'}
                valueKey="total"
                label="Total Sales"
              />
            )}
          </CardContent>
        </Card>

        {/* Data Table */}
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Detailed Breakdown</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            {reportsLoading ? (
              <div className="flex items-center justify-center h-32">
                <Icon icon="solar:refresh-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{reportType === 'daily' ? 'Date' : 'Month'}</TableHead>
                    <TableHead>Orders</TableHead>
                    <TableHead>Total Sales</TableHead>
                    <TableHead>Tax Collected</TableHead>
                    <TableHead>Avg. Order</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(reports?.data || []).map((row: any, idx: number) => (
                    <TableRow key={idx}>
                      <TableCell className="font-medium">
                        {reportType === 'daily' 
                          ? formatDate(row.date)
                          : formatMonth(row.year, row.month)
                        }
                      </TableCell>
                      <TableCell>
                        <Badge variant="secondary">{row.orders}</Badge>
                      </TableCell>
                      <TableCell>{formatCurrency(Number(row.total))}</TableCell>
                      <TableCell>{formatCurrency(Number(row.tax))}</TableCell>
                      <TableCell>
                        {formatCurrency(row.orders > 0 ? Math.round(row.total / row.orders) : 0)}
                      </TableCell>
                    </TableRow>
                  ))}
                  {(reports?.data || []).length === 0 && (
                    <TableRow>
                      <TableCell colSpan={5} className="text-center text-muted-foreground py-8">
                        No data available for the selected period
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>

        {/* Recent Orders */}
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Recent Orders</CardTitle>
            <CardDescription>Latest POS transactions</CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Order #</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Items</TableHead>
                  <TableHead>Total</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(dashboard?.recent_orders || []).slice(0, 5).map((order: any) => (
                  <TableRow key={order.id}>
                    <TableCell className="font-medium">{order.order_number}</TableCell>
                    <TableCell>
                      {new Date(order.created_at).toLocaleDateString('en-NG', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                      })}
                    </TableCell>
                    <TableCell>
                      <Badge variant="secondary">{order.items?.length || 0}</Badge>
                    </TableCell>
                    <TableCell>{formatCurrency(Number(order.total))}</TableCell>
                    <TableCell>
                      <Badge className={order.status === 'paid' ? 'bg-green-500' : ''}>
                        {order.status}
                      </Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
