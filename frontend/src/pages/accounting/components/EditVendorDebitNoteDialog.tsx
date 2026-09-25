import { useState, useEffect } from 'react';
import { useUpdateVendorDebitNote, useVendorDebitNote } from '@/hooks/useVendorDebitNotes';
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
      <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
        <DialogContent className="sm:max-w-md">
          <div className="py-8 text-center">
            <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary"></div>
            <p className="mt-4 text-muted-foreground">Loading debit note...</p>
          </div>
        </DialogContent>
      </Dialog>
    );
  }

  if (!debitNote) return null;

  return (
    <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Edit Debit Note</DialogTitle>
          <DialogDescription>{debitNote.debit_note_number}</DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label className="mb-1 block">Debit Note Date *</Label>
            <Input
              type="date"
              value={formData.debit_note_date}
              onChange={(e) => setFormData(prev => ({ ...prev, debit_note_date: e.target.value }))}
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

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={updateMutation.isPending}>
              {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
