/**
 * Performance Preservation Utilities
 * Monitor and maintain performance during UI enhancements
 */

import { performanceMonitor } from './performance-optimization'

/**
 * Performance thresholds and targets
 */
export const performanceThresholds = {
  // Core Web Vitals targets
  lcp: 2500, // Largest Contentful Paint (ms)
  fid: 100,  // First Input Delay (ms)
  cls: 0.1,  // Cumulative Layout Shift
  
  // Custom performance targets
  pageLoad: 3000,     // Total page load time (ms)
  renderTime: 16,     // Component render time (ms) - 60fps target
  bundleSize: 500000, // Total bundle size (bytes)
  
  // Memory usage targets
  memoryUsage: 50 * 1024 * 1024, // 50MB heap size limit
  
  // Network performance
  resourceLoad: 1000, // Individual resource load time (ms)
}

/**
 * Performance monitoring and preservation class
 */
export class PerformancePreservation {
  private static instance: PerformancePreservation
  private metrics: Map<string, number[]> = new Map()
  private observers: Map<string, PerformanceObserver> = new Map()
  private isMonitoring: boolean = false
  
  static getInstance(): PerformancePreservation {
    if (!PerformancePreservation.instance) {
      PerformancePreservation.instance = new PerformancePreservation()
    }
    return PerformancePreservation.instance
  }
  
  /**
   * Start comprehensive performance monitoring
   */
  startMonitoring(): void {
    if (this.isMonitoring || typeof window === 'undefined') return
    
    this.isMonitoring = true
    
    // Monitor Core Web Vitals
    this.monitorCoreWebVitals()
    
    // Monitor resource loading
    this.monitorResourceLoading()
    
    // Monitor memory usage
    this.monitorMemoryUsage()
    
    // Monitor frame rate
    this.monitorFrameRate()
    
    // Monitor bundle size impact
    this.monitorBundleSize()
  }
  
  /**
   * Stop performance monitoring
   */
  stopMonitoring(): void {
    this.isMonitoring = false
    
    this.observers.forEach(observer => observer.disconnect())
    this.observers.clear()
  }
  
  /**
   * Monitor Core Web Vitals
   */
  private monitorCoreWebVitals(): void {
    if (!('PerformanceObserver' in window)) return
    
    // Monitor LCP (Largest Contentful Paint)
    const lcpObserver = new PerformanceObserver((list) => {
      const entries = list.getEntries()
      const lastEntry = entries[entries.length - 1] as any
      if (lastEntry) {
        this.recordMetric('lcp', lastEntry.startTime)
        this.checkThreshold('lcp', lastEntry.startTime)
      }
    })
    
    try {
      lcpObserver.observe({ entryTypes: ['largest-contentful-paint'] })
      this.observers.set('lcp', lcpObserver)
    } catch (e) {
      console.warn('LCP monitoring not supported')
    }
    
    // Monitor FID (First Input Delay)
    const fidObserver = new PerformanceObserver((list) => {
      const entries = list.getEntries()
      entries.forEach((entry: any) => {
        this.recordMetric('fid', entry.processingStart - entry.startTime)
        this.checkThreshold('fid', entry.processingStart - entry.startTime)
      })
    })
    
    try {
      fidObserver.observe({ entryTypes: ['first-input'] })
      this.observers.set('fid', fidObserver)
    } catch (e) {
      console.warn('FID monitoring not supported')
    }
    
    // Monitor CLS (Cumulative Layout Shift)
    let clsValue = 0
    const clsObserver = new PerformanceObserver((list) => {
      const entries = list.getEntries()
      entries.forEach((entry: any) => {
        if (!entry.hadRecentInput) {
          clsValue += entry.value
          this.recordMetric('cls', clsValue)
          this.checkThreshold('cls', clsValue)
        }
      })
    })
    
    try {
      clsObserver.observe({ entryTypes: ['layout-shift'] })
      this.observers.set('cls', clsObserver)
    } catch (e) {
      console.warn('CLS monitoring not supported')
    }
  }
  
  /**
   * Monitor resource loading performance
   */
  private monitorResourceLoading(): void {
    if (!('PerformanceObserver' in window)) return
    
    const resourceObserver = new PerformanceObserver((list) => {
      const entries = list.getEntries()
      entries.forEach((entry: any) => {
        const loadTime = entry.responseEnd - entry.startTime
        this.recordMetric('resourceLoad', loadTime)
        
        // Check for slow resources
        if (loadTime > performanceThresholds.resourceLoad) {
          console.warn(`Slow resource detected: ${entry.name} (${loadTime}ms)`)
        }
        
        // Track specific resource types
        if (entry.initiatorType === 'script') {
          this.recordMetric('scriptLoad', loadTime)
        } else if (entry.initiatorType === 'css') {
          this.recordMetric('cssLoad', loadTime)
        } else if (entry.initiatorType === 'img') {
          this.recordMetric('imageLoad', loadTime)
        }
      })
    })
    
    try {
      resourceObserver.observe({ entryTypes: ['resource'] })
      this.observers.set('resource', resourceObserver)
    } catch (e) {
      console.warn('Resource monitoring not supported')
    }
  }
  
