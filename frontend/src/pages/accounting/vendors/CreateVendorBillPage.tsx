import React, { useEffect, useState } from 'react';
import { preventImplicitSubmit } from '@/lib/prevent-enter-submit';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import { useToast } from '@/hooks/use-toast';
import { accountingApi } from '@/lib/api';
import { formatCurrency, NON_WHOLE_QUANTITY_KEYS, toWholeQuantity } from '@/lib/utils';
import { ProductSearchCombobox, getProductLineDescription } from '@/components/inventory/ProductSearchCombobox';
import { VendorSearchCombobox } from '@/components/ui/VendorSearchCombobox';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog';
import { DocumentTotalsCard } from '@/components/sales/DocumentTotalsCard';
import { LineItemsTable } from '@/components/sales/LineItemsTable';
import {
  ProductLineActions,
  productLineColumns,
  LINE_CELL,
  LINE_CELL_BOX,
  LINE_INPUT,
  LINE_INPUT_NUMERIC,
  LINE_TEXTAREA,
  LINE_VALUE,
} from '@/components/sales/ProductLineTable';
import { DocumentTextLineRow, getDocumentSectionTotal, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow';
import { resolveLineTaxRate } from '@/lib/lineTax';
import { cn } from '@/lib/utils';

const formatLegacyAddress = (address: string | null | undefined): string => {
  if (!address) return '';
  try {
    const parsed = JSON.parse(address);
    const parts = [
      parsed.first_name || parsed.name || '',
      parsed.last_name || '',
      parsed.address || parsed.street || '',
      parsed.street2 || '',
      parsed.city || '',
      parsed.state || '',
      parsed.postal_code || parsed.zip || '',
      parsed.country || '',
    ].filter(Boolean);
    return parts.join(', ');
  } catch {
    return address;
  }
};

type BillLineType = 'product' | 'note' | 'section';

interface BillLine {
  line_type: BillLineType;
  product_id: string;
  sku: string;
  model_number: string;
  product_notes: string;
  lot_number: string;
  serial_number: string;
  expiry_date: string;
  description: string;
  quantity: number | '';
  unit_price: number | '';
  tax_rate: number | '';
}

interface VendorRecord {
  id: number;
  name?: string;
  email?: string | null;
  phone?: string | null;
  company?: string | null;
  tax_number?: string | null;
  company_registration_number?: string | null;
  address?: string | null;
  contact_person?: string | null;
  website?: string | null;
  payment_terms?: number | null;
  notes?: string | null;
  is_active?: boolean;
}

interface VendorDocumentSnapshot {
  vendor_reference: string;
  internal_reference: string;
  attention_to: string;
  contact_email: string;
  contact_phone: string;
  vendor_address_snapshot: string;
  remittance_address_snapshot: string;
  company_registration_number: string;
  tax_registration_number: string;
}

const emptyLine = (): BillLine => ({
  line_type: 'product',
  product_id: '',
  sku: '',
  model_number: '',
  product_notes: '',
  lot_number: '',
  serial_number: '',
  expiry_date: '',
  description: '',
  quantity: 1,
  unit_price: '',
  tax_rate: '',
});

const emptyDocumentSnapshot: VendorDocumentSnapshot = {
  vendor_reference: '',
  internal_reference: '',
  attention_to: '',
  contact_email: '',
  contact_phone: '',
  vendor_address_snapshot: '',
  remittance_address_snapshot: '',
  company_registration_number: '',
  tax_registration_number: '',
};

export const CreateVendorBillPage: React.FC = () => {
  const navigate = useNavigate();
  const { toast } = useToast();

  const [vendorId, setVendorId] = useState('');
  const [billNumber, setBillNumber] = useState('');
  const [billDate, setBillDate] = useState(new Date().toISOString().split('T')[0]);
  const [dueDate, setDueDate] = useState(
    new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]
  );
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed');
  const [discountAmount, setDiscountAmount] = useState<number | ''>('');
  const [lines, setLines] = useState<BillLine[]>([emptyLine()]);
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null);
  // Same field toggles and defaults as the invoice editor (parity 2026-10-01).
  const [visibleLineColumns, setVisibleLineColumns] = useState<Record<string, boolean>>(() => {
    const defaults = { sku: true, description: true, model_number: true, product_notes: false, lot_number: true, expiry_date: true, tax_rate: true };
    try {
      return { ...defaults, ...JSON.parse(localStorage.getItem('bill-line-columns-v1') || '{}') };
    } catch {
      return defaults;
    }
  });
  const [documentSnapshot, setDocumentSnapshot] = useState<VendorDocumentSnapshot>(emptyDocumentSnapshot);

  const { data: vendors } = useQuery<VendorRecord[]>({
    queryKey: ['vendors-list'],
    queryFn: async () => {
      const response = await accountingApi.get('/accounting/vendors?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const { data: selectedVendorDetail } = useQuery<VendorRecord>({
    queryKey: ['vendor-document', vendorId],
    queryFn: async () => {
      const response = await accountingApi.get(`/accounting/vendors/${vendorId}`);
      return response.data.data;
    },
    enabled: Boolean(vendorId),
  });

  const { data: products = [] } = useQuery<any[]>({
    queryKey: ['products-list'],
    queryFn: async () => {
      const response = await accountingApi.get('/products?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const selectedVendor = selectedVendorDetail
    ?? vendors?.find((vendor) => String(vendor.id) === String(vendorId));

  useEffect(() => {
    if (!selectedVendor) {
      return;
    }

    setDocumentSnapshot({
      vendor_reference: selectedVendor.company ?? selectedVendor.name ?? '',
      internal_reference: '',
      attention_to: selectedVendor.contact_person ?? selectedVendor.name ?? '',
      contact_email: selectedVendor.email ?? '',
      contact_phone: selectedVendor.phone ?? '',
      vendor_address_snapshot: formatLegacyAddress(selectedVendor.address),
      remittance_address_snapshot: formatLegacyAddress(selectedVendor.address),
      company_registration_number: selectedVendor.company_registration_number ?? '',
      tax_registration_number: selectedVendor.tax_number ?? '',
    });
  }, [selectedVendor]);

  const createMutation = useMutation({
    mutationFn: async (data: unknown) => {
      const response = await accountingApi.post('/accounting/vendor-bills', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({ title: 'Vendor bill created successfully' });
      const billId = data?.data?.id || data?.id;
      if (billId) {
        navigate(`/accounting/vendors/bills/${billId}`);
      } else {
        navigate('/accounting/vendors/bills');
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create vendor bill',
        description: error.response?.data?.message || error.response?.data?.error || 'An error occurred',
      });
    },
  });

  // Product lines mirror the invoice editor: picking a product fills the
  // description, cost, tax rate and capture fields. The expense account is
  // derived server-side (product's own account, else the tenant mapping).
  const selectProduct = (index: number, productId: string, product?: any) => {
    const updated = [...lines];
    const line: BillLine = {
      ...updated[index],
      line_type: 'product',
      product_id: productId,
      lot_number: '',
      serial_number: '',
      expiry_date: '',
    };
    if (product) {
      line.description = getProductLineDescription(product);
      line.unit_price = Number(product.cost ?? 0);
      line.model_number = product.manufacturer_part_number || '';
      line.sku = product.sku || '';
      line.tax_rate = resolveLineTaxRate(product, 0) ?? '';
    }
    updated[index] = line;
    setLines(updated);
  };

  const updateDocumentSnapshot = (field: keyof VendorDocumentSnapshot, value: string) => {
    setDocumentSnapshot((current) => ({
      ...current,
      [field]: value,
    }));
  };

  const addLine = () => setLines([...lines, emptyLine()]);

  const addTextLine = (line_type: 'note' | 'section') => {
    setLines([...lines, { ...emptyLine(), line_type, quantity: 0, unit_price: 0, tax_rate: 0 }]);
  };

  const addCatalogProducts = (items: Array<{ product: any; quantity: number }>) => {
    const next = [...lines];
    items.forEach(({ product, quantity }) => {
      const existing = next.find((line) => line.line_type === 'product' && line.product_id === String(product.id));
      if (existing) {
        existing.quantity = Number(existing.quantity || 0) + quantity;
      } else {
        next.push({
          ...emptyLine(),
          product_id: String(product.id),
          sku: product.sku || '',
          description: getProductLineDescription(product),
          model_number: product.manufacturer_part_number || '',
          unit_price: Number(product.cost ?? 0),
          quantity,
          tax_rate: resolveLineTaxRate(product, 0) ?? '',
        });
      }
    });
    setLines(next);
  };

  // Re-price every product line from the product's cost (bills are costed
  // from the catalogue, unlike invoices which re-run the pricing engine).
  const recalculateFromCost = () => {
    const byId = new Map((products as any[]).map((product) => [String(product.id), product]));
    setLines((current) => current.map((line) => {
      if (line.line_type !== 'product' || !line.product_id) return line;
      const product = byId.get(line.product_id);
      if (!product) return line;
      const cost = Number(product.cost ?? 0);
      return {
        ...line,
        unit_price: cost > 0 ? cost : line.unit_price,
        tax_rate: resolveLineTaxRate(product, 0) ?? line.tax_rate,
      };
    }));
    toast({ title: 'Recalculated from product cost' });
  };

  const duplicateLine = (index: number) => {
    const targetLine = lines[index];
    if (!targetLine) return;
    const updated = [...lines];
    updated.splice(index + 1, 0, { ...targetLine });
    setLines(updated);
  };

  const resetLine = (index: number) => {
    const updated = [...lines];
    updated[index] = emptyLine();
    setLines(updated);
  };

  const moveLine = (index: number, direction: 'up' | 'down') => {
    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= lines.length) {
      return;
    }

    const updated = [...lines];
    [updated[index], updated[targetIndex]] = [updated[targetIndex], updated[index]];
    setLines(updated);
  };

  const removeLine = (index: number) => {
    if (lines.length > 1) {
      setLines(lines.filter((_, currentIndex) => currentIndex !== index));
    } else {
      resetLine(index);
    }
  };

  const toggleLineColumn = (key: string) => {
    setVisibleLineColumns((current) => {
      const next = { ...current, [key]: !current[key] };
      localStorage.setItem('bill-line-columns-v1', JSON.stringify(next));
      return next;
    });
  };

  const getLineTotal = (line: BillLine) => {
    if (line.line_type !== 'product') return 0;
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    const taxRate = line.tax_rate === '' ? 0 : line.tax_rate;
    const base = Number(line.quantity || 0) * unitPrice;
    return base + base * (taxRate / 100);
  };

  const productLines = lines.filter((line) => line.line_type === 'product');
  const subtotal = productLines.reduce((sum, line) => {
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    return sum + Number(line.quantity || 0) * unitPrice;
  }, 0);
  const taxTotal = productLines.reduce((sum, line) => {
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    const taxRate = line.tax_rate === '' ? 0 : line.tax_rate;
    return sum + Number(line.quantity || 0) * unitPrice * (taxRate / 100);
  }, 0);
  const discountAmt = discountAmount === '' ? 0 : discountAmount;
  const documentDiscountAmount = discountMode === 'percentage'
    ? subtotal * (discountAmt / 100)
    : discountAmt;
  const discountedSubtotal = subtotal - documentDiscountAmount;
  // Mirror TaxEngineService per line: allocate the document discount
  // proportionally (rounded), then tax the reduced base.
  const discountRatio = subtotal > 0 ? documentDiscountAmount / subtotal : 0;
  const adjustedTaxTotal = productLines.reduce((sum, line) => {
    const base = Number(line.quantity || 0) * (line.unit_price === '' ? 0 : line.unit_price);
    const allocated = Math.min(Math.max(Math.round(base * discountRatio * 100) / 100, 0), Math.max(base, 0));
    const taxable = Math.round((base - allocated) * 100) / 100;
    const rate = line.tax_rate === '' ? 0 : line.tax_rate;
    return sum + (rate > 0 ? Math.round(taxable * (rate / 100) * 100) / 100 : 0);
  }, 0);
  const total = discountedSubtotal + adjustedTaxTotal;

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (!vendorId) {
      toast({ variant: 'destructive', title: 'Please select a vendor' });
      return;
    }

    if (!billDate) {
      toast({ variant: 'destructive', title: 'Please enter the bill date' });
      return;
    }

    const validLines = lines.filter((line) => line.line_type === 'product'
      ? Boolean(line.product_id) && Number(line.quantity || 0) > 0 && line.unit_price !== ''
      : line.description.trim() !== '');
    if (!validLines.some((line) => line.line_type === 'product' && line.product_id)) {
      toast({ variant: 'destructive', title: 'Please add at least one product line with a quantity and price' });
      return;
    }

    const payload = {
      vendor_id: parseInt(vendorId, 10),
      bill_number: billNumber || undefined,
      bill_date: billDate,
      due_date: dueDate,
      notes: notes || undefined,
      terms: terms || undefined,
      discount_amount: documentDiscountAmount || 0,
      ...documentSnapshot,
      lines: validLines.map((line) => ({
        line_type: line.line_type,
        product_id: line.product_id ? parseInt(line.product_id, 10) : undefined,
        model_number: line.model_number || undefined,
        product_notes: line.product_notes || undefined,
        lot_number: line.lot_number || undefined,
        serial_number: line.serial_number || undefined,
        expiry_date: line.expiry_date || undefined,
        description: line.description || undefined,
        quantity: line.quantity === '' ? 0 : line.quantity,
        unit_price: line.unit_price === '' ? 0 : line.unit_price,
        tax_rate: line.tax_rate === '' ? 0 : line.tax_rate,
      })),
    };

    createMutation.mutate(payload);
  };

  return (
    <>
      <div className="p-6 space-y-6">
        <div>
          <Button
            variant="ghost"
            size="sm"
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
            onClick={() => navigate('/accounting/vendors/bills')}
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">Create Vendor Bill</h1>
            <p className="text-muted-foreground mt-1">Record a new bill from a vendor</p>
          </div>
        </div>

        <form noValidate onSubmit={handleSubmit} onKeyDown={preventImplicitSubmit}>
          <Card className="mb-6">
            <CardHeader>
              <CardTitle>Bill Details</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Vendor *</label>
                  <VendorSearchCombobox
                    value={vendorId}
                    onValueChange={(id) => setVendorId(id)}
                    placeholder="Select vendor..."
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Bill Number</label>
                  <input
                    type="text"
                    value={billNumber}
                    onChange={(event) => setBillNumber(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Auto-generated if blank"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Bill Date *</label>
                  <input
                    type="date"
                    value={billDate}
                    onChange={(event) => setBillDate(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Due Date</label>
                  <input
                    type="date"
                    value={dueDate}
                    onChange={(event) => setDueDate(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>

              {selectedVendor && (
                <div className="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                  <div>
                    <div className="text-xs uppercase tracking-wide text-slate-500">Vendor</div>
                    <div className="mt-1 font-medium text-slate-900">{selectedVendor.name}</div>
                  </div>
                  <div>
                    <div className="text-xs uppercase tracking-wide text-slate-500">Email / Phone</div>
                    <div className="mt-1 text-sm text-slate-700">{selectedVendor.email || selectedVendor.phone || 'Not captured'}</div>
                  </div>
                  <div>
                    <div className="text-xs uppercase tracking-wide text-slate-500">Business Reference</div>
                    <div className="mt-1 text-sm text-slate-700">
                      {selectedVendor.tax_number || selectedVendor.company_registration_number || selectedVendor.company || 'No tax or registration reference on file'}
                    </div>
                  </div>
                </div>
              )}

              <div className="mt-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Vendor Ref.</label>
                  <input
                    type="text"
                    value={documentSnapshot.vendor_reference}
                    onChange={(event) => updateDocumentSnapshot('vendor_reference', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Supplier invoice / account"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Internal Ref.</label>
                  <input
                    type="text"
                    value={documentSnapshot.internal_reference}
                    onChange={(event) => updateDocumentSnapshot('internal_reference', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="PO / project / request"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Attention To</label>
                  <input
                    type="text"
                    value={documentSnapshot.attention_to}
                    onChange={(event) => updateDocumentSnapshot('attention_to', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Vendor contact"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Email</label>
                  <input
                    type="email"
                    value={documentSnapshot.contact_email}
                    onChange={(event) => updateDocumentSnapshot('contact_email', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="ap@vendor.com"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Phone</label>
                  <input
                    type="text"
                    value={documentSnapshot.contact_phone}
                    onChange={(event) => updateDocumentSnapshot('contact_phone', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Vendor phone"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Company Reg. No.</label>
                  <input
                    type="text"
                    value={documentSnapshot.company_registration_number}
                    onChange={(event) => updateDocumentSnapshot('company_registration_number', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Business registration number"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Tax Registration</label>
                  <input
                    type="text"
                    value={documentSnapshot.tax_registration_number}
                    onChange={(event) => updateDocumentSnapshot('tax_registration_number', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="GST / VAT / TIN"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Vendor Address Snapshot</label>
                  <textarea
                    value={documentSnapshot.vendor_address_snapshot}
                    onChange={(event) => updateDocumentSnapshot('vendor_address_snapshot', event.target.value)}
                    rows={3}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Vendor billing address"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Remittance Address Snapshot</label>
                  <textarea
                    value={documentSnapshot.remittance_address_snapshot}
                    onChange={(event) => updateDocumentSnapshot('remittance_address_snapshot', event.target.value)}
                    rows={3}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Where the vendor expects payment correspondence"
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
                  {/* Same field toggles as the invoice line editor. */}
                  <details className="relative">
                    <summary className="list-none cursor-pointer rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-accent">
                      <Icon icon="solar:settings-bold-duotone" className="inline h-4 w-4 mr-1" />
                      Show/Hide Fields
                    </summary>
                    <div className="absolute right-0 z-20 mt-2 w-52 rounded-md border bg-background p-3 shadow-lg">
                      {[
                        ['sku', 'SKU'], ['description', 'Description'], ['model_number', 'Model #'],
                        ['product_notes', 'Product Notes'], ['lot_number', 'Lot Number'],
                        ['expiry_date', 'Expiry Date'], ['tax_rate', 'Tax %'],
                      ].map(([key, label]) => (
                        <label key={key} className="flex cursor-pointer items-center gap-2 py-1 text-sm">
                          <input type="checkbox" checked={visibleLineColumns[key]} onChange={() => toggleLineColumn(key)} />
                          {label}
                        </label>
                      ))}
                    </div>
                  </details>
                </div>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <LineItemsTable
                  variant="raw"
                  tableClassName="w-full min-w-[1700px] text-sm"
                  headRowClassName="border-b bg-muted/30"
                  columns={productLineColumns({
                    sku: visibleLineColumns.sku,
                    description: visibleLineColumns.description,
                    modelNumber: visibleLineColumns.model_number,
                    productNotes: visibleLineColumns.product_notes,
                    lotNumber: visibleLineColumns.lot_number,
                    expiryDate: visibleLineColumns.expiry_date,
                    taxRate: visibleLineColumns.tax_rate,
                  })}
                >
                  {lines.map((line, index) => (
                    <tr
                      key={index}
                      className="border-b transition-colors hover:bg-muted/20"
                      draggable={line.line_type === 'product'}
                      onDragStart={() => setDraggedLineIndex(index)}
                      onDragOver={(event) => event.preventDefault()}
                      onDrop={() => {
                        if (draggedLineIndex !== null && draggedLineIndex !== index) {
                          setLines(reorderDocumentLines(lines, draggedLineIndex, index));
                        }
                        setDraggedLineIndex(null);
                      }}
                    >
                      {line.line_type === 'note' || line.line_type === 'section' ? (
                        <DocumentTextLineRow
                          type={line.line_type}
                          text={line.description}
                          colSpan={13}
                          total={line.line_type === 'section' ? formatCurrency(getDocumentSectionTotal(lines, index, getLineTotal)) : undefined}
                          onChange={(text) => {
                            const updated = [...lines];
                            updated[index] = { ...updated[index], description: text };
                            setLines(updated);
                          }}
                          onDelete={() => removeLine(index)}
                          onDragStart={() => setDraggedLineIndex(index)}
                          onDrop={() => {
                            if (draggedLineIndex !== null) {
                              setLines(reorderDocumentLines(lines, draggedLineIndex, index));
                            }
                            setDraggedLineIndex(null);
                          }}
                        />
                      ) : <>
                        <td className={cn(LINE_CELL, 'w-10')}>
                          <div className={cn(LINE_CELL_BOX, 'justify-center text-xs font-medium text-muted-foreground')}>
                            {lines.slice(0, index + 1).filter((item) => item.line_type === 'product').length}
                          </div>
                        </td>
                        <td className={cn(LINE_CELL, 'min-w-[17rem]')}>
                          <ProductSearchCombobox
                            value={line.product_id}
                            onValueChange={(productId, product) => selectProduct(index, productId, product)}
                            wrapText
                          />
                        </td>
                        {visibleLineColumns.sku && <td className={cn(LINE_CELL, 'min-w-[7rem]')}>
                          <div className={LINE_VALUE}>{line.sku || <span className="opacity-60">SKU</span>}</div>
                        </td>}
                        {visibleLineColumns.description && <td className={cn(LINE_CELL, 'min-w-[16rem]')}>
                          <textarea
                            value={line.description}
                            onChange={(event) => {
                              const updated = [...lines];
                              updated[index] = { ...updated[index], description: event.target.value };
                              setLines(updated);
                            }}
                            rows={2}
                            className={LINE_TEXTAREA}
                            placeholder="Description"
                          />
                        </td>}
                        {visibleLineColumns.model_number && <td className={cn(LINE_CELL, 'min-w-[7rem]')}>
                          <div className={LINE_VALUE}>{line.model_number || <span className="opacity-60">Model #</span>}</div>
                        </td>}
                        {visibleLineColumns.product_notes && <td className={cn(LINE_CELL, 'min-w-[10rem]')}>
                          <textarea
                            value={line.product_notes}
                            onChange={(event) => {
                              const updated = [...lines];
                              updated[index] = { ...updated[index], product_notes: event.target.value };
                              setLines(updated);
                            }}
                            rows={2}
                            className={LINE_TEXTAREA}
                            placeholder="Product notes"
                          />
                        </td>}
                        {visibleLineColumns.lot_number && <td className={cn(LINE_CELL, 'min-w-[7.5rem]')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="text"
                              value={line.lot_number}
                              onChange={(event) => {
                                const updated = [...lines];
                                updated[index] = { ...updated[index], lot_number: event.target.value };
                                setLines(updated);
                              }}
                              className={LINE_INPUT}
                              placeholder="Lot / batch"
                            />
                          </div>
                        </td>}
                        {visibleLineColumns.expiry_date && <td className={cn(LINE_CELL, 'min-w-[9.5rem]')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="date"
                              value={line.expiry_date}
                              onChange={(event) => {
                                const updated = [...lines];
                                updated[index] = { ...updated[index], expiry_date: event.target.value };
                                setLines(updated);
                              }}
                              className={LINE_INPUT}
                            />
                          </div>
                        </td>}
                        <td data-label="Qty" className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="number"
                              min="0"
                              // #1024: quantities are whole numbers; arrows step by 1.
                              step="1"
                              inputMode="numeric"
                              value={line.quantity}
                              onKeyDown={(event) => {
                                if (NON_WHOLE_QUANTITY_KEYS.has(event.key)) event.preventDefault();
                              }}
                              onChange={(event) => {
                                const raw = event.target.value;
                                const whole = toWholeQuantity(raw);
                                if (raw !== '' && whole === '') return;
                                const updated = [...lines];
                                updated[index] = { ...updated[index], quantity: whole };
                                setLines(updated);
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
                              onChange={(event) => {
                                const updated = [...lines];
                                updated[index] = {
                                  ...updated[index],
                                  unit_price: event.target.value === '' ? '' : parseFloat(event.target.value),
                                };
                                setLines(updated);
                              }}
                              className={LINE_INPUT_NUMERIC}
                            />
                          </div>
                        </td>
                        {visibleLineColumns.tax_rate && <td className={cn(LINE_CELL, 'w-[5rem] min-w-[4rem]')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="number"
                              min="0"
                              max="100"
                              step="0.01"
                              placeholder="0"
                              value={line.tax_rate === '' ? '' : line.tax_rate}
                              onChange={(event) => {
                                const updated = [...lines];
                                updated[index] = {
                                  ...updated[index],
                                  tax_rate: event.target.value === '' ? '' : parseFloat(event.target.value),
                                };
                                setLines(updated);
                              }}
                              className={LINE_INPUT_NUMERIC}
                            />
                          </div>
                        </td>}
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
              </div>

              <div className="mt-3 flex flex-wrap gap-2">
                <ProductCatalogDialog products={products as any[]} onAdd={addCatalogProducts} />
                <HeaderActionButton type="button" variant="outline" icon="solar:add-circle-bold-duotone" onClick={addLine}>
                  Add Line
                </HeaderActionButton>
                <HeaderActionButton
                  type="button"
                  variant="outline"
                  icon="solar:refresh-bold-duotone"
                  title="Re-price every product line from its catalogue cost"
                  onClick={recalculateFromCost}
                >
                  Recalculate
                </HeaderActionButton>
                <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('section')}>Add Section</HeaderActionButton>
                <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('note')}>Add Note</HeaderActionButton>
              </div>

              <DocumentTotalsCard
                subtotal={subtotal}
                tax={taxTotal}
                total={total}
                money={formatCurrency}
                discountMode={discountMode}
                onDiscountModeChange={setDiscountMode}
                discountAmount={discountAmount}
                onDiscountAmountChange={setDiscountAmount}
                documentDiscountAmount={documentDiscountAmount}
              />
            </CardContent>
          </Card>

          <div className="flex justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => navigate('/accounting/vendors/bills')}>
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
                  Create Bill
                </>
              )}
            </Button>
          </div>
        </form>
      </div>

    </>
  );
};

export default CreateVendorBillPage;