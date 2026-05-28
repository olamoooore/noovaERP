import { useCreditNotesByInvoice } from '@/hooks/useCustomerCreditNotes';

interface InvoiceCreditNotesProps {
  invoiceId: number;
  invoiceTotal: number;
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

export default function InvoiceCreditNotes({ invoiceId, invoiceTotal, currencyCode = 'USD' }: InvoiceCreditNotesProps) {
  const { data: creditNotes, isLoading } = useCreditNotesByInvoice(invoiceId);

  if (isLoading) {
    return (
      <div className="mt-6 p-4 bg-gray-50 rounded-lg">
        <p className="text-gray-600">Loading credit notes...</p>
      </div>
    );
  }

  const creditNotesList = Array.isArray(creditNotes) ? creditNotes : (creditNotes?.data || []);
  const totalCredited = creditNotesList
    .filter((cn: any) => cn.status === 'posted')
    .reduce((sum: number, cn: any) => sum + Number(cn.total_amount), 0);
  const remainingBalance = Number(invoiceTotal) - totalCredited;

  return (
    <div className="mt-6">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold text-gray-900">Credit Notes</h3>
        {creditNotesList.length > 0 && (
          <div className="text-sm text-gray-600">
            <span className="font-medium">Remaining Balance:</span>{' '}
            <span className={remainingBalance < invoiceTotal ? 'text-green-600 font-semibold' : ''}>
              {currencyCode} {(remainingBalance || 0).toFixed(2)}
            </span>
          </div>
        )}
      </div>

      {creditNotesList.length === 0 ? (
        <div className="bg-gray-50 rounded-lg p-4 text-center text-gray-500">
          No credit notes for this invoice
        </div>
      ) : (
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Credit Note #
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Date
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Amount
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Status
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Reason
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {creditNotesList.map((creditNote: any) => (
                <tr key={creditNote.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 text-sm font-medium text-blue-600">
                    <a href={`/accounting/customers/credit-notes/${creditNote.id}`} className="hover:underline">
                      {creditNote.credit_note_number}
                    </a>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-900">
                    {new Date(creditNote.credit_note_date).toLocaleDateString()}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-900">
                    {creditNote.currency_code} {(Number(creditNote.total_amount) || 0).toFixed(2)}
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge status={creditNote.status} />
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600">
                    {creditNote.reason || '-'}
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot className="bg-gray-50">
              <tr>
                <td colSpan={2} className="px-4 py-3 text-sm font-semibold text-gray-900">
                  Total Credited (Posted):
                </td>
                <td colSpan={3} className="px-4 py-3 text-sm font-bold text-green-600">
                  {currencyCode} {(totalCredited || 0).toFixed(2)}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </div>
  );
}
