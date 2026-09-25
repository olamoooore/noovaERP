import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Card } from '@/components/ui/card'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import complianceApi from '@/lib/complianceApi'

type ReportType = 'trial_balance' | 'balance_sheet' | 'income_statement'

const reportTypeOptions: Array<{ value: ReportType; label: string }> = [
  { value: 'trial_balance', label: 'Trial Balance' },
  { value: 'balance_sheet', label: 'Balance Sheet' },
  { value: 'income_statement', label: 'Income Statement' },
]

interface ComplianceReportsPageProps {
  embedded?: boolean
}

export default function ComplianceReportsPage({ embedded = false }: ComplianceReportsPageProps) {
  const navigate = useNavigate()
  const { toast } = useToast()

  const today = useMemo(() => new Date().toISOString().split('T')[0], [])
  const startOfYear = useMemo(() => `${new Date().getFullYear()}-01-01`, [])

  const countryCode = 'SG'
  const [reportType, setReportType] = useState<ReportType>('trial_balance')
  const [asOfDate, setAsOfDate] = useState(today)
  const [startDate, setStartDate] = useState(startOfYear)
  const [isDownloading, setIsDownloading] = useState(false)
  const [downloadMeta, setDownloadMeta] = useState<{
    filename: string
    taxonomyValidated: boolean
    taxonomyValid: boolean
  } | null>(null)

  const parseDownloadError = async (error: unknown): Promise<string> => {
    const fallback = 'Unable to download XBRL report.'
    const responseData = (error as any)?.response?.data

    if (typeof responseData === 'string' && responseData.trim().length > 0) {
      return responseData
    }

    if (responseData instanceof Blob) {
      const text = (await responseData.text())?.trim()
      if (!text) {
        return fallback
      }

      try {
        const parsed = JSON.parse(text)
        const parsedErrors = parsed?.errors

        if (Array.isArray(parsedErrors) && parsedErrors.length > 0) {
          return parsedErrors.join(', ')
        }

        if (typeof parsedErrors === 'string' && parsedErrors.length > 0) {
          return parsedErrors
        }

        if (typeof parsed?.message === 'string' && parsed.message.length > 0) {
          return parsed.message
        }
      } catch {
        return text
      }
    }

    if (error instanceof Error && error.message.trim().length > 0) {
      return error.message
    }

    return fallback
  }

  const handleDownload = async () => {
    try {
      setIsDownloading(true)
      setDownloadMeta(null)

      const { blob, filename, taxonomyValidated, taxonomyValid } = await complianceApi.downloadXbrl(
        countryCode,
        reportType,
        {
          as_of_date: asOfDate,
          start_date: reportType === 'income_statement' ? startDate : undefined,
          strict_schema_validation: false,
        }
      )

      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)

      setDownloadMeta({ filename, taxonomyValidated, taxonomyValid })

      toast({
        title: 'XBRL Downloaded',
        description: `${filename} downloaded successfully.`,
      })
    } catch (error) {
      const message = await parseDownloadError(error)

      toast({
        title: 'Download Failed',
        description: message,
        variant: 'destructive',
      })
    } finally {
      setIsDownloading(false)
    }
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Compliance Reports</h1>
          <p className="text-muted-foreground mt-1">
            Generate statutory reports and download XBRL XML for Singapore compliance.
          </p>
        </div>
        {!embedded && (
          <HeaderActionButton icon="solar:arrow-left-linear" variant="outline" onClick={() => navigate('/accounting/core/journal-entries')}>
            Back to Accounting
          </HeaderActionButton>
        )}
      </div>

      {/* Report parameters — compact pills, matching the rest of the app */}
      <Card className="p-4">
        <div className="flex flex-wrap items-end gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="reportType" className="text-xs text-muted-foreground">
              Report Type
            </Label>
            <Select value={reportType} onValueChange={(value) => setReportType(value as ReportType)}>
              <SelectTrigger id="reportType" className="h-8 w-44 rounded-full text-xs">
                <SelectValue placeholder="Select report type" />
              </SelectTrigger>
              <SelectContent>
                {reportTypeOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="asOfDate" className="text-xs text-muted-foreground">
              As Of Date
            </Label>
            <Input
              id="asOfDate"
              type="date"
              value={asOfDate}
              onChange={(e) => setAsOfDate(e.target.value)}
              className="h-8 w-40 rounded-full px-3 text-xs"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="startDate" className="text-xs text-muted-foreground">
              Start Date (P&L only)
            </Label>
            <Input
              id="startDate"
              type="date"
              value={startDate}
              onChange={(e) => setStartDate(e.target.value)}
              disabled={reportType !== 'income_statement'}
              className="h-8 w-40 rounded-full px-3 text-xs"
            />
          </div>

          <div className="ml-auto">
            <HeaderActionButton icon="solar:file-download-bold" onClick={handleDownload} disabled={isDownloading}>
              {isDownloading ? 'Downloading...' : 'Download XBRL XML'}
            </HeaderActionButton>
          </div>
        </div>
      </Card>

      {downloadMeta && (
        <Card className="p-4">
          <h3 className="text-sm font-semibold">Last download</h3>
          <p className="mt-1 text-sm text-muted-foreground">
            <span className="font-medium text-foreground">{downloadMeta.filename}</span>
            {downloadMeta.taxonomyValidated
              ? downloadMeta.taxonomyValid
                ? ' · taxonomy validated'
                : ' · taxonomy warnings'
              : ''}
          </p>
        </Card>
      )}
    </div>
  )
}
