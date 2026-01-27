/**
 * Motion Sensitivity Support
 * Utilities for respecting user motion preferences and providing animation controls
 */

import React from 'react'
import { designTokens } from './design-tokens'

/**
 * Motion preference detection
 */
export const getMotionPreference = (): 'reduce' | 'no-preference' => {
  if (typeof window === 'undefined') return 'no-preference'
  
  const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
  return mediaQuery.matches ? 'reduce' : 'no-preference'
}

/**
 * Check if user prefers reduced motion
 */
export const prefersReducedMotion = (): boolean => {
  return getMotionPreference() === 'reduce'
}

/**
 * Motion preference change listener
 */
export const onMotionPreferenceChange = (callback: (prefersReduced: boolean) => void): (() => void) => {
  if (typeof window === 'undefined') return () => {}
  
  const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
  
  const handleChange = (event: MediaQueryListEvent) => {
    callback(event.matches)
  }
  
  mediaQuery.addEventListener('change', handleChange)
  
  // Return cleanup function
  return () => {
    mediaQuery.removeEventListener('change', handleChange)
  }
}

/**
 * Animation configuration based on motion preferences
 */
export interface MotionSafeAnimationConfig {
  duration: number
  easing: string
  delay?: number
  respectMotionPreference?: boolean
}

/**
 * Get animation duration respecting motion preferences
 */
export const getMotionSafeDuration = (
  baseDuration: number,
  respectPreference: boolean = true
): number => {
  if (!respectPreference) return baseDuration
  
  return prefersReducedMotion() ? 0 : baseDuration
}

/**
 * Get animation easing respecting motion preferences
 */
export const getMotionSafeEasing = (
  baseEasing: string,
  respectPreference: boolean = true
): string => {
  if (!respectPreference) return baseEasing
  
  return prefersReducedMotion() ? 'linear' : baseEasing
}

/**
 * Create motion-safe animation configuration
 */
export const createMotionSafeConfig = (
  config: MotionSafeAnimationConfig
): MotionSafeAnimationConfig => {
  const respectPreference = config.respectMotionPreference !== false
  
  return {
    duration: getMotionSafeDuration(config.duration, respectPreference),
    easing: getMotionSafeEasing(config.easing, respectPreference),
    delay: config.delay ? getMotionSafeDuration(config.delay, respectPreference) : undefined,
    respectMotionPreference: respectPreference,
  }
}

/**
 * CSS classes for motion-safe animations
 */
export const getMotionSafeClasses = (animationClasses: string): string => {
  const baseClasses = animationClasses
  const motionReduceClasses = [
    'motion-reduce:transition-none',
    'motion-reduce:transform-none',
    'motion-reduce:animate-none',
    'motion-reduce:duration-0',
  ].join(' ')
  
  return `${baseClasses} ${motionReduceClasses}`
}

/**
 * Animation presets with motion sensitivity support
 */
