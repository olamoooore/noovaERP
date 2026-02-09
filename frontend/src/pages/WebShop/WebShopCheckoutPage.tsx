import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { loadStripe, Stripe } from '@stripe/stripe-js'
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Separator } from '@/components/ui/separator'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { CheckoutLoyaltyWidget } from './components/CheckoutLoyaltyWidget'

interface CartItem {
  product_id: number
  name: string
  price: number
  quantity: number
  image?: string
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

interface CheckoutFormData {
  email: string
  phone: string
  shipping_address: ShippingAddress
  notes: string
}

interface PaymentIntentData {
  client_secret: string
  publishable_key: string
  order_number: string
  order_id: number
  total: number
  guest_token: string
}

const defaultFormData: CheckoutFormData = {
  email: '',
  phone: '',
  shipping_address: {
    first_name: '',
    last_name: '',
    address: '',
    city: '',
    state: '',
    postal_code: '',
    country: 'US',
  },
  notes: '',
}

function PaymentForm({ 
  paymentData, 
  onSuccess 
}: { 
  paymentData: PaymentIntentData
  onSuccess: () => void
}) {
  const stripe = useStripe()
  const elements = useElements()
  const { toast } = useToast()
  const [isProcessing, setIsProcessing] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string>('')

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!stripe || !elements) {
      return
    }

    setIsProcessing(true)
    setErrorMessage('')

