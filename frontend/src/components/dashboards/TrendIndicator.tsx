import React from 'react';
import { ArrowUp, ArrowDown, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';

interface TrendIndicatorProps {
  value: number;
  isPositive: boolean;
  label?: string;
  showIcon?: boolean;
  className?: string;
}

export const TrendIndicator: React.FC<TrendIndicatorProps> = ({
  value,
  isPositive,
  label,
  showIcon = true,
  className = '',
}) => {
  const isZero = value === 0;
  const displayValue = Math.abs(value).toFixed(1);

  const colorClass = isZero
    ? 'text-gray-500'
    : isPositive
    ? 'text-green-600'
    : 'text-red-600';

  const Icon = isZero ? Minus : isPositive ? ArrowUp : ArrowDown;

  return (
    <div className={cn('flex items-center gap-1 text-xs', colorClass, className)}>
      {showIcon && <Icon className="h-3 w-3" />}
      <span className="font-medium">{displayValue}%</span>
      {label && <span className="text-muted-foreground ml-1">{label}</span>}
    </div>
  );
};
