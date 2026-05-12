import { useEffect } from 'react'

/**
 * Lightweight callback page for HitPay (and similar) payment redirects.
 * Opens inside the popup window after payment completes.
 * Posts a message to the parent/opener window, then closes itself.
 */
export default function PaymentCallbackPage() {
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const reference = params.get('reference') || params.get('ref') || params.get('token') || ''
    const status = (params.get('status') || params.get('payment_status') || '').toLowerCase()
    const isSuccessful = ['completed', 'succeeded', 'success'].includes(status) || (!status && !!reference)

    const primaryMessage = isSuccessful
      ? {
          type: 'PAYMENT_COMPLETE',
          reference,
          status: status || 'completed',
        }
      : {
          type: 'PAYMENT_FAILED',
          reference,
          status: status || 'failed',
        }

    const legacyHitPayMessage = {
      type: 'hitpay_payment_complete',
      reference,
      status: status || (isSuccessful ? 'completed' : 'failed'),
    }

    if (window.opener) {
      window.opener.postMessage(primaryMessage, window.location.origin)
      if (isSuccessful) {
        window.opener.postMessage(legacyHitPayMessage, window.location.origin)
      }
      setTimeout(() => window.close(), 500)
    } else {
      window.location.href = isSuccessful ? '/payment/success?ref=' + encodeURIComponent(reference) : '/store/checkout'
    }
  }, [])

  return (
    <div className="flex items-center justify-center min-h-screen bg-background">
      <div className="text-center space-y-3">
        <div className="animate-spin inline-block w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full" />
        <p className="text-sm text-muted-foreground">Payment received. Closing...</p>
      </div>
    </div>
  )
}
