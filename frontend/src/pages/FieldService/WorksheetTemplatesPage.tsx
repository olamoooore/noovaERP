import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter
} from '@/components/ui/dialog'
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel,
  AlertDialogContent, AlertDialogDescription, AlertDialogFooter,
  AlertDialogHeader, AlertDialogTitle
} from '@/components/ui/alert-dialog'
import WorksheetBuilder from '@/components/field-service/WorksheetBuilder'
import type { WorksheetField } from '@/components/field-service/WorksheetForm'
import { fieldServiceApi, type WorksheetTemplate } from '@/lib/fieldServiceApi'
import { Plus, Pencil, Trash2, CheckCircle2, FileText } from 'lucide-react'

// ── Page ──────────────────────────────────────────────────────────────────────

export default function WorksheetTemplatesPage() {
  const qc = useQueryClient()

  const { data: templates, isLoading } = useQuery({
    queryKey: ['fs-worksheet-templates'],
    queryFn: () => fieldServiceApi.getWorksheetTemplates().then((r) => r.data),
  })

  const [editTarget, setEditTarget]   = useState<WorksheetTemplate | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<WorksheetTemplate | null>(null)
  const [showCreate, setShowCreate]   = useState(false)

  const deleteMutation = useMutation({
    mutationFn: (id: number) => fieldServiceApi.deleteWorksheetTemplate(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['fs-worksheet-templates'] })
      setDeleteTarget(null)
    },
  })

  const setDefaultMutation = useMutation({
    mutationFn: (id: number) => fieldServiceApi.updateWorksheetTemplate(id, { is_default: true }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['fs-worksheet-templates'] }),
  })

  return (
    <div className="p-6 space-y-5">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Worksheet Templates</h1>
          <p className="text-sm text-gray-500">Configure reusable checklist / inspection forms for Work Orders.</p>
        </div>
        <Button onClick={() => setShowCreate(true)}>
          <Plus className="mr-2 h-4 w-4" />
          New Template
        </Button>
      </div>

      {/* List */}
      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-32 rounded-xl" />
          ))}
        </div>
      ) : !templates?.length ? (
        <div className="rounded-xl border-2 border-dashed border-gray-200 p-12 text-center">
          <FileText className="mx-auto h-10 w-10 text-gray-300" />
          <p className="mt-3 text-sm font-medium text-gray-500">No templates yet</p>
          <p className="text-xs text-gray-400">Create a template to attach reusable worksheets to Work Orders.</p>
          <Button className="mt-4" onClick={() => setShowCreate(true)}>
            <Plus className="mr-2 h-4 w-4" /> New Template
          </Button>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {templates.map((tpl) => (
            <Card key={tpl.id} className="relative overflow-hidden">
              {tpl.is_default && (
                <div className="absolute top-2 right-2">
                  <Badge className="bg-emerald-100 text-emerald-700 text-[10px]">
                    <CheckCircle2 className="mr-1 h-3 w-3" /> Default
                  </Badge>
                </div>
              )}
              <CardHeader className="pb-2">
                <CardTitle className="text-base">{tpl.name}</CardTitle>
                {tpl.description && (
                  <p className="text-xs text-gray-500 line-clamp-2">{tpl.description}</p>
                )}
              </CardHeader>
              <CardContent>
                <p className="text-xs text-gray-500 mb-3">
                  {tpl.fields?.length ?? 0} field{tpl.fields?.length !== 1 ? 's' : ''}
                </p>
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" className="flex-1" onClick={() => setEditTarget(tpl)}>
                    <Pencil className="mr-1 h-3.5 w-3.5" /> Edit
                  </Button>
                  {!tpl.is_default && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="text-gray-400 hover:text-emerald-600"
                      title="Set as default"
                      onClick={() => setDefaultMutation.mutate(tpl.id)}
                    >
                      <CheckCircle2 className="h-4 w-4" />
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-gray-400 hover:text-red-500"
                    onClick={() => setDeleteTarget(tpl)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Create dialog */}
      {showCreate && (
        <TemplateFormDialog
          onClose={() => setShowCreate(false)}
          onSaved={() => {
            qc.invalidateQueries({ queryKey: ['fs-worksheet-templates'] })
            setShowCreate(false)
          }}
        />
      )}

      {/* Edit dialog */}
      {editTarget && (
        <TemplateFormDialog
          template={editTarget}
          onClose={() => setEditTarget(null)}
          onSaved={() => {
            qc.invalidateQueries({ queryKey: ['fs-worksheet-templates'] })
            setEditTarget(null)
          }}
        />
      )}

      {/* Delete confirm */}
      <AlertDialog open={!!deleteTarget} onOpenChange={(o) => !o && setDeleteTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Template?</AlertDialogTitle>
            <AlertDialogDescription>
              "{deleteTarget?.name}" will be deleted. Work Orders using it will lose their worksheet configuration.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-red-600 hover:bg-red-700"
              onClick={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

// ── Template form dialog ──────────────────────────────────────────────────────

function TemplateFormDialog({
  template,
  onClose,
  onSaved,
}: {
  template?: WorksheetTemplate
  onClose: () => void
  onSaved: () => void
}) {
  const [name, setName]             = useState(template?.name ?? '')
  const [description, setDesc]      = useState(template?.description ?? '')
  const [isDefault, setIsDefault]   = useState(template?.is_default ?? false)
  const [fields, setFields]         = useState<WorksheetField[]>((template?.fields as WorksheetField[]) ?? [])
  const [saving, setSaving]         = useState(false)
  const [error, setError]           = useState<string | null>(null)

  const handleSave = async () => {
    if (!name.trim()) { setError('Template name is required.'); return }
    setSaving(true)
    setError(null)
    try {
      const payload = { name: name.trim(), description: description.trim() || null, is_default: isDefault, fields }
      if (template) {
        await fieldServiceApi.updateWorksheetTemplate(template.id, payload)
      } else {
        await fieldServiceApi.createWorksheetTemplate(payload)
      }
      onSaved()
    } catch (e: any) {
      setError(e?.response?.data?.message ?? 'Failed to save template.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{template ? 'Edit Template' : 'New Worksheet Template'}</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {error && (
            <div className="rounded-md bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>
          )}

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1">
              <Label>Name <span className="text-red-500">*</span></Label>
              <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. AC Installation Checklist" />
            </div>
            <div className="space-y-1">
              <Label>Set as default</Label>
              <div className="flex h-10 items-center">
                <Switch checked={isDefault} onCheckedChange={setIsDefault} />
              </div>
            </div>
          </div>

          <div className="space-y-1">
            <Label>Description</Label>
            <Textarea
              value={description}
              onChange={(e) => setDesc(e.target.value)}
              placeholder="Describe when and how this worksheet is used…"
              rows={2}
            />
          </div>

          <div className="space-y-1">
            <Label className="text-sm font-semibold">Fields</Label>
            <WorksheetBuilder fields={fields} onChange={setFields} />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={handleSave} disabled={saving}>
            {saving ? 'Saving…' : template ? 'Save changes' : 'Create template'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
