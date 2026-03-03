/**
 * Performance Optimization Utilities
 * CSS and JavaScript optimization for minimal bundle size impact
 */

/**
 * Lazy loading utilities
 */
export const createLazyComponent = <T extends React.ComponentType<any>>(
  importFn: () => Promise<{ default: T }>
) => {
  return React.lazy(importFn)
}

/**
 * Code splitting utilities
 */
export const loadComponentAsync = async <T>(
  importFn: () => Promise<{ default: T }>
): Promise<T> => {
  const module = await importFn()
  return module.default
}

/**
 * CSS optimization utilities
 */
export const optimizedCSS = {
  /**
   * Generate minimal CSS classes for common patterns
   */
  button: 'inline-flex items-center justify-center rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-50',
  
  card: 'rounded-lg border bg-card text-card-foreground shadow-sm',
  
  input: 'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
  
  /**
   * Minimal animation classes
   */
  fadeIn: 'animate-in fade-in-0 duration-200',
  slideIn: 'animate-in slide-in-from-bottom-2 duration-200',
  scaleIn: 'animate-in zoom-in-95 duration-200',
  
  /**
   * Responsive utilities (minimal set)
   */
  responsive: {
    hide: 'hidden md:block',
    show: 'block md:hidden',
    stack: 'flex flex-col md:flex-row',
    center: 'mx-auto max-w-7xl px-4 sm:px-6 lg:px-8',
  },
  
  /**
   * Accessibility utilities (minimal set)
   */
  a11y: {
    srOnly: 'sr-only',
    focusVisible: 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
    touchTarget: 'min-h-[44px] min-w-[44px]',
    motionSafe: 'motion-reduce:transition-none motion-reduce:transform-none',
  }
}

/**
 * Bundle size monitoring
 */
export class BundleSizeMonitor {
  private static instance: BundleSizeMonitor
  private sizeThresholds = {
    css: 50 * 1024, // 50KB
    js: 200 * 1024, // 200KB
    total: 500 * 1024, // 500KB
  }
  
  static getInstance(): BundleSizeMonitor {
    if (!BundleSizeMonitor.instance) {
      BundleSizeMonitor.instance = new BundleSizeMonitor()
    }
    return BundleSizeMonitor.instance
  }
  
  /**
   * Estimate component size impact
   */
  estimateComponentSize(componentName: string, features: string[]): number {
    // Rough estimates in bytes
    const baseSizes = {
      button: 2048,
      card: 1536,
      input: 2560,
      modal: 4096,
      table: 3072,
      chart: 8192,
    }
    
    const featureSizes = {
      animation: 1024,
      responsive: 512,
      accessibility: 768,
      theming: 256,
    }
    
    const baseSize = baseSizes[componentName as keyof typeof baseSizes] || 1024
    const featureSize = features.reduce((total, feature) => {
      return total + (featureSizes[feature as keyof typeof featureSizes] || 0)
    }, 0)
    
    return baseSize + featureSize
  }
  
  /**
   * Check if size is within threshold
   */
  checkThreshold(type: 'css' | 'js' | 'total', size: number): boolean {
    return size <= this.sizeThresholds[type]
  }
  
  /**
   * Get size recommendations
   */
  getRecommendations(currentSize: number, type: 'css' | 'js' | 'total'): string[] {
    const threshold = this.sizeThresholds[type]
    const recommendations: string[] = []
    
    if (currentSize > threshold) {
      const overage = currentSize - threshold
      const percentage = Math.round((overage / threshold) * 100)
      
      recommendations.push(`Bundle is ${percentage}% over recommended size`)
      
      if (type === 'css') {
        recommendations.push('Consider using CSS-in-JS or atomic CSS')
        recommendations.push('Remove unused CSS classes')
        recommendations.push('Use CSS custom properties instead of duplicated values')
      } else if (type === 'js') {
        recommendations.push('Implement code splitting for large components')
        recommendations.push('Use dynamic imports for non-critical features')
        recommendations.push('Consider tree shaking unused exports')
      }
    }
    
    return recommendations
  }
}

/**
 * GPU acceleration utilities
 */
export const gpuOptimization = {
  /**
   * CSS properties that trigger GPU acceleration
   */
  gpuAccelerated: [
    'transform',
    'opacity',
    'filter',
    'backdrop-filter',
  ],
  
  /**
   * Properties that should be avoided for animations (cause layout/paint)
   */
  layoutTriggers: [
    'width',
    'height',
    'padding',
    'margin',
    'border',
    'top',
    'left',
    'right',
    'bottom',
  ],
  
  /**
   * Generate GPU-optimized animation classes
   */
  getOptimizedAnimationClass: (property: string): string => {
    const gpuProps = gpuOptimization.gpuAccelerated
    const isGpuOptimized = gpuProps.includes(property)
    
    if (isGpuOptimized) {
      return `transition-${property} will-change-${property}`
    } else {
      console.warn(`Property '${property}' may cause layout thrashing. Consider using transform or opacity instead.`)
      return `transition-${property}`
    }
  },
  
  /**
   * Optimize element for animations
   */
  optimizeForAnimation: (element: HTMLElement, properties: string[]): void => {
    // Add will-change for upcoming animations
    element.style.willChange = properties.join(', ')
    
    // Force layer creation for GPU acceleration
    if (properties.some(prop => gpuOptimization.gpuAccelerated.includes(prop))) {
      element.style.transform = element.style.transform || 'translateZ(0)'
    }
  },
  
  /**
   * Clean up animation optimizations
   */
  cleanupAnimationOptimizations: (element: HTMLElement): void => {
    element.style.willChange = 'auto'
    // Don't remove transform as it might be used for positioning
  }
}

