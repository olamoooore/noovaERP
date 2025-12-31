import { useEffect, useState, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import type { Editor } from 'grapesjs'
import MonacoEditor from '@monaco-editor/react'
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
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

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
    header_js?: string
    body_js?: string
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
  const [activeDevice, setActiveDevice] = useState<string>('Desktop')
  
  // Content states
  const [headerJs, setHeaderJs] = useState('')
  const [bodyJs, setBodyJs] = useState('')
  
  // Dialog states
  const [showCodeEditor, setShowCodeEditor] = useState(false)
  const [showAddPage, setShowAddPage] = useState(false)
  const [codeHtml, setCodeHtml] = useState('')
  const [codeCss, setCodeCss] = useState('')
  const [codeHeaderJs, setCodeHeaderJs] = useState('')
  const [codeBodyJs, setCodeBodyJs] = useState('')
  const [codeView, setCodeView] = useState<'html' | 'css' | 'js' | 'split'>('split')
  const [newPageTitle, setNewPageTitle] = useState('')
  const [newPageSlug, setNewPageSlug] = useState('')

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

  // Sync JS content
  useEffect(() => {
    if (pageData?.content) {
      setHeaderJs(pageData.content.header_js || '')
      setBodyJs(pageData.content.body_js || '')
    }
  }, [pageData])

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

  // Device switching functions
  const setDevice = (device: string) => {
    if (!editorInstance) {
      console.warn('Editor not ready yet')
      return
    }
    editorInstance.setDevice(device)
    setActiveDevice(device)
  }

  // GrapesJS command functions
  const runCommand = (command: string) => {
    if (!editorInstance) return
    editorInstance.runCommand(command)
  }

  const toggleCommand = (command: string) => {
    if (!editorInstance) return
    if (editorInstance.Commands.isActive(command)) {
      editorInstance.stopCommand(command)
    } else {
      editorInstance.runCommand(command)
    }
  }

  // Save mutation
  const saveMutation = useMutation({
    mutationFn: async (data: { html: string; css: string; components: any; styles: any; header_js?: string; body_js?: string }) => {
      if (!selectedPageId) {
        throw new Error('No page selected')
      }
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
    onError: (error: any) => {
      setIsSaving(false)
      toast({ 
        title: 'Error', 
        description: error?.response?.data?.message || 'Failed to save.', 
        variant: 'destructive' 
      })
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

  // Add page mutation
  const addPageMutation = useMutation({
    mutationFn: async (data: { title: string; slug?: string }) => {
      const response = await api.post(`/website-builder/websites/${id}/pages`, data)
      return response.data
    },
    onSuccess: (newPage) => {
      toast({ title: 'Success', description: 'Page created successfully.' })
      queryClient.invalidateQueries({ queryKey: ['website', id] })
      setSelectedPageId(newPage.id)
      setShowAddPage(false)
      setNewPageTitle('')
      setNewPageSlug('')
    },
    onError: (error: any) => {
      toast({ 
        title: 'Error', 
        description: error?.response?.data?.message || 'Failed to create page.', 
        variant: 'destructive' 
      })
    },
  })

  // Open code editor
  const openCodeEditor = () => {
    if (!editorInstance) return
    // Get HTML and format it with proper indentation
    const rawHtml = editorInstance.getHtml()
    const rawCss = editorInstance.getCss() || ''
    
    // Format HTML with proper line breaks and indentation
    const formatHtml = (html: string) => {
      let formatted = ''
      let indent = 0
      const tab = '  '
      
      // Split by tags but keep the tags
      const tokens = html.split(/(<[^>]+>)/g).filter(Boolean)
      
      tokens.forEach(token => {
        const isTag = token.startsWith('<')
        const isClosingTag = token.startsWith('</')
        const isSelfClosing = token.endsWith('/>') || /^<(img|br|hr|input|meta|link|area|base|col|embed|param|source|track|wbr)/i.test(token)
        const isVoidTag = /^<(img|br|hr|input|meta|link|area|base|col|embed|param|source|track|wbr)/i.test(token)
        
        if (isTag) {
          if (isClosingTag) {
            indent = Math.max(0, indent - 1)
            formatted += '\n' + tab.repeat(indent) + token
          } else if (isSelfClosing || isVoidTag) {
            formatted += '\n' + tab.repeat(indent) + token
          } else {
            formatted += '\n' + tab.repeat(indent) + token
            indent++
          }
        } else {
          const trimmed = token.trim()
          if (trimmed) {
            formatted += trimmed
          }
        }
      })
      
      return formatted.trim()
    }
    
    // Format CSS with proper line breaks
    const formatCss = (css: string) => {
      return css
        .replace(/\{/g, ' {\n  ')
        .replace(/;/g, ';\n  ')
        .replace(/\n  \}/g, '\n}')
        .replace(/\}\s*/g, '}\n\n')
        .trim()
    }
    
    setCodeHtml(formatHtml(rawHtml))
    setCodeCss(formatCss(rawCss))
    setCodeHeaderJs(headerJs)
    setCodeBodyJs(bodyJs)
    setShowCodeEditor(true)
  }

  // Apply code changes
  const applyCodeChanges = () => {
    if (!editorInstance) return
    editorInstance.setComponents(codeHtml)
    editorInstance.setStyle(codeCss)
    setHeaderJs(codeHeaderJs)
    setBodyJs(codeBodyJs)
    setShowCodeEditor(false)
    setIsDirty(true)
    toast({ title: 'Applied', description: 'Code changes applied to canvas.' })
  }

  // Handle save
  const handleSave = useCallback((data: { html: string; css: string; components: any; styles: any; header_js?: string; body_js?: string }) => {
    saveMutation.mutate({
      ...data,
      header_js: data.header_js ?? headerJs,
      body_js: data.body_js ?? bodyJs,
    })
  }, [saveMutation, headerJs, bodyJs])

  // Manual save trigger
  const triggerSave = useCallback(() => {
    if (!editorInstance || !selectedPageId) return
    
    handleSave({
      html: editorInstance.getHtml(),
      css: editorInstance.getCss() || '',
      components: editorInstance.getComponents(),
      styles: editorInstance.getStyle(),
    })
  }, [editorInstance, handleSave, selectedPageId])

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
          <script>${headerJs}</script>
        </head>
        <body>
          ${html}
          <script>${bodyJs}</script>
        </body>
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
  <script>
${headerJs}
  </script>
</head>
<body>
${html}
<script>
${bodyJs}
</script>
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
      <div className="flex items-center justify-center h-screen bg-zinc-900">
        <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-zinc-400" />
      </div>
    )
  }

  if (!website) {
    return (
      <div className="flex flex-col items-center justify-center h-screen bg-zinc-900 text-white">
        <p className="text-zinc-400 mb-4">Website not found</p>
        <Button variant="outline" onClick={() => navigate('/website-builder')}>
          Back to websites
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col h-screen bg-zinc-900">
      {/* Top Toolbar */}
      <header className="flex items-center justify-between h-14 px-4 bg-zinc-800 border-b border-zinc-700">
        <div className="flex items-center gap-4">
          <Button 
            variant="ghost" 
            size="icon" 
            className="text-zinc-400 hover:text-white hover:bg-zinc-700"
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
              <SelectTrigger className="w-[180px] bg-zinc-700 border-zinc-600 text-white">
                <SelectValue placeholder="Select page" />
              </SelectTrigger>
              <SelectContent className="bg-zinc-800 border-zinc-700">
                {website.pages.map((page) => (
                  <SelectItem 
                    key={page.id} 
                    value={page.id.toString()}
                    className="text-white hover:bg-zinc-700 focus:bg-zinc-700"
                  >
                    {page.title} {page.is_home && '(Home)'}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            
            {/* Add Page Button */}
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-zinc-400 hover:text-white hover:bg-zinc-700"
              onClick={() => setShowAddPage(true)}
              title="Add new page"
            >
              <Icon icon="solar:add-circle-bold" className="h-5 w-5" />
            </Button>
          </div>
        </div>

        {/* Device Switcher - Center */}
        <div className="flex items-center gap-4">
          {/* Responsive Device Buttons */}
          <div className="flex items-center gap-1 bg-zinc-700 rounded-lg p-1">
            <Button
              variant="ghost"
              size="icon"
              className={`h-8 w-8 ${activeDevice === 'Desktop' ? 'bg-teal-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-600'}`}
              onClick={() => setDevice('Desktop')}
              title="Desktop"
            >
              <Icon icon="solar:monitor-bold" className="h-4 w-4" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className={`h-8 w-8 ${activeDevice === 'Tablet' ? 'bg-teal-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-600'}`}
              onClick={() => setDevice('Tablet')}
              title="Tablet"
            >
              <Icon icon="solar:tablet-bold" className="h-4 w-4" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className={`h-8 w-8 ${activeDevice === 'Mobile' ? 'bg-teal-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-600'}`}
              onClick={() => setDevice('Mobile')}
              title="Mobile"
            >
              <Icon icon="solar:smartphone-bold" className="h-4 w-4" />
            </Button>
          </div>

          {/* GrapesJS Command Buttons */}
          <div className="flex items-center gap-1 bg-zinc-700 rounded-lg p-1">
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-zinc-400 hover:text-white hover:bg-zinc-600"
              onClick={() => toggleCommand('sw-visibility')}
              title="View components"
            >
              <svg className="h-4 w-4" viewBox="0 0 24 24">
                <path fill="currentColor" d="M15,5H17V3H15M15,21H17V19H15M11,5H13V3H11M19,5H21V3H19M19,9H21V7H19M19,21H21V19H19M19,13H21V11H19M19,17H21V15H19M3,5H5V3H3M3,9H5V7H3M3,13H5V11H3M3,17H5V15H3M3,21H5V19H3M11,21H13V19H11M7,21H9V19H7M7,5H9V3H7V5Z"/>
              </svg>
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-zinc-400 hover:text-white hover:bg-zinc-600"
              onClick={() => toggleCommand('fullscreen')}
              title="Fullscreen"
            >
              <svg className="h-4 w-4" viewBox="0 0 24 24">
                <path fill="currentColor" d="M5,5H10V7H7V10H5V5M14,5H19V10H17V7H14V5M17,14H19V19H14V17H17V14M10,17V19H5V14H7V17H10Z"/>
              </svg>
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-zinc-400 hover:text-white hover:bg-zinc-600"
              onClick={openCodeEditor}
              title="View & Edit code"
            >
              <svg className="h-4 w-4" viewBox="0 0 24 24">
                <path fill="currentColor" d="M12.89,3L14.85,3.4L11.11,21L9.15,20.6L12.89,3M19.59,12L16,8.41V5.58L22.42,12L16,18.41V15.58L19.59,12M1.58,12L8,5.58V8.41L4.41,12L8,15.58V18.41L1.58,12Z"/>
              </svg>
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-zinc-400 hover:text-white hover:bg-zinc-600"
              onClick={() => runCommand('core:undo')}
              title="Undo"
            >
              <svg className="h-4 w-4" viewBox="0 0 24 24">
                <path fill="currentColor" d="M20 13.5C20 17.09 17.09 20 13.5 20H6V18H13.5C16 18 18 16 18 13.5S16 9 13.5 9H7.83L10.91 12.09L9.5 13.5L4 8L9.5 2.5L10.92 3.91L7.83 7H13.5C17.09 7 20 9.91 20 13.5Z"/>
              </svg>
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-zinc-400 hover:text-white hover:bg-zinc-600"
              onClick={() => runCommand('core:redo')}
              title="Redo"
            >
              <svg className="h-4 w-4" viewBox="0 0 24 24">
                <path fill="currentColor" d="M10.5 18H18V20H10.5C6.91 20 4 17.09 4 13.5S6.91 7 10.5 7H16.17L13.08 3.91L14.5 2.5L20 8L14.5 13.5L13.09 12.09L16.17 9H10.5C8 9 6 11 6 13.5S8 18 10.5 18Z"/>
              </svg>
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-zinc-400 hover:text-white hover:bg-zinc-600"
              onClick={() => runCommand('upload-file')}
              title="Upload HTML/CSS/JS"
            >
              <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-zinc-400 hover:text-white hover:bg-zinc-600"
              onClick={() => runCommand('gjs-export-zip')}
              title="Export ZIP"
            >
              <svg className="h-4 w-4" viewBox="0 0 24 24">
                <path fill="currentColor" d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/>
              </svg>
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-zinc-400 hover:text-white hover:bg-zinc-600"
              onClick={() => runCommand('core:canvas-clear')}
              title="Clear canvas"
            >
              <svg className="h-4 w-4" viewBox="0 0 24 24">
                <path fill="currentColor" d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z"/>
              </svg>
            </Button>
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
            className="bg-transparent border-zinc-600 text-zinc-300 hover:bg-zinc-700 hover:text-white"
            onClick={handlePreview}
          >
            <Icon icon="solar:eye-bold" className="h-4 w-4 mr-2" />
            Preview
          </Button>
          
          <Button
            variant="outline"
            size="sm"
            className="bg-transparent border-zinc-600 text-zinc-300 hover:bg-zinc-700 hover:text-white"
            onClick={triggerSave}
            disabled={!isDirty || isSaving || !selectedPageId}
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
              <Button size="sm" className="bg-teal-600 hover:bg-teal-700">
                <Icon icon="solar:upload-bold" className="h-4 w-4 mr-2" />
                Publish
                <Icon icon="solar:alt-arrow-down-bold" className="h-4 w-4 ml-2" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="bg-zinc-800 border-zinc-700">
              <DropdownMenuItem 
                className="text-white hover:bg-zinc-700 focus:bg-zinc-700 cursor-pointer"
                onClick={() => publishMutation.mutate()}
              >
                <Icon icon="solar:cloud-upload-bold" className="h-4 w-4 mr-2" />
                Publish Website
              </DropdownMenuItem>
              <DropdownMenuSeparator className="bg-zinc-700" />
              <DropdownMenuItem 
                className="text-white hover:bg-zinc-700 focus:bg-zinc-700 cursor-pointer"
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
            <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-zinc-400" />
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

      {/* Code Editor Dialog */}
      <Dialog open={showCodeEditor} onOpenChange={setShowCodeEditor}>
        <DialogContent className="max-w-6xl max-h-[90vh] bg-zinc-900 border-zinc-700 text-white p-0">
          <div className="flex flex-col h-[85vh]">
            {/* Header */}
            <div className="flex items-center justify-between px-4 py-3 border-b border-zinc-700">
              <DialogTitle className="flex items-center gap-2 text-base">
                <Icon icon="solar:code-bold" className="h-5 w-5 text-teal-500" />
                Edit Code
              </DialogTitle>
              
              {/* View Tabs */}
              <div className="flex items-center gap-1 bg-zinc-800 rounded-lg p-1">
                <button
                  onClick={() => setCodeView('html')}
                  className={`px-3 py-1.5 text-sm rounded-md transition-colors ${
                    codeView === 'html' 
                      ? 'bg-teal-600 text-white' 
                      : 'text-zinc-400 hover:text-white hover:bg-zinc-700'
                  }`}
                >
                  <Icon icon="solar:code-file-bold" className="h-4 w-4 inline mr-1.5" />
                  HTML
                </button>
                <button
                  onClick={() => setCodeView('css')}
                  className={`px-3 py-1.5 text-sm rounded-md transition-colors ${
                    codeView === 'css' 
                      ? 'bg-teal-600 text-white' 
                      : 'text-zinc-400 hover:text-white hover:bg-zinc-700'
                  }`}
                >
                  <Icon icon="solar:pallete-2-bold" className="h-4 w-4 inline mr-1.5" />
                  CSS
                </button>
                <button
                  onClick={() => setCodeView('js')}
                  className={`px-3 py-1.5 text-sm rounded-md transition-colors ${
                    codeView === 'js' 
                      ? 'bg-teal-600 text-white' 
                      : 'text-zinc-400 hover:text-white hover:bg-zinc-700'
                  }`}
                >
                  <Icon icon="solar:code-circle-bold" className="h-4 w-4 inline mr-1.5" />
                  JS
                </button>
                <button
                  onClick={() => setCodeView('split')}
                  className={`px-3 py-1.5 text-sm rounded-md transition-colors ${
                    codeView === 'split' 
                      ? 'bg-teal-600 text-white' 
                      : 'text-zinc-400 hover:text-white hover:bg-zinc-700'
                  }`}
                >
                  <Icon icon="solar:slider-vertical-bold" className="h-4 w-4 inline mr-1.5" />
                  Split
                </button>
              </div>
            </div>
            
            {/* Editor Area */}
            <div className="flex-1 overflow-hidden">
              {codeView === 'split' ? (
                <div className="grid grid-cols-2 h-full divide-x divide-zinc-700">
                  <div className="flex flex-col">
                    <div className="px-3 py-2 bg-zinc-800 border-b border-zinc-700 text-xs text-zinc-400 font-medium">
                      index.html
                    </div>
                    <MonacoEditor
                      height="100%"
                      language="html"
                      theme="vs-dark"
                      value={codeHtml}
                      onChange={(value) => setCodeHtml(value || '')}
                      options={{
                        minimap: { enabled: false },
                        fontSize: 13,
                        lineNumbers: 'on',
                        scrollBeyondLastLine: false,
                        wordWrap: 'off',
                        automaticLayout: true,
                        tabSize: 2,
                        insertSpaces: true,
                        folding: true,
                        lineDecorationsWidth: 10,
                        renderLineHighlight: 'line',
                        scrollbar: {
                          verticalScrollbarSize: 10,
                          horizontalScrollbarSize: 10,
                        },
                      }}
                    />
                  </div>
                  <div className="flex flex-col">
                    <div className="px-3 py-2 bg-zinc-800 border-b border-zinc-700 text-xs text-zinc-400 font-medium">
                      styles.css
                    </div>
                    <MonacoEditor
                      height="100%"
                      language="css"
                      theme="vs-dark"
                      value={codeCss}
                      onChange={(value) => setCodeCss(value || '')}
                      options={{
                        minimap: { enabled: false },
                        fontSize: 13,
                        lineNumbers: 'on',
                        scrollBeyondLastLine: false,
                        wordWrap: 'off',
                        automaticLayout: true,
                        tabSize: 2,
                        insertSpaces: true,
                        folding: true,
                        lineDecorationsWidth: 10,
                        renderLineHighlight: 'line',
                        scrollbar: {
                          verticalScrollbarSize: 10,
                          horizontalScrollbarSize: 10,
                        },
                      }}
                    />
                  </div>
                </div>
              ) : codeView === 'js' ? (
                <div className="grid grid-cols-2 h-full divide-x divide-zinc-700">
                  <div className="flex flex-col">
                    <div className="px-3 py-2 bg-zinc-800 border-b border-zinc-700 text-xs text-zinc-400 font-medium">
                      Header JS (in &lt;head&gt;)
                    </div>
                    <MonacoEditor
                      height="100%"
                      language="javascript"
                      theme="vs-dark"
                      value={codeHeaderJs}
                      onChange={(value) => setCodeHeaderJs(value || '')}
                      options={{
                        minimap: { enabled: false },
                        fontSize: 13,
                        lineNumbers: 'on',
                        scrollBeyondLastLine: false,
                        wordWrap: 'off',
                        automaticLayout: true,
                        tabSize: 2,
                        insertSpaces: true,
                        folding: true,
                        lineDecorationsWidth: 10,
                        renderLineHighlight: 'line',
                        scrollbar: {
                          verticalScrollbarSize: 10,
                          horizontalScrollbarSize: 10,
                        },
                      }}
                    />
                  </div>
                  <div className="flex flex-col">
                    <div className="px-3 py-2 bg-zinc-800 border-b border-zinc-700 text-xs text-zinc-400 font-medium">
                      Body JS (end of &lt;body&gt;)
                    </div>
                    <MonacoEditor
                      height="100%"
                      language="javascript"
                      theme="vs-dark"
                      value={codeBodyJs}
                      onChange={(value) => setCodeBodyJs(value || '')}
                      options={{
                        minimap: { enabled: false },
                        fontSize: 13,
                        lineNumbers: 'on',
                        scrollBeyondLastLine: false,
                        wordWrap: 'off',
                        automaticLayout: true,
                        tabSize: 2,
                        insertSpaces: true,
                        folding: true,
                        lineDecorationsWidth: 10,
                        renderLineHighlight: 'line',
                        scrollbar: {
                          verticalScrollbarSize: 10,
                          horizontalScrollbarSize: 10,
                        },
                      }}
                    />
                  </div>
                </div>
              ) : (
                <div className="flex flex-col h-full">
                  <div className="px-3 py-2 bg-zinc-800 border-b border-zinc-700 text-xs text-zinc-400 font-medium">
                    {codeView === 'html' ? 'index.html' : 'styles.css'}
                  </div>
                  <MonacoEditor
                    height="100%"
                    language={codeView}
                    theme="vs-dark"
                    value={codeView === 'html' ? codeHtml : codeCss}
                    onChange={(value) => {
                      if (codeView === 'html') {
                        setCodeHtml(value || '')
                      } else {
                        setCodeCss(value || '')
                      }
                    }}
                    options={{
                      minimap: { enabled: true },
                      fontSize: 13,
                      lineNumbers: 'on',
                      scrollBeyondLastLine: false,
                      wordWrap: 'off',
                      automaticLayout: true,
                      tabSize: 2,
                      insertSpaces: true,
                      folding: true,
                      lineDecorationsWidth: 10,
                      renderLineHighlight: 'line',
                      scrollbar: {
                        verticalScrollbarSize: 10,
                        horizontalScrollbarSize: 10,
                      },
                    }}
                  />
                </div>
              )}
            </div>
            
            {/* Footer */}
            <div className="flex items-center justify-between px-4 py-3 border-t border-zinc-700 bg-zinc-800/50">
              <div className="text-xs text-zinc-500">
                Press Ctrl+S to format • Changes apply to canvas when you click Apply
              </div>
              <div className="flex items-center gap-2">
                <Button variant="outline" onClick={() => setShowCodeEditor(false)} className="border-zinc-600 text-zinc-300 hover:bg-zinc-700">
                  Cancel
                </Button>
                <Button onClick={applyCodeChanges} className="bg-teal-600 hover:bg-teal-700">
                  <Icon icon="solar:check-circle-bold" className="h-4 w-4 mr-2" />
                  Apply Changes
                </Button>
              </div>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* Add Page Dialog */}
      <Dialog open={showAddPage} onOpenChange={setShowAddPage}>
        <DialogContent className="bg-zinc-900 border-zinc-700 text-white">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:document-add-bold" className="h-5 w-5 text-teal-500" />
              Add New Page
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4 mt-4">
            <div className="space-y-2">
              <Label className="text-zinc-300">Page Title</Label>
              <Input
                value={newPageTitle}
                onChange={(e) => setNewPageTitle(e.target.value)}
                placeholder="e.g., About Us"
                className="bg-zinc-800 border-zinc-700 text-white placeholder:text-zinc-500"
              />
            </div>
            <div className="space-y-2">
              <Label className="text-zinc-300">URL Slug (optional)</Label>
              <Input
                value={newPageSlug}
                onChange={(e) => setNewPageSlug(e.target.value)}
                placeholder="e.g., about-us"
                className="bg-zinc-800 border-zinc-700 text-white placeholder:text-zinc-500"
              />
              <p className="text-xs text-zinc-500">Leave empty to auto-generate from title</p>
            </div>
          </div>
          <DialogFooter className="mt-4">
            <Button variant="outline" onClick={() => setShowAddPage(false)} className="border-zinc-600 text-zinc-300 hover:bg-zinc-800">
              Cancel
            </Button>
            <Button 
              onClick={() => addPageMutation.mutate({ title: newPageTitle, slug: newPageSlug || undefined })}
              disabled={!newPageTitle.trim() || addPageMutation.isPending}
              className="bg-teal-600 hover:bg-teal-700"
            >
              {addPageMutation.isPending ? 'Creating...' : 'Create Page'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
