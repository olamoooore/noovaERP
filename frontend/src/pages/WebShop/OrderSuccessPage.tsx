import { useState, useEffect } from 'react'
import { useSearchParams, useNavigate, Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useToast } from '@/hooks/use-toast'
import { useWebShopAuth } from '@/pages/WebShop/hooks/useWebShopAuth'
import api from '@/lib/api'

interface OrderItem {
  id: number
  product_name: string
  product_image?: string
  quantity: number
  unit_price: number
  line_total: number
  tax_amount: number
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

interface OrderData {
  order_number: string
  status: string
  payment_status: string
  total: number
  tax_amount: number
  subtotal: number
  shipping_cost: number
  shipping_method?: ShippingMethod | null
  discount_amount: number
  loyalty_discount: number
  currency: string
  created_at: string
  shipping_address: ShippingAddress
  items: OrderItem[]
  email: string
  phone?: string
  notes?: string
  loyalty_points_earned?: number
  loyalty_points_redeemed?: number
}

export function OrderSuccessPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { toast } = useToast()
  const { register, isAuthenticated } = useWebShopAuth()
  const [order, setOrder] = useState<OrderData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string>('')
  const [accountCreated, setAccountCreated] = useState(false)
  const [isCreatingAccount, setIsCreatingAccount] = useState(false)
  const [createAccountError, setCreateAccountError] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')

  const guestToken = searchParams.get('token') || localStorage.getItem('ace_guest_token')
  const isGuest = !isAuthenticated && guestToken

  useEffect(() => {
    const fetchOrderDetails = async () => {
      if (!guestToken) {
        setError('No order token found')
        setLoading(false)
        return
      }

      try {
        const response = await api.get(`/web-shop/orders/guest/${guestToken}`)
        const orderData = response.data.order || response.data
        setOrder(orderData)
        setLoading(false)
      } catch (err: any) {
        console.error('Failed to fetch order:', err)
        setError(err.response?.data?.message || 'Failed to load order details')
        setLoading(false)
        toast({
          variant: 'destructive',
          title: 'Error',
          description: 'Failed to load order details.',
        })
      }
    }

    fetchOrderDetails()
  }, [guestToken, toast])

