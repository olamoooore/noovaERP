import { useState, useEffect } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Stripe } from '@stripe/stripe-js'
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { loadAceStripe } from '@/lib/stripe'

interface PaymentData {
  clientSecret: string
  publishableKey: string
  amount: number
  currency: string
  invoiceNumber: string
}

function CheckoutForm({ paymentData }: { paymentData: PaymentData }) {
  const stripe = useStripe()
  const elements = useElements()
  const navigate = useNavigate()
  const { toast } = useToast()
  const [isProcessing, setIsProcessing] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string>('')

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
          return_url: `${window.location.origin}/payment-success`,
        },
        redirect: 'if_required',
      })

      if (error) {
        setErrorMessage(error.message || 'Payment failed')
        toast({
          variant: 'destructive',
          title: 'Payment failed',
          description: error.message,
        })
      } else if (paymentIntent && paymentIntent.status === 'succeeded') {
        // Update transaction status on backend
        try {
          console.log('Confirming payment with transaction_id:', paymentIntent.id)
          const confirmResponse = await api.post('/payment-transactions/confirm', {
            transaction_id: paymentIntent.id,
          })
          console.log('Confirm response:', confirmResponse.data)
        } catch (confirmError: any) {
          console.error('Failed to confirm payment on backend:', confirmError)
          console.error('Error response:', confirmError.response?.data)
          
          // Show error to user but don't block the success flow
          toast({
            variant: 'destructive',
            title: 'Warning',
            description: 'Payment succeeded but status update failed. Please refresh the page.',
          })
        }

        toast({
          title: 'Payment successful!',
          description: `Your payment of ${paymentData.currency.toUpperCase()} ${Number(paymentData.amount).toFixed(2)} has been processed.`,
        })
        
        // Redirect to success page or invoices
        setTimeout(() => {
          navigate('/invoices')
        }, 2000)
      }
    } catch (err: any) {
      setErrorMessage(err.message || 'An unexpected error occurred')
    } finally {
      setIsProcessing(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <div className="bg-muted p-4 rounded-lg">
        <div className="flex justify-between items-center mb-2">
          <span className="text-sm text-muted-foreground">Invoice</span>
          <span className="font-medium">{paymentData.invoiceNumber}</span>
        </div>
        <div className="flex justify-between items-center">
          <span className="text-sm text-muted-foreground">Amount</span>
          <span className="text-2xl font-bold">
            {paymentData.currency.toUpperCase()} {Number(paymentData.amount).toFixed(2)}
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

      <div className="bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 p-4 rounded-lg">
        <p className="text-sm text-blue-900 dark:text-blue-100 font-medium mb-2">
          <Icon icon="material-symbols:info-outline" className="inline mr-1" />
          Test Mode - Use Test Card
        </p>
        <div className="space-y-1 text-xs text-blue-800 dark:text-blue-200">
          <p>• Card: <code className="bg-blue-100 dark:bg-blue-900 px-2 py-0.5 rounded">4242 4242 4242 4242</code></p>
          <p>• Expiry: Any future date (e.g., 12/25)</p>
          <p>• CVC: Any 3 digits (e.g., 123)</p>
        </div>
      </div>

      <Button 
        type="submit" 
        className="w-full" 
        size="lg"
        disabled={!stripe || isProcessing}
      >
        {isProcessing ? (
          <>
            <Icon icon="eos-icons:loading" className="mr-2 h-5 w-5" />
            Processing...
          </>
        ) : (
          <>
            <Icon icon="material-symbols:lock" className="mr-2 h-5 w-5" />
            Pay {paymentData.currency.toUpperCase()} {Number(paymentData.amount).toFixed(2)}
          </>
        )}
      </Button>

      <p className="text-xs text-center text-muted-foreground">
        <Icon icon="material-symbols:shield-lock-outline" className="inline mr-1" />
        Secured by Stripe
      </p>
    </form>
  )
}

