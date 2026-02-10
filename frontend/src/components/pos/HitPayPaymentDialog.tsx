import { useState, useEffect, useRef, useCallback } from 'react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Icon } from '@iconify/react'
import api from '@/lib/api'

interface HitPayPaymentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  amount: number
  posOrderId: number | null
  gatewayId: number
  onSuccess: (transactionId: string) => void
  onError: (error: string) => void
}

export function HitPayPaymentDialog({
  open,
  onOpenChange,
  amount,
  posOrderId,
  gatewayId,
  onSuccess,
  onError,
}: HitPayPaymentDialogProps) {
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [transactionId, setTransactionId] = useState('')
  const [paymentWindowOpen, setPaymentWindowOpen] = useState(false)
  const [statusMessage, setStatusMessage] = useState('')
  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const popupRef = useRef<Window | null>(null)

  // Stop polling
  const stopPolling = useCallback(() => {
    if (pollingRef.current) {
      clearInterval(pollingRef.current)
      pollingRef.current = null
    }
  }, [])

  // Poll backend for payment status
  const startPolling = useCallback(
    (txnId: string) => {
      stopPolling()
      let attempts = 0
      const maxAttempts = 120 // 10 minutes at 5s intervals

      pollingRef.current = setInterval(async () => {
        attempts++

        // Check if popup was closed by user
        if (popupRef.current && popupRef.current.closed) {
          setPaymentWindowOpen(false)
          setStatusMessage('Payment window was closed. Checking payment status...')
        }

        if (attempts >= maxAttempts) {
          stopPolling()
          setStatusMessage('Payment timed out. Please try again.')
          return
        }

        try {
          const response = await api.post('/payment-transactions/confirm', {
            transaction_id: txnId,
          })
          const status = response.data?.transaction?.status
          if (status === 'completed' || status === 'succeeded') {
            stopPolling()
            // Close popup if still open
            if (popupRef.current && !popupRef.current.closed) {
              popupRef.current.close()
            }
            popupRef.current = null
            onSuccess(txnId)
          }
        } catch {
          // Silently continue polling
        }
      }, 4000) // Check every 4 seconds
    },
    [stopPolling, onSuccess]
  )

  // Create payment and open popup
  const initializePayment = useCallback(async () => {
    if (!posOrderId || !gatewayId) return

    setLoading(true)
    setError('')
    setStatusMessage('')

    try {
      const response = await api.post('/payment-transactions/create', {
        gateway_id: gatewayId,
        pos_order_id: posOrderId,
        amount: amount,
        redirect_url: `${window.location.origin}/payment/callback`,
      })

      const { payment_url, transaction } = response.data
      const txnId = transaction?.transaction_id || ''

      if (!payment_url) {
        throw new Error('No payment URL returned from HitPay')
      }

      setTransactionId(txnId)
      setLoading(false)

      // Open HitPay checkout in a popup window
      const width = 500
      const height = 700
      const left = window.screenX + (window.outerWidth - width) / 2
      const top = window.screenY + (window.outerHeight - height) / 2

      const popup = window.open(
        payment_url,
        'hitpay_checkout',
        `width=${width},height=${height},left=${left},top=${top},toolbar=no,menubar=no,scrollbars=yes,resizable=yes`
      )

      if (popup) {
        popupRef.current = popup
        setPaymentWindowOpen(true)
        setStatusMessage('Complete your payment in the checkout window...')

        // Start polling for payment completion
        if (txnId) {
          startPolling(txnId)
        }
      } else {
        // Popup was blocked — fallback to redirect
        setError(
          'Popup was blocked by your browser. Click "Open Checkout" to try again, or allow popups for this site.'
        )
      }
    } catch (err: any) {
      console.error('HitPay payment init error:', err)
      const errorMsg =
        err.response?.data?.message || err.message || 'Failed to initialize payment'
      setError(errorMsg)
      setLoading(false)
    }
  }, [posOrderId, gatewayId, amount, startPolling])

  // Listen for postMessage from the payment callback popup
  useEffect(() => {
    const handleMessage = (event: MessageEvent) => {
      if (event.origin !== window.location.origin) return
      if (event.data?.type === 'hitpay_payment_complete') {
        const ref = event.data.reference || transactionId
        stopPolling()
        if (popupRef.current && !popupRef.current.closed) {
          popupRef.current.close()
        }
        popupRef.current = null
        // Confirm with backend then fire success
        api
          .post('/payment-transactions/confirm', { transaction_id: ref })
          .then(() => onSuccess(ref))
          .catch(() => onSuccess(ref)) // still treat as success — HitPay said completed
      }
    }
    window.addEventListener('message', handleMessage)
    return () => window.removeEventListener('message', handleMessage)
  }, [transactionId, stopPolling, onSuccess])

  // Initialize when dialog opens
  useEffect(() => {
    if (open && posOrderId && gatewayId) {
      initializePayment()
    }
    return () => {
      stopPolling()
      if (popupRef.current && !popupRef.current.closed) {
        popupRef.current.close()
      }
      popupRef.current = null
      setPaymentWindowOpen(false)
      setTransactionId('')
      setError('')
      setStatusMessage('')
    }
  }, [open, posOrderId, gatewayId])

  const handleClose = () => {
    stopPolling()
    if (popupRef.current && !popupRef.current.closed) {
      popupRef.current.close()
    }
    popupRef.current = null

    // Check final status before closing
    if (transactionId) {
      api
        .post('/payment-transactions/confirm', { transaction_id: transactionId })
        .then((res) => {
          const status = res.data?.transaction?.status
          if (status === 'completed' || status === 'succeeded') {
            onSuccess(transactionId)
          } else {
            onOpenChange(false)
          }
        })
        .catch(() => {
          onOpenChange(false)
        })
    } else {
      onOpenChange(false)
    }
  }

  const reopenCheckout = () => {
    if (popupRef.current && !popupRef.current.closed) {
      popupRef.current.focus()
    } else {
      // Re-initialize payment (creates new request)
      initializePayment()
    }
  }

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) handleClose() }}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="mdi:credit-card-outline" className="w-5 h-5 text-blue-600" />
            HitPay Payment
          </DialogTitle>
          <DialogDescription>
            {loading
              ? 'Preparing secure checkout...'
              : error
              ? 'Payment initialization failed'
              : `Payment of ${amount.toFixed(2)} in progress`}
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col items-center gap-4 py-6">
          {/* Loading state */}
          {loading && (
            <div className="flex flex-col items-center gap-3">
              <Icon icon="mdi:loading" className="w-10 h-10 text-blue-600 animate-spin" />
              <p className="text-sm text-muted-foreground">Connecting to HitPay...</p>
            </div>
          )}

          {/* Error state */}
          {error && (
            <>
              <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
              <div className="flex gap-2">
                <Button variant="outline" onClick={handleClose}>
                  Cancel
                </Button>
                <Button onClick={initializePayment}>
                  <Icon icon="mdi:refresh" className="w-4 h-4 mr-2" />
                  Retry
                </Button>
              </div>
            </>
          )}

          {/* Payment in progress */}
          {!loading && !error && paymentWindowOpen && (
            <div className="flex flex-col items-center gap-4 text-center">
              <div className="relative">
                <Icon icon="mdi:credit-card-check" className="w-16 h-16 text-blue-500" />
                <div className="absolute -bottom-1 -right-1 bg-blue-600 rounded-full p-1">
                  <Icon icon="mdi:loading" className="w-4 h-4 text-white animate-spin" />
                </div>
              </div>

              <div className="space-y-1">
                <p className="text-sm font-medium">{statusMessage}</p>
                <p className="text-xs text-muted-foreground">
                  This dialog will close automatically once payment is confirmed.
                </p>
              </div>

              <div className="flex flex-col gap-2 w-full max-w-xs">
                <Button variant="outline" onClick={reopenCheckout} className="w-full">
                  <Icon icon="mdi:open-in-new" className="w-4 h-4 mr-2" />
                  {popupRef.current && !popupRef.current.closed
                    ? 'Focus Checkout Window'
                    : 'Reopen Checkout'}
                </Button>
              </div>
            </div>
          )}

          {/* Popup closed but no success yet */}
          {!loading && !error && !paymentWindowOpen && transactionId && (
            <div className="flex flex-col items-center gap-4 text-center">
              <Icon icon="mdi:alert-circle-outline" className="w-12 h-12 text-amber-500" />
              <div className="space-y-1">
                <p className="text-sm font-medium">Payment window was closed</p>
                <p className="text-xs text-muted-foreground">
                  If you completed the payment, it will be confirmed shortly.
                  <br />
                  Otherwise you can reopen the checkout.
                </p>
              </div>
              <div className="flex gap-2">
                <Button variant="outline" onClick={handleClose}>
                  Cancel
                </Button>
                <Button onClick={initializePayment}>
                  <Icon icon="mdi:open-in-new" className="w-4 h-4 mr-2" />
                  Reopen Checkout
                </Button>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        {!loading && !error && paymentWindowOpen && (
          <div className="flex items-center justify-between pt-2 border-t">
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
              <Icon icon="mdi:shield-lock" className="w-4 h-4 text-green-600" />
              Secured by HitPay
            </div>
            <Button variant="ghost" size="sm" onClick={handleClose}>
              Cancel
            </Button>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
