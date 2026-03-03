import * as React from "react"
import { cn } from "@/lib/utils"
import { 
  animationPresets, 
  useAnimation, 
  respectsReducedMotion,
  performanceMonitor,
  MicroAnimationOptions
} from "@/lib/micro-animations"

// Animated Container Props
export interface AnimatedContainerProps extends React.HTMLAttributes<HTMLDivElement> {
  children: React.ReactNode
  animation?: keyof typeof animationPresets | 'none'
  trigger?: 'mount' | 'hover' | 'click' | 'focus' | 'visible' | 'manual'
  delay?: number
  duration?: 'fast' | 'normal' | 'slow'
  easing?: 'easeIn' | 'easeOut' | 'easeInOut' | 'spring'
  respectReducedMotion?: boolean
  performanceMonitoring?: boolean
  onAnimationStart?: () => void
  onAnimationComplete?: () => void
}

// Animated Container Component
export const AnimatedContainer = React.forwardRef<HTMLDivElement, AnimatedContainerProps>(
  ({ 
    children,
    className,
    animation = 'fadeIn',
    trigger = 'mount',
    delay = 0,
    duration = 'normal',
    easing = 'easeOut',
    respectReducedMotion: respectMotion = true,
    performanceMonitoring = false,
    onAnimationStart,
    onAnimationComplete,
    onMouseEnter,
    onMouseLeave,
    onClick,
    onFocus,
    onBlur,
    ...props
  }, ref) => {
    const elementRef = React.useRef<HTMLDivElement>(null)
    const [hasAnimated, setHasAnimated] = React.useState(false)
    const { isAnimating, startAnimation } = useAnimation()
    
    // Combine refs
    React.useImperativeHandle(ref, () => elementRef.current!)

    // Animation options
    const animationOptions: MicroAnimationOptions = {
      duration,
      easing,
      delay,
      respectReducedMotion: respectMotion,
      performanceMonitoring,
      onStart: onAnimationStart,
      onComplete: onAnimationComplete
    }

    // Trigger animation function
    const triggerAnimation = React.useCallback(() => {
      if (!elementRef.current || animation === 'none') return
      
      if (respectMotion && respectsReducedMotion()) {
        onAnimationStart?.()
        onAnimationComplete?.()
        return
      }
      
      startAnimation(elementRef.current, animation, animationOptions)
    }, [animation, animationOptions, startAnimation, respectMotion, onAnimationStart, onAnimationComplete])

    // Mount animation
    React.useEffect(() => {
      if (trigger === 'mount' && !hasAnimated) {
        setHasAnimated(true)
        triggerAnimation()
      }
    }, [trigger, hasAnimated, triggerAnimation])

    // Intersection Observer for visibility-based animations
    React.useEffect(() => {
      if (trigger !== 'visible' || !elementRef.current) return

      const observer = new IntersectionObserver(
        ([entry]) => {
          if (entry.isIntersecting && !hasAnimated) {
            setHasAnimated(true)
            triggerAnimation()
          }
        },
        { threshold: 0.1 }
      )

      observer.observe(elementRef.current)

      return () => observer.disconnect()
    }, [trigger, hasAnimated, triggerAnimation])

    // Event handlers
    const handleMouseEnter = React.useCallback((e: React.MouseEvent<HTMLDivElement>) => {
      if (trigger === 'hover') {
        triggerAnimation()
      }
      onMouseEnter?.(e)
    }, [trigger, triggerAnimation, onMouseEnter])

    const handleClick = React.useCallback((e: React.MouseEvent<HTMLDivElement>) => {
      if (trigger === 'click') {
        triggerAnimation()
      }
      onClick?.(e)
    }, [trigger, triggerAnimation, onClick])

    const handleFocus = React.useCallback((e: React.FocusEvent<HTMLDivElement>) => {
      if (trigger === 'focus') {
        triggerAnimation()
      }
      onFocus?.(e)
    }, [trigger, triggerAnimation, onFocus])

    // Initial styles for certain animations
    const getInitialStyles = (): React.CSSProperties => {
      if (animation === 'none' || hasAnimated) return {}
      
      switch (animation) {
        case 'fadeIn':
          return { opacity: 0, transform: 'translateY(10px)' }
        case 'slideIn':
          return { transform: 'translateX(-100%)', opacity: 0 }
        case 'scaleIn':
          return { transform: 'scale(0.8)', opacity: 0 }
        default:
          return {}
      }
    }

    return (
      <div
        ref={elementRef}
        className={cn(
          "transition-all",
          isAnimating && "pointer-events-none",
          className
        )}
        style={getInitialStyles()}
        onMouseEnter={handleMouseEnter}
        onMouseLeave={onMouseLeave}
        onClick={handleClick}
        onFocus={handleFocus}
        onBlur={onBlur}
        {...props}
      >
        {children}
      </div>
    )
  }
)

