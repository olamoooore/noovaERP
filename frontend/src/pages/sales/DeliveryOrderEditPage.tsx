import { useState, useEffect } from 'react'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { TagEditor } from '@/components/tags/TagEditor'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { TableCell, TableRow } from '@/components/ui/table'
import { LineItemsTable } from '@/components/sales/LineItemsTable'
import {
  LINE_CELL,
  LINE_CELL_BOX,
  LINE_INPUT_NUMERIC,
  LINE_PRODUCT_WRAP,
  LINE_TEXTAREA,
  ProductLineActions,
} from '@/components/sales/ProductLineTable'
import { cn } from '@/lib/utils'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { getProductLineDescription, ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeliveryOrderColumnVisibility, SalesLineColumnMenu } from '@/components/sales/DeliveryOrderColumnVisibility'
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog'
import { DocumentTextLineRow, getDocumentSectionTotal, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow'
import { ProductTrackingSelector } from '@/components/inventory/ProductTrackingSelector'

interface LineItem {
  line_type?: 'product' | 'note' | 'section'
  product_id: number | null
  product_name: string
  model_number: string
  description: string
  product_notes: string
  patient_name: string
  quantity: number
  unit: string
  unit_price: number | null
  // MRD pricelist-multicurrency FR-6.2/6.3: server-side list resolution.
  price_mode?: 'auto' | 'manual' | 'carried'
  lot_number: string
  serial_number?: string
  product_batch_id?: number | null
  product_serial_id?: number | null
  tracking_method?: string
  expiry_date: string
}

const DO_EDIT_COLUMNS = [
  'Product', 'Description', 'Product Notes', 'Patient Name', 'Quantity', 'Unit',
  'Unit Price', 'Lot Number', 'Expiry Date', 'Actions',
].map((label, index) => ({ label, index: index + 1 }))

export function DeliveryOrderEditPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const [deliveryDate, setDeliveryDate] = useState('')

  // Product Notes starts hidden, matching the previous default.
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>(
    () => DO_EDIT_COLUMNS.filter((c) => c.label.toLowerCase() === 'product notes').map((c) => c.index)
  )
  const [expectedDate, setExpectedDate] = useState('')
  const [paymentTerms, setPaymentTerms] = useState('')
  const [invoiceId, setInvoiceId] = useState('')
  const [quotationId, setQuotationId] = useState('')
  const [carrier, setCarrier] = useState('')
  const [trackingNumber, setTrackingNumber] = useState('')
  const [shippingAddress, setShippingAddress] = useState('')
  const [poNumber, setPoNumber] = useState('')
  const [salesperson, setSalesperson] = useState('')
  const [attentionTo, setAttentionTo] = useState('')
  const [notes, setNotes] = useState('')
  const [warehouseId, setWarehouseId] = useState('')
  const [lines, setLines] = useState<LineItem[]>([])
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null)

  // Fetch existing delivery order
  const { data: deliveryOrder, isLoading } = useQuery({
    queryKey: ['delivery-order', id],
    queryFn: async () => {
      const res = await api.get(`/sales/delivery-orders/${id}`)
      return res.data
    },
    enabled: !!id,
  })

  // Fetch products for line items
  const { data: productsData } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const res = await api.get('/products', { params: { per_page: 500 } })
      return res.data?.data || res.data || []
    },
  })

  // Fetch warehouses
  const { data: warehousesData } = useQuery({
    queryKey: ['warehouses-list'],
    queryFn: async () => {
      const res = await api.get('/warehouses', { params: { per_page: 100 } })
      return res.data?.data || res.data || []
    },
  })
  const warehouses = Array.isArray(warehousesData) ? warehousesData : []

  const { data: invoicesData } = useQuery({
    queryKey: ['delivery-order-invoices', deliveryOrder?.customer_id],
    queryFn: async () => {
      const res = await api.get('/invoices', { params: { per_page: 200, customer_id: deliveryOrder.customer_id } })
      return res.data?.data || []
    },
    enabled: !!deliveryOrder?.customer_id,
  })

  const { data: quotationsData } = useQuery({
    queryKey: ['delivery-order-quotations', deliveryOrder?.customer_id],
    queryFn: async () => {
      const res = await api.get('/quotations', { params: { per_page: 200, customer_id: deliveryOrder.customer_id } })
      return res.data?.data || []
    },
    enabled: !!deliveryOrder?.customer_id,
  })

  const invoices = Array.isArray(invoicesData) ? invoicesData : []
  const quotations = Array.isArray(quotationsData) ? quotationsData : []

  // Populate form when data loads
  useEffect(() => {
    if (deliveryOrder) {
      setDeliveryDate(deliveryOrder.delivery_date?.split('T')[0] || '')
      setExpectedDate((deliveryOrder.expected_date || deliveryOrder.due_date || '').split('T')[0])
      setPaymentTerms(deliveryOrder.payment_terms || '')
      setInvoiceId(deliveryOrder.invoice_id?.toString() || '')
      setQuotationId(deliveryOrder.quotation_id?.toString() || '')
      setCarrier(deliveryOrder.carrier || '')
      setTrackingNumber(deliveryOrder.tracking_number || '')
      setShippingAddress(deliveryOrder.shipping_address || '')
      setPoNumber(deliveryOrder.po_number || '')
      setSalesperson(deliveryOrder.salesperson || '')
      setAttentionTo(deliveryOrder.attention_to || '')
      setNotes(deliveryOrder.notes || '')
      setWarehouseId(deliveryOrder.warehouse_id?.toString() || '')
      setLines(
        deliveryOrder.lines?.map((l: any) => ({
          line_type: l.line_type || 'product',
          product_id: l.product_id,
          product_name: l.product?.name || '',
          model_number: l.model_number || '',
          description: l.description || '',
          product_notes: l.product_notes || '',
          patient_name: l.patient_name || '',
          quantity: l.quantity_delivered != null ? parseFloat(l.quantity_delivered) : '',
          unit: l.unit || 'pcs',
          unit_price: l.unit_price ? parseFloat(l.unit_price) : null,
          lot_number: l.lot_number || '',
          serial_number: l.serial_number || '',
          product_batch_id: l.product_batch_id || null,
          product_serial_id: l.product_serial_id || null,
          tracking_method: l.product?.tracking_method || 'none',
          expiry_date: l.expiry_date?.split('T')[0] || '',
        })) || []
      )
    }
  }, [deliveryOrder])

  const updateMutation = useMutation({
    mutationFn: async (payload: any) => {
      const res = await api.put(`/sales/delivery-orders/${id}`, payload)
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      toast({ title: 'Delivery order updated' })
      navigate(`/delivery-orders/${id}`)
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const products = Array.isArray(productsData) ? productsData : []

  const updateLine = (index: number, field: keyof LineItem, value: any) => {
    setLines((prev) => prev.map((line, i) => (i === index ? { ...line, [field]: value } : line)))
  }

  const addLine = () => {
    setLines((prev) => [...prev, { product_id: null, product_name: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 1, unit: 'pcs', unit_price: null, lot_number: '', expiry_date: '' }])
  }
  const addNote = () => setLines((prev) => [...prev, { line_type: 'note', product_id: null, product_name: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 0, unit: '', unit_price: null, lot_number: '', expiry_date: '' }])
  const addSection = () => setLines((prev) => [...prev, { line_type: 'section', product_id: null, product_name: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 0, unit: '', unit_price: null, lot_number: '', expiry_date: '' }])
  const addCatalogProducts = (selected: Array<{ product: any; quantity: number }>) => setLines((prev) => [...prev, ...selected.map(({ product, quantity }) => ({ line_type: 'product' as const, product_id: product.id, product_name: product.name || '', model_number: '', description: product.name || '', product_notes: '', patient_name: '', quantity, unit: product.unit || 'pcs', unit_price: Number(product.price || 0), price_mode: 'auto' as const, lot_number: '', expiry_date: '' }))])

  const removeLine = (index: number) => {
    setLines((prev) => (prev.length > 1 ? prev.filter((_, i) => i !== index) : prev))
  }

  const emptyLine = (): LineItem => ({
    line_type: 'product', product_id: null, product_name: '', model_number: '',
    description: '', product_notes: '', patient_name: '', quantity: 1, unit: 'pcs',
    unit_price: null, lot_number: '', expiry_date: '',
  })

  /** Clear a line in place — a lone row can be emptied without deleting it. */
  const resetLine = (index: number) => {
    setLines((prev) => prev.map((line, i) => (i === index ? emptyLine() : line)))
  }

  const moveLine = (index: number, direction: 'up' | 'down') => {
    const target = direction === 'up' ? index - 1 : index + 1
    setLines((prev) => reorderDocumentLines(prev, index, target))
  }

  const duplicateLine = (index: number) => {
    setLines((prev) => {
      const copy = { ...prev[index] }
      const next = [...prev]
      next.splice(index + 1, 0, copy)
      return next
    })
  }

  const handleSubmit = () => {
    const validLines = lines.filter((l) => l.line_type === 'note' || l.line_type === 'section' || l.quantity > 0)
    const missingProductIndex = lines.findIndex((line) => (line.line_type ?? 'product') === 'product' && !line.product_id)
    if (missingProductIndex >= 0) {
      toast({
        variant: 'destructive',
        title: `Product required on line ${missingProductIndex + 1}`,
        description: 'Select a product before saving.',
      })
      return
    }
    updateMutation.mutate({
      warehouse_id: warehouseId ? parseInt(warehouseId) : undefined,
      delivery_date: deliveryDate || undefined,
      expected_date: expectedDate || undefined,
      payment_terms: paymentTerms || undefined,
      invoice_id: invoiceId ? parseInt(invoiceId) : null,
      quotation_id: quotationId ? parseInt(quotationId) : null,
      carrier: carrier || undefined,
      tracking_number: trackingNumber || undefined,
      shipping_address: shippingAddress || undefined,
      po_number: poNumber || undefined,
      salesperson: salesperson || undefined,
      attention_to: attentionTo || undefined,
      notes: notes || undefined,
      lines: validLines.map((l) => ({
        line_type: l.line_type || 'product',
        sequence: validLines.indexOf(l),
        product_id: l.product_id,
        model_number: l.model_number || null,
        description: l.description,
        product_notes: l.product_notes || null,
        patient_name: l.patient_name || undefined,
        quantity_delivered: l.quantity,
        unit: l.unit,
        unit_price: l.unit_price || undefined,
        price_mode: l.price_mode || undefined,
        lot_number: l.lot_number || undefined,
        serial_number: l.serial_number || undefined,
        product_batch_id: l.product_batch_id || undefined,
        product_serial_id: l.product_serial_id || undefined,
        expiry_date: l.expiry_date || undefined,
      })),
    })
  }

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="py-12 text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
          <p className="mt-4 text-sm text-muted-foreground">Loading delivery order...</p>
        </div>
      </div>
    )
  }

  if (!deliveryOrder) {
    return (
      <div className="p-6">
        <div className="py-12 text-center text-muted-foreground">
          <p>Delivery order not found.</p>
          <Button variant="outline" onClick={() => navigate('/delivery-orders')} className="mt-4">
            Back to Delivery Orders
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate(`/delivery-orders/${id}`)}
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <h1 className="text-3xl font-bold">Edit Delivery Order</h1>
          <p className="text-muted-foreground mt-1">{deliveryOrder.delivery_order_number}</p>
          {id && <div className="mt-2"><TagEditor subjectType="delivery_order" subjectId={id} /></div>}
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Delivery Details</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
              <Label>Delivery Date</Label>
              <Input type="date" value={deliveryDate} onChange={(e) => setDeliveryDate(e.target.value)} />
            </div>
            <div>
              <Label>Due Date</Label>
              <Input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
            </div>
            <div>
              <Label>Warehouse</Label>
              <Select value={warehouseId} onValueChange={setWarehouseId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select warehouse" />
                </SelectTrigger>
                <SelectContent>
                  {warehouses.map((w: any) => (
                    <SelectItem key={w.id} value={w.id.toString()}>
                      {w.code ? `${w.code} — ` : ''}{w.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Carrier</Label>
              <Input value={carrier} onChange={(e) => setCarrier(e.target.value)} placeholder="e.g. DHL, FedEx" />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>PO Number</Label>
              <Input value={poNumber} onChange={(e) => setPoNumber(e.target.value)} placeholder="Purchase order number" />
            </div>
            <div>
              <Label>Salesperson</Label>
              <Input value={salesperson} onChange={(e) => setSalesperson(e.target.value)} placeholder="Salesperson name" />
            </div>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <Label>Invoice No.</Label>
              <Select value={invoiceId || 'none'} onValueChange={(value) => setInvoiceId(value === 'none' ? '' : value)}>
                <SelectTrigger><SelectValue placeholder="Select invoice" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None</SelectItem>
                  {invoices.map((invoice: any) => (
                    <SelectItem key={invoice.id} value={invoice.id.toString()}>{invoice.invoice_number}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Quotation No.</Label>
              <Select value={quotationId || 'none'} onValueChange={(value) => setQuotationId(value === 'none' ? '' : value)}>
                <SelectTrigger><SelectValue placeholder="Select quotation" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None</SelectItem>
                  {quotations.map((quotation: any) => (
                    <SelectItem key={quotation.id} value={quotation.id.toString()}>{quotation.quotation_number}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div>
            <Label>Payment Terms</Label>
            <Input value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value)} placeholder="e.g. Net 30" />
          </div>
          <div>
            <Label>Attention To</Label>
            <Input value={attentionTo} onChange={(e) => setAttentionTo(e.target.value)} placeholder="Attention to" />
          </div>
          <div>
            <Label>Shipping Address</Label>
            <textarea
              value={shippingAddress}
              onChange={(e) => setShippingAddress(e.target.value)}
              placeholder="Shipping address"
              className="w-full px-3 py-2 border rounded-md text-sm min-h-[60px]"
            />
          </div>
          <div>
            <Label>Notes</Label>
            <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional notes" />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <CardTitle>Line Items</CardTitle>
          <SalesLineColumnMenu
            columns={DO_EDIT_COLUMNS}
            hidden={hiddenLineColumns}
            setHidden={setHiddenLineColumns}
          />
        </CardHeader>
        <CardContent>
          <DeliveryOrderColumnVisibility
            tableClass="do-edit-columns"
            columns={DO_EDIT_COLUMNS}
            hiddenColumns={hiddenLineColumns}
            onHiddenColumnsChange={setHiddenLineColumns}
            showMenu={false}
          >
          <LineItemsTable
            variant="shadcn"
            columns={[
              { key: 'index', label: '#', align: 'center', minWidth: 'w-10', className: 'w-10' },
              { key: 'product', label: 'Product', minWidth: 'min-w-[15rem]' },
              { key: 'description', label: 'Description', minWidth: 'min-w-[16rem]' },
              { key: 'product_notes', label: 'Product Notes', minWidth: 'min-w-[10rem]' },
              { key: 'patient_name', label: 'Patient Name', minWidth: 'min-w-[9rem]' },
              { key: 'quantity', label: 'Quantity', align: 'right', minWidth: 'w-[5.5rem] min-w-[4.5rem]' },
              { key: 'unit', label: 'Unit', minWidth: 'w-[5rem] min-w-[4rem]' },
              { key: 'unit_price', label: 'Unit Price', align: 'right', minWidth: 'w-[7rem] min-w-[6rem]' },
              { key: 'lot_number', label: 'Lot Number', minWidth: 'min-w-[7.5rem]' },
              { key: 'expiry_date', label: 'Expiry Date', minWidth: 'min-w-[9.5rem]' },
              { key: 'actions', label: '', align: 'right', minWidth: 'w-[10.5rem]', className: 'w-[10.5rem]' },
            ]}
          >
              {lines.map((line, idx) => (
                <TableRow
                  key={idx}
                  className="transition-colors hover:bg-muted/20"
                  draggable={(line.line_type ?? 'product') === 'product'}
                  onDragStart={() => setDraggedLineIndex(idx)}
                  onDragOver={(event) => event.preventDefault()}
                  onDrop={() => {
                    if (draggedLineIndex !== null && draggedLineIndex !== idx) setLines(reorderDocumentLines(lines, draggedLineIndex, idx))
                    setDraggedLineIndex(null)
                  }}
                >
                  {line.line_type === 'note' || line.line_type === 'section' ? (
                    <DocumentTextLineRow
                      type={line.line_type}
                      text={line.description}
                      colSpan={11}
                      total={line.line_type === 'section' ? getDocumentSectionTotal(lines, idx, (item) => item.quantity * Number(item.unit_price || 0)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : undefined}
                      onChange={(text) => updateLine(idx, 'description', text)}
                      onDelete={() => removeLine(idx)}
                      onDragStart={() => setDraggedLineIndex(idx)}
                      onDrop={() => { if (draggedLineIndex !== null) setLines(reorderDocumentLines(lines, draggedLineIndex, idx)); setDraggedLineIndex(null) }}
                    />
                  ) : <>
                  <TableCell className={cn(LINE_CELL, 'w-10')}>
                    <div className={cn(LINE_CELL_BOX, 'justify-center text-xs font-medium text-muted-foreground')}>
                      {lines.slice(0, idx + 1).filter((item) => (item.line_type ?? 'product') === 'product').length}
                    </div>
                  </TableCell>

                  <TableCell className={cn(LINE_CELL, 'min-w-[15rem]', LINE_PRODUCT_WRAP)}>
                    <ProductSearchCombobox
                      wrapText
                      value={line.product_id?.toString() || ''}
                      onValueChange={(val, product) => {
                        const productId = val ? parseInt(val) : null
                        // Changing the product must re-price the line from the
                        // NEW product. Only re-selecting the same product keeps
                        // the user's edits. (Previously the price was gated on
                        // `!line.unit_price`, so switching products on a priced
                        // line silently kept the old product's price.)
                        const productChanged = productId !== line.product_id
                        updateLine(idx, 'product_id', productId)
                        updateLine(idx, 'product_name', product?.name || '')
                        if (product && (productChanged || !line.description)) {
                          updateLine(idx, 'description', getProductLineDescription(product))
                        }
                        if (product) {
                          updateLine(idx, 'model_number', (product as any).manufacturer_part_number || (productChanged ? '' : line.model_number))
                          if (productChanged || !line.unit_price) {
                            updateLine(idx, 'unit_price', parseFloat((product as any).price || (product as any).selling_price || '0') || null)
                          updateLine(idx, 'price_mode', 'auto' as any)
                          }
                          if (productChanged || !line.unit || line.unit === 'pcs') {
                            updateLine(idx, 'unit', (product as any).unit || line.unit)
                          }
                          setLines((current) => current.map((item, itemIndex) => itemIndex === idx ? { ...item, tracking_method: (product as any).tracking_method || 'none', product_batch_id: null, product_serial_id: null, lot_number: '', serial_number: '', expiry_date: '' } : item))
                        }
                      }}
                      placeholder="Search product..."
                    />
                  </TableCell>

                  <TableCell className={cn(LINE_CELL, 'min-w-[16rem]')}>
                    <textarea
                      value={line.description}
                      onChange={(e) => updateLine(idx, 'description', e.target.value)}
                      rows={2}
                      className={LINE_TEXTAREA}
                      placeholder="Description"
                    />
                  </TableCell>

                  <TableCell className={cn(LINE_CELL, 'min-w-[10rem]')}>
                    <textarea
                      value={line.product_notes}
                      onChange={(e) => updateLine(idx, 'product_notes', e.target.value)}
                      rows={2}
                      className={LINE_TEXTAREA}
                      placeholder="Product notes"
                    />
                  </TableCell>

                  <TableCell className={cn(LINE_CELL, 'min-w-[9rem]')}>
                    <div className={LINE_CELL_BOX}>
                      <Input value={line.patient_name} onChange={(e) => updateLine(idx, 'patient_name', e.target.value)} placeholder="Patient name" className="h-9" />
                    </div>
                  </TableCell>

                  <TableCell className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                    <div className={LINE_CELL_BOX}>
                      <Input type="number" min="0.01" step="0.01" value={line.quantity ?? ''} onChange={(e) => {
                        // HR71-08: keep literal intent — empty while typing,
                        // no silent coercion to 0.
                        const raw = e.target.value
                        if (raw === '') { updateLine(idx, 'quantity', ''); return }
                        const parsed = parseFloat(raw)
                        if (!Number.isNaN(parsed) && parsed >= 0) updateLine(idx, 'quantity', parsed)
                      }} className={LINE_INPUT_NUMERIC} />
                    </div>
                  </TableCell>

                  <TableCell className={cn(LINE_CELL, 'w-[5rem] min-w-[4rem]')}>
                    <div className={LINE_CELL_BOX}>
                      <Input value={line.unit} onChange={(e) => updateLine(idx, 'unit', e.target.value)} className="h-9" />
                    </div>
                  </TableCell>

                  <TableCell className={cn(LINE_CELL, 'w-[7rem] min-w-[6rem]')}>
                    <div className={LINE_CELL_BOX}>
                      <Input type="number" min="0" step="0.01" value={line.unit_price ?? ''} onChange={(e) => updateLine(idx, 'unit_price', parseFloat(e.target.value) || null)} className={LINE_INPUT_NUMERIC} placeholder="0.00" />
                    </div>
                  </TableCell>
                  <TableCell className={cn(LINE_CELL, 'min-w-[7.5rem]')}>
                    <div className={LINE_CELL_BOX}>
                      <ProductTrackingSelector productId={line.product_id} warehouseId={warehouseId} trackingMethod={line.tracking_method} batchId={line.product_batch_id} serialId={line.product_serial_id} onChange={(selection) => setLines((current) => current.map((item, itemIndex) => itemIndex === idx ? { ...item, ...selection } : item))} />
                    </div>
                  </TableCell>

                  <TableCell className={cn(LINE_CELL, 'min-w-[9.5rem]')}>
                    <div className={LINE_CELL_BOX}>
                      <Input type="date" value={line.expiry_date} readOnly className="h-9 bg-gray-50 dark:bg-gray-900" />
                      {line.serial_number ? <div className="mt-1 text-xs text-muted-foreground">Serial: {line.serial_number}</div> : null}
                    </div>
                  </TableCell>

                  <TableCell className={cn(LINE_CELL, 'w-[10.5rem]')}>
                    <div className={cn(LINE_CELL_BOX, 'justify-end')}>
                      <ProductLineActions
                        onMoveUp={() => moveLine(idx, 'up')}
                        onMoveDown={() => moveLine(idx, 'down')}
                        canMoveUp={idx !== 0}
                        canMoveDown={idx !== lines.length - 1}
                        onDuplicate={() => duplicateLine(idx)}
                        onReset={() => resetLine(idx)}
                        onDelete={() => removeLine(idx)}
                      />
                    </div>
                  </TableCell>
                  </>}
                </TableRow>
              ))}
          </LineItemsTable>
          </DeliveryOrderColumnVisibility>
          <div className="mt-3 flex flex-wrap gap-2">
            <ProductCatalogDialog products={products || []} onAdd={addCatalogProducts} />
            <HeaderActionButton type="button" variant="outline" icon="solar:add-circle-bold" onClick={addLine}>
              Add Line
            </HeaderActionButton>
            <HeaderActionButton type="button" variant="outline" onClick={addSection}>Add Section</HeaderActionButton>
            <HeaderActionButton type="button" variant="outline" onClick={addNote}>Add Note</HeaderActionButton>
          </div>
        </CardContent>
      </Card>

      <div className="flex justify-end gap-3">
        <Button variant="outline" onClick={() => navigate(`/delivery-orders/${id}`)}>
          Cancel
        </Button>
        <Button onClick={handleSubmit} disabled={updateMutation.isPending} size="lg">
          {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
        </Button>
      </div>
    </div>
  )
}

export default DeliveryOrderEditPage
