import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface ChartContainerProps {
  title?: string;
  description?: string;
  children: React.ReactNode;
  className?: string;
  actions?: React.ReactNode;
}

/**
 * ChartContainer - Wrapper component for all charts
 * Provides consistent styling and layout
 */
export const ChartContainer: React.FC<ChartContainerProps> = ({
  title,
  description,
  children,
  className,
  actions,
}) => {
  return (
    <Card className={cn('w-full', className)}>
      {(title || description || actions) && (
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-4">
          <div className="space-y-1">
            {title && <CardTitle className="text-lg">{title}</CardTitle>}
            {description && <CardDescription>{description}</CardDescription>}
          </div>
          {actions && <div className="flex items-center gap-2">{actions}</div>}
        </CardHeader>
      )}
      <CardContent className="pt-0">
        <div className="w-full h-full">{children}</div>
      </CardContent>
    </Card>
  );
};
