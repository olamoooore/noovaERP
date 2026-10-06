import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { CheckCircle2, Loader2, XCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { PaymentRetry } from '@/components/payment/PaymentRetry'
import api from '@/lib/api'
import { formatCurrency } from '@/lib/utils'

export default function PaymentSuccess() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState<'success' | 'pending' | 'failed'>('pending')
  const [invoice, setInvoice] = useState<any>(null)
  const [transaction, setTransaction] = useState<any>(null)
  const [error, setError] = useState('')
  const [checking, setChecking] = useState(false)

  // Get transaction reference from URL
  // PayPal uses 'token', HitPay uses 'ref', Stripe might use 'reference'
  const token = searchParams.get('token')
  const ref = searchParams.get('ref') || searchParams.get('reference')
  const reference = token || ref

  const handleCheckPayment = async () => {
    if (!reference) {
      setError('No transaction reference')
      return
    }

    setChecking(true)
    try {
      // Call confirm endpoint to check payment status with gateway API
      await api.post('/payment-transactions/confirm', {
        transaction_id: reference,
      })

      // Refresh transaction to get updated invoice status
      const txResponse = await api.get(`/payment-transactions/transaction/${reference}`)
      const txData = txResponse.data.data
      setTransaction(txData)
      
      if (txData.invoice) {
        setInvoice(txData.invoice)
        
        if (txData.invoice.status === 'paid' || txData.invoice.balance <= 0) {
          setStatus('success')
        } else {
          setStatus('pending')
        }
      }
    } catch (err: any) {
      console.error('Payment check error:', err)
      setError(err.response?.data?.message || 'Failed to check payment status')
    } finally {
      setChecking(false)
    }
  }

  useEffect(() => {
    const checkPaymentStatus = async () => {
      try {
        if (!reference) {
          setError('Invalid payment information')
          setStatus('failed')
          setLoading(false)
          return
        }

        // Clean up URL by removing sensitive parameters (PayerID, token, etc)
        // Keep only essential ref parameter
        const cleanUrl = new URL(window.location.href)
        const hasToken = searchParams.get('token')
        const hasPayerId = searchParams.get('PayerID')
        
        if (hasToken || hasPayerId) {
          // Remove PayPal-specific parameters
          cleanUrl.searchParams.delete('PayerID')
          cleanUrl.searchParams.delete('token')
          cleanUrl.searchParams.set('ref', reference)
          window.history.replaceState({}, '', cleanUrl.toString())
        }

        // Fetch transaction by reference (this also loads the invoice)
        const txResponse = await api.get(`/payment-transactions/transaction/${reference}`)
        const txData = txResponse.data.data
        setTransaction(txData)
        
        // Get invoice from transaction
        if (txData.invoice) {
          setInvoice(txData.invoice)
          
          // Check if invoice is paid
          if (txData.invoice.status === 'paid' || txData.invoice.balance <= 0) {
            setStatus('success')
            setLoading(false)
            return
          }
        }

        // If payment is pending, start polling
        if (txData.status === 'completed' || txData.status === 'succeeded') {
          // Payment gateway says completed, wait for webhook to process
          // Poll invoice status every 3 seconds for up to 45 seconds
          let attempts = 0
          const maxAttempts = 15 // 45 seconds total

          const pollInterval = setInterval(async () => {
            attempts++
            
            try {
              const recheckResponse = await api.get(`/payment-transactions/transaction/${reference}`)
              const recheckData = recheckResponse.data.data
              
              if (recheckData.invoice && (recheckData.invoice.status === 'paid' || recheckData.invoice.balance <= 0)) {
                setStatus('success')
                setInvoice(recheckData.invoice)
                clearInterval(pollInterval)
              } else if (attempts >= maxAttempts) {
                // Webhook hasn't arrived yet, show pending with manual check option
                setStatus('pending')
                if (recheckData.invoice) setInvoice(recheckData.invoice)
                clearInterval(pollInterval)
              }
            } catch (err) {
              console.error('Poll error:', err)
              if (attempts >= maxAttempts) {
                clearInterval(pollInterval)
              }
            }
          }, 3000)

          // Clean up interval on unmount
          return () => clearInterval(pollInterval)
        } else {
          setStatus('pending')
          setLoading(false)
        }
      } catch (err: any) {
        console.error('Payment status check error:', err)
        setError(err.response?.data?.message || 'Failed to verify payment status')
        setStatus('failed')
      } finally {
        setLoading(false)
      }
    }

    checkPaymentStatus()
  }, [reference])

  if (loading) {
    return (
      <div className="container max-w-2xl mx-auto py-12 flex flex-col items-center justify-center min-h-[400px]">
        <Loader2 className="h-12 w-12 animate-spin text-primary mb-4" />
        <p className="text-lg text-muted-foreground">Verifying payment status...</p>
      </div>
    )
  }

  return (
    <div className="container max-w-2xl mx-auto py-12">
      <Card>
        <CardHeader className="text-center">
          {status === 'success' && (
            <>
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
                <CheckCircle2 className="h-10 w-10 text-green-600" />
              </div>
              <CardTitle className="text-2xl">Payment Successful!</CardTitle>
              <CardDescription>
                Your payment has been processed successfully
              </CardDescription>
            </>
          )}

          {status === 'pending' && (
            <>
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-yellow-100">
                <Loader2 className="h-10 w-10 text-yellow-600 animate-spin" />
              </div>
              <CardTitle className="text-2xl">Payment Processing</CardTitle>
              <CardDescription>
                Your payment is being processed. This may take a few moments.
              </CardDescription>
            </>
          )}

          {status === 'failed' && (
            <>
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
                <XCircle className="h-10 w-10 text-red-600" />
              </div>
              <CardTitle className="text-2xl">Payment Failed</CardTitle>
              <CardDescription>
                There was an issue processing your payment
              </CardDescription>
            </>
          )}
        </CardHeader>

        <CardContent className="space-y-6">
          {invoice && (
            <div className="bg-muted rounded-lg p-4 space-y-2">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Invoice Number:</span>
                <span className="font-medium">{invoice.invoice_number}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Total Amount:</span>
                <span className="font-medium">
                  {formatCurrency(Number(invoice.total_amount) || 0, invoice.currency || undefined)}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Balance:</span>
                <span className="font-medium">
                  {formatCurrency(Number(invoice.balance) || 0, invoice.currency || undefined)}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Status:</span>
                <span className={`font-medium capitalize ${
                  invoice.status === 'paid' ? 'text-green-600' : ''
                }`}>
                  {invoice.status}
                </span>
              </div>
            </div>
          )}

          {error && (
            <Alert variant="destructive">
              <XCircle className="h-4 w-4" />
              <AlertTitle>Error</AlertTitle>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {status === 'failed' && invoice && transaction && (
            <PaymentRetry
              transactionId={transaction.transaction_id}
              invoiceId={invoice.id}
              onRetrySuccess={() => setLoading(true)}
            />
          )}

          {status === 'pending' && (
            <Alert>
              <Loader2 className="h-4 w-4 animate-spin" />
              <AlertTitle>Processing</AlertTitle>
              <AlertDescription>
                Your payment is being verified. You can check the invoice status later or click the button below to manually verify with the payment gateway.
              </AlertDescription>
            </Alert>
          )}

          {status === 'pending' && (transaction || reference) && (
            <div className="flex justify-center">
              <Button
                onClick={handleCheckPayment}
                disabled={checking}
                variant="outline"
              >
                {checking ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                    Checking Payment...
                  </>
                ) : (
                  'Check Payment Status'
                )}
              </Button>
            </div>
          )}

          <div className="flex gap-4 justify-center pt-4">
            <Button
              variant="outline"
              onClick={() => navigate('/invoices')}
            >
              View All Invoices
            </Button>
            {invoice && (
              <Button onClick={() => navigate(`/invoices/${invoice.id}`)}>
                View Invoice Details
              </Button>
            )}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
