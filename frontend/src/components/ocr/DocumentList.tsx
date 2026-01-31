import { useState } from 'react'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { formatCurrency } from '@/lib/utils'
import api from '@/lib/api'
import { useToast } from '@/hooks/use-toast'

interface OcrDocument {
  uuid: string
  file_name: string
  vendor_name: string
  document_date: string
  total_amount: number
  currency_code: string
  status: string
  created_at: string
}

interface DocumentListProps {
  documents: OcrDocument[]
  onRefresh: () => void
}

const statusConfig = {
  PENDING: { label: 'Pending', color: 'bg-gray-500', icon: 'solar:clock-circle-bold' },
  PROCESSING: { label: 'Processing', color: 'bg-blue-500', icon: 'solar:refresh-circle-bold' },
  NEEDS_REVIEW: { label: 'Needs Review', color: 'bg-yellow-500', icon: 'solar:eye-bold' },
  POSTED: { label: 'Posted', color: 'bg-green-500', icon: 'solar:check-circle-bold' },
  EXTRACTION_FAILED: { label: 'Failed', color: 'bg-red-500', icon: 'solar:close-circle-bold' },
}

export function DocumentList({ documents, onRefresh }: DocumentListProps) {
  const navigate = useNavigate()
  const { toast } = useToast()
  const [searchTerm, setSearchTerm] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('all')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [unpostDialogOpen, setUnpostDialogOpen] = useState(false)
  const [documentToDelete, setDocumentToDelete] = useState<string | null>(null)
  const [documentToUnpost, setDocumentToUnpost] = useState<string | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)
  const [isUnposting, setIsUnposting] = useState(false)

  const filteredDocuments = documents.filter((doc) => {
    const matchesSearch =
      doc.vendor_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
      doc.file_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
      doc.total_amount?.toString().includes(searchTerm)

    const matchesStatus = statusFilter === 'all' || doc.status === statusFilter

    return matchesSearch && matchesStatus
  })

  const getStatusBadge = (status: string) => {
    const config = statusConfig[status as keyof typeof statusConfig] || statusConfig.PENDING
    return (
      <Badge className={`${config.color} text-white`}>
        <Icon icon={config.icon} className="mr-1 h-3 w-3" />
        {config.label}
      </Badge>
    )
  }

  const handleDeleteClick = (e: React.MouseEvent, uuid: string) => {
    e.stopPropagation()
    setDocumentToDelete(uuid)
    setDeleteDialogOpen(true)
  }

  const handleUnpostClick = (e: React.MouseEvent, uuid: string) => {
    e.stopPropagation()
    setDocumentToUnpost(uuid)
    setUnpostDialogOpen(true)
  }

  const handleDeleteConfirm = async () => {
    if (!documentToDelete) return

    setIsDeleting(true)
    try {
      await api.delete(`/ocr/documents/${documentToDelete}`)
      toast({
        title: 'Success',
        description: 'Document deleted successfully',
      })
      onRefresh()
    } catch (error: any) {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete document',
        variant: 'destructive',
      })
    } finally {
      setIsDeleting(false)
      setDeleteDialogOpen(false)
      setDocumentToDelete(null)
    }
  }

  const handleUnpostConfirm = async () => {
    if (!documentToUnpost) return

    setIsUnposting(true)
    try {
      await api.post(`/ocr/documents/${documentToUnpost}/unpost`)
      toast({
        title: 'Success',
        description: 'Document unposted successfully. You can now delete it if needed.',
      })
      onRefresh()
    } catch (error: any) {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to unpost document',
        variant: 'destructive',
      })
    } finally {
      setIsUnposting(false)
      setUnpostDialogOpen(false)
      setDocumentToUnpost(null)
    }
  }

  return (
    <div className="space-y-4">
      {/* Filters */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex gap-4">
            <div className="flex-1">
              <Input
                placeholder="Search by vendor, filename, or amount..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full"
              />
            </div>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-[200px]">
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
            <Button variant="outline" size="icon" onClick={onRefresh}>
              <Icon icon="solar:refresh-bold" className="h-4 w-4" />
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Document Cards */}
      <div className="grid gap-4">
        {filteredDocuments.length === 0 ? (
          <Card>
            <CardContent className="py-12 text-center">
              <Icon icon="solar:document-text-bold-duotone" className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
              <p className="text-muted-foreground">
                {documents.length === 0
                  ? 'No documents uploaded yet. Click "Upload Documents" to get started.'
                  : 'No documents match your filters.'}
              </p>
            </CardContent>
          </Card>
        ) : (
          filteredDocuments.map((doc) => (
            <Card
              key={doc.uuid}
              className="cursor-pointer hover:shadow-md transition-shadow"
              onClick={() => navigate(`/ocr/documents/${doc.uuid}`)}
            >
              <CardContent className="p-6">
                <div className="flex items-start justify-between">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-3 mb-2">
                      <Icon icon="solar:document-text-bold" className="h-5 w-5 text-muted-foreground flex-shrink-0" />
                      <h3 className="font-semibold truncate">{doc.file_name}</h3>
                      {getStatusBadge(doc.status)}
                    </div>

                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4 text-sm">
                      <div>
                        <p className="text-muted-foreground">Vendor</p>
                        <p className="font-medium">{doc.vendor_name || 'Unknown'}</p>
                      </div>
                      <div>
                        <p className="text-muted-foreground">Date</p>
                        <p className="font-medium">
                          {doc.document_date
                            ? new Date(doc.document_date).toLocaleDateString()
                            : 'N/A'}
                        </p>
                      </div>
                      <div>
                        <p className="text-muted-foreground">Amount</p>
                        <p className="font-medium">
                          {formatCurrency(doc.total_amount || 0, doc.currency_code || 'USD')}
                        </p>
                      </div>
                      <div>
                        <p className="text-muted-foreground">Uploaded</p>
                        <p className="font-medium">
                          {new Date(doc.created_at).toLocaleDateString()}
                        </p>
                      </div>
                    </div>
                  </div>

                  <div className="flex gap-2 flex-shrink-0">
                    {doc.status === 'POSTED' && (
                      <Button
                        variant="outline"
                        size="icon"
                        onClick={(e) => handleUnpostClick(e, doc.uuid)}
                        className="text-orange-600 hover:text-orange-700 hover:bg-orange-50"
                        title="Unpost document"
                      >
                        <Icon icon="solar:undo-left-bold" className="h-5 w-5" />
                      </Button>
                    )}
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={(e) => handleDeleteClick(e, doc.uuid)}
                      className="text-destructive hover:text-destructive"
                      title="Delete document"
                    >
                      <Icon icon="solar:trash-bin-trash-bold" className="h-5 w-5" />
                    </Button>
                    <Button variant="ghost" size="icon">
                      <Icon icon="solar:alt-arrow-right-bold" className="h-5 w-5" />
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))
        )}
      </div>

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Document</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete this document? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isDeleting}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteConfirm}
              disabled={isDeleting}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {isDeleting ? 'Deleting...' : 'Delete'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Unpost Confirmation Dialog */}
      <AlertDialog open={unpostDialogOpen} onOpenChange={setUnpostDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Unpost Document</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to unpost this document? This will reverse the journal entry and change the document status back to "Needs Review".
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isUnposting}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleUnpostConfirm}
              disabled={isUnposting}
              className="bg-orange-600 text-white hover:bg-orange-700"
            >
              {isUnposting ? 'Unposting...' : 'Unpost'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
