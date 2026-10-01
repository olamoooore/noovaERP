import { Icon } from '@iconify/react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

type ChipTone = 'default' | 'primary' | 'warn' | 'muted';

const TONES: Record<ChipTone, string> = {
  default: 'border-border bg-background text-foreground hover:bg-muted/60',
  primary:
    'border-indigo-200 bg-indigo-50 text-indigo-800 hover:bg-indigo-100 dark:border-indigo-900/50 dark:bg-indigo-950/40 dark:text-indigo-300',
  warn: 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300',
  muted: 'border-border bg-muted/50 text-muted-foreground',
};

const CHIP =
  'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs transition-colors [&_svg]:shrink-0';

/**
 * D3 (Oct-2026 invoice review): one-line chip header. A chip shows a header
 * value in place; clicking it opens the editor in a popover instead of a
 * labelled form field.
 */
export function HeaderChip({
  icon,
  label,
  value,
  tone = 'default',
  title,
  children,
  className,
}: {
  icon?: string;
  label?: string;
  value: string;
  tone?: ChipTone;
  title?: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button type="button" title={title} className={cn(CHIP, TONES[tone], className)}>
          {icon && <Icon icon={icon} className="h-3.5 w-3.5" />}
          {label && <span className="text-muted-foreground">{label}</span>}
          <span className="font-medium">{value}</span>
          <Icon icon="solar:alt-arrow-down-linear" className="h-3 w-3 opacity-60" />
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-64 p-3">
        {children}
      </PopoverContent>
    </Popover>
  );
}

/** Read-only chip for status-style values (e.g. "Approval req."). */
export function StaticChip({
  icon,
  value,
  tone = 'warn',
  title,
}: {
  icon?: string;
  value: string;
  tone?: ChipTone;
  title?: string;
}) {
  return (
    <span title={title} className={cn(CHIP, TONES[tone])}>
      {icon && <Icon icon={icon} className="h-3.5 w-3.5" />}
      <span className="font-medium">{value}</span>
    </span>
  );
}