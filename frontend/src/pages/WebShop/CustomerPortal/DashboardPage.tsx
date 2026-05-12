import { useEffect, useState } from 'react'
import { Icon } from '@iconify/react'
import { Link } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useWebShopAuth } from '@/pages/WebShop/hooks/useWebShopAuth'
import api from '@/lib/api'

interface Order {
  id: number
  order_number: string
  status: string
  payment_status: string
  total: number
  currency: string
  created_at: string
  items: { quantity: number }[]
}

interface TicketSummary {
  id: number
  booking_reference: string
  status: string
  event_title?: string
  event_date?: string
  event_venue?: string
  ticket_count: number
}

interface DashboardStats {
  totalOrders: number
  totalSpent: number
  loyaltyPoints: number
  ticketCount: number
}

export function DashboardPage() {
  const { customer, getAuthHeaders } = useWebShopAuth()
  const [orders, setOrders] = useState<Order[]>([])
  const [tickets, setTickets] = useState<TicketSummary[]>([])
  const [stats, setStats] = useState<DashboardStats>({
    totalOrders: 0,
    totalSpent: 0,
    loyaltyPoints: 0,
    ticketCount: 0,
  })
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const fetchData = async () => {
      try {
        const [ordersRes, ticketsRes] = await Promise.all([
          api.get('/web-shop/orders', {
            headers: getAuthHeaders(),
            params: { per_page: 5 },
          }),
          api.get('/web-shop/tickets', {
            headers: getAuthHeaders(),
          }).catch(() => ({ data: { tickets: [] } })),
        ])

        const ordersData = ordersRes.data.orders || []
        setOrders(ordersData)

        const ticketBookings: TicketSummary[] = ticketsRes.data.tickets || []
        setTickets(ticketBookings)

        const totalOrders = ordersRes.data.pagination?.total || 0
        const totalSpent = ordersData.reduce((sum: number, order: Order) => sum + (order.total || 0), 0)
        const ticketCount = ticketBookings.reduce((sum, b) => sum + (b.ticket_count || 0), 0)

        setStats({
          totalOrders,
          totalSpent,
          loyaltyPoints: customer?.loyalty_points || 0,
          ticketCount,
        })
      } catch (error) {
        console.error('Failed to fetch dashboard data:', error)
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [customer, getAuthHeaders])

  const getStatusColor = (status: string) => {
    switch (status.toLowerCase()) {
      case 'completed':
      case 'delivered':
        return 'bg-green-100 text-green-800 border-green-200'
      case 'shipped':
        return 'bg-blue-100 text-blue-800 border-blue-200'
      case 'processing':
        return 'bg-yellow-100 text-yellow-800 border-yellow-200'
      case 'pending':
        return 'bg-orange-100 text-orange-800 border-orange-200'
      case 'cancelled':
        return 'bg-red-100 text-red-800 border-red-200'
      default:
        return 'bg-gray-100 text-gray-800 border-gray-200'
    }
  }

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">Dashboard</h1>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card>
          <CardContent className="p-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Total Orders</p>
                <p className="text-3xl font-bold">{stats.totalOrders}</p>
              </div>
              <div className="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                <Icon icon="solar:box-bold-duotone" className="h-6 w-6 text-blue-600" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Total Spent</p>
                <p className="text-3xl font-bold">${stats.totalSpent.toFixed(2)}</p>
              </div>
              <div className="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center">
                <Icon icon="solar:wallet-bold-duotone" className="h-6 w-6 text-green-600" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Loyalty Points</p>
                <p className="text-3xl font-bold">{stats.loyaltyPoints}</p>
              </div>
              <div className="h-12 w-12 rounded-full bg-purple-100 flex items-center justify-center">
                <Icon icon="solar:gift-bold-duotone" className="h-6 w-6 text-purple-600" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Event Tickets</p>
                <p className="text-3xl font-bold">{stats.ticketCount}</p>
              </div>
              <div className="h-12 w-12 rounded-full bg-amber-100 flex items-center justify-center">
                <Icon icon="solar:ticket-bold-duotone" className="h-6 w-6 text-amber-600" />
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Recent Orders */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:clock-circle-bold-duotone" className="h-5 w-5" />
            Recent Orders
          </CardTitle>
        </CardHeader>
        <CardContent>
          {orders.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              <Icon icon="solar:inbox-bold-duotone" className="h-12 w-12 mx-auto mb-4 opacity-50" />
              <p>No orders yet</p>
            </div>
          ) : (
            <div className="space-y-4">
              {orders.map((order) => (
                <div
                  key={order.id}
                  className="flex items-center justify-between p-4 bg-muted/50 rounded-lg"
                >
                  <div className="flex items-center gap-4">
                    <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                      <Icon icon="solar:receipt-bold-duotone" className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <p className="font-medium">{order.order_number}</p>
                      <p className="text-sm text-muted-foreground">
                        {formatDate(order.created_at)} • {order.items?.length || 0} items
                      </p>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="font-medium">${Number(order.total).toFixed(2)}</p>
                    <Badge variant="outline" className={getStatusColor(order.status)}>
                      {order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                    </Badge>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* My Event Tickets */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:ticket-bold-duotone" className="h-5 w-5" />
            My Event Tickets
          </CardTitle>
          {tickets.length > 0 && (
            <Button asChild variant="ghost" size="sm">
              <Link to="/web-shop/account/tickets">View all</Link>
            </Button>
          )}
        </CardHeader>
        <CardContent>
          {tickets.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              <Icon icon="solar:ticket-bold-duotone" className="h-12 w-12 mx-auto mb-4 opacity-50" />
              <p>No event tickets yet</p>
              <p className="text-xs mt-1">Purchased event tickets will appear here.</p>
            </div>
          ) : (
            <div className="space-y-3">
              {tickets.slice(0, 4).map((booking) => (
                <Link
                  key={booking.id}
                  to="/web-shop/account/tickets"
                  className="flex items-center justify-between p-4 bg-muted/50 rounded-lg hover:bg-muted transition"
                >
                  <div className="flex items-center gap-4">
                    <div className="h-10 w-10 rounded-full bg-amber-100 flex items-center justify-center">
                      <Icon icon="solar:ticket-bold-duotone" className="h-5 w-5 text-amber-600" />
                    </div>
                    <div>
                      <p className="font-medium">{booking.event_title || `Event #${booking.id}`}</p>
                      <p className="text-sm text-muted-foreground">
                        {booking.event_date ? new Date(booking.event_date).toLocaleString() : 'Date TBA'}
                        {booking.event_venue ? ` • ${booking.event_venue}` : ''}
                      </p>
                      <p className="text-xs text-muted-foreground mt-1 font-mono">
                        {booking.booking_reference} · {booking.ticket_count} ticket{booking.ticket_count === 1 ? '' : 's'}
                      </p>
                    </div>
                  </div>
                  <Badge variant="outline" className={getStatusColor(booking.status)}>
                    {booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}
                  </Badge>
                </Link>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}