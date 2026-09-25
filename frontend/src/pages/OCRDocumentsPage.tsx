import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { DocumentUpload } from '@/components/ocr/DocumentUpload'
import { DocumentList } from '@/components/ocr/DocumentList'
import api from '@/lib/api'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Icon } from '@iconify/react'

export function OCRDocumentsPage() {
  const [showUpload, setShowUpload] = useState(false)
  const [searchTerm, setSearchTerm] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
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
      <div>
        <h1 className="text-3xl font-bold">OCR Documents</h1>
        <p className="text-muted-foreground mt-1">
          Upload and process invoices and receipts automatically
        </p>
      </div>

      {/* Toolbar — search, status filter, upload and refresh on one row. */}
      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search by vendor, filename, or amount..."
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          className="h-8 w-full max-w-xs rounded-full px-3 text-xs"
        />
        <Select value={statusFilter} onValueChange={setStatusFilter}>
          <SelectTrigger className="h-8 w-40 rounded-full text-xs">
            <SelectValue placeholder="Filter by status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Statuses</SelectItem>
            <SelectItem value="PENDING">Pending</SelectItem>
            <SelectItem value="PROCESSING">Processing</SelectItem>
            <SelectItem value="NEEDS_REVIEW">Needs Review</SelectItem>
            <SelectItem value="POSTED">Posted</SelectItem>
            <SelectItem value="EXTRACTION_FAILED">Failed</SelectItem>
          </SelectContent>
        </Select>
        <HeaderActionButton icon="solar:upload-bold" onClick={() => setShowUpload(true)}>
          Upload Documents
        </HeaderActionButton>
        <Button
          variant="outline"
          size="icon"
          className="h-8 w-8 rounded-full"
          onClick={handleRefresh}
          aria-label="Refresh documents"
        >
          <Icon icon="solar:refresh-bold" className="h-4 w-4" />
        </Button>
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

      <DocumentList
        documents={documents || []}
        onRefresh={handleRefresh}
        searchTerm={searchTerm}
        statusFilter={statusFilter}
      />
    </div>
  )
}
