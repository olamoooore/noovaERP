import { designTokens } from './design-tokens'
import { prefersReducedMotion, getMotionSafeDuration, getMotionSafeEasing } from './motion-sensitivity'

// Animation Configuration Types
export interface AnimationConfig {
  duration?: 'fast' | 'normal' | 'slow' | number
  easing?: 'easeIn' | 'easeOut' | 'easeInOut' | 'spring' | string
  delay?: number
  iterations?: number | 'infinite'
  direction?: 'normal' | 'reverse' | 'alternate' | 'alternate-reverse'
  fillMode?: 'none' | 'forwards' | 'backwards' | 'both'
  playState?: 'running' | 'paused'
}

export interface MicroAnimationOptions extends AnimationConfig {
  respectReducedMotion?: boolean
  performanceMonitoring?: boolean
  onComplete?: () => void
  onStart?: () => void
}

// Predefined Animation Presets
export const animationPresets = {
  // Entrance animations
  fadeIn: {
    keyframes: [
      { opacity: 0, transform: 'translateY(10px)' },
      { opacity: 1, transform: 'translateY(0)' }
    ],
    duration: 'normal' as const,
    easing: 'easeOut' as const
  },
  
  slideIn: {
    keyframes: [
      { transform: 'translateX(-100%)', opacity: 0 },
      { transform: 'translateX(0)', opacity: 1 }
    ],
    duration: 'normal' as const,
    easing: 'spring' as const
  },
  
  scaleIn: {
    keyframes: [
      { transform: 'scale(0.8)', opacity: 0 },
      { transform: 'scale(1)', opacity: 1 }
    ],
    duration: 'fast' as const,
    easing: 'spring' as const
  },
  
  // Exit animations
  fadeOut: {
    keyframes: [
      { opacity: 1, transform: 'translateY(0)' },
      { opacity: 0, transform: 'translateY(-10px)' }
    ],
    duration: 'fast' as const,
    easing: 'easeIn' as const
  },
  
  slideOut: {
    keyframes: [
      { transform: 'translateX(0)', opacity: 1 },
      { transform: 'translateX(100%)', opacity: 0 }
    ],
    duration: 'fast' as const,
    easing: 'easeIn' as const
  },
  
  scaleOut: {
    keyframes: [
      { transform: 'scale(1)', opacity: 1 },
      { transform: 'scale(0.8)', opacity: 0 }
    ],
    duration: 'fast' as const,
    easing: 'easeIn' as const
  },
  
  // Interaction animations
  bounce: {
    keyframes: [
      { transform: 'scale(1)' },
      { transform: 'scale(1.05)' },
      { transform: 'scale(0.95)' },
      { transform: 'scale(1)' }
    ],
    duration: 'fast' as const,
    easing: 'easeInOut' as const
  },
  
  pulse: {
    keyframes: [
      { transform: 'scale(1)', opacity: 1 },
      { transform: 'scale(1.05)', opacity: 0.8 },
      { transform: 'scale(1)', opacity: 1 }
    ],
    duration: 'normal' as const,
    easing: 'easeInOut' as const,
    iterations: 'infinite' as const
  },
  
  shake: {
    keyframes: [
      { transform: 'translateX(0)' },
      { transform: 'translateX(-5px)' },
      { transform: 'translateX(5px)' },
      { transform: 'translateX(-5px)' },
      { transform: 'translateX(0)' }
    ],
    duration: 'fast' as const,
    easing: 'easeInOut' as const
  },
  
  // State change animations
  highlight: {
    keyframes: [
      { backgroundColor: 'transparent' },
      { backgroundColor: 'rgba(59, 130, 246, 0.1)' },
      { backgroundColor: 'transparent' }
    ],
    duration: 'normal' as const,
    easing: 'easeInOut' as const
  },
  
  glow: {
    keyframes: [
      { boxShadow: '0 0 0 rgba(59, 130, 246, 0)' },
      { boxShadow: '0 0 20px rgba(59, 130, 246, 0.3)' },
      { boxShadow: '0 0 0 rgba(59, 130, 246, 0)' }
    ],
    duration: 'slow' as const,
    easing: 'easeInOut' as const
  },
  
  // Loading animations
  spin: {
    keyframes: [
      { transform: 'rotate(0deg)' },
      { transform: 'rotate(360deg)' }
    ],
    duration: 'slow' as const,
    easing: 'linear' as const,
    iterations: 'infinite' as const
  },
  
  breathe: {
    keyframes: [
      { opacity: 0.4 },
      { opacity: 1 },
      { opacity: 0.4 }
    ],
    duration: 'slow' as const,
    easing: 'easeInOut' as const,
    iterations: 'infinite' as const
  }
}

