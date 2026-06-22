import api from './api'

export interface ComplianceReportOptions {
  as_of_date: string
  start_date?: string
  format?: 'json' | 'xbrl' | 'xml'
}

export interface DownloadOptions {
  as_of_date: string
  start_date?: string
  strict_schema_validation?: boolean
}

export interface ComplianceDownloadResult {
  blob: Blob
  filename: string
  taxonomyValidated: boolean
  taxonomyValid: boolean
}

function normalizeReportType(reportType: string): string {
  const normalized = reportType.trim().toLowerCase()
  return normalized === 'profit_loss' ? 'income_statement' : normalized
}

function parseFilename(contentDisposition?: string): string | null {
  if (!contentDisposition) return null

  const utf8Match = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)
  if (utf8Match?.[1]) {
    return decodeURIComponent(utf8Match[1])
  }

  const simpleMatch = contentDisposition.match(/filename="?([^";]+)"?/i)
  return simpleMatch?.[1] ?? null
}

class ComplianceApiClient {
  async getReport(countryCode: string, reportType: string, options: ComplianceReportOptions) {
    const normalizedReportType = normalizeReportType(reportType)

    const response = await api.get(`/accounting/compliance/reports/${countryCode}/${normalizedReportType}`, {
      params: options,
    })

    return response.data
  }

  private async downloadFile(
    countryCode: string,
    reportType: string,
    options: DownloadOptions,
    format: 'xbrl' | 'excel'
  ): Promise<ComplianceDownloadResult> {
    const normalizedReportType = normalizeReportType(reportType)
    const params = {
      ...options,
      strict_schema_validation: options.strict_schema_validation ? 1 : 0,
      format,
    }

    const response = await api.get(`/accounting/compliance/reports/${countryCode}/${normalizedReportType}/download`, {
      params,
      responseType: 'blob',
    })

    const filename =
      parseFilename(response.headers['content-disposition']) ||
      `${countryCode}_${normalizedReportType}_${options.as_of_date.replaceAll('-', '')}.${format === 'excel' ? 'xlsx' : 'xbrl'}`

    return {
      blob: response.data,
      filename,
      taxonomyValidated: response.headers['x-xbrl-taxonomy-validated'] === 'true',
      taxonomyValid: response.headers['x-xbrl-taxonomy-valid'] === 'true',
    }
  }

  async downloadXbrl(countryCode: string, reportType: string, options: DownloadOptions): Promise<ComplianceDownloadResult> {
    return this.downloadFile(countryCode, reportType, options, 'xbrl')
  }

  async downloadExcel(countryCode: string, reportType: string, options: DownloadOptions): Promise<ComplianceDownloadResult> {
    return this.downloadFile(countryCode, reportType, options, 'excel')
  }
}

export default new ComplianceApiClient()
