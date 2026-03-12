import { useMemo, useRef, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import type { WorkOrder, WorkOrderStatus } from '@/lib/fieldServiceApi'

// ── Types ─────────────────────────────────────────────────────────────────────

export interface GanttTechnician {
  id: number
  name: string
  avatar?: string | null
}

export interface GanttWorkOrder extends Pick<WorkOrder,
  'id' | 'reference' | 'title' | 'status' | 'priority' | 'scheduled_start' | 'scheduled_end'
> {
  technicianId: number
}

export interface GanttChartProps {
  technicians: GanttTechnician[]
  workOrders: GanttWorkOrder[]
  dateFrom: Date
  dateTo: Date
  /** Called when a WO bar is dropped onto a new date/technician */
  onReschedule?: (woId: number, techId: number, newStart: Date, newEnd: Date) => void
}

// ── Constants ─────────────────────────────────────────────────────────────────

const ROW_HEIGHT   = 52   // px per technician row
const HEADER_H     = 40   // px for the date header row
const MIN_BAR_W    = 4    // minimum bar width in px
const STATUS_COLORS: Record<WorkOrderStatus, string> = {
  draft:       '#94a3b8',
  scheduled:   '#3b82f6',
  in_progress: '#f59e0b',
  on_hold:     '#ef4444',
  completed:   '#10b981',
  invoiced:    '#8b5cf6',
  cancelled:   '#6b7280',
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function daysBetween(a: Date, b: Date): number {
  return Math.round((b.getTime() - a.getTime()) / 86_400_000)
}

function addDays(d: Date, n: number): Date {
  const r = new Date(d)
  r.setDate(r.getDate() + n)
  return r
}

function formatDay(d: Date): string {
  return d.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric' })
}

function formatMonth(d: Date): string {
  return d.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function GanttChart({
  technicians,
  workOrders,
  dateFrom,
  dateTo,
  onReschedule,
}: GanttChartProps) {
  const containerRef     = useRef<HTMLDivElement>(null)
  const [dragging, setDragging]   = useState<{ woId: number; offsetDays: number } | null>(null)
  const [dragOverCell, setDragOverCell] = useState<{ techId: number; day: number } | null>(null)

  const totalDays = useMemo(() => Math.max(daysBetween(dateFrom, dateTo), 1), [dateFrom, dateTo])

  // Build day labels
  const days = useMemo(
    () => Array.from({ length: totalDays }, (_, i) => addDays(dateFrom, i)),
    [dateFrom, totalDays]
  )

  // Map work orders by technician
  const woByTech = useMemo(() => {
    const map: Record<number, GanttWorkOrder[]> = {}
    for (const wo of workOrders) {
      if (!map[wo.technicianId]) map[wo.technicianId] = []
      map[wo.technicianId].push(wo)
    }
    return map
  }, [workOrders])

  // ── Drag handlers ──────────────────────────────────────────────────────────

  const handleBarDragStart = (wo: GanttWorkOrder, startDay: number, e: React.DragEvent) => {
    if (!onReschedule) return
    const clickDay = Math.floor(e.nativeEvent.offsetX / getCellWidth())
    setDragging({ woId: wo.id, offsetDays: clickDay })
    e.dataTransfer.effectAllowed = 'move'
  }

  const handleCellDragEnter = (techId: number, day: number) => {
    setDragOverCell({ techId, day })
  }

  const handleCellDrop = (techId: number, day: number) => {
    if (!dragging || !onReschedule) return
    const wo = workOrders.find((w) => w.id === dragging.woId)
    if (!wo) return

    const originalDuration = wo.scheduled_end && wo.scheduled_start
      ? daysBetween(new Date(wo.scheduled_start), new Date(wo.scheduled_end))
      : 1

    const newStart = addDays(dateFrom, day - dragging.offsetDays)
    const newEnd   = addDays(newStart, originalDuration)

    onReschedule(wo.id, techId, newStart, newEnd)
    setDragging(null)
    setDragOverCell(null)
  }

  const getCellWidth = (): number => {
    if (!containerRef.current) return 40
    const labelW = 160
    return (containerRef.current.clientWidth - labelW) / totalDays
  }

  // ── Render ─────────────────────────────────────────────────────────────────

  const cellW = `${100 / totalDays}%`

  return (
    <TooltipProvider>
      <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <div ref={containerRef} style={{ minWidth: 600 }}>

          {/* Month / header row */}
          <div className="flex border-b border-gray-100" style={{ height: HEADER_H }}>
            {/* Label spacer */}
            <div className="shrink-0 w-40 border-r border-gray-100 bg-gray-50 flex items-end px-3 pb-1">
              <span className="text-xs font-semibold text-gray-400 uppercase tracking-wide">Technician</span>
            </div>

            {/* Day columns */}
            <div className="flex flex-1">
              {days.map((d, i) => (
                <div
                  key={i}
                  className={`flex-1 border-r border-gray-100 flex flex-col items-center justify-end pb-1
                    ${d.getDay() === 0 || d.getDay() === 6 ? 'bg-gray-50' : ''}
                  `}
                >
                  {i === 0 || d.getDate() === 1 ? (
                    <span className="text-[9px] text-gray-400">{formatMonth(d)}</span>
                  ) : null}
                  <span className={`text-[10px] ${d.toDateString() === new Date().toDateString() ? 'font-bold text-blue-600' : 'text-gray-500'}`}>
                    {formatDay(d)}
                  </span>
                </div>
              ))}
            </div>
          </div>

          {/* Technician rows */}
          {technicians.map((tech) => {
            const tWos = woByTech[tech.id] ?? []

            return (
              <div
                key={tech.id}
                className="flex border-b border-gray-100 last:border-b-0"
                style={{ height: ROW_HEIGHT }}
              >
                {/* Label */}
                <div className="shrink-0 w-40 border-r border-gray-100 bg-gray-50 flex items-center px-3 gap-2">
                  <div className="h-7 w-7 rounded-full bg-gradient-to-br from-blue-400 to-indigo-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                    {tech.name.charAt(0).toUpperCase()}
                  </div>
                  <span className="text-xs font-medium text-gray-700 truncate">{tech.name}</span>
                </div>

                {/* Grid + bars */}
                <div
                  className="relative flex flex-1"
                  onDragOver={(e) => e.preventDefault()}
                >
                  {/* Background cells (drop targets) */}
                  {days.map((d, dayIdx) => (
                    <div
                      key={dayIdx}
                      className={`flex-1 border-r border-gray-50 h-full
                        ${d.getDay() === 0 || d.getDay() === 6 ? 'bg-gray-50/70' : ''}
                        ${dragOverCell?.techId === tech.id && dragOverCell?.day === dayIdx ? 'bg-blue-50' : ''}
                      `}
                      onDragEnter={() => handleCellDragEnter(tech.id, dayIdx)}
                      onDrop={() => handleCellDrop(tech.id, dayIdx)}
                      onDragOver={(e) => e.preventDefault()}
                    />
                  ))}

                  {/* WO Bars */}
                  {tWos.map((wo) => {
                    if (!wo.scheduled_start) return null

                    const start   = new Date(wo.scheduled_start)
                    const end     = wo.scheduled_end ? new Date(wo.scheduled_end) : addDays(start, 1)
                    const startDay = Math.max(0, daysBetween(dateFrom, start))
                    const endDay   = Math.min(totalDays, daysBetween(dateFrom, end))
                    const spanDays = Math.max(endDay - startDay, 0.5)

                    if (startDay >= totalDays || endDay <= 0) return null

                    const left  = `${(startDay / totalDays) * 100}%`
                    const width = `calc(${(spanDays / totalDays) * 100}% - 2px)`
                    const color = STATUS_COLORS[wo.status] ?? '#94a3b8'

                    return (
                      <Tooltip key={wo.id}>
                        <TooltipTrigger asChild>
                          <div
                            draggable={!!onReschedule}
                            onDragStart={(e) => handleBarDragStart(wo, startDay, e)}
                            style={{
                              position: 'absolute',
                              top: 8,
                              height: ROW_HEIGHT - 16,
                              left,
                              width,
                              backgroundColor: color,
                              minWidth: MIN_BAR_W,
                            }}
                            className="rounded cursor-pointer flex items-center px-1.5 overflow-hidden
                              shadow-sm hover:brightness-110 active:brightness-90 transition-all select-none"
                          >
                            <span className="text-white text-[10px] font-medium truncate leading-tight">
                              {wo.reference}
                            </span>
                          </div>
                        </TooltipTrigger>
                        <TooltipContent side="top" className="text-xs max-w-xs">
                          <div className="font-semibold">{wo.title}</div>
                          <div className="text-gray-400">{wo.reference}</div>
                          <div className="mt-1">
                            <Badge style={{ backgroundColor: color, color: '#fff', border: 'none' }} className="text-[10px]">
                              {wo.status.replace('_', ' ')}
                            </Badge>
                          </div>
                          {wo.scheduled_start && (
                            <div className="mt-1 text-gray-500">
                              {new Date(wo.scheduled_start).toLocaleDateString()} →{' '}
                              {wo.scheduled_end ? new Date(wo.scheduled_end).toLocaleDateString() : '?'}
                            </div>
                          )}
                        </TooltipContent>
                      </Tooltip>
                    )
                  })}
                </div>
              </div>
            )
          })}

          {technicians.length === 0 && (
            <div className="p-8 text-center text-sm text-gray-400">
              No technicians found for the selected team/date range.
            </div>
          )}
        </div>
      </div>
    </TooltipProvider>
  )
}