// Performance Monitoring
class AnimationPerformanceMonitor {
  private frameRates: number[] = []
  private animationCount = 0
  private isMonitoring = false
  
  startMonitoring() {
    this.isMonitoring = true
    this.frameRates = []
    this.animationCount = 0
  }
  
  stopMonitoring() {
    this.isMonitoring = false
    this.frameRates = []
    this.animationCount = 0
  }
  
  recordFrame(frameRate: number) {
    if (!this.isMonitoring || isNaN(frameRate) || !isFinite(frameRate)) return
    
    this.frameRates.push(frameRate)
    if (this.frameRates.length > 60) {
      this.frameRates.shift()
    }
  }
  
  incrementAnimationCount() {
    if (this.isMonitoring) {
      this.animationCount++
    }
  }
  
  getAverageFrameRate(): number {
    if (this.frameRates.length === 0) return 60 // Default to good performance when no data
    return this.frameRates.reduce((sum, rate) => sum + rate, 0) / this.frameRates.length
  }
  
  getAnimationCount(): number {
    return this.animationCount
  }
  
  isPerformant(): boolean {
    const avgFps = this.getAverageFrameRate()
    const animCount = this.getAnimationCount()
    return avgFps >= 30 && animCount < 10
  }
}

export const performanceMonitor = new AnimationPerformanceMonitor()

// Reduced Motion Detection (enhanced)
export const respectsReducedMotion = (): boolean => {
  return prefersReducedMotion()
}

// Animation Duration Conversion (enhanced with motion sensitivity)
const getDurationMs = (duration: AnimationConfig['duration'], respectMotion: boolean = true): number => {
  let baseDuration: number
  
  if (typeof duration === 'number') {
    baseDuration = duration
  } else {
    switch (duration) {
      case 'fast':
        baseDuration = parseFloat(designTokens.animations.duration.fast.replace('ms', ''))
        break
      case 'slow':
        baseDuration = parseFloat(designTokens.animations.duration.slow.replace('ms', ''))
        break
      case 'normal':
      default:
        baseDuration = parseFloat(designTokens.animations.duration.normal.replace('ms', ''))
        break
    }
  }
  
  return respectMotion ? getMotionSafeDuration(baseDuration) : baseDuration
}

// Animation Easing Conversion (enhanced with motion sensitivity)
const getEasing = (easing: AnimationConfig['easing'], respectMotion: boolean = true): string => {
  let baseEasing: string
  
  if (!easing) {
    baseEasing = designTokens.animations.easing.easeOut
  } else {
    switch (easing) {
      case 'easeIn':
        baseEasing = designTokens.animations.easing.easeIn
        break
      case 'easeOut':
        baseEasing = designTokens.animations.easing.easeOut
        break
      case 'easeInOut':
        baseEasing = designTokens.animations.easing.easeInOut
        break
      case 'spring':
        baseEasing = designTokens.animations.easing.spring
        break
      default:
        baseEasing = easing
        break
    }
  }
  
  return respectMotion ? getMotionSafeEasing(baseEasing) : baseEasing
}

// Main Animation Function
export const animate = (
  element: HTMLElement,
  animationName: keyof typeof animationPresets | Keyframe[],
  options: MicroAnimationOptions = {}
): Animation | null => {
  // Check for reduced motion preference
  if (options.respectReducedMotion !== false && respectsReducedMotion()) {
    // Skip animation but still call callbacks
    options.onStart?.()
    setTimeout(() => options.onComplete?.(), 0)
    return null
  }
  
  // Performance monitoring
  if (options.performanceMonitoring) {
    performanceMonitor.incrementAnimationCount()
    
    if (!performanceMonitor.isPerformant()) {
      console.warn('Animation performance degraded, skipping animation')
      options.onComplete?.()
      return null
    }
  }
  
  // Get animation definition
  let keyframes: Keyframe[]
  let animationOptions: AnimationConfig
  
  if (Array.isArray(animationName)) {
    keyframes = animationName
    animationOptions = options
  } else {
    const preset = animationPresets[animationName]
    keyframes = preset.keyframes
    animationOptions = { ...preset, ...options }
  }
  
  // Convert options to Web Animations API format
  const webAnimationOptions: KeyframeAnimationOptions = {
    duration: getDurationMs(animationOptions.duration, options.respectReducedMotion),
    easing: getEasing(animationOptions.easing, options.respectReducedMotion),
    delay: animationOptions.delay ? getMotionSafeDuration(animationOptions.delay, options.respectReducedMotion !== false) : 0,
    iterations: animationOptions.iterations || 1,
    direction: animationOptions.direction || 'normal',
    fill: animationOptions.fillMode || 'both'
  }
  
  // Create and start animation
  const animation = element.animate(keyframes, webAnimationOptions)
  
  // Set play state
  if (animationOptions.playState === 'paused') {
    animation.pause()
  }
  
  // Add event listeners
  animation.addEventListener('start', () => {
    options.onStart?.()
  })
  
  animation.addEventListener('finish', () => {
    options.onComplete?.()
  })
  
  return animation
}

