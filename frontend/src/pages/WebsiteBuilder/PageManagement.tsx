import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { useToast } from '@/hooks/use-toast'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'

interface Page {
  id: number
  uuid: string
  title: string
  slug: string
  is_home: boolean
  status: string
  seo_title?: string
  seo_description?: string
  sort_order: number
  created_at: string
  updated_at: string
}

interface Website {
  id: number
  name: string
  slug: string
  status: string
  pages: Page[]
}

export function PageManagement() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [showEditDialog, setShowEditDialog] = useState(false)
  const [showDeleteDialog, setShowDeleteDialog] = useState(false)
  const [showLinkDialog, setShowLinkDialog] = useState(false)
  const [selectedPage, setSelectedPage] = useState<Page | null>(null)
  const [editForm, setEditForm] = useState({
    title: '',
    slug: '',
    seo_title: '',
    seo_description: '',
  })

  // Fetch website with pages
  const { data: website, isLoading } = useQuery<Website>({
    queryKey: ['website', id],
    queryFn: async () => {
      const response = await api.get(`/website-builder/websites/${id}`)
      return response.data
    },
    enabled: !!id,
  })

  // Update page mutation
  const updatePageMutation = useMutation({
    mutationFn: async (data: { pageId: number; updates: any }) => {
      await api.put(`/website-builder/pages/${data.pageId}`, data.updates)
    },
    onSuccess: () => {
      toast({ title: 'Success', description: 'Page updated successfully.' })
      queryClient.invalidateQueries({ queryKey: ['website', id] })
      setShowEditDialog(false)
      setSelectedPage(null)
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error?.response?.data?.message || 'Failed to update page.',
        variant: 'destructive',
      })
    },
  })

  // Delete page mutation
  const deletePageMutation = useMutation({
    mutationFn: async (pageId: number) => {
      await api.delete(`/website-builder/pages/${pageId}`)
    },
    onSuccess: () => {
      toast({ title: 'Success', description: 'Page deleted successfully.' })
      queryClient.invalidateQueries({ queryKey: ['website', id] })
      setShowDeleteDialog(false)
      setSelectedPage(null)
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error?.response?.data?.message || 'Failed to delete page.',
        variant: 'destructive',
      })
    },
  })

  // Set home page mutation
  const setHomePageMutation = useMutation({
    mutationFn: async (pageId: number) => {
      await api.put(`/website-builder/pages/${pageId}`, { is_home: true })
    },
    onSuccess: () => {
      toast({ title: 'Success', description: 'Home page updated.' })
      queryClient.invalidateQueries({ queryKey: ['website', id] })
    },
  })

  const handleEditClick = (page: Page) => {
    setSelectedPage(page)
    setEditForm({
      title: page.title,
      slug: page.slug,
      seo_title: page.seo_title || '',
      seo_description: page.seo_description || '',
    })
    setShowEditDialog(true)
  }

  const handleDeleteClick = (page: Page) => {
    setSelectedPage(page)
    setShowDeleteDialog(true)
  }

  const handleSaveEdit = () => {
    if (!selectedPage) return
    updatePageMutation.mutate({
      pageId: selectedPage.id,
      updates: editForm,
    })
  }

  const handleCopyUrl = (page: Page) => {
    const baseUrl = window.location.origin
    const pageUrl = page.is_home 
      ? `${baseUrl}/sites/${website?.slug}` 
      : `${baseUrl}/sites/${website?.slug}/${page.slug}`
    
    navigator.clipboard.writeText(pageUrl)
    toast({ title: 'Copied!', description: 'Page URL copied to clipboard.' })
  }

  const handleCopyRelativeUrl = (page: Page) => {
    const relativeUrl = getPageUrl(page)
    navigator.clipboard.writeText(relativeUrl)
    toast({ title: 'Copied!', description: 'Relative URL copied to clipboard.' })
  }

  const handleShowLinkDialog = () => {
    setShowLinkDialog(true)
  }

  const getPageUrl = (page: Page) => {
    if (!website) return ''
    return page.is_home 
      ? `/sites/${website.slug}` 
      : `/sites/${website.slug}/${page.slug}`
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-zinc-400" />
      </div>
    )
  }

  if (!website) {
    return (
      <div className="flex flex-col items-center justify-center h-screen">
        <p className="text-zinc-400 mb-4">Website not found</p>
        <Button variant="outline" onClick={() => navigate('/website-builder')}>
          Back to websites
        </Button>
      </div>
    )
  }

  return (
    <div className="container mx-auto py-8 px-4">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-4">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => navigate(`/website-builder/${id}`)}
          >
            <Icon icon="solar:arrow-left-bold" className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold">Page Management</h1>
            <p className="text-muted-foreground">{website.name}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={handleShowLinkDialog}>
            <Icon icon="solar:link-bold" className="h-4 w-4 mr-2" />
            Get Page Links
          </Button>
          <Button onClick={() => navigate(`/website-builder/${id}`)}>
            <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
            Add New Page
          </Button>
        </div>
      </div>

      {/* Stats Cards */}
      {website.pages.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
          <div className="border rounded-lg p-4 bg-card">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-primary/10 rounded-lg">
                <Icon icon="solar:document-bold" className="h-5 w-5 text-primary" />
              </div>
              <div>
                <p className="text-sm text-muted-foreground">Total Pages</p>
                <p className="text-2xl font-bold">{website.pages.length}</p>
              </div>
            </div>
          </div>
          <div className="border rounded-lg p-4 bg-card">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-green-500/10 rounded-lg">
                <Icon icon="solar:check-circle-bold" className="h-5 w-5 text-green-500" />
              </div>
              <div>
                <p className="text-sm text-muted-foreground">Published</p>
                <p className="text-2xl font-bold">
                  {website.pages.filter(p => p.status === 'published').length}
                </p>
              </div>
            </div>
          </div>
          <div className="border rounded-lg p-4 bg-card">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-amber-500/10 rounded-lg">
                <Icon icon="solar:document-text-bold" className="h-5 w-5 text-amber-500" />
              </div>
              <div>
                <p className="text-sm text-muted-foreground">Drafts</p>
                <p className="text-2xl font-bold">
                  {website.pages.filter(p => p.status === 'draft').length}
                </p>
              </div>
            </div>
          </div>
          <div className="border rounded-lg p-4 bg-card">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-blue-500/10 rounded-lg">
                <Icon icon="solar:home-2-bold" className="h-5 w-5 text-blue-500" />
              </div>
              <div>
                <p className="text-sm text-muted-foreground">Home Page</p>
                <p className="text-lg font-semibold truncate">
                  {website.pages.find(p => p.is_home)?.title || 'None'}
                </p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Pages Table */}
      <div className="border rounded-lg bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-[25%]">Title</TableHead>
              <TableHead className="w-[30%]">URL</TableHead>
              <TableHead className="w-[10%]">Status</TableHead>
              <TableHead className="w-[10%]">Type</TableHead>
              <TableHead className="w-[15%]">Updated</TableHead>
              <TableHead className="w-[10%] text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {website.pages.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="text-center py-12">
                  <div className="flex flex-col items-center gap-3">
                    <Icon icon="solar:document-bold-duotone" className="h-16 w-16 text-muted-foreground/50" />
                    <div>
                      <p className="text-muted-foreground font-medium">No pages yet</p>
                      <p className="text-sm text-muted-foreground/70 mt-1">
                        Create your first page to get started
                      </p>
                    </div>
                    <Button onClick={() => navigate(`/website-builder/${id}`)} className="mt-2">
                      <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
                      Create First Page
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ) : (
              website.pages.map((page) => (
                <TableRow key={page.id}>
                  <TableCell className="font-medium">{page.title}</TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      <code className="text-xs bg-muted px-2 py-1 rounded">
                        {getPageUrl(page)}
                      </code>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-6 w-6"
                        onClick={() => handleCopyUrl(page)}
                      >
                        <Icon icon="solar:copy-bold" className="h-3 w-3" />
                      </Button>
                    </div>
                  </TableCell>
                  <TableCell>
                    <Badge variant={page.status === 'published' ? 'default' : 'secondary'}>
                      {page.status}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    {page.is_home && (
                      <Badge variant="outline" className="gap-1">
                        <Icon icon="solar:home-2-bold" className="h-3 w-3" />
                        Home
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {new Date(page.updated_at).toLocaleDateString()}
                  </TableCell>
                  <TableCell className="text-right">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon">
                          <Icon icon="solar:menu-dots-bold" className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem
                          onClick={() => navigate(`/website-builder/${id}`)}
                        >
                          <Icon icon="solar:pen-bold" className="h-4 w-4 mr-2" />
                          Edit in Builder
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => handleEditClick(page)}>
                          <Icon icon="solar:settings-bold" className="h-4 w-4 mr-2" />
                          Edit Details
                        </DropdownMenuItem>
                        <DropdownMenuItem 
                          onClick={() => window.open(getPageUrl(page), '_blank')}
                        >
                          <Icon icon="solar:eye-bold" className="h-4 w-4 mr-2" />
                          View Live
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => handleCopyUrl(page)}>
                          <Icon icon="solar:link-bold" className="h-4 w-4 mr-2" />
                          Copy URL
                        </DropdownMenuItem>
                        {!page.is_home && (
                          <DropdownMenuItem
                            onClick={() => setHomePageMutation.mutate(page.id)}
                          >
                            <Icon icon="solar:home-2-bold" className="h-4 w-4 mr-2" />
                            Set as Home
                          </DropdownMenuItem>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          onClick={() => handleDeleteClick(page)}
                          className="text-destructive focus:text-destructive"
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 mr-2" />
                          Delete
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {/* Edit Dialog */}
      <Dialog open={showEditDialog} onOpenChange={setShowEditDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Page Details</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 mt-4">
            <div className="space-y-2">
              <Label>Page Title</Label>
              <Input
                value={editForm.title}
                onChange={(e) => setEditForm({ ...editForm, title: e.target.value })}
                placeholder="e.g., About Us"
              />
            </div>
            <div className="space-y-2">
              <Label>URL Slug</Label>
              <Input
                value={editForm.slug}
                onChange={(e) => setEditForm({ ...editForm, slug: e.target.value })}
                placeholder="e.g., about-us"
              />
            </div>
            <div className="space-y-2">
              <Label>SEO Title</Label>
              <Input
                value={editForm.seo_title}
                onChange={(e) => setEditForm({ ...editForm, seo_title: e.target.value })}
                placeholder="Optional SEO title"
              />
            </div>
            <div className="space-y-2">
              <Label>SEO Description</Label>
              <Textarea
                value={editForm.seo_description}
                onChange={(e) => setEditForm({ ...editForm, seo_description: e.target.value })}
                placeholder="Optional SEO description"
                rows={3}
              />
            </div>
          </div>
          <DialogFooter className="mt-4">
            <Button variant="outline" onClick={() => setShowEditDialog(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleSaveEdit}
              disabled={!editForm.title.trim() || updatePageMutation.isPending}
            >
              {updatePageMutation.isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Page</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground mt-2">
            Are you sure you want to delete "{selectedPage?.title}"? This action cannot be undone.
          </p>
          <DialogFooter className="mt-4">
            <Button variant="outline" onClick={() => setShowDeleteDialog(false)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => selectedPage && deletePageMutation.mutate(selectedPage.id)}
              disabled={deletePageMutation.isPending}
            >
              {deletePageMutation.isPending ? 'Deleting...' : 'Delete Page'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Page Links Dialog */}
      <Dialog open={showLinkDialog} onOpenChange={setShowLinkDialog}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Page Links</DialogTitle>
          </DialogHeader>
          <div className="mt-4">
            <p className="text-sm text-muted-foreground mb-4">
              Copy these URLs to link to pages in your website builder or share them externally.
            </p>
            <div className="space-y-3 max-h-[400px] overflow-y-auto">
              {website.pages.map((page) => (
                <div key={page.id} className="border rounded-lg p-3 bg-muted/50">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-1">
                        <p className="font-medium">{page.title}</p>
                        {page.is_home && (
                          <Badge variant="outline" className="text-xs">
                            <Icon icon="solar:home-2-bold" className="h-3 w-3 mr-1" />
                            Home
                          </Badge>
                        )}
                      </div>
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <code className="text-xs bg-background px-2 py-1 rounded flex-1 truncate">
                            {getPageUrl(page)}
                          </code>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2"
                            onClick={() => handleCopyRelativeUrl(page)}
                          >
                            <Icon icon="solar:copy-bold" className="h-3 w-3" />
                          </Button>
                        </div>
                        <div className="flex items-center gap-2">
                          <code className="text-xs bg-background px-2 py-1 rounded flex-1 truncate text-muted-foreground">
                            {window.location.origin}{getPageUrl(page)}
                          </code>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2"
                            onClick={() => handleCopyUrl(page)}
                          >
                            <Icon icon="solar:copy-bold" className="h-3 w-3" />
                          </Button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
          <DialogFooter className="mt-4">
            <Button onClick={() => setShowLinkDialog(false)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
