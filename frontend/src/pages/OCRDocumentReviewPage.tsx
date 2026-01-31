import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import { DocumentViewer } from '@/components/ocr/DocumentViewer'
import { DocumentForm } from '@/components/ocr/DocumentForm'
import { PostingConfirmationDialog } from '@/components/ocr/PostingConfirmationDialog'
import api from '@/lib/api'

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
        description: 'Journal entry has been created'
      })
      setShowPostDialog(false)
      navigate('/ocr/documents')
    },
    onError: (error: any) => {
      toast({ 
        variant: 'destructive', 
        title: 'Failed to post document',
        description: error.response?.data?.message || 'An error occurred'
      })
      setShowPostDialog(false)
    },
  })

  const handleSave = (data: any) => {
    setIsSaving(true)
    updateMutation.mutate(data)
  }

  const handlePostClick = () => {
    setShowPostDialog(true)
  }

  const handlePostConfirm = () => {
    postMutation.mutate()
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Icon icon="solar:refresh-circle-bold" className="h-8 w-8 animate-spin text-primary" />
      </div>
    )
  }

  if (!document) {
    return (
      <div className="space-y-6">
        <h1 className="text-3xl font-bold">Document Not Found</h1>
        <Card>
          <CardContent className="py-12 text-center">
            <p className="text-muted-foreground">The requested document could not be found.</p>
            <Button className="mt-4" onClick={() => navigate('/ocr/documents')}>
              Back to Documents
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  const statusConfig = {
    PENDING: { label: 'Pending', color: 'bg-gray-500' },
    PROCESSING: { label: 'Processing', color: 'bg-blue-500' },
    NEEDS_REVIEW: { label: 'Needs Review', color: 'bg-yellow-500' },
    POSTED: { label: 'Posted', color: 'bg-green-500' },
    EXTRACTION_FAILED: { label: 'Failed', color: 'bg-red-500' },
  }

  const status = statusConfig[document.status as keyof typeof statusConfig] || statusConfig.PENDING

  const canEdit = document.status === 'NEEDS_REVIEW'
  const canPost = document.status === 'NEEDS_REVIEW'

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate('/ocr/documents')}>
            <Icon icon="solar:alt-arrow-left-bold" className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-3xl font-bold">{document.file_name}</h1>
            <p className="text-muted-foreground mt-1">Review and verify extracted data</p>
          </div>
        </div>
        <Badge className={`${status.color} text-white`}>
          {status.label}
        </Badge>
      </div>

      {/* Split View */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Left: Document Viewer */}
        <Card className="lg:sticky lg:top-6 lg:h-[calc(100vh-12rem)]">
          <CardHeader>
            <CardTitle>Original Document</CardTitle>
          </CardHeader>
          <CardContent className="h-[calc(100%-5rem)] overflow-auto">
            <DocumentViewer document={document} />
          </CardContent>
        </Card>

        {/* Right: Editable Form */}
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Extracted Data</CardTitle>
            </CardHeader>
            <CardContent>
              <DocumentForm
                document={document}
                suggestions={suggestions}
                onSave={handleSave}
                isSaving={isSaving}
                readOnly={!canEdit}
              />
            </CardContent>
          </Card>

          {/* Actions */}
          {canPost && (
            <Card>
              <CardContent className="pt-6">
                <div className="flex justify-end gap-2">
                  <Button
                    variant="outline"
                    onClick={() => navigate('/ocr/documents')}
                  >
                    Cancel
                  </Button>
                  <Button
                    onClick={handlePostClick}
                    disabled={postMutation.isPending}
                  >
                    <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                    Approve & Post
                  </Button>
                </div>
              </CardContent>
            </Card>
          )}
        </div>
      </div>

      {/* Posting Confirmation Dialog */}
      <PostingConfirmationDialog
        open={showPostDialog}
        onOpenChange={setShowPostDialog}
        document={document}
        onConfirm={handlePostConfirm}
        isPosting={postMutation.isPending}
      />
    </div>
  )
}
