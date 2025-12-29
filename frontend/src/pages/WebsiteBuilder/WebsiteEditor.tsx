import { useEffect, useState, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import type { Editor } from 'grapesjs'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { useToast } from '@/hooks/use-toast'
import { GrapesEditor } from '@/components/website-builder/GrapesEditor'
import '@/components/website-builder/grapes-custom.css'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

interface Page {
  id: number
  uuid: string
  title: string
  slug: string
  is_home: boolean
  status: string
  content?: {
    html: string
    css: string
    components?: any
    styles?: any
  }
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
  const [editorInstance, setEditorInstance] = useState<Editor | null>(null)
  const [isDirty, setIsDirty] = useState(false)
  const [isSaving, setIsSaving] = useState(false)

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
  const { data: pageData, isLoading: isPageLoading } = useQuery<Page>({
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

  // Track changes
  const handleEditorLoad = useCallback((editor: Editor) => {
    setEditorInstance(editor)
    
    // Listen for changes
    editor.on('change:changesCount', () => {
      setIsDirty(true)
    })
  }, [])

  // Save mutation
  const saveMutation = useMutation({
    mutationFn: async (data: { html: string; css: string; components: any; styles: any }) => {
      setIsSaving(true)
      await api.put(`/website-builder/pages/${selectedPageId}`, {
        content: data,
      })
    },
    onSuccess: () => {
      setIsDirty(false)
      setIsSaving(false)
      toast({ title: 'Saved', description: 'Page saved successfully.' })
      queryClient.invalidateQueries({ queryKey: ['page', selectedPageId] })
    },
    onError: () => {
      setIsSaving(false)
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

  // Handle save
  const handleSave = useCallback((data: { html: string; css: string; components: any; styles: any }) => {
    saveMutation.mutate(data)
  }, [saveMutation])

  // Manual save trigger
  const triggerSave = useCallback(() => {
    if (!editorInstance) return
    
    handleSave({
      html: editorInstance.getHtml(),
      css: editorInstance.getCss() || '',
      components: editorInstance.getComponents(),
      styles: editorInstance.getStyle(),
    })
  }, [editorInstance, handleSave])

  // Handle page change
  const handlePageChange = (pageId: string) => {
    if (isDirty) {
      // TODO: Show confirmation dialog
      const confirm = window.confirm('You have unsaved changes. Do you want to save before switching pages?')
      if (confirm) {
        triggerSave()
      }
    }
    setSelectedPageId(Number(pageId))
    setIsDirty(false)
  }

  // Preview in new tab
  const handlePreview = () => {
    if (!editorInstance) return
    
    const html = editorInstance.getHtml()
    const css = editorInstance.getCss()
    
    const previewHtml = `
      <!DOCTYPE html>
      <html>
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
          <style>${css}</style>
        </head>
        <body>${html}</body>
      </html>
    `
    
    const blob = new Blob([previewHtml], { type: 'text/html' })
    const url = URL.createObjectURL(blob)
    window.open(url, '_blank')
  }

  // Export HTML
  const handleExport = () => {
    if (!editorInstance) return
    
    const html = editorInstance.getHtml()
    const css = editorInstance.getCss()
    
    const fullHtml = `
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${website?.name || 'Website'}</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <style>
${css}
  </style>
</head>
<body>
${html}
</body>
</html>`
    
    const blob = new Blob([fullHtml], { type: 'text/html' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${website?.slug || 'website'}-${pageData?.slug || 'page'}.html`
    a.click()
    URL.revokeObjectURL(url)
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen bg-slate-900">
        <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-slate-400" />
      </div>
    )
  }

  if (!website) {
    return (
      <div className="flex flex-col items-center justify-center h-screen bg-slate-900 text-white">
        <p className="text-slate-400 mb-4">Website not found</p>
        <Button variant="outline" onClick={() => navigate('/website-builder')}>
          Back to websites
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col h-screen bg-slate-900">
      {/* Top Toolbar */}
      <header className="flex items-center justify-between h-14 px-4 bg-slate-800 border-b border-slate-700">
        <div className="flex items-center gap-4">
          <Button 
            variant="ghost" 
            size="icon" 
            className="text-slate-400 hover:text-white hover:bg-slate-700"
            onClick={() => navigate('/website-builder')}
          >
            <Icon icon="solar:arrow-left-bold" className="h-5 w-5" />
          </Button>
          
          <div className="flex items-center gap-3">
            <div>
              <h1 className="font-semibold text-white">{website.name}</h1>
            </div>
            
            {/* Page Selector */}
            <Select value={selectedPageId?.toString()} onValueChange={handlePageChange}>
              <SelectTrigger className="w-[180px] bg-slate-700 border-slate-600 text-white">
                <SelectValue placeholder="Select page" />
              </SelectTrigger>
              <SelectContent className="bg-slate-800 border-slate-700">
                {website.pages.map((page) => (
                  <SelectItem 
                    key={page.id} 
                    value={page.id.toString()}
                    className="text-white hover:bg-slate-700 focus:bg-slate-700"
                  >
                    {page.title} {page.is_home && '(Home)'}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>

        {/* Actions */}
        <div className="flex items-center gap-2">
          {isDirty && (
            <span className="text-xs text-amber-400 mr-2">Unsaved changes</span>
          )}
          
          <Button
            variant="outline"
            size="sm"
            className="bg-transparent border-slate-600 text-slate-300 hover:bg-slate-700 hover:text-white"
            onClick={handlePreview}
          >
            <Icon icon="solar:eye-bold" className="h-4 w-4 mr-2" />
            Preview
          </Button>
          
          <Button
            variant="outline"
            size="sm"
            className="bg-transparent border-slate-600 text-slate-300 hover:bg-slate-700 hover:text-white"
            onClick={triggerSave}
            disabled={!isDirty || isSaving}
          >
            {isSaving ? (
              <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
            ) : (
              <Icon icon="solar:diskette-bold" className="h-4 w-4 mr-2" />
            )}
            Save
          </Button>
          
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button size="sm" className="bg-blue-600 hover:bg-blue-700">
                <Icon icon="solar:upload-bold" className="h-4 w-4 mr-2" />
                Publish
                <Icon icon="solar:alt-arrow-down-bold" className="h-4 w-4 ml-2" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="bg-slate-800 border-slate-700">
              <DropdownMenuItem 
                className="text-white hover:bg-slate-700 focus:bg-slate-700 cursor-pointer"
                onClick={() => publishMutation.mutate()}
              >
                <Icon icon="solar:cloud-upload-bold" className="h-4 w-4 mr-2" />
                Publish Website
              </DropdownMenuItem>
              <DropdownMenuSeparator className="bg-slate-700" />
              <DropdownMenuItem 
                className="text-white hover:bg-slate-700 focus:bg-slate-700 cursor-pointer"
                onClick={handleExport}
              >
                <Icon icon="solar:download-bold" className="h-4 w-4 mr-2" />
                Export HTML
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>

      {/* Editor */}
      <div className="flex-1 overflow-hidden">
        {isPageLoading ? (
          <div className="flex items-center justify-center h-full">
            <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-slate-400" />
          </div>
        ) : (
          <GrapesEditor
            key={selectedPageId} // Force remount on page change
            initialContent={pageData?.content}
            onSave={handleSave}
            onLoad={handleEditorLoad}
            projectId={selectedPageId || undefined}
          />
        )}
      </div>
    </div>
  )
}
