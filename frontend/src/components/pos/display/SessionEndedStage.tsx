import { Icon } from '@iconify/react'

interface SessionEndedStageProps {
  message?: string
}

export function SessionEndedStage({ message = 'Welcome!' }: SessionEndedStageProps) {
  return (
    <div className="flex h-full flex-col items-center justify-center bg-gradient-to-br from-gray-50 to-slate-100 p-8">
      <div className="flex flex-col items-center space-y-6">
        <Icon
          icon="mdi:cash-register"
          className="h-32 w-32 text-gray-400"
        />
        
        <div className="text-center">
          <h2 className="text-4xl font-bold text-gray-700">
            {message}
          </h2>
          <p className="mt-4 text-xl text-gray-500">
            Please wait for the next cashier
          </p>
        </div>
      </div>
    </div>
  )
}