  /**
   * Monitor memory usage
   */
  private monitorMemoryUsage(): void {
    if (!('memory' in performance)) return
    
    const checkMemory = () => {
      const memory = (performance as any).memory
      if (memory) {
        this.recordMetric('memoryUsed', memory.usedJSHeapSize)
        this.recordMetric('memoryTotal', memory.totalJSHeapSize)
        this.recordMetric('memoryLimit', memory.jsHeapSizeLimit)
        
        // Check memory threshold
        if (memory.usedJSHeapSize > performanceThresholds.memoryUsage) {
          console.warn(`High memory usage detected: ${Math.round(memory.usedJSHeapSize / 1024 / 1024)}MB`)
        }
      }
    }
    
    // Check memory every 5 seconds
    const memoryInterval = setInterval(checkMemory, 5000)
    
    // Store interval for cleanup
    ;(this as any).memoryInterval = memoryInterval
  }
  
  /**
   * Monitor frame rate performance
   */
  private monitorFrameRate(): void {
    let lastTime = performance.now()
    let frameCount = 0
    let fps = 0
    
    const measureFPS = (currentTime: number) => {
      frameCount++
      
      if (currentTime - lastTime >= 1000) {
        fps = Math.round((frameCount * 1000) / (currentTime - lastTime))
        this.recordMetric('fps', fps)
        
        // Check for low frame rate
        if (fps < 30) {
          console.warn(`Low frame rate detected: ${fps}fps`)
        }
        
        frameCount = 0
        lastTime = currentTime
      }
      
      if (this.isMonitoring) {
        requestAnimationFrame(measureFPS)
      }
    }
    
    requestAnimationFrame(measureFPS)
  }
  
  /**
   * Monitor bundle size impact
   */
  private monitorBundleSize(): void {
    // Check initial bundle size
    const checkBundleSize = () => {
      const scripts = Array.from(document.querySelectorAll('script[src]'))
      const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
      
      let totalSize = 0
      
      // Estimate script sizes (would need actual size data from build process)
      scripts.forEach(script => {
        const src = (script as HTMLScriptElement).src
        if (src && !src.includes('node_modules')) {
          // Rough estimate - would be better to get actual sizes
          totalSize += 50000 // 50KB estimate per script
        }
      })
      
      // Estimate style sizes
      styles.forEach(style => {
        const href = (style as HTMLLinkElement).href
        if (href && !href.includes('node_modules')) {
          totalSize += 20000 // 20KB estimate per stylesheet
        }
      })
      
      this.recordMetric('bundleSize', totalSize)
      
      if (totalSize > performanceThresholds.bundleSize) {
        console.warn(`Large bundle size detected: ${Math.round(totalSize / 1024)}KB`)
      }
    }
    
    // Check bundle size after page load
    if (document.readyState === 'complete') {
      checkBundleSize()
    } else {
      window.addEventListener('load', checkBundleSize)
    }
  }
  
  /**
   * Record performance metric
   */
  private recordMetric(name: string, value: number): void {
    if (!this.metrics.has(name)) {
      this.metrics.set(name, [])
    }
    
    const values = this.metrics.get(name)!
    values.push(value)
    
    // Keep only last 100 measurements
    if (values.length > 100) {
      values.shift()
    }
    
    // Also record in performance monitor
    performanceMonitor.recordMetric(name, value)
  }
  
  /**
   * Check if metric exceeds threshold
   */
  private checkThreshold(metric: string, value: number): void {
    const threshold = performanceThresholds[metric as keyof typeof performanceThresholds]
    if (threshold && value > threshold) {
      console.warn(`Performance threshold exceeded for ${metric}: ${value} > ${threshold}`)
      
      // Emit custom event for performance degradation
      window.dispatchEvent(new CustomEvent('performance-degradation', {
        detail: { metric, value, threshold }
      }))
    }
  }
  
