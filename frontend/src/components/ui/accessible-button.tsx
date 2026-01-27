import * as React from "react"
import { Button, ButtonProps } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { LucideIcon } from "lucide-react"

export interface AccessibleButtonProps extends ButtonProps {
  icon?: LucideIcon
  ariaLabel?: string
  screenReaderText?: string
}

const AccessibleButton = React.forwardRef<HTMLButtonElement, AccessibleButtonProps>(
  ({ className, children, icon: Icon, ariaLabel, screenReaderText, ...props }, ref) => {
    return (
      <Button
        className={cn(className)}
        ref={ref}
        aria-label={ariaLabel}
        {...props}
      >
        {/* If icon is provided via prop but not as generic child, we could render it. 
            However, usage shows explicit children often include the icon too. 
            We'll render children as primary content. */}
        {children}
        {screenReaderText && <span className="sr-only">{screenReaderText}</span>}
      </Button>
    )
  }
)
AccessibleButton.displayName = "AccessibleButton"

export { AccessibleButton }
