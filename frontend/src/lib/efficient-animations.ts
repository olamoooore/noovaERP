/**
 * Efficient Animation Utilities
 * GPU-accelerated animations with performance monitoring
 */

import { performanceMonitor, gpuOptimization } from './performance-optimization'
import { prefersReducedMotion } from './motion-sensitivity'

/**
 * Efficient animation configuration
 */
export interface EfficientAnimationConfig {
  duration: number
  easing?: string
  delay?: number
  useGPU?: boolean
  respectMotion?: boolean
  onStart?: () => void
  onComplete?: () => void
  onFrame?: (progress: number) => void
}

/**
 * GPU-accelerated animation class
 */
export class EfficientAnimation {
  private element: HTMLElement
  private config: EfficientAnimationConfig
  private animationId: number | null = null
  private startTime: number = 0
  private isRunning: boolean = false
  
  constructor(element: HTMLElement, config: EfficientAnimationConfig) {
    this.element = element
    this.config = {
      useGPU: true,
      respectMotion: true,
      easing: 'ease-out',
      ...config
    }
  }
  
  /**
   * Start the animation
   */
  start(): void {
    if (this.isRunning) return
    
    // Check motion preferences
    if (this.config.respectMotion && prefersReducedMotion()) {
      this.config.onComplete?.()
      return
    }
    
    // Optimize element for animation
    if (this.config.useGPU) {
      gpuOptimization.optimizeForAnimation(this.element, ['transform', 'opacity'])
    }
    
    // Start performance monitoring
    performanceMonitor.mark('animation-start')
    
    this.isRunning = true
    this.startTime = performance.now()
    
    if (this.config.delay && this.config.delay > 0) {
      setTimeout(() => {
        this.animationId = requestAnimationFrame(this.animate.bind(this))
      }, this.config.delay)
    } else {
      this.animationId = requestAnimationFrame(this.animate.bind(this))
    }
    
    this.config.onStart?.()
  }
  
  /**
   * Animation frame handler
   */
  private animate(currentTime: number): void {
    if (!this.isRunning) return
    
    const elapsed = currentTime - this.startTime
    const progress = Math.min(elapsed / this.config.duration, 1)
    
    // Apply easing
    const easedProgress = this.applyEasing(progress, this.config.easing || 'ease-out')
    
    // Call frame callback
    this.config.onFrame?.(easedProgress)
    
    if (progress < 1) {
      this.animationId = requestAnimationFrame(this.animate.bind(this))
    } else {
      this.complete()
    }
  }
  
  /**
   * Apply easing function
   */
  private applyEasing(t: number, easing: string): number {
    switch (easing) {
      case 'linear':
        return t
      case 'ease-in':
        return t * t
      case 'ease-out':
        return 1 - (1 - t) * (1 - t)
      case 'ease-in-out':
        return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2
      case 'spring':
        return 1 - Math.cos(t * Math.PI / 2)
      default:
        return 1 - (1 - t) * (1 - t) // Default to ease-out
    }
  }
  
  /**
   * Complete the animation
   */
  private complete(): void {
    this.isRunning = false
    
    // Cleanup GPU optimizations
    if (this.config.useGPU) {
      gpuOptimization.cleanupAnimationOptimizations(this.element)
    }
    
    // Record performance
    performanceMonitor.mark('animation-end')
    performanceMonitor.measure('animation-duration', 'animation-start', 'animation-end')
    
    this.config.onComplete?.()
  }
  
  /**
   * Stop the animation
   */
  stop(): void {
    if (this.animationId) {
      cancelAnimationFrame(this.animationId)
      this.animationId = null
    }
    this.isRunning = false
    
    // Cleanup GPU optimizations
    if (this.config.useGPU) {
      gpuOptimization.cleanupAnimationOptimizations(this.element)
    }
  }
}

/**
 * Predefined efficient animations
 */
