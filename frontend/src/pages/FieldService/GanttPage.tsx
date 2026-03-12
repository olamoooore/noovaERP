import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import GanttChart, { type GanttWorkOrder, type GanttTechnician } from '@/components/field-service/GanttChart'
import { fieldServiceApi } from '@/lib/fieldServiceApi'
import { ChevronLeft, ChevronRight, CalendarDays } from 'lucide-react'

// ── Helpers ───────────────────────────────────────────────────────────────────

function addDays(d: Date, n: number): Date {
  const r = new Date(d); r.setDate(r.getDate() + n); return r
}
function toISO(d: Date): string {
  return d.toISOString().split('T')[0]
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function GanttPage() {
  const queryClient = useQueryClient()
  const navigate    = useNavigate()

  const [dateFrom, setDateFrom] = useState<Date>(() => {
    const d = new Date(); d.setDate(d.getDate() - d.getDay()); return d  // Start of week
  })
  const [span, setSpan]       = useState<14 | 28 | 7>(14)
  const [teamId, setTeamId]   = useState<string>('all')

  const dateTo = useMemo(() => addDays(dateFrom, span), [dateFrom, span])

  // Queries
  const { data: ganttData, isLoading: ganttLoading } = useQuery({
    queryKey: ['fs-gantt', toISO(dateFrom), toISO(dateTo)],
    queryFn: () =>
      fieldServiceApi.getGantt(toISO(dateFrom), toISO(dateTo)).then((r) => r.data),
  })

  const { data: teams } = useQuery({
    queryKey: ['fs-teams'],
    queryFn: () => fieldServiceApi.getTeams().then((r) => r.data),
  })

  const { data: technicianList } = useQuery({
    queryKey: ['fs-technicians'],
    queryFn: () => fieldServiceApi.getTechnicians().then((r) => r.data),
  })

  // Reschedule mutation
  const rescheduleMutation = useMutation({
    mutationFn: ({ woId, newStart, newEnd }: { woId: number; techId: number; newStart: Date; newEnd: Date }) =>
      fieldServiceApi.updateWorkOrder(woId, {
        scheduled_start: newStart.toISOString(),
        scheduled_end:   newEnd.toISOString(),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fs-gantt'] })
    },
  })

  // ── Build chart data ───────────────────────────────────────────────────────

  const technicians = useMemo((): GanttTechnician[] => {
    const list = technicianList ?? []
    if (teamId === 'all') return list.map((t) => ({ id: t.id, name: t.user?.name ?? 'Unknown', avatar: t.user?.avatar }))
    return list
      .filter((t) => String(t.team_id) === teamId)
      .map((t) => ({ id: t.id, name: t.user?.name ?? 'Unknown', avatar: t.user?.avatar }))
  }, [technicianList, teamId])

  const ganttWOs = useMemo((): GanttWorkOrder[] => {
    const items = (ganttData as any)?.work_orders ?? []
    return items.flatMap((wo: any) => {
      const assignedTechs: number[] = (wo.technicians ?? []).map((t: any) => t.id)
      if (assignedTechs.length === 0) return []
      return assignedTechs.map((techId: number) => ({
        id:              wo.id,
        reference:       wo.reference,
        title:           wo.title,
        status:          wo.status,
        priority:        wo.priority,
        scheduled_start: wo.scheduled_start,
        scheduled_end:   wo.scheduled_end,
        technicianId:    techId,
      }))
    })
  }, [ganttData])

  // ── Navigation ─────────────────────────────────────────────────────────────

  const prev = () => setDateFrom((d) => addDays(d, -span))
  const next = () => setDateFrom((d) => addDays(d,  span))
  const today = () => {
    const d = new Date(); d.setDate(d.getDate() - d.getDay()); setDateFrom(d)
  }

  return (
    <div className="space-y-5 p-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Gantt Scheduler</h1>
          <p className="text-sm text-gray-500">
            {dateFrom.toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}
            {' – '}
            {dateTo.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* Team filter */}
          <Select value={teamId} onValueChange={setTeamId}>
            <SelectTrigger className="w-36 h-8 text-sm">
              <SelectValue placeholder="All teams" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All teams</SelectItem>
              {(teams ?? []).map((t) => (
                <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>

          {/* Span selector */}
          <Select value={String(span)} onValueChange={(v) => setSpan(Number(v) as 7 | 14 | 28)}>
            <SelectTrigger className="w-24 h-8 text-sm">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="7">1 week</SelectItem>
              <SelectItem value="14">2 weeks</SelectItem>
              <SelectItem value="28">4 weeks</SelectItem>
            </SelectContent>
          </Select>

          {/* Navigation */}
          <div className="flex items-center gap-1 rounded-md border border-gray-200 bg-white overflow-hidden">
            <Button variant="ghost" size="icon" className="h-8 w-8 rounded-none" onClick={prev}>
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <Button variant="ghost" size="sm" className="h-8 rounded-none px-2 text-xs" onClick={today}>
              <CalendarDays className="mr-1 h-3.5 w-3.5" />
              Today
            </Button>
            <Button variant="ghost" size="icon" className="h-8 w-8 rounded-none" onClick={next}>
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </div>

      {/* Gantt */}
      {ganttLoading ? (
        <Card><CardContent className="p-6"><Skeleton className="h-64 w-full" /></CardContent></Card>
      ) : (
        <GanttChart
          technicians={technicians}
          workOrders={ganttWOs}
          dateFrom={dateFrom}
          dateTo={dateTo}
          onReschedule={(woId, techId, newStart, newEnd) =>
            rescheduleMutation.mutate({ woId, techId, newStart, newEnd })
          }
        />
      )}

      {/* Conflict warning */}
      {detectConflicts(ganttWOs).length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          ⚠️ <strong>Scheduling conflict detected</strong> — some technicians have overlapping work orders in this period.
        </div>
      )}
    </div>
  )
}

// ── Conflict detection ────────────────────────────────────────────────────────

function detectConflicts(wos: GanttWorkOrder[]): [GanttWorkOrder, GanttWorkOrder][] {
  const conflicts: [GanttWorkOrder, GanttWorkOrder][] = []
  const byTech: Record<number, GanttWorkOrder[]> = {}
  for (const wo of wos) {
    if (!byTech[wo.technicianId]) byTech[wo.technicianId] = []
    byTech[wo.technicianId].push(wo)
  }
  for (const list of Object.values(byTech)) {
    for (let i = 0; i < list.length; i++) {
      for (let j = i + 1; j < list.length; j++) {
        const a = list[i], b = list[j]
        if (!a.scheduled_start || !b.scheduled_start) continue
        const aStart = new Date(a.scheduled_start).getTime()
        const aEnd   = a.scheduled_end ? new Date(a.scheduled_end).getTime() : aStart + 3_600_000
        const bStart = new Date(b.scheduled_start).getTime()
        const bEnd   = b.scheduled_end ? new Date(b.scheduled_end).getTime() : bStart + 3_600_000
        if (aStart < bEnd && aEnd > bStart) conflicts.push([a, b])
      }
    }
  }
  return conflicts
}
