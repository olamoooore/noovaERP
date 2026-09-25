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
import api from '@/lib/api';

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

  useEffect(() => {
    if (creditNote) {
      setFormData({
        credit_note_date: creditNote.credit_note_date?.split('T')[0] || new Date().toISOString().split('T')[0],
        reason: creditNote.reason || '',
        notes: creditNote.notes || '',
      });
    }
  }, [creditNote]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      await updateMutation.mutateAsync({ id: creditNoteId, creditNote: formData });
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
      <DialogContent className="sm:max-w-lg">
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
