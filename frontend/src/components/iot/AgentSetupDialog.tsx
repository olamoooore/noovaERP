import { Icon } from '@iconify/react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'

interface AgentSetupDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function AgentSetupDialog({ open, onOpenChange }: AgentSetupDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[600px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="solar:download-bold-duotone" className="h-5 w-5 text-primary" />
            Install Local Agent
          </DialogTitle>
          <DialogDescription>
            Set up the NoovaERP IoT Local Agent on your Windows POS machine
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          {/* System Requirements */}
          <div className="space-y-2">
            <h4 className="font-semibold text-sm flex items-center gap-2">
              <Icon icon="solar:laptop-bold" className="h-4 w-4" />
              System Requirements
            </h4>
            <div className="grid grid-cols-2 gap-2 text-sm">
              <div className="flex items-center gap-2">
                <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-green-500" />
                <span>Windows 10/11</span>
              </div>
              <div className="flex items-center gap-2">
                <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-green-500" />
                <span>Node.js 18+</span>
              </div>
              <div className="flex items-center gap-2">
                <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-green-500" />
                <span>2GB RAM</span>
              </div>
              <div className="flex items-center gap-2">
                <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-green-500" />
                <span>USB Ports</span>
              </div>
            </div>
          </div>

          <Separator />

          {/* Download Options */}
          <div className="space-y-3">
            <h4 className="font-semibold text-sm flex items-center gap-2">
              <Icon icon="solar:download-square-bold" className="h-4 w-4" />
              Download Options
            </h4>
            
            <div className="space-y-2">
              <Button className="w-full justify-between" size="lg">
                <div className="flex items-center gap-3">
                  <Icon icon="solar:windows-bold" className="h-5 w-5" />
                  <div className="text-left">
                    <div className="font-semibold">Windows Installer (.exe)</div>
                    <div className="text-xs opacity-80">Recommended - Auto-install with GUI</div>
                  </div>
                </div>
                <Badge variant="secondary">v1.0.0</Badge>
              </Button>

              <Button variant="outline" className="w-full justify-between" size="lg">
                <div className="flex items-center gap-3">
                  <Icon icon="solar:code-bold" className="h-5 w-5" />
                  <div className="text-left">
                    <div className="font-semibold">Source Code (.zip)</div>
                    <div className="text-xs opacity-80">For developers - Manual setup</div>
                  </div>
                </div>
                <Icon icon="solar:download-bold" className="h-4 w-4" />
              </Button>
            </div>
          </div>

          <Separator />

          {/* Installation Steps */}
          <div className="space-y-3">
            <h4 className="font-semibold text-sm flex items-center gap-2">
              <Icon icon="solar:list-check-bold" className="h-4 w-4" />
              Installation Steps
            </h4>
            
            <div className="space-y-2 text-sm">
              <div className="flex gap-3">
                <div className="flex-shrink-0 w-6 h-6 rounded-full bg-primary/10 text-primary flex items-center justify-center font-semibold text-xs">
                  1
                </div>
                <div>
                  <p className="font-medium">Download the installer</p>
                  <p className="text-muted-foreground text-xs">Choose the Windows Installer option above</p>
                </div>
              </div>

              <div className="flex gap-3">
                <div className="flex-shrink-0 w-6 h-6 rounded-full bg-primary/10 text-primary flex items-center justify-center font-semibold text-xs">
                  2
                </div>
                <div>
                  <p className="font-medium">Run the installer</p>
                  <p className="text-muted-foreground text-xs">Double-click noovaerp-iot-agent-setup.exe and follow the wizard</p>
                </div>
              </div>

              <div className="flex gap-3">
                <div className="flex-shrink-0 w-6 h-6 rounded-full bg-primary/10 text-primary flex items-center justify-center font-semibold text-xs">
                  3
                </div>
                <div>
                  <p className="font-medium">Configure connection</p>
                  <p className="text-muted-foreground text-xs">Enter your tenant domain and API token</p>
                </div>
              </div>

              <div className="flex gap-3">
                <div className="flex-shrink-0 w-6 h-6 rounded-full bg-primary/10 text-primary flex items-center justify-center font-semibold text-xs">
                  4
                </div>
                <div>
                  <p className="font-medium">Connect USB devices</p>
                  <p className="text-muted-foreground text-xs">Plug in your payment terminals, scanners, or printers</p>
                </div>
              </div>

              <div className="flex gap-3">
                <div className="flex-shrink-0 w-6 h-6 rounded-full bg-primary/10 text-primary flex items-center justify-center font-semibold text-xs">
                  5
                </div>
                <div>
                  <p className="font-medium">Verify connection</p>
                  <p className="text-muted-foreground text-xs">Check this page to see your agent online</p>
                </div>
              </div>
            </div>
          </div>

          <Separator />

          {/* Help Links */}
          <div className="flex items-center justify-between text-sm">
            <Button variant="link" className="p-0 h-auto">
              <Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />
              Documentation
            </Button>
            <Button variant="link" className="p-0 h-auto">
              <Icon icon="solar:question-circle-bold" className="mr-2 h-4 w-4" />
              Troubleshooting
            </Button>
            <Button variant="link" className="p-0 h-auto">
              <Icon icon="solar:video-library-bold" className="mr-2 h-4 w-4" />
              Video Tutorial
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
