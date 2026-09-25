import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { useToast } from '@/hooks/use-toast'
import { DocumentViewer } from '@/components/ocr/DocumentViewer'
import { DocumentForm } from '@/components/ocr/DocumentForm'
import { PostingConfirmationDialog } from '@/components/ocr/PostingConfirmationDialog'
import { getOcrStatusStyle } from '@/components/ocr/ocrStatus'
import api from '@/lib/api'

const formatDate = (value?: string | null) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

export function OCRDocumentReviewPage() {
  const { uuid } = useParams()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [isSaving, setIsSaving] = useState(false)
  const [showPostDialog, setShowPostDialog] = useState(false)

  const { data: document, isLoading } = useQuery({
    queryKey: ['ocr-document', uuid],
    queryFn: async () => {
      const response = await api.get(`/ocr/documents/${uuid}`)
      return response.data.data
    },
    enabled: !!uuid,
  })

  const { data: suggestions } = useQuery({
    queryKey: ['ocr-suggestions', uuid],
    queryFn: async () => {
      const response = await api.get(`/ocr/documents/${uuid}/suggestions`)
      return response.data.data
    },
    enabled: !!uuid && document?.status === 'NEEDS_REVIEW',
  })

  const updateMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.put(`/ocr/documents/${uuid}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ocr-document', uuid] })
      toast({ title: 'Document updated successfully' })
      setIsSaving(false)
    },
    onError: () => {
      toast({ variant: 'destructive', title: 'Failed to update document' })
      setIsSaving(false)
    },
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/ocr/documents/${uuid}/post`)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Document posted successfully',
        description: 'Journal entry has been created',
      })
      setShowPostDialog(false)
      navigate('/ocr/documents')
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to post document',
        description: error.response?.data?.message || 'An error occurred',
      })
      setShowPostDialog(false)
    },
  })

  const retryMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/ocr/documents/${uuid}/retry`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ocr-document', uuid] })
      toast({ title: 'Document reprocessed successfully' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Retry failed',
        description: error.response?.data?.error || error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleSave = (data: any) => {
    setIsSaving(true)
    updateMutation.mutate(data)
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Icon icon="svg-spinners:ring-resize" className="h-8 w-8 text-primary" />
      </div>
    )
  }

  if (!document) {
    return (
      <div className="space-y-6">
        <h1 className="text-3xl font-bold">Document Not Found</h1>
        <Card className="rounded-xl">
          <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
            <div className="grid h-14 w-14 place-items-center rounded-full bg-muted">
              <Icon icon="solar:document-text-bold-duotone" className="h-7 w-7 text-muted-foreground" />
            </div>
            <p className="text-muted-foreground">The requested document could not be found.</p>
            <HeaderActionButton icon="solar:alt-arrow-left-linear" onClick={() => navigate('/ocr/documents')}>
              Back to Documents
            </HeaderActionButton>
          </CardContent>
        </Card>
      </div>
    )
  }

  const status = getOcrStatusStyle(document.status)
  const canEdit = document.status === 'NEEDS_REVIEW' || document.status === 'EXTRACTION_FAILED'
  const canPost = document.status === 'NEEDS_REVIEW'
  const canRetry = document.status === 'EXTRACTION_FAILED'

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <HeaderActionButton
            variant="outline"
            icon="solar:alt-arrow-left-linear"
            onClick={() => navigate('/ocr/documents')}
          >
            Back
          </HeaderActionButton>
          <div className="min-w-0">
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              <h1 className="truncate text-2xl font-bold">{document.file_name}</h1>
              <Badge variant="outline" className={`gap-1 font-medium ${status.badge}`}>
                <Icon icon={status.icon} className="h-3.5 w-3.5" />
                {status.label}
              </Badge>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
              Review and verify extracted data · Uploaded {formatDate(document.created_at)}
            </p>
          </div>
        </div>

        {canPost && (
          <div className="flex items-center gap-2">
            <HeaderActionButton variant="outline" onClick={() => navigate('/ocr/documents')}>
              Cancel
            </HeaderActionButton>
            <HeaderActionButton
              icon="solar:check-circle-bold"
              onClick={() => setShowPostDialog(true)}
              disabled={postMutation.isPending}
            >
              Approve & Post
            </HeaderActionButton>
          </div>
        )}
      </div>

      {/* Extraction failure banner */}
      {canRetry && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 p-4">
          <div className="flex items-start gap-3">
            <Icon icon="solar:danger-triangle-bold" className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
            <div>
              <p className="font-medium text-red-800">Extraction failed</p>
              <p className="text-sm text-red-700">
                {document.error_message || 'The OCR service could not process this document. Retry to run it again.'}
              </p>
            </div>
          </div>
          <HeaderActionButton
            icon="solar:refresh-circle-bold"
            onClick={() => retryMutation.mutate()}
            disabled={retryMutation.isPending}
          >
            {retryMutation.isPending ? 'Retrying...' : 'Retry Extraction'}
          </HeaderActionButton>
        </div>
      )}

      {/* Split View */}
      <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
        {/* Left: Document Viewer */}
        <Card className="rounded-xl lg:sticky lg:top-6 lg:h-[calc(100vh-11rem)]">
          <CardHeader className="flex flex-row items-center gap-2 space-y-0 border-b pb-4">
            <div className="grid h-8 w-8 place-items-center rounded-lg bg-slate-100 text-slate-600">
              <Icon icon="solar:gallery-wide-bold-duotone" className="h-4 w-4" />
            </div>
            <CardTitle className="text-base">Original Document</CardTitle>
          </CardHeader>
          <CardContent className="h-[calc(100%-4.75rem)] overflow-auto pt-4">
            <DocumentViewer document={document} />
          </CardContent>
        </Card>

        {/* Right: Editable Form */}
        <Card className="rounded-xl">
          <CardHeader className="flex flex-row items-center gap-2 space-y-0 border-b pb-4">
            <div className="grid h-8 w-8 place-items-center rounded-lg bg-indigo-50 text-indigo-600">
              <Icon icon="solar:document-add-bold-duotone" className="h-4 w-4" />
            </div>
            <CardTitle className="text-base">Extracted Data</CardTitle>
          </CardHeader>
          <CardContent className="pt-6">
            <DocumentForm
              document={document}
              suggestions={suggestions}
              onSave={handleSave}
              isSaving={isSaving}
              readOnly={!canEdit}
            />
          </CardContent>
        </Card>
      </div>

      {/* Posting Confirmation Dialog */}
      <PostingConfirmationDialog
        open={showPostDialog}
        onOpenChange={setShowPostDialog}
        document={document}
        onConfirm={() => postMutation.mutate()}
        isPosting={postMutation.isPending}
      />
    </div>
  )
}
