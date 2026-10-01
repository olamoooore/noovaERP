import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Input } from '@/components/ui/input'
import api from '@/lib/api'

export interface MedicalCaseOption {
  id: number
  case_no: string
  case_tag?: string | null
  case_date?: string | null
  procedure_date?: string | null
  location?: string | null
  patient_reference?: string | null
  patient_name?: string | null
  doctor_contact_id?: number | null
  requestor_contact_id?: number | null
  buyer_contact_id?: number | null
  customer_id?: number | null
}

interface CaseSearchComboboxProps {
  value: string
  /** Fired on every keystroke — the field stays free-text (new cases allowed). */
  onValueChange: (value: string) => void
  /** Fired when an existing case is picked — populate the case fields. */
  onSelectCase: (medicalCase: MedicalCaseOption) => void
  customerId?: string | number
  placeholder?: string
  disabled?: boolean
}

/**
 * Case Number field: type any reference for a NEW case, or pick an existing
 * case from the registry (scoped to the selected customer) to populate the
 * rest of the Case Information fields.
 */
export function CaseSearchCombobox({
  value,
  onValueChange,
  onSelectCase,
  customerId,
  placeholder = 'Case reference number — or pick an existing case',
  disabled = false,
}: CaseSearchComboboxProps) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  const { data: cases, isLoading } = useQuery<MedicalCaseOption[]>({
    queryKey: ['medical-cases-search', debounced, customerId],
    queryFn: async () => {
      const params: Record<string, string> = { per_page: '10' }
      if (debounced) params.search = debounced
      if (customerId) params.customer_id = String(customerId)
      const res = await api.get('/medical-cases', { params })
      return (res.data?.data ?? []) as MedicalCaseOption[]
    },
    enabled: open,
  })

  const label = (c: MedicalCaseOption) =>
    [c.case_no, c.patient_name || c.patient_reference, c.case_date ? String(c.case_date).slice(0, 10) : null]
      .filter(Boolean)
      .join(' · ')

  const handleSelect = (medicalCase: MedicalCaseOption) => {
    onValueChange(medicalCase.case_no)
    onSelectCase(medicalCase)
    setOpen(false)
    setSearch('')
  }

  return (
    <div className="relative">
      <Input
        value={value}
        disabled={disabled}
        placeholder={placeholder}
        autoComplete="off"
        onFocus={() => {
          setSearch(value)
          setOpen(true)
        }}
        onBlur={() => {
          // Let click handlers run before closing.
          setTimeout(() => setOpen(false), 150)
        }}
        onChange={(event) => {
          onValueChange(event.target.value)
          setSearch(event.target.value)
          setOpen(true)
        }}
      />
      {open && (
        <div className="absolute z-50 mt-1 max-h-72 w-full overflow-y-auto rounded-md border bg-background shadow-lg">
          {isLoading ? (
            <div className="px-3 py-2 text-xs text-muted-foreground">Searching cases…</div>
          ) : (cases?.length ?? 0) === 0 ? (
            <div className="px-3 py-2 text-xs text-muted-foreground">
              No existing case found — the reference you type will be used as a new case.
            </div>
          ) : (
            cases?.map((medicalCase) => (
              <button
                key={medicalCase.id}
                type="button"
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => handleSelect(medicalCase)}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
              >
                <Icon icon="solar:medical-kit-bold-duotone" className="h-4 w-4 shrink-0 text-primary" />
                <span className="min-w-0 flex-1 truncate">{label(medicalCase)}</span>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  )
}