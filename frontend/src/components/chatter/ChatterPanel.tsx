import React, { useState, useRef, useEffect, useMemo } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { useToast } from '@/hooks/use-toast'
import chatterApi, {
  type ActivityType,
  type ChatterActivity,
  type ChatterBundle,
  type ChatterLogEntry,
  type ChatterAttachment,
  type ChatterMessageEntry,
} from '@/lib/chatterApi'

// ─── Constants ──────────────────────────────────────────────────────────────

const ACTIVITY_TYPES: { value: ActivityType; label: string; icon: string }[] = [
  { value: 'follow_up', label: 'Follow-up', icon: 'solar:round-arrow-right-bold-duotone' },
  { value: 'call', label: 'Call', icon: 'solar:phone-calling-bold-duotone' },
  { value: 'meeting', label: 'Meeting', icon: 'solar:users-group-two-rounded-bold-duotone' },
  { value: 'todo', label: 'To-do', icon: 'solar:checklist-bold-duotone' },
  { value: 'reminder', label: 'Reminder', icon: 'solar:bell-bold-duotone' },
]

const eventIcons: Record<string, string> = {
  created: 'solar:add-circle-bold-duotone',
  updated: 'solar:pen-bold-duotone',
  status_changed: 'solar:refresh-circle-bold-duotone',
  posted: 'solar:check-circle-bold-duotone',
  paid: 'solar:bill-list-bold-duotone',
  cancelled: 'solar:close-circle-bold-duotone',
  reversed: 'solar:undo-left-round-bold-duotone',
  attachment_uploaded: 'solar:paperclip-bold-duotone',
  attachment_deleted: 'solar:trash-bin-trash-bold-duotone',
  message: 'solar:chat-round-dots-bold-duotone',
  activity_scheduled: 'solar:calendar-add-bold-duotone',
  activity_done: 'solar:calendar-check-bold-duotone',
  note: 'solar:notes-bold-duotone',
}

const eventColors: Record<string, string> = {
  created: 'bg-green-100 text-green-700',
  updated: 'bg-slate-100 text-slate-600',
  status_changed: 'bg-amber-100 text-amber-700',
  posted: 'bg-emerald-100 text-emerald-700',
  paid: 'bg-emerald-100 text-emerald-700',
  cancelled: 'bg-red-100 text-red-700',
  reversed: 'bg-orange-100 text-orange-700',
  attachment_uploaded: 'bg-indigo-100 text-indigo-700',
  attachment_deleted: 'bg-rose-100 text-rose-700',
  message: 'bg-blue-100 text-blue-700',
  activity_scheduled: 'bg-purple-100 text-purple-700',
  activity_done: 'bg-emerald-100 text-emerald-700',
  note: 'bg-blue-100 text-blue-700',
}

const activityTypeIcons: Record<ActivityType, string> = {
  follow_up: 'solar:round-arrow-right-bold-duotone',
  call: 'solar:phone-calling-bold-duotone',
  meeting: 'solar:users-group-two-rounded-bold-duotone',
  todo: 'solar:checklist-bold-duotone',
  reminder: 'solar:bell-bold-duotone',
}

const activityTypeColors: Record<ActivityType, string> = {
  follow_up: 'bg-blue-100 text-blue-700',
  call: 'bg-emerald-100 text-emerald-700',
  meeting: 'bg-purple-100 text-purple-700',
  todo: 'bg-amber-100 text-amber-700',
  reminder: 'bg-rose-100 text-rose-700',
}

