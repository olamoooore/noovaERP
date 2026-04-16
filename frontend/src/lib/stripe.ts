import { loadStripe } from '@stripe/stripe-js'
import type { Stripe, StripeConstructorOptions } from '@stripe/stripe-js'

const STRIPE_BASE_OPTIONS: StripeConstructorOptions = {
  developerTools: {
    assistant: {
      enabled: false,
    },
  },
}

export function loadAceStripe(
  publishableKey: string,
  options?: StripeConstructorOptions,
): Promise<Stripe | null> {
  return loadStripe(publishableKey, {
    ...STRIPE_BASE_OPTIONS,
    ...options,
    developerTools: {
      ...STRIPE_BASE_OPTIONS.developerTools,
      ...options?.developerTools,
      assistant: {
        ...STRIPE_BASE_OPTIONS.developerTools?.assistant,
        ...options?.developerTools?.assistant,
        enabled: false,
      },
    },
  })
}