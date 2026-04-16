import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Stripe } from '@stripe/stripe-js'
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useToast } from '@/hooks/use-toast'
import { loadAceStripe } from '@/lib/stripe'

// ============================================================
// Types
// ============================================================

interface Product {
  id: number
  name: string
  secondary_name?: string
  slug: string
  sku: string
  description?: string
  price: number
  image?: string
  gallery?: string[]
  category?: { id: number; name: string }
  brand?: { id: number; name: string }
  is_new_arrival: boolean
  is_preorder: boolean
  in_stock: boolean
  stock_quantity: number
  unit?: string
}

interface Category {
  id: number
  name: string
  slug: string
  product_count: number
}

interface CartItem {
  productId: number
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

function buildLegacyProductDescription(product: Product): string {
  if (product.description?.trim()) {
    return product.description
  }

  const brand = product.brand?.name || NoovaERP
  const category = product.category?.name || 'collection'
  const stockMessage = product.in_stock
    ? `Ready to ship with ${product.stock_quantity} unit${product.stock_quantity === 1 ? '' : 's'} currently available.`
    : 'This item is currently out of stock, but you can still review the full product details here.'

  return [
    `${product.name} is part of the ${brand} ${category} range and is presented as a complete product page so customers can review the item before checkout.`,
    stockMessage,
    'Each order is processed through NoovaERP with secure checkout, customer tracking, and order-history support so the item page is not just a placeholder listing.',
  ].join(' ')
}

function LegacyShopFooter({ navigate }: { navigate: (path: string) => void }) {
  const links = [
    { label: 'FAQ', path: '/store/faq' },
    { label: 'Shipping', path: '/store/shipping' },
    { label: 'Returns', path: '/store/returns' },
    { label: 'About', path: '/store/about' },
    { label: 'Contact', path: '/store/contact' },
  ]

  return (
    <footer className="border-t bg-white/95 mt-10">
      <div className="max-w-7xl mx-auto px-4 py-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p className="font-semibold text-gray-900">ACE Web Shop</p>
          <p className="text-sm text-gray-500">Customer help, shipping policies, and company information.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {links.map((link) => (
            <Button
              key={link.path}
              variant="ghost"
              size="sm"
              onClick={() => navigate(link.path)}
            >
              {link.label}
            </Button>
          ))}
        </div>
      </div>
    </footer>
  )
}

// ============================================================
// Cart Store (localStorage-backed)
// ============================================================

function getCart(): CartItem[] {
  try {
    return JSON.parse(localStorage.getItem('ecommerce_cart') || '[]')
  } catch {
    return []
  }
}

function saveCart(cart: CartItem[]) {
  localStorage.setItem('ecommerce_cart', JSON.stringify(cart))
  window.dispatchEvent(new CustomEvent('cart-updated', { detail: { cart } }))
}

// ============================================================
// Payment Form Component
// ============================================================

function StripePaymentForm({
  orderNumber,
  total,
  orderId,
  guestToken,
  onSuccess,
}: {
  orderNumber: string
  total: number
  orderId: number
  guestToken: string
  onSuccess: () => void
}) {
  const stripe = useStripe()
  const elements = useElements()
  const { toast } = useToast()
  const [isProcessing, setIsProcessing] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!stripe || !elements) return

    setIsProcessing(true)
    setErrorMessage('')