export const motionSafeAnimations = {
  // Entrance animations
  fadeIn: {
    base: 'transition-opacity duration-300 ease-out',
    motionSafe: 'motion-reduce:transition-none motion-reduce:duration-0',
    keyframes: {
      from: { opacity: 0 },
      to: { opacity: 1 }
    }
  },
  
  slideInUp: {
    base: 'transition-all duration-300 ease-out',
    motionSafe: 'motion-reduce:transition-none motion-reduce:transform-none motion-reduce:duration-0',
    keyframes: {
      from: { opacity: 0, transform: 'translateY(20px)' },
      to: { opacity: 1, transform: 'translateY(0)' }
    }
  },
  
  slideInDown: {
    base: 'transition-all duration-300 ease-out',
    motionSafe: 'motion-reduce:transition-none motion-reduce:transform-none motion-reduce:duration-0',
    keyframes: {
      from: { opacity: 0, transform: 'translateY(-20px)' },
      to: { opacity: 1, transform: 'translateY(0)' }
    }
  },
  
  slideInLeft: {
    base: 'transition-all duration-300 ease-out',
    motionSafe: 'motion-reduce:transition-none motion-reduce:transform-none motion-reduce:duration-0',
    keyframes: {
      from: { opacity: 0, transform: 'translateX(-20px)' },
      to: { opacity: 1, transform: 'translateX(0)' }
    }
  },
  
  slideInRight: {
    base: 'transition-all duration-300 ease-out',
    motionSafe: 'motion-reduce:transition-none motion-reduce:transform-none motion-reduce:duration-0',
    keyframes: {
      from: { opacity: 0, transform: 'translateX(20px)' },
      to: { opacity: 1, transform: 'translateX(0)' }
    }
  },
  
  scaleIn: {
    base: 'transition-all duration-200 ease-out',
    motionSafe: 'motion-reduce:transition-none motion-reduce:transform-none motion-reduce:duration-0',
    keyframes: {
      from: { opacity: 0, transform: 'scale(0.95)' },
      to: { opacity: 1, transform: 'scale(1)' }
    }
  },
  
  // Interaction animations
  hover: {
    base: 'transition-all duration-150 ease-in-out hover:scale-105',
    motionSafe: 'motion-reduce:hover:scale-100 motion-reduce:transition-none motion-reduce:duration-0'
  },
  
  press: {
    base: 'transition-all duration-100 ease-in-out active:scale-95',
    motionSafe: 'motion-reduce:active:scale-100 motion-reduce:transition-none motion-reduce:duration-0'
  },
  
  focus: {
    base: 'transition-all duration-150 ease-in-out focus:ring-2 focus:ring-offset-2',
    motionSafe: 'motion-reduce:transition-none motion-reduce:duration-0'
  },
  
  // Loading animations
  spin: {
    base: 'animate-spin',
    motionSafe: 'motion-reduce:animate-none motion-reduce:after:content-["⏳"]'
  },
  
  pulse: {
    base: 'animate-pulse',
    motionSafe: 'motion-reduce:animate-none motion-reduce:opacity-70'
  },
  
  bounce: {
    base: 'animate-bounce',
    motionSafe: 'motion-reduce:animate-none'
  },
  
  // Status change animations
  highlight: {
    base: 'transition-colors duration-500 ease-in-out',
    motionSafe: 'motion-reduce:transition-none motion-reduce:duration-0'
  },
  
  // Exit animations
  fadeOut: {
    base: 'transition-opacity duration-200 ease-in',
    motionSafe: 'motion-reduce:transition-none motion-reduce:duration-0',
    keyframes: {
      from: { opacity: 1 },
      to: { opacity: 0 }
    }
  }
}

/**
 * Get combined animation classes with motion sensitivity
 */
export const getMotionSafeAnimation = (
  animationName: keyof typeof motionSafeAnimations,
  additionalClasses: string = ''
): string => {
  const animation = motionSafeAnimations[animationName]
  return `${animation.base} ${animation.motionSafe} ${additionalClasses}`.trim()
}

/**
 * React hook for motion preferences
 */
export const useMotionPreference = () => {
  const [prefersReduced, setPrefersReduced] = React.useState(() => prefersReducedMotion())
  
  React.useEffect(() => {
    const cleanup = onMotionPreferenceChange(setPrefersReduced)
    return cleanup
  }, [])
  
  return {
    prefersReducedMotion: prefersReduced,
    motionPreference: prefersReduced ? 'reduce' : 'no-preference'
  }
}

/**
 * Animation control context for manual override
 */
export interface AnimationControlContextValue {
  animationsEnabled: boolean
  setAnimationsEnabled: (enabled: boolean) => void
  respectSystemPreference: boolean
  setRespectSystemPreference: (respect: boolean) => void
}

export const AnimationControlContext = React.createContext<AnimationControlContextValue | null>(null)

/**
 * Animation control provider
 */
export const AnimationControlProvider: React.FC<{
  children: React.ReactNode
  defaultEnabled?: boolean
  defaultRespectSystem?: boolean
}> = ({ 
  children, 
  defaultEnabled = true, 
  defaultRespectSystem = true 
}) => {
  const [animationsEnabled, setAnimationsEnabled] = React.useState(defaultEnabled)
  const [respectSystemPreference, setRespectSystemPreference] = React.useState(defaultRespectSystem)
  const { prefersReducedMotion: systemPrefersReduced } = useMotionPreference()
  
  // Determine effective animation state
  const effectiveAnimationsEnabled = respectSystemPreference 
    ? animationsEnabled && !systemPrefersReduced
    : animationsEnabled
  
  const contextValue: AnimationControlContextValue = {
    animationsEnabled: effectiveAnimationsEnabled,
    setAnimationsEnabled,
    respectSystemPreference,
    setRespectSystemPreference,
  }
  
  return (
    <AnimationControlContext.Provider value={contextValue}>
      {children}
    </AnimationControlContext.Provider>
  )
}

/**
 * Hook to use animation controls
 */
