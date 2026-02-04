import { useState } from 'react';
import { usePostVendorCreditNote } from '@/hooks/useVendorCreditNotes';

interface VendorCreditNotePostButtonProps {
  creditNoteId: number;
  onSuccess: () => void;
}

export default function VendorCreditNotePostButton({ creditNoteId, onSuccess }: VendorCreditNotePostButtonProps) {
  const [showConfirm, setShowConfirm] = useState(false);
  const postMutation = usePostVendorCreditNote();

  const handlePost = async () => {
    try {
      await postMutation.mutateAsync(creditNoteId);
      alert('Credit note posted successfully');
      setShowConfirm(false);
      onSuccess();
    } catch (error: any) {
      alert(error.response?.data?.message || 'Failed to post credit note');
    }
  };

  return (
    <>
      <button
        onClick={() => setShowConfirm(true)}
        className="text-green-600 hover:text-green-900 font-medium"
      >
        Post
      </button>

      {showConfirm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
            <h3 className="text-lg font-bold text-gray-900 mb-2">Post Credit Note</h3>
            <p className="text-gray-600 mb-4">
              Are you sure you want to post this credit note? This action cannot be undone.
              The credit note will create GL entries and update vendor balances.
            </p>
            <div className="bg-yellow-50 border border-yellow-200 rounded p-3 mb-4">
              <p className="text-sm text-yellow-800">
                ⚠️ Once posted, this credit note cannot be edited or deleted (IFRS/GAAP compliance).
              </p>
            </div>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => setShowConfirm(false)}
                className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handlePost}
                disabled={postMutation.isPending}
                className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
              >
                {postMutation.isPending ? 'Posting...' : 'Confirm Post'}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