// Utility Functions
export const fadeIn = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'fadeIn', options)

export const fadeOut = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'fadeOut', options)

export const slideIn = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'slideIn', options)

export const slideOut = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'slideOut', options)

export const scaleIn = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'scaleIn', options)

export const scaleOut = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'scaleOut', options)

export const bounce = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'bounce', options)

export const pulse = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'pulse', options)

export const shake = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'shake', options)

export const highlight = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'highlight', options)

export const glow = (element: HTMLElement, options?: MicroAnimationOptions) => 
  animate(element, 'glow', options)

// Animation Sequences
export const animateSequence = async (
  animations: Array<{
    element: HTMLElement
    animation: keyof typeof animationPresets | Keyframe[]
    options?: MicroAnimationOptions
  }>
): Promise<void> => {
  for (const { element, animation, options } of animations) {
    await new Promise<void>((resolve) => {
      animate(element, animation, {
        ...options,
        onComplete: () => {
          options?.onComplete?.()
          resolve()
        }
      })
    })
  }
}

export const animateParallel = (
  animations: Array<{
    element: HTMLElement
    animation: keyof typeof animationPresets | Keyframe[]
    options?: MicroAnimationOptions
  }>
): Promise<void[]> => {
  const promises = animations.map(({ element, animation, options }) => 
    new Promise<void>((resolve) => {
      animate(element, animation, {
        ...options,
        onComplete: () => {
          options?.onComplete?.()
          resolve()
        }
      })
    })
  )
  
  return Promise.all(promises)
}

// React Hook for Animations
export const useAnimation = () => {
  const [isAnimating, setIsAnimating] = React.useState(false)
  const [currentAnimation, setCurrentAnimation] = React.useState<Animation | null>(null)
  
  const startAnimation = React.useCallback((
    element: HTMLElement,
    animationName: keyof typeof animationPresets | Keyframe[],
    options?: MicroAnimationOptions
  ) => {
    if (currentAnimation) {
      currentAnimation.cancel()
    }
    
    setIsAnimating(true)
    
    const animation = animate(element, animationName, {
      ...options,
      onStart: () => {
        options?.onStart?.()
      },
      onComplete: () => {
        setIsAnimating(false)
        setCurrentAnimation(null)
        options?.onComplete?.()
      }
    })
    
    setCurrentAnimation(animation)
    return animation
  }, [currentAnimation])
  
  const stopAnimation = React.useCallback(() => {
    if (currentAnimation) {
      currentAnimation.cancel()
      setCurrentAnimation(null)
      setIsAnimating(false)
    }
  }, [currentAnimation])
  
  return {
    isAnimating,
    startAnimation,
    stopAnimation,
    currentAnimation
  }
}

// CSS Class-based Animations (for simpler cases)
export const getCSSAnimationClasses = (
  animationName: keyof typeof animationPresets,
  options: MicroAnimationOptions = {}
): string => {
  const duration = options.duration || 'normal'
  const easing = options.easing || 'easeOut'
  
  const durationClass = `duration-${duration === 'fast' ? '150' : duration === 'slow' ? '500' : '300'}`
  const easingClass = `ease-${easing === 'easeIn' ? 'in' : easing === 'easeOut' ? 'out' : 'in-out'}`
  
  let animationClass = ''
  
  switch (animationName) {
    case 'fadeIn':
      animationClass = 'animate-fade-in'
      break
    case 'fadeOut':
      animationClass = 'animate-fade-out'
      break
    case 'bounce':
      animationClass = 'animate-bounce'
      break
    case 'pulse':
      animationClass = 'animate-pulse'
      break
    case 'spin':
      animationClass = 'animate-spin'
      break
    default:
      animationClass = `animate-${animationName}`
  }
  
  return `${animationClass} ${durationClass} ${easingClass} transition-all`
}

// Import React for hooks
import React from 'react'