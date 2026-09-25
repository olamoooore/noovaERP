import { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useAPTransactions, useVendorBalance, useExportAPTransactions } from '@/hooks/useTransactions';
import type { TransactionFilters } from '@/types/transactions';
import api from '@/lib/api';
import { Pagination } from '@/components/ui/Pagination';

function StatusBadge({ status }: { status: string }) {
  const colors = {
    draft: 'bg-gray-100 text-gray-800',
    posted: 'bg-green-100 text-green-800',
    paid: 'bg-blue-100 text-blue-800',
    cancelled: 'bg-red-100 text-red-800',
  };

  return (
    <span className={`px-2 py-1 rounded-full text-xs font-medium ${colors[status as keyof typeof colors] || 'bg-gray-100 text-gray-800'}`}>
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  );
}

function TransactionTypeBadge({ type }: { type: string }) {
  const colors: Record<string, string> = {
    bill: 'bg-purple-100 text-purple-800',
    payment: 'bg-green-100 text-green-800',
    credit_note: 'bg-orange-100 text-orange-800',
    debit_note: 'bg-blue-100 text-blue-800',
  };

  const labels: Record<string, string> = {
    bill: 'Bill',
    payment: 'Payment',
    credit_note: 'Credit Note',
    debit_note: 'Debit Note',
  };

  return (
    <span className={`px-2 py-1 rounded-full text-xs font-medium ${colors[type] || 'bg-gray-100 text-gray-800'}`}>
      {labels[type] || type}
    </span>
  );
}

