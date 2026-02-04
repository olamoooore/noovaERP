import { useState, useEffect } from 'react';
import { useCreateVendorCreditNote } from '@/hooks/useVendorCreditNotes';
import type { CreateVendorCreditNotePayload } from '@/types/vendorCreditNote';
import axios from 'axios';

interface CreateVendorCreditNoteDialogProps {
  onClose: () => void;
  onSuccess: () => void;
}

interface Bill {
  id: number;
  bill_number: string;
  vendor_id: number;
  vendor_name: string;
  total_amount: number;
  currency_code: string;
  lines: BillLine[];
}

interface BillLine {
  id: number;
  product_id: number | null;
  product_name: string | null;
  description: string;
  quantity: number;
  unit_price: number;
  tax_rate: number;
  line_total: number;
  account_id: number | null;
}

export default function CreateVendorCreditNoteDialog({ onClose, onSuccess }: CreateVendorCreditNoteDialogProps) {
  const [bills, setBills] = useState<Bill[]>([]);
  const [selectedBill, setSelectedBill] = useState<Bill | null>(null);
  const [formData, setFormData] = useState({
    bill_id: 0,
    vendor_id: 0,
    credit_note_date: new Date().toISOString().split('T')[0],
    reason: '',
    notes: '',
    lines: [] as any[],
  });

  const createMutation = useCreateVendorCreditNote();

  useEffect(() => {
    // Fetch posted bills
    axios.get('/api/v1/accounting/vendors/bills', {
      params: { status: 'posted', per_page: 100 }
    })
      .then(res => setBills(res.data.data || []))
      .catch(err => console.error('Failed to load bills:', err));
  }, []);

  const handleBillSelect = (billId: number) => {
    const bill = bills.find(b => b.id === billId);
    if (bill) {
      setSelectedBill(bill);
      setFormData(prev => ({
        ...prev,
        bill_id: bill.id,
        vendor_id: bill.vendor_id,
        lines: bill.lines.map(line => ({
          bill_line_id: line.id,
          product_id: line.product_id,
          description: line.description,
          quantity: line.quantity,
          unit_price: line.unit_price,
          tax_rate: line.tax_rate,
          account_id: line.account_id,
        })),
      }));
    }
  };

  const handleLineChange = (index: number, field: string, value: any) => {
    const newLines = [...formData.lines];
    newLines[index] = { ...newLines[index], [field]: value };
    setFormData(prev => ({ ...prev, lines: newLines }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!formData.bill_id) {
      alert('Please select a bill');
      return;
    }

    if (formData.lines.length === 0) {
      alert('Please add at least one line item');
      return;
    }

    try {
      await createMutation.mutateAsync(formData as CreateVendorCreditNotePayload);
      alert('Credit note created successfully');
      onSuccess();
    } catch (error: any) {
      alert(error.response?.data?.message || 'Failed to create credit note');
    }
  };

  const calculateLineTotal = (line: any) => {
    const subtotal = line.quantity * line.unit_price;
    const tax = subtotal * (line.tax_rate / 100);
    return subtotal + tax;
  };

  const calculateTotal = () => {
    return formData.lines.reduce((sum, line) => sum + calculateLineTotal(line), 0);
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
        <div className="p-6 border-b border-gray-200">
          <h2 className="text-2xl font-bold text-gray-900">Create Vendor Credit Note</h2>
          <p className="text-gray-600 mt-1">Create a credit note for a vendor bill</p>
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Select Bill *
              </label>
              <select
                value={formData.bill_id}
                onChange={(e) => handleBillSelect(Number(e.target.value))}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                required
              >
                <option value="">-- Select Bill --</option>
                {bills.map(bill => (
                  <option key={bill.id} value={bill.id}>
                    {bill.bill_number} - {bill.vendor_name} ({bill.currency_code} {bill.total_amount.toFixed(2)})
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Credit Note Date *
              </label>
              <input
                type="date"
                value={formData.credit_note_date}
                onChange={(e) => setFormData(prev => ({ ...prev, credit_note_date: e.target.value }))}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                required
              />
            </div>

            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Reason
              </label>
              <input
                type="text"
                value={formData.reason}
                onChange={(e) => setFormData(prev => ({ ...prev, reason: e.target.value }))}
                placeholder="e.g., Product return, Billing error"
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Notes
              </label>
              <textarea
                value={formData.notes}
                onChange={(e) => setFormData(prev => ({ ...prev, notes: e.target.value }))}
                rows={3}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          {selectedBill && formData.lines.length > 0 && (
            <div className="mb-6">
              <h3 className="text-lg font-semibold text-gray-900 mb-3">Line Items</h3>
              <div className="border border-gray-200 rounded-lg overflow-hidden">
                <table className="w-full">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">Description</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">Quantity</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">Unit Price</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">Tax %</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">Total</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200">
                    {formData.lines.map((line, index) => (
                      <tr key={index}>
                        <td className="px-4 py-2 text-sm">{line.description}</td>
                        <td className="px-4 py-2">
                          <input
                            type="number"
                            value={line.quantity}
                            onChange={(e) => handleLineChange(index, 'quantity', Number(e.target.value))}
                            min="0"
                            step="0.01"
                            className="w-20 px-2 py-1 border border-gray-300 rounded"
                          />
                        </td>
                        <td className="px-4 py-2">
                          <input
                            type="number"
                            value={line.unit_price}
                            onChange={(e) => handleLineChange(index, 'unit_price', Number(e.target.value))}
                            min="0"
                            step="0.01"
                            className="w-24 px-2 py-1 border border-gray-300 rounded"
                          />
                        </td>
                        <td className="px-4 py-2 text-sm">{line.tax_rate}%</td>
                        <td className="px-4 py-2 text-sm font-medium">
                          {selectedBill.currency_code} {calculateLineTotal(line).toFixed(2)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="bg-gray-50">
                    <tr>
                      <td colSpan={4} className="px-4 py-2 text-right font-semibold">Total:</td>
                      <td className="px-4 py-2 font-bold">
                        {selectedBill.currency_code} {calculateTotal().toFixed(2)}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          )}

          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={createMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
            >
              {createMutation.isPending ? 'Creating...' : 'Create Credit Note'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
