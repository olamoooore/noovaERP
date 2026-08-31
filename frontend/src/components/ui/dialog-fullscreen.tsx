import * as React from 'react'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import { buttonVariants } from '@/components/ui/button'

/**
 * Fullscreen-capable DialogContent.
 *
 * Adds a small "expand / collapse" toggle button next to the close button. The
 * toggle swaps between the default centered modal and a full-screen modal.
 * Use this component anywhere a dialog should be openable in full-screen
 * without per-page wiring.
 */

interface FullscreenDialogContentProps
  extends React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content> {
  /** Hide the expand toggle (default false = show toggle) */
  hideFullscreenToggle?: boolean
}

const FullscreenDialogContent = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Content>,
  FullscreenDialogContentProps
>(({ className, children, hideFullscreenToggle = false, ...props }, ref) => {
  const [isFullscreen, setIsFullscreen] = React.useState(false)

  // Reset when the dialog closes so the next open starts in normal mode.
  React.useEffect(() => {
    if (!props['aria-hidden']) return
    setIsFullscreen(false)
  }, [props['aria-hidden']])

  return (
    <DialogPrimitive.Portal>
      <DialogPrimitive.Overlay
        className={cn(
          'fixed inset-0 z-50 bg-background/80 backdrop-blur-sm',
          'data-[state=open]:animate-in data-[state=closed]:animate-out',
          'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
        )}
      />
      <DialogPrimitive.Content
        ref={ref}
        data-fullscreen={isFullscreen ? 'true' : 'false'}
        className={cn(
          'fixed left-[50%] top-[50%] z-50 grid translate-x-[-50%] translate-y-[-50%] gap-4 border bg-background shadow-lg duration-200',
          'data-[state=open]:animate-in data-[state=closed]:animate-out',
          'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
          'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
          'sm:rounded-lg',
          isFullscreen
            ? 'w-[100vw] h-[100vh] max-w-none rounded-none p-4 sm:p-6'
            : 'w-full max-w-lg p-6',
          className,
        )}
        {...props}
      >
        {children}
        {!hideFullscreenToggle && (
          <button
            type="button"
            onClick={() => setIsFullscreen((v) => !v)}
            aria-label={isFullscreen ? 'Collapse dialog' : 'Expand dialog to full screen'}
            className={cn(
              buttonVariants({ variant: 'ghost', size: 'icon' }),
              'absolute right-12 top-4 h-7 w-7 rounded-sm opacity-70 hover:opacity-100',
            )}
          >
            <Icon
              icon={isFullscreen ? 'solar:minimize-square-bold' : 'solar:maximize-square-bold'}
              className="h-4 w-4"
            />
          </button>
        )}
        <DialogPrimitive.Close
          aria-label="Close"
          className="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:pointer-events-none"
        >
          <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
          <span className="sr-only">Close</span>
        </DialogPrimitive.Close>
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  )
})
FullscreenDialogContent.displayName = 'FullscreenDialogContent'

export { FullscreenDialogContent }
