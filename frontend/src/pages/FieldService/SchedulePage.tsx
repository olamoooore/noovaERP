import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { WorkOrderStatusBadge } from '@/components/field-service/WorkOrderStatusBadge'
import { WorkOrderPriorityIcon } from '@/components/field-service/WorkOrderPriorityIcon'
import fieldServiceApi from '@/lib/fieldServiceApi'

function getWeekDays(date: Date): Date[] {
  const start = new Date(date)
  start.setDate(start.getDate() - start.getDay() + 1) // Monday
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(start)
    d.setDate(start.getDate() + i)
    return d
  })
}

function isSameDay(a: Date, b: Date) {
  return a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
}

export function SchedulePage() {
  const [currentDate, setCurrentDate] = useState(new Date())
  const [view, setView] = useState<'week' | 'month'>('week')

  const weekDays = getWeekDays(currentDate)
  const from = weekDays[0].toISOString().slice(0, 10)
  const to = weekDays[6].toISOString().slice(0, 10)

  const { data: events = [], isLoading } = useQuery({
    queryKey: ['fs-calendar', from, to],
    queryFn: () => fieldServiceApi.getCalendar(from, to).then(r => r.data),
  })

  const prevWeek = () => {
    const d = new Date(currentDate)
    d.setDate(d.getDate() - 7)
    setCurrentDate(d)
  }

  const nextWeek = () => {
    const d = new Date(currentDate)
    d.setDate(d.getDate() + 7)
    setCurrentDate(d)
  }

  const todayLabel = new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric' }).format(currentDate)

  return (
    <div className="p-6 space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <h1 className="text-xl font-bold">Schedule</h1>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={prevWeek}>
            <Icon icon="solar:arrow-left-bold" width={16} />
          </Button>
          <span className="text-sm font-medium min-w-[140px] text-center">{todayLabel}</span>
          <Button variant="outline" size="sm" onClick={nextWeek}>
            <Icon icon="solar:arrow-right-bold" width={16} />
          </Button>
          <Button variant="outline" size="sm" onClick={() => setCurrentDate(new Date())}>Today</Button>
          <Button asChild size="sm">
            <Link to="/field-service/work-orders/new">
              <Icon icon="solar:add-circle-bold-duotone" width={16} className="mr-1" />
              New
            </Link>
          </Button>
        </div>
      </div>

      {/* Week calendar grid */}
      <div className="overflow-x-auto">
        <div className="grid grid-cols-7 gap-px bg-border rounded-lg overflow-hidden min-w-[700px]">
          {weekDays.map(day => {
            const isToday = isSameDay(day, new Date())
            const dayEvents = (events as any[]).filter(e => {
              if (!e.scheduled_start) return false
              return isSameDay(new Date(e.scheduled_start), day)
            })

            return (
              <div key={day.toISOString()} className="bg-background flex flex-col min-h-[280px]">
                {/* Day header */}
                <div className={`px-2 py-2 text-center border-b ${isToday ? 'bg-primary/10' : ''}`}>
                  <p className="text-xs text-muted-foreground uppercase">
                    {new Intl.DateTimeFormat('en-GB', { weekday: 'short' }).format(day)}
                  </p>
                  <p className={`text-lg font-semibold leading-none mt-0.5 ${isToday ? 'text-primary' : ''}`}>
                    {day.getDate()}
                  </p>
                </div>

                {/* Events */}
                <div className="flex-1 p-1 space-y-1 overflow-y-auto">
                  {isLoading && <div className="h-6 bg-muted animate-pulse rounded" />}
                  {dayEvents.map((event: any) => (
                    <Link
                      key={event.id}
                      to={`/field-service/work-orders/${event.id}`}
                      className="block"
                    >
                      <div className="rounded px-1.5 py-1 text-xs bg-primary/10 hover:bg-primary/20 transition-colors border border-primary/20 space-y-0.5">
                        <div className="flex items-center gap-1">
                          <WorkOrderPriorityIcon priority={event.priority} size={10} />
                          <span className="font-medium truncate">{event.title}</span>
                        </div>
                        <WorkOrderStatusBadge status={event.status} size="sm" />
                      </div>
                    </Link>
                  ))}
                </div>
              </div>
            )
          })}
        </div>
      </div>

      {/* Unscheduled work orders */}
      <div>
        <h2 className="text-sm font-semibold text-muted-foreground mb-2">Unscheduled this week</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
          {(events as any[])
            .filter(e => !e.scheduled_start)
            .map((event: any) => (
              <Card key={event.id} className="border border-dashed border-border/60">
                <CardContent className="p-3 flex items-center gap-2">
                  <WorkOrderPriorityIcon priority={event.priority} size={14} />
                  <div className="flex-1 min-w-0">
                    <Link to={`/field-service/work-orders/${event.id}`} className="text-sm font-medium hover:underline truncate block">
                      {event.title}
                    </Link>
                    <p className="text-xs text-muted-foreground">{event.reference}</p>
                  </div>
                  <WorkOrderStatusBadge status={event.status} size="sm" />
                </CardContent>
              </Card>
            ))}
          {!isLoading && (events as any[]).filter(e => !e.scheduled_start).length === 0 && (
            <p className="text-sm text-muted-foreground col-span-3">All work orders are scheduled.</p>
          )}
        </div>
      </div>
    </div>
  )
}
