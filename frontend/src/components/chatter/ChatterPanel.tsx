import React, { useState, useRef, useEffect, useMemo } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import chatterApi, {
  type ActivityType,
  type ChatterActivity,
  type ChatterBundle,
  type ChatterLogEntry,
  type ChatterAttachment,
  type ChatterMention,
  type ChatterMessageEntry,
  type ChatterMessageCategory,
  type ChatterCustomerChannel,
  type CreateMessageRequest,
} from '@/lib/chatterApi'
import { ChatterFollowers } from '@/components/chatter/ChatterFollowers'

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

// Issue #636: All / Customer / Internal / System filter for the Messages tab.
type MessageFilter = 'all' | ChatterMessageCategory

const MESSAGE_FILTERS: { id: MessageFilter; label: string }[] = [
  { id: 'all', label: 'All' },
  { id: 'customer', label: 'Customer' },
  { id: 'internal', label: 'Internal' },
  { id: 'system', label: 'System' },
]

type ComposerMode = 'note' | 'customer'

/** Messages without a category (older API payloads) are treated as internal. */
const messageCategory = (entry: ChatterMessageEntry): ChatterMessageCategory =>
  entry.category ?? (entry.is_internal_note === false ? 'customer' : 'internal')

const channelLabel = (channel?: string | null): string =>
  channel === 'whatsapp' ? 'WhatsApp' : channel === 'sms' ? 'SMS' : 'Email'

interface MessageThread {
  entry: ChatterMessageEntry
  replies: ChatterMessageEntry[]
}

/**
 * Issue #636: nest replies (parent_id) under the message they answer. A reply
 * whose parent is not in the visible list stays top-level.
 */
function buildThreads(entries: ChatterMessageEntry[]): MessageThread[] {
  const visible = new Set(entries.map((e) => String(e.id)))
  const replies = new Map<string, ChatterMessageEntry[]>()

  for (const entry of entries) {
    const parent = entry.parent_id != null ? String(entry.parent_id) : null
    if (parent !== null && parent !== String(entry.id) && visible.has(parent)) {
      replies.set(parent, [...(replies.get(parent) ?? []), entry])
    }
  }

  return entries
    .filter((entry) => {
      const parent = entry.parent_id != null ? String(entry.parent_id) : null
      return parent === null || parent === String(entry.id) || !visible.has(parent)
    })
    .map((entry) => ({
      entry,
      replies: [...(replies.get(String(entry.id)) ?? [])].sort((a, b) =>
        (a.created_at ?? '').localeCompare(b.created_at ?? '')
      ),
    }))
}

const apiErrorMessage = (error: unknown): string | undefined => {
  const data = (error as { response?: { data?: { message?: unknown } } })?.response?.data
  return typeof data?.message === 'string' ? data.message : undefined
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
  /** When true the panel hides the log-message composer (read-only context) */
  readOnly?: boolean
  /** Optional className applied to the outer wrapper */
  className?: string
}

// ─── Component ──────────────────────────────────────────────────────────────

