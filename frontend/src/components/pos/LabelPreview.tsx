import { Card } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { ScrollArea } from '@/components/ui/scroll-area'

interface LabelData {
  product_name: string
  barcode: string
  price: string
  sku: string
}

interface LabelPreviewProps {
  labels: LabelData[]
  loading?: boolean
}

export function LabelPreview({ labels, loading }: LabelPreviewProps) {
  if (loading) {
    return (
      <Card className="w-full h-full p-4 bg-muted/30 animate-pulse">
        <div className="space-y-2">
          <div className="h-20 bg-muted rounded" />
          <div className="h-20 bg-muted rounded" />
          <div className="h-20 bg-muted rounded" />
        </div>
      </Card>
    )
  }

  if (!labels || labels.length === 0) {
    return (
      <Card className="w-full h-full p-4 bg-muted/30 flex items-center justify-center">
        <p className="text-sm text-muted-foreground">Label previews will appear here</p>
      </Card>
    )
  }

  return (
    <Card className="w-full h-full overflow-hidden">
      <div className="p-3 border-b bg-muted/30">
        <div className="flex items-center justify-between">
          <span className="text-sm font-medium">Labels to Print</span>
          <Badge variant="secondary">{labels.length} label{labels.length !== 1 ? 's' : ''}</Badge>
        </div>
      </div>
      
      <ScrollArea className="h-[calc(100%-60px)]">
        <div className="p-3 space-y-2">
          {labels.map((label, index) => (
            <Card key={index} className="p-3 bg-white border-2 border-dashed">
              {/* Label dimensions: 76mm x 50mm */}
              <div className="space-y-2">
                {/* Product Name */}
                <div className="font-bold text-xs leading-tight line-clamp-2 min-h-[2rem]">
                  {label.product_name}
                </div>
                
                {/* Barcode representation */}
                {label.barcode && (
                  <div className="bg-black/5 p-2 rounded text-center">
                    <div className="flex justify-center gap-[1px] mb-1">
                      {/* Simple barcode visualization */}
                      {Array.from({ length: 12 }).map((_, i) => (
                        <div
                          key={i}
                          className="w-1 bg-black"
                          style={{ height: `${20 + Math.random() * 10}px` }}
                        />
                      ))}
                    </div>
                    <div className="text-[9px] font-mono">{label.barcode}</div>
                  </div>
                )}
                
                {/* Price */}
                <div className="flex justify-between items-center">
                  <span className="text-xl font-bold">{label.price}</span>
                  {label.sku && (
                    <span className="text-[9px] text-muted-foreground">SKU: {label.sku}</span>
                  )}
                </div>
              </div>
            </Card>
          ))}
        </div>
      </ScrollArea>
    </Card>
  )
}
