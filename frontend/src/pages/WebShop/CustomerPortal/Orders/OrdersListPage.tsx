import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Pagination } from '@/components/ui/Pagination'
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

interface OrdersPagination {
  current_page: number
  total: number
  per_page: number
}

export function OrdersListPage() {
  const { getAuthHeaders } = useWebShopAuth()
  const [orders, setOrders] = useState<Order[]>([])
  const [pagination, setPagination] = useState<OrdersPagination>({
    current_page: 1,
    total: 0,
    per_page: 10,
  })
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)

  useEffect(() => {
    const fetchOrders = async () => {
      try {
        setLoading(true)
        const response = await api.get('/web-shop/orders', {
          headers: getAuthHeaders(),
          params: { page, per_page: pageSize },
        })

        setOrders(response.data.orders || [])
        setPagination(response.data.pagination || { current_page: 1, total: 0, per_page: pageSize })
      } catch (error) {
        console.error('Failed to fetch orders:', error)
      } finally {
        setLoading(false)
      }
    }

    fetchOrders()
  }, [page, pageSize, getAuthHeaders])

  const getStatusIcon = (status: string) => {
    switch (status.toLowerCase()) {
      case 'completed':
      case 'delivered':
        return 'solar:check-circle-bold-duotone'
      case 'shipped':
        return 'solar:truck-bold-duotone'
      case 'processing':
        return 'solar:clock-circle-bold-duotone'
      case 'pending':
        return 'solar:hourglass-bold-duotone'
      case 'cancelled':
        return 'solar:close-circle-bold-duotone'
      default:
        return 'solar:box-bold-duotone'
    }
  }

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
      month: 'long',
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
      <h1 className="text-2xl font-bold">My Orders</h1>

      <Card>
        <CardContent className="p-0">
          {orders.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:inbox-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p className="text-lg">No orders found</p>
              <p className="text-sm">Start shopping to see your orders here</p>
            </div>
          ) : (
            <div className="divide-y">
              {orders.map((order) => (
                <Link
                  key={order.id}
                  to={`/web-shop/account/orders/${order.id}`}
                  className="flex items-center justify-between p-6 hover:bg-muted/50 transition-colors"
                >
                  <div className="flex items-center gap-4">
                    <div className={`h-12 w-12 rounded-full flex items-center justify-center ${getStatusColor(order.status).replace('text-', 'bg-').replace('border-', '').split(' ')[0]}`}>
                      <Icon icon={getStatusIcon(order.status)} className={`h-6 w-6 ${getStatusColor(order.status).split(' ')[1]}`} />
                    </div>
                    <div>
                      <p className="font-medium">{order.order_number}</p>
                      <p className="text-sm text-muted-foreground">
                        {formatDate(order.created_at)} • {order.items?.length || 0} items
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-4">
                    <div className="text-right">
                      <p className="font-medium">${Number(order.total).toFixed(2)}</p>
                      <Badge variant="outline" className={getStatusColor(order.status)}>
                        {order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                      </Badge>
                    </div>
                    <Icon icon="solar:alt-arrow-right-bold-duotone" className="h-5 w-5 text-muted-foreground" />
                  </div>
                </Link>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Pagination */}
      <Pagination
        page={page}
        totalPages={Math.max(1, Math.ceil(pagination.total / pagination.per_page))}
        onPageChange={setPage}
        from={(page - 1) * pagination.per_page + 1}
        to={Math.min(page * pagination.per_page, pagination.total)}
        total={pagination.total}
        label="orders"
        pageSize={pageSize}
        onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
      />
    </div>
  )
}