AnimatedContainer.displayName = "AnimatedContainer"

// Animated List Item Component
export interface AnimatedListItemProps extends AnimatedContainerProps {
  index?: number
  staggerDelay?: number
}

export const AnimatedListItem = React.forwardRef<HTMLDivElement, AnimatedListItemProps>(
  ({ index = 0, staggerDelay = 100, delay = 0, ...props }, ref) => {
    const calculatedDelay = delay + (index * staggerDelay)
    
    return (
      <AnimatedContainer
        ref={ref}
        delay={calculatedDelay}
        {...props}
      />
    )
  }
)

AnimatedListItem.displayName = "AnimatedListItem"

// State Change Animator Component
export interface StateChangeAnimatorProps {
  children: React.ReactNode
  watchValue: any
  animation?: keyof typeof animationPresets
  className?: string
}

export const StateChangeAnimator: React.FC<StateChangeAnimatorProps> = ({
  children,
  watchValue,
  animation = 'highlight',
  className
}) => {
  const elementRef = React.useRef<HTMLDivElement>(null)
  const previousValue = React.useRef(watchValue)
  const { startAnimation } = useAnimation()

  React.useEffect(() => {
    if (previousValue.current !== watchValue && elementRef.current) {
      startAnimation(elementRef.current, animation, {
        respectReducedMotion: true,
        performanceMonitoring: true
      })
    }
    previousValue.current = watchValue
  }, [watchValue, animation, startAnimation])

  return (
    <div ref={elementRef} className={className}>
      {children}
    </div>
  )
}

// Loading Animator Component
export interface LoadingAnimatorProps {
  isLoading: boolean
  children: React.ReactNode
  loadingAnimation?: 'spin' | 'pulse' | 'breathe'
  className?: string
}

export const LoadingAnimator: React.FC<LoadingAnimatorProps> = ({
  isLoading,
  children,
  loadingAnimation = 'pulse',
  className
}) => {
  const elementRef = React.useRef<HTMLDivElement>(null)
  const { startAnimation, stopAnimation } = useAnimation()

  React.useEffect(() => {
    if (!elementRef.current) return

    if (isLoading) {
      startAnimation(elementRef.current, loadingAnimation, {
        iterations: 'infinite',
        respectReducedMotion: true,
        performanceMonitoring: true
      })
    } else {
      stopAnimation()
    }
  }, [isLoading, loadingAnimation, startAnimation, stopAnimation])

  return (
    <div ref={elementRef} className={className}>
      {children}
    </div>
  )
}

// Performance Monitor Component
export const AnimationPerformanceMonitor: React.FC<{
  enabled?: boolean
  onPerformanceIssue?: (averageFps: number, animationCount: number) => void
}> = ({ enabled = false, onPerformanceIssue }) => {
  React.useEffect(() => {
    if (!enabled) return

    performanceMonitor.startMonitoring()

    const interval = setInterval(() => {
      const avgFps = performanceMonitor.getAverageFrameRate()
      const animCount = performanceMonitor.getAnimationCount()
      
      if (!performanceMonitor.isPerformant()) {
        onPerformanceIssue?.(avgFps, animCount)
      }
    }, 1000)

    return () => {
      clearInterval(interval)
      performanceMonitor.stopMonitoring()
    }
  }, [enabled, onPerformanceIssue])

  return null
}

// Animation Trigger Hook
export const useAnimationTrigger = () => {
  const elementRef = React.useRef<HTMLElement>(null)
  const { startAnimation } = useAnimation()

  const trigger = React.useCallback((
    animation: keyof typeof animationPresets,
    options?: MicroAnimationOptions
  ) => {
    if (elementRef.current) {
      return startAnimation(elementRef.current, animation, options)
    }
    return null
  }, [startAnimation])

  return { elementRef, trigger }
}

// Stagger Animation Hook
export const useStaggerAnimation = (itemCount: number, staggerDelay: number = 100) => {
  const [currentIndex, setCurrentIndex] = React.useState(0)
  const timeoutRef = React.useRef<NodeJS.Timeout>()

  const startStagger = React.useCallback(() => {
    setCurrentIndex(0)
    
    const animateNext = (index: number) => {
      if (index < itemCount) {
        setCurrentIndex(index)
        timeoutRef.current = setTimeout(() => animateNext(index + 1), staggerDelay)
      }
    }
    
    animateNext(0)
  }, [itemCount, staggerDelay])

  const stopStagger = React.useCallback(() => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current)
    }
  }, [])

  React.useEffect(() => {
    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
      }
    }
  }, [])

  return { currentIndex, startStagger, stopStagger }
}

export {
  animate,
  animationPresets,
  useAnimation,
  respectsReducedMotion,
  performanceMonitor
} from "@/lib/micro-animations"