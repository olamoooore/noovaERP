import { useState, useEffect, useCallback } from 'react';
import { useUpdateCreditNote, useCustomerCreditNote } from '@/hooks/useCustomerCreditNotes';
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
import type { CreateCreditNotePayload } from '@/types/creditNote';
import api from '@/lib/api';

// #518 (CN9): the draft's lines are editable; the server replaces them and
// recalculates totals and tax through the same line calculation it uses at
// create time (tax rates of invoice-linked lines are inherited server-side).
interface EditableLine {
  invoice_line_id: number | null;
  product_id: number | null;
  description: string;
  quantity: number;
  unit_price: number;
  tax_rate: number;
  tax_inclusive: boolean;
  discount_amount: number;
  account_id: number | null;
}

interface CreditNoteLineResponse {
  invoice_line_id?: number | null;
  product_id?: number | null;
  description?: string;
  quantity?: number | string;
  unit_price?: number | string;
  tax_rate?: number | string;
  tax_inclusive?: boolean;
  discount_amount?: number | string | null;
  account_id?: number | null;
}

interface EditCreditNoteDialogProps {
  creditNoteId: number;
  onClose: () => void;
  onSuccess: () => void;
}

export default function EditCreditNoteDialog({ creditNoteId, onClose, onSuccess }: EditCreditNoteDialogProps) {
  const { toast } = useToast();
  const { data: creditNote, isLoading: isLoadingCreditNote } = useCustomerCreditNote(creditNoteId);
  const updateMutation = useUpdateCreditNote();
  const [formData, setFormData] = useState({
    credit_note_date: '',
    reason: '',
    notes: '',
  });
  const [lines, setLines] = useState<EditableLine[]>([]);

  useEffect(() => {
    if (creditNote) {
      setFormData({
        credit_note_date: creditNote.credit_note_date?.split('T')[0] || new Date().toISOString().split('T')[0],
        reason: creditNote.reason || '',
        notes: creditNote.notes || '',
      });
      const sourceLines = ((creditNote as unknown as { lines?: CreditNoteLineResponse[] }).lines) ?? [];
      setLines(sourceLines.map((line) => ({
        invoice_line_id: line.invoice_line_id ?? null,
        product_id: line.product_id ?? null,
        description: line.description ?? '',
        quantity: Number(line.quantity ?? 0),
        unit_price: Number(line.unit_price ?? 0),
        tax_rate: Number(line.tax_rate ?? 0),
        tax_inclusive: Boolean(line.tax_inclusive),
        discount_amount: Number(line.discount_amount ?? 0),
        account_id: line.account_id ?? null,
      })));
    }
  }, [creditNote]);

  const updateLine = (index: number, field: 'quantity' | 'unit_price' | 'discount_amount', value: string) => {
    setLines(prev => prev.map((line, i) => (i === index ? { ...line, [field]: Number(value) } : line)));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      const payload = {
        ...formData,
        ...(lines.length > 0
          ? {
              lines: lines.map(({ tax_rate, ...line }) => ({
                ...line,
                // Invoice-linked lines inherit their rate server-side (CN5);
                // only unlinked lines carry their own rate.
                ...(line.invoice_line_id ? {} : { tax_rate }),
              })),
            }
          : {}),
      };
      await updateMutation.mutateAsync({ id: creditNoteId, creditNote: payload as Partial<CreateCreditNotePayload> });
      toast({ title: 'Updated', description: 'Credit note updated successfully.' });
      onSuccess();
    } catch (error: any) {
      toast({ variant: 'destructive', title: 'Failed to update', description: error.response?.data?.message || 'Failed to update credit note.' });
    }
  };

  if (isLoadingCreditNote) {
    return (
      <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
        <DialogContent className="sm:max-w-md">
          <div className="py-8 text-center">
            <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary"></div>
            <p className="mt-4 text-muted-foreground">Loading credit note...</p>
          </div>
        </DialogContent>
      </Dialog>
    );
  }

  if (!creditNote) {
    return null;
  }

  return (
    <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Edit Credit Note</DialogTitle>
          <DialogDescription>{creditNote.credit_note_number}</DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label className="mb-1 block">Credit Note Date *</Label>
            <Input
              type="date"
              value={formData.credit_note_date}
              onChange={(e) => setFormData(prev => ({ ...prev, credit_note_date: e.target.value }))}
              required
            />
          </div>

          <div>
            <Label className="mb-1 block">Reason</Label>
            <Input
              type="text"
              value={formData.reason}
              onChange={(e) => setFormData(prev => ({ ...prev, reason: e.target.value }))}
              placeholder="e.g., Product return, Billing error"
            />
          </div>

          <div>
            <Label className="mb-1 block">Notes</Label>
            <Textarea
              value={formData.notes}
              onChange={(e) => setFormData(prev => ({ ...prev, notes: e.target.value }))}
              rows={3}
            />
          </div>

          {lines.length > 0 && (
            <div>
              <Label className="mb-1 block">Lines</Label>
              <div className="overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                  <thead className="bg-muted/50 text-muted-foreground">
                    <tr>
                      <th className="px-2 py-1 text-left font-medium">Description</th>
                      <th className="px-2 py-1 text-right font-medium">Qty</th>
                      <th className="px-2 py-1 text-right font-medium">Unit Price</th>
                      <th className="px-2 py-1 text-right font-medium">Discount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {lines.map((line, index) => (
                      <tr key={index} className="border-t">
                        <td className="px-2 py-1">{line.description}</td>
                        <td className="px-2 py-1">
                          <Input
                            type="number"
                            min="0.01"
                            step="0.01"
                            aria-label={`Quantity for line ${index + 1}`}
                            value={line.quantity}
                            onChange={(e) => updateLine(index, 'quantity', e.target.value)}
                            className="text-right"
                            required
                          />
                        </td>
                        <td className="px-2 py-1">
                          <Input
                            type="number"
                            min="0"
                            step="0.01"
                            aria-label={`Unit price for line ${index + 1}`}
                            value={line.unit_price}
                            onChange={(e) => updateLine(index, 'unit_price', e.target.value)}
                            className="text-right"
                            required
                          />
                        </td>
                        <td className="px-2 py-1">
                          <Input
                            type="number"
                            min="0"
                            step="0.01"
                            aria-label={`Discount for line ${index + 1}`}
                            value={line.discount_amount}
                            onChange={(e) => updateLine(index, 'discount_amount', e.target.value)}
                            className="text-right"
                          />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                Totals and tax are recalculated when you save.
              </p>
            </div>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={updateMutation.isPending}>
              {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
