import { useCallback, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { DocumentTemplateDialog, type DocumentTemplateType } from './DocumentTemplateDialog'
import { extractBlobError } from './documentRender'
import { PRINT_SEND_CHANNELS } from './documentSendChannels'

export interface TemplateResolution {
  outcome: string
  template_id: string | null
  template_name: string | null
  rule: { scope_type: string; scope_value: string | null } | null
  rule_label: string | null
}

/**
 * Issue #405: another printable document built from the same source record
 * (e.g. a sales order's order confirmation and pro-forma invoice). Each one
 * has its own template type, render endpoint and resolver lookup.
 */
export interface PrintSendDocumentOption {
  documentType: DocumentTemplateType
  /** Menu label, e.g. "Pro-forma invoice". */
  label: string
  pdfPath: string
  filename: string
  title: string
  resolutionPath?: string
}

interface PrintSendMenuProps {
  documentType: DocumentTemplateType
  /** Render endpoint; may already carry query params (statement period). */
  pdfPath: string
  filename: string
  title: string
  /**
   * Read-only resolver lookup (issue #618) so the menu can show
   * "Using: <template name> (<rule>)" for THIS document — `document_id` for a
   * persisted document, or explicit context for a generated one.
   */
  resolutionPath?: string
  /** Reuses the page's existing email send service; hidden when omitted. */
  onEmail?: () => void
  /** Reuses the page's existing WhatsApp send service; hidden when omitted. */
  onWhatsApp?: () => void
  /**
   * Issue #405: label for the primary document when `documents` is given,
   * e.g. "Sales order".
   */
  label?: string
  /**
   * Issue #405: further documents from the same record. When present the
   * menu lists the primary document plus these, and every action (Preview,
   * Download, Print, Choose template, resolver label, send channels) applies
   * to the selected one.
   */
  documents?: PrintSendDocumentOption[]
}

/**
 * The single print/send header menu for every sales document: Preview,
 * Download PDF, Print, Email, WhatsApp and Choose template. The default
 * template is the one the deterministic resolver picks for the document
 * (issue #612 context) and is shown as "Using: <template name> (<rule>)".
 */
export function PrintSendMenu({
  documentType: primaryDocumentType,
  pdfPath: primaryPdfPath,
  filename: primaryFilename,
  title: primaryTitle,
  resolutionPath: primaryResolutionPath,
  onEmail,
  onWhatsApp,
  label,
  documents,
}: PrintSendMenuProps) {
  const options = useMemo<PrintSendDocumentOption[]>(() => [
    {
      documentType: primaryDocumentType,
      label: label ?? primaryTitle,
      pdfPath: primaryPdfPath,
      filename: primaryFilename,
      title: primaryTitle,
      resolutionPath: primaryResolutionPath,
    },
    ...(documents ?? []),
  ], [documents, label, primaryDocumentType, primaryFilename, primaryPdfPath, primaryResolutionPath, primaryTitle])
  const [selectedIndex, setSelectedIndex] = useState(0)
  const selected = options[selectedIndex] ?? options[0]
  const { documentType, pdfPath, filename, title, resolutionPath } = selected
  // Send actions belong to the primary document's existing send service.
  const isPrimary = selectedIndex === 0

  const { toast } = useToast()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [dialogStep, setDialogStep] = useState<'default' | 'choose'>('default')
  const [busy, setBusy] = useState<'preview' | 'print' | null>(null)

  const { data: resolution, isLoading: resolving } = useQuery({
    queryKey: ['document-template-resolution', resolutionPath],
    queryFn: async () => {
      const response = await api.get<{ data: TemplateResolution }>(resolutionPath as string)
      return response.data?.data ?? null
    },
    enabled: Boolean(resolutionPath),
  })

  const defaultTemplateId = resolution?.template_id ?? null

  const usingLabel = useMemo(() => {
    if (!resolutionPath) return null
    if (resolving) return 'Resolving template…'
    if (!resolution) return 'Template unavailable'
    if (!resolution.template_name) return 'No template resolved'
    return `Using: ${resolution.template_name} (${resolution.rule_label ?? 'default rule'})`
  }, [resolutionPath, resolving, resolution])

  const channels = PRINT_SEND_CHANNELS[documentType] ?? { email: false, whatsapp: false }
  const showEmail = isPrimary && channels.email && Boolean(onEmail)
  const showWhatsApp = isPrimary && channels.whatsapp && Boolean(onWhatsApp)

  /** Fetch the rendered PDF as an object URL, resolved with the document's template. */
  const fetchObjectUrl = useCallback(async (): Promise<string> => {
    const response = await api.get(pdfPath, {
      params: defaultTemplateId ? { template: defaultTemplateId } : undefined,
      responseType: 'blob',
    })
    const blob = new Blob([response.data], { type: 'application/pdf' })
    return window.URL.createObjectURL(blob)
  }, [defaultTemplateId, pdfPath])

  const handleRenderError = useCallback(async (error: any, fallback: string) => {
    const message = await extractBlobError(error)
    toast({
      variant: 'destructive',
      title: fallback,
      description: message || 'Could not generate the PDF. Please try again.',
    })
  }, [toast])

  const preview = useCallback(async () => {
    if (busy) return
    setBusy('preview')
    try {
      const url = await fetchObjectUrl()
      window.open(url, '_blank', 'noopener,noreferrer')
      // The new tab needs the URL alive while the PDF loads.
      window.setTimeout(() => window.URL.revokeObjectURL(url), 60_000)
    } catch (error: any) {
      await handleRenderError(error, 'Preview failed')
    } finally {
      setBusy(null)
    }
  }, [busy, fetchObjectUrl, handleRenderError])

  const print = useCallback(async () => {
    if (busy) return
    setBusy('print')
    try {
      const url = await fetchObjectUrl()
      const frame = document.createElement('iframe')
      frame.style.position = 'fixed'
      frame.style.right = '0'
      frame.style.bottom = '0'
      frame.style.width = '0'
      frame.style.height = '0'
      frame.style.border = '0'
      frame.src = url
      frame.onload = () => {
        // The browser's PDF viewer prints the rendered document, not the SPA.
        frame.contentWindow?.focus()
        frame.contentWindow?.print()
        window.setTimeout(() => {
          frame.remove()
          window.URL.revokeObjectURL(url)
        }, 60_000)
      }
      document.body.appendChild(frame)
    } catch (error: any) {
      await handleRenderError(error, 'Print failed')
    } finally {
      setBusy(null)
    }
  }, [busy, fetchObjectUrl, handleRenderError])

  const openDialog = (step: 'default' | 'choose') => {
    setDialogStep(step)
    setDialogOpen(true)
  }

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <HeaderActionButton
            type="button"
            variant="outline"
            icon="solar:printer-bold-duotone"
            title={`Print or send ${title}`}
          >
            Print / Send
          </HeaderActionButton>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-72">
          {options.length > 1 && (
            <>
              <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                Document
              </DropdownMenuLabel>
              {options.map((option, index) => (
                <DropdownMenuItem
                  key={option.documentType}
                  // Keep the menu open: choosing a document re-targets the actions below.
                  onSelect={(event) => {
                    event.preventDefault()
                    setSelectedIndex(index)
                  }}
                  aria-checked={index === selectedIndex}
                  role="menuitemradio"
                >
                  <Icon
                    icon="solar:check-read-linear"
                    className={`mr-2 h-4 w-4 ${index === selectedIndex ? '' : 'invisible'}`}
                  />
                  {option.label}
                </DropdownMenuItem>
              ))}
              <DropdownMenuSeparator />
            </>
          )}
          {usingLabel && (
            <>
              <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                {usingLabel}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
            </>
          )}
          <DropdownMenuItem onSelect={() => void preview()} disabled={busy !== null}>
            <Icon icon="solar:eye-bold-duotone" className="mr-2 h-4 w-4" />
            Preview
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => openDialog('default')}>
            <Icon icon="solar:download-minimalistic-bold-duotone" className="mr-2 h-4 w-4" />
            Download PDF
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => void print()} disabled={busy !== null}>
            <Icon icon="solar:printer-bold-duotone" className="mr-2 h-4 w-4" />
            Print
          </DropdownMenuItem>
          {showEmail && (
            <DropdownMenuItem onSelect={() => onEmail?.()}>
              <Icon icon="solar:letter-bold-duotone" className="mr-2 h-4 w-4" />
              Email
            </DropdownMenuItem>
          )}
          {showWhatsApp && (
            <DropdownMenuItem onSelect={() => onWhatsApp?.()}>
              <Icon icon="solar:chat-round-line-bold-duotone" className="mr-2 h-4 w-4" />
              WhatsApp
            </DropdownMenuItem>
          )}
          <DropdownMenuSeparator />
          <DropdownMenuItem onSelect={() => openDialog('choose')}>
            <Icon icon="solar:documents-bold-duotone" className="mr-2 h-4 w-4" />
            Choose template
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <DocumentTemplateDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        documentType={documentType}
        pdfPath={pdfPath}
        filename={filename}
        title={title}
        defaultTemplateId={defaultTemplateId}
        initialStep={dialogStep}
      />
    </>
  )
}