    try {
      const { error, paymentIntent } = await stripe.confirmPayment({
        elements,
        confirmParams: {
          return_url: `${window.location.origin}/web-shop/order/success`,
        },
        redirect: 'if_required',
      })

      if (error) {
        setErrorMessage(error.message || 'Payment failed')
        toast({
          variant: 'destructive',
          title: 'Payment failed',
          description: error.message,
        })
      } else if (paymentIntent && paymentIntent.status === 'succeeded') {
        // Confirm payment on backend (updates order, creates invoice, awards loyalty points)
        try {
          await api.post('/web-shop/checkout/confirm-payment', {
            order_id: paymentData.order_id,
            payment_intent_id: paymentIntent.id,
            guest_token: paymentData.guest_token,
          })
        } catch (confirmErr) {
          console.warn('Payment confirmation sync failed (payment was successful):', confirmErr)
        }

        toast({
          title: 'Payment successful!',
          description: `Your order ${paymentData.order_number} has been placed.`,
        })
        onSuccess()
      }
    } catch (err: any) {
      setErrorMessage(err.message || 'An unexpected error occurred')
    } finally {
      setIsProcessing(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <div className="bg-muted p-4 rounded-lg">
        <div className="flex justify-between items-center mb-2">
          <span className="text-sm text-muted-foreground">Order Number</span>
          <span className="font-medium">{paymentData.order_number}</span>
        </div>
        <div className="flex justify-between items-center">
          <span className="text-sm text-muted-foreground">Total Amount</span>
          <span className="text-2xl font-bold">
            ${Number(paymentData.total).toFixed(2)}
          </span>
        </div>
      </div>

      <PaymentElement />

      {errorMessage && (
        <Alert variant="destructive">
          <Icon icon="solar:danger-triangle-bold-duotone" className="h-4 w-4" />
          <AlertDescription>{errorMessage}</AlertDescription>
        </Alert>
      )}

      <div className="bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 p-4 rounded-lg">
        <p className="text-sm text-blue-900 dark:text-blue-100 font-medium mb-2">
          <Icon icon="solar:info-circle-bold-duotone" className="inline mr-1" />
          Test Mode - Use Test Card
        </p>
        <div className="space-y-1 text-xs text-blue-800 dark:text-blue-200">
          <p>• Card: <code className="bg-blue-100 dark:bg-blue-900 px-2 py-0.5 rounded">4242 4242 4242 4242</code></p>
          <p>• Expiry: Any future date (e.g., 12/25)</p>
          <p>• CVC: Any 3 digits (e.g., 123)</p>
        </div>
      </div>

      <Button 
        type="submit" 
        className="w-full" 
        size="lg"
        disabled={!stripe || isProcessing}
      >
        {isProcessing ? (
          <>
            <Icon icon="solar:spinner-bold-duotone" className="mr-2 h-5 w-5 animate-spin" />
            Processing...
          </>
        ) : (
          <>
            <Icon icon="solar:lock-keyhole-bold-duotone" className="mr-2 h-5 w-5" />
            Pay ${Number(paymentData.total).toFixed(2)}
          </>
        )}
      </Button>

      <p className="text-xs text-center text-muted-foreground">
        <Icon icon="solar:shield-check-bold-duotone" className="inline mr-1" />
        Secured by Stripe
      </p>
    </form>
  )
}

function OrderSummary({
  items,
  subtotal,
  tax,
  shipping,
  total,
  loyaltyDiscount,
}: {
  items: CartItem[]
  subtotal: number
  tax: number
  shipping: number
  total: number
  loyaltyDiscount?: number
}) {
  return (
    <Card className="h-fit sticky top-4">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Icon icon="solar:cart-check-bold-duotone" className="h-5 w-5" />
          Order Summary
        </CardTitle>
        <CardDescription>
          {items.length} {items.length === 1 ? 'item' : 'items'} in your cart
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="space-y-3 max-h-64 overflow-y-auto">
          {items.map((item, index) => (
            <div key={index} className="flex gap-3 p-2 bg-muted/50 rounded-lg">
              {item.image ? (
                <img 
                  src={item.image} 
                  alt={item.name}
                  className="w-16 h-16 object-cover rounded-md"
                />
              ) : (
                <div className="w-16 h-16 bg-muted rounded-md flex items-center justify-center">
                  <Icon icon="solar:box-bold-duotone" className="h-6 w-6 text-muted-foreground" />
                </div>
              )}
              <div className="flex-1 min-w-0">
                <p className="font-medium text-sm truncate">{item.name}</p>
                <p className="text-xs text-muted-foreground">Qty: {item.quantity}</p>
                <p className="text-sm font-medium">${(item.price * item.quantity).toFixed(2)}</p>
              </div>
            </div>
          ))}
        </div>

        <Separator />

          <div className="space-y-2">
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Subtotal</span>
              <span>${subtotal.toFixed(2)}</span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Tax (10%)</span>
              <span>${tax.toFixed(2)}</span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Shipping</span>
              <span className="text-green-600">{shipping === 0 ? 'Free' : `$${shipping.toFixed(2)}`}</span>
            </div>
            {loyaltyDiscount && loyaltyDiscount > 0 && (
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Loyalty Discount</span>
                <span className="text-green-600">-${loyaltyDiscount.toFixed(2)}</span>
              </div>
            )}
            <Separator />
            <div className="flex justify-between font-medium text-lg">
              <span>Total</span>
              <span>${total.toFixed(2)}</span>
            </div>
          </div>

        <div className="bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 p-3 rounded-lg">
          <div className="flex items-center gap-2 text-green-800 dark:text-green-200 text-sm">
            <Icon icon="solar:shield-check-bold-duotone" className="h-4 w-4" />
            <span>Secure Checkout</span>
          </div>
          <p className="text-xs text-green-700 dark:text-green-300 mt-1">
            Your information is protected with industry-standard encryption
          </p>
        </div>
      </CardContent>
    </Card>
  )
}

export function WebShopCheckoutPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const [cartItems, setCartItems] = useState<CartItem[]>([])
  const [formData, setFormData] = useState<CheckoutFormData>(defaultFormData)
  const [isLoading, setIsLoading] = useState(false)
  const [paymentData, setPaymentData] = useState<PaymentIntentData | null>(null)
  const [stripePromise, setStripePromise] = useState<Promise<Stripe | null> | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [loyaltyRedemption, setLoyaltyRedemption] = useState({ points: 0, discount: 0 })