export const efficientAnimations = {
  /**
   * Fade in animation
   */
  fadeIn: (element: HTMLElement, duration: number = 300): EfficientAnimation => {
    element.style.opacity = '0'
    
    return new EfficientAnimation(element, {
      duration,
      onFrame: (progress) => {
        element.style.opacity = progress.toString()
      },
      onComplete: () => {
        element.style.opacity = '1'
      }
    })
  },
  
  /**
   * Slide in from bottom
   */
  slideInUp: (element: HTMLElement, duration: number = 300): EfficientAnimation => {
    const initialTransform = element.style.transform || ''
    element.style.transform = `${initialTransform} translateY(20px)`
    element.style.opacity = '0'
    
    return new EfficientAnimation(element, {
      duration,
      onFrame: (progress) => {
        const translateY = 20 * (1 - progress)
        element.style.transform = `${initialTransform} translateY(${translateY}px)`
        element.style.opacity = progress.toString()
      },
      onComplete: () => {
        element.style.transform = initialTransform
        element.style.opacity = '1'
      }
    })
  },
  
  /**
   * Scale in animation
   */
  scaleIn: (element: HTMLElement, duration: number = 200): EfficientAnimation => {
    const initialTransform = element.style.transform || ''
    element.style.transform = `${initialTransform} scale(0.95)`
    element.style.opacity = '0'
    
    return new EfficientAnimation(element, {
      duration,
      easing: 'spring',
      onFrame: (progress) => {
        const scale = 0.95 + (0.05 * progress)
        element.style.transform = `${initialTransform} scale(${scale})`
        element.style.opacity = progress.toString()
      },
      onComplete: () => {
        element.style.transform = initialTransform
        element.style.opacity = '1'
      }
    })
  },
  
  /**
   * Hover scale animation
   */
  hoverScale: (element: HTMLElement, scale: number = 1.05): {
    enter: () => void
    leave: () => void
  } => {
    const initialTransform = element.style.transform || ''
    
    return {
      enter: () => {
        const animation = new EfficientAnimation(element, {
          duration: 150,
          onFrame: (progress) => {
            const currentScale = 1 + ((scale - 1) * progress)
            element.style.transform = `${initialTransform} scale(${currentScale})`
          }
        })
        animation.start()
      },
      leave: () => {
        const animation = new EfficientAnimation(element, {
          duration: 150,
          onFrame: (progress) => {
            const currentScale = scale - ((scale - 1) * progress)
            element.style.transform = `${initialTransform} scale(${currentScale})`
          },
          onComplete: () => {
            element.style.transform = initialTransform
          }
        })
        animation.start()
      }
    }
  },
  
  /**
   * Loading spinner animation
   */
  spin: (element: HTMLElement): EfficientAnimation => {
    let rotation = 0
    
    return new EfficientAnimation(element, {
      duration: 1000,
      onFrame: (progress) => {
        rotation = progress * 360
        element.style.transform = `rotate(${rotation}deg)`
      },
      onComplete: () => {
        // Restart for continuous spinning
        if (element.dataset.spinning === 'true') {
          efficientAnimations.spin(element).start()
        }
      }
    })
  },
  
  /**
   * Pulse animation for loading states
   */
  pulse: (element: HTMLElement): EfficientAnimation => {
    const initialOpacity = element.style.opacity || '1'
    
    return new EfficientAnimation(element, {
      duration: 1000,
      easing: 'ease-in-out',
      onFrame: (progress) => {
        // Create pulse effect (0.4 to 1.0 opacity)
        const opacity = 0.4 + (0.6 * (Math.sin(progress * Math.PI * 2) + 1) / 2)
        element.style.opacity = opacity.toString()
      },
      onComplete: () => {
        // Restart for continuous pulsing
        if (element.dataset.pulsing === 'true') {
          efficientAnimations.pulse(element).start()
        } else {
          element.style.opacity = initialOpacity
        }
      }
    })
  }
}

/**
 * Animation queue for managing multiple animations
 */
export class AnimationQueue {
  private queue: Array<() => Promise<void>> = []
  private isRunning: boolean = false
  
