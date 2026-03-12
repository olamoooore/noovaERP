import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import fieldServiceApi, { type Team } from '@/lib/fieldServiceApi'

export function TeamsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState({ name: '', description: '', color: '#3b82f6' })

  const { data: teams = [], isLoading } = useQuery({
    queryKey: ['fs-teams'],
    queryFn: () => fieldServiceApi.getTeams().then(r => r.data),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['fs-teams'] })

  const save = useMutation({
    mutationFn: () =>
      editingId
        ? fieldServiceApi.updateTeam(editingId, form)
        : fieldServiceApi.createTeam(form),
    onSuccess: () => {
      toast({ title: editingId ? 'Team updated' : 'Team created' })
      setOpen(false)
      setEditingId(null)
      setForm({ name: '', description: '', color: '#3b82f6' })
      invalidate()
    },
    onError: () => toast({ title: 'Failed to save team', variant: 'destructive' }),
  })

  const deactivate = useMutation({
    mutationFn: (id: number) => fieldServiceApi.deleteTeam(id),
    onSuccess: () => { toast({ title: 'Team deactivated' }); invalidate() },
    onError: () => toast({ title: 'Failed to deactivate team', variant: 'destructive' }),
  })

  const openCreate = () => {
    setEditingId(null)
    setForm({ name: '', description: '', color: '#3b82f6' })
    setOpen(true)
  }

  const openEdit = (team: Team) => {
    setEditingId(team.id)
    setForm({ name: team.name, description: team.description ?? '', color: team.color ?? '#3b82f6' })
    setOpen(true)
  }

  return (
    <div className="p-6 space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Teams</h1>
        <Button onClick={openCreate}>
          <Icon icon="solar:add-circle-bold-duotone" width={18} className="mr-1.5" />
          New Team
        </Button>
      </div>

      {isLoading && (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          {Array.from({ length: 4 }).map((_, i) => <div key={i} className="h-28 rounded-lg bg-muted animate-pulse" />)}
        </div>
      )}

      {!isLoading && (teams as Team[]).length === 0 && (
        <div className="text-center py-16 text-muted-foreground">
          <Icon icon="solar:people-nearby-bold-duotone" width={40} className="mx-auto mb-3 opacity-30" />
          <p>No teams yet.</p>
        </div>
      )}

      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        {(teams as Team[]).map(team => (
          <Card key={team.id} className="border border-border/60">
            <CardContent className="p-4 space-y-2">
              <div className="flex items-center gap-3">
                <div
                  className="w-3 h-10 rounded-sm flex-shrink-0"
                  style={{ backgroundColor: team.color ?? '#3b82f6' }}
                />
                <div className="flex-1 min-w-0">
                  <p className="font-semibold text-sm">{team.name}</p>
                  {team.description && <p className="text-xs text-muted-foreground line-clamp-2">{team.description}</p>}
                </div>
                <div className="flex gap-1 flex-shrink-0">
                  <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => openEdit(team)}>
                    <Icon icon="solar:pen-bold-duotone" width={14} />
                  </Button>
                  <Button
                    variant="ghost" size="icon" className="h-7 w-7 text-destructive"
                    onClick={() => deactivate.mutate(team.id)}
                    disabled={deactivate.isPending}
                  >
                    <Icon icon="solar:trash-bin-trash-bold-duotone" width={14} />
                  </Button>
                </div>
              </div>
              {team.leader && (
                <div className="flex items-center gap-1 text-xs text-muted-foreground pl-5">
                  <Icon icon="solar:crown-bold-duotone" width={13} className="text-amber-500" />
                  <span>{team.leader.name}</span>
                </div>
              )}
              {team.technicians && team.technicians.length > 0 && (
                <p className="text-xs text-muted-foreground pl-5">
                  {team.technicians.length} technician{team.technicians.length !== 1 ? 's' : ''}
                </p>
              )}
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Create/Edit dialog */}
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{editingId ? 'Edit Team' : 'New Team'}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div>
              <Label>Team Name *</Label>
              <Input
                required
                value={form.name}
                onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                placeholder="e.g. HVAC North Team"
                className="mt-1"
              />
            </div>
            <div>
              <Label>Description</Label>
              <Textarea
                value={form.description}
                onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
                placeholder="Optional description..."
                rows={2}
                className="mt-1 resize-none"
              />
            </div>
            <div>
              <Label>Color</Label>
              <div className="flex items-center gap-2 mt-1">
                <input
                  type="color"
                  value={form.color}
                  onChange={e => setForm(f => ({ ...f, color: e.target.value }))}
                  className="h-9 w-16 rounded border cursor-pointer"
                />
                <span className="text-sm text-muted-foreground">{form.color}</span>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
            <Button onClick={() => save.mutate()} disabled={save.isPending || !form.name.trim()}>
              {save.isPending ? 'Saving...' : 'Save'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