  const getStatusColor = (status: string) => {
    switch (status.toLowerCase()) {
      case 'completed':
      case 'paid':
        return 'text-green-600 bg-green-50 dark:bg-green-950'
      case 'pending':
      case 'processing':
        return 'text-yellow-600 bg-yellow-50 dark:bg-yellow-950'
      case 'cancelled':
      case 'failed':
        return 'text-red-600 bg-red-50 dark:bg-red-950'
      default:
        return 'text-blue-600 bg-blue-50 dark:bg-blue-950'
    }
  }

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    })
  }

  const handleCreateAccount = async (e: React.FormEvent) => {
    e.preventDefault()
    setCreateAccountError('')

    if (!order) return

    if (password.length < 8) {
      setCreateAccountError('Password must be at least 8 characters long')
      return
    }

    if (password !== passwordConfirmation) {
      setCreateAccountError('Passwords do not match')
      return
    }

    setIsCreatingAccount(true)

    const result = await register({
      email: order.email,
      password,
      password_confirmation: passwordConfirmation,
      first_name: order.shipping_address.first_name,
      last_name: order.shipping_address.last_name,
      phone: order.phone,
      guest_token: guestToken || undefined,
    })

    if (result.success) {
      setAccountCreated(true)
      toast({
        title: 'Account Created!',
        description: 'You can now access your order history and manage your account.',
      })
    } else {
      setCreateAccountError(result.error || 'Failed to create account. Please try again.')
    }

    setIsCreatingAccount(false)
  }

  if (loading) {
    return (
      <div className="container mx-auto py-12 px-4">
        <div className="flex flex-col items-center justify-center min-h-[400px]">
          <Icon icon="solar:spinner-bold-duotone" className="h-12 w-12 text-primary mb-4 animate-spin" />
          <p className="text-lg text-muted-foreground">Loading your order...</p>
        </div>
      </div>
    )
  }

  if (error || !order) {
    return (
      <div className="container mx-auto py-12 px-4 max-w-2xl">
        <Card>
          <CardHeader className="text-center">
            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
              <Icon icon="solar:close-circle-bold-duotone" className="h-10 w-10 text-red-600" />
            </div>
            <CardTitle className="text-2xl">Order Not Found</CardTitle>
            <CardDescription>
              We could not find your order details
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <Alert variant="destructive">
              <AlertDescription>{error || 'Unable to load order information'}</AlertDescription>
            </Alert>
            <div className="flex justify-center gap-4">
              <Button variant="outline" onClick={() => navigate('/')}>
                <Icon icon="solar:home-bold-duotone" className="mr-2 h-4 w-4" />
                Go Home
              </Button>
              <Button onClick={() => navigate('/web-shop')}>
                <Icon icon="solar:shop-bold-duotone" className="mr-2 h-4 w-4" />
                Continue Shopping
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <div className="container mx-auto py-8 px-4 max-w-4xl">
      {/* Success Header */}
      <div className="text-center mb-8">
        <div className="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-green-100">
          <Icon icon="solar:check-circle-bold-duotone" className="h-12 w-12 text-green-600" />
        </div>
        <h1 className="text-3xl font-bold mb-2">Order Confirmed!</h1>
        <p className="text-muted-foreground">
          Thank you for your purchase. We've sent a confirmation email to {order.email}
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Order Details */}
        <div className="lg:col-span-2 space-y-6">
          <Card>
            <CardHeader>
              <div className="flex justify-between items-start">
                <div>
                  <CardTitle className="flex items-center gap-2">
                    <Icon icon="solar:receipt-bold-duotone" className="h-5 w-5" />
                    Order Details
                  </CardTitle>
                  <CardDescription>
                    Placed on {formatDate(order.created_at)}
                  </CardDescription>
                </div>
                <span className={`px-3 py-1 rounded-full text-sm font-medium ${getStatusColor(order.status)}`}>
                  {order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                </span>
              </div>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Order Number */}
              <div className="bg-muted p-4 rounded-lg">
                <p className="text-sm text-muted-foreground mb-1">Order Number</p>
                <p className="text-xl font-mono font-bold">{order.order_number}</p>
              </div>

              {/* Order Items */}
              <div className="space-y-3">
                <h3 className="font-medium">Items Ordered</h3>
                {order.items.map((item) => (
                  <div key={item.id} className="flex gap-4 p-3 bg-muted/50 rounded-lg">
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
                      <p className="text-sm text-muted-foreground">
                        Qty: {item.quantity} × ${Number(item.unit_price).toFixed(2)}
                      </p>
                      <p className="font-medium mt-1">${Number(item.line_total).toFixed(2)}</p>
                    </div>
                  </div>
                ))}
              </div>

              {/* Shipping Address */}
              <div className="space-y-2">
                <h3 className="font-medium flex items-center gap-2">
                  <Icon icon="solar:map-point-bold-duotone" className="h-4 w-4" />
                  Shipping Address
                </h3>
                <div className="bg-muted p-4 rounded-lg text-sm">
                  <p className="font-medium">
                    {order.shipping_address.first_name} {order.shipping_address.last_name}
                  </p>
                  <p>{order.shipping_address.address}</p>
                  <p>
                    {order.shipping_address.city}, {order.shipping_address.state} {order.shipping_address.postal_code}
                  </p>
                  <p>{order.shipping_address.country}</p>
                </div>
              </div>

              {/* Order Notes */}
              {order.notes && (
                <div className="space-y-2">
                  <h3 className="font-medium flex items-center gap-2">
                    <Icon icon="solar:notes-bold-duotone" className="h-4 w-4" />
                    Order Notes
                  </h3>
                  <p className="text-sm text-muted-foreground bg-muted p-3 rounded-lg">
                    {order.notes}
                  </p>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Action Buttons */}
          <div className="flex flex-col sm:flex-row gap-4">
            <Button asChild variant="outline" className="flex-1">
              <Link to="/web-shop">
                <Icon icon="solar:arrow-left-bold-duotone" className="mr-2 h-4 w-4" />
                Continue Shopping
              </Link>
            </Button>
            <Button asChild className="flex-1">
              <Link to="/register">
                <Icon icon="solar:user-plus-bold-duotone" className="mr-2 h-4 w-4" />
                Create Account
              </Link>
            </Button>
          </div>
        </div>

        {/* Order Summary Sidebar */}
        <div className="lg:col-span-1">
          {/* Loyalty Points Earned */}
          {order.loyalty_points_earned && order.loyalty_points_earned > 0 && (
            <Card className="mb-4 border-green-200 bg-green-50/50">
              <CardContent className="pt-6">
                <div className="flex items-center gap-3">
                  <div className="p-3 rounded-full bg-green-100">
                    <Icon icon="solar:gift-bold-duotone" className="h-6 w-6 text-green-600" />
                  </div>
                  <div>
                    <p className="font-semibold text-green-900">Points Earned!</p>
                    <p className="text-sm text-green-700">
                      You earned {order.loyalty_points_earned} points from this order!
                    </p>
                    <Link
                      to="/web-shop/account/loyalty"
                      className="text-sm text-green-800 underline hover:text-green-900 mt-1 inline-block"
                    >
                      View your points
                    </Link>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}
          <Card className="h-fit sticky top-4">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
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
                  <span className="text-green-600">{(order.shipping_cost || 0) === 0 ? 'Free' : `$${Number(order.shipping_cost).toFixed(2)}`}</span>
                </div>
                {order.shipping_method?.label && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Shipping Method</span>
                    <span>{order.shipping_method.label}</span>
                  </div>
                )}
                {order.loyalty_discount && order.loyalty_discount > 0 ? (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Loyalty Discount</span>
                    <span className="text-green-600">-${Number(order.loyalty_discount).toFixed(2)}</span>
                  </div>
                ) : null}
                <Separator />
                <div className="flex justify-between font-medium text-lg">
                  <span>Total</span>
                  <span>
                    {order.currency} ${Number(order.total).toFixed(2)}
                  </span>
                </div>
              </div>

              <div className="bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 p-4 rounded-lg">
                <div className="flex items-center gap-2 text-green-800 dark:text-green-200">
                  <Icon icon="solar:check-circle-bold-duotone" className="h-5 w-5" />
                  <span className="font-medium">Payment Confirmed</span>
                </div>
                <p className="text-xs text-green-700 dark:text-green-300 mt-1">
                  Your payment has been successfully processed
                </p>
              </div>

              {/* Create Account CTA */}
              {isGuest && !accountCreated ? (
                <div className="bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 p-4 rounded-lg">
                  <div className="flex items-center gap-2 text-blue-800 dark:text-blue-200 mb-2">
                    <Icon icon="solar:user-plus-bold-duotone" className="h-5 w-5" />
                    <span className="font-medium">Create an Account</span>
                  </div>
                  <p className="text-xs text-blue-700 dark:text-blue-300 mb-3">
                    Save your details for faster checkout next time and track all your orders in one place.
                  </p>
                  {createAccountError && (
                    <Alert variant="destructive" className="mb-3">
                      <AlertDescription>{createAccountError}</AlertDescription>
                    </Alert>
                  )}
                  <form onSubmit={handleCreateAccount} className="space-y-3">
                    <div>
                      <Label htmlFor="password" className="text-xs">Password</Label>
                      <Input
                        id="password"
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="Min 8 characters"
                        className="bg-white dark:bg-slate-900"
                        required
                        minLength={8}
                      />
                    </div>
                    <div>
                      <Label htmlFor="password_confirmation" className="text-xs">Confirm Password</Label>
                      <Input
                        id="password_confirmation"
                        type="password"
                        value={passwordConfirmation}
                        onChange={(e) => setPasswordConfirmation(e.target.value)}
                        placeholder="Confirm password"
                        className="bg-white dark:bg-slate-900"
                        required
                      />
                    </div>
                    <Button type="submit" size="sm" className="w-full" disabled={isCreatingAccount}>
                      {isCreatingAccount ? (
                        <>
                          <Icon icon="solar:spinner-bold-duotone" className="mr-2 h-4 w-4 animate-spin" />
                          Creating...
                        </>
                      ) : (
                        <>
                          <Icon icon="solar:user-plus-bold-duotone" className="mr-2 h-4 w-4" />
                          Create Account
                        </>
                      )}
                    </Button>
                  </form>
                </div>
              ) : accountCreated ? (
                <div className="bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 p-4 rounded-lg">
                  <div className="flex items-center gap-2 text-green-800 dark:text-green-200 mb-2">
                    <Icon icon="solar:check-circle-bold-duotone" className="h-5 w-5" />
                    <span className="font-medium">Account Created!</span>
                  </div>
                  <p className="text-xs text-green-700 dark:text-green-300">
                    Your account has been created successfully. You can now view your order history.
                  </p>
                </div>
              ) : null}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
