import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { useWebShopAuth } from '@/pages/WebShop/hooks/useWebShopAuth'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface OrderItem {
  id: number
  product_id: number
  product_name: string
  product_sku: string
  product_image: string | null
  unit_price: number
  quantity: number
  tax_amount: number
  line_total: number
}

interface ShippingAddress {
  first_name: string
  last_name: string
  address: string
  city: string
  state: string
  postal_code: string
  country: string
}

interface ShippingMethod {
  provider_name?: string | null
  service_name?: string | null
  label?: string | null
}

interface Order {
  id: number
  order_number: string
  status: string
  payment_status: string
  email: string
  phone: string
  first_name: string
  last_name: string
  shipping_address: ShippingAddress
  shipping_method?: ShippingMethod | null
  subtotal: number
  shipping_cost: number
  tax_amount: number
  discount_amount: number
  loyalty_discount: number
  total: number
  currency: string
  notes: string | null
  items: OrderItem[]
  created_at: string
  paid_at: string | null
  shipped_at: string | null
  delivered_at: string | null
}

export function OrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { getAuthHeaders } = useWebShopAuth()
  const { toast } = useToast()
  const [order, setOrder] = useState<Order | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const fetchOrder = async () => {
      try {
        const response = await api.get(`/web-shop/orders/${id}`, {
          headers: getAuthHeaders(),
        })
        setOrder(response.data.order)
      } catch (error: any) {
        console.error('Failed to fetch order:', error)
        toast({
          variant: 'destructive',
          title: 'Error',
          description: 'Failed to load order details.',
        })
      } finally {
        setLoading(false)
      }
    }

    fetchOrder()
  }, [id, getAuthHeaders, toast])

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

  const formatDate = (dateString: string | null) => {
    if (!dateString) return 'Pending'
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

  if (!order) {
    return (
      <div className="text-center py-12">
        <Icon icon="solar:close-circle-bold-duotone" className="h-16 w-16 mx-auto mb-4 text-red-500" />
        <h2 className="text-xl font-bold mb-2">Order Not Found</h2>
        <p className="text-muted-foreground mb-4">The order you are looking for does not exist.</p>
        <Button asChild>
          <Link to="/web-shop/account/orders">Back to Orders</Link>
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Link to="/web-shop/account/orders" className="hover:text-foreground">
          Orders
        </Link>
        <Icon icon="solar:alt-arrow-right-bold-duotone" className="h-4 w-4" />
        <span className="text-foreground">{order.order_number}</span>
      </div>

      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">{order.order_number}</h1>
          <p className="text-sm text-muted-foreground">
            Placed on {formatDate(order.created_at)}
          </p>
        </div>
        <Badge variant="outline" className={`text-base px-4 py-1 ${getStatusColor(order.status)}`}>
          {order.status.charAt(0).toUpperCase() + order.status.slice(1)}
        </Badge>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Content */}
        <div className="lg:col-span-2 space-y-6">
          {/* Order Timeline */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Icon icon="solar:history-bold-duotone" className="h-5 w-5" />
                Order Timeline
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="relative">
                <div className="absolute left-4 top-0 bottom-0 w-0.5 bg-border"></div>
                <div className="space-y-6">
                  <div className="relative flex gap-4">
                    <div className={`h-8 w-8 rounded-full flex items-center justify-center z-10 ${order.created_at ? 'bg-green-500 text-white' : 'bg-muted'}`}>
                      <Icon icon="solar:check-bold-duotone" className="h-4 w-4" />
                    </div>
                    <div>
                      <p className="font-medium">Order Placed</p>
                      <p className="text-sm text-muted-foreground">{formatDate(order.created_at)}</p>
                    </div>
                  </div>
                  <div className="relative flex gap-4">
                    <div className={`h-8 w-8 rounded-full flex items-center justify-center z-10 ${order.paid_at ? 'bg-green-500 text-white' : 'bg-muted'}`}>
                      {order.paid_at ? <Icon icon="solar:check-bold-duotone" className="h-4 w-4" /> : <Icon icon="solar:clock-bold-duotone" className="h-4 w-4" />}
                    </div>
                    <div>
                      <p className="font-medium">Payment {order.paid_at ? 'Confirmed' : 'Pending'}</p>
                      <p className="text-sm text-muted-foreground">{formatDate(order.paid_at)}</p>
                    </div>
                  </div>
                  <div className="relative flex gap-4">
                    <div className={`h-8 w-8 rounded-full flex items-center justify-center z-10 ${order.shipped_at ? 'bg-green-500 text-white' : 'bg-muted'}`}>
                      {order.shipped_at ? <Icon icon="solar:check-bold-duotone" className="h-4 w-4" /> : <Icon icon="solar:truck-bold-duotone" className="h-4 w-4" />}
                    </div>
                    <div>
                      <p className="font-medium">{order.shipped_at ? 'Shipped' : 'Shipping'}</p>
                      <p className="text-sm text-muted-foreground">{formatDate(order.shipped_at)}</p>
                    </div>
                  </div>
                  <div className="relative flex gap-4">
                    <div className={`h-8 w-8 rounded-full flex items-center justify-center z-10 ${order.delivered_at ? 'bg-green-500 text-white' : 'bg-muted'}`}>
                      {order.delivered_at ? <Icon icon="solar:check-bold-duotone" className="h-4 w-4" /> : <Icon icon="solar:box-bold-duotone" className="h-4 w-4" />}
                    </div>
                    <div>
                      <p className="font-medium">{order.delivered_at ? 'Delivered' : 'Delivery'}</p>
                      <p className="text-sm text-muted-foreground">{formatDate(order.delivered_at)}</p>
                    </div>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Order Items */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Icon icon="solar:box-bold-duotone" className="h-5 w-5" />
                Order Items ({order.items?.length || 0})
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {order.items?.map((item) => (
                  <div key={item.id} className="flex gap-4 p-4 bg-muted/50 rounded-lg">
                    {item.product_image ? (
                      <img
                        src={item.product_image}
                        alt={item.product_name}
                        className="w-20 h-20 object-cover rounded-md"
                      />
                    ) : (
                      <div className="w-20 h-20 bg-muted rounded-md flex items-center justify-center">
                        <Icon icon="solar:box-bold-duotone" className="h-8 w-8 text-muted-foreground" />
                      </div>
                    )}
                    <div className="flex-1">
                      <p className="font-medium">{item.product_name}</p>
                      <p className="text-sm text-muted-foreground">SKU: {item.product_sku}</p>
                      <p className="text-sm text-muted-foreground mt-1">
                        Qty: {item.quantity} × ${Number(item.unit_price).toFixed(2)}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="font-medium">${Number(item.line_total).toFixed(2)}</p>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Sidebar */}
        <div className="lg:col-span-1 space-y-6">
          {/* Order Summary */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5" />
                Order Summary
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span>${Number(order.subtotal).toFixed(2)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Tax</span>
                  <span>${Number(order.tax_amount).toFixed(2)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Shipping</span>
                  <span>${Number(order.shipping_cost).toFixed(2)}</span>
                </div>
                {order.shipping_method?.label && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Shipping Method</span>
                    <span>{order.shipping_method.label}</span>
                  </div>
                )}
                {order.discount_amount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Discount</span>
                    <span className="text-green-600">-${Number(order.discount_amount).toFixed(2)}</span>
                  </div>
                )}
                {order.loyalty_discount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Loyalty Discount</span>
                    <span className="text-green-600">-${Number(order.loyalty_discount).toFixed(2)}</span>
                  </div>
                )}
                <Separator />
                <div className="flex justify-between font-medium text-lg">
                  <span>Total</span>
                  <span>{order.currency} {Number(order.total).toFixed(2)}</span>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Shipping Address */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Icon icon="solar:map-point-bold-duotone" className="h-5 w-5" />
                Shipping Address
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-sm space-y-1">
                <p className="font-medium">
                  {order.shipping_address.first_name} {order.shipping_address.last_name}
                </p>
                <p>{order.shipping_address.address}</p>
                <p>
                  {order.shipping_address.city}, {order.shipping_address.state} {order.shipping_address.postal_code}
                </p>
                <p>{order.shipping_address.country}</p>
              </div>
            </CardContent>
          </Card>

          {/* Contact Info */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Icon icon="solar:user-bold-duotone" className="h-5 w-5" />
                Contact Information
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-sm space-y-2">
                <div className="flex items-center gap-2">
                  <Icon icon="solar:letter-bold-duotone" className="h-4 w-4 text-muted-foreground" />
                  <span>{order.email}</span>
                </div>
                {order.phone && (
                  <div className="flex items-center gap-2">
                    <Icon icon="solar:phone-bold-duotone" className="h-4 w-4 text-muted-foreground" />
                    <span>{order.phone}</span>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}