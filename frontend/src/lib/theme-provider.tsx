/**
 * Theme Provider
 * Provides design system context and theme management
 */

import React, { createContext, useContext, useEffect, useState } from 'react';
import { designTokens, type DesignTokens } from './design-tokens';

// Theme types
export type Theme = 'light' | 'dark';

// UI State interface
export interface UIState {
  theme: Theme;
  reducedMotion: boolean;
  currentBreakpoint: 'mobile' | 'tablet' | 'desktop';
  loading: Record<string, boolean>;
  animations: Record<string, AnimationState>;
}

export interface AnimationState {
  isPlaying: boolean;
  progress: number;
  duration: number;
}

// Theme context interface
interface ThemeContextType {
  theme: Theme;
  setTheme: (theme: Theme) => void;
  tokens: DesignTokens;
  uiState: UIState;
  updateUIState: (updates: Partial<UIState>) => void;
  setLoading: (componentId: string, loading: boolean) => void;
  setAnimation: (animationId: string, state: AnimationState) => void;
}

// Create theme context
const ThemeContext = createContext<ThemeContextType | undefined>(undefined);

// Theme provider props
interface ThemeProviderProps {
  children: React.ReactNode;
  defaultTheme?: Theme;
  storageKey?: string;
}

// Theme provider component
export function ThemeProvider({
  children,
  defaultTheme = 'light',
  storageKey = 'ui-theme',
}: ThemeProviderProps) {
  const [theme, setThemeState] = useState<Theme>(() => {
    if (typeof window !== 'undefined') {
      return (localStorage.getItem(storageKey) as Theme) || defaultTheme;
    }
    return defaultTheme;
  });

  const [uiState, setUIState] = useState<UIState>({
    theme,
    reducedMotion: false,
    currentBreakpoint: 'desktop',
    loading: {},
    animations: {},
  });

  // Update theme
  const setTheme = (newTheme: Theme) => {
    localStorage.setItem(storageKey, newTheme);
    setThemeState(newTheme);
    setUIState(prev => ({ ...prev, theme: newTheme }));
  };

  // Update UI state
  const updateUIState = (updates: Partial<UIState>) => {
    setUIState(prev => ({ ...prev, ...updates }));
  };

  // Set loading state for specific component
  const setLoading = (componentId: string, loading: boolean) => {
    setUIState(prev => ({
      ...prev,
      loading: {
        ...prev.loading,
        [componentId]: loading,
      },
    }));
  };

  // Set animation state
  const setAnimation = (animationId: string, state: AnimationState) => {
    setUIState(prev => ({
      ...prev,
      animations: {
        ...prev.animations,
        [animationId]: state,
      },
    }));
  };

  // Handle system theme changes
  useEffect(() => {
    const root = window.document.documentElement;
    root.classList.remove('light', 'dark');
    root.classList.add(theme);
  }, [theme]);

  // Handle reduced motion preference
  useEffect(() => {
    const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    const handleChange = (e: MediaQueryListEvent) => {
      updateUIState({ reducedMotion: e.matches });
    };

    updateUIState({ reducedMotion: mediaQuery.matches });
    mediaQuery.addEventListener('change', handleChange);

    return () => mediaQuery.removeEventListener('change', handleChange);
  }, []);

  // Handle responsive breakpoints
  useEffect(() => {
    const handleResize = () => {
      const width = window.innerWidth;
      let breakpoint: 'mobile' | 'tablet' | 'desktop' = 'desktop';

      if (width < 768) {
        breakpoint = 'mobile';
      } else if (width < 1024) {
        breakpoint = 'tablet';
      }

      updateUIState({ currentBreakpoint: breakpoint });
    };

    handleResize(); // Set initial breakpoint
    window.addEventListener('resize', handleResize);

    return () => window.removeEventListener('resize', handleResize);
  }, []);

  const value: ThemeContextType = {
    theme,
    setTheme,
    tokens: designTokens,
    uiState,
    updateUIState,
    setLoading,
    setAnimation,
  };

  return (
    <ThemeContext.Provider value={value}>
      {children}
    </ThemeContext.Provider>
  );
}

// Hook to use theme context
export function useTheme() {
  const context = useContext(ThemeContext);

  if (context === undefined) {
    throw new Error('useTheme must be used within a ThemeProvider');
  }

  return context;
}

// Hook to use design tokens
export function useDesignTokens() {
  const { tokens } = useTheme();
  return tokens;
}

// Hook to use UI state
export function useUIState() {
  const { uiState, updateUIState, setLoading, setAnimation } = useTheme();
  return { uiState, updateUIState, setLoading, setAnimation };
}

// Utility hook for responsive design
export function useBreakpoint() {
  const { uiState } = useTheme();
  return {
    isMobile: uiState.currentBreakpoint === 'mobile',
    isTablet: uiState.currentBreakpoint === 'tablet',
    isDesktop: uiState.currentBreakpoint === 'desktop',
    breakpoint: uiState.currentBreakpoint,
  };
}

// Utility hook for animations
export function useAnimation(animationId: string) {
  const { uiState, setAnimation } = useTheme();
  const animationState = uiState.animations[animationId];

  const startAnimation = (duration: number = 300) => {
    setAnimation(animationId, {
      isPlaying: true,
      progress: 0,
      duration,
    });
  };

  const stopAnimation = () => {
    setAnimation(animationId, {
      isPlaying: false,
      progress: 1,
      duration: 0,
    });
  };

  const updateProgress = (progress: number) => {
    if (animationState) {
      setAnimation(animationId, {
        ...animationState,
        progress: Math.max(0, Math.min(1, progress)),
      });
    }
  };

  return {
    animationState,
    startAnimation,
    stopAnimation,
    updateProgress,
    isPlaying: animationState?.isPlaying || false,
    progress: animationState?.progress || 0,
  };
}