import { useQuery, useMutation } from '@tanstack/react-query';
import api from '@/lib/api';
import type { ARTransaction, APTransaction, TransactionFilters, CustomerBalance, VendorBalance } from '@/types/transactions';

// AR Transactions
export function useARTransactions(filters: TransactionFilters, page: number = 1) {
  return useQuery({
    queryKey: ['ar-transactions', filters, page],
    queryFn: async () => {
      const { data } = await api.get('/accounting/customers/transactions', {
        params: { ...filters, page, per_page: 20 },
      });
      return data;
    },
  });
}

// Customer Balance
export function useCustomerBalance(customerId: number) {
  return useQuery({
    queryKey: ['customer-balance', customerId],
    queryFn: async () => {
      const { data } = await api.get(`/accounting/customers/${customerId}/balance`);
      return data;
    },
    enabled: !!customerId,
  });
}

// Export AR Transactions
export function useExportARTransactions() {
  return useMutation({
    mutationFn: async (filters: TransactionFilters) => {
      const response = await api.get('/accounting/customers/transactions/export', {
        params: filters,
        responseType: 'blob',
      });
      
      // Create download link
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `ar-transactions-${new Date().toISOString().split('T')[0]}.csv`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      
      return response.data;
    },
  });
}

// AP Transactions
export function useAPTransactions(filters: TransactionFilters, page: number = 1) {
  return useQuery({
    queryKey: ['ap-transactions', filters, page],
    queryFn: async () => {
      const { data } = await api.get('/accounting/vendors/transactions', {
        params: { ...filters, page, per_page: 20 },
      });
      return data;
    },
  });
}

// Vendor Balance
export function useVendorBalance(vendorId: number) {
  return useQuery({
    queryKey: ['vendor-balance', vendorId],
    queryFn: async () => {
      const { data } = await api.get(`/accounting/vendors/${vendorId}/balance`);
      return data;
    },
    enabled: !!vendorId,
  });
}

// Export AP Transactions
export function useExportAPTransactions() {
  return useMutation({
    mutationFn: async (filters: TransactionFilters) => {
      const response = await api.get('/accounting/vendors/transactions/export', {
        params: filters,
        responseType: 'blob',
      });
      
      // Create download link
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `ap-transactions-${new Date().toISOString().split('T')[0]}.csv`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      
      return response.data;
    },
  });
}
