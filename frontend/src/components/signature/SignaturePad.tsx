import { useCallback, useEffect, useRef, useState } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'

interface SignaturePadProps {
  /** Existing signature (PNG data URL) to render, or null. */
  value?: string | null
  onChange?: (dataUrl: string | null) => void
  label?: string
  disabled?: boolean
  /** Rendered height in CSS pixels. Width always fills the container. */
  height?: number
  hint?: string
  className?: string
}

/**
 * Pen/mouse/touch signature pad.
 *
 * Two things this gets right that the previous inline canvases did not:
 *
 *  1. **The drawing surface matches the rendered box.** A canvas with a fixed
 *     `width` attribute stretched by `w-full` has its intrinsic coordinate
 *     space (e.g. 500px) out of step with its on-screen width (e.g. 800px), so
 *     strokes on the right-hand side were drawn outside the visible surface —
 *     "I sign here and the ink appears somewhere else". The surface is now
 *     sized from the measured box (x devicePixelRatio) and kept in sync by a
 *     ResizeObserver, so 1 CSS pixel of pointer travel is 1 CSS pixel of ink.
 *
 *  2. **Pointer events.** `pointerdown/move/up/cancel` cover stylus, mouse and
 *     touch with one code path, with pointer capture so a stroke keeps
 *     tracking when the pen briefly leaves the element.
 */
export function SignaturePad({
  value,
  onChange,
  label,
  disabled = false,
  height = 160,
  hint = 'Sign with your mouse, finger or stylus above',
  className = '',
}: SignaturePadProps) {
  const wrapperRef = useRef<HTMLDivElement | null>(null)
  const canvasRef = useRef<HTMLCanvasElement | null>(null)
  const drawing = useRef(false)
  const lastPos = useRef<{ x: number; y: number } | null>(null)
  const [isEmpty, setIsEmpty] = useState(!value)
  const [hasStrokes, setHasStrokes] = useState(false)

  const paintStyle = useCallback((ctx: CanvasRenderingContext2D, dpr: number) => {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    ctx.strokeStyle = '#0f172a'
    ctx.fillStyle = '#0f172a'
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
  }, [])

  // Size the bitmap to the rendered box, preserving anything already drawn.
  const syncSize = useCallback(() => {
    const canvas = canvasRef.current
    const wrapper = wrapperRef.current
    if (!canvas || !wrapper) return

    const rect = wrapper.getBoundingClientRect()
    if (rect.width === 0) return

    const dpr = typeof window !== 'undefined' ? window.devicePixelRatio || 1 : 1
    const nextWidth = Math.round(rect.width * dpr)
    const nextHeight = Math.round(height * dpr)
    if (canvas.width === nextWidth && canvas.height === nextHeight) return

    let snapshot: string | null = null
    if (hasStrokes && canvas.width > 0 && canvas.height > 0) {
      try {
        snapshot = canvas.toDataURL('image/png')
      } catch {
        snapshot = null
      }
    }

    canvas.width = nextWidth
    canvas.height = nextHeight

    const ctx = canvas.getContext('2d')
    if (!ctx) return
    paintStyle(ctx, dpr)

    if (snapshot) {
      const img = new Image()
      img.onload = () => ctx.drawImage(img, 0, 0, rect.width, height)
      img.src = snapshot
    }
  }, [height, hasStrokes, paintStyle])

  // Initial sizing + keep in step with layout/viewport changes.
  useEffect(() => {
    syncSize()

    const wrapper = wrapperRef.current
    if (!wrapper || typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(() => syncSize())
    observer.observe(wrapper)
    return () => observer.disconnect()
  }, [syncSize])

  // Render an externally supplied signature (e.g. restoring a saved one).
  useEffect(() => {
    const canvas = canvasRef.current
    const wrapper = wrapperRef.current
    if (!canvas || !wrapper || hasStrokes) return

    const ctx = canvas.getContext('2d')
    if (!ctx) return

    if (!value) {
      ctx.save()
      ctx.setTransform(1, 0, 0, 1, 0, 0)
      ctx.clearRect(0, 0, canvas.width, canvas.height)
      ctx.restore()
      setIsEmpty(true)
      return
    }

    const rect = wrapper.getBoundingClientRect()
    const img = new Image()
    img.onload = () => {
      ctx.save()
      ctx.setTransform(1, 0, 0, 1, 0, 0)
      ctx.clearRect(0, 0, canvas.width, canvas.height)
      ctx.restore()
      ctx.drawImage(img, 0, 0, rect.width, height)
      setIsEmpty(false)
    }
    img.src = value
  }, [value, height, hasStrokes])

  const posFromEvent = (event: React.PointerEvent<HTMLCanvasElement>) => {
    const rect = canvasRef.current!.getBoundingClientRect()
    return { x: event.clientX - rect.left, y: event.clientY - rect.top }
  }

  const onPointerDown = (event: React.PointerEvent<HTMLCanvasElement>) => {
    if (disabled) return
    // Stop the browser treating a pen drag as scrolling/selection.
    event.preventDefault()

    const canvas = canvasRef.current
    const ctx = canvas?.getContext('2d')
    if (!canvas || !ctx) return

    canvas.setPointerCapture?.(event.pointerId)
    drawing.current = true

    const pos = posFromEvent(event)
    lastPos.current = pos

    // A tap should leave a mark, not nothing.
    ctx.beginPath()
    ctx.arc(pos.x, pos.y, 1.2, 0, Math.PI * 2)
    ctx.fill()

    setHasStrokes(true)
    setIsEmpty(false)
  }

  const onPointerMove = (event: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawing.current || disabled) return

    const canvas = canvasRef.current
    const ctx = canvas?.getContext('2d')
    if (!canvas || !ctx) return

    const pos = posFromEvent(event)
    const last = lastPos.current ?? pos

    ctx.beginPath()
    ctx.moveTo(last.x, last.y)
    ctx.lineTo(pos.x, pos.y)
    ctx.stroke()
    lastPos.current = pos
  }

  const finishStroke = () => {
    if (!drawing.current) return
    drawing.current = false
    lastPos.current = null

    const canvas = canvasRef.current
    if (!canvas) return
    onChange?.(canvas.toDataURL('image/png'))
  }

  const clear = () => {
    const canvas = canvasRef.current
    const ctx = canvas?.getContext('2d')
    if (!canvas || !ctx) return

    ctx.save()
    ctx.setTransform(1, 0, 0, 1, 0, 0)
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    ctx.restore()

    setHasStrokes(false)
    setIsEmpty(true)
    onChange?.(null)
  }

  const interactive = !disabled

  return (
    <div className={`space-y-2 ${className}`}>
      {label && <p className="text-sm font-medium">{label}</p>}

      <div
        ref={wrapperRef}
        className={`relative overflow-hidden rounded-md border bg-white ${disabled ? 'opacity-60' : ''}`}
        style={{ height }}
      >
        <canvas
          ref={canvasRef}
          className="block h-full w-full touch-none"
          style={{ cursor: interactive ? 'crosshair' : 'default' }}
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={finishStroke}
          onPointerCancel={finishStroke}
          onPointerLeave={finishStroke}
        />
        {isEmpty && interactive && (
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
            <p className="text-sm text-muted-foreground">Sign here</p>
          </div>
        )}
      </div>

      {interactive && (
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>{hint}</span>
          <Button type="button" variant="ghost" size="sm" onClick={clear} disabled={isEmpty}>
            <Icon icon="solar:close-circle-bold" className="mr-1 h-3.5 w-3.5" />
            Clear
          </Button>
        </div>
      )}
    </div>
  )
}

export default SignaturePad
