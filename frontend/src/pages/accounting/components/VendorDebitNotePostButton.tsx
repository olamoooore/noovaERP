import { useState } from 'react';
import { usePostVendorDebitNote } from '@/hooks/useVendorDebitNotes';
import { useToast } from '@/hooks/use-toast';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';

interface VendorDebitNotePostButtonProps {
  creditNoteId: number;
  onSuccess: () => void;
}

export default function VendorDebitNotePostButton({ creditNoteId, onSuccess }: VendorDebitNotePostButtonProps) {
  const { toast } = useToast();
  const [showConfirm, setShowConfirm] = useState(false);
  const postMutation = usePostVendorDebitNote();

  const handlePost = async () => {
    try {
      await postMutation.mutateAsync(creditNoteId);
      toast({ title: 'Posted', description: 'Debit note posted successfully.' });
      setShowConfirm(false);
      onSuccess();
    } catch (error: any) {
      toast({ variant: 'destructive', title: 'Failed to post', description: error.response?.data?.message || 'Failed to post debit note.' });
    }
  };

  return (
    <>
      <Button
        size="icon"
        variant="ghost"
        className="h-7 w-7 text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700 dark:hover:bg-emerald-950/40"
        onClick={() => setShowConfirm(true)}
        title="Post"
        aria-label="Post debit note"
      >
        <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4" />
      </Button>

      <AlertDialog open={showConfirm} onOpenChange={setShowConfirm}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Post Debit Note?</AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. The debit note will create GL entries and update vendor balances.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <div className="bg-yellow-50 border border-yellow-200 rounded-md p-3">
            <p className="text-sm text-yellow-800">
              Once posted, this debit note cannot be edited or deleted (IFRS/GAAP compliance).
            </p>
          </div>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handlePost} disabled={postMutation.isPending} className="bg-green-600 hover:bg-green-700">
              {postMutation.isPending ? 'Posting...' : 'Confirm Post'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
