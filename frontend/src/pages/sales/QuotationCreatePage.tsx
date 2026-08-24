import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import { useToast } from '@/hooks/use-toast';
import { getApiErrorMessage } from '@/lib/utils';
import api from '@/lib/api';
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox';
import { SalesLineColumnMenu, SalesLineColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility';

const QUOTATION_CREATE_COLUMNS = ['#', 'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Qty', 'Unit Price', 'Tax %', 'Line Total', 'Actions']
  .map((label, index) => ({ label, index: index + 1 }));

interface QuotationLine {
  product_id: string;
  sku: string;
  model_number: string;
  description: string;
  product_notes: string;
  quantity: number;
  unit_price: number | '';
  tax_rate: number | '';
  discount_amount: number | '';
}

interface CustomerRecord {
  id: number;
  name?: string;
  code?: string | null;
  email?: string | null;
  phone?: string | null;
  payment_terms?: number | null;
}

const emptyLine = (): QuotationLine => ({
  product_id: '',
  sku: '',
  model_number: '',
  description: '',
  product_notes: '',
  quantity: 1,
  unit_price: '',
  tax_rate: '',
  discount_amount: '',
});

export const QuotationCreatePage: React.FC = () => {
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>([])
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { toast } = useToast();

  const [customerId, setCustomerId] = useState('');
  const [quotationDate, setQuotationDate] = useState(new Date().toISOString().split('T')[0]);
  const [validUntil, setValidUntil] = useState(
    new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]
  );
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [discountAmount, setDiscountAmount] = useState<number | ''>('');
  const [lines, setLines] = useState<QuotationLine[]>([emptyLine()]);
  const [customerReference, setCustomerReference] = useState('');
  const [internalReference, setInternalReference] = useState('');
  const [attentionTo, setAttentionTo] = useState('');
  const [contactEmail, setContactEmail] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [billingAddressSnapshot, setBillingAddressSnapshot] = useState('');
  const [shippingAddressSnapshot, setShippingAddressSnapshot] = useState('');
  const [salesperson, setSalesperson] = useState('');
  const [deliveryLeadTime, setDeliveryLeadTime] = useState('');
  const [shippingMethod, setShippingMethod] = useState('');
  const [shippingTerms, setShippingTerms] = useState('');
  const [validity, setValidity] = useState('');

  const { data: customers } = useQuery<CustomerRecord[]>({
    queryKey: ['customers-list'],
    queryFn: async () => {
      const response = await api.get('/customers?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const { data: products } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const response = await api.get('/products?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const createMutation = useMutation({
    mutationFn: async (data: unknown) => {
      const response = await api.post('/quotations', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({ title: 'Quotation created successfully' });
      const id = data?.data?.id;
      if (id) {
        navigate(`/quotations/${id}`);
      } else {
        navigate('/quotations');
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create quotation',
        description: getApiErrorMessage(error),
      });
    },
  });

  const updateLine = (index: number, field: keyof QuotationLine, value: string | number) => {
    const updated = [...lines];
    updated[index] = { ...updated[index], [field]: value };

  if (field === 'product_id' && value && products) {
    const product = products.find((item: any) => String(item.id) === String(value));
    if (product) {
      updated[index].description = product.name || product.description || '';
      updated[index].unit_price = parseFloat(product.price || product.selling_price || '0');
      updated[index].model_number = product.manufacturer_part_number || '';
      updated[index].sku = product.sku || '';
    }
  }

    setLines(updated);
  };

  const addLine = () => setLines([...lines, emptyLine()]);

  const duplicateLine = (index: number) => {
    const targetLine = lines[index];
    if (!targetLine) return;
    const updated = [...lines];
    updated.splice(index + 1, 0, { ...targetLine });
    setLines(updated);
  };

  const moveLine = (index: number, direction: 'up' | 'down') => {
    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= lines.length) return;
    const updated = [...lines];
    [updated[index], updated[targetIndex]] = [updated[targetIndex], updated[index]];
    setLines(updated);
  };

  const removeLine = (index: number) => {
    if (lines.length > 1) {
      setLines(lines.filter((_, currentIndex) => currentIndex !== index));
    }
  };

  const getLineTotal = (line: QuotationLine) => {
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    const taxRate = line.tax_rate === '' ? 0 : line.tax_rate;
    const discount = line.discount_amount === '' ? 0 : line.discount_amount;
    const base = line.quantity * unitPrice;
    const discounted = base - discount;
    const tax = discounted * (taxRate / 100);
    return discounted + tax;
  };

  const subtotal = lines.reduce((sum, line) => {
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    return sum + line.quantity * unitPrice;
  }, 0);
  const taxTotal = lines.reduce((sum, line) => {
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    const taxRate = line.tax_rate === '' ? 0 : line.tax_rate;
    const discount = line.discount_amount === '' ? 0 : line.discount_amount;
    const base = line.quantity * unitPrice;
    return sum + (base - discount) * (taxRate / 100);
  }, 0);
  const lineDiscounts = lines.reduce((sum, line) => sum + (line.discount_amount === '' ? 0 : line.discount_amount), 0);
  const discountAmt = discountAmount === '' ? 0 : discountAmount;
  const total = subtotal + taxTotal - lineDiscounts - discountAmt;
  const quoteNumberPreview = `QUO-${new Date(quotationDate).getFullYear()}-XXXX`;

  const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (!customerId) {
      toast({ variant: 'destructive', title: 'Please select a customer' });
      return;
    }

    if (lines.length === 0 || lines.every((line) => !line.description && !line.product_id)) {
      toast({ variant: 'destructive', title: 'Please add at least one line item' });
      return;
    }

    const payload = {
      customer_id: parseInt(customerId, 10),
      quotation_date: quotationDate,
      valid_until: validUntil,
      customer_reference: customerReference || undefined,
      internal_reference: internalReference || undefined,
      attention_to: attentionTo || undefined,
      contact_email: contactEmail || undefined,
      contact_phone: contactPhone || undefined,
      billing_address_snapshot: billingAddressSnapshot || undefined,
      shipping_address_snapshot: shippingAddressSnapshot || undefined,
      salesperson: salesperson || undefined,
      delivery_lead_time: deliveryLeadTime || undefined,
      shipping_method: shippingMethod || undefined,
      shipping_terms: shippingTerms || undefined,
      validity: validity || undefined,
      notes: notes || undefined,
      terms: terms || undefined,
      discount_amount: discountAmt || 0,
    lines: lines
      .filter((line) => line.description || line.product_id)
      .map((line) => ({
        product_id: line.product_id ? parseInt(line.product_id, 10) : null,
        model_number: line.model_number || null,
        description: line.description || 'Item',
        product_notes: line.product_notes || null,
        quantity: line.quantity,
        unit_price: line.unit_price === '' ? 0 : line.unit_price,
        tax_rate: line.tax_rate === '' ? 0 : line.tax_rate,
        discount_amount: line.discount_amount === '' ? 0 : line.discount_amount,
      })),
    };

    createMutation.mutate(payload);
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" onClick={() => navigate('/quotations')}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">Create Quotation</h1>
            <p className="text-muted-foreground mt-1">
              Prepare a quotation for your customer. Confirm to create a Sales Order.
            </p>
          </div>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Quotation Details</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Customer *</label>
                <select
                  value={customerId}
                  onChange={(event) => setCustomerId(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  required
                >
                  <option value="">Select customer...</option>
                  {customers?.map((customer) => (
                    <option key={customer.id} value={customer.id}>
                      {customer.name}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Quote Date</label>
                <input
                  type="date"
                  value={quotationDate}
                  onChange={(event) => setQuotationDate(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Valid Until</label>
                <input
                  type="date"
                  value={validUntil}
                  onChange={(event) => setValidUntil(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Quotation Number</label>
                <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2">
                  <div className="text-sm font-semibold text-slate-900">{quoteNumberPreview}</div>
                  <div className="text-xs text-slate-500 mt-1">Final sequence assigned when the quotation is created.</div>
                </div>
              </div>
            </div>

            <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Customer Reference</label>
                <input
                  type="text"
                  value={customerReference}
                  onChange={(event) => setCustomerReference(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="PO / customer code"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Internal Reference</label>
                <input
                  type="text"
                  value={internalReference}
                  onChange={(event) => setInternalReference(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="Sales order / project"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Attention To</label>
                <input
                  type="text"
                  value={attentionTo}
                  onChange={(event) => setAttentionTo(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="Primary contact"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Contact Email</label>
                <input
                  type="email"
                  value={contactEmail}
                  onChange={(event) => setContactEmail(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="billing@customer.com"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Contact Phone</label>
                <input
                  type="text"
                  value={contactPhone}
                  onChange={(event) => setContactPhone(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="Phone or mobile"
                />
              </div>
            </div>

            <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Billing Address</label>
                <textarea
                  value={billingAddressSnapshot}
                  onChange={(event) => setBillingAddressSnapshot(event.target.value)}
                  rows={2}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="Billing address"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Shipping Address</label>
                <textarea
                  value={shippingAddressSnapshot}
                  onChange={(event) => setShippingAddressSnapshot(event.target.value)}
                  rows={2}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="Delivery address"
                />
              </div>
            </div>

            <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Salesperson</label>
                <input
                  type="text"
                  value={salesperson}
                  onChange={(event) => setSalesperson(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="Assigned salesperson"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Delivery Lead Time</label>
                <input
                  type="text"
                  value={deliveryLeadTime}
                  onChange={(event) => setDeliveryLeadTime(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="e.g. 2 weeks"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Shipping Method</label>
                <input
                  type="text"
                  value={shippingMethod}
                  onChange={(event) => setShippingMethod(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="e.g. Sea freight, Air"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Shipping Terms</label>
                <input
                  type="text"
                  value={shippingTerms}
                  onChange={(event) => setShippingTerms(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="e.g. FOB, CIF, EXW"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Validity</label>
                <input
                  type="text"
                  value={validity}
                  onChange={(event) => setValidity(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="e.g. 30 days"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <div>
                <label className="block text-sm font-medium mb-1">Notes</label>
                <textarea
                  value={notes}
                  onChange={(event) => setNotes(event.target.value)}
                  rows={2}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Internal notes..."
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Terms</label>
                <textarea
                  value={terms}
                  onChange={(event) => setTerms(event.target.value)}
                  rows={2}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Payment terms..."
                />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="mb-6">
          <CardHeader>
            <CardTitle className="flex items-center justify-between">
              <span>Line Items</span>
              <div className="flex flex-wrap gap-2">
                <SalesLineColumnMenu columns={QUOTATION_CREATE_COLUMNS} hidden={hiddenLineColumns} setHidden={setHiddenLineColumns} />
                <Button type="button" variant="outline" size="sm" onClick={addLine}>
                  <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-1" />
                  Add Line
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={() => duplicateLine(lines.length - 1)} disabled={lines.length === 0}>
                  <Icon icon="solar:copy-bold-duotone" className="h-4 w-4 mr-1" />
                  Duplicate Last
                </Button>
              </div>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <SalesLineColumnVisibility tableClass="quotation-create-columns" columns={QUOTATION_CREATE_COLUMNS} hiddenColumns={hiddenLineColumns} onHiddenColumnsChange={setHiddenLineColumns} showMenu={false}>
              <table className="w-full text-sm">
                <thead>
    <tr className="border-b">
      <th className="text-left py-2 px-2 w-12">#</th>
      <th className="text-left py-2 px-2 w-[200px]">Product</th>
      <th className="text-left py-2 px-2 w-24">SKU</th>
      <th className="text-left py-2 px-2">Description</th>
      <th className="text-left py-2 px-2 w-28">Model #</th>
      <th className="text-left py-2 px-2 w-36">Product Notes</th>
      <th className="text-right py-2 px-2 w-20">Qty</th>
      <th className="text-right py-2 px-2 w-28">Unit Price</th>
      <th className="text-right py-2 px-2 w-20">Tax %</th>
      <th className="text-right py-2 px-2 w-28">Line Total</th>
      <th className="py-2 px-2 w-32">Actions</th>
    </tr>
                </thead>
                <tbody>
                  {lines.map((line, index) => (
                    <tr key={index} className="border-b">
                      <td className="py-2 px-2 text-sm font-medium text-slate-500">{index + 1}</td>
                      <td className="py-2 px-2 w-[200px] max-w-[200px] overflow-hidden">
                        <ProductSearchCombobox
                          value={line.product_id}
                          onValueChange={(val, product) => {
                            updateLine(index, 'product_id', val)
                            if (product) {
                              const updated = [...lines]
                              updated[index] = {
                                ...updated[index],
                                product_id: val,
                                description: product.name || updated[index].description,
                                unit_price: parseFloat((product as any).price || (product as any).selling_price || '0') || updated[index].unit_price,
                                model_number: (product as any).manufacturer_part_number || updated[index].model_number,
                                sku: (product as any).sku || updated[index].sku,
                              }
                              setLines(updated)
                            }
                          }}
                          placeholder="Search product..."
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="text"
                          value={line.sku}
                          readOnly
                          className="w-full px-2 py-1 border border-gray-200 dark:border-gray-700 rounded bg-gray-50 dark:bg-gray-900 text-sm text-slate-500"
                          placeholder="SKU"
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="text"
                          value={line.description}
                          onChange={(event) => updateLine(index, 'description', event.target.value)}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                          placeholder="Description"
                          required
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="text"
                          value={line.model_number}
                          readOnly
                          className="w-full px-2 py-1 border border-gray-200 dark:border-gray-700 rounded bg-gray-50 dark:bg-gray-900 text-sm text-slate-500"
                          placeholder="Model #"
                        />
                      </td>
                      <td className="py-2 px-2">
                        <textarea
                          value={line.product_notes}
                          onChange={(event) => updateLine(index, 'product_notes', event.target.value)}
                          rows={2}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                          placeholder="Product notes"
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="number"
                          min="0"
                          value={line.quantity}
                          onChange={(event) => updateLine(index, 'quantity', Math.max(0, parseInt(event.target.value, 10) || 0))}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          placeholder="0.00"
                          value={line.unit_price === '' ? '' : line.unit_price}
                          onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="number"
                          min="0"
                          max="100"
                          step="0.01"
                          placeholder="0"
                          value={line.tax_rate === '' ? '' : line.tax_rate}
                          onChange={(event) => updateLine(index, 'tax_rate', event.target.value === '' ? '' : parseFloat(event.target.value))}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                        />
                      </td>
                      <td className="py-2 px-2 text-right font-medium">
                        {formatCurrency(getLineTotal(line))}
                      </td>
                      <td className="py-2 px-2">
                        <div className="flex items-center justify-end gap-1">
                          <Button type="button" variant="ghost" size="sm" onClick={() => moveLine(index, 'up')} disabled={index === 0}>
                            <Icon icon="solar:arrow-up-bold-duotone" className="h-4 w-4" />
                          </Button>
                          <Button type="button" variant="ghost" size="sm" onClick={() => moveLine(index, 'down')} disabled={index === lines.length - 1}>
                            <Icon icon="solar:arrow-down-bold-duotone" className="h-4 w-4" />
                          </Button>
                          <Button type="button" variant="ghost" size="sm" onClick={() => duplicateLine(index)}>
                            <Icon icon="solar:copy-bold-duotone" className="h-4 w-4" />
                          </Button>
                          {lines.length > 1 && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => removeLine(index)}>
                              <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4 text-destructive" />
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </SalesLineColumnVisibility>
            </div>

            <div className="mt-6 flex justify-end">
              <div className="w-full max-w-sm space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal:</span>
                  <span className="font-medium">{formatCurrency(subtotal)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Tax:</span>
                  <span className="font-medium">{formatCurrency(taxTotal)}</span>
                </div>
                {lineDiscounts > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Discount:</span>
                    <span className="font-medium text-green-600">-{formatCurrency(lineDiscounts)}</span>
                  </div>
                )}
                <div className="flex justify-between text-lg font-bold pt-2 border-t">
                  <span>Total:</span>
                  <span className="text-primary">{formatCurrency(total)}</span>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <div className="flex justify-end gap-3">
          <Button type="button" variant="outline" onClick={() => navigate('/quotations')}>
            Cancel
          </Button>
          <Button type="submit" disabled={createMutation.isPending}>
            {createMutation.isPending ? (
              <>
                <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2" />
                Creating...
              </>
            ) : (
              <>
                <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 mr-2" />
                Create Quotation
              </>
            )}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default QuotationCreatePage;
