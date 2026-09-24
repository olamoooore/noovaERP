import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { DocumentUpload } from '@/components/ocr/DocumentUpload'
import { DocumentList } from '@/components/ocr/DocumentList'
import api from '@/lib/api'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'

export function OCRDocumentsPage() {
  const [showUpload, setShowUpload] = useState(false)
  const queryClient = useQueryClient()

  const { data: documents, refetch } = useQuery({
    queryKey: ['ocr-documents'],
    queryFn: async () => {
      const response = await api.get('/ocr/documents')
      return response.data.data
    },
  })

  const handleRefresh = () => {
    queryClient.invalidateQueries({ queryKey: ['ocr-documents'] })
    refetch()
  }

  const handleUploadComplete = () => {
    setShowUpload(false)
    handleRefresh()
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">OCR Documents</h1>
          <p className="text-muted-foreground mt-1">
            Upload and process invoices and receipts automatically
          </p>
        </div>
        <HeaderActionButton icon="solar:upload-bold" onClick={() => setShowUpload(true)}>
          Upload Documents
        </HeaderActionButton>
      </div>

      {showUpload && (
        <Card>
          <CardHeader>
            <CardTitle>Upload Documents</CardTitle>
          </CardHeader>
          <CardContent>
            <DocumentUpload onComplete={handleUploadComplete} onCancel={() => setShowUpload(false)} />
          </CardContent>
        </Card>
      )}

      <DocumentList documents={documents || []} onRefresh={handleRefresh} />
    </div>
  )
}
