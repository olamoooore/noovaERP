import React from 'react';
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

// Props for individual metric display (single value mode)
interface SingleMetricProps {
  value: number | string;
  label: string;
  format?: "number" | "currency" | "percentage";
  icon?: LucideIcon;
  size?: "sm" | "md" | "default" | "lg";
  precision?: number;
  className?: string;
}

// Props for multiple metrics display (array mode)
interface MultipleMetricsProps {
  metrics: {
    total_bookings?: number;
    total_spent?: number;
    events_attended?: number;
    favorite_artists?: Array<any>;
    member_since?: string;
    [key: string]: any;
  };
  className?: string;
}

// Union type to support both usage patterns
type AnimatedMetricsProps = SingleMetricProps | MultipleMetricsProps;

// Type guard to check if props are for single metric
function isSingleMetric(props: AnimatedMetricsProps): props is SingleMetricProps {
  return 'value' in props && 'label' in props;
}

const formatValue = (value: number | string, format?: string, precision?: number): string => {
  if (typeof value === 'string') return value;
  
  switch (format) {
    case 'currency':
      return new Intl.NumberFormat('en-US', { 
        style: 'currency', 
        currency: 'USD',
        minimumFractionDigits: precision ?? 0,
        maximumFractionDigits: precision ?? 0,
      }).format(value);
    case 'percentage':
      return `${(value * 100).toFixed(precision ?? 0)}%`;
    case 'number':
    default:
      return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: precision ?? 0,
        maximumFractionDigits: precision ?? 0,
      }).format(value);
  }
};

const sizeClasses = {
  sm: "text-lg",
  md: "text-xl",
  default: "text-2xl",
  lg: "text-4xl"
};

// Single metric component
const SingleMetric: React.FC<SingleMetricProps> = ({ 
  value, 
  label, 
  format, 
  icon: Icon, 
  size = "default",
  precision,
  className 
}) => {
  return (
    <div className={cn("text-center", className)}>
      {Icon && <Icon className="h-6 w-6 mx-auto mb-2 text-muted-foreground" />}
      <div className={cn("font-bold", sizeClasses[size])}>
        {formatValue(value, format, precision)}
      </div>
      <p className="text-xs text-muted-foreground mt-1">{label}</p>
    </div>
  );
};

// Multiple metrics component (grid layout)
const MultipleMetrics: React.FC<MultipleMetricsProps> = ({ metrics, className }) => {
  if (!metrics) return null;

  const items = [
    { title: "Total Bookings", value: metrics.total_bookings ?? 0, format: "number" as const },
    { title: "Total Spent", value: metrics.total_spent ?? 0, format: "currency" as const },
    { title: "Events Attended", value: metrics.events_attended ?? 0, format: "number" as const },
  ];

  return (
    <div className={cn("grid gap-4 md:grid-cols-3", className)}>
      {items.map((item, index) => (
        <Card key={index}>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">{item.title}</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{formatValue(item.value, item.format)}</div>
          </CardContent>
        </Card>
      ))}
    </div>
  );
};

// Main component that handles both patterns
export const AnimatedMetrics: React.FC<AnimatedMetricsProps> = (props) => {
  if (isSingleMetric(props)) {
    return <SingleMetric {...props} />;
  }
  return <MultipleMetrics {...props} />;
};
