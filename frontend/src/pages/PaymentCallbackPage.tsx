import { useEffect } from 'react'

/**
 * Lightweight callback page for HitPay (and similar) payment redirects.
 * Opens inside the popup window after payment completes.
 * Posts a message to the parent/opener window, then closes itself.
 */
export default function PaymentCallbackPage() {
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const reference = params.get('reference') || params.get('ref') || ''
    const status = params.get('status') || params.get('payment_status') || ''

    const message = {
      type: 'hitpay_payment_complete',
      reference,
      status,
    }

    // Notify the parent window (the POS terminal that opened this popup)
    if (window.opener) {
      window.opener.postMessage(message, window.location.origin)
      // Close this popup after a short delay
      setTimeout(() => window.close(), 500)
    } else {
      // Not opened as popup — redirect to POS
      window.location.href = '/pos/terminal'
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
