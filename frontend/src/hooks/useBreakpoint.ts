/**
 * Responsive Breakpoint Hook
 * Provides utilities for responsive design and breakpoint detection
 */

import { useState, useEffect } from 'react';
import { designTokens } from '@/lib/design-tokens';

export type Breakpoint = 'mobile' | 'tablet' | 'desktop' | 'wide';

export interface BreakpointState {
  current: Breakpoint;
  isMobile: boolean;
  isTablet: boolean;
  isDesktop: boolean;
  isWide: boolean;
  width: number;
  height: number;
}

/**
 * Hook to detect current breakpoint and provide responsive utilities
 */
export const useBreakpoint = (): BreakpointState => {
  const [breakpointState, setBreakpointState] = useState<BreakpointState>(() => {
    // Initialize with safe defaults for SSR
    if (typeof window === 'undefined') {
      return {
        current: 'desktop',
        isMobile: false,
        isTablet: false,
        isDesktop: true,
        isWide: false,
        width: 1024,
        height: 768,
      };
    }

    return getBreakpointState(window.innerWidth, window.innerHeight);
  });

  useEffect(() => {
    if (typeof window === 'undefined') return;

    const handleResize = () => {
      const newState = getBreakpointState(window.innerWidth, window.innerHeight);
      setBreakpointState(newState);
    };

    // Set initial state
    handleResize();

    // Add event listener
    window.addEventListener('resize', handleResize);

    // Cleanup
    return () => {
      window.removeEventListener('resize', handleResize);
    };
  }, []);

  return breakpointState;
};

/**
 * Utility function to determine breakpoint state from dimensions
 */
const getBreakpointState = (width: number, height: number): BreakpointState => {
  const mobileBreakpoint = parseInt(designTokens.breakpoints.mobile);
  const tabletBreakpoint = parseInt(designTokens.breakpoints.tablet);
  const desktopBreakpoint = parseInt(designTokens.breakpoints.desktop);

  let current: Breakpoint;
  if (width < mobileBreakpoint) {
    current = 'mobile';
  } else if (width < tabletBreakpoint) {
    current = 'tablet';
  } else if (width < desktopBreakpoint) {
    current = 'desktop';
  } else {
    current = 'wide';
  }

  return {
    current,
    isMobile: width < mobileBreakpoint,
    isTablet: width >= mobileBreakpoint && width < tabletBreakpoint,
    isDesktop: width >= tabletBreakpoint && width < desktopBreakpoint,
    isWide: width >= desktopBreakpoint,
    width,
    height,
  };
};

/**
 * Hook for media query matching
 */
export const useMediaQuery = (query: string): boolean => {
  const [matches, setMatches] = useState<boolean>(() => {
    if (typeof window === 'undefined') return false;
    return window.matchMedia(query).matches;
  });

  useEffect(() => {
    if (typeof window === 'undefined') return;

    const mediaQuery = window.matchMedia(query);
    const handleChange = (event: MediaQueryListEvent) => {
      setMatches(event.matches);
    };

    // Set initial state
    setMatches(mediaQuery.matches);

    // Add listener
    mediaQuery.addEventListener('change', handleChange);

    // Cleanup
    return () => {
      mediaQuery.removeEventListener('change', handleChange);
    };
  }, [query]);

  return matches;
};

/**
 * Predefined media query hooks for common breakpoints
 */
export const useIsMobile = (): boolean => {
  return useMediaQuery(`(max-width: ${designTokens.breakpoints.mobile})`);
};

export const useIsTablet = (): boolean => {
  return useMediaQuery(
    `(min-width: ${designTokens.breakpoints.mobile}) and (max-width: ${designTokens.breakpoints.tablet})`
  );
};

export const useIsDesktop = (): boolean => {
  return useMediaQuery(`(min-width: ${designTokens.breakpoints.tablet})`);
};

export const useIsWide = (): boolean => {
  return useMediaQuery(`(min-width: ${designTokens.breakpoints.desktop})`);
};

/**
 * Hook for responsive values based on current breakpoint
 */
export const useResponsiveValue = <T>(values: {
  mobile?: T;
  tablet?: T;
  desktop?: T;
  wide?: T;
  default: T;
}): T => {
  const { current } = useBreakpoint();

  switch (current) {
    case 'mobile':
      return values.mobile ?? values.default;
    case 'tablet':
      return values.tablet ?? values.mobile ?? values.default;
    case 'desktop':
      return values.desktop ?? values.tablet ?? values.mobile ?? values.default;
    case 'wide':
      return values.wide ?? values.desktop ?? values.tablet ?? values.mobile ?? values.default;
    default:
      return values.default;
  }
};