  useEffect(() => {
    // Load cart from localStorage - support both ecommerce_cart (shop pages) and ace_cart (legacy)
    const ecommerceCart = localStorage.getItem('ecommerce_cart')
    const aceCart = localStorage.getItem('ace_cart')
    const cartRaw = ecommerceCart || aceCart
    
    if (cartRaw) {
      try {
        const parsed = JSON.parse(cartRaw)
        if (Array.isArray(parsed) && parsed.length > 0) {
          // Normalize cart items - ecommerce_cart uses productId, ace_cart uses product_id
          const normalized = parsed.map((item: any) => ({
            product_id: item.product_id || item.productId,
            name: item.name,
            price: Number(item.price),
            quantity: Number(item.quantity),
            image: item.image || undefined,
          }))
          setCartItems(normalized)
        } else {
          toast({
            variant: 'destructive',
            title: 'Empty Cart',
            description: 'Your cart is empty. Add items to continue.',
          })
          navigate('/')
        }
      } catch {
        navigate('/')
      }
    } else {
      navigate('/')
    }
  }, [navigate, toast])

  const calculateTotals = () => {
    const subtotal = cartItems.reduce((sum, item) => sum + item.price * item.quantity, 0)
    const tax = subtotal * 0.10
    const shipping = 0 // Free shipping
    const total = subtotal + tax + shipping - loyaltyRedemption.discount
    return { subtotal, tax, shipping, total, loyaltyDiscount: loyaltyRedemption.discount }
  }

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!formData.email) {
      newErrors.email = 'Email is required'
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      newErrors.email = 'Please enter a valid email'
    }

    if (!formData.shipping_address.first_name) {
      newErrors.first_name = 'First name is required'
    }

    if (!formData.shipping_address.last_name) {
      newErrors.last_name = 'Last name is required'
    }

    if (!formData.shipping_address.address) {
      newErrors.address = 'Address is required'
    }

    if (!formData.shipping_address.city) {
      newErrors.city = 'City is required'
    }

    if (!formData.shipping_address.state) {
      newErrors.state = 'State is required'
    }

    if (!formData.shipping_address.postal_code) {
      newErrors.postal_code = 'Postal code is required'
    }

    if (!formData.shipping_address.country) {
      newErrors.country = 'Country is required'
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleInputChange = (field: keyof CheckoutFormData, value: string) => {
    setFormData(prev => ({ ...prev, [field]: value }))
    // Clear error when user starts typing
    if (errors[field]) {
      setErrors(prev => ({ ...prev, [field]: '' }))
    }
  }

  const handleAddressChange = (field: keyof ShippingAddress, value: string) => {
    setFormData(prev => ({
      ...prev,
      shipping_address: { ...prev.shipping_address, [field]: value }
    }))
    if (errors[field]) {
      setErrors(prev => ({ ...prev, [field]: '' }))
    }
  }

  const handleProceedToPayment = async () => {
    if (!validateForm()) {
      toast({
        variant: 'destructive',
        title: 'Validation Error',
        description: 'Please fill in all required fields.',
      })
      return
    }

    setIsLoading(true)

    try {
      const items = cartItems.map(item => ({
        product_id: item.product_id,
        quantity: item.quantity,
      }))

      const response = await api.post('/web-shop/checkout/guest', {
        items,
        email: formData.email,
        phone: formData.phone || undefined,
        shipping_address: formData.shipping_address,
        notes: formData.notes || undefined,
        loyalty_points_to_redeem: loyaltyRedemption.points > 0 ? loyaltyRedemption.points : undefined,
      })

      const data = response.data

      // Extract payment data - handle nested payment_intent structure from backend
      const client_secret = data.payment_intent?.client_secret || data.client_secret
      const publishable_key = data.payment_intent?.publishable_key || data.publishable_key
      const order_number = data.order_number
      const total = data.total
      const guest_token = data.guest_token

      // Store guest token for success page
      if (guest_token) {
        localStorage.setItem('ace_guest_token', guest_token)
      }

      setPaymentData({
        client_secret,
        publishable_key,
        order_number,
        order_id: data.order_id,
        total,
        guest_token: guest_token || '',
      })

      // Initialize Stripe
      setStripePromise(loadStripe(publishable_key))
    } catch (err: any) {
      console.error('Checkout error:', err)
      toast({
        variant: 'destructive',
        title: 'Checkout Failed',
        description: err.response?.data?.message || 'Failed to create order. Please try again.',
      })
    } finally {
      setIsLoading(false)
    }
  }

  const handlePaymentSuccess = () => {
    // Clear both cart keys
    localStorage.removeItem('ace_cart')
    localStorage.removeItem('ecommerce_cart')
    
    // Get guest token and redirect to success page
    const guestToken = localStorage.getItem('ace_guest_token')
    if (guestToken) {
      navigate(`/web-shop/order/success?token=${guestToken}`)
    } else {
      navigate('/web-shop/order/success')
    }
  }

  const { subtotal, tax, shipping, total, loyaltyDiscount } = calculateTotals()

  return (
    <div className="container mx-auto py-8 px-4">
      <div className="mb-6">
        <Button variant="ghost" onClick={() => navigate(-1)} className="mb-4">
          <Icon icon="solar:arrow-left-bold-duotone" className="mr-2 h-4 w-4" />
          Back to Cart
        </Button>
        <h1 className="text-3xl font-bold">Checkout</h1>
        <p className="text-muted-foreground">Complete your purchase securely</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {/* Left Column - Forms */}
        <div className="lg:col-span-2 space-y-6">
          {!paymentData ? (
            // Shipping Information Form
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon icon="solar:delivery-bold-duotone" className="h-5 w-5" />
                  Shipping Information
                </CardTitle>
                <CardDescription>
                  Enter your contact and shipping details
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-6">
                {/* Contact Information */}
                <div className="space-y-4">
                  <h3 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
                    Contact Information
                  </h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="email">
                        Email <span className="text-red-500">*</span>
                      </Label>
                      <Input
                        id="email"
                        type="email"
                        placeholder="your@email.com"
                        value={formData.email}
                        onChange={(e) => handleInputChange('email', e.target.value)}
                        className={errors.email ? 'border-red-500' : ''}
                      />
                      {errors.email && (
                        <p className="text-xs text-red-500">{errors.email}</p>
                      )}
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="phone">Phone (optional)</Label>
                      <Input
                        id="phone"
                        type="tel"
                        placeholder="+1 234 567 890"
                        value={formData.phone}
                        onChange={(e) => handleInputChange('phone', e.target.value)}
                      />
                    </div>
                  </div>
                </div>

                <Separator />

                {/* Shipping Address */}
                <div className="space-y-4">
                  <h3 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
                    Shipping Address
                  </h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="first_name">
                        First Name <span className="text-red-500">*</span>
                      </Label>
                      <Input
                        id="first_name"
                        placeholder="John"
                        value={formData.shipping_address.first_name}
                        onChange={(e) => handleAddressChange('first_name', e.target.value)}
                        className={errors.first_name ? 'border-red-500' : ''}
                      />
                      {errors.first_name && (
                        <p className="text-xs text-red-500">{errors.first_name}</p>
                      )}
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="last_name">
                        Last Name <span className="text-red-500">*</span>
                      </Label>
                      <Input
                        id="last_name"
                        placeholder="Doe"
                        value={formData.shipping_address.last_name}
                        onChange={(e) => handleAddressChange('last_name', e.target.value)}
                        className={errors.last_name ? 'border-red-500' : ''}
                      />
                      {errors.last_name && (
                        <p className="text-xs text-red-500">{errors.last_name}</p>
                      )}
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="address">
                      Address <span className="text-red-500">*</span>
                    </Label>
                    <Input
                      id="address"
                      placeholder="123 Main Street, Apt 4B"
                      value={formData.shipping_address.address}
                      onChange={(e) => handleAddressChange('address', e.target.value)}
                      className={errors.address ? 'border-red-500' : ''}
                    />
                    {errors.address && (
                      <p className="text-xs text-red-500">{errors.address}</p>
                    )}
                  </div>

                  <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="city">
                        City <span className="text-red-500">*</span>
                      </Label>
                      <Input
                        id="city"
                        placeholder="New York"
                        value={formData.shipping_address.city}
                        onChange={(e) => handleAddressChange('city', e.target.value)}
                        className={errors.city ? 'border-red-500' : ''}
                      />
                      {errors.city && (
                        <p className="text-xs text-red-500">{errors.city}</p>
                      )}
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="state">
                        State <span className="text-red-500">*</span>
                      </Label>
                      <Input
                        id="state"
                        placeholder="NY"
                        value={formData.shipping_address.state}
                        onChange={(e) => handleAddressChange('state', e.target.value)}
                        className={errors.state ? 'border-red-500' : ''}
                      />
                      {errors.state && (
                        <p className="text-xs text-red-500">{errors.state}</p>
                      )}
                    </div>
                    <div className="space-y-2 col-span-2 md:col-span-1">
                      <Label htmlFor="postal_code">
                        Postal Code <span className="text-red-500">*</span>
                      </Label>
                      <Input
                        id="postal_code"
                        placeholder="10001"
                        value={formData.shipping_address.postal_code}
                        onChange={(e) => handleAddressChange('postal_code', e.target.value)}
                        className={errors.postal_code ? 'border-red-500' : ''}
                      />
                      {errors.postal_code && (
                        <p className="text-xs text-red-500">{errors.postal_code}</p>
                      )}
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="country">
                      Country <span className="text-red-500">*</span>
                    </Label>
                    <Input
                      id="country"
                      placeholder="US"
                      value={formData.shipping_address.country}
                      onChange={(e) => handleAddressChange('country', e.target.value)}
                      className={errors.country ? 'border-red-500' : ''}
                    />
                    {errors.country && (
                      <p className="text-xs text-red-500">{errors.country}</p>
                    )}
                  </div>
                </div>

                <Separator />

                {/* Order Notes */}
                <div className="space-y-2">
                  <Label htmlFor="notes">Order Notes (optional)</Label>
                  <Textarea
                    id="notes"
                    placeholder="Special instructions for delivery..."
                    value={formData.notes}
                    onChange={(e) => handleInputChange('notes', e.target.value)}
                    rows={3}
                  />
                </div>

                <Button 
                  onClick={handleProceedToPayment}
                  className="w-full"
                  size="lg"
                  disabled={isLoading}
                >
                  {isLoading ? (
                    <>
                      <Icon icon="solar:spinner-bold-duotone" className="mr-2 h-5 w-5 animate-spin" />
                      Processing...
                    </>
                  ) : (
                    <>
                      <Icon icon="solar:card-send-bold-duotone" className="mr-2 h-5 w-5" />
                      Proceed to Payment
                    </>
                  )}
                </Button>
              </CardContent>
            </Card>
          ) : (
            // Payment Section
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon icon="solar:card-bold-duotone" className="h-5 w-5" />
                  Payment
                </CardTitle>
                <CardDescription>
                  Complete your payment securely with Stripe
                </CardDescription>
              </CardHeader>
              <CardContent>
                {stripePromise && (
                  <Elements
                    stripe={stripePromise}
                    options={{
                      clientSecret: paymentData.client_secret,
                      appearance: {
                        theme: 'stripe',
                      },
                    }}
                  >
                    <PaymentForm 
                      paymentData={paymentData}
                      onSuccess={handlePaymentSuccess}
                    />
                  </Elements>
                )}
              </CardContent>
            </Card>
          )}
        </div>

        {/* Right Column - Order Summary */}
        <div className="lg:col-span-1 space-y-4">
          {!paymentData && (
            <CheckoutLoyaltyWidget
              orderAmount={subtotal}
              onRedeem={(points, discount) => setLoyaltyRedemption({ points, discount })}
              appliedPoints={loyaltyRedemption.points}
              appliedDiscount={loyaltyRedemption.discount}
            />
          )}
          <OrderSummary
            items={cartItems}
            subtotal={subtotal}
            tax={tax}
            shipping={shipping}
            total={total}
            loyaltyDiscount={loyaltyDiscount}
          />
        </div>
      </div>
    </div>
  )
}
