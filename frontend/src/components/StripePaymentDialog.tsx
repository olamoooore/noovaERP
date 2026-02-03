import { useState, useEffect } from 'react'
import { loadStripe, Stripe } from '@stripe/stripe-js'
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Icon } from '@iconify/react'
import api from '@/lib/api'

interface StripePaymentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  amount: number
  posOrderId: number | null
  gatewayId: number
  onSuccess: (transactionId: string) => void
  onError: (error: string) => void
}

function CheckoutForm({ 
  amount, 
  currency, 
  clientSecret, 
  onSuccess, 
  onError, 
  onClose 
}: { 
  amount: number
  currency: string
  clientSecret: string
  onSuccess: (transactionId: string) => void
  onError: (error: string) => void
  onClose: () => void
}) {
  const stripe = useStripe()
  const elements = useElements()
  const [isProcessing, setIsProcessing] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!stripe || !elements) {
      return
    }

    setIsProcessing(true)
    setErrorMessage('')

    try {
      const { error, paymentIntent } = await stripe.confirmPayment({
        elements,
        confirmParams: {
          return_url: window.location.href,
        },
        redirect: 'if_required',
      })

      if (error) {
        setErrorMessage(error.message || 'Payment failed')
        onError(error.message || 'Payment failed')
      } else if (paymentIntent && paymentIntent.status === 'succeeded') {
        // Confirm payment on backend
        try {
          await api.post('/payment-transactions/confirm', {
            transaction_id: paymentIntent.id,
          })
          onSuccess(paymentIntent.id)
        } catch (confirmError: any) {
          console.error('Failed to confirm payment on backend:', confirmError)
          // Still consider it successful since payment went through
          onSuccess(paymentIntent.id)
        }
      }
    } catch (err: any) {
      setErrorMessage(err.message || 'An unexpected error occurred')
      onError(err.message || 'An unexpected error occurred')
    } finally {
      setIsProcessing(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="bg-muted p-4 rounded-lg">
        <div className="flex justify-between items-center">
          <span className="text-sm text-muted-foreground">Amount to Pay</span>
          <span className="text-2xl font-bold">
            {currency.toUpperCase()} {amount.toFixed(2)}
          </span>
        </div>
      </div>

      <PaymentElement />

      {errorMessage && (
        <Alert variant="destructive">
          <Icon icon="material-symbols:error-outline" className="h-4 w-4" />
          <AlertDescription>{errorMessage}</AlertDescription>
        </Alert>
      )}

      <div className="bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 p-3 rounded-lg">
        <p className="text-xs text-blue-800 dark:text-blue-200">
          <Icon icon="material-symbols:info-outline" className="inline mr-1" />
          Test Card: 4242 4242 4242 4242, Any future date, Any 3 digits
        </p>
      </div>

      <div className="flex gap-2">
        <Button 
          type="button" 
          variant="outline" 
          className="flex-1"
          onClick={onClose}
          disabled={isProcessing}
        >
          Cancel
        </Button>
        <Button 
          type="submit" 
          className="flex-1"
          disabled={!stripe || isProcessing}
        >
          {isProcessing ? (
            <>
              <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
              Processing...
            </>
          ) : (
            <>
              <Icon icon="material-symbols:lock" className="mr-2 h-4 w-4" />
              Pay {amount.toFixed(2)}
            </>
          )}
        </Button>
      </div>
    </form>
  )
}