export function PaymentCheckout() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { toast } = useToast()
  const [paymentData, setPaymentData] = useState<PaymentData | null>(null)
  const [stripePromise, setStripePromise] = useState<Promise<Stripe | null> | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string>('')

  const invoiceId = searchParams.get('invoice')
  const posOrderId = searchParams.get('pos_order')
  const amount = searchParams.get('amount')
  const gatewayId = searchParams.get('gateway')

  useEffect(() => {
    const initializePayment = async () => {
      if (!invoiceId && !posOrderId) {
        setError('Invoice ID or POS Order ID is required')
        setLoading(false)
        return
      }

      try {
        let selectedGatewayId = gatewayId ? parseInt(gatewayId) : null
        
        // If no gateway specified, get the active one
        if (!selectedGatewayId) {
          const gatewaysResponse = await api.get('/payment-gateways')
          const activeGateway = gatewaysResponse.data.find((g: any) => g.is_active)
          
          if (!activeGateway) {
            setError('No active payment gateway found. Please contact support.')
            setLoading(false)
            return
          }
          selectedGatewayId = activeGateway.id
        }

        // Create payment with either invoice_id or pos_order_id
        const paymentPayload: any = {
          gateway_id: selectedGatewayId,
          amount: amount ? parseFloat(amount) : undefined,
        }
        
        if (invoiceId) {
          paymentPayload.invoice_id = parseInt(invoiceId)
        } else if (posOrderId) {
          paymentPayload.pos_order_id = parseInt(posOrderId)
        }

        const paymentResponse = await api.post('/payment-transactions/create', paymentPayload)

        const { transaction, client_secret, publishable_key, invoice, pos_order, payment_url } = paymentResponse.data
        
        // Get gateway info for the selected gateway
        const gatewaysResponse = await api.get('/payment-gateways')
        const gatewayInfo = gatewaysResponse.data.find((g: any) => g.id === selectedGatewayId)

        // If HitPay or PayPal, redirect to payment URL
        if (gatewayInfo?.gateway_name === 'hitpay' || gatewayInfo?.gateway_name === 'paypal') {
          if (payment_url) {
            window.location.href = payment_url
            return
          } else {
            setError('Payment URL not provided by gateway. Please try again.')
            setLoading(false)
            return
          }
        }

        // Stripe flow - only execute if gateway is Stripe
        if (gatewayInfo?.gateway_name === 'stripe') {
          if (!publishable_key) {
            setError('Stripe publishable key not configured. Please add it in payment gateway settings.')
            setLoading(false)
            return
          }

          setPaymentData({
            clientSecret: client_secret,
            publishableKey: publishable_key,
            amount: transaction.amount,
            currency: transaction.currency,
            invoiceNumber: invoice?.invoice_number || pos_order?.order_number || 'N/A',
          })

          // Load Stripe with publishable key
          setStripePromise(loadAceStripe(publishable_key))
          setLoading(false)
        } else {
          setError(`Unsupported payment gateway: ${gatewayInfo?.gateway_name || 'unknown'}`)
          setLoading(false)
          return
        }
      } catch (err: any) {
        console.error('Payment initialization error:', err)
        console.error('Full error response:', JSON.stringify(err.response?.data, null, 2))
        setError(err.response?.data?.message || 'Failed to initialize payment')
        setLoading(false)
        
        toast({
          variant: 'destructive',
          title: 'Payment initialization failed',
          description: err.response?.data?.message || err.response?.data?.errors ? JSON.stringify(err.response.data.errors) : 'Please try again',
        })
      }
    }

    initializePayment()
  }, [invoiceId, posOrderId, amount, gatewayId, toast])

  if (loading) {
    return (
      <div className="container max-w-2xl mx-auto py-8">
        <Card>
          <CardContent className="pt-6">
            <div className="flex flex-col items-center justify-center py-12">
              <Icon icon="eos-icons:loading" className="h-12 w-12 text-primary mb-4" />
              <p className="text-muted-foreground">Initializing payment...</p>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }

  if (error || !paymentData || !stripePromise) {
    return (
      <div className="container max-w-2xl mx-auto py-8">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-destructive">
              <Icon icon="material-symbols:error-outline" className="h-6 w-6" />
              Payment Error
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <Alert variant="destructive">
              <AlertDescription>{error || 'Failed to load payment details'}</AlertDescription>
            </Alert>
            <Button onClick={() => navigate('/invoices')} variant="outline" className="w-full">
              <Icon icon="material-symbols:arrow-back" className="mr-2 h-4 w-4" />
              Back to Invoices
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <div className="container max-w-2xl mx-auto py-8">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="material-symbols:payment" className="h-6 w-6" />
            Complete Payment
          </CardTitle>
          <CardDescription>
            Enter your card details to complete the payment
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Elements 
            stripe={stripePromise} 
            options={{
              clientSecret: paymentData.clientSecret,
              appearance: {
                theme: 'stripe',
              },
            }}
          >
            <CheckoutForm paymentData={paymentData} />
          </Elements>
        </CardContent>
      </Card>
    </div>
  )
}
