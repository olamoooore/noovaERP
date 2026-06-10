import { useState } from 'react';
import { useDebitNotesByBill, useDeleteVendorDebitNote } from '@/hooks/useVendorDebitNotes';
import VendorDebitNotePostButton from './VendorDebitNotePostButton';
import EditVendorDebitNoteDialog from './EditVendorDebitNoteDialog';
import { useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/hooks/use-toast';

interface BillDebitNotesProps {
  billId: number;
  billTotal: number;
  currencyCode: string;
}

function StatusBadge({ status }: { status: string }) {
  const colors = {
    draft: 'bg-gray-100 text-gray-800',
    posted: 'bg-green-100 text-green-800',
    cancelled: 'bg-red-100 text-red-800',
  };

  return (
    <span className={`px-2 py-1 rounded-full text-xs font-medium ${colors[status as keyof typeof colors]}`}>
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  );
}

export default function BillDebitNotes({ billId, billTotal, currencyCode }: BillDebitNotesProps) {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const { data: debitNotes, isLoading } = useDebitNotesByBill(billId);
  const [editingDebitNoteId, setEditingDebitNoteId] = useState<number | null>(null);
  const deleteMutation = useDeleteVendorDebitNote();

  if (isLoading) {
    return (
      <div className="mt-6 p-4 bg-gray-50 rounded-lg">
        <p className="text-gray-600">Loading debit notes...</p>
      </div>
    );
  }

  const debitNotesList = debitNotes?.data || [];
  const totalDebited = debitNotesList
    .filter((cn: any) => cn.status === 'posted')
    .reduce((sum: number, cn: any) => sum + Number(cn.total_amount), 0);
  const remainingBalance = billTotal - totalDebited;

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this debit note?')) return;
    try {
      await deleteMutation.mutateAsync(id);
      toast({ title: 'Deleted', description: 'Debit note deleted successfully.' });
      queryClient.invalidateQueries({ queryKey: ['bill-debit-notes', billId] });
    } catch (err: any) {
      toast({ variant: 'destructive', title: 'Failed to delete', description: err.response?.data?.message || 'Failed to delete debit note.' });
    }
  };

  const handlePosted = () => {
    queryClient.invalidateQueries({ queryKey: ['bill-debit-notes', billId] });
  };

  return (
    <div className="mt-6">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold text-gray-900">Debit Notes</h3>
        {debitNotesList.length > 0 && (
          <div className="text-sm text-gray-600">
            <span className="font-medium">Remaining Balance:</span>{' '}
            <span className={remainingBalance < billTotal ? 'text-green-600 font-semibold' : ''}>
              {currencyCode} {remainingBalance.toFixed(2)}
            </span>
          </div>
        )}
      </div>

      {debitNotesList.length === 0 ? (
        <div className="bg-gray-50 rounded-lg p-4 text-center text-gray-500">
          No debit notes for this bill
        </div>
      ) : (
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Debit Note #</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {debitNotesList.map((debitNote: any) => (
                <tr key={debitNote.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 text-sm font-medium text-blue-600">
                    <a href={`/accounting/vendors/debit-notes/${debitNote.id}`} className="hover:underline">
                      {debitNote.debit_note_number}
                    </a>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-900">
                    {new Date(debitNote.debit_note_date).toLocaleDateString()}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-900">
                    {debitNote.currency_code} {Number(debitNote.total_amount).toFixed(2)}
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge status={debitNote.status} />
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600">
                    {debitNote.reason || '-'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {debitNote.status === 'draft' && (
                      <>
                        <Button size="sm" variant="ghost" onClick={() => setEditingDebitNoteId(debitNote.id)}>
                          <Icon icon="solar:pen-bold-duotone" className="h-4 w-4" />
                        </Button>
                        <VendorDebitNotePostButton creditNoteId={debitNote.id} onSuccess={handlePosted} />
                        <Button size="sm" variant="ghost" onClick={() => handleDelete(debitNote.id)}>
                          <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4 text-destructive" />
                        </Button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot className="bg-gray-50">
              <tr>
                <td colSpan={2} className="px-4 py-3 text-sm font-semibold text-gray-900">Total Debited (Posted):</td>
                <td colSpan={4} className="px-4 py-3 text-sm font-bold text-green-600">
                  {currencyCode} {totalDebited.toFixed(2)}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}

      {editingDebitNoteId && (
        <EditVendorDebitNoteDialog
          debitNoteId={editingDebitNoteId}
          onClose={() => setEditingDebitNoteId(null)}
          onSuccess={() => {
            setEditingDebitNoteId(null);
            queryClient.invalidateQueries({ queryKey: ['bill-debit-notes', billId] });
          }}
        />
      )}
    </div>
  );
}