const formatDateTime = (iso?: string | null): string => {
  if (!iso) return ''
  return new Date(iso).toLocaleString('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

const formatBytes = (bytes: number): string => {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

// ─── Props ──────────────────────────────────────────────────────────────────

interface Props {
  /** Fully-qualified class name of the subject model (e.g. App\Models\Invoice) */
  subjectType: string
  /** Numeric/string subject id */
  subjectId: number | string
  /** Display name of the document (shown in the panel header) */
  documentLabel?: string
  /** When true the panel hides the log-message composer (read-only context) */
  readOnly?: boolean
  /** Optional className applied to the outer wrapper */
  className?: string
}

// ─── Component ──────────────────────────────────────────────────────────────

export function ChatterPanel({
  subjectType,
  subjectId,
  documentLabel,
  readOnly = false,
  className = '',
}: Props) {
  const queryKey = useMemo(
    () => ['chatter', subjectType, subjectId],
    [subjectType, subjectId]
  )
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [tab, setTab] = useState<'activity' | 'messages' | 'attachments' | 'log'>('activity')
  const [message, setMessage] = useState('')
  const fileInputRef = useRef<HTMLInputElement | null>(null)
  const feedRef = useRef<HTMLDivElement | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey,
    queryFn: () => chatterApi.list(subjectType, subjectId),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey })

  const sendMessage = useMutation({
    mutationFn: (body: string) => chatterApi.addMessage(subjectType, subjectId, { body }),
    onSuccess: () => {
      setMessage('')
      invalidate()
    },
    onError: () => toast({ title: 'Failed to send message', variant: 'destructive' }),
  })

  const uploadAttachment = useMutation({
    mutationFn: (file: File) => chatterApi.uploadAttachment(subjectType, subjectId, file),
    onSuccess: () => {
      invalidate()
      if (fileInputRef.current) fileInputRef.current.value = ''
    },
    onError: () => toast({ title: 'Failed to upload attachment', variant: 'destructive' }),
  })

  const deleteAttachment = useMutation({
    mutationFn: (id: number | string) =>
      chatterApi.deleteAttachment(subjectType, subjectId, id),
    onSuccess: invalidate,
    onError: () => toast({ title: 'Failed to delete attachment', variant: 'destructive' }),
  })

  const markDone = useMutation({
    mutationFn: (id: number | string) =>
      chatterApi.markActivityDone(subjectType, subjectId, id),
    onSuccess: invalidate,
    onError: () => toast({ title: 'Failed to update activity', variant: 'destructive' }),
  })

  useEffect(() => {
    if (tab === 'log' && feedRef.current) {
      feedRef.current.scrollTop = feedRef.current.scrollHeight
    }
  }, [tab, data?.logs.length])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const trimmed = message.trim()
    if (!trimmed || sendMessage.isPending) return
    sendMessage.mutate(trimmed)
  }

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    if (file.size > 25 * 1024 * 1024) {
      toast({ title: 'File too large', description: 'Max 25 MB.', variant: 'destructive' })
      return
    }
    uploadAttachment.mutate(file)
  }

  if (error) {
    return (
      <div className={`rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive ${className}`}>
        Failed to load chatter. Refresh the page to retry.
      </div>
    )
  }

  return (
    <div className={`flex h-full flex-col rounded-md border bg-card ${className}`}>
      {/* Header */}
      <div className="flex items-center justify-between border-b px-4 py-3">
        <div className="flex items-center gap-2">
          <Icon icon="solar:chat-round-dots-bold-duotone" className="h-5 w-5 text-primary" />
          <h3 className="text-sm font-semibold">Chatter</h3>
          {documentLabel && (
            <span className="text-xs text-muted-foreground">— {documentLabel}</span>
          )}
        </div>
        {isLoading && <span className="text-xs text-muted-foreground">Loading…</span>}
      </div>

      {/* Tabs */}
      <div className="flex gap-1 border-b px-2 py-2">
        {([
          { id: 'activity', label: 'Activities', count: data?.activities.length ?? 0 },
          { id: 'messages', label: 'Messages', count: data?.messages.length ?? 0 },
          { id: 'attachments', label: 'Attachments', count: data?.attachments.length ?? 0 },
          { id: 'log', label: 'History', count: data?.logs.length ?? 0 },
        ] as const).map(({ id, label, count }) => (
          <button
            key={id}
            onClick={() => setTab(id)}
            className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition ${
              tab === id
                ? 'bg-primary/10 text-primary'
                : 'text-muted-foreground hover:bg-muted'
            }`}
          >
            {label}
            <span className="rounded-full bg-muted px-1.5 text-[10px]">{count}</span>
          </button>
        ))}
      </div>

      {/* Body */}
      <div className="flex-1 overflow-y-auto px-4 py-3">
        {tab === 'activity' && (
          <ActivitiesTab
            activities={data?.activities ?? []}
            readOnly={readOnly}
            onSubmit={(payload) =>
              chatterApi.addActivity(subjectType, subjectId, payload).then(invalidate)
            }
            onMarkDone={(id) => markDone.mutate(id)}
            onError={(msg) => toast({ title: msg, variant: 'destructive' })}
          />
        )}

        {tab === 'messages' && (
          <div className="flex flex-col gap-3">
            {(data?.messages ?? []).map((msg) => (
              <MessageItem key={String(msg.id)} entry={msg} />
            ))}
            {(data?.messages ?? []).length === 0 && (
              <EmptyHint text="No messages yet. Post the first one below." />
            )}
          </div>
        )}

        {tab === 'attachments' && (
          <div className="flex flex-col gap-2">
            {(data?.attachments ?? []).map((att) => (
              <AttachmentItem
                key={att.id}
                attachment={att}
                readOnly={readOnly}
                onDelete={() => deleteAttachment.mutate(att.id)}
              />
            ))}
            {(data?.attachments ?? []).length === 0 && (
              <EmptyHint text="No attachments yet." />
            )}
          </div>
        )}

        {tab === 'log' && (
          <div ref={feedRef} className="flex flex-col gap-3">
            {(data?.logs ?? []).map((entry) => (
              <LogItem key={entry.id} entry={entry} />
            ))}
            {(data?.logs ?? []).length === 0 && (
              <EmptyHint text="No history events yet." />
            )}
          </div>
        )}
      </div>

      {/* Footer — message composer (only on Messages tab) */}
      {tab === 'messages' && !readOnly && (
        <form onSubmit={handleSubmit} className="border-t p-3">
          <Textarea
            placeholder="Write a message…"
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            rows={3}
            className="resize-none"
          />
          <div className="mt-2 flex justify-end">
            <Button type="submit" size="sm" disabled={!message.trim() || sendMessage.isPending}>
              <Icon icon="solar:send-square-bold" width={16} className="mr-1" />
              {sendMessage.isPending ? 'Sending…' : 'Send'}
            </Button>
          </div>
        </form>
      )}

      {/* Footer — attachment uploader (only on Attachments tab) */}
      {tab === 'attachments' && !readOnly && (
        <div className="border-t p-3">
          <input
            ref={fileInputRef}
            type="file"
            className="hidden"
            onChange={handleFileSelect}
          />
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={uploadAttachment.isPending}
            onClick={() => fileInputRef.current?.click()}
            className="w-full"
          >
            <Icon icon="solar:upload-square-bold" width={16} className="mr-2" />
            {uploadAttachment.isPending ? 'Uploading…' : 'Upload attachment (max 25 MB)'}
          </Button>
        </div>
      )}
    </div>
  )
}

// ─── Sub-components ─────────────────────────────────────────────────────────

function EmptyHint({ text }: { text: string }) {
  return <p className="text-sm text-muted-foreground text-center py-6">{text}</p>
}

function LogItem({ entry }: { entry: ChatterLogEntry }) {
  const icon = eventIcons[entry.event] ?? eventIcons.updated
  const color = eventColors[entry.event] ?? eventColors.updated

  // Internal IDs (message_id, attachment_id, etc.) and the activity_type
  // are useful to the backend but not to a human reading the log.
  // Filter them out so the log row shows just the description + a clean
  // summary of any user-meaningful properties (filename, status, due_date).
  const INTERNAL_KEYS = new Set([
    'message_id',
    'activity_id',
    'attachment_id',
    'activity_type',
  ])
  const meaningfulProps = entry.properties
    ? Object.entries(entry.properties).filter(
        ([k, v]) => !INTERNAL_KEYS.has(k) && v !== null && v !== undefined
      )
    : []

  return (
    <div className="flex gap-3">
      <div className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${color}`}>
        <Icon icon={icon} width={16} />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium">{entry.actor?.name ?? 'System'}</span>
          <span className="text-xs text-muted-foreground">{formatDateTime(entry.created_at)}</span>
        </div>
        <p className="text-sm text-foreground/80 mt-0.5">
          {entry.description || readableEvent(entry.event)}
        </p>
        {meaningfulProps.length > 0 && (
          <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
            {meaningfulProps.map(([k, v]) => (
              <span key={k} className="inline-flex items-center gap-1">
                <span className="font-medium">{humanPropLabel(k)}:</span>
                {String(v)}
              </span>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

function humanPropLabel(key: string): string {
  return key
    .replace(/_/g, ' ')
    .replace(/^./, (c) => c.toUpperCase())
}

function MessageItem({ entry }: { entry: ChatterMessageEntry }) {
  return (
    <div className="flex gap-3 rounded-md bg-muted/40 p-3">
      <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700">
        <Icon icon="solar:user-circle-bold-duotone" width={16} />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium">{entry.actor?.name ?? 'System'}</span>
          <span className="text-xs text-muted-foreground">{formatDateTime(entry.created_at)}</span>
        </div>
        <p className="mt-1 whitespace-pre-wrap text-sm">{entry.body}</p>
      </div>
    </div>
  )
}

function AttachmentItem({
  attachment,
  readOnly,
  onDelete,
}: {
  attachment: ChatterAttachment
  readOnly: boolean
  onDelete: () => void
}) {
  return (
    <div className="flex items-center justify-between rounded-md border bg-card px-3 py-2">
      <div className="flex min-w-0 items-center gap-2">
        <Icon icon="solar:paperclip-bold-duotone" width={16} className="text-indigo-600" />
        <a
          href={attachment.url}
          target="_blank"
          rel="noopener noreferrer"
          className="truncate text-sm font-medium hover:underline"
        >
          {attachment.filename}
        </a>
        <span className="text-xs text-muted-foreground">{formatBytes(attachment.size)}</span>
      </div>
      {!readOnly && (
        <Button
          type="button"
          size="icon"
          variant="ghost"
          className="h-7 w-7 text-muted-foreground hover:text-destructive"
          onClick={onDelete}
        >
          <Icon icon="solar:trash-bin-trash-bold-duotone" width={16} />
        </Button>
      )}
    </div>
  )
}

function ActivitiesTab({
  activities,
  readOnly,
  onSubmit,
  onMarkDone,
  onError,
}: {
  activities: ChatterActivity[]
  readOnly: boolean
  onSubmit: (payload: {
    activity_type: ActivityType
    summary: string
    note?: string
    due_date?: string
    assigned_to_id?: number
  }) => Promise<unknown>
  onMarkDone: (id: number | string) => void
  onError: (msg: string) => void
}) {
  const [activityType, setActivityType] = useState<ActivityType>('todo')
  const [summary, setSummary] = useState('')
  const [dueDate, setDueDate] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const handleAdd = async () => {
    const trimmed = summary.trim()
    if (!trimmed) {
      onError('Activity summary is required.')
      return
    }
    setSubmitting(true)
    try {
      await onSubmit({
        activity_type: activityType,
        summary: trimmed,
        due_date: dueDate || undefined,
      })
      setSummary('')
      setDueDate('')
    } catch {
      onError('Failed to schedule activity.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex flex-col gap-3">
      {activities.map((act) => (
        <div
          key={act.id}
          className={`flex items-start gap-3 rounded-md border p-3 ${
            act.status === 'done' ? 'bg-muted/30 opacity-70' : 'bg-card'
          }`}
        >
          <div className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${activityTypeColors[act.activity_type]}`}>
            <Icon icon={activityTypeIcons[act.activity_type]} width={16} />
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-medium">{act.summary}</span>
              {act.status === 'done' ? (
                <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium uppercase text-emerald-700">Done</span>
              ) : (
                <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium uppercase text-amber-700">Planned</span>
              )}
            </div>
            {act.note && <p className="text-xs text-muted-foreground mt-1">{act.note}</p>}
            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
              <span>{ACTIVITY_TYPES.find((t) => t.value === act.activity_type)?.label}</span>
              {act.due_date && <span>Due {formatDateTime(act.due_date)}</span>}
              {act.assigned_to && <span>→ {act.assigned_to.name}</span>}
            </div>
          </div>
          {act.status !== 'done' && !readOnly && (
            <Button size="sm" variant="outline" onClick={() => onMarkDone(act.id)}>
              <Icon icon="solar:check-circle-bold" width={14} className="mr-1" />
              Done
            </Button>
          )}
        </div>
      ))}

      {activities.length === 0 && <EmptyHint text="No activities scheduled yet." />}

      {!readOnly && (
        <div className="rounded-md border bg-muted/30 p-3">
          <div className="flex flex-wrap items-center gap-2">
            <select
              value={activityType}
              onChange={(e) => setActivityType(e.target.value as ActivityType)}
              className="h-8 rounded-md border bg-background px-2 text-xs"
            >
              {ACTIVITY_TYPES.map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </select>
            <Input
              placeholder="Summary (required)"
              value={summary}
              onChange={(e) => setSummary(e.target.value)}
              className="h-8 flex-1 min-w-[160px] text-sm"
            />
            <Input
              type="date"
              value={dueDate}
              onChange={(e) => setDueDate(e.target.value)}
              className="h-8 w-[150px] text-sm"
            />
            <Button size="sm" onClick={handleAdd} disabled={submitting}>
              <Icon icon="solar:add-circle-bold" width={14} className="mr-1" />
              {submitting ? 'Adding…' : 'Schedule'}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

function readableEvent(event: string): string {
  return event
    .replace(/_/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

export default ChatterPanel
