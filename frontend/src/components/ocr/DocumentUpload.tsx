import { useState, useCallback } from 'react'
import { useDropzone } from 'react-dropzone'
import { useMutation } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Progress } from '@/components/ui/progress'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface DocumentUploadProps {
  onComplete: () => void
  onCancel: () => void
}

interface UploadFile {
  file: File
  progress: number
  status: 'pending' | 'uploading' | 'success' | 'error'
  error?: string
  uuid?: string
}

interface UploadResponse {
  message: string
  error?: string
  data?: {
    uuid?: string
    status?: string
    error_message?: string | null
  }
}

export function DocumentUpload({ onComplete, onCancel }: DocumentUploadProps) {
  const [files, setFiles] = useState<UploadFile[]>([])
  const { toast } = useToast()

  const uploadMutation = useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('file', file)

      const response = await api.post('/ocr/documents/upload', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
        onUploadProgress: (progressEvent) => {
          const progress = progressEvent.total
            ? Math.round((progressEvent.loaded * 100) / progressEvent.total)
            : 0
          
          setFiles((prev) =>
            prev.map((f) =>
              f.file === file ? { ...f, progress, status: 'uploading' } : f
            )
          )
        },
      })

      return response.data
    },
    onSuccess: (data: UploadResponse, file) => {
      if (data.data?.status === 'EXTRACTION_FAILED') {
        const errorMessage = data.error || data.data.error_message || 'OCR extraction failed'

        setFiles((prev) =>
          prev.map((f) =>
            f.file === file
              ? { ...f, status: 'error', progress: 100, error: errorMessage, uuid: data.data?.uuid }
              : f
          )
        )

        toast({
          variant: 'destructive',
          title: 'OCR extraction failed',
          description: errorMessage,
        })

        return
      }

      setFiles((prev) =>
        prev.map((f) =>
          f.file === file
            ? { ...f, status: 'success', progress: 100, uuid: data.data?.uuid }
            : f
        )
      )
    },
    onError: (error: any, file) => {
      const errorMessage = error.response?.data?.error || error.response?.data?.message || 'Upload failed'
      const uuid = error.response?.data?.data?.uuid

      setFiles((prev) =>
        prev.map((f) =>
          f.file === file ? { ...f, status: 'error', error: errorMessage, uuid } : f
        )
      )

      toast({
        variant: 'destructive',
        title: 'Upload failed',
        description: errorMessage,
      })
    },
  })

  const onDrop = useCallback((acceptedFiles: File[]) => {
    const newFiles: UploadFile[] = acceptedFiles.map((file) => ({
      file,
      progress: 0,
      status: 'pending',
    }))

    setFiles((prev) => [...prev, ...newFiles])

    // Start uploading files
    newFiles.forEach((uploadFile) => {
      uploadMutation.mutate(uploadFile.file)
    })
  }, [uploadMutation])

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    accept: {
      'image/jpeg': ['.jpg', '.jpeg'],
      'image/png': ['.png'],
      'application/pdf': ['.pdf'],
    },
    maxSize: 10 * 1024 * 1024, // 10MB
    multiple: true,
  })

  const allComplete = files.length > 0 && files.every((f) => f.status === 'success' || f.status === 'error')
  const hasSuccess = files.some((f) => f.status === 'success')

  const handleComplete = () => {
    if (hasSuccess) {
      toast({
        title: 'Upload complete',
        description: `${files.filter((f) => f.status === 'success').length} document(s) uploaded and extracted successfully`,
      })
      onComplete()
    }
  }

  const removeFile = (index: number) => {
    setFiles((prev) => prev.filter((_, i) => i !== index))
  }

  return (
    <div className="space-y-4">
      {/* Dropzone */}
      <div
        {...getRootProps()}
        className={`
          border-2 border-dashed rounded-lg p-8 text-center cursor-pointer transition-colors
          ${isDragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25 hover:border-primary/50'}
        `}
      >
        <input {...getInputProps()} />
        <Icon icon="solar:cloud-upload-bold-duotone" className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
        {isDragActive ? (
          <p className="text-lg font-medium">Drop files here...</p>
        ) : (
          <div>
            <p className="text-lg font-medium mb-2">Drag & drop files here, or click to select</p>
            <p className="text-sm text-muted-foreground">
              Supports JPG, PNG, PDF (max 10MB per file)
            </p>
          </div>
        )}
      </div>

      {/* File List */}
      {files.length > 0 && (
        <div className="space-y-2">
          <h3 className="font-medium">Uploading {files.length} file(s)</h3>
          {files.map((uploadFile, index) => (
            <div key={index} className="border rounded-lg p-4 space-y-2">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2 flex-1 min-w-0">
                  {uploadFile.status === 'success' && (
                    <Icon icon="solar:check-circle-bold" className="h-5 w-5 text-green-500 flex-shrink-0" />
                  )}
                  {uploadFile.status === 'error' && (
                    <Icon icon="solar:close-circle-bold" className="h-5 w-5 text-red-500 flex-shrink-0" />
                  )}
                  {(uploadFile.status === 'pending' || uploadFile.status === 'uploading') && (
                    <Icon icon="solar:refresh-circle-bold" className="h-5 w-5 text-blue-500 animate-spin flex-shrink-0" />
                  )}
                  <span className="text-sm font-medium truncate">{uploadFile.file.name}</span>
                  <span className="text-xs text-muted-foreground flex-shrink-0">
                    ({(uploadFile.file.size / 1024 / 1024).toFixed(2)} MB)
                  </span>
                </div>
                {uploadFile.status === 'error' && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => removeFile(index)}
                  >
                    <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                  </Button>
                )}
              </div>

              {uploadFile.status === 'uploading' && (
                <Progress value={uploadFile.progress} className="h-2" />
              )}

              {uploadFile.error && (
                <p className="text-sm text-red-500">{uploadFile.error}</p>
              )}

              {uploadFile.status === 'success' && (
                <p className="text-sm text-green-600">
                  Upload complete - OCR extraction finished successfully
                </p>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Actions */}
      <div className="flex justify-end gap-2">
        <Button variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        {allComplete && hasSuccess && (
          <Button onClick={handleComplete}>
            Done
          </Button>
        )}
      </div>
    </div>
  )
}
