import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export type DocumentTemplateType = 'invoice' | 'delivery_order' | 'quotation'

interface DocumentTemplateDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  documentType: DocumentTemplateType
  /** Template-render endpoint, e.g. `/invoices/{id}/pdf`. */
  pdfPath: string
  /** Download filename, e.g. `invoice-INV-0001.pdf`. */
  filename: string
  /** Human label used in the dialog title, e.g. `Invoice`. */
  title: string
}

interface TemplateOption {
  id: string
  name: string
  is_system_default?: boolean
}

/**
 * Shared "select a Template Builder template, then download" dialog used by the
 * invoice, delivery order and quotation detail pages. The PDF is rendered with
 * the selected template server-side, so it matches the tenant's document design
 * instead of the browser printing the whole SPA.
 */
export function DocumentTemplateDialog({
  open,
  onOpenChange,
  documentType,
  pdfPath,
  filename,
  title,
}: DocumentTemplateDialogProps) {
  const { toast } = useToast()
  const [selectedTemplateId, setSelectedTemplateId] = useState('')
  const [busy, setBusy] = useState<'download' | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['document-template-options', documentType],
    queryFn: async () => {
      const [list, defaults] = await Promise.all([
        api.get('/template-builder/templates', {
          params: { document_type: documentType, status: 'published', per_page: 100 },
        }),
        // Preselect the tenant's resolved default; tolerate older tenants that
        // cannot resolve one (the picker still works with the first template).
        api.get('/template-builder/active-defaults').catch(() => null),
      ])
      return {
        templates: (list.data?.data || []) as TemplateOption[],
        defaultTemplateId: (defaults?.data?.data?.[documentType] ?? null) as string | null,
      }
    },
    enabled: open,
  })

  const templates = useMemo(() => data?.templates ?? [], [data])
  const defaultTemplateId = data?.defaultTemplateId ?? null

  useEffect(() => {
    if (!open) {
      setSelectedTemplateId('')
      setBusy(null)
      return
    }
    if (selectedTemplateId || templates.length === 0) return
    const resolved = defaultTemplateId && templates.some((template) => template.id === defaultTemplateId)
      ? defaultTemplateId
      : templates[0].id
    setSelectedTemplateId(String(resolved))
  }, [open, templates, defaultTemplateId, selectedTemplateId])

  const run = async () => {
    if (!selectedTemplateId || busy) return
    setBusy('download')
    try {
      const response = await api.get(pdfPath, {
        params: { template: selectedTemplateId },
        responseType: 'blob',
      })
      const blob = new Blob([response.data], { type: 'application/pdf' })
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', filename)
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
      onOpenChange(false)
    } catch (error: any) {
      const message = await extractBlobError(error)
      toast({
        variant: 'destructive',
        title: 'Download failed',
        description: message || 'Could not generate the PDF. Please try again.',
      })
    } finally {
      setBusy(null)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Download {title} PDF</DialogTitle>
          <DialogDescription>
            Choose the template to use, then download the generated PDF.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-2">
          <Label>{title} template</Label>
          <Select value={selectedTemplateId} onValueChange={setSelectedTemplateId} disabled={isLoading}>
            <SelectTrigger>
              <SelectValue placeholder={isLoading ? 'Loading templates…' : 'Select a template'} />
            </SelectTrigger>
            <SelectContent>
              {templates.map((template) => (
                <SelectItem key={template.id} value={template.id}>
                  <span className="flex items-center gap-2">
                    <Icon icon="solar:document-text-bold-duotone" className="h-4 w-4 text-muted-foreground" />
                    {template.name}
                    {template.is_system_default ? ' (Default)' : ''}
                  </span>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            The PDF is generated with the selected template — no browser print dialog.
          </p>
        </div>

        {!isLoading && templates.length === 0 && (
          <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-8 text-center">
            <Icon icon="solar:document-text-bold-duotone" className="h-8 w-8 text-muted-foreground/30" />
            <p className="text-sm text-muted-foreground">
              No published templates are available for this document type.
            </p>
          </div>
        )}

        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy !== null}>
            Cancel
          </Button>
          <Button onClick={() => run()} disabled={!selectedTemplateId || busy !== null || isLoading}>
            <Icon icon={busy === 'download' ? 'eos-icons:loading' : 'solar:download-bold-duotone'} className="mr-2 h-4 w-4" />
            {busy === 'download' ? 'Preparing…' : 'Download PDF'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/** Render errors are returned as a JSON blob (responseType: 'blob'). */
async function extractBlobError(error: any): Promise<string | null> {
  const data = error?.response?.data
  if (data instanceof Blob) {
    try {
      const parsed = JSON.parse(await data.text())
      return parsed?.message ?? null
    } catch {
      return null
    }
  }
  return data?.message ?? null
}
