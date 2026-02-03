import { useState } from 'react'
import { AlertCircle, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import api from '@/lib/api'

interface PaymentRetryProps {
  transactionId: string
  invoiceId: number
  onRetrySuccess?: () => void
}

export function PaymentRetry({ invoiceId, onRetrySuccess }: PaymentRetryProps) {
  const [retrying, setRetrying] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleRetry = async () => {
    setRetrying(true)
    setError(null)

    try {
      // Create a new payment attempt for the same invoice
      const gatewayResponse = await api.get('/payment-gateways')
      const gateways = gatewayResponse.data.data.filter((g: any) => g.is_active)
      
      if (gateways.length === 0) {
        setError('No active payment gateways available')
        return
      }

      const response = await api.post('/payment-transactions/create', {
        invoice_id: invoiceId,
        gateway_id: gateways[0].id,
        amount: null, // Use invoice total
      })

      if (response.data.payment_url) {
        // Redirect to new payment URL
        window.location.href = response.data.payment_url
      }

      onRetrySuccess?.()
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to retry payment. Please try again.')
    } finally {
      setRetrying(false)
    }
  }

  return (
    <div className="space-y-4">
      {error && (
        <Alert variant="destructive">
          <AlertCircle className="h-4 w-4" />
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      
      <Button
        onClick={handleRetry}
        disabled={retrying}
        variant="outline"
        className="w-full"
      >
        {retrying ? (
          <>
            <RefreshCw className="mr-2 h-4 w-4 animate-spin" />
            Retrying Payment...
          </>
        ) : (
          <>
            <RefreshCw className="mr-2 h-4 w-4" />
            Retry Payment
          </>
        )}
      </Button>
    </div>
  )
}
