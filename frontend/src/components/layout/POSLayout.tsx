import { Outlet, Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { useState, useEffect } from 'react'

export function POSLayout() {
  const [isFullscreen, setIsFullscreen] = useState(false)

  useEffect(() => {
    const handleFullscreenChange = () => {
      setIsFullscreen(!!document.fullscreenElement)
    }
    document.addEventListener('fullscreenchange', handleFullscreenChange)
    return () => document.removeEventListener('fullscreenchange', handleFullscreenChange)
  }, [])

  const toggleFullscreen = async () => {
    if (!document.fullscreenElement) {
      await document.documentElement.requestFullscreen()
    } else {
      await document.exitFullscreen()
    }
  }

  return (
    <div className="flex h-screen flex-col overflow-hidden bg-background">
      {/* Minimal Top Bar */}
      <header className="flex h-14 items-center justify-between border-b bg-primary px-4 text-primary-foreground">
        <div className="flex items-center gap-4">
          <Link to="/" className="flex items-center gap-2">
            <Icon icon="solar:buildings-2-bold" className="h-7 w-7" />
            <span className="text-lg font-bold">NoovaERP</span>
          </Link>
          <span className="text-sm opacity-80">Point of Sale</span>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            className="text-primary-foreground hover:bg-primary-foreground/10"
            onClick={toggleFullscreen}
          >
            <Icon 
              icon={isFullscreen ? "solar:quit-full-screen-bold" : "solar:full-screen-bold"} 
              className="h-5 w-5" 
            />
            <span className="ml-2 hidden sm:inline">
              {isFullscreen ? 'Exit Fullscreen' : 'Fullscreen'}
            </span>
          </Button>

          <Link to="/pos/orders">
            <Button
              variant="ghost"
              size="sm"
              className="text-primary-foreground hover:bg-primary-foreground/10"
            >
              <Icon icon="solar:document-text-bold" className="h-5 w-5" />
              <span className="ml-2 hidden sm:inline">Orders</span>
            </Button>
          </Link>

          <Link to="/">
            <Button
              variant="ghost"
              size="sm"
              className="text-primary-foreground hover:bg-primary-foreground/10"
            >
              <Icon icon="solar:home-2-bold" className="h-5 w-5" />
              <span className="ml-2 hidden sm:inline">Home</span>
            </Button>
          </Link>
        </div>
      </header>

      {/* Main Content - Full Width */}
      <main className="flex-1 overflow-hidden">
        <Outlet />
      </main>
    </div>
  )
}
