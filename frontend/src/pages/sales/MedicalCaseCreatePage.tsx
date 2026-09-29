import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { PageHeader } from '@/components/layout/PageHeader'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Checkbox } from '@/components/ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'

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
    patient_name_encrypted: '',
    case_date: '',
    procedure_date: '',
    location: '',
    notes: '',
  })

  const [items, setItems] = useState<any[]>([])

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
      patient_name_encrypted: formData.patient_name_encrypted || null,
      items: items.filter(i => i.product_id && i.sales_order_item_id),
    }
    createMutation.mutate(payload)
  }

  const addItem = () => {
    setItems([...items, { sales_order_item_id: '', product_id: '', product_name: '', allocated_qty: 1, unit_price: 0, is_foc: false, notes: '' }])
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
    <div className="max-w-5xl space-y-6">
      <PageHeader
        title="Create Medical Case"
        description="Link a case to its customer, supporting documents and allocated stock."
        backTo="/sales/medical-cases"
        actions={
          <>
            <HeaderActionButton
              variant="outline"
              onClick={() => navigate('/sales/medical-cases')}
              disabled={createMutation.isPending}
            >
              Cancel
            </HeaderActionButton>
            <HeaderActionButton
              type="submit"
              form="medical-case-form"
              icon="solar:medical-kit-bold-duotone"
              loading={createMutation.isPending}
              disabled={createMutation.isPending}
            >
              Create Medical Case
            </HeaderActionButton>
          </>
        }
      />

      <form id="medical-case-form" onSubmit={handleSubmit} className="space-y-6">
        {/* Case information */}
        <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
          <CardHeader>
            <CardTitle className="text-lg">Case Information</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-5 md:grid-cols-2">
            <div className="space-y-2">
              <Label>Customer <span className="text-destructive">*</span></Label>
              <CustomerSearchCombobox
                value={formData.customer_id}
                onValueChange={(value) => setFormData({ ...formData, customer_id: value })}
                placeholder="Search customer name or code..."
              />
            </div>

            <div className="space-y-2">
              <Label>Invoice</Label>
              <Select
                value={formData.invoice_id}
                onValueChange={(value) => setFormData({ ...formData, invoice_id: value === 'none' ? '' : value })}
                disabled={!formData.customer_id}
              >
                <SelectTrigger className="h-9"><SelectValue placeholder="Select invoice" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No invoice</SelectItem>
                  {invoices?.map((invoice: any) => (
                    <SelectItem key={invoice.id} value={String(invoice.id)}>
                      {invoice.invoice_number}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Case Date</Label>
              <Input
                type="date"
                className="h-9"
                value={formData.case_date}
                onChange={(e) => setFormData({ ...formData, case_date: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label>Procedure Date</Label>
              <Input
                type="date"
                className="h-9"
                value={formData.procedure_date}
                onChange={(e) => setFormData({ ...formData, procedure_date: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label>Location</Label>
              <Input
                className="h-9"
                placeholder="OT, Ward, Clinic..."
                value={formData.location}
                onChange={(e) => setFormData({ ...formData, location: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label>Patient Reference</Label>
              <Input
                className="h-9"
                value={formData.patient_reference}
                onChange={(e) => setFormData({ ...formData, patient_reference: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label>Patient Name</Label>
              <Input
                className="h-9"
                value={formData.patient_name_encrypted}
                onChange={(e) => setFormData({ ...formData, patient_name_encrypted: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label>Delivery Address</Label>
              <Select
                value={formData.delivery_address_id}
                onValueChange={(value) => setFormData({ ...formData, delivery_address_id: value === 'none' ? '' : value })}
                disabled={!formData.customer_id}
              >
                <SelectTrigger className="h-9"><SelectValue placeholder="Select address" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No address</SelectItem>
                  {addresses?.map((address: any) => (
                    <SelectItem key={address.id} value={String(address.id)}>
                      {[address.street, address.city, address.zip].filter(Boolean).join(', ') || address.type}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Doctor</Label>
              <Select
                value={formData.doctor_contact_id}
                onValueChange={(value) => setFormData({ ...formData, doctor_contact_id: value === 'none' ? '' : value })}
                disabled={!formData.customer_id}
              >
                <SelectTrigger className="h-9"><SelectValue placeholder="Select doctor" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No doctor</SelectItem>
                  {contacts?.map((contact: any) => (
                    <SelectItem key={contact.id} value={String(contact.id)}>{contact.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Requestor</Label>
              <Select
                value={formData.requestor_contact_id}
                onValueChange={(value) => setFormData({ ...formData, requestor_contact_id: value === 'none' ? '' : value })}
                disabled={!formData.customer_id}
              >
                <SelectTrigger className="h-9"><SelectValue placeholder="Select requestor" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No requestor</SelectItem>
                  {contacts?.map((contact: any) => (
                    <SelectItem key={contact.id} value={String(contact.id)}>{contact.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Buyer</Label>
              <Select
                value={formData.buyer_contact_id}
                onValueChange={(value) => setFormData({ ...formData, buyer_contact_id: value === 'none' ? '' : value })}
                disabled={!formData.customer_id}
              >
                <SelectTrigger className="h-9"><SelectValue placeholder="Select buyer" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No buyer</SelectItem>
                  {contacts?.map((contact: any) => (
                    <SelectItem key={contact.id} value={String(contact.id)}>{contact.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2 md:col-span-2">
              <Label>Notes</Label>
              <Textarea
                rows={2}
                placeholder="Optional case notes"
                value={formData.notes}
                onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              />
            </div>
          </CardContent>
        </Card>

        {/* Case items */}
        <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
          <CardHeader className="flex flex-row items-center justify-between space-y-0">
            <CardTitle className="text-lg">Case Items</CardTitle>
            <HeaderActionButton type="button" variant="outline" icon="solar:add-circle-bold" onClick={addItem}>
              Add Item
            </HeaderActionButton>
          </CardHeader>
          <CardContent className="space-y-4">
            {items.length === 0 ? (
              <div className="rounded-xl border border-dashed border-slate-300 py-12 text-center dark:border-slate-700">
                <Icon icon="solar:medical-kit-bold-duotone" className="mx-auto mb-2 h-10 w-10 text-muted-foreground opacity-40" />
                <p className="text-sm font-medium">No items added yet</p>
                <p className="mt-0.5 text-xs text-muted-foreground">Add the products allocated to this case.</p>
                <div className="mt-4 flex justify-center">
                  <HeaderActionButton type="button" icon="solar:add-circle-bold" onClick={addItem}>
                    Add Item
                  </HeaderActionButton>
                </div>
              </div>
            ) : (
              items.map((item, index) => (
                <div key={index} className="relative rounded-xl border border-slate-200/80 p-4 dark:border-slate-800">
                  <div className="absolute right-3 top-3">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-7 w-7 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                      onClick={() => removeItem(index)}
                      title="Remove item"
                      aria-label="Remove item"
                    >
                      <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                  </div>

                  <div className="grid gap-4 md:grid-cols-6">
                    <div className="space-y-2 md:col-span-2">
                      <Label>Product <span className="text-destructive">*</span></Label>
                      <ProductSearchCombobox
                        value={item.product_id}
                        onValueChange={(value, product) => {
                          updateItem(index, 'product_id', value)
                          updateItem(index, 'product_name', product?.name ?? '')
                        }}
                        placeholder="Search name, SKU, barcode, lot or serial..."
                      />
                    </div>

                    <div className="space-y-2 md:col-span-2">
                      <Label>SO Item <span className="text-destructive">*</span></Label>
                      <Select
                        value={item.sales_order_item_id}
                        onValueChange={(value) => updateItem(index, 'sales_order_item_id', value)}
                        disabled={!formData.customer_id}
                      >
                        <SelectTrigger className="h-9"><SelectValue placeholder="Select SO item" /></SelectTrigger>
                        <SelectContent>
                          {salesOrders?.flatMap((so: any) =>
                            (so.items || []).map((soi: any) => (
                              <SelectItem key={soi.id} value={String(soi.id)}>
                                {so.sales_order_number} - {soi.product?.name || soi.description}
                              </SelectItem>
                            ))
                          )}
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <Label>Qty <span className="text-destructive">*</span></Label>
                      <Input
                        type="number"
                        min="0"
                        className="h-9"
                        value={item.allocated_qty}
                        onChange={(e) => updateItem(index, 'allocated_qty', Number(e.target.value))}
                      />
                    </div>

                    <div className="space-y-2">
                      <Label>Unit Price</Label>
                      <Input
                        type="number"
                        min="0"
                        step="0.01"
                        className="h-9"
                        value={item.unit_price}
                        onChange={(e) => updateItem(index, 'unit_price', Number(e.target.value))}
                      />
                    </div>

                    <div className="flex items-end gap-2 md:col-span-6">
                      <label className="flex cursor-pointer items-center gap-2 text-sm">
                        <Checkbox
                          checked={item.is_foc}
                          onCheckedChange={(checked) => updateItem(index, 'is_foc', checked === true)}
                        />
                        FOC
                      </label>
                    </div>
                  </div>
                </div>
              ))
            )}
          </CardContent>
        </Card>
      </form>
    </div>
  )
}
