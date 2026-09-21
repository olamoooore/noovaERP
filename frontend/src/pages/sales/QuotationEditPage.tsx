import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import { useToast } from '@/hooks/use-toast';
import { getApiErrorMessage } from '@/lib/utils';
import api from '@/lib/api';
import { getProductLineDescription, ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox';
import { SalesLineColumnMenu, SalesLineColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility';
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog';
import { DocumentTextLineRow, getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow';
import { QuotationCaseInformation, emptyQuotationCaseInformation } from '@/components/sales/QuotationCaseInformation';
import { InternalReferenceTags } from '@/components/sales/InternalReferenceTags';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';

const QUOTATION_EDIT_COLUMNS = ['#', 'Product', 'Description', 'Model #', 'Product Notes', 'Qty', 'Unit Price', 'Tax %', 'Discount', 'Line Total', 'Actions']
  .map((label, index) => ({ label, index: index + 1 }));

interface QuotationLine {
  line_type?: 'product' | 'note' | 'section';
  product_id: string;
  model_number: string;
  description: string;
  product_notes: string;
  quantity: number;
  unit_price: number | '';
  tax_rate: number | '';
  discount_amount: number | '';
}

const emptyLine = (): QuotationLine => ({
  line_type: 'product',
  product_id: '',
  model_number: '',
  description: '',
  product_notes: '',
  quantity: 1,
  unit_price: '',
  tax_rate: '',
  discount_amount: '',
});

export const QuotationEditPage: React.FC = () => {
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>([5])
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null)
  const { id } = useParams();
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
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed');
  const [lines, setLines] = useState<QuotationLine[]>([emptyLine()]);
  const [customerReference, setCustomerReference] = useState('');
  const [internalReference, setInternalReference] = useState('');
  const [creditTerms, setCreditTerms] = useState('');
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
  const [caseInformation, setCaseInformation] = useState(emptyQuotationCaseInformation);

  const { data: products } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const response = await api.get('/products?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const { data: quotation, isLoading } = useQuery({
    queryKey: ['quotation', id],
    queryFn: async () => {
      const response = await api.get(`/quotations/${id}`);
      return response.data;
    },
    enabled: !!id,
  });

  // Pre-fill the form once the quotation loads.
  useEffect(() => {
    if (!quotation) return;
    setCustomerId(quotation.customer_id ? String(quotation.customer_id) : '');
    setQuotationDate(quotation.quotation_date ? String(quotation.quotation_date).split('T')[0] : new Date().toISOString().split('T')[0]);
    setValidUntil(quotation.valid_until ? String(quotation.valid_until).split('T')[0] : '');
    setNotes(quotation.notes || '');
    setTerms(quotation.terms || '');
    setDiscountMode(quotation.discount_type === 'percentage' ? 'percentage' : 'fixed');
    setDiscountAmount(quotation.discount_value != null ? Number(quotation.discount_value) : (quotation.discount_amount ? Number(quotation.discount_amount) : ''));
    setCustomerReference(quotation.customer_reference || '');
    setInternalReference(quotation.internal_reference || '');
    setCreditTerms(quotation.credit_terms || '');
    setAttentionTo(quotation.attention_to || '');
    setContactEmail(quotation.contact_email || '');
    setContactPhone(quotation.contact_phone || '');
    setBillingAddressSnapshot(quotation.billing_address_snapshot || '');
    setShippingAddressSnapshot(quotation.shipping_address_snapshot || '');
    setSalesperson(quotation.salesperson || '');
    setDeliveryLeadTime(quotation.delivery_lead_time || '');
    setShippingMethod(quotation.shipping_method || '');
    setShippingTerms(quotation.shipping_terms || '');
    setValidity(quotation.validity || '');
    setCaseInformation({
      case_number: quotation.case_number || '',
      date_of_case: quotation.date_of_case ? String(quotation.date_of_case).split('T')[0] : '',
      case_location: quotation.case_location || '',
      patient_name: quotation.patient_name || '',
      cost_center_id: quotation.cost_center_id || '',
      delivery_date: quotation.delivery_date ? String(quotation.delivery_date).split('T')[0] : '',
      pr_no: quotation.pr_no || '',
      contract_no: quotation.contract_no || '',
      plant_code: quotation.plant_code || '',
      company_code: quotation.company_code || '',
    });

    const mappedLines = (quotation.lines || []).map((line: any): QuotationLine => ({
      line_type: line.line_type || 'product',
      product_id: line.product_id ? String(line.product_id) : '',
      model_number: line.model_number || '',
      description: line.description || '',
      product_notes: line.product_notes || '',
      quantity: line.quantity ?? 1,
      unit_price: line.unit_price != null ? Number(line.unit_price) : '',
      tax_rate: line.tax_rate != null ? Number(line.tax_rate) : '',
      discount_amount: line.discount_amount != null ? Number(line.discount_amount) : '',
    }));
    setLines(mappedLines.length > 0 ? mappedLines : [emptyLine()]);
  }, [quotation]);

  const updateMutation = useMutation({
    mutationFn: async (data: unknown) => {
      const response = await api.put(`/quotations/${id}`, data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['quotation', id] });
      queryClient.invalidateQueries({ queryKey: ['quotations'] });
      toast({ title: 'Quotation updated successfully' });
      navigate(`/quotations/${id}`);
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update quotation',
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
      updated[index].unit_price = parseFloat(product.price || product.selling_price || '0');
      updated[index].model_number = product.manufacturer_part_number || '';
    }
  }

    setLines(updated);
  };

  const addLine = () => setLines([...lines, emptyLine()]);
  const addNote = () => setLines([...lines, { ...emptyLine(), line_type: 'note', description: '', quantity: 0, unit_price: 0, tax_rate: 0, discount_amount: 0 }]);
  const addSection = () => setLines([...lines, { ...emptyLine(), line_type: 'section', description: '', quantity: 0, unit_price: 0, tax_rate: 0, discount_amount: 0 }]);
  const addCatalogProducts = (items: Array<{ product: any; quantity: number }>) => {
    const updated = [...lines];
    items.forEach(({ product, quantity }) => {
      const existing = updated.find((line) => line.line_type !== 'note' && line.product_id === String(product.id));
      if (existing) existing.quantity += quantity;
      else updated.push({ ...emptyLine(), product_id: String(product.id), description: product.name || '', unit_price: Number(product.price || product.selling_price || 0), quantity });
    });
    setLines(updated);
  };

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
    if (line.line_type === 'note' || line.line_type === 'section') return 0;
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
  const discountValue = discountAmount === '' ? 0 : discountAmount;
  const subtotalAfterLineDiscounts = Math.max(subtotal - lineDiscounts, 0);
  const requestedDocumentDiscount = discountMode === 'percentage' ? subtotal * (discountValue / 100) : discountValue;
  const documentDiscountAmount = Math.min(requestedDocumentDiscount, subtotalAfterLineDiscounts);
  const taxableAmount = Math.max(subtotalAfterLineDiscounts - documentDiscountAmount, 0);
  const taxAfterDocumentDiscount = subtotalAfterLineDiscounts > 0 ? taxTotal * (taxableAmount / subtotalAfterLineDiscounts) : 0;
  const total = taxableAmount + taxAfterDocumentDiscount;

  const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (!customerId) {
      toast({ variant: 'destructive', title: 'Please select a customer' });
      return;
    }

    const missingProductIndex = lines.findIndex((line) =>
      (line.line_type ?? 'product') === 'product' && !line.product_id
    );
    if (missingProductIndex >= 0) {
      toast({
        variant: 'destructive',
        title: `Product required on line ${missingProductIndex + 1}`,
        description: 'Select a product before saving.',
      });
      return;
    }

    if (discountMode === 'percentage' && discountValue > 100) {
      toast({ variant: 'destructive', title: 'Percentage discount cannot exceed 100%' });
      return;
    }

    const payload = {
      customer_id: parseInt(customerId, 10),
      quotation_date: quotationDate,
      valid_until: validUntil || null,
      customer_reference: customerReference || null,
      internal_reference: internalReference || null,
      credit_terms: creditTerms || null,
      attention_to: attentionTo || null,
      contact_email: contactEmail || null,
      contact_phone: contactPhone || null,
      billing_address_snapshot: billingAddressSnapshot || null,
      shipping_address_snapshot: shippingAddressSnapshot || null,
      salesperson: salesperson || null,
      delivery_lead_time: deliveryLeadTime || null,
      shipping_method: shippingMethod || null,
      shipping_terms: shippingTerms || null,
      validity: validity || null,
      ...Object.fromEntries(Object.entries(caseInformation).map(([key, value]) => [key, value || null])),
      notes: notes || null,
      terms: terms || null,
      discount_amount: documentDiscountAmount || 0,
      discount_type: discountMode,
      discount_value: discountValue,
  lines: lines
    .filter((line) => line.description || line.product_id)
    .map((line) => ({
      line_type: line.line_type || 'product',
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

    updateMutation.mutate(payload);
  };

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="py-12 text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
          <p className="mt-4 text-sm text-muted-foreground">Loading quotation...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" onClick={() => navigate(`/quotations/${id}`)}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">Edit Quotation</h1>
            <p className="text-muted-foreground mt-1">
              {quotation?.quotation_number ? `Editing ${quotation.quotation_number}` : 'Update quotation details'}
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
                <CustomerSearchCombobox
                  value={customerId}
                  onValueChange={setCustomerId}
                  placeholder="Search customer name or code..."
                />
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
                <label className="block text-sm font-medium mb-1">Customer Reference</label>
                <input
                  type="text"
                  value={customerReference}
                  onChange={(event) => setCustomerReference(event.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  placeholder="PO / customer code"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <div>
                <label className="block text-sm font-medium mb-1">Internal Reference</label>
                <InternalReferenceTags value={internalReference} onChange={setInternalReference} />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Credit Terms</label>
                <input type="text" value={creditTerms} onChange={(event) => setCreditTerms(event.target.value)} className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm" placeholder="e.g. Net 30 days" />
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

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
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

        <QuotationCaseInformation value={caseInformation} onChange={setCaseInformation} />

        <Card className="mb-6">
          <CardHeader>
            <CardTitle className="flex items-center justify-between">
              <span>Line Items</span>
              <div className="flex flex-wrap gap-2">
                <SalesLineColumnMenu columns={QUOTATION_EDIT_COLUMNS} hidden={hiddenLineColumns} setHidden={setHiddenLineColumns} />
              </div>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <SalesLineColumnVisibility tableClass="quotation-edit-columns" columns={QUOTATION_EDIT_COLUMNS} hiddenColumns={hiddenLineColumns} onHiddenColumnsChange={setHiddenLineColumns} showMenu={false}>
              <table className="w-full min-w-[1450px] table-fixed text-sm">
                <thead>
  <tr className="border-b">
    <th className="text-left py-2 px-2 w-12">#</th>
    <th className="text-left py-2 px-2 w-[260px]">Product</th>
    <th className="text-left py-2 px-2 w-[320px]">Description</th>
    <th className="text-left py-2 px-2 w-32">Model #</th>
    <th className="text-left py-2 px-2 w-40">Product Notes</th>
    <th className="text-right py-2 px-2 w-24">Qty</th>
    <th className="text-right py-2 px-2 w-32">Unit Price</th>
    <th className="text-right py-2 px-2 w-24">Tax %</th>
    <th className="text-right py-2 px-2 w-24">Disc</th>
    <th className="text-right py-2 px-2 w-32">Line Total</th>
    <th className="py-2 px-2 w-40">Actions</th>
  </tr>
                </thead>
                <tbody>
                  {lines.map((line, index) => (
                    <tr key={index} className="border-b" draggable={(line.line_type ?? 'product') === 'product'} onDragStart={() => setDraggedLineIndex(index)} onDragOver={(e) => e.preventDefault()} onDrop={() => { if (draggedLineIndex !== null && draggedLineIndex !== index) { const reordered = [...lines]; const [dragged] = reordered.splice(draggedLineIndex, 1); reordered.splice(index, 0, dragged); setLines(reordered); } setDraggedLineIndex(null) }}>
                      {line.line_type === 'note' || line.line_type === 'section' ? <DocumentTextLineRow type={line.line_type} text={line.description} colSpan={11} total={line.line_type === 'section' ? formatCurrency(getDocumentSectionTotal(lines, index, getLineTotal)) : undefined} onChange={(text) => updateLine(index, 'description', text)} onDelete={() => removeLine(index)} onDragStart={() => setDraggedLineIndex(index)} onDrop={() => { if (draggedLineIndex !== null && draggedLineIndex !== index) { const reordered = [...lines]; const [dragged] = reordered.splice(draggedLineIndex, 1); reordered.splice(index, 0, dragged); setLines(reordered); } setDraggedLineIndex(null); }} /> : <>
                      <td className="py-2 px-2 text-sm font-medium text-slate-500">{index + 1}</td>
                      <td className="py-2 px-2 align-top whitespace-normal break-words">
                        <ProductSearchCombobox
                          value={line.product_id}
                          onValueChange={(val, product) => {
                            updateLine(index, 'product_id', val)
                            if (product) {
                              const updated = [...lines]
                              updated[index] = {
                                ...updated[index],
                                product_id: val,
                                description: getProductLineDescription(product) || updated[index].description,
                                unit_price: parseFloat((product as any).price || (product as any).selling_price || '0') || updated[index].unit_price,
                              }
                              setLines(updated)
                            }
                          }}
                          placeholder="Search product..."
                          wrapText
                        />
                      </td>
                      <td className="py-2 px-2 align-top">
                        <textarea
                          value={line.description}
                          onChange={(event) => updateLine(index, 'description', event.target.value)}
                          rows={2}
                          className="w-full whitespace-pre-wrap break-words px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm resize-y"
                          placeholder="Description"
                          required
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="text"
                          value={line.model_number}
                          onChange={(event) => updateLine(index, 'model_number', event.target.value)}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
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
                      <td className="py-2 px-2">
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          placeholder="0.00"
                          value={line.discount_amount === '' ? '' : line.discount_amount}
                          onChange={(event) => updateLine(index, 'discount_amount', event.target.value === '' ? '' : parseFloat(event.target.value))}
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
                      </>}
                    </tr>
                  ))}
                </tbody>
              </table>
              </SalesLineColumnVisibility>
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
              <ProductCatalogDialog products={products || []} onAdd={addCatalogProducts} />
              <Button type="button" variant="outline" size="sm" onClick={addLine}>
                <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-1" />
                Add Line
              </Button>
              <Button type="button" variant="outline" size="sm" onClick={addSection}>Add Section</Button>
              <Button type="button" variant="outline" size="sm" onClick={addNote}>Add Note</Button>
            </div>

            <div className="mt-6 flex justify-end">
              <div className="w-full max-w-sm space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal:</span>
                  <span className="font-medium">{formatCurrency(subtotal)}</span>
                </div>
                <div className="flex items-center justify-between gap-3 text-sm">
                  <label htmlFor="quotation-header-discount" className="text-muted-foreground">Discount:</label>
                  <div className="flex items-center gap-2">
                    <select
                      aria-label="Header discount type"
                      value={discountMode}
                      onChange={(event) => setDiscountMode(event.target.value as 'fixed' | 'percentage')}
                      className="rounded border bg-background px-2 py-1 text-sm"
                    >
                      <option value="fixed">Fixed</option>
                      <option value="percentage">%</option>
                    </select>
                    <input
                      id="quotation-header-discount"
                      type="number"
                      min="0"
                      max={discountMode === 'percentage' ? 100 : undefined}
                      step="0.01"
                      value={discountAmount}
                      onChange={(event) => setDiscountAmount(event.target.value === '' ? '' : Number(event.target.value))}
                      className="w-28 rounded border bg-background px-2 py-1 text-right text-sm"
                      placeholder="0"
                    />
                  </div>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Tax:</span>
                  <span className="font-medium">{formatCurrency(taxAfterDocumentDiscount)}</span>
                </div>
                {(lineDiscounts > 0 || documentDiscountAmount > 0) && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Discount:</span>
                    <span className="font-medium text-green-600">-{formatCurrency(lineDiscounts + documentDiscountAmount)}</span>
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
          <Button type="button" variant="outline" onClick={() => navigate(`/quotations/${id}`)}>
            Cancel
          </Button>
          <Button type="submit" disabled={updateMutation.isPending}>
            {updateMutation.isPending ? (
              <>
                <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2" />
                Saving...
              </>
            ) : (
              <>
                <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 mr-2" />
                Save Changes
              </>
            )}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default QuotationEditPage;