/**
 * Lazy loading for non-critical visual elements
 */
export class LazyLoader {
  private observer: IntersectionObserver | null = null
  private loadedElements = new Set<Element>()
  
  constructor(options: IntersectionObserverInit = {}) {
    if (typeof window !== 'undefined' && 'IntersectionObserver' in window) {
      this.observer = new IntersectionObserver(
        this.handleIntersection.bind(this),
        {
          rootMargin: '50px',
          threshold: 0.1,
          ...options,
        }
      )
    }
  }
  
  private handleIntersection(entries: IntersectionObserverEntry[]): void {
    entries.forEach(entry => {
      if (entry.isIntersecting && !this.loadedElements.has(entry.target)) {
        this.loadElement(entry.target)
        this.loadedElements.add(entry.target)
        this.observer?.unobserve(entry.target)
      }
    })
  }
  
  private loadElement(element: Element): void {
    // Load images
    if (element.tagName === 'IMG') {
      const img = element as HTMLImageElement
      const dataSrc = img.dataset.src
      if (dataSrc) {
        img.src = dataSrc
        img.removeAttribute('data-src')
      }
    }
    
    // Load background images
    const dataBg = element.getAttribute('data-bg')
    if (dataBg) {
      (element as HTMLElement).style.backgroundImage = `url(${dataBg})`
      element.removeAttribute('data-bg')
    }
    
    // Load components
    const dataComponent = element.getAttribute('data-component')
    if (dataComponent) {
      this.loadComponent(element, dataComponent)
    }
    
    // Trigger custom load event
    element.dispatchEvent(new CustomEvent('lazyload'))
  }
  
  private async loadComponent(element: Element, componentName: string): Promise<void> {
    try {
      // Dynamic import based on component name
      const componentMap: Record<string, () => Promise<any>> = {
        chart: () => import('@/components/ui/enhanced-chart'),
        table: () => import('@/components/ui/enhanced-table'),
        // Add more components as needed
      }
      
      const importFn = componentMap[componentName]
      if (importFn) {
        await importFn()
        element.setAttribute('data-loaded', 'true')
      }
    } catch (error) {
      console.error(`Failed to load component: ${componentName}`, error)
    }
  }
  
  /**
   * Observe element for lazy loading
   */
  observe(element: Element): void {
    if (this.observer) {
      this.observer.observe(element)
    } else {
      // Fallback for browsers without IntersectionObserver
      this.loadElement(element)
    }
  }
  
  /**
   * Stop observing all elements
   */
  disconnect(): void {
    if (this.observer) {
      this.observer.disconnect()
    }
    this.loadedElements.clear()
  }
}

/**
 * Performance monitoring
 */
export class PerformanceMonitor {
  private metrics: Record<string, number[]> = {}
  
  /**
   * Mark performance timing
   */
  mark(name: string): void {
    if (typeof performance !== 'undefined') {
      performance.mark(name)
    }
  }
  
  /**
   * Measure performance between marks
   */
  measure(name: string, startMark: string, endMark?: string): number {
    if (typeof performance !== 'undefined') {
      try {
        performance.measure(name, startMark, endMark)
        const entries = performance.getEntriesByName(name, 'measure')
        const latestEntry = entries[entries.length - 1]
        
        if (latestEntry) {
          this.recordMetric(name, latestEntry.duration)
          return latestEntry.duration
        }
      } catch (error) {
        console.warn('Performance measurement failed:', error)
      }
    }
    return 0
  }
  
  /**
   * Record custom metric
   */
  recordMetric(name: string, value: number): void {
    if (!this.metrics[name]) {
      this.metrics[name] = []
    }
    this.metrics[name].push(value)
    
    // Keep only last 100 measurements
    if (this.metrics[name].length > 100) {
      this.metrics[name].shift()
    }
  }
  
  /**
   * Get metric statistics
   */
  getMetricStats(name: string): {
    count: number
    average: number
    min: number
    max: number
    latest: number
  } | null {
    const values = this.metrics[name]
    if (!values || values.length === 0) return null
    
    return {
      count: values.length,
      average: values.reduce((sum, val) => sum + val, 0) / values.length,
      min: Math.min(...values),
      max: Math.max(...values),
      latest: values[values.length - 1],
    }
  }
  
