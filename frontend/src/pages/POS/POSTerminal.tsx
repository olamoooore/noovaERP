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
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { useToast } from '@/hooks/use-toast'
import { cn, formatCurrency, getCurrencySymbol } from '@/lib/utils'
import { useSettingsStore } from '@/store/settingsStore'
import { usePosSessionStore, CartItem } from '@/store/posSessionStore'
import { POSReceipt } from '@/components/pos/POSReceipt'

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

  const taxConfig = useSettingsStore((s) => s.tax)
  
  // Local State
  const [search, setSearch] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null)
  const [warehouseId, setWarehouseId] = useState<string>(sessionWarehouseId ?? 'all')
  const [showCheckoutDialog, setShowCheckoutDialog] = useState(false)
  const [paymentMethod, setPaymentMethod] = useState<string>('cash')
  const [barcodeInput, setBarcodeInput] = useState('')
  
  // Customer Search & Add States
  const [customerSearchOpen, setCustomerSearchOpen] = useState(false)
  const [customerSearch, setCustomerSearch] = useState('')
  const [showAddCustomerDialog, setShowAddCustomerDialog] = useState(false)
  const [newCustomer, setNewCustomer] = useState({ name: '', phone: '', email: '' })
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null)
  
  // Receipt Dialog State
  const [showReceiptDialog, setShowReceiptDialog] = useState(false)
  const [lastOrder, setLastOrder] = useState<any>(null)
  const receiptRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!activeTerminalId || !openedAt) {
      navigate('/pos', { replace: true })
    }
  }, [activeTerminalId, openedAt, navigate])

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

  const taxEnabled = taxConfig.enabled
  const pricingMode = taxConfig.pricing_mode
  const defaultTaxRate = taxConfig.default_tax_rate ?? 0

  const getEffectiveTaxRate = (item: Product | CartItem) => {
    if (!taxEnabled) return 0
    const rate = typeof item.tax_rate === 'number' ? item.tax_rate : 0
    return rate > 0 ? rate : defaultTaxRate
  }

  const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100

  const computeLine = (item: Product | CartItem, quantity: number) => {
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
            ...computeLine(product, 1),
        }
        setCart([...cart, newItem])
    }
  }, [cart, setCart, toast, taxEnabled, pricingMode, defaultTaxRate])

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
  
  const getOrderData = () => ({
    customer_id: customerId !== 'walk-in' ? parseInt(customerId) : null,
    session_id: activeTerminalId ? parseInt(activeTerminalId) : null,
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
          // Check if we are UPDATING an existing draft?
          // Current logic in `PosOrderController` assumes `store` creates new or updates if we passed an ID?
          // The controller uses `firstOrCreate(['external_id' => ...])`.
          // We don't track `external_id` or `order_id` in the frontend session yet.
          // For now, "Save Draft" creates a NEW draft.
          // To update, we'd need to store `currentOrderId` in the store.
          // Simplification for now: Always create new draft. (Or maybe we implement `currentOrderId` later).
          // Ideally, loading a draft should set `currentOrderId` so we update it.
          // But strict "Save as Draft" usually means "Park Order".
          
          await createOrderMutation.mutateAsync(getOrderData())
          toast({ title: "Draft Saved", description: "Order saved as draft." })
          clearSessionCart()
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
      // Create order
      const order = await createOrderMutation.mutateAsync(getOrderData())

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

      // Clear cart after showing receipt
      clearSessionCart()
      setSelectedCustomer(null)

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
            <Badge variant="secondary">{cart.length} items</Badge>
          </div>
          
          {/* Customer & Warehouse Selection */}
          <div className="grid grid-cols-2 gap-2 mt-3">
            {/* Customer Searchable Dropdown */}
            <Popover open={customerSearchOpen} onOpenChange={setCustomerSearchOpen}>
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  role="combobox"
                  aria-expanded={customerSearchOpen}
                  className="h-9 justify-between text-sm"
                >
                  <span className="truncate">
                    {selectedCustomer ? selectedCustomer.name : 
                     customerId === 'walk-in' ? 'Walk-in Customer' : 'Select Customer'}
                  </span>
                  <Icon icon="solar:alt-arrow-down-linear" className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
              </PopoverTrigger>
              <PopoverContent className="w-[280px] p-0" align="start">
                <Command shouldFilter={false}>
                  <CommandInput 
                    placeholder="Search customers..." 
                    value={customerSearch}
                    onValueChange={setCustomerSearch}
                  />
                  <CommandList>
                    <CommandEmpty>
                      {searchingCustomers ? (
                        <div className="flex items-center justify-center py-4">
                          <Icon icon="solar:refresh-bold" className="h-4 w-4 animate-spin mr-2" />
                          Searching...
                        </div>
                      ) : customerSearch.length >= 2 ? (
                        <div className="py-4 text-center text-sm">
                          No customer found.
                          <Button 
                            variant="link" 
                            className="p-0 h-auto ml-1"
                            onClick={() => {
                              setNewCustomer({ ...newCustomer, name: customerSearch })
                              setShowAddCustomerDialog(true)
                              setCustomerSearchOpen(false)
                            }}
                          >
                            Add new?
                          </Button>
                        </div>
                      ) : (
                        <div className="py-4 text-center text-sm text-muted-foreground">
                          Type at least 2 characters to search
                        </div>
                      )}
                    </CommandEmpty>
                    <CommandGroup>
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
                        Walk-in Customer
                        {customerId === 'walk-in' && (
                          <Icon icon="solar:check-circle-bold" className="ml-auto h-4 w-4" />
                        )}
                      </CommandItem>
                      {(customerSearch.length >= 2 ? searchedCustomers : customers)?.map((customer) => (
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
                          <div className="flex flex-col flex-1">
                            <span className="font-medium">{customer.name}</span>
                            {customer.phone && (
                              <span className="text-xs text-muted-foreground">{customer.phone}</span>
                            )}
                          </div>
                          {customer.loyalty_points !== undefined && customer.loyalty_points > 0 && (
                            <Badge variant="secondary" className="ml-2 text-xs">
                              {customer.loyalty_points} pts
                            </Badge>
                          )}
                          {customerId === customer.id.toString() && (
                            <Icon icon="solar:check-circle-bold" className="ml-2 h-4 w-4" />
                          )}
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  </CommandList>
                  <div className="border-t p-2">
                    <Button 
                      variant="ghost" 
                      size="sm" 
                      className="w-full justify-start"
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
            
            <Select
              value={warehouseId}
              onValueChange={(v) => {
                setWarehouseId(v)
                setWarehouse(v === 'all' ? null : v)
              }}
            >
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
              onClick={() => clearSessionCart()}
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
          
          <div className="space-y-4 py-4">
            {/* Customer Info */}
            {selectedCustomer && (
              <div className="rounded-lg border p-3 bg-muted/30">
                <div className="flex items-center gap-2">
                  <Icon icon="solar:user-circle-bold" className="h-5 w-5 text-primary" />
                  <div>
                    <p className="font-medium text-sm">{selectedCustomer.name}</p>
                    {selectedCustomer.phone && (
                      <p className="text-xs text-muted-foreground">{selectedCustomer.phone}</p>
                    )}
                  </div>
                  {selectedCustomer.loyalty_points !== undefined && (
                    <Badge variant="secondary" className="ml-auto">
                      {selectedCustomer.loyalty_points} points
                    </Badge>
                  )}
                </div>
              </div>
            )}

            <div className="space-y-2">
              <label className="text-sm font-medium">Payment Method</label>
              <Select value={paymentMethod} onValueChange={setPaymentMethod}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="cash">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:money-bag-bold" className="h-4 w-4" />
                      Cash
                    </div>
                  </SelectItem>
                  <SelectItem value="card">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:card-bold" className="h-4 w-4" />
                      Card
                    </div>
                  </SelectItem>
                  <SelectItem value="bank_transfer">
                    <div className="flex items-center gap-2">
                      <Icon icon="mdi:bank" className="h-4 w-4" />
                      Bank Transfer
                    </div>
                  </SelectItem>
                  <SelectItem value="mobile_money">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:smartphone-bold" className="h-4 w-4" />
                      Mobile Money
                    </div>
                  </SelectItem>
                  <SelectItem value="other">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:wallet-bold" className="h-4 w-4" />
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
              {discountAmount > 0 && (
                <div className="flex justify-between text-destructive">
                  <span>Discount</span>
                  <span>-{formatCurrency(discountAmount)}</span>
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
                      <html>
                        <head>
                          <title>Receipt</title>
                          <style>
                            body { margin: 0; padding: 20px; font-family: 'Courier New', Courier, monospace; }
                            @media print { body { padding: 0; } }
                          </style>
                        </head>
                        <body>
                          ${receiptRef.current.innerHTML}
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
    </div>
  </div>
  )
}

function userDiscountInput(val: string): number {
    const n = parseFloat(val)
    return isNaN(n) ? 0 : n
}
