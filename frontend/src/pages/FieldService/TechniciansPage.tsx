import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/hooks/use-toast'
import fieldServiceApi, { type Technician } from '@/lib/fieldServiceApi'
import { formatCurrency } from '@/lib/utils'

export function TechniciansPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState({ user_id: '', skills: '', hourly_rate: '', team_id: '' })

  const { data: technicians = [], isLoading } = useQuery({
    queryKey: ['fs-technicians'],
    queryFn: () => fieldServiceApi.getTechnicians().then(r => r.data),
  })

  const { data: nonTechs = [] } = useQuery({
    queryKey: ['fs-non-technicians'],
    queryFn: () => fieldServiceApi.getNonTechnicians().then(r => r.data),
  })

  const { data: teams = [] } = useQuery({
    queryKey: ['fs-teams'],
    queryFn: () => fieldServiceApi.getTeams().then(r => r.data),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['fs-technicians'] })

  const save = useMutation({
    mutationFn: () => {
      const payload: any = {
        user_id: Number(form.user_id),
        skills: form.skills || undefined,
        hourly_rate: form.hourly_rate ? parseFloat(form.hourly_rate) : undefined,
        team_id: form.team_id ? Number(form.team_id) : undefined,
      }
      return editingId
        ? fieldServiceApi.updateTechnician(editingId, payload)
        : fieldServiceApi.createTechnician(payload)
    },
    onSuccess: () => {
      toast({ title: editingId ? 'Technician updated' : 'Technician added' })
      setOpen(false)
      setEditingId(null)
      setForm({ user_id: '', skills: '', hourly_rate: '', team_id: '' })
      invalidate()
    },
    onError: () => toast({ title: 'Failed to save', variant: 'destructive' }),
  })

  const deactivate = useMutation({
    mutationFn: (id: number) => fieldServiceApi.deleteTechnician(id),
    onSuccess: () => { toast({ title: 'Technician deactivated' }); invalidate() },
    onError: () => toast({ title: 'Failed to deactivate', variant: 'destructive' }),
  })

  const openCreate = () => {
    setEditingId(null)
    setForm({ user_id: '', skills: '', hourly_rate: '', team_id: '' })
    setOpen(true)
  }

  const openEdit = (tech: Technician) => {
    setEditingId(tech.id)
    setForm({
      user_id: String(tech.user_id),
      skills: tech.skills ?? '',
      hourly_rate: tech.hourly_rate ? String(tech.hourly_rate) : '',
      team_id: tech.team_id ? String(tech.team_id) : '',
    })
    setOpen(true)
  }

  return (
    <div className="p-6 space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Technicians</h1>
        <Button onClick={openCreate}>
          <Icon icon="solar:add-circle-bold-duotone" width={18} className="mr-1.5" />
          Add Technician
        </Button>
      </div>

      {isLoading && (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          {Array.from({ length: 6 }).map((_, i) => <div key={i} className="h-28 rounded-lg bg-muted animate-pulse" />)}
        </div>
      )}

      {!isLoading && (technicians as Technician[]).length === 0 && (
        <div className="text-center py-16 text-muted-foreground">
          <Icon icon="solar:users-group-two-rounded-bold-duotone" width={40} className="mx-auto mb-3 opacity-30" />
          <p>No technicians yet. Add one to get started.</p>
        </div>
      )}

      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        {(technicians as Technician[]).map(tech => (
          <Card key={tech.id} className="border border-border/60">
            <CardContent className="p-4 space-y-3">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                  <Icon icon="solar:user-bold-duotone" width={20} className="text-primary" />
                </div>
                <div className="min-w-0">
                  <p className="font-medium text-sm truncate">{tech.user?.name ?? 'Unknown'}</p>
                  <p className="text-xs text-muted-foreground truncate">{tech.user?.email}</p>
                </div>
                <div className="ml-auto flex gap-1">
                  <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => openEdit(tech)}>
                    <Icon icon="solar:pen-bold-duotone" width={14} />
                  </Button>
                  <Button
                    variant="ghost" size="icon" className="h-7 w-7 text-destructive"
                    onClick={() => deactivate.mutate(tech.id)}
                    disabled={deactivate.isPending}
                  >
                    <Icon icon="solar:trash-bin-trash-bold-duotone" width={14} />
                  </Button>
                </div>
              </div>
              {tech.team && (
                <div className="flex items-center gap-1 text-xs text-muted-foreground">
                  <Icon icon="solar:people-nearby-bold-duotone" width={13} />
                  <span>{tech.team.name}</span>
                </div>
              )}
              {tech.skills && (
                <div className="flex flex-wrap gap-1">
                  {tech.skills.split(',').map(s => s.trim()).filter(Boolean).map(skill => (
                    <Badge key={skill} variant="secondary" className="text-xs">{skill}</Badge>
                  ))}
                </div>
              )}
              {tech.hourly_rate && (
                <p className="text-xs text-muted-foreground">Rate: {formatCurrency(Number(tech.hourly_rate) || 0)}/hr</p>
              )}
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Add/Edit dialog */}
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{editingId ? 'Edit Technician' : 'Add Technician'}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            {!editingId && (
              <div>
                <Label>User *</Label>
                <select
                  className="w-full mt-1 border rounded-md px-3 py-2 text-sm bg-background"
                  value={form.user_id}
                  onChange={e => setForm(f => ({ ...f, user_id: e.target.value }))}
                  required
                >
                  <option value="">Select user…</option>
                  {(nonTechs as any[]).map((u: any) => (
                    <option key={u.id} value={String(u.id)}>{u.name} — {u.email}</option>
                  ))}
                </select>
              </div>
            )}
            <div>
              <Label>Team</Label>
              <select
                className="w-full mt-1 border rounded-md px-3 py-2 text-sm bg-background"
                value={form.team_id}
                onChange={e => setForm(f => ({ ...f, team_id: e.target.value }))}
              >
                <option value="">No team</option>
                {(teams as any[]).map((t: any) => <option key={t.id} value={String(t.id)}>{t.name}</option>)}
              </select>
            </div>
            <div>
              <Label>Skills (comma separated)</Label>
              <Input value={form.skills} onChange={e => setForm(f => ({ ...f, skills: e.target.value }))} placeholder="Electrical, Plumbing, HVAC" className="mt-1" />
            </div>
            <div>
              <Label>Hourly Rate</Label>
              <Input type="number" min="0" step="0.01" value={form.hourly_rate} onChange={e => setForm(f => ({ ...f, hourly_rate: e.target.value }))} className="mt-1" />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
            <Button onClick={() => save.mutate()} disabled={save.isPending || (!editingId && !form.user_id)}>
              {save.isPending ? 'Saving...' : 'Save'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
