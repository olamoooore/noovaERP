import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import type { VendorDebitNote, VendorDebitNoteFilters, CreateVendorDebitNotePayload } from '@/types/vendorDebitNote';

const API_BASE = '/accounting/vendors/debit-notes';

export function useVendorDebitNotes(filters: VendorDebitNoteFilters, page: number = 1, perPage: number = 20) {
  return useQuery({
    queryKey: ['vendor-debit-notes', filters, page, perPage],
    queryFn: async () => {
      const { data } = await api.get(API_BASE, {
        params: { ...filters, page, per_page: perPage },
      });
      return data;
    },
  });
}

export function useVendorDebitNote(id: number) {
  return useQuery({
    queryKey: ['vendor-debit-note', id],
    queryFn: async () => {
      const { data } = await api.get(`${API_BASE}/${id}`);
      return data;
    },
    enabled: !!id,
  });
}

export function useCreateVendorDebitNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (debitNote: CreateVendorDebitNotePayload) => {
      const { data } = await api.post(API_BASE, debitNote);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-debit-notes'] });
    },
  });
}

export function useUpdateVendorDebitNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, debitNote }: { id: number; debitNote: Partial<CreateVendorDebitNotePayload> }) => {
      const { data } = await api.put(`${API_BASE}/${id}`, debitNote);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-debit-notes'] });
    },
  });
}

export function usePostVendorDebitNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id: number) => {
      const { data } = await api.post(`${API_BASE}/${id}/post`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-debit-notes'] });
    },
  });
}

export function useDeleteVendorDebitNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`${API_BASE}/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-debit-notes'] });
    },
  });
}

export function useDebitNotesByBill(billId: number) {
  return useQuery({
    queryKey: ['bill-debit-notes', billId],
    queryFn: async () => {
      const { data } = await api.get(`/accounting/vendors/bills/${billId}/debit-notes`);
      return data;
    },
    enabled: !!billId,
  });
}
