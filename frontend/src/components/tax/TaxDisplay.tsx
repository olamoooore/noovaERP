import { Badge } from '@/components/ui/badge';
import { Icon } from '@iconify/react';
import type { StructuredAdvice } from '@/lib/taxDisplay';

export const TreatmentBadge = ({ treatment }: { treatment?: string | null }) => {
  switch ((treatment || '').toLowerCase()) {
    case 'standard_rated':
      return <Badge variant="secondary" className="whitespace-nowrap bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300">Standard rated</Badge>;
    case 'zero_rated':
      return <Badge variant="secondary" className="whitespace-nowrap bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">Zero rated</Badge>;
    case 'exempt':
      return <Badge variant="secondary" className="whitespace-nowrap bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300">Exempt</Badge>;
    case 'out_of_scope':
      return <Badge variant="secondary" className="whitespace-nowrap bg-muted text-muted-foreground">Out of scope</Badge>;
    default:
      return <Badge variant="secondary" className="whitespace-nowrap">{treatment || '—'}</Badge>;
  }
};

/**
 * Renders the structured AI advisory ({summary, risks[], actions[]} — the
 * server normalizes every LLM shape into this contract, so raw model text can
 * never reach the UI). Shared by the Tax Engine console and the AI Suites Tax
 * Advisory workspace (single presentation, SOP §4).
 */
export function AiAdviceResult({ mode, advice }: { mode: string; advice: StructuredAdvice }) {
  return (
    <div className="space-y-3 rounded-xl border bg-muted/30 p-4">
      <Badge variant="outline" className="font-normal">
        {mode === 'ai' ? 'AI advisory' : 'Deterministic fallback'}
      </Badge>

      {(advice.summary ?? '') !== '' && (
        <p className="text-sm leading-relaxed text-foreground">{advice.summary}</p>
      )}

      {(advice.risks ?? []).length > 0 && (
        <div className="space-y-1.5">
          <p className="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">Risks to watch</p>
          <ul className="space-y-1">
            {(advice.risks ?? []).map((risk) => (
              <li key={risk} className="flex gap-2 text-sm text-muted-foreground">
                <Icon icon="solar:danger-triangle-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                <span>{risk}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {(advice.actions ?? []).length > 0 && (
        <div className="space-y-1.5">
          <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Suggested review actions</p>
          <ol className="space-y-1">
            {(advice.actions ?? []).map((action, index) => (
              <li key={action} className="flex gap-2 text-sm text-muted-foreground">
                <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-[10px] font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                  {index + 1}
                </span>
                <span>{action}</span>
              </li>
            ))}
          </ol>
        </div>
      )}

      <p className="border-t pt-2 text-xs text-muted-foreground">
        Advisory only — the deterministic engine result is authoritative. Verify current
        IRAS / LHDN-RMCD guidance before posting or filing.
      </p>
    </div>
  );
}
