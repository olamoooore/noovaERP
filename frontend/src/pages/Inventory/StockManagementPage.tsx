import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import { Pagination } from '@/components/ui/Pagination'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { formatCurrency, formatDate } from '@/lib/utils'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'

interface Product {
  id: number
  name: string
  sku: string
  stock_quantity: number
  reorder_level?: number | null
  cost: number
  price: number
  image: string | null
  is_active: boolean
  min_stock_threshold: number | null
  category?: { name: string }
  tracking_method?: 'none' | 'quantity' | 'batch' | 'serial'
}

interface Warehouse {
  id: number
  name: string
  code: string
}

interface StockMovement {
  id: number
  product: Product
  product_id?: number
  warehouse?: Warehouse
  warehouse_id?: number
  type: string
  quantity: number
  old_quantity: number
  balance_after: number
  reference: string | null
  notes: string | null
  created_by: { name: string } | null
  created_at: string
}

interface AdjustmentReason {
  id: number
  name: string
  code: string
  type: string
}

interface InventorySettings {
  sme_mode: boolean
}

interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const normalizePaginatedResponse = <T,>(payload: any): PaginatedResponse<T> => {
  const rows = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : []

  return {
    data: rows,
    current_page: payload?.current_page ?? payload?.meta?.current_page ?? 1,
    last_page: payload?.last_page ?? payload?.meta?.last_page ?? 1,
    per_page: payload?.per_page ?? payload?.meta?.per_page ?? rows.length,
    total: payload?.total ?? payload?.meta?.total ?? rows.length,
  }
}

type OperationType = 'in' | 'out' | 'adjust'
type ActiveTab = 'products' | 'movements' | 'low-stock' | 'out-of-stock'

const isReasonAllowedForOperation = (reason: AdjustmentReason, operationType: OperationType) => {
  if (operationType === 'in') return reason.type === 'increase' || reason.type === 'both'
  if (operationType === 'out') return reason.type === 'decrease' || reason.type === 'both'
  return reason.type === 'both'
}

