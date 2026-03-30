import { useState, useEffect } from 'react';
import { useARTransactions, useCustomerBalance, useExportARTransactions } from '@/hooks/useTransactions';
import type { TransactionFilters } from '@/types/transactions';
import api from '@/lib/api';

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
  const colors = {
    invoice: 'bg-purple-100 text-purple-800',
    payment: 'bg-green-100 text-green-800',
    credit_note: 'bg-orange-100 text-orange-800',
  };

  const labels = {
    invoice: 'Invoice',
    payment: 'Payment',
    credit_note: 'Credit Note',
  };

  return (
    <span className={`px-2 py-1 rounded-full text-xs font-medium ${colors[type as keyof typeof colors]}`}>
      {labels[type as keyof typeof labels] || type}
    </span>
  );
}

export default function ARTransactionsPage() {
  const [filters, setFilters] = useState<TransactionFilters>({});
  const [page, setPage] = useState(1);
  const [customers, setCustomers] = useState<any[]>([]);

  const { data, isLoading, error } = useARTransactions(filters, page);
  const { data: balanceData } = useCustomerBalance(filters.customer_id || 0);
  const exportMutation = useExportARTransactions();

  useEffect(() => {
    api.get('/accounting/customers', { params: { per_page: 100 } })
      .then(res => setCustomers(res.data.data || []))
      .catch(err => console.error('Failed to load customers:', err));
  }, []);

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
      invoice: `/accounting/customers/invoices/${transaction.transaction_id}`,
      payment: `/accounting/customers/payments/${transaction.transaction_id}`,
      credit_note: `/accounting/customers/credit-notes/${transaction.transaction_id}`,
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
        <h1 className="text-2xl font-bold text-gray-900">AR Transactions Ledger</h1>
        <p className="text-gray-600 mt-1">View all accounts receivable transactions</p>
      </div>

      {balanceData && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <p className="text-sm text-gray-600">Customer</p>
              <p className="text-lg font-semibold text-gray-900">{balanceData.customer_name}</p>
            </div>
            <div>
              <p className="text-sm text-gray-600">Current Balance</p>
              <p className="text-lg font-semibold text-blue-600">
                {balanceData.currency_code} {parseFloat(balanceData.balance).toFixed(2)}
              </p>
            </div>
            <div>
              <p className="text-sm text-gray-600">Total Invoices</p>
              <p className="text-lg font-semibold text-gray-900">
                {balanceData.currency_code} {parseFloat(balanceData.total_invoices).toFixed(2)}
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
              <label className="block text-sm font-medium text-gray-700 mb-1">Customer</label>
              <select
                value={filters.customer_id || ''}
                onChange={(e) => handleFilterChange('customer_id', e.target.value ? Number(e.target.value) : undefined)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All Customers</option>
                {customers.map(customer => (
                  <option key={customer.id} value={customer.id}>{customer.name}</option>
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
                <option value="invoice">Invoice</option>
                <option value="payment">Payment</option>
                <option value="credit_note">Credit Note</option>
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
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
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
                        {transaction.customer_name}
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

            {pagination.last_page > 1 && (
              <div className="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                <div className="text-sm text-gray-700">
                  Showing {pagination.from} to {pagination.to} of {pagination.total} results
                </div>
                <div className="flex gap-2">
                  <button
                    onClick={() => setPage(page - 1)}
                    disabled={page === 1}
                    className="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Previous
                  </button>
                  <button
                    onClick={() => setPage(page + 1)}
                    disabled={page === pagination.last_page}
                    className="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
