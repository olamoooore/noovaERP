import { Download, CheckCircle, WifiOff } from 'lucide-react'
import { usePWAInstall } from '@/hooks/usePWAInstall'
import { useState, useEffect } from 'react'

export function PWAInstallButton() {
  const { isInstallable, isInstalled, install } = usePWAInstall()
  const [isOnline, setIsOnline] = useState(navigator.onLine)

  useEffect(() => {
    const handleOnline = () => setIsOnline(true)
    const handleOffline = () => setIsOnline(false)

    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)

    return () => {
      window.removeEventListener('online', handleOnline)
      window.removeEventListener('offline', handleOffline)
    }
  }, [])

  if (isInstalled) {
    return (
      <div className="flex items-center gap-1.5 text-xs text-green-500">
        <CheckCircle className="h-3.5 w-3.5" />
        <span>App Installed</span>
        {!isOnline && (
          <span className="ml-1 flex items-center gap-1 text-yellow-500">
            <WifiOff className="h-3 w-3" />
            Offline
          </span>
        )}
      </div>
    )
  }

  if (!isInstallable) {
    return null
  }

  return (
    <button
      onClick={install}
      className="flex items-center gap-1.5 rounded-md bg-primary/10 px-3 py-1.5 text-xs font-medium text-primary transition-colors hover:bg-primary/20"
    >
      <Download className="h-3.5 w-3.5" />
      <span>Install POS App</span>
    </button>
  )
}
