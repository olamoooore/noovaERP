import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'
import { QRParsedData } from '@/lib/iotApi'

interface QRScanResultProps {
  rawData: string
  parsed?: QRParsedData
  timestamp: string
  onAction?: (action: string, data: Record<string, any>) => void
  onCopy?: (text: string) => void
}

export function QRScanResult({ rawData, parsed, timestamp, onAction, onCopy }: QRScanResultProps) {
  const getTypeIcon = () => {
    switch (parsed?.type) {
      case 'json': return 'solar:code-bold-duotone'
      case 'url': return 'solar:link-bold-duotone'
      case 'noovaerp_product': return 'solar:box-bold-duotone'
      default: return 'solar:text-bold-duotone'
    }
  }

  const getTypeBadge = () => {
    switch (parsed?.type) {
      case 'json': return <Badge variant="secondary">JSON</Badge>
      case 'url': return <Badge variant="outline">URL</Badge>
      case 'noovaerp_product': return <Badge className="bg-primary text-primary-foreground">NoovaERP Product</Badge>
      default: return <Badge variant="outline">Text</Badge>
    }
  }

  const handleCopy = () => {
    onCopy?.(rawData)
  }

  return (
    <Card className="border-green-500/30 bg-green-500/5">
      <CardHeader className="pb-2">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Icon icon={getTypeIcon()} className="h-5 w-5 text-green-600" />
            <CardTitle className="text-sm">QR Code Scanned</CardTitle>
          </div>
          <div className="flex items-center gap-2">
            {getTypeBadge()}
            <Button variant="ghost" size="icon" className="h-8 w-8" onClick={handleCopy}>
              <Icon icon="solar:copy-bold" className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        {/* NoovaERP Product Format */}
        {parsed?.type === 'noovaerp_product' && parsed.data && (
          <div className="space-y-2">
            <div className="grid grid-cols-2 gap-2 text-sm">
              {parsed.data.prod && (
                <div className="flex justify-between p-2 bg-background rounded">
                  <span className="text-muted-foreground">Product:</span>
                  <span className="font-mono font-medium">{parsed.data.prod}</span>
                </div>
              )}
              {parsed.data.qty && (
                <div className="flex justify-between p-2 bg-background rounded">
                  <span className="text-muted-foreground">Quantity:</span>
                  <span className="font-mono font-medium">{parsed.data.qty}</span>
                </div>
              )}
              {parsed.data.price && (
                <div className="flex justify-between p-2 bg-background rounded">
                  <span className="text-muted-foreground">Price:</span>
                  <span className="font-mono font-medium">${parsed.data.price}</span>
                </div>
              )}
            </div>
            {onAction && (
              <Button 
                size="sm" 
                className="w-full"
                onClick={() => onAction('add_to_cart', parsed.data)}
              >
                <Icon icon="solar:cart-plus-bold" className="mr-2 h-4 w-4" />
                Add to Cart
              </Button>
            )}
          </div>
        )}

        {/* URL Format */}
        {parsed?.type === 'url' && parsed.data?.url && (
          <div className="space-y-2">
            <a 
              href={parsed.data.url} 
              target="_blank" 
              rel="noopener noreferrer"
              className="text-primary hover:underline break-all text-sm flex items-center gap-1"
            >
              <Icon icon="solar:link-bold" className="h-4 w-4 flex-shrink-0" />
              {parsed.data.url}
            </a>
            {onAction && (
              <Button 
                size="sm" 
                variant="outline"
                className="w-full"
                onClick={() => window.open(parsed.data.url, '_blank')}
              >
                <Icon icon="solar:square-arrow-right-up-bold" className="mr-2 h-4 w-4" />
                Open Link
              </Button>
            )}
          </div>
        )}

        {/* JSON Format */}
        {parsed?.type === 'json' && parsed.data && (
          <div className="space-y-2">
            <pre className="text-xs bg-background p-2 rounded overflow-auto max-h-32 font-mono">
              {JSON.stringify(parsed.data, null, 2)}
            </pre>
            {parsed.data.sku && onAction && (
              <Button 
                size="sm" 
                className="w-full"
                onClick={() => onAction('lookup_product', parsed.data)}
              >
                <Icon icon="solar:magnifer-bold" className="mr-2 h-4 w-4" />
                Lookup Product: {parsed.data.sku}
              </Button>
            )}
          </div>
        )}

        {/* Plain Text */}
        {parsed?.type === 'text' && (
          <div className="p-2 bg-background rounded">
            <p className="font-mono text-sm break-all">{parsed.data?.value || rawData}</p>
          </div>
        )}

        {/* No parsed data - show raw */}
        {!parsed && (
          <div className="p-2 bg-background rounded">
            <p className="font-mono text-sm break-all">{rawData}</p>
          </div>
        )}

        <p className="text-xs text-muted-foreground">
          Scanned at {new Date(timestamp).toLocaleTimeString()}
        </p>
      </CardContent>
    </Card>
  )
}
