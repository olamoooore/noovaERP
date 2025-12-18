import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface Category {
  id: number
  name: string
  parent_id: number | null
  is_active: boolean
}

type DialogMode =
  | { type: 'create'; parentId?: number }
  | { type: 'edit'; category: Category }

function safeNumber(value: string | null | undefined): number | null {
  if (!value) return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

export function CategoryManager() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [dialogOpen, setDialogOpen] = useState(false)
  const [dialogMode, setDialogMode] = useState<DialogMode>({ type: 'create' })
  const [name, setName] = useState('')
  const [parentId, setParentId] = useState<string>('')

  const { data: categories, isLoading } = useQuery<Category[]>({
    queryKey: ['product-categories'],
    queryFn: async () => {
      const response = await api.get('/product-categories')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const categoriesById = useMemo(() => {
    const map = new Map<number, Category>()
    for (const cat of categories || []) map.set(cat.id, cat)
    return map
  }, [categories])

  const buildPath = (cat: Category): string => {
    const parts: string[] = [cat.name]
    let current = cat
    let safety = 0
    while (current.parent_id && safety < 10) {
      const parent = categoriesById.get(current.parent_id)
      if (!parent) break
      parts.unshift(parent.name)
      current = parent
      safety += 1
    }
    return parts.join(' / ')
  }

  const tree = useMemo(() => {
    const list = categories || []
    const childrenByParent = new Map<number | null, Category[]>()
    for (const cat of list) {
      const key = cat.parent_id ?? null
      const arr = childrenByParent.get(key) || []
      arr.push(cat)
      childrenByParent.set(key, arr)
    }

    for (const [, arr] of childrenByParent) {
      arr.sort((a, b) => a.name.localeCompare(b.name))
    }

    const rows: Array<{ cat: Category; depth: number; path: string }> = []
    const walk = (parent: number | null, depth: number) => {
      const children = childrenByParent.get(parent) || []
      for (const cat of children) {
        rows.push({ cat, depth, path: buildPath(cat) })
        walk(cat.id, depth + 1)
      }
    }

    walk(null, 0)
    return rows
  }, [categories, categoriesById])

  const parentOptions = useMemo(() => {
    const list = (categories || []).slice()
    list.sort((a, b) => buildPath(a).localeCompare(buildPath(b)))
    return list
  }, [categories])

  const openCreate = (initialParentId?: number) => {
    setDialogMode({ type: 'create', parentId: initialParentId })
    setName('')
    setParentId(initialParentId ? String(initialParentId) : '')
    setDialogOpen(true)
  }

  const openEdit = (category: Category) => {
    setDialogMode({ type: 'edit', category })
    setName(category.name)
    setParentId(category.parent_id ? String(category.parent_id) : '')
    setDialogOpen(true)
  }

  const createMutation = useMutation({
    mutationFn: async (payload: { name: string; parent_id: number | null }) => {
      const response = await api.post('/product-categories', {
        ...payload,
        is_active: true,
      })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['product-categories'] })
      queryClient.invalidateQueries({ queryKey: ['categories'] })
      toast({ title: 'Category created' })
      setDialogOpen(false)
    },
    onError: () => toast({ variant: 'destructive', title: 'Failed to create category' }),
  })

  const updateMutation = useMutation({
    mutationFn: async (payload: { id: number; name: string; parent_id: number | null }) => {
      const response = await api.put(`/product-categories/${payload.id}`, {
        name: payload.name,
        parent_id: payload.parent_id,
      })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['product-categories'] })
      queryClient.invalidateQueries({ queryKey: ['categories'] })
      toast({ title: 'Category updated' })
      setDialogOpen(false)
    },
    onError: () => toast({ variant: 'destructive', title: 'Failed to update category' }),
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/product-categories/${id}`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['product-categories'] })
      queryClient.invalidateQueries({ queryKey: ['categories'] })
      toast({ title: 'Category deleted' })
    },
    onError: () => toast({ variant: 'destructive', title: 'Failed to delete category' }),
  })

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault()
    const trimmed = name.trim()
    if (!trimmed) return

    const finalParent = safeNumber(parentId)

    if (dialogMode.type === 'edit') {
      updateMutation.mutate({ id: dialogMode.category.id, name: trimmed, parent_id: finalParent })
      return
    }

    createMutation.mutate({ name: trimmed, parent_id: finalParent })
  }

  const submitting = createMutation.isPending || updateMutation.isPending

  if (isLoading) return <div>Loading categories...</div>

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <div className="text-lg font-semibold">Product Categories</div>
          <div className="text-sm text-muted-foreground">
            Create clear hierarchies like <span className="font-medium">Men / Shoes / Lifestyle Shoes</span>
          </div>
        </div>
        <Button type="button" onClick={() => openCreate()}>
          <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
          Add Category
        </Button>
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {dialogMode.type === 'edit'
                ? 'Edit Category'
                : dialogMode.parentId
                  ? 'Add Subcategory'
                  : 'Add Category'}
            </DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSave} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="cat-name">Name</Label>
              <Input
                id="cat-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Shoes"
                autoFocus
              />
            </div>

            <div className="space-y-2">
              <Label>Parent (optional)</Label>
              <Select
                value={parentId || 'none'}
                onValueChange={(v) => setParentId(v === 'none' ? '' : v)}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Top level" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Top level</SelectItem>
                  {parentOptions
                    .filter((c) => (dialogMode.type === 'edit' ? c.id !== dialogMode.category.id : true))
                    .map((c) => (
                      <SelectItem key={c.id} value={String(c.id)}>
                        {buildPath(c)}
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
              <div className="text-xs text-muted-foreground">
                Tip: pick <span className="font-medium">Men / Shoes</span> as parent to create a third level.
              </div>
            </div>

            <Button type="submit" className="w-full" disabled={submitting}>
              {dialogMode.type === 'edit' ? 'Save Changes' : 'Create'}
            </Button>
          </form>
        </DialogContent>
      </Dialog>

      <Card>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Category</TableHead>
                <TableHead>Path</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {tree.map(({ cat, depth, path }) => (
                <TableRow key={cat.id}>
                  <TableCell className="font-medium">
                    <div className="flex items-center" style={{ paddingLeft: depth * 16 }}>
                      {depth > 0 ? (
                        <Icon icon="solar:alt-arrow-right-bold" className="mr-2 h-3.5 w-3.5 text-muted-foreground" />
                      ) : null}
                      {cat.name}
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{path}</TableCell>
                  <TableCell>
                    <span
                      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        cat.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      {cat.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="inline-flex items-center gap-1">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        title="Add subcategory"
                        onClick={() => openCreate(cat.id)}
                      >
                        <Icon icon="solar:add-square-bold" className="h-4 w-4" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        title="Edit"
                        onClick={() => openEdit(cat)}
                      >
                        <Icon icon="solar:pen-bold" className="h-4 w-4" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        title="Delete"
                        onClick={() => {
                          const ok = confirm(`Delete “${cat.name}”? This cannot be undone.`)
                          if (!ok) return
                          deleteMutation.mutate(cat.id)
                        }}
                        disabled={deleteMutation.isPending}
                      >
                        <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-red-500" />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
              {tree.length === 0 && (
                <TableRow>
                  <TableCell colSpan={4} className="text-center py-8 text-muted-foreground">
                    No categories yet
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  )
}
