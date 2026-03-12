import { useRef, useEffect, useCallback, useState } from 'react'
import { Button } from '@/components/ui/button'

interface SignaturePadProps {
  value?: string | null          // base64 PNG or null
  onChange?: (base64: string | null) => void
  label?: string
  disabled?: boolean
  width?: number
  height?: number
}

export default function SignaturePad({
  value,
  onChange,
  label,
  disabled = false,
  width = 400,
  height = 150,
}: SignaturePadProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const drawing   = useRef(false)
  const lastPos   = useRef<{ x: number; y: number } | null>(null)
  const [isEmpty, setIsEmpty] = useState(!value)

  // Render the saved signature when value changes externally
  useEffect(() => {
    if (!canvasRef.current) return
    const ctx = canvasRef.current.getContext('2d')!
    ctx.clearRect(0, 0, width, height)
    if (value) {
      const img = new Image()
      img.onload = () => ctx.drawImage(img, 0, 0)
      img.src = value
      setIsEmpty(false)
    } else {
      setIsEmpty(true)
    }
  }, [value, width, height])

  const getPos = (e: React.MouseEvent | React.TouchEvent): { x: number; y: number } => {
    const canvas = canvasRef.current!
    const rect   = canvas.getBoundingClientRect()
    const scaleX = canvas.width  / rect.width
    const scaleY = canvas.height / rect.height
    if ('touches' in e) {
      return {
        x: (e.touches[0].clientX - rect.left) * scaleX,
        y: (e.touches[0].clientY - rect.top)  * scaleY,
      }
    }
    return {
      x: (e.clientX - rect.left) * scaleX,
      y: (e.clientY - rect.top)  * scaleY,
    }
  }

  const startDraw = useCallback((e: React.MouseEvent | React.TouchEvent) => {
    if (disabled) return
    e.preventDefault()
    drawing.current = true
    lastPos.current = getPos(e)
  }, [disabled])

  const draw = useCallback((e: React.MouseEvent | React.TouchEvent) => {
    if (!drawing.current || disabled) return
    e.preventDefault()
    const canvas = canvasRef.current!
    const ctx    = canvas.getContext('2d')!
    const pos    = getPos(e)

    ctx.beginPath()
    ctx.moveTo(lastPos.current!.x, lastPos.current!.y)
    ctx.lineTo(pos.x, pos.y)
    ctx.strokeStyle = '#1f2937'
    ctx.lineWidth   = 2
    ctx.lineCap     = 'round'
    ctx.lineJoin    = 'round'
    ctx.stroke()

    lastPos.current = pos
    setIsEmpty(false)
  }, [disabled])

  const endDraw = useCallback(() => {
    if (!drawing.current) return
    drawing.current = false
    lastPos.current = null

    // Emit the base64 representation
    const canvas = canvasRef.current!
    onChange?.(canvas.toDataURL('image/png'))
  }, [onChange])

  const clear = useCallback(() => {
    const canvas = canvasRef.current!
    const ctx    = canvas.getContext('2d')!
    ctx.clearRect(0, 0, width, height)
    setIsEmpty(true)
    onChange?.(null)
  }, [onChange, width, height])

  return (
    <div className="space-y-2">
      {label && <p className="text-sm font-medium text-gray-700">{label}</p>}

      <div
        className="relative rounded-lg border border-gray-300 bg-gray-50 overflow-hidden"
        style={{ width: '100%', maxWidth: width }}
      >
        <canvas
          ref={canvasRef}
          width={width}
          height={height}
          className="w-full touch-none cursor-crosshair block"
          style={{ height: height }}
          onMouseDown={startDraw}
          onMouseMove={draw}
          onMouseUp={endDraw}
          onMouseLeave={endDraw}
          onTouchStart={startDraw}
          onTouchMove={draw}
          onTouchEnd={endDraw}
        />
        {isEmpty && !disabled && (
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
            <p className="text-sm text-gray-400">Sign here</p>
          </div>
        )}
      </div>

      {!disabled && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={clear}
          disabled={isEmpty}
        >
          Clear signature
        </Button>
      )}
    </div>
  )
}
