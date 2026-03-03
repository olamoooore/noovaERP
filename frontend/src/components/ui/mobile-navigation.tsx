/**
 * Mobile-Optimized Navigation Component
 * Navigation component optimized for thumb-friendly mobile interaction
 */

import React from 'react';
import { cn } from '@/lib/utils';
import { getMobileNavigationClasses, getTouchTargetClasses } from '@/lib/touch-utils';
import { useBreakpoint } from '@/hooks/useBreakpoint';

export interface MobileNavigationProps {
  children: React.ReactNode;
  className?: string;
  position?: 'bottom' | 'top' | 'relative';
}

export const MobileNavigation: React.FC<MobileNavigationProps> = ({
  children,
  className,
  position = 'bottom',
}) => {
  const { isMobile } = useBreakpoint();
  const navigationClasses = getMobileNavigationClasses();
  
  const positionClasses = {
    bottom: 'fixed bottom-0 left-0 right-0',
    top: 'fixed top-0 left-0 right-0',
    relative: 'relative',
  };
  
  return (
    <nav
      className={cn(
        navigationClasses,
        isMobile ? positionClasses[position] : positionClasses.relative,
        className
      )}
    >
      {children}
    </nav>
  );
};

export interface MobileNavItemProps {
  children: React.ReactNode;
  className?: string;
  active?: boolean;
  onClick?: () => void;
  icon?: React.ReactNode;
  label?: string;
}

export const MobileNavItem: React.FC<MobileNavItemProps> = ({
  children,
  className,
  active = false,
  onClick,
  icon,
  label,
}) => {
  const touchClasses = getTouchTargetClasses({ minSize: 44 });
  const { isMobile } = useBreakpoint();
  
  return (
    <button
      onClick={onClick}
      className={cn(
        'flex',
        'flex-col',
        'items-center',
        'justify-center',
        'gap-1',
        'rounded-md',
        'transition-colors',
        'duration-200',
        touchClasses,
        // Mobile-first styling
        'px-2',
        'py-2',
        'text-xs',
        'md:flex-row',
        'md:gap-2',
        'md:px-3',
        'md:py-2',
        'md:text-sm',
        // Active state
        active
          ? 'bg-primary text-primary-foreground'
          : 'text-muted-foreground hover:text-foreground hover:bg-accent',
        className
      )}
    >
      {icon && (
        <span className={cn('flex-shrink-0', isMobile ? 'text-lg' : 'text-base')}>
          {icon}
        </span>
      )}
      {(label || children) && (
        <span className={cn('truncate', isMobile ? 'text-xs' : 'text-sm')}>
          {label || children}
        </span>
      )}
    </button>
  );
};

export interface MobileTabBarProps {
  children: React.ReactNode;
  className?: string;
}

export const MobileTabBar: React.FC<MobileTabBarProps> = ({
  children,
  className,
}) => {
  return (
    <div
      className={cn(
        'flex',
        'items-center',
        'justify-around',
        'bg-background',
        'border-t',
        'px-2',
        'py-1',
        'safe-area-inset-bottom', // Handle devices with home indicator
        className
      )}
    >
      {children}
    </div>
  );
};