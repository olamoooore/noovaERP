import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeliveryOrderColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility'
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog'
import { DocumentTextLineRow, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow'

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
  lot_number: string
  expiry_date: string
}

export function DeliveryOrderEditPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const [deliveryDate, setDeliveryDate] = useState('')
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
          quantity: parseFloat(l.quantity_delivered) || 1,
          unit: l.unit || 'pcs',
          unit_price: l.unit_price ? parseFloat(l.unit_price) : null,
          lot_number: l.lot_number || '',
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
  const addCatalogProducts = (selected: Array<{ product: any; quantity: number }>) => setLines((prev) => [...prev, ...selected.map(({ product, quantity }) => ({ line_type: 'product' as const, product_id: product.id, product_name: product.name || '', model_number: '', description: product.name || '', product_notes: '', patient_name: '', quantity, unit: product.unit || 'pcs', unit_price: Number(product.price || 0), lot_number: '', expiry_date: '' }))])

  const removeLine = (index: number) => {
    setLines((prev) => prev.filter((_, i) => i !== index))
  }

  const handleSubmit = () => {
    const validLines = lines.filter((l) => l.line_type === 'note' || l.line_type === 'section' || l.quantity > 0)
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
        lot_number: l.lot_number || undefined,
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
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" onClick={() => navigate(`/delivery-orders/${id}`)}>
          <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
          Back
        </Button>
        <div>
          <h1 className="text-3xl font-bold">Edit Delivery Order</h1>
          <p className="text-muted-foreground mt-1">{deliveryOrder.delivery_order_number}</p>
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
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            Line Items
            <ProductCatalogDialog products={products || []} onAdd={addCatalogProducts} /><Button variant="outline" size="sm" onClick={addSection}>Add Section</Button><Button variant="outline" size="sm" onClick={addNote}>Add Note</Button>
            <Button variant="outline" size="sm" onClick={addLine}>
              <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-1" />
              Add Line
            </Button>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <DeliveryOrderColumnVisibility tableClass="do-edit-columns" columns={[
            'Product', 'Description', 'Product Notes', 'Patient Name', 'Quantity', 'Unit', 'Unit Price', 'Lot Number', 'Expiry Date', 'Actions',
          ].map((label, index) => ({ label, index: index + 1 }))}>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-[200px]">Product</TableHead>
                <TableHead>Description</TableHead>
                <TableHead className="w-[180px]">Product Notes</TableHead>
                <TableHead className="w-[180px]">Patient Name</TableHead>
                <TableHead className="text-right w-[100px]">Quantity</TableHead>
                <TableHead className="w-[100px]">Unit</TableHead>
                <TableHead className="text-right w-[120px]">Unit Price</TableHead>
                <TableHead className="w-[120px]">Lot Number</TableHead>
                <TableHead className="w-[140px]">Expiry Date</TableHead>
                <TableHead className="w-[50px]"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {lines.map((line, idx) => (
                <TableRow key={idx} draggable={(line.line_type ?? 'product') === 'product'} onDragStart={() => setDraggedLineIndex(idx)} onDragOver={(event) => event.preventDefault()} onDrop={() => { if (draggedLineIndex !== null && draggedLineIndex !== idx) setLines(reorderDocumentLines(lines, draggedLineIndex, idx)); setDraggedLineIndex(null); }}>
                  {line.line_type === 'note' || line.line_type === 'section' ? <DocumentTextLineRow type={line.line_type} text={line.description} colSpan={10} onChange={(text) => updateLine(idx, 'description', text)} onDelete={() => removeLine(idx)} onDragStart={() => setDraggedLineIndex(idx)} onDrop={() => { if (draggedLineIndex !== null) setLines(reorderDocumentLines(lines, draggedLineIndex, idx)); setDraggedLineIndex(null); }} /> : <>
                  <TableCell>
                    <ProductSearchCombobox
                      value={line.product_id?.toString() || ''}
                      onValueChange={(val, product) => {
                        updateLine(idx, 'product_id', val ? parseInt(val) : null)
                        updateLine(idx, 'product_name', product?.name || '')
                        if (!line.description && product) updateLine(idx, 'description', product.name || '')
                      }}
                      placeholder="Search product..."
                    />
                  </TableCell>
                  <TableCell>
                    <Input value={line.description} onChange={(e) => updateLine(idx, 'description', e.target.value)} placeholder="Description" />
                  </TableCell>
                  <TableCell>
                    <Input value={line.product_notes} onChange={(e) => updateLine(idx, 'product_notes', e.target.value)} placeholder="Product notes" />
                  </TableCell>
                  <TableCell>
                    <Input value={line.patient_name} onChange={(e) => updateLine(idx, 'patient_name', e.target.value)} placeholder="Patient name" />
                  </TableCell>
                  <TableCell className="text-right">
                    <Input type="number" min="0.01" step="0.01" value={line.quantity || ''} onChange={(e) => updateLine(idx, 'quantity', parseFloat(e.target.value) || 0)} className="w-24 text-right" />
                  </TableCell>
                  <TableCell>
                    <Input value={line.unit} onChange={(e) => updateLine(idx, 'unit', e.target.value)} className="w-20" />
                  </TableCell>
                  <TableCell className="text-right">
                    <Input type="number" min="0" step="0.01" value={line.unit_price ?? ''} onChange={(e) => updateLine(idx, 'unit_price', parseFloat(e.target.value) || null)} className="w-28 text-right" placeholder="0.00" />
                  </TableCell>
                  <TableCell>
                    <Input value={line.lot_number} onChange={(e) => updateLine(idx, 'lot_number', e.target.value)} className="w-28" placeholder="Lot #" />
                  </TableCell>
                  <TableCell>
                    <Input type="date" value={line.expiry_date} onChange={(e) => updateLine(idx, 'expiry_date', e.target.value)} className="w-36" />
                  </TableCell>
                  <TableCell>
                    {lines.length > 1 && (
                      <Button variant="ghost" size="sm" onClick={() => removeLine(idx)}>
                        <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4 text-destructive" />
                      </Button>
                    )}
                  </TableCell>
                  </>}
                </TableRow>
              ))}
            </TableBody>
          </Table>
          </DeliveryOrderColumnVisibility>
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
