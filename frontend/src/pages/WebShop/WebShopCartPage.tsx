import { useState, useEffect, useCallback } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { useToast } from '@/hooks/use-toast'

interface CartItem {
  productId: number
  name: string
  price: number
  quantity: number
  image?: string
}

function getCart(): CartItem[] {
  try {
    const raw = localStorage.getItem('ecommerce_cart') || localStorage.getItem('ace_cart')
    if (!raw) return []
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    return parsed.map((item: any) => ({
      productId: item.productId || item.product_id,
      name: item.name,
      price: Number(item.price),
      quantity: Number(item.quantity),
      image: item.image || undefined,
    }))
  } catch {
    return []
  }
}

function saveCart(cart: CartItem[]) {
  localStorage.setItem('ecommerce_cart', JSON.stringify(cart))
  window.dispatchEvent(new CustomEvent('cart-updated', { detail: { cart } }))
}

export function WebShopCartPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const [cart, setCart] = useState<CartItem[]>(getCart)

  // Listen for external cart updates (e.g. from other tabs)
  useEffect(() => {
    const handleStorage = (e: StorageEvent) => {
      if (e.key === 'ecommerce_cart' || e.key === 'ace_cart') {
        setCart(getCart())
      }
    }
    window.addEventListener('storage', handleStorage)
    return () => window.removeEventListener('storage', handleStorage)
  }, [])

  const updateQuantity = useCallback((productId: number, newQty: number) => {
    setCart(prev => {
      if (newQty <= 0) {
        const updated = prev.filter(i => i.productId !== productId)
        saveCart(updated)
        return updated
      }
      const updated = prev.map(i =>
        i.productId === productId ? { ...i, quantity: newQty } : i
      )
      saveCart(updated)
      return updated
    })
  }, [])

  const removeItem = useCallback((productId: number) => {
    setCart(prev => {
      const updated = prev.filter(i => i.productId !== productId)
      saveCart(updated)
      if (updated.length === 0) {
        toast({ title: 'Cart is empty', description: 'Add items to continue shopping.' })
      }
      return updated
    })
  }, [toast])

  const clearCart = useCallback(() => {
    setCart([])
    saveCart([])
    localStorage.removeItem('ace_cart')
    toast({ title: 'Cart cleared' })
  }, [toast])

  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0)

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-950">
      {/* Header */}
      <header className="bg-white dark:bg-gray-900 border-b sticky top-0 z-50">
        <div className="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
          <Button variant="ghost" onClick={() => navigate(-1)} className="gap-2">
            <Icon icon="solar:arrow-left-bold" className="h-4 w-4" />
            Continue Shopping
          </Button>
          <h1 className="text-xl font-bold">Shopping Cart</h1>
          <div className="w-36" /> {/* Spacer */}
        </div>
      </header>

      <div className="max-w-6xl mx-auto px-4 py-8">
        {cart.length === 0 ? (
          /* ────── Empty Cart ────── */
          <div className="text-center py-20">
            <div className="mx-auto mb-6 flex h-24 w-24 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
              <Icon icon="solar:bag-4-bold-duotone" className="h-14 w-14 text-gray-300 dark:text-gray-600" />
            </div>
            <h2 className="text-2xl font-bold mb-2">Your cart is empty</h2>
            <p className="text-muted-foreground mb-8">
              Looks like you haven't added any items yet.
            </p>
            <Button size="lg" onClick={() => navigate(-1)}>
              <Icon icon="solar:shop-bold-duotone" className="h-5 w-5 mr-2" />
              Continue Shopping
            </Button>
          </div>
        ) : (
          /* ────── Cart with items ────── */
          <div className="grid lg:grid-cols-3 gap-8">
            {/* Items list */}
            <div className="lg:col-span-2 space-y-4">
              <div className="flex items-center justify-between mb-2">
                <h2 className="text-lg font-semibold">
                  {itemCount} {itemCount === 1 ? 'item' : 'items'}
                </h2>
                <Button variant="ghost" size="sm" onClick={clearCart} className="text-red-500 hover:text-red-600 hover:bg-red-50">
                  <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 mr-1" />
                  Clear Cart
                </Button>
              </div>

              {cart.map((item) => (
                <Card key={item.productId} className="overflow-hidden">
                  <CardContent className="p-4 flex gap-4">
                    {/* Product image */}
                    <div className="flex-shrink-0">
                      {item.image ? (
                        <img
                          src={item.image}
                          alt={item.name}
                          className="w-24 h-24 object-cover rounded-lg"
                        />
                      ) : (
                        <div className="w-24 h-24 bg-gray-100 dark:bg-gray-800 rounded-lg flex items-center justify-center">
                          <Icon icon="solar:bag-4-bold-duotone" className="h-8 w-8 text-gray-300" />
                        </div>
                      )}
                    </div>

                    {/* Product details */}
                    <div className="flex-1 min-w-0">
                      <h3 className="font-medium text-gray-900 dark:text-gray-100 truncate">
                        {item.name}
                      </h3>
                      <p className="text-primary font-bold mt-1">
                        ${item.price.toFixed(2)}
                      </p>

                      {/* Quantity controls */}
                      <div className="flex items-center gap-2 mt-3">
                        <Button
                          variant="outline"
                          size="icon"
                          className="h-8 w-8"
                          onClick={() => updateQuantity(item.productId, item.quantity - 1)}
                        >
                          <Icon icon="solar:minus-circle-linear" className="h-4 w-4" />
                        </Button>
                        <span className="w-8 text-center font-medium">{item.quantity}</span>
                        <Button
                          variant="outline"
                          size="icon"
                          className="h-8 w-8"
                          onClick={() => updateQuantity(item.productId, item.quantity + 1)}
                        >
                          <Icon icon="solar:add-circle-linear" className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8 text-red-500 hover:text-red-600 ml-auto"
                          onClick={() => removeItem(item.productId)}
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>

                    {/* Line total */}
                    <div className="text-right flex-shrink-0">
                      <p className="font-bold text-gray-900 dark:text-gray-100">
                        ${(item.price * item.quantity).toFixed(2)}
                      </p>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>

            {/* Order summary sidebar */}
            <div className="lg:col-span-1">
              <Card className="sticky top-20">
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5" />
                    Order Summary
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="space-y-2">
                    <div className="flex justify-between text-sm">
                      <span className="text-muted-foreground">Subtotal ({itemCount} items)</span>
                      <span>${subtotal.toFixed(2)}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                      <span className="text-muted-foreground">Shipping</span>
                      <span className="text-green-600 font-medium">Free</span>
                    </div>
                  </div>

                  <Separator />

                  <div className="flex justify-between font-bold text-lg">
                    <span>Total</span>
                    <span>${subtotal.toFixed(2)}</span>
                  </div>

                  <Button
                    className="w-full"
                    size="lg"
                    asChild
                  >
                    <Link to="/web-shop/checkout">
                      <Icon icon="solar:lock-keyhole-bold-duotone" className="h-5 w-5 mr-2" />
                      Proceed to Checkout
                    </Link>
                  </Button>

                  <Button
                    variant="ghost"
                    className="w-full"
                    onClick={() => navigate(-1)}
                  >
                    ← Continue Shopping
                  </Button>

                  <div className="bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 p-3 rounded-lg">
                    <div className="flex items-center gap-2 text-green-800 dark:text-green-200 text-sm">
                      <Icon icon="solar:shield-check-bold-duotone" className="h-4 w-4" />
                      <span className="font-medium">Secure Checkout</span>
                    </div>
                    <p className="text-xs text-green-700 dark:text-green-300 mt-1">
                      Your information is protected with industry-standard encryption
                    </p>
                  </div>
                </CardContent>
              </Card>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
