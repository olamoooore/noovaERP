import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const badgeVariants = cva(
  "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2",
  {
    variants: {
      variant: {
        default: "border-transparent bg-primary text-primary-foreground",
        secondary: "border-transparent bg-secondary text-secondary-foreground",
        destructive: "border-transparent bg-destructive text-destructive-foreground",
        outline: "text-foreground border border-border",
        limited: "bg-gradient-to-r from-amber-500 to-yellow-400 text-black font-semibold border-0",
        preorder: "bg-accent text-accent-foreground border-0",
        new: "bg-gradient-to-r from-primary to-neon-violet text-primary-foreground border-0",
        bestseller: "bg-gradient-to-r from-amber-500 to-orange-500 text-white border-0",
        bronze: "bg-gradient-to-r from-amber-700 to-amber-500 text-white border-0",
        silver: "bg-gradient-to-r from-slate-400 to-slate-300 text-slate-900 border-0",
        gold: "bg-gradient-to-r from-yellow-500 to-amber-300 text-amber-900 border-0",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
);

export interface BadgeProps extends React.HTMLAttributes<HTMLDivElement>, VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return <div className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
