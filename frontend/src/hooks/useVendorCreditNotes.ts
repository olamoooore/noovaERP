import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import type { VendorCreditNote, VendorCreditNoteFilters, CreateVendorCreditNotePayload } from '@/types/vendorCreditNote';

const API_BASE = '/accounting/vendors/credit-notes';

// List credit notes
export function useVendorCreditNotes(filters: VendorCreditNoteFilters, page: number = 1, perPage: number = 20) {
  return useQuery({
    queryKey: ['vendor-credit-notes', filters, page, perPage],
    queryFn: async () => {
      const { data } = await api.get(API_BASE, {
        params: { ...filters, page, per_page: perPage },
      });
      return data;
    },
  });
}

// Get single credit note
export function useVendorCreditNote(id: number) {
  return useQuery({
    queryKey: ['vendor-credit-note', id],
    queryFn: async () => {
      const { data } = await api.get(`${API_BASE}/${id}`);
      return data;
    },
    enabled: !!id,
  });
}

// Create credit note
export function useCreateVendorCreditNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (creditNote: CreateVendorCreditNotePayload) => {
      const { data } = await api.post(API_BASE, creditNote);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-credit-notes'] });
    },
  });
}

// Update credit note
export function useUpdateVendorCreditNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, creditNote }: { id: number; creditNote: Partial<CreateVendorCreditNotePayload> }) => {
      const { data } = await api.put(`${API_BASE}/${id}`, creditNote);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-credit-notes'] });
    },
  });
}

// Post credit note
export function usePostVendorCreditNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id: number) => {
      const { data } = await api.post(`${API_BASE}/${id}/post`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-credit-notes'] });
    },
  });
}

// Delete credit note
export function useDeleteVendorCreditNote() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`${API_BASE}/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-credit-notes'] });
    },
  });
}

// Get credit notes by bill
export function useCreditNotesByBill(billId: number) {
  return useQuery({
    queryKey: ['bill-credit-notes', billId],
    queryFn: async () => {
      const { data } = await api.get(`/accounting/vendors/bills/${billId}/credit-notes`);
      return data;
    },
    enabled: !!billId,
  });
}
