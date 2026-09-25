import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import type { CustomerCreditNote, CreditNoteFilters, CreateCreditNotePayload } from '@/types/creditNote';

const API_BASE = '/accounting/customers/credit-notes';

// List credit notes
export function useCustomerCreditNotes(filters: CreditNoteFilters, page: number = 1, perPage: number = 20) {
  return useQuery({
    queryKey: ['customer-credit-notes', filters, page, perPage],
    queryFn: async () => {
      const { data } = await api.get(API_BASE, {
        params: { ...filters, page, per_page: perPage },
      });
      return data;
    },
  });
}

// Get single credit note
export function useCustomerCreditNote(id: number) {
  return useQuery({
    queryKey: ['customer-credit-note', id],
    queryFn: async () => {
      const { data } = await api.get(`${API_BASE}/${id}`);
      return data;
    },
    enabled: !!id,
  });
}

// Create credit note
export function useCreateCreditNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (creditNote: CreateCreditNotePayload) => {
      const { data } = await api.post(API_BASE, creditNote);
      return data;
    },
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['customer-credit-notes'] });
      if (variables.invoice_id) {
        queryClient.invalidateQueries({ queryKey: ['invoice-credit-notes', variables.invoice_id] });
      }
    },
  });
}

// Update credit note
export function useUpdateCreditNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, creditNote }: { id: number; creditNote: Partial<CreateCreditNotePayload> }) => {
      const { data } = await api.put(`${API_BASE}/${id}`, creditNote);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer-credit-notes'] });
    },
  });
}

// Post credit note
export function usePostCreditNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id: number) => {
      const { data } = await api.post(`${API_BASE}/${id}/post`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer-credit-notes'] });
    },
  });
}

// Delete credit note
export function useDeleteCreditNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`${API_BASE}/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer-credit-notes'] });
    },
  });
}

// Get credit notes by invoice
export function useCreditNotesByInvoice(invoiceId: number) {
  return useQuery({
    queryKey: ['invoice-credit-notes', invoiceId],
    queryFn: async () => {
      const { data } = await api.get(`/accounting/customers/invoices/${invoiceId}/credit-notes`);
      return data;
    },
    enabled: !!invoiceId,
  });
}
