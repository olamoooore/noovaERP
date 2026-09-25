import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import { useToast } from '@/hooks/use-toast';
import { formatCurrency, getApiErrorMessage } from '@/lib/utils';
import api from '@/lib/api';
import { getProductLineDescription, ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox';
import { SalesLineColumnMenu, SalesLineColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility';
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog';
import { DocumentTextLineRow, getDocumentSectionTotal, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow';
import { LineItemsTable } from '@/components/sales/LineItemsTable';
import { PageHeader } from '@/components/layout/PageHeader';
import { TagPicker } from '@/components/tags/TagPicker';
import {
  LINE_CELL,
  LINE_CELL_BOX,
  LINE_INPUT_NUMERIC,
  LINE_PRODUCT_WRAP,
  LINE_TEXTAREA,
  LINE_VALUE,
  ProductLineActions,
  productLineColumns,
} from '@/components/sales/ProductLineTable';
import { cn } from '@/lib/utils';
import { QuotationCaseInformation, emptyQuotationCaseInformation } from '@/components/sales/QuotationCaseInformation';
import { InternalReferenceTags } from '@/components/sales/InternalReferenceTags';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';

const QUOTATION_CREATE_COLUMNS = ['#', 'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Qty', 'Unit Price', 'Tax %', 'Line Total', 'Actions']
  .map((label, index) => ({ label, index: index + 1 }));

interface QuotationLine {
  line_type?: 'product' | 'note' | 'section';
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

const emptyLine = (): QuotationLine => ({
  line_type: 'product',
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
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>([6])
  const [tags, setTags] = useState<string[]>([])
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null)
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
  const addTextLine = (line_type: 'note' | 'section') => setLines([...lines, { ...emptyLine(), line_type, quantity: 0, unit_price: 0, tax_rate: 0, discount_amount: 0 }]);
  const addCatalogProducts = (selected: Array<{ product: any; quantity: number }>) => { const next = [...lines]; selected.forEach(({ product, quantity }) => { const existing = next.find((line) => line.line_type === 'product' && line.product_id === String(product.id)); if (existing) existing.quantity += quantity; else next.push({ ...emptyLine(), product_id: String(product.id), sku: product.sku || '', description: product.name || '', unit_price: Number(product.price || 0), quantity }); }); setLines(next); };

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

  /** Clear a line in place so a lone row can be emptied without deleting it. */
  const resetLine = (index: number) => {
    setLines((current) => current.map((line, i) => (i === index ? emptyLine() : line)));
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
  const discountValue = discountAmount === '' ? 0 : discountAmount;
  const subtotalAfterLineDiscounts = Math.max(subtotal - lineDiscounts, 0);
  const requestedDocumentDiscount = discountMode === 'percentage' ? subtotal * (discountValue / 100) : discountValue;
  const documentDiscountAmount = Math.min(requestedDocumentDiscount, subtotalAfterLineDiscounts);
  const taxableAmount = Math.max(subtotalAfterLineDiscounts - documentDiscountAmount, 0);
  const taxAfterDocumentDiscount = subtotalAfterLineDiscounts > 0 ? taxTotal * (taxableAmount / subtotalAfterLineDiscounts) : 0;
  const total = taxableAmount + taxAfterDocumentDiscount;
  const [customQuoteNumber, setCustomQuoteNumber] = useState('');
  const quoteNumberPreview = `QUO-${new Date(quotationDate).getFullYear()}-XXXX`;

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
      ...(customQuoteNumber.trim() ? { quotation_number: customQuoteNumber.trim() } : {}),
      quotation_date: quotationDate,
      valid_until: validUntil,
      customer_reference: customerReference || undefined,
      internal_reference: internalReference || undefined,
      credit_terms: creditTerms || undefined,
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
      ...Object.fromEntries(Object.entries(caseInformation).map(([key, value]) => [key, value || null])),
      notes: notes || undefined,
      terms: terms || undefined,
      discount_amount: documentDiscountAmount || 0,
      discount_type: discountMode,
      discount_value: discountValue,
      ...(tags.length ? { tags } : {}),
    lines: lines
      .filter((line) => line.description || line.product_id)
      .map((line) => ({
        line_type: line.line_type || 'product',
        sequence: lines.indexOf(line),
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
      <PageHeader
        title="Create Quotation"
        description="Prepare a quotation for your customer. Confirm to create a Sales Order."
        backTo="/quotations"
      >
        <div className="mt-2">
          <TagPicker value={tags} onChange={setTags} />
        </div>
      </PageHeader>

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
                <label className="block text-sm font-medium mb-1">Quotation Number</label>
                <input
                  type="text"
                  value={customQuoteNumber}
                  onChange={(event) => setCustomQuoteNumber(event.target.value)}
                  placeholder={quoteNumberPreview}
                  className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800"
                />
                <div className="mt-1 text-xs text-slate-500">
                  Leave blank to use the next auto-generated number ({quoteNumberPreview}).
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

        <QuotationCaseInformation value={caseInformation} onChange={setCaseInformation} />

        <Card className="mb-6">
          <CardHeader>
            <CardTitle className="flex items-center justify-between">
              <span>Line Items</span>
              <div className="flex flex-wrap gap-2">
                <SalesLineColumnMenu columns={QUOTATION_CREATE_COLUMNS} hidden={hiddenLineColumns} setHidden={setHiddenLineColumns} />
              </div>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <SalesLineColumnVisibility tableClass="quotation-create-columns" columns={QUOTATION_CREATE_COLUMNS} hiddenColumns={hiddenLineColumns} onHiddenColumnsChange={setHiddenLineColumns} showMenu={false}>
              <LineItemsTable
                variant="raw"
                tableClassName="w-full min-w-[1150px] text-sm"
                headRowClassName="border-b bg-muted/30"
                columns={productLineColumns({
                  sku: true,
                  description: true,
                  modelNumber: true,
                  productNotes: true,
                  taxRate: true,
                })}
              >
                  {lines.map((line, index) => (
                    <tr
                      key={index}
                      className="border-b transition-colors hover:bg-muted/20"
                      draggable={(line.line_type ?? 'product') === 'product'}
                      onDragStart={() => setDraggedLineIndex(index)}
                      onDragOver={(event) => event.preventDefault()}
                      onDrop={() => {
                        if (draggedLineIndex !== null && draggedLineIndex !== index) setLines(reorderDocumentLines(lines, draggedLineIndex, index))
                        setDraggedLineIndex(null)
                      }}
                    >
                      {line.line_type === 'note' || line.line_type === 'section' ? (
                        <DocumentTextLineRow
                          type={line.line_type}
                          text={line.description}
                          colSpan={11}
                          total={line.line_type === 'section' ? formatCurrency(getDocumentSectionTotal(lines, index, getLineTotal)) : undefined}
                          onChange={(text) => updateLine(index, 'description', text)}
                          onDelete={() => removeLine(index)}
                          onDragStart={() => setDraggedLineIndex(index)}
                          onDrop={() => { if (draggedLineIndex !== null) setLines(reorderDocumentLines(lines, draggedLineIndex, index)); setDraggedLineIndex(null) }}
                        />
                      ) : <>
                      <td className={cn(LINE_CELL, 'w-10')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-center text-xs font-medium text-muted-foreground')}>
                          {lines.slice(0, index + 1).filter((item) => (item.line_type ?? 'product') === 'product').length}
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[17rem]', LINE_PRODUCT_WRAP)}>
                        <ProductSearchCombobox
                          value={line.product_id}
                          onValueChange={(val, product) => {
                            const productChanged = val !== lines[index].product_id
                            updateLine(index, 'product_id', val)
                            if (product) {
                              const updated = [...lines]
                              updated[index] = {
                                ...updated[index],
                                product_id: val,
                                description: productChanged
                                  ? getProductLineDescription(product)
                                  : (updated[index].description || getProductLineDescription(product)),
                                // Re-price from the NEW product; only a no-op
                                // re-select of the same product keeps edits.
                                unit_price: productChanged
                                  ? (parseFloat((product as any).price || (product as any).selling_price || '0') || 0)
                                  : updated[index].unit_price,
                                model_number: (product as any).manufacturer_part_number || (productChanged ? '' : updated[index].model_number),
                                sku: (product as any).sku || (productChanged ? '' : updated[index].sku),
                              }
                              setLines(updated)
                            }
                          }}
                          placeholder="Search product..."
                          wrapText
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[10rem]')}>
                        <div className={LINE_VALUE}>{line.sku || <span className="opacity-60">SKU</span>}</div>
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[16rem]')}>
                        <textarea
                          value={line.description}
                          onChange={(event) => updateLine(index, 'description', event.target.value)}
                          rows={2}
                          className={LINE_TEXTAREA}
                          placeholder="Description"
                          required
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[7rem]')}>
                        <div className={LINE_VALUE}>{line.model_number || <span className="opacity-60">Model #</span>}</div>
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[10rem]')}>
                        <textarea
                          value={line.product_notes}
                          onChange={(event) => updateLine(index, 'product_notes', event.target.value)}
                          rows={2}
                          className={LINE_TEXTAREA}
                          placeholder="Product notes"
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                        <div className={LINE_CELL_BOX}>
                        <input
                          type="number"
                          min="0"
                          value={line.quantity}
                          onChange={(event) => {
                            const raw = event.target.value
                            // HR71-08: keep literal intent — empty is allowed
                            // while typing (caught on submit), no silent
                            // substitution of 1 or 0.
                            if (raw === '') { updateLine(index, 'quantity', ''); return }
                            const parsed = parseFloat(raw)
                            if (!Number.isNaN(parsed) && parsed >= 0) updateLine(index, 'quantity', parsed)
                          }}
                          className={LINE_INPUT_NUMERIC}
                        />
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'w-[7rem] min-w-[6rem]')}>
                        <div className={LINE_CELL_BOX}>
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          placeholder="0.00"
                          value={line.unit_price === '' ? '' : line.unit_price}
                          onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
                          className={LINE_INPUT_NUMERIC}
                        />
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'w-[5rem] min-w-[4rem]')}>
                        <div className={LINE_CELL_BOX}>
                        <input
                          type="number"
                          min="0"
                          max="100"
                          step="0.01"
                          placeholder="0"
                          value={line.tax_rate === '' ? '' : line.tax_rate}
                          onChange={(event) => updateLine(index, 'tax_rate', event.target.value === '' ? '' : parseFloat(event.target.value))}
                          className={LINE_INPUT_NUMERIC}
                        />
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'w-[7.5rem] min-w-[6.5rem]')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-end text-sm font-semibold tabular-nums')}>
                          {formatCurrency(getLineTotal(line))}
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'w-[10.5rem]')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-end')}>
                        <ProductLineActions
                          onMoveUp={() => moveLine(index, 'up')}
                          onMoveDown={() => moveLine(index, 'down')}
                          canMoveUp={index !== 0}
                          canMoveDown={index !== lines.length - 1}
                          onDuplicate={() => duplicateLine(index)}
                          onReset={() => resetLine(index)}
                          onDelete={() => removeLine(index)}
                        />
                        </div>
                      </td>
                      </>}
                    </tr>
                  ))}
              </LineItemsTable>
              </SalesLineColumnVisibility>
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
              <ProductCatalogDialog products={products || []} onAdd={addCatalogProducts} />
              <Button type="button" variant="outline" size="sm" onClick={addLine}>
                <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-1" />
                Add Line
              </Button>
              <Button type="button" variant="outline" size="sm" onClick={() => addTextLine('section')}>Add Section</Button>
              <Button type="button" variant="outline" size="sm" onClick={() => addTextLine('note')}>Add Note</Button>
            </div>

            <div className="mt-6 flex justify-end">
              <div className="w-full max-w-sm space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal:</span>
                  <span className="font-medium">{formatCurrency(subtotal)}</span>
                </div>
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="text-muted-foreground">Discount:</span>
                  <div className="flex items-center gap-2">
                    <select value={discountMode} onChange={(event) => setDiscountMode(event.target.value as 'fixed' | 'percentage')} className="rounded border bg-background px-2 py-1 text-sm">
                      <option value="fixed">Fixed</option>
                      <option value="percentage">%</option>
                    </select>
                    <input type="number" min="0" max={discountMode === 'percentage' ? 100 : undefined} step="0.01" value={discountAmount} onChange={(event) => setDiscountAmount(event.target.value === '' ? '' : Number(event.target.value))} className="w-28 rounded border bg-background px-2 py-1 text-right text-sm" placeholder="0" />
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
