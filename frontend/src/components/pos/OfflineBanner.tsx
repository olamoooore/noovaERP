import { useState, useEffect } from 'react'
import { WifiOff } from 'lucide-react'

export function OfflineBanner() {
  const [isOffline, setIsOffline] = useState(!navigator.onLine)
  const [showBanner, setShowBanner] = useState(false)

  useEffect(() => {
    const handleOffline = () => {
      setIsOffline(true)
      setShowBanner(true)
    }
    const handleOnline = () => {
      setIsOffline(false)
      // Keep banner visible briefly so user sees reconnection
      setTimeout(() => setShowBanner(false), 3000)
    }

    window.addEventListener('offline', handleOffline)
    window.addEventListener('online', handleOnline)

    // Initial check
    if (!navigator.onLine) {
      setShowBanner(true)
    }

    return () => {
      window.removeEventListener('offline', handleOffline)
      window.removeEventListener('online', handleOnline)
    }
  }, [])

  if (!showBanner) return null

  return (
    <div
      className={`fixed top-0 left-0 right-0 z-[9999] flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium transition-colors ${
        isOffline
          ? 'bg-destructive text-destructive-foreground'
          : 'bg-green-600 text-white'
      }`}
    >
      {isOffline ? (
        <>
          <WifiOff className="h-4 w-4" />
          <span>You are offline. Some features may be unavailable.</span>
        </>
      ) : (
        <span>Back online!</span>
      )}
    </div>
  )
}
