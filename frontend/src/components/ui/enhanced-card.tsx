import * as React from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { cn } from "@/lib/utils"
import { cva, type VariantProps } from "class-variance-authority"

const enhancedCardVariants = cva(
  "transition-all duration-200",
  {
    variants: {
      variant: {
        default: "bg-card hover:shadow-md",
        glass: "bg-white/10 backdrop-blur-md border-white/20",
        gradient: "bg-gradient-to-br from-primary/10 to-secondary/10",
        elevated: "shadow-lg hover:shadow-xl",
        outline: "border-2 hover:border-primary/50",
        outlined: "border-2 hover:border-primary/50", // Alias for outline
      },
      size: {
        default: "",
        sm: "p-2",
        lg: "p-6",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

export interface EnhancedCardProps 
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof enhancedCardVariants> {}

export const EnhancedCard = React.forwardRef<HTMLDivElement, EnhancedCardProps>(
  ({ className, variant, size, ...props }, ref) => (
    <Card 
      ref={ref} 
      className={cn(enhancedCardVariants({ variant, size }), className)} 
      {...props} 
    />
  )
)
EnhancedCard.displayName = "EnhancedCard"

export const EnhancedCardHeader = CardHeader
export const EnhancedCardTitle = CardTitle
export const EnhancedCardDescription = CardDescription
export const EnhancedCardContent = CardContent
