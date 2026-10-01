import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { templateBuilderApi } from '@/lib/templateBuilderApi'
import { DOCUMENT_TYPE_LABELS, type DocumentType, type Starter } from '@/types/template-builder'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { toast } from '@/hooks/use-toast'
import { Plus, Pencil, Copy, Star, Archive, FileText, Download, Trash2 } from 'lucide-react'
import type { DocumentTemplate } from '@/types/template-builder'

export default function TemplateListPage({ embedded = false }: { embedded?: boolean }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [filter, setFilter] = useState<DocumentType | 'all'>('all')
  const [newOpen, setNewOpen] = useState(false)
  const [blankName, setBlankName] = useState('')
  const [blankType, setBlankType] = useState<DocumentType>('invoice')
  const [deleteTarget, setDeleteTarget] = useState<DocumentTemplate | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['tb-templates', filter],
    queryFn: () => templateBuilderApi.list(filter === 'all' ? undefined : { document_type: filter }),
  })

  const { data: starters } = useQuery({
    queryKey: ['tb-starters'],
    queryFn: () => templateBuilderApi.starters(),
    staleTime: 10 * 60 * 1000,
  })

  const { data: activeDefaults } = useQuery({
    queryKey: ['tb-active-defaults'],
    queryFn: () => templateBuilderApi.activeDefaults(),
    staleTime: 30 * 1000,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['tb-templates'] })
    queryClient.invalidateQueries({ queryKey: ['tb-active-defaults'] })
  }

  const createBlank = useMutation({
    mutationFn: () =>
      templateBuilderApi.create({
        name: blankName || `${DOCUMENT_TYPE_LABELS[blankType]} template`,
        document_type: blankType,
        mode: 'block',
        format: 'A4',
        orientation: 'portrait',
        status: 'draft',
        layout_config: { blocks: [] },
      }),
    onSuccess: (created) => {
      invalidate()
      setNewOpen(false)
      setBlankName('')
      navigate(`/template-builder/${created.id}`)
    },
    onError: () => toast({ title: 'Create failed', variant: 'destructive' }),
  })

  const createFromStarter = useMutation({
    mutationFn: (docType: DocumentType) => templateBuilderApi.createFromStarter(docType),
    onSuccess: (created) => {
      invalidate()
      setNewOpen(false)
      navigate(`/template-builder/${created.id}`)
    },
    onError: () => toast({ title: 'Could not create from starter', variant: 'destructive' }),
  })

  const duplicateMutation = useMutation({
    mutationFn: (id: string) => templateBuilderApi.duplicate(id),
    onSuccess: () => { invalidate(); toast({ title: 'Duplicated' }) },
    onError: () => toast({ title: 'Duplicate failed', variant: 'destructive' }),
  })

  const setDefaultMutation = useMutation({
    mutationFn: (id: string) => templateBuilderApi.setDefault(id),
    onSuccess: () => { invalidate(); toast({ title: 'Set as active default' }) },
    onError: () => toast({ title: 'Failed to set default', variant: 'destructive' }),
  })

  const archiveMutation = useMutation({
    mutationFn: (id: string) => templateBuilderApi.archive(id),
    onSuccess: () => { invalidate(); toast({ title: 'Archived' }) },
    onError: () => toast({ title: 'Archive failed', variant: 'destructive' }),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => templateBuilderApi.delete(id),
    onSuccess: () => {
      invalidate()
      setDeleteTarget(null)
      toast({ title: 'Template deleted' })
    },
    onError: (error: any) => toast({
      title: 'Could not delete template',
      description: error?.response?.data?.message || 'This template may still be in use.',
      variant: 'destructive',
    }),
  })

  const templates = data?.data ?? []

  return (
    <div className={embedded ? 'space-y-6' : 'mx-auto max-w-7xl space-y-6 p-6'}>
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold">Template Builder</h2>
          <p className="text-sm text-muted-foreground">
            Design printable layouts for invoices, quotations, purchase orders and receipts.
          </p>
        </div>
        <Button onClick={() => setNewOpen(true)}>
          <Plus className="mr-1.5 h-4 w-4" /> New Template
        </Button>
      </div>

      <div className="flex flex-wrap gap-2">
        <FilterChip active={filter === 'all'} onClick={() => setFilter('all')}>All</FilterChip>
        {(Object.keys(DOCUMENT_TYPE_LABELS) as DocumentType[]).map((t) => (
          <FilterChip key={t} active={filter === t} onClick={() => setFilter(t)}>
            {DOCUMENT_TYPE_LABELS[t]}
          </FilterChip>
        ))}
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading templates…</p>
      ) : templates.length === 0 ? (
        <Card className="flex flex-col items-center justify-center gap-2 p-12 text-center">
          <FileText className="h-8 w-8 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">No templates yet. Click <strong>New Template</strong> to start from a predefined layout.</p>
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {templates.map((t) => {
            const isActive = activeDefaults?.[t.document_type] === t.id
            return (
              <Card key={t.id} className={`flex flex-col p-4 ${isActive ? 'ring-2 ring-emerald-500' : ''}`}>
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="font-medium">{t.name}</h3>
                    <p className="text-xs text-muted-foreground">{DOCUMENT_TYPE_LABELS[t.document_type]}</p>
                  </div>
                  <div className="flex items-center gap-1">
                    {/* Single source of truth for "active": the resolved
                        assignment (isActive). The legacy is_system_default
                        flag rendered a star on a different card than the one
                        actually in use (report 2026-10-01). */}
                    {isActive ? (
                      <Badge className="gap-1 bg-emerald-600" title="This template is used for live documents">
                        <Star className="h-3 w-3 fill-current" /> Active
                      </Badge>
                    ) : (
                      <Badge variant="outline" className="text-muted-foreground" title="Not the active template for this document type">
                        Not active
                      </Badge>
                    )}
                    {t.status === 'archived'
                      ? <Badge variant="secondary">Archived</Badge>
                      : t.status === 'published'
                        ? <Badge variant="secondary">v{t.published_version}</Badge>
                        : t.published_version > 0
                          ? <Badge variant="outline" title="Edited since the last publish — live documents still use the published version">v{t.published_version} · draft edits</Badge>
                          : <Badge variant="outline">Draft</Badge>}
                  </div>
                </div>

                <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">
                  {t.description || `${t.format} · ${t.orientation} · ${(t.layout_config?.blocks ?? []).length} blocks`}
                </p>

                <div className="mt-4 flex flex-wrap items-center gap-1">
                  <Button size="sm" variant="default" onClick={() => navigate(`/template-builder/${t.id}`)}>
                    <Pencil className="mr-1 h-3.5 w-3.5" /> Edit
                  </Button>
                  <Button size="sm" variant="ghost" asChild title="Download sample PDF">
                    <a href={templateBuilderApi.samplePdfUrl(t.id)} target="_blank" rel="noreferrer">
                      <Download className="h-4 w-4" />
                    </a>
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => duplicateMutation.mutate(t.id)} title="Duplicate">
                    <Copy className="h-4 w-4" />
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setDefaultMutation.mutate(t.id)} title="Set as active default" disabled={isActive}>
                    <Star className="h-4 w-4" />
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => archiveMutation.mutate(t.id)} title="Archive">
                    <Archive className="h-4 w-4" />
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    onClick={() => setDeleteTarget(t)}
                    title={isActive || t.is_system_default ? 'Templates in use cannot be deleted' : 'Delete template'}
                    aria-label={`Delete ${t.name}`}
                    disabled={isActive || t.is_system_default || deleteMutation.isPending}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </Card>
            )
          })}
        </div>
      )}

      {/* New template — choose a starter or blank */}
      <NewTemplateDialog
        open={newOpen}
        onOpenChange={setNewOpen}
        starters={starters}
        blankName={blankName}
        setBlankName={setBlankName}
        blankType={blankType}
        setBlankType={setBlankType}
        onCreateBlank={() => createBlank.mutate()}
        onCreateStarter={(docType) => createFromStarter.mutate(docType)}
        creating={createBlank.isPending || createFromStarter.isPending}
      />

      <AlertDialog open={deleteTarget !== null} onOpenChange={(open) => !open && setDeleteTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete template?</AlertDialogTitle>
            <AlertDialogDescription>
              {deleteTarget
                ? `“${deleteTarget.name}” will be removed from Template Builder. This action cannot be undone.`
                : 'This template will be removed from Template Builder.'}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteMutation.isPending}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={!deleteTarget || deleteMutation.isPending}
              onClick={(event) => {
                event.preventDefault()
                if (deleteTarget) deleteMutation.mutate(deleteTarget.id)
              }}
            >
              {deleteMutation.isPending ? 'Deleting…' : 'Delete template'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function NewTemplateDialog({
  open, onOpenChange, starters, blankName, setBlankName, blankType, setBlankType,
  onCreateBlank, onCreateStarter, creating,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  starters?: Starter[]
  blankName: string
  setBlankName: (v: string) => void
  blankType: DocumentType
  setBlankType: (v: DocumentType) => void
  onCreateBlank: () => void
  onCreateStarter: (docType: DocumentType) => void
  creating: boolean
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Start a new template</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Predefined layouts — clone and customise
            </p>
            <div className="grid grid-cols-2 gap-2">
              {starters?.map((s) => (
                <button
                  key={s.document_type}
                  onClick={() => onCreateStarter(s.document_type)}
                  disabled={creating}
                  className="flex flex-col items-start gap-1 rounded-md border p-3 text-left transition hover:border-primary hover:bg-accent disabled:opacity-50"
                >
                  <span className="text-sm font-medium">{DOCUMENT_TYPE_LABELS[s.document_type]}</span>
                  <span className="text-[11px] text-muted-foreground">{s.name}</span>
                  <span className="text-[10px] text-muted-foreground">{s.format} · {s.orientation}</span>
                </button>
              ))}
            </div>
          </div>

          <div className="rounded-md border border-dashed p-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Or start from blank
            </p>
            <div className="grid grid-cols-2 gap-2">
              <div className="space-y-1.5">
                <Label className="text-xs">Name</Label>
                <Input value={blankName} onChange={(e) => setBlankName(e.target.value)} placeholder="My template" />
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">Document type</Label>
                <Select value={blankType} onValueChange={(v) => setBlankType(v as DocumentType)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {(Object.keys(DOCUMENT_TYPE_LABELS) as DocumentType[]).map((t) => (
                      <SelectItem key={t} value={t}>{DOCUMENT_TYPE_LABELS[t]}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button onClick={onCreateBlank} disabled={creating}>
            {creating ? 'Creating…' : 'Create blank'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function FilterChip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      className={
        'rounded-full border px-3 py-1 text-xs transition ' +
        (active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent')
      }
    >
      {children}
    </button>
  )
}
