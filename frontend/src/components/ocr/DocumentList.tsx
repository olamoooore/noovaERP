import { useState } from 'react'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { getOcrStatusStyle } from '@/components/ocr/ocrStatus'
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
  searchTerm: string
  statusFilter: string
}

const formatDate = (value?: string | null) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

export function DocumentList({ documents, onRefresh, searchTerm, statusFilter }: DocumentListProps) {
  const navigate = useNavigate()
  const { toast } = useToast()
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
    const config = getOcrStatusStyle(status)
    return (
      <Badge variant="outline" className={`gap-1 font-medium ${config.badge}`}>
        <Icon icon={config.icon} className="h-3.5 w-3.5" />
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
      {/* Document Cards */}
      <div className="grid gap-3">
        {filteredDocuments.length === 0 ? (
          <Card className="rounded-xl border-dashed">
            <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
              <div className="grid h-14 w-14 place-items-center rounded-full bg-muted">
                <Icon icon="solar:document-text-bold-duotone" className="h-7 w-7 text-muted-foreground" />
              </div>
              <div>
                <p className="font-medium">
                  {documents.length === 0 ? 'No documents yet' : 'No matching documents'}
                </p>
                <p className="mt-1 text-sm text-muted-foreground">
                  {documents.length === 0
                    ? 'Click "Upload Documents" to process your first invoice or receipt.'
                    : 'Try a different search or status filter.'}
                </p>
              </div>
            </CardContent>
          </Card>
        ) : (
          filteredDocuments.map((doc) => {
            const config = getOcrStatusStyle(doc.status)
            return (
              <Card
                key={doc.uuid}
                className="group cursor-pointer rounded-xl transition-shadow hover:shadow-md"
                onClick={() => navigate(`/ocr/documents/${doc.uuid}`)}
              >
                <CardContent className="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
                  {/* Identity */}
                  <div className="flex min-w-0 flex-1 items-center gap-4">
                    <div className={`grid h-11 w-11 shrink-0 place-items-center rounded-xl ${config.tile}`}>
                      <Icon icon="solar:document-text-bold" className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                      <div className="flex min-w-0 items-center gap-2">
                        <h3 className="truncate font-semibold">{doc.file_name}</h3>
                        {getStatusBadge(doc.status)}
                      </div>
                      <p className="mt-1 truncate text-sm text-muted-foreground">
                        {doc.vendor_name || 'Unknown vendor'}
                      </p>
                    </div>
                  </div>

                  {/* Facts */}
                  <div className="grid shrink-0 grid-cols-3 gap-6 text-sm">
                    <div className="min-w-20">
                      <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Date</p>
                      <p className="mt-0.5 font-medium">{formatDate(doc.document_date)}</p>
                    </div>
                    <div className="min-w-24">
                      <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Amount</p>
                      <p className="mt-0.5 font-medium">
                        {formatCurrency(doc.total_amount || 0, doc.currency_code || 'USD')}
                      </p>
                    </div>
                    <div className="min-w-24">
                      <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Uploaded</p>
                      <p className="mt-0.5 font-medium">{formatDate(doc.created_at)}</p>
                    </div>
                  </div>

                  {/* Actions */}
                  <div
                    className="flex shrink-0 items-center gap-2"
                    onClick={(e) => e.stopPropagation()}
                  >
                    {doc.status === 'POSTED' && (
                      <HeaderActionButton
                        variant="outline"
                        icon="solar:undo-left-bold"
                        className="text-orange-600 hover:bg-orange-50 hover:text-orange-700"
                        onClick={(e) => handleUnpostClick(e, doc.uuid)}
                      >
                        Unpost
                      </HeaderActionButton>
                    )}
                    <HeaderActionButton
                      variant="outline"
                      icon="solar:trash-bin-trash-bold"
                      className="text-destructive hover:bg-destructive/5 hover:text-destructive"
                      onClick={(e) => handleDeleteClick(e, doc.uuid)}
                    >
                      Delete
                    </HeaderActionButton>
                    <HeaderActionButton
                      icon="solar:eye-bold"
                      onClick={() => navigate(`/ocr/documents/${doc.uuid}`)}
                    >
                      Review
                    </HeaderActionButton>
                  </div>
                </CardContent>
              </Card>
            )
          })
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
