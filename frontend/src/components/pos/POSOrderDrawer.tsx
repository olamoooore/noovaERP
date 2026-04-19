import { useEffect, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Separator } from '@/components/ui/separator'
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
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  SlideOver,
  SlideOverContent,
  SlideOverHeader,
  SlideOverTitle,
} from '@/components/ui/slide-over'
import { formatCurrency, formatDateTime, getApiErrorMessage, resolveImageUrl } from '@/lib/utils'
import { usePosSessionStore, CartItem } from '@/store/posSessionStore'
import { useToast } from '@/hooks/use-toast'

interface POSOrderDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  initialStatusFilter?: 'all' | 'draft' | 'paid' | 'void'
}

interface PosOrderDetail {
  id: number
  order_number: string
  status: string
  created_at: string
  paid_at?: string | null
  subtotal: number
  tax_amount: number
  discount_amount: number
  loyalty_discount?: number
  total: number
  notes?: string | null
  receipt_number?: string | null
  cashier_name?: string | null
  currency?: string | null
  customer_id?: number | null
  is_return?: boolean
  customer?: {
    name: string
    email?: string
    phone?: string
  }
  terminal?: {
    name?: string
  }
  session?: {
    name?: string
  }
  created_by?: number | null
  createdBy?: {
    name?: string
  }
  payments?: {
    id: number
    method?: string | null
    amount: number
    reference?: string | null
    notes?: string | null
    payment_method?: {
      name?: string | null
    }
    received_by?: number | null
    receivedBy?: {
      name?: string | null
    }
  }[]
  items?: {
    id: number
    name: string
    sku?: string | null
    quantity: number
    unit_price: number
    tax_rate?: number
    tax_amount?: number
    line_total: number
    product?: {
      name?: string
      image?: string | null
    }
    variant?: {
      name?: string
      image?: string | null
    }
  }[]
}

interface Invoice {
  id: number
  invoice_number: string
  invoice_date: string
  due_date: string
  status: string
  subtotal: number
  tax_amount: number
  discount_amount: number
  total: number
  paid_amount: number
  balance: number
  notes?: string
  customer?: {
    name: string
    email?: string
    phone?: string
  }
  lines?: {
    id: number
    description: string
    quantity: number
    unit_price: number
    line_total: number
    product?: {
      name?: string
      image?: string | null
    }
  }[]
}

