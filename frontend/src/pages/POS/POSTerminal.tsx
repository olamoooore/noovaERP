import { useState, useEffect, useCallback } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Separator } from '@/components/ui/separator'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import { cn, formatCurrency, getCurrencySymbol } from '@/lib/utils'
import { useSettingsStore } from '@/store/settingsStore'

interface Product {
  id: number
  name: string
  price: number
  sku: string
  barcode?: string
  stock: number
  image_url?: string
  tax_rate?: number
  category_id?: number
  category?: { id: number; name: string }
}

interface Category {
  id: number
  name: string
  product_count?: number
}

interface Customer {
  id: number
  name: string
  email?: string
  phone?: string
}

interface Warehouse {
  id: number
  name: string
}

interface CartItem extends Product {
  quantity: number
  line_subtotal: number
  line_total: number
  tax_amount: number
}

export function POSTerminal() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const taxConfig = useSettingsStore((s) => s.tax)
  
  // State
  const [search, setSearch] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null)
  const [cart, setCart] = useState<CartItem[]>([])
  const [customerId, setCustomerId] = useState<string>('walk-in')
  const [warehouseId, setWarehouseId] = useState<string>('all')
  const [discount, setDiscount] = useState<number>(0)
  const [showCheckoutDialog, setShowCheckoutDialog] = useState(false)
  const [paymentMethod, setPaymentMethod] = useState<string>('cash')
  const [barcodeInput, setBarcodeInput] = useState('')

  // Fetch Products
  const { data: productsData, isLoading: productsLoading } = useQuery({
    queryKey: ['pos-products', search, selectedCategory, warehouseId],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: 50 }
      if (search) params.search = search
      if (selectedCategory) params.category_id = selectedCategory
      if (warehouseId && warehouseId !== 'all') params.warehouse_id = warehouseId
      const response = await api.get('/products', { params })
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  // Fetch Categories
  const { data: categories } = useQuery<Category[]>({
    queryKey: ['categories'],
    queryFn: async () => {
      const response = await api.get('/product-categories')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  // Fetch Customers
  const { data: customers } = useQuery<Customer[]>({
    queryKey: ['customers-pos'],
    queryFn: async () => {
      const response = await api.get('/customers', { params: { per_page: 100 } })
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  // Fetch Warehouses
  const { data: warehouses } = useQuery<Warehouse[]>({
    queryKey: ['warehouses'],
    queryFn: async () => {
      const response = await api.get('/pos/warehouses')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  // Create Order Mutation
  const createOrderMutation = useMutation({
    mutationFn: async (orderData: any) => {
      const response = await api.post('/pos/orders', orderData)
      return response.data
    },
  })

  // Pay Order Mutation
  const payOrderMutation = useMutation({
    mutationFn: async ({ orderId, paymentData }: { orderId: number; paymentData: any }) => {
      const response = await api.post(`/pos/orders/${orderId}/pay`, paymentData)
      return response.data
    },
  })

  const taxEnabled = taxConfig.enabled
  const pricingMode = taxConfig.pricing_mode
  const defaultTaxRate = taxConfig.default_tax_rate ?? 0

  const getEffectiveTaxRate = (item: Product) => {
    if (!taxEnabled) return 0
    const rate = typeof item.tax_rate === 'number' ? item.tax_rate : 0
    return rate > 0 ? rate : defaultTaxRate
  }

  const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100

  const computeLine = (item: Product, quantity: number) => {
    const gross = item.price * quantity
    const rate = getEffectiveTaxRate(item)

    if (pricingMode === 'inclusive' && rate > 0) {
      const div = 1 + rate / 100
      const net = div > 0 ? gross / div : gross
      const tax = gross - net
      return {
        line_subtotal: round2(net),
        tax_amount: round2(tax),
        line_total: round2(gross),
      }
    }

    const net = gross
    const tax = net * (rate / 100)
    return {
      line_subtotal: round2(net),
      tax_amount: round2(tax),
      line_total: round2(net + tax),
    }
  }

  // Calculate totals
  const subtotal = round2(cart.reduce((sum, item) => sum + item.line_subtotal, 0))
  const taxAmount = round2(cart.reduce((sum, item) => sum + item.tax_amount, 0))
  const total = round2(cart.reduce((sum, item) => sum + item.line_total, 0) - discount)

  // Add to cart
  const addToCart = useCallback((product: Product) => {
    setCart(prev => {
      const existing = prev.find(item => item.id === product.id)
      if (existing) {
        if (existing.quantity >= product.stock) {
          toast({
            title: "Out of Stock",
            description: `Only ${product.stock} items available`,
            variant: "destructive",
          })
          return prev
        }
        return prev.map(item => {
          if (item.id === product.id) {
            const newQty = item.quantity + 1
            return {
              ...item,
              quantity: newQty,
              ...computeLine(product, newQty),
            }
          }
          return item
        })
      }
      
      return [...prev, {
        ...product,
        quantity: 1,
        ...computeLine(product, 1),
      }]
    })
  }, [toast, taxEnabled, pricingMode, defaultTaxRate])

  // Update quantity
  const updateQuantity = (productId: number, delta: number) => {
    setCart(prev => prev.map(item => {
      if (item.id === productId) {
        const newQty = Math.max(1, item.quantity + delta)
        if (newQty > item.stock) {
          toast({
            title: "Out of Stock",
            description: `Only ${item.stock} items available`,
            variant: "destructive",
          })
          return item
        }
        return {
          ...item,
          quantity: newQty,
          ...computeLine(item, newQty),
        }
      }
      return item
    }))
  }

  // Remove from cart
  const removeFromCart = (productId: number) => {
    setCart(prev => prev.filter(item => item.id !== productId))
  }

  // Clear cart
  const clearCart = () => {
    setCart([])
    setDiscount(0)
    setCustomerId('walk-in')
  }

  // Handle barcode scan
  const handleBarcodeScan = useCallback(async (barcode: string) => {
    try {
      const response = await api.get('/pos/products/lookup', { params: { code: barcode } })
      if (response.data) {
        const item = response.data.type === 'variant' ? response.data.variant : response.data.product
        // Ensure stock is present (it comes from backend accessor)
        if (response.data.type === 'variant') {
             // If it's a variant, we might need to handle it differently in the cart
             // For now, we'll map it to a product-like structure
             item.name = `${response.data.product.name} - ${item.name}`
             item.image = response.data.product.image
        }
        addToCart(item)
        toast({
          title: "Product Added",
          description: item.name,
        })
      }
    } catch (error) {
      toast({
        title: "Product Not Found",
        description: `No product found with barcode: ${barcode}`,
        variant: "destructive",
      })
    }
    setBarcodeInput('')
  }, [addToCart, toast])

  // Barcode input handler
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // If Enter is pressed and we have barcode input
      if (e.key === 'Enter' && barcodeInput.length > 0) {
        handleBarcodeScan(barcodeInput)
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [barcodeInput, handleBarcodeScan])

  // Handle checkout
  const handleCheckout = async () => {
    if (cart.length === 0) {
      toast({
        title: "Empty Cart",
        description: "Add products to cart before checkout",
        variant: "destructive",
      })
      return
    }

    try {
      // Create order
      const orderData = {
        notes: customerId !== 'walk-in' ? `Customer ID: ${customerId}` : 'Walk-in customer',
        items: cart.map(item => ({
          product_id: item.id,
          quantity: item.quantity,
          unit_price: item.price,
        })),
      }

      const order = await createOrderMutation.mutateAsync(orderData)

      // Process payment
      const paymentData = {
        method: paymentMethod,
        amount: round2(total),
        reference: `POS-${Date.now()}`,
      }

      await payOrderMutation.mutateAsync({ orderId: order.id, paymentData })

      toast({
        title: "Payment Successful",
        description: `Order #${order.order_number} completed`,
      })

      // Clear cart and close dialog
      clearCart()
      setShowCheckoutDialog(false)

      // Refresh products to update stock
      queryClient.invalidateQueries({ queryKey: ['pos-products'] })
    } catch (error: any) {
      toast({
        title: "Checkout Failed",
        description: error.response?.data?.message || "An error occurred",
        variant: "destructive",
      })
    }
  }

  const products = productsData || []

  return (
    <div className="flex h-full gap-4 p-4">
      {/* Left: Product Selection */}
      <div className="flex flex-1 flex-col gap-4">
        {/* Search and Barcode */}
        <div className="flex gap-2">
          <div className="relative flex-1">
            <Icon icon="solar:magnifer-linear" className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search products..."
              className="pl-9"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <div className="relative w-48">
            <Icon icon="solar:barcode-linear" className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Scan barcode..."
              className="pl-9"
              value={barcodeInput}
              onChange={(e) => setBarcodeInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && barcodeInput) {
                  handleBarcodeScan(barcodeInput)
                }
              }}
            />
          </div>
        </div>

        {/* Category Filter */}
        <div className="flex gap-2 overflow-x-auto pb-2">
          <Button
            variant={selectedCategory === null ? "default" : "outline"}
            size="sm"
            onClick={() => setSelectedCategory(null)}
          >
            All Categories
          </Button>
          {categories?.map((cat) => (
            <Button
              key={cat.id}
              variant={selectedCategory === cat.id ? "default" : "outline"}
              size="sm"
              onClick={() => setSelectedCategory(cat.id)}
            >
              {cat.name}
            </Button>
          ))}
        </div>

        {/* Products Grid */}
        <ScrollArea className="flex-1 rounded-md border bg-card p-4">
          {productsLoading ? (
            <div className="flex h-full items-center justify-center">
              <Icon icon="solar:refresh-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : products.length === 0 ? (
            <div className="flex h-full flex-col items-center justify-center text-muted-foreground">
              <Icon icon="solar:box-bold-duotone" className="h-16 w-16 mb-4" />
              <p>No products found</p>
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
              {products.map((product: Product) => (
                <Card
                  key={product.id}
                  className={cn(
                    "cursor-pointer transition-all hover:shadow-md",
                    product.stock <= 0 && "opacity-50 cursor-not-allowed"
                  )}
                  onClick={() => product.stock > 0 && addToCart(product)}
                >
                  <CardContent className="p-3">
                    <div className="aspect-square mb-2 rounded-md bg-muted flex items-center justify-center overflow-hidden">
                      {product.image_url ? (
                        <img src={product.image_url} alt={product.name} className="object-cover w-full h-full" />
                      ) : (
                        <Icon icon="solar:box-bold-duotone" className="h-10 w-10 text-muted-foreground" />
                      )}
                    </div>
                    <h3 className="font-medium text-sm truncate">{product.name}</h3>
                    <p className="text-xs text-muted-foreground truncate">{product.sku}</p>
                    <div className="flex items-center justify-between mt-2">
                      <span className="font-bold text-sm">{formatCurrency(product.price)}</span>
                      <Badge variant={product.stock > 10 ? "secondary" : product.stock > 0 ? "outline" : "destructive"} className="text-xs">
                        {product.stock > 0 ? `${product.stock}` : 'Out'}
                      </Badge>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </ScrollArea>
      </div>

      {/* Right: Cart */}
      <Card className="flex w-96 flex-col">
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
            <CardTitle className="flex items-center gap-2">
              <Icon icon="solar:cart-large-minimalistic-bold-duotone" className="h-5 w-5" />
              Current Order
            </CardTitle>
            <Badge variant="secondary">{cart.length} items</Badge>
          </div>
          
          {/* Customer & Warehouse Selection */}
          <div className="grid grid-cols-2 gap-2 mt-3">
            <Select value={customerId} onValueChange={setCustomerId}>
              <SelectTrigger className="h-9">
                <SelectValue placeholder="Customer" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="walk-in">Walk-in Customer</SelectItem>
                {customers?.map((c) => (
                  <SelectItem key={c.id} value={c.id.toString()}>{c.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger className="h-9">
                <SelectValue placeholder="Warehouse" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Warehouses</SelectItem>
                {warehouses?.map((w) => (
                  <SelectItem key={w.id} value={w.id.toString()}>{w.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </CardHeader>

        <ScrollArea className="flex-1 px-4">
          <div className="space-y-3">
            {cart.map((item) => (
              <div key={item.id} className="flex gap-3 rounded-lg border p-2">
                <div className="h-12 w-12 rounded bg-muted flex items-center justify-center shrink-0">
                  {item.image_url ? (
                    <img src={item.image_url} alt={item.name} className="w-full h-full object-cover rounded" />
                  ) : (
                    <Icon icon="solar:box-bold-duotone" className="h-6 w-6 text-muted-foreground" />
                  )}
                </div>
                <div className="flex-1 min-w-0">
                  <h4 className="font-medium text-sm truncate">{item.name}</h4>
                  <p className="text-xs text-muted-foreground">{formatCurrency(item.price)} x {item.quantity}</p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <span className="font-bold text-sm">{formatCurrency(item.line_total)}</span>
                  <div className="flex items-center gap-1">
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-6 w-6"
                      onClick={() => updateQuantity(item.id, -1)}
                    >
                      <Icon icon="solar:minus-circle-linear" className="h-4 w-4" />
                    </Button>
                    <span className="text-sm w-6 text-center">{item.quantity}</span>
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-6 w-6"
                      onClick={() => updateQuantity(item.id, 1)}
                    >
                      <Icon icon="solar:add-circle-linear" className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-6 w-6 text-destructive"
                      onClick={() => removeFromCart(item.id)}
                    >
                      <Icon icon="solar:trash-bin-minimalistic-linear" className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              </div>
            ))}
            {cart.length === 0 && (
              <div className="text-center text-muted-foreground py-12">
                <Icon icon="solar:cart-large-minimalistic-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
                <p>Cart is empty</p>
                <p className="text-sm">Click on products to add them</p>
              </div>
            )}
          </div>
        </ScrollArea>

        {/* Totals & Actions */}
        <div className="p-4 border-t bg-muted/20 space-y-4">
          {/* Discount Input */}
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">Discount:</span>
            <div className="relative flex-1">
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">{getCurrencySymbol()}</span>
              <Input
                type="number"
                className="pl-7 h-8"
                value={discount || ''}
                onChange={(e) => setDiscount(Number(e.target.value) || 0)}
                placeholder="0"
              />
            </div>
          </div>

          <div className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span className="text-muted-foreground">Subtotal</span>
              <span>{formatCurrency(subtotal)}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Tax</span>
              <span>{formatCurrency(taxAmount)}</span>
            </div>
            {discount > 0 && (
              <div className="flex justify-between text-destructive">
                <span>Discount</span>
                <span>-{formatCurrency(discount)}</span>
              </div>
            )}
            <Separator />
            <div className="flex justify-between font-bold text-lg">
              <span>Total</span>
              <span>{formatCurrency(total)}</span>
            </div>
          </div>

          <div className="flex gap-2">
            <Button
              variant="outline"
              className="flex-1"
              onClick={clearCart}
              disabled={cart.length === 0}
            >
              <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4 mr-2" />
              Clear
            </Button>
            <Button
              className="flex-1"
              onClick={() => setShowCheckoutDialog(true)}
              disabled={cart.length === 0}
            >
              <Icon icon="solar:wallet-money-bold" className="h-4 w-4 mr-2" />
              Pay {formatCurrency(total)}
            </Button>
          </div>
        </div>
      </Card>

      {/* Checkout Dialog */}
      <Dialog open={showCheckoutDialog} onOpenChange={setShowCheckoutDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Complete Payment</DialogTitle>
            <DialogDescription>
              Total amount: {formatCurrency(total)}
            </DialogDescription>
          </DialogHeader>
          
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <label className="text-sm font-medium">Payment Method</label>
              <Select value={paymentMethod} onValueChange={setPaymentMethod}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="cash">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:money-bag-bold-duotone" className="h-4 w-4" />
                      Cash
                    </div>
                  </SelectItem>
                  <SelectItem value="card">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:card-bold-duotone" className="h-4 w-4" />
                      Card
                    </div>
                  </SelectItem>
                  <SelectItem value="bank_transfer">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:bank-bold-duotone" className="h-4 w-4" />
                      Bank Transfer
                    </div>
                  </SelectItem>
                  <SelectItem value="other">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:wallet-bold-duotone" className="h-4 w-4" />
                      Other
                    </div>
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Order Summary */}
            <div className="rounded-lg border p-3 space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Items</span>
                <span>{cart.length}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Subtotal</span>
                <span>{formatCurrency(subtotal)}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Tax</span>
                <span>{formatCurrency(taxAmount)}</span>
              </div>
              {discount > 0 && (
                <div className="flex justify-between text-destructive">
                  <span>Discount</span>
                  <span>-{formatCurrency(discount)}</span>
                </div>
              )}
              <Separator />
              <div className="flex justify-between font-bold">
                <span>Total</span>
                <span>{formatCurrency(total)}</span>
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCheckoutDialog(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleCheckout}
              disabled={createOrderMutation.isPending || payOrderMutation.isPending}
            >
              {(createOrderMutation.isPending || payOrderMutation.isPending) && (
                <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
              )}
              Complete Payment
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
