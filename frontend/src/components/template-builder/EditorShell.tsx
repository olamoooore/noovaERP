import { useEffect, useRef, useState } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { DndProvider } from 'react-dnd'
import { HTML5Backend } from 'react-dnd-html5-backend'
import { useTemplateEditorStore } from '@/store/templateEditorStore'
import { templateBuilderApi } from '@/lib/templateBuilderApi'
import { DOCUMENT_TYPE_LABELS } from '@/types/template-builder'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { toast } from '@/hooks/use-toast'
import { BlocksPalette } from './BlocksPalette'
import { DocumentCanvas } from './DocumentCanvas'
import { PropertyPanel } from './PropertyPanel'
import { PreviewPane } from './PreviewPane'
import { ArrowLeft, Save, Send, Eye, Pencil, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'

export function EditorShell({ templateId }: { templateId: string }) {
  const navigate = useNavigate()
  const [view, setView] = useState<'edit' | 'preview'>('edit')
  const loadedId = useRef<string | null>(null)

  const template = useTemplateEditorStore((s) => s.template)
  const dirty = useTemplateEditorStore((s) => s.dirty)
  const loadTemplate = useTemplateEditorStore((s) => s.loadTemplate)
  const updateMeta = useTemplateEditorStore((s) => s.updateMeta)
  const markSaved = useTemplateEditorStore((s) => s.markSaved)
  const previewHtml = useTemplateEditorStore((s) => s.previewHtml)
  const previewWarnings = useTemplateEditorStore((s) => s.previewWarnings)
  const loadingPreview = useTemplateEditorStore((s) => s.loadingPreview)
  const setPreview = useTemplateEditorStore((s) => s.setPreview)
  const setLoadingPreview = useTemplateEditorStore((s) => s.setLoadingPreview)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['tb-template', templateId],
    queryFn: () => templateBuilderApi.get(templateId),
    enabled: !!templateId,
  })

  useEffect(() => {
    if (data && loadedId.current !== data.id) {
      loadTemplate(data)
      loadedId.current = data.id
    }
  }, [data, loadTemplate])

  const saveMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => templateBuilderApi.update(templateId, payload),
    onSuccess: (updated) => {
      markSaved(updated)
      toast({ title: 'Saved', description: 'Template draft updated.' })
    },
    onError: () => toast({ title: 'Save failed', variant: 'destructive' }),
  })

  const publishMutation = useMutation({
    mutationFn: (note?: string) => templateBuilderApi.publish(templateId, note),
    onSuccess: (updated) => {
      markSaved(updated)
      toast({ title: 'Published', description: `Version ${updated.published_version} is now live.` })
    },
    onError: () => toast({ title: 'Publish failed', variant: 'destructive' }),
  })

  const handleSave = () => {
    if (!template) return
    saveMutation.mutate({
      name: template.name,
      document_type: template.document_type,
      format: template.format,
      orientation: template.orientation,
      default_font: template.default_font,
      layout_config: template.layout_config,
    })
  }

  const handlePreview = () => {
    if (!template) return
    setLoadingPreview(true)
    templateBuilderApi
      .preview(templateId, { layout_config: template.layout_config, mode: 'block', document_type: template.document_type })
      .then((r) => setPreview(r.html, r.warnings))
      .catch(() => toast({ title: 'Preview failed', variant: 'destructive' }))
      .finally(() => setLoadingPreview(false))
  }

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }
  if (isError || !template) {
    return (
      <div className="flex h-screen flex-col items-center justify-center gap-3">
        <p className="text-sm text-muted-foreground">Template could not be loaded.</p>
        <Button variant="outline" onClick={() => navigate('/template-builder')}>Back to templates</Button>
      </div>
    )
  }

  const statusBadge =
    template.status === 'published' ? <Badge className="bg-emerald-600">Published v{template.published_version}</Badge>
    : template.status === 'archived' ? <Badge variant="secondary">Archived</Badge>
    : <Badge variant="outline">Draft</Badge>

  return (
    <div className="flex h-screen flex-col">
      {/* Toolbar */}
      <header className="flex items-center gap-2 border-b bg-background px-3 py-2">
        <Button variant="ghost" size="sm" onClick={() => navigate('/template-builder')}>
          <ArrowLeft className="mr-1 h-4 w-4" /> Templates
        </Button>

        <Input
          className="h-8 w-64 text-sm"
          value={template.name}
          onChange={(e) => updateMeta({ name: e.target.value })}
        />

        <Badge variant="secondary">{DOCUMENT_TYPE_LABELS[template.document_type]}</Badge>
        {statusBadge}
        {dirty && <span className="text-[11px] text-amber-600">● unsaved</span>}

        <div className="ml-auto flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => setView(view === 'edit' ? 'preview' : 'edit')}>
            {view === 'edit' ? <><Eye className="mr-1.5 h-3.5 w-3.5" /> Preview</> : <><Pencil className="mr-1.5 h-3.5 w-3.5" /> Edit</>}
          </Button>
          <Button variant="outline" size="sm" onClick={handleSave} disabled={!dirty || saveMutation.isPending}>
            {saveMutation.isPending ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Save className="mr-1.5 h-3.5 w-3.5" />}
            Save
          </Button>
          <Button size="sm" onClick={() => publishMutation.mutate(undefined)} disabled={publishMutation.isPending}>
            {publishMutation.isPending ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Send className="mr-1.5 h-3.5 w-3.5" />}
            Publish
          </Button>
        </div>
      </header>

      {/* Body */}
      <DndProvider backend={HTML5Backend}>
        <div className="flex flex-1 overflow-hidden">
          {view === 'edit' ? (
            <>
              <aside className="w-56 shrink-0 border-r bg-background">
                <BlocksPalette />
              </aside>
              <main className="flex-1 overflow-hidden">
                <DocumentCanvas />
              </main>
              <aside className="w-80 shrink-0 border-l bg-background">
                <PropertyPanel />
              </aside>
            </>
          ) : (
            <main className="flex-1 overflow-hidden">
              <PreviewPane
                html={previewHtml}
                warnings={previewWarnings}
                loading={loadingPreview}
                onRefresh={handlePreview}
              />
            </main>
          )}
        </div>
      </DndProvider>
    </div>
  )
}
