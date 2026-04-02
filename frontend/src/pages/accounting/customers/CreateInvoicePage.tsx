import React, { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';

interface InvoiceLine {
  product_id: string;
  description: string;
  quantity: number;
  unit_price: number;
  tax_rate: number;
  discount_amount: number;
}

const emptyLine = (): InvoiceLine => ({
  product_id: '',
  description: '',
  quantity: 1,
  unit_price: 0,
  tax_rate: 0,
  discount_amount: 0,
});

export const CreateInvoicePage: React.FC = () => {
  const navigate = useNavigate();
  const { toast } = useToast();

  const [customerId, setCustomerId] = useState('');
  const [invoiceDate, setInvoiceDate] = useState(new Date().toISOString().split('T')[0]);
  const [dueDate, setDueDate] = useState(
    new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]
  );
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed');
  const [discountAmount, setDiscountAmount] = useState(0);
  const [lines, setLines] = useState<InvoiceLine[]>([emptyLine()]);

  // Fetch customers
  const { data: customers } = useQuery({
    queryKey: ['customers-list'],
    queryFn: async () => {
      const response = await api.get('/customers?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  // Fetch products
  const { data: products } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const response = await api.get('/products?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const createMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post('/invoices', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({ title: 'Invoice created successfully' });
      const invoiceId = data?.id || data?.data?.id;
      if (invoiceId) {
        navigate(`/accounting/customers/bills/${invoiceId}`);
      } else {
        navigate('/accounting/customers/bills');
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create invoice',
        description: error.response?.data?.message || error.response?.data?.error || 'An error occurred',
      });
    },
  });

  const updateLine = (index: number, field: keyof InvoiceLine, value: any) => {
    const updated = [...lines];
    updated[index] = { ...updated[index], [field]: value };

    // Auto-fill description and price from selected product
    if (field === 'product_id' && value && products) {
      const product = products.find((p: any) => String(p.id) === String(value));
      if (product) {
        updated[index].description = product.name || product.description || '';
        updated[index].unit_price = parseFloat(product.price || product.selling_price || '0');
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
    if (targetIndex < 0 || targetIndex >= lines.length) {
      return;
    }

    const updated = [...lines];
    [updated[index], updated[targetIndex]] = [updated[targetIndex], updated[index]];
    setLines(updated);
  };

  const removeLine = (index: number) => {
    if (lines.length > 1) {
      setLines(lines.filter((_, i) => i !== index));
    }
  };

  const getLineTotal = (line: InvoiceLine) => {
    const base = line.quantity * line.unit_price;
    const tax = base * (line.tax_rate / 100);
    return base + tax - line.discount_amount;
  };

  const subtotal = lines.reduce((sum, l) => sum + l.quantity * l.unit_price, 0);
  const taxTotal = lines.reduce((sum, l) => sum + l.quantity * l.unit_price * (l.tax_rate / 100), 0);
  const lineDiscounts = lines.reduce((sum, l) => sum + l.discount_amount, 0);
  const documentDiscountAmount = discountMode === 'percentage'
    ? subtotal * (discountAmount / 100)
    : discountAmount;
  const total = subtotal + taxTotal - lineDiscounts - documentDiscountAmount;
  const selectedCustomer = customers?.find((customer: any) => String(customer.id) === String(customerId));
  const invoiceNumberPreview = `INV-${new Date(invoiceDate).getFullYear()}-XXXX`;

  const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    if (!customerId) {
      toast({ variant: 'destructive', title: 'Please select a customer' });
      return;
    }

    if (lines.length === 0 || lines.every((l) => !l.description && !l.product_id)) {
      toast({ variant: 'destructive', title: 'Please add at least one line item' });
      return;
    }

    const payload = {
      customer_id: parseInt(customerId, 10),
      invoice_date: invoiceDate,
      due_date: dueDate,
      notes: notes || undefined,
      terms: terms || undefined,
      discount_amount: documentDiscountAmount || 0,
      lines: lines
        .filter((l) => l.description || l.product_id)
        .map((l) => ({
          product_id: l.product_id ? parseInt(l.product_id, 10) : null,
          description: l.description || 'Item',
          quantity: l.quantity,
          unit_price: l.unit_price,
          tax_rate: l.tax_rate,
          discount_amount: l.discount_amount,
        })),
    };

    createMutation.mutate(payload);
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" onClick={() => navigate('/accounting/customers/bills')}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">Create Invoice</h1>
            <p className="text-muted-foreground mt-1">Create a new customer invoice</p>
          </div>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        {/* Invoice Header */}
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Invoice Details</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Customer *</label>
                <select
                  value={customerId}
                  onChange={(e) => setCustomerId(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  required
                >
                  <option value="">Select customer...</option>
                  {customers?.map((c: any) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Invoice Date</label>
                <input
                  type="date"
                  value={invoiceDate}
                  onChange={(e) => setInvoiceDate(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Due Date</label>
                <input
                  type="date"
                  value={dueDate}
                  onChange={(e) => setDueDate(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Document Number</label>
                <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2">
                  <div className="text-sm font-semibold text-slate-900">{invoiceNumberPreview}</div>
                  <div className="text-xs text-slate-500 mt-1">Final sequence assigned when the invoice is created.</div>
                </div>
              </div>
            </div>

            <div className="mt-4 flex flex-wrap gap-2">
              <Button type="button" variant="outline" size="sm" onClick={() => navigate('/customers')}>
                <Icon icon="solar:users-group-rounded-bold-duotone" className="h-4 w-4 mr-2" />
                Manage Customers
              </Button>
              {selectedCustomer && (
                <Button type="button" variant="outline" size="sm" onClick={() => navigate(`/customers/${selectedCustomer.id}`)}>
                  <Icon icon="solar:user-id-bold-duotone" className="h-4 w-4 mr-2" />
                  Open Customer Profile
                </Button>
              )}
              <Button type="button" variant="outline" size="sm" onClick={() => navigate('/products')}>
                <Icon icon="solar:box-bold-duotone" className="h-4 w-4 mr-2" />
                Product List
              </Button>
              <Button type="button" variant="outline" size="sm" onClick={() => navigate('/products/new')}>
                <Icon icon="solar:add-square-bold-duotone" className="h-4 w-4 mr-2" />
                Create Product
              </Button>
            </div>

            {selectedCustomer && (
              <div className="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div>
                  <div className="text-xs uppercase tracking-wide text-slate-500">Customer</div>
                  <div className="mt-1 font-medium text-slate-900">{selectedCustomer.name}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-slate-500">Email / Phone</div>
                  <div className="mt-1 text-sm text-slate-700">{selectedCustomer.email || selectedCustomer.phone || selectedCustomer.mobile || 'Not captured'}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-slate-500">Business Reference</div>
                  <div className="mt-1 text-sm text-slate-700">{selectedCustomer.tax_id || selectedCustomer.code || 'No tax or registration reference on file'}</div>
                </div>
              </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <div>
                <label className="block text-sm font-medium mb-1">Notes</label>
                <textarea
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  rows={2}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Internal notes..."
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Terms</label>
                <textarea
                  value={terms}
                  onChange={(e) => setTerms(e.target.value)}
                  rows={2}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Payment terms..."
                />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Line Items */}
        <Card className="mb-6">
          <CardHeader>
            <CardTitle className="flex items-center justify-between">
              <span>Line Items</span>
              <div className="flex flex-wrap gap-2">
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
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b">
                    <th className="text-left py-2 px-2 w-12">#</th>
                    <th className="text-left py-2 px-2">Product</th>
                    <th className="text-left py-2 px-2">Description</th>
                    <th className="text-right py-2 px-2 w-24">Qty</th>
                    <th className="text-right py-2 px-2 w-32">Unit Price</th>
                    <th className="text-right py-2 px-2 w-24">Tax %</th>
                    <th className="text-right py-2 px-2 w-32">Line Total</th>
                    <th className="py-2 px-2 w-40">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line, index) => (
                    <tr key={index} className="border-b">
                      <td className="py-2 px-2 text-sm font-medium text-slate-500">{index + 1}</td>
                      <td className="py-2 px-2">
                        <select
                          value={line.product_id}
                          onChange={(e) => updateLine(index, 'product_id', e.target.value)}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                        >
                          <option value="">-- None --</option>
                          {products?.map((p: any) => (
                            <option key={p.id} value={p.id}>
                              {p.name} {p.sku ? `(${p.sku})` : ''}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="text"
                          value={line.description}
                          onChange={(e) => updateLine(index, 'description', e.target.value)}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                          placeholder="Description"
                          required
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="number"
                          min="1"
                          value={line.quantity}
                          onChange={(e) => updateLine(index, 'quantity', parseInt(e.target.value) || 1)}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          value={line.unit_price}
                          onChange={(e) => updateLine(index, 'unit_price', parseFloat(e.target.value) || 0)}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="number"
                          min="0"
                          max="100"
                          step="0.01"
                          value={line.tax_rate}
                          onChange={(e) => updateLine(index, 'tax_rate', parseFloat(e.target.value) || 0)}
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
            </div>

            {/* Totals */}
            <div className="mt-6 flex justify-end">
              <div className="w-full max-w-sm space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal:</span>
                  <span className="font-medium">{formatCurrency(subtotal)}</span>
                </div>
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="text-muted-foreground">Document Discount:</span>
                  <div className="flex items-center gap-2">
                    <select
                      value={discountMode}
                      onChange={(e) => setDiscountMode(e.target.value as 'fixed' | 'percentage')}
                      className="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                    >
                      <option value="fixed">Fixed</option>
                      <option value="percentage">%</option>
                    </select>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={discountAmount}
                      onChange={(e) => setDiscountAmount(parseFloat(e.target.value) || 0)}
                      className="w-28 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                    />
                  </div>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Tax:</span>
                  <span className="font-medium">{formatCurrency(taxTotal)}</span>
                </div>
                {(documentDiscountAmount > 0 || lineDiscounts > 0) && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Discount:</span>
                    <span className="font-medium text-green-600">
                      -{formatCurrency(documentDiscountAmount + lineDiscounts)}
                    </span>
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

        {/* Actions */}
        <div className="flex justify-end gap-3">
          <Button type="button" variant="outline" onClick={() => navigate('/accounting/customers/bills')}>
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
                Create Invoice
              </>
            )}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default CreateInvoicePage;
