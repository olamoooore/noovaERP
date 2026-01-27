import * as React from "react"
import { cn } from "@/lib/utils"
import { cva, type VariantProps } from "class-variance-authority"

const textVariants = cva(
  "",
  {
    variants: {
      variant: {
        default: "",
        muted: "text-muted-foreground",
        primary: "text-primary",
        secondary: "text-secondary-foreground",
      },
      size: {
        xs: "text-xs",
        sm: "text-sm",
        base: "text-base",
        lg: "text-lg",
        xl: "text-xl",
        "2xl": "text-2xl",
        "3xl": "text-3xl",
      },
      weight: {
        normal: "font-normal",
        medium: "font-medium",
        semibold: "font-semibold",
        bold: "font-bold",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "base",
      weight: "normal",
    },
  }
)

interface TextProps 
  extends React.HTMLAttributes<HTMLParagraphElement>,
    VariantProps<typeof textVariants> {}

export const ResponsiveText = ({ className, variant, size, weight, ...props }: TextProps) => {
  return <p className={cn(textVariants({ variant, size, weight }), className)} {...props} />
}

export const Heading1 = ({ className, ...props }: React.HTMLAttributes<HTMLHeadingElement>) => {
  return <h1 className={cn("scroll-m-20 text-4xl font-extrabold tracking-tight lg:text-5xl", className)} {...props} />
}

export const Heading2 = ({ className, ...props }: React.HTMLAttributes<HTMLHeadingElement>) => {
  return <h2 className={cn("scroll-m-20 border-b pb-2 text-3xl font-semibold tracking-tight first:mt-0", className)} {...props} />
}

export const Heading3 = ({ className, ...props }: React.HTMLAttributes<HTMLHeadingElement>) => {
  return <h3 className={cn("scroll-m-20 text-2xl font-semibold tracking-tight", className)} {...props} />
}

export const BodyText = ({ className, variant = "default", size, weight, ...props }: TextProps) => {
  return (
    <p 
      className={cn(
        "leading-7 [&:not(:first-child)]:mt-6", 
        textVariants({ variant, size, weight }),
        className
      )} 
      {...props} 
    />
  )
}

export const SmallText = ({ className, variant, size = "sm", weight = "medium", ...props }: TextProps) => {
  return (
    <small 
      className={cn(
        "leading-none",
        textVariants({ variant, size, weight }),
        className
      )} 
      {...props} 
    />
  )
}