export function POSOrderDrawer({ open, onOpenChange, initialStatusFilter = 'all' }: POSOrderDrawerProps) {
  const { toast } = useToast()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('all')
  const [selectedOrder, setSelectedOrder] = useState<PosOrderDetail | null>(null)
  const [orderModalOpen, setOrderModalOpen] = useState(false)
  const [loadingOrderDetails, setLoadingOrderDetails] = useState(false)
  const [downloadingInvoice, setDownloadingInvoice] = useState(false)
  const [actingOrderId, setActingOrderId] = useState<number | null>(null)

  useEffect(() => {
    if (open) {
      setStatusFilter(initialStatusFilter)
    }
  }, [initialStatusFilter, open])
  
  // Store actions
  const setCart = usePosSessionStore((s) => s.setCart)
  const setDiscount = usePosSessionStore((s) => s.setDiscount)
  const setCustomerId = usePosSessionStore((s) => s.setCustomerId)

  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['pos-orders', search, statusFilter, open], 
    queryFn: async () => {
      const params: Record<string, any> = { per_page: 50 }
      if (search) params.search = search
      if (statusFilter !== 'all') params.status = statusFilter
      const response = await api.get('/pos/orders', { params })
      return response.data
    },
    enabled: open, 
  })

  const orderActionMutation = useMutation({
    mutationFn: async ({ orderId, action }: { orderId: number; action: 'cancel' | 'void' | 'refund' }) => {
      if (action === 'refund') {
        return api.post(`/pos/orders/${orderId}/refund`, {
          notes: 'Refunded from POS orders list',
        })
      }

      return api.post(`/pos/orders/${orderId}/${action}`, {
        reason: action === 'cancel' ? 'Cancelled from POS orders list' : 'Voided from POS orders list',
      })
    },
    onSuccess: (_response, variables) => {
      const messages = {
        cancel: {
          title: 'Order Cancelled',
          description: 'Draft order has been cancelled.',
        },
        void: {
          title: 'Order Voided',
          description: 'Order has been voided successfully.',
        },
        refund: {
          title: 'Order Refunded',
          description: 'Refund return has been created successfully.',
        },
      } as const

      toast(messages[variables.action])
      queryClient.invalidateQueries({ queryKey: ['pos-orders'] })
      if (selectedOrder?.id === variables.orderId) {
        setOrderModalOpen(false)
        setSelectedOrder(null)
      }
    },
    onError: (error: any, variables) => {
      const labels = {
        cancel: 'Cancel Failed',
        void: 'Void Failed',
        refund: 'Refund Failed',
      } as const

      toast({
        title: labels[variables.action],
        description: getApiErrorMessage(error, 'The order action could not be completed.'),
        variant: 'destructive',
      })
    },
    onSettled: () => {
      setActingOrderId(null)
    },
  })

  const orders = data?.data || []

  const runOrderAction = async (
    order: PosOrderDetail,
    action: 'cancel' | 'void' | 'refund',
    confirmationMessage: string,
  ) => {
    if (!window.confirm(confirmationMessage)) {
      return
    }

    setActingOrderId(order.id)
    await orderActionMutation.mutateAsync({ orderId: order.id, action })
  }

  const handleLoadOrder = async (order: any) => {
    if (order.status === 'paid') {
       toast({
         title: "Cannot Load Paid Order",
         description: "Only draft orders can be loaded for editing.",
         variant: "destructive"
       })
       return
    }

    try {
      // Map items to CartItem
      const cartItems: CartItem[] = order.items.map((item: any) => ({
        id: item.product_variant_id || item.product_id,
        name: item.name,
        price: Number(item.unit_price),
        sku: item.sku,
        image: item.variant?.image || item.product?.image,
        quantity: item.quantity,
        line_subtotal: Number(item.line_total) - Number(item.tax_amount), 
        line_total: Number(item.line_total),
        tax_amount: Number(item.tax_amount),
        tax_rate: Number(item.tax_rate),
      }))

      setCart(cartItems)
      
      if (order.discount_type) {
          setDiscount({
              type: order.discount_type,
              value: Number(order.discount_value)
          })
      } else {
          setDiscount({ type: 'fixed', value: 0 })
      }

      if (order.notes && order.notes.includes('Customer ID: ')) {
          const cid = order.notes.split('Customer ID: ')[1].split(' ')[0]
          setCustomerId(cid)
      } else {
          setCustomerId('walk-in')
      }

      toast({
        title: "Order Loaded",
        description: `Order #${order.order_number} loaded to cart.`,
      })
      window.dispatchEvent(new CustomEvent('pos:order-loaded', {
        detail: {
          externalId: order.external_id ?? null,
          orderNumber: order.order_number ?? null,
        },
      }))
      onOpenChange(false)
    } catch (e) {
      console.error(e)
      toast({
        title: "Error Loading Order",
        description: "Could not load order details.",
        variant: "destructive"
      })
    }
  }
  
  const handlePrint = async (order: any) => {
      toast({ title: "Printing Receipt", description: `Preparing receipt for ${order.order_number}...` })

      try {
        const response = await api.get(`/pos/orders/${order.id}/receipt`, {
          responseType: 'blob'
        })

        const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }))
        const printWindow = window.open(url, '_blank')

        if (!printWindow) {
          window.URL.revokeObjectURL(url)
          toast({
            title: "Print Blocked",
            description: "Allow pop-ups for this site and try again.",
            variant: "destructive"
          })
          return
        }

        const cleanup = () => window.setTimeout(() => window.URL.revokeObjectURL(url), 60000)
        printWindow.addEventListener('load', () => {
          printWindow.focus()
          printWindow.print()
          cleanup()
        }, { once: true })

        toast({
          title: "Receipt Ready",
          description: `Opened receipt for ${order.order_number}`
        })
      } catch (error) {
        console.error('Failed to print receipt:', error)
        toast({
          title: "Error",
          description: "Failed to prepare the receipt for printing. Please try again.",
          variant: "destructive"
        })
      }
  }

  const handleViewOrder = async (order: any) => {
      setLoadingOrderDetails(true)
      try {
        const response = await api.get(`/pos/orders/${order.id}`)
        setSelectedOrder(response.data)
        setOrderModalOpen(true)
      } catch (error: any) {
        toast({ 
          title: "Unable to Load Order", 
          description: getApiErrorMessage(error, 'Could not load order details.'), 
          variant: "destructive" 
        })
      } finally {
        setLoadingOrderDetails(false)
      }
  }

  const handleDownloadInvoice = async () => {
      if (!selectedOrder) return
      
      try {
        setDownloadingInvoice(true)
        const invoiceResponse = await api.get(`/pos/orders/${selectedOrder.id}/invoice`)
        const invoice = invoiceResponse.data.data as Invoice

        const response = await api.get(`/invoices/${invoice.id}/pdf`, {
          responseType: 'blob'
        })
        
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `invoice-${invoice.invoice_number}.pdf`)
        document.body.appendChild(link)
        link.click()
        link.parentNode?.removeChild(link)
        window.URL.revokeObjectURL(url)
        
        toast({ 
          title: "Success", 
          description: `Invoice downloaded` 
        })
      } catch (error) {
        console.error('Failed to download invoice:', error)
        toast({ 
          title: "Error", 
          description: "Failed to download invoice.", 
          variant: "destructive" 
        })
      } finally {
        setDownloadingInvoice(false)
      }
  }

  const handleGenerateReceipt = async (order: any) => {
      toast({ title: "Generating PDF", description: `Downloading receipt for ${order.order_number}...` })
      
      try {
        const response = await api.get(`/pos/orders/${order.id}/receipt`, {
          responseType: 'blob'
        })
        
        // Create a download link
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `receipt-${order.order_number}.pdf`)
        document.body.appendChild(link)
        link.click()
        link.parentNode?.removeChild(link)
        window.URL.revokeObjectURL(url)
        
        toast({ 
          title: "Success", 
          description: `Receipt downloaded for ${order.order_number}` 
        })
      } catch (error) {
        console.error('Failed to download receipt:', error)
        toast({ 
          title: "Error", 
          description: "Failed to download receipt. Please try again.", 
          variant: "destructive" 
        })
      }
  }

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'paid': return <Badge className="bg-green-500">Paid</Badge>
      case 'draft': return <Badge variant="secondary">Draft</Badge>
      case 'void': return <Badge variant="destructive">Voided</Badge>
      case 'cancelled': return <Badge variant="destructive">Cancelled</Badge>
      default: return <Badge variant="outline">{status}</Badge>
    }
  }

  const isOrderActionPending = (orderId: number) => actingOrderId === orderId && orderActionMutation.isPending

  const formatPaymentLabel = (payment: NonNullable<PosOrderDetail['payments']>[number]) => {
    const source = payment.payment_method?.name || payment.method || 'Unknown'
    return source
      .split(/[_\s]+/)
      .filter(Boolean)
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
      .join(' ')
  }

  const getPaymentSummary = (order: PosOrderDetail | null) => {
    if (!order?.payments?.length) {
      return order?.status === 'paid' ? 'Paid' : 'Unpaid'
    }

    return order.payments.map(formatPaymentLabel).join(', ')
  }

  const getOrderPaidAmount = (order: PosOrderDetail | null) => {
    return order?.payments?.reduce((sum, payment) => sum + Number(payment.amount || 0), 0) ?? 0
  }

  return (
    <SlideOver open={open} onOpenChange={onOpenChange}>
      <SlideOverContent className="flex flex-col gap-0 p-0 sm:max-w-[500px]">
        <SlideOverHeader className="p-4 border-b bg-muted/10">
          <SlideOverTitle className="flex items-center gap-2">
              <Icon icon="solar:document-text-bold" className="h-5 w-5" />
              Orders
          </SlideOverTitle>
        </SlideOverHeader>

        <div className="p-4 border-b space-y-3 bg-muted/5">
          <div className="flex gap-2">
            <div className="relative flex-1">
                <Icon icon="solar:magnifer-bold" className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                placeholder="Search order #..."
                className="pl-9 bg-background"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                />
            </div>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-[120px] bg-background">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All</SelectItem>
                <SelectItem value="draft">Drafts</SelectItem>
                <SelectItem value="paid">Paid</SelectItem>
                <SelectItem value="void">Voided</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <ScrollArea className="flex-1 p-4 bg-muted/5">
          {isLoading ? (
            <div className="flex justify-center py-8">
              <Icon icon="solar:refresh-bold" className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : orders.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-muted-foreground">
              <Icon icon="solar:box-minimalistic-bold" className="h-12 w-12 mb-2 opacity-20" />
              <p>No orders found</p>
            </div>
          ) : (
            <div className="space-y-3">
              {orders.map((order: any) => (
                <div
                  key={order.id}
                  className="rounded-2xl border border-border/70 bg-gradient-to-br from-background via-background to-muted/40 p-4 shadow-[0_14px_36px_-28px_rgba(15,23,42,0.45)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_22px_42px_-28px_rgba(15,23,42,0.55)]"
                >
                  <div className="mb-4 flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                      <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary/12 text-primary ring-1 ring-primary/10">
                        <Icon icon="solar:bag-3-bold" className="h-5 w-5 shrink-0" />
                      </div>
                      <div className="min-w-0">
                        <h4 className="truncate text-sm font-semibold tracking-tight">{order.order_number}</h4>
                        <p className="mt-1 text-xs text-muted-foreground">
                          {new Date(order.created_at).toLocaleString()}
                        </p>
                      </div>
                    </div>
                    <div className="shrink-0">{getStatusBadge(order.status)}</div>
                  </div>

                  <div className="mb-4 grid grid-cols-3 gap-2 rounded-2xl border border-border/60 bg-background/80 p-2 text-sm backdrop-blur-sm">
                    <div className="rounded-xl bg-muted/50 px-3 py-2 text-center">
                      <span className="block text-[11px] uppercase tracking-[0.16em] text-muted-foreground">Items</span>
                      <span className="mt-1 block font-semibold">{order.items?.length || 0}</span>
                    </div>
                    <div className="rounded-xl bg-primary/5 px-3 py-2 text-center ring-1 ring-primary/10">
                      <span className="block text-[11px] uppercase tracking-[0.16em] text-muted-foreground">Total</span>
                      <span className="mt-1 block font-bold text-primary">{formatCurrency(Number(order.total))}</span>
                    </div>
                    <div className="rounded-xl bg-muted/50 px-3 py-2 text-center">
                      <span className="block text-[11px] uppercase tracking-[0.16em] text-muted-foreground">Discount</span>
                      <span className="mt-1 block font-semibold">
                        {order.discount_amount > 0 ? formatCurrency(Number(order.discount_amount)) : '-'}
                      </span>
                    </div>
                  </div>

                  <div className="rounded-2xl border border-border/60 bg-background/85 p-2 backdrop-blur-sm">
                    <div className="space-y-2">
                      {order.status === 'draft' ? (
                        <>
                          <Button
                            className="h-11 w-full justify-center rounded-xl px-4 text-sm font-semibold shadow-sm"
                            onClick={() => handleLoadOrder(order)}
                            disabled={isOrderActionPending(order.id)}
                          >
                            <Icon icon="solar:upload-track-bold" className="h-4 w-4 shrink-0" />
                            <span className="ml-2 truncate">Load Draft</span>
                          </Button>
                          <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_44px_44px] gap-2">
                            <Button
                              variant="outline"
                              className="h-11 justify-center rounded-xl px-3 text-sm"
                              disabled={isOrderActionPending(order.id)}
                              onClick={() => runOrderAction(order, 'cancel', `Cancel draft ${order.order_number}?`)}
                            >
                              <Icon icon="solar:close-circle-bold" className="h-4 w-4 shrink-0" />
                              <span className="ml-2 truncate">Cancel</span>
                            </Button>
                            <Button
                              variant="outline"
                              className="h-11 justify-center rounded-xl px-3 text-sm"
                              disabled={isOrderActionPending(order.id)}
                              onClick={() => runOrderAction(order, 'void', `Void draft ${order.order_number}?`)}
                            >
                              <Icon icon="solar:danger-triangle-bold" className="h-4 w-4 shrink-0" />
                              <span className="ml-2 truncate">Void</span>
                            </Button>
                            <Button
                              variant="outline"
                              size="icon"
                              className="h-11 w-11 rounded-xl"
                              onClick={() => handleGenerateReceipt(order)}
                              disabled={isOrderActionPending(order.id)}
                              title="Generate Receipt (PDF)"
                            >
                              <Icon icon="solar:file-text-bold" className="h-4 w-4 shrink-0" />
                            </Button>
                            <Button
                              variant="outline"
                              size="icon"
                              className="h-11 w-11 rounded-xl"
                              onClick={() => handlePrint(order)}
                              disabled={isOrderActionPending(order.id)}
                              title="Print Receipt"
                            >
                              <Icon icon="solar:printer-bold" className="h-4 w-4 shrink-0" />
                            </Button>
                          </div>
                        </>
                      ) : order.status === 'paid' ? (
                        <>
                          <Button
                            variant="default"
                            className="h-11 w-full justify-center rounded-xl px-4 text-sm font-semibold shadow-sm"
                            onClick={() => handleViewOrder(order)}
                            disabled={loadingOrderDetails || isOrderActionPending(order.id)}
                          >
                            <Icon icon="solar:eye-bold" className="h-4 w-4 shrink-0" />
                            <span className="ml-2 truncate">Details</span>
                          </Button>
                          <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_44px_44px] gap-2">
                            <Button
                              variant="outline"
                              className="h-11 justify-center rounded-xl px-3 text-sm"
                              disabled={isOrderActionPending(order.id)}
                              onClick={() => runOrderAction(order, 'refund', `Refund paid order ${order.order_number}? This creates a full return.`)}
                            >
                              <Icon icon="mdi:cash-refund" className="h-4 w-4 shrink-0" />
                              <span className="ml-2 truncate">Refund</span>
                            </Button>
                            <Button
                              variant="outline"
                              className="h-11 justify-center rounded-xl px-3 text-sm"
                              disabled={isOrderActionPending(order.id)}
                              onClick={() => runOrderAction(order, 'void', `Void paid order ${order.order_number}? This will create a full return and mark the order void.`)}
                            >
                              <Icon icon="solar:danger-triangle-bold" className="h-4 w-4 shrink-0" />
                              <span className="ml-2 truncate">Void</span>
                            </Button>
                            <Button
                              variant="outline"
                              size="icon"
                              className="h-11 w-11 rounded-xl"
                              onClick={() => handleGenerateReceipt(order)}
                              disabled={isOrderActionPending(order.id)}
                              title="Generate Receipt (PDF)"
                            >
                              <Icon icon="solar:file-text-bold" className="h-4 w-4 shrink-0" />
                            </Button>
                            <Button
                              variant="outline"
                              size="icon"
                              className="h-11 w-11 rounded-xl"
                              onClick={() => handlePrint(order)}
                              disabled={isOrderActionPending(order.id)}
                              title="Print Receipt"
                            >
                              <Icon icon="solar:printer-bold" className="h-4 w-4 shrink-0" />
                            </Button>
                          </div>
                        </>
                      ) : (
                        <div className="grid grid-cols-[minmax(0,1fr)_44px_44px] gap-2">
                          <Button
                            variant="default"
                            className="h-11 justify-center rounded-xl px-4 text-sm font-semibold shadow-sm"
                            onClick={() => handleViewOrder(order)}
                            disabled={loadingOrderDetails || isOrderActionPending(order.id)}
                          >
                            <Icon icon="solar:eye-bold" className="h-4 w-4 shrink-0" />
                            <span className="ml-2 truncate">Details</span>
                          </Button>
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-11 w-11 rounded-xl"
                            onClick={() => handleGenerateReceipt(order)}
                            disabled={isOrderActionPending(order.id)}
                            title="Generate Receipt (PDF)"
                          >
                            <Icon icon="solar:file-text-bold" className="h-4 w-4 shrink-0" />
                          </Button>
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-11 w-11 rounded-xl"
                            onClick={() => handlePrint(order)}
                            disabled={isOrderActionPending(order.id)}
                            title="Print Receipt"
                          >
                            <Icon icon="solar:printer-bold" className="h-4 w-4 shrink-0" />
                          </Button>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </ScrollArea>
      </SlideOverContent>

      <Dialog open={orderModalOpen} onOpenChange={setOrderModalOpen}>
        <DialogContent className="sm:max-w-3xl">
          <DialogHeader>
            <DialogTitle className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <span>{selectedOrder?.order_number}</span>
                {selectedOrder?.is_return ? <Badge variant="outline">Return</Badge> : <Badge variant="outline">POS</Badge>}
              </div>
              {selectedOrder && getStatusBadge(selectedOrder.status)}
            </DialogTitle>
          </DialogHeader>

          {selectedOrder && (
            <div className="space-y-4">
              <div className="grid gap-3 md:grid-cols-3">
                <div className="rounded-lg border bg-muted/30 p-3">
                  <p className="text-xs uppercase tracking-wide text-muted-foreground">Customer</p>
                  <p className="mt-1 font-medium">{selectedOrder.customer?.name || 'Walk-in Customer'}</p>
                  {selectedOrder.customer?.email && (
                    <p className="text-sm text-muted-foreground">{selectedOrder.customer.email}</p>
                  )}
                  {selectedOrder.customer?.phone && (
                    <p className="text-sm text-muted-foreground">{selectedOrder.customer.phone}</p>
                  )}
                </div>
                <div className="rounded-lg border bg-muted/30 p-3">
                  <p className="text-xs uppercase tracking-wide text-muted-foreground">Payment Mode</p>
                  <p className="mt-1 font-medium">{getPaymentSummary(selectedOrder)}</p>
                  {selectedOrder.payments?.[0]?.reference && (
                    <p className="text-sm text-muted-foreground">Ref: {selectedOrder.payments[0].reference}</p>
                  )}
                </div>
                <div className="rounded-lg border bg-muted/30 p-3">
                  <p className="text-xs uppercase tracking-wide text-muted-foreground">Cashier</p>
                  <p className="mt-1 font-medium">{selectedOrder.cashier_name || selectedOrder.createdBy?.name || 'Unknown'}</p>
                  {selectedOrder.receipt_number && (
                    <p className="text-sm text-muted-foreground">Receipt: {selectedOrder.receipt_number}</p>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                <div>
                  <span className="text-muted-foreground">Created</span>
                  <p className="font-medium">{formatDateTime(selectedOrder.created_at)}</p>
                </div>
                <div>
                  <span className="text-muted-foreground">Paid At</span>
                  <p className="font-medium">{formatDateTime(selectedOrder.paid_at)}</p>
                </div>
                <div>
                  <span className="text-muted-foreground">Terminal</span>
                  <p className="font-medium">{selectedOrder.terminal?.name || '-'}</p>
                </div>
                <div>
                  <span className="text-muted-foreground">Session</span>
                  <p className="font-medium">{selectedOrder.session?.name || '-'}</p>
                </div>
              </div>

              <Separator />

              <div>
                <h4 className="font-medium mb-2">Order Lines</h4>
                <ScrollArea className="max-h-72">
                  <div className="space-y-2">
                    {selectedOrder.items?.map((item) => (
                      <div key={item.id} className="flex items-center justify-between gap-3 text-sm p-3 rounded border bg-muted/30">
                        <div className="flex items-center gap-3 min-w-0">
                          {item.variant?.image || item.product?.image ? (
                            <img
                              src={resolveImageUrl(item.variant?.image || item.product?.image)}
                              alt={item.name}
                              className="h-12 w-12 rounded object-cover border bg-background"
                            />
                          ) : (
                            <div className="h-12 w-12 rounded border bg-background flex items-center justify-center text-muted-foreground">
                              <Icon icon="solar:box-bold-duotone" className="h-5 w-5" />
                            </div>
                          )}
                          <div className="min-w-0">
                            <p className="font-medium truncate">{item.name}</p>
                            {item.sku && (
                              <p className="text-xs text-muted-foreground truncate">SKU: {item.sku}</p>
                            )}
                            <p className="text-xs text-muted-foreground">
                              {item.quantity} x {formatCurrency(Number(item.unit_price))}
                              {Number(item.tax_amount || 0) > 0 && ` • Tax ${formatCurrency(Number(item.tax_amount || 0))}`}
                            </p>
                          </div>
                        </div>
                        <div className="text-right shrink-0">
                          <p className="text-xs text-muted-foreground">
                            Qty {item.quantity}
                          </p>
                          <span className="font-medium">{formatCurrency(Number(item.line_total))}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                </ScrollArea>
              </div>

              <Separator />

              <div className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span>{formatCurrency(Number(selectedOrder.subtotal))}</span>
                </div>
                {Number(selectedOrder.tax_amount) > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Tax</span>
                    <span>{formatCurrency(Number(selectedOrder.tax_amount))}</span>
                  </div>
                )}
                {Number(selectedOrder.discount_amount) > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Discount</span>
                    <span>-{formatCurrency(Number(selectedOrder.discount_amount))}</span>
                  </div>
                )}
                {Number(selectedOrder.loyalty_discount || 0) > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Loyalty Discount</span>
                    <span>-{formatCurrency(Number(selectedOrder.loyalty_discount || 0))}</span>
                  </div>
                )}
                <Separator />
                <div className="flex justify-between font-bold text-lg">
                  <span>Total</span>
                  <span>{formatCurrency(Number(selectedOrder.total))}</span>
                </div>
                {selectedOrder.payments?.length ? (
                  <div className="rounded-lg border border-green-200 bg-green-50/60 p-3 text-sm">
                    <div className="mb-2 flex justify-between font-medium text-green-700">
                      <span>Payments</span>
                      <span>{formatCurrency(getOrderPaidAmount(selectedOrder))}</span>
                    </div>
                    <div className="space-y-2">
                      {selectedOrder.payments.map((payment) => (
                        <div key={payment.id} className="flex items-start justify-between gap-3 text-green-900">
                          <div>
                            <p className="font-medium">{formatPaymentLabel(payment)}</p>
                            {payment.reference && (
                              <p className="text-xs text-green-700/80">Reference: {payment.reference}</p>
                            )}
                            {payment.receivedBy?.name && (
                              <p className="text-xs text-green-700/80">Received by: {payment.receivedBy.name}</p>
                            )}
                          </div>
                          <span className="font-medium">{formatCurrency(Number(payment.amount))}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                ) : null}
              </div>

              {selectedOrder.notes && (
                <>
                  <Separator />
                  <div>
                    <h4 className="font-medium mb-1 text-sm">Notes</h4>
                    <p className="text-xs text-muted-foreground">{selectedOrder.notes}</p>
                  </div>
                </>
              )}

              <div className="flex gap-2 pt-2">
                {selectedOrder.status === 'draft' && (
                  <Button
                    variant="outline"
                    onClick={() => runOrderAction(selectedOrder, 'cancel', `Cancel draft ${selectedOrder.order_number}?`)}
                    disabled={isOrderActionPending(selectedOrder.id)}
                  >
                    <Icon icon="solar:close-circle-bold" className="h-4 w-4 mr-2" />
                    Cancel
                  </Button>
                )}
                {selectedOrder.status === 'paid' && (
                  <Button
                    variant="outline"
                    onClick={() => runOrderAction(selectedOrder, 'refund', `Refund paid order ${selectedOrder.order_number}? This creates a full return.`)}
                    disabled={isOrderActionPending(selectedOrder.id)}
                  >
                    <Icon icon="mdi:cash-refund" className="h-4 w-4 mr-2" />
                    Refund
                  </Button>
                )}
                {(selectedOrder.status === 'draft' || selectedOrder.status === 'paid') && (
                  <Button
                    variant="outline"
                    onClick={() => runOrderAction(selectedOrder, 'void', selectedOrder.status === 'paid'
                      ? `Void paid order ${selectedOrder.order_number}? This will create a full return and mark the order void.`
                      : `Void draft ${selectedOrder.order_number}?`)}
                    disabled={isOrderActionPending(selectedOrder.id)}
                  >
                    <Icon icon="solar:danger-triangle-bold" className="h-4 w-4 mr-2" />
                    Void
                  </Button>
                )}
                <Button 
                  className="flex-1"
                  onClick={handleDownloadInvoice}
                  disabled={downloadingInvoice || isOrderActionPending(selectedOrder.id)}
                >
                  <Icon icon="solar:document-bold" className="h-4 w-4 mr-2" />
                  {downloadingInvoice ? 'Preparing Invoice...' : 'Download Invoice PDF'}
                </Button>
                <Button 
                  variant="outline"
                  onClick={() => handleGenerateReceipt(selectedOrder)}
                  disabled={isOrderActionPending(selectedOrder.id)}
                >
                  <Icon icon="solar:file-text-bold" className="h-4 w-4 mr-2" />
                  Receipt PDF
                </Button>
                <Button 
                  variant="outline"
                  onClick={() => setOrderModalOpen(false)}
                >
                  Close
                </Button>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </SlideOver>
  )
}
