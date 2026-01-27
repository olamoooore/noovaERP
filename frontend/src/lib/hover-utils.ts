import { designTokens } from './design-tokens'

// Hover State Configuration
export interface HoverConfig {
  variant: 'subtle' | 'elevated' | 'scale' | 'glow' | 'none'
  duration?: 'fast' | 'normal' | 'slow'
  easing?: 'easeIn' | 'easeOut' | 'easeInOut' | 'spring'
  delay?: number
  disabled?: boolean
}

// Default hover configurations for different component types
export const hoverPresets = {
  button: {
    variant: 'elevated' as const,
    duration: 'fast' as const,
    easing: 'easeOut' as const
  },
  card: {
    variant: 'subtle' as const,
    duration: 'normal' as const,
    easing: 'easeOut' as const
  },
  interactive: {
    variant: 'scale' as const,
    duration: 'fast' as const,
    easing: 'spring' as const
  },
  navigation: {
    variant: 'glow' as const,
    duration: 'fast' as const,
    easing: 'easeOut' as const
  }
}

// Generate CSS classes for hover effects
export const getHoverClasses = (config: HoverConfig): string => {
  if (config.disabled || config.variant === 'none') {
    return ''
  }

  const duration = designTokens.animations.duration[config.duration || 'normal']
  const easing = designTokens.animations.easing[config.easing || 'easeOut']
  
  const baseClasses = `transition-all duration-${config.duration || 'normal'} ease-out`
  
  switch (config.variant) {
    case 'subtle':
      return `${baseClasses} hover:bg-gray-50 hover:shadow-sm active:bg-gray-100`
    
    case 'elevated':
      return `${baseClasses} hover:shadow-md hover:-translate-y-0.5 active:shadow-lg active:translate-y-0`
    
    case 'scale':
      return `${baseClasses} hover:scale-105 active:scale-95`
    
    case 'glow':
      return `${baseClasses} hover:shadow-lg hover:shadow-blue-500/25 active:shadow-md`
    
    default:
      return baseClasses
  }
}

// Generate inline styles for hover effects
export const getHoverStyles = (config: HoverConfig): React.CSSProperties => {
  if (config.disabled || config.variant === 'none') {
    return {}
  }

  return {
    transition: `all ${designTokens.animations.duration[config.duration || 'normal']} ${designTokens.animations.easing[config.easing || 'easeOut']}`,
    transitionDelay: config.delay ? `${config.delay}ms` : undefined
  }
}

// Hook for managing hover states with configuration
export const useHoverState = (config: HoverConfig = { variant: 'subtle' }) => {
  const [isHovered, setIsHovered] = React.useState(false)
  const [isFocused, setIsFocused] = React.useState(false)
  const [isPressed, setIsPressed] = React.useState(false)
  
  const hoverTimeoutRef = React.useRef<NodeJS.Timeout>()

  const handleMouseEnter = React.useCallback(() => {
    if (config.disabled) return
    
    if (config.delay && config.delay > 0) {
      hoverTimeoutRef.current = setTimeout(() => {
        setIsHovered(true)
      }, config.delay)
    } else {
      setIsHovered(true)
    }
  }, [config.disabled, config.delay])

  const handleMouseLeave = React.useCallback(() => {
    if (hoverTimeoutRef.current) {
      clearTimeout(hoverTimeoutRef.current)
    }
    setIsHovered(false)
  }, [])

  const handleFocus = React.useCallback(() => {
    if (config.disabled) return
    setIsFocused(true)
  }, [config.disabled])

  const handleBlur = React.useCallback(() => {
    setIsFocused(false)
  }, [])

  const handleMouseDown = React.useCallback(() => {
    if (config.disabled) return
    setIsPressed(true)
  }, [config.disabled])

  const handleMouseUp = React.useCallback(() => {
    setIsPressed(false)
  }, [])

  // Cleanup timeout on unmount
  React.useEffect(() => {
    return () => {
      if (hoverTimeoutRef.current) {
        clearTimeout(hoverTimeoutRef.current)
      }
    }
  }, [])

  const handlers = React.useMemo(() => ({
    onMouseEnter: handleMouseEnter,
    onMouseLeave: handleMouseLeave,
    onFocus: handleFocus,
    onBlur: handleBlur,
    onMouseDown: handleMouseDown,
    onMouseUp: handleMouseUp
  }), [handleMouseEnter, handleMouseLeave, handleFocus, handleBlur, handleMouseDown, handleMouseUp])

  return {
    state: { isHovered, isFocused, isPressed },
    handlers,
    classes: getHoverClasses(config),
    styles: getHoverStyles(config)
  }
}

// Loading state utilities
export interface LoadingConfig {
  type: 'spinner' | 'pulse' | 'dots' | 'skeleton'
  size?: 'sm' | 'md' | 'lg'
  text?: string
  overlay?: boolean
}

export const getLoadingClasses = (config: LoadingConfig): string => {
  const baseClasses = 'relative'
  
  if (config.overlay) {
    return `${baseClasses} pointer-events-none opacity-75`
  }
  
  return baseClasses
}

// Focus ring utilities
export interface FocusConfig {
  color?: 'blue' | 'gray' | 'red' | 'green' | 'yellow' | 'purple'
  size?: 'sm' | 'md' | 'lg'
  offset?: boolean
}

export const getFocusClasses = (config: FocusConfig = {}): string => {
  const color = config.color || 'blue'
  const size = config.size || 'md'
  const offset = config.offset !== false
  
  const sizeClasses = {
    sm: 'ring-1',
    md: 'ring-2', 
    lg: 'ring-4'
  }
  
  const colorClasses = {
    blue: 'ring-blue-500',
    gray: 'ring-gray-500',
    red: 'ring-red-500',
    green: 'ring-green-500',
    yellow: 'ring-yellow-500',
    purple: 'ring-purple-500'
  }
  
  return `focus-visible:outline-none focus-visible:${sizeClasses[size]} focus-visible:${colorClasses[color]} ${offset ? 'focus-visible:ring-offset-2' : ''}`
}

// Accessibility utilities
export const getAccessibilityProps = (config: {
  role?: string
  disabled?: boolean
  loading?: boolean
  describedBy?: string
}) => {
  return {
    role: config.role || 'button',
    'aria-disabled': config.disabled || config.loading,
    'aria-busy': config.loading,
    'aria-describedby': config.describedBy,
    tabIndex: config.disabled || config.loading ? -1 : 0
  }
}

// Performance monitoring for animations
export const useAnimationPerformance = () => {
  const frameRateRef = React.useRef<number[]>([])
  const lastFrameTimeRef = React.useRef<number>(0)
  
  const measureFrameRate = React.useCallback(() => {
    const now = performance.now()
    if (lastFrameTimeRef.current) {
      const delta = now - lastFrameTimeRef.current
      const fps = 1000 / delta
      frameRateRef.current.push(fps)
      
      // Keep only last 60 measurements
      if (frameRateRef.current.length > 60) {
        frameRateRef.current.shift()
      }
    }
    lastFrameTimeRef.current = now
  }, [])
  
  const getAverageFrameRate = React.useCallback(() => {
    if (frameRateRef.current.length === 0) return 0
    const sum = frameRateRef.current.reduce((a, b) => a + b, 0)
    return sum / frameRateRef.current.length
  }, [])
  
  const isPerformant = React.useCallback(() => {
    return getAverageFrameRate() >= 30 // Consider 30fps as minimum acceptable
  }, [getAverageFrameRate])
  
  return {
    measureFrameRate,
    getAverageFrameRate,
    isPerformant
  }
}

// Import React for hooks
import React from 'react'