import { useState } from 'react';
import { usePostCreditNote } from '@/hooks/useCustomerCreditNotes';
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

interface CreditNotePostButtonProps {
  creditNoteId: number;
  onSuccess: () => void;
}

export default function CreditNotePostButton({ creditNoteId, onSuccess }: CreditNotePostButtonProps) {
  const { toast } = useToast();
  const [showConfirm, setShowConfirm] = useState(false);
  const postMutation = usePostCreditNote();

  const handlePost = async () => {
    try {
      await postMutation.mutateAsync(creditNoteId);
      toast({ title: 'Posted', description: 'Credit note posted successfully.' });
      setShowConfirm(false);
      onSuccess();
    } catch (error: any) {
      toast({ variant: 'destructive', title: 'Failed to post', description: error.response?.data?.message || 'Failed to post credit note.' });
    }
  };

  return (
    <>
      <Button
        size="sm"
        variant="ghost"
        onClick={() => setShowConfirm(true)}
      >
        <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 text-green-600" />
      </Button>

      <AlertDialog open={showConfirm} onOpenChange={setShowConfirm}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Post Credit Note?</AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. The credit note will create GL entries and update customer balances.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <div className="bg-yellow-50 border border-yellow-200 rounded-md p-3">
            <p className="text-sm text-yellow-800">
              Once posted, this credit note cannot be edited or deleted (IFRS/GAAP compliance).
            </p>
          </div>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handlePost}
              disabled={postMutation.isPending}
              className="bg-green-600 hover:bg-green-700"
            >
              {postMutation.isPending ? 'Posting...' : 'Confirm Post'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