export function StripePaymentDialog({
  open,
  onOpenChange,
  amount,
  posOrderId,
  gatewayId,
  onSuccess,
  onError,
}: StripePaymentDialogProps) {
  const [stripePromise, setStripePromise] = useState<Promise<Stripe | null> | null>(null)
  const [clientSecret, setClientSecret] = useState<string>('')
  const [publishableKey, setPublishableKey] = useState<string>('')
  const [currency, setCurrency] = useState<string>('MYR')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState(false)

  useEffect(() => {
    if (!open) {
      // Reset state when dialog closes
      setSuccess(false)
      setError('')
      return
    }
    
    // Wait for valid posOrderId
    if (!posOrderId || posOrderId <= 0) {
      setError('Order not ready. Please try again.')
      setLoading(false)
      return
    }

    const initializePayment = async () => {
      setLoading(true)
      setError('')

      try {
        // Create payment
        const paymentPayload: any = {
          gateway_id: gatewayId,
          pos_order_id: posOrderId,
          amount: amount,
        }
        
        console.log('Creating payment with payload:', paymentPayload)

        const paymentResponse = await api.post('/payment-transactions/create', paymentPayload)
        
        console.log('Payment creation response:', paymentResponse.data)

        const { transaction, client_secret, publishable_key } = paymentResponse.data

        if (!publishable_key) {
          setError('Stripe publishable key not configured. Please add it in payment gateway settings.')
          setLoading(false)
          return
        }

        setClientSecret(client_secret)
        setPublishableKey(publishable_key)
        setCurrency(transaction.currency)
        setStripePromise(loadStripe(publishable_key))
        setLoading(false)
      } catch (err: any) {
        console.error('Payment initialization error:', err)
        console.error('Error response data:', err.response?.data)
        console.error('Error response status:', err.response?.status)
        console.error('Error response headers:', err.response?.headers)
        
        // Extract detailed validation errors if available
        let errorMsg = 'Failed to initialize payment'
        if (err.response?.data?.errors) {
          // Laravel validation errors format
          const errors = err.response.data.errors
          errorMsg = Object.entries(errors)
            .map(([field, msgs]) => `${field}: ${Array.isArray(msgs) ? msgs.join(', ') : msgs}`)
            .join('\n')
        } else if (err.response?.data?.message) {
          errorMsg = err.response.data.message
        } else if (err.response?.data?.error) {
          errorMsg = err.response.data.error
        } else if (err.message) {
          errorMsg = err.message
        }
        
        setError(errorMsg)
        setLoading(false)
      }
    }

    initializePayment()
  }, [open, posOrderId, gatewayId, amount])

  const handleSuccess = (transactionId: string) => {
    setSuccess(true)
    // Delay closing to show success message
    setTimeout(() => {
      onSuccess(transactionId)
      onOpenChange(false)
      setSuccess(false) // Reset for next time
    }, 1500)
  }

  const handleError = (errorMsg: string) => {
    onError(errorMsg)
  }

  const handleClose = () => {
    // Don't allow closing during loading or success animation
    if (!loading && !success) {
      onOpenChange(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleClose} modal={true}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Card Payment</DialogTitle>
          <DialogDescription>
            Complete your payment securely
          </DialogDescription>
        </DialogHeader>

        {success ? (
          <div className="flex flex-col items-center justify-center py-12">
            <div className="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mb-4">
              <Icon icon="material-symbols:check-circle" className="h-10 w-10 text-green-600" />
            </div>
            <h3 className="text-xl font-semibold text-green-600 mb-2">Payment Successful!</h3>
            <p className="text-muted-foreground">Processing your receipt...</p>
          </div>
        ) : loading ? (
          <div className="flex flex-col items-center justify-center py-8">
            <Icon icon="eos-icons:loading" className="h-8 w-8 text-primary mb-4" />
            <p className="text-sm text-muted-foreground">Initializing payment...</p>
          </div>
        ) : error ? (
          <div className="py-4">
            <Alert variant="destructive">
              <Icon icon="material-symbols:error-outline" className="h-4 w-4" />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
            <Button className="w-full mt-4" onClick={handleClose}>
              Close
            </Button>
          </div>
        ) : stripePromise && clientSecret ? (
          <Elements stripe={stripePromise} options={{ clientSecret }}>
            <CheckoutForm
              amount={amount}
              currency={currency}
              clientSecret={clientSecret}
              onSuccess={handleSuccess}
              onError={handleError}
              onClose={handleClose}
            />
          </Elements>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