export default function APTransactionsPage() {
  const [searchParams] = useSearchParams();
  const [filters, setFilters] = useState<TransactionFilters>({});
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(20);
  const [vendors, setVendors] = useState<any[]>([]);

  const { data, isLoading, error } = useAPTransactions(filters, page, pageSize);
  const { data: balanceData } = useVendorBalance(filters.vendor_id || 0);
  const exportMutation = useExportAPTransactions();

  useEffect(() => {
    api.get('/accounting/vendors', { params: { per_page: 100 } })
      .then(res => setVendors(res.data.data || []))
      .catch(err => console.error('Failed to load vendors:', err));
  }, []);

  useEffect(() => {
    const vendorId = searchParams.get('vendor_id');
    const transactionType = searchParams.get('transaction_type');
    const status = searchParams.get('status');

    setFilters(prev => ({
      ...prev,
      vendor_id: vendorId ? Number(vendorId) : prev.vendor_id,
      transaction_type: transactionType || prev.transaction_type,
      status: status || prev.status,
    }));
  }, [searchParams]);

  const handleFilterChange = (key: keyof TransactionFilters, value: any) => {
    setFilters(prev => ({ ...prev, [key]: value || undefined }));
    setPage(1);
  };

  const handleExport = async () => {
    try {
      await exportMutation.mutateAsync(filters);
      alert('Transactions exported successfully');
    } catch (error) {
      alert('Failed to export transactions');
    }
  };

  const handleRowClick = (transaction: any) => {
    const routes = {
      bill: `/accounting/vendors/bills/${transaction.transaction_id}`,
      payment: `/accounting/vendors/payments?search=${encodeURIComponent(transaction.document_number || '')}`,
      credit_note: `/accounting/vendors/credit-notes?search=${encodeURIComponent(transaction.document_number || '')}`,
      debit_note: `/accounting/vendors/debit-notes?search=${encodeURIComponent(transaction.document_number || '')}`,
    };
    
    const route = routes[transaction.transaction_type as keyof typeof routes];
    if (route) {
      window.location.href = route;
    }
  };

  const transactions = data?.data || [];
  const pagination = data?.meta || {};

  return (
    <div className="p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">AP Transactions Ledger</h1>
        <p className="text-gray-600 mt-1">View all accounts payable transactions</p>
      </div>

      {balanceData && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <p className="text-sm text-gray-600">Vendor</p>
              <p className="text-lg font-semibold text-gray-900">{balanceData.vendor_name}</p>
            </div>
            <div>
              <p className="text-sm text-gray-600">Current Balance</p>
              <p className="text-lg font-semibold text-red-600">
                {balanceData.currency_code} {parseFloat(balanceData.balance).toFixed(2)}
              </p>
            </div>
            <div>
              <p className="text-sm text-gray-600">Total Bills</p>
              <p className="text-lg font-semibold text-gray-900">
                {balanceData.currency_code} {parseFloat(balanceData.total_bills).toFixed(2)}
              </p>
            </div>
            <div>
              <p className="text-sm text-gray-600">Total Payments</p>
              <p className="text-lg font-semibold text-green-600">
                {balanceData.currency_code} {parseFloat(balanceData.total_payments).toFixed(2)}
              </p>
            </div>
          </div>
        </div>
      )}

      <div className="bg-white rounded-lg shadow">
        <div className="p-4 border-b border-gray-200">
          <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Vendor</label>
              <select
                value={filters.vendor_id || ''}
                onChange={(e) => handleFilterChange('vendor_id', e.target.value ? Number(e.target.value) : undefined)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All Vendors</option>
                {vendors.map(vendor => (
                  <option key={vendor.id} value={vendor.id}>{vendor.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Transaction Type</label>
              <select
                value={filters.transaction_type || ''}
                onChange={(e) => handleFilterChange('transaction_type', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All Types</option>
                <option value="bill">Bill</option>
                <option value="payment">Payment</option>
                <option value="credit_note">Credit Note</option>
                <option value="debit_note">Debit Note</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
              <select
                value={filters.status || ''}
                onChange={(e) => handleFilterChange('status', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All Status</option>
                <option value="draft">Draft</option>
                <option value="posted">Posted</option>
                <option value="paid">Paid</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
              <input
                type="date"
                value={filters.start_date || ''}
                onChange={(e) => handleFilterChange('start_date', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">End Date</label>
              <input
                type="date"
                value={filters.end_date || ''}
                onChange={(e) => handleFilterChange('end_date', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <div className="mt-4 flex justify-end">
            <button
              onClick={handleExport}
              disabled={exportMutation.isPending}
              className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
            >
              {exportMutation.isPending ? 'Exporting...' : 'Export to CSV'}
            </button>
          </div>
        </div>

        {isLoading ? (
          <div className="p-8 text-center">
            <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <p className="mt-2 text-gray-600">Loading transactions...</p>
          </div>
        ) : error ? (
          <div className="p-8 text-center text-red-600">
            Error loading transactions. Please try again.
          </div>
        ) : transactions.length === 0 ? (
          <div className="p-8 text-center text-gray-500">
            No transactions found. Adjust filters to see results.
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Document #</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {transactions.map((transaction: any) => (
                    <tr 
                      key={`${transaction.transaction_type}-${transaction.transaction_id}`}
                      onClick={() => handleRowClick(transaction)}
                      className="hover:bg-gray-50 cursor-pointer"
                    >
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {new Date(transaction.transaction_date).toLocaleDateString()}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <TransactionTypeBadge type={transaction.transaction_type} />
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                        {transaction.document_number}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {transaction.vendor_name}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                        {transaction.debit > 0 ? `${transaction.currency_code} ${parseFloat(transaction.debit).toFixed(2)}` : '-'}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600">
                        {transaction.credit > 0 ? `${transaction.currency_code} ${parseFloat(transaction.credit).toFixed(2)}` : '-'}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-gray-900">
                        {transaction.balance !== undefined 
                          ? `${transaction.currency_code} ${parseFloat(transaction.balance).toFixed(2)}`
                          : '-'}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <StatusBadge status={transaction.status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <Pagination
              page={page}
              totalPages={pagination.last_page ?? 1}
              onPageChange={setPage}
              from={pagination.from}
              to={pagination.to}
              total={pagination.total}
              label="transactions"
              pageSize={pageSize}
              onPageSizeChange={(size) => { setPageSize(size); setPage(1); }}
            />
          </>
        )}
      </div>
    </div>
  );
}
