import { useState, useEffect } from 'react';
import { useUpdateVendorDebitNote, useVendorDebitNote } from '@/hooks/useVendorDebitNotes';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/hooks/use-toast';

interface EditVendorDebitNoteDialogProps {
  debitNoteId: number;
  onClose: () => void;
  onSuccess: () => void;
}

export default function EditVendorDebitNoteDialog({ debitNoteId, onClose, onSuccess }: EditVendorDebitNoteDialogProps) {
  const { toast } = useToast();
  const { data: debitNote, isLoading: isLoadingDebitNote } = useVendorDebitNote(debitNoteId);
  const updateMutation = useUpdateVendorDebitNote();
  const [formData, setFormData] = useState({
    debit_note_date: '',
    reason: '',
    notes: '',
  });

  useEffect(() => {
    if (debitNote) {
      setFormData({
        debit_note_date: debitNote.debit_note_date?.split('T')[0] || new Date().toISOString().split('T')[0],
        reason: debitNote.reason || '',
        notes: debitNote.notes || '',
      });
    }
  }, [debitNote]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      await updateMutation.mutateAsync({ id: debitNoteId, debitNote: formData });
      toast({ title: 'Updated', description: 'Debit note updated successfully.' });
      onSuccess();
    } catch (error: any) {
      toast({ variant: 'destructive', title: 'Failed to update', description: error.response?.data?.message || 'Failed to update debit note.' });
    }
  };

  if (isLoadingDebitNote) {
    return (
      <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div className="bg-white rounded-lg shadow-xl p-6 max-w-md w-full">
          <div className="text-center py-8">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
            <p className="text-muted-foreground mt-4">Loading debit note...</p>
          </div>
        </div>
      </div>
    );
  }

  if (!debitNote) return null;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-lg w-full">
        <div className="p-6 border-b">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-xl font-bold">Edit Debit Note</h2>
              <p className="text-sm text-muted-foreground mt-1">{debitNote.debit_note_number}</p>
            </div>
            <Button variant="ghost" size="icon" onClick={onClose}>
              <Icon icon="solar:close-circle-bold" className="h-5 w-5" />
            </Button>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1">Debit Note Date *</label>
            <input
              type="date"
              value={formData.debit_note_date}
              onChange={(e) => setFormData(prev => ({ ...prev, debit_note_date: e.target.value }))}
              className="w-full px-3 py-2 border border-input rounded-md bg-background text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium mb-1">Reason</label>
            <input
              type="text"
              value={formData.reason}
              onChange={(e) => setFormData(prev => ({ ...prev, reason: e.target.value }))}
              placeholder="e.g., Product return, Billing error"
              className="w-full px-3 py-2 border border-input rounded-md bg-background text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            />
          </div>

          <div>
            <label className="block text-sm font-medium mb-1">Notes</label>
            <textarea
              value={formData.notes}
              onChange={(e) => setFormData(prev => ({ ...prev, notes: e.target.value }))}
              rows={3}
              className="w-full px-3 py-2 border border-input rounded-md bg-background text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            />
          </div>

          <div className="flex justify-end gap-3 pt-4">
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={updateMutation.isPending}>
              {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