  /**
   * Get performance summary
   */
  getPerformanceSummary(): Record<string, {
    current: number
    average: number
    threshold: number
    status: 'good' | 'warning' | 'poor'
  }> {
    const summary: Record<string, any> = {}
    
    this.metrics.forEach((values, metric) => {
      if (values.length === 0) return
      
      const current = values[values.length - 1]
      const average = values.reduce((sum, val) => sum + val, 0) / values.length
      const threshold = performanceThresholds[metric as keyof typeof performanceThresholds] || 0
      
      let status: 'good' | 'warning' | 'poor' = 'good'
      if (current > threshold) {
        status = current > threshold * 1.5 ? 'poor' : 'warning'
      }
      
      summary[metric] = {
        current: Math.round(current * 100) / 100,
        average: Math.round(average * 100) / 100,
        threshold,
        status
      }
    })
    
    return summary
  }
  
  /**
   * Get performance recommendations
   */
  getRecommendations(): string[] {
    const recommendations: string[] = []
    const summary = this.getPerformanceSummary()
    
    Object.entries(summary).forEach(([metric, data]) => {
      if (data.status === 'poor' || data.status === 'warning') {
        switch (metric) {
          case 'lcp':
            recommendations.push('Optimize largest contentful paint by reducing image sizes and improving server response times')
            break
          case 'fid':
            recommendations.push('Reduce first input delay by minimizing JavaScript execution time')
            break
          case 'cls':
            recommendations.push('Improve cumulative layout shift by setting dimensions for images and avoiding dynamic content insertion')
            break
          case 'fps':
            recommendations.push('Improve frame rate by optimizing animations and reducing DOM manipulations')
            break
          case 'memoryUsed':
            recommendations.push('Reduce memory usage by cleaning up event listeners and avoiding memory leaks')
            break
          case 'bundleSize':
            recommendations.push('Reduce bundle size through code splitting and tree shaking')
            break
          case 'resourceLoad':
            recommendations.push('Optimize resource loading by compressing assets and using CDN')
            break
        }
      }
    })
    
    return recommendations
  }
  
  /**
   * Export performance data
   */
  exportPerformanceData(): {
    timestamp: number
    metrics: Record<string, number[]>
    summary: Record<string, any>
    recommendations: string[]
  } {
    return {
      timestamp: Date.now(),
      metrics: Object.fromEntries(this.metrics),
      summary: this.getPerformanceSummary(),
      recommendations: this.getRecommendations()
    }
  }
}

/**
 * Image optimization utilities
 */
export const imageOptimization = {
  /**
   * Optimize image loading with lazy loading and responsive images
   */
  optimizeImage: (img: HTMLImageElement, options: {
    lazy?: boolean
    responsive?: boolean
    quality?: number
    format?: 'webp' | 'avif' | 'auto'
  } = {}): void => {
    const { lazy = true, responsive = true, quality = 80, format = 'auto' } = options
    
    // Add lazy loading
    if (lazy && 'loading' in HTMLImageElement.prototype) {
      img.loading = 'lazy'
    }
    
    // Add responsive behavior
    if (responsive) {
      img.style.maxWidth = '100%'
      img.style.height = 'auto'
    }
    
    // Optimize format if supported
    if (format !== 'auto') {
      const canvas = document.createElement('canvas')
      const ctx = canvas.getContext('2d')
      
      if (ctx && img.complete) {
        canvas.width = img.naturalWidth
        canvas.height = img.naturalHeight
        ctx.drawImage(img, 0, 0)
        
        const optimizedDataUrl = canvas.toDataURL(`image/${format}`, quality / 100)
        img.src = optimizedDataUrl
      }
    }
  },
  
  /**
   * Preload critical images
   */
  preloadCriticalImages: (imageSrcs: string[]): void => {
    imageSrcs.forEach(src => {
      const link = document.createElement('link')
      link.rel = 'preload'
      link.as = 'image'
      link.href = src
      document.head.appendChild(link)
    })
  },
  
  /**
   * Generate responsive image srcset
   */
  generateResponsiveSrcSet: (baseSrc: string, sizes: number[]): string => {
    return sizes
      .map(size => {
        // For a real implementation, you'd generate different sized images
        // This is a placeholder that would work with an image service
        const optimizedSrc = baseSrc.includes('?') 
          ? `${baseSrc}&w=${size}&q=80&f=auto`
          : `${baseSrc}?w=${size}&q=80&f=auto`
        return `${optimizedSrc} ${size}w`
      })
      .join(', ')
  }
}

/**
 * Asset compression utilities
 */