export function StockManagementPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  
  // Tab state
  const [activeTab, setActiveTab] = useState<ActiveTab>('products')
  
  // Dialog state
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [operationType, setOperationType] = useState<OperationType>('in')
  
  // Form state
  const [productId, setProductId] = useState('')
  const [warehouseId, setWarehouseId] = useState('')
  const [quantity, setQuantity] = useState('')
  const [newQuantity, setNewQuantity] = useState('')
  const [reasonId, setReasonId] = useState('')
  const [reference, setReference] = useState('')
  const [notes, setNotes] = useState('')
  const [batchNumber, setBatchNumber] = useState('')
  const [lotNumber, setLotNumber] = useState('')
  const [expiryDate, setExpiryDate] = useState('')
  const [serialNumbers, setSerialNumbers] = useState('')
  
  // Filter state
  const [searchProducts, setSearchProducts] = useState('')
  const [searchMovements, setSearchMovements] = useState('')
  const [filterType, setFilterType] = useState('all')
  const [filterWarehouse, setFilterWarehouse] = useState('all')
  const [productsPage, setProductsPage] = useState(1)
  const [movementsPage, setMovementsPage] = useState(1)
  const [lowStockPage, setLowStockPage] = useState(1)
  const [outOfStockPage, setOutOfStockPage] = useState(1)
  const [productsPageSize, setProductsPageSize] = useState(50)
  const [movementsPageSize, setMovementsPageSize] = useState(50)
  const [lowStockPageSize, setLowStockPageSize] = useState(25)
  const [outOfStockPageSize, setOutOfStockPageSize] = useState(25)

  useEffect(() => {
    setProductsPage(1)
  }, [searchProducts])

  useEffect(() => {
    setMovementsPage(1)
  }, [searchMovements, filterType, filterWarehouse])

  // Queries
  const { data: productsData, isLoading: productsLoading } = useQuery<PaginatedResponse<Product>>({
    queryKey: ['products-stock', searchProducts, productsPage, productsPageSize],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: productsPageSize, page: productsPage }
      if (searchProducts) params.search = searchProducts
      const res = await api.get('/products', { params })
      return normalizePaginatedResponse<Product>(res.data)
    },
    staleTime: 0,
    refetchOnMount: 'always',
  })

  const { data: warehouses } = useQuery<Warehouse[]>({
    queryKey: ['warehouses'],
    queryFn: async () => {
      const res = await api.get('/warehouses')
      return res.data
    },
  })

  const { data: reasons } = useQuery<AdjustmentReason[]>({
    queryKey: ['adjustment-reasons'],
    queryFn: async () => {
      const res = await api.get('/stock/adjustment-reasons')
      return res.data
    },
  })

  const { data: inventorySettings } = useQuery<InventorySettings>({
    queryKey: ['inventory-settings'],
    queryFn: async () => {
      const res = await api.get('/inventory/settings')
      return res.data
    },
  })

  const { data: movementsData, isLoading: movementsLoading } = useQuery<PaginatedResponse<StockMovement>>({
    queryKey: ['stock-movements', searchMovements, filterType, filterWarehouse, movementsPage, movementsPageSize],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: movementsPageSize, page: movementsPage }
      if (searchMovements) params.search = searchMovements
      if (filterType !== 'all') params.type = filterType
      if (filterWarehouse !== 'all') params.warehouse_id = filterWarehouse
      const res = await api.get('/stock/movements', { params })
      return normalizePaginatedResponse<StockMovement>(res.data)
    },
  })

  const { data: lowStockData, isLoading: lowStockLoading } = useQuery<PaginatedResponse<Product>>({
    queryKey: ['low-stock', lowStockPage, lowStockPageSize],
    queryFn: async () => {
      const res = await api.get('/stock/low-stock', { params: { per_page: lowStockPageSize, page: lowStockPage } })
      return normalizePaginatedResponse<Product>(res.data)
    },
  })

  const { data: outOfStockData, isLoading: outOfStockLoading } = useQuery<PaginatedResponse<Product>>({
    queryKey: ['out-of-stock', outOfStockPage, outOfStockPageSize],
    queryFn: async () => {
      const res = await api.get('/stock/out-of-stock', { params: { per_page: outOfStockPageSize, page: outOfStockPage } })
      return normalizePaginatedResponse<Product>(res.data)
    },
  })

  const { data: inventoryDashboard } = useQuery<{
    summary?: {
      total_products?: number
      total_warehouses?: number
      low_stock_count?: number
      out_of_stock_count?: number
    }
  }>({
    queryKey: ['inventory-dashboard'],
    queryFn: async () => {
      const res = await api.get('/inventory/dashboard')
      return res.data
    },
  })

  const invalidateStockQueries = () => {
    const keysToRefresh = new Set([
      'stock-movements',
      'products',
      'products-stock',
      'products-list',
      'products-search',
      'product-detail',
      'low-stock',
      'out-of-stock',
      'inventory-dashboard',
    ])

    queryClient.invalidateQueries({
      predicate: (query) => keysToRefresh.has(String(query.queryKey[0] ?? '')),
    })
  }

  const assertMovementResponse = (payload: any) => {
    if (!payload || typeof payload !== 'object' || typeof payload.id !== 'number') {
      throw new Error('Unexpected stock mutation response from server.')
    }
    return payload
  }

  const getMovementDelta = (movement: StockMovement) => {
    if (movement.type === 'adjustment') {
      return movement.balance_after - movement.old_quantity
    }

    if (['out', 'sale', 'transfer_out'].includes(movement.type)) {
      return -Math.abs(movement.quantity)
    }

    return Math.abs(movement.quantity)
  }

  const syncProductStockInCache = (movement: StockMovement) => {
    const productId = movement?.product?.id ?? movement?.product_id
    const movementDelta = getMovementDelta(movement)

    if (typeof productId !== 'number') {
      return
    }

    queryClient.setQueriesData({
      predicate: (query) => ['products', 'products-stock', 'products-list', 'products-search'].includes(String(query.queryKey[0] ?? '')),
    }, (existing: any) => {
      if (!existing) return existing

      if (Array.isArray(existing)) {
        return existing.map((item) => {
          if (item?.id !== productId) return item
          const nextStockQuantity = Math.max(0, Number(item?.stock_quantity ?? item?.stock ?? 0) + movementDelta)
          return { ...item, stock_quantity: nextStockQuantity, stock: nextStockQuantity }
        })
      }

      if (Array.isArray(existing?.data)) {
        return {
          ...existing,
          data: existing.data.map((item: any) => {
            if (item?.id !== productId) return item
            const nextStockQuantity = Math.max(0, Number(item?.stock_quantity ?? item?.stock ?? 0) + movementDelta)
            return { ...item, stock_quantity: nextStockQuantity, stock: nextStockQuantity }
          }),
        }
      }

      return existing
    })

    queryClient.setQueriesData({
      predicate: (query) => String(query.queryKey[0] ?? '') === 'product-detail' && String(query.queryKey[1] ?? '') === String(productId),
    }, (existing: any) => {
      if (!existing) return existing
      const nextStockQuantity = Math.max(0, Number(existing?.stock_quantity ?? existing?.stock ?? 0) + movementDelta)
      return { ...existing, stock_quantity: nextStockQuantity, stock: nextStockQuantity }
    })
  }

  const refreshProductStockInCache = async (productId: number) => {
    const response = await api.get(`/stock/product/${productId}`)
    const stockLevels = Array.isArray(response.data) ? response.data : []
    const totalStock = stockLevels.reduce((sum, stockLevel) => sum + Number(stockLevel.quantity ?? 0), 0)

    queryClient.setQueriesData({
      predicate: (query) => ['products', 'products-stock', 'products-list', 'products-search'].includes(String(query.queryKey[0] ?? '')),
    }, (existing: any) => {
      if (!existing) return existing

      if (Array.isArray(existing)) {
        return existing.map((item) => item?.id === productId ? { ...item, stock_quantity: totalStock, stock: totalStock } : item)
      }

      if (Array.isArray(existing?.data)) {
        return {
          ...existing,
          data: existing.data.map((item: any) => item?.id === productId ? { ...item, stock_quantity: totalStock, stock: totalStock } : item),
        }
      }

      return existing
    })

    queryClient.setQueriesData({
      predicate: (query) => String(query.queryKey[0] ?? '') === 'product-detail' && String(query.queryKey[1] ?? '') === String(productId),
    }, (existing: any) => {
      if (!existing) return existing
      return { ...existing, stock_quantity: totalStock, stock: totalStock }
    })

    return totalStock
  }

  const refetchStockQueries = async () => {
    await Promise.all([
      queryClient.refetchQueries({ queryKey: ['products-stock'], type: 'active' }),
      queryClient.refetchQueries({ queryKey: ['stock-movements'], type: 'active' }),
      queryClient.refetchQueries({ queryKey: ['low-stock'], type: 'active' }),
      queryClient.refetchQueries({ queryKey: ['out-of-stock'], type: 'active' }),
      queryClient.refetchQueries({ queryKey: ['inventory-dashboard'], type: 'active' }),
    ])
  }

  const stockInMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/stock/in', {
        product_id: parseInt(productId),
        warehouse_id: parseInt(warehouseId),
        quantity: parseInt(quantity),
        reference: reference || null,
        notes: notes || null,
        adjustment_reason_id: parseInt(reasonId),
        batch_number: batchNumber || null,
        lot_number: lotNumber || batchNumber || null,
        expiry_date: expiryDate || null,
        serial_numbers: serialNumbers.split(/[\n,]+/).map((value) => value.trim()).filter(Boolean),
      })
      return assertMovementResponse(response.data)
    },
    onSuccess: async (movement) => {
      syncProductStockInCache(movement)
      invalidateStockQueries()
      toast({ title: 'Stock added successfully' })
      closeDialog()
    },
    onError: (error: any) => {
      const msg = error.response?.data?.message || 'Failed to add stock'
      toast({ title: 'Error', description: msg, variant: 'destructive' })
    },
  })

  const stockOutMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/stock/out', {
        product_id: parseInt(productId),
        warehouse_id: parseInt(warehouseId),
        quantity: parseInt(quantity),
        reference: reference || null,
        notes: notes || null,
        adjustment_reason_id: parseInt(reasonId),
      })
      return assertMovementResponse(response.data)
    },
    onSuccess: async (movement) => {
      syncProductStockInCache(movement)
      invalidateStockQueries()
      toast({ title: 'Stock removed successfully' })
      closeDialog()
    },
    onError: (error: any) => {
      const msg = error.response?.data?.message || 'Failed to remove stock'
      toast({ title: 'Error', description: msg, variant: 'destructive' })
    },
  })

  const adjustMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/stock/adjust', {
        product_id: parseInt(productId),
        warehouse_id: parseInt(warehouseId),
        new_quantity: parseInt(newQuantity),
        adjustment_reason_id: parseInt(reasonId),
        notes: notes || null,
      })
      return assertMovementResponse(response.data)
    },
    onSuccess: async (movement) => {
      syncProductStockInCache(movement)
      invalidateStockQueries()
      toast({ title: 'Stock adjusted successfully' })
      closeDialog()
    },
    onError: (error: any) => {
      const msg = error.response?.data?.message || 'Failed to adjust stock'
      toast({ title: 'Error', description: msg, variant: 'destructive' })
    },
  })

  const closeDialog = () => {
    setIsDialogOpen(false)
    setProductId('')
    setWarehouseId('')
    setQuantity('')
    setNewQuantity('')
    setReasonId('')
    setReference('')
    setNotes('')
    setBatchNumber('')
    setLotNumber('')
    setExpiryDate('')
    setSerialNumbers('')
  }

  const openDialog = (type: OperationType, preselectedProductId?: number) => {
    setOperationType(type)
    if (preselectedProductId) {
      setProductId(String(preselectedProductId))
    }
    setIsDialogOpen(true)
  }

  const handleSubmit = () => {
    if (!productId || !warehouseId) {
      toast({ title: 'Please select product and warehouse', variant: 'destructive' })
      return
    }

    if (inventorySettings && !inventorySettings.sme_mode) {
      toast({
        title: 'SME Mode is disabled',
        description: 'Enable SME Mode in Inventory Settings before using direct stock entry.',
        variant: 'destructive',
      })
      return
    }

    if (operationType === 'adjust') {
      if (newQuantity === '' || !reasonId) {
        toast({ title: 'Please fill all required fields', variant: 'destructive' })
        return
      }
      adjustMutation.mutate()
    } else {
      if (!quantity || parseInt(quantity) <= 0 || !reasonId) {
        toast({ title: 'Please enter a valid quantity', variant: 'destructive' })
        return
      }
      if (operationType === 'in' && selectedProduct?.tracking_method === 'batch' && !batchNumber.trim()) {
        toast({ title: 'Batch/Lot number is required', variant: 'destructive' })
        return
      }
      if (operationType === 'in' && selectedProduct?.tracking_method === 'serial') {
        const serials = serialNumbers.split(/[\n,]+/).map((value) => value.trim()).filter(Boolean)
        if (serials.length !== parseInt(quantity) || new Set(serials).size !== serials.length) {
          toast({ title: 'Serial count must match quantity and contain no duplicates', variant: 'destructive' })
          return
        }
      }
      if (operationType === 'in') {
        stockInMutation.mutate()
      } else {
        stockOutMutation.mutate()
      }
    }
  }

  const products = productsData?.data || []
  const movements = movementsData?.data || []
  const { sorted: sortedProducts, sortKey: productSortKey, sortDir: productSortDir, toggle: toggleProductSort } = useSort<Product>(products)
  const { sorted: sortedMovements, sortKey: movementSortKey, sortDir: movementSortDir, toggle: toggleMovementSort } = useSort<StockMovement>(movements, 'created_at', 'desc')
  const lowStockItems = lowStockData?.data || []
  const outOfStockItems = outOfStockData?.data || []
  const dialogProducts = Array.from(
    new Map([...products, ...lowStockItems, ...outOfStockItems].map((product) => [product.id, product])).values()
  )
  const selectedProduct = dialogProducts.find(p => p.id === parseInt(productId))
  const availableReasons = (reasons || []).filter((reason) => isReasonAllowedForOperation(reason, operationType))
  const lowStockCount = inventoryDashboard?.summary?.low_stock_count ?? lowStockItems.length
  const outOfStockCount = inventoryDashboard?.summary?.out_of_stock_count ?? outOfStockItems.length
  const isSubmitting = stockInMutation.isPending || stockOutMutation.isPending || adjustMutation.isPending

  const getTypeConfig = (type: string) => {
    const configs: Record<string, { color: string; icon: string; label: string }> = {
      in: { color: 'bg-primary/10 text-primary border-primary/20', icon: 'solar:arrow-down-bold', label: 'Stock In' },
      out: { color: 'bg-rose-500/10 text-rose-600 border-rose-500/20', icon: 'solar:arrow-up-bold', label: 'Stock Out' },
      purchase: { color: 'bg-blue-500/10 text-blue-600 border-blue-500/20', icon: 'solar:cart-large-2-bold', label: 'Purchase' },
      sale: { color: 'bg-amber-500/10 text-amber-600 border-amber-500/20', icon: 'solar:bag-bold', label: 'Sale' },
      adjustment: { color: 'bg-violet-500/10 text-violet-600 border-violet-500/20', icon: 'solar:tuning-2-bold', label: 'Adjustment' },
      transfer_in: { color: 'bg-cyan-500/10 text-cyan-600 border-cyan-500/20', icon: 'solar:login-2-bold', label: 'Transfer In' },
      transfer_out: { color: 'bg-orange-500/10 text-orange-600 border-orange-500/20', icon: 'solar:logout-2-bold', label: 'Transfer Out' },
      return: { color: 'bg-teal-500/10 text-teal-600 border-teal-500/20', icon: 'solar:undo-left-bold', label: 'Return' },
    }
    return configs[type] || { color: 'bg-gray-500/10 text-gray-600 border-gray-500/20', icon: 'solar:question-circle-bold', label: type }
  }

  const getStockStatus = (product: Product) => {
    const threshold = product.min_stock_threshold || product.reorder_level || 10
    if (product.stock_quantity <= 0) return { label: 'Out of Stock', color: 'bg-rose-100 text-rose-800' }
    if (product.stock_quantity <= threshold) return { label: 'Low Stock', color: 'bg-amber-100 text-amber-800' }
    return { label: 'In Stock', color: 'bg-green-100 text-green-800' }
  }

  const renderPagination = (
    pagination: PaginatedResponse<unknown> | undefined,
    page: number,
    setPage: React.Dispatch<React.SetStateAction<number>>,
    itemLabel: string,
    pageSize: number,
    setPageSize: (size: number) => void
  ) => {
    if (!pagination) {
      return null
    }

    return (
      <Pagination
        page={page}
        totalPages={pagination.last_page}
        onPageChange={setPage}
        from={((pagination.current_page - 1) * pagination.per_page) + 1}
        to={Math.min(pagination.current_page * pagination.per_page, pagination.total)}
        total={pagination.total}
        label={itemLabel}
        pageSize={pageSize}
        onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
      />
    )
  }

  return (
    <div className="space-y-6">
      {inventorySettings && !inventorySettings.sme_mode && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
          <div className="flex items-start gap-2">
            <Icon icon="solar:info-circle-bold" className="mt-0.5 h-4 w-4 shrink-0" />
            <div>
              <span className="font-semibold">SME Mode is disabled.</span> Direct stock entry requires SME Mode to be enabled.
              Go to <a href="/inventory/settings" className="underline font-medium">Inventory Settings</a> and turn on <em>SME Mode</em> to use these actions.
            </div>
          </div>
        </div>
      )}

      {/* Header */}
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Stock Management</h1>
          <p className="text-muted-foreground mt-1">Manage your inventory stock levels and movements</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => openDialog('in')} className="bg-primary hover:bg-primary/90">
            <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
            Receive Stock
          </Button>
          <Button onClick={() => openDialog('out')} variant="destructive">
            <Icon icon="solar:minus-circle-bold" className="mr-2 h-4 w-4" />
            Remove Stock
          </Button>
          <Button onClick={() => openDialog('adjust')} variant="outline">
            <Icon icon="solar:tuning-2-bold" className="mr-2 h-4 w-4" />
            Reconcile Count
          </Button>
        </div>
      </div>

      {/* Quick Stats */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Card className="relative overflow-hidden">
          <div className="absolute right-0 top-0 h-24 w-24 translate-x-8 -translate-y-4">
            <div className="absolute h-full w-full rounded-full bg-primary/10" />
          </div>
          <CardHeader className="pb-2">
            <CardDescription>Total Products</CardDescription>
            <CardTitle className="text-3xl font-bold">{inventoryDashboard?.summary?.total_products ?? products.length}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">Active inventory items</p>
          </CardContent>
        </Card>

        <Card className="relative overflow-hidden">
          <div className="absolute right-0 top-0 h-24 w-24 translate-x-8 -translate-y-4">
            <div className="absolute h-full w-full rounded-full bg-blue-500/10" />
          </div>
          <CardHeader className="pb-2">
            <CardDescription>Warehouses</CardDescription>
            <CardTitle className="text-3xl font-bold">{warehouses?.length || 0}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">Storage locations</p>
          </CardContent>
        </Card>

        <Card className="relative overflow-hidden border-amber-500/50">
          <div className="absolute right-0 top-0 h-24 w-24 translate-x-8 -translate-y-4">
            <div className="absolute h-full w-full rounded-full bg-amber-500/10" />
          </div>
          <CardHeader className="pb-2">
            <CardDescription className="flex items-center gap-2">
              <Icon icon="solar:danger-triangle-bold" className="h-4 w-4 text-amber-500" />
              Low Stock
            </CardDescription>
            <CardTitle className="text-3xl font-bold text-amber-600">{lowStockCount}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">Items below threshold</p>
          </CardContent>
        </Card>

        <Card className="relative overflow-hidden border-rose-500/50">
          <div className="absolute right-0 top-0 h-24 w-24 translate-x-8 -translate-y-4">
            <div className="absolute h-full w-full rounded-full bg-rose-500/10" />
          </div>
          <CardHeader className="pb-2">
            <CardDescription className="flex items-center gap-2">
              <Icon icon="solar:close-circle-bold" className="h-4 w-4 text-rose-500" />
              Out of Stock
            </CardDescription>
            <CardTitle className="text-3xl font-bold text-rose-600">{outOfStockCount}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">Items need restocking</p>
          </CardContent>
        </Card>
      </div>

      {/* Main Content */}
      <Tabs value={activeTab} onValueChange={(v) => setActiveTab(v as ActiveTab)} className="space-y-4">
        <TabsList className="grid w-full grid-cols-4 lg:w-[500px]">
          <TabsTrigger value="products" className="gap-2">
            <Icon icon="solar:box-bold" className="h-4 w-4" />
            Products
          </TabsTrigger>
          <TabsTrigger value="movements" className="gap-2">
            <Icon icon="solar:history-bold" className="h-4 w-4" />
            Movements
          </TabsTrigger>
          <TabsTrigger value="low-stock" className="gap-2">
            <Icon icon="solar:danger-triangle-bold" className="h-4 w-4" />
            Low Stock
          </TabsTrigger>
          <TabsTrigger value="out-of-stock" className="gap-2">
            <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
            Out of Stock
          </TabsTrigger>
        </TabsList>

        {/* Products Tab - Shows all products with stock */}
        <TabsContent value="products" className="space-y-4">
          <Card>
            <CardHeader className="pb-4">
              <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                  <CardTitle>Product Stock Levels</CardTitle>
                  <CardDescription>View and manage stock for all products</CardDescription>
                </div>
                <div className="relative max-w-sm">
                  <Icon icon="solar:magnifer-bold" className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    placeholder="Search products..."
                    value={searchProducts}
                    onChange={(e) => setSearchProducts(e.target.value)}
                    className="pl-9"
                  />
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <div className="rounded-lg border">
                <Table>
                  <TableHeader>
                    <TableRow className="bg-muted/50">
                      <SortableHead label="Product" sortKey="name" activeKey={productSortKey} direction={productSortDir} onSort={toggleProductSort} className="w-[300px]" />
                      <SortableHead label="SKU" sortKey="sku" activeKey={productSortKey} direction={productSortDir} onSort={toggleProductSort} />
                      <SortableHead label="Category" sortKey="category.name" activeKey={productSortKey} direction={productSortDir} onSort={toggleProductSort} />
                      <SortableHead label="Cost" sortKey="cost" activeKey={productSortKey} direction={productSortDir} onSort={toggleProductSort} align="right" />
                      <SortableHead label="Price" sortKey="price" activeKey={productSortKey} direction={productSortDir} onSort={toggleProductSort} align="right" />
                      <SortableHead label="Stock" sortKey="stock_quantity" activeKey={productSortKey} direction={productSortDir} onSort={toggleProductSort} align="right" />
                      <TableHead>Status</TableHead>
                      <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {productsLoading ? (
                      <TableRow>
                        <TableCell colSpan={8} className="h-32 text-center">
                          <div className="flex items-center justify-center gap-2">
                            <Icon icon="solar:refresh-bold" className="h-5 w-5 animate-spin" />
                            Loading products...
                          </div>
                        </TableCell>
                      </TableRow>
                    ) : products.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={8} className="h-32 text-center">
                          <div className="flex flex-col items-center gap-2 text-muted-foreground">
                            <Icon icon="solar:box-bold-duotone" className="h-10 w-10" />
                            <p>No products found</p>
                          </div>
                        </TableCell>
                      </TableRow>
                    ) : (
                      sortedProducts.map((product) => {
                        const status = getStockStatus(product)
                        return (
                          <TableRow key={product.id} className="group hover:bg-muted/50">
                            <TableCell>
                              <div className="flex items-center gap-3">
                                <div className="h-10 w-10 rounded-lg bg-muted flex items-center justify-center overflow-hidden border">
                                  {product.image ? (
                                    <img src={product.image} alt="" className="h-full w-full object-cover" />
                                  ) : (
                                    <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-muted-foreground" />
                                  )}
                                </div>
                                <div>
                                  <p className="font-medium">{product.name}</p>
                                </div>
                              </div>
                            </TableCell>
                            <TableCell className="text-muted-foreground">{product.sku}</TableCell>
                            <TableCell className="text-muted-foreground">{product.category?.name || '-'}</TableCell>
                            <TableCell className="text-right">{formatCurrency(product.cost)}</TableCell>
                            <TableCell className="text-right font-medium">{formatCurrency(product.price)}</TableCell>
                            <TableCell className="text-right">
                              <span className="font-semibold text-lg">{product.stock_quantity ?? 0}</span>
                            </TableCell>
                            <TableCell>
                              <Badge variant="outline" className={status.color}>
                                {status.label}
                              </Badge>
                            </TableCell>
                            <TableCell className="text-right">
                              <div className="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <Button size="sm" variant="ghost" onClick={() => openDialog('in', product.id)} title="Stock In">
                                  <Icon icon="solar:add-circle-bold" className="h-4 w-4 text-primary" />
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => openDialog('out', product.id)} title="Stock Out">
                                  <Icon icon="solar:minus-circle-bold" className="h-4 w-4 text-rose-500" />
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => openDialog('adjust', product.id)} title="Adjust">
                                  <Icon icon="solar:tuning-2-bold" className="h-4 w-4 text-violet-500" />
                                </Button>
                              </div>
                            </TableCell>
                          </TableRow>
                        )
                      })
                    )}
                  </TableBody>
                </Table>
                {renderPagination(productsData, productsPage, setProductsPage, 'products', productsPageSize, setProductsPageSize)}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Movements Tab */}
        <TabsContent value="movements" className="space-y-4">
          <Card>
            <CardHeader className="pb-4">
              <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                  <CardTitle>Stock Movement History</CardTitle>
                  <CardDescription>Track all inventory changes</CardDescription>
                </div>
                <div className="flex flex-wrap gap-2">
                  <div className="relative">
                    <Icon icon="solar:magnifer-bold" className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      placeholder="Search movements..."
                      value={searchMovements}
                      onChange={(e) => setSearchMovements(e.target.value)}
                      className="pl-9 w-[200px]"
                    />
                  </div>
                  <Select value={filterType} onValueChange={setFilterType}>
                    <SelectTrigger className="w-[140px]">
                      <SelectValue placeholder="Type" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">All Types</SelectItem>
                      <SelectItem value="in">Stock In</SelectItem>
                      <SelectItem value="out">Stock Out</SelectItem>
                      <SelectItem value="adjustment">Adjustment</SelectItem>
                      <SelectItem value="purchase">Purchase</SelectItem>
                      <SelectItem value="sale">Sale</SelectItem>
                    </SelectContent>
                  </Select>
                  <Select value={filterWarehouse} onValueChange={setFilterWarehouse}>
                    <SelectTrigger className="w-[160px]">
                      <SelectValue placeholder="Warehouse" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">All Warehouses</SelectItem>
                      {warehouses?.map((w) => (
                        <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <div className="rounded-lg border">
                <Table>
                  <TableHeader>
                    <TableRow className="bg-muted/50">
                      <SortableHead label="Product" sortKey="product.name" activeKey={movementSortKey} direction={movementSortDir} onSort={toggleMovementSort} className="w-[200px]" />
                      <SortableHead label="Type" sortKey="type" activeKey={movementSortKey} direction={movementSortDir} onSort={toggleMovementSort} />
                      <SortableHead label="Qty Change" sortKey="quantity" activeKey={movementSortKey} direction={movementSortDir} onSort={toggleMovementSort} align="right" />
                      <SortableHead label="Balance" sortKey="balance_after" activeKey={movementSortKey} direction={movementSortDir} onSort={toggleMovementSort} align="right" />
                      <SortableHead label="Reference" sortKey="reference" activeKey={movementSortKey} direction={movementSortDir} onSort={toggleMovementSort} />
                      <SortableHead label="By" sortKey="created_by.name" activeKey={movementSortKey} direction={movementSortDir} onSort={toggleMovementSort} />
                      <SortableHead label="Date" sortKey="created_at" activeKey={movementSortKey} direction={movementSortDir} onSort={toggleMovementSort} />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {movementsLoading ? (
                      <TableRow>
                        <TableCell colSpan={7} className="h-32 text-center">
                          <div className="flex items-center justify-center gap-2">
                            <Icon icon="solar:refresh-bold" className="h-5 w-5 animate-spin" />
                            Loading movements...
                          </div>
                        </TableCell>
                      </TableRow>
                    ) : movements.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={7} className="h-32 text-center">
                          <div className="flex flex-col items-center gap-2 text-muted-foreground">
                            <Icon icon="solar:history-bold-duotone" className="h-10 w-10" />
                            <p>No stock movements found</p>
                          </div>
                        </TableCell>
                      </TableRow>
                    ) : (
                      sortedMovements.map((movement: StockMovement) => {
                        const config = getTypeConfig(movement.type)
                        const isIncoming = ['in', 'purchase', 'transfer_in', 'return'].includes(movement.type)
                        return (
                          <TableRow key={movement.id} className="group hover:bg-muted/50">
                            <TableCell>
                              <div className="flex items-center gap-3">
                                <div className="h-10 w-10 rounded-lg bg-muted flex items-center justify-center overflow-hidden">
                                  {movement.product?.image ? (
                                    <img src={movement.product.image} alt="" className="h-full w-full object-cover" />
                                  ) : (
                                    <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-muted-foreground" />
                                  )}
                                </div>
                                <div>
                                  <p className="font-medium">{movement.product?.name}</p>
                                  <p className="text-xs text-muted-foreground">{movement.product?.sku}</p>
                                </div>
                              </div>
                            </TableCell>
                            <TableCell>
                              <Badge variant="outline" className={`${config.color} border gap-1`}>
                                <Icon icon={config.icon} className="h-3 w-3" />
                                {config.label}
                              </Badge>
                            </TableCell>
                            <TableCell className="text-right">
                              <span className={`font-semibold ${isIncoming ? 'text-primary' : 'text-rose-600'}`}>
                                {isIncoming ? '+' : '-'}{movement.quantity}
                              </span>
                            </TableCell>
                            <TableCell className="text-right font-medium">{movement.balance_after}</TableCell>
                            <TableCell className="text-muted-foreground">{movement.reference || '-'}</TableCell>
                            <TableCell className="text-muted-foreground">{movement.created_by?.name || '-'}</TableCell>
                            <TableCell className="text-muted-foreground">
                              {formatDate(movement.created_at)}
                            </TableCell>
                          </TableRow>
                        )
                      })
                    )}
                  </TableBody>
                </Table>
                {renderPagination(movementsData, movementsPage, setMovementsPage, 'movements', movementsPageSize, setMovementsPageSize)}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Low Stock Tab */}
        <TabsContent value="low-stock" className="space-y-4">
          <Card>
            <CardHeader>
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-500/10">
                  <Icon icon="solar:danger-triangle-bold" className="h-5 w-5 text-amber-500" />
                </div>
                <div>
                  <CardTitle>Low Stock Items</CardTitle>
                  <CardDescription>Products below minimum threshold that need restocking</CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {lowStockLoading ? (
                <div className="flex items-center justify-center py-12">
                  <div className="flex items-center gap-2 text-muted-foreground">
                    <Icon icon="solar:refresh-bold" className="h-5 w-5 animate-spin" />
                    Loading low stock items...
                  </div>
                </div>
              ) : lowStockItems.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 text-center">
                  <div className="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 mb-4">
                    <Icon icon="solar:check-circle-bold" className="h-8 w-8 text-primary" />
                  </div>
                  <h3 className="text-lg font-semibold">All Stock Levels Healthy</h3>
                  <p className="text-muted-foreground mt-1">No products are below their minimum threshold</p>
                </div>
              ) : (
                <div className="grid gap-3">
                  {lowStockItems.map((item: any) => (
                    <div key={item.id} className="flex items-center justify-between p-4 rounded-lg border bg-amber-500/5 border-amber-500/20">
                      <div className="flex items-center gap-4">
                        <div className="h-12 w-12 rounded-lg bg-muted flex items-center justify-center overflow-hidden">
                          {item.image ? (
                            <img src={item.image} alt="" className="h-full w-full object-cover" />
                          ) : (
                            <Icon icon="solar:box-bold-duotone" className="h-6 w-6 text-muted-foreground" />
                          )}
                        </div>
                        <div>
                          <p className="font-medium">{item.name}</p>
                          <p className="text-sm text-muted-foreground">{item.sku}</p>
                        </div>
                      </div>
                      <div className="flex items-center gap-6">
                        <div className="text-right">
                          <p className="text-sm text-muted-foreground">Current Stock</p>
                          <p className="text-xl font-bold text-amber-600">{item.stock_quantity}</p>
                        </div>
                        <div className="text-right">
                          <p className="text-sm text-muted-foreground">Min Threshold</p>
                          <p className="text-lg font-medium">{item.min_stock_threshold || item.reorder_level || 10}</p>
                        </div>
                        <Button size="sm" onClick={() => openDialog('in', item.id)}>
                          <Icon icon="solar:add-circle-bold" className="mr-1 h-4 w-4" />
                          Restock
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
              {renderPagination(lowStockData, lowStockPage, setLowStockPage, 'low stock items', lowStockPageSize, setLowStockPageSize)}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Out of Stock Tab */}
        <TabsContent value="out-of-stock" className="space-y-4">
          <Card>
            <CardHeader>
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-rose-500/10">
                  <Icon icon="solar:close-circle-bold" className="h-5 w-5 text-rose-500" />
                </div>
                <div>
                  <CardTitle>Out of Stock Items</CardTitle>
                  <CardDescription>Products with zero inventory that need immediate attention</CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {outOfStockLoading ? (
                <div className="flex items-center justify-center py-12">
                  <div className="flex items-center gap-2 text-muted-foreground">
                    <Icon icon="solar:refresh-bold" className="h-5 w-5 animate-spin" />
                    Loading out of stock items...
                  </div>
                </div>
              ) : outOfStockItems.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 text-center">
                  <div className="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 mb-4">
                    <Icon icon="solar:check-circle-bold" className="h-8 w-8 text-primary" />
                  </div>
                  <h3 className="text-lg font-semibold">No Out of Stock Items</h3>
                  <p className="text-muted-foreground mt-1">All products have available inventory</p>
                </div>
              ) : (
                <div className="grid gap-3">
                  {outOfStockItems.map((item: any) => (
                    <div key={item.id} className="flex items-center justify-between p-4 rounded-lg border bg-rose-500/5 border-rose-500/20">
                      <div className="flex items-center gap-4">
                        <div className="h-12 w-12 rounded-lg bg-muted flex items-center justify-center overflow-hidden">
                          {item.image ? (
                            <img src={item.image} alt="" className="h-full w-full object-cover" />
                          ) : (
                            <Icon icon="solar:box-bold-duotone" className="h-6 w-6 text-muted-foreground" />
                          )}
                        </div>
                        <div>
                          <p className="font-medium">{item.name}</p>
                          <p className="text-sm text-muted-foreground">{item.sku}</p>
                        </div>
                      </div>
                      <div className="flex items-center gap-6">
                        <div className="text-right">
                          <Badge variant="destructive" className="gap-1">
                            <Icon icon="solar:close-circle-bold" className="h-3 w-3" />
                            Out of Stock
                          </Badge>
                        </div>
                        <div className="text-right">
                          <p className="text-sm text-muted-foreground">Cost</p>
                          <p className="font-medium">{formatCurrency(item.cost || 0)}</p>
                        </div>
                        <Button size="sm" className="bg-primary hover:bg-primary/90" onClick={() => openDialog('in', item.id)}>
                          <Icon icon="solar:add-circle-bold" className="mr-1 h-4 w-4" />
                          Add Stock
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
              {renderPagination(outOfStockData, outOfStockPage, setOutOfStockPage, 'out of stock items', outOfStockPageSize, setOutOfStockPageSize)}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Stock Operation Dialog */}
      <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[500px]">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              {operationType === 'in' && (
                <>
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                    <Icon icon="solar:add-circle-bold" className="h-4 w-4 text-primary" />
                  </div>
                  Receive Stock
                </>
              )}
              {operationType === 'out' && (
                <>
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-500/10">
                    <Icon icon="solar:minus-circle-bold" className="h-4 w-4 text-rose-500" />
                  </div>
                  Remove Stock
                </>
              )}
              {operationType === 'adjust' && (
                <>
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-500/10">
                    <Icon icon="solar:tuning-2-bold" className="h-4 w-4 text-violet-500" />
                  </div>
                  Post Stock Count
                </>
              )}
            </DialogTitle>
            <DialogDescription>
              {operationType === 'in' && 'Record a stock receipt for this product and warehouse.'}
              {operationType === 'out' && 'Record a stock removal for this product and warehouse.'}
              {operationType === 'adjust' && 'Reconcile the recorded quantity to the counted quantity.'}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            {/* Product Selection */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Product</label>
              <Select value={productId} onValueChange={(value) => { setProductId(value); setBatchNumber(''); setLotNumber(''); setExpiryDate(''); setSerialNumbers(''); }}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a product" />
                </SelectTrigger>
                <SelectContent>
                  {dialogProducts.map((product) => (
                    <SelectItem key={product.id} value={String(product.id)}>
                      <div className="flex items-center gap-2">
                        <span>{product.name}</span>
                        <span className="text-muted-foreground">({product.sku})</span>
                        <Badge variant="outline" className="ml-2">{product.stock_quantity} in stock</Badge>
                      </div>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Warehouse Selection */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Warehouse</label>
              <Select value={warehouseId} onValueChange={setWarehouseId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a warehouse" />
                </SelectTrigger>
                <SelectContent>
                  {warehouses?.map((warehouse) => (
                    <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                      {warehouse.name} ({warehouse.code})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Selected Product Info */}
            {selectedProduct && (
              <div className="rounded-lg border bg-muted/50 p-3">
                <div className="flex items-center gap-3">
                  <div className="h-12 w-12 rounded-lg bg-background flex items-center justify-center overflow-hidden">
                    {selectedProduct.image ? (
                      <img src={selectedProduct.image} alt="" className="h-full w-full object-cover" />
                    ) : (
                      <Icon icon="solar:box-bold-duotone" className="h-6 w-6 text-muted-foreground" />
                    )}
                  </div>
                  <div className="flex-1">
                    <p className="font-medium">{selectedProduct.name}</p>
                    <p className="text-sm text-muted-foreground">{selectedProduct.sku}</p>
                  </div>
                  <div className="text-right">
                    <p className="text-sm text-muted-foreground">Current Stock</p>
                    <p className="text-xl font-bold">{selectedProduct.stock_quantity}</p>
                  </div>
                </div>
              </div>
            )}

            {/* Quantity Input */}
            {operationType !== 'adjust' ? (
              <div className="space-y-2">
                <label className="text-sm font-medium">Quantity</label>
                <Input
                  type="number"
                  min="1"
                  placeholder="Enter quantity"
                  value={quantity}
                  onChange={(e) => setQuantity(e.target.value)}
                />
              </div>
            ) : (
              <>
                <div className="space-y-2">
                  <label className="text-sm font-medium">Counted Quantity</label>
                  <Input
                    type="number"
                    min="0"
                    placeholder="Enter physically counted stock"
                    value={newQuantity}
                    onChange={(e) => setNewQuantity(e.target.value)}
                  />
                </div>
              </>
            )}

            {operationType === 'in' && selectedProduct?.tracking_method === 'batch' && (
              <div className="rounded-lg border bg-muted/30 p-4 space-y-4">
                <div>
                  <p className="font-medium">Batch/Lot details</p>
                  <p className="text-sm text-muted-foreground">Create a new batch or receive more quantity into an existing batch number.</p>
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <label className="text-sm font-medium">Batch/Lot Number *</label>
                    <Input value={batchNumber} onChange={(event) => { setBatchNumber(event.target.value); if (!lotNumber) setLotNumber(event.target.value); }} placeholder="e.g., LOT-2026-001" />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium">Expiry Date</label>
                    <Input type="date" value={expiryDate} onChange={(event) => setExpiryDate(event.target.value)} />
                  </div>
                </div>
              </div>
            )}

            {operationType === 'in' && selectedProduct?.tracking_method === 'serial' && (
              <div className="rounded-lg border bg-muted/30 p-4 space-y-2">
                <label className="text-sm font-medium">Serial Numbers *</label>
                <Textarea value={serialNumbers} onChange={(event) => setSerialNumbers(event.target.value)} placeholder="Enter one serial per line or separate with commas" rows={5} />
                <p className="text-xs text-muted-foreground">Entered {serialNumbers.split(/[\n,]+/).map((value) => value.trim()).filter(Boolean).length} of {parseInt(quantity) || 0} required serial numbers.</p>
              </div>
            )}

            <div className="space-y-2">
              <label className="text-sm font-medium">Business Reason</label>
              <Select value={reasonId} onValueChange={setReasonId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select why stock is changing" />
                </SelectTrigger>
                <SelectContent>
                  {availableReasons.map((reason) => (
                    <SelectItem key={reason.id} value={String(reason.id)}>
                      {reason.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Reference (for Stock In/Out) */}
            {operationType !== 'adjust' && (
              <div className="space-y-2">
                <label className="text-sm font-medium">Reference (Optional)</label>
                <Input
                  placeholder="e.g., PO-001, Invoice #123"
                  value={reference}
                  onChange={(e) => setReference(e.target.value)}
                />
              </div>
            )}

            {/* Notes */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Notes (Optional)</label>
              <Textarea
                placeholder="Add any additional notes..."
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                rows={3}
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={closeDialog}>Cancel</Button>
            <Button
              onClick={handleSubmit}
              disabled={isSubmitting}
              className={
                operationType === 'in' ? 'bg-primary hover:bg-primary/90' :
                operationType === 'out' ? 'bg-rose-600 hover:bg-rose-700' :
                'bg-violet-600 hover:bg-violet-700'
              }
            >
              {isSubmitting ? (
                <>
                  <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
                  Processing...
                </>
              ) : (
                operationType === 'in' ? 'Receive Stock' : operationType === 'out' ? 'Remove Stock' : 'Post Stock Count'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