export function ChatterPanel({
  subjectType,
  subjectId,
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
  // Issue #636: composer defaults to an internal note — reaching the customer
  // is always an explicit choice.
  const [composerMode, setComposerMode] = useState<ComposerMode>('note')
  const [channel, setChannel] = useState<ChatterCustomerChannel>('email')
  const [messageFilter, setMessageFilter] = useState<MessageFilter>('all')
  const fileInputRef = useRef<HTMLInputElement | null>(null)
  const feedRef = useRef<HTMLDivElement | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey,
    queryFn: () => chatterApi.list(subjectType, subjectId),
    // Issue #87: poll every 10s so newly posted messages from other users
    // appear without a manual refresh. Pauses while the tab is hidden.
    refetchInterval: () => (typeof document !== 'undefined' && document.hidden ? false : 10_000),
    refetchIntervalInBackground: false,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey })

  const sendMessage = useMutation({
    mutationFn: (payload: CreateMessageRequest) =>
      chatterApi.addMessage(subjectType, subjectId, payload),
    onSuccess: () => {
      setMessage('')
      invalidate()
    },
    onError: (err: unknown, payload) =>
      toast({
        title: payload.mode === 'customer' ? 'Failed to message customer' : 'Failed to log note',
        description: apiErrorMessage(err),
        variant: 'destructive',
      }),
  })

  const allMessages = useMemo(() => data?.messages ?? [], [data?.messages])
  const messageCounts = useMemo(() => {
    const counts: Record<MessageFilter, number> = { all: allMessages.length, customer: 0, internal: 0, system: 0 }
    for (const m of allMessages) counts[messageCategory(m)] += 1
    return counts
  }, [allMessages])
  const threads = useMemo(
    () =>
      buildThreads(
        messageFilter === 'all'
          ? allMessages
          : allMessages.filter((m) => messageCategory(m) === messageFilter)
      ),
    [allMessages, messageFilter]
  )

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
    sendMessage.mutate(
      composerMode === 'customer'
        ? { body: trimmed, mode: 'customer', channel }
        : { body: trimmed, mode: 'note' }
    )
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
    <div className={`flex h-full flex-col overflow-hidden rounded-xl border bg-card ${className}`}>
      {/* The surrounding dock/dialog owns the title bar — an extra header here
          rendered a second "Chatter — <doc>" row directly beneath it. */}
      {isLoading && (
        <div className="border-b px-4 py-1.5 text-xs text-muted-foreground">Loading…</div>
      )}

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

      {/* Issue #639: shared follower strip — rendered here so every page that
          already embeds ChatterPanel gets followers with no page-level edit. */}
      <ChatterFollowers subjectType={subjectType} subjectId={subjectId} />

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
            {/* Issue #636: All / Customer / Internal / System */}
            <div role="group" aria-label="Message filter" className="flex flex-wrap gap-1">
              {MESSAGE_FILTERS.map(({ id, label }) => (
                <button
                  key={id}
                  type="button"
                  aria-pressed={messageFilter === id}
                  onClick={() => setMessageFilter(id)}
                  className={`flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-medium transition ${
                    messageFilter === id
                      ? 'border-primary/40 bg-primary/10 text-primary'
                      : 'text-muted-foreground hover:bg-muted'
                  }`}
                >
                  {label}
                  <span className="text-[10px] opacity-70">{messageCounts[id]}</span>
                </button>
              ))}
            </div>
            {threads.map(({ entry, replies }) => (
              <div key={String(entry.id)} data-message-id={String(entry.id)} className="flex flex-col gap-2">
                <MessageItem entry={entry} />
                {replies.length > 0 && (
                  <div className="ml-6 flex flex-col gap-2 border-l-2 border-muted pl-3">
                    {replies.map((reply) => (
                      <div key={String(reply.id)} data-message-id={String(reply.id)}>
                        <MessageItem entry={reply} />
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))}
            {threads.length === 0 && (
              <EmptyHint
                text={
                  allMessages.length === 0
                    ? 'No messages yet. Post the first one below.'
                    : 'No messages match this filter.'
                }
              />
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
          {/* Issue #636: two composer modes */}
          <div role="group" aria-label="Composer mode" className="mb-2 flex flex-wrap items-center gap-1">
            {([
              { id: 'note', label: 'Log note', icon: 'solar:notes-bold-duotone' },
              { id: 'customer', label: 'Message customer', icon: 'solar:letter-bold-duotone' },
            ] as const).map(({ id, label, icon }) => (
              <button
                key={id}
                type="button"
                aria-pressed={composerMode === id}
                onClick={() => setComposerMode(id)}
                className={`flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium transition ${
                  composerMode === id
                    ? id === 'note'
                      ? 'bg-amber-100 text-amber-800'
                      : 'bg-primary/10 text-primary'
                    : 'text-muted-foreground hover:bg-muted'
                }`}
              >
                <Icon icon={icon} width={14} />
                {label}
              </button>
            ))}
            {composerMode === 'customer' && (
              <label className="ml-auto flex items-center gap-1 text-xs text-muted-foreground">
                Channel
                <select
                  aria-label="Channel"
                  value={channel}
                  onChange={(e) => setChannel(e.target.value as ChatterCustomerChannel)}
                  className="h-7 rounded-md border bg-background px-1.5 text-xs text-foreground"
                >
                  <option value="email">Email</option>
                  <option value="whatsapp">WhatsApp</option>
                </select>
              </label>
            )}
          </div>
          <Textarea
            placeholder={
              composerMode === 'customer'
                ? 'Write a message to the customer…'
                : 'Write an internal note (only your team sees this)…'
            }
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            rows={3}
            className={`resize-none ${composerMode === 'note' ? 'bg-amber-50/60' : ''}`}
          />
          <div className="mt-2 flex items-center justify-between gap-2">
            <span className="text-[11px] text-muted-foreground">
              {composerMode === 'customer'
                ? `Sent to the document's customer contact by ${channelLabel(channel)}.`
                : 'Internal only — never sent to the customer.'}
            </span>
            <Button
              type="submit"
              size="sm"
              className="h-8 rounded-full px-3 text-xs"
              disabled={!message.trim() || sendMessage.isPending}
            >
              <Icon
                icon={composerMode === 'customer' ? 'solar:send-square-bold' : 'solar:notes-bold'}
                width={16}
                className="mr-1"
              />
              {sendMessage.isPending ? 'Sending…' : composerMode === 'customer' ? 'Send' : 'Log'}
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
            className="h-8 w-full rounded-full text-xs"
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

  // Customer-portal actions have no platform user behind them; the producer
  // records the person's name so the row does not read "System".
  const actorName =
    entry.actor?.name
    || (entry.properties?.uploaded_by_name as string | undefined)
    || (entry.properties?.signer_name as string | undefined)
    || 'System'

  // Internal IDs (message_id, attachment_id, etc.) and the activity_type
  // are useful to the backend but not to a human reading the log.
  // Filter them out so the log row shows just the description + a clean
  // summary of any user-meaningful properties (filename, status, due_date).
  const INTERNAL_KEYS = new Set([
    'message_id',
    'activity_id',
    'attachment_id',
    'activity_type',
    'sales_order_id',
    'sales_order_number',
    // Used to attribute the entry to a non-user uploader instead.
    'uploaded_by_name',
    'signer_name',
    'unlinked_sales_order_id',
    'unlinked_sales_order_number',
  ])
  // Only primitive values are rendered. Objects/arrays are internal payloads
  // (they used to render as "[object Object]") and booleans alone are noise.
  const meaningfulProps = entry.properties
    ? Object.entries(entry.properties).filter(
        ([k, v]) =>
          !INTERNAL_KEYS.has(k) &&
          v !== null &&
          v !== undefined &&
          typeof v !== 'object' &&
          String(v).trim() !== ''
      )
    : []

  return (
    <div className="flex gap-3">
      <div className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${color}`}>
        <Icon icon={icon} width={16} />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium">{actorName}</span>
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
  const actorName = entry.actor?.name ?? 'System'
  const category = messageCategory(entry)

  // Issue #636: make the audience of every message obvious.
  const badge =
    category === 'internal'
      ? { text: 'Internal note', className: 'bg-amber-100 text-amber-800' }
      : category === 'system'
        ? { text: 'System', className: 'bg-slate-100 text-slate-600' }
        : entry.direction === 'inbound'
          ? { text: `From customer · ${channelLabel(entry.channel)}`, className: 'bg-emerald-100 text-emerald-700' }
          : {
              text: entry.channel ? `To customer · ${channelLabel(entry.channel)}` : 'Customer',
              className: 'bg-blue-100 text-blue-700',
            }

  const surface =
    category === 'internal'
      ? 'bg-amber-50/70 border border-amber-100'
      : category === 'system'
        ? 'bg-muted/30'
        : 'bg-muted/40'

  return (
    <div className={`flex gap-3 rounded-md p-3 ${surface}`}>
      <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700">
        <Icon
          icon={category === 'internal' ? 'solar:notes-bold-duotone' : 'solar:user-circle-bold-duotone'}
          width={16}
        />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium">{actorName}</span>
          <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${badge.className}`}>
            {badge.text}
          </span>
          <span className="text-xs text-muted-foreground">{formatDateTime(entry.created_at)}</span>
          {entry.direction === 'outbound' && entry.recipient && (
            <span className="text-xs text-muted-foreground">→ {entry.recipient}</span>
          )}
        </div>
        <p className="mt-1 whitespace-pre-wrap text-sm">
          <MentionText body={entry.body} mentions={entry.mentions} />
        </p>
      </div>
    </div>
  )
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Issue #635 — render resolved @mentions as chips.
 *
 * Only tokens the backend actually resolved are highlighted: the mention
 * payload carries the users' names and email handles, and unknown tokens
 * (`@nobody`) stay plain body text. A trailing sentence period after the
 * mention is not part of the token.
 */
function MentionText({ body, mentions }: { body: string; mentions?: ChatterMention[] }) {
  const tokens = useMemo(() => {
    if (!mentions?.length) return []

    const values = mentions.flatMap((mention) => {
      const candidates = [mention.name]
      const handle = mention.email?.split('@')[0]
      if (handle) candidates.push(handle)
      return candidates
    })

    return [...new Set(values.filter((value): value is string => Boolean(value && value.trim())))].sort(
      (a, b) => b.length - a.length
    )
  }, [mentions])

  if (tokens.length === 0) return <>{body}</>

  const pattern = new RegExp(
    `(^|[^\\p{L}\\p{N}_@.-])@(${tokens.map(escapeRegExp).join('|')})(?![\\p{L}\\p{N}_@]|\\.[\\p{L}\\p{N}]|-[\\p{L}\\p{N}])`,
    'giu'
  )

  const parts: React.ReactNode[] = []
  let cursor = 0

  for (const match of body.matchAll(pattern)) {
    const index = (match.index ?? 0) + match[1].length
    if (index > cursor) parts.push(body.slice(cursor, index))

    parts.push(
      <span
        key={`mention-${index}`}
        className="mention-chip rounded bg-primary/10 px-1 font-medium text-primary"
      >
        @{match[2]}
      </span>
    )

    cursor = index + match[0].length - match[1].length
  }

  if (cursor < body.length) parts.push(body.slice(cursor))

  return <>{parts}</>
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
  const [busy, setBusy] = useState<'view' | 'download' | null>(null)

  const fetchBlob = async (): Promise<Blob> => {
    // Issue #78: attachments are fetched through the authenticated API
    // (the storage path is never exposed), so download as a blob.
    const response = await api.get(attachment.url, { responseType: 'blob' })
    return response.data as Blob
  }

  const handleView = async () => {
    try {
      setBusy('view')
      const blob = await fetchBlob()
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener,noreferrer')
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch {
      // Surfaced by the global api interceptor; keep the row inert.
    } finally {
      setBusy(null)
    }
  }

  const handleDownload = async () => {
    try {
      setBusy('download')
      const blob = await fetchBlob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = attachment.filename
      document.body.appendChild(a)
      a.click()
      a.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch {
      // Surfaced by the global api interceptor; keep the row inert.
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="flex items-center justify-between gap-3 rounded-md border bg-card px-3 py-2">
      <div className="flex min-w-0 items-center gap-2">
        <Icon icon="solar:paperclip-bold-duotone" width={16} className="shrink-0 text-indigo-600" />
        <span className="truncate text-sm font-medium" title={attachment.filename}>
          {attachment.filename}
        </span>
        <span className="shrink-0 text-xs text-muted-foreground">{formatBytes(attachment.size)}</span>
        {(attachment.uploaded_by?.name || attachment.uploaded_by_name) && (
          <span className="shrink-0 text-xs text-muted-foreground">
            · {attachment.uploaded_by?.name || attachment.uploaded_by_name}
          </span>
        )}
      </div>

      <div className="flex shrink-0 items-center gap-1">
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="h-7 rounded-full px-2.5 text-xs"
          onClick={handleView}
          disabled={busy !== null}
        >
          <Icon icon="solar:eye-bold-duotone" width={14} className="mr-1" />
          View
        </Button>
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="h-7 rounded-full px-2.5 text-xs"
          onClick={handleDownload}
          disabled={busy !== null}
        >
          <Icon icon="solar:download-minimalistic-bold-duotone" width={14} className="mr-1" />
          {busy === 'download' ? '…' : 'Download'}
        </Button>
        {!readOnly && (
          <Button
            type="button"
            size="icon"
            variant="ghost"
            className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive"
            onClick={onDelete}
            title="Delete attachment"
          >
            <Icon icon="solar:trash-bin-trash-bold-duotone" width={16} />
          </Button>
        )}
      </div>
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
            <Button
              size="sm"
              variant="outline"
              className="h-8 rounded-full px-3 text-xs"
              onClick={() => onMarkDone(act.id)}
            >
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
            <Button size="sm" className="h-8 rounded-full px-3 text-xs" onClick={handleAdd} disabled={submitting}>
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