export const assetCompression = {
  /**
   * Compress text content using gzip-like compression
   */
  compressText: (text: string): string => {
    // Simple compression simulation - in real implementation, 
    // this would be handled by build tools or server
    return text
      .replace(/\s+/g, ' ') // Normalize whitespace
      .replace(/\/\*[\s\S]*?\*\//g, '') // Remove CSS comments
      .replace(/\/\/.*$/gm, '') // Remove JS comments
      .trim()
  },
  
  /**
   * Minify CSS
   */
  minifyCSS: (css: string): string => {
    return css
      .replace(/\/\*[\s\S]*?\*\//g, '') // Remove comments
      .replace(/\s+/g, ' ') // Normalize whitespace
      .replace(/;\s*}/g, '}') // Remove last semicolon in blocks
      .replace(/\s*{\s*/g, '{') // Remove spaces around braces
      .replace(/;\s*/g, ';') // Remove spaces after semicolons
      .replace(/,\s*/g, ',') // Remove spaces after commas
      .trim()
  },
  
  /**
   * Estimate compression savings
   */
  estimateCompressionSavings: (originalSize: number, compressedSize: number): {
    savings: number
    percentage: number
  } => {
    const savings = originalSize - compressedSize
    const percentage = Math.round((savings / originalSize) * 100)
    
    return { savings, percentage }
  }
}

/**
 * Network condition monitoring
 */
export const networkMonitoring = {
  /**
   * Get network information
   */
  getNetworkInfo: (): {
    effectiveType: string
    downlink: number
    rtt: number
    saveData: boolean
  } | null => {
    const connection = (navigator as any).connection || (navigator as any).mozConnection || (navigator as any).webkitConnection
    
    if (!connection) return null
    
    return {
      effectiveType: connection.effectiveType || 'unknown',
      downlink: connection.downlink || 0,
      rtt: connection.rtt || 0,
      saveData: connection.saveData || false
    }
  },
  
  /**
   * Adapt performance based on network conditions
   */
  adaptToNetworkConditions: (): {
    shouldReduceAnimations: boolean
    shouldLazyLoad: boolean
    shouldCompressImages: boolean
    recommendedQuality: number
  } => {
    const networkInfo = networkMonitoring.getNetworkInfo()
    
    if (!networkInfo) {
      return {
        shouldReduceAnimations: false,
        shouldLazyLoad: true,
        shouldCompressImages: true,
        recommendedQuality: 80
      }
    }
    
    const isSlowConnection = networkInfo.effectiveType.includes('2g') || networkInfo.effectiveType.includes('slow-2g')
    const isSaveDataEnabled = networkInfo.saveData
    
    return {
      shouldReduceAnimations: isSlowConnection || isSaveDataEnabled,
      shouldLazyLoad: true,
      shouldCompressImages: isSlowConnection || isSaveDataEnabled,
      recommendedQuality: isSlowConnection || isSaveDataEnabled ? 60 : 80
    }
  }
}

/**
 * Device performance detection
 */
export const devicePerformance = {
  /**
   * Detect device performance level
   */
  getDevicePerformanceLevel: (): 'high' | 'medium' | 'low' => {
    // Check hardware concurrency (CPU cores)
    const cores = navigator.hardwareConcurrency || 1
    
    // Check memory (if available)
    const memory = (navigator as any).deviceMemory || 0
    
    // Check connection speed
    const networkInfo = networkMonitoring.getNetworkInfo()
    const isSlowNetwork = networkInfo?.effectiveType.includes('2g') || false
    
    // Determine performance level
    if (cores >= 8 && memory >= 8 && !isSlowNetwork) {
      return 'high'
    } else if (cores >= 4 && memory >= 4 && !isSlowNetwork) {
      return 'medium'
    } else {
      return 'low'
    }
  },
  
  /**
   * Get performance recommendations based on device
   */
  getDeviceRecommendations: (): {
    maxAnimations: number
    shouldUseGPU: boolean
    shouldPreload: boolean
    recommendedFPS: number
  } => {
    const level = devicePerformance.getDevicePerformanceLevel()
    
    switch (level) {
      case 'high':
        return {
          maxAnimations: 10,
          shouldUseGPU: true,
          shouldPreload: true,
          recommendedFPS: 60
        }
      case 'medium':
        return {
          maxAnimations: 5,
          shouldUseGPU: true,
          shouldPreload: false,
          recommendedFPS: 30
        }
      case 'low':
        return {
          maxAnimations: 2,
          shouldUseGPU: false,
          shouldPreload: false,
          recommendedFPS: 15
        }
    }
  }
}

// Export singleton instance
export const performancePreservation = PerformancePreservation.getInstance()

// Auto-start monitoring in browser environment
if (typeof window !== 'undefined') {
  // Start monitoring after page load
  if (document.readyState === 'complete') {
    performancePreservation.startMonitoring()
  } else {
    window.addEventListener('load', () => {
      performancePreservation.startMonitoring()
    })
  }
  
  // Stop monitoring on page unload
  window.addEventListener('beforeunload', () => {
    performancePreservation.stopMonitoring()
  })
}