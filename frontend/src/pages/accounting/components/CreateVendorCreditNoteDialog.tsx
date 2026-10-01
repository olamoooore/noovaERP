import { useState, useEffect, useCallback } from 'react';
import { useCreateVendorCreditNote } from '@/hooks/useVendorCreditNotes';
import type { CreateVendorCreditNotePayload } from '@/types/vendorCreditNote';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
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

interface CreateVendorCreditNoteDialogProps {
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

export default function CreateVendorCreditNoteDialog({ onClose, onSuccess, billId }: CreateVendorCreditNoteDialogProps) {
  const { toast } = useToast();
  const [bills, setBills] = useState<Bill[]>([]);
  const [selectedBill, setSelectedBill] = useState<Bill | null>(null);
  const [open, setOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [formData, setFormData] = useState({
    bill_id: billId ?? 0,
    vendor_id: 0,
    credit_note_date: new Date().toISOString().split('T')[0],
    reason: '',
    notes: '',
    lines: [] as any[],
  });

  const createMutation = useCreateVendorCreditNote();

  // Fetch posted bills
  useEffect(() => {
    setIsLoading(true);
    api.get('/accounting/vendor-bills', {
      params: {
        status: 'posted,partial,unpaid',
        per_page: 100,
      }
    })
      .then(res => {
        const data = (res.data.data || res.data || []) as Bill[];
        setBills(data);
        // If billId is provided, auto-select it
        if (billId) {
          const bill = data.find((b: Bill) => b.id === billId);
          if (bill) {
            selectBill(bill);
          }
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

  // Filter bills locally by search query
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
      toast({ variant: 'destructive', title: 'Bill required',         description: 'Please select a bill for this credit note.' });
      return;
    }

    if (formData.lines.length === 0) {
      toast({ variant: 'destructive', title: 'No line items', description: 'Please add at least one line item.' });
      return;
    }

    try {
      await createMutation.mutateAsync(formData as CreateVendorCreditNotePayload);
      toast({ title: 'Credit note created', description: 'The credit note has been created successfully.' });
      onSuccess();
    } catch (error: any) {
      toast({ variant: 'destructive', title: 'Failed to create credit note', description: error.response?.data?.message || 'Failed to create credit note.' });
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

  const creditNoteTotal = calculateTotal();
  const billTotal = selectedBill ? Number(selectedBill.total) || 0 : 0;
  const exceedsBill = selectedBill && creditNoteTotal > billTotal;

  return (
    <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>Create Credit Note</DialogTitle>
          <DialogDescription>Create a credit note for a vendor bill</DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-5">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <Label className="mb-1 block">
                Select Bill *
              </Label>
              <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                  <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={isLoading}
                    className={cn(
                      'w-full justify-between font-normal',
                      !selectedBill && 'text-muted-foreground'
                    )}
                  >
                    <span className="truncate">{getDisplayValue()}</span>
                    <div className="flex items-center gap-1 ml-2 shrink-0">
                      {selectedBill && (
                        <Icon
                          icon="solar:close-circle-bold"
                          className="h-4 w-4 opacity-50 hover:opacity-100 cursor-pointer"
                          onClick={handleClear}
                        />
                      )}
                      <Icon
                        icon="solar:alt-arrow-down-linear"
                        className={cn('h-4 w-4 transition-transform', open && 'rotate-180')}
                      />
                    </div>
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[400px] p-0" align="start">
                  <Command shouldFilter={false}>
                    <CommandInput
                      placeholder="Search by bill number or vendor..."
                      value={searchQuery}
                      onValueChange={setSearchQuery}
                    />
                    <CommandList>
                      {isLoading ? (
                        <div className="py-6 text-center text-sm text-muted-foreground">
                          <Icon icon="solar:refresh-bold" className="h-4 w-4 animate-spin inline mr-2" />
                          Loading bills...
                        </div>
                      ) : filteredBills.length === 0 ? (
                        <CommandEmpty>
                          {searchQuery ? 'No bills found.' : 'No bills available.'}
                        </CommandEmpty>
                      ) : (
                        <CommandGroup heading={`Bills (${filteredBills.length})`}>
                          {filteredBills.map((bill) => (
                            <CommandItem
                              key={bill.id}
                              value={String(bill.id)}
                              onSelect={handleSelect}
                              className="flex items-center justify-between py-3"
                            >
                              <div className="flex flex-col">
                                <span className="font-medium">{bill.bill_number}</span>
                                <span className="text-xs text-muted-foreground">
                                  {bill.vendor?.name ?? 'Unknown'} · ${(Number(bill.total) || 0).toFixed(2)}
                                </span>
                              </div>
                              <div className="flex items-center gap-2">
                                <span className="text-xs text-muted-foreground capitalize">{bill.status}</span>
                                {selectedBill?.id === bill.id && (
                                  <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-primary" />
                                )}
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
              <Label className="mb-1 block">
                Credit Note Date *
              </Label>
              <Input
                type="date"
                value={formData.credit_note_date}
                onChange={(e) => setFormData(prev => ({ ...prev, credit_note_date: e.target.value }))}
                required
              />
            </div>

            <div className="md:col-span-2">
              <Label className="mb-1 block">
                Reason
              </Label>
              <Input
                type="text"
                value={formData.reason}
                onChange={(e) => setFormData(prev => ({ ...prev, reason: e.target.value }))}
                placeholder="e.g., Product return, Billing error"
                
              />
            </div>

            <div className="md:col-span-2">
              <Label className="mb-1 block">
                Notes
              </Label>
              <Textarea
                value={formData.notes}
                onChange={(e) => setFormData(prev => ({ ...prev, notes: e.target.value }))}
                rows={3}
                
              />
            </div>
          </div>

          {selectedBill && formData.lines.length > 0 && (
            <div className="mb-6">
              {exceedsBill && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-destructive">
                  <Icon icon="solar:danger-triangle-bold" className="h-5 w-5 shrink-0" />
                  <p className="text-sm">
                    <strong>Warning:</strong> Credit note total (${creditNoteTotal.toFixed(2)}) exceeds bill total (${billTotal.toFixed(2)}). The bill's remaining balance may not cover this amount.
                  </p>
                </div>
              )}
              <h3 className="mb-3 text-lg font-semibold">Line Items</h3>
              <div className="overflow-hidden rounded-lg border">
                <table className="w-full">
                  <thead className="bg-muted/50">
                    <tr>
                      <th className="px-4 py-2 text-left text-xs font-medium text-muted-foreground">Description</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-muted-foreground">Quantity</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-muted-foreground">Unit Price</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-muted-foreground">Tax %</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-muted-foreground">Total</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
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
                            className="w-20 rounded border border-input bg-background px-2 py-1"
                          />
                        </td>
                        <td className="px-4 py-2">
                          <input
                            type="number"
                            value={line.unit_price}
                            onChange={(e) => handleLineChange(index, 'unit_price', Number(e.target.value))}
                            min="0"
                            step="0.01"
                            className="w-24 rounded border border-input bg-background px-2 py-1"
                          />
                        </td>
                        <td className="px-4 py-2 text-sm">{line.tax_rate}%</td>
                        <td className="px-4 py-2 text-sm font-medium">
                          ${calculateLineTotal(line).toFixed(2)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="bg-muted/50">
                    <tr>
                      <td colSpan={4} className="px-4 py-2 text-right text-sm text-muted-foreground">Bill Total:</td>
                      <td className="px-4 py-2 text-sm font-medium">${billTotal.toFixed(2)}</td>
                    </tr>
                    <tr>
                      <td colSpan={4} className="px-4 py-2 text-right font-semibold">Credit Note Total:</td>
                      <td className={cn('px-4 py-2 font-bold', exceedsBill && 'text-destructive')}>${creditNoteTotal.toFixed(2)}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" data-enter-submit disabled={createMutation.isPending}>
              {createMutation.isPending ? 'Creating...' : 'Create Credit Note'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
