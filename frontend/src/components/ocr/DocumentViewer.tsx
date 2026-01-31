import { Icon } from '@iconify/react'

interface DocumentViewerProps {
  document: {
    uuid: string
    file_path: string
    file_name: string
    mime_type: string
  }
}

export function DocumentViewer({ document }: DocumentViewerProps) {
  const isPDF = document.mime_type === 'application/pdf'
  const isImage = document.mime_type?.startsWith('image/')

  // Use the API endpoint to get the file (no auth required - UUID is secure)
  const documentUrl = `http://demo.localhost:8000/api/v1/ocr/documents/${document.uuid}/file`

  if (isPDF) {
    return (
      <div className="w-full h-full">
        <iframe
          src={documentUrl}
          className="w-full h-full border-0 rounded-lg"
          title={document.file_name}
        />
      </div>
    )
  }

  if (isImage) {
    return (
      <div className="w-full h-full flex items-center justify-center bg-muted/20 rounded-lg">
        <img
          src={documentUrl}
          alt={document.file_name}
          className="max-w-full max-h-full object-contain rounded-lg"
          onError={(e) => {
            console.error('Image failed to load:', documentUrl)
          }}
        />
      </div>
    )
  }

  return (
    <div className="w-full h-full flex flex-col items-center justify-center text-center p-8">
      <Icon icon="solar:document-text-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
      <p className="text-muted-foreground">
        Preview not available for this file type
      </p>
      <p className="text-sm text-muted-foreground mt-2">
        {document.file_name}
      </p>
    </div>
  )
}
