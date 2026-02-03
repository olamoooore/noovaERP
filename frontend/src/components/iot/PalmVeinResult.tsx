import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'
import { PalmVeinParsedData } from '@/lib/iotApi'

interface PalmVeinResultProps {
  rawData: string
  parsed?: PalmVeinParsedData
  timestamp: string
  onCopy?: (text: string) => void
}

export function PalmVeinResult({ rawData, parsed, timestamp, onCopy }: PalmVeinResultProps) {
  const getQualityColor = (quality?: number) => {
    if (!quality) return 'text-gray-500'
    if (quality >= 80) return 'text-green-600'
    if (quality >= 60) return 'text-blue-600'
    if (quality >= 40) return 'text-yellow-600'
    return 'text-red-600'
  }

  const getQualityLabel = (quality?: number) => {
    if (!quality) return 'Unknown'
    if (quality >= 80) return 'Excellent'
    if (quality >= 60) return 'Good'
    if (quality >= 40) return 'Fair'
    return 'Poor'
  }

  const getMatchColor = (score?: number) => {
    if (score === null || score === undefined) return 'bg-gray-100 text-gray-700'
    if (score >= 90) return 'bg-green-100 text-green-700'
    if (score >= 70) return 'bg-blue-100 text-blue-700'
    if (score >= 50) return 'bg-yellow-100 text-yellow-700'
    return 'bg-red-100 text-red-700'
  }

  const getMatchLabel = (score?: number) => {
    if (score === null || score === undefined) return 'No Match'
    if (score >= 90) return 'Strong Match'
    if (score >= 70) return 'Match'
    if (score >= 50) return 'Weak Match'
    return 'No Match'
  }

  const handleCopy = () => {
    onCopy?.(rawData)
  }

  return (
    <Card className="border-purple-500/30 bg-purple-500/5">
      <CardHeader className="pb-2">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Icon icon="solar:fingerprint-bold-duotone" className="h-5 w-5 text-purple-600" />
            <CardTitle className="text-sm">Palm Vein Scanned</CardTitle>
          </div>
          <Button variant="ghost" size="icon" className="h-8 w-8" onClick={handleCopy}>
            <Icon icon="solar:copy-bold" className="h-4 w-4" />
          </Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        {parsed && parsed.type === 'palm_vein' ? (
          <div className="space-y-3">
            {/* Quality Score */}
            {parsed.data?.quality !== null && parsed.data?.quality !== undefined && (
              <div className="p-3 bg-background rounded-lg">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-sm font-medium">Capture Quality</span>
                  <span className={`text-sm font-bold ${getQualityColor(parsed.data.quality)}`}>
                    {getQualityLabel(parsed.data.quality)}
                  </span>
                </div>
                <div className="w-full bg-gray-200 rounded-full h-2">
                  <div
                    className={`h-2 rounded-full transition-all ${
                      parsed.data.quality >= 80
                        ? 'bg-green-500'
                        : parsed.data.quality >= 60
                          ? 'bg-blue-500'
                          : parsed.data.quality >= 40
                            ? 'bg-yellow-500'
                            : 'bg-red-500'
                    }`}
                    style={{ width: `${parsed.data.quality}%` }}
                  />
                </div>
                <p className="text-xs text-muted-foreground mt-1">{parsed.data.quality}%</p>
              </div>
            )}

            {/* Match Score */}
            {parsed.data?.matchScore !== null && parsed.data?.matchScore !== undefined && (
              <div className={`p-3 rounded-lg ${getMatchColor(parsed.data.matchScore)}`}>
                <div className="flex items-center justify-between mb-2">
                  <span className="text-sm font-medium">Match Result</span>
                  <Badge variant="outline" className="text-xs">
                    {getMatchLabel(parsed.data.matchScore)}
                  </Badge>
                </div>
                <div className="w-full bg-gray-200 rounded-full h-2">
                  <div
                    className={`h-2 rounded-full transition-all ${
                      parsed.data.matchScore >= 90
                        ? 'bg-green-500'
                        : parsed.data.matchScore >= 70
                          ? 'bg-blue-500'
                          : parsed.data.matchScore >= 50
                            ? 'bg-yellow-500'
                            : 'bg-red-500'
                    }`}
                    style={{ width: `${parsed.data.matchScore}%` }}
                  />
                </div>
                <p className="text-xs text-muted-foreground mt-1">{parsed.data.matchScore}%</p>
              </div>
            )}

            {/* Template Info */}
            {parsed.data?.template && (
              <div className="p-3 bg-background rounded-lg">
                <p className="text-xs text-muted-foreground mb-1">Template</p>
                <p className="font-mono text-xs break-all text-gray-600">
                  {parsed.data.template.length > 100
                    ? parsed.data.template.substring(0, 100) + '...'
                    : parsed.data.template}
                </p>
                {parsed.data.format && (
                  <Badge variant="outline" className="mt-2 text-xs">
                    {parsed.data.format}
                  </Badge>
                )}
              </div>
            )}

            {/* Timestamp */}
            <p className="text-xs text-muted-foreground">
              Scanned at {new Date(timestamp).toLocaleTimeString()}
            </p>
          </div>
        ) : (
          <div className="p-3 bg-background rounded-lg">
            <p className="text-xs text-muted-foreground mb-1">Raw Template Data</p>
            <p className="font-mono text-xs break-all text-gray-600">
              {rawData.length > 100 ? rawData.substring(0, 100) + '...' : rawData}
            </p>
            <p className="text-xs text-muted-foreground mt-2">
              Scanned at {new Date(timestamp).toLocaleTimeString()}
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
