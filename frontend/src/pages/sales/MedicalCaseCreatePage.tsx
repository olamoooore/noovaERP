import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export function MedicalCaseCreatePage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [formData, setFormData] = useState({
    customer_id: '',
    invoice_id: '',
    delivery_address_id: '',
    doctor_contact_id: '',
    requestor_contact_id: '',
    buyer_contact_id: '',
    patient_reference: '',
    case_date: '',
    procedure_date: '',
    location: '',
    notes: '',
  })

  const [items, setItems] = useState<any[]>([])

  const { data: customers } = useQuery({
    queryKey: ['customers-list'],
    queryFn: async () => {
      const res = await api.get('/customers', { params: { per_page: 1000 } })
      return res.data.data || []
    },
  })

  const { data: invoices } = useQuery({
    queryKey: ['invoices-list', formData.customer_id],
    queryFn: async () => {
      if (!formData.customer_id) return []
      const res = await api.get('/invoices', { params: { customer_id: formData.customer_id, per_page: 1000 } })
      return res.data.data || []
    },
    enabled: !!formData.customer_id,
  })

  const { data: addresses } = useQuery({
    queryKey: ['customer-addresses', formData.customer_id],
    queryFn: async () => {
      if (!formData.customer_id) return []
      const res = await api.get(`/customers/${formData.customer_id}`)
      return res.data.addresses || []
    },
    enabled: !!formData.customer_id,
  })

  const { data: contacts } = useQuery({
    queryKey: ['customer-contacts', formData.customer_id],
    queryFn: async () => {
      if (!formData.customer_id) return []
      const res = await api.get(`/customers/${formData.customer_id}`)
      return res.data.contacts || []
    },
    enabled: !!formData.customer_id,
  })

  const { data: products } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const res = await api.get('/products', { params: { per_page: 1000 } })
      return res.data.data || []
    },
  })

  const { data: salesOrders } = useQuery({
    queryKey: ['sales-orders-list', formData.customer_id],
    queryFn: async () => {
      if (!formData.customer_id) return []
      const res = await api.get('/sales/orders', { params: { customer_id: formData.customer_id, per_page: 1000 } })
      return res.data.data || []
    },
    enabled: !!formData.customer_id,
  })

  const createMutation = useMutation({
    mutationFn: async (data: any) => {
      const res = await api.post('/medical-cases', data)
      return res.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['medical-cases'] })
      toast({ title: 'Success', description: 'Medical case created successfully' })
      navigate(`/sales/medical-cases/${data.data.id}`)
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to create', variant: 'destructive' })
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const payload = {
      ...formData,
      customer_id: Number(formData.customer_id),
      invoice_id: formData.invoice_id ? Number(formData.invoice_id) : null,
      delivery_address_id: formData.delivery_address_id ? Number(formData.delivery_address_id) : null,
      doctor_contact_id: formData.doctor_contact_id ? Number(formData.doctor_contact_id) : null,
      requestor_contact_id: formData.requestor_contact_id ? Number(formData.requestor_contact_id) : null,
      buyer_contact_id: formData.buyer_contact_id ? Number(formData.buyer_contact_id) : null,
      items: items.filter(i => i.product_id && i.sales_order_item_id),
    }
    createMutation.mutate(payload)
  }

  const addItem = () => {
    setItems([...items, { sales_order_item_id: '', product_id: '', allocated_qty: 1, unit_price: 0, is_foc: false, notes: '' }])
  }

  const removeItem = (index: number) => {
    setItems(items.filter((_, i) => i !== index))
  }

  const updateItem = (index: number, field: string, value: any) => {
    const updated = [...items]
    updated[index] = { ...updated[index], [field]: value }
    setItems(updated)
  }

  return (
    <div className="container mx-auto py-6 space-y-6">
      <div className="flex items-center justify-between">
        <Button variant="outline" onClick={() => navigate('/sales/medical-cases')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="mr-2 h-4 w-4" />
          Back
        </Button>
        <h1 className="text-3xl font-bold">Create Medical Case</h1>
      </div>

      <form onSubmit={handleSubmit}>
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Case Information</CardTitle>
          </CardHeader>
          <CardContent className="p-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="text-sm font-medium">Customer *</label>
                <select
                  value={formData.customer_id}
                  onChange={(e) => setFormData({ ...formData, customer_id: e.target.value, invoice_id: '', delivery_address_id: '' })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  required
                >
                  <option value="">Select Customer</option>
                  {customers?.map((c: any) => (
                    <option key={c.id} value={c.id}>{c.company_name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">Invoice</label>
                <select
                  value={formData.invoice_id}
                  onChange={(e) => setFormData({ ...formData, invoice_id: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="">Select Invoice</option>
                  {invoices?.map((inv: any) => (
                    <option key={inv.id} value={inv.id}>{inv.invoice_number}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">Case Date</label>
                <input
                  type="date"
                  value={formData.case_date}
                  onChange={(e) => setFormData({ ...formData, case_date: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="text-sm font-medium">Procedure Date</label>
                <input
                  type="date"
                  value={formData.procedure_date}
                  onChange={(e) => setFormData({ ...formData, procedure_date: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="text-sm font-medium">Location</label>
                <input
                  type="text"
                  value={formData.location}
                  onChange={(e) => setFormData({ ...formData, location: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  placeholder="OT, Ward, Clinic..."
                />
              </div>
              <div>
                <label className="text-sm font-medium">Patient Reference</label>
                <input
                  type="text"
                  value={formData.patient_reference}
                  onChange={(e) => setFormData({ ...formData, patient_reference: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="text-sm font-medium">Delivery Address</label>
                <select
                  value={formData.delivery_address_id}
                  onChange={(e) => setFormData({ ...formData, delivery_address_id: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="">Select Address</option>
                  {addresses?.map((a: any) => (
                    <option key={a.id} value={a.id}>{a.site_name || a.address_line_1}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">Doctor</label>
                <select
                  value={formData.doctor_contact_id}
                  onChange={(e) => setFormData({ ...formData, doctor_contact_id: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="">Select Doctor</option>
                  {contacts?.map((c: any) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">Requestor</label>
                <select
                  value={formData.requestor_contact_id}
                  onChange={(e) => setFormData({ ...formData, requestor_contact_id: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="">Select Requestor</option>
                  {contacts?.map((c: any) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">Buyer</label>
                <select
                  value={formData.buyer_contact_id}
                  onChange={(e) => setFormData({ ...formData, buyer_contact_id: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="">Select Buyer</option>
                  {contacts?.map((c: any) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>
              <div className="md:col-span-2">
                <label className="text-sm font-medium">Notes</label>
                <textarea
                  value={formData.notes}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                  className="w-full mt-1 flex min-h-[80px] rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Case Items */}
        <Card className="mb-6">
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Case Items</CardTitle>
            <Button type="button" variant="outline" size="sm" onClick={addItem}>
              <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
              Add Item
            </Button>
          </CardHeader>
          <CardContent>
            {items.length === 0 ? (
              <div className="py-8 text-center text-muted-foreground">
                <p>No items added yet. Click "Add Item" to begin.</p>
              </div>
            ) : (
              <div className="space-y-4">
                {items.map((item, index) => (
                  <div key={index} className="grid grid-cols-1 md:grid-cols-6 gap-3 p-4 border rounded-lg">
                    <div className="md:col-span-2">
                      <label className="text-sm font-medium">Product *</label>
                      <select
                        value={item.product_id}
                        onChange={(e) => updateItem(index, 'product_id', e.target.value)}
                        className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        required
                      >
                        <option value="">Select Product</option>
                        {products?.map((p: any) => (
                          <option key={p.id} value={p.id}>{p.name}</option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label className="text-sm font-medium">SO Item *</label>
                      <select
                        value={item.sales_order_item_id}
                        onChange={(e) => updateItem(index, 'sales_order_item_id', e.target.value)}
                        className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        required
                      >
                        <option value="">Select SO Item</option>
                        {salesOrders?.flatMap((so: any) =>
                          (so.items || []).map((soi: any) => (
                            <option key={soi.id} value={soi.id}>
                              {so.sales_order_number} - {soi.product?.name || soi.description}
                            </option>
                          ))
                        )}
                      </select>
                    </div>
                    <div>
                      <label className="text-sm font-medium">Qty *</label>
                      <input
                        type="number"
                        value={item.allocated_qty}
                        onChange={(e) => updateItem(index, 'allocated_qty', Number(e.target.value))}
                        className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        min="0"
                        required
                      />
                    </div>
                    <div>
                      <label className="text-sm font-medium">Unit Price</label>
                      <input
                        type="number"
                        value={item.unit_price}
                        onChange={(e) => updateItem(index, 'unit_price', Number(e.target.value))}
                        className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        min="0"
                        step="0.01"
                      />
                    </div>
                    <div className="flex items-end gap-2">
                      <label className="flex items-center gap-2 text-sm">
                        <input
                          type="checkbox"
                          checked={item.is_foc}
                          onChange={(e) => updateItem(index, 'is_foc', e.target.checked)}
                          className="rounded"
                        />
                        FOC
                      </label>
                      <Button type="button" variant="ghost" size="sm" onClick={() => removeItem(index)}>
                        <Icon icon="solar:trash-bin-minimalistic-bold-duotone" className="h-4 w-4 text-red-500" />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <div className="flex justify-end gap-3">
          <Button type="button" variant="outline" onClick={() => navigate('/sales/medical-cases')}>
            Cancel
          </Button>
          <Button type="submit" disabled={createMutation.isPending}>
            {createMutation.isPending ? 'Creating...' : 'Create Medical Case'}
          </Button>
        </div>
      </form>
    </div>
  )
}
