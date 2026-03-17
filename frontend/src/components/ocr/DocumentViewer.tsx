import { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import api from '@/lib/api'

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
  const [blobUrl, setBlobUrl] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  useEffect(() => {
    let cancelled = false

    async function fetchFile() {
      try {
        setLoading(true)
        setError(false)
        const response = await api.get(`/ocr/documents/${document.uuid}/file`, {
          responseType: 'blob',
        })
        if (cancelled) return
        const url = URL.createObjectURL(response.data)
        setBlobUrl(url)
      } catch (e) {
        console.error('Failed to load document file:', e)
        if (!cancelled) setError(true)
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    fetchFile()
    return () => {
      cancelled = true
      if (blobUrl) URL.revokeObjectURL(blobUrl)
    }
  }, [document.uuid])

  if (loading) {
    return (
      <div className="w-full h-full flex items-center justify-center">
        <Icon icon="solar:refresh-circle-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (error || !blobUrl) {
    return (
      <div className="w-full h-full flex flex-col items-center justify-center text-center p-8">
        <Icon icon="solar:danger-triangle-bold-duotone" className="h-12 w-12 text-red-400 mb-4" />
        <p className="text-muted-foreground">Failed to load document preview</p>
        <p className="text-sm text-muted-foreground mt-2">{document.file_name}</p>
      </div>
    )
  }

  if (isPDF) {
    return (
      <div className="w-full h-full">
        <iframe
          src={blobUrl}
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
          src={blobUrl}
          alt={document.file_name}
          className="max-w-full max-h-full object-contain rounded-lg"
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
