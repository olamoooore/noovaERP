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
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import { cn, formatCurrency, getCurrencySymbol } from '@/lib/utils'
import { useSettingsStore } from '@/store/settingsStore'
import { usePosSessionStore, CartItem } from '@/store/posSessionStore'
import { POSReceipt } from '@/components/pos/POSReceipt'
import { PaymentMethodSelector } from '@/components/pos/PaymentMethodSelector'
import { StripePaymentDialog } from '@/components/StripePaymentDialog'
import { HitPayPaymentDialog } from '@/components/pos/HitPayPaymentDialog'
import loyaltyApi from '@/lib/loyaltyApi'

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
  current_points?: number
  loyalty_tier_name?: string
  is_enrolled?: boolean
  type?: 'individual' | 'company'
}

interface LoyaltyRedemptionInfo {
  enrolled: boolean
  available_points: number
  tier_name: string
  tier_multiplier: number
  currency_per_point: number
  min_redemption_points: number
  max_redemption_percent: number
  max_redeemable_points: number
  max_discount_amount: number
}

interface Warehouse {
  id: number
  name: string
}

interface PosPaymentResponse {
  data?: {
    id?: number
    order_number?: string
    receipt_number?: string | null
    paid_at?: string | null
  }
  loyalty?: {
    earned?: {
      points?: number
      new_balance?: number
      tier_upgraded?: boolean
    } | null
    redeemed?: {
      points?: number
      discount?: number
    } | null
  }
}

interface PosReceiptOrder {
  id?: number | null
  orderNumber: string
  receiptNumber: string
  date: Date
  customer: {
    name: string
    phone?: string
    email?: string
    loyaltyPoints?: number
  }
  items: Array<{
    name: string
    quantity: number
    price: number
    line_total: number
  }>
  subtotal: number
  taxAmount: number
  discountAmount: number
  discountType: string
  discountValue: number
  coupon: {
    code: string
    discountAmount: number
    discountType: 'fixed' | 'percent'
    discountValue: number
  } | null
  loyaltyDiscountAmount: number
  loyaltyPointsEarned?: number
  loyaltyPointsUsed?: number
  total: number
  paymentMethod: string
  amountPaid: number
  change: number
  terminal: number | null
  transactionId?: string
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
  const setCustomerInfo = usePosSessionStore((s) => s.setCustomerInfo)
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

