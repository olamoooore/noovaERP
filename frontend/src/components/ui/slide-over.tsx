import * as React from 'react'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { cn } from '@/lib/utils'
import { Icon } from '@iconify/react'

const SlideOver = DialogPrimitive.Root
const SlideOverTrigger = DialogPrimitive.Trigger
const SlideOverPortal = DialogPrimitive.Portal
const SlideOverClose = DialogPrimitive.Close

const SlideOverOverlay = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay
    ref={ref}
    className={cn(
      'fixed inset-0 z-40 bg-background/60 backdrop-blur-sm data-[state=open]:animate-in data-[state=closed]:animate-out',
      className
    )}
    {...props}
  />
))
SlideOverOverlay.displayName = DialogPrimitive.Overlay.displayName

const SlideOverContent = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content>
>(({ className, children, ...props }, ref) => (
  <SlideOverPortal>
    <SlideOverOverlay />
    <DialogPrimitive.Content
      ref={ref}
      className={cn(
        'fixed top-0 right-0 z-50 h-full w-full sm:w-[480px] max-w-full bg-background shadow-lg p-4 transform transition-transform duration-200 data-[state=open]:translate-x-0 data-[state=closed]:translate-x-full',
        className
      )}
      {...props}
    >
      {children}
      <DialogPrimitive.Close className="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2">
        <Icon icon="solar:close-circle-bold" className="h-5 w-5" />
        <span className="sr-only">Close</span>
      </DialogPrimitive.Close>
    </DialogPrimitive.Content>
  </SlideOverPortal>
))
SlideOverContent.displayName = DialogPrimitive.Content.displayName

const SlideOverHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div className={cn('flex items-center justify-between', className)} {...props} />
)
SlideOverHeader.displayName = 'SlideOverHeader'

const SlideOverTitle = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Title ref={ref} className={cn('text-lg font-semibold', className)} {...props} />
))
SlideOverTitle.displayName = DialogPrimitive.Title.displayName

const SlideOverFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div className={cn('mt-4 flex justify-end', className)} {...props} />
)
SlideOverFooter.displayName = 'SlideOverFooter'

export {
  SlideOver,
  SlideOverTrigger,
  SlideOverContent,
  SlideOverOverlay,
  SlideOverPortal,
  SlideOverClose,
  SlideOverHeader,
  SlideOverTitle,
  SlideOverFooter,
}
