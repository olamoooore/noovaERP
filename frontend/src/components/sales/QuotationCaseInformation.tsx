import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

export interface QuotationCaseInformationValue {
  case_number: string
  date_of_case: string
  case_location: string
  patient_name: string
  cost_center_id: string
  delivery_date: string
  pr_no: string
  contract_no: string
  plant_code: string
  company_code: string
}

export const emptyQuotationCaseInformation = (): QuotationCaseInformationValue => ({
  case_number: '', date_of_case: '', case_location: '', patient_name: '',
  cost_center_id: '', delivery_date: '', pr_no: '', contract_no: '',
  plant_code: '', company_code: '',
})

const labels: Array<[keyof QuotationCaseInformationValue, string, 'text' | 'date', string]> = [
  ['case_number', 'Case Number', 'text', 'Case reference number'],
  ['date_of_case', 'Date of Case', 'date', ''],
  ['pr_no', 'PR No', 'text', 'Purchase requisition number'],
  ['contract_no', 'Contract No', 'text', 'Contract reference'],
  ['plant_code', 'Plant Code', 'text', 'Plant code'],
  ['company_code', 'Company Code', 'text', 'Company code'],
  ['case_location', 'Location', 'text', 'Case / site location'],
  ['patient_name', 'Patient Name', 'text', 'Patient name'],
  ['cost_center_id', 'Cost Center ID', 'text', 'Cost center reference'],
  ['delivery_date', 'Delivery Date', 'date', ''],
]

export function QuotationCaseInformation({ value, onChange }: {
  value: QuotationCaseInformationValue
  onChange: (value: QuotationCaseInformationValue) => void
}) {
  return (
    <Card className="mb-6">
      <CardHeader><CardTitle>Case Information</CardTitle></CardHeader>
      <CardContent>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {labels.map(([field, label, type, placeholder]) => (
            <div key={field}>
              <label className="mb-1 block text-sm font-medium">{label}</label>
              <input
                type={type}
                value={value[field]}
                onChange={(event) => onChange({ ...value, [field]: event.target.value })}
                placeholder={placeholder}
                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
              />
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  )
}

export function QuotationCaseInformationDisplay({ quotation }: { quotation: Partial<QuotationCaseInformationValue> }) {
  const populated = labels.filter(([field]) => quotation[field])
  if (populated.length === 0) return null

  return (
    <Card>
      <CardHeader><CardTitle>Case Information</CardTitle></CardHeader>
      <CardContent className="grid grid-cols-2 gap-4 text-sm md:grid-cols-5">
        {populated.map(([field, label, type]) => (
          <div key={field}>
            <div className="text-muted-foreground">{label}</div>
            <div className="font-medium">{type === 'date' ? new Date(`${quotation[field]}T00:00:00`).toLocaleDateString() : quotation[field]}</div>
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
