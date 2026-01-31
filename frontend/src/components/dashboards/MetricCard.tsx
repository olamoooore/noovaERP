import React from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

interface MetricCardProps {
  label: string;
  value: string | number;
  icon?: LucideIcon;
  iconColor?: string;
  change?: {
    value: number;
    label?: string;
  };
  loading?: boolean;
  className?: string;
}

export const MetricCard: React.FC<MetricCardProps> = ({
  label,
  value,
  icon: Icon,
  iconColor = 'text-blue-600',
  change,
  loading = false,
  className = '',
}) => {
  if (loading) {
    return (
      <Card className={className}>
        <CardContent className="p-4">
          <div className="flex items-center justify-between">
            <div className="flex-1">
              <div className="h-4 w-20 bg-gray-200 animate-pulse rounded mb-2" />
              <div className="h-6 w-24 bg-gray-200 animate-pulse rounded" />
            </div>
            {Icon && (
              <div className="h-10 w-10 bg-gray-200 animate-pulse rounded-full" />
            )}
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className={className}>
      <CardContent className="p-4">
        <div className="flex items-center justify-between">
          <div className="flex-1">
            <p className="text-sm text-muted-foreground mb-1">{label}</p>
            <p className="text-xl font-bold">{value}</p>
            {change && (
              <p
                className={cn(
                  'text-xs mt-1',
                  change.value >= 0 ? 'text-green-600' : 'text-red-600'
                )}
              >
                {change.value >= 0 ? '+' : ''}
                {change.value.toFixed(1)}%
                {change.label && ` ${change.label}`}
              </p>
            )}
          </div>
          {Icon && (
            <div className={cn('p-2 rounded-full bg-opacity-10', iconColor)}>
              <Icon className={cn('h-6 w-6', iconColor)} />
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  );
};
