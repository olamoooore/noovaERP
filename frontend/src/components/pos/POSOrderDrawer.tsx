import { useState } from 'react'
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
import { formatCurrency } from '@/lib/utils'
import { usePosSessionStore, CartItem } from '@/store/posSessionStore'
import { useToast } from '@/hooks/use-toast'

interface POSOrderDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
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
  }[]
}

export function POSOrderDrawer({ open, onOpenChange }: POSOrderDrawerProps) {
  const { toast } = useToast()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('all')
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null)
  const [invoiceModalOpen, setInvoiceModalOpen] = useState(false)
  const [loadingInvoice, setLoadingInvoice] = useState(false)
  
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

  const deleteOrderMutation = useMutation({
    mutationFn: async (orderId: number) => {
      await api.delete(`/pos/orders/${orderId}`)
    },
    onSuccess: () => {
      toast({ title: "Order Deleted", description: "Draft order has been removed." })
      queryClient.invalidateQueries({ queryKey: ['pos-orders'] })
    },
    onError: (error: any) => {
        toast({ 
            title: "Delete Failed", 
            description: error.response?.data?.message || "Could not delete order.", 
            variant: "destructive" 
        })
    }
  })

  const orders = data?.data || []

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
  
  const handlePrint = (order: any) => {
      toast({ title: "Printing Receipt", description: `Printing for ${order.order_number}...` })
      // Actual implementation would invoke window.print() on a receipt template or call backend
  }

  const handleViewInvoice = async (order: any) => {
      setLoadingInvoice(true)
      try {
        const response = await api.get(`/pos/orders/${order.id}/invoice`)
        const invoice = response.data.data
        setSelectedInvoice(invoice)
        setInvoiceModalOpen(true)
      } catch (error: any) {
        toast({ 
          title: "No Invoice Found", 
          description: "No invoice is associated with this order.", 
          variant: "destructive" 
        })
      } finally {
        setLoadingInvoice(false)
      }
  }

  const handleDownloadInvoice = async () => {
      if (!selectedInvoice) return
      
      try {
        const response = await api.get(`/invoices/${selectedInvoice.id}/pdf`, {
          responseType: 'blob'
        })
        
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `invoice-${selectedInvoice.invoice_number}.pdf`)
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
      case 'cancelled': return <Badge variant="destructive">Cancelled</Badge>
      default: return <Badge variant="outline">{status}</Badge>
    }
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
                <div key={order.id} className="rounded-xl border bg-card p-4 shadow-sm hover:shadow-md transition-shadow">
                  <div className="flex justify-between items-start mb-3">
                    <div className="flex gap-3">
                        <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center text-primary">
                             <Icon icon="solar:bag-3-bold" className="h-5 w-5" />
                        </div>
                        <div>
                            <h4 className="font-semibold text-sm">{order.order_number}</h4>
                            <p className="text-xs text-muted-foreground">
                                {new Date(order.created_at).toLocaleString()}
                            </p>
                        </div>
                    </div>
                    {getStatusBadge(order.status)}
                  </div>
                  
                  <div className="grid grid-cols-3 gap-2 text-sm mb-4 bg-muted/50 p-2 rounded-lg">
                      <div className="text-center">
                          <span className="block text-xs text-muted-foreground">Items</span>
                          <span className="font-medium">{order.items?.length || 0}</span>
                      </div>
                      <div className="text-center border-l border-r border-border/50">
                           <span className="block text-xs text-muted-foreground">Total</span>
                           <span className="font-bold text-primary">{formatCurrency(Number(order.total))}</span>
                      </div>
                      <div className="text-center">
                           <span className="block text-xs text-muted-foreground">Discount</span>
                           <span className="font-medium">{order.discount_amount > 0 ? formatCurrency(Number(order.discount_amount)) : '-'}</span>
                      </div>
                  </div>

                  <div className="flex gap-2">
                    {order.status === 'draft' ? (
                        <>
                           <Button 
                                className="flex-1 h-9" 
                                onClick={() => handleLoadOrder(order)}
                            >
                                <Icon icon="solar:upload-track-bold" className="h-4 w-4 mr-2" />
                                Load Order
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                className="h-9 w-9 text-destructive hover:text-destructive hover:bg-destructive/10"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    if(window.confirm('Are you sure you want to delete this draft?')) {
                                        deleteOrderMutation.mutate(order.id);
                                    }
                                }}
                                title="Delete Draft"
                            >
                                <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button 
                                variant="default"
                                className="flex-1 h-9"
                                onClick={() => handleViewInvoice(order)}
                            >
                                <Icon icon="solar:document-bold" className="h-4 w-4 mr-2" />
                                View Invoice
                            </Button>
                        </>
                    )}
                    
                    <Button 
                        variant="outline" 
                        size="icon"
                        className="h-9 w-9"
                        onClick={() => handleGenerateReceipt(order)}
                        title="Generate Receipt (PDF)"
                    >
                        <Icon icon="solar:file-text-bold" className="h-4 w-4" />
                    </Button>
                    <Button 
                        variant="outline" 
                        size="icon" 
                        className="h-9 w-9"
                        onClick={() => handlePrint(order)}
                        title="Print Receipt"
                    >
                        <Icon icon="solar:printer-bold" className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </ScrollArea>
      </SlideOverContent>

      {/* Invoice Modal */}
      <Dialog open={invoiceModalOpen} onOpenChange={setInvoiceModalOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center justify-between">
              <span>{selectedInvoice?.invoice_number}</span>
              <Badge className={selectedInvoice?.status === 'paid' ? 'bg-green-500' : ''}>
                {selectedInvoice?.status}
              </Badge>
            </DialogTitle>
          </DialogHeader>

          {selectedInvoice && (
            <div className="space-y-4">
              {/* Customer Info */}
              <div className="p-3 bg-muted/50 rounded-lg">
                <p className="font-medium">{selectedInvoice.customer?.name || 'Walk-in Customer'}</p>
                {selectedInvoice.customer?.email && (
                  <p className="text-sm text-muted-foreground">{selectedInvoice.customer.email}</p>
                )}
              </div>

              {/* Invoice Details */}
              <div className="grid grid-cols-2 gap-3 text-sm">
                <div>
                  <span className="text-muted-foreground">Invoice Date:</span>
                  <p className="font-medium">{new Date(selectedInvoice.invoice_date).toLocaleDateString()}</p>
                </div>
                <div>
                  <span className="text-muted-foreground">Due Date:</span>
                  <p className="font-medium">{new Date(selectedInvoice.due_date).toLocaleDateString()}</p>
                </div>
              </div>

              <Separator />

              {/* Line Items */}
              <div>
                <h4 className="font-medium mb-2">Items</h4>
                <ScrollArea className="max-h-40">
                  <div className="space-y-2">
                    {selectedInvoice.lines?.map((line) => (
                      <div key={line.id} className="flex justify-between text-sm p-2 rounded bg-muted/50">
                        <div>
                          <p className="font-medium">{line.description}</p>
                          <p className="text-xs text-muted-foreground">
                            {line.quantity} x {formatCurrency(line.unit_price)}
                          </p>
                        </div>
                        <span className="font-medium">{formatCurrency(line.line_total)}</span>
                      </div>
                    ))}
                  </div>
                </ScrollArea>
              </div>

              <Separator />

              {/* Totals */}
              <div className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span>{formatCurrency(selectedInvoice.subtotal)}</span>
                </div>
                {selectedInvoice.tax_amount > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Tax</span>
                    <span>{formatCurrency(selectedInvoice.tax_amount)}</span>
                  </div>
                )}
                {selectedInvoice.discount_amount > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Discount</span>
                    <span>-{formatCurrency(selectedInvoice.discount_amount)}</span>
                  </div>
                )}
                <Separator />
                <div className="flex justify-between font-bold text-lg">
                  <span>Total</span>
                  <span>{formatCurrency(selectedInvoice.total)}</span>
                </div>
                <div className="flex justify-between text-green-600">
                  <span>Paid</span>
                  <span>{formatCurrency(selectedInvoice.paid_amount)}</span>
                </div>
              </div>

              {/* Notes */}
              {selectedInvoice.notes && (
                <>
                  <Separator />
                  <div>
                    <h4 className="font-medium mb-1 text-sm">Notes</h4>
                    <p className="text-xs text-muted-foreground">{selectedInvoice.notes}</p>
                  </div>
                </>
              )}

              {/* Actions */}
              <div className="flex gap-2 pt-2">
                <Button 
                  className="flex-1"
                  onClick={handleDownloadInvoice}
                >
                  <Icon icon="solar:download-bold" className="h-4 w-4 mr-2" />
                  Download PDF
                </Button>
                <Button 
                  variant="outline"
                  onClick={() => setInvoiceModalOpen(false)}
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
