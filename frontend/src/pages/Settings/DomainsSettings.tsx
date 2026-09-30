import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import { useAuthStore } from '@/store/authStore'
import api from '@/lib/api'
import { Loader2, Plus, Trash2, ShieldCheck, RefreshCw } from 'lucide-react'

interface TenantDomain {
  id: number
  domain: string
  type: 'platform' | 'custom'
  is_primary: boolean
  dns_status: 'pending' | 'verified' | 'failed'
  ssl_status: 'unknown' | 'active' | 'failed'
  verification_token: string | null
  verification_record: { type: 'TXT'; host: string; value: string | null }
  verified_at: string | null
  cert_expires_at: string | null
  last_checked_at: string | null
  created_at: string
}

const DNS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  verified: 'default',
  pending: 'secondary',
  failed: 'destructive',
}

const SSL_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  active: 'default',
  unknown: 'outline',
  failed: 'destructive',
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  return new Date(value).toLocaleString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

function errorMessage(err: unknown): string {
  const ax = err as { response?: { data?: { message?: string } } }
  return ax.response?.data?.message ?? 'Request failed.'
}

export function DomainsSettings() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const hasPermission = useAuthStore((s) => s.hasPermission)
  const canWrite = hasPermission('settings.write')

  const [domain, setDomain] = useState('')

  const { data: domains = [], isLoading, isError, error } = useQuery({
    queryKey: ['tenant-domains'],
    queryFn: async () => {
      const res = await api.get('/tenant-domains')
      return res.data.data as TenantDomain[]
    },
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tenant-domains'] })

  const addMutation = useMutation({
    mutationFn: async () => {
      const res = await api.post('/tenant-domains', { domain: domain.trim() })
      return res.data.data as TenantDomain
    },
    onSuccess: () => {
      setDomain('')
      toast({ title: 'Domain added', description: 'It stays offline until the DNS ownership proof verifies.' })
      invalidate()
    },
    onError: (err) => toast({ title: 'Could not add domain', description: errorMessage(err), variant: 'destructive' }),
  })

  const verifyMutation = useMutation({
    mutationFn: async (id: number) => (await api.post(`/tenant-domains/${id}/verify`)).data.data as TenantDomain,
    onSuccess: (updated) => {
      toast({
        title: updated.dns_status === 'verified' ? 'Domain verified' : 'Verification failed',
        description: updated.dns_status === 'verified'
          ? `${updated.domain} is now serving.`
          : `TXT record for ${updated.domain} not found yet — the domain stays offline.`,
        variant: updated.dns_status === 'verified' ? 'default' : 'destructive',
      })
      invalidate()
    },
    onError: (err) => toast({ title: 'Verification failed', description: errorMessage(err), variant: 'destructive' }),
  })

  const checkMutation = useMutation({
    mutationFn: async (id: number) => (await api.post(`/tenant-domains/${id}/check`)).data.data as TenantDomain,
    onSuccess: () => {
      toast({ title: 'Status refreshed', description: 'DNS ownership and certificate status re-checked.' })
      invalidate()
    },
    onError: (err) => toast({ title: 'Status check failed', description: errorMessage(err), variant: 'destructive' }),
  })

  const removeMutation = useMutation({
    mutationFn: async (id: number) => api.delete(`/tenant-domains/${id}`),
    onSuccess: () => {
      toast({ title: 'Domain removed' })
      invalidate()
    },
    onError: (err) => toast({ title: 'Could not remove domain', description: errorMessage(err), variant: 'destructive' }),
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>Storefront Domains</CardTitle>
        <CardDescription>
          Custom domains and platform subdomains that serve this store. A custom domain only starts
          serving after the TXT ownership proof (<code>_storefront-verify.&lt;domain&gt;</code>) is
          verified — until then requests to it return 404.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        {isError && (
          <p className="text-sm text-destructive">
            {errorMessage(error)} — domain management requires owner/admin (settings) access.
          </p>
        )}

        <div className="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Domain</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>DNS</TableHead>
                <TableHead>SSL</TableHead>
                <TableHead>Certificate expires</TableHead>
                <TableHead>Last checked</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                    Loading domains…
                  </TableCell>
                </TableRow>
              ) : domains.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                    No domains registered yet.
                  </TableCell>
                </TableRow>
              ) : (
                domains.map((d) => (
                  <TableRow key={d.id}>
                    <TableCell className="font-mono text-xs">
                      {d.domain}
                      {d.is_primary && (
                        <Badge variant="secondary" className="ml-2 font-sans">primary</Badge>
                      )}
                    </TableCell>
                    <TableCell className="capitalize">{d.type}</TableCell>
                    <TableCell><Badge variant={DNS_VARIANT[d.dns_status] ?? 'outline'}>{d.dns_status}</Badge></TableCell>
                    <TableCell><Badge variant={SSL_VARIANT[d.ssl_status] ?? 'outline'}>{d.ssl_status}</Badge></TableCell>
                    <TableCell className="text-muted-foreground">{formatDate(d.cert_expires_at)}</TableCell>
                    <TableCell className="text-muted-foreground">{formatDate(d.last_checked_at)}</TableCell>
                    <TableCell className="text-right space-x-1">
                      {d.type === 'custom' && (
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={!canWrite || verifyMutation.isPending}
                          onClick={() => verifyMutation.mutate(d.id)}
                        >
                          {verifyMutation.isPending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ShieldCheck className="h-3.5 w-3.5" />}
                          <span className="ml-1">Verify</span>
                        </Button>
                      )}
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={!canWrite || checkMutation.isPending}
                        onClick={() => checkMutation.mutate(d.id)}
                      >
                        <RefreshCw className="h-3.5 w-3.5" />
                        <span className="ml-1">Check</span>
                      </Button>
                      {d.type === 'custom' && (
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={!canWrite || removeMutation.isPending}
                          onClick={() => removeMutation.mutate(d.id)}
                        >
                          <Trash2 className="h-3.5 w-3.5 text-destructive" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </div>

        {canWrite && (
          <div className="space-y-3">
            <div className="grid gap-2 sm:max-w-md">
              <Label htmlFor="domain-input">Add a domain</Label>
              <div className="flex gap-2">
                <Input
                  id="domain-input"
                  value={domain}
                  onChange={(e) => setDomain(e.target.value)}
                  placeholder="shop.example.com"
                  className="font-mono"
                />
                <Button
                  disabled={!domain.trim() || addMutation.isPending}
                  onClick={() => addMutation.mutate()}
                >
                  {addMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                  <span className="ml-1">Add</span>
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                Custom domains require a DNS TXT record before they serve traffic. Platform
                subdomains are system-assigned and can only be added by the platform team.
              </p>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
