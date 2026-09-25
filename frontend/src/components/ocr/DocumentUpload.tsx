import { useState, useCallback } from 'react'
import { useDropzone } from 'react-dropzone'
import { useMutation } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Progress } from '@/components/ui/progress'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface DocumentUploadProps {
  onComplete: () => void
  onCancel: () => void
}

interface UploadFile {
  file: File
  progress: number
  status: 'pending' | 'uploading' | 'success' | 'duplicate' | 'error'
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
      const status = error.response?.status
      const uuid = error.response?.data?.data?.uuid

      // 409 = the same file was already uploaded (the first attempt created
      // the record, so a retry hits the duplicate guard). That is not a
      // failure — surface the existing document instead of a red error.
      if (status === 409) {
        setFiles((prev) =>
          prev.map((f) =>
            f.file === file
              ? { ...f, status: 'duplicate', progress: 100, uuid: error.response?.data?.data?.existing_uuid }
              : f
          )
        )

        toast({
          title: 'Already uploaded',
          description: error.response?.data?.message || 'This document was uploaded before — showing the existing record.',
        })

        return
      }

      const errorMessage = error.response?.data?.error || error.response?.data?.message || 'Upload failed'

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

  const allComplete = files.length > 0 && files.every((f) => f.status === 'success' || f.status === 'duplicate' || f.status === 'error')
  const hasSuccess = files.some((f) => f.status === 'success' || f.status === 'duplicate')

  const handleComplete = () => {
    if (hasSuccess) {
      toast({
        title: 'Upload complete',
        description: `${files.filter((f) => f.status === 'success' || f.status === 'duplicate').length} document(s) uploaded and extracted successfully`,
      })
      onComplete()
    }
  }

  const removeFile = (index: number) => {
    setFiles((prev) => prev.filter((_, i) => i !== index))
  }

  const fileStatusMeta = (status: UploadFile['status']) => {
    switch (status) {
      case 'success':
        return {
          icon: 'solar:check-circle-bold',
          tile: 'bg-emerald-50 text-emerald-600',
          message: 'Upload complete — OCR extraction finished successfully',
          messageClass: 'text-emerald-700',
          spin: false,
        }
      case 'duplicate':
        return {
          icon: 'solar:info-circle-bold',
          tile: 'bg-amber-50 text-amber-600',
          message: 'Already uploaded — the existing document is shown in the list.',
          messageClass: 'text-amber-700',
          spin: false,
        }
      case 'error':
        return {
          icon: 'solar:close-circle-bold',
          tile: 'bg-red-50 text-red-600',
          message: null,
          messageClass: 'text-red-600',
          spin: false,
        }
      default:
        return {
          icon: 'solar:refresh-circle-bold',
          tile: 'bg-blue-50 text-blue-600',
          message: null,
          messageClass: '',
          spin: true,
        }
    }
  }

  return (
    <div className="space-y-5">
      {/* Dropzone */}
      <div
        {...getRootProps()}
        className={`cursor-pointer rounded-xl border-2 border-dashed p-10 text-center transition-colors ${
          isDragActive
            ? 'border-primary bg-primary/5'
            : 'border-muted-foreground/25 hover:border-primary/50 hover:bg-muted/30'
        }`}
      >
        <input {...getInputProps()} className="hidden" />
        <div className="mx-auto grid h-16 w-16 place-items-center rounded-full bg-primary/10 text-primary">
          <Icon icon="solar:cloud-upload-bold-duotone" className="h-8 w-8" />
        </div>
        {isDragActive ? (
          <p className="mt-4 text-lg font-medium">Drop files to upload…</p>
        ) : (
          <>
            <p className="mt-4 text-lg font-medium">Drag & drop files here</p>
            <p className="mt-1 text-sm text-muted-foreground">
              or <span className="font-medium text-primary">click to browse</span> · JPG, PNG, PDF up to 10MB per file
            </p>
          </>
        )}
      </div>

      {/* File List */}
      {files.length > 0 && (
        <div className="space-y-2">
          <h3 className="text-sm font-semibold">
            Uploading {files.length} file{files.length === 1 ? '' : 's'}
          </h3>
          {files.map((uploadFile, index) => {
            const meta = fileStatusMeta(uploadFile.status)
            return (
              <div key={index} className="rounded-xl border p-4">
                <div className="flex items-center gap-3">
                  <div className={`grid h-9 w-9 shrink-0 place-items-center rounded-lg ${meta.tile}`}>
                    <Icon
                      icon={meta.icon}
                      className={`h-4 w-4 ${meta.spin ? 'animate-spin' : ''}`}
                    />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{uploadFile.file.name}</p>
                    <p className="text-xs text-muted-foreground">
                      {(uploadFile.file.size / 1024 / 1024).toFixed(2)} MB
                    </p>
                  </div>
                  {uploadFile.status === 'error' && (
                    <HeaderActionButton
                      type="button"
                      variant="ghost"
                      icon="solar:trash-bin-trash-bold"
                      className="h-7 px-2 text-destructive hover:text-destructive"
                      aria-label="Remove file"
                      onClick={() => removeFile(index)}
                    />
                  )}
                </div>

                {uploadFile.status === 'uploading' && (
                  <Progress value={uploadFile.progress} className="mt-3 h-1.5" />
                )}

                {uploadFile.status === 'error' && uploadFile.error && (
                  <p className={`mt-2 text-sm ${meta.messageClass}`}>{uploadFile.error}</p>
                )}

                {meta.message && (
                  <p className={`mt-2 text-sm ${meta.messageClass}`}>{meta.message}</p>
                )}
              </div>
            )
          })}
        </div>
      )}

      {/* Actions */}
      <div className="flex justify-end gap-2">
        <HeaderActionButton type="button" variant="outline" onClick={onCancel}>
          Cancel
        </HeaderActionButton>
        {allComplete && hasSuccess && (
          <HeaderActionButton type="button" icon="solar:check-circle-bold" onClick={handleComplete}>
            Done
          </HeaderActionButton>
        )}
      </div>
    </div>
  )
}
