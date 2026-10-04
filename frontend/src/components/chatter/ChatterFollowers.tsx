import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { useToast } from '@/hooks/use-toast'
import { useAuthStore } from '@/store/authStore'
import chatterApi, { type ChatterFollower, type FollowRequest } from '@/lib/chatterApi'

interface Props {
  /** Fully-qualified class name of the subject model (e.g. App\Models\Invoice) */
  subjectType: string
  /** Numeric/string subject id */
  subjectId: number | string
}

/**
 * Issue #639 — compact follower strip shared by every document detail page
 * (rendered inside ChatterPanel, so pages need no per-page wiring).
 *
 * Users follow/unfollow themselves and pick which chatter events they receive;
 * customer contacts can be added as partner followers and are delivered the
 * customer-facing messages by the backend Communication engine.
 */
export function ChatterFollowers({ subjectType, subjectId }: Props) {
  const queryKey = ['chatter-followers', subjectType, subjectId]
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const currentUserId = useAuthStore((state) => state.user?.id ?? null)
  const [showSubscriptions, setShowSubscriptions] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey,
    queryFn: () => chatterApi.listFollowers(subjectType, subjectId),
    staleTime: 15_000,
  })

  const followers = data?.followers ?? []
  const me =
    currentUserId != null
      ? followers.find((follower) => follower.user?.id === currentUserId)
      : undefined
  const eligibleContacts = (data?.partnerContacts ?? []).filter(
    (contact) => !followers.some((follower) => follower.contact?.id === contact.id)
  )

  const invalidate = () => queryClient.invalidateQueries({ queryKey })
  const onError = () =>
    toast({ title: 'Failed to update followers', variant: 'destructive' })

  const follow = useMutation({
    mutationFn: (payload: FollowRequest) =>
      chatterApi.follow(subjectType, subjectId, payload),
    onSuccess: invalidate,
    onError,
  })

  const unfollow = useMutation({
    mutationFn: (followerId: number | string) =>
      chatterApi.unfollow(subjectType, subjectId, followerId),
    onSuccess: invalidate,
    onError,
  })

  const toggleSubscription = (
    flag: 'subscribe_messages' | 'subscribe_activities' | 'subscribe_stage_changes'
  ) => {
    if (!me) return
    follow.mutate({ [flag]: !me[flag] } as FollowRequest)
  }

  const label = (follower: ChatterFollower) =>
    follower.user?.name ?? follower.contact?.name ?? 'Unknown follower'

  return (
    <div className="border-b px-3 py-2">
      <div className="flex flex-wrap items-center gap-1.5">
        <span className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground">
          <Icon icon="solar:users-group-rounded-bold-duotone" width={14} />
          Followers
        </span>

        {isLoading && <span className="text-xs text-muted-foreground">Loading…</span>}

        {followers.map((follower) => (
          <span
            key={String(follower.id)}
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs ${
              follower.follower_type === 'partner'
                ? 'border-amber-200 bg-amber-50 text-amber-800'
                : 'border-slate-200 bg-slate-50 text-slate-700'
            }`}
            title={follower.follow_source}
          >
            <Icon
              icon={
                follower.follower_type === 'partner'
                  ? 'solar:user-speak-bold-duotone'
                  : 'solar:user-circle-bold-duotone'
              }
              width={13}
            />
            {label(follower)}
            {follower.user?.id === currentUserId && (
              <span className="text-[10px] text-muted-foreground">(you)</span>
            )}
            <button
              type="button"
              className="ml-0.5 rounded-full text-muted-foreground opacity-60 hover:text-destructive hover:opacity-100"
              title="Remove follower"
              onClick={() => unfollow.mutate(follower.id)}
              disabled={unfollow.isPending}
            >
              <Icon icon="solar:close-circle-bold" width={13} />
            </button>
          </span>
        ))}

        {currentUserId != null && !me && (
          <Button
            type="button"
            size="sm"
            variant="outline"
            className="h-6 rounded-full px-2 text-xs"
            onClick={() => follow.mutate({})}
            disabled={follow.isPending}
          >
            <Icon icon="solar:add-circle-bold" width={13} className="mr-0.5" />
            Follow
          </Button>
        )}

        {me && (
          <button
            type="button"
            className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs text-muted-foreground hover:bg-muted"
            onClick={() => setShowSubscriptions((open) => !open)}
          >
            <Icon icon="solar:bell-bold-duotone" width={13} />
            Subscriptions
          </button>
        )}

        {eligibleContacts.length > 0 && (
          <select
            value=""
            onChange={(event) => {
              const contactId = Number(event.target.value)
              if (contactId > 0) follow.mutate({ contact_id: contactId })
            }}
            className="h-6 rounded-full border bg-background px-2 text-xs"
            disabled={follow.isPending}
          >
            <option value="">Add customer contact…</option>
            {eligibleContacts.map((contact) => (
              <option key={contact.id} value={contact.id}>
                {contact.name ?? contact.email ?? `Contact #${contact.id}`}
              </option>
            ))}
          </select>
        )}
      </div>

      {me && showSubscriptions && (
        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
          {(
            [
              ['subscribe_messages', 'Messages'],
              ['subscribe_activities', 'Activities'],
              ['subscribe_stage_changes', 'Stage changes'],
            ] as const
          ).map(([flag, text]) => (
            <label key={flag} className="inline-flex cursor-pointer items-center gap-1.5">
              <input
                type="checkbox"
                checked={me[flag]}
                onChange={() => toggleSubscription(flag)}
                className="h-3.5 w-3.5 rounded border"
              />
              {text}
            </label>
          ))}
        </div>
      )}
    </div>
  )
}

export default ChatterFollowers
