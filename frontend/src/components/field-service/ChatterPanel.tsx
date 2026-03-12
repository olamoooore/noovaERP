import { useState, useRef, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/hooks/use-toast'
import { fieldServiceApi, type WorkOrderActivity } from '@/lib/fieldServiceApi'

const activityIcons: Record<string, string> = {
  note:          'solar:chat-round-dots-bold-duotone',
  status_change: 'solar:refresh-circle-bold-duotone',
  assignment:    'solar:user-check-bold-duotone',
  creation:      'solar:add-circle-bold-duotone',
  update:        'solar:pen-bold-duotone',
  invoice:       'solar:bill-list-bold-duotone',
}

const activityColors: Record<string, string> = {
  note:          'bg-blue-100 text-blue-600',
  status_change: 'bg-amber-100 text-amber-600',
  assignment:    'bg-purple-100 text-purple-600',
  creation:      'bg-green-100 text-green-600',
  update:        'bg-slate-100 text-slate-600',
  invoice:       'bg-emerald-100 text-emerald-600',
}

interface Props {
  workOrderId: number
  activities: WorkOrderActivity[]
}

export function ChatterPanel({ workOrderId, activities }: Props) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [note, setNote] = useState('')
  const bottomRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [activities.length])

  const addNote = useMutation({
    mutationFn: (text: string) => fieldServiceApi.addNote(workOrderId, text),
    onSuccess: () => {
      setNote('')
      queryClient.invalidateQueries({ queryKey: ['work-order', workOrderId] })
    },
    onError: () => toast({ title: 'Failed to add note', variant: 'destructive' }),
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (note.trim()) addNote.mutate(note.trim())
  }

  return (
    <div className="flex flex-col gap-4">
      {/* Activity feed */}
      <div className="space-y-3 max-h-96 overflow-y-auto pr-1">
        {activities.length === 0 && (
          <p className="text-sm text-muted-foreground text-center py-4">No activity yet.</p>
        )}
        {activities.map(activity => {
          const icon = activityIcons[activity.type] ?? activityIcons.note
          const color = activityColors[activity.type] ?? activityColors.note
          const date = new Date(activity.created_at).toLocaleString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
          })
          return (
            <div key={activity.id} className="flex gap-3">
              <div className={`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center ${color}`}>
                <Icon icon={icon} width={16} />
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-sm font-medium">{activity.user?.name ?? 'System'}</span>
                  <span className="text-xs text-muted-foreground">{date}</span>
                </div>
                <p className="text-sm text-foreground/80 mt-0.5 whitespace-pre-wrap">{activity.description}</p>
              </div>
            </div>
          )
        })}
        <div ref={bottomRef} />
      </div>

      {/* Add note form */}
      <form onSubmit={handleSubmit} className="flex flex-col gap-2">
        <Textarea
          placeholder="Add a note..."
          value={note}
          onChange={e => setNote(e.target.value)}
          rows={3}
          className="resize-none"
        />
        <div className="flex justify-end">
          <Button type="submit" size="sm" disabled={!note.trim() || addNote.isPending}>
            <Icon icon="solar:send-bold-duotone" width={16} className="mr-1" />
            {addNote.isPending ? 'Sending...' : 'Add Note'}
          </Button>
        </div>
      </form>
    </div>
  )
}
