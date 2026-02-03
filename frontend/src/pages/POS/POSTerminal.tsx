import { useState, useEffect, useCallback, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Separator } from '@/components/ui/separator'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
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
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandGroup,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { useToast } from '@/hooks/use-toast'
import { cn, formatCurrency, getCurrencySymbol } from '@/lib/utils'
import { useSettingsStore } from '@/store/settingsStore'
import { usePosSessionStore, CartItem } from '@/store/posSessionStore'
import { POSReceipt } from '@/components/pos/POSReceipt'
import { PaymentMethodSelector } from '@/components/pos/PaymentMethodSelector'
import { StripePaymentDialog } from '@/components/StripePaymentDialog'

interface Product {
  id: number
  name: string
  price: number
  sku: string
  barcode?: string
  stock?: number
  stock_quantity?: number
  image?: string
  tax_rate?: number
  effective_tax_rate?: number  // Computed by backend: includes fallback to default system tax
  effective_tax_is_inclusive?: boolean  // Computed by backend: whether tax is included in price
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
  code?: string
  loyalty_points?: number
  type?: 'individual' | 'company'
}

interface Warehouse {
  id: number
  name: string
}

export function POSTerminal() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  // Store State
  const activeSessionId = usePosSessionStore((s) => s.activeSessionId)
  const activeTerminalId = usePosSessionStore((s) => s.activeTerminalId)
  const sessionWarehouseId = usePosSessionStore((s) => s.warehouseId)
  const openedAt = usePosSessionStore((s) => s.openedAt)
  const setWarehouse = usePosSessionStore((s) => s.setWarehouse)
  
  const cart = usePosSessionStore((s) => s.cart)
  const setCart = usePosSessionStore((s) => s.setCart)
  const discount = usePosSessionStore((s) => s.discount)
  const setDiscount = usePosSessionStore((s) => s.setDiscount)
  const customerId = usePosSessionStore((s) => s.customerId)
  const setCustomerId = usePosSessionStore((s) => s.setCustomerId)
  const clearSessionCart = usePosSessionStore((s) => s.clearCart)
  const clearSession = usePosSessionStore((s) => s.clearSession)

  const taxConfig = useSettingsStore((s) => s.tax)
  const loadSettings = useSettingsStore((s) => s.loadSettings)
  const isSettingsLoaded = useSettingsStore((s) => s.isLoaded)
  
  // Load settings on mount
  useEffect(() => {
    if (!isSettingsLoaded) {
      loadSettings()
    }
  }, [isSettingsLoaded, loadSettings])
  
  // Local State
  const [search, setSearch] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null)
  const [warehouseId, setWarehouseId] = useState<string>(sessionWarehouseId ?? 'all')
  const [showCheckoutDialog, setShowCheckoutDialog] = useState(false)
  const [paymentMethod, setPaymentMethod] = useState<string>('cash')
  const [barcodeInput, setBarcodeInput] = useState('')
  const [currentOrderId, setCurrentOrderId] = useState<string | null>(null) // Track draft order ID
  
  // Stripe Payment Dialog State
  const [showStripeDialog, setShowStripeDialog] = useState(false)
  const [stripePaymentData, setStripePaymentData] = useState<{
    orderId: number | null
    gatewayId: number | null
  }>({ orderId: null, gatewayId: null })
  
  // Customer Search & Add States
  const [customerSearchOpen, setCustomerSearchOpen] = useState(false)
  const [customerSearch, setCustomerSearch] = useState('')
  const [showAddCustomerDialog, setShowAddCustomerDialog] = useState(false)
  const [newCustomer, setNewCustomer] = useState({ name: '', phone: '', email: '' })
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null)
  
  // Session Close State
  const [showCloseSessionDialog, setShowCloseSessionDialog] = useState(false)
  const [closingBalance, setClosingBalance] = useState<string>('')
  const [closingNotes, setClosingNotes] = useState<string>('')
  
  // Generate unique order ID for idempotency (UUID v4 format)
  const generateOrderId = useCallback(() => {
    // Generate a proper UUID v4
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = Math.random() * 16 | 0
      const v = c === 'x' ? r : (r & 0x3 | 0x8)
      return v.toString(16)
    })
  }, [])
  // Receipt Dialog State
  const [showReceiptDialog, setShowReceiptDialog] = useState(false)
  const [lastOrder, setLastOrder] = useState<any>(null)
  const receiptRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!activeSessionId || !activeTerminalId || !openedAt) {
      navigate('/pos', { replace: true })
    }
  }, [activeSessionId, activeTerminalId, openedAt, navigate])

  useEffect(() => {
    setWarehouseId(sessionWarehouseId ?? 'all')
  }, [sessionWarehouseId])

  const getStock = (p: Product) => {
    const s = typeof p.stock === 'number' ? p.stock : null
    const sq = typeof p.stock_quantity === 'number' ? p.stock_quantity : null
    return (s ?? sq ?? 0) as number
  }

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

  // Fetch Customers - For initial list
  const { data: customers, refetch: refetchCustomers } = useQuery<Customer[]>({
    queryKey: ['customers-pos'],
    queryFn: async () => {
      const response = await api.get('/customers', { params: { per_page: 100 } })
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  // Customer Search Query
  const { data: searchedCustomers, isLoading: searchingCustomers } = useQuery<Customer[]>({
    queryKey: ['customers-search', customerSearch],
    queryFn: async () => {
      if (!customerSearch || customerSearch.length < 2) return []
      const response = await api.get('/customers', { 
        params: { search: customerSearch, per_page: 20 } 
      })
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
    enabled: customerSearch.length >= 2,
  })

  // Create Customer Mutation
  const createCustomerMutation = useMutation({
    mutationFn: async (data: { name: string; phone?: string; email?: string }) => {
      const response = await api.post('/customers', { 
        ...data, 
        type: 'individual',
        is_active: true 
      })
      return response.data
    },
    onSuccess: (newCust) => {
      refetchCustomers()
      toast({ title: 'Customer Created', description: newCust.name })
      setSelectedCustomer(newCust)
      setCustomerId(newCust.id.toString())
      setShowAddCustomerDialog(false)
      setNewCustomer({ name: '', phone: '', email: '' })
    },
    onError: (error: any) => {
      toast({ 
        title: 'Error', 
        description: error.response?.data?.message || 'Failed to create customer', 
        variant: 'destructive' 
      })
    }
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

  // Close Session Mutation
  const closeSessionMutation = useMutation({
    mutationFn: async ({ sessionId, closingBalance, notes }: { sessionId: number; closingBalance: number; notes?: string }) => {
      console.log('Closing session:', sessionId, closingBalance, notes)
      const response = await api.post(`/pos/sessions/${sessionId}/close`, {
        closing_balance: closingBalance,
        notes,
      })
      console.log('Close session response:', response.data)
      return response.data
    },
    onSuccess: async () => {
      console.log('Session closed successfully')
      // Clear session state first
      clearSession()
      
      // Then invalidate and refetch queries
      await queryClient.invalidateQueries({ queryKey: ['pos-terminals'] })
      await queryClient.invalidateQueries({ queryKey: ['pos-current-session'] })
      
      // Force refetch to ensure data is fresh before navigation
      await queryClient.refetchQueries({ queryKey: ['pos-terminals'] })
      
      toast({
        title: 'Session Closed',
        description: 'Your POS session has been closed successfully.',
      })
      
      // Navigate after everything is done
      navigate('/pos')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.error || 'Failed to close session',
        variant: 'destructive',
      })
    },
  })

  const taxEnabled = taxConfig.enabled

  // Get effective tax rate from the product (computed by backend)
  const getEffectiveTaxRate = (item: Product | CartItem) => {
    // If tax is disabled globally, return 0
    if (!taxEnabled) return 0
    
    // Use the product's effective_tax_rate from backend (already handles fallback logic)
    if ('effective_tax_rate' in item && typeof item.effective_tax_rate === 'number') {
      return item.effective_tax_rate
    }
    
    // Fallback to product's tax_rate if effective_tax_rate not available
    if (typeof item.tax_rate === 'number' && item.tax_rate > 0) {
      return item.tax_rate
    }
    
    // No tax
    return 0
  }

  // Get whether tax is inclusive from the product (computed by backend)
  const getEffectiveTaxIsInclusive = (item: Product | CartItem) => {
    // Use the product's effective_tax_is_inclusive from backend
    if ('effective_tax_is_inclusive' in item && typeof item.effective_tax_is_inclusive === 'boolean') {
      return item.effective_tax_is_inclusive
    }
    
    // Default to exclusive (tax added on top)
    return false
  }

  const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100

  const computeLine = (item: Product | CartItem, quantity: number) => {
    const gross = item.price * quantity
    const rate = getEffectiveTaxRate(item)
    const isInclusive = getEffectiveTaxIsInclusive(item)

    if (isInclusive && rate > 0) {
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
  const itemsTotal = round2(cart.reduce((sum, item) => sum + item.line_total, 0)) // gross total of items
  
  // Calculate Discount Amount
  let discountAmount = 0
  if (discount.type === 'fixed') {
      discountAmount = discount.value
  } else {
      discountAmount = itemsTotal * (discount.value / 100)
  }
  discountAmount = round2(discountAmount)
  
  const total = round2(Math.max(0, itemsTotal - discountAmount))

  // Add to cart
  const addToCart = useCallback((product: Product) => {
    // Check stock
    const currentQtyInCart = cart.find(i => i.id === product.id)?.quantity || 0
    if (currentQtyInCart + 1 > getStock(product)) {
      toast({
        title: "Out of Stock",
        description: `Only ${getStock(product)} items available`,
        variant: "destructive",
      })
      return
    }

    const existing = cart.find(item => item.id === product.id)
    if (existing) {
        const newQty = existing.quantity + 1
        const updatedItem = {
            ...existing,
            quantity: newQty,
            ...computeLine(product, newQty),
        }
        setCart(cart.map(i => i.id === product.id ? updatedItem : i))
    } else {
        const newItem: CartItem = {
            id: product.id,
            name: product.name,
            price: product.price,
            sku: product.sku,
            image: product.image,
            quantity: 1,
            tax_rate: product.tax_rate,
            effective_tax_rate: product.effective_tax_rate,  // Include effective tax rate from backend
            effective_tax_is_inclusive: product.effective_tax_is_inclusive,  // Include tax inclusive flag from backend
            ...computeLine(product, 1),
        }
        setCart([...cart, newItem])
    }
  }, [cart, setCart, toast, taxEnabled])

  // Update quantity
  const updateQuantity = (productId: number, delta: number) => {
    const existing = cart.find(item => item.id === productId)
    if (!existing) return

    const newQty = Math.max(1, existing.quantity + delta)
    
    // Check stock if increasing
    if (delta > 0) {
        // Try to find product in current data to check stock
        // If not found (e.g. searching/filtering), allow valid if we have stock data in item?
        // Current implementation relies on `productsData`.
        const product = productsData?.find((p: Product) => p.id === productId)
        if (product && newQty > getStock(product)) {
             toast({
                title: "Out of Stock",
                description: `Only ${getStock(product)} items available`,
                variant: "destructive",
            })
            return
        }
    }

    const updatedItem = {
        ...existing,
        quantity: newQty,
        ...computeLine(existing, newQty),
    }
    setCart(cart.map(i => i.id === productId ? updatedItem : i))
  }

  // Remove from cart
  const removeFromCart = (productId: number) => {
    setCart(cart.filter(item => item.id !== productId))
  }

  // Handle barcode scan
  const handleBarcodeScan = useCallback(async (barcode: string) => {
    try {
      const response = await api.get('/pos/products/lookup', { params: { code: barcode } })
      if (response.data) {
        const item = response.data.type === 'variant' ? response.data.variant : response.data.product
        // Stock check
        // ... (Similar logic, assuming item has stock info)
        if (response.data.type === 'variant') {
             item.name = `${response.data.product.name} - ${item.name}`
             item.image = response.data.product.image
             // Need to ensure item has property tax_rate if present in product
             item.tax_rate = item.tax_rate ?? response.data.product.tax_rate
             item.effective_tax_rate = item.effective_tax_rate ?? response.data.product.effective_tax_rate
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
  
  const getOrderData = (externalId?: string) => ({
    external_id: externalId,
    customer_id: customerId !== 'walk-in' ? parseInt(customerId) : null,
    session_id: activeSessionId || null,
    terminal_id: activeTerminalId ? parseInt(activeTerminalId) : null,
    notes: customerId !== 'walk-in' 
      ? `Customer: ${selectedCustomer?.name || 'Selected Customer'}` 
      : 'Walk-in customer',
    discount_type: discount.type,
    discount_value: discount.value,
    items: cart.map(item => ({
      product_id: item.id,
      quantity: item.quantity,
      unit_price: item.price,
    })),
  })

  // Handle Save Draft
  const handleSaveDraft = async () => {
      if (cart.length === 0) {
          toast({ title: "Empty Cart", description: "Add items first.", variant: "destructive" })
          return
      }
      try {
          // Use existing order ID or generate new one
          const orderId = currentOrderId || generateOrderId()
          if (!currentOrderId) setCurrentOrderId(orderId)
          
          await createOrderMutation.mutateAsync(getOrderData(orderId))
          toast({ title: "Draft Saved", description: "Order saved as draft." })
      } catch (error: any) {
          toast({ title: "Error", description: error.message, variant: "destructive" })
      }
  }

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
      // Use existing order ID or generate new one for idempotency
      const orderId = currentOrderId || generateOrderId()
      
      // Create order
      const order = await createOrderMutation.mutateAsync(getOrderData(orderId))

      // Process payment
      const paymentData = {
        method: paymentMethod,
        amount: round2(total), // Pay the discounted total
        reference: `POS-${Date.now()}`,
      }

      await payOrderMutation.mutateAsync({ orderId: order.id, paymentData })

      // Store order data for receipt
      setLastOrder({
        orderNumber: order.order_number,
        receiptNumber: order.receipt_number || order.order_number,
        date: new Date(),
        customer: selectedCustomer ? {
          name: selectedCustomer.name,
          phone: selectedCustomer.phone,
          email: selectedCustomer.email,
          loyaltyPoints: selectedCustomer.loyalty_points
        } : { name: 'Walk-in Customer' },
        items: cart.map(item => ({
          name: item.name,
          quantity: item.quantity,
          price: item.price,
          line_total: item.line_total
        })),
        subtotal,
        taxAmount,
        discountAmount,
        discountType: discount.type,
        discountValue: discount.value,
        total,
        paymentMethod,
        amountPaid: total,
        change: 0,
        terminal: activeTerminalId,
      })

      toast({
        title: "Payment Successful",
        description: `Order #${order.order_number} completed`,
      })

      // Close checkout dialog and show receipt
      setShowCheckoutDialog(false)
      setShowReceiptDialog(true)

      // Clear cart and order tracking after showing receipt
      clearSessionCart()
      setSelectedCustomer(null)
      setCurrentOrderId(null) // Reset order ID for next order

      // Refresh products to update stock
      queryClient.invalidateQueries({ queryKey: ['pos-products'] })
      queryClient.invalidateQueries({ queryKey: ['customers-pos'] }) // Refresh customer data
    } catch (error: any) {
      toast({
        title: "Checkout Failed",
        description: error.response?.data?.message || "An error occurred",
        variant: "destructive",
      })
    }
  }

  // Handle checkout with selected payment method
  const handleCheckoutWithMethod = async (method: any, reference?: string) => {
    if (cart.length === 0) {
      toast({
        title: "Empty Cart",
        description: "Add products to cart before checkout",
        variant: "destructive",
      })
      return
    }

    try {
      // Use existing order ID or generate new one for idempotency
      const orderId = currentOrderId || generateOrderId()
      
      // Create order
      const order = await createOrderMutation.mutateAsync(getOrderData(orderId))

      // Process payment with selected method
      const paymentData = {
        method: method.code,
        amount: round2(total),
        reference: reference || `POS-${Date.now()}`,
        payment_type: method.type,
        gateway_id: method.gateway_id,
        offline_method_id: method.id?.startsWith('offline_') ? parseInt(method.id.replace('offline_', '')) : null,
      }

      await payOrderMutation.mutateAsync({ orderId: order.id, paymentData })

      // Store order data for receipt
      setLastOrder({
        orderNumber: order.order_number,
        receiptNumber: order.receipt_number || order.order_number,
        date: new Date(),
        customer: selectedCustomer ? {
          name: selectedCustomer.name,
          phone: selectedCustomer.phone,
          email: selectedCustomer.email,
          loyaltyPoints: selectedCustomer.loyalty_points
        } : { name: 'Walk-in Customer' },
        items: cart.map(item => ({
          name: item.name,
          quantity: item.quantity,
          price: item.price,
          line_total: item.line_total
        })),
        subtotal,
        taxAmount,
        discountAmount,
        discountType: discount.type,
        discountValue: discount.value,
        total,
        paymentMethod: method.name,
        amountPaid: total,
        change: 0,
        terminal: activeTerminalId,
      })

      toast({
        title: "Payment Successful",
        description: `Order #${order.order_number} completed`,
      })

      // Close checkout dialog and show receipt
      setShowCheckoutDialog(false)
      setShowReceiptDialog(true)

      // Clear cart and order tracking after showing receipt
      clearSessionCart()
      setSelectedCustomer(null)
      setCurrentOrderId(null)

      // Refresh products to update stock
      queryClient.invalidateQueries({ queryKey: ['pos-products'] })
      queryClient.invalidateQueries({ queryKey: ['customers-pos'] })
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
    <div className="flex flex-col h-full p-4 gap-4">
      <div className="flex flex-1 gap-4 overflow-hidden">
        {/* Left: Product Selection */}
        <div className="flex flex-1 flex-col gap-4 h-full">
          {/* Search and Barcode */}
          <div className="flex gap-2">
          <div className="relative flex-1">
            <Icon icon="solar:magnifer-bold" className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search products..."
              className="pl-9"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <div className="relative w-48">
            <Icon icon="solar:barcode-bold" className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
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
              <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : products.length === 0 ? (
            <div className="flex h-full flex-col items-center justify-center text-muted-foreground">
              <Icon icon="solar:box-bold" className="h-16 w-16 mb-4" />
              <p>No products found</p>
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
              {products.map((product: Product) => (
                <Card
                  key={product.id}
                  className={cn(
                    "cursor-pointer transition-all hover:shadow-md",
                    getStock(product) <= 0 && "opacity-50 cursor-not-allowed"
                  )}
                  onClick={() => getStock(product) > 0 && addToCart(product)}
                >
                  <CardContent className="p-3">
                    <div className="aspect-square mb-2 rounded-md bg-muted flex items-center justify-center overflow-hidden">
                      {product.image ? (
                        <img src={product.image} alt={product.name} className="object-cover w-full h-full" />
                      ) : (
                        <Icon icon="solar:box-bold" className="h-10 w-10 text-muted-foreground" />
                      )}
                    </div>
                    <h3 className="font-medium text-sm truncate">{product.name}</h3>
                    <p className="text-xs text-muted-foreground truncate">{product.sku}</p>
                    <div className="flex items-center justify-between mt-2">
                      <span className="font-bold text-sm">{formatCurrency(product.price)}</span>
                      <Badge
                        variant={getStock(product) > 10 ? "secondary" : getStock(product) > 0 ? "outline" : "destructive"}
                        className="text-xs"
                      >
                        {getStock(product) > 0 ? `${getStock(product)}` : 'Out'}
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
              <Icon icon="solar:cart-large-minimalistic-bold" className="h-5 w-5" />
              Current Order
            </CardTitle>
            <div className="flex items-center gap-2">
              <Badge variant="secondary">{cart.length} items</Badge>
            </div>
          </div>
          
          {/* Customer Selection */}
          <div className="mt-3">
            <Popover open={customerSearchOpen} onOpenChange={setCustomerSearchOpen}>
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  role="combobox"
                  aria-expanded={customerSearchOpen}
                  className="h-9 flex-1 justify-between text-sm"
                >
                  <div className="flex items-center gap-2 truncate">
                    <Icon icon="solar:user-circle-bold" className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className="truncate">
                      {selectedCustomer ? selectedCustomer.name : 
                       customerId === 'walk-in' ? 'Walk-in' : 'Select Customer'}
                    </span>
                  </div>
                  <Icon icon="solar:alt-arrow-down-linear" className="ml-1 h-4 w-4 shrink-0 opacity-50" />
                </Button>
              </PopoverTrigger>
              <PopoverContent className="w-[300px] p-0" align="start">
                <Command shouldFilter={false}>
                  <div className="flex items-center border-b px-3">
                    <Icon icon="solar:magnifer-linear" className="h-4 w-4 text-muted-foreground mr-2" />
                    <input
                      placeholder="Search customers by name or phone..."
                      value={customerSearch}
                      onChange={(e) => setCustomerSearch(e.target.value)}
                      className="flex h-10 w-full bg-transparent py-3 text-sm outline-none placeholder:text-muted-foreground"
                    />
                  </div>
                  <CommandList className="max-h-[250px]">
                    {searchingCustomers ? (
                      <div className="flex items-center justify-center py-6">
                        <Icon icon="solar:refresh-bold" className="h-5 w-5 animate-spin mr-2 text-muted-foreground" />
                        <span className="text-sm text-muted-foreground">Searching...</span>
                      </div>
                    ) : (
                      <>
                        <CommandGroup heading="Quick Selection">
                          <CommandItem
                            value="walk-in"
                            onSelect={() => {
                              setCustomerId('walk-in')
                              setSelectedCustomer(null)
                              setCustomerSearchOpen(false)
                              setCustomerSearch('')
                            }}
                            className="cursor-pointer"
                          >
                            <Icon icon="solar:user-bold" className="mr-2 h-4 w-4" />
                            <span className="flex-1">Walk-in Customer</span>
                            {customerId === 'walk-in' && (
                              <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-primary" />
                            )}
                          </CommandItem>
                        </CommandGroup>
                        
                        {(customerSearch.length >= 2 ? searchedCustomers : customers)?.length ? (
                          <CommandGroup heading={customerSearch.length >= 2 ? "Search Results" : "Recent Customers"}>
                            {(customerSearch.length >= 2 ? searchedCustomers : customers)?.slice(0, 10).map((customer) => (
                              <CommandItem
                                key={customer.id}
                                value={customer.id.toString()}
                                onSelect={() => {
                                  setCustomerId(customer.id.toString())
                                  setSelectedCustomer(customer)
                                  setCustomerSearchOpen(false)
                                  setCustomerSearch('')
                                }}
                                className="cursor-pointer"
                              >
                                <div className="flex items-center gap-3 flex-1">
                                  <div className="h-8 w-8 rounded-full bg-primary/10 flex items-center justify-center">
                                    <Icon icon="solar:user-bold" className="h-4 w-4 text-primary" />
                                  </div>
                                  <div className="flex flex-col flex-1 min-w-0">
                                    <span className="font-medium truncate">{customer.name}</span>
                                    {customer.phone && (
                                      <span className="text-xs text-muted-foreground">{customer.phone}</span>
                                    )}
                                  </div>
                                </div>
                                {customer.loyalty_points !== undefined && customer.loyalty_points > 0 && (
                                  <Badge variant="secondary" className="ml-2 text-xs shrink-0">
                                    {customer.loyalty_points} pts
                                  </Badge>
                                )}
                                {customerId === customer.id.toString() && (
                                  <Icon icon="solar:check-circle-bold" className="ml-2 h-4 w-4 text-primary shrink-0" />
                                )}
                              </CommandItem>
                            ))}
                          </CommandGroup>
                        ) : customerSearch.length >= 2 ? (
                          <div className="py-6 text-center">
                            <Icon icon="solar:user-cross-bold" className="h-10 w-10 mx-auto text-muted-foreground/50 mb-2" />
                            <p className="text-sm text-muted-foreground">No customer found</p>
                            <Button 
                              variant="link" 
                              size="sm"
                              className="mt-1"
                              onClick={() => {
                                setNewCustomer({ ...newCustomer, name: customerSearch })
                                setShowAddCustomerDialog(true)
                                setCustomerSearchOpen(false)
                              }}
                            >
                              <Icon icon="solar:user-plus-bold" className="h-4 w-4 mr-1" />
                              Create "{customerSearch}"
                            </Button>
                          </div>
                        ) : null}
                      </>
                    )}
                  </CommandList>
                  <div className="border-t p-2">
                    <Button 
                      variant="default" 
                      size="sm" 
                      className="w-full"
                      onClick={() => {
                        setShowAddCustomerDialog(true)
                        setCustomerSearchOpen(false)
                      }}
                    >
                      <Icon icon="solar:user-plus-bold" className="mr-2 h-4 w-4" />
                      Add New Customer
                    </Button>
                  </div>
                </Command>
              </PopoverContent>
            </Popover>
          </div>

          {/* Selected Customer Info */}
          {selectedCustomer && (
            <div className="mt-3 p-2 rounded-lg border bg-primary/5 flex items-center gap-2">
              <div className="h-8 w-8 rounded-full bg-primary/10 flex items-center justify-center">
                <Icon icon="solar:user-circle-bold" className="h-5 w-5 text-primary" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium truncate">{selectedCustomer.name}</p>
                {selectedCustomer.phone && (
                  <p className="text-xs text-muted-foreground">{selectedCustomer.phone}</p>
                )}
              </div>
              {selectedCustomer.loyalty_points !== undefined && selectedCustomer.loyalty_points > 0 && (
                <Badge variant="secondary" className="shrink-0">
                  ★ {selectedCustomer.loyalty_points}
                </Badge>
              )}
              <Button 
                variant="ghost" 
                size="icon" 
                className="h-6 w-6 shrink-0" 
                onClick={() => {
                  setSelectedCustomer(null)
                  setCustomerId('walk-in')
                }}
              >
                <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
              </Button>
            </div>
          )}
        </CardHeader>

        <ScrollArea className="flex-1 px-4">
          <div className="space-y-3">
            {cart.map((item) => (
              <div key={item.id} className="flex gap-3 rounded-lg border p-2">
                <div className="h-12 w-12 rounded bg-muted flex items-center justify-center shrink-0">
                  {item.image ? (
                    <img src={item.image} alt={item.name} className="w-full h-full object-cover rounded" />
                  ) : (
                    <Icon icon="solar:box-bold" className="h-6 w-6 text-muted-foreground" />
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
                      <Icon icon="solar:minus-circle-bold" className="h-4 w-4" />
                    </Button>
                    <span className="text-sm w-6 text-center">{item.quantity}</span>
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-6 w-6"
                      onClick={() => updateQuantity(item.id, 1)}
                    >
                      <Icon icon="solar:add-circle-bold" className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-6 w-6 text-destructive"
                      onClick={() => removeFromCart(item.id)}
                    >
                      <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              </div>
            ))}
            {cart.length === 0 && (
              <div className="text-center text-muted-foreground py-12">
                <Icon icon="solar:cart-large-minimalistic-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
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
            <span className="text-sm text-muted-foreground w-16">Discount:</span>
            <Select 
                value={discount.type} 
                onValueChange={(val: any) => setDiscount({ ...discount, type: val })}
            >
                <SelectTrigger className="w-24 h-8 text-xs">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="fixed">Fixed</SelectItem>
                    <SelectItem value="percent">%</SelectItem>
                </SelectContent>
            </Select>
            <div className="relative flex-1">
              {discount.type === 'fixed' && (
                  <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">{getCurrencySymbol()}</span>
              )}
              <Input
                type="number"
                className={cn("h-8", discount.type === 'fixed' ? "pl-7" : "pl-3")}
                value={discount.value || ''}
                onChange={(e) => setDiscount({ ...discount, value: userDiscountInput(e.target.value) })}
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
            {discountAmount > 0 && (
              <div className="flex justify-between text-destructive">
                <span>Discount ({discount.type === 'percent' ? `${discount.value}%` : 'Fixed'})</span>
                <span>-{formatCurrency(discountAmount)}</span>
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
              onClick={handleSaveDraft}
              disabled={cart.length === 0 || createOrderMutation.isPending}
            >
              <Icon icon="solar:diskette-bold" className="h-4 w-4 mr-2" />
              Draft
            </Button>
            
            <Button
              variant="default"
              className="flex-1 bg-red-500 hover:bg-red-600"
              onClick={() => {
                clearSessionCart()
                setCurrentOrderId(null) // Reset order ID when clearing cart
              }}
              disabled={cart.length === 0}
              title="Clear Cart"
            >
              <Icon icon="solar:broom-bold" className="h-4 w-4 mr-2" />
              Clear
            </Button>
            
            <Button
              className="flex-[2]"
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
          
          <PaymentMethodSelector
            amount={total}
            onSelect={async (method, reference) => {
              // Store selected payment method details
              setPaymentMethod(method.code)
              
              // Handle different payment types
              if (method.type === 'online') {
                try {
                  // For online payments (Stripe, PayPal, etc.), create order first
                  const orderData = getOrderData()
                  console.log('Creating order for online payment:', orderData)
                  
                  const order = await createOrderMutation.mutateAsync(orderData)
                  console.log('Order created:', order)
                  
                  if (!order || !order.id) {
                    throw new Error('Order creation failed - no order ID returned')
                  }
                  
                  // Validate order has required fields
                  if (!order?.id) {
                    throw new Error('Order created but no ID returned')
                  }
                  if (!method?.gateway_id) {
                    throw new Error('No gateway ID found for selected payment method')
                  }
                  
                  // Close checkout dialog first
                  setShowCheckoutDialog(false)
                  
                  // Set the payment data and open Stripe dialog
                  setStripePaymentData({ orderId: order.id, gatewayId: method.gateway_id })
                  
                  // Small delay to ensure state is updated
                  setTimeout(() => {
                    setShowStripeDialog(true)
                  }, 100)
                  return
                } catch (error: any) {
                  console.error('Failed to create order for online payment:', error)
                  toast({
                    variant: 'destructive',
                    title: 'Order creation failed',
                    description: error.message || 'Could not create order for payment',
                  })
                  return
                }
              }
              
              // For cash and offline payments, process normally
              await handleCheckoutWithMethod(method, reference)
            }}
            onCancel={() => setShowCheckoutDialog(false)}
          />
        </DialogContent>
      </Dialog>

      {/* Add Customer Dialog */}
      <Dialog open={showAddCustomerDialog} onOpenChange={setShowAddCustomerDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Add New Customer</DialogTitle>
            <DialogDescription>
              Quickly add a customer to this order
            </DialogDescription>
          </DialogHeader>
          
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="cust-name">Name *</Label>
              <Input
                id="cust-name"
                placeholder="Customer name"
                value={newCustomer.name}
                onChange={(e) => setNewCustomer({ ...newCustomer, name: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="cust-phone">Phone</Label>
              <Input
                id="cust-phone"
                placeholder="Phone number"
                value={newCustomer.phone}
                onChange={(e) => setNewCustomer({ ...newCustomer, phone: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="cust-email">Email</Label>
              <Input
                id="cust-email"
                type="email"
                placeholder="Email address"
                value={newCustomer.email}
                onChange={(e) => setNewCustomer({ ...newCustomer, email: e.target.value })}
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowAddCustomerDialog(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => createCustomerMutation.mutate(newCustomer)}
              disabled={!newCustomer.name.trim() || createCustomerMutation.isPending}
            >
              {createCustomerMutation.isPending && (
                <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
              )}
              Add Customer
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Close Session Dialog */}
      <Dialog open={showCloseSessionDialog} onOpenChange={setShowCloseSessionDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-destructive">
              <Icon icon="solar:logout-bold" className="h-5 w-5" />
              Close Session
            </DialogTitle>
            <DialogDescription>
              Enter the closing cash balance to close this session. This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <label className="text-sm font-medium">
                Closing Balance *
              </label>
              <Input
                type="number"
                min="0"
                step="0.01"
                value={closingBalance}
                onChange={(e) => setClosingBalance(e.target.value)}
                placeholder="0.00"
                autoFocus
              />
              <p className="text-xs text-muted-foreground">
                Actual cash amount in the drawer
              </p>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">
                Notes
              </label>
              <Input
                value={closingNotes}
                onChange={(e) => setClosingNotes(e.target.value)}
                placeholder="Any discrepancies or notes..."
              />
            </div>

            {cart.length > 0 && (
              <div className="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                <div className="flex items-center gap-2">
                  <Icon icon="solar:danger-triangle-bold" className="h-4 w-4" />
                  <span className="font-medium">Warning</span>
                </div>
                <p className="mt-1">
                  You have {cart.length} item(s) in the cart. Closing the session will clear the cart.
                </p>
              </div>
            )}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCloseSessionDialog(false)}>
              Cancel
            </Button>
            <Button 
              variant="destructive"
              onClick={() => {
                console.log('Close button clicked!')
                console.log('activeSessionId:', activeSessionId)
                console.log('closingBalance:', closingBalance)
                if (!activeSessionId) {
                  console.error('No active session ID')
                  toast({
                    title: 'Error',
                    description: 'No active session to close',
                    variant: 'destructive',
                  })
                  return
                }
                const balance = parseFloat(closingBalance)
                console.log('Parsed balance:', balance)
                if (isNaN(balance) || balance < 0) {
                  toast({
                    title: 'Invalid Balance',
                    description: 'Please enter a valid closing balance',
                    variant: 'destructive',
                  })
                  return
                }
                console.log('Calling closeSessionMutation with:', { sessionId: activeSessionId, closingBalance: balance, notes: closingNotes })
                closeSessionMutation.mutate({
                  sessionId: activeSessionId,
                  closingBalance: balance,
                  notes: closingNotes,
                })
              }}
              disabled={closeSessionMutation.isPending}
            >
              {closeSessionMutation.isPending && (
                <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
              )}
              Close Session
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Receipt Dialog */}
      <Dialog open={showReceiptDialog} onOpenChange={setShowReceiptDialog}>
        <DialogContent className="sm:max-w-[400px] max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:document-text-bold" className="h-5 w-5" />
              Order Receipt
            </DialogTitle>
          </DialogHeader>
          
          {lastOrder && (
            <div className="border rounded-lg overflow-hidden bg-white">
              <POSReceipt
                ref={receiptRef}
                data={lastOrder}
                businessName="NoovaERP Store"
                businessAddress="Your Business Address"
                businessPhone="+1 234 567 890"
                footerMessage="Thank you for shopping with us!"
              />
            </div>
          )}

          <DialogFooter className="flex-col sm:flex-row gap-2">
            <Button 
              variant="outline" 
              onClick={() => setShowReceiptDialog(false)}
              className="flex-1"
            >
              Close
            </Button>
            <Button 
              variant="outline"
              className="flex-1"
              onClick={() => {
                // Email receipt functionality placeholder
                toast({ 
                  title: "Coming Soon", 
                  description: "Email receipt feature coming soon" 
                })
              }}
            >
              <Icon icon="solar:letter-bold" className="h-4 w-4 mr-2" />
              Email
            </Button>
            <Button 
              className="flex-1"
              onClick={() => {
                if (receiptRef.current) {
                  const printWindow = window.open('', '_blank')
                  if (printWindow) {
                    printWindow.document.write(`
                      <!DOCTYPE html>
                      <html>
                        <head>
                          <title>Receipt - ${lastOrder?.receiptNumber || lastOrder?.orderNumber}</title>
                          <style>
                            * { margin: 0; padding: 0; box-sizing: border-box; }
                            body { 
                              font-family: 'Courier New', Courier, monospace;
                              font-size: 12px;
                              line-height: 1.4;
                              color: #000;
                              background: #fff;
                              padding: 10px;
                              width: 80mm;
                              max-width: 80mm;
                            }
                            .receipt-container {
                              width: 100%;
                              max-width: 300px;
                              margin: 0 auto;
                              padding: 15px;
                            }
                            .text-center { text-align: center; }
                            .text-right { text-align: right; }
                            .text-left { text-align: left; }
                            .font-bold { font-weight: bold; }
                            .text-xl { font-size: 18px; }
                            .text-lg { font-size: 16px; }
                            .text-sm { font-size: 11px; }
                            .text-xs { font-size: 10px; }
                            .mb-1 { margin-bottom: 4px; }
                            .mb-2 { margin-bottom: 8px; }
                            .mb-3 { margin-bottom: 12px; }
                            .mb-4 { margin-bottom: 16px; }
                            .mt-1 { margin-top: 4px; }
                            .mt-2 { margin-top: 8px; }
                            .mt-4 { margin-top: 16px; }
                            .my-2 { margin: 8px 0; }
                            .my-3 { margin: 12px 0; }
                            .my-4 { margin: 16px 0; }
                            .pt-1 { padding-top: 4px; }
                            .pt-3 { padding-top: 12px; }
                            .p-2 { padding: 8px; }
                            .uppercase { text-transform: uppercase; }
                            .border-dashed { 
                              border-style: dashed; 
                              border-color: #999;
                            }
                            .border-b { border-bottom-width: 1px; }
                            .border-b-2 { border-bottom-width: 2px; }
                            .border-t { border-top-width: 1px; }
                            .border { border-width: 1px; border-style: solid; border-color: #ccc; }
                            .rounded { border-radius: 4px; }
                            .flex { display: flex; }
                            .justify-between { justify-content: space-between; }
                            .items-center { align-items: center; }
                            .flex-1 { flex: 1; }
                            .gap-1 { gap: 1px; }
                            .inline-flex { display: inline-flex; }
                            .flex-col { flex-direction: column; }
                            .space-y-1 > * + * { margin-top: 4px; }
                            .space-y-2 > * + * { margin-top: 8px; }
                            .divider {
                              border-bottom: 1px dashed #999;
                              margin: 8px 0;
                            }
                            .header { text-align: center; margin-bottom: 15px; }
                            .header h1 { font-size: 20px; font-weight: bold; letter-spacing: 2px; }
                            .header p { font-size: 11px; margin-top: 3px; }
                            .info-row { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 3px; }
                            .customer-box { border: 1px solid #ccc; border-radius: 4px; padding: 8px; margin-bottom: 10px; font-size: 11px; }
                            .items-header { display: flex; justify-content: space-between; font-size: 11px; font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-bottom: 8px; }
                            .item-row { margin-bottom: 8px; }
                            .item-name { font-size: 11px; font-weight: 500; }
                            .item-details { display: flex; justify-content: space-between; font-size: 11px; }
                            .totals-section { font-size: 11px; }
                            .total-row { display: flex; justify-content: space-between; margin-bottom: 3px; }
                            .grand-total { display: flex; justify-content: space-between; font-size: 16px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px; margin-top: 5px; }
                            .payment-section { font-size: 11px; }
                            .barcode { display: flex; justify-content: center; gap: 1px; margin: 15px 0; }
                            .barcode-bar { background: #000; height: 40px; }
                            .barcode-text { font-size: 10px; text-align: center; margin-top: 5px; }
                            .footer { text-align: center; font-size: 11px; margin-top: 15px; padding-top: 10px; border-top: 1px dashed #999; }
                            .footer .thank-you { font-weight: bold; margin-bottom: 5px; }
                            .footer .powered { font-size: 9px; color: #666; margin-top: 10px; }
                            .bg-gray { background-color: #f3f4f6; padding: 8px; border-radius: 4px; text-align: center; margin-bottom: 10px; }
                            @media print {
                              body { padding: 0; width: 80mm; }
                              @page { margin: 5mm; size: 80mm auto; }
                            }
                          </style>
                        </head>
                        <body>
                          <div class="receipt-container">
                            ${receiptRef.current.innerHTML}
                          </div>
                        </body>
                      </html>
                    `)
                    printWindow.document.close()
                    printWindow.focus()
                    setTimeout(() => {
                      printWindow.print()
                      printWindow.close()
                    }, 250)
                  }
                }
              }}
            >
              <Icon icon="solar:printer-bold" className="h-4 w-4 mr-2" />
              Print
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Stripe Payment Dialog */}
      <StripePaymentDialog
        open={showStripeDialog}
        onOpenChange={setShowStripeDialog}
        amount={total}
        posOrderId={stripePaymentData.orderId}
        gatewayId={stripePaymentData.gatewayId || 0}
        onSuccess={async (transactionId) => {
          toast({
            title: 'Payment successful!',
            description: `Transaction ID: ${transactionId}`,
          })
          
          // Close Stripe dialog first
          setShowStripeDialog(false)
          
          // Set receipt data BEFORE clearing cart
          setLastOrder({
            orderNumber: stripePaymentData.orderId?.toString() || 'ONLINE',
            receiptNumber: `ONLINE-${Date.now()}`,
            date: new Date(),
            customer: selectedCustomer ? {
              name: selectedCustomer.name,
              phone: selectedCustomer.phone,
              email: selectedCustomer.email,
            } : { name: 'Walk-in Customer' },
            items: cart.map(item => ({
              name: item.name,
              quantity: item.quantity,
              price: item.price,
              line_total: item.line_total
            })),
            subtotal,
            taxAmount,
            discountAmount,
            discountType: discount.type,
            discountValue: discount.value,
            total,
            paymentMethod: 'Card (Stripe)',
            amountPaid: total,
            change: 0,
            terminal: activeTerminalId,
            transactionId,
          })
          
          // Show receipt dialog
          setShowReceiptDialog(true)
          
          // Clear cart after setting receipt data
          setTimeout(() => {
            clearSessionCart()
            setSelectedCustomer(null)
            setCurrentOrderId(null)
          }, 500)
        }}
        onError={(error) => {
          toast({
            variant: 'destructive',
            title: 'Payment failed',
            description: error,
          })
        }}
      />
    </div>
  </div>
  )
}

function userDiscountInput(val: string): number {
    const n = parseFloat(val)
    return isNaN(n) ? 0 : n
}
