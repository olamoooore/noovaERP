import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

interface OfflineBannerProps {
  isOnline: boolean
  queuedCount?: number
  onViewQueue?: () => void
}

export function OfflineBanner({ isOnline, queuedCount = 0, onViewQueue }: OfflineBannerProps) {
  if (isOnline && queuedCount === 0) {
    return null
  }

  return (
    <div
      className={cn(
        'fixed top-0 left-0 right-0 z-50 border-b px-4 py-2',
        !isOnline
          ? 'bg-amber-500 border-amber-600 text-white'
          : 'bg-blue-500 border-blue-600 text-white'
      )}
    >
      <div className="container mx-auto flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Icon
            icon={!isOnline ? 'solar:wifi-off-bold' : 'solar:cloud-upload-bold'}
            className="h-5 w-5 animate-pulse"
          />
          <div>
            <p className="font-semibold text-sm">
              {!isOnline ? 'Offline Mode' : 'Syncing...'}
            </p>
            <p className="text-xs opacity-90">
              {!isOnline
                ? 'Operations will be queued and synced when connection is restored'
                : `${queuedCount} operation${queuedCount !== 1 ? 's' : ''} pending sync`}
            </p>
          </div>
        </div>

        {queuedCount > 0 && onViewQueue && (
          <Button
            variant="secondary"
            size="sm"
            onClick={onViewQueue}
            className="bg-white/20 hover:bg-white/30 text-white border-white/30"
          >
            <Icon icon="solar:list-bold" className="mr-2 h-4 w-4" />
            View Queue ({queuedCount})
          </Button>
        )}

        {!isOnline && (
          <Badge variant="secondary" className="bg-white/20 text-white border-white/30">
            <Icon icon="solar:danger-triangle-bold" className="mr-1 h-3 w-3" />
            No Internet
          </Badge>
        )}
      </div>
    </div>
  )
}