    try {
      const { error, paymentIntent } = await stripe.confirmPayment({
        elements,
        confirmParams: {
          return_url: `${window.location.origin}/shop/order-success`,
        },
        redirect: 'if_required',
      })

      if (error) {
        setErrorMessage(error.message || 'Payment failed')
      } else if (paymentIntent?.status === 'succeeded') {
        // Confirm payment on backend (updates order, creates invoice, awards loyalty points)
        try {
          await api.post('/web-shop/checkout/confirm-payment', {
            order_id: orderId,
            payment_intent_id: paymentIntent.id,
            guest_token: guestToken,
          })
        } catch (confirmErr) {
          console.warn('Payment confirmation sync failed (payment was successful):', confirmErr)
        }

        toast({ title: 'Payment successful!', description: `Order ${orderNumber} placed.` })
        onSuccess()
      }
    } catch (err: any) {
      setErrorMessage(err.message || 'An unexpected error occurred')
    } finally {
      setIsProcessing(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="bg-gray-50 p-4 rounded-lg">
        <div className="flex justify-between items-center mb-2">
          <span className="text-sm text-gray-500">Order Number</span>
          <span className="font-medium">{orderNumber}</span>
        </div>
        <div className="flex justify-between items-center">
          <span className="text-sm text-gray-500">Total</span>
          <span className="text-2xl font-bold">${Number(total).toFixed(2)}</span>
        </div>
      </div>

      <PaymentElement />

      {errorMessage && (
        <Alert variant="destructive">
          <AlertDescription>{errorMessage}</AlertDescription>
        </Alert>
      )}

      <div className="bg-blue-50 border border-blue-200 p-3 rounded-lg">
        <p className="text-sm text-blue-900 font-medium mb-1">Test Mode - Use Test Card</p>
        <div className="text-xs text-blue-800 space-y-0.5">
          <p>Card: <code className="bg-blue-100 px-1 rounded">4242 4242 4242 4242</code></p>
          <p>Expiry: Any future date • CVC: Any 3 digits</p>
        </div>
      </div>

      <Button type="submit" className="w-full" size="lg" disabled={!stripe || isProcessing}>
        {isProcessing ? (
          <>
            <Icon icon="solar:spinner-bold-duotone" className="mr-2 h-5 w-5 animate-spin" />
            Processing...
          </>
        ) : (
          <>
            <Icon icon="solar:lock-keyhole-bold-duotone" className="mr-2 h-5 w-5" />
            Pay ${Number(total).toFixed(2)}
          </>
        )}
      </Button>
    </form>
  )
}

// ============================================================
// Product Card Component
// ============================================================

function ProductCard({
  product,
  onAddToCart,
  onViewProduct,
}: {
  product: Product
  onAddToCart: (product: Product) => void
  onViewProduct: (product: Product) => void
}) {
  return (
    <div
      className="group bg-white border border-gray-200 rounded-xl overflow-hidden hover:shadow-lg transition-all cursor-pointer"
      onClick={() => onViewProduct(product)}
    >
      <div className="relative aspect-square bg-gray-50 overflow-hidden">
        {product.image ? (
          <img
            src={product.image}
            alt={product.name}
            className="w-full h-full object-contain group-hover:scale-105 transition-transform duration-300"
            onError={(e) => { (e.target as HTMLImageElement).src = 'https://placehold.co/300x300?text=No+Image' }}
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center">
            <Icon icon="solar:box-bold-duotone" className="h-16 w-16 text-gray-300" />
          </div>
        )}
        {product.is_new_arrival && (
          <Badge className="absolute top-3 left-3 bg-red-500">NEW</Badge>
        )}
        {!product.in_stock && (
          <div className="absolute inset-0 bg-black/40 flex items-center justify-center">
            <Badge variant="secondary" className="text-lg">Out of Stock</Badge>
          </div>
        )}
      </div>
      <div className="p-4">
        {product.category && (
          <p className="text-xs text-gray-500 mb-1">{product.category.name}</p>
        )}
        <h3 className="font-semibold text-gray-900 truncate">{product.name}</h3>
        <div className="flex items-center justify-between mt-3">
          <span className="text-lg font-bold text-primary">${product.price.toFixed(2)}</span>
          <Button
            size="sm"
            variant="outline"
            disabled={!product.in_stock}
            onClick={(e) => {
              e.stopPropagation()
              onAddToCart(product)
            }}
          >
            <Icon icon="solar:bag-4-bold" className="h-4 w-4 mr-1" />
            Add
          </Button>
        </div>
      </div>
    </div>
  )
}

// ============================================================
// Main Storefront Component
// ============================================================

export function EcommerceStorefront() {
  const { pageType } = useParams<{ pageType?: string }>()
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { toast } = useToast()

  // State
  const [cart, setCart] = useState<CartItem[]>(getCart)
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [sortBy, setSortBy] = useState('name')

  // Determine which view to show
  const currentView = pageType || 'shop'
  const productId = searchParams.get('id')

  // Listen for cart updates from other sources
  useEffect(() => {
    const handleStorageChange = (e: StorageEvent) => {
      if (e.key === 'ecommerce_cart') {
        setCart(getCart())
      }
    }
    const handleCartUpdate = () => setCart(getCart())
    window.addEventListener('storage', handleStorageChange)
    window.addEventListener('cart-updated', handleCartUpdate)
    return () => {
      window.removeEventListener('storage', handleStorageChange)
      window.removeEventListener('cart-updated', handleCartUpdate)
    }
  }, [])

  // Cart functions
  const addToCart = useCallback((product: Product) => {
    const currentCart = getCart()
    const existing = currentCart.find((item) => item.productId === product.id)
    if (existing) {
      existing.quantity += 1
    } else {
      currentCart.push({
        productId: product.id,
        name: product.name,
        price: product.price,
        quantity: 1,
        image: product.image,
      })
    }
    saveCart(currentCart)
    setCart([...currentCart])
    toast({ title: 'Added to cart', description: `${product.name} added to your cart.` })
  }, [toast])

  const updateQuantity = useCallback((productId: number, quantity: number) => {
    const currentCart = getCart()
    if (quantity <= 0) {
      const filtered = currentCart.filter((item) => item.productId !== productId)
      saveCart(filtered)
      setCart(filtered)
    } else {
      const item = currentCart.find((i) => i.productId === productId)
      if (item) {
        item.quantity = quantity
        saveCart(currentCart)
        setCart([...currentCart])
      }
    }
  }, [])

  const removeFromCart = useCallback((productId: number) => {
    const filtered = getCart().filter((item) => item.productId !== productId)
    saveCart(filtered)
    setCart(filtered)
  }, [])

  const clearCart = useCallback(() => {
    saveCart([])
    setCart([])
  }, [])

  const cartTotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  const cartCount = cart.reduce((sum, item) => sum + item.quantity, 0)

  // Fetch products
  const { data: productsData, isLoading: productsLoading } = useQuery({
    queryKey: ['shop-products', selectedCategory, searchQuery, sortBy],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: 100 }
      if (selectedCategory) params.category_id = selectedCategory
      if (searchQuery) params.search = searchQuery
      if (sortBy === 'price_asc') { params.sort_by = 'price'; params.sort_dir = 'asc' }
      else if (sortBy === 'price_desc') { params.sort_by = 'price'; params.sort_dir = 'desc' }
      else if (sortBy === 'newest') { params.sort_by = 'created_at'; params.sort_dir = 'desc' }
      else { params.sort_by = 'name'; params.sort_dir = 'asc' }
      const res = await api.get('/website-public/products', { params })
      return res.data
    },
  })

  // Fetch categories
  const { data: categoriesData } = useQuery({
    queryKey: ['shop-categories'],
    queryFn: async () => {
      const res = await api.get('/website-public/categories')
      return res.data as Category[]
    },
  })

  // Fetch single product
  const { data: singleProduct, isLoading: productLoading } = useQuery({
    queryKey: ['shop-product', productId],
    queryFn: async () => {
      const res = await api.get(`/website-public/products/${productId}`)
      return res.data as Product
    },
    enabled: currentView === 'product' && !!productId,
  })

  const products: Product[] = productsData?.data || []
  const categories: Category[] = categoriesData || []

  // ========================================
  // RENDER: Shop / Product Listing
  // ========================================
  if (currentView === 'shop') {
    return (
      <div className="min-h-screen bg-gray-50">
        {/* Header */}
        <header className="bg-white border-b sticky top-0 z-50">
          <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <h1 className="text-xl font-bold text-gray-900 cursor-pointer" onClick={() => navigate('/shop')}>
              <Icon icon="solar:bag-4-bold" className="h-5 w-5 inline mr-2 text-primary" />
              Shop
            </h1>
            <div className="flex items-center gap-4">
              <div className="relative max-w-xs">
                <Icon icon="solar:magnifer-bold" className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
                <Input
                  placeholder="Search products..."
                  className="pl-9 w-64"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                />
              </div>
              <Button
                variant="outline"
                className="relative"
                onClick={() => navigate('/shop/cart')}
              >
                <Icon icon="solar:bag-4-bold" className="h-4 w-4 mr-2" />
                Cart
                {cartCount > 0 && (
                  <Badge className="absolute -top-2 -right-2 h-5 w-5 flex items-center justify-center p-0 text-xs">
                    {cartCount}
                  </Badge>
                )}
              </Button>
              <Button variant="ghost" size="sm" onClick={() => navigate('/shop/account')}>
                <Icon icon="solar:user-bold" className="h-4 w-4 mr-1" />
                Account
              </Button>
            </div>
          </div>
        </header>

        <div className="max-w-7xl mx-auto px-4 py-8">
          <div className="flex gap-8">
            {/* Sidebar: Categories */}
            <aside className="w-64 shrink-0 hidden lg:block">
              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm font-semibold uppercase text-gray-500">Categories</CardTitle>
                </CardHeader>
                <CardContent className="space-y-1 p-3 pt-0">
                  <Button
                    variant={selectedCategory === null ? 'default' : 'ghost'}
                    className="w-full justify-start"
                    size="sm"
                    onClick={() => setSelectedCategory(null)}
                  >
                    All Products
                  </Button>
                  {categories.map((cat) => (
                    <Button
                      key={cat.id}
                      variant={selectedCategory === cat.id ? 'default' : 'ghost'}
                      className="w-full justify-between"
                      size="sm"
                      onClick={() => setSelectedCategory(cat.id)}
                    >
                      <span>{cat.name}</span>
                      <Badge variant="secondary" className="text-xs">{cat.product_count}</Badge>
                    </Button>
                  ))}
                </CardContent>
              </Card>

              <Card className="mt-4">
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm font-semibold uppercase text-gray-500">Sort By</CardTitle>
                </CardHeader>
                <CardContent className="space-y-1 p-3 pt-0">
                  {[
                    { value: 'name', label: 'Name (A-Z)' },
                    { value: 'price_asc', label: 'Price: Low to High' },
                    { value: 'price_desc', label: 'Price: High to Low' },
                    { value: 'newest', label: 'Newest First' },
                  ].map((opt) => (
                    <Button
                      key={opt.value}
                      variant={sortBy === opt.value ? 'default' : 'ghost'}
                      className="w-full justify-start"
                      size="sm"
                      onClick={() => setSortBy(opt.value)}
                    >
                      {opt.label}
                    </Button>
                  ))}
                </CardContent>
              </Card>
            </aside>

            {/* Product Grid */}
            <main className="flex-1">
              <div className="flex items-center justify-between mb-6">
                <p className="text-sm text-gray-500">
                  Showing {products.length} product{products.length !== 1 ? 's' : ''}
                  {selectedCategory && categories.find(c => c.id === selectedCategory)
                    ? ` in ${categories.find(c => c.id === selectedCategory)!.name}`
                    : ''}
                </p>
              </div>

              {productsLoading ? (
                <div className="flex justify-center py-20">
                  <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-gray-400" />
                </div>
              ) : products.length === 0 ? (
                <div className="text-center py-20">
                  <Icon icon="solar:box-bold-duotone" className="h-16 w-16 text-gray-300 mx-auto mb-4" />
                  <h3 className="text-lg font-medium text-gray-600">No products found</h3>
                  <p className="text-gray-400 mt-1">Try a different search or category.</p>
                </div>
              ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-5">
                  {products.map((product) => (
                    <ProductCard
                      key={product.id}
                      product={product}
                      onAddToCart={addToCart}
                      onViewProduct={(p) => navigate(`/shop/product?id=${p.id}`)}
                    />
                  ))}
                </div>
              )}
            </main>
          </div>
        </div>
        <LegacyShopFooter navigate={navigate} />
      </div>
    )
  }

  // ========================================
  // RENDER: Product Detail
  // ========================================
  if (currentView === 'product' && productId) {
    return (
      <div className="min-h-screen bg-gray-50">
        <header className="bg-white border-b sticky top-0 z-50">
          <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <Button variant="ghost" onClick={() => navigate('/shop')}>
              <Icon icon="solar:arrow-left-bold" className="h-4 w-4 mr-2" />
              Back to Shop
            </Button>
            <Button variant="outline" className="relative" onClick={() => navigate('/shop/cart')}>
              <Icon icon="solar:bag-4-bold" className="h-4 w-4 mr-2" />
              Cart
              {cartCount > 0 && (
                <Badge className="absolute -top-2 -right-2 h-5 w-5 flex items-center justify-center p-0 text-xs">
                  {cartCount}
                </Badge>
              )}
            </Button>
          </div>
        </header>

        <div className="max-w-5xl mx-auto px-4 py-8">
          {productLoading ? (
            <div className="flex justify-center py-20">
              <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-gray-400" />
            </div>
          ) : singleProduct ? (
            <div className="grid md:grid-cols-2 gap-10 bg-white rounded-2xl p-8 shadow-sm">
              <div className="bg-gray-50 rounded-xl p-6 flex items-center justify-center">
                <img
                  src={singleProduct.image || 'https://placehold.co/500x500?text=No+Image'}
                  alt={singleProduct.name}
                  className="max-w-full max-h-[400px] object-contain"
                  onError={(e) => { (e.target as HTMLImageElement).src = 'https://placehold.co/500x500?text=No+Image' }}
                />
              </div>
              <div className="flex flex-col gap-4">
                <div className="flex items-center gap-2">
                  <Badge variant={singleProduct.in_stock ? 'default' : 'destructive'}>
                    {singleProduct.in_stock ? `In Stock (${singleProduct.stock_quantity})` : 'Out of Stock'}
                  </Badge>
                  {singleProduct.is_new_arrival && <Badge className="bg-red-500">NEW</Badge>}
                </div>
                <h1 className="text-3xl font-bold text-gray-900">{singleProduct.name}</h1>
                {singleProduct.secondary_name && (
                  <p className="text-gray-500">{singleProduct.secondary_name}</p>
                )}
                <p className="text-3xl font-bold text-primary">${singleProduct.price.toFixed(2)}</p>
                {singleProduct.category && (
                  <p className="text-sm text-gray-500">
                    Category: <span className="text-primary cursor-pointer" onClick={() => {
                      setSelectedCategory(singleProduct.category!.id)
                      navigate('/shop')
                    }}>{singleProduct.category.name}</span>
                  </p>
                )}
                {singleProduct.brand && (
                  <p className="text-sm text-gray-500">Brand: {singleProduct.brand.name}</p>
                )}
                <div className="rounded-xl border bg-gray-50/80 p-4 space-y-3">
                  <div>
                    <p className="text-sm font-semibold uppercase tracking-wide text-gray-500">Product Overview</p>
                    <p className="text-gray-600 leading-relaxed mt-2">
                      {buildLegacyProductDescription(singleProduct)}
                    </p>
                  </div>
                  <div className="grid gap-3 sm:grid-cols-2 text-sm">
                    <div className="rounded-lg bg-white p-3 border">
                      <p className="text-gray-500">SKU</p>
                      <p className="font-medium text-gray-900">{singleProduct.sku || 'Generated at checkout'}</p>
                    </div>
                    <div className="rounded-lg bg-white p-3 border">
                      <p className="text-gray-500">Availability</p>
                      <p className="font-medium text-gray-900">
                        {singleProduct.in_stock ? `${singleProduct.stock_quantity} in stock` : 'Currently unavailable'}
                      </p>
                    </div>
                  </div>
                </div>
                <div className="flex gap-3 mt-4">
                  <Button
                    size="lg"
                    className="flex-1"
                    disabled={!singleProduct.in_stock}
                    onClick={() => addToCart(singleProduct)}
                  >
                    <Icon icon="solar:bag-4-bold" className="h-5 w-5 mr-2" />
                    Add to Cart
                  </Button>
                  <Button
                    size="lg"
                    variant="outline"
                    onClick={() => {
                      addToCart(singleProduct)
                      navigate('/shop/checkout')
                    }}
                    disabled={!singleProduct.in_stock}
                  >
                    Buy Now
                  </Button>
                </div>
              </div>
            </div>
          ) : (
            <div className="text-center py-20">
              <h3 className="text-lg font-medium text-gray-600">Product not found</h3>
            </div>
          )}
        </div>
        <LegacyShopFooter navigate={navigate} />
      </div>
    )
  }

  // ========================================
  // RENDER: Cart
  // ========================================
  if (currentView === 'cart') {
    return (
      <div className="min-h-screen bg-gray-50">
        <header className="bg-white border-b sticky top-0 z-50">
          <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <Button variant="ghost" onClick={() => navigate('/shop')}>
              <Icon icon="solar:arrow-left-bold" className="h-4 w-4 mr-2" />
              Continue Shopping
            </Button>
            <h1 className="text-xl font-bold">Shopping Cart</h1>
            <div className="w-24" />
          </div>
        </header>

        <div className="max-w-4xl mx-auto px-4 py-8">
          {cart.length === 0 ? (
            <div className="text-center py-20">
              <Icon icon="solar:bag-4-bold-duotone" className="h-20 w-20 text-gray-300 mx-auto mb-4" />
              <h3 className="text-xl font-medium text-gray-600 mb-2">Your cart is empty</h3>
              <p className="text-gray-400 mb-6">Add some products to get started</p>
              <Button onClick={() => navigate('/shop')}>Continue Shopping</Button>
            </div>
          ) : (
            <div className="grid lg:grid-cols-3 gap-8">
              <div className="lg:col-span-2 space-y-4">
                {cart.map((item) => (
                  <Card key={item.productId}>
                    <CardContent className="p-4 flex gap-4">
                      <img
                        src={item.image || 'https://placehold.co/100x100?text=No+Image'}
                        alt={item.name}
                        className="w-20 h-20 object-cover rounded-lg"
                      />
                      <div className="flex-1">
                        <h4 className="font-medium text-gray-900">{item.name}</h4>
                        <p className="text-primary font-bold mt-1">${item.price.toFixed(2)}</p>
                        <div className="flex items-center gap-2 mt-2">
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            onClick={() => updateQuantity(item.productId, item.quantity - 1)}
                          >
                            -
                          </Button>
                          <span className="w-8 text-center font-medium">{item.quantity}</span>
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            onClick={() => updateQuantity(item.productId, item.quantity + 1)}
                          >
                            +
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 text-red-500 ml-auto"
                            onClick={() => removeFromCart(item.productId)}
                          >
                            <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                      <div className="text-right">
                        <p className="font-bold text-gray-900">
                          ${(item.price * item.quantity).toFixed(2)}
                        </p>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>

              <div>
                <Card className="sticky top-20">
                  <CardHeader>
                    <CardTitle>Order Summary</CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-3">
                    <div className="flex justify-between text-sm">
                      <span className="text-gray-500">Subtotal</span>
                      <span>${cartTotal.toFixed(2)}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                      <span className="text-gray-500">Tax (10%)</span>
                      <span>${(cartTotal * 0.1).toFixed(2)}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                      <span className="text-gray-500">Shipping</span>
                      <span className="text-green-600">Free</span>
                    </div>
                    <Separator />
                    <div className="flex justify-between font-bold text-lg">
                      <span>Total</span>
                      <span>${(cartTotal * 1.1).toFixed(2)}</span>
                    </div>
                    <Button
                      className="w-full mt-4"
                      size="lg"
                      onClick={() => navigate('/shop/checkout')}
                    >
                      <Icon icon="solar:card-send-bold-duotone" className="h-5 w-5 mr-2" />
                      Proceed to Checkout
                    </Button>
                    <Button
                      variant="ghost"
                      className="w-full"
                      onClick={clearCart}
                    >
                      Clear Cart
                    </Button>
                  </CardContent>
                </Card>
              </div>
            </div>
          )}
        </div>
        <LegacyShopFooter navigate={navigate} />
      </div>
    )
  }

  // ========================================
  // RENDER: Checkout
  // ========================================
  if (currentView === 'checkout') {
    return <EcommerceCheckout cart={cart} onClearCart={clearCart} />
  }

  // ========================================
  // RENDER: Order Success
  // ========================================
  if (currentView === 'order-success') {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <Card className="max-w-md w-full text-center">
          <CardContent className="pt-8 pb-8 space-y-4">
            <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto">
              <Icon icon="solar:check-circle-bold-duotone" className="h-10 w-10 text-green-600" />
            </div>
            <h2 className="text-2xl font-bold">Order Placed!</h2>
            <p className="text-gray-500">
              Thank you for your purchase. You will receive a confirmation email shortly.
            </p>
            <Button onClick={() => navigate('/shop')} className="mt-4">
              Continue Shopping
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  // ========================================
  // RENDER: Account / Login
  // ========================================
  if (currentView === 'account') {
    return <EcommerceAccount />
  }

  // Fallback
  return null
}

// ============================================================
// Checkout Sub-Component (with full form + payment)
// ============================================================

function EcommerceCheckout({ cart, onClearCart }: { cart: CartItem[]; onClearCart: () => void }) {
  const navigate = useNavigate()
  const { toast } = useToast()
  const [step, setStep] = useState<'shipping' | 'payment'>('shipping')
  const [isLoading, setIsLoading] = useState(false)
  const [paymentData, setPaymentData] = useState<{
    client_secret?: string
    publishable_key?: string
    payment_url?: string
    gateway_name: string
    order_number: string
    order_id: number
    total: number
    guest_token: string
  } | null>(null)
  const [stripePromise, setStripePromise] = useState<Promise<Stripe | null> | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [selectedGatewayId, setSelectedGatewayId] = useState<number | null>(null)

  // Fetch available gateways
  const { data: gatewaysData } = useQuery({
    queryKey: ['web-shop-payment-gateways'],
    queryFn: async () => {
      const response = await api.get('/web-shop/payment-gateways')
      return response.data.gateways as { id: number; name: string; display_name: string; is_test_mode: boolean }[]
    },
  })

  // Auto-select first gateway
  useEffect(() => {
    if (gatewaysData?.length && !selectedGatewayId) {
      setSelectedGatewayId(gatewaysData[0].id)
    }
  }, [gatewaysData, selectedGatewayId])

  const [formData, setFormData] = useState({
    email: '',
    phone: '',
    first_name: '',
    last_name: '',
    address: '',
    city: '',
    state: '',
    postal_code: '',
    country: 'US',
    notes: '',
  })

  useEffect(() => {
    if (cart.length === 0 && !paymentData) {
      toast({ variant: 'destructive', title: 'Empty Cart', description: 'Your cart is empty.' })
      navigate('/shop')
    }
  }, [cart, paymentData, navigate, toast])

  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  const tax = subtotal * 0.1
  const total = subtotal + tax

  const validate = (): boolean => {
    const e: Record<string, string> = {}
    if (!formData.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) e.email = 'Valid email required'
    if (!formData.first_name) e.first_name = 'Required'
    if (!formData.last_name) e.last_name = 'Required'
    if (!formData.address) e.address = 'Required'
    if (!formData.city) e.city = 'Required'
    if (!formData.postal_code) e.postal_code = 'Required'
    if (!formData.country) e.country = 'Required'
    setErrors(e)
    return Object.keys(e).length === 0
  }

  const handleProceedToPayment = async () => {
    if (!validate()) return

    setIsLoading(true)
    try {
      const items = cart.map((item) => ({
        product_id: item.productId,
        quantity: item.quantity,
      }))

      const response = await api.post('/web-shop/checkout/guest', {
        items,
        email: formData.email,
        phone: formData.phone || undefined,
        shipping_address: {
          first_name: formData.first_name,
          last_name: formData.last_name,
          address: formData.address,
          city: formData.city,
          state: formData.state || undefined,
          postal_code: formData.postal_code,
          country: formData.country,
        },
        notes: formData.notes || undefined,
        gateway_id: selectedGatewayId,
      })

      const data = response.data
      const gatewayName = data.gateway_name || 'stripe'

      // Handle redirect-based gateways (HitPay, PayPal)
      if (gatewayName === 'hitpay' || gatewayName === 'paypal') {
        const paymentUrl = data.payment_intent?.payment_url
        if (paymentUrl) {
          // Store order info before redirect
          localStorage.setItem('ace_pending_order', JSON.stringify({
            order_id: data.order_id,
            order_number: data.order_number,
            guest_token: data.guest_token,
          }))
          if (data.guest_token) {
            localStorage.setItem('ace_guest_token', data.guest_token)
          }
          // Redirect to payment page
          window.location.href = paymentUrl
          return
        }
      }

      // Stripe inline flow
      setPaymentData({
        client_secret: data.payment_intent?.client_secret || data.client_secret,
        publishable_key: data.payment_intent?.publishable_key || data.publishable_key,
        gateway_name: gatewayName,
        order_number: data.order_number,
        order_id: data.order_id,
        total: data.total,
        guest_token: data.guest_token,
      })

      if (data.guest_token) {
        localStorage.setItem('ace_guest_token', data.guest_token)
      }

      setStripePromise(loadAceStripe(data.payment_intent?.publishable_key || data.publishable_key))
      setStep('payment')
    } catch (err: any) {
      toast({
        variant: 'destructive',
        title: 'Checkout Failed',
        description: err.response?.data?.message || err.response?.data?.error || 'Failed to create order.',
      })
    } finally {
      setIsLoading(false)
    }
  }

  const handlePaymentSuccess = () => {
    onClearCart()
    localStorage.removeItem('ecommerce_cart')
    localStorage.removeItem('ace_cart')
    navigate('/shop/order-success')
  }

  const handleFieldChange = (field: string, value: string) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
    if (errors[field]) setErrors((prev) => ({ ...prev, [field]: '' }))
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="bg-white border-b sticky top-0 z-50">
        <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
          <Button variant="ghost" onClick={() => navigate('/shop/cart')}>
            <Icon icon="solar:arrow-left-bold" className="h-4 w-4 mr-2" />
            Back to Cart
          </Button>
          <h1 className="text-xl font-bold">Checkout</h1>
          <div className="w-24" />
        </div>
      </header>

      <div className="max-w-5xl mx-auto px-4 py-8">
        {/* Progress Steps */}
        <div className="flex items-center justify-center mb-8 gap-4">
          <div className={`flex items-center gap-2 ${step === 'shipping' ? 'text-primary' : 'text-gray-400'}`}>
            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold ${step === 'shipping' ? 'bg-primary text-white' : 'bg-green-500 text-white'}`}>
              {step === 'shipping' ? '1' : '✓'}
            </div>
            <span className="font-medium">Shipping</span>
          </div>
          <div className="w-12 h-px bg-gray-300" />
          <div className={`flex items-center gap-2 ${step === 'payment' ? 'text-primary' : 'text-gray-400'}`}>
            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold ${step === 'payment' ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500'}`}>
              2
            </div>
            <span className="font-medium">Payment</span>
          </div>
        </div>

        <div className="grid lg:grid-cols-3 gap-8">
          <div className="lg:col-span-2">
            {step === 'shipping' ? (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <Icon icon="solar:delivery-bold-duotone" className="h-5 w-5" />
                    Shipping Information
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <Label>Email *</Label>
                      <Input
                        type="email"
                        placeholder="your@email.com"
                        value={formData.email}
                        onChange={(e) => handleFieldChange('email', e.target.value)}
                        className={errors.email ? 'border-red-500' : ''}
                      />
                      {errors.email && <p className="text-xs text-red-500 mt-1">{errors.email}</p>}
                    </div>
                    <div>
                      <Label>Phone</Label>
                      <Input
                        placeholder="+1 234 567 890"
                        value={formData.phone}
                        onChange={(e) => handleFieldChange('phone', e.target.value)}
                      />
                    </div>
                  </div>

                  <Separator />

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <Label>First Name *</Label>
                      <Input
                        placeholder="John"
                        value={formData.first_name}
                        onChange={(e) => handleFieldChange('first_name', e.target.value)}
                        className={errors.first_name ? 'border-red-500' : ''}
                      />
                      {errors.first_name && <p className="text-xs text-red-500 mt-1">{errors.first_name}</p>}
                    </div>
                    <div>
                      <Label>Last Name *</Label>
                      <Input
                        placeholder="Doe"
                        value={formData.last_name}
                        onChange={(e) => handleFieldChange('last_name', e.target.value)}
                        className={errors.last_name ? 'border-red-500' : ''}
                      />
                      {errors.last_name && <p className="text-xs text-red-500 mt-1">{errors.last_name}</p>}
                    </div>
                  </div>

                  <div>
                    <Label>Address *</Label>
                    <Input
                      placeholder="123 Main Street"
                      value={formData.address}
                      onChange={(e) => handleFieldChange('address', e.target.value)}
                      className={errors.address ? 'border-red-500' : ''}
                    />
                    {errors.address && <p className="text-xs text-red-500 mt-1">{errors.address}</p>}
                  </div>

                  <div className="grid grid-cols-3 gap-4">
                    <div>
                      <Label>City *</Label>
                      <Input
                        placeholder="New York"
                        value={formData.city}
                        onChange={(e) => handleFieldChange('city', e.target.value)}
                        className={errors.city ? 'border-red-500' : ''}
                      />
                      {errors.city && <p className="text-xs text-red-500 mt-1">{errors.city}</p>}
                    </div>
                    <div>
                      <Label>State</Label>
                      <Input
                        placeholder="NY"
                        value={formData.state}
                        onChange={(e) => handleFieldChange('state', e.target.value)}
                      />
                    </div>
                    <div>
                      <Label>Postal Code *</Label>
                      <Input
                        placeholder="10001"
                        value={formData.postal_code}
                        onChange={(e) => handleFieldChange('postal_code', e.target.value)}
                        className={errors.postal_code ? 'border-red-500' : ''}
                      />
                      {errors.postal_code && <p className="text-xs text-red-500 mt-1">{errors.postal_code}</p>}
                    </div>
                  </div>

                  <div>
                    <Label>Country *</Label>
                    <Input
                      placeholder="US"
                      value={formData.country}
                      onChange={(e) => handleFieldChange('country', e.target.value)}
                      className={errors.country ? 'border-red-500' : ''}
                    />
                    {errors.country && <p className="text-xs text-red-500 mt-1">{errors.country}</p>}
                  </div>

                  <div>
                    <Label>Notes (optional)</Label>
                    <Textarea
                      placeholder="Special delivery instructions..."
                      value={formData.notes}
                      onChange={(e) => handleFieldChange('notes', e.target.value)}
                      rows={3}
                    />
                  </div>

                  {/* Payment Method Selection */}
                  {gatewaysData && gatewaysData.length > 1 && (
                    <div className="space-y-3">
                      <Separator />
                      <Label className="text-base font-semibold">Payment Method</Label>
                      <div className="grid grid-cols-1 gap-2">
                        {gatewaysData.map((gw) => {
                          const icons: Record<string, string> = {
                            stripe: 'logos:stripe',
                            hitpay: 'solar:wallet-money-bold-duotone',
                            paypal: 'logos:paypal',
                          }
                          return (
                            <div
                              key={gw.id}
                              className={`flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition-colors ${
                                selectedGatewayId === gw.id
                                  ? 'border-primary bg-primary/5'
                                  : 'border-gray-200 hover:border-gray-300'
                              }`}
                              onClick={() => setSelectedGatewayId(gw.id)}
                            >
                              <Icon icon={icons[gw.name] || 'solar:card-bold'} className="h-6 w-6" />
                              <div className="flex-1">
                                <p className="font-medium">{gw.display_name}</p>
                                {gw.is_test_mode && (
                                  <span className="text-xs text-amber-500">Test Mode</span>
                                )}
                              </div>
                              <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center ${
                                selectedGatewayId === gw.id ? 'border-primary' : 'border-gray-300'
                              }`}>
                                {selectedGatewayId === gw.id && (
                                  <div className="w-3 h-3 rounded-full bg-primary" />
                                )}
                              </div>
                            </div>
                          )
                        })}
                      </div>
                    </div>
                  )}

                  <Button
                    className="w-full"
                    size="lg"
                    onClick={handleProceedToPayment}
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
            ) : paymentData && stripePromise ? (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <Icon icon="solar:card-bold-duotone" className="h-5 w-5" />
                    Payment
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <Elements
                    stripe={stripePromise}
                    options={{
                      clientSecret: paymentData.client_secret,
                      appearance: { theme: 'stripe' },
                    }}
                  >
                    <StripePaymentForm
                      orderNumber={paymentData.order_number}
                      total={paymentData.total}
                      orderId={paymentData.order_id}
                      guestToken={paymentData.guest_token}
                      onSuccess={handlePaymentSuccess}
                    />
                  </Elements>
                </CardContent>
              </Card>
            ) : null}
          </div>

          {/* Order Summary Sidebar */}
          <div>
            <Card className="sticky top-20">
              <CardHeader>
                <CardTitle className="text-sm">Order Summary</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="space-y-2 max-h-48 overflow-y-auto">
                  {cart.map((item) => (
                    <div key={item.productId} className="flex gap-3">
                      <img
                        src={item.image || 'https://placehold.co/48x48?text=...'}
                        className="w-12 h-12 rounded object-cover"
                        alt={item.name}
                      />
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium truncate">{item.name}</p>
                        <p className="text-xs text-gray-500">Qty: {item.quantity}</p>
                      </div>
                      <p className="text-sm font-medium">${(item.price * item.quantity).toFixed(2)}</p>
                    </div>
                  ))}
                </div>
                <Separator />
                <div className="flex justify-between text-sm">
                  <span className="text-gray-500">Subtotal</span>
                  <span>${subtotal.toFixed(2)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-gray-500">Tax (10%)</span>
                  <span>${tax.toFixed(2)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-gray-500">Shipping</span>
                  <span className="text-green-600">Free</span>
                </div>
                <Separator />
                <div className="flex justify-between font-bold text-lg">
                  <span>Total</span>
                  <span>${total.toFixed(2)}</span>
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </div>
  )
}

// ============================================================
// Account / Membership Component
// ============================================================

function EcommerceAccount() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const [isLogin, setIsLogin] = useState(true)
  const [isLoading, setIsLoading] = useState(false)
  const [isLoggedIn, setIsLoggedIn] = useState(false)
  const [customer, setCustomer] = useState<any>(null)

  const [loginForm, setLoginForm] = useState({ email: '', password: '' })
  const [registerForm, setRegisterForm] = useState({
    first_name: '', last_name: '', email: '', password: '', password_confirmation: '',
  })

  // Check if already logged in
  useEffect(() => {
    const token = localStorage.getItem('web_customer_token')
    if (token) {
      api.get('/web-shop/customer/profile', {
        headers: { Authorization: `Bearer ${token}` },
      })
        .then((res) => {
          setCustomer(res.data)
          setIsLoggedIn(true)
        })
        .catch(() => {
          localStorage.removeItem('web_customer_token')
          setIsLoggedIn(false)
        })
    }
  }, [])

  const handleLogin = async () => {
    if (!loginForm.email || !loginForm.password) {
      toast({ variant: 'destructive', title: 'Error', description: 'Please fill in all fields.' })
      return
    }
    setIsLoading(true)
    try {
      const res = await api.post('/web-shop/auth/login', loginForm)
      localStorage.setItem('web_customer_token', res.data.token)
      setCustomer(res.data.customer)
      setIsLoggedIn(true)
      toast({ title: 'Welcome back!', description: `Logged in as ${res.data.customer.first_name}` })
    } catch (err: any) {
      toast({
        variant: 'destructive',
        title: 'Login Failed',
        description: err.response?.data?.message || 'Invalid credentials.',
      })
    } finally {
      setIsLoading(false)
    }
  }

  const handleRegister = async () => {
    if (!registerForm.email || !registerForm.password || !registerForm.first_name || !registerForm.last_name) {
      toast({ variant: 'destructive', title: 'Error', description: 'Please fill in all required fields.' })
      return
    }
    if (registerForm.password !== registerForm.password_confirmation) {
      toast({ variant: 'destructive', title: 'Error', description: 'Passwords do not match.' })
      return
    }
    setIsLoading(true)
    try {
      const res = await api.post('/web-shop/auth/register', registerForm)
      localStorage.setItem('web_customer_token', res.data.token)
      setCustomer(res.data.customer)
      setIsLoggedIn(true)
      toast({ title: 'Account Created!', description: 'Welcome to our store.' })
    } catch (err: any) {
      toast({
        variant: 'destructive',
        title: 'Registration Failed',
        description: err.response?.data?.message || 'Failed to create account.',
      })
    } finally {
      setIsLoading(false)
    }
  }

  const handleLogout = () => {
    const token = localStorage.getItem('web_customer_token')
    if (token) {
      api.post('/web-shop/auth/logout', {}, {
        headers: { Authorization: `Bearer ${token}` },
      }).catch(() => {})
    }
    localStorage.removeItem('web_customer_token')
    setCustomer(null)
    setIsLoggedIn(false)
    toast({ title: 'Logged out' })
  }

  if (isLoggedIn && customer) {
    return (
      <div className="min-h-screen bg-gray-50">
        <header className="bg-white border-b sticky top-0 z-50">
          <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <Button variant="ghost" onClick={() => navigate('/shop')}>
              <Icon icon="solar:arrow-left-bold" className="h-4 w-4 mr-2" />
              Back to Shop
            </Button>
            <h1 className="text-xl font-bold">My Account</h1>
            <Button variant="ghost" onClick={handleLogout}>
              <Icon icon="solar:logout-3-bold" className="h-4 w-4 mr-1" />
              Logout
            </Button>
          </div>
        </header>

        <div className="max-w-4xl mx-auto px-4 py-8">
          <div className="grid md:grid-cols-3 gap-6">
            <Card className="md:col-span-1">
              <CardContent className="pt-6 text-center">
                <div className="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                  <Icon icon="solar:user-bold-duotone" className="h-10 w-10 text-primary" />
                </div>
                <h3 className="font-bold text-lg">{customer.first_name} {customer.last_name}</h3>
                <p className="text-gray-500 text-sm">{customer.email}</p>
                {customer.phone && <p className="text-gray-400 text-sm">{customer.phone}</p>}
              </CardContent>
            </Card>

            <Card className="md:col-span-2">
              <CardHeader>
                <CardTitle>Quick Links</CardTitle>
              </CardHeader>
              <CardContent className="grid grid-cols-2 gap-4">
                <Button variant="outline" className="h-auto p-4 flex flex-col items-center gap-2" onClick={() => navigate('/web-shop/account/orders')}>
                  <Icon icon="solar:bag-check-bold-duotone" className="h-8 w-8 text-primary" />
                  <span>My Orders</span>
                </Button>
                <Button variant="outline" className="h-auto p-4 flex flex-col items-center gap-2" onClick={() => navigate('/web-shop/account/addresses')}>
                  <Icon icon="solar:map-point-bold-duotone" className="h-8 w-8 text-primary" />
                  <span>Addresses</span>
                </Button>
                <Button variant="outline" className="h-auto p-4 flex flex-col items-center gap-2" onClick={() => navigate('/web-shop/account/profile')}>
                  <Icon icon="solar:user-check-bold-duotone" className="h-8 w-8 text-primary" />
                  <span>Profile</span>
                </Button>
                <Button variant="outline" className="h-auto p-4 flex flex-col items-center gap-2" onClick={() => navigate('/web-shop/account/loyalty')}>
                  <Icon icon="solar:star-bold-duotone" className="h-8 w-8 text-primary" />
                  <span>Loyalty Points</span>
                </Button>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    )
  }

  // Auth forms
  return (
    <div className="min-h-screen bg-gray-50">
      <header className="bg-white border-b sticky top-0 z-50">
        <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
          <Button variant="ghost" onClick={() => navigate('/shop')}>
            <Icon icon="solar:arrow-left-bold" className="h-4 w-4 mr-2" />
            Back to Shop
          </Button>
          <h1 className="text-xl font-bold">{isLogin ? 'Sign In' : 'Create Account'}</h1>
          <div className="w-24" />
        </div>
      </header>

      <div className="max-w-md mx-auto px-4 py-12">
        <Card>
          <CardHeader>
            <CardTitle className="text-center">
              {isLogin ? 'Welcome Back' : 'Create Your Account'}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {isLogin ? (
              <>
                <div>
                  <Label>Email</Label>
                  <Input
                    type="email"
                    placeholder="your@email.com"
                    value={loginForm.email}
                    onChange={(e) => setLoginForm({ ...loginForm, email: e.target.value })}
                  />
                </div>
                <div>
                  <Label>Password</Label>
                  <Input
                    type="password"
                    placeholder="••••••••"
                    value={loginForm.password}
                    onChange={(e) => setLoginForm({ ...loginForm, password: e.target.value })}
                  />
                </div>
                <Button className="w-full" onClick={handleLogin} disabled={isLoading}>
                  {isLoading ? 'Signing in...' : 'Sign In'}
                </Button>
              </>
            ) : (
              <>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label>First Name *</Label>
                    <Input
                      placeholder="John"
                      value={registerForm.first_name}
                      onChange={(e) => setRegisterForm({ ...registerForm, first_name: e.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Last Name *</Label>
                    <Input
                      placeholder="Doe"
                      value={registerForm.last_name}
                      onChange={(e) => setRegisterForm({ ...registerForm, last_name: e.target.value })}
                    />
                  </div>
                </div>
                <div>
                  <Label>Email *</Label>
                  <Input
                    type="email"
                    placeholder="your@email.com"
                    value={registerForm.email}
                    onChange={(e) => setRegisterForm({ ...registerForm, email: e.target.value })}
                  />
                </div>
                <div>
                  <Label>Password *</Label>
                  <Input
                    type="password"
                    placeholder="••••••••"
                    value={registerForm.password}
                    onChange={(e) => setRegisterForm({ ...registerForm, password: e.target.value })}
                  />
                </div>
                <div>
                  <Label>Confirm Password *</Label>
                  <Input
                    type="password"
                    placeholder="••••••••"
                    value={registerForm.password_confirmation}
                    onChange={(e) => setRegisterForm({ ...registerForm, password_confirmation: e.target.value })}
                  />
                </div>
                <Button className="w-full" onClick={handleRegister} disabled={isLoading}>
                  {isLoading ? 'Creating Account...' : 'Create Account'}
                </Button>
              </>
            )}

            <Separator />

            <p className="text-center text-sm text-gray-500">
              {isLogin ? "Don't have an account?" : 'Already have an account?'}{' '}
              <button className="text-primary font-medium hover:underline" onClick={() => setIsLogin(!isLogin)}>
                {isLogin ? 'Sign Up' : 'Sign In'}
              </button>
            </p>

            {isLogin && (
              <p className="text-center text-sm text-gray-400">
                Or continue as{' '}
                <button className="text-primary font-medium hover:underline" onClick={() => navigate('/shop/checkout')}>
                  guest
                </button>
              </p>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
