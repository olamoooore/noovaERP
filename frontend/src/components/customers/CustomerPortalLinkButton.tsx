import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { useToast } from '@/hooks/use-toast'
import { getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'

/**
 * Issue #337: staff-side management of a customer's portal links — issue a
 * signed, expiring link (shown once), list existing links, revoke.
 * Backed by /api/v1/customers/{id}/portal-links (invoices.send).
 */

interface PortalLink {
  id: number
  label: string | null
  expires_at: string | null
  revoked_at: string | null
  last_accessed_at: string | null
  status: 'active' | 'expired' | 'revoked'
  url?: string
}

export function CustomerPortalLinkButton({ customerId }: { customerId: number | string }) {
  const [open, setOpen] = useState(false)
  const [days, setDays] = useState('30')
  const [issuedUrl, setIssuedUrl] = useState<string | null>(null)
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const key = ['customer-portal-links', String(customerId)]

  const links = useQuery<PortalLink[]>({
    queryKey: key,
    queryFn: async () => (await api.get(`/customers/${customerId}/portal-links`)).data?.data ?? [],
    enabled: open,
  })

  const issue = useMutation({
    mutationFn: async () =>
      (await api.post(`/customers/${customerId}/portal-links`, { expires_in_days: Number(days) || 30 })).data?.data as PortalLink,
    onSuccess: (link) => {
      setIssuedUrl(link.url ?? null)
      queryClient.invalidateQueries({ queryKey: key })
    },
    onError: (error) =>
      toast({ variant: 'destructive', title: 'Could not create portal link', description: getApiErrorMessage(error) }),
  })

  const revoke = useMutation({
    mutationFn: async (id: number) => api.delete(`/customers/${customerId}/portal-links/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
    onError: (error) =>
      toast({ variant: 'destructive', title: 'Could not revoke link', description: getApiErrorMessage(error) }),
  })

  const copy = async () => {
    if (!issuedUrl) return
    try {
      await navigator.clipboard.writeText(issuedUrl)
      toast({ title: 'Link copied' })
    } catch {
      toast({ variant: 'destructive', title: 'Copy failed', description: 'Select the link and copy it manually.' })
    }
  }

  return (
    <>
      <HeaderActionButton
        variant="outline"
        icon="solar:link-round-bold-duotone"
        onClick={() => {
          setIssuedUrl(null)
          setOpen(true)
        }}
      >
        Portal link
      </HeaderActionButton>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Customer portal link</DialogTitle>
            <DialogDescription>
              Anyone with this link can view this customer&apos;s posted invoices, open quotations and statement, download
              them and pay online. The link is shown once; revoke it at any time.
            </DialogDescription>
          </DialogHeader>

          <div className="flex items-end gap-2">
            <div className="flex-1">
              <Label htmlFor="portal-days">Expires in (days)</Label>
              <Input
                id="portal-days"
                type="number"
                min={1}
                max={90}
                value={days}
                onChange={(e) => setDays(e.target.value)}
              />
            </div>
            <Button onClick={() => issue.mutate()} disabled={issue.isPending}>
              Create link
            </Button>
          </div>

          {issuedUrl ? (
            <div className="space-y-2">
              <Label>New link (copy it now — it will not be shown again)</Label>
              <div className="flex gap-2">
                <Input readOnly value={issuedUrl} onFocus={(e) => e.currentTarget.select()} />
                <Button variant="outline" onClick={copy}>
                  Copy
                </Button>
              </div>
            </div>
          ) : null}

          <div className="space-y-2">
            <Label>Existing links</Label>
            {(links.data ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">No portal links yet.</p>
            ) : (
              <ul className="space-y-1">
                {(links.data ?? []).map((link) => (
                  <li key={link.id} className="flex items-center justify-between text-sm">
                    <span>
                      #{link.id} · expires {link.expires_at ? new Date(link.expires_at).toLocaleDateString() : '—'}{' '}
                      <Badge variant={link.status === 'active' ? 'default' : 'secondary'}>{link.status}</Badge>
                    </span>
                    {link.status === 'active' ? (
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={revoke.isPending}
                        onClick={() => revoke.mutate(link.id)}
                      >
                        Revoke
                      </Button>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
