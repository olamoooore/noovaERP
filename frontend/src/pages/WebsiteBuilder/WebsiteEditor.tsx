import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { useToast } from '@/hooks/use-toast'
import { useBuilderStore, BuilderNode } from '@/store/websiteBuilderStore'
import { Canvas } from '@/components/website-builder/Canvas'
import { ComponentPanel } from '@/components/website-builder/ComponentPanel'
import { PropertiesPanel } from '@/components/website-builder/PropertiesPanel'
import { LayersPanel } from '@/components/website-builder/LayersPanel'

interface Page {
  id: number
  uuid: string
  title: string
  slug: string
  is_home: boolean
  status: string
  content?: BuilderNode
}

interface Website {
  id: number
  name: string
  slug: string
  status: string
  pages: Page[]
}

export function WebsiteEditor() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  
  const [selectedPageId, setSelectedPageId] = useState<number | null>(null)
  const [leftPanel, setLeftPanel] = useState<'components' | 'layers'>('components')
  
  const {
    content,
    setContent,
    setWebsite,
    previewMode,
    setPreviewMode,
    isDirty,
    setSaving,
    markSaved,
  } = useBuilderStore()

  // Fetch website
  const { data: website, isLoading } = useQuery<Website>({
    queryKey: ['website', id],
    queryFn: async () => {
      const response = await api.get(`/website-builder/websites/${id}`)
      return response.data
    },
    enabled: !!id,
  })

  // Fetch page content
  const { data: pageData } = useQuery({
    queryKey: ['page', selectedPageId],
    queryFn: async () => {
      const response = await api.get(`/website-builder/pages/${selectedPageId}`)
      return response.data
    },
    enabled: !!selectedPageId,
  })

  // Auto-select first page
  useEffect(() => {
    if (website?.pages?.length && !selectedPageId) {
      const homePage = website.pages.find(p => p.is_home) || website.pages[0]
      setSelectedPageId(homePage.id)
    }
  }, [website, selectedPageId])

  // Load page content into store
  useEffect(() => {
    if (pageData?.content) {
      setContent(pageData.content)
      setWebsite(Number(id), pageData.id, pageData.title)
    }
  }, [pageData, id, setContent, setWebsite])

  // Save mutation
  const saveMutation = useMutation({
    mutationFn: async () => {
      setSaving(true)
      await api.put(`/website-builder/pages/${selectedPageId}`, {
        content,
      })
    },
    onSuccess: () => {
      markSaved()
      setSaving(false)
      toast({ title: 'Saved', description: 'Page saved successfully.' })
    },
    onError: () => {
      setSaving(false)
      toast({ title: 'Error', description: 'Failed to save.', variant: 'destructive' })
    },
  })

  // Publish mutation
  const publishMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/website-builder/websites/${id}/publish`)
    },
    onSuccess: () => {
      toast({ title: 'Publishing', description: 'Website is being published...' })
      queryClient.invalidateQueries({ queryKey: ['website', id] })
    },
  })

  // Keyboard shortcuts
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 's') {
        e.preventDefault()
        if (isDirty && selectedPageId) {
          saveMutation.mutate()
        }
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [isDirty, selectedPageId])

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!website) {
    return (
      <div className="flex flex-col items-center justify-center h-full">
        <p className="text-muted-foreground">Website not found</p>
        <Button variant="link" onClick={() => navigate('/website-builder')}>
          Back to websites
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col h-screen bg-muted">
      {/* Toolbar */}
      <header className="flex items-center justify-between h-14 px-4 bg-background border-b">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate('/website-builder')}>
            <Icon icon="solar:arrow-left-bold" className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="font-semibold">{website.name}</h1>
            <p className="text-xs text-muted-foreground">
              {website.pages.find(p => p.id === selectedPageId)?.title || 'No page selected'}
            </p>
          </div>
        </div>

        {/* Viewport Toggles */}
        <div className="flex items-center gap-1 bg-muted rounded-lg p-1">
          <Button
            variant={previewMode === 'desktop' ? 'secondary' : 'ghost'}
            size="icon"
            className="h-8 w-8"
            onClick={() => setPreviewMode('desktop')}
          >
            <Icon icon="solar:monitor-bold" className="h-4 w-4" />
          </Button>
          <Button
            variant={previewMode === 'tablet' ? 'secondary' : 'ghost'}
            size="icon"
            className="h-8 w-8"
            onClick={() => setPreviewMode('tablet')}
          >
            <Icon icon="solar:tablet-bold" className="h-4 w-4" />
          </Button>
          <Button
            variant={previewMode === 'mobile' ? 'secondary' : 'ghost'}
            size="icon"
            className="h-8 w-8"
            onClick={() => setPreviewMode('mobile')}
          >
            <Icon icon="solar:smartphone-bold" className="h-4 w-4" />
          </Button>
        </div>

        {/* Actions */}
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => saveMutation.mutate()}
            disabled={!isDirty || saveMutation.isPending}
          >
            {saveMutation.isPending ? (
              <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
            ) : (
              <Icon icon="solar:diskette-bold" className="h-4 w-4 mr-2" />
            )}
            Save
          </Button>
          <Button
            size="sm"
            onClick={() => publishMutation.mutate()}
            disabled={publishMutation.isPending}
          >
            <Icon icon="solar:upload-bold" className="h-4 w-4 mr-2" />
            Publish
          </Button>
        </div>
      </header>

      {/* Main Layout */}
      <div className="flex flex-1 overflow-hidden">
        {/* Left Sidebar */}
        <aside className="w-64 bg-background border-r flex flex-col">
          <div className="flex border-b">
            <button
              className={`flex-1 py-2 text-sm font-medium ${leftPanel === 'components' ? 'bg-muted' : ''}`}
              onClick={() => setLeftPanel('components')}
            >
              Components
            </button>
            <button
              className={`flex-1 py-2 text-sm font-medium ${leftPanel === 'layers' ? 'bg-muted' : ''}`}
              onClick={() => setLeftPanel('layers')}
            >
              Layers
            </button>
          </div>
          <div className="flex-1 overflow-auto">
            {leftPanel === 'components' ? <ComponentPanel /> : <LayersPanel />}
          </div>
        </aside>

        {/* Canvas */}
        <main className="flex-1 overflow-auto p-8">
          <Canvas />
        </main>

        {/* Right Sidebar - Properties */}
        <aside className="w-72 bg-background border-l overflow-auto">
          <PropertiesPanel />
        </aside>
      </div>
    </div>
  )
}
