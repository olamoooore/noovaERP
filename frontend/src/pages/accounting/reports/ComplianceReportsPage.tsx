import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
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
  const [isExcelDownloading, setIsExcelDownloading] = useState(false)
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

  const handleExcelDownload = async () => {
    try {
      setIsExcelDownloading(true)
      setDownloadMeta(null)

      const { blob, filename } = await complianceApi.downloadExcel(
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

      toast({
        title: 'Excel Downloaded',
        description: `${filename} downloaded successfully.`,
      })
    } catch (error) {
      const message = await parseDownloadError(error)

      toast({
        title: 'Excel Download Failed',
        description: message,
        variant: 'destructive',
      })
    } finally {
      setIsExcelDownloading(false)
    }
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Compliance Reports</h1>
          <p className="text-muted-foreground mt-1">
            Generate statutory reports and download XBRL XML for Singapore compliance.
          </p>
        </div>
        {!embedded && (
          <Button variant="outline" onClick={() => navigate('/accounting/core/journal-entries')}>
            <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
            Back to Accounting
          </Button>
        )}
      </div>

      <Card className="p-6 space-y-6">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <div className="space-y-2">
            <Label htmlFor="reportType">Report Type</Label>
            <Select value={reportType} onValueChange={(value) => setReportType(value as ReportType)}>
              <SelectTrigger id="reportType">
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

          <div className="space-y-2">
            <Label htmlFor="asOfDate">As Of Date</Label>
            <Input
              id="asOfDate"
              type="date"
              value={asOfDate}
              onChange={(e) => setAsOfDate(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="startDate">Start Date (P&L only)</Label>
            <Input
              id="startDate"
              type="date"
              value={startDate}
              onChange={(e) => setStartDate(e.target.value)}
              disabled={reportType !== 'income_statement'}
            />
          </div>
        </div>

        <div className="flex flex-wrap gap-3">
          <Button variant="outline" onClick={handleDownload} disabled={isDownloading || isExcelDownloading}>
            <Icon icon="solar:file-download-bold" className="w-4 h-4 mr-2" />
            {isDownloading ? 'Downloading...' : 'Download XBRL XML'}
          </Button>
          <Button variant="outline" onClick={handleExcelDownload} disabled={isDownloading || isExcelDownloading}>
            <Icon icon="solar:document-bold" className="w-4 h-4 mr-2" />
            {isExcelDownloading ? 'Downloading Excel...' : 'Download Excel'}
          </Button>
        </div>

        {downloadMeta && (
          <div className="rounded-lg border p-4 bg-muted/30">
            <h3 className="font-semibold mb-2">Last Download</h3>
            <div className="text-sm space-y-1">
              <p><span className="font-medium">File:</span> {downloadMeta.filename}</p>
            </div>
          </div>
        )}
      </Card>
    </div>
  )
}
