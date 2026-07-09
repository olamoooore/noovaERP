import { useQuery } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { ScrollArea } from '@/components/ui/scroll-area'
import { templateBuilderApi } from '@/lib/templateBuilderApi'
import type { DocumentType } from '@/types/template-builder'
import { Braces } from 'lucide-react'

interface Props {
  documentType: DocumentType | string
  onInsert: (token: string) => void
}

export function MergeFieldPicker({ documentType, onInsert }: Props) {
  const { data, isLoading } = useQuery({
    queryKey: ['tb-merge-fields', documentType],
    queryFn: () => templateBuilderApi.mergeFields(documentType),
    enabled: !!documentType,
    staleTime: 5 * 60 * 1000,
  })

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" size="sm" type="button">
          <Braces className="mr-1.5 h-3.5 w-3.5" />
          Insert field
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-72 p-0" align="start">
        <ScrollArea className="h-72">
          <div className="p-2">
            {isLoading && <p className="px-2 py-4 text-xs text-muted-foreground">Loading fields…</p>}
            {!isLoading && (!data || data.length === 0) && (
              <p className="px-2 py-4 text-xs text-muted-foreground">No fields available.</p>
            )}
            {data?.map((g) => (
              <div key={g.group} className="mb-2">
                <p className="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                  {g.group}
                </p>
                {g.fields.map((f) => (
                  <button
                    key={f.token}
                    onClick={() => onInsert(f.token)}
                    className="flex w-full items-center justify-between rounded px-2 py-1.5 text-left text-xs hover:bg-accent"
                    title={f.token}
                  >
                    <span>{f.label}</span>
                    <code className="text-[10px] text-muted-foreground">{f.token}</code>
                  </button>
                ))}
              </div>
            ))}
          </div>
        </ScrollArea>
      </PopoverContent>
    </Popover>
  )
}