  /**
   * Monitor component render performance
   */
  monitorComponent<T extends React.ComponentType<any>>(
    Component: T,
    name: string
  ): T {
    const MonitoredComponent = React.forwardRef<any, React.ComponentProps<T>>((props, ref) => {
      React.useEffect(() => {
        this.mark(`${name}-render-start`)
        
        return () => {
          this.mark(`${name}-render-end`)
          this.measure(`${name}-render`, `${name}-render-start`, `${name}-render-end`)
        }
      })
      
      return React.createElement(Component, { ...props, ref })
    })
    
    MonitoredComponent.displayName = `Monitored(${Component.displayName || Component.name || 'Component'})`
    
    return MonitoredComponent as unknown as T
  }
}

/**
 * Resource optimization utilities
 */
export const resourceOptimization = {
  /**
   * Preload critical resources
   */
  preloadCriticalResources: (): void => {
    const criticalResources = [
      { href: '/fonts/inter-var.woff2', as: 'font', type: 'font/woff2' },
      // Add more critical resources
    ]
    
    criticalResources.forEach(resource => {
      const link = document.createElement('link')
      link.rel = 'preload'
      link.href = resource.href
      link.as = resource.as
      if (resource.type) link.type = resource.type
      if (resource.as === 'font') link.crossOrigin = 'anonymous'
      document.head.appendChild(link)
    })
  },
  
  /**
   * Optimize images for different screen densities
   */
  getOptimizedImageSrc: (
    baseSrc: string,
    width: number,
    devicePixelRatio: number = window.devicePixelRatio || 1
  ): string => {
    const targetWidth = Math.round(width * devicePixelRatio)
    
    // If using a CDN or image service, append size parameters
    if (baseSrc.includes('cloudinary') || baseSrc.includes('imagekit')) {
      return `${baseSrc}?w=${targetWidth}&q=auto&f=auto`
    }
    
    // For local images, return as-is (would need build-time optimization)
    return baseSrc
  },
  
  /**
   * Generate responsive image srcset
   */
  generateSrcSet: (baseSrc: string, sizes: number[]): string => {
    return sizes
      .map(size => `${resourceOptimization.getOptimizedImageSrc(baseSrc, size)} ${size}w`)
      .join(', ')
  }
}

/**
 * Memory management utilities
 */
export const memoryManagement = {
  /**
   * Clean up event listeners and observers
   */
  cleanup: {
    eventListeners: new Map<Element, Array<{ event: string; handler: EventListener }>>(),
    
    addListener: (element: Element, event: string, handler: EventListener): void => {
      element.addEventListener(event, handler)
      
      if (!memoryManagement.cleanup.eventListeners.has(element)) {
        memoryManagement.cleanup.eventListeners.set(element, [])
      }
      memoryManagement.cleanup.eventListeners.get(element)!.push({ event, handler })
    },
    
    removeAllListeners: (element: Element): void => {
      const listeners = memoryManagement.cleanup.eventListeners.get(element)
      if (listeners) {
        listeners.forEach(({ event, handler }) => {
          element.removeEventListener(event, handler)
        })
        memoryManagement.cleanup.eventListeners.delete(element)
      }
    },
    
    cleanupAll: (): void => {
      memoryManagement.cleanup.eventListeners.forEach((listeners, element) => {
        listeners.forEach(({ event, handler }) => {
          element.removeEventListener(event, handler)
        })
      })
      memoryManagement.cleanup.eventListeners.clear()
    }
  },
  
  /**
   * Debounce expensive operations
   */
  debounce: <T extends (...args: any[]) => any>(
    func: T,
    wait: number
  ): ((...args: Parameters<T>) => void) => {
    let timeout: NodeJS.Timeout
    
    return (...args: Parameters<T>) => {
      clearTimeout(timeout)
      timeout = setTimeout(() => func(...args), wait)
    }
  },
  
  /**
   * Throttle high-frequency events
   */
  throttle: <T extends (...args: any[]) => any>(
    func: T,
    limit: number
  ): ((...args: Parameters<T>) => void) => {
    let inThrottle: boolean
    
    return (...args: Parameters<T>) => {
      if (!inThrottle) {
        func(...args)
        inThrottle = true
        setTimeout(() => inThrottle = false, limit)
      }
    }
  }
}

// Export singleton instances
export const bundleSizeMonitor = BundleSizeMonitor.getInstance()
export const performanceMonitor = new PerformanceMonitor()
export const lazyLoader = new LazyLoader()

// Import React for components
import React from 'react'

// Initialize performance optimizations
if (typeof window !== 'undefined') {
  // Preload critical resources
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', resourceOptimization.preloadCriticalResources)
  } else {
    resourceOptimization.preloadCriticalResources()
  }
  
  // Cleanup on page unload
  window.addEventListener('beforeunload', memoryManagement.cleanup.cleanupAll)
}