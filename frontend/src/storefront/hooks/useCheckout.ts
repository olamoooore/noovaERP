import { useMutation } from '@tanstack/react-query';
import { guestCheckout, getPaymentGateways, confirmPayment, CheckoutPayload } from '@/storefront/lib/api';

/**
 * Hook to submit guest checkout
 */
export function useGuestCheckout() {
  return useMutation({
    mutationFn: guestCheckout,
  });
}

/**
 * Hook to fetch payment gateways
 */
export function usePaymentGateways() {
  return useMutation({
    mutationFn: getPaymentGateways,
  });
}

/**
 * Hook to confirm payment after successful gateway redirect
 */
export function useConfirmPayment() {
  return useMutation({
    mutationFn: confirmPayment,
  });
}

export type { CheckoutPayload };
