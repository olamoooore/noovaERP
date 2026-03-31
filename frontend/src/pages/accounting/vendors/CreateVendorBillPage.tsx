import React, { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import { useToast } from '@/hooks/use-toast';
import { accountingApi } from '@/lib/api';
import api from '@/lib/api';

interface BillLine {
  account_id: string;
  description: string;
  quantity: number;
  unit_price: number;
  tax_rate: number;
}

const emptyLine = (): BillLine => ({
  account_id: '',
  description: '',
  quantity: 1,
  unit_price: 0,
  tax_rate: 0,
});

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
  const [discountAmount, setDiscountAmount] = useState(0);
  const [lines, setLines] = useState<BillLine[]>([emptyLine()]);

  // Fetch vendors
  const { data: vendors } = useQuery({
    queryKey: ['vendors-list'],
    queryFn: async () => {
      const response = await accountingApi.get('/accounting/vendors');
      return response.data.data || response.data || [];
    },
  });

  // Fetch chart of accounts for expense accounts
  const { data: accounts } = useQuery({
    queryKey: ['chart-of-accounts-list'],
    queryFn: async () => {
      const response = await accountingApi.get('/accounting/chart-of-accounts');
      return response.data.data || response.data || [];
    },
  });

  const createMutation = useMutation({
    mutationFn: async (data: any) => {
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

  const updateLine = (index: number, field: keyof BillLine, value: any) => {
    const updated = [...lines];
    updated[index] = { ...updated[index], [field]: value };
    setLines(updated);
  };

  const addLine = () => setLines([...lines, emptyLine()]);

  const removeLine = (index: number) => {
    if (lines.length > 1) {
      setLines(lines.filter((_, i) => i !== index));
    }
  };

  const getLineTotal = (line: BillLine) => {
    const base = line.quantity * line.unit_price;
    const tax = base * (line.tax_rate / 100);
    return base + tax;
  };

  const subtotal = lines.reduce((sum, l) => sum + l.quantity * l.unit_price, 0);
  const taxTotal = lines.reduce((sum, l) => sum + l.quantity * l.unit_price * (l.tax_rate / 100), 0);
  const total = subtotal + taxTotal - discountAmount;

  const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    if (!vendorId) {
      toast({ variant: 'destructive', title: 'Please select a vendor' });
      return;
    }

    const validLines = lines.filter((l) => l.account_id && l.quantity > 0 && l.unit_price > 0);
    if (validLines.length === 0) {
      toast({ variant: 'destructive', title: 'Please add at least one line item with an account, quantity, and price' });
      return;
    }

    const payload = {
      vendor_id: parseInt(vendorId, 10),
      bill_number: billNumber || undefined,
      bill_date: billDate,
      due_date: dueDate,
      notes: notes || undefined,
      terms: terms || undefined,
      discount_amount: discountAmount || 0,
      lines: validLines.map((l) => ({
        account_id: parseInt(l.account_id, 10),
        description: l.description || undefined,
        quantity: l.quantity,
        unit_price: l.unit_price,
        tax_rate: l.tax_rate,
      })),
    };

    createMutation.mutate(payload);
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" onClick={() => navigate('/accounting/vendors/bills')}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">Create Vendor Bill</h1>
            <p className="text-muted-foreground mt-1">Record a new bill from a vendor</p>
          </div>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        {/* Bill Header */}
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Bill Details</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Vendor *</label>
                <select
                  value={vendorId}
                  onChange={(e) => setVendorId(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  required
                >
                  <option value="">Select vendor...</option>
                  {vendors?.map((v: any) => (
                    <option key={v.id} value={v.id}>
                      {v.name}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Bill Number</label>
                <input
                  type="text"
                  value={billNumber}
                  onChange={(e) => setBillNumber(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Auto-generated if blank"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Bill Date *</label>
                <input
                  type="date"
                  value={billDate}
                  onChange={(e) => setBillDate(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  required
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
            </div>
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
              <Button type="button" variant="outline" size="sm" onClick={addLine}>
                <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-1" />
                Add Line
              </Button>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b">
                    <th className="text-left py-2 px-2">Account *</th>
                    <th className="text-left py-2 px-2">Description</th>
                    <th className="text-right py-2 px-2 w-24">Qty</th>
                    <th className="text-right py-2 px-2 w-32">Unit Price</th>
                    <th className="text-right py-2 px-2 w-24">Tax %</th>
                    <th className="text-right py-2 px-2 w-32">Line Total</th>
                    <th className="py-2 px-2 w-12"></th>
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line, index) => (
                    <tr key={index} className="border-b">
                      <td className="py-2 px-2">
                        <select
                          value={line.account_id}
                          onChange={(e) => updateLine(index, 'account_id', e.target.value)}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                          required
                        >
                          <option value="">Select account...</option>
                          {accounts?.map((a: any) => (
                            <option key={a.id} value={a.id}>
                              {a.code} - {a.name}
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
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          value={line.quantity}
                          onChange={(e) => updateLine(index, 'quantity', parseFloat(e.target.value) || 0)}
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
                        {lines.length > 1 && (
                          <Button type="button" variant="ghost" size="sm" onClick={() => removeLine(index)}>
                            <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4 text-destructive" />
                          </Button>
                        )}
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
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Tax:</span>
                  <span className="font-medium">{formatCurrency(taxTotal)}</span>
                </div>
                {discountAmount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Discount:</span>
                    <span className="font-medium text-green-600">-{formatCurrency(discountAmount)}</span>
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
  );
};

export default CreateVendorBillPage;
