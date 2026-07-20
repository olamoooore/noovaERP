import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'

interface Website {
  id: number
  uuid: string
  name: string
  slug: string
  status: 'draft' | 'published' | 'archived'
  published_at: string | null
  created_at: string
  pages_count?: number
}

export function WebsitesIndex() {
  const { toast } = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [showCreateDialog, setShowCreateDialog] = useState(false)
  const [newWebsiteName, setNewWebsiteName] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['websites', search],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: 50 }
      if (search) params.search = search
      const response = await api.get('/website-builder/websites', { params })
      return response.data
    },
  })

  const createMutation = useMutation({
    mutationFn: async (name: string) => {
      const response = await api.post('/website-builder/websites', { name })
      return response.data.data ?? response.data
    },
    onSuccess: (website) => {
      toast({ title: 'Website Created', description: `${website.name} created successfully.` })
      queryClient.invalidateQueries({ queryKey: ['websites'] })
      setShowCreateDialog(false)
      setNewWebsiteName('')
      navigate(`/website-builder/${website.id}`)
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to create website.', variant: 'destructive' })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/website-builder/websites/${id}`)
    },
    onSuccess: () => {
      toast({ title: 'Website Deleted' })
      queryClient.invalidateQueries({ queryKey: ['websites'] })
    },
  })

  const websites: Website[] = data?.data || []

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'published':
        return <Badge className="bg-green-500">Published</Badge>
      case 'draft':
        return <Badge variant="secondary">Draft</Badge>
      case 'archived':
        return <Badge variant="outline">Archived</Badge>
      default:
        return <Badge variant="outline">{status}</Badge>
    }
  }

  return (
    <div className="flex flex-col h-full p-6">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold">Website Builder</h1>
          <p className="text-muted-foreground">Create and manage your websites</p>
        </div>
        <Button onClick={() => setShowCreateDialog(true)}>
          <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
          New Website
        </Button>
      </div>

      {/* Search */}
      <div className="relative mb-6 max-w-md">
        <Icon icon="solar:magnifer-bold" className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
        <Input
          placeholder="Search websites..."
          className="pl-9"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {/* Websites Grid */}
      <ScrollArea className="flex-1">
        {isLoading ? (
          <div className="flex justify-center py-12">
            <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
          </div>
        ) : websites.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16 text-muted-foreground">
            <Icon icon="solar:layers-minimalistic-bold" className="h-16 w-16 mb-4 opacity-20" />
            <p className="text-lg">No websites yet</p>
            <p className="text-sm mb-4">Create your first website to get started</p>
            <Button onClick={() => setShowCreateDialog(true)}>
              <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
              Create Website
            </Button>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {websites.map((website) => (
              <Card
                key={website.id}
                className="group cursor-pointer hover:shadow-lg transition-shadow"
                onClick={() => navigate(`/website-builder/${website.id}`)}
              >
                <CardContent className="p-0">
                  {/* Preview */}
                  <div className="h-40 bg-gradient-to-br from-primary/10 to-primary/5 flex items-center justify-center rounded-t-lg">
                    <Icon icon="solar:browser-bold" className="h-12 w-12 text-primary/30" />
                  </div>
                  
                  {/* Info */}
                  <div className="p-4">
                    <div className="flex items-start justify-between mb-2">
                      <h3 className="font-semibold truncate">{website.name}</h3>
                      {getStatusBadge(website.status)}
                    </div>
                    <p className="text-sm text-muted-foreground mb-3">
                      <a 
                        href={`${window.location.origin}${(import.meta.env.VITE_BASE_PATH || '').replace(/\/+$/, '')}/w/${website.slug}`} 
                        target="_blank" 
                        rel="noopener noreferrer"
                        className="hover:text-primary hover:underline"
                        onClick={(e) => e.stopPropagation()}
                      >
                        {window.location.origin}{(import.meta.env.VITE_BASE_PATH || '').replace(/\/+$/, '')}/w/{website.slug}
                      </a>
                    </p>
                    
                    <div className="flex items-center justify-between">
                      <span className="text-xs text-muted-foreground">
                        {new Date(website.created_at).toLocaleDateString()}
                      </span>
                      <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7"
                          title="View Live Site"
                          onClick={(e) => {
                            e.stopPropagation()
                            window.open(`${window.location.origin}${(import.meta.env.VITE_BASE_PATH || '').replace(/\/+$/, '')}/w/${website.slug}`, '_blank')
                          }}
                        >
                          <Icon icon="solar:eye-bold" className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7"
                          onClick={(e) => {
                            e.stopPropagation()
                            navigate(`/website-builder/${website.id}`)
                          }}
                        >
                          <Icon icon="solar:pen-bold" className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7 text-destructive"
                          onClick={(e) => {
                            e.stopPropagation()
                            if (confirm('Delete this website?')) {
                              deleteMutation.mutate(website.id)
                            }
                          }}
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-3.5 w-3.5" />
                        </Button>
                      </div>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </ScrollArea>

      {/* Create Dialog */}
      <Dialog open={showCreateDialog} onOpenChange={setShowCreateDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Create New Website</DialogTitle>
          </DialogHeader>
          <div className="py-4">
            <Input
              placeholder="Website name"
              value={newWebsiteName}
              onChange={(e) => setNewWebsiteName(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && newWebsiteName.trim()) {
                  createMutation.mutate(newWebsiteName.trim())
                }
              }}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCreateDialog(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => createMutation.mutate(newWebsiteName.trim())}
              disabled={!newWebsiteName.trim() || createMutation.isPending}
            >
              {createMutation.isPending && (
                <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
              )}
              Create
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
