import { Icon } from '@iconify/react'
import { formatCurrency } from '@/lib/utils'

interface PaymentSuccessStageProps {
  total: number
  changeDue?: number
  currencyCode?: string
}

export function PaymentSuccessStage({ 
  total, 
  changeDue = 0, 
  currencyCode = 'USD' 
}: PaymentSuccessStageProps) {
  return (
    <div className="flex h-full flex-col items-center justify-center bg-gradient-to-br from-green-50 to-emerald-100 p-8">
      <div className="flex flex-col items-center space-y-6">
        <div className="relative">
          <div className="absolute inset-0 animate-ping rounded-full bg-green-400 opacity-75"></div>
          <Icon
            icon="mdi:check-circle"
            className="relative h-32 w-32 text-green-600"
          />
        </div>
        
        <div className="text-center">
          <h2 className="text-5xl font-bold text-green-900">
            Payment Successful!
          </h2>
          <p className="mt-2 text-2xl text-green-700">
            Thank you for your purchase
          </p>
        </div>

        <div className="mt-8 rounded-lg bg-white/80 p-6 shadow-lg">
          <div className="text-center">
            <p className="text-lg text-gray-600">Total Paid</p>
            <p className="text-4xl font-bold text-gray-900">
              {formatCurrency(total, currencyCode)}
            </p>
          </div>

          {changeDue > 0 && (
            <div className="mt-4 border-t border-gray-200 pt-4 text-center">
              <p className="text-lg text-gray-600">Change Due</p>
              <p className="text-3xl font-bold text-green-600">
                {formatCurrency(changeDue, currencyCode)}
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
