import { Icon } from '@iconify/react'

export function PaymentFailedStage() {
  return (
    <div className="flex h-full flex-col items-center justify-center bg-gradient-to-br from-red-50 to-rose-100 p-8">
      <div className="flex flex-col items-center space-y-6">
        <div className="relative">
          <Icon
            icon="mdi:close-circle"
            className="h-32 w-32 text-red-600"
          />
        </div>
        
        <div className="text-center">
          <h2 className="text-5xl font-bold text-red-900">
            Payment Failed
          </h2>
          <p className="mt-2 text-2xl text-red-700">
            Please try again
          </p>
        </div>

        <div className="mt-8 rounded-lg bg-white/80 p-6 shadow-lg">
          <p className="text-center text-lg text-gray-700">
            Please see the cashier for assistance
          </p>
        </div>
      </div>
    </div>
  )
}
