import { useEffect, useMemo, useRef, useState } from 'react'
import gsap from 'gsap'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'

type AnimatedDropzoneProps = {
  label: string
  description?: string
  accept?: string
  multiple?: boolean
  disabled?: boolean
  onPickFiles: (files: File[]) => void
}

export function AnimatedDropzone({
  label,
  description,
  accept,
  multiple,
  disabled,
  onPickFiles,
}: AnimatedDropzoneProps) {
  const rootRef = useRef<HTMLDivElement | null>(null)
  const inputRef = useRef<HTMLInputElement | null>(null)
  const [isOver, setIsOver] = useState(false)
  const [lastPickedCount, setLastPickedCount] = useState(0)

  const helperText = useMemo(() => {
    if (disabled) return 'Disabled'
    if (lastPickedCount > 0) return `Picked ${lastPickedCount} file${lastPickedCount === 1 ? '' : 's'}`
    return 'Drop files here'
  }, [disabled, lastPickedCount])

  useEffect(() => {
    const el = rootRef.current
    if (!el) return

    gsap.set(el, { scale: 1 })

    if (isOver) {
      gsap.to(el, { scale: 1.01, duration: 0.18, ease: 'power2.out' })
    } else {
      gsap.to(el, { scale: 1, duration: 0.2, ease: 'power2.out' })
    }
  }, [isOver])

  const openPicker = () => {
    if (disabled) return
    inputRef.current?.click()
  }

  const handleFiles = (files: File[]) => {
    if (disabled) return
    if (!files.length) return
    setLastPickedCount(files.length)
    onPickFiles(files)
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <div>
          <div className="text-sm font-medium">{label}</div>
          {description ? <div className="text-xs text-muted-foreground">{description}</div> : null}
        </div>
      </div>

      <div
        ref={rootRef}
        className={cn(
          'relative rounded-md border bg-card p-4 transition-colors',
          'flex items-center justify-between gap-4',
          isOver ? 'ring-1 ring-ring border-ring' : 'hover:bg-muted/40',
          disabled ? 'opacity-60 pointer-events-none' : ''
        )}
        onDragEnter={(e) => {
          e.preventDefault()
          e.stopPropagation()
          setIsOver(true)
        }}
        onDragOver={(e) => {
          e.preventDefault()
          e.stopPropagation()
          setIsOver(true)
        }}
        onDragLeave={(e) => {
          e.preventDefault()
          e.stopPropagation()
          setIsOver(false)
        }}
        onDrop={(e) => {
          e.preventDefault()
          e.stopPropagation()
          setIsOver(false)
          const files = Array.from(e.dataTransfer.files || [])
          handleFiles(files)
        }}
        onClick={openPicker}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault()
            openPicker()
          }
        }}
      >
        <div className="flex items-center gap-3">
          <div className={cn('rounded-md border p-2', isOver ? 'bg-muted' : 'bg-background')}>
            <Icon icon={multiple ? 'solar:gallery-bold' : 'solar:camera-bold'} className="h-5 w-5" />
          </div>
          <div>
            <div className="text-sm">{helperText}</div>
            <div className="text-xs text-muted-foreground">Click to select • Drag and drop supported</div>
          </div>
        </div>

        <input
          ref={inputRef}
          type="file"
          accept={accept}
          multiple={multiple}
          className="hidden"
          disabled={disabled}
          onChange={(e) => {
            const files = Array.from(e.target.files || [])
            handleFiles(files)
            e.currentTarget.value = ''
          }}
        />
      </div>
    </div>
  )
}