  /**
   * Add animation to queue
   */
  add(animationFn: () => Promise<void>): void {
    this.queue.push(animationFn)
    
    if (!this.isRunning) {
      this.process()
    }
  }
  
  /**
   * Process animation queue
   */
  private async process(): Promise<void> {
    if (this.queue.length === 0) {
      this.isRunning = false
      return
    }
    
    this.isRunning = true
    const animation = this.queue.shift()!
    
    try {
      await animation()
    } catch (error) {
      console.error('Animation failed:', error)
    }
    
    // Process next animation
    this.process()
  }
  
  /**
   * Clear all queued animations
   */
  clear(): void {
    this.queue = []
    this.isRunning = false
  }
}

/**
 * React hook for efficient animations
 */
export const useEfficientAnimation = () => {
  const animationsRef = React.useRef<Map<string, EfficientAnimation>>(new Map())
  const queueRef = React.useRef<AnimationQueue>(new AnimationQueue())
  
  const startAnimation = React.useCallback((
    element: HTMLElement,
    type: keyof typeof efficientAnimations,
    options?: Partial<EfficientAnimationConfig>
  ) => {
    const animationId = `${type}-${Date.now()}`
    
    // Stop existing animation if any
    const existingAnimation = animationsRef.current.get(animationId)
    if (existingAnimation) {
      existingAnimation.stop()
    }
    
    // Create new animation
    const animationFn = efficientAnimations[type] as (element: HTMLElement, duration?: number) => EfficientAnimation
    const animation = animationFn(element, options?.duration)
    
    // Override config if provided
    if (options) {
      Object.assign(animation['config'], options)
    }
    
    animationsRef.current.set(animationId, animation)
    animation.start()
    
    return animationId
  }, [])
  
  const stopAnimation = React.useCallback((animationId: string) => {
    const animation = animationsRef.current.get(animationId)
    if (animation) {
      animation.stop()
      animationsRef.current.delete(animationId)
    }
  }, [])
  
  const queueAnimation = React.useCallback((animationFn: () => Promise<void>) => {
    queueRef.current.add(animationFn)
  }, [])
  
  // Cleanup on unmount
  React.useEffect(() => {
    return () => {
      animationsRef.current.forEach(animation => animation.stop())
      animationsRef.current.clear()
      queueRef.current.clear()
    }
  }, [])
  
  return {
    startAnimation,
    stopAnimation,
    queueAnimation,
  }
}

/**
 * Performance-aware animation utilities
 */
export const performanceAwareAnimations = {
  /**
   * Check if device can handle complex animations
   */
  canHandleComplexAnimations: (): boolean => {
    // Check for reduced motion preference
    if (prefersReducedMotion()) return false
    
    // Check device capabilities
    const connection = (navigator as any).connection
    if (connection && connection.effectiveType && connection.effectiveType.includes('2g')) {
      return false
    }
    
    // Check for low-end device indicators
    const memory = (performance as any).memory
    if (memory && memory.jsHeapSizeLimit < 100 * 1024 * 1024) { // Less than 100MB
      return false
    }
    
    return true
  },
  
  /**
   * Get appropriate animation duration based on device performance
   */
  getOptimalDuration: (baseDuration: number): number => {
    if (!performanceAwareAnimations.canHandleComplexAnimations()) {
      return Math.min(baseDuration * 0.5, 150) // Faster animations on low-end devices
    }
    
    return baseDuration
  },
  
  /**
   * Adaptive animation based on performance
   */
  createAdaptiveAnimation: (
    element: HTMLElement,
    complexAnimation: () => EfficientAnimation,
    simpleAnimation: () => EfficientAnimation
  ): EfficientAnimation => {
    return performanceAwareAnimations.canHandleComplexAnimations()
      ? complexAnimation()
      : simpleAnimation()
  }
}

// Export singleton queue
export const globalAnimationQueue = new AnimationQueue()

// Import React for hooks
import React from 'react'