  // Clean any stale payment params from URL (safety measure)
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    if (params.has('payment_status') || params.has('reference') || params.has('ref')) {
      window.history.replaceState({}, '', window.location.pathname)
    }
  }, [])
  
  // Local State
  const [search, setSearch] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null)
  const [warehouseId, setWarehouseId] = useState<string>(sessionWarehouseId ?? 'all')
  const [showCheckoutDialog, setShowCheckoutDialog] = useState(false)
  const [paymentMethod, setPaymentMethod] = useState<string>('cash')
  const [barcodeInput, setBarcodeInput] = useState('')
  const [currentOrderId, setCurrentOrderId] = useState<string | null>(null) // Track draft order ID
  const [productPage, setProductPage] = useState(1) // Product pagination
  
  // Stripe Payment Dialog State
  const [showStripeDialog, setShowStripeDialog] = useState(false)
  const [stripePaymentData, setStripePaymentData] = useState<{
    orderId: number | null
    gatewayId: number | null
  }>({ orderId: null, gatewayId: null })

  // HitPay Payment Dialog State
  const [showHitPayDialog, setShowHitPayDialog] = useState(false)
  const [hitPayPaymentData, setHitPayPaymentData] = useState<{
    orderId: number | null
    gatewayId: number | null
  }>({ orderId: null, gatewayId: null })
  
  // Customer Search & Add States
  const [customerSearchOpen, setCustomerSearchOpen] = useState(false)
  const [customerSearch, setCustomerSearch] = useState('')
  const [showAddCustomerDialog, setShowAddCustomerDialog] = useState(false)
  const [newCustomer, setNewCustomer] = useState({ name: '', phone: '', email: '' })
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null)
  const [showCustomerDialog, setShowCustomerDialog] = useState(false)
  
  // Session Close State
  const [showCloseSessionDialog, setShowCloseSessionDialog] = useState(false)
  const [closingBalance, setClosingBalance] = useState<string>('')
  const [closingNotes, setClosingNotes] = useState<string>('')
  
  // Discount/Loyalty/Coupon Tab State
  const [activeDiscountTab, setActiveDiscountTab] = useState<'discount' | 'loyalty' | 'coupon'>('discount')
  const [couponCode, setCouponCode] = useState('')
  const [appliedCoupon, setAppliedCoupon] = useState<{
    code: string
    discount_type: 'fixed' | 'percent'
    discount_value: number
    description?: string
  } | null>(null)
  
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
  const [lastOrder, setLastOrder] = useState<PosReceiptOrder | null>(null)
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

  // Reset page when search/category/warehouse changes
  useEffect(() => {
    setProductPage(1)
  }, [search, selectedCategory, warehouseId])

  // Fetch Products with pagination
  const { data: productsResponse, isLoading: productsLoading } = useQuery({
    queryKey: ['pos-products', search, selectedCategory, warehouseId, productPage],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: 50, page: productPage }
      if (search) params.search = search
      if (selectedCategory) params.category_id = selectedCategory
      if (warehouseId && warehouseId !== 'all') params.warehouse_id = warehouseId
      const response = await api.get('/products', { params })
      if (Array.isArray(response.data)) {
        return { data: response.data, last_page: 1, current_page: 1, total: response.data.length }
      }
      return {
        data: response.data.data || [],
        last_page: response.data.last_page || response.data.meta?.last_page || 1,
        current_page: response.data.current_page || response.data.meta?.current_page || 1,
        total: response.data.total || response.data.meta?.total || 0,
      }
    },
    placeholderData: (prev) => prev, // keep previous data while fetching
  })

  const productsData = productsResponse?.data
  const productLastPage = productsResponse?.last_page ?? 1
  const productTotal = productsResponse?.total ?? 0

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

  // Customer Search Query with debounce
  const [debouncedSearch, setDebouncedSearch] = useState('')
  
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(customerSearch)
    }, 300)
    return () => clearTimeout(timer)
  }, [customerSearch])
  
  // Refetch customers when dialog opens
  useEffect(() => {
    if (showCustomerDialog) {
      refetchCustomers()
    }
  }, [showCustomerDialog])
  
  // Ref to access current itemsTotal in queries
  const itemsTotalRef = useRef(0)
  
  const { data: searchedCustomers, isLoading: searchingCustomers } = useQuery<Customer[]>({
    queryKey: ['customers-search', debouncedSearch],
    queryFn: async () => {
      if (!debouncedSearch || debouncedSearch.length < 2) return []
      const response = await api.get('/customers', { 
        params: { search: debouncedSearch, per_page: 20 } 
      })
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
    enabled: debouncedSearch.length >= 2,
  })

  // Loyalty Redemption State
  const [loyaltyRedemption, setLoyaltyRedemption] = useState<{
    pointsToRedeem: number
    discountAmount: number
    redemptionId?: number
  }>({ pointsToRedeem: 0, discountAmount: 0 })

  // Fetch Loyalty Info for selected customer
  const [loyaltyQueryKey, setLoyaltyQueryKey] = useState(['loyalty-pos-info', null, 0])
  
  const { data: loyaltyInfo, refetch: refetchLoyaltyInfo } = useQuery<LoyaltyRedemptionInfo>({
    queryKey: loyaltyQueryKey,
    queryFn: async () => {
      if (!selectedCustomer?.id) return null
      const response = await loyaltyApi.getPosRedemptionInfo(selectedCustomer.id, itemsTotalRef.current)
      return response
    },
    enabled: !!selectedCustomer?.id,
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
      setCustomerInfo({
        id: newCust.id,
        name: newCust.name,
        email: newCust.email,
        phone: newCust.phone,
        loyalty_points: newCust.loyalty_points || newCust.current_points,
        loyalty_tier_name: newCust.loyalty_tier_name,
      })
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
      // Add a small delay to ensure order is fully committed to database
      await new Promise(resolve => setTimeout(resolve, 100))
      
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
  
  // Calculate Discount Amount (regular + coupon + loyalty)
  let regularDiscountAmount = 0
  if (discount.type === 'fixed') {
      regularDiscountAmount = discount.value
  } else if (discount.type === 'percent') {
      regularDiscountAmount = itemsTotal * (discount.value / 100)
  }
  regularDiscountAmount = round2(regularDiscountAmount)
  
  // Coupon discount
  let couponDiscountAmount = 0
  if (appliedCoupon) {
    if (appliedCoupon.discount_type === 'fixed') {
      couponDiscountAmount = appliedCoupon.discount_value
    } else if (appliedCoupon.discount_type === 'percent') {
      couponDiscountAmount = itemsTotal * (appliedCoupon.discount_value / 100)
    }
    couponDiscountAmount = round2(couponDiscountAmount)
  }
  
  // Loyalty discount amount
  const loyaltyDiscountAmount = loyaltyRedemption.discountAmount || 0
  
  // Total discount cannot exceed item total
  const totalDiscountAmount = round2(Math.min(regularDiscountAmount + couponDiscountAmount + loyaltyDiscountAmount, itemsTotal))
  
  const total = round2(Math.max(0, itemsTotal - totalDiscountAmount))

  // Update ref for loyalty queries
  useEffect(() => {
    itemsTotalRef.current = itemsTotal
  }, [itemsTotal])

  // Update loyalty query key when customer or cart changes
  useEffect(() => {
    setLoyaltyQueryKey(['loyalty-pos-info', selectedCustomer?.id ?? null, itemsTotal])
  }, [selectedCustomer?.id, itemsTotal])

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

  // Extract detailed error message from API error response
  const extractErrorMessage = (error: any): string => {
    if (error.response?.data) {
      const data = error.response.data
      if (data.errors) {
        const messages = Object.values(data.errors).flat()
        return (messages as string[]).join(', ')
      }
      if (data.message) return data.message
      if (typeof data === 'string') return data
    }
    return error.message || 'An error occurred'
  }
  
  const getOrderData = (externalId?: string) => ({
    external_id: externalId,
    customer_id: customerId !== 'walk-in' ? parseInt(customerId) : null,
    session_id: activeSessionId || null,
    terminal_id: activeTerminalId ? parseInt(activeTerminalId) : null,
    notes: customerId !== 'walk-in' 
      ? `Customer: ${selectedCustomer?.name || 'Selected Customer'}${appliedCoupon ? ` | Coupon: ${appliedCoupon.code}` : ''}` 
      : `Walk-in customer${appliedCoupon ? ` | Coupon: ${appliedCoupon.code}` : ''}`,
    discount_type: discount.type,
    discount_value: discount.value,
    coupon_code: appliedCoupon?.code || null,
    coupon_discount: couponDiscountAmount > 0 ? couponDiscountAmount : null,
    loyalty_points_redeemed: loyaltyRedemption.pointsToRedeem > 0 ? loyaltyRedemption.pointsToRedeem : null,
    loyalty_discount: loyaltyRedemption.discountAmount > 0 ? loyaltyRedemption.discountAmount : null,
    items: cart.map(item => ({
      product_id: item.id,
      quantity: item.quantity,
      unit_price: item.price,
    })),
  })

  // Apply loyalty redemption
  const applyLoyaltyRedemption = (points: number) => {
    if (!loyaltyInfo?.enrolled) return
    
    const discountAmount = round2(points * loyaltyInfo.currency_per_point)
    setLoyaltyRedemption({ pointsToRedeem: points, discountAmount })
    
    toast({
      title: 'Loyalty Points Applied',
      description: `${points} points = ${formatCurrency(discountAmount)} discount`,
    })
  }

  // Clear loyalty redemption
  const clearLoyaltyRedemption = () => {
    setLoyaltyRedemption({ pointsToRedeem: 0, discountAmount: 0 })
    toast({
      title: 'Loyalty Points Removed',
      description: 'Points redemption has been cancelled',
    })
  }

  // Apply coupon
  const applyCoupon = async () => {
    if (!couponCode.trim()) return
    
    try {
      // For now, simulate coupon validation - in production this would call the API
      // const response = await api.post('/coupons/validate', { code: couponCode, amount: itemsTotal })
      
      // Mock coupon for demonstration
      const mockCoupons: Record<string, any> = {
        'SAVE10': { code: 'SAVE10', discount_type: 'percent', discount_value: 10, description: '10% off your order' },
        'SAVE20': { code: 'SAVE20', discount_type: 'percent', discount_value: 20, description: '20% off your order' },
        'FLAT50': { code: 'FLAT50', discount_type: 'fixed', discount_value: 50, description: '$50 off your order' },
      }
      
      const coupon = mockCoupons[couponCode.toUpperCase()]
      
      if (coupon) {
        setAppliedCoupon(coupon)
        toast({
          title: 'Coupon Applied',
          description: `${coupon.code}: ${coupon.description}`,
        })
        setCouponCode('')
      } else {
        toast({
          title: 'Invalid Coupon',
          description: 'The coupon code you entered is not valid',
          variant: 'destructive',
        })
      }
    } catch (error: any) {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to apply coupon',
        variant: 'destructive',
      })
    }
  }

  const resetTerminalForNextOrder = useCallback(() => {
    clearSessionCart()
    setSelectedCustomer(null)
    setCustomerId('walk-in')
    setCustomerInfo(null)
    setCurrentOrderId(null)
    setLoyaltyRedemption({ pointsToRedeem: 0, discountAmount: 0 })
    setAppliedCoupon(null)
    setDiscount({ type: 'fixed', value: 0 })
    setActiveDiscountTab('discount')
  }, [clearSessionCart, setCustomerId, setCustomerInfo, setDiscount])

  const buildReceiptOrder = useCallback((
    order: { id?: number | null; order_number?: string; receipt_number?: string | null; paid_at?: string | null },
    paymentMethodLabel: string,
    options?: { loyaltyPointsEarned?: number; transactionId?: string }
  ): PosReceiptOrder => ({
    id: order.id ?? null,
    orderNumber: order.order_number || String(order.id || 'POS'),
    receiptNumber: order.receipt_number || order.order_number || String(order.id || 'POS'),
    date: order.paid_at ? new Date(order.paid_at) : new Date(),
    customer: selectedCustomer ? {
      name: selectedCustomer.name,
      phone: selectedCustomer.phone,
      email: selectedCustomer.email,
      loyaltyPoints: selectedCustomer.current_points ?? selectedCustomer.loyalty_points,
    } : { name: 'Walk-in Customer' },
    items: cart.map(item => ({
      name: item.name,
      quantity: item.quantity,
      price: item.price,
      line_total: item.line_total,
    })),
    subtotal,
    taxAmount,
    discountAmount: regularDiscountAmount,
    discountType: discount.type,
    discountValue: discount.value,
    coupon: appliedCoupon ? {
      code: appliedCoupon.code,
      discountAmount: couponDiscountAmount,
      discountType: appliedCoupon.discount_type,
      discountValue: appliedCoupon.discount_value,
    } : null,
    loyaltyDiscountAmount,
    loyaltyPointsEarned: options?.loyaltyPointsEarned,
    loyaltyPointsUsed: loyaltyRedemption.pointsToRedeem,
    total,
    paymentMethod: paymentMethodLabel,
    amountPaid: total,
    change: 0,
    terminal: activeTerminalId,
    transactionId: options?.transactionId,
  }), [
    activeTerminalId,
    appliedCoupon,
    cart,
    couponDiscountAmount,
    discount.type,
    discount.value,
    loyaltyDiscountAmount,
    loyaltyRedemption.pointsToRedeem,
    regularDiscountAmount,
    selectedCustomer,
    subtotal,
    taxAmount,
    total,
  ])

  const finalizeCheckoutSuccess = useCallback((receiptData: PosReceiptOrder, description: string) => {
    setLastOrder(receiptData)
    setShowCheckoutDialog(false)
    setShowReceiptDialog(true)

    toast({
      title: 'Payment Successful',
      description,
    })

    resetTerminalForNextOrder()
    void queryClient.invalidateQueries({ queryKey: ['pos-products'] })
    void queryClient.invalidateQueries({ queryKey: ['customers-pos'] })
    void queryClient.invalidateQueries({ queryKey: ['customers-search'] })
  }, [queryClient, resetTerminalForNextOrder, toast])

  const downloadReceiptPdf = useCallback(async (orderId?: number | null, orderNumber?: string) => {
    if (!orderId) {
      toast({
        title: 'Receipt Unavailable',
        description: 'This receipt cannot be downloaded because no order ID was found.',
        variant: 'destructive',
      })
      return
    }

    try {
      const response = await api.get(`/pos/orders/${orderId}/receipt`, {
        responseType: 'blob',
      })

      const url = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', `receipt-${orderNumber || orderId}.pdf`)
      document.body.appendChild(link)
      link.click()
      link.parentNode?.removeChild(link)
      window.URL.revokeObjectURL(url)
    } catch (error: any) {
      toast({
        title: 'Download Failed',
        description: error.response?.data?.message || 'Could not download the receipt PDF.',
        variant: 'destructive',
      })
    }
  }, [toast])

  const printReceiptPreview = useCallback(() => {
    if (!receiptRef.current) {
      return
    }

    const printWindow = window.open('', '_blank')
    if (!printWindow) {
      toast({
        title: 'Print Blocked',
        description: 'Allow pop-ups for this site and try printing again.',
        variant: 'destructive',
      })
      return
    }

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
    window.setTimeout(() => {
      printWindow.print()
      printWindow.close()
    }, 250)
  }, [lastOrder, toast])

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

      const paymentResult = await payOrderMutation.mutateAsync({ orderId: order.id, paymentData }) as PosPaymentResponse
      const paidOrder = paymentResult.data || order
      const loyaltyPointsEarned = paymentResult.loyalty?.earned?.points || 0

      finalizeCheckoutSuccess(
        buildReceiptOrder(paidOrder, paymentMethod, { loyaltyPointsEarned }),
        loyaltyPointsEarned > 0
          ? `Order #${paidOrder.order_number} completed. ${loyaltyPointsEarned} loyalty points earned.`
          : `Order #${paidOrder.order_number} completed`
      )
    } catch (error: any) {
      const errorMessage = extractErrorMessage(error)
      console.error('Checkout error:', error.response?.data || error)
      toast({
        title: "Checkout Failed",
        description: errorMessage,
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
      
      console.log('Creating order with external_id:', orderId)
      
      // Create order
      const order = await createOrderMutation.mutateAsync(getOrderData(orderId))
      
      console.log('Order created successfully:', order)
      console.log('Order ID:', order.id)
      console.log('Order Number:', order.order_number)
      console.log('Order Status:', order.status)

      // Verify order exists before attempting payment
      if (!order || !order.id) {
        throw new Error('Order creation failed - no order ID returned')
      }

      // Skip verification for now - tenant issue
      // try {
      //   console.log('Verifying order exists by fetching it...')
      //   const verifyResponse = await api.get(`/pos/orders/${order.id}`)
      //   console.log('Order verification successful:', verifyResponse.data)
      // } catch (verifyError: any) {
      //   console.error('Order verification failed:', verifyError)
      //   throw new Error(`Order ${order.id} was created but cannot be found in database. This may be a multi-tenancy or database issue.`)
      // }

      // Process payment with selected method
      const paymentData = {
        method: method.code,
        amount: round2(total),
        reference: reference || `POS-${Date.now()}`,
        payment_type: method.type,
        gateway_id: method.gateway_id,
        offline_method_id: String(method.id ?? '').startsWith('offline_') ? parseInt(String(method.id).replace('offline_', '')) : null,
      }

      console.log('Processing payment for order ID:', order.id, 'with data:', paymentData)

      const paymentResult = await payOrderMutation.mutateAsync({ orderId: order.id, paymentData }) as PosPaymentResponse
      const paidOrder = paymentResult.data || order
      const loyaltyPointsEarned = paymentResult.loyalty?.earned?.points || 0

      finalizeCheckoutSuccess(
        buildReceiptOrder(paidOrder, method.name, { loyaltyPointsEarned }),
        loyaltyPointsEarned > 0
          ? `Order #${paidOrder.order_number} completed. ${loyaltyPointsEarned} loyalty points earned.`
          : `Order #${paidOrder.order_number} completed`
      )
    } catch (error: any) {
      const errorMessage = extractErrorMessage(error)
      console.error('Checkout error:', error)
      console.error('Error response:', error.response?.data)
      console.error('Error status:', error.response?.status)
      toast({
        title: "Checkout Failed",
        description: errorMessage,
        variant: "destructive",
      })
    }
  }

  const products = productsData || []

  return (
    <div className="flex flex-col h-full p-4 gap-4 overflow-hidden">
      <div className="flex flex-1 gap-4 overflow-hidden min-h-0">
        {/* Left: Product Selection */}
        <div className="flex flex-1 flex-col gap-4 min-h-0 min-w-0 overflow-hidden">
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

        {/* Pagination Controls */}
        {productLastPage > 1 && (
          <div className="flex items-center justify-between pt-2 shrink-0">
            <span className="text-xs text-muted-foreground">
              {productTotal.toLocaleString()} products · Page {productPage} of {productLastPage}
            </span>
            <div className="flex items-center gap-1">
              <Button
                variant="outline"
                size="sm"
                className="h-7 px-2"
                disabled={productPage <= 1}
                onClick={() => setProductPage(p => Math.max(1, p - 1))}
              >
                <Icon icon="solar:alt-arrow-left-bold" className="h-4 w-4" />
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="h-7 px-2"
                disabled={productPage >= productLastPage}
                onClick={() => setProductPage(p => Math.min(productLastPage, p + 1))}
              >
                <Icon icon="solar:alt-arrow-right-bold" className="h-4 w-4" />
              </Button>
            </div>
          </div>
        )}
      </div>

      {/* Right: Cart */}
      <Card className="flex h-full w-full max-w-[24rem] min-w-[16rem] shrink flex-col overflow-hidden xl:w-96 xl:max-w-none">
        <CardHeader className="pb-3 space-y-3">
          {/* Top Row: Title, Items Badge, Customer Button */}
          <div className="flex items-center justify-between">
            <CardTitle className="flex items-center gap-2 text-lg">
              <Icon icon="solar:cart-large-minimalistic-bold" className="h-5 w-5" />
              Current Order
            </CardTitle>
            <div className="flex items-center gap-2">
              <Badge variant="secondary">{cart.length} items</Badge>
              
              {/* Customer Selector Button */}
              <Button
                variant={selectedCustomer ? "default" : "outline"}
                size="sm"
                className="h-8 gap-2 min-w-[110px]"
                onClick={() => setShowCustomerDialog(true)}
              >
                <Icon 
                  icon={selectedCustomer ? "solar:user-check-bold" : "solar:user-circle-bold"} 
                  className="h-4 w-4" 
                />
                <span className="truncate">
                  {selectedCustomer ? 'Customer' : 'Walk-in'}
                </span>
                {selectedCustomer && (
                  <span className="ml-1 w-2 h-2 rounded-full bg-white/80" />
                )}
              </Button>
            </div>
          </div>

          {/* Selected Customer Info Card */}
          {selectedCustomer && (
            <div className="p-3 rounded-lg border bg-gradient-to-r from-primary/5 to-primary/10">
              <div className="flex items-start gap-3">
                <div className="h-10 w-10 rounded-full bg-primary/20 flex items-center justify-center shrink-0">
                  <Icon icon="solar:user-circle-bold" className="h-6 w-6 text-primary" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-medium truncate">{selectedCustomer.name}</p>
                  {selectedCustomer.phone && (
                    <p className="text-xs text-muted-foreground">{selectedCustomer.phone}</p>
                  )}
                  
                  {/* Loyalty Info */}
                  {loyaltyInfo?.enrolled ? (
                    <div className="mt-2 flex items-center gap-2 flex-wrap">
                      <Badge 
                        variant="secondary" 
                        className="text-xs bg-amber-100 text-amber-700 hover:bg-amber-100 border-amber-200"
                      >
                        <Icon icon="solar:crown-bold" className="h-3 w-3 mr-1" />
                        {loyaltyInfo.tier_name}
                      </Badge>
                      <Badge variant="outline" className="text-xs">
                        {loyaltyInfo.available_points.toLocaleString()} pts
                      </Badge>
                    </div>
                  ) : (
                    <p className="text-xs text-muted-foreground mt-1">Not enrolled in loyalty</p>
                  )}
                </div>
                <Button 
                  variant="ghost" 
                  size="icon" 
                  className="h-7 w-7 shrink-0 -mt-1 -mr-1" 
                  onClick={() => {
                    setSelectedCustomer(null)
                    setCustomerId('walk-in')
                    setCustomerInfo(null)
                    setLoyaltyRedemption({ pointsToRedeem: 0, discountAmount: 0 })
                  }}
                >
                  <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
                </Button>
              </div>
            </div>
          )}
        </CardHeader>

        <ScrollArea className="flex-1 px-4">
          <div className="space-y-3">
            {cart.map((item) => (
              <div key={item.id} className="flex gap-2 rounded-lg border p-2 min-w-0">
                {/* Product Image */}
                <div className="h-12 w-12 rounded bg-muted flex items-center justify-center shrink-0">
                  {item.image ? (
                    <img src={item.image} alt={item.name} className="w-full h-full object-cover rounded" />
                  ) : (
                    <Icon icon="solar:box-bold" className="h-6 w-6 text-muted-foreground" />
                  )}
                </div>
                
                {/* Product Info - Flexible with proper truncation */}
                <div className="flex-1 flex flex-col justify-center" style={{ minWidth: 0, overflow: 'hidden' }}>
                  <h4 
                    className="font-medium text-sm leading-tight" 
                    title={item.name}
                    style={{ 
                      overflow: 'hidden', 
                      textOverflow: 'ellipsis', 
                      whiteSpace: 'nowrap',
                      maxWidth: '100%'
                    }}
                  >
                    {item.name}
                  </h4>
                  <p className="text-xs text-muted-foreground mt-0.5" style={{ whiteSpace: 'nowrap' }}>
                    {formatCurrency(item.price)} x {item.quantity}
                  </p>
                </div>
                
                {/* Price and Actions - Fixed Width */}
                <div className="flex min-w-[132px] flex-col items-end justify-between gap-1">
                  <span className="font-bold text-sm whitespace-nowrap">{formatCurrency(item.line_total)}</span>
                  <div className="flex flex-wrap items-center justify-end gap-0.5">
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-6 w-6 shrink-0"
                      onClick={() => updateQuantity(item.id, -1)}
                    >
                      <Icon icon="solar:minus-circle-bold" className="h-4 w-4" />
                    </Button>
                    <span className="text-sm w-7 text-center shrink-0">{item.quantity}</span>
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-6 w-6 shrink-0"
                      onClick={() => updateQuantity(item.id, 1)}
                    >
                      <Icon icon="solar:add-circle-bold" className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-6 w-6 text-destructive shrink-0"
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
          
          {/* Discount/Loyalty/Coupon Tabs */}
          {(cart.length > 0 || appliedCoupon || loyaltyRedemption.pointsToRedeem > 0) && (
            <div className="rounded-lg border bg-white overflow-hidden">
              <Tabs value={activeDiscountTab} onValueChange={(v) => setActiveDiscountTab(v as any)} className="w-full">
                <TabsList className="w-full grid grid-cols-3 rounded-none border-b bg-muted/30">
                  <TabsTrigger value="discount" className="text-xs gap-1">
                    <Icon icon="solar:tag-bold" className="h-3.5 w-3.5" />
                    Discount
                    {regularDiscountAmount > 0 && (
                      <span className="ml-1 w-1.5 h-1.5 rounded-full bg-primary" />
                    )}
                  </TabsTrigger>
                  <TabsTrigger 
                    value="loyalty" 
                    className="text-xs gap-1"
                    disabled={!selectedCustomer || !loyaltyInfo?.enrolled}
                  >
                    <Icon icon="solar:gift-bold" className="h-3.5 w-3.5" />
                    Loyalty
                    {loyaltyRedemption.pointsToRedeem > 0 && (
                      <span className="ml-1 w-1.5 h-1.5 rounded-full bg-primary" />
                    )}
                  </TabsTrigger>
                  <TabsTrigger value="coupon" className="text-xs gap-1">
                    <Icon icon="solar:ticket-bold" className="h-3.5 w-3.5" />
                    Coupon
                    {appliedCoupon && (
                      <span className="ml-1 w-1.5 h-1.5 rounded-full bg-primary" />
                    )}
                  </TabsTrigger>
                </TabsList>
                
                {/* Discount Tab */}
                <TabsContent value="discount" className="p-3 space-y-3 mt-0">
                  <div className="flex items-center gap-2">
                    <Select 
                      value={discount.type} 
                      onValueChange={(val: any) => setDiscount({ ...discount, type: val })}
                    >
                      <SelectTrigger className="w-28 h-9">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="fixed">Fixed Amount</SelectItem>
                        <SelectItem value="percent">Percentage</SelectItem>
                      </SelectContent>
                    </Select>
                    <div className="relative flex-1">
                      {discount.type === 'fixed' && (
                        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">{getCurrencySymbol()}</span>
                      )}
                      <Input
                        type="number"
                        className={cn("h-9", discount.type === 'fixed' ? "pl-8" : "pl-3")}
                        value={discount.value || ''}
                        onChange={(e) => setDiscount({ ...discount, value: userDiscountInput(e.target.value) })}
                        placeholder="Enter discount..."
                      />
                      {discount.type === 'percent' && (
                        <span className="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">%</span>
                      )}
                    </div>
                  </div>
                  {regularDiscountAmount > 0 && (
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">Discount applied:</span>
                      <span className="font-medium text-destructive">-{formatCurrency(regularDiscountAmount)}</span>
                    </div>
                  )}
                </TabsContent>
                
                {/* Loyalty Tab */}
                <TabsContent value="loyalty" className="p-3 space-y-3 mt-0">
                  {!selectedCustomer ? (
                    <div className="text-center py-4 text-muted-foreground">
                      <Icon icon="solar:user-circle-bold" className="h-8 w-8 mx-auto mb-2 opacity-50" />
                      <p className="text-sm">Select a customer to use loyalty points</p>
                      <Button 
                        variant="link" 
                        size="sm" 
                        className="mt-1"
                        onClick={() => setShowCustomerDialog(true)}
                      >
                        Select Customer
                      </Button>
                    </div>
                  ) : !loyaltyInfo?.enrolled ? (
                    <div className="text-center py-4 text-muted-foreground">
                      <Icon icon="solar:info-circle-bold" className="h-8 w-8 mx-auto mb-2 opacity-50" />
                      <p className="text-sm">Customer not enrolled in loyalty program</p>
                    </div>
                  ) : loyaltyRedemption.pointsToRedeem > 0 ? (
                    <div className="space-y-3">
                      <div className="flex items-center justify-between bg-primary/5 rounded-lg p-3">
                        <div>
                          <p className="font-medium">{loyaltyRedemption.pointsToRedeem.toLocaleString()} points</p>
                          <p className="text-sm text-muted-foreground">= {formatCurrency(loyaltyRedemption.discountAmount)} off</p>
                        </div>
                        <Button 
                          variant="ghost" 
                          size="sm"
                          className="text-destructive"
                          onClick={clearLoyaltyRedemption}
                        >
                          <Icon icon="solar:close-circle-bold" className="h-4 w-4 mr-1" />
                          Remove
                        </Button>
                      </div>
                      <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Badge variant="secondary">{loyaltyInfo.tier_name}</Badge>
                        <span>{loyaltyInfo.available_points.toLocaleString()} pts available</span>
                      </div>
                    </div>
                  ) : loyaltyInfo.max_redeemable_points > 0 ? (
                    <div className="space-y-3">
                      <div className="flex items-center gap-2">
                        <Input
                          type="number"
                          min={loyaltyInfo.min_redemption_points}
                          max={loyaltyInfo.max_redeemable_points}
                          placeholder={`Min ${loyaltyInfo.min_redemption_points} pts`}
                          className="flex-1 h-9"
                          id="loyalty-points-input"
                        />
                        <Button 
                          size="sm"
                          className="h-9"
                          onClick={() => {
                            const input = document.getElementById('loyalty-points-input') as HTMLInputElement
                            const points = parseInt(input?.value || '0')
                            if (points >= loyaltyInfo.min_redemption_points && points <= loyaltyInfo.max_redeemable_points) {
                              applyLoyaltyRedemption(points)
                            } else {
                              toast({
                                title: 'Invalid Points',
                                description: `Enter between ${loyaltyInfo.min_redemption_points} and ${loyaltyInfo.max_redeemable_points} points`,
                                variant: 'destructive',
                              })
                            }
                          }}
                        >
                          Apply
                        </Button>
                      </div>
                      <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <span>Max: {loyaltyInfo.max_redeemable_points.toLocaleString()} pts</span>
                        <span>({formatCurrency(loyaltyInfo.max_discount_amount)} max)</span>
                      </div>
                      <Button
                        variant="link"
                        size="sm"
                        className="h-auto p-0 text-xs"
                        onClick={() => applyLoyaltyRedemption(loyaltyInfo.max_redeemable_points)}
                      >
                        Use maximum ({loyaltyInfo.max_redeemable_points.toLocaleString()} pts)
                      </Button>
                    </div>
                  ) : (
                    <div className="text-center py-4 text-xs text-muted-foreground">
                      {loyaltyInfo.available_points < loyaltyInfo.min_redemption_points ? (
                        <p>Need {loyaltyInfo.min_redemption_points - loyaltyInfo.available_points} more points to redeem</p>
                      ) : itemsTotal === 0 ? (
                        <p>Add items to cart to redeem points</p>
                      ) : (
                        <p>Cannot redeem: max {loyaltyInfo.max_redemption_percent}% of order</p>
                      )}
                    </div>
                  )}
                </TabsContent>
                
                {/* Coupon Tab */}
                <TabsContent value="coupon" className="p-3 space-y-3 mt-0">
                  {appliedCoupon ? (
                    <div className="space-y-3">
                      <div className="flex items-center justify-between bg-amber-50 border border-amber-200 rounded-lg p-3">
                        <div className="flex items-center gap-2">
                          <Icon icon="solar:ticket-bold" className="h-5 w-5 text-amber-600" />
                          <div>
                            <p className="font-medium text-amber-900">{appliedCoupon.code}</p>
                            <p className="text-xs text-amber-700">
                              {appliedCoupon.discount_type === 'percent' 
                                ? `${appliedCoupon.discount_value}% off` 
                                : `${formatCurrency(appliedCoupon.discount_value)} off`}
                            </p>
                          </div>
                        </div>
                        <Button 
                          variant="ghost" 
                          size="sm"
                          className="text-destructive"
                          onClick={() => {
                            setAppliedCoupon(null)
                            toast({ title: 'Coupon removed' })
                          }}
                        >
                          <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
                        </Button>
                      </div>
                      {appliedCoupon.description && (
                        <p className="text-xs text-muted-foreground">{appliedCoupon.description}</p>
                      )}
                    </div>
                  ) : (
                    <div className="space-y-2">
                      <div className="flex items-center gap-2">
                        <Input
                          placeholder="Enter coupon code..."
                          value={couponCode}
                          onChange={(e) => setCouponCode(e.target.value.toUpperCase())}
                          className="flex-1 h-9 uppercase"
                          onKeyDown={(e) => e.key === 'Enter' && applyCoupon()}
                        />
                        <Button 
                          size="sm"
                          className="h-9"
                          onClick={applyCoupon}
                          disabled={!couponCode.trim()}
                        >
                          Apply
                        </Button>
                      </div>
                      <p className="text-xs text-muted-foreground">
                        Enter a valid coupon code to get discount
                      </p>
                    </div>
                  )}
                </TabsContent>
              </Tabs>
            </div>
          )}

          <div className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span className="text-muted-foreground">Subtotal</span>
              <span>{formatCurrency(subtotal)}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Tax</span>
              <span>{formatCurrency(taxAmount)}</span>
            </div>
            {regularDiscountAmount > 0 && (
              <div className="flex justify-between text-destructive">
                <span>Discount ({discount.type === 'percent' ? `${discount.value}%` : 'Fixed'})</span>
                <span>-{formatCurrency(regularDiscountAmount)}</span>
              </div>
            )}
            {couponDiscountAmount > 0 && appliedCoupon && (
              <div className="flex justify-between text-amber-600">
                <span>Coupon ({appliedCoupon.code})</span>
                <span>-{formatCurrency(couponDiscountAmount)}</span>
              </div>
            )}
            {loyaltyDiscountAmount > 0 && (
              <div className="flex justify-between text-primary">
                <span>Loyalty ({loyaltyRedemption.pointsToRedeem.toLocaleString()} pts)</span>
                <span>-{formatCurrency(loyaltyDiscountAmount)}</span>
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
                setLoyaltyRedemption({ pointsToRedeem: 0, discountAmount: 0 }) // Clear loyalty
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
                  // For online payments, create order first
                  const orderData = getOrderData()
                  console.log('Creating order for online payment:', orderData)
                  
                  const order = await createOrderMutation.mutateAsync(orderData)
                  console.log('Order created:', order)
                  
                  if (!order || !order.id) {
                    throw new Error('Order creation failed - no order ID returned')
                  }
                  if (!method?.gateway_id) {
                    throw new Error('No gateway ID found for selected payment method')
                  }
                  
                  // Route to appropriate payment dialog based on gateway type
                  if (method.code === 'hitpay') {
                    // HitPay: use Drop-In UI (popup overlay)
                    setShowCheckoutDialog(false)
                    setHitPayPaymentData({ orderId: order.id, gatewayId: method.gateway_id })
                    setTimeout(() => {
                      setShowHitPayDialog(true)
                    }, 100)
                    return
                  }
                  
                  // For Stripe / other inline gateways: open Stripe dialog
                  setShowCheckoutDialog(false)
                  setStripePaymentData({ orderId: order.id, gatewayId: method.gateway_id })
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

      {/* Customer Selection Dialog */}
      <Dialog 
        open={showCustomerDialog} 
        onOpenChange={(open) => {
          setShowCustomerDialog(open)
          if (!open) {
            // Clear search when dialog closes
            setCustomerSearch('')
            setDebouncedSearch('')
          }
        }}
      >
        <DialogContent className="sm:max-w-lg max-h-[80vh] flex flex-col">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:users-group-rounded-bold" className="h-5 w-5" />
              Select Customer
            </DialogTitle>
            <DialogDescription>
              Choose a customer for this order or add a new one
            </DialogDescription>
          </DialogHeader>
          
          {/* Search */}
          <div className="flex items-center gap-2 my-4">
            <div className="relative flex-1">
              <Icon icon="solar:magnifer-linear" className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search by name, phone, or email..."
                value={customerSearch}
                onChange={(e) => setCustomerSearch(e.target.value)}
                className="pl-9"
                autoFocus
              />
            </div>
            <Button 
              variant="outline" 
              size="icon"
              onClick={() => {
                setShowAddCustomerDialog(true)
                setShowCustomerDialog(false)
              }}
            >
              <Icon icon="solar:user-plus-bold" className="h-4 w-4" />
            </Button>
          </div>

          {/* Quick Selection - Walk-in */}
          <div className="space-y-2">
            <h4 className="text-sm font-medium text-muted-foreground">Quick Selection</h4>
            <Button
              variant={customerId === 'walk-in' ? "default" : "outline"}
              className="w-full justify-start gap-3 h-auto py-3"
              onClick={() => {
                setCustomerId('walk-in')
                setSelectedCustomer(null)
                setLoyaltyRedemption({ pointsToRedeem: 0, discountAmount: 0 })
                setShowCustomerDialog(false)
                setCustomerSearch('')
              }}
            >
              <div className="h-10 w-10 rounded-full bg-muted flex items-center justify-center">
                <Icon icon="solar:user-bold" className="h-5 w-5 text-muted-foreground" />
              </div>
              <div className="text-left">
                <p className="font-medium">Walk-in Customer</p>
                <p className="text-xs text-muted-foreground">No loyalty points</p>
              </div>
              {customerId === 'walk-in' && (
                <Icon icon="solar:check-circle-bold" className="h-5 w-5 ml-auto" />
              )}
            </Button>
          </div>

          {/* Customer List */}
          <div className="flex-1 overflow-y-auto mt-4 space-y-2 min-h-[200px]">
            <h4 className="text-sm font-medium text-muted-foreground sticky top-0 bg-background py-2">
              {debouncedSearch.length >= 2 ? 'Search Results' : 'Recent Customers'}
            </h4>
            
            {searchingCustomers ? (
              <div className="flex items-center justify-center py-8">
                <Icon icon="solar:refresh-bold" className="h-6 w-6 animate-spin mr-2 text-muted-foreground" />
                <span className="text-muted-foreground">Searching...</span>
              </div>
            ) : (
              <div className="space-y-2">
                {(debouncedSearch.length >= 2 ? searchedCustomers : customers)?.length ? (
                  (debouncedSearch.length >= 2 ? searchedCustomers : customers)?.slice(0, 20).map((customer) => (
                    <Button
                      key={customer.id}
                      variant={customerId === customer.id.toString() ? "default" : "outline"}
                      className="w-full justify-start gap-3 h-auto py-3 px-3"
                      onClick={() => {
                        setCustomerId(customer.id.toString())
                        setSelectedCustomer(customer)
                        setCustomerInfo({
                          id: customer.id,
                          name: customer.name,
                          email: customer.email,
                          phone: customer.phone,
                          loyalty_points: customer.loyalty_points || customer.current_points,
                          loyalty_tier_name: customer.loyalty_tier_name,
                        })
                        setShowCustomerDialog(false)
                        setCustomerSearch('')
                      }}
                    >
                      <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                        <Icon icon="solar:user-bold" className="h-5 w-5 text-primary" />
                      </div>
                      <div className="text-left flex-1 min-w-0">
                        <p className="font-medium truncate">{customer.name}</p>
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                          {customer.phone && <span>{customer.phone}</span>}
                          {customer.loyalty_tier_name ? (
                            <span className="flex items-center gap-1 text-amber-600">
                              <Icon icon="solar:medal-star-bold" className="h-3 w-3" />
                              {customer.loyalty_tier_name}
                            </span>
                          ) : (
                            <span>Not enrolled</span>
                          )}
                        </div>
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        {customer.current_points !== undefined && customer.current_points > 0 && (
                          <Badge variant="secondary" className="text-xs">
                            {customer.current_points.toLocaleString()} pts
                          </Badge>
                        )}
                        {customerId === customer.id.toString() && (
                          <Icon icon="solar:check-circle-bold" className="h-5 w-5" />
                        )}
                      </div>
                    </Button>
                  ))
                ) : debouncedSearch.length >= 2 ? (
                  <div className="text-center py-8">
                    <Icon icon="solar:user-cross-bold" className="h-12 w-12 mx-auto text-muted-foreground/50 mb-3" />
                    <p className="text-muted-foreground">No customers found</p>
                    <Button 
                      variant="link" 
                      onClick={() => {
                        setNewCustomer({ ...newCustomer, name: debouncedSearch })
                        setShowAddCustomerDialog(true)
                        setShowCustomerDialog(false)
                      }}
                    >
                      Create "{debouncedSearch}"
                    </Button>
                  </div>
                ) : null}
              </div>
            )}
          </div>
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
        <DialogContent className="w-[calc(100vw-2rem)] max-w-[760px] max-h-[92vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:document-text-bold" className="h-5 w-5" />
              Order Receipt
            </DialogTitle>
          </DialogHeader>
          
          {lastOrder && (
            <div className="mx-auto w-full max-w-[360px] border rounded-lg overflow-hidden bg-white">
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

          <DialogFooter className="flex-col gap-2">
            <Button 
              variant="default"
              className="w-full"
              onClick={() => {
                // Close receipt and start new order
                setShowReceiptDialog(false)
                resetTerminalForNextOrder()
                toast({ title: 'New Order', description: 'Ready for next customer' })
              }}
            >
              <Icon icon="solar:plus-circle-bold" className="h-4 w-4 mr-2" />
              New Order
            </Button>
            <div className="flex gap-2 w-full">
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
              onClick={() => void downloadReceiptPdf(lastOrder?.id, lastOrder?.orderNumber)}
            >
              <Icon icon="solar:download-bold" className="h-4 w-4 mr-2" />
              PDF
            </Button>
            <Button 
              className="flex-1"
              onClick={printReceiptPreview}
            >
              <Icon icon="solar:printer-bold" className="h-4 w-4 mr-2" />
              Print
            </Button>
            </div>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* HitPay Payment Dialog */}
      <HitPayPaymentDialog
        open={showHitPayDialog}
        onOpenChange={setShowHitPayDialog}
        amount={total}
        posOrderId={hitPayPaymentData.orderId}
        gatewayId={hitPayPaymentData.gatewayId || 0}
        onSuccess={async (transactionId) => {
          toast({
            title: 'Payment successful!',
            description: `HitPay payment completed. Transaction: ${transactionId}`,
          })
          setShowHitPayDialog(false)
          setLastOrder(buildReceiptOrder({
            id: hitPayPaymentData.orderId,
            order_number: hitPayPaymentData.orderId?.toString() || 'ONLINE',
            receipt_number: `HITPAY-${Date.now()}`,
          }, 'Card (HitPay)', { transactionId }))
          setShowReceiptDialog(true)
          resetTerminalForNextOrder()
        }}
        onError={(error) => {
          toast({
            variant: 'destructive',
            title: 'HitPay payment failed',
            description: error,
          })
        }}
      />

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
          setLastOrder(buildReceiptOrder({
            id: stripePaymentData.orderId,
            order_number: stripePaymentData.orderId?.toString() || 'ONLINE',
            receipt_number: `ONLINE-${Date.now()}`,
          }, 'Card (Stripe)', { transactionId }))
          
          // Show receipt dialog
          setShowReceiptDialog(true)
          
          resetTerminalForNextOrder()
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
