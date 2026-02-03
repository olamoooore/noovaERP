import { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import api from '@/lib/api'

interface PaymentMethod {
  id: string
  code: string
  name: string
  type: 'cash' | 'online' | 'offline'
  category: string
  icon: string
  requires_reference: boolean
  reference_label?: string
  reference_placeholder?: string
  gateway_id?: number
  config?: {
    instructions?: string
    account_details?: Record<string, string>
    is_test_mode?: boolean
  }
}

interface PaymentMethodSelectorProps {
  amount: number
  onSelect: (method: PaymentMethod, reference?: string) => void
  onCancel: () => void
}

export function PaymentMethodSelector({ amount, onSelect, onCancel }: PaymentMethodSelectorProps) {
  const [selectedMethod, setSelectedMethod] = useState<PaymentMethod | null>(null)
  const [referenceNumber, setReferenceNumber] = useState('')

  const { data: methods, isLoading } = useQuery({
    queryKey: ['pos-payment-methods'],
    queryFn: async () => {
      const response = await api.get('/pos-payments/methods')
      return response.data.methods as PaymentMethod[]
    },
  })

  const handleSelect = (method: PaymentMethod) => {
    setSelectedMethod(method)
    if (!method.requires_reference) {
      onSelect(method)
    }
  }

  const handleConfirmOffline = () => {
    if (selectedMethod && referenceNumber) {
      onSelect(selectedMethod, referenceNumber)
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center p-8">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    )
  }

  // Group methods by category
  const groupedMethods = methods?.reduce((acc, method) => {
    const category = method.category
    if (!acc[category]) acc[category] = []
    acc[category].push(method)
    return acc
  }, {} as Record<string, PaymentMethod[]>)

  const categoryLabels: Record<string, string> = {
    cash: 'Cash Payment',
    card: 'Card Payments',
    bank_transfer: 'Bank Transfer / Offline',
  }

  if (selectedMethod?.requires_reference) {
    return (
      <div className="space-y-4">
        <div className="text-center mb-4">
          <h3 className="text-lg font-semibold">{selectedMethod.name}</h3>
          <p className="text-muted-foreground">Amount: ${amount.toFixed(2)}</p>
        </div>

        {selectedMethod.config?.instructions && (
          <div className="bg-muted p-4 rounded-lg text-sm">
            <p className="font-medium mb-2">Payment Instructions:</p>
            <p className="text-muted-foreground">{selectedMethod.config.instructions}</p>
          </div>
        )}

        {selectedMethod.config?.account_details && (
          <div className="bg-muted p-4 rounded-lg text-sm space-y-2">
            <p className="font-medium">Account Details:</p>
            {Object.entries(selectedMethod.config.account_details).map(([key, value]) => (
              <div key={key} className="flex justify-between">
                <span className="text-muted-foreground capitalize">{key.replace('_', ' ')}:</span>
                <span className="font-medium">{value}</span>
              </div>
            ))}
          </div>
        )}

        <div className="space-y-2">
          <Label htmlFor="reference">
            {selectedMethod.reference_label || 'Reference Number'}
          </Label>
          <Input
            id="reference"
            placeholder={selectedMethod.reference_placeholder}
            value={referenceNumber}
            onChange={(e) => setReferenceNumber(e.target.value)}
          />
          <p className="text-xs text-muted-foreground">
            Enter the transaction reference or receipt number
          </p>
        </div>

        <div className="flex gap-2 pt-4">
          <Button variant="outline" onClick={() => setSelectedMethod(null)} className="flex-1">
            Back
          </Button>
          <Button 
            onClick={handleConfirmOffline} 
            disabled={!referenceNumber}
            className="flex-1"
          >
            Confirm Payment
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="text-center mb-4">
        <h3 className="text-lg font-semibold">Select Payment Method</h3>
        <p className="text-muted-foreground">Amount: ${amount.toFixed(2)}</p>
      </div>

      {groupedMethods && Object.entries(groupedMethods).map(([category, categoryMethods]) => (
        <div key={category} className="space-y-2">
          <h4 className="text-sm font-medium text-muted-foreground uppercase tracking-wide">
            {categoryLabels[category] || category}
          </h4>
          <div className="grid grid-cols-1 gap-2">
            {categoryMethods.map((method) => (
              <Card
                key={method.id}
                className="cursor-pointer hover:bg-accent transition-colors"
                onClick={() => handleSelect(method)}
              >
                <CardContent className="flex items-center gap-3 p-4">
                  <Icon icon={method.icon} className="h-5 w-5" />
                  <div className="flex-1">
                    <p className="font-medium">{method.name}</p>
                    {method.config?.is_test_mode && (
                      <span className="text-xs text-amber-500">Test Mode</span>
                    )}
                  </div>
                  <Icon icon="solar:alt-arrow-right-bold" className="h-4 w-4 text-muted-foreground" />
                </CardContent>
              </Card>
            ))}
          </div>
        </div>
      ))}

      <Button variant="outline" onClick={onCancel} className="w-full">
        Cancel
      </Button>
    </div>
  )
}