export const useAnimationControls = () => {
  const context = React.useContext(AnimationControlContext)
  
  if (!context) {
    // Fallback to system preference if no provider
    const { prefersReducedMotion } = useMotionPreference()
    return {
      animationsEnabled: !prefersReducedMotion,
      setAnimationsEnabled: () => {},
      respectSystemPreference: true,
      setRespectSystemPreference: () => {},
    }
  }
  
  return context
}

/**
 * Conditional animation wrapper
 */
export const ConditionalAnimation: React.FC<{
  children: React.ReactNode
  animation: keyof typeof motionSafeAnimations
  className?: string
  fallbackClassName?: string
  respectPreference?: boolean
}> = ({ 
  children, 
  animation, 
  className = '', 
  fallbackClassName = '',
  respectPreference = true 
}) => {
  const { animationsEnabled } = useAnimationControls()
  const shouldAnimate = respectPreference ? animationsEnabled : true
  
  const animationClasses = shouldAnimate 
    ? getMotionSafeAnimation(animation, className)
    : fallbackClassName
  
  return (
    <div className={animationClasses}>
      {children}
    </div>
  )
}

/**
 * Motion-safe CSS custom properties
 */
export const getMotionSafeCSSProperties = () => {
  const prefersReduced = prefersReducedMotion()
  
  return {
    '--animation-duration-fast': prefersReduced ? '0ms' : designTokens.animations.duration.fast,
    '--animation-duration-normal': prefersReduced ? '0ms' : designTokens.animations.duration.normal,
    '--animation-duration-slow': prefersReduced ? '0ms' : designTokens.animations.duration.slow,
    '--animation-easing': prefersReduced ? 'linear' : designTokens.animations.easing.easeInOut,
    '--transform-scale-hover': prefersReduced ? '1' : '1.05',
    '--transform-scale-active': prefersReduced ? '1' : '0.95',
  } as React.CSSProperties
}

/**
 * Apply motion-safe styles to document root
 */
export const applyMotionSafeStyles = () => {
  if (typeof document === 'undefined') return
  
  const root = document.documentElement
  const properties = getMotionSafeCSSProperties()
  
  Object.entries(properties).forEach(([property, value]) => {
    root.style.setProperty(property, value as string)
  })
}

/**
 * Animation settings component for user preferences
 */
export const AnimationSettings: React.FC<{
  className?: string
}> = ({ className }) => {
  const {
    animationsEnabled,
    setAnimationsEnabled,
    respectSystemPreference,
    setRespectSystemPreference,
  } = useAnimationControls()
  
  const { prefersReducedMotion: systemPrefersReduced } = useMotionPreference()
  
  return (
    <div className={`space-y-4 ${className}`}>
      <div className="space-y-2">
        <h3 className="text-lg font-medium">Animation Preferences</h3>
        <p className="text-sm text-muted-foreground">
          Control how animations and transitions behave in the interface.
        </p>
      </div>
      
      <div className="space-y-3">
        <label className="flex items-center space-x-2">
          <input
            type="checkbox"
            checked={respectSystemPreference}
            onChange={(e) => setRespectSystemPreference(e.target.checked)}
            className="rounded border-gray-300 focus:ring-2 focus:ring-blue-500"
          />
          <span className="text-sm">Respect system motion preferences</span>
        </label>
        
        {systemPrefersReduced && respectSystemPreference && (
          <p className="text-xs text-amber-600 bg-amber-50 p-2 rounded">
            Your system is set to reduce motion. Animations will be minimized.
          </p>
        )}
        
        <label className="flex items-center space-x-2">
          <input
            type="checkbox"
            checked={animationsEnabled}
            onChange={(e) => setAnimationsEnabled(e.target.checked)}
            disabled={respectSystemPreference && systemPrefersReduced}
            className="rounded border-gray-300 focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
          />
          <span className="text-sm">Enable animations and transitions</span>
        </label>
        
        <div className="text-xs text-muted-foreground space-y-1">
          <p>• Animations help provide visual feedback and guide attention</p>
          <p>• Disabling animations may improve performance on slower devices</p>
          <p>• System preferences override manual settings when enabled</p>
        </div>
      </div>
    </div>
  )
}

// Initialize motion-safe styles on module load
if (typeof window !== 'undefined') {
  // Apply styles when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyMotionSafeStyles)
  } else {
    applyMotionSafeStyles()
  }
  
  // Update styles when motion preference changes
  onMotionPreferenceChange(applyMotionSafeStyles)
}