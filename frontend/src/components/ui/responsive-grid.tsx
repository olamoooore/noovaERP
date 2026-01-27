/**
 * Responsive Grid Component
 * A flexible grid system that adapts to different screen sizes
 */

import React from 'react';
import { cn } from '@/lib/utils';
import { getResponsiveGridClasses, type ResponsiveGridConfig } from '@/lib/responsive-utils';

export interface ResponsiveGridProps extends ResponsiveGridConfig {
  children: React.ReactNode;
  className?: string;
  as?: keyof JSX.IntrinsicElements;
}

/**
 * Responsive Grid Component
 * Automatically adapts grid layout based on screen size
 */
export const ResponsiveGrid: React.FC<ResponsiveGridProps> = ({
  children,
  className,
  as: Component = 'div',
  ...gridConfig
}) => {
  const gridClasses = getResponsiveGridClasses(gridConfig);

  return (
    <Component className={cn(gridClasses, className)}>
      {children}
    </Component>
  );
};

/**
 * Responsive Grid Item Component
 * Individual grid item with responsive span capabilities
 */
export interface ResponsiveGridItemProps {
  children: React.ReactNode;
  className?: string;
  span?: {
    mobile?: number;
    tablet?: number;
    desktop?: number;
    wide?: number;
  };
  as?: keyof JSX.IntrinsicElements;
}

export const ResponsiveGridItem: React.FC<ResponsiveGridItemProps> = ({
  children,
  className,
  span,
  as: Component = 'div',
}) => {
  const spanClasses: string[] = [];

  if (span?.mobile) {
    spanClasses.push(`col-span-${span.mobile}`);
  }
  if (span?.tablet) {
    spanClasses.push(`md:col-span-${span.tablet}`);
  }
  if (span?.desktop) {
    spanClasses.push(`lg:col-span-${span.desktop}`);
  }
  if (span?.wide) {
    spanClasses.push(`xl:col-span-${span.wide}`);
  }

  return (
    <Component className={cn(spanClasses.join(' '), className)}>
      {children}
    </Component>
  );
};

/**
 * Responsive Container Component
 * Provides consistent container widths across breakpoints
 */
export interface ResponsiveContainerProps {
  children: React.ReactNode;
  className?: string;
  maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'full';
  padding?: boolean;
  as?: keyof JSX.IntrinsicElements;
}

export const ResponsiveContainer: React.FC<ResponsiveContainerProps> = ({
  children,
  className,
  maxWidth = 'xl',
  padding = true,
  as: Component = 'div',
}) => {
  const containerClasses: string[] = ['w-full', 'mx-auto'];

  // Add max-width classes
  switch (maxWidth) {
    case 'sm':
      containerClasses.push('max-w-sm');
      break;
    case 'md':
      containerClasses.push('max-w-md');
      break;
    case 'lg':
      containerClasses.push('max-w-4xl');
      break;
    case 'xl':
      containerClasses.push('max-w-6xl');
      break;
    case '2xl':
      containerClasses.push('max-w-7xl');
      break;
    case 'full':
      containerClasses.push('max-w-full');
      break;
  }

  // Add responsive padding
  if (padding) {
    containerClasses.push('px-4', 'sm:px-6', 'lg:px-8');
  }

  return (
    <Component className={cn(containerClasses.join(' '), className)}>
      {children}
    </Component>
  );
};

/**
 * Responsive Stack Component
 * Stacks items vertically on mobile, horizontally on larger screens
 */
export interface ResponsiveStackProps {
  children: React.ReactNode;
  className?: string;
  direction?: {
    mobile?: 'row' | 'col';
    tablet?: 'row' | 'col';
    desktop?: 'row' | 'col';
  };
  gap?: {
    mobile?: string;
    tablet?: string;
    desktop?: string;
  };
  align?: 'start' | 'center' | 'end' | 'stretch';
  justify?: 'start' | 'center' | 'end' | 'between' | 'around' | 'evenly';
  as?: keyof JSX.IntrinsicElements;
}

export const ResponsiveStack: React.FC<ResponsiveStackProps> = ({
  children,
  className,
  direction = { mobile: 'col', tablet: 'row', desktop: 'row' },
  gap = { mobile: '1rem', tablet: '1.5rem', desktop: '2rem' },
  align = 'start',
  justify = 'start',
  as: Component = 'div',
}) => {
  const stackClasses: string[] = ['flex'];

  // Direction classes
  if (direction.mobile) {
    stackClasses.push(direction.mobile === 'row' ? 'flex-row' : 'flex-col');
  }
  if (direction.tablet) {
    stackClasses.push(direction.tablet === 'row' ? 'md:flex-row' : 'md:flex-col');
  }
  if (direction.desktop) {
    stackClasses.push(direction.desktop === 'row' ? 'lg:flex-row' : 'lg:flex-col');
  }

  // Gap classes
  if (gap.mobile) {
    const mobileGap = convertRemToGapClass(gap.mobile);
    stackClasses.push(`gap-${mobileGap}`);
  }
  if (gap.tablet) {
    const tabletGap = convertRemToGapClass(gap.tablet);
    stackClasses.push(`md:gap-${tabletGap}`);
  }
  if (gap.desktop) {
    const desktopGap = convertRemToGapClass(gap.desktop);
    stackClasses.push(`lg:gap-${desktopGap}`);
  }

  // Alignment classes
  switch (align) {
    case 'center':
      stackClasses.push('items-center');
      break;
    case 'end':
      stackClasses.push('items-end');
      break;
    case 'stretch':
      stackClasses.push('items-stretch');
      break;
    default:
      stackClasses.push('items-start');
  }

  // Justify classes
  switch (justify) {
    case 'center':
      stackClasses.push('justify-center');
      break;
    case 'end':
      stackClasses.push('justify-end');
      break;
    case 'between':
      stackClasses.push('justify-between');
      break;
    case 'around':
      stackClasses.push('justify-around');
      break;
    case 'evenly':
      stackClasses.push('justify-evenly');
      break;
    default:
      stackClasses.push('justify-start');
  }

  return (
    <Component className={cn(stackClasses.join(' '), className)}>
      {children}
    </Component>
  );
};

/**
 * Helper function to convert rem values to Tailwind gap classes
 */
const convertRemToGapClass = (remValue: string): string => {
  const numValue = parseFloat(remValue);
  
  const gapMap: Record<number, string> = {
    0.25: '1',
    0.5: '2',
    0.75: '3',
    1: '4',
    1.25: '5',
    1.5: '6',
    2: '8',
    2.5: '10',
    3: '12',
    4: '16',
  };

  return gapMap[numValue] || '4';
};