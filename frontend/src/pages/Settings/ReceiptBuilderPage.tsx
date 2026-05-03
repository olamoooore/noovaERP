import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useReceiptBuilderStore } from '@/store/receiptBuilderStore'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'
import { BuilderToolbar } from '@/components/receipt-builder/BuilderToolbar'
import { BuilderSidebar } from '@/components/receipt-builder/BuilderSidebar'
import { ReceiptCanvas } from '@/components/receipt-builder/ReceiptCanvas'

export function ReceiptBuilderPage() {
  const navigate = useNavigate()
  const { config, createNewConfig, hasUnsavedChanges } = useReceiptBuilderStore()

  useEffect(() => {
    // Initialize with a new config if none exists
    if (!config) {
      createNewConfig('My Receipt Template', '80mm')
    }
  }, [config, createNewConfig])

  // Warn before leaving if there are unsaved changes
  useEffect(() => {
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (hasUnsavedChanges) {
        e.preventDefault()
        e.returnValue = ''
      }
    }

    window.addEventListener('beforeunload', handleBeforeUnload)
    return () => window.removeEventListener('beforeunload', handleBeforeUnload)
  }, [hasUnsavedChanges])

  if (!config) {
    return (
      <div className="flex items-center justify-center h-screen bg-zinc-900">
        <div className="text-center">
          <Icon icon="solar:refresh-bold" className="w-8 h-8 mx-auto mb-4 text-zinc-400 animate-spin" />
          <p className="text-zinc-400">Loading receipt builder...</p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col h-screen bg-zinc-900">
      {/* Top Toolbar */}
      <header className="flex items-center justify-between h-14 px-4 bg-zinc-800 border-b border-zinc-700">
        <div className="flex items-center gap-4">
          <Button 
            variant="ghost" 
            size="icon" 
            className="text-zinc-400 hover:text-white hover:bg-zinc-700"
            onClick={() => {
              if (hasUnsavedChanges) {
                const confirm = window.confirm('You have unsaved changes. Are you sure you want to leave?')
                if (!confirm) return
              }
              navigate('/settings')
            }}
          >
            <Icon icon="solar:arrow-left-bold" className="h-5 w-5" />
          </Button>
          
          <div className="flex items-center gap-3">
            <Icon icon="solar:document-text-bold-duotone" className="h-6 w-6 text-teal-500" />
            <div>
              <h1 className="font-semibold text-white">Receipt Builder</h1>
              <p className="text-xs text-zinc-400">Design your custom receipt layout</p>
            </div>
          </div>
        </div>

        {/* Toolbar Actions */}
        <BuilderToolbar />
      </header>

      {/* Main Content */}
      <div className="flex flex-1 overflow-hidden">
        {/* Sidebar */}
        <BuilderSidebar />

        {/* Canvas Area */}
        <div className="flex-1 overflow-auto bg-zinc-800 p-8">
          <div className="max-w-4xl mx-auto">
            <ReceiptCanvas />
          </div>
        </div>
      </div>
    </div>
  )
}
