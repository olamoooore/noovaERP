import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import api from '@/lib/api'
import iotApi from '@/lib/iotApi'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Separator } from '@/components/ui/separator'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import { Pagination } from '@/components/ui/Pagination'
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useToast } from '@/hooks/use-toast'
import { cn, formatCurrency, getCurrencySymbol, resolveImageUrl } from '@/lib/utils'
import { useSettingsStore } from '@/store/settingsStore'
import { usePosSessionStore, CartItem } from '@/store/posSessionStore'
import { POSReceipt } from '@/components/pos/POSReceipt'
import { PaymentMethodSelector } from '@/components/pos/PaymentMethodSelector'
import { StripePaymentDialog } from '@/components/StripePaymentDialog'
import { HitPayPaymentDialog } from '@/components/pos/HitPayPaymentDialog'
import { OfflineBanner } from '@/components/pos/OfflineBanner'
import loyaltyApi from '@/lib/loyaltyApi'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'

const METHOD_ICONS: Record<string, string> = {
  cash: 'solar:wallet-money-bold-duotone',
  card: 'solar:card-bold-duotone',
  bank_transfer: 'solar:bank-card-bold-duotone',
  mobile: 'solar:smartphone-bold-duotone',
  voucher: 'solar:ticket-bold-duotone',
  credit: 'solar:file-text-bold-duotone',
  other: 'solar:wallet-bold-duotone',
}

const METHOD_LABELS: Record<string, string> = {
  cash: 'Cash',
  card: 'Card',
  bank_transfer: 'Bank Transfer',
  mobile: 'Mobile Payment',
  voucher: 'Voucher',
  credit: 'Credit',
  other: 'Other',
}

const CASH_DENOMINATIONS = [
  { label: '$100', value: 100, type: 'note' as const },
  { label: '$50', value: 50, type: 'note' as const },
  { label: '$10', value: 10, type: 'note' as const },
  { label: '$5', value: 5, type: 'note' as const },
  { label: '$2', value: 2, type: 'note' as const },
  { label: '$1', value: 1, type: 'coin' as const },
  { label: '50¢', value: 0.5, type: 'coin' as const },
  { label: '20¢', value: 0.2, type: 'coin' as const },
  { label: '10¢', value: 0.1, type: 'coin' as const },
  { label: '5¢', value: 0.05, type: 'coin' as const },
]

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

interface CompanySettings {
  company_name?: string | null
  address?: string | null
  phone?: string | null
  email?: string | null
  website?: string | null
  addresses?: string[] | null
  phone_numbers?: string[] | null
  social_links?: {
    whatsapp?: string | null
    instagram?: string | null
    facebook?: string | null
    [key: string]: string | null | undefined
  } | null
  gst_registration_number?: string | null
}

interface PosTerminalSummary {
  id: number | string
  name: string
  code?: string | null
  receipt_header?: string | null
  receipt_footer?: string | null
  settings?: {
    receipt_print_mode?: 'web' | 'direct'
    printer_agent_id?: string | null
    receipt_printer_device_id?: string | null
    label_printer_device_id?: string | null
    [key: string]: unknown
  } | null
  warehouse?: {
    id: number
    name: string
  } | null
}

interface PosRuntimeSettings {
  auto_print_receipt?: boolean
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
  discountType: 'fixed' | 'percent'
  discountValue: number
  coupon?: {
    code: string
    discountAmount: number
    discountType: 'fixed' | 'percent'
    discountValue: number
  }
  loyaltyDiscountAmount: number
  loyaltyPointsEarned?: number
  loyaltyPointsUsed?: number
  total: number
  paymentMethod: string
  amountPaid: number
  change: number
  terminal?: string
  warehouse?: string
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
  const [currentOrderId, setCurrentOrderId] = useState<string | null>(null) // Track draft order ID
  const [currentOrderNumber, setCurrentOrderNumber] = useState<string | null>(null)
  const [productPage, setProductPage] = useState(1) // Product pagination
  const [productPageSize, setProductPageSize] = useState(50)
  const scannerBufferRef = useRef('')
  const scannerLastKeyTsRef = useRef(0)
  const scannerFlushTimerRef = useRef<number | null>(null)

  // Top bar: live clock + online status
  const [currentTime, setCurrentTime] = useState(() =>
    new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  )
  const [isOnline, setIsOnline] = useState(true)

  useEffect(() => {
    const tick = setInterval(() => {
      setCurrentTime(new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }))
    }, 1000)

    // Use an active server ping instead of navigator.onLine.
    // navigator.onLine reflects the browser's NIC state, not backend reachability —
    // it fires false positives in dev (e.g. DevTools offline mode, no internet but
    // localhost works fine).
    const checkConnectivity = async () => {
      try {
        const basePath = import.meta.env.VITE_BASE_PATH || ''
        const res = await fetch(`${basePath}/api/ping`, { method: 'HEAD', cache: 'no-store' })
        setIsOnline(res.ok)
      } catch {
        setIsOnline(false)
      }
    }

    // Initial probe
    checkConnectivity()

    // Re-probe every 30 s while the component is mounted
    const connectivityTick = setInterval(checkConnectivity, 30_000)

