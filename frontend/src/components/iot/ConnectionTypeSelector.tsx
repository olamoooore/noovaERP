/**
 * Connection Type Selector Component
 * 
 * Radio group for selecting device connection type
 */

import { Icon } from '@iconify/react'
import { Label } from '@/components/ui/label'
import { CONNECTION_TYPES, type ConnectionType } from '@/types/iot'
import { cn } from '@/lib/utils'

interface ConnectionTypeSelectorProps {
  value: ConnectionType
  onChange: (value: ConnectionType) => void
  disabled?: boolean
}

export function ConnectionTypeSelector({ 
  value, 
  onChange, 
  disabled 
}: ConnectionTypeSelectorProps) {
  return (
    <div className="space-y-3">
      <Label>Connection Type</Label>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        {CONNECTION_TYPES.map((type) => (
          <button
            key={type.value}
            type="button"
            onClick={() => !disabled && onChange(type.value)}
            disabled={disabled}
            className={cn(
              'relative flex cursor-pointer rounded-lg border-2 p-4 transition-all text-left',
              'hover:border-primary/50 hover:bg-accent/50',
              value === type.value
                ? 'border-primary bg-accent'
                : 'border-muted',
              disabled && 'cursor-not-allowed opacity-50'
            )}
          >
            <div className="flex w-full items-start gap-3">
              <div className={cn(
                'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                value === type.value
                  ? 'bg-primary/10'
                  : 'bg-muted'
              )}>
                <Icon
                  icon={type.icon}
                  className={cn(
                    'h-5 w-5',
                    value === type.value
                      ? 'text-primary'
                      : 'text-muted-foreground'
                  )}
                />
              </div>
              <div className="flex-1 space-y-1">
                <div className="flex items-center justify-between">
                  <p className="font-medium text-sm">{type.label}</p>
                  {value === type.value && (
                    <Icon
                      icon="solar:check-circle-bold"
                      className="h-5 w-5 text-primary"
                    />
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  {type.description}
                </p>
                <p className="text-xs text-muted-foreground/70 mt-1">
                  <span className="font-medium">Requires:</span> {type.requires}
                </p>
              </div>
            </div>
          </button>
        ))}
      </div>
    </div>
  )
}
