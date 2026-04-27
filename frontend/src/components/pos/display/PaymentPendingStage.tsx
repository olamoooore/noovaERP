import { Icon } from '@iconify/react'

export function PaymentPendingStage() {
  return (
    <div className="flex h-full flex-col items-center justify-center bg-gradient-to-br from-blue-50 to-blue-100 p-8">
      <div className="flex flex-col items-center space-y-6">
        <div className="relative">
          <Icon
            icon="svg-spinners:ring-resize"
            className="h-24 w-24 text-blue-600"
          />
        </div>
        
        <div className="text-center">
          <h2 className="text-4xl font-bold text-blue-900">
            Processing Payment
          </h2>
          <p className="mt-2 text-xl text-blue-700">
            Please wait...
          </p>
        </div>
      </div>
    </div>
  )
}
