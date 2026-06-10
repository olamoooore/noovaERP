import { useState, useEffect, useCallback } from 'react';
import { useCreateVendorDebitNote } from '@/hooks/useVendorDebitNotes';
import type { CreateVendorDebitNotePayload } from '@/types/vendorDebitNote';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/hooks/use-toast';
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import api from '@/lib/api';

interface CreateVendorDebitNoteDialogProps {
  onClose: () => void;
  onSuccess: () => void;
  billId?: number;
}

interface Bill {
  id: number;
  bill_number: string;
  vendor_id: number;
  vendor: { name: string } | null;
  total: number;
  balance: number;
  status: string;
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

export default function CreateVendorDebitNoteDialog({ onClose, onSuccess, billId }: CreateVendorDebitNoteDialogProps) {
  const { toast } = useToast();
  const [bills, setBills] = useState<Bill[]>([]);
  const [selectedBill, setSelectedBill] = useState<Bill | null>(null);
  const [open, setOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [formData, setFormData] = useState({
    bill_id: billId ?? 0,
    vendor_id: 0,
    debit_note_date: new Date().toISOString().split('T')[0],
    reason: '',
    notes: '',
    lines: [] as any[],
  });

  const createMutation = useCreateVendorDebitNote();

  useEffect(() => {
    setIsLoading(true);
    api.get('/accounting/vendor-bills', {
      params: { status: 'posted,partial,unpaid', per_page: 100 }
    })
      .then(res => {
        const data = (res.data.data || res.data || []) as Bill[];
        setBills(data);
        if (billId) {
          const bill = data.find((b: Bill) => b.id === billId);
          if (bill) selectBill(bill);
        }
      })
      .catch(err => console.error('Failed to load bills:', err))
      .finally(() => setIsLoading(false));
  }, [billId]);

  const selectBill = useCallback((bill: Bill) => {
    setSelectedBill(bill);
    setFormData(prev => ({
      ...prev,
      bill_id: bill.id,
      vendor_id: bill.vendor_id,
      lines: bill.lines.map((line: BillLine) => ({
        bill_line_id: line.id,
        product_id: line.product_id,
        description: line.description,
        quantity: line.quantity,
        unit_price: line.unit_price,
        tax_rate: line.tax_rate,
        account_id: line.account_id,
      })),
    }));
  }, []);

  const handleSelect = (billIdStr: string) => {
    const id = Number(billIdStr);
    const bill = bills.find(b => b.id === id);
    if (bill) {
      selectBill(bill);
      setOpen(false);
      setSearchQuery('');
    }
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    setSelectedBill(null);
    setFormData(prev => ({ ...prev, bill_id: 0, vendor_id: 0, lines: [] }));
  };

  const getDisplayValue = () => {
    if (!selectedBill) return 'Select a bill...';
    const vendorName = selectedBill.vendor?.name ?? 'Unknown';
    const total = Number(selectedBill.total) || 0;
    return `${selectedBill.bill_number} — ${vendorName} ($${total.toFixed(2)})`;
  };

  const filteredBills = searchQuery
    ? bills.filter(bill =>
        bill.bill_number.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (bill.vendor?.name ?? '').toLowerCase().includes(searchQuery.toLowerCase())
      )
    : bills;

  const handleLineChange = (index: number, field: string, value: any) => {
    const newLines = [...formData.lines];
    newLines[index] = { ...newLines[index], [field]: value };
    setFormData(prev => ({ ...prev, lines: newLines }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!formData.bill_id) {
      toast({ variant: 'destructive', title: 'Bill required', description: 'Please select a bill for this debit note.' });
      return;
    }

    if (formData.lines.length === 0) {
      toast({ variant: 'destructive', title: 'No line items', description: 'Please add at least one line item.' });
      return;
    }

    try {
      await createMutation.mutateAsync(formData as CreateVendorDebitNotePayload);
      toast({ title: 'Debit note created', description: 'The debit note has been created successfully.' });
      onSuccess();
    } catch (error: any) {
      toast({ variant: 'destructive', title: 'Failed to create debit note', description: error.response?.data?.message || 'Failed to create debit note.' });
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

  const debitNoteTotal = calculateTotal();
  const billTotal = selectedBill ? Number(selectedBill.total) || 0 : 0;
  const exceedsBill = selectedBill && debitNoteTotal > billTotal;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
        <div className="p-6 border-b border-gray-200">
          <h2 className="text-2xl font-bold text-gray-900">Create Debit Note</h2>
          <p className="text-gray-600 mt-1">Create a debit note for a vendor bill</p>
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Select Bill *</label>
              <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                  <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={isLoading}
                    className={cn('w-full justify-between font-normal', !selectedBill && 'text-muted-foreground')}
                  >
                    <span className="truncate">{getDisplayValue()}</span>
                    <div className="flex items-center gap-1 ml-2 shrink-0">
                      {selectedBill && (
                        <Icon icon="solar:close-circle-bold" className="h-4 w-4 opacity-50 hover:opacity-100 cursor-pointer" onClick={handleClear} />
                      )}
                      <Icon icon="solar:alt-arrow-down-linear" className={cn('h-4 w-4 transition-transform', open && 'rotate-180')} />
                    </div>
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[400px] p-0" align="start">
                  <Command shouldFilter={false}>
                    <CommandInput placeholder="Search by bill number or vendor..." value={searchQuery} onValueChange={setSearchQuery} />
                    <CommandList>
                      {isLoading ? (
                        <div className="py-6 text-center text-sm text-muted-foreground">
                          <Icon icon="solar:refresh-bold" className="h-4 w-4 animate-spin inline mr-2" />
                          Loading bills...
                        </div>
                      ) : filteredBills.length === 0 ? (
                        <CommandEmpty>{searchQuery ? 'No bills found.' : 'No bills available.'}</CommandEmpty>
                      ) : (
                        <CommandGroup heading={`Bills (${filteredBills.length})`}>
                          {filteredBills.map((bill) => (
                            <CommandItem key={bill.id} value={String(bill.id)} onSelect={handleSelect} className="flex items-center justify-between py-3">
                              <div className="flex flex-col">
                                <span className="font-medium">{bill.bill_number}</span>
                                <span className="text-xs text-muted-foreground">{bill.vendor?.name ?? 'Unknown'} · ${(Number(bill.total) || 0).toFixed(2)}</span>
                              </div>
                              <div className="flex items-center gap-2">
                                <span className="text-xs text-muted-foreground capitalize">{bill.status}</span>
                                {selectedBill?.id === bill.id && <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-primary" />}
                              </div>
                            </CommandItem>
                          ))}
                        </CommandGroup>
                      )}
                    </CommandList>
                  </Command>
                </PopoverContent>
              </Popover>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Debit Note Date *</label>
              <input
                type="date"
                value={formData.debit_note_date}
                onChange={(e) => setFormData(prev => ({ ...prev, debit_note_date: e.target.value }))}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                required
              />
            </div>

            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700 mb-1">Reason</label>
              <input
                type="text"
                value={formData.reason}
                onChange={(e) => setFormData(prev => ({ ...prev, reason: e.target.value }))}
                placeholder="e.g., Product return, Billing error"
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
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
              {exceedsBill && (
                <div className="flex items-center gap-2 p-4 mb-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
                  <Icon icon="solar:danger-triangle-bold" className="h-5 w-5 shrink-0" />
                  <p className="text-sm">
                    <strong>Warning:</strong> Debit note total (${debitNoteTotal.toFixed(2)}) exceeds bill total (${billTotal.toFixed(2)}). The bill's remaining balance may not cover this amount.
                  </p>
                </div>
              )}
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
                          <input type="number" value={line.quantity} onChange={(e) => handleLineChange(index, 'quantity', Number(e.target.value))} min="0" step="0.01" className="w-20 px-2 py-1 border border-gray-300 rounded" />
                        </td>
                        <td className="px-4 py-2">
                          <input type="number" value={line.unit_price} onChange={(e) => handleLineChange(index, 'unit_price', Number(e.target.value))} min="0" step="0.01" className="w-24 px-2 py-1 border border-gray-300 rounded" />
                        </td>
                        <td className="px-4 py-2 text-sm">{line.tax_rate}%</td>
                        <td className="px-4 py-2 text-sm font-medium">${calculateLineTotal(line).toFixed(2)}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="bg-gray-50">
                    <tr>
                      <td colSpan={4} className="px-4 py-2 text-right text-sm text-gray-600">Bill Total:</td>
                      <td className="px-4 py-2 text-sm font-medium">${billTotal.toFixed(2)}</td>
                    </tr>
                    <tr>
                      <td colSpan={4} className="px-4 py-2 text-right font-semibold">Debit Note Total:</td>
                      <td className={`px-4 py-2 font-bold ${exceedsBill ? 'text-red-600' : ''}`}>${debitNoteTotal.toFixed(2)}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          )}

          <div className="flex justify-end gap-3">
            <button type="button" onClick={onClose} className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
            <button type="submit" disabled={createMutation.isPending} className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {createMutation.isPending ? 'Creating...' : 'Create Debit Note'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