    // When the browser fires 'online', re-verify with the server before trusting it
    const handleOnline = () => checkConnectivity()
    // When the browser fires 'offline', trust it immediately — no point pinging
    const handleOffline = () => setIsOnline(false)

    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)
    return () => {
      clearInterval(tick)
      clearInterval(connectivityTick)
      window.removeEventListener('online', handleOnline)
      window.removeEventListener('offline', handleOffline)
    }
  }, [])

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
  const [closingNotes, setClosingNotes] = useState<string>('')
  const [showCashCountPanel, setShowCashCountPanel] = useState(false)
  const [cashDenominationCounts, setCashDenominationCounts] = useState<Record<string, number>>({})
  const [paymentBreakdown, setPaymentBreakdown] = useState<Array<{
    method: string
    methodLabel: string
    expectedAmount: number
    countedAmount: string
    icon: string
  }>>([])
  
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
    const handleOrderLoaded = (event: Event) => {
      const detail = (event as CustomEvent<{ externalId?: string | null; orderNumber?: string | null }>).detail
      if (!detail) {
        return
      }

      setCurrentOrderId(detail.externalId ?? null)
      setCurrentOrderNumber(detail.orderNumber ?? null)
    }

    window.addEventListener('pos:order-loaded', handleOrderLoaded as EventListener)
    return () => window.removeEventListener('pos:order-loaded', handleOrderLoaded as EventListener)
  }, [])

  // Sync active session to localStorage so Customer Display can find it
  useEffect(() => {
    if (activeSessionId) {
      localStorage.setItem('pos_active_session_id', String(activeSessionId))
    } else {
      localStorage.removeItem('pos_active_session_id')
    }
  }, [activeSessionId])

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
    queryKey: ['pos-products', search, selectedCategory, warehouseId, productPage, productPageSize],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: productPageSize, page: productPage }
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

  const { data: companySettings } = useQuery<CompanySettings | null>({
    queryKey: ['pos-company-settings'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings')
      return response.data?.data ?? null
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  })

  const { data: terminals = [] } = useQuery<PosTerminalSummary[]>({
    queryKey: ['pos-terminals'],
    queryFn: async () => {
      const response = await api.get('/pos/terminals')
      return response.data?.terminals || []
    },
    staleTime: 30_000,
    refetchOnWindowFocus: false,
  })

  const activeTerminal = useMemo(
    () => terminals.find((terminal) => String(terminal.id) === String(activeTerminalId)) ?? null,
    [activeTerminalId, terminals]
  )

  const { data: posRuntimeSettings } = useQuery<PosRuntimeSettings>({
    queryKey: ['pos-runtime-settings-for-printing'],
    queryFn: async () => {
      const response = await api.get('/pos/settings')
      return response.data?.settings || {}
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  })

  const { data: pendingOrdersData } = useQuery({
    queryKey: ['pos-draft-orders-summary'],
    queryFn: async () => {
      const response = await api.get('/pos/orders', { params: { status: 'draft', per_page: 1 } })
      return response.data
    },
    staleTime: 15_000,
    refetchOnWindowFocus: false,
  })

  const receiptBranding = useMemo(() => {
    const companyName = companySettings?.company_name?.trim()
    const terminalHeader = activeTerminal?.receipt_header?.trim()
    const terminalFooter = activeTerminal?.receipt_footer?.trim()
    const businessAddress = companySettings?.address?.trim()
    const businessPhone = companySettings?.phone?.trim()
    const businessEmail = companySettings?.email?.trim()

    const addresses = companySettings?.addresses?.filter(Boolean) ?? []
    const phones = companySettings?.phone_numbers?.filter(Boolean) ?? []
    const socialLinks = companySettings?.social_links ?? {}

    return {
      businessName: companyName || terminalHeader || activeTerminal?.warehouse?.name || activeTerminal?.name || 'Point of Sale',
      businessAddress: businessAddress || undefined,
      businessAddresses: addresses.length ? addresses : undefined,
      businessPhone: (phones[0] || businessPhone) || undefined,
      businessEmail: businessEmail || undefined,
      whatsapp: socialLinks.whatsapp || phones[0] || businessPhone || undefined,
      instagram: socialLinks.instagram || undefined,
      facebook: socialLinks.facebook || undefined,
      businessWebsite: companySettings?.website?.trim() || undefined,
      gstRegNo: companySettings?.gst_registration_number?.trim() || undefined,
      footerMessage: terminalFooter || undefined,
    }
  }, [activeTerminal, companySettings])

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
    mutationFn: async () => {
      const paymentCounts = paymentBreakdown.map((item) => ({
        method: item.method,
        method_name: item.methodLabel,
        counted_amount: parseFloat(item.countedAmount) || 0,
      }))

      const response = await api.post(`/pos/sessions/${activeSessionId}/close`, {
        payment_counts: paymentCounts,
        notes: closingNotes,
      })
      return response.data
    },
    onSuccess: async () => {
      console.log('Session closed successfully')
      setShowCloseSessionDialog(false)
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

  // Fetch expected payment breakdown when close dialog opens
  useEffect(() => {
    if (!showCloseSessionDialog || !activeSessionId) return

    const fetchBreakdown = async () => {
      try {
        const response = await api.get(`/pos/sessions/${activeSessionId}/close-summary`)
        const breakdown = response.data?.expected_breakdown || []

        const items = breakdown.map((item: { method: string; expected_amount: number }) => ({
          method: item.method,
          methodLabel: METHOD_LABELS[item.method] || item.method,
          expectedAmount: item.expected_amount,
          countedAmount: item.expected_amount.toFixed(2),
          icon: METHOD_ICONS[item.method] || 'solar:wallet-bold-duotone',
        }))

        if (!items.some((i: { method: string }) => i.method === 'cash')) {
          items.unshift({
            method: 'cash',
            methodLabel: 'Cash',
            expectedAmount: 0,
            countedAmount: '0.00',
            icon: 'solar:wallet-money-bold-duotone',
          })
        }

        setPaymentBreakdown(items)
      } catch {
        setPaymentBreakdown([
          {
            method: 'cash',
            methodLabel: 'Cash',
            expectedAmount: 0,
            countedAmount: '0.00',
            icon: 'solar:wallet-money-bold-duotone',
          },
        ])
      }
    }

    void fetchBreakdown()
    setClosingNotes('')
    setShowCashCountPanel(false)
    setCashDenominationCounts({})
  }, [showCloseSessionDialog, activeSessionId])

  const updateCountedAmount = useCallback((method: string, value: string) => {
    setPaymentBreakdown((prev) =>
      prev.map((item) =>
        item.method === method ? { ...item, countedAmount: value } : item
      )
    )
  }, [])

  const cashNotesTotal = useMemo(() => {
    return CASH_DENOMINATIONS
      .filter((item) => item.type === 'note')
      .reduce((sum, item) => sum + (cashDenominationCounts[item.label] || 0) * item.value, 0)
  }, [cashDenominationCounts])

  const cashCoinsTotal = useMemo(() => {
    return CASH_DENOMINATIONS
      .filter((item) => item.type === 'coin')
      .reduce((sum, item) => sum + (cashDenominationCounts[item.label] || 0) * item.value, 0)
  }, [cashDenominationCounts])

  const cashPiecesTotal = useMemo(() => {
    return CASH_DENOMINATIONS.reduce((sum, item) => sum + (cashDenominationCounts[item.label] || 0), 0)
  }, [cashDenominationCounts])

  const updateCashDenominationCount = useCallback((label: string, qty: number) => {
    const nextQty = Math.max(0, Math.floor(Number.isFinite(qty) ? qty : 0))
    setCashDenominationCounts((prev) => {
      const next = {
        ...prev,
        [label]: nextQty,
      }

      const total = CASH_DENOMINATIONS.reduce(
        (sum, item) => sum + (next[item.label] || 0) * item.value,
        0
      )
      updateCountedAmount('cash', total.toFixed(2))

      return next
    })
  }, [updateCountedAmount])

  const adjustCashDenominationCount = useCallback((label: string, delta: number) => {
    const currentQty = cashDenominationCounts[label] || 0
    updateCashDenominationCount(label, currentQty + delta)
  }, [cashDenominationCounts, updateCashDenominationCount])

  const totalExpected = useMemo(
    () => paymentBreakdown.reduce((sum, item) => sum + item.expectedAmount, 0),
    [paymentBreakdown]
  )

  const totalCounted = useMemo(
    () => paymentBreakdown.reduce((sum, item) => sum + (parseFloat(item.countedAmount) || 0), 0),
    [paymentBreakdown]
  )

  const totalDifference = totalCounted - totalExpected

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
  const products = productsData || []
  const activeOrderItemCount = cart.reduce((sum, item) => sum + item.quantity, 0)
  const currentOrderReference = currentOrderNumber
    || (currentOrderId ? `Draft ${currentOrderId.split('-')[0].toUpperCase()}` : 'New Order')
  const pendingDraftOrderCount = Number(pendingOrdersData?.total ?? pendingOrdersData?.meta?.total ?? 0)
  const selectedCategoryName = selectedCategory === null
    ? 'All Products'
    : categories?.find((category) => category.id === selectedCategory)?.name || 'Filtered Category'
  const visibleInStockCount = products.filter((product: Product) => getStock(product) > 0).length
  const lowStockVisibleCount = products.filter((product: Product) => {
    const stock = getStock(product)
    return stock > 0 && stock <= 5
  }).length

  const openPendingOrders = () => {
    window.dispatchEvent(new CustomEvent('pos:open-orders-drawer', { detail: { status: 'draft' } }))
  }

  // Update ref for loyalty queries
  useEffect(() => {
    itemsTotalRef.current = itemsTotal
  }, [itemsTotal])

  // Update loyalty query key when customer or cart changes
  useEffect(() => {
    setLoyaltyQueryKey(['loyalty-pos-info', selectedCustomer?.id ?? null, itemsTotal])
  }, [selectedCustomer?.id, itemsTotal])

  const buildDisplayCartItems = useCallback((sourceCart: CartItem[]) => (
    sourceCart.map((item) => ({
      product_id: item.id,
      name: item.name,
      sku: item.sku ?? null,
      quantity: item.quantity,
      unit_price: item.price,
      line_total: item.line_total,
      image: item.image ?? null,
    }))
  ), [])

  // Fire display event to customer-facing screen (best-effort, non-blocking)
  const fireDisplayEvent = useCallback(async (event: string, extra?: Record<string, any>) => {
    if (!activeSessionId) return
    try {
      await api.post(`/pos/sessions/${activeSessionId}/display-event`, {
        event,
        cart_items: buildDisplayCartItems(cart),
        ...extra,
      })
    } catch {
      // Display events are best-effort and must never block cashier flow.
    }
  }, [activeSessionId, buildDisplayCartItems, cart])

  const addToCart = useCallback((product: Product) => {
    const currentQtyInCart = cart.find((item) => item.id === product.id)?.quantity || 0
    if (currentQtyInCart + 1 > getStock(product)) {
      toast({
        title: 'Out of Stock',
        description: `Only ${getStock(product)} items available`,
        variant: 'destructive',
      })
      return
    }

    const existing = cart.find((item) => item.id === product.id)
    if (existing) {
      const newQty = existing.quantity + 1
      const updatedItem = {
        ...existing,
        quantity: newQty,
        ...computeLine(product, newQty),
      }
      const nextCart = cart.map((item) => item.id === product.id ? updatedItem : item)
      setCart(nextCart)
      void fireDisplayEvent('CartItemQtyChanged', { cart_items: buildDisplayCartItems(nextCart) })
      return
    }

    const newItem: CartItem = {
      id: product.id,
      name: product.name,
      price: product.price,
      sku: product.sku,
      image: product.image,
      quantity: 1,
      tax_rate: product.tax_rate,
      effective_tax_rate: product.effective_tax_rate,
      effective_tax_is_inclusive: product.effective_tax_is_inclusive,
      ...computeLine(product, 1),
    }
    const nextCart = [...cart, newItem]
    setCart(nextCart)
    void fireDisplayEvent('CartItemAdded', { cart_items: buildDisplayCartItems(nextCart) })
  }, [buildDisplayCartItems, cart, computeLine, fireDisplayEvent, getStock, setCart, toast])

  const updateQuantity = (productId: number, delta: number) => {
    const existing = cart.find((item) => item.id === productId)
    if (!existing) return

    const newQty = Math.max(1, existing.quantity + delta)
    if (delta > 0) {
      const product = productsData?.find((entry: Product) => entry.id === productId)
      if (product && newQty > getStock(product)) {
        toast({
          title: 'Out of Stock',
          description: `Only ${getStock(product)} items available`,
          variant: 'destructive',
        })
        return
      }
    }

    const updatedItem = {
      ...existing,
      quantity: newQty,
      ...computeLine(existing, newQty),
    }
    const nextCart = cart.map((item) => item.id === productId ? updatedItem : item)
    setCart(nextCart)
    void fireDisplayEvent('CartItemQtyChanged', { cart_items: buildDisplayCartItems(nextCart) })
  }

  const removeFromCart = (productId: number) => {
    const nextCart = cart.filter((item) => item.id !== productId)
    setCart(nextCart)
    void fireDisplayEvent('CartItemRemoved', { cart_items: buildDisplayCartItems(nextCart) })
  }

  const handleBarcodeScan = useCallback(async (barcode: string) => {
    const code = barcode.trim()
    if (!code) return

    try {
      const response = await api.get('/pos/products/lookup', {
        params: {
          code,
          barcode: code,
        },
      })
      if (response.data) {
        // Check if multiple matches
        if (response.data.multiple_matches) {
          // TODO: Show selection modal for multiple matches
          toast({
            title: 'Multiple Products Found',
            description: `${response.data.count} products match barcode ${code}. Please select manually.`,
            variant: 'default',
          })
          return
        }

        // Single match - add to cart
        const item: Product = response.data.type === 'variant'
          ? {
              ...response.data.variant,
              name: `${response.data.product.name} - ${response.data.variant.name}`,
              image: response.data.product.image,
              tax_rate: response.data.variant.tax_rate ?? response.data.product.tax_rate,
              effective_tax_rate: response.data.variant.effective_tax_rate ?? response.data.product.effective_tax_rate,
              effective_tax_is_inclusive: response.data.variant.effective_tax_is_inclusive ?? response.data.product.effective_tax_is_inclusive,
            }
          : response.data.product

        addToCart(item)
        toast({
          title: 'Product Added',
          description: item.name,
        })
      }
    } catch {
      toast({
        title: 'Product Not Found',
        description: `No product found with barcode: ${code}`,
        variant: 'destructive',
      })
    }
  }, [addToCart, toast])

  useEffect(() => {
    const isEditableTarget = (target: EventTarget | null) => {
      const el = target as HTMLElement | null
      if (!el) return false
      const tagName = el.tagName?.toLowerCase()
      return tagName === 'input' || tagName === 'textarea' || el.isContentEditable
    }

    const flushBuffer = () => {
      const code = scannerBufferRef.current.trim()
      scannerBufferRef.current = ''
      if (code.length >= 6) {
        void handleBarcodeScan(code)
      }
    }

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.defaultPrevented || event.isComposing) return
      if (event.ctrlKey || event.metaKey || event.altKey) return

      const now = Date.now()
      const delta = now - scannerLastKeyTsRef.current
      scannerLastKeyTsRef.current = now

      const editableTarget = isEditableTarget(event.target)

      if (event.key === 'Enter') {
        if (scannerBufferRef.current.length >= 6) {
          event.preventDefault()
          flushBuffer()
        }
        return
      }

      if (event.key.length !== 1) return

      // Scanner keyboards emit very fast bursts; avoid interfering with normal typing.
      if (editableTarget && delta > 70) {
        scannerBufferRef.current = ''
        if (scannerFlushTimerRef.current !== null) {
          window.clearTimeout(scannerFlushTimerRef.current)
          scannerFlushTimerRef.current = null
        }
        return
      }

      if (delta > 120) {
        scannerBufferRef.current = ''
      }

      scannerBufferRef.current += event.key

      if (scannerFlushTimerRef.current !== null) {
        window.clearTimeout(scannerFlushTimerRef.current)
      }

      // Some scanners do not emit Enter; flush after brief silence.
      scannerFlushTimerRef.current = window.setTimeout(() => {
        flushBuffer()
      }, 90)
    }

    window.addEventListener('keydown', handleKeyDown, true)
    return () => {
      window.removeEventListener('keydown', handleKeyDown, true)
      if (scannerFlushTimerRef.current !== null) {
        window.clearTimeout(scannerFlushTimerRef.current)
        scannerFlushTimerRef.current = null
      }
    }
  }, [handleBarcodeScan])

  const extractErrorMessage = (error: any): string => {
    if (error.response?.data) {
      const data = error.response.data

      if (data.error === 'Missing product cost basis' && typeof data.message === 'string') {
        const affectedItemsMatch = data.message.match(/Affected items:\s*(.+)$/i)
        const affectedItems = affectedItemsMatch?.[1]?.trim()

        return affectedItems
          ? `Cannot complete this sale because these stock-tracked items have no cost set: ${affectedItems}. Update the product cost and retry.`
          : 'Cannot complete this sale because one or more stock-tracked items have no cost set. Update the product cost and retry.'
      }

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
    items: cart.map((item) => ({
      product_id: item.id,
      quantity: item.quantity,
      unit_price: item.price,
    })),
  })

  const applyLoyaltyRedemption = (points: number) => {
    if (!loyaltyInfo?.enrolled) return

    const discountAmount = round2(points * loyaltyInfo.currency_per_point)
    setLoyaltyRedemption({ pointsToRedeem: points, discountAmount })

    toast({
      title: 'Loyalty Points Applied',
      description: `${points} points = ${formatCurrency(discountAmount)} discount`,
    })
  }

  const clearLoyaltyRedemption = () => {
    setLoyaltyRedemption({ pointsToRedeem: 0, discountAmount: 0 })
    toast({
      title: 'Loyalty Points Removed',
      description: 'Points redemption has been cancelled',
    })
  }

  const applyCoupon = async () => {
    if (!couponCode.trim()) return

    try {
      const response = await api.post('/pos/coupons/validate', {
        code: couponCode.trim().toUpperCase(),
        amount: itemsTotal,
        customer_id: selectedCustomer?.id ?? null,
        session_id: activeSessionId,
        terminal_id: activeTerminalId ? parseInt(activeTerminalId, 10) : null,
      })

      const rawCoupon = response.data?.data ?? response.data?.coupon ?? response.data
      const discountType = rawCoupon?.discount_type ?? rawCoupon?.discountType
      const discountValue = Number(rawCoupon?.discount_value ?? rawCoupon?.discountValue ?? 0)

      if (!rawCoupon || (discountType !== 'fixed' && discountType !== 'percent') || !Number.isFinite(discountValue) || discountValue <= 0) {
        throw new Error('Coupon validation returned incomplete data')
      }

      const coupon = {
        code: String(rawCoupon.code ?? rawCoupon.coupon_code ?? couponCode.trim()).toUpperCase(),
        discount_type: discountType,
        discount_value: discountValue,
        description: rawCoupon.description ?? rawCoupon.name ?? undefined,
      }

      setAppliedCoupon(coupon)
      toast({
        title: 'Coupon Applied',
        description: coupon.description ? `${coupon.code}: ${coupon.description}` : `${coupon.code} applied successfully`,
      })
      setCouponCode('')
    } catch (error: any) {
      const status = error?.response?.status
      toast({
        title: status === 404 ? 'Coupon Validation Unavailable' : 'Coupon Not Applied',
        description: status === 404
          ? 'Live coupon validation is not configured for this POS terminal yet.'
          : error.response?.data?.message || error.message || 'Failed to apply coupon',
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
    setCurrentOrderNumber(null)
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
    items: cart.map((item) => ({
      name: item.name,
      quantity: item.quantity,
      price: item.price,
      line_total: item.line_total,
    })),
    subtotal,
    taxAmount,
    discountAmount: regularDiscountAmount,
    discountType: discount.type as 'fixed' | 'percent',
    discountValue: discount.value,
    coupon: appliedCoupon ? {
      code: appliedCoupon.code,
      discountAmount: couponDiscountAmount,
      discountType: appliedCoupon.discount_type,
      discountValue: appliedCoupon.discount_value,
    } : undefined,
    loyaltyDiscountAmount,
    loyaltyPointsEarned: options?.loyaltyPointsEarned,
    loyaltyPointsUsed: loyaltyRedemption.pointsToRedeem,
    total,
    paymentMethod: paymentMethodLabel,
    amountPaid: total,
    change: 0,
    terminal: activeTerminal?.name || activeTerminal?.code || (activeTerminalId ? String(activeTerminalId) : undefined),
    warehouse: activeTerminal?.warehouse?.name || undefined,
    transactionId: options?.transactionId,
  }), [
    activeTerminal,
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

    void fireDisplayEvent('PaymentApproved')

    toast({
      title: 'Payment Successful',
      description,
    })

    resetTerminalForNextOrder()
    void queryClient.invalidateQueries({ queryKey: ['pos-products'] })
    void queryClient.invalidateQueries({ queryKey: ['customers-pos'] })
    void queryClient.invalidateQueries({ queryKey: ['customers-search'] })
  }, [fireDisplayEvent, queryClient, resetTerminalForNextOrder, toast])

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

  const printReceiptDirect = useCallback(async (receipt: PosReceiptOrder): Promise<boolean> => {
    const terminalSettings = activeTerminal?.settings || {}
    const printMode = terminalSettings.receipt_print_mode || 'web'
    const agentId = terminalSettings.printer_agent_id || null
    const deviceId = terminalSettings.receipt_printer_device_id || null

    if (printMode !== 'direct' || !agentId || !deviceId) {
      return false
    }

    try {
      const response = await iotApi.printReceipt({
        agent_id: agentId,
        device_id: deviceId,
        receipt_data: {
          store_name: receiptBranding.businessName,
          items: receipt.items.map((item) => ({
            name: item.name,
            quantity: item.quantity,
            price: item.price,
          })),
          subtotal: receipt.subtotal,
          tax: receipt.taxAmount,
          total: receipt.total,
          payment_method: receipt.paymentMethod,
          payment_amount: receipt.amountPaid,
          change: receipt.change,
          receipt_number: receipt.receiptNumber,
          date: receipt.date.toISOString(),
        },
      })

      if (!response?.success) {
        throw new Error(response?.message || 'Direct receipt print failed')
      }

      toast({
        title: 'Receipt sent to printer',
        description: `Printed via IoT device ${deviceId}`,
      })
      return true
    } catch (error: any) {
      toast({
        title: 'Direct print failed',
        description: error?.message || 'Falling back to web print.',
        variant: 'destructive',
      })
      return false
    }
  }, [activeTerminal?.settings, receiptBranding.businessName, toast])

  const handleReceiptPrint = useCallback(async () => {
    if (!lastOrder) return

    const printedDirectly = await printReceiptDirect(lastOrder)
    if (!printedDirectly) {
      printReceiptPreview()
    }
  }, [lastOrder, printReceiptDirect, printReceiptPreview])

  useEffect(() => {
    if (!lastOrder) return
    if (posRuntimeSettings?.auto_print_receipt !== true) return

    void printReceiptDirect(lastOrder)
  }, [lastOrder, posRuntimeSettings?.auto_print_receipt, printReceiptDirect])

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
          
            const order = await createOrderMutation.mutateAsync(getOrderData(orderId))
            setCurrentOrderNumber(order?.order_number ?? null)
            setCurrentOrderId(order?.external_id ?? orderId)
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
      setCurrentOrderNumber(order?.order_number ?? null)
      setCurrentOrderId(order?.external_id ?? orderId)

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
      setCurrentOrderNumber(order?.order_number ?? null)
      setCurrentOrderId(order?.external_id ?? orderId)
      
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

  return (
    <>
      <OfflineBanner isOnline={isOnline} />
      <div className="flex h-full min-h-0 flex-col overflow-hidden bg-[radial-gradient(circle_at_top_left,rgba(30,64,175,0.12),transparent_28%),radial-gradient(circle_at_bottom_right,rgba(37,99,235,0.10),transparent_24%),linear-gradient(180deg,#f8fafc_0%,#eef4f8_100%)]">
      <div className="flex min-h-0 flex-1 flex-row-reverse gap-3 overflow-hidden p-3 xl:gap-4 xl:p-4">
        <section className="flex min-h-0 min-w-0 basis-0 flex-1 flex-col overflow-hidden rounded-[1.75rem] border border-slate-200/80 bg-white/95 shadow-[0_18px_45px_rgba(15,23,42,0.08)]">
          <div className="border-b border-slate-200/80 bg-[linear-gradient(180deg,#ffffff_0%,#f7fafc_100%)] px-4 py-4 xl:px-5">
            <div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
              <div className="min-w-0">
                <div className="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">
                  Product Discovery
                </div>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                  <h2 className="text-xl font-semibold tracking-[-0.03em] text-slate-950">{selectedCategoryName}</h2>
                  <Badge variant="secondary" className="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-medium text-slate-700">
                    {productTotal.toLocaleString()} catalog items
                  </Badge>
                  <Badge variant="outline" className="rounded-full border-blue-200 bg-blue-50 px-3 py-1 text-[11px] font-medium text-blue-700">
                    {visibleInStockCount.toLocaleString()} in stock
                  </Badge>
                  {lowStockVisibleCount > 0 && (
                    <Badge variant="outline" className="rounded-full border-amber-200 bg-amber-50 px-3 py-1 text-[11px] font-medium text-amber-700">
                      {lowStockVisibleCount} low stock
                    </Badge>
                  )}
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                {activeTerminal?.warehouse?.name && (
                  <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1.5 font-medium">
                    <Icon icon="solar:shop-2-bold-duotone" className="h-3.5 w-3.5 text-slate-500" />
                    {activeTerminal.warehouse.name}
                  </span>
                )}
                {activeTerminal?.name && (
                  <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1.5 font-medium">
                    <Icon icon="solar:monitor-bold-duotone" className="h-3.5 w-3.5 text-slate-500" />
                    {activeTerminal.name}
                  </span>
                )}
              </div>
            </div>

            <div className="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center">
              <div className="relative flex-1">
                <Icon icon="solar:magnifer-bold" className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <Input
                  placeholder="Search products or scan barcode..."
                  className="h-11 rounded-xl border-slate-200 bg-white pl-10 shadow-sm"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' && /^\d{6,}$/.test(search.trim())) {
                      handleBarcodeScan(search.trim())
                      setSearch('')
                    }
                  }}
                />
              </div>

              {selectedCategory !== null && (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-11 rounded-xl border-slate-200 bg-white px-4 shadow-sm"
                  onClick={() => setSelectedCategory(null)}
                >
                  <Icon icon="solar:close-circle-bold" className="mr-2 h-4 w-4" />
                  Clear Filter
                </Button>
              )}
            </div>
          </div>

          <div className="border-b border-slate-200/80 bg-slate-50/70 px-4 py-3 xl:px-5">
            <div className="flex gap-2 overflow-x-auto pb-1">
              <Button
                variant={selectedCategory === null ? 'default' : 'outline'}
                size="sm"
                className={cn(
                  'h-8 shrink-0 rounded-full px-4 text-xs font-medium shadow-sm',
                  selectedCategory === null
                    ? 'bg-[#1e40af] text-white hover:bg-[#1e3a8a]'
                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100'
                )}
                onClick={() => setSelectedCategory(null)}
              >
                All
              </Button>
              {categories?.slice(0, 10).map((cat) => (
                <Button
                  key={cat.id}
                  variant={selectedCategory === cat.id ? 'default' : 'outline'}
                  size="sm"
                  className={cn(
                    'h-8 shrink-0 rounded-full px-4 text-xs font-medium shadow-sm',
                    selectedCategory === cat.id
                      ? 'bg-slate-950 text-white hover:bg-slate-900'
                      : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100'
                  )}
                  onClick={() => setSelectedCategory(cat.id)}
                >
                  {cat.name}
                </Button>
              ))}
            </div>
          </div>

          <ScrollArea className="flex-1">
            <div className="p-4 xl:p-5">
              {productsLoading ? (
                <div className="flex h-full min-h-[22rem] items-center justify-center">
                  <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
                </div>
              ) : products.length === 0 ? (
                <div className="flex h-full min-h-[22rem] flex-col items-center justify-center rounded-[1.5rem] border border-dashed border-slate-200 bg-slate-50 text-muted-foreground">
                  <Icon icon="solar:box-bold" className="mb-4 h-16 w-16" />
                  <p className="text-sm font-medium">No products found</p>
                </div>
              ) : (
                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                  {products.map((product: Product) => {
                    const stock = getStock(product)
                    const isOutOfStock = stock <= 0
                    const isLowStock = stock > 0 && stock <= 5
                    const stockLabel = isOutOfStock ? 'Out of stock' : isLowStock ? `${stock} left` : `${stock} in stock`
                    const stockToneClassName = isOutOfStock
                      ? 'border-rose-200 bg-rose-50 text-rose-600'
                      : isLowStock
                        ? 'border-amber-200 bg-amber-50 text-amber-700'
                        : 'border-blue-200 bg-blue-50 text-blue-700'

                    return (
                      <Card
                        key={product.id}
                        className={cn(
                          'group cursor-pointer overflow-hidden rounded-[1.35rem] border border-slate-200/80 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-[0_16px_35px_rgba(15,23,42,0.10)]',
                          isOutOfStock && 'cursor-not-allowed opacity-60 grayscale'
                        )}
                        onClick={() => !isOutOfStock && addToCart(product)}
                      >
                        <CardContent className="p-3.5">
                          <div className="relative mb-3 aspect-[0.96] overflow-hidden rounded-[1rem] bg-[linear-gradient(180deg,#f8fafc_0%,#eef4f7_100%)]">
                            {product.category?.name && (
                              <span className="absolute left-2 top-2 inline-flex max-w-[70%] truncate rounded-full bg-white/90 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500 shadow-sm">
                                {product.category.name}
                              </span>
                            )}
                            <span className={cn('absolute right-2 top-2 inline-flex rounded-full border px-2.5 py-1 text-[10px] font-semibold shadow-sm', stockToneClassName)}>
                              {stockLabel}
                            </span>
                            <div className="flex h-full items-center justify-center overflow-hidden p-3">
                              {product.image ? (
                                <img src={product.image} alt={product.name} className="h-full w-full object-cover transition duration-200 group-hover:scale-[1.03]" />
                              ) : (
                                <Icon icon="solar:box-bold" className="h-12 w-12 text-muted-foreground" />
                              )}
                            </div>
                          </div>

                          <div className="space-y-2">
                            <h3 className="min-h-[2.7rem] overflow-hidden text-sm font-semibold leading-[1.35] text-slate-900" title={product.name}>
                              {product.name}
                            </h3>
                            <p className="truncate text-[11px] uppercase tracking-[0.12em] text-slate-400">{product.sku}</p>
                            <div className="flex items-end justify-between gap-3">
                              <span className="text-lg font-semibold tracking-[-0.03em] text-slate-950">
                                {formatCurrency(product.price)}
                              </span>
                              {!isOutOfStock && (
                                <span className="text-[11px] font-medium text-slate-500">Tap to add</span>
                              )}
                            </div>
                          </div>
                        </CardContent>
                      </Card>
                    )
                  })}
                </div>
              )}
            </div>
          </ScrollArea>

          <Pagination
            page={productPage}
            totalPages={productLastPage}
            onPageChange={setProductPage}
            from={(productPage - 1) * productPageSize + 1}
            to={Math.min(productPage * productPageSize, productTotal)}
            total={productTotal}
            label="products"
            className="border-slate-200/80 bg-slate-50/70 px-4 xl:px-5"
            pageSize={productPageSize}
            onPageSizeChange={(size) => { setProductPageSize(size); setProductPage(1) }}
          />
        </section>

        <Card className="flex h-full min-w-0 basis-0 flex-1 flex-col overflow-hidden rounded-[1.75rem] border border-slate-200/80 bg-white/95 shadow-[0_18px_45px_rgba(15,23,42,0.08)]">
        <CardHeader className="pb-3 px-4 pt-3 space-y-2">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <div className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                Current Order
              </div>
              <div className="mt-1 flex flex-wrap items-center gap-2">
                <CardTitle className="text-lg tracking-[-0.02em]">{currentOrderReference}</CardTitle>
                <Badge variant="secondary" className="h-5 px-1.5 text-xs">
                  {activeOrderItemCount} item{activeOrderItemCount === 1 ? '' : 's'}
                </Badge>
              </div>
            </div>
            <div className="flex items-center gap-0.5">
              <Button
                variant="ghost"
                size="icon"
                className="h-7 w-7"
                title="Save Draft"
                onClick={handleSaveDraft}
                disabled={cart.length === 0 || createOrderMutation.isPending}
              >
                <Icon icon="solar:diskette-bold" className="h-4 w-4" />
              </Button>
              <Button
                variant="ghost"
                size="icon"
                className="h-9 w-9 rounded-full border border-rose-200 bg-rose-50 text-rose-600 shadow-sm hover:bg-rose-100 hover:text-rose-700"
                title="Clear Cart"
                disabled={cart.length === 0}
                onClick={() => {
                  clearSessionCart()
                  setCurrentOrderId(null)
                  setCurrentOrderNumber(null)
                  setLoyaltyRedemption({ pointsToRedeem: 0, discountAmount: 0 })
                }}
              >
                <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4" />
              </Button>
            </div>
          </div>
          <div className="flex items-center justify-between gap-2 text-xs text-slate-500">
            <div className="truncate font-medium">
              {activeTerminal?.name || 'Terminal'}
              {activeTerminal?.warehouse?.name ? ` · ${activeTerminal.warehouse.name}` : ''}
            </div>
            <Button variant="ghost" size="sm" className="h-8 rounded-full px-3 text-xs font-medium text-slate-600 hover:bg-slate-100" onClick={openPendingOrders}>
              <Icon icon="solar:clipboard-list-bold" className="mr-1.5 h-4 w-4" />
              View Pending Orders
              {pendingDraftOrderCount > 0 && (
                <Badge variant="secondary" className="ml-2 h-5 rounded-full bg-slate-200 px-1.5 text-[10px] text-slate-700 hover:bg-slate-200">
                  {pendingDraftOrderCount}
                </Badge>
              )}
            </Button>
          </div>

          <Button
            variant={selectedCustomer ? "default" : "outline"}
            size="sm"
            className={cn(
              'h-14 w-full justify-start gap-3 rounded-[1.2rem] border px-4 text-left shadow-sm transition',
              selectedCustomer
                ? 'border-[#1e40af] bg-[linear-gradient(135deg,#1e40af_0%,#2563eb_100%)] text-white hover:bg-[linear-gradient(135deg,#1e3a8a_0%,#1d4ed8_100%)]'
                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
            )}
            onClick={() => setShowCustomerDialog(true)}
          >
            <Icon
              icon={selectedCustomer ? "solar:user-check-bold" : "solar:user-circle-bold"}
              className="h-4 w-4 shrink-0"
            />
            <span className="min-w-0 flex-1">
              <span className="block text-[11px] font-semibold uppercase tracking-[0.18em] opacity-70">Customer</span>
              <span className="block truncate text-sm font-medium">
                {selectedCustomer ? selectedCustomer.name : 'Walk-in'}
              </span>
            </span>
            {selectedCustomer && loyaltyInfo?.enrolled && (
              <Badge variant="secondary" className="shrink-0 rounded-full bg-white/15 text-xs text-white hover:bg-white/15">
                {loyaltyInfo.available_points.toLocaleString()} pts
              </Badge>
            )}
          </Button>

          {/* Selected Customer Info Card */}
          {selectedCustomer && (
            <div className="rounded-[1.2rem] border border-blue-100 bg-[linear-gradient(135deg,#eff6ff_0%,#dbeafe_100%)] p-4 shadow-sm">
              <div className="flex items-start gap-3">
                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-blue-100">
                  <Icon icon="solar:user-circle-bold" className="h-6 w-6 text-primary" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="truncate font-semibold text-slate-900">{selectedCustomer.name}</p>
                  {selectedCustomer.phone && (
                    <p className="text-xs text-slate-500">{selectedCustomer.phone}</p>
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

        <ScrollArea className="flex-1">
          <div className="space-y-3 p-4">
            {cart.length === 0 && (
              <div className="flex flex-col items-center justify-center rounded-[1.4rem] border border-dashed border-slate-200 bg-slate-50 px-6 py-16 text-muted-foreground">
                <Icon icon="solar:cart-large-minimalistic-bold" className="mb-3 h-14 w-14 opacity-20" />
                <p className="text-sm font-medium">No items in this order yet</p>
                <p className="text-xs mt-1 opacity-70">Scan a barcode or search by name to start.</p>
              </div>
            )}
            {cart.map((item) => (
              <div key={item.id} className="group flex gap-3 rounded-[1.25rem] border border-slate-200/80 bg-white p-3.5 shadow-sm transition duration-200 hover:shadow-md">
                {/* Product Image */}
                <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-[1rem] bg-[linear-gradient(180deg,#f8fafc_0%,#eef4f7_100%)]">
                  {item.image ? (
                    <img src={item.image} alt={item.name} className="w-full h-full object-cover" />
                  ) : (
                    <Icon icon="solar:box-bold" className="h-7 w-7 text-muted-foreground" />
                  )}
                </div>

                {/* Product info + controls */}
                <div className="flex-1 min-w-0">
                  <p className="truncate text-sm font-semibold leading-tight text-slate-900" title={item.name}>{item.name}</p>
                  <p className="mt-1 text-xs text-slate-500">{formatCurrency(item.price)} each</p>
                  <div className="flex items-center justify-between mt-2">
                    {/* Large quantity controls */}
                    <div className="flex items-center gap-2 rounded-full bg-slate-50 px-2 py-1">
                      <Button
                        variant="outline"
                        size="icon"
                        className="h-8 w-8 rounded-full border-slate-200 bg-white shadow-sm"
                        onClick={() => updateQuantity(item.id, -1)}
                      >
                        <Icon icon="solar:minus-circle-bold" className="h-4 w-4" />
                      </Button>
                      <span className="w-6 text-center text-sm font-semibold text-slate-900">{item.quantity}</span>
                      <Button
                        variant="outline"
                        size="icon"
                        className="h-8 w-8 rounded-full border-slate-200 bg-white shadow-sm"
                        onClick={() => updateQuantity(item.id, 1)}
                      >
                        <Icon icon="solar:add-circle-bold" className="h-4 w-4" />
                      </Button>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <span className="text-sm font-semibold tracking-[-0.02em] text-slate-950">{formatCurrency(item.line_total)}</span>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7 rounded-full text-destructive hover:bg-rose-50 hover:text-destructive"
                        onClick={() => removeFromCart(item.id)}
                      >
                        <Icon icon="solar:trash-bin-minimalistic-bold" className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </ScrollArea>

        {/* Totals & Actions */}
        <div className="shrink-0 max-h-[45%] space-y-4 overflow-y-auto border-t border-slate-200/80 bg-slate-50/80 p-4">
          
          {/* Discount/Loyalty/Coupon Tabs */}
          {(cart.length > 0 || appliedCoupon || loyaltyRedemption.pointsToRedeem > 0) && (
            <div className="overflow-hidden rounded-[1.2rem] border border-slate-200 bg-white shadow-sm">
              <Tabs value={activeDiscountTab} onValueChange={(v) => setActiveDiscountTab(v as any)} className="w-full">
                <TabsList className="grid w-full grid-cols-3 rounded-none border-b border-slate-200 bg-slate-50">
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

          <div className="rounded-[1.2rem] border border-slate-200 bg-white px-4 py-4 shadow-sm">
            <div className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-slate-500">Subtotal</span>
                <span className="font-medium text-slate-900">{formatCurrency(subtotal)}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Tax</span>
                <span className="font-medium text-slate-900">{formatCurrency(taxAmount)}</span>
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
              <div className="flex items-end justify-between font-semibold">
                <div>
                  <div className="text-[11px] uppercase tracking-[0.2em] text-slate-500">Grand Total</div>
                  <div className="mt-1 text-3xl font-semibold tracking-[-0.04em] text-slate-950">{formatCurrency(total)}</div>
                </div>
              </div>
            </div>
          </div>

          {/* PAY — primary full-width action */}
          <Button
            size="lg"
            className="h-14 w-full rounded-[1.2rem] bg-[linear-gradient(135deg,#1e40af_0%,#2563eb_100%)] text-base font-semibold text-white shadow-[0_18px_35px_rgba(30,64,175,0.28)] hover:bg-[linear-gradient(135deg,#1e3a8a_0%,#1d4ed8_100%)]"
            onClick={() => setShowCheckoutDialog(true)}
            disabled={cart.length === 0}
          >
            <Icon icon="solar:wallet-money-bold" className="h-5 w-5 mr-2" />
            PAY{cart.length > 0 ? ` ${formatCurrency(total)}` : ''}
          </Button>
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
                placeholder="Search by customer name, code, phone, or email..."
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
                        void fireDisplayEvent('MemberIdentified', { member_id: customer.id })
                      }}
                    >
                      <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                        <Icon icon="solar:user-bold" className="h-5 w-5 text-primary" />
                      </div>
                      <div className="text-left flex-1 min-w-0">
                        <p className="font-medium truncate">{customer.name}</p>
                        {customer.code && <p className="text-xs text-muted-foreground truncate">{customer.code}</p>}
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
        <DialogContent className="w-[96vw] max-w-none sm:max-w-[860px] max-h-[90vh] overflow-hidden rounded-[1.5rem] border-0 p-0 shadow-[0_24px_64px_rgba(15,23,42,0.18)]">
          {/* Header */}
          <div className="relative bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 px-6 pt-6 pb-5 text-white">
            <div className="absolute right-4 top-4 h-24 w-24 rounded-full bg-[#1e40af]/10 blur-2xl" />
            <div className="relative flex items-start gap-4">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-[1rem] bg-rose-500/15 ring-1 ring-rose-400/20">
                <Icon icon="solar:logout-2-bold-duotone" className="h-6 w-6 text-rose-400" />
              </div>
              <DialogHeader className="space-y-1 p-0 text-left">
                <DialogTitle className="flex items-center gap-2 text-lg font-semibold tracking-[-0.02em]">
                  <Icon icon="solar:logout-2-bold-duotone" className="h-5 w-5 text-rose-300" />
                  Close Register
                </DialogTitle>
                <DialogDescription className="text-sm leading-relaxed text-white/60">
                  Count cash and verify all payment methods before closing.
                </DialogDescription>
              </DialogHeader>
            </div>
          </div>

          {/* Body */}
          <div className="flex flex-col overflow-y-auto p-6" style={{ maxHeight: 'calc(90vh - 200px)' }}>
            {/* Session Info */}
            <div className="mb-5 grid grid-cols-3 gap-3 rounded-[1rem] border border-slate-200/80 bg-slate-50/80 p-3">
              <div className="text-center">
                <div className="text-[11px] font-medium uppercase tracking-[0.1em] text-slate-400">Terminal</div>
                <div className="mt-1 text-sm font-semibold text-slate-900">{activeTerminal?.name || '—'}</div>
              </div>
              <div className="text-center">
                <div className="text-[11px] font-medium uppercase tracking-[0.1em] text-slate-400">Orders</div>
                <div className="mt-1 text-sm font-semibold text-slate-900">{cart.length} in cart</div>
              </div>
              <div className="text-center">
                <div className="text-[11px] font-medium uppercase tracking-[0.1em] text-slate-400">Status</div>
                <div className={`mt-1 inline-flex items-center gap-1.5 text-sm font-semibold ${isOnline ? 'text-emerald-600' : 'text-rose-600'}`}>
                  <span className={`h-1.5 w-1.5 rounded-full ${isOnline ? 'bg-emerald-500' : 'bg-rose-500'}`} />
                  {isOnline ? 'Online' : 'Offline'}
                </div>
              </div>
            </div>

            {/* Payment Method Counting */}
            <div className="mb-4">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-[0.1em] text-slate-500">Payment Reconciliation</h3>
              <div className="space-y-2">
                {paymentBreakdown.map((item) => {
                  const counted = parseFloat(item.countedAmount) || 0
                  const diff = counted - item.expectedAmount
                  const diffTone = diff > 0.005 ? 'text-emerald-600' : diff < -0.005 ? 'text-rose-600' : 'text-slate-400'
                  const diffLabel = diff > 0.005 ? 'Over' : diff < -0.005 ? 'Short' : 'Exact'
                  const isCash = item.method === 'cash'

                  return (
                    <div key={item.method} className="rounded-[0.85rem] border border-slate-200/80 bg-white p-3">
                      <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2.5">
                          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100">
                            <Icon icon={item.icon} className="h-4 w-4 text-slate-600" />
                          </div>
                          <div>
                            <div className="text-sm font-semibold text-slate-900">{item.methodLabel}</div>
                            <div className="text-xs text-slate-400">Expected: {formatCurrency(item.expectedAmount)}</div>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={item.countedAmount}
                            onChange={(e) => updateCountedAmount(item.method, e.target.value)}
                            className="h-9 w-28 rounded-lg border-slate-200 bg-slate-50 text-right text-sm font-semibold tabular-nums focus:bg-white"
                            placeholder="0.00"
                          />
                          {isCash && (
                            <Button
                              type="button"
                              variant="outline"
                              size="icon"
                              className={cn(
                                'h-9 w-9 rounded-lg border-slate-200',
                                showCashCountPanel && 'border-primary bg-primary/5 text-primary'
                              )}
                              onClick={() => setShowCashCountPanel((prev) => !prev)}
                              title="Count notes and coins"
                            >
                              <Icon icon="solar:calculator-bold-duotone" className="h-4 w-4" />
                            </Button>
                          )}
                        </div>
                      </div>
                      {isCash && showCashCountPanel && (
                        <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50/80 p-3">
                          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div className="rounded-lg border border-slate-200 bg-white p-2.5">
                              <div className="mb-2 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Notes Count</div>
                              <div className="space-y-2">
                                {CASH_DENOMINATIONS.filter((denom) => denom.type === 'note').map((denom) => {
                                  const qty = cashDenominationCounts[denom.label] || 0
                                  const rowTotal = qty * denom.value
                                  return (
                                    <div key={denom.label} className="grid grid-cols-[60px_1fr_84px] items-center gap-1.5 rounded-md border border-slate-200 bg-slate-50 p-1.5">
                                      <span className="text-xs font-semibold text-slate-700">{denom.label}</span>
                                      <div className="grid grid-cols-[26px_1fr_26px] items-center gap-1">
                                        <Button type="button" variant="outline" className="h-7 w-7 p-0" onClick={() => adjustCashDenominationCount(denom.label, -1)}>
                                          -
                                        </Button>
                                        <Input
                                          type="number"
                                          min="0"
                                          step="1"
                                          value={qty}
                                          onChange={(e) => updateCashDenominationCount(denom.label, Number(e.target.value || 0))}
                                          className="h-7 px-1 text-center text-xs"
                                        />
                                        <Button type="button" variant="outline" className="h-7 w-7 p-0" onClick={() => adjustCashDenominationCount(denom.label, 1)}>
                                          +
                                        </Button>
                                      </div>
                                      <span className="text-right text-[11px] font-semibold text-slate-600">{formatCurrency(rowTotal)}</span>
                                    </div>
                                  )
                                })}
                              </div>
                            </div>

                            <div className="rounded-lg border border-slate-200 bg-white p-2.5">
                              <div className="mb-2 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Coins Count</div>
                              <div className="space-y-2">
                                {CASH_DENOMINATIONS.filter((denom) => denom.type === 'coin').map((denom) => {
                                  const qty = cashDenominationCounts[denom.label] || 0
                                  const rowTotal = qty * denom.value
                                  return (
                                    <div key={denom.label} className="grid grid-cols-[60px_1fr_84px] items-center gap-1.5 rounded-md border border-slate-200 bg-slate-50 p-1.5">
                                      <span className="text-xs font-semibold text-slate-700">{denom.label}</span>
                                      <div className="grid grid-cols-[26px_1fr_26px] items-center gap-1">
                                        <Button type="button" variant="outline" className="h-7 w-7 p-0" onClick={() => adjustCashDenominationCount(denom.label, -1)}>
                                          -
                                        </Button>
                                        <Input
                                          type="number"
                                          min="0"
                                          step="1"
                                          value={qty}
                                          onChange={(e) => updateCashDenominationCount(denom.label, Number(e.target.value || 0))}
                                          className="h-7 px-1 text-center text-xs"
                                        />
                                        <Button type="button" variant="outline" className="h-7 w-7 p-0" onClick={() => adjustCashDenominationCount(denom.label, 1)}>
                                          +
                                        </Button>
                                      </div>
                                      <span className="text-right text-[11px] font-semibold text-slate-600">{formatCurrency(rowTotal)}</span>
                                    </div>
                                  )
                                })}
                              </div>
                            </div>
                          </div>

                          <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <div className="rounded-md border border-slate-200 bg-white p-2">
                              <div className="text-[11px] text-slate-500">Notes Total</div>
                              <div className="text-sm font-semibold text-slate-800">{formatCurrency(cashNotesTotal)}</div>
                            </div>
                            <div className="rounded-md border border-slate-200 bg-white p-2">
                              <div className="text-[11px] text-slate-500">Coins Total</div>
                              <div className="text-sm font-semibold text-slate-800">{formatCurrency(cashCoinsTotal)}</div>
                            </div>
                            <div className="rounded-md border border-slate-200 bg-white p-2">
                              <div className="text-[11px] text-slate-500">Total Pieces</div>
                              <div className="text-sm font-semibold text-slate-800">{cashPiecesTotal}</div>
                            </div>
                          </div>
                        </div>
                      )}
                      {item.expectedAmount > 0 && (
                        <div className={`mt-2 flex items-center justify-end gap-1 text-xs font-medium ${diffTone}`}>
                          <span>{diffLabel}</span>
                          <span>{diff > 0 ? '+' : ''}{formatCurrency(diff)}</span>
                        </div>
                      )}
                    </div>
                  )
                })}
              </div>
            </div>

            {/* Totals Summary */}
            <div className="mb-4 rounded-[1rem] border border-slate-200/80 bg-slate-50/80 p-4">
              <div className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-slate-500">Total Expected</span>
                  <span className="font-semibold tabular-nums text-slate-900">{formatCurrency(totalExpected)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Total Counted</span>
                  <span className="font-semibold tabular-nums text-slate-900">{formatCurrency(totalCounted)}</span>
                </div>
                <Separator />
                <div className="flex justify-between">
                  <span className="font-semibold text-slate-700">Difference</span>
                  <span className={`font-bold tabular-nums ${totalDifference > 0.005 ? 'text-emerald-600' : totalDifference < -0.005 ? 'text-rose-600' : 'text-slate-500'}`}>
                    {totalDifference > 0.005 ? '+' : ''}{formatCurrency(totalDifference)}
                  </span>
                </div>
              </div>
            </div>

            {/* Notes */}
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">Closing Notes (optional)</label>
              <Input
                value={closingNotes}
                onChange={(e) => setClosingNotes(e.target.value)}
                placeholder="Any discrepancies or notes..."
                className="h-9 rounded-lg border-slate-200 bg-slate-50 text-sm"
              />
            </div>
          </div>

          {/* Footer */}
          <div className="border-t border-slate-200/80 bg-slate-50/50 px-6 py-4">
            <div className="flex gap-3">
              <Button
                variant="outline"
                onClick={() => setShowCloseSessionDialog(false)}
                disabled={closeSessionMutation.isPending}
                className="flex-1 rounded-[0.85rem] border-slate-200 bg-white py-2.5 font-semibold text-slate-700 hover:bg-slate-50"
              >
                Cancel
              </Button>
              <Button
                onClick={() => closeSessionMutation.mutate()}
                disabled={closeSessionMutation.isPending}
                className="flex-1 rounded-[0.85rem] bg-gradient-to-b from-rose-600 to-rose-700 py-2.5 font-semibold text-white shadow-[0_8px_20px_rgba(225,29,72,0.30)] hover:from-rose-700 hover:to-rose-800"
              >
                {closeSessionMutation.isPending ? (
                  <span className="flex items-center justify-center gap-2">
                    <Icon icon="svg-spinners:ring-resize" className="h-4 w-4" />
                    Closing...
                  </span>
                ) : 'Close Register'}
              </Button>
            </div>
          </div>
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
                businessName={receiptBranding.businessName}
                businessAddresses={receiptBranding.businessAddresses}
                businessAddress={receiptBranding.businessAddress}
                businessPhone={receiptBranding.businessPhone}
                businessEmail={receiptBranding.businessEmail}
                whatsapp={receiptBranding.whatsapp}
                instagram={receiptBranding.instagram}
                facebook={receiptBranding.facebook}
                businessWebsite={receiptBranding.businessWebsite}
                gstRegNo={receiptBranding.gstRegNo}
                footerMessage={receiptBranding.footerMessage}
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
              onClick={() => void handleReceiptPrint()}
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
    </>
  )
}

function userDiscountInput(val: string): number {
    const n = parseFloat(val)
    return isNaN(n) ? 0 : n